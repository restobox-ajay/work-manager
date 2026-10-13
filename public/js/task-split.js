// Split task (ADR-139): add and remove rows in place, number them, and preview Total / Diff against the original
// payout. Without JavaScript the Add / Remove links reload the page with one row more or fewer.
(function () {
    'use strict';

    var form = document.getElementById('task-split-form');
    if (!form) { return; }

    var body = form.querySelector('[data-split-rows]');
    var template = document.getElementById('split-row-template');
    var addLink = form.querySelector('[data-split-add]');
    var totals = form.querySelector('[data-split-original]');
    var nextIndex = body.querySelectorAll('[data-split-row]').length;

    // Whole hundredths, so 0.1 + 0.2 adds up exactly; null when the text is not an amount.
    function hundredths(value) {
        var m = String(value || '').replace(/[,\s]/g, '').match(/^(-?)(\d{1,10})(?:\.(\d{1,2}))?$/);
        if (!m) { return null; }
        var h = parseInt(m[2], 10) * 100 + parseInt(((m[3] || '') + '00').slice(0, 2), 10);
        return m[1] === '-' ? -h : h;
    }
    function plain(h) { return (h < 0 ? '-' : '') + Math.floor(Math.abs(h) / 100) + '.' + String(Math.abs(h) % 100).padStart(2, '0'); }

    function refresh() {
        var rows = body.querySelectorAll('[data-split-row]');
        rows.forEach(function (row, i) { row.querySelector('[data-split-number]').textContent = String(i + 1); });
        // .btn sets its own display, which beats [hidden]
        if (addLink) { addLink.style.display = rows.length - 1 >= parseInt(addLink.dataset.max, 10) ? 'none' : ''; }
        if (!totals) { return; }

        var sum = 0;
        var valid = true;
        body.querySelectorAll('[data-split-amount]').forEach(function (field) {
            var h = field.value.trim() === '' ? 0 : hundredths(field.value);
            if (h === null) { valid = false; } else { sum += h; }
        });
        var original = hundredths(totals.dataset.splitOriginal) || 0;
        totals.querySelector('[data-split-total]').textContent = valid ? plain(sum) : '—';
        totals.querySelector('[data-split-diff]').textContent = valid ? plain(sum - original) : '—';
    }

    if (addLink) {
        addLink.addEventListener('click', function (event) {
            event.preventDefault();
            body.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__i__/g, String(nextIndex++)));
            refresh();
            body.lastElementChild.querySelector('input').focus();
        });
    }

    body.addEventListener('click', function (event) {
        var remove = event.target.closest('[data-split-remove]');
        if (!remove) { return; }
        event.preventDefault();
        remove.closest('[data-split-row]').remove();
        refresh();
    });
    body.addEventListener('input', refresh);

    refresh();
})();
