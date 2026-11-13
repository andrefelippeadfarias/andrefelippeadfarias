/*
 * Rules, Orders and Market screens of Ritmo Revenue. Plain JavaScript, no library: every action
 * works without it (forms post and redirect). It enables a submit button once the typed
 * confirmation matches the text shown next to it (the server checks again anyway) and opens the
 * changes of a rules version in place.
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

    /*
     * The changes of a version open in place: the read-only compare route answers labels and
     * values already in words; they are added as text, never as markup. Without JavaScript (or
     * when the route fails) the link next to the button opens the comparison of the two versions.
     */
    function bindVersionChanges(button) {
        var table = button.closest('[data-rest-url]');
        var list = button.nextElementSibling;
        var status = list ? list.nextElementSibling : null;
        if (!table || !list || !status || typeof window.fetch !== 'function') {
            return;
        }
        var loaded = false;
        button.hidden = false;
        button.addEventListener('click', function () {
            var open = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', open ? 'false' : 'true');
            list.hidden = open;
            if (open || loaded) {
                return;
            }
            var url = new URL(table.getAttribute('data-rest-url'), window.location.href);
            url.searchParams.set('from', button.getAttribute('data-from'));
            url.searchParams.set('to', button.getAttribute('data-to'));
            url.searchParams.set('property', table.getAttribute('data-property') || '');
            window.fetch(url.toString(), { credentials: 'same-origin', headers: { 'X-WP-Nonce': table.getAttribute('data-rest-nonce') } })
                .then(function (response) {
                    return response.ok ? response.json() : Promise.reject(new Error('status'));
                })
                .then(function (body) {
                    loaded = true;
                    (body.changes || []).forEach(function (change) {
                        var item = document.createElement('li');
                        item.textContent = change.label + ': ' + change.before + ' → ' + change.after;
                        list.appendChild(item);
                    });
                    status.textContent = body.text || '';
                })
                .catch(function () {
                    status.textContent = table.getAttribute('data-error') || '';
                });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.ritmo-editors [data-ritmo-js="confirm-typed"]'), bindConfirmation);
        Array.prototype.forEach.call(document.querySelectorAll('.ritmo-editors [data-ritmo-js="version-changes"]'), bindVersionChanges);
        focusErrorSummary();
    });
}());
