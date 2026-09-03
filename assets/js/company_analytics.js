(function () {
    'use strict';

    let applicationId = null;
    let readOnly = false;
    let silentNotifications = false;

    function notify(message, type) {
        if (silentNotifications) {
            return;
        }
        if (typeof showNotification === 'function') {
            showNotification(message, type);
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatMoneyRu(number) {
        const n = Number(number) || 0;
        return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
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
        const limits = finscore.limits && finscore.limits.bg ? finscore.limits.bg : { value: 0, low: 0, high: 0 };
        const individual = !!finscore.individual_only || !(limits.value > 0);
        const factors = Array.isArray(finscore.factors) ? finscore.factors : [];
        const series = finscore.finance && Array.isArray(finscore.finance.series) ? finscore.finance.series : [];

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
               <div class="fs-limit-sub">Автолимит недоступен</div>`
            : `<div class="fs-limit">${formatMoneyRu(limits.value)} ₽</div>
               <div class="fs-limit-sub">Диапазон ${formatMoneyRu(limits.low)} – ${formatMoneyRu(limits.high)} ₽</div>`;

        const chartCanvas = series.length > 1
            ? `<div class="fs-chart-wrap"><canvas id="finscore-finance-chart" height="120"></canvas></div>`
            : '';

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
                    <div class="text-muted mb-2">FinScore · ${escapeHtml(gradeLabel)}</div>
                    ${limitBlock}
                    <div class="text-muted small mt-1">${escapeHtml(finscore.recommendation || '')}</div>
                </div>
            </div>
            ${chartCanvas}
            ${factorsHtml ? `<div class="fs-factors">${factorsHtml}</div>` : ''}
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
            ? finscore.finance.series
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

    function renderAnalytics(data, finscore) {
        const container = document.getElementById('analytics-content');

        function getStatusCircle(value, type = 'default') {
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

                return boolValue ?
                    '<span class="status-circle red" title="Проблема"></span>' :
                    '<span class="status-circle green" title="Норма"></span>';
            }

            if (type === 'boolean_positive') {
                if (typeof value === 'string') {
                    return value.trim() !== '' ?
                        '<span class="status-circle green" title="Норма"></span>' :
                        '<span class="status-circle gray" title="Неизвестно"></span>';
                }

                let boolValue = !!value;
                return boolValue ?
                    '<span class="status-circle green" title="Норма"></span>' :
                    '<span class="status-circle red" title="Проблема"></span>';
            }

            const numValue = typeof value === 'number' ? value : parseFloat(value) || 0;

            if (type === 'tax_debt') {
                return numValue > 0 ?
                    '<span class="status-circle yellow" title="Задолженность"></span>' :
                    '<span class="status-circle green" title="Нет задолженности"></span>';
            }

            if (type === 'enforcement_debt') {
                return numValue > 0 ?
                    '<span class="status-circle red" title="Задолженность"></span>' :
                    '<span class="status-circle green" title="Нет задолженности"></span>';
            }

            if (type === 'numeric_positive') {
                return numValue > 0 ?
                    '<span class="status-circle green" title="Норма"></span>' :
                    '<span class="status-circle yellow" title="Нет данных/нулевые"></span>';
            }

            if (type === 'numeric_negative') {
                return numValue > 0 ?
                    '<span class="status-circle yellow" title="Требует внимания"></span>' :
                    '<span class="status-circle green" title="Норма"></span>';
            }

            if (type === 'company_age') {
                return numValue < 1 ?
                    '<span class="status-circle yellow" title="Молодая компания"></span>' :
                    '<span class="status-circle green" title="Норма"></span>';
            }

            return '<span class="status-circle green" title="Норма"></span>';
        }

        function formatNumberWeb(number) {
            if (!number || number == 0) return '0,00';
            return number.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ').replace('.', ',');
        }

        function formatDateWeb(date) {
            if (!date) return '-';
            try {
                const d = new Date(date);
                return d.toLocaleDateString('ru-RU');
            } catch (e) {
                return date;
            }
        }

        let html = renderFinScoreHeader(finscore);

        if (data.company && data.company.data) {
            const d = data.company.data;
            const now = new Date();
            const regDate = d['ДатаРег'] ? new Date(d['ДатаРег']) : null;
            const interval = regDate ? now.getFullYear() - regDate.getFullYear() : 0;
            const taxDebt = parseFloat(d['Налоги']?.['СумНедоим'] || 0);

            html += `
        <div class="analytics-card mb-4">
            <div class="analytics-card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-building me-2"></i>Основная информация
                   
                </h5>
            </div>
            <div class="analytics-card-body">
                <div class="analytics-section">
                    <h6>Общие сведения</h6>
                    <div class="table-responsive">
                        <table class="table table-sm analytics-table">
                            <tbody>
                                <tr>
                                    <td width="40" style="border: none;">${getStatusCircle(d['НаимПолн'], 'boolean_positive')}</td>
                                    <td style="border: none;"><strong>Полное наименование:</strong></td>
                                    <td style="border: none;">${escapeHtml(d['НаимПолн'] || '-')}</td>
                                </tr>
                                <tr>
                                    <td style="border: none;">${getStatusCircle(interval, 'company_age')}</td>
                                    <td style="border: none;"><strong>Дата регистрации:</strong></td>
                                    <td style="border: none;">${formatDateWeb(d['ДатаРег'] || '')} (${interval} лет)</td>
                                </tr>
                                <tr>
                                    <td style="border: none;">${getStatusCircle(d['Регион']?.['Наим'], 'boolean_positive')}</td>
                                    <td style="border: none;"><strong>Регион:</strong></td>
                                    <td style="border: none;">${escapeHtml(d['Регион']?.['Наим'] || '-')}</td>
                                </tr>
                                <tr>
                                    <td style="border: none;">${getStatusCircle(d['ЮрАдрес']?.['Недост'], 'boolean_negative')}</td>
                                    <td style="border: none;"><strong>Юридический адрес недостоверен:</strong></td>
                                    <td style="border: none;">
                                        ${d['ЮрАдрес']?.['Недост'] ? 'Да (' + escapeHtml(d['ЮрАдрес']?.['НедостОпис'] || '') + ')' : 'Нет'}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="border: none;">${getStatusCircle(taxDebt, 'tax_debt')}</td>
                                    <td style="border: none;"><strong>Задолженность по налогам:</strong></td>
                                    <td style="border: none;">${formatNumberWeb(taxDebt)} руб. (на ${d['Налоги']?.['НедоимДата'] || '-'})</td>
                                </tr>
                                <tr>
                                    <td style="border: none;">${getStatusCircle(d['РМСП']?.['Кат'], 'boolean_positive')}</td>
                                    <td style="border: none;"><strong>Субъект МСП:</strong></td>
                                    <td style="border: none;">${escapeHtml(d['РМСП']?.['Кат'] || '-')}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="analytics-section">
                    <h6>Риски и нарушения</h6>
                    <div class="table-responsive">
                        <table class="table table-sm analytics-table">
                            <tbody>
                               <tr>
    <td width="40" style="border: none;">${getStatusCircle(!!d['ПоддержМСП']?.[0]?.['Наруш'], 'boolean_negative')}</td>
    <td style="border: none;"><strong>Нарушения требований МСП:</strong></td>
    <td style="border: none;">${d['ПоддержМСП']?.[0]?.['Наруш'] ? 'Да' : 'Нет'}</td>
</tr>
                                <tr>
                                    <td style="border: none;">${getStatusCircle(d['НедобПост'], 'boolean_negative')}</td>
                                    <td style="border: none;"><strong>В реестре недобросовестных поставщиков:</strong></td>
                                    <td style="border: none;">${d['НедобПост'] ? 'Да' : 'Нет'}</td>
                                </tr>
                                <tr>
                                    <td style="border: none;">${getStatusCircle(d['ДисквЛица'], 'boolean_negative')}</td>
                                    <td style="border: none;"><strong>Есть дисквалифицированные лица:</strong></td>
                                    <td style="border: none;">${d['ДисквЛица'] ? 'Да' : 'Нет'}</td>
                                </tr>
                                <tr>
                                    <td style="border: none;">${getStatusCircle(d['МассРуковод'], 'boolean_negative')}</td>
                                    <td style="border: none;"><strong>Массовые руководители:</strong></td>
                                    <td style="border: none;">${d['МассРуковод'] ? 'Да' : 'Нет'}</td>
                                </tr>
                                <tr>
                                    <td style="border: none;">${getStatusCircle(d['МассУчред'], 'boolean_negative')}</td>
                                    <td style="border: none;"><strong>Массовые учредители:</strong></td>
                                    <td style="border: none;">${d['МассУчред'] ? 'Да' : 'Нет'}</td>
                                </tr>
                                <tr>
                                    <td style="border: none;">${getStatusCircle(d['НелегалФин'], 'boolean_negative')}</td>
                                    <td style="border: none;"><strong>Финансовая нелегалка:</strong></td>
                                    <td style="border: none;">${d['НелегалФин'] ? 'Да (' + escapeHtml(d['НелегалФинСтатус'] || '') + ')' : 'Нет'}</td>
                                </tr>
                                <tr>
                                    <td style="border: none;">${getStatusCircle(d['Санкции'], 'boolean_negative')}</td>
                                    <td style="border: none;"><strong>Санкции:</strong></td>
                                    <td style="border: none;">${d['Санкции'] ? 'Да' : 'Нет'}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        `;
        } else {
            html += `
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-building me-2"></i>Основная информация
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>Данные о компании не найдены
                </div>
            </div>
        </div>
        `;
        }

        html += `
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="bi bi-graph-up me-2"></i>Финансовые показатели
            </h5>
        </div>
        <div class="card-body">
    `;

        if (data.finance && data.finance.data) {
            const f = data.finance.data;
            const yearKeys = Object.keys(f)
                .map(function (k) { return parseInt(k, 10); })
                .filter(function (y) { return y >= 2015 && y <= 2100; })
                .sort(function (a, b) { return a - b; });
            const lastYears = yearKeys.slice(-2);

            if (lastYears.length === 0) {
                html += `
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>Финансовая отчетность не найдена
            </div>
        `;
            } else {
                html += `<div class="row">`;
                lastYears.forEach(function (year) {
                    const row = f[year] || f[String(year)] || {};
                    const rev = parseInt(row['2110'] || row['Выручка'] || 0, 10);
                    const profit = parseInt(row['2400'] || row['ЧистПриб'] || 0, 10);
                    html += `
                <div class="col-md-6">
                    <h6>${year} год</h6>
                    <table class="table table-sm analytics-table">
                        <tbody>
                            <tr>
                                <td width="40" style="border: none;">${getStatusCircle(rev, 'numeric_positive')}</td>
                                <td style="border: none;"><strong>Выручка:</strong></td>
                                <td style="border: none;">${formatNumberWeb(rev)} руб.</td>
                            </tr>
                            <tr>
                                <td style="border: none;">${getStatusCircle(profit, 'numeric_positive')}</td>
                                <td style="border: none;"><strong>Чистая прибыль:</strong></td>
                                <td style="border: none;">${formatNumberWeb(profit)} руб.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>`;
                });
                html += `</div>`;
            }
        } else {
            html += `
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>Финансовая отчетность не найдена
            </div>
        `;
        }

        html += `</div></div>`;

        html += `
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="bi bi-shield-exclamation me-2"></i>Исполнительные производства
            </h5>
        </div>
        <div class="card-body">
    `;

        if (data.enforcements && data.enforcements.data) {
            const e = data.enforcements.data;
            const debt = parseInt(e['ОстЗадолж'] || e['ОбщСум'] || 0, 10);
            const count = parseInt(e['КолвоИП'] || e['ОбщКолич'] || 0, 10);

            html += `
            <table class="table table-sm analytics-table">
                <tbody>
                    <tr>
                        <td width="40" style="border: none;">${getStatusCircle(debt, 'enforcement_debt')}</td>
                        <td style="border: none;"><strong>Остаток задолженности:</strong></td>
                        <td style="border: none;">${formatNumberWeb(debt)} руб.</td>
                    </tr>
                    <tr>
                        <td style="border: none;">${getStatusCircle(count, 'numeric_negative')}</td>
                        <td style="border: none;"><strong>Количество ИП:</strong></td>
                        <td style="border: none;">${count}</td>
                    </tr>
                </tbody>
            </table>
        `;
        } else {
            html += `
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>Исполнительные производства не найдены
            </div>
        `;
        }

        html += `</div></div>`;

        html += `
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="bi bi-journal-text me-2"></i>Арбитражные дела
            </h5>
        </div>
        <div class="card-body">
    `;

        if (data.lawsuits && data.lawsuits.data) {
            const l = data.lawsuits.data;
            const count = parseInt(l['ЗапВсего'] || 0, 10);
            const claimSum = parseInt(l['ОбщСуммИск'] || l['СуммИск'] || 0, 10);

            html += `
            <table class="table table-sm analytics-table">
                <tbody>
                    <tr>
                        <td width="40" style="border: none;">${getStatusCircle(count, 'numeric_negative')}</td>
                        <td style="border: none;"><strong>Общее количество дел:</strong></td>
                        <td style="border: none;">${count}</td>
                    </tr>
                    <tr>
                        <td style="border: none;">${getStatusCircle(claimSum, 'numeric_negative')}</td>
                        <td style="border: none;"><strong>Сумма исковых требований:</strong></td>
                        <td style="border: none;">${formatNumberWeb(claimSum)} руб.</td>
                    </tr>
                </tbody>
            </table>
        `;
        } else {
            html += `
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>Арбитражные дела не найдены
            </div>
        `;
        }

        html += `</div></div>`;

        if (container) {
            container.innerHTML = html;
            mountFinScoreInteractions(finscore);
        }
    }

    function showAnalyticsContent() {
        document.getElementById('analytics-loading').style.display = 'none';
        document.getElementById('analytics-empty').style.display = 'none';
        document.getElementById('analytics-content').style.display = 'block';
    }

    function showAnalyticsEmpty() {
        document.getElementById('analytics-loading').style.display = 'none';
        document.getElementById('analytics-content').style.display = 'none';
        document.getElementById('analytics-error').style.display = 'none';
        document.getElementById('analytics-empty').style.display = 'block';
    }

    function updateLastUpdated(timestamp) {
        const lastUpdateEl = document.getElementById('last-update');
        if (!lastUpdateEl) {
            return;
        }
        if (timestamp) {
            lastUpdateEl.innerHTML = `
            <i class="bi bi-clock me-1"></i>
            <span>Обновлено: ${timestamp.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}</span>
        `;
        } else {
            lastUpdateEl.innerHTML = `
            <i class="bi bi-clock me-1"></i>
            <span>Данные не загружены</span>
        `;
        }
    }

    function handleAnalyticsError(error) {
        document.getElementById('analytics-loading').style.display = 'none';
        document.getElementById('analytics-error').style.display = 'block';
        const errorMessageEl = document.getElementById('error-message');
        if (errorMessageEl) {
            errorMessageEl.textContent = error.message;
        }
        notify('Ошибка при загрузке аналитики: ' + error.message, 'danger');
    }

    function processAnalyticsData(data, isNew = false, timestamp = null, finscore = null) {
        const companyNameEl = document.getElementById('company-name');
        if (companyNameEl) {
            if (finscore && finscore.company_name) {
                companyNameEl.textContent = finscore.company_name;
            } else if (data?.company?.data?.НаимСокр) {
                companyNameEl.textContent = data.company.data.НаимСокр;
            } else if (data?.company?.data?.НаимПолн) {
                companyNameEl.textContent = data.company.data.НаимПолн;
            }
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

    function loadAnalytics(forceReload) {
        if (typeof forceReload === 'undefined') {
            forceReload = false;
        }

        if (readOnly && forceReload) {
            return;
        }

        document.getElementById('analytics-loading').style.display = 'block';
        document.getElementById('analytics-empty').style.display = 'none';
        document.getElementById('analytics-content').style.display = 'none';
        document.getElementById('analytics-error').style.display = 'none';

        const loadBtn = document.getElementById('load-analytics-btn');
        const originalHtml = loadBtn ? loadBtn.innerHTML : null;

        function restoreLoadBtn() {
            if (loadBtn) {
                loadBtn.innerHTML = originalHtml;
                loadBtn.disabled = false;
            }
        }

        if (forceReload) {
            if (loadBtn) {
                loadBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Загрузка новых данных...';
                loadBtn.disabled = true;
            }

            fetch('api_get_company_analytics.php?application_id=' + applicationId)
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP error! status: ' + response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (data.success) {
                        if (!readOnly) {
                            return fetch('save_analytics.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify({
                                    application_id: applicationId,
                                    analytics_data: data.data,
                                    finscore: data.finscore || null
                                })
                            }).then(function () {
                                return data;
                            });
                        }
                        return data;
                    }
                    throw new Error(data.error || 'Неизвестная ошибка');
                })
                .then(function (data) {
                    processAnalyticsData(data.data, true, null, data.finscore || null);
                    notify('Аналитика успешно обновлена и сохранена!', 'success');
                })
                .catch(handleAnalyticsError)
                .finally(restoreLoadBtn);
        } else {
            if (loadBtn) {
                loadBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Загрузка из кэша...';
                loadBtn.disabled = true;
            }

            fetch('load_analytics_cache.php?application_id=' + applicationId)
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data.success) {
                        processAnalyticsData(data.data, false, data.timestamp, data.finscore || null);
                        notify('Аналитика загружена из кэша', 'success');
                    } else {
                        showAnalyticsEmpty();
                    }
                })
                .catch(handleAnalyticsError)
                .finally(restoreLoadBtn);
        }
    }

    function initCompanyAnalytics(options) {
        options = options || {};
        applicationId = options.applicationId;
        readOnly = !!options.readOnly;
        silentNotifications = readOnly;

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
