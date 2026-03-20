/**
 * Emporiqa Admin Sync Scripts
 *
 * Handles bulk sync progress UI, connection testing, tab switching,
 * collapsible sections, payload preview, and cross-tab links.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   AFL-3.0
 */

(function () {
    'use strict';

    var syncCancelled = false;
    var syncRunning = false;

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function addLogEntry(msg, type) {
        var log = document.querySelector('.emporiqa-sync-log');
        if (!log) return;
        log.classList.add('visible');
        var p = document.createElement('p');
        p.className = 'log-entry log-' + (type || 'info');
        p.textContent = msg;
        log.appendChild(p);
        log.scrollTop = log.scrollHeight;
    }

    function addSyncCompleteMessage(baseUrl) {
        var log = document.querySelector('.emporiqa-sync-log');
        if (!log) return;
        log.classList.add('visible');
        var p = document.createElement('p');
        p.className = 'log-entry log-info';
        p.appendChild(document.createTextNode('Your data is now being processed by Emporiqa. This may take a few minutes depending on the number of items. Check the '));
        var prodLink = document.createElement('a');
        prodLink.href = baseUrl + '/platform/products/';
        prodLink.target = '_blank';
        prodLink.rel = 'noopener';
        prodLink.textContent = 'Products';
        p.appendChild(prodLink);
        p.appendChild(document.createTextNode(' / '));
        var pageLink = document.createElement('a');
        pageLink.href = baseUrl + '/platform/pages/';
        pageLink.target = '_blank';
        pageLink.rel = 'noopener';
        pageLink.textContent = 'Pages';
        p.appendChild(pageLink);
        p.appendChild(document.createTextNode(' list in your Emporiqa dashboard to follow the progress.'));
        log.appendChild(p);
        log.scrollTop = log.scrollHeight;
    }

    function updateProgress(pct) {
        var rounded = Math.min(100, Math.round(pct));
        var wrapper = document.querySelector('.emporiqa-progress-wrapper');
        if (!wrapper) return;
        wrapper.classList.add('visible');
        wrapper.setAttribute('aria-valuenow', rounded);
        var fill = wrapper.querySelector('.emporiqa-progress-bar-fill');
        if (fill) fill.style.width = rounded + '%';
        var text = wrapper.querySelector('.emporiqa-progress-text');
        if (text) text.textContent = rounded + '%';
    }

    function setSyncRunning(running) {
        syncRunning = running;
        syncCancelled = false;

        var buttons = document.querySelectorAll('#emporiqa-sync-products, #emporiqa-sync-pages, #emporiqa-sync-all');
        var cancelBtn = document.getElementById('emporiqa-sync-cancel');

        if (running) {
            buttons.forEach(function (btn) { btn.disabled = true; });
            if (cancelBtn) cancelBtn.style.display = 'inline-block';
        } else {
            buttons.forEach(function (btn) {
                if (!btn.dataset.initiallyDisabled) {
                    btn.disabled = false;
                }
            });
            if (cancelBtn) {
                cancelBtn.style.display = 'none';
                cancelBtn.disabled = false;
            }
        }
    }

    function sprintf(fmt) {
        var args = Array.prototype.slice.call(arguments, 1);
        var idx = 0;
        return fmt.replace(/%(\d+\$)?([sd])/g, function (match, pos, specifier) {
            var argIdx = pos ? parseInt(pos, 10) - 1 : idx++;
            var val = args[argIdx] !== undefined ? args[argIdx] : '';
            if ('d' === specifier) return parseInt(val, 10) || 0;
            return String(val);
        });
    }

    function ajaxPost(url, data, callback) {
        var config = window.emporiqaSyncConfig || {};
        var formData = new FormData();
        Object.keys(data).forEach(function (key) {
            formData.append(key, data[key]);
        });
        if (config.token) {
            formData.append('emporiqa_token', config.token);
        }

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function (json) { callback(true, json); })
        .catch(function () { callback(false, null); });
    }

    // -------------------------------------------------------------------------
    // Collapsible Sections
    // -------------------------------------------------------------------------

    function initCollapsibleSections() {
        var sections = document.querySelectorAll('.emporiqa-collapsible-section');

        sections.forEach(function (section) {
            var header = section.querySelector('.emporiqa-section-header');
            if (!header) return;

            var sectionId = section.id;
            if (sectionId) {
                try {
                    var stored = sessionStorage.getItem('emporiqa_section_' + sectionId);
                    if (stored === 'closed') {
                        section.classList.remove('emporiqa-section-open');
                        section.classList.add('emporiqa-section-closed');
                        header.setAttribute('aria-expanded', 'false');
                    } else if (stored === 'open') {
                        section.classList.remove('emporiqa-section-closed');
                        section.classList.add('emporiqa-section-open');
                        header.setAttribute('aria-expanded', 'true');
                    }
                } catch (e) { /* sessionStorage unavailable */ }
            }

            header.addEventListener('click', function () {
                toggleSection(section);
            });

            header.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggleSection(section);
                }
            });
        });
    }

    function toggleSection(section) {
        var header = section.querySelector('.emporiqa-section-header');
        var isOpen = section.classList.contains('emporiqa-section-open');

        if (isOpen) {
            section.classList.remove('emporiqa-section-open');
            section.classList.add('emporiqa-section-closed');
            if (header) header.setAttribute('aria-expanded', 'false');
        } else {
            section.classList.remove('emporiqa-section-closed');
            section.classList.add('emporiqa-section-open');
            if (header) header.setAttribute('aria-expanded', 'true');
        }

        if (section.id) {
            try {
                sessionStorage.setItem(
                    'emporiqa_section_' + section.id,
                    isOpen ? 'closed' : 'open'
                );
            } catch (e) { /* sessionStorage unavailable */ }
        }
    }

    // -------------------------------------------------------------------------
    // Payload Preview
    // -------------------------------------------------------------------------

    function renderPayloadPreview(response) {
        var container = document.getElementById('emporiqa-payload-preview');
        if (!container) return;

        container.innerHTML = '';

        if (response.sample_product) {
            container.appendChild(
                createPayloadBlock('Sample Product Payload', response.sample_product)
            );
        }

        if (response.sample_page) {
            container.appendChild(
                createPayloadBlock('Sample Page Payload', response.sample_page)
            );
        }
    }

    function createPayloadBlock(title, data) {
        var wrapper = document.createElement('div');

        var toggle = document.createElement('div');
        toggle.className = 'emporiqa-payload-toggle collapsed';
        toggle.setAttribute('tabindex', '0');
        toggle.setAttribute('role', 'button');
        toggle.setAttribute('aria-expanded', 'false');

        var arrow = document.createElement('span');
        arrow.className = 'emporiqa-payload-arrow';
        toggle.appendChild(arrow);
        toggle.appendChild(document.createTextNode(' ' + title));

        var pre = document.createElement('pre');
        pre.className = 'emporiqa-payload-pre';
        pre.style.display = 'none';
        pre.textContent = JSON.stringify(data, null, 2);

        toggle.addEventListener('click', function () {
            togglePayload(toggle, pre);
        });

        toggle.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                togglePayload(toggle, pre);
            }
        });

        wrapper.appendChild(toggle);
        wrapper.appendChild(pre);
        return wrapper;
    }

    function togglePayload(toggle, pre) {
        var isCollapsed = toggle.classList.contains('collapsed');
        if (isCollapsed) {
            toggle.classList.remove('collapsed');
            toggle.setAttribute('aria-expanded', 'true');
            pre.style.display = 'block';
        } else {
            toggle.classList.add('collapsed');
            toggle.setAttribute('aria-expanded', 'false');
            pre.style.display = 'none';
        }
    }

    // -------------------------------------------------------------------------
    // Cross-tab Links
    // -------------------------------------------------------------------------

    function initCrossTabLinks() {
        document.addEventListener('click', function (e) {
            var link = e.target.closest('.emporiqa-nav-tab-link');
            if (!link) return;

            e.preventDefault();
            var targetTab = link.dataset.targetTab;
            if (!targetTab) return;

            var tabLink = document.querySelector('.emporiqa-nav-tab[data-tab="' + targetTab + '"]');
            if (tabLink) tabLink.click();
        });
    }

    // -------------------------------------------------------------------------
    // Sync Runner
    // -------------------------------------------------------------------------

    function runSync(entity) {
        if (syncRunning) return;

        var config = window.emporiqaSyncConfig || {};
        var syncUrl = config.ajaxUrl || '';

        var log = document.querySelector('.emporiqa-sync-log');
        if (log) {
            log.innerHTML = '';
            log.classList.remove('visible');
        }
        updateProgress(0);
        setSyncRunning(true);
        addLogEntry('Initializing sync...', 'info');

        ajaxPost(syncUrl, {
            ajax: 1,
            action: 'emporiqaSyncAjax',
            sync_action: 'init',
            entity: entity
        }, function (ok, response) {
            if (!ok || !response || !response.success) {
                addLogEntry('Failed to initialize sync.', 'error');
                setSyncRunning(false);
                return;
            }

            var data = response;
            var sessions = data.sessions || [];
            var itemsPerBatch = data.items_per_batch || 25;
            var productCount = data.product_count || 0;
            var pageCount = data.page_count || 0;
            var totalItems = productCount + pageCount;

            var workQueue = [];
            for (var s = 0; s < sessions.length; s++) {
                var itemCount = 0;
                if (sessions[s].entity === 'products') itemCount = productCount;
                else if (sessions[s].entity === 'pages') itemCount = pageCount;

                var totalPages = Math.ceil(itemCount / itemsPerBatch);
                addLogEntry(sprintf('Started %1$s sync', sessions[s].entity), 'info');

                for (var p = 1; p <= totalPages; p++) {
                    workQueue.push({
                        entity: sessions[s].entity,
                        session_id: sessions[s].session_id,
                        page: p,
                        items_per_batch: itemsPerBatch
                    });
                }
            }

            var processed = 0;
            var batchIndex = 0;

            function processBatch() {
                if (syncCancelled) {
                    addLogEntry('Sync cancelled.', 'warning');
                    setSyncRunning(false);
                    return;
                }

                if (batchIndex >= workQueue.length) {
                    completeSessions(sessions, 0);
                    return;
                }

                var work = workQueue[batchIndex];
                batchIndex++;

                ajaxPost(syncUrl, {
                    ajax: 1,
                    action: 'emporiqaSyncAjax',
                    sync_action: 'batch',
                    entity: work.entity,
                    session_id: work.session_id,
                    page: work.page,
                    items_per_batch: work.items_per_batch
                }, function (ok, batchResponse) {
                    if (ok && batchResponse && batchResponse.success !== false) {
                        processed += batchResponse.processed || 0;
                        addLogEntry(
                            sprintf(
                                'Processed %1$d items (%2$d events) for %3$s',
                                batchResponse.processed || 0,
                                batchResponse.events || 0,
                                work.entity
                            ),
                            batchResponse.success ? 'info' : 'warning'
                        );
                    } else {
                        addLogEntry(sprintf('Batch failed for %1$s', work.entity), 'error');
                    }

                    if (totalItems > 0) {
                        updateProgress((processed / totalItems) * 100);
                    }

                    processBatch();
                });
            }

            function completeSessions(sessionsList, idx) {
                if (idx >= sessionsList.length) {
                    updateProgress(100);
                    addLogEntry('Sync completed successfully!', 'success');
                    var baseUrl = (window.emporiqaSyncConfig || {}).platformBaseUrl || 'https://emporiqa.com';
                    addSyncCompleteMessage(baseUrl);
                    setSyncRunning(false);
                    return;
                }

                var sess = sessionsList[idx];

                ajaxPost(syncUrl, {
                    ajax: 1,
                    action: 'emporiqaSyncAjax',
                    sync_action: 'complete',
                    entity: sess.entity,
                    session_id: sess.session_id
                }, function (ok, completeResponse) {
                    if (ok && completeResponse && completeResponse.success) {
                        addLogEntry(
                            sprintf('Completed %1$s sync session', sess.entity),
                            'success'
                        );
                    } else {
                        addLogEntry(
                            sprintf('Failed to complete %1$s session', sess.entity),
                            'error'
                        );
                    }
                    completeSessions(sessionsList, idx + 1);
                });
            }

            processBatch();
        });
    }

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', function () {

        // Record initially-disabled buttons
        document.querySelectorAll('#emporiqa-sync-products, #emporiqa-sync-pages, #emporiqa-sync-all').forEach(function (btn) {
            if (btn.disabled) {
                btn.dataset.initiallyDisabled = 'true';
            }
        });

        // Collapsible sections
        initCollapsibleSections();

        // Cross-tab links
        initCrossTabLinks();

        // Tab switching
        document.querySelectorAll('.emporiqa-nav-tab').forEach(function (tab) {
            tab.addEventListener('click', function (e) {
                e.preventDefault();
                var tabId = this.dataset.tab;

                document.querySelectorAll('.emporiqa-nav-tab').forEach(function (t) {
                    t.classList.remove('active');
                });
                this.classList.add('active');

                document.querySelectorAll('.emporiqa-tab-content').forEach(function (content) {
                    content.classList.remove('active');
                });
                var target = document.getElementById(tabId);
                if (target) target.classList.add('active');

                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', this.getAttribute('href'));
                }
            });
        });

        // Restore tab from URL hash
        var hash = window.location.hash.replace('#', '');
        if (hash && /^[a-zA-Z0-9_-]+$/.test(hash)) {
            var target = document.querySelector('.emporiqa-nav-tab[href="#' + hash + '"]');
            if (target) target.click();
        }

        // Test connection button
        var testBtn = document.getElementById('emporiqa-test-connection');
        if (testBtn) {
            testBtn.addEventListener('click', function () {
                var config = window.emporiqaSyncConfig || {};
                var resultEl = document.getElementById('emporiqa-test-result');
                var previewEl = document.getElementById('emporiqa-payload-preview');
                testBtn.disabled = true;
                if (resultEl) resultEl.textContent = 'Testing...';
                if (previewEl) previewEl.innerHTML = '';

                ajaxPost(config.ajaxUrl || '', {
                    ajax: 1,
                    action: 'emporiqaSyncAjax',
                    sync_action: 'test_connection'
                }, function (ok, response) {
                    testBtn.disabled = false;
                    if (resultEl) {
                        if (ok && response && response.success) {
                            resultEl.textContent = response.message || 'Success';
                            resultEl.style.color = 'green';
                            renderPayloadPreview(response);
                        } else {
                            var msg = (response && response.message) ? response.message : 'Request failed.';
                            resultEl.textContent = msg;
                            resultEl.style.color = 'red';
                        }
                    }
                });
            });
        }

        // Copy tracking URL button
        var copyBtn = document.getElementById('emporiqa-copy-tracking-url');
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                var url = this.dataset.url || '';
                if (!url) return;
                var showCopied = function () {
                    var originalText = copyBtn.textContent;
                    copyBtn.textContent = 'Copied!';
                    setTimeout(function () {
                        copyBtn.textContent = originalText;
                    }, 2000);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(showCopied).catch(function () {});
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = url;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    showCopied();
                }
            });
        }

        // Sync buttons
        ['emporiqa-sync-products', 'emporiqa-sync-pages', 'emporiqa-sync-all'].forEach(function (id) {
            var btn = document.getElementById(id);
            if (btn) {
                btn.addEventListener('click', function () {
                    var entity = this.dataset.entity;
                    if (entity) runSync(entity);
                });
            }
        });

        // Cancel button
        var cancelBtn = document.getElementById('emporiqa-sync-cancel');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                syncCancelled = true;
                this.disabled = true;
            });
        }
    });
})();
