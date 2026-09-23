/**
 * Банковская методика — UI в ЛК банка и на карточке заявки.
 */
(function (window) {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function numOrEmpty(v) {
        if (v === null || v === undefined || v === '') return '';
        return String(v);
    }

    function fmtScore(v) {
        if (v === null || v === undefined || Number.isNaN(Number(v))) return '—';
        const n = Number(v);
        return (Math.round(n * 100) / 100).toLocaleString('ru-RU');
    }

    function notify(msg, type) {
        if (typeof showNotification === 'function') {
            showNotification(msg, type || 'info');
        } else if (window.bootstrap) {
            console.log(type, msg);
        } else {
            alert(msg);
        }
    }

    function initBankMethodology(opts) {
        const root = document.getElementById(opts.rootId || 'bankMethodologyRoot');
        if (!root) return;

        const nextApplicationId = Number(opts.applicationId || opts.bankCaseId || 0);
        // Повторный вызов на том же root — смена заявки без повторной подписки на вкладку
        if (root._bmCtl && typeof root._bmCtl.setApplicationId === 'function') {
            root._bmCtl.setApplicationId(nextApplicationId);
            return root._bmCtl;
        }

        let applicationId = nextApplicationId;
        let rules = null;
        let state = null;
        let evaluated = null;
        let assessment = null;
        let history = [];
        let dirty = false;
        let loaded = false;
        let sectionCollapsed = { stops: false, finance: true, business: true, judgment: true };
        let stopFilter = 'all';
        let stopGroupCollapsed = {};
        let stopMoreOpen = {};
        let autosaveTimer = null;
        let autosaving = false;
        let editGeneration = 0;
        let saveHint = ''; // '' | 'сохранение' | 'сохранено'
        let saveHintTimer = null;
        let stickyScrollBound = false;

        async function api(action, payload) {
            if (action === 'get') {
                const url = 'api_bank_methodology.php?action=get&application_id=' + encodeURIComponent(String(applicationId));
                const r = await fetch(url, { credentials: 'same-origin' });
                return r.json();
            }
            const body = new FormData();
            body.append('action', action);
            body.append('application_id', String(applicationId));
            if (payload && payload.state) {
                body.append('state', JSON.stringify(payload.state));
            }
            if (payload && payload.force_finance) {
                body.append('force_finance', '1');
            }
            const r = await fetch('api_bank_methodology.php', {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
            });
            return r.json();
        }

        function collectStateFromDom() {
            if (!state) return null;
            const next = JSON.parse(JSON.stringify(state));

            // stops
            next.stop_factors = (rules.stop_factors || []).map(function (sf) {
                const cb = root.querySelector('[data-bm-stop="' + sf.code + '"]');
                const ta = root.querySelector('[data-bm-stop-comment="' + sf.code + '"]');
                const item = cb ? cb.closest('.bm-stop-item') : null;
                const prev = ((state && state.stop_factors) || []).find(function (x) {
                    return x.code === sf.code;
                }) || {};
                const checked = !!(cb && cb.checked);
                let source = 'manual';
                if (item && item.getAttribute('data-bm-stop-source') === 'checko') {
                    source = checked ? 'checko' : 'manual';
                } else if (!checked && (prev.source === 'checko') && !prev.triggered) {
                    source = 'checko';
                } else if (checked && prev.source === 'checko') {
                    source = 'checko';
                }
                return {
                    code: sf.code,
                    triggered: checked,
                    source: source,
                    comment: ta ? ta.value.trim() : '',
                };
            });

            // finance inputs
            const finKeys = [
                'revenue', 'revenue_last_year', 'net_profit', 'prior_year_net_profit',
                'income_from_participation', 'interest_receivable', 'other_income',
                'equity', 'current_assets', 'current_liabilities',
                'long_term_liabilities', 'balance_total', 'industry', 'reporting_period',
                'short_term_borrowings', 'long_term_borrowings', 'accounts_payable', 'other_short_liabilities',
                'q1_seasonal_comment'
            ];
            finKeys.forEach(function (k) {
                const el = root.querySelector('[data-bm-fin="' + k + '"]');
                if (!el) return;
                next.finance.inputs[k] = el.type === 'checkbox' ? el.checked : (el.value === '' ? null : el.value);
            });
            next.finance.inputs.debt_to_revenue = null;
            next.finance.score_overrides = {};
            ['q1_seasonal_loss_explained'].forEach(function (k) {
                const el = root.querySelector('[data-bm-fin-flag="' + k + '"]');
                next.finance.inputs[k] = !!(el && el.checked);
            });
            next.finance.inputs.profitability_explained_zero = false;
            next.finance.inputs.roe_explained_zero = false;

            // business
            Object.keys(rules.business_metrics || {}).forEach(function (id) {
                const el = root.querySelector('[data-bm-biz="' + id + '"]');
                if (!el) return;
                if (!next.business[id]) next.business[id] = { value: null, source: 'manual', score_override: null };
                const prev = (state.business && state.business[id]) || {};
                const nextVal = el.value === '' ? null : el.value;
                next.business[id].value = nextVal;
                if (prev.source === 'checko' && String(prev.value || '') === String(nextVal || '')) {
                    next.business[id].source = 'checko';
                } else {
                    next.business[id].source = 'manual';
                }
                next.business[id].score_override = null;
            });

            // judgment
            const comment = root.querySelector('[data-bm-judgment="comment"]');
            const reason = root.querySelector('[data-bm-judgment="upgrade_downgrade_reason"]');
            const conclusion = root.querySelector('[data-bm-judgment="conclusion"]');
            const force = root.querySelector('[data-bm-judgment="force_not_good"]');
            const est = root.querySelector('[data-bm-judgment="established_rating"]');
            next.judgment.comment = comment ? comment.value : '';
            next.judgment.upgrade_downgrade_reason = reason ? reason.value : '';
            next.judgment.conclusion = conclusion ? conclusion.value : '';
            next.judgment.force_not_good = !!(force && force.checked);
            next.judgment.established_rating = est ? est.value : '';

            state = next;
            return next;
        }

        async function recalculateSilent() {
            const s = collectStateFromDom();
            if (!s) return;
            const data = await api('recalculate', { state: s });
            if (!data.success) {
                notify(data.error || 'Ошибка расчёта', 'error');
                return;
            }
            evaluated = data.evaluated;
            state = data.evaluated.state;
            dirty = true;
            renderSummary();
            renderMetricScores();
            renderBanners();
            updateSectionMeta();
            updateStopToolbar();
            updateJudgmentCalc();
        }

        function draftStatusLabel() {
            if (isLocked()) {
                return dirty ? 'зафиксировано · изменённый просмотр' : 'зафиксировано';
            }
            if (saveHint === 'сохранение' || autosaving) return 'черновик · сохранение';
            if (saveHint === 'сохранено' && !dirty) return 'черновик · сохранено';
            if (dirty) return 'черновик · не сохранено';
            return 'черновик';
        }

        function setSaveHint(hint, holdMs) {
            saveHint = hint || '';
            clearTimeout(saveHintTimer);
            renderSummary();
            if (holdMs && hint) {
                saveHintTimer = setTimeout(function () {
                    if (saveHint === hint) {
                        saveHint = '';
                        renderSummary();
                    }
                }, holdMs);
            }
        }

        async function autosaveDraft() {
            if (isLocked() || autosaving || !dirty || !loaded) return;
            const s = collectStateFromDom();
            if (!s) return;
            const gen = editGeneration;
            autosaving = true;
            setSaveHint('сохранение');
            try {
                const data = await api('save_draft', { state: s });
                if (!data.success) throw new Error(data.error || 'Ошибка автосохранения');
                if (gen !== editGeneration) {
                    return;
                }
                assessment = data.assessment;
                evaluated = data.evaluated;
                state = data.evaluated.state;
                dirty = false;
                history = await reloadHistory();
                renderBanners();
                renderMetricScores();
                updateSectionMeta();
                updateJudgmentCalc();
                setSaveHint('сохранено', 2000);
            } catch (err) {
                console.warn('autosave failed', err);
                saveHint = '';
                renderSummary();
            } finally {
                autosaving = false;
                if (saveHint === 'сохранение') {
                    saveHint = dirty ? '' : 'сохранено';
                    renderSummary();
                }
            }
        }

        function scheduleAutosave() {
            if (isLocked()) return;
            clearTimeout(autosaveTimer);
            autosaveTimer = setTimeout(function () { autosaveDraft(); }, 1600);
        }

        function isLocked() {
            return !!(assessment && assessment.status === 'final');
        }

        function stopCounts() {
            const factors = rules && rules.stop_factors ? rules.stop_factors : [];
            const rows = (state && state.stop_factors) || [];
            let on = 0;
            factors.forEach(function (sf) {
                const row = rows.find(function (x) { return x.code === sf.code; }) || {};
                const cb = root.querySelector('[data-bm-stop="' + sf.code + '"]');
                if (cb ? cb.checked : !!row.triggered) on += 1;
            });
            return { on: on, total: factors.length };
        }

        function ratingCategory(letter) {
            if (!letter || !rules) return '';
            const row = (rules.rating_scale || []).find(function (x) {
                return String(x.rating) === String(letter);
            });
            return row ? row.category : '';
        }

        function updateSectionMeta() {
            const r = (evaluated && evaluated.result) || {};
            const stops = stopCounts();
            const plain = {
                stops: 'отмечено ' + stops.on,
                finance: fmtScore(r.finance_score) + '/50',
                business: fmtScore(r.business_score) + '/50',
                judgment: r.incomplete ? '—' : (r.rating || '—'),
            };
            Object.keys(plain).forEach(function (key) {
                const el = root.querySelector('[data-bm-section="' + key + '"] .bm-section-head .meta');
                if (el) el.textContent = plain[key];
            });
        }

        function updateStopToolbar() {
            const el = root.querySelector('[data-bm-stop-count]');
            if (!el) return;
            const c = stopCounts();
            el.textContent = 'Отмечено ' + c.on + ' из ' + c.total;
        }

        function updateJudgmentCalc() {
            const box = root.querySelector('[data-bm-judgment-calc]');
            if (!box || !evaluated) return;
            const r = evaluated.result || {};
            if (r.incomplete) {
                box.textContent = 'Расчётный: —';
                return;
            }
            const letter = r.calculated_rating || r.rating || '—';
            const cat = ratingCategory(letter);
            box.textContent = 'Расчётный: ' + letter + (cat ? ' · ' + cat : '');
        }

        function applyStopFilter() {
            const onlyOn = stopFilter === 'triggered';
            root.querySelectorAll('[data-bm-stop-row]').forEach(function (row) {
                const on = row.classList.contains('is-on');
                row.hidden = onlyOn && !on;
            });
            root.querySelectorAll('[data-bm-stop-group]').forEach(function (group) {
                const visible = group.querySelectorAll('[data-bm-stop-row]:not([hidden])');
                group.hidden = onlyOn && visible.length === 0;
            });
            root.querySelectorAll('[data-bm-stop-filter]').forEach(function (btn) {
                btn.classList.toggle('is-active', btn.getAttribute('data-bm-stop-filter') === stopFilter);
            });
        }

        function applyQ1Visibility() {
            const sel = root.querySelector('[data-bm-fin="reporting_period"]');
            const block = root.querySelector('[data-bm-q1-block]');
            if (!block) return;
            const isQ1 = !!(sel && sel.value === 'q1');
            block.classList.toggle('is-visible', isQ1);
            block.hidden = !isQ1;
            const flag = root.querySelector('[data-bm-fin-flag="q1_seasonal_loss_explained"]');
            const commentWrap = root.querySelector('[data-bm-q1-comment]');
            if (commentWrap) {
                const showComment = isQ1 && !!(flag && flag.checked);
                commentWrap.classList.toggle('is-hidden', !showComment);
                commentWrap.hidden = !showComment;
            }
        }

        function renderSummary() {
            const box = root.querySelector('[data-bm-summary]');
            if (!box || !evaluated) return;
            const r = evaluated.result || {};
            const incomplete = !!r.incomplete;
            const posClass = incomplete ? ''
                : (r.position === 'good' ? 'bm-pos-good'
                : (r.position === 'bad' ? 'bm-pos-bad' : 'bm-pos-average'));
            const status = assessment ? assessment.status : 'draft';
            const statusLabel = draftStatusLabel();
            const ratingText = incomplete ? '—' : (r.rating || '—');
            const posText = r.position_label || '';
            const calcRating = r.calculated_rating || '';
            const establishedApplied = !!r.established_applied;
            const ratingLabel = establishedApplied ? 'Установленный' : 'Рейтинг';
            let ratingSub = esc(posText) + (r.hard_stop ? ' · стоп' : '');
            if (!incomplete && calcRating) {
                if (establishedApplied && String(calcRating) === String(ratingText)) {
                    ratingSub += ' · как расчётный';
                } else if (String(calcRating) !== String(ratingText)) {
                    ratingSub += ' · расчётный ' + esc(calcRating);
                }
            }
            const versionLabel = assessment && assessment.version != null && assessment.version !== ''
                ? ('версия ' + assessment.version + ' · ')
                : '';
            box.innerHTML = `
                <div class="bm-kpi bm-kpi-score">
                    <div class="label">Финансы</div>
                    <div class="value">${esc(fmtScore(r.finance_score))}<span class="denom">/50</span></div>
                </div>
                <div class="bm-kpi bm-kpi-score">
                    <div class="label">Бизнес-риск</div>
                    <div class="value">${esc(fmtScore(r.business_score))}<span class="denom">/50</span></div>
                </div>
                <div class="bm-kpi bm-kpi-total">
                    <div class="label">Итого</div>
                    <div class="value">${esc(fmtScore(r.total_score))}<span class="denom">/100</span></div>
                    <div class="meta">${versionLabel}<span class="bm-status-chip ${esc(status)}">${esc(statusLabel)}</span></div>
                </div>
                <div class="bm-kpi bm-kpi-rating ${posClass}">
                    <div class="label">${esc(ratingLabel)}</div>
                    <div class="value">${esc(ratingText)}</div>
                    <div class="meta">${ratingSub}</div>
                </div>`;
            updateStickyOffsets();
        }

        function updateStickyOffsets() {
            const wrap = root.querySelector('.bm-wrap');
            const summary = root.querySelector('[data-bm-summary]');
            if (!wrap) return;
            let offsetPx = 80;
            if (summary && summary.offsetHeight) {
                offsetPx = summary.offsetHeight + 8;
            }
            wrap.style.setProperty('--bm-summary-offset', offsetPx + 'px');
        }

        function navbarHeightPx() {
            const raw = getComputedStyle(document.documentElement)
                .getPropertyValue('--top-navbar-height')
                .trim();
            const n = parseFloat(raw);
            return Number.isFinite(n) && n > 0 ? n : 60;
        }

        function pinTopPx() {
            const summary = root.querySelector('[data-bm-summary]');
            const summaryH = summary && summary.offsetHeight ? summary.offsetHeight : 0;
            return navbarHeightPx() + 8 + summaryH + 6;
        }

        function ensureHeadSpacer(head) {
            let spacer = head.previousElementSibling;
            if (!spacer || !spacer.classList.contains('bm-section-head-spacer')) {
                spacer = document.createElement('div');
                spacer.className = 'bm-section-head-spacer';
                spacer.hidden = true;
                spacer.setAttribute('aria-hidden', 'true');
                head.parentElement.insertBefore(spacer, head);
            }
            return spacer;
        }

        function unpinSectionHead(head) {
            const spacer = head.previousElementSibling;
            head.classList.remove('is-pinned');
            head.style.position = '';
            head.style.top = '';
            head.style.left = '';
            head.style.width = '';
            head.style.zIndex = '';
            if (spacer && spacer.classList.contains('bm-section-head-spacer')) {
                spacer.hidden = true;
                spacer.style.height = '';
            }
        }

        function pinSectionHeads() {
            const sections = root.querySelectorAll('.bm-section[data-bm-section]');
            if (!sections.length) return;
            const top = pinTopPx();

            sections.forEach(function (section) {
                const head = section.firstElementChild && section.firstElementChild.classList.contains('bm-section-head-spacer')
                    ? section.children[1]
                    : section.querySelector('.bm-section-head');
                if (!head || !head.classList.contains('bm-section-head')) return;
                const spacer = ensureHeadSpacer(head);

                if (section.classList.contains('is-collapsed')) {
                    unpinSectionHead(head);
                    return;
                }

                const headH = head.offsetHeight || spacer.offsetHeight || 48;
                const sectionRect = section.getBoundingClientRect();
                const shouldPin = sectionRect.top < top && sectionRect.bottom > top + headH + 4;

                if (!shouldPin) {
                    unpinSectionHead(head);
                    return;
                }

                // Уезжает вверх вместе с низом секции
                const clampedTop = Math.min(top, sectionRect.bottom - headH - 2);

                spacer.style.height = headH + 'px';
                spacer.hidden = false;
                head.classList.add('is-pinned');
                head.style.position = 'fixed';
                head.style.top = Math.max(sectionRect.top, clampedTop) + 'px';
                head.style.left = sectionRect.left + 'px';
                head.style.width = sectionRect.width + 'px';
                head.style.zIndex = '26';
            });
        }

        function ensureStickyScrollBound() {
            if (stickyScrollBound) return;
            stickyScrollBound = true;
            window.addEventListener('scroll', pinSectionHeads, { passive: true });
            window.addEventListener('resize', function () {
                updateStickyOffsets();
                pinSectionHeads();
            });
        }

        function renderBanners() {
            const box = root.querySelector('[data-bm-banners]');
            if (!box || !evaluated) return;
            const r = evaluated.result || {};
            let html = '';
            if (isLocked()) {
                html += `<div class="bm-banner warn"><strong>Оценка зафиксирована.</strong> Для правок нажмите «Новый черновик» — будет создана новая версия на базе текущей.</div>`;
            }
            if (r.incomplete) {
                html += `<div class="bm-banner warn"><strong>Рейтинг ещё не присвоен.</strong> Заполните все показатели финансов и бизнес-риска.</div>`;
            }
            if (r.hard_stop) {
                const list = (r.mandatory_stops || []).map(function (s) {
                    return '<li>' + esc(s.label)
                        + (s.comment ? ' <span class="text-muted">(' + esc(s.comment) + ')</span>' : '')
                        + '</li>';
                }).join('');
                html += `<div class="bm-banner stop"><strong>Сработали обязательные стоп-факторы.</strong> Как правило — отказ от сделки.<ul class="mb-0 mt-2">${list}</ul></div>`;
            }
            if ((r.conditional_stops || []).length) {
                const list = (r.conditional_stops || []).map(function (s) {
                    return '<li>' + esc(s.label)
                        + (s.comment ? ' <span class="text-muted">(' + esc(s.comment) + ')</span>' : '')
                        + '</li>';
                }).join('');
                html += `<div class="bm-banner warn"><strong>Отмечены условные стоп-факторы.</strong> Не блокируют оценку автоматически — учитываются в профсуждении.<ul class="mb-0 mt-2">${list}</ul></div>`;
            }
            (r.warnings || []).forEach(function (w) {
                if (r.incomplete && String(w).indexOf('Недостаточно данных') === 0) {
                    return; // уже показали отдельным баннером
                }
                html += `<div class="bm-banner warn">${esc(w)}</div>`;
            });
            box.innerHTML = html;
        }

        function renderMetricScores() {
            if (!evaluated) return;
            const fin = (evaluated.finance && evaluated.finance.metrics) || {};
            Object.keys(fin).forEach(function (id) {
                const pill = root.querySelector('[data-bm-fin-pill="' + id + '"]');
                if (!pill) return;
                const m = fin[id];
                pill.textContent = fmtScore(m.score) + ' / ' + m.max;
                pill.className = 'score-pill' + (m.source === 'edited' ? ' edited' : (m.source === 'pending' ? ' pending' : ''));
                const note = root.querySelector('[data-bm-fin-note="' + id + '"]');
                if (note) {
                    const parts = [];
                    if (m.value != null && m.value !== '') {
                        const unit = m.unit === '%' ? '%' : '';
                        parts.push(fmtScore(m.value) + unit);
                    }
                    if (m.note) parts.push(m.note);
                    note.textContent = parts.join(' · ');
                }
            });

            const biz = (evaluated.business && evaluated.business.metrics) || {};
            Object.keys(biz).forEach(function (id) {
                const pill = root.querySelector('[data-bm-biz-pill="' + id + '"]');
                if (!pill) return;
                const m = biz[id];
                pill.textContent = fmtScore(m.score) + ' / ' + m.max;
                pill.className = 'score-pill' + (m.source === 'edited' ? ' edited' : (m.source === 'pending' ? ' pending' : ''));
            });
        }

        function finField(label, key, opts) {
            opts = opts || {};
            const inputs = state.finance.inputs || {};
            const val = numOrEmpty(inputs[key]);
            const ph = opts.placeholder != null ? opts.placeholder : 'руб';
            return `
                <div class="bm-field">
                    <label>${esc(label)}</label>
                    <input data-bm-fin="${esc(key)}" inputmode="decimal" value="${esc(val)}" placeholder="${esc(ph)}">
                </div>`;
        }

        function renderAll() {
            if (!rules || !state) return;
            const stopGroupLabels = rules.stop_groups || {};
            const stopByGroup = {};
            (rules.stop_factors || []).forEach(function (sf) {
                const g = sf.group || 'other';
                if (!stopByGroup[g]) stopByGroup[g] = [];
                stopByGroup[g].push(sf);
            });
            const stopGroupOrder = Object.keys(stopGroupLabels).length
                ? Object.keys(stopGroupLabels)
                : Object.keys(stopByGroup);

            const stopCountsNow = (function () {
                let on = 0;
                (rules.stop_factors || []).forEach(function (sf) {
                    const row = (state.stop_factors || []).find(function (x) { return x.code === sf.code; }) || {};
                    if (row.triggered) on += 1;
                });
                return { on: on, total: (rules.stop_factors || []).length };
            })();

            const stopHtml = stopGroupOrder.map(function (groupId) {
                const items = stopByGroup[groupId];
                if (!items || !items.length) return '';
                const title = stopGroupLabels[groupId] || groupId;
                const isMulti = items.length > 1;
                const groupCollapsed = !!stopGroupCollapsed[groupId];
                const cards = items.map(function (sf) {
                    const row = (state.stop_factors || []).find(function (x) { return x.code === sf.code; }) || {};
                    const on = !!row.triggered;
                    const cond = !sf.mandatory;
                    const short = sf.short_label || sf.label;
                    const moreOpen = !!stopMoreOpen[sf.code];
                    const fromChecko = on && row.source === 'checko';
                    const id = 'bm-stop-' + String(sf.code).replace(/[^a-zA-Z0-9_-]/g, '_');
                    return `
                    <div class="bm-stop-item ${on ? 'is-on' : ''} ${cond ? 'is-conditional' : ''} ${moreOpen ? 'is-more-open' : ''}${fromChecko ? ' is-checko' : ''}" data-bm-stop-row="${esc(sf.code)}" data-bm-stop-source="${esc(row.source || 'manual')}">
                        <div class="bm-stop-main">
                            <label class="bm-stop-head" for="${esc(id)}">
                                <input id="${esc(id)}" type="checkbox" data-bm-stop="${esc(sf.code)}" ${on ? 'checked' : ''}>
                                <span class="title">${esc(short)}${fromChecko ? '<span class="bm-source-pill" title="Проставлено из Checko">Checko</span>' : ''}</span>
                            </label>
                            <button type="button" class="bm-stop-info${moreOpen ? ' is-open' : ''}" data-bm-stop-more="${esc(sf.code)}" title="Полная формулировка" aria-expanded="${moreOpen ? 'true' : 'false'}">i</button>
                        </div>
                        <div class="bm-stop-full"${moreOpen ? '' : ' hidden'}>${esc(sf.label)}</div>
                        <textarea data-bm-stop-comment="${esc(sf.code)}" rows="2" placeholder="Комментарий"${on ? '' : ' hidden'}>${esc(row.comment || '')}</textarea>
                    </div>`;
                }).join('');

                if (isMulti) {
                    return `
                    <div class="bm-stop-group${groupCollapsed ? ' is-collapsed' : ''}" data-bm-stop-group="${esc(groupId)}">
                        <button type="button" class="bm-stop-group-toggle" data-bm-stop-group-toggle="${esc(groupId)}" aria-expanded="${groupCollapsed ? 'false' : 'true'}">
                            <span class="bm-stop-group-title">${esc(title)}</span>
                            <span class="bm-stop-group-count">${items.length}</span>
                            <span class="bm-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="bm-stop-list">${cards}</div>
                    </div>`;
                }
                return `
                <div class="bm-stop-group bm-stop-group-single" data-bm-stop-group="${esc(groupId)}">
                    <div class="bm-stop-list">${cards}</div>
                </div>`;
            }).join('');

            const inputs = state.finance.inputs || {};
            const locked = isLocked();
            const isQ1 = inputs.reporting_period === 'q1';

            const finInputsHtml = `
                <div class="bm-fin-blocks">
                    <div class="bm-fin-block">
                        <div class="bm-fin-block-title">Контекст</div>
                        <div class="bm-inputs bm-inputs-2">
                            <div class="bm-field">
                                <label>Отчётный период</label>
                                <select data-bm-fin="reporting_period">
                                    <option value="annual" ${!inputs.reporting_period || inputs.reporting_period === 'annual' ? 'selected' : ''}>Год / иной период</option>
                                    <option value="q1" ${inputs.reporting_period === 'q1' ? 'selected' : ''}>1 квартал</option>
                                    <option value="q2" ${inputs.reporting_period === 'q2' ? 'selected' : ''}>2 квартал</option>
                                    <option value="9m" ${inputs.reporting_period === '9m' || inputs.reporting_period === 'q3' ? 'selected' : ''}>9 месяцев (3 кв.)</option>
                                </select>
                            </div>
                            <div class="bm-field">
                                <label>Отрасль</label>
                                <select data-bm-fin="industry">
                                    <option value="default" ${inputs.industry === 'default' || !inputs.industry ? 'selected' : ''}>Обычная</option>
                                    <option value="leasing" ${inputs.industry === 'leasing' ? 'selected' : ''}>Лизинг</option>
                                    <option value="factoring" ${inputs.industry === 'factoring' ? 'selected' : ''}>Факторинг</option>
                                </select>
                            </div>
                        </div>
                        <div class="bm-q1-block${isQ1 ? ' is-visible' : ''}" data-bm-q1-block ${isQ1 ? '' : 'hidden'}>
                            <label class="bm-inline-check"><input type="checkbox" data-bm-fin-flag="q1_seasonal_loss_explained" ${inputs.q1_seasonal_loss_explained ? 'checked' : ''}> Убыток 1 кв. — сезонность</label>
                            <div class="bm-field bm-field-grow bm-q1-comment${!inputs.q1_seasonal_loss_explained ? ' is-hidden' : ''}" data-bm-q1-comment>
                                <label>Комментарий к сезонности</label>
                                <input data-bm-fin="q1_seasonal_comment" value="${esc(inputs.q1_seasonal_comment || '')}" placeholder="Обоснование сезонности">
                            </div>
                        </div>
                    </div>

                    <div class="bm-fin-block">
                        <div class="bm-fin-block-title">ОПиУ</div>
                        <div class="bm-inputs bm-inputs-2">
                            ${finField('Выручка (период)', 'revenue')}
                            ${finField('Выручка (год)', 'revenue_last_year')}
                            ${finField('Чистая прибыль (период)', 'net_profit')}
                            ${finField('Чистая прибыль (год)', 'prior_year_net_profit')}
                        </div>
                        <details class="bm-fin-details">
                            <summary>Аналог выручки <span class="bm-muted-note">если выручка = 0</span></summary>
                            <div class="bm-inputs bm-inputs-3">
                                ${finField('Доходы от участия', 'income_from_participation')}
                                ${finField('Проценты к получению', 'interest_receivable')}
                                ${finField('Прочие доходы', 'other_income')}
                            </div>
                        </details>
                    </div>

                    <div class="bm-fin-block">
                        <div class="bm-fin-block-title">Баланс</div>
                        <div class="bm-inputs bm-inputs-3">
                            ${finField('СК', 'equity')}
                            ${finField('Текущие активы', 'current_assets')}
                            ${finField('Текущие обязательства', 'current_liabilities')}
                            ${finField('Долгосрочные обязательства', 'long_term_liabilities')}
                            ${finField('Валюта баланса', 'balance_total')}
                        </div>
                    </div>

                    <div class="bm-fin-block">
                        <div class="bm-fin-block-title">Долг</div>
                        <div class="bm-inputs bm-inputs-2">
                            ${finField('Краткосрочные займы', 'short_term_borrowings')}
                            ${finField('Кредиторская задолженность', 'accounts_payable')}
                            ${finField('Прочие краткоср. обязательства', 'other_short_liabilities')}
                            ${finField('Долгосрочные займы', 'long_term_borrowings')}
                        </div>
                    </div>
                </div>`;

            const finMetrics = rules.finance_metrics || {};
            const finGroups = rules.finance_groups || {};
            const metricsByGroup = {};
            Object.keys(finMetrics).forEach(function (id) {
                const g = finMetrics[id].group || 'other';
                if (!metricsByGroup[g]) metricsByGroup[g] = [];
                metricsByGroup[g].push(id);
            });
            const finGroupOrder = Object.keys(finGroups).length
                ? Object.keys(finGroups)
                : Object.keys(metricsByGroup);
            const finMetricsHtml = finGroupOrder.map(function (gid) {
                const ids = metricsByGroup[gid] || [];
                if (!ids.length) return '';
                const gLabel = (finGroups[gid] && finGroups[gid].label) || gid;
                const rows = ids.map(function (id) {
                    const m = finMetrics[id];
                    return `
                    <div class="bm-fin-metric-row">
                        <div class="name">${esc(m.label)}</div>
                        <div class="note" data-bm-fin-note="${esc(id)}"></div>
                        <span class="score-pill" data-bm-fin-pill="${esc(id)}">—</span>
                    </div>`;
                }).join('');
                return `
                <div class="bm-fin-group">
                    <div class="bm-fin-group-title">${esc(gLabel)}</div>
                    <div class="bm-fin-metric-list">${rows}</div>
                </div>`;
            }).join('');

            const bizGroups = [
                { title: 'КИ и срок', ids: ['credit_history', 'company_age'] },
                { title: 'Контракты', ids: ['comparable_contracts', 'gov_contracts'] },
                { title: 'Собственники, правовые риски и учёт', ids: ['ownership_stability', 'legal_risk_client', 'legal_risk_founders', 'accounting_accuracy'] },
            ];
            const bizHtml = bizGroups.map(function (grp) {
                const rows = grp.ids.map(function (id) {
                    const m = (rules.business_metrics || {})[id];
                    if (!m) return '';
                    const row = state.business[id] || {};
                    const opts = (m.options || []).map(function (o) {
                        return `<option value="${esc(o.value)}" ${String(row.value) === String(o.value) ? 'selected' : ''}>${esc(o.label)} (${o.score})</option>`;
                    }).join('');
                    const hint = m.hint || (m.manual_only ? 'только вручную' : '');
                    const hintHtml = hint
                        ? `<span class="bm-hint" title="${esc(hint)}"><span class="bm-hint-icon">i</span><span class="bm-hint-text">${esc(hint)}${m.manual_only && m.hint ? ' · только вручную' : (m.manual_only && !m.hint ? '' : '')}</span></span>`
                        : '';
                    return `
                    <div class="bm-biz-row">
                        <div class="bm-biz-name">
                            <span class="name">${esc(m.label)}</span>
                            ${hintHtml}
                        </div>
                        <select data-bm-biz="${esc(id)}">
                            <option value="">— выберите —</option>
                            ${opts}
                        </select>
                        <span class="score-pill" data-bm-biz-pill="${esc(id)}">—</span>
                    </div>`;
                }).join('');
                return `
                <div class="bm-biz-group">
                    <div class="bm-biz-subhead">${esc(grp.title)}</div>
                    ${rows}
                </div>`;
            }).join('');

            const j = state.judgment || {};
            const ratingOpts = (rules.rating_scale || []).map(function (row) {
                return `<option value="${esc(row.rating)}" ${String(j.established_rating || '') === String(row.rating) ? 'selected' : ''}>${esc(row.rating)} — ${esc(row.category)}</option>`;
            }).join('');
            const r0 = (evaluated && evaluated.result) || {};
            const calcLetter = r0.incomplete ? '' : (r0.calculated_rating || r0.rating || '');
            const calcCat = ratingCategory(calcLetter);
            const calcText = r0.incomplete
                ? 'Расчётный: —'
                : ('Расчётный: ' + (calcLetter || '—') + (calcCat ? ' · ' + calcCat : ''));
            const histItems = (history || []).slice(0, 8).map(function (h) {
                return `<li>v${esc(h.version)} · ${esc(h.status)} · ${esc(h.rating || '—')} · ${esc(h.total_score != null ? h.total_score : '—')}</li>`;
            }).join('');

            const actionsHtml = locked
                ? `<button type="button" class="btn btn-primary btn-sm" data-bm-action="newdraft" title="Создать редактируемую версию на базе зафиксированной"><i class="bi bi-plus-lg me-1"></i>Новый черновик</button>`
                : `<button type="button" class="btn btn-outline-secondary btn-sm" data-bm-action="checko" title="Стоп-факторы 1.1, 1.3, 1.5, 3, 4, C1 и пустые финансы из Checko"><i class="bi bi-cloud-download me-1"></i>Из Checko</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-bm-action="save"><i class="bi bi-save me-1"></i>Сохранить черновик</button>
                        <button type="button" class="btn btn-primary btn-sm" data-bm-action="finalize"><i class="bi bi-check2-circle me-1"></i>Зафиксировать</button>`;

            const rMeta = (evaluated && evaluated.result) || {};
            const sectionMeta = {
                stops: 'отмечено ' + stopCountsNow.on,
                finance: fmtScore(rMeta.finance_score) + '/50',
                business: fmtScore(rMeta.business_score) + '/50',
                judgment: rMeta.incomplete ? '—' : (rMeta.rating || '—'),
            };

            root.innerHTML = `
            <div class="bm-wrap${locked ? ' is-locked' : ''}">
                <div class="bm-hero">
                    <div class="bm-hero-text">
                        <h4>Банковская методика</h4>
                        <p>Авторасчёт по шкалам и ручная корректировка. Фиксация — официальная версия по заявке.</p>
                    </div>
                    <div class="bm-actions">
                        ${actionsHtml}
                    </div>
                </div>
                <div data-bm-summary class="bm-summary"></div>
                <div data-bm-banners></div>

                <div class="bm-section${sectionCollapsed.stops ? ' is-collapsed' : ''}" data-bm-section="stops">
                    <div class="bm-section-head" aria-expanded="${sectionCollapsed.stops ? 'false' : 'true'}">
                        <span class="bm-section-chevron" aria-hidden="true"></span>
                        <h5>1. Стоп-факторы</h5>
                        <span class="meta">${esc(sectionMeta.stops)}</span>
                    </div>
                    <div class="bm-section-body">
                        <div class="bm-stop-toolbar">
                            <span class="bm-stop-count" data-bm-stop-count>Отмечено ${stopCountsNow.on} из ${stopCountsNow.total}</span>
                            <div class="bm-stop-filters" role="group" aria-label="Фильтр стоп-факторов">
                                <button type="button" class="bm-chip${stopFilter === 'all' ? ' is-active' : ''}" data-bm-stop-filter="all">Все</button>
                                <button type="button" class="bm-chip${stopFilter === 'triggered' ? ' is-active' : ''}" data-bm-stop-filter="triggered">Только отмеченные</button>
                            </div>
                        </div>
                        <div class="bm-stop-groups">${stopHtml}</div>
                    </div>
                </div>

                <div class="bm-section${sectionCollapsed.finance ? ' is-collapsed' : ''}" data-bm-section="finance">
                    <div class="bm-section-head" aria-expanded="${sectionCollapsed.finance ? 'false' : 'true'}">
                        <span class="bm-section-chevron" aria-hidden="true"></span>
                        <h5>2. Финансы</h5>
                        <span class="meta">${esc(sectionMeta.finance)}</span>
                    </div>
                    <div class="bm-section-body">
                        ${finInputsHtml}
                        <div class="bm-finance-metrics">${finMetricsHtml}</div>
                    </div>
                </div>

                <div class="bm-section${sectionCollapsed.business ? ' is-collapsed' : ''}" data-bm-section="business">
                    <div class="bm-section-head" aria-expanded="${sectionCollapsed.business ? 'false' : 'true'}">
                        <span class="bm-section-chevron" aria-hidden="true"></span>
                        <h5>3. Бизнес-риск</h5>
                        <span class="meta">${esc(sectionMeta.business)}</span>
                    </div>
                    <div class="bm-section-body"><div class="bm-business-list">${bizHtml}</div></div>
                </div>

                <div class="bm-section${sectionCollapsed.judgment ? ' is-collapsed' : ''}" data-bm-section="judgment">
                    <div class="bm-section-head" aria-expanded="${sectionCollapsed.judgment ? 'false' : 'true'}">
                        <span class="bm-section-chevron" aria-hidden="true"></span>
                        <h5>4. Профсуждение</h5>
                        <span class="meta">${esc(sectionMeta.judgment)}</span>
                    </div>
                    <div class="bm-section-body bm-judgment">
                        <div class="mb-3">
                            <label class="form-label small text-muted fw-bold">Комментарий</label>
                            <textarea data-bm-judgment="comment" placeholder="Профессиональное суждение по оценке">${esc(j.comment || '')}</textarea>
                        </div>
                        <div class="bm-judgment-rating mb-3">
                            <div class="bm-judgment-rating-row">
                                <div class="bm-field bm-field-grow">
                                    <label class="form-label small text-muted fw-bold">Установленный рейтинг</label>
                                    <select data-bm-judgment="established_rating">
                                        <option value="">— как расчётный —</option>
                                        ${ratingOpts}
                                    </select>
                                </div>
                                <div class="bm-judgment-calc" data-bm-judgment-calc>${esc(calcText)}</div>
                            </div>
                            <div class="hint mt-1">Расчётный рейтинг можно скорректировать с обоснованием.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted fw-bold">Основания повышения / понижения рейтинга</label>
                            <textarea data-bm-judgment="upgrade_downgrade_reason">${esc(j.upgrade_downgrade_reason || '')}</textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted fw-bold">Заключение</label>
                            <textarea data-bm-judgment="conclusion" placeholder="Итоговое заключение по оценке финансового положения">${esc(j.conclusion || '')}</textarea>
                        </div>
                        <div class="bm-checks">
                            <label><input type="checkbox" data-bm-judgment="force_not_good" ${j.force_not_good ? 'checked' : ''}> Обстоятельства 590-П, исключающие «Хорошее»</label>
                        </div>
                        <div class="bm-history mt-3">
                            ${histItems
                                ? ('<div class="bm-history-title">История</div><ul class="bm-history-list">' + histItems + '</ul>')
                                : '<div class="bm-history-empty">История версий появится после сохранений</div>'}
                        </div>
                    </div>
                </div>
            </div>`;

            bind();
            applyLockState();
            applyStopFilter();
            applyQ1Visibility();
            renderSummary();
            renderBanners();
            renderMetricScores();
            updateSectionMeta();
            updateJudgmentCalc();
            updateStickyOffsets();
            ensureStickyScrollBound();
            pinSectionHeads();
        }

        function applyLockState() {
            const locked = isLocked();
            const wrap = root.querySelector('.bm-wrap');
            if (wrap) wrap.classList.toggle('is-locked', locked);
            root.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.disabled = locked;
            });
            root.querySelectorAll('[data-bm-stop-more], [data-bm-stop-filter], [data-bm-stop-group-toggle]').forEach(function (el) {
                // info/filter/toggle remain usable when locked for reading
                el.disabled = false;
            });
        }

        function bind() {
            root.querySelectorAll('.bm-section-head').forEach(function (head) {
                head.addEventListener('click', function () {
                    const section = head.parentElement;
                    if (!section) return;
                    const key = section.getAttribute('data-bm-section');
                    section.classList.toggle('is-collapsed');
                    const collapsed = section.classList.contains('is-collapsed');
                    head.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                    if (key && Object.prototype.hasOwnProperty.call(sectionCollapsed, key)) {
                        sectionCollapsed[key] = collapsed;
                    }
                    if (collapsed) unpinSectionHead(head);
                    updateStickyOffsets();
                    pinSectionHeads();
                });
            });

            let timer = null;
            function scheduleRecalc() {
                if (isLocked()) return;
                dirty = true;
                editGeneration += 1;
                renderSummary();
                clearTimeout(timer);
                timer = setTimeout(function () { recalculateSilent(); }, 280);
                scheduleAutosave();
            }

            root.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.addEventListener('change', scheduleRecalc);
                el.addEventListener('input', scheduleRecalc);
            });

            root.querySelectorAll('[data-bm-stop]').forEach(function (el) {
                el.addEventListener('change', function () {
                    const item = el.closest('.bm-stop-item');
                    if (item) {
                        item.classList.toggle('is-on', !!el.checked);
                        item.setAttribute('data-bm-stop-source', 'manual');
                        item.classList.remove('is-checko');
                        const pill = item.querySelector('.bm-source-pill');
                        if (pill) pill.remove();
                        const ta = item.querySelector('textarea[data-bm-stop-comment]');
                        if (ta) ta.hidden = !el.checked;
                    }
                    updateStopToolbar();
                    applyStopFilter();
                    updateSectionMeta();
                });
            });

            root.querySelectorAll('[data-bm-stop-more]').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const code = btn.getAttribute('data-bm-stop-more');
                    const item = btn.closest('.bm-stop-item');
                    if (!item || !code) return;
                    const open = !item.classList.contains('is-more-open');
                    item.classList.toggle('is-more-open', open);
                    btn.classList.toggle('is-open', open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    const full = item.querySelector('.bm-stop-full');
                    if (full) full.hidden = !open;
                    stopMoreOpen[code] = open;
                });
            });

            root.querySelectorAll('[data-bm-stop-filter]').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    stopFilter = btn.getAttribute('data-bm-stop-filter') || 'all';
                    applyStopFilter();
                });
            });

            root.querySelectorAll('[data-bm-stop-group-toggle]').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    const gid = btn.getAttribute('data-bm-stop-group-toggle');
                    const group = btn.closest('[data-bm-stop-group]');
                    if (!group || !gid) return;
                    const collapsed = !group.classList.contains('is-collapsed');
                    group.classList.toggle('is-collapsed', collapsed);
                    btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                    stopGroupCollapsed[gid] = collapsed;
                });
            });

            const periodSel = root.querySelector('[data-bm-fin="reporting_period"]');
            if (periodSel) {
                periodSel.addEventListener('change', function () {
                    applyQ1Visibility();
                });
            }
            const q1Flag = root.querySelector('[data-bm-fin-flag="q1_seasonal_loss_explained"]');
            if (q1Flag) {
                q1Flag.addEventListener('change', function () {
                    applyQ1Visibility();
                });
                q1Flag.addEventListener('click', function () {
                    // click раньше change — синхронно показать поле по текущему checked
                    requestAnimationFrame(applyQ1Visibility);
                });
            }

            const act = async function (name) {
                try {
                    if (name === 'save') {
                        if (isLocked()) {
                            notify('Оценка зафиксирована. Создайте новый черновик для правок.', 'error');
                            return;
                        }
                        clearTimeout(autosaveTimer);
                        const s = collectStateFromDom();
                        const data = await api('save_draft', { state: s });
                        if (!data.success) throw new Error(data.error || 'Ошибка сохранения');
                        assessment = data.assessment;
                        evaluated = data.evaluated;
                        state = data.evaluated.state;
                        dirty = false;
                        history = await reloadHistory();
                        renderSummary();
                        renderBanners();
                        renderMetricScores();
                        updateSectionMeta();
                        updateJudgmentCalc();
                        notify(data.message || 'Сохранено', 'success');
                        return;
                    }
                    if (name === 'finalize') {
                        if (isLocked()) {
                            notify('Эта версия уже зафиксирована.', 'error');
                            return;
                        }
                        clearTimeout(autosaveTimer);
                        const s = collectStateFromDom();
                        // локальная проверка incomplete до запроса
                        const preview = await api('recalculate', { state: s });
                        if (preview.success && preview.evaluated) {
                            evaluated = preview.evaluated;
                            state = preview.evaluated.state;
                            renderSummary();
                            renderBanners();
                            renderMetricScores();
                            updateSectionMeta();
                            updateJudgmentCalc();
                            if (preview.evaluated.result && preview.evaluated.result.incomplete) {
                                throw new Error('Нельзя зафиксировать: заполните все показатели финансов и бизнес-риска.');
                            }
                        }
                        if (!window.confirm('Зафиксировать текущую оценку как официальную версию по заявке?')) return;
                        const data = await api('finalize', { state: s });
                        if (!data.success) throw new Error(data.error || 'Ошибка фиксации');
                        assessment = data.assessment;
                        evaluated = data.evaluated;
                        state = data.evaluated.state;
                        dirty = false;
                        history = await reloadHistory();
                        renderAll();
                        notify(data.message || 'Зафиксировано', 'success');
                        return;
                    }
                    if (name === 'checko') {
                        if (isLocked()) {
                            notify('Оценка зафиксирована. Создайте новый черновик для правок.', 'error');
                            return;
                        }
                        clearTimeout(autosaveTimer);
                        const s = collectStateFromDom();
                        const data = await api('pull_checko', { state: s });
                        if (!data.success) throw new Error(data.error || 'Ошибка Checko');
                        assessment = data.assessment;
                        evaluated = data.evaluated;
                        state = data.evaluated.state;
                        dirty = false;
                        history = await reloadHistory();
                        renderAll();
                        notify(data.message || 'Данные Checko подтянуты', 'success');
                        return;
                    }
                    if (name === 'newdraft') {
                        if (!isLocked()) {
                            notify('Новый черновик нужен только после фиксации — чтобы править уже утверждённую версию.', 'info');
                            return;
                        }
                        if (!window.confirm('Создать новый черновик на базе зафиксированной оценки? Текущая версия останется в истории.')) return;
                        const data = await api('new_draft', {});
                        if (!data.success) throw new Error(data.error || 'Ошибка');
                        assessment = data.assessment;
                        evaluated = data.evaluated;
                        state = data.evaluated.state;
                        dirty = false;
                        history = await reloadHistory();
                        renderAll();
                        notify(data.message || 'Черновик готов', 'success');
                    }
                } catch (e) {
                    notify(e.message || 'Ошибка', 'error');
                }
            };

            root.querySelectorAll('[data-bm-action]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    act(btn.getAttribute('data-bm-action'));
                });
            });
        }

        async function reloadHistory() {
            const data = await api('get');
            return data.history || [];
        }

        async function load() {
            root.innerHTML = '<div class="bm-empty">Загрузка методики…</div>';
            try {
                const data = await api('get');
                if (!data.success) throw new Error(data.error || 'Ошибка загрузки');
                rules = data.rules;
                assessment = data.assessment;
                history = data.history || [];
                evaluated = data.evaluated;
                state = (data.evaluated && data.evaluated.state)
                    || (data.assessment && data.assessment.state)
                    || null;
                if (!state) {
                    // empty from server evaluated always has state
                    state = data.evaluated.state;
                }
                renderAll();
            } catch (e) {
                root.innerHTML = '<div class="bm-empty text-danger">' + esc(e.message || 'Ошибка') + '</div>';
            }
        }

        // lazy load when tab shown
        const tabBtn = document.getElementById(opts.tabId || 'bank-methodology-tab');
        function ensureLoad() {
            if (loaded) return;
            loaded = true;
            load();
        }
        if (tabBtn) {
            tabBtn.addEventListener('shown.bs.tab', ensureLoad);
            if (tabBtn.classList.contains('active')) ensureLoad();
        } else {
            ensureLoad();
        }

        // also if URL tab points here
        const params = new URLSearchParams(window.location.search);
        if (params.get('tab') === 'bankAppMethodology' || params.get('tab') === 'methodology') ensureLoad();

        window.addEventListener('beforeunload', function (e) {
            if (!dirty || isLocked()) return;
            e.preventDefault();
            e.returnValue = '';
        });

        const ctl = {
            setApplicationId: function (id) {
                const next = Number(id || 0);
                if (next <= 0 || next === applicationId) return;
                clearTimeout(autosaveTimer);
                applicationId = next;
                dirty = false;
                editGeneration += 1;
                rules = null;
                state = null;
                evaluated = null;
                assessment = null;
                history = [];
                stopFilter = 'all';
                stopGroupCollapsed = {};
                stopMoreOpen = {};
                sectionCollapsed = { stops: false, finance: true, business: true, judgment: true };
                if (loaded) {
                    load();
                } else {
                    ensureLoad();
                }
            }
        };
        root._bmCtl = ctl;
        return ctl;
    }

    window.initBankMethodology = initBankMethodology;
})(window);
