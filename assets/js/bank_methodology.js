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
                return {
                    code: sf.code,
                    triggered: !!(cb && cb.checked),
                    source: 'manual',
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
                next.business[id].value = el.value === '' ? null : el.value;
                next.business[id].source = 'manual';
                next.business[id].score_override = null;
            });

            // judgment
            const comment = root.querySelector('[data-bm-judgment="comment"]');
            const reason = root.querySelector('[data-bm-judgment="upgrade_downgrade_reason"]');
            const force = root.querySelector('[data-bm-judgment="force_not_good"]');
            const negEq = root.querySelector('[data-bm-judgment="negative_equity"]');
            const est = root.querySelector('[data-bm-judgment="established_rating"]');
            next.judgment.comment = comment ? comment.value : '';
            next.judgment.upgrade_downgrade_reason = reason ? reason.value : '';
            next.judgment.force_not_good = !!(force && force.checked);
            next.judgment.negative_equity = !!(negEq && negEq.checked);
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
        }

        function isLocked() {
            return !!(assessment && assessment.status === 'final');
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
            const statusLabel = status === 'final'
                ? (dirty ? 'зафиксировано · изменённый просмотр' : 'зафиксировано')
                : (dirty ? 'черновик · не сохранено' : 'черновик');
            const ratingText = incomplete ? '—' : (r.rating || '—');
            const posText = r.position_label || '';
            const calcRating = r.calculated_rating || '';
            let ratingSub = esc(posText) + (r.hard_stop ? ' · стоп' : '');
            if (!incomplete && calcRating && String(calcRating) !== String(ratingText)) {
                ratingSub += ' · расчётный ' + esc(calcRating);
            }
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
                    <div class="meta">v${assessment ? assessment.version : '—'} · <span class="bm-status-chip ${esc(status)}">${esc(statusLabel)}</span></div>
                </div>
                <div class="bm-kpi bm-kpi-rating ${posClass}">
                    <div class="label">Рейтинг</div>
                    <div class="value">${esc(ratingText)}</div>
                    <div class="meta">${ratingSub}</div>
                </div>`;
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
                html += `<div class="bm-banner warn"><strong>Рейтинг ещё не присвоен.</strong> Заполните все показатели финансов и бизнес-риска (или укажите ручной балл).</div>`;
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
                        parts.push('Значение: ' + fmtScore(m.value) + unit);
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
            const stopHtml = stopGroupOrder.map(function (groupId) {
                const items = stopByGroup[groupId];
                if (!items || !items.length) return '';
                const title = stopGroupLabels[groupId] || groupId;
                const isMulti = items.length > 1;
                const cards = items.map(function (sf) {
                    const row = (state.stop_factors || []).find(function (x) { return x.code === sf.code; }) || {};
                    const on = !!row.triggered;
                    const cond = !sf.mandatory;
                    const id = 'bm-stop-' + String(sf.code).replace(/[^a-zA-Z0-9_-]/g, '_');
                    return `
                    <div class="bm-stop-item ${on ? 'is-on' : ''} ${cond ? 'is-conditional' : ''}">
                        <label class="bm-stop-head" for="${esc(id)}">
                            <input id="${esc(id)}" type="checkbox" data-bm-stop="${esc(sf.code)}" ${on ? 'checked' : ''}>
                            <span class="title">${esc(sf.label)}</span>
                        </label>
                        <textarea data-bm-stop-comment="${esc(sf.code)}" rows="2" placeholder="Комментарий">${esc(row.comment || '')}</textarea>
                    </div>`;
                }).join('');
                const heading = isMulti
                    ? `<h6 class="bm-stop-group-title">${esc(title)}</h6>`
                    : '';
                return `
                <div class="bm-stop-group${isMulti ? '' : ' bm-stop-group-single'}">
                    ${heading}
                    <div class="bm-stop-grid">${cards}</div>
                </div>`;
            }).join('');

            const inputs = state.finance.inputs || {};
            const locked = isLocked();
            const finInputsHtml = `
                <div class="bm-inputs">
                    <div><label>Выручка за текущий период, руб</label><input data-bm-fin="revenue" inputmode="decimal" value="${esc(numOrEmpty(inputs.revenue))}"></div>
                    <div><label>Выручка за последний завершённый год, руб</label><input data-bm-fin="revenue_last_year" inputmode="decimal" value="${esc(numOrEmpty(inputs.revenue_last_year))}"></div>
                    <div><label>Чистая прибыль (текущий период), руб</label><input data-bm-fin="net_profit" inputmode="decimal" value="${esc(numOrEmpty(inputs.net_profit))}"></div>
                    <div><label>Чистая прибыль за последний завершённый год, руб</label><input data-bm-fin="prior_year_net_profit" inputmode="decimal" value="${esc(numOrEmpty(inputs.prior_year_net_profit))}"></div>
                    <div><label>Доходы от участия в других организациях, руб</label><input data-bm-fin="income_from_participation" inputmode="decimal" value="${esc(numOrEmpty(inputs.income_from_participation))}"></div>
                    <div><label>Проценты к получению, руб</label><input data-bm-fin="interest_receivable" inputmode="decimal" value="${esc(numOrEmpty(inputs.interest_receivable))}"></div>
                    <div><label>Прочие доходы, руб</label><input data-bm-fin="other_income" inputmode="decimal" value="${esc(numOrEmpty(inputs.other_income))}"></div>
                    <div><label>Собственные средства (СК), руб</label><input data-bm-fin="equity" inputmode="decimal" value="${esc(numOrEmpty(inputs.equity))}"></div>
                    <div><label>Текущие активы, руб</label><input data-bm-fin="current_assets" inputmode="decimal" value="${esc(numOrEmpty(inputs.current_assets))}"></div>
                    <div><label>Текущие обязательства, руб</label><input data-bm-fin="current_liabilities" inputmode="decimal" value="${esc(numOrEmpty(inputs.current_liabilities))}"></div>
                    <div><label>Долгосрочные обязательства, руб</label><input data-bm-fin="long_term_liabilities" inputmode="decimal" value="${esc(numOrEmpty(inputs.long_term_liabilities))}"></div>
                    <div><label>Валюта баланса (итог), руб</label><input data-bm-fin="balance_total" inputmode="decimal" value="${esc(numOrEmpty(inputs.balance_total))}"></div>
                    <div><label>Краткосрочные займы, руб</label><input data-bm-fin="short_term_borrowings" inputmode="decimal" value="${esc(numOrEmpty(inputs.short_term_borrowings))}"></div>
                    <div><label>Кредиторская задолженность, руб</label><input data-bm-fin="accounts_payable" inputmode="decimal" value="${esc(numOrEmpty(inputs.accounts_payable))}"></div>
                    <div><label>Прочие краткосрочные обязательства, руб</label><input data-bm-fin="other_short_liabilities" inputmode="decimal" value="${esc(numOrEmpty(inputs.other_short_liabilities))}"></div>
                    <div><label>Долгосрочные займы, руб</label><input data-bm-fin="long_term_borrowings" inputmode="decimal" value="${esc(numOrEmpty(inputs.long_term_borrowings))}"></div>
                    <div><label>Отчётный период</label>
                        <select data-bm-fin="reporting_period">
                            <option value="annual" ${!inputs.reporting_period || inputs.reporting_period === 'annual' ? 'selected' : ''}>Год / иной период</option>
                            <option value="q1" ${inputs.reporting_period === 'q1' ? 'selected' : ''}>1 квартал</option>
                            <option value="q2" ${inputs.reporting_period === 'q2' ? 'selected' : ''}>2 квартал</option>
                            <option value="q3" ${inputs.reporting_period === 'q3' ? 'selected' : ''}>9 месяцев / 3 кв.</option>
                            <option value="9m" ${inputs.reporting_period === '9m' ? 'selected' : ''}>9 месяцев</option>
                        </select>
                    </div>
                    <div><label>Отрасль</label>
                        <select data-bm-fin="industry">
                            <option value="default" ${inputs.industry === 'default' || !inputs.industry ? 'selected' : ''}>Обычная</option>
                            <option value="leasing" ${inputs.industry === 'leasing' ? 'selected' : ''}>Лизинг</option>
                            <option value="factoring" ${inputs.industry === 'factoring' ? 'selected' : ''}>Факторинг</option>
                        </select>
                    </div>
                    <div>
                        <label>&nbsp;</label>
                        <label class="bm-inline-check"><input type="checkbox" data-bm-fin-flag="q1_seasonal_loss_explained" ${inputs.q1_seasonal_loss_explained ? 'checked' : ''}> Убыток 1 кв. — сезонность</label>
                    </div>
                    <div style="grid-column:1/-1">
                        <label>Комментарий к убытку 1 кв. (обязателен для правила сезонности)</label>
                        <input data-bm-fin="q1_seasonal_comment" value="${esc(inputs.q1_seasonal_comment || '')}" placeholder="Обоснование сезонности">
                    </div>
                </div>`;

            const finMetrics = rules.finance_metrics || {};
            const finMetricsHtml = Object.keys(finMetrics).map(function (id) {
                const m = finMetrics[id];
                return `
                <div class="bm-metric bm-metric-compact">
                    <div>
                        <div class="name">${esc(m.label)}</div>
                        <div class="hint" data-bm-fin-note="${esc(id)}"></div>
                    </div>
                    <div>
                        <label>Балл</label>
                        <div><span class="score-pill" data-bm-fin-pill="${esc(id)}">—</span></div>
                    </div>
                </div>`;
            }).join('');

            const bizHtml = Object.keys(rules.business_metrics || {}).map(function (id) {
                const m = rules.business_metrics[id];
                const row = state.business[id] || {};
                const opts = (m.options || []).map(function (o) {
                    return `<option value="${esc(o.value)}" ${String(row.value) === String(o.value) ? 'selected' : ''}>${esc(o.label)} (${o.score})</option>`;
                }).join('');
                return `
                <div class="bm-metric bm-metric-compact">
                    <div>
                        <div class="name">${esc(m.label)}</div>
                        <div class="hint">${esc(m.hint || '')}${m.manual_only ? ' · только вручную' : ''}</div>
                        <div class="mt-2">
                            <label>Градация</label>
                            <select data-bm-biz="${esc(id)}">
                                <option value="">— выберите —</option>
                                ${opts}
                            </select>
                        </div>
                    </div>
                    <div>
                        <label>Балл</label>
                        <div><span class="score-pill" data-bm-biz-pill="${esc(id)}">—</span></div>
                    </div>
                </div>`;
            }).join('');

            const j = state.judgment || {};
            const ratingOpts = (rules.rating_scale || []).map(function (row) {
                return `<option value="${esc(row.rating)}" ${String(j.established_rating || '') === String(row.rating) ? 'selected' : ''}>${esc(row.rating)} — ${esc(row.category)}</option>`;
            }).join('');
            const hist = (history || []).slice(0, 8).map(function (h) {
                return `v${h.version} · ${h.status} · ${h.rating || '—'} · ${h.total_score != null ? h.total_score : '—'}`;
            }).join(' · ');

            const actionsHtml = locked
                ? `<button type="button" class="btn btn-primary btn-sm" data-bm-action="newdraft" title="Создать редактируемую версию на базе зафиксированной"><i class="bi bi-plus-lg me-1"></i>Новый черновик</button>`
                : `<button type="button" class="btn btn-outline-secondary btn-sm" data-bm-action="recalc"><i class="bi bi-arrow-repeat me-1"></i>Пересчитать</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-bm-action="save"><i class="bi bi-save me-1"></i>Сохранить черновик</button>
                        <button type="button" class="btn btn-primary btn-sm" data-bm-action="finalize"><i class="bi bi-check2-circle me-1"></i>Зафиксировать</button>`;

            root.innerHTML = `
            <div class="bm-wrap${locked ? ' is-locked' : ''}">
                <div class="bm-hero">
                    <div>
                        <h4>Банковская методика</h4>
                        <p>Авторасчёт по шкалам методики и ручная корректировка менеджером. Фиксация создаёт официальную версию по заявке.</p>
                    </div>
                    <div class="bm-actions">
                        ${actionsHtml}
                    </div>
                </div>
                <div data-bm-summary class="bm-summary"></div>
                <div data-bm-banners></div>

                <div class="bm-section" data-bm-section="stops">
                    <div class="bm-section-head"><h5>1. Стоп-факторы</h5><span class="meta">обязательные и условные</span></div>
                    <div class="bm-section-body"><div class="bm-stop-groups">${stopHtml}</div></div>
                </div>

                <div class="bm-section" data-bm-section="finance">
                    <div class="bm-section-head"><h5>2. Финансы</h5><span class="meta">до 50 баллов</span></div>
                    <div class="bm-section-body">
                        ${finInputsHtml}
                        <div class="bm-finance-grid">${finMetricsHtml}</div>
                    </div>
                </div>

                <div class="bm-section" data-bm-section="business">
                    <div class="bm-section-head"><h5>3. Бизнес-риск</h5><span class="meta">до 50 баллов</span></div>
                    <div class="bm-section-body"><div class="bm-business-grid">${bizHtml}</div></div>
                </div>

                <div class="bm-section" data-bm-section="judgment">
                    <div class="bm-section-head"><h5>4. Профсуждение</h5><span class="meta">расчётный и установленный рейтинг</span></div>
                    <div class="bm-section-body bm-judgment">
                        <div class="mb-3">
                            <label class="form-label small text-muted fw-bold">Комментарий</label>
                            <textarea data-bm-judgment="comment" placeholder="Профессиональное суждение по оценке">${esc(j.comment || '')}</textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted fw-bold">Установленный рейтинг</label>
                            <select data-bm-judgment="established_rating">
                                <option value="">— как расчётный —</option>
                                ${ratingOpts}
                            </select>
                            <div class="hint mt-1">По методике: расчётный рейтинг можно скорректировать с обоснованием.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted fw-bold">Основания повышения / понижения рейтинга</label>
                            <textarea data-bm-judgment="upgrade_downgrade_reason">${esc(j.upgrade_downgrade_reason || '')}</textarea>
                        </div>
                        <div class="bm-checks">
                            <label><input type="checkbox" data-bm-judgment="negative_equity" ${j.negative_equity ? 'checked' : ''}> Отрицательный СК</label>
                            <label><input type="checkbox" data-bm-judgment="force_not_good" ${j.force_not_good ? 'checked' : ''}> Обстоятельства, исключающие «Хорошее»</label>
                        </div>
                        <div class="bm-history mt-3">${hist ? ('История: ' + esc(hist)) : 'История версий появится после сохранений'}</div>
                    </div>
                </div>
            </div>`;

            bind();
            applyLockState();
            renderSummary();
            renderBanners();
            renderMetricScores();
        }

        function applyLockState() {
            const locked = isLocked();
            const wrap = root.querySelector('.bm-wrap');
            if (wrap) wrap.classList.toggle('is-locked', locked);
            root.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.disabled = locked;
            });
        }

        function bind() {
            root.querySelectorAll('.bm-section-head').forEach(function (head) {
                head.addEventListener('click', function () {
                    head.parentElement.classList.toggle('is-collapsed');
                });
            });

            let timer = null;
            function scheduleRecalc() {
                if (isLocked()) return;
                dirty = true;
                clearTimeout(timer);
                timer = setTimeout(function () { recalculateSilent(); }, 280);
            }

            root.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.addEventListener('change', scheduleRecalc);
                el.addEventListener('input', scheduleRecalc);
            });

            root.querySelectorAll('[data-bm-stop]').forEach(function (el) {
                el.addEventListener('change', function () {
                    const item = el.closest('.bm-stop-item');
                    if (item) item.classList.toggle('is-on', !!el.checked);
                });
            });

            const act = async function (name) {
                try {
                    if (name === 'recalc') {
                        if (isLocked()) return;
                        await recalculateSilent();
                        notify('Пересчитано', 'success');
                        return;
                    }
                    if (name === 'save') {
                        if (isLocked()) {
                            notify('Оценка зафиксирована. Создайте новый черновик для правок.', 'error');
                            return;
                        }
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
                        notify(data.message || 'Сохранено', 'success');
                        return;
                    }
                    if (name === 'finalize') {
                        if (isLocked()) {
                            notify('Эта версия уже зафиксирована.', 'error');
                            return;
                        }
                        const s = collectStateFromDom();
                        // локальная проверка incomplete до запроса
                        const preview = await api('recalculate', { state: s });
                        if (preview.success && preview.evaluated) {
                            evaluated = preview.evaluated;
                            state = preview.evaluated.state;
                            renderSummary();
                            renderBanners();
                            renderMetricScores();
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

        const ctl = {
            setApplicationId: function (id) {
                const next = Number(id || 0);
                if (next <= 0 || next === applicationId) return;
                applicationId = next;
                dirty = false;
                rules = null;
                state = null;
                evaluated = null;
                assessment = null;
                history = [];
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
