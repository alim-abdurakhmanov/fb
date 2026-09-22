/**
 * Оценка по банковской методике — UI в ЛК банка.
 * FinScore не затрагивается.
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

        const nextCaseId = Number(opts.bankCaseId || 0);
        // Повторный вызов на том же root — смена кейса без повторной подписки на вкладку
        if (root._bmCtl && typeof root._bmCtl.setCaseId === 'function') {
            root._bmCtl.setCaseId(nextCaseId);
            return root._bmCtl;
        }

        let caseId = nextCaseId;
        let rules = null;
        let state = null;
        let evaluated = null;
        let assessment = null;
        let history = [];
        let dirty = false;
        let loaded = false;

        async function api(action, payload) {
            if (action === 'get') {
                const url = 'api_bank_methodology.php?action=get&bank_case_id=' + encodeURIComponent(String(caseId));
                const r = await fetch(url, { credentials: 'same-origin' });
                return r.json();
            }
            const body = new FormData();
            body.append('action', action);
            body.append('bank_case_id', String(caseId));
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
                'revenue', 'net_profit', 'equity', 'current_assets', 'current_liabilities',
                'long_term_liabilities', 'balance_total', 'debt_to_revenue', 'industry',
                'short_term_borrowings', 'long_term_borrowings', 'accounts_payable', 'other_short_liabilities'
            ];
            finKeys.forEach(function (k) {
                const el = root.querySelector('[data-bm-fin="' + k + '"]');
                if (!el) return;
                next.finance.inputs[k] = el.type === 'checkbox' ? el.checked : (el.value === '' ? null : el.value);
            });
            ['q1_seasonal_loss_explained', 'profitability_explained_zero', 'roe_explained_zero',
                'missing_equity', 'no_current_liabilities', 'no_revenue'].forEach(function (k) {
                const el = root.querySelector('[data-bm-fin-flag="' + k + '"]');
                if (el) next.finance.inputs[k] = !!el.checked;
            });

            // finance score overrides
            next.finance.score_overrides = next.finance.score_overrides || {};
            Object.keys(rules.finance_metrics || {}).forEach(function (id) {
                const el = root.querySelector('[data-bm-fin-score="' + id + '"]');
                if (!el) return;
                if (el.value === '') {
                    delete next.finance.score_overrides[id];
                } else {
                    next.finance.score_overrides[id] = el.value;
                }
            });

            // business
            Object.keys(rules.business_metrics || {}).forEach(function (id) {
                const el = root.querySelector('[data-bm-biz="' + id + '"]');
                if (!el) return;
                if (!next.business[id]) next.business[id] = { value: null, source: 'manual', score_override: null };
                next.business[id].value = el.value === '' ? null : el.value;
                next.business[id].source = 'manual';
                const ov = root.querySelector('[data-bm-biz-score="' + id + '"]');
                if (ov) {
                    next.business[id].score_override = ov.value === '' ? null : ov.value;
                }
            });

            // judgment
            const comment = root.querySelector('[data-bm-judgment="comment"]');
            const reason = root.querySelector('[data-bm-judgment="upgrade_downgrade_reason"]');
            const force = root.querySelector('[data-bm-judgment="force_not_good"]');
            const negEq = root.querySelector('[data-bm-judgment="negative_equity"]');
            next.judgment.comment = comment ? comment.value : '';
            next.judgment.upgrade_downgrade_reason = reason ? reason.value : '';
            next.judgment.force_not_good = !!(force && force.checked);
            next.judgment.negative_equity = !!(negEq && negEq.checked);

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
            box.innerHTML = `
                <div class="bm-kpi">
                    <div class="label">Финансы</div>
                    <div class="value">${esc(fmtScore(r.finance_score))} <span class="sub">/ 50</span></div>
                </div>
                <div class="bm-kpi">
                    <div class="label">Бизнес-риск</div>
                    <div class="value">${esc(fmtScore(r.business_score))} <span class="sub">/ 50</span></div>
                </div>
                <div class="bm-kpi">
                    <div class="label">Итого</div>
                    <div class="value">${esc(fmtScore(r.total_score))} <span class="sub">/ 100</span></div>
                    <div class="sub">версия ${assessment ? assessment.version : '—'} · <span class="bm-status-chip ${esc(status)}">${esc(statusLabel)}</span></div>
                </div>
                <div class="bm-kpi">
                    <div class="label">Рейтинг</div>
                    <div class="value ${posClass}">${esc(ratingText)}</div>
                    <div class="sub ${posClass}">${esc(posText)}${r.hard_stop ? ' · стоп-фактор' : ''}</div>
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
                    return '<li><strong>' + esc(s.code) + '</strong> — ' + esc(s.label) + '</li>';
                }).join('');
                html += `<div class="bm-banner stop"><strong>Сработали обязательные стоп-факторы.</strong> Как правило — отказ от сделки.<ul class="mb-0 mt-2">${list}</ul></div>`;
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
                if (note) note.textContent = m.note || (m.value != null ? ('Значение: ' + fmtScore(m.value)) : '');
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
            const stopHtml = (rules.stop_factors || []).map(function (sf) {
                const row = (state.stop_factors || []).find(function (x) { return x.code === sf.code; }) || {};
                const on = !!row.triggered;
                const cond = !sf.mandatory;
                return `
                <div class="bm-stop-item ${on ? 'is-on' : ''} ${cond ? 'is-conditional' : ''}">
                    <div><input type="checkbox" data-bm-stop="${esc(sf.code)}" ${on ? 'checked' : ''}></div>
                    <div>
                        <label class="title">${esc(sf.label)}</label>
                        <div class="codes">${esc(sf.code)}${cond ? ' · условный' : ' · обязательный'}</div>
                        <textarea data-bm-stop-comment="${esc(sf.code)}" placeholder="Комментарий (обязателен при снятии/установке по решению менеджера)">${esc(row.comment || '')}</textarea>
                    </div>
                </div>`;
            }).join('');

            const inputs = state.finance.inputs || {};
            const locked = isLocked();
            const finInputsHtml = `
                <div class="bm-inputs">
                    <div><label>Выручка</label><input data-bm-fin="revenue" inputmode="decimal" value="${esc(numOrEmpty(inputs.revenue))}"></div>
                    <div><label>Чистая прибыль</label><input data-bm-fin="net_profit" inputmode="decimal" value="${esc(numOrEmpty(inputs.net_profit))}"></div>
                    <div><label>Собственные средства (СК)</label><input data-bm-fin="equity" inputmode="decimal" value="${esc(numOrEmpty(inputs.equity))}"></div>
                    <div><label>Текущие активы (стр.6)</label><input data-bm-fin="current_assets" inputmode="decimal" value="${esc(numOrEmpty(inputs.current_assets))}"></div>
                    <div><label>Текущие обязательства</label><input data-bm-fin="current_liabilities" inputmode="decimal" value="${esc(numOrEmpty(inputs.current_liabilities))}"></div>
                    <div><label>Долгосрочные обязательства</label><input data-bm-fin="long_term_liabilities" inputmode="decimal" value="${esc(numOrEmpty(inputs.long_term_liabilities))}"></div>
                    <div><label>Валюта баланса</label><input data-bm-fin="balance_total" inputmode="decimal" value="${esc(numOrEmpty(inputs.balance_total))}"></div>
                    <div><label>Краткосрочные займы (стр.14 / 1510)</label><input data-bm-fin="short_term_borrowings" inputmode="decimal" value="${esc(numOrEmpty(inputs.short_term_borrowings))}"></div>
                    <div><label>Кредиторская задолженность (стр.15 / 1520)</label><input data-bm-fin="accounts_payable" inputmode="decimal" value="${esc(numOrEmpty(inputs.accounts_payable))}"></div>
                    <div><label>Прочие кр. обязательства (стр.19 / 1540+1550)</label><input data-bm-fin="other_short_liabilities" inputmode="decimal" value="${esc(numOrEmpty(inputs.other_short_liabilities))}"></div>
                    <div><label>Долгосрочные займы (стр.24 / 1410)</label><input data-bm-fin="long_term_borrowings" inputmode="decimal" value="${esc(numOrEmpty(inputs.long_term_borrowings))}"></div>
                    <div><label>Долг / выручка (коэф., вручную)</label><input data-bm-fin="debt_to_revenue" inputmode="decimal" placeholder="или по формуле из стр.14–24" value="${esc(numOrEmpty(inputs.debt_to_revenue))}"></div>
                    <div><label>Отрасль</label>
                        <select data-bm-fin="industry">
                            <option value="default" ${inputs.industry === 'default' || !inputs.industry ? 'selected' : ''}>Обычная</option>
                            <option value="leasing" ${inputs.industry === 'leasing' ? 'selected' : ''}>Лизинг</option>
                            <option value="factoring" ${inputs.industry === 'factoring' ? 'selected' : ''}>Факторинг</option>
                        </select>
                    </div>
                    <div class="bm-checks" style="grid-column:1/-1">
                        <label><input type="checkbox" data-bm-fin-flag="no_revenue" ${inputs.no_revenue ? 'checked' : ''}> Нет выручки</label>
                        <label><input type="checkbox" data-bm-fin-flag="missing_equity" ${inputs.missing_equity ? 'checked' : ''}> Нет СК</label>
                        <label><input type="checkbox" data-bm-fin-flag="no_current_liabilities" ${inputs.no_current_liabilities ? 'checked' : ''}> Нет текущих обязательств</label>
                        <label><input type="checkbox" data-bm-fin-flag="profitability_explained_zero" ${inputs.profitability_explained_zero ? 'checked' : ''}> Рент. 0% с объяснением</label>
                        <label><input type="checkbox" data-bm-fin-flag="roe_explained_zero" ${inputs.roe_explained_zero ? 'checked' : ''}> ROE 0% с объяснением</label>
                        <label><input type="checkbox" data-bm-fin-flag="q1_seasonal_loss_explained" ${inputs.q1_seasonal_loss_explained ? 'checked' : ''}> Убыток 1 кв. (сезонность)</label>
                    </div>
                </div>`;

            const finMetrics = rules.finance_metrics || {};
            const overrides = (state.finance && state.finance.score_overrides) || {};
            const finMetricsHtml = Object.keys(finMetrics).map(function (id) {
                const m = finMetrics[id];
                const ov = overrides[id];
                return `
                <div class="bm-metric">
                    <div>
                        <div class="name">${esc(m.label)}</div>
                        <div class="hint" data-bm-fin-note="${esc(id)}"></div>
                    </div>
                    <div>
                        <label>Ручной балл (override)</label>
                        <input data-bm-fin-score="${esc(id)}" inputmode="decimal" placeholder="авто" value="${esc(numOrEmpty(ov))}">
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
                <div class="bm-metric">
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
                        <label>Ручной балл (override)</label>
                        <input data-bm-biz-score="${esc(id)}" inputmode="decimal" placeholder="авто" value="${esc(numOrEmpty(row.score_override))}">
                    </div>
                    <div>
                        <label>Балл</label>
                        <div><span class="score-pill" data-bm-biz-pill="${esc(id)}">—</span></div>
                    </div>
                </div>`;
            }).join('');

            const j = state.judgment || {};
            const hist = (history || []).slice(0, 8).map(function (h) {
                return `v${h.version} · ${h.status} · ${h.rating || '—'} · ${h.total_score != null ? h.total_score : '—'}`;
            }).join(' · ');

            const actionsHtml = locked
                ? `<button type="button" class="btn btn-primary btn-sm" data-bm-action="newdraft"><i class="bi bi-plus-lg me-1"></i>Новый черновик</button>`
                : `<button type="button" class="btn btn-outline-secondary btn-sm" data-bm-action="recalc"><i class="bi bi-arrow-repeat me-1"></i>Пересчитать</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-bm-action="save"><i class="bi bi-save me-1"></i>Сохранить черновик</button>
                        <button type="button" class="btn btn-primary btn-sm" data-bm-action="finalize"><i class="bi bi-check2-circle me-1"></i>Зафиксировать</button>
                        <button type="button" class="btn btn-outline-dark btn-sm" data-bm-action="newdraft"><i class="bi bi-plus-lg me-1"></i>Новый черновик</button>`;

            root.innerHTML = `
            <div class="bm-wrap${locked ? ' is-locked' : ''}">
                <div class="bm-hero">
                    <div>
                        <h4>Оценка по методике банка</h4>
                        <p>Отдельный контур от FinScore. Авторасчёт по шкалам методики + ручная корректировка параметров и баллов менеджером банка. Фиксация создаёт официальную версию по заявке.</p>
                    </div>
                    <div class="bm-actions">
                        ${actionsHtml}
                    </div>
                </div>
                <div data-bm-summary class="bm-summary"></div>
                <div data-bm-banners></div>

                <div class="bm-section" data-bm-section="stops">
                    <div class="bm-section-head"><h5>1. Стоп-факторы</h5><span class="meta">обязательные и условные</span></div>
                    <div class="bm-section-body"><div class="bm-stop-grid">${stopHtml}</div></div>
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
                    <div class="bm-section-head"><h5>4. Профсуждение</h5><span class="meta">комментарий менеджера</span></div>
                    <div class="bm-section-body bm-judgment">
                        <div class="mb-3">
                            <label class="form-label small text-muted fw-bold">Комментарий</label>
                            <textarea data-bm-judgment="comment" placeholder="Профессиональное суждение по оценке">${esc(j.comment || '')}</textarea>
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
                                throw new Error('Нельзя зафиксировать: заполните все показатели финансов и бизнес-риска (или укажите ручной балл).');
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
            setCaseId: function (id) {
                const next = Number(id || 0);
                if (next <= 0 || next === caseId) return;
                caseId = next;
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
