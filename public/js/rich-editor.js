/*
 * A small rich-text editor (ADR-118) for any <textarea data-rich-editor>: a toolbar plus an editable area. The
 * textarea stays the form field (hidden) and is kept in step with the editor, so the form posts HTML. The server
 * cleans it (App\Service\Text\RichText) — nothing here is trusted. Without JavaScript the plain textarea is used.
 */
(function () {
    'use strict';

    var BUTTONS = [
        { cmd: 'bold', label: 'B', title: 'Bold (Ctrl+B)', style: 'font-weight:700' },
        { cmd: 'italic', label: 'I', title: 'Italic (Ctrl+I)', style: 'font-style:italic' },
        { cmd: 'underline', label: 'U', title: 'Underline (Ctrl+U)', style: 'text-decoration:underline' },
        { cmd: 'strikeThrough', label: 'S', title: 'Strikethrough', style: 'text-decoration:line-through' },
        { sep: true },
        { cmd: 'formatBlock', arg: 'h3', label: 'H', title: 'Heading' },
        { cmd: 'formatBlock', arg: 'p', label: '¶', title: 'Normal text' },
        { cmd: 'formatBlock', arg: 'blockquote', label: '❝', title: 'Quote' },
        { sep: true },
        { cmd: 'insertUnorderedList', label: '• List', title: 'Bulleted list' },
        { cmd: 'insertOrderedList', label: '1. List', title: 'Numbered list' },
        { sep: true },
        { cmd: 'createLink', label: 'Link', title: 'Add a link to the selected text' },
        { cmd: 'unlink', label: 'Unlink', title: 'Remove link' },
        { cmd: 'removeFormat', label: 'Clear', title: 'Clear formatting' },
        { sep: true },
        { cmd: 'undo', label: '↶', title: 'Undo (Ctrl+Z)' },
        { cmd: 'redo', label: '↷', title: 'Redo (Ctrl+Y)' }
    ];

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function isPlain(value) { return !/<\/?[a-z][a-z0-9]*(\s[^<>]*)?\/?>/i.test(value); }

    function enhance(textarea) {
        var wrap = document.createElement('div');
        wrap.className = 'rte';
        var bar = document.createElement('div');
        bar.className = 'rte-toolbar';
        bar.setAttribute('role', 'toolbar');
        bar.setAttribute('aria-label', 'Formatting');
        var area = document.createElement('div');
        area.className = 'rte-area';
        area.contentEditable = 'true';
        area.setAttribute('role', 'textbox');
        area.setAttribute('aria-multiline', 'true');
        var label = textarea.id && document.querySelector('label[for="' + textarea.id + '"]');
        if (label) {
            if (!label.id) { label.id = textarea.id + '-label'; }
            area.setAttribute('aria-labelledby', label.id);
            label.addEventListener('click', function () { area.focus(); });
        }
        if (textarea.placeholder) { area.dataset.placeholder = textarea.placeholder; }
        var value = textarea.value;
        area.innerHTML = value.trim() === '' ? '' : (isPlain(value) ? escapeHtml(value).replace(/\n/g, '<br>') : value);

        BUTTONS.forEach(function (b) {
            if (b.sep) { var s = document.createElement('span'); s.className = 'rte-sep'; bar.appendChild(s); return; }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'rte-btn';
            btn.textContent = b.label;
            btn.title = b.title;
            btn.setAttribute('aria-label', b.title);
            if (b.style) { btn.setAttribute('style', b.style); }
            btn.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep the selection
            btn.addEventListener('click', function () {
                area.focus();
                if (b.cmd === 'createLink') {
                    var url = window.prompt('Link address (https://…)', 'https://');
                    if (!url || url === 'https://') { return; }
                    if (!/^(https?:\/\/|mailto:)/i.test(url)) { url = 'https://' + url; }
                    document.execCommand('createLink', false, url);
                } else if (b.cmd === 'formatBlock') {
                    document.execCommand('formatBlock', false, '<' + b.arg + '>');
                } else {
                    document.execCommand(b.cmd, false, null);
                }
                sync();
                refresh();
            });
            bar.appendChild(btn);
        });

        function sync() { textarea.value = area.textContent.trim() === '' && !area.querySelector('li,hr') ? '' : area.innerHTML; }
        function refresh() {
            bar.querySelectorAll('.rte-btn').forEach(function (btn, i) {
                var b = BUTTONS.filter(function (x) { return !x.sep; })[i];
                var on = false;
                try { on = ['bold', 'italic', 'underline', 'strikeThrough', 'insertUnorderedList', 'insertOrderedList'].indexOf(b.cmd) >= 0 && document.queryCommandState(b.cmd); } catch (e) { on = false; }
                btn.classList.toggle('on', on);
            });
        }

        // Paste as plain text: formatting from Word or web pages is mostly noise (and gets stripped server-side anyway).
        area.addEventListener('paste', function (e) {
            var text = (e.clipboardData || window.clipboardData).getData('text/plain');
            e.preventDefault();
            document.execCommand('insertHTML', false, escapeHtml(text).replace(/\n/g, '<br>'));
        });
        area.addEventListener('input', sync);
        area.addEventListener('keyup', refresh);
        area.addEventListener('mouseup', refresh);
        textarea.form && textarea.form.addEventListener('submit', sync);

        try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* older browsers */ }
        textarea.hidden = true;
        textarea.removeAttribute('required');
        wrap.appendChild(bar);
        wrap.appendChild(area);
        textarea.parentNode.insertBefore(wrap, textarea.nextSibling);
        if (textarea.rows) { area.style.minHeight = Math.max(8, textarea.rows) * 1.6 + 'em'; }
    }

    function init() { document.querySelectorAll('textarea[data-rich-editor]').forEach(enhance); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
