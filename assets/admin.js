/* global jQuery, smsngData */
(function ($) {
    'use strict';

    var state    = smsngData.state  || {};
    var nonce    = smsngData.nonce;
    var ajaxUrl  = smsngData.ajaxUrl;
    var retries  = 0;
    var MAX_RETRIES = 5;

    function showPanel(name) {
        ['idle', 'running', 'done', 'error'].forEach(function (n) {
            var el = document.getElementById('smsng-panel-' + n);
            if (el) el.style.display = (n === name) ? '' : 'none';
        });
    }

    function setProgress(msg, pct) {
        var msgEl  = document.getElementById('smsng-status-msg');
        var fillEl = document.getElementById('smsng-progress-fill');
        if (msgEl)  msgEl.textContent = msg;
        if (fillEl && pct >= 0) fillEl.style.width = pct + '%';
    }

    function showError(msg) {
        var errEl = document.getElementById('smsng-error-msg');
        if (errEl) errEl.textContent = msg;
        showPanel('error');
    }

    function runStep() {
        $.post(ajaxUrl, { action: 'smsng_step', nonce: nonce })
            .done(function (res) {
                retries = 0;
                if (!res.success) {
                    showError(res.data || 'An error occurred.');
                    return;
                }
                var d = res.data;
                setProgress(d.message || '', d.percent >= 0 ? d.percent : -1);

                if (d.finished) {
                    if (d.staging_url) {
                        var linkEl = document.getElementById('smsng-staging-link');
                        if (linkEl) {
                            linkEl.href        = d.staging_url;
                            linkEl.textContent = d.staging_url;
                        }
                        showPanel('done');
                    } else {
                        // Not all steps finished yet — continue
                        setTimeout(runStep, 100);
                    }
                } else {
                    setTimeout(runStep, 200);
                }
            })
            .fail(function () {
                retries++;
                if (retries <= MAX_RETRIES) {
                    setProgress('Connection issue — retrying (' + retries + '/' + MAX_RETRIES + ')…', -1);
                    setTimeout(runStep, 3000);
                } else {
                    showError('Too many failed requests. Please reload the page and try again.');
                }
            });
    }

    function startCreate() {
        var stagingUrl      = ($('#smsng-staging-url').val()      || '').trim();
        var stagingDir      = ($('#smsng-staging-dir').val()      || '').trim();
        var disabledPlugins       = ($('#smsng-disabled-plugins').val() || '').trim();
        var restrictAccess        = $('#smsng-restrict-access').is(':checked')        ? 1 : 0;
        var clearElementorLicense = $('#smsng-clear-elementor-license').is(':checked') ? 1 : 0;

        if (!stagingUrl || !stagingDir) {
            alert('Please fill in both the staging URL and staging directory.');
            return;
        }

        showPanel('running');
        setProgress('Initializing…', 0);

        $.post(ajaxUrl, {
            action:           'smsng_create',
            nonce:            nonce,
            staging_url:      stagingUrl,
            staging_dir:      stagingDir,
            disabled_plugins:        disabledPlugins,
            restrict_access:         restrictAccess,
            clear_elementor_license: clearElementorLicense,
        })
            .done(function (res) {
                if (!res.success) {
                    showError(res.data || 'Could not start staging job.');
                    return;
                }
                runStep();
            })
            .fail(function () {
                showError('Failed to start. Please try again.');
                showPanel('idle');
            });
    }

    function startDelete() {
        if (!window.confirm('Delete the staging site and all its files and database tables? This cannot be undone.')) {
            return;
        }
        showPanel('running');
        setProgress('Deleting staging site…', -1);

        $.post(ajaxUrl, { action: 'smsng_delete', nonce: nonce })
            .done(function (res) {
                if (res.success) {
                    showPanel('idle');
                } else {
                    showError(res.data || 'Deletion failed.');
                }
            })
            .fail(function () {
                showError('Deletion request failed. Please try again.');
                showPanel('done');
            });
    }

    $(function () {
        // Restore correct panel from server-side state
        var status = state.status || 'idle';
        if (status === 'running') {
            showPanel('running');
            setProgress('Resuming…', -1);
            setTimeout(runStep, 500);
        } else if (status === 'done') {
            showPanel('done');
        } else if (status === 'error') {
            showError(state.error || 'An error occurred during the last run.');
        } else {
            showPanel('idle');
        }

        $(document).on('click', '#smsng-btn-create', startCreate);
        $(document).on('click', '#smsng-btn-delete', startDelete);
        $(document).on('click', '#smsng-btn-reset', function () {
            if (!window.confirm('Delete the staging site and all its files and database tables? This cannot be undone.')) {
                return;
            }
            $.post(ajaxUrl, { action: 'smsng_delete', nonce: nonce }).always(function () {
                showPanel('idle');
            });
        });
    });
}(jQuery));
