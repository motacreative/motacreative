(function () {
    'use strict';

    var STEPS = ['products', 'customers', 'orders'];
    var running = false;
    var currentStepIndex = 0;
    var currentPage = 1;
    var batchSize = 20;

    var elements = {};

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        elements.startBtn = document.getElementById('sc-fct-start');
        elements.resetBtn = document.getElementById('sc-fct-reset');
        elements.batchSelect = document.getElementById('sc-fct-batch-size');
        elements.status = document.getElementById('sc-fct-status');
        elements.statusText = document.getElementById('sc-fct-status-text');
        elements.log = document.getElementById('sc-fct-log');

        if (!elements.startBtn) return;

        elements.startBtn.addEventListener('click', startMigration);
        elements.resetBtn.addEventListener('click', resetMigration);

        // Fetch initial counts from SureCart
        fetchCounts();
    }

    function fetchCounts() {
        ajax('sc_fct_count', {}, function (data) {
            STEPS.forEach(function (step) {
                var el = document.querySelector('[data-step="' + step + '"] .sc-fct-step__total');
                if (el && data[step] !== undefined) {
                    el.textContent = data[step];
                }
            });
        });
    }

    function startMigration() {
        if (running) return;

        running = true;
        currentStepIndex = 0;
        currentPage = 1;
        batchSize = parseInt(elements.batchSelect.value, 10) || 20;

        elements.startBtn.disabled = true;
        elements.startBtn.textContent = 'Migration Running...';
        elements.resetBtn.disabled = true;
        showStatus('Starting migration...');
        clearLog();

        log('Migration started with batch size ' + batchSize);
        runNextBatch();
    }

    function runNextBatch() {
        if (currentStepIndex >= STEPS.length) {
            migrationComplete();
            return;
        }

        var step = STEPS[currentStepIndex];
        showStatus('Migrating ' + step + ' (page ' + currentPage + ')...');

        ajax('sc_fct_migrate_batch', {
            step: step,
            page: currentPage,
            batch_size: batchSize
        }, function (data) {
            // Update progress
            updateStepProgress(step, data.processed, data.total);

            // Log batch result
            var msg = step + ' page ' + data.page + ': ' + data.processed + '/' + data.total;
            if (data.batch_new > 0) {
                msg += ' (' + data.batch_new + ' new)';
            }
            if (data.skipped > 0) {
                msg += ' (' + data.skipped + ' skipped)';
            }
            log(msg);

            // Log any errors
            if (data.errors && data.errors.length > 0) {
                data.errors.forEach(function (err) {
                    log('ERROR [' + step + ':' + err.id + '] ' + err.message, 'error');
                });
            }

            if (data.has_more) {
                currentPage = data.next_page;
                runNextBatch();
            } else {
                // Step complete
                log(step + ' migration complete (' + data.processed + ' total).');
                markStepComplete(step);
                currentStepIndex++;
                currentPage = 1;
                runNextBatch();
            }
        }, function (errorMsg) {
            log('ERROR: ' + errorMsg, 'error');
            showStatus('Error occurred. You can retry by clicking Start again.');
            running = false;
            elements.startBtn.disabled = false;
            elements.startBtn.textContent = 'Retry Migration';
            elements.resetBtn.disabled = false;
        });
    }

    function migrationComplete() {
        running = false;
        elements.startBtn.disabled = false;
        elements.startBtn.textContent = 'Start Migration';
        elements.resetBtn.disabled = false;
        hideStatus();
        log('Migration completed successfully!', 'success');
    }

    function resetMigration() {
        if (running) return;

        if (!confirm('This will clear all migration tracking data. Previously imported FluentCart records will NOT be deleted. Continue?')) {
            return;
        }

        ajax('sc_fct_reset', {}, function () {
            log('Migration data reset.', 'info');

            // Reset UI
            STEPS.forEach(function (step) {
                updateStepProgress(step, 0, '?');
                var stepEl = document.querySelector('[data-step="' + step + '"]');
                if (stepEl) {
                    stepEl.classList.remove('sc-fct-step--complete');
                }
            });

            fetchCounts();
        });
    }

    function updateStepProgress(step, processed, total) {
        var stepEl = document.querySelector('[data-step="' + step + '"]');
        if (!stepEl) return;

        var migratedEl = stepEl.querySelector('.sc-fct-step__migrated');
        var totalEl = stepEl.querySelector('.sc-fct-step__total');
        var barEl = stepEl.querySelector('.sc-fct-progress__bar');

        if (migratedEl) migratedEl.textContent = processed;
        if (totalEl && total !== undefined) totalEl.textContent = total;

        var pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
        if (barEl) barEl.style.width = pct + '%';
    }

    function markStepComplete(step) {
        var stepEl = document.querySelector('[data-step="' + step + '"]');
        if (stepEl) {
            stepEl.classList.add('sc-fct-step--complete');
        }
    }

    function showStatus(text) {
        if (elements.status) elements.status.style.display = 'flex';
        if (elements.statusText) elements.statusText.textContent = text;
    }

    function hideStatus() {
        if (elements.status) elements.status.style.display = 'none';
    }

    function log(message, type) {
        if (!elements.log) return;

        var timestamp = new Date().toLocaleTimeString();
        var div = document.createElement('div');
        div.className = 'sc-fct-log__entry' + (type ? ' sc-fct-log__entry--' + type : '');
        div.textContent = '[' + timestamp + '] ' + message;
        elements.log.appendChild(div);
        elements.log.scrollTop = elements.log.scrollHeight;
    }

    function clearLog() {
        if (elements.log) elements.log.innerHTML = '';
    }

    function ajax(action, data, onSuccess, onError) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('_nonce', scFctMigration.nonce);

        for (var key in data) {
            if (data.hasOwnProperty(key)) {
                formData.append(key, data[key]);
            }
        }

        fetch(scFctMigration.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(function (response) {
            return response.json();
        })
        .then(function (result) {
            if (result.success) {
                if (onSuccess) onSuccess(result.data);
            } else {
                var msg = (result.data && result.data.message) ? result.data.message : 'Unknown error';
                if (onError) {
                    onError(msg);
                } else {
                    log('Error: ' + msg, 'error');
                }
            }
        })
        .catch(function (err) {
            var msg = err.message || 'Network error';
            if (onError) {
                onError(msg);
            } else {
                log('Network error: ' + msg, 'error');
            }
        });
    }
})();
