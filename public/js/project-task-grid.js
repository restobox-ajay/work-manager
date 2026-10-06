// Project tasks grid (ADR-083): add and remove new rows. The server reads whatever rows are posted, so this only
// edits the form.
(function () {
    'use strict';

    document.querySelectorAll('[data-ptg]').forEach(function (grid) {
        var rows = grid.querySelector('[data-ptg-rows]');
        var template = grid.querySelector('[data-ptg-template]');
        var addButton = grid.parentNode.querySelector('[data-ptg-add]');
        var next = rows.querySelectorAll('.ptg-row').length;

        function addRow() {
            rows.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__i__/g, String(next++)));
            var first = rows.lastElementChild && rows.lastElementChild.querySelector('input[type="text"]');
            if (first) { first.focus(); }
        }

        if (addButton) { addButton.addEventListener('click', addRow); }

        grid.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-ptg-remove]');
            if (remove) { remove.closest('.ptg-row').remove(); }
        });

        if (rows.querySelectorAll('.ptg-row').length === 0) { addRow(); }
    });
})();
