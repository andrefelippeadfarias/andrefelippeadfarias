/*
 * Assistant of Ritmo Revenue. Plain JavaScript, no library, nothing loaded from elsewhere. Every
 * action works without it (the forms post and the screen shows the result); with it:
 * - the request form asks the REST route, says the assistant is working (an answer can take up to
 *   two minutes), then reloads the screen with the new draft, or shows why there is none;
 * - the "explain" buttons of the Alerts screen and the run reports ask their route and add the
 *   answer as text (never as markup).
 */
(function () {
    'use strict';

    // The answers (and refusals) of the routes come in the language of the person's profile, as
    // the rest of the screen, not in the site's.
    function withUserLocale(url) {
        return url + (url.indexOf('?') === -1 ? '?' : '&') + '_locale=user';
    }

    function post(url, nonce, body) {
        return window.fetch(withUserLocale(url), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify(body || {})
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (data) {
                return { ok: response.ok, data: data || {} };
            });
        });
    }

    function setText(element, text) {
        element.textContent = text;
    }

    function paragraphs(container, texts) {
        texts.forEach(function (text) {
            if (text) {
                var p = document.createElement('p');
                p.textContent = text;
                container.appendChild(p);
            }
        });
    }

    function listOf(container, items) {
        if (!items || !items.length) {
            return;
        }
        var list = document.createElement('ul');
        items.forEach(function (item) {
            var li = document.createElement('li');
            li.textContent = String(item);
            list.appendChild(li);
        });
        container.appendChild(list);
    }

    function busy(form, on) {
        var button = form.querySelector('button[type="submit"]');
        var spinner = form.querySelector('.spinner');
        form.setAttribute('aria-busy', on ? 'true' : 'false');
        if (button) {
            button.disabled = on;
        }
        if (spinner) {
            spinner.classList.toggle('is-active', on);
        }
    }

    function bindDraftForm(form) {
        var root = form.closest('[data-rest-url]');
        var status = form.querySelector('[data-ritmo="ai-draft-status"]');
        if (!root || !status || !window.fetch) {
            return;
        }
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var request = form.querySelector('textarea[name="request"]');
            var property = form.querySelector('input[name="property_id"]');
            busy(form, true);
            status.textContent = '';
            setText(status, root.getAttribute('data-busy-text') || '…');
            post(root.getAttribute('data-rest-url') + 'drafts', root.getAttribute('data-rest-nonce'), {
                property: property ? property.value : '',
                request: request ? request.value : ''
            }).then(function (result) {
                if (result.ok) {
                    window.location.reload();
                    return;
                }
                busy(form, false);
                status.textContent = '';
                paragraphs(status, [result.data.message || root.getAttribute('data-error-text')]);
                listOf(status, result.data.details);
            }).catch(function () {
                form.submit();
            });
        });
    }

    function bindExplain(button) {
        var box = button.closest('[data-ritmo="ai-explain"]');
        var result = box ? box.querySelector('[data-ritmo="ai-explain-result"]') : null;
        if (!box || !result || !window.fetch) {
            return;
        }
        box.hidden = false;
        button.addEventListener('click', function () {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            result.textContent = '';
            setText(result, button.getAttribute('data-busy-text'));
            post(button.getAttribute('data-rest-url'), button.getAttribute('data-rest-nonce'), {}).then(function (answer) {
                button.disabled = false;
                button.removeAttribute('aria-busy');
                result.textContent = '';
                if (!answer.ok) {
                    paragraphs(result, [answer.data.message || button.getAttribute('data-error-text')]);
                    listOf(result, answer.data.details);
                    return;
                }
                if (answer.data.explanation) {
                    paragraphs(result, [answer.data.explanation]);
                    if (answer.data.action) {
                        var p = document.createElement('p');
                        var strong = document.createElement('strong');
                        strong.textContent = button.getAttribute('data-action-label') + ' ';
                        p.appendChild(strong);
                        p.appendChild(document.createTextNode(answer.data.action));
                        result.appendChild(p);
                    }
                    return;
                }
                paragraphs(result, String(answer.data.text || '').split(/\n\s*\n/));
            }).catch(function () {
                button.disabled = false;
                button.removeAttribute('aria-busy');
                setText(result, button.getAttribute('data-error-text'));
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-ritmo-js="ai-draft-form"]'), bindDraftForm);
        Array.prototype.forEach.call(document.querySelectorAll('[data-ritmo-js="ai-explain"]'), bindExplain);
    });
}());
