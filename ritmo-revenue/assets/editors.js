/*
 * Rules, Orders and Market screens of Ritmo Revenue. Plain JavaScript, no library: every action
 * works without it (forms post and redirect). It only enables a submit button once the typed
 * confirmation matches the text shown next to it; the server checks again anyway.
 */
(function () {
    'use strict';

    function bindConfirmation(input) {
        var form = input.form;
        if (!form) {
            return;
        }
        var buttons = form.querySelectorAll('button[type="submit"]');
        var expected = input.getAttribute('data-expected') || '';
        var update = function () {
            var matches = input.value.trim() === expected;
            Array.prototype.forEach.call(buttons, function (button) {
                button.disabled = !matches;
            });
        };
        input.addEventListener('input', update);
        update();
    }

    function focusErrorSummary() {
        var summary = document.querySelector('.ritmo-editors [data-ritmo="error-summary"]');
        if (summary) {
            summary.focus();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.ritmo-editors [data-ritmo-js="confirm-typed"]'), bindConfirmation);
        focusErrorSummary();
    });
}());
