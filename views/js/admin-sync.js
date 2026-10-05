/**
 * Emporiqa Admin Sync Scripts
 *
 * Handles bulk sync progress UI, connection testing, tab switching,
 * collapsible sections, the technical details of a test, and cross-tab links.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   AFL-3.0
 */

(function () {
    'use strict';

    var syncCancelled = false;
    var syncRunning = false;

    // Translated in PHP (Emporiqa::getAdminJsStrings, via Media::addJsDef).
    function t(key) {
        var strings = window.emporiqaI18n || {};
        return typeof strings[key] === 'string' && strings[key] !== '' ? strings[key] : key;
    }

    function entityLabel(entity) {
        return entity === 'pages' ? t('pages') : t('products');
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

    function dashboardLink(href, label) {
        var a = document.createElement('a');
        a.href = href;
        a.target = '_blank';
        a.rel = 'noopener';
        a.textContent = label;
        return a;
    }

    function addSyncCompleteMessage(baseUrl) {
        var log = document.querySelector('.emporiqa-sync-log');
        if (!log) return;
        log.classList.add('visible');
        var p = document.createElement('p');
        p.className = 'log-entry log-info';
        // Menu names in the Emporiqa dashboard, which is in English. Not
        // translated: PrestaShop's fallback would turn "Products" into its
        // own catalog term ("Artikel"), which the dashboard does not show.
        var links = {
            '%1$s': dashboardLink(baseUrl + '/platform/products/', 'Products'),
            '%2$s': dashboardLink(baseUrl + '/platform/pages/', 'Pages'),
        };
        var text = t('processing');
        // The translation decides where the two links sit in the sentence.
        text.split(/(%[12]\$s)/).forEach(function (part) {
            if (links[part]) {
                p.appendChild(links[part]);
            } else if (part !== '') {
                p.appendChild(document.createTextNode(part));
            }
        });
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
            if (!response.ok) throw new Error('Sync request failed');
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
        if (!response.sample_product && !response.sample_page) return;

        // Collapsed by default: the JSON is for support, not for the merchant.
        var body = document.createElement('div');
        body.className = 'emporiqa-tech-details-body';
        body.style.display = 'none';

        var help = document.createElement('p');
        help.className = 'help-block';
        help.textContent = t('technicalDetailsHelp');
        body.appendChild(help);

        if (response.sample_product) {
            body.appendChild(createPayloadBlock(t('sampleProduct'), response.sample_product));
        }
        if (response.sample_page) {
            body.appendChild(createPayloadBlock(t('samplePage'), response.sample_page));
        }

        container.appendChild(createToggle(t('technicalDetails'), body));
        container.appendChild(body);
    }

    function createPayloadBlock(title, data) {
        var wrapper = document.createElement('div');

        var heading = document.createElement('div');
        heading.className = 'emporiqa-payload-title';
        heading.textContent = title;

        var pre = document.createElement('pre');
        pre.className = 'emporiqa-payload-pre';
        pre.textContent = JSON.stringify(data, null, 2);

        wrapper.appendChild(heading);
        wrapper.appendChild(pre);
        return wrapper;
    }

    function createToggle(title, target) {
        var toggle = document.createElement('div');
        toggle.className = 'emporiqa-payload-toggle collapsed';
        toggle.setAttribute('tabindex', '0');
        toggle.setAttribute('role', 'button');
        toggle.setAttribute('aria-expanded', 'false');

        var arrow = document.createElement('span');
        arrow.className = 'emporiqa-payload-arrow';
        toggle.appendChild(arrow);
        toggle.appendChild(document.createTextNode(' ' + title));

        toggle.addEventListener('click', function () {
            togglePayload(toggle, target);
        });

        toggle.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                togglePayload(toggle, target);
            }
        });

        return toggle;
    }

    function togglePayload(toggle, target) {
        var isCollapsed = toggle.classList.contains('collapsed');
        toggle.classList.toggle('collapsed', !isCollapsed);
        toggle.setAttribute('aria-expanded', isCollapsed ? 'true' : 'false');
        target.style.display = isCollapsed ? 'block' : 'none';
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
        addLogEntry(t('initializing'), 'info');

        ajaxPost(syncUrl, {
            ajax: 1,
            action: 'emporiqaSyncAjax',
            sync_action: 'init',
            entity: entity
        }, function (ok, response) {
            if (!ok || !response || !response.success) {
                var reason = (response && (response.error || response.message)) || '';
                addLogEntry(
                    t('initFailed') + (reason ? ' ' + reason : ''),
                    'error',
                );
                setSyncRunning(false);
                return;
            }

            var data = response;
            var sessions = data.sessions || [];
            var itemsPerBatch = data.items_per_batch || 25;
            var productCount = data.product_count || 0;
            var pageCount = data.page_count || 0;
            var totalItems = productCount + pageCount;

            // One entry per session, paged by id (after_id) until a batch
            // comes back empty, so an item disabled or added mid-sync never
            // shifts the pages and skips a live one.
            var workQueue = [];
            for (var s = 0; s < sessions.length; s++) {
                addLogEntry(sprintf(t('started'), entityLabel(sessions[s].entity)), 'info');
                workQueue.push({
                    entity: sessions[s].entity,
                    session_id: sessions[s].session_id,
                    after_id: 0,
                    items_per_batch: itemsPerBatch
                });
            }

            var processed = 0;
            var batchIndex = 0;
            // Per-entity failure/success tracking: sessions with failed
            // batches must NOT be completed, or the backend deletes the
            // unseen items from the remote catalog.
            var entityFailures = {};
            var entitySynced = {};
            var completionErrors = 0;

            function processBatch() {
                if (syncCancelled) {
                    addLogEntry(t('cancelled'), 'warning');
                    setSyncRunning(false);
                    return;
                }

                if (batchIndex >= workQueue.length) {
                    completeSessions(sessions, 0);
                    return;
                }

                var work = workQueue[batchIndex];

                ajaxPost(syncUrl, {
                    ajax: 1,
                    action: 'emporiqaSyncAjax',
                    sync_action: 'batch',
                    entity: work.entity,
                    session_id: work.session_id,
                    after_id: work.after_id,
                    items_per_batch: work.items_per_batch
                }, function (ok, batchResponse) {
                    var failed = !ok || !batchResponse || !batchResponse.success;
                    // One retry of the same page before moving on: a single
                    // timeout should not leave the session uncompletable.
                    if (failed && work.retried_after_id !== work.after_id) {
                        work.retried_after_id = work.after_id;
                        addLogEntry(
                            sprintf(t('batchRetry'), entityLabel(work.entity)),
                            'warning',
                        );
                        processBatch();
                        return;
                    }
                    var lastId = batchResponse && batchResponse.last_id;
                    if (batchResponse && batchResponse.processed > 0 && lastId > work.after_id) {
                        work.after_id = lastId;
                    } else {
                        batchIndex++;
                    }
                    if (ok && batchResponse && batchResponse.success) {
                        processed += batchResponse.processed || 0;
                        entitySynced[work.entity] = (entitySynced[work.entity] || 0) + (batchResponse.processed || 0);
                        addLogEntry(
                            sprintf(
                                t('batchDone'),
                                batchResponse.processed || 0,
                                batchResponse.events || 0,
                                entityLabel(work.entity),
                            ),
                            'info',
                        );
                    } else {
                        entityFailures[work.entity] = (entityFailures[work.entity] || 0) + 1;
                        var batchReason = (batchResponse && (batchResponse.error || batchResponse.message)) || '';
                        addLogEntry(
                            sprintf(t('batchFailed'), entityLabel(work.entity)) + (batchReason ? ' ' + batchReason : ''),
                            'error',
                        );
                    }

                    if (totalItems > 0) {
                        updateProgress((processed / totalItems) * 100);
                    }

                    processBatch();
                });
            }

            function completeSessions(sessionsList, idx) {
                if (idx >= sessionsList.length) {
                    if (Object.keys(entityFailures).length > 0 || completionErrors > 0) {
                        addLogEntry(t('finishedWithErrors'), 'error');
                    } else {
                        updateProgress(100);
                        addLogEntry(t('completed'), 'success');
                        var baseUrl = (window.emporiqaSyncConfig || {}).platformBaseUrl || 'https://emporiqa.com';
                        addSyncCompleteMessage(baseUrl);
                    }
                    setSyncRunning(false);
                    return;
                }

                var sess = sessionsList[idx];

                // Never complete a session that had a failed batch (the
                // backend would delete the unseen items) or that synced
                // nothing at all. The server enforces the same guard.
                if (entityFailures[sess.entity]) {
                    addLogEntry(
                        sprintf(
                            t('skippedFailed'),
                            entityLabel(sess.entity),
                            entityFailures[sess.entity],
                        ),
                        'warning',
                    );
                    completeSessions(sessionsList, idx + 1);
                    return;
                }
                if (!(entitySynced[sess.entity] > 0)) {
                    addLogEntry(sprintf(t('skippedEmpty'), entityLabel(sess.entity)), 'warning');
                    completeSessions(sessionsList, idx + 1);
                    return;
                }

                ajaxPost(syncUrl, {
                    ajax: 1,
                    action: 'emporiqaSyncAjax',
                    sync_action: 'complete',
                    entity: sess.entity,
                    session_id: sess.session_id
                }, function (ok, completeResponse) {
                    if (ok && completeResponse && completeResponse.success) {
                        addLogEntry(
                            sprintf(t('sessionCompleted'), entityLabel(sess.entity)),
                            'success',
                        );
                    } else {
                        completionErrors++;
                        var completeReason = (completeResponse && (completeResponse.error || completeResponse.message)) || '';
                        addLogEntry(
                            sprintf(t('sessionFailed'), entityLabel(sess.entity))
                                + (completeReason ? ' ' + completeReason : ''),
                            'error',
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
                if (resultEl) {
                    resultEl.textContent = t('testing');
                    resultEl.style.color = '';
                }
                if (previewEl) previewEl.innerHTML = '';

                ajaxPost(config.ajaxUrl || '', {
                    ajax: 1,
                    action: 'emporiqaSyncAjax',
                    sync_action: 'test_connection'
                }, function (ok, response) {
                    testBtn.disabled = false;
                    if (resultEl) {
                        if (ok && response && response.success) {
                            resultEl.textContent = response.message || t('success');
                            resultEl.style.color = response.clock_warning ? '#b45309' : 'green';
                            renderPayloadPreview(response);
                        } else {
                            var msg = (response && (response.message || response.error))
                                || t('requestFailed');
                            resultEl.textContent = msg;
                            resultEl.style.color = 'red';
                        }
                    }
                });
            });
        }

        // Copy buttons (order tracking and Order status addresses)
        document.querySelectorAll('.emporiqa-copy-btn').forEach(function (copyBtn) {
            copyBtn.addEventListener('click', function () {
                var url = this.dataset.url || '';
                if (!url) return;
                var showCopied = function () {
                    var originalText = copyBtn.textContent;
                    copyBtn.textContent = t('copied');
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
        });

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
