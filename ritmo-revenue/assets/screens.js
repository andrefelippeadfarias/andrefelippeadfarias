/*
 * Ritmo Revenue: the central screens. Plain ES2017, no library, no build. Every action works
 * without this file (forms post and redirect); this only enhances. Texts come from data-*
 * attributes printed (translated and escaped) by PHP.
 */
(function () {
    'use strict';

    var root = document.documentElement;
    root.classList.add('ritmo-js');

    function each(selector, fn, scope) {
        Array.prototype.forEach.call((scope || document).querySelectorAll(selector), fn);
    }

    function say(region, text) {
        if (region) {
            region.textContent = text;
        }
    }

    function format(template, values) {
        return template.replace(/%(\d)\$s/g, function (match, index) {
            var value = values[Number(index) - 1];
            return value === undefined ? match : String(value);
        });
    }

    /* Typed confirmation: the submit button stays disabled until the text matches (the server checks anyway). */
    each('[data-ritmo-js="confirm-typed"] input[data-expected]', function (input) {
        var form = input.form;
        var button = form && form.querySelector('button[type="submit"], button:not([type])');
        if (!button || input.closest('.ritmo-listing-row')) {
            return;
        }
        var check = function () {
            button.disabled = input.value.trim() !== input.getAttribute('data-expected');
        };
        input.addEventListener('input', check);
        check();
    });

    /* Focus the heading of an opened detail (night, alert, run) so keyboard and screen readers land on it. */
    each('[data-ritmo-focus]', function (heading) {
        if (window.location.hash && heading.closest(window.location.hash)) {
            window.addEventListener('load', function () {
                window.setTimeout(function () { heading.focus(); }, 0);
            });
        }
    });

    /* Approval selection: room and segment boxes tick their nights; a group goes together; live totals. */
    each('[data-ritmo-js="selection-tree"]', function (form) {
        var total = form.querySelector('[data-ritmo="selection-total"]');
        var nights = function (scope) {
            return Array.prototype.slice.call(scope.querySelectorAll('input[name="entries[]"]'));
        };
        var refresh = function () {
            each('.ritmo-segment, .ritmo-room', function (box) {
                var own = box.querySelector(':scope > summary input[data-level]');
                if (!own) {
                    return;
                }
                var all = nights(box);
                var ticked = all.filter(function (n) { return n.checked; }).length;
                own.checked = ticked > 0 && ticked === all.length;
                own.indeterminate = ticked > 0 && ticked < all.length;
                own.setAttribute('aria-checked', own.indeterminate ? 'mixed' : String(own.checked));
            }, form);
            var chosen = nights(form).filter(function (n) { return n.checked; });
            var up = 0;
            var down = 0;
            chosen.forEach(function (n) {
                var row = n.closest('[data-direction]');
                var direction = row ? row.getAttribute('data-direction') : '';
                up += direction === 'up' ? 1 : 0;
                down += direction === 'down' ? 1 : 0;
            });
            if (total) {
                say(total, format(form.getAttribute('data-text-total') || '', [chosen.length, up, down]));
            }
        };
        each('input[data-level]', function (box) {
            box.hidden = false;
            box.addEventListener('click', function (event) {
                event.stopPropagation();
            });
            box.addEventListener('change', function () {
                var scope = box.closest('details');
                nights(scope).forEach(function (n) { n.checked = box.checked; });
                refresh();
            });
        }, form);
        nights(form).forEach(function (input) {
            input.addEventListener('change', function () {
                var row = input.closest('[data-group]');
                var group = row ? row.getAttribute('data-group') : '';
                var linked = false;
                nights(form).forEach(function (other) {
                    var otherRow = other.closest('[data-group]');
                    if (other !== input && otherRow && otherRow.getAttribute('data-group') === group && other.checked !== input.checked) {
                        other.checked = input.checked;
                        linked = true;
                    }
                });
                refresh();
                if (linked && total) {
                    say(total, total.textContent + ' ' + (form.getAttribute('data-text-linked') || ''));
                }
            });
        });
        refresh();
    });

    /* A form that can answer in place through the REST API (connection test); falls back to a normal post. */
    each('[data-ritmo-js="async-form"]', function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.fetch) {
                return;
            }
            event.preventDefault();
            var region = document.querySelector('[data-ritmo="' + form.getAttribute('data-result') + '"]');
            var spinner = form.querySelector('.spinner');
            if (spinner) {
                spinner.classList.add('is-active');
            }
            fetch(form.getAttribute('data-rest-url'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': form.getAttribute('data-rest-nonce') }
            }).then(function (response) {
                return response.json();
            }).then(function (body) {
                say(region, body.text || body.message || '');
            }).catch(function () {
                form.submit();
            }).then(function () {
                if (spinner) {
                    spinner.classList.remove('is-active');
                }
            });
        });
    });

    /* The progress of an approved write: one poll per page, every few seconds, until a final status. */
    var poller = document.querySelector('[data-ritmo-js="poll"]');
    if (poller && window.fetch) {
        var calls = 0;
        var max = Number(poller.getAttribute('data-max') || 24);
        var status = poller.querySelector('[data-ritmo-status]');
        var spinner = poller.querySelector('.spinner');
        var tick = function () {
            calls += 1;
            fetch(poller.getAttribute('data-rest-url'), {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': poller.getAttribute('data-rest-nonce') }
            }).then(function (response) {
                return response.json();
            }).then(function (body) {
                say(status, body.text || '');
                if (body.final) {
                    if (spinner) {
                        spinner.classList.remove('is-active');
                    }
                    return;
                }
                if (calls >= max) {
                    say(status, poller.getAttribute('data-timeout-text') || '');
                    if (spinner) {
                        spinner.classList.remove('is-active');
                    }
                    return;
                }
                window.setTimeout(tick, Number(poller.getAttribute('data-interval') || 5) * 1000);
            }).catch(function () {
                say(status, poller.getAttribute('data-timeout-text') || '');
            });
        };
        window.setTimeout(tick, Number(poller.getAttribute('data-interval') || 5) * 1000);
    }

    /* On a phone the filters start folded, so the grid is the first thing seen. */
    if (window.matchMedia && window.matchMedia('(max-width: 600px)').matches) {
        each('details[data-ritmo-js="fold-narrow"]', function (details) {
            details.open = false;
        });
    }

    /* The property id is suggested from its name (lowercase ASCII and hyphens) until it is edited. */
    each('[data-ritmo-js="slug-from"]', function (input) {
        var source = document.getElementById(input.getAttribute('data-source'));
        var edited = false;
        input.addEventListener('input', function () { edited = true; });
        if (source) {
            source.addEventListener('input', function () {
                if (!edited) {
                    input.value = source.value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 32);
                }
            });
        }
    });

    /* The shadow run button: runs through the REST API and reloads when it ends (the form posts without JS). */
    var SHADOW_MAX_CALLS = 30;

    function shadowState(form, state, text) {
        form.setAttribute('data-ritmo-state', state);
        var progress = form.querySelector('[data-ritmo="progress"]');
        if (progress) {
            progress.textContent = text || '';
        }
    }

    function shadowCall(button, form, calls) {
        return fetch(button.getAttribute('data-rest-url'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': button.getAttribute('data-rest-nonce'), 'Accept': 'application/json' }
        }).then(function (response) {
            return response.json().then(function (body) {
                body = body || {};
                if (response.ok && body.status === 'running' && calls < SHADOW_MAX_CALLS) {
                    shadowState(form, 'running', button.getAttribute('data-text-running'));
                    return shadowCall(button, form, calls + 1);
                }
                if (response.ok && body.status === 'completed') {
                    shadowState(form, 'done', '');
                    window.location.reload();
                    return null;
                }
                shadowState(form, 'error', button.getAttribute('data-text-failed') + ' ' + (body.message || ''));
                button.disabled = false;
                return null;
            });
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-ritmo="run-shadow"]');
        if (!button || !window.fetch || !button.getAttribute('data-rest-url')) {
            return;
        }
        event.preventDefault();
        var form = button.closest('form');
        button.disabled = true;
        shadowState(form, 'running', button.getAttribute('data-text-running'));
        shadowCall(button, form, 1).catch(function () {
            shadowState(form, 'error', button.getAttribute('data-text-failed'));
            button.disabled = false;
        });
    });
}());
