// Password Manager (ADR-092). Everything secret is encrypted and decrypted here, in the browser, with Web Crypto:
//
//   master password --PBKDF2-SHA256 (>= 600,000 iterations, random 16-byte salt)--> key-encryption key (KEK)
//   KEK --AES-256-GCM--> wraps a random 256-bit vault key (stored wrapped on the server)
//   vault key --AES-256-GCM, fresh 12-byte IV per save--> each entry as JSON (type, title, username, password…)
//
// The master password, the KEK and the unwrapped vault key never leave this page; the server stores salt, iteration
// count, wrapped key and entry ciphertext only. Additional authenticated data binds the wrapped key and entries to
// this user, so ciphertext copied between accounts will not decrypt. Decrypted values are only ever written to the
// page with textContent / input.value (never innerHTML). The vault locks after 5 idle minutes and on leaving the page.
(function () {
    'use strict';

    var root = document.getElementById('vault');
    if (!root || !window.crypto || !window.crypto.subtle) {
        if (root) {
            var box = document.getElementById('v-loading');
            box.hidden = false;
            // Browsers only offer Web Crypto on https:// (or localhost): send a plain-http visitor to the https address.
            if (location.protocol === 'http:') {
                var secure = 'https://' + location.host + location.pathname;
                box.replaceChildren(document.createTextNode('The Password Manager only works over HTTPS, because browsers only allow its encryption there. '));
                var link = document.createElement('a');
                link.href = secure;
                link.textContent = 'Open ' + secure;
                box.appendChild(link);
            } else {
                box.textContent = 'This browser cannot run the Password Manager (no Web Crypto). Use a current browser.';
            }
        }
        return;
    }

    var MIN_ITERATIONS = Math.max(600000, parseInt(root.dataset.minIterations, 10) || 0);
    var IDLE_LOCK_MS = 5 * 60 * 1000;
    var CLIPBOARD_CLEAR_MS = 30 * 1000;
    var USER = root.dataset.user;
    var AAD_KEY = utf8('mwm-vault-key:v1:u' + USER);
    var AAD_ENTRY = utf8('mwm-vault-entry:v1:u' + USER);

    // Entry types and their fields. secret: masked with Show / Copy; generate: offers the password generator.
    var TYPES = {
        login: { label: 'Website login', fields: [
            { key: 'title', label: 'Title', required: true, placeholder: 'e.g. Gmail — work' },
            { key: 'url', label: 'Website', kind: 'url', placeholder: 'https://accounts.google.com' },
            { key: 'app', label: 'App name', placeholder: 'e.g. Gmail' },
            { key: 'username', label: 'Username / email' },
            { key: 'password', label: 'Password', secret: true, generate: true },
            { key: 'group', label: 'Group', placeholder: 'e.g. Google, Work, Banking' },
            { key: 'notes', label: 'Notes', kind: 'textarea', secret: true } ] },
        app: { label: 'App login', fields: [
            { key: 'title', label: 'Title', required: true, placeholder: 'e.g. Microsoft Office' },
            { key: 'app', label: 'App / platform', placeholder: 'e.g. Windows desktop, Android' },
            { key: 'url', label: 'URL', kind: 'url', placeholder: 'https://' },
            { key: 'username', label: 'Username / email' },
            { key: 'password', label: 'Password', secret: true, generate: true },
            { key: 'group', label: 'Group' },
            { key: 'notes', label: 'Notes', kind: 'textarea', secret: true } ] },
        pin: { label: 'App MPIN / PIN', fields: [
            { key: 'title', label: 'Title', required: true, placeholder: 'e.g. Bank app MPIN' },
            { key: 'app', label: 'App', placeholder: 'e.g. HDFC MobileBanking (Android)' },
            { key: 'url', label: 'URL', kind: 'url', placeholder: 'https://' },
            { key: 'username', label: 'Account / phone / customer ID' },
            { key: 'pin', label: 'MPIN / PIN', secret: true, inputmode: 'numeric' },
            { key: 'group', label: 'Group' },
            { key: 'notes', label: 'Notes', kind: 'textarea', secret: true } ] },
        licence: { label: 'Licence key', fields: [
            { key: 'title', label: 'Title', required: true, placeholder: 'e.g. PhpStorm licence' },
            { key: 'app', label: 'Product' },
            { key: 'url', label: 'URL', kind: 'url', placeholder: 'https://' },
            { key: 'username', label: 'Licensed to / email' },
            { key: 'licenceKey', label: 'Licence key', kind: 'textarea', secret: true },
            { key: 'group', label: 'Group' },
            { key: 'notes', label: 'Notes', kind: 'textarea', secret: true } ] },
        note: { label: 'Secure note', fields: [
            { key: 'title', label: 'Title', required: true },
            { key: 'group', label: 'Group' },
            { key: 'notes', label: 'Note', kind: 'textarea', secret: true } ] }
    };
    var MAX_FIELD = 20000;

    var vaultKey = null;     // CryptoKey, non-extractable; null when locked
    var keyInfo = null;      // {kdf, iterations, salt, wrappedKey, wrapIv} from the server
    var entries = [];        // [{id, version, updatedAt, data:{type, fields}|null}]
    var current = null;      // the entry shown or edited
    var idleTimer = null;
    var clipboardTimer = null;

    // ---- small helpers -------------------------------------------------------------------------------------------
    function el(id) { return document.getElementById(id); }
    function show(id) { el(id).hidden = false; }
    function hide(id) { el(id).hidden = true; }
    function utf8(text) { return new TextEncoder().encode(text); }
    function toB64(buffer) {
        var bytes = new Uint8Array(buffer), s = '';
        for (var i = 0; i < bytes.length; i++) { s += String.fromCharCode(bytes[i]); }
        return btoa(s);
    }
    function fromB64(text) {
        var s = atob(text), bytes = new Uint8Array(s.length);
        for (var i = 0; i < s.length; i++) { bytes[i] = s.charCodeAt(i); }
        return bytes;
    }
    function random(n) { return crypto.getRandomValues(new Uint8Array(n)); }
    function make(tag, attrs, text) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) { if (k === 'className') { node.className = attrs[k]; } else { node.setAttribute(k, attrs[k]); } });
        if (text !== undefined && text !== null) { node.textContent = text; }
        return node;
    }
    function message(text, kind) {
        var box = el('v-msg');
        box.textContent = text;
        box.className = 'v-msg alert ' + (kind || 'success');
        box.hidden = !text;
        if (text) { box.scrollIntoView({ block: 'nearest' }); }
    }

    function api(method, url, body) {
        return fetch(url, {
            method: method,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': root.dataset.csrf },
            body: body === undefined ? undefined : JSON.stringify(body)
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (json) {
                if (!response.ok) {
                    var error = new Error(json.error || ('The server refused the request (' + response.status + ').'));
                    error.status = response.status;
                    throw error;
                }
                return json;
            });
        });
    }

    // ---- crypto --------------------------------------------------------------------------------------------------
    function deriveKek(password, salt, iterations) {
        return crypto.subtle.importKey('raw', utf8(password), 'PBKDF2', false, ['deriveKey']).then(function (base) {
            return crypto.subtle.deriveKey({ name: 'PBKDF2', hash: 'SHA-256', salt: salt, iterations: iterations },
                base, { name: 'AES-GCM', length: 256 }, false, ['wrapKey', 'unwrapKey']);
        });
    }

    // Wrapped vault key → CryptoKey. Rejects (OperationError) on a wrong master password: GCM authentication fails.
    function unwrap(password, info, extractable) {
        return deriveKek(password, fromB64(info.salt), info.iterations).then(function (kek) {
            return crypto.subtle.unwrapKey('raw', fromB64(info.wrappedKey), kek,
                { name: 'AES-GCM', iv: fromB64(info.wrapIv), additionalData: AAD_KEY },
                { name: 'AES-GCM', length: 256 }, extractable, ['encrypt', 'decrypt']);
        });
    }

    function wrap(password, key) {
        var salt = random(16), iv = random(12);
        return deriveKek(password, salt, MIN_ITERATIONS).then(function (kek) {
            return crypto.subtle.wrapKey('raw', key, kek, { name: 'AES-GCM', iv: iv, additionalData: AAD_KEY });
        }).then(function (wrapped) {
            return { kdf: 'PBKDF2-SHA256', iterations: MIN_ITERATIONS, salt: toB64(salt), wrappedKey: toB64(wrapped), wrapIv: toB64(iv) };
        });
    }

    function encryptEntry(data) {
        var iv = random(12);
        return crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv, additionalData: AAD_ENTRY }, vaultKey, utf8(JSON.stringify(data)))
            .then(function (ct) { return { ciphertext: toB64(ct), iv: toB64(iv) }; });
    }

    function decryptEntry(row) {
        return crypto.subtle.decrypt({ name: 'AES-GCM', iv: fromB64(row.iv), additionalData: AAD_ENTRY }, vaultKey, fromB64(row.ciphertext))
            .then(function (plain) {
                var data = JSON.parse(new TextDecoder().decode(plain));
                return TYPES[data.type] && data.fields ? data : null;
            })
            .catch(function () { return null; });
    }

    // ---- password strength and generator ---------------------------------------------------------------------------
    function strengthBits(password) {
        var pool = 0;
        if (/[a-z]/.test(password)) { pool += 26; }
        if (/[A-Z]/.test(password)) { pool += 26; }
        if (/[0-9]/.test(password)) { pool += 10; }
        if (/[^a-zA-Z0-9]/.test(password)) { pool += 33; }
        var unique = new Set(password).size;
        return Math.round(Math.min(password.length, unique * 2) * Math.log2(Math.max(pool, 1)));
    }
    function meter(input, bar) {
        var bits = strengthBits(input.value);
        bar.style.width = Math.min(100, bits) + '%';
        bar.className = bits >= 80 ? 'strong' : (bits >= 60 ? 'fair' : 'weak');
    }
    function generatePassword(length) {
        var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*()-_=+[]{};:,.?';
        var out = '', limit = 256 - (256 % chars.length);
        while (out.length < length) {
            var bytes = random(length * 2);
            for (var i = 0; i < bytes.length && out.length < length; i++) {
                if (bytes[i] < limit) { out += chars[bytes[i] % chars.length]; } // rejection sampling: no modulo bias
            }
        }
        return out;
    }

    // ---- clipboard -----------------------------------------------------------------------------------------------
    function copy(text, what) {
        var done = function () {
            message(what + ' copied. The clipboard is cleared in 30 seconds.');
            clearTimeout(clipboardTimer);
            clipboardTimer = setTimeout(function () {
                if (navigator.clipboard) { navigator.clipboard.writeText('').catch(function () {}); }
            }, CLIPBOARD_CLEAR_MS);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { message('Copying was blocked by the browser.', 'danger'); });
            return;
        }
        var area = make('textarea', { 'aria-hidden': 'true', className: 'v-offscreen' });
        area.value = text;
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); done(); } catch (e) { message('Copying is not available here.', 'danger'); }
        area.value = '';
        area.remove();
    }

    // ---- views ---------------------------------------------------------------------------------------------------
    var VIEWS = ['v-loading', 'v-setup', 'v-unlock-wrap', 'v-main', 'v-detail', 'v-edit', 'v-master'];
    function view(ids) {
        VIEWS.forEach(function (id) { el(id).hidden = ids.indexOf(id) === -1; });
        var open = vaultKey !== null;
        el('v-actions').hidden = !open;
        el('v-lock-badge').hidden = !open;
    }

    function load() {
        return api('GET', root.dataset.stateUrl).then(function (state) {
            keyInfo = state.key;
            if (!state.setUp) {
                view(['v-setup']);
                el('v-setup-pass').focus();
                return;
            }
            entries = state.entries.map(function (row) { return { id: row.id, version: row.version, updatedAt: row.updatedAt, row: row, data: null }; });
            if (vaultKey) { return decryptAll().then(renderList); }
            view(['v-unlock-wrap']);
            el('v-unlock-pass').focus();
        }).catch(function (error) { view([]); message(error.message, 'danger'); });
    }

    function decryptAll() {
        return Promise.all(entries.map(function (e) {
            return decryptEntry(e.row).then(function (data) { e.data = data; delete e.row; });
        }));
    }

    function lock(reason) {
        vaultKey = null;
        entries = [];
        current = null;
        clearTimeout(idleTimer);
        el('v-list').replaceChildren();
        el('v-detail-fields').replaceChildren();
        el('v-edit-fields').replaceChildren();
        ['v-unlock-pass', 'v-master-current', 'v-master-new', 'v-master-confirm', 'v-search'].forEach(function (id) { el(id).value = ''; });
        view(['v-unlock-wrap']);
        message(reason || '', 'warn');
    }

    function touch() {
        if (!vaultKey) { return; }
        clearTimeout(idleTimer);
        idleTimer = setTimeout(function () { lock('Locked after 5 minutes without activity.'); }, IDLE_LOCK_MS);
    }

    function field(entry, key) { return entry.data && entry.data.fields[key] ? String(entry.data.fields[key]) : ''; }
    function secretOf(entry) {
        var type = entry.data ? TYPES[entry.data.type] : null;
        var f = type ? type.fields.filter(function (d) { return d.secret && d.key !== 'notes'; })[0] : null;
        return f ? { label: f.label, value: field(entry, f.key) } : null;
    }

    var MASK = '••••••••••';
    // A masked secret with Show / Hide and Copy; used by the list and the detail panel.
    function secretControl(value, label, tag) {
        var wrap = make('span', { className: 'v-secret-ctl' });
        var shown = make(tag || 'span', { className: 'v-secret' }, MASK);
        var toggle = make('button', { type: 'button', className: 'btn secondary xs' }, 'Show');
        toggle.addEventListener('click', function (ev) {
            ev.stopPropagation();
            var hidden = toggle.textContent === 'Show';
            shown.textContent = hidden ? value : MASK;
            toggle.textContent = hidden ? 'Hide' : 'Show';
            touch();
        });
        var cp = make('button', { type: 'button', className: 'btn info xs' }, 'Copy');
        cp.addEventListener('click', function (ev) { ev.stopPropagation(); copy(value, label); });
        wrap.append(shown, ' ', toggle, ' ', cp);
        return wrap;
    }

    function renderList() {
        var term = el('v-search').value.trim().toLowerCase();
        var type = el('v-type-filter').value;
        var list = el('v-list');
        list.replaceChildren();
        var rows = entries.filter(function (e) {
            if (!e.data) { return !term && !type; }
            if (type && e.data.type !== type) { return false; }
            if (!term) { return true; }
            return ['title', 'username', 'url', 'app', 'group'].some(function (k) { return field(e, k).toLowerCase().indexOf(term) !== -1; });
        }).sort(function (a, b) { return field(a, 'title').localeCompare(field(b, 'title')); });

        rows.forEach(function (e) {
            var tr = make('tr', { className: 'v-row', tabindex: '0' });
            if (!e.data) {
                tr.appendChild(make('td', { colspan: '8', className: 'muted' }, 'Entry #' + e.id + ' could not be decrypted with this vault key.'));
                list.appendChild(tr);
                return;
            }
            var title = make('td', { className: 'bold' }, field(e, 'title'));
            if (field(e, 'group')) { title.appendChild(make('span', { className: 'badge plain v-group' }, field(e, 'group'))); }
            tr.appendChild(title);
            tr.appendChild(make('td', {}, TYPES[e.data.type].label));
            tr.appendChild(make('td', {}, field(e, 'app') || '—'));
            var url = field(e, 'url');
            var urlCell = make('td', { className: 'v-url' });
            if (/^https?:\/\//i.test(url)) {
                var link = make('a', { href: url, target: '_blank', rel: 'noopener noreferrer' }, url);
                link.addEventListener('click', function (ev) { ev.stopPropagation(); });
                urlCell.appendChild(link);
            } else {
                urlCell.textContent = url || '—';
            }
            tr.appendChild(urlCell);
            tr.appendChild(make('td', {}, field(e, 'username') || '—'));
            var secret = secretOf(e);
            var secretCell = make('td', { className: 'nowrap' });
            if (secret && secret.value) { secretCell.appendChild(secretControl(secret.value, secret.label)); } else { secretCell.textContent = '—'; }
            tr.appendChild(secretCell);
            tr.appendChild(make('td', { className: 'nowrap muted' }, new Date(e.updatedAt * 1000).toLocaleDateString()));
            var actions = make('td', { className: 'actions' });
            if (field(e, 'username')) {
                var cu = make('button', { type: 'button', className: 'btn secondary xs' }, 'Copy user');
                cu.addEventListener('click', function (ev) { ev.stopPropagation(); copy(field(e, 'username'), 'Username'); });
                actions.appendChild(cu);
            }
            tr.appendChild(actions);
            tr.addEventListener('click', function () { openDetail(e); });
            tr.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { openDetail(e); } });
            list.appendChild(tr);
        });
        if (rows.length === 0) {
            list.appendChild(make('tr', {}, null)).appendChild(make('td', { colspan: '8', className: 'empty' },
                entries.length ? 'Nothing matches.' : 'Your vault is empty. Use “+ New entry” to add a login, app PIN, licence key or note.'));
        }
        el('v-count').textContent = entries.length + ' entr' + (entries.length === 1 ? 'y' : 'ies');
        view(['v-main']);
        touch();
    }

    function openDetail(entry) {
        current = entry;
        var type = TYPES[entry.data.type];
        el('v-detail-type').textContent = type.label;
        el('v-detail-title').textContent = field(entry, 'title');
        var table = el('v-detail-fields');
        table.replaceChildren();
        type.fields.forEach(function (def) {
            var value = field(entry, def.key);
            if (def.key === 'title' || !value) { return; }
            var tr = make('tr');
            tr.appendChild(make('th', {}, def.label));
            var td = make('td');
            if (def.secret) {
                td.appendChild(secretControl(value, def.label, def.kind === 'textarea' ? 'pre' : 'span'));
            } else if (def.kind === 'url' && /^https?:\/\//i.test(value)) {
                td.appendChild(make('a', { href: value, target: '_blank', rel: 'noopener noreferrer' }, value));
            } else {
                td.appendChild(make('span', {}, value));
                if (def.key === 'username') {
                    var cu = make('button', { type: 'button', className: 'btn secondary xs' }, 'Copy');
                    cu.addEventListener('click', function () { copy(value, def.label); });
                    td.append(' ', cu);
                }
            }
            tr.appendChild(td);
            table.appendChild(tr);
        });
        view(['v-main', 'v-detail']);
        el('v-detail').scrollIntoView({ block: 'nearest' });
        touch();
    }

    function openEdit(entry) {
        current = entry;
        var typeSelect = el('v-edit-type');
        typeSelect.value = entry ? entry.data.type : 'login';
        el('v-edit-heading').textContent = entry ? 'Edit ' + field(entry, 'title') : 'New entry';
        renderEditFields(entry ? entry.data.fields : {});
        view(['v-main', 'v-edit']);
        el('v-edit').scrollIntoView({ block: 'nearest' });
        var first = el('v-edit-fields').querySelector('input, textarea');
        if (first) { first.focus(); }
        touch();
    }

    function renderEditFields(values) {
        var type = TYPES[el('v-edit-type').value];
        var box = el('v-edit-fields');
        // Keep what was typed when switching type.
        box.querySelectorAll('[data-key]').forEach(function (input) { if (input.value && values[input.dataset.key] === undefined) { values[input.dataset.key] = input.value; } });
        box.replaceChildren();
        type.fields.forEach(function (def) {
            var id = 'v-f-' + def.key;
            var wrap = make('div', { className: 'field' + (def.kind === 'textarea' ? ' span-all' : '') });
            var label = make('label', { 'for': id }, def.label);
            if (def.required) { label.appendChild(make('span', { className: 'req' }, ' *')); }
            wrap.appendChild(label);
            var input = def.kind === 'textarea' ? make('textarea', { id: id, rows: '3' }) : make('input', { id: id, type: def.secret ? 'password' : (def.kind === 'url' ? 'url' : 'text') });
            input.dataset.key = def.key;
            input.setAttribute('autocomplete', def.secret ? 'new-password' : 'off');
            input.setAttribute('maxlength', String(MAX_FIELD));
            input.setAttribute('spellcheck', 'false');
            if (def.required) { input.required = true; }
            if (def.placeholder) { input.placeholder = def.placeholder; }
            if (def.inputmode) { input.setAttribute('inputmode', def.inputmode); }
            input.value = values[def.key] || '';
            wrap.appendChild(input);
            if (def.secret && def.kind !== 'textarea') {
                var row = make('div', { className: 'btn-row v-field-tools' });
                var reveal = make('button', { type: 'button', className: 'btn secondary xs' }, 'Show');
                reveal.addEventListener('click', function () {
                    input.type = input.type === 'password' ? 'text' : 'password';
                    reveal.textContent = input.type === 'password' ? 'Show' : 'Hide';
                });
                row.appendChild(reveal);
                if (def.generate) {
                    var gen = make('button', { type: 'button', className: 'btn info xs' }, 'Generate strong password');
                    gen.addEventListener('click', function () { input.value = generatePassword(20); input.type = 'text'; reveal.textContent = 'Hide'; });
                    row.appendChild(gen);
                }
                wrap.appendChild(row);
            }
            box.appendChild(wrap);
        });
    }

    // ---- actions -------------------------------------------------------------------------------------------------
    function busy(form, on) { form.querySelectorAll('button').forEach(function (b) { b.disabled = on; }); }

    el('v-setup-pass').addEventListener('input', function () { meter(this, el('v-setup-meter')); });
    el('v-master-new').addEventListener('input', function () { meter(this, el('v-master-meter')); });

    el('v-setup').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var form = this, pass = el('v-setup-pass').value;
        if (pass.length < 12) { message('Use at least 12 characters for the master password.', 'danger'); return; }
        if (pass !== el('v-setup-confirm').value) { message('The two master passwords do not match.', 'danger'); return; }
        if (strengthBits(pass) < 50) { message('That master password is too easy to guess. Make it longer or mix in words, digits and symbols.', 'danger'); return; }
        busy(form, true);
        message('Creating your vault…');
        var key;
        crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']).then(function (k) {
            key = k;
            return wrap(pass, key);
        }).then(function (wrapped) {
            return api('POST', root.dataset.setupUrl, wrapped).then(function () { return unwrap(pass, wrapped, false); });
        }).then(function (k) {
            vaultKey = k;
            el('v-setup-pass').value = el('v-setup-confirm').value = '';
            message('Vault created and unlocked.');
            return load();
        }).catch(function (error) { message(error.message, 'danger'); }).finally(function () { busy(form, false); });
    });

    var failures = 0;
    el('v-unlock').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var form = this, input = el('v-unlock-pass');
        busy(form, true);
        message('Unlocking…');
        unwrap(input.value, keyInfo, false).then(function (k) {
            vaultKey = k;
            failures = 0;
            input.value = '';
            message('');
            return load(); // lock() dropped the entries: fetch them again and decrypt with the key
        }, function () {
            failures++;
            input.value = '';
            message('Wrong master password.', 'danger');
            // Slow down guessing at the keyboard (an offline attacker is held back by PBKDF2's cost instead).
            return new Promise(function (resolve) { setTimeout(resolve, Math.min(30000, 1000 * Math.pow(2, failures - 1))); });
        }).finally(function () { busy(form, false); input.focus(); });
    });

    el('v-reset').addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (el('v-reset-confirm').value.trim() !== 'DELETE MY VAULT') { message('Type DELETE MY VAULT to confirm.', 'danger'); return; }
        var form = this;
        busy(form, true);
        api('POST', root.dataset.resetUrl, { password: el('v-reset-pass').value }).then(function (result) {
            el('v-reset-pass').value = el('v-reset-confirm').value = '';
            message('Your vault was deleted (' + result.deleted + ' entries). Create a new one below.', 'warn');
            return load();
        }).catch(function (error) { message(error.message, 'danger'); }).finally(function () { busy(form, false); });
    });

    el('v-search').addEventListener('input', renderList);
    el('v-type-filter').addEventListener('change', renderList);
    el('v-new').addEventListener('click', function () { openEdit(null); });
    el('v-lock').addEventListener('click', function () { lock('Vault locked.'); });
    el('v-detail-close').addEventListener('click', function () { current = null; view(['v-main']); });
    el('v-detail-edit').addEventListener('click', function () { openEdit(current); });
    el('v-edit-cancel').addEventListener('click', function () { if (current) { openDetail(current); } else { view(['v-main']); } });
    el('v-edit-type').addEventListener('change', function () { renderEditFields({}); });

    el('v-detail-delete').addEventListener('click', function () {
        var entry = current;
        if (!entry || !window.confirm('Delete "' + field(entry, 'title') + '"? This cannot be undone.')) { return; }
        api('DELETE', root.dataset.entriesUrl + '/' + entry.id).then(function () {
            entries = entries.filter(function (e) { return e !== entry; });
            current = null;
            message('Entry deleted.');
            renderList();
        }).catch(function (error) { message(error.message, 'danger'); });
    });

    el('v-edit').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var form = this, fields = {};
        el('v-edit-fields').querySelectorAll('[data-key]').forEach(function (input) {
            var v = input.tagName === 'TEXTAREA' ? input.value : input.value.trim();
            if (v !== '') { fields[input.dataset.key] = v; }
        });
        if (!fields.title) { message('Give the entry a title.', 'danger'); return; }
        var data = { v: 1, type: el('v-edit-type').value, fields: fields };
        var editing = current;
        busy(form, true);
        encryptEntry(data).then(function (sealed) {
            return editing
                ? api('PUT', root.dataset.entriesUrl + '/' + editing.id, { ciphertext: sealed.ciphertext, iv: sealed.iv, version: editing.version })
                : api('POST', root.dataset.entriesUrl, sealed);
        }).then(function (saved) {
            var entry = editing || { id: saved.id };
            entry.version = saved.version;
            entry.updatedAt = saved.updatedAt;
            entry.data = data;
            if (!editing) { entries.push(entry); }
            el('v-edit-fields').replaceChildren();
            message('Entry saved.');
            renderList();
            openDetail(entry);
        }).catch(function (error) { message(error.message, 'danger'); }).finally(function () { busy(form, false); });
    });

    el('v-change-master').addEventListener('click', function () { view(['v-main', 'v-master']); el('v-master-current').focus(); touch(); });
    el('v-master-cancel').addEventListener('click', function () { ['v-master-current', 'v-master-new', 'v-master-confirm'].forEach(function (id) { el(id).value = ''; }); view(['v-main']); });
    el('v-master').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var form = this, next = el('v-master-new').value;
        if (next.length < 12) { message('Use at least 12 characters for the master password.', 'danger'); return; }
        if (next !== el('v-master-confirm').value) { message('The two new master passwords do not match.', 'danger'); return; }
        if (strengthBits(next) < 50) { message('That master password is too easy to guess.', 'danger'); return; }
        busy(form, true);
        message('Changing the master password…');
        // Unwrap again with the current password (extractable just for this) and wrap the same key under the new one.
        unwrap(el('v-master-current').value, keyInfo, true).then(function (key) {
            return wrap(next, key);
        }, function () { throw new Error('The current master password is wrong.'); }).then(function (wrapped) {
            return api('POST', root.dataset.masterUrl, wrapped).then(function () { keyInfo = wrapped; });
        }).then(function () {
            ['v-master-current', 'v-master-new', 'v-master-confirm'].forEach(function (id) { el(id).value = ''; });
            view(['v-main']);
            message('Master password changed.');
        }).catch(function (error) { message(error.message, 'danger'); }).finally(function () { busy(form, false); });
    });

    ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function (name) { document.addEventListener(name, touch, { passive: true }); });
    window.addEventListener('pagehide', function () { lock(); });

    Object.keys(TYPES).forEach(function (k) {
        el('v-type-filter').appendChild(make('option', { value: k }, TYPES[k].label));
        el('v-edit-type').appendChild(make('option', { value: k }, TYPES[k].label));
    });
    load();
})();
