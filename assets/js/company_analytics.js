(function () {
    'use strict';

    let applicationId = null;
    let readOnly = false;
    let silentNotifications = false;
    let mode = 'application'; // application | inn
    let standaloneInn = '';

    function notify(message, type) {
        if (silentNotifications) {
            return;
        }
        if (typeof showNotification === 'function') {
            showNotification(message, type);
        }
    }

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    function formatMoneyRu(number) {
        const n = Number(number) || 0;
        return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }

    function formatNumberWeb(number) {
        const n = Number(number) || 0;
        if (!n) return '0';
        return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }

    function formatDateWeb(date) {
        if (!date) return '-';
        try {
            return new Date(date).toLocaleDateString('ru-RU');
        } catch (e) {
            return date;
        }
    }

    function metricNumber(value) {
        if (value === null || value === undefined) return 0;
        if (typeof value === 'number') return value;
        if (typeof value === 'object') {
            if (value.СумОтч !== undefined) return Number(value.СумОтч) || 0;
            if (value.value !== undefined) return Number(value.value) || 0;
        }
        return Number(value) || 0;
    }

    function seriesFromFinanceData(financeData) {
        if (!financeData || typeof financeData !== 'object') return [];
        return Object.keys(financeData)
            .map(function (k) { return parseInt(k, 10); })
            .filter(function (y) { return y >= 2015 && y <= 2100; })
            .sort(function (a, b) { return a - b; })
            .map(function (year) {
                const row = financeData[year] || financeData[String(year)] || {};
                const src = row.БухОтчет && typeof row.БухОтчет === 'object' ? row.БухОтчет : row;
                return {
                    year: year,
                    revenue: metricNumber(src['2110'] || src['Выручка']),
                    profit: metricNumber(src['2400'] || src['ЧистПриб']),
                    assets: metricNumber(src['1600'] || src['Актив']),
                    equity: metricNumber(src['1300'] || src['Капитал']),
                };
            });
    }

    function renderFinScoreHeader(finscore) {
        if (!finscore || typeof finscore !== 'object') {
            return '';
        }

        const score = Math.max(0, Math.min(100, Number(finscore.score) || 0));
        const grade = finscore.grade || '—';
        const gradeLabel = finscore.grade_label || '';
        const color = finscore.grade_color || '#2f6fed';
        const companyName = finscore.company_name || 'Компания';
        const bg = (finscore.limits && finscore.limits.bg) || { value: 0, low: 0, high: 0 };
        const credit = (finscore.limits && finscore.limits.credit) || { value: 0, low: 0, high: 0 };
        const individual = !!finscore.individual_only || !(bg.value > 0);
        const factors = Array.isArray(finscore.factors) ? finscore.factors : [];
        const hardStops = Array.isArray(finscore.hard_stops) ? finscore.hard_stops : [];
        const series = finscore.finance && Array.isArray(finscore.finance.series) ? finscore.finance.series : [];
        const confidence = (finscore.confidence && finscore.confidence.label) ? finscore.confidence.label : '';
        const metrics = finscore.metrics || {};
        const finance = finscore.finance || {};

        let factorsHtml = '';
        factors.forEach(function (f) {
            const tone = f.tone || 'warn';
            factorsHtml += `
                <button type="button" class="fs-factor" data-fs-factor>
                    <span class="fs-tone ${tone}"></span>
                    <strong>${escapeHtml(f.label || '')}</strong>
                    <div class="fs-factor-detail">${escapeHtml(f.detail || '')}</div>
                </button>`;
        });

        const limitBlock = individual
            ? `<div class="fs-limit">Индивидуально</div>
               <div class="fs-limit-sub">Автолимит недоступен — нужен ручной разбор</div>`
            : `<div class="fs-limit">${formatMoneyRu(bg.value)} ₽</div>
               <div class="fs-limit-sub">Ориентир БГ · диапазон ${formatMoneyRu(bg.low)} – ${formatMoneyRu(bg.high)} ₽</div>`;

        const chartCanvas = series.length > 1
            ? `<div class="fs-chart-wrap"><canvas id="finscore-finance-chart" height="120"></canvas></div>`
            : '';

        let hardHtml = '';
        if (hardStops.length) {
            hardHtml = `<div class="fs-hardstops"><strong>Стоп-факторы:</strong><ul>`
                + hardStops.map(function (s) { return `<li>${escapeHtml(s)}</li>`; }).join('')
                + `</ul></div>`;
        }

        return `
        <div class="fs-header mb-4" style="--fs-score:${score};--fs-color:${color};">
            <div class="fs-header-main">
                <div class="fs-ring" aria-hidden="true">
                    <div class="fs-ring-inner">
                        <div class="fs-grade">${escapeHtml(String(grade))}</div>
                        <div class="fs-score">${score}/100</div>
                    </div>
                </div>
                <div class="fs-meta">
                    <h5 class="mb-1">${escapeHtml(companyName)}</h5>
                    <div class="text-muted mb-2">
                        FinScore · ${escapeHtml(gradeLabel)}
                        ${confidence ? ` · уверенность: ${escapeHtml(confidence)}` : ''}
                    </div>
                    ${limitBlock}
                    <div class="fs-secondary-limit">
                        Кредит: ${individual || !(credit.value > 0)
                            ? 'индивидуально'
                            : formatMoneyRu(credit.value) + ' ₽ (' + formatMoneyRu(credit.low) + ' – ' + formatMoneyRu(credit.high) + ')'}
                    </div>
                    <div class="text-muted small mt-1">${escapeHtml(finscore.recommendation || '')}</div>
                </div>
            </div>

            <div class="fs-kpis">
                <div class="fs-kpi">
                    <div class="label">Выручка${finance.year ? ' ' + finance.year : ''}</div>
                    <div class="value">${formatMoneyRu(finance.revenue || 0)} ₽</div>
                </div>
                <div class="fs-kpi">
                    <div class="label">Прибыль</div>
                    <div class="value">${formatMoneyRu(finance.profit || 0)} ₽</div>
                </div>
                <div class="fs-kpi">
                    <div class="label">Возраст</div>
                    <div class="value">${metrics.company_age != null ? metrics.company_age + ' лет' : '—'}</div>
                </div>
                <div class="fs-kpi">
                    <div class="label">ФССП</div>
                    <div class="value">${formatMoneyRu(metrics.fssp_debt || 0)} ₽</div>
                </div>
                <div class="fs-kpi">
                    <div class="label">Арбитраж</div>
                    <div class="value">${Number(metrics.law_count || 0)} дел</div>
                </div>
            </div>

            ${hardHtml}
            ${chartCanvas}
            ${factorsHtml ? `<div class="fs-factors-title">Ключевые факторы <span>(нажмите для деталей)</span></div><div class="fs-factors">${factorsHtml}</div>` : ''}
        </div>`;
    }

    function mountFinScoreInteractions(finscore) {
        document.querySelectorAll('[data-fs-factor]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const active = btn.classList.contains('is-active');
                document.querySelectorAll('[data-fs-factor]').forEach(function (el) {
                    el.classList.remove('is-active');
                });
                if (!active) {
                    btn.classList.add('is-active');
                }
            });
        });

        const series = finscore && finscore.finance && Array.isArray(finscore.finance.series)
            ? finscore.finance.series.slice(-6)
            : [];
        const canvas = document.getElementById('finscore-finance-chart');
        if (!canvas || series.length < 2 || typeof Chart === 'undefined') {
            return;
        }

        const labels = series.map(function (r) { return String(r.year); });
        const revenue = series.map(function (r) { return Number(r.revenue) || 0; });
        const profit = series.map(function (r) { return Number(r.profit) || 0; });

        if (canvas._fsChart) {
            canvas._fsChart.destroy();
        }
        canvas._fsChart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Выручка',
                        data: revenue,
                        borderColor: '#2f6fed',
                        backgroundColor: 'rgba(47,111,237,0.12)',
                        tension: 0.25,
                        fill: true,
                    },
                    {
                        label: 'Прибыль',
                        data: profit,
                        borderColor: '#1a7f4b',
                        backgroundColor: 'rgba(26,127,75,0.08)',
                        tension: 0.25,
                        fill: false,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 450 },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.label + ': ' + formatMoneyRu(ctx.raw) + ' ₽';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: function (v) {
                                if (Math.abs(v) >= 1e9) return (v / 1e9).toFixed(1) + ' млрд';
                                if (Math.abs(v) >= 1e6) return (v / 1e6).toFixed(1) + ' млн';
                                if (Math.abs(v) >= 1e3) return (v / 1e3).toFixed(0) + ' тыс';
                                return v;
                            }
                        }
                    }
                }
            }
        });
    }

    function getStatusCircle(value, type) {
        type = type || 'default';
        if (value === null || value === undefined || value === '' || value === '-') {
            return '<span class="status-circle gray" title="Неизвестно"></span>';
        }

        if (type === 'boolean_negative') {
            let boolValue;
            if (typeof value === 'boolean') {
                boolValue = value;
            } else if (typeof value === 'string') {
                const lowerVal = value.toLowerCase().trim();
                if (lowerVal === 'true' || lowerVal === 'да' || lowerVal === 'yes' || lowerVal === '1') {
                    boolValue = true;
                } else if (lowerVal === 'false' || lowerVal === 'нет' || lowerVal === 'no' || lowerVal === '0' || lowerVal === '') {
                    boolValue = false;
                } else {
                    boolValue = true;
                }
            } else if (typeof value === 'number') {
                boolValue = value !== 0;
            } else {
                boolValue = !!value;
            }
            return boolValue
                ? '<span class="status-circle red" title="Проблема"></span>'
                : '<span class="status-circle green" title="Норма"></span>';
        }

        if (type === 'boolean_positive') {
            if (typeof value === 'string') {
                return value.trim() !== ''
                    ? '<span class="status-circle green" title="Норма"></span>'
                    : '<span class="status-circle gray" title="Неизвестно"></span>';
            }
            return value
                ? '<span class="status-circle green" title="Норма"></span>'
                : '<span class="status-circle red" title="Проблема"></span>';
        }

        const numValue = typeof value === 'number' ? value : parseFloat(value) || 0;

        if (type === 'tax_debt') {
            return numValue > 0
                ? '<span class="status-circle yellow" title="Задолженность"></span>'
                : '<span class="status-circle green" title="Нет задолженности"></span>';
        }
        if (type === 'enforcement_debt') {
            return numValue > 0
                ? '<span class="status-circle red" title="Задолженность"></span>'
                : '<span class="status-circle green" title="Нет задолженности"></span>';
        }
        if (type === 'numeric_positive') {
            return numValue > 0
                ? '<span class="status-circle green" title="Норма"></span>'
                : '<span class="status-circle yellow" title="Нет данных/нулевые"></span>';
        }
        if (type === 'numeric_negative') {
            return numValue > 0
                ? '<span class="status-circle yellow" title="Требует внимания"></span>'
                : '<span class="status-circle green" title="Норма"></span>';
        }
        if (type === 'company_age') {
            return numValue < 1
                ? '<span class="status-circle yellow" title="Молодая компания"></span>'
                : '<span class="status-circle green" title="Норма"></span>';
        }

        return '<span class="status-circle green" title="Норма"></span>';
    }

    function renderFinanceBlock(data, finscore) {
        let series = finscore && finscore.finance && Array.isArray(finscore.finance.series)
            ? finscore.finance.series
            : seriesFromFinanceData(data && data.finance ? data.finance.data : null);

        series = series.slice(-5);
        if (!series.length) {
            return `
            <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle me-2"></i>Финансовая отчетность не найдена
            </div>`;
        }

        let rows = '';
        series.slice().reverse().forEach(function (row) {
            rows += `
                <tr>
                    <td>${row.year}</td>
                    <td>${getStatusCircle(row.revenue, 'numeric_positive')} ${formatNumberWeb(row.revenue)} ₽</td>
                    <td>${getStatusCircle(row.profit, 'numeric_positive')} ${formatNumberWeb(row.profit)} ₽</td>
                    <td>${formatNumberWeb(row.equity)} ₽</td>
                    <td>${formatNumberWeb(row.assets)} ₽</td>
                </tr>`;
        });

        return `
            <div class="table-responsive">
                <table class="table table-sm analytics-finance-table mb-0">
                    <thead>
                        <tr>
                            <th>Год</th>
                            <th>Выручка</th>
                            <th>Прибыль</th>
                            <th>Капитал</th>
                            <th>Активы</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;
    }

    function renderAnalytics(data, finscore) {
        const container = document.getElementById('analytics-content');
        let html = renderFinScoreHeader(finscore);

        if (data && data.company && data.company.data) {
            const d = data.company.data;
            const now = new Date();
            const regDate = d['ДатаРег'] ? new Date(d['ДатаРег']) : null;
            const interval = regDate ? now.getFullYear() - regDate.getFullYear() : 0;
            const taxDebt = parseFloat((d['Налоги'] && d['Налоги']['СумНедоим']) || 0);

            html += `
        <div class="analytics-card mb-4">
            <div class="analytics-card-header">
                <h5 class="card-title mb-0"><i class="bi bi-building me-2"></i>Основная информация</h5>
            </div>
            <div class="analytics-card-body">
                <div class="analytics-section">
                    <h6>Общие сведения</h6>
                    <div class="table-responsive">
                        <table class="table table-sm analytics-table">
                            <tbody>
                                <tr>
                                    <td width="40">${getStatusCircle(d['НаимПолн'], 'boolean_positive')}</td>
                                    <td><strong>Полное наименование:</strong></td>
                                    <td>${escapeHtml(d['НаимПолн'] || '-')}</td>
                                </tr>
                                <tr>
                                    <td>${getStatusCircle(interval, 'company_age')}</td>
                                    <td><strong>Дата регистрации:</strong></td>
                                    <td>${formatDateWeb(d['ДатаРег'] || '')} (${interval} лет)</td>
                                </tr>
                                <tr>
                                    <td>${getStatusCircle(d['Регион'] && d['Регион']['Наим'], 'boolean_positive')}</td>
                                    <td><strong>Регион:</strong></td>
                                    <td>${escapeHtml((d['Регион'] && d['Регион']['Наим']) || '-')}</td>
                                </tr>
                                <tr>
                                    <td>${getStatusCircle(d['ЮрАдрес'] && d['ЮрАдрес']['Недост'], 'boolean_negative')}</td>
                                    <td><strong>Юридический адрес недостоверен:</strong></td>
                                    <td>${d['ЮрАдрес'] && d['ЮрАдрес']['Недост']
                                        ? 'Да (' + escapeHtml(d['ЮрАдрес']['НедостОпис'] || '') + ')'
                                        : 'Нет'}</td>
                                </tr>
                                <tr>
                                    <td>${getStatusCircle(taxDebt, 'tax_debt')}</td>
                                    <td><strong>Задолженность по налогам:</strong></td>
                                    <td>${formatNumberWeb(taxDebt)} руб. (на ${(d['Налоги'] && d['Налоги']['НедоимДата']) || '-'})</td>
                                </tr>
                                <tr>
                                    <td>${getStatusCircle(d['РМСП'] && d['РМСП']['Кат'], 'boolean_positive')}</td>
                                    <td><strong>Субъект МСП:</strong></td>
                                    <td>${escapeHtml((d['РМСП'] && d['РМСП']['Кат']) || '-')}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="analytics-section mb-0">
                    <h6>Риски и нарушения</h6>
                    <div class="table-responsive">
                        <table class="table table-sm analytics-table">
                            <tbody>
                                <tr>
                                    <td width="40">${getStatusCircle(!!(d['ПоддержМСП'] && d['ПоддержМСП'][0] && d['ПоддержМСП'][0]['Наруш']), 'boolean_negative')}</td>
                                    <td><strong>Нарушения требований МСП:</strong></td>
                                    <td>${(d['ПоддержМСП'] && d['ПоддержМСП'][0] && d['ПоддержМСП'][0]['Наруш']) ? 'Да' : 'Нет'}</td>
                                </tr>
                                <tr>
                                    <td>${getStatusCircle(d['НедобПост'], 'boolean_negative')}</td>
                                    <td><strong>В реестре недобросовестных поставщиков:</strong></td>
                                    <td>${d['НедобПост'] ? 'Да' : 'Нет'}</td>
                                </tr>
                                <tr>
                                    <td>${getStatusCircle(d['ДисквЛица'], 'boolean_negative')}</td>
                                    <td><strong>Есть дисквалифицированные лица:</strong></td>
                                    <td>${d['ДисквЛица'] ? 'Да' : 'Нет'}</td>
                                </tr>
                                <tr>
                                    <td>${getStatusCircle(d['МассРуковод'], 'boolean_negative')}</td>
                                    <td><strong>Массовые руководители:</strong></td>
                                    <td>${d['МассРуковод'] ? 'Да' : 'Нет'}</td>
                                </tr>
                                <tr>
                                    <td>${getStatusCircle(d['МассУчред'], 'boolean_negative')}</td>
                                    <td><strong>Массовые учредители:</strong></td>
                                    <td>${d['МассУчред'] ? 'Да' : 'Нет'}</td>
                                </tr>
                                <tr>
                                    <td>${getStatusCircle(d['НелегалФин'], 'boolean_negative')}</td>
                                    <td><strong>Финансовая нелегалка:</strong></td>
                                    <td>${d['НелегалФин'] ? 'Да (' + escapeHtml(d['НелегалФинСтатус'] || '') + ')' : 'Нет'}</td>
                                </tr>
                                <tr>
                                    <td>${getStatusCircle(d['Санкции'], 'boolean_negative')}</td>
                                    <td><strong>Санкции:</strong></td>
                                    <td>${d['Санкции'] ? 'Да' : 'Нет'}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>`;
        } else {
            html += `
        <div class="card mb-4">
            <div class="card-body">
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>Данные о компании не найдены
                </div>
            </div>
        </div>`;
        }

        html += `
    <div class="analytics-card mb-4">
        <div class="analytics-card-header">
            <h5 class="card-title mb-0"><i class="bi bi-graph-up me-2"></i>Финансовые показатели</h5>
        </div>
        <div class="analytics-card-body">
            ${renderFinanceBlock(data, finscore)}
        </div>
    </div>`;

        html += `
    <div class="analytics-card mb-4">
        <div class="analytics-card-header">
            <h5 class="card-title mb-0"><i class="bi bi-shield-exclamation me-2"></i>Исполнительные производства</h5>
        </div>
        <div class="analytics-card-body">`;

        if (data && data.enforcements && data.enforcements.data) {
            const e = data.enforcements.data;
            const debt = parseInt(e['ОстЗадолж'] || e['ОбщСум'] || 0, 10);
            const count = parseInt(e['КолвоИП'] || e['ОбщКолич'] || 0, 10);
            html += `
            <table class="table table-sm analytics-table mb-0">
                <tbody>
                    <tr>
                        <td width="40">${getStatusCircle(debt, 'enforcement_debt')}</td>
                        <td><strong>Остаток задолженности:</strong></td>
                        <td>${formatNumberWeb(debt)} руб.</td>
                    </tr>
                    <tr>
                        <td>${getStatusCircle(count, 'numeric_negative')}</td>
                        <td><strong>Количество ИП:</strong></td>
                        <td>${count}</td>
                    </tr>
                </tbody>
            </table>`;
        } else {
            html += `
            <div class="alert alert-success mb-0">
                <i class="bi bi-check-circle me-2"></i>Исполнительные производства не найдены
            </div>`;
        }
        html += `</div></div>`;

        html += `
    <div class="analytics-card mb-4">
        <div class="analytics-card-header">
            <h5 class="card-title mb-0"><i class="bi bi-journal-text me-2"></i>Арбитражные дела</h5>
        </div>
        <div class="analytics-card-body">`;

        if (data && data.lawsuits && data.lawsuits.data) {
            const l = data.lawsuits.data;
            const count = parseInt(l['ЗапВсего'] || 0, 10);
            const claimSum = parseInt(l['ОбщСуммИск'] || l['СуммИск'] || 0, 10);
            html += `
            <table class="table table-sm analytics-table mb-0">
                <tbody>
                    <tr>
                        <td width="40">${getStatusCircle(count, 'numeric_negative')}</td>
                        <td><strong>Общее количество дел:</strong></td>
                        <td>${count}</td>
                    </tr>
                    <tr>
                        <td>${getStatusCircle(claimSum, 'numeric_negative')}</td>
                        <td><strong>Сумма исковых требований:</strong></td>
                        <td>${formatNumberWeb(claimSum)} руб.</td>
                    </tr>
                </tbody>
            </table>`;
        } else {
            html += `
            <div class="alert alert-success mb-0">
                <i class="bi bi-check-circle me-2"></i>Арбитражные дела не найдены
            </div>`;
        }
        html += `</div></div>`;

        if (container) {
            container.innerHTML = html;
            mountFinScoreInteractions(finscore);
        }
    }

    function showAnalyticsContent() {
        const loading = document.getElementById('analytics-loading');
        const empty = document.getElementById('analytics-empty');
        const content = document.getElementById('analytics-content');
        const error = document.getElementById('analytics-error');
        if (loading) loading.style.display = 'none';
        if (empty) empty.style.display = 'none';
        if (error) error.style.display = 'none';
        if (content) content.style.display = 'block';
    }

    function showAnalyticsEmpty() {
        const loading = document.getElementById('analytics-loading');
        const empty = document.getElementById('analytics-empty');
        const content = document.getElementById('analytics-content');
        const error = document.getElementById('analytics-error');
        if (loading) loading.style.display = 'none';
        if (content) content.style.display = 'none';
        if (error) error.style.display = 'none';
        if (empty) empty.style.display = 'block';
    }

    function updateLastUpdated(timestamp) {
        const lastUpdateEl = document.getElementById('last-update');
        if (!lastUpdateEl) return;
        if (timestamp) {
            lastUpdateEl.innerHTML = `
            <i class="bi bi-clock me-1"></i>
            <span>Обновлено: ${timestamp.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}</span>`;
        } else {
            lastUpdateEl.innerHTML = `
            <i class="bi bi-clock me-1"></i>
            <span>Данные не загружены</span>`;
        }
    }

    function handleAnalyticsError(error) {
        const loading = document.getElementById('analytics-loading');
        const errBox = document.getElementById('analytics-error');
        const errorMessageEl = document.getElementById('error-message');
        if (loading) loading.style.display = 'none';
        if (errBox) errBox.style.display = 'block';
        if (errorMessageEl) errorMessageEl.textContent = error.message || String(error);
        notify('Ошибка при загрузке аналитики: ' + (error.message || error), 'danger');
    }

    function processAnalyticsData(data, isNew, timestamp, finscore) {
        const companyNameEl = document.getElementById('company-name');
        const companyInnEl = document.getElementById('company-inn');
        if (companyNameEl) {
            if (finscore && finscore.company_name) {
                companyNameEl.textContent = finscore.company_name;
            } else if (data && data.company && data.company.data && data.company.data.НаимСокр) {
                companyNameEl.textContent = data.company.data.НаимСокр;
            } else if (data && data.company && data.company.data && data.company.data.НаимПолн) {
                companyNameEl.textContent = data.company.data.НаимПолн;
            }
        }
        if (companyInnEl && finscore && finscore.inn) {
            companyInnEl.textContent = finscore.inn;
        } else if (companyInnEl && standaloneInn) {
            companyInnEl.textContent = standaloneInn;
        }

        if (isNew) {
            updateLastUpdated(new Date());
            const analyticsBadge = document.getElementById('analytics-badge');
            if (analyticsBadge) {
                analyticsBadge.style.display = 'inline';
                setTimeout(function () {
                    analyticsBadge.style.display = 'none';
                }, 3000);
            }
        } else if (timestamp) {
            updateLastUpdated(new Date(timestamp));
        }

        renderAnalytics(data, finscore);
        showAnalyticsContent();
    }

    function beginLoading(buttonLabel) {
        const loading = document.getElementById('analytics-loading');
        const empty = document.getElementById('analytics-empty');
        const content = document.getElementById('analytics-content');
        const error = document.getElementById('analytics-error');
        if (loading) loading.style.display = 'block';
        if (empty) empty.style.display = 'none';
        if (content) content.style.display = 'none';
        if (error) error.style.display = 'none';

        const loadBtn = document.getElementById('load-analytics-btn');
        const standaloneBtn = document.getElementById('standalone-analytics-submit');
        const buttons = [loadBtn, standaloneBtn].filter(Boolean);
        buttons.forEach(function (btn) {
            btn.dataset.originalHtml = btn.innerHTML;
            btn.innerHTML = buttonLabel || '<i class="bi bi-hourglass-split me-2"></i>Загрузка...';
            btn.disabled = true;
        });
        return function restore() {
            buttons.forEach(function (btn) {
                if (btn.dataset.originalHtml) {
                    btn.innerHTML = btn.dataset.originalHtml;
                }
                btn.disabled = false;
            });
        };
    }

    function loadAnalyticsByInn(inn) {
        inn = String(inn || '').replace(/\D+/g, '');
        if (inn.length !== 10 && inn.length !== 12) {
            handleAnalyticsError(new Error('Укажите корректный ИНН (10 или 12 цифр)'));
            return;
        }
        standaloneInn = inn;
        const restore = beginLoading('<i class="bi bi-hourglass-split me-2"></i>Считаем FinScore...');

        fetch('api_company_intelligence.php?inn=' + encodeURIComponent(inn) + '&product_type=bg&log=1')
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.json();
            })
            .then(function (payload) {
                if (!payload.success) {
                    throw new Error(payload.error || 'Неизвестная ошибка');
                }
                processAnalyticsData(payload.data, true, null, payload.finscore || null);
                notify('Аналитика загружена', 'success');
            })
            .catch(handleAnalyticsError)
            .finally(restore);
    }

    function loadAnalytics(forceReload) {
        if (mode === 'inn') {
            const innInput = document.getElementById('standalone-inn');
            const inn = (innInput && innInput.value) || standaloneInn;
            loadAnalyticsByInn(inn);
            return;
        }

        if (typeof forceReload === 'undefined') {
            forceReload = false;
        }
        if (readOnly && forceReload) {
            return;
        }
        if (!applicationId) {
            showAnalyticsEmpty();
            return;
        }

        const restore = beginLoading(
            forceReload
                ? '<i class="bi bi-hourglass-split me-2"></i>Загрузка новых данных...'
                : '<i class="bi bi-hourglass-split me-2"></i>Загрузка из кэша...'
        );

        if (forceReload) {
            fetch('api_get_company_analytics.php?application_id=' + applicationId)
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP error! status: ' + response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (!data.success) {
                        throw new Error(data.error || 'Неизвестная ошибка');
                    }
                    if (!readOnly) {
                        return fetch('save_analytics.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                application_id: applicationId,
                                analytics_data: data.data,
                                finscore: data.finscore || null
                            })
                        }).then(function () { return data; });
                    }
                    return data;
                })
                .then(function (data) {
                    processAnalyticsData(data.data, true, null, data.finscore || null);
                    notify('Аналитика успешно обновлена и сохранена!', 'success');
                })
                .catch(handleAnalyticsError)
                .finally(restore);
            return;
        }

        fetch('load_analytics_cache.php?application_id=' + applicationId)
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    processAnalyticsData(data.data, false, data.timestamp, data.finscore || null);
                    notify('Аналитика загружена из кэша', 'success');
                } else {
                    showAnalyticsEmpty();
                }
            })
            .catch(handleAnalyticsError)
            .finally(restore);
    }

    function initCompanyAnalytics(options) {
        options = options || {};
        mode = options.mode === 'inn' ? 'inn' : 'application';
        applicationId = options.applicationId || null;
        readOnly = !!options.readOnly;
        silentNotifications = !!options.readOnly || !!options.silentNotifications;

        if (mode === 'inn') {
            const form = document.getElementById(options.formId || 'standalone-analytics-form');
            const innInput = document.getElementById(options.innInputId || 'standalone-inn');
            if (innInput) {
                innInput.addEventListener('input', function () {
                    this.value = this.value.replace(/[^\d]/g, '');
                });
            }
            if (form) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const inn = innInput ? innInput.value : '';
                    loadAnalyticsByInn(inn);
                });
            }
            if (options.autoLoadInn) {
                loadAnalyticsByInn(options.autoLoadInn);
            }
            return;
        }

        let tabId = options.tabId;
        if (!tabId) {
            tabId = document.getElementById('bank-analytics-tab') ? 'bank-analytics-tab' : 'analytics-tab';
        }

        let paneId = options.paneId;
        if (!paneId) {
            if (tabId === 'bank-analytics-tab') {
                paneId = 'bankAppAnalytics';
            } else if (tabId === 'analytics-tab') {
                paneId = 'analytics';
            }
        }

        const analyticsTab = document.getElementById(tabId);
        if (analyticsTab) {
            analyticsTab.addEventListener('shown.bs.tab', function () {
                loadAnalytics(false);
            });
        }

        if (paneId) {
            const analyticsPane = document.getElementById(paneId);
            if (analyticsPane && analyticsPane.classList.contains('active')) {
                loadAnalytics(false);
            }
        }
    }

    window.initCompanyAnalytics = initCompanyAnalytics;
    window.loadAnalytics = loadAnalytics;
    window.showAnalyticsEmpty = showAnalyticsEmpty;
})();
