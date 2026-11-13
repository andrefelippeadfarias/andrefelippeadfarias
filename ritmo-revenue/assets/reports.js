/*
 * Reports screen: after a month was chosen, focus moves to the summary sentence of the result, so
 * a keyboard or screen reader user lands on what changed. Without this file the page works the
 * same (the "Show" button submits the month).
 */
(function () {
    'use strict';

    if (!/[?&]month=/.test(window.location.search)) {
        return;
    }
    var summary = document.querySelector('[data-ritmo="report-summary"]');
    if (summary) {
        summary.setAttribute('tabindex', '-1');
        summary.focus();
    }
}());
