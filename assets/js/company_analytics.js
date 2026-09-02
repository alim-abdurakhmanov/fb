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

    function renderAnalytics(data) {
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

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        let html = '';

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
            const rev24 = parseInt(f[2024]?.['2110'] || 0);
            const profit24 = parseInt(f[2024]?.['2400'] || 0);
            const rev25 = parseInt(f[2025]?.['2110'] || 0);
            const profit25 = parseInt(f[2025]?.['2400'] || 0);

            html += `
            <div class="row">
                <div class="col-md-6">
                    <h6>2024 год</h6>
                    <table class="table table-sm analytics-table">
                        <tbody>
                            <tr>
                                <td width="40" style="border: none;">${getStatusCircle(rev24, 'numeric_positive')}</td>
                                <td style="border: none;"><strong>Выручка:</strong></td>
                                <td style="border: none;">${formatNumberWeb(rev24)} руб.</td>
                            </tr>
                            <tr>
                                <td style="border: none;">${getStatusCircle(profit24, 'numeric_positive')}</td>
                                <td style="border: none;"><strong>Чистая прибыль:</strong></td>
                                <td style="border: none;">${formatNumberWeb(profit24)} руб.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6>2025 год</h6>
                    <table class="table table-sm analytics-table">
                        <tbody>
                            <tr>
                                <td width="40" style="border: none;">${getStatusCircle(rev25, 'numeric_positive')}</td>
                                <td style="border: none;"><strong>Выручка:</strong></td>
                                <td style="border: none;">${formatNumberWeb(rev25)} руб.</td>
                            </tr>
                            <tr>
                                <td style="border: none;">${getStatusCircle(profit25, 'numeric_positive')}</td>
                                <td style="border: none;"><strong>Чистая прибыль:</strong></td>
                                <td style="border: none;">${formatNumberWeb(profit25)} руб.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;
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
            const debt = parseInt(e['ОстЗадолж'] || 0);

            html += `
            <table class="table table-sm analytics-table">
                <tbody>
                    <tr>
                        <td width="40" style="border: none;">${getStatusCircle(debt, 'enforcement_debt')}</td>
                        <td style="border: none;"><strong>Остаток задолженности:</strong></td>
                        <td style="border: none;">${formatNumberWeb(debt)} руб.</td>
                    </tr>
                    <tr>
                        <td style="border: none;">${getStatusCircle(e['КолвоИП'] || 0, 'numeric_negative')}</td>
                        <td style="border: none;"><strong>Количество ИП:</strong></td>
                        <td style="border: none;">${e['КолвоИП'] || 0}</td>
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
            const count = parseInt(l['ЗапВсего'] || 0);
            const claimSum = parseInt(l['СуммИск'] || 0);

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

    function processAnalyticsData(data, isNew = false, timestamp = null) {
        const companyNameEl = document.getElementById('company-name');
        if (companyNameEl) {
            if (data?.company?.data?.НаимСокр) {
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

        renderAnalytics(data);
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
                                    analytics_data: data.data
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
                    processAnalyticsData(data.data, true);
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
                        processAnalyticsData(data.data, false, data.timestamp);
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
