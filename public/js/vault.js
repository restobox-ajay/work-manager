// Password Manager (ADR-092). Everything secret is encrypted and decrypted here, in the browser, with Web Crypto:
//
//   master password --PBKDF2-SHA256 (>= 600,000 iterations, random 16-byte salt)--> 256-bit master key
//   master key, as is --> key-encryption key (KEK); master key --HKDF-SHA256--> auth key (ADR-094)
//   KEK --AES-256-GCM--> wraps a random 256-bit vault key (stored wrapped on the server)
//   vault key --AES-256-GCM, fresh 12-byte IV per save--> each entry as JSON (type, title, username, password…)
//
// The master password, the KEK and the unwrapped vault key never leave this page; the server stores salt, iteration
// count, wrapped key and entry ciphertext only. The auth key is sent with every change (X-Vault-Auth) to prove the
// master password — the server keeps only its hash, and it cannot decrypt anything. Additional authenticated data binds the wrapped key and entries to
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
    var AUTH_INFO = utf8('mwm-vault-auth:v1:u' + USER);
    var MIN_MASTER_LENGTH = 14;
    var MIN_MASTER_BITS = 60;

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
    var authKey = null;      // base64 auth key proving the master password to the server; null when locked
    var keyInfo = null;      // {kdf, iterations, salt, wrappedKey, wrapIv, hasAuth} from the server
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

    // auth: the auth key to prove (defaults to the unlocked vault's; the master-password change proves the current one).
    function api(method, url, body, auth) {
        var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': root.dataset.csrf };
        if (auth || authKey) { headers['X-Vault-Auth'] = auth || authKey; }
        return fetch(url, {
            method: method,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: headers,
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
    // Master password → {kek, auth}. The KEK is the raw 256 PBKDF2 bits, exactly what deriveKey produced before
    // ADR-094, so existing vaults still open. The auth key is an HKDF output of the same bits: it proves the master
    // password to the server, and neither it nor its hash leads back to the KEK.
    function deriveSecrets(password, salt, iterations) {
        if (!(iterations >= MIN_ITERATIONS)) { return Promise.reject(new Error('The vault key settings are too weak.')); }
        return crypto.subtle.importKey('raw', utf8(password), 'PBKDF2', false, ['deriveBits']).then(function (base) {
            return crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: salt, iterations: iterations }, base, 256);
        }).then(function (bits) {
            return Promise.all([
                crypto.subtle.importKey('raw', bits, { name: 'AES-GCM' }, false, ['wrapKey', 'unwrapKey']),
                crypto.subtle.importKey('raw', bits, 'HKDF', false, ['deriveBits']).then(function (ikm) {
                    return crypto.subtle.deriveBits({ name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(0), info: AUTH_INFO }, ikm, 256);
                })
            ]).then(function (pair) {
                new Uint8Array(bits).fill(0);
                return { kek: pair[0], auth: toB64(pair[1]) };
            });
        });
    }

    // Wrapped vault key → {key, auth}. Rejects (OperationError) on a wrong master password: GCM authentication fails.
    function unwrap(password, info) {
        return deriveSecrets(password, fromB64(info.salt), info.iterations).then(function (secrets) {
            return crypto.subtle.unwrapKey('raw', fromB64(info.wrappedKey), secrets.kek,
                { name: 'AES-GCM', iv: fromB64(info.wrapIv), additionalData: AAD_KEY },
                { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt'])
                .then(function (key) { return { key: key, auth: secrets.auth }; });
        });
    }

    // Vault key → the wrapping the server stores, plus the new auth key for it to hash.
    function wrap(password, key) {
        var salt = random(16), iv = random(12), auth;
        return deriveSecrets(password, salt, MIN_ITERATIONS).then(function (secrets) {
            auth = secrets.auth;
            return crypto.subtle.wrapKey('raw', key, secrets.kek, { name: 'AES-GCM', iv: iv, additionalData: AAD_KEY });
        }).then(function (wrapped) {
            return { kdf: 'PBKDF2-SHA256', iterations: MIN_ITERATIONS, salt: toB64(salt), wrappedKey: toB64(wrapped), wrapIv: toB64(iv), auth: auth };
        });
    }

    function encryptEntry(data, key) {
        var iv = random(12);
        return crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv, additionalData: AAD_ENTRY }, key || vaultKey, utf8(JSON.stringify(data)))
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
    // Words, keyboard runs and fragments that guessing tools try first; matched after undoing l33t substitutions.
    var COMMON_PARTS = ['password', 'passwort', 'pass', 'qwerty', 'asdf', 'zxcv', 'letmein', 'welcome', 'admin', 'login',
        'iloveyou', 'love', 'monkey', 'dragon', 'master', 'secret', 'sunshine', 'princess', 'football', 'cricket', 'shadow',
        'superman', 'batman', 'trustno', 'whatever', 'freedom', 'hello', 'summer', 'winter', 'india', 'google', 'gmail',
        'facebook', 'bank', 'money', 'vault', 'modexbyte'];
    var LEET = { '0': 'o', '1': 'i', '3': 'e', '4': 'a', '5': 's', '7': 't', '@': 'a', '$': 's', '!': 'i' };

    // A rough guess-resistance estimate: common words, years, sequences (abc, 4321) and repeats count for almost
    // nothing; only the rest is scored by length and character variety.
    function strengthBits(password) {
        var lower = password.toLowerCase(), plain = lower.replace(/[013457@$!]/g, function (c) { return LEET[c]; });
        var predictable = new Array(password.length).fill(false), chunks = 0, i, j;
        function mark(from, to) { chunks++; for (var k = from; k < to; k++) { predictable[k] = true; } }
        COMMON_PARTS.forEach(function (word) {
            for (var at = plain.indexOf(word); at !== -1; at = plain.indexOf(word, at + 1)) { mark(at, at + word.length); }
        });
        lower.replace(/(?:19|20)\d\d/g, function (m, at) { mark(at, at + m.length); return m; });
        for (i = 0; i < lower.length; i = j) {
            var step = lower.charCodeAt(i + 1) - lower.charCodeAt(i);
            for (j = i + 1; j < lower.length && lower.charCodeAt(j) - lower.charCodeAt(j - 1) === step && Math.abs(step) <= 1; j++) { /* run */ }
            if (j - i >= 3) { mark(i, j); } else { j = i + 1; }
        }
        var rest = password.split('').filter(function (c, k) { return !predictable[k]; }).join('');
        var pool = 0;
        if (/[a-z]/.test(rest)) { pool += 26; }
        if (/[A-Z]/.test(rest)) { pool += 26; }
        if (/[0-9]/.test(rest)) { pool += 10; }
        if (/[^a-zA-Z0-9]/.test(rest)) { pool += 33; }
        var unique = new Set(rest).size;
        return Math.round(Math.min(rest.length, unique * 2) * Math.log2(Math.max(pool, 1)) + chunks * 4);
    }
    // Why a new master password is not good enough, or '' when it is.
    function masterPasswordProblem(password) {
        if (password.length < MIN_MASTER_LENGTH) { return 'Use at least ' + MIN_MASTER_LENGTH + ' characters for the master password.'; }
        if (strengthBits(password) < MIN_MASTER_BITS) {
            return 'That master password is too easy to guess. Avoid common words, names, years, sequences like 1234 or abcd and repeated characters — four or five unrelated words work well.';
        }
        return '';
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
    // Browsers only let a focused page write the clipboard, and the usual flow is copy → switch to the bank's tab. So
    // the clear is retried each time this tab gets focus again until it succeeds. Best effort: the OS clipboard
    // history (Windows: Win+V) keeps its own copy.
    var clipboardDirty = false;
    function clearClipboard() {
        if (!clipboardDirty || !navigator.clipboard || !document.hasFocus()) { return; }
        navigator.clipboard.writeText('').then(function () { clipboardDirty = false; }, function () {});
    }
    function copy(text, what) {
        var done = function () {
            message(what + ' copied. It is cleared from the clipboard after 30 seconds (or when you return to this tab).');
            clearTimeout(clipboardTimer);
            clipboardTimer = setTimeout(function () { clipboardTimer = null; clipboardDirty = true; clearClipboard(); }, CLIPBOARD_CLEAR_MS);
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

    function fetchState() {
        return api('GET', root.dataset.stateUrl).then(function (state) { keyInfo = state.key; return state; });
    }

    function load() {
        return fetchState().then(function (state) {
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
        authKey = null;
        entries = [];
        if (clipboardTimer) { clearTimeout(clipboardTimer); clipboardTimer = null; clipboardDirty = true; clearClipboard(); }
        current = null;
        setRevealAll(false);
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
    var revealAll = false;   // list-wide "Show all passwords"; reset on lock
    // A masked secret with Show / Hide and Copy; used by the list and the detail panel.
    function secretControl(value, label, tag, startShown) {
        var wrap = make('span', { className: 'v-secret-ctl' });
        var shown = make(tag || 'span', { className: 'v-secret' }, startShown ? value : MASK);
        var toggle = make('button', { type: 'button', className: 'btn secondary xs' }, startShown ? 'Hide' : 'Show');
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
            if (secret && secret.value) { secretCell.appendChild(secretControl(secret.value, secret.label, 'span', revealAll)); } else { secretCell.textContent = '—'; }
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
        var form = this, pass = el('v-setup-pass').value, problem = masterPasswordProblem(pass);
        if (problem) { message(problem, 'danger'); return; }
        if (pass !== el('v-setup-confirm').value) { message('The two master passwords do not match.', 'danger'); return; }
        busy(form, true);
        message('Creating your vault…');
        var key;
        crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']).then(function (k) {
            key = k;
            return wrap(pass, key);
        }).then(function (wrapped) {
            // Unwrap again so the key kept in memory is non-extractable.
            return api('POST', root.dataset.setupUrl, wrapped).then(function () { return unwrap(pass, wrapped); });
        }).then(function (opened) {
            vaultKey = opened.key;
            authKey = opened.auth;
            el('v-setup-pass').value = el('v-setup-confirm').value = '';
            message('Vault created and unlocked.');
            return load();
        }).catch(function (error) { message(error.message, 'danger'); }).finally(function () { busy(form, false); });
    });

    var failures = 0;
    el('v-unlock').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var form = this, input = el('v-unlock-pass'), password = input.value;
        input.value = '';
        busy(form, true);
        message('Unlocking…');
        // Fresh key settings first: another tab may have changed the master password since this page loaded.
        fetchState().then(function (state) {
            if (!state.setUp) { return load(); }
            return unwrap(password, keyInfo).then(function (opened) {
                vaultKey = opened.key;
                authKey = opened.auth;
                failures = 0;
                message('');
                // A vault from before ADR-094 registers its auth key now (409: another tab already did).
                var registered = keyInfo.hasAuth ? Promise.resolve()
                    : api('POST', root.dataset.authUrl, { auth: authKey }).catch(function (error) { if (error.status !== 409) { throw error; } });
                return registered.then(load); // lock() dropped the entries: fetch them again and decrypt with the key
            }, function () {
                failures++;
                message('Wrong master password.', 'danger');
                // Slow down guessing at the keyboard (an offline attacker is held back by PBKDF2's cost instead).
                return new Promise(function (resolve) { setTimeout(resolve, Math.min(30000, 1000 * Math.pow(2, failures - 1))); });
            });
        }).catch(function (error) { message(error.message, 'danger'); }).finally(function () { busy(form, false); input.focus(); });
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

    function setRevealAll(on) {
        revealAll = on;
        el('v-reveal-all').textContent = on ? 'Hide all passwords' : 'Show all passwords';
        el('v-reveal-all').setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    el('v-reveal-all').addEventListener('click', function () { setRevealAll(!revealAll); renderList(); });
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
        var form = this, next = el('v-master-new').value, problem = masterPasswordProblem(next);
        if (problem) { message(problem, 'danger'); return; }
        if (next !== el('v-master-confirm').value) { message('The two new master passwords do not match.', 'danger'); return; }
        if (entries.some(function (e) { return !e.data; })) {
            message('Some entries cannot be decrypted. Delete them before changing the master password.', 'danger');
            return;
        }
        busy(form, true);
        message('Changing the master password and re-encrypting ' + entries.length + ' entries…');
        // A brand-new vault key, with every entry re-encrypted under it (ADR-094). The current password's auth key
        // proves to the server that whoever asks knows it.
        var currentAuth;
        unwrap(el('v-master-current').value, keyInfo).then(function (opened) { currentAuth = opened.auth; },
            function () { throw new Error('The current master password is wrong.'); }).then(function () {
            return crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
        }).then(function (newKey) {
            return Promise.all([wrap(next, newKey)].concat(entries.map(function (e) {
                return encryptEntry(e.data, newKey).then(function (sealed) { return { id: e.id, version: e.version, ciphertext: sealed.ciphertext, iv: sealed.iv }; });
            })));
        }).then(function (parts) {
            var wrapped = parts[0];
            return api('POST', root.dataset.masterUrl, { key: wrapped, entries: parts.slice(1) }, currentAuth)
                .then(function () { return unwrap(next, wrapped); }); // keep a non-extractable copy of the new key
        }).then(function (opened) {
            vaultKey = opened.key;
            authKey = opened.auth;
            ['v-master-current', 'v-master-new', 'v-master-confirm'].forEach(function (id) { el(id).value = ''; });
            message('Master password changed and the vault re-encrypted with a new key.');
            return load();
        }).catch(function (error) { message(error.message, 'danger'); }).finally(function () { busy(form, false); });
    });

    ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function (name) { document.addEventListener(name, touch, { passive: true }); });
    window.addEventListener('pagehide', function () { lock(); });
    window.addEventListener('focus', clearClipboard);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { clearClipboard(); } });

    Object.keys(TYPES).forEach(function (k) {
        el('v-type-filter').appendChild(make('option', { value: k }, TYPES[k].label));
        el('v-edit-type').appendChild(make('option', { value: k }, TYPES[k].label));
    });
    load();
})();
