// Invoice form (ADR-077): add/remove item rows, live totals, and filling Billed By / Billed To when a billing
// profile or client is chosen. The server recomputes everything on save; this is only a preview.
(function () {
    'use strict';

    var form = document.getElementById('invoice-form');
    if (!form) { return; }

    var lines = form.querySelector('[data-lines]');
    var template = document.getElementById('inv-line-template');
    var currencySelect = form.querySelector('[data-currency]');
    var currencyInput = form.querySelector('input[name="currency"]');
    var symbols = { USD: '$', CAD: '$', AUD: '$', INR: '₹', EUR: '€', GBP: '£' };
    var nextIndex = lines.querySelectorAll('.inv-line').length;

    function readJson(id) {
        var node = document.getElementById(id);
        try { return node ? JSON.parse(node.textContent) : []; } catch (e) { return []; }
    }

    // Same arithmetic as InvoiceMoney: whole hundredths, rounded half away from zero.
    function hundredths(value) {
        var m = String(value || '').replace(/[,\s]/g, '').match(/^(-?)(\d{1,10})(?:\.(\d{1,2}))?$/);
        if (!m) { return null; }
        var h = parseInt(m[2], 10) * 100 + parseInt(((m[3] || '') + '00').slice(0, 2), 10);
        return m[1] === '-' ? -h : h;
    }
    function divRound(n, d) { var q = n / d; return q < 0 ? -Math.round(-q) : Math.round(q); }
    function currency() { return (currencySelect ? currencySelect.value : (currencyInput ? currencyInput.value : 'USD')) || 'USD'; }
    function money(h) {
        var code = currency();
        var symbol = symbols[code] || code + ' ';
        var abs = Math.abs(h);
        var text = (Math.floor(abs / 100)).toLocaleString('en-US') + '.' + String(abs % 100).padStart(2, '0');
        return (h < 0 ? '-' : '') + symbol + text;
    }

    // 125050 → "1250.50", the way the Amount and Rate fields are filled in.
    function plain(h) { return (h < 0 ? '-' : '') + Math.floor(Math.abs(h) / 100) + '.' + String(Math.abs(h) % 100).padStart(2, '0'); }

    // Quantity × Rate, or null while either is not a number.
    function lineAmount(row) {
        var q = hundredths(row.querySelector('[data-qty]').value);
        var r = hundredths(row.querySelector('[data-rate]').value);
        return q === null || r === null ? null : divRound(q * r, 100);
    }

    function recalc() {
        var sum = { amount: 0, cgst: 0, sgst: 0 };
        var rows = lines.querySelectorAll('.inv-line');
        rows.forEach(function (row, i) {
            row.querySelector('.inv-pos').textContent = (i + 1) + '.';
            var amountField = row.querySelector('[data-amount]');
            var amount = amountField.value.trim() === '' ? lineAmount(row) : hundredths(amountField.value);
            var g = hundredths(row.querySelector('[data-gst]').value || '0');
            if (amount === null || g === null) {
                row.querySelector('[data-total]').textContent = '—';
                return;
            }
            var half = divRound(amount * g, 20000);
            row.querySelector('[data-total]').textContent = money(amount + 2 * half);
            sum.amount += amount; sum.cgst += half; sum.sgst += half;
        });
        form.querySelector('[data-sum="amount"]').textContent = money(sum.amount);
        form.querySelector('[data-sum="cgst"]').textContent = money(sum.cgst);
        form.querySelector('[data-sum="sgst"]').textContent = money(sum.sgst);
        form.querySelector('[data-sum="total"]').textContent = money(sum.amount + sum.cgst + sum.sgst);
        form.querySelectorAll('[data-tax-row]').forEach(function (tr) { tr.hidden = sum.cgst === 0 && sum.sgst === 0; });
        form.querySelectorAll('[data-currency-label]').forEach(function (label) { label.textContent = currency(); });
    }

    function addLine() {
        var html = template.innerHTML.replace(/__i__/g, String(nextIndex++));
        lines.insertAdjacentHTML('beforeend', html);
        recalc();
        var added = lines.lastElementChild && lines.lastElementChild.querySelector('input[type="text"]');
        if (added) { added.focus(); }
    }

    form.querySelector('[data-add-line]').addEventListener('click', addLine);
    lines.addEventListener('click', function (event) {
        var button = event.target.closest('[data-remove]');
        if (!button) { return; }
        button.closest('.inv-line').remove();
        recalc();
    });
    form.addEventListener('input', function (event) {
        var row = event.target.closest('.inv-line');
        if (!row) { return; }
        if (event.target.matches('[data-qty], [data-rate]')) {
            // Quantity or Rate changed: Amount follows while both are filled; emptying one keeps the Amount typed.
            var amount = lineAmount(row);
            if (amount !== null) { row.querySelector('[data-amount]').value = plain(amount); }
        } else if (event.target.matches('[data-amount]')) {
            // Amount typed by hand: Rate follows (Amount ÷ Quantity), Amount stays as typed.
            var typed = hundredths(event.target.value);
            var q = hundredths(row.querySelector('[data-qty]').value);
            if (typed !== null && q !== null && q > 0) { row.querySelector('[data-rate]').value = plain(divRound(typed * 100, q)); }
        }
        if (event.target.matches('[data-qty], [data-rate], [data-amount], [data-gst]')) { recalc(); }
    });
    form.addEventListener('change', function (event) {
        if (event.target.matches('[data-gst]')) { recalc(); }
    });
    if (currencySelect) { currencySelect.addEventListener('change', recalc); }

    // Choosing a billing profile / client overwrites the block's fields with its saved details.
    function filler(selector, records) {
        var select = form.querySelector(selector);
        if (!select) { return; }
        select.addEventListener('change', function () {
            var record = records.filter(function (r) { return String(r.id) === select.value; })[0];
            if (!record) { return; }
            Object.keys(record).forEach(function (field) {
                var input = form.querySelector('[name="' + field + '"]');
                if (input && field !== 'id') { input.value = record[field] || ''; }
            });
        });
    }
    filler('[data-profile]', readJson('inv-profiles'));
    filler('[data-client]', readJson('inv-clients'));

    if (lines.querySelectorAll('.inv-line').length === 0) { addLine(); } else { recalc(); }
})();
