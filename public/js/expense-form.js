// Expense form (ADR-097): the optional payment-details field follows "Paid by" — its label asks for the UPI id,
// cheque number, bank reference… and it is hidden for cash. The server decides what is kept; this is only the UI.
(function () {
    'use strict';

    var method = document.getElementById('f-method');
    var field = document.getElementById('f-payment-details-field');
    if (!method || !field) { return; }

    var label = field.querySelector('label');
    var labels;
    try { labels = JSON.parse(method.dataset.paymentLabels || '{}'); } catch (e) { labels = {}; }

    function sync() {
        var text = labels[method.value];
        field.hidden = !text;
        if (text) { label.textContent = text; }
    }

    method.addEventListener('change', sync);
    sync();
})();
