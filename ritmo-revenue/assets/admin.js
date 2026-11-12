/**
 * Ritmo Revenue admin: runs the shadow run through the REST API (ritmo/v1) and reloads the
 * dashboard when it ends. Without JavaScript the same button posts the form to admin-post.php.
 */
(function () {
    'use strict';

    var MAX_CALLS = 30;

    function setState(form, state, text) {
        form.setAttribute('data-ritmo-state', state);
        var progress = form.querySelector('[data-ritmo="progress"]');
        if (progress) {
            progress.textContent = text || '';
        }
    }

    function call(button) {
        return fetch(button.getAttribute('data-rest-url'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': button.getAttribute('data-rest-nonce'), 'Accept': 'application/json' }
        }).then(function (response) {
            return response.json().then(function (body) {
                return { ok: response.ok, body: body };
            });
        });
    }

    function run(button, form, calls) {
        return call(button).then(function (answer) {
            var body = answer.body || {};
            if (answer.ok && body.status === 'running' && calls < MAX_CALLS) {
                setState(form, 'running', button.getAttribute('data-text-running') + ' (' + body.stage + ')');
                return run(button, form, calls + 1);
            }
            if (answer.ok && body.status === 'completed') {
                setState(form, 'done', '');
                window.location.reload();
                return null;
            }
            var reason = body.error || body.message || body.status || '';
            setState(form, 'error', button.getAttribute('data-text-failed') + ' ' + reason);
            button.disabled = false;
            return null;
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-ritmo="run-shadow"]');
        if (!button || !window.fetch) {
            return;
        }
        event.preventDefault();
        var form = button.closest('form');
        button.disabled = true;
        setState(form, 'running', button.getAttribute('data-text-running'));
        run(button, form, 1).catch(function (failure) {
            setState(form, 'error', button.getAttribute('data-text-failed') + ' ' + failure.message);
            button.disabled = false;
        });
    });
}());
