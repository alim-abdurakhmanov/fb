<?php
$current_page = 'create_application';
require_once 'config.php';
require_once __DIR__ . '/includes/application_documents_upload.php';
checkAuth();

$pdo = getPDO();
$current_user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'client';
$isSubmanager = finbuild_is_case_manager(); // Без выбора клиента и ответственного — он сам
$usersList = [];
$managersList = [];

if (finbuild_is_manager($user_role)) {
    $stmtUsers = $pdo->prepare("
        SELECT id, first_name, last_name, company_name, inn, role, phone 
        FROM users 
        WHERE role IN ('client', 'partner') 
        ORDER BY company_name ASC, last_name ASC
    ");
    $stmtUsers->execute();
    $usersList = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

    // Список менеджеров для поля "Ответственный"
    $stmtManagers = $pdo->prepare("
        SELECT id, first_name, last_name 
        FROM users 
        WHERE role IN (" . finbuild_manager_roles_sql_in() . ") AND is_active = 1
        ORDER BY last_name ASC, first_name ASC
    ");
    $stmtManagers->execute();
    $managersList = $stmtManagers->fetchAll(PDO::FETCH_ASSOC);
}
// --------------------------------------------------------------------

$error = '';
$success = '';
$created_application_id = null;

// Проверяем, что пользователь клиент или партнер
$userRole = $_SESSION['role'] ?? 'client';
$currentUserInn = '';
if ($userRole === 'client') {
    $stmtInn = $pdo->prepare("SELECT inn FROM users WHERE id = ? AND role = 'client' LIMIT 1");
    $stmtInn->execute([$current_user_id]);
    $currentUserInn = (string)($stmtInn->fetchColumn() ?: '');
}
// if (finbuild_is_manager($userRole)) {
//     header('Location: applications.php');
//     exit();
// }

// Обработка формы - ДО любого вывода!
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {

     
        $product_type = $_POST['product_type'] ?? '';
        $inn = trim($_POST['inn'] ?? '');
        $company_name = trim($_POST['company_name'] ?? '');
        
        // Валидация основных полей
        if (empty($product_type) || empty($inn) || empty($company_name)) {
            throw new Exception("Заполните все обязательные поля");
        }
        
        if (!preg_match('/^\d{10,12}$/', $inn)) {
            throw new Exception("ИНН должен состоять из 10–12 цифр");
        }

        // Проверяем наличие term_bg для БГ
if ($product_type === 'bg') {
    $term_bg = $_POST['term_bg'] ?? null;
    if (empty($term_bg)) {
        throw new Exception("Укажите срок гарантии");
    }
    
    // Преобразуем дату в формат для БД
    $term_bg_date = date('Y-m-d', strtotime($term_bg));
    
    // Рассчитываем количество месяцев от текущей даты до term_bg
    $currentDate = new DateTime();
    $endDate = new DateTime($term_bg_date);
    $interval = $currentDate->diff($endDate);
    $months = $interval->y * 12 + $interval->m;
    // Если остались дни, добавляем еще месяц
    if ($interval->d > 0) {
        $months++;
    }
    $term = $months;
} else {
    // Для кредита оставляем как есть
    $term = $_POST['term'] ? intval($_POST['term']) : null;
    $term_bg_date = null;
}
        function parseAmount($amount) {
    if (empty($amount) || trim($amount) === '') return null;
    
    // Заменяем запятую на точку для корректного парсинга
    $amount = str_replace(',', '.', $amount);
    
    // Убираем пробелы (разделители тысяч)
    $amount = str_replace(' ', '', $amount);
    
    // Проверяем, что остались только цифры и точка
    if (!preg_match('/^\d+(\.\d+)?$/', $amount)) {
        throw new Exception("Некорректный формат суммы: " . $amount);
    }
    
    return floatval($amount);
}
        
        // Получаем суммы с копейками
        $amount = null;
        $contract_price = null;
        
        try {
            $amount = isset($_POST['amount']) ? parseAmount($_POST['amount']) : null;
        } catch (Exception $e) {
            throw new Exception("Ошибка в поле суммы: " . $e->getMessage());
        }
        
        try {
            $contract_price = isset($_POST['contract_price']) ? parseAmount($_POST['contract_price']) : null;
        } catch (Exception $e) {
            throw new Exception("Ошибка в поле цены контракта: " . $e->getMessage());
        }

        $is_extension = !empty($_POST['is_extension']) ? 1 : 0;
        $is_replacement = !empty($_POST['is_replacement']) ? 1 : 0;
           
          // 1. ОПРЕДЕЛЯЕМ РОЛИ
        $created_by = $_SESSION['user_id']; // По дефолту - текущий
        $added_by = null;

        // Для director/manager выбор клиента/партнёра обязателен
        if (finbuild_has_full_manager_access()) {
            $target_user_id_raw = trim((string)($_POST['target_user_id'] ?? ''));
            if ($target_user_id_raw === '') {
                throw new Exception("Выберите клиента или партнёра");
            }
            $created_by = (int)$_POST['target_user_id']; // Владелец = выбранный клиент/партнёр
            $added_by = $_SESSION['user_id'];           // Добавил = сотрудник
        } elseif ($isSubmanager) {
            // Менеджер по заявкам: владелец и автор — он сам (без выбора клиента)
            $created_by = (int) $current_user_id;
            $added_by = (int) $current_user_id;
        }

        // Ответственный: только для менеджерских ролей; по умолчанию — текущий пользователь
        $assigned_to = null;
        if (finbuild_is_manager($user_role)) {
            if ($isSubmanager) {
                $assigned_to = (int) $current_user_id; // всегда ответственный по своим заявкам
            } else {
                $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : $current_user_id;
            }
        }

          // --- ЗАПИСЬ В БД (Используем вычисленные $created_by, $added_by, $assigned_to) ---
        $purchase_link = trim((string)($_POST['purchase_link'] ?? ''));
        $purchase_link = $purchase_link === '' ? null : $purchase_link;

        $stmt = $pdo->prepare("INSERT INTO applications 
            (company_name, inn, product_type, fz_type, guarantee_type, loan_type, amount, term, term_bg,
             is_extension, is_replacement,
             purchase_number, purchase_link, declined_banks, contract_subject, contract_price, customer_inn, customer_name, 
             guarantee_provision_deadline,
             collateral_transport_enabled, collateral_transport_details,
             collateral_real_estate_enabled, collateral_real_estate_details,
             collateral_deposit_note_enabled, collateral_deposit_note_details,
             collateral_third_party_guarantee_enabled, collateral_third_party_guarantee_details,
             contact_name, contact_phone, comment, created_by, added_by, assigned_to) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $company_name,
            $inn,
            $product_type,
            $_POST['fz_type'] ?? null,
            $_POST['guarantee_type'] ?? null,
            $_POST['loan_type'] ?? null,
            $amount,
            $term,
            $product_type === 'bg' ? $term_bg_date : null,
            $is_extension,
            $is_replacement,
            $_POST['purchase_number'] ?? null,
            $purchase_link,
            $_POST['declined_banks'] ?? null,
            $_POST['contract_subject'] ?? null,
            $contract_price,
            $_POST['customer_inn'] ?? null,
            $_POST['customer_name'] ?? null,
            $product_type === 'bg' && trim((string)($_POST['guarantee_provision_deadline'] ?? '')) !== '' ? trim((string)$_POST['guarantee_provision_deadline']) : null,
            $product_type === 'bg' && !empty($_POST['collateral_transport_enabled']) ? 1 : 0,
            $product_type === 'bg' && !empty($_POST['collateral_transport_enabled']) && trim((string)($_POST['collateral_transport_details'] ?? '')) !== '' ? trim((string)$_POST['collateral_transport_details']) : null,
            $product_type === 'bg' && !empty($_POST['collateral_real_estate_enabled']) ? 1 : 0,
            $product_type === 'bg' && !empty($_POST['collateral_real_estate_enabled']) && trim((string)($_POST['collateral_real_estate_details'] ?? '')) !== '' ? trim((string)$_POST['collateral_real_estate_details']) : null,
            $product_type === 'bg' && !empty($_POST['collateral_deposit_note_enabled']) ? 1 : 0,
            $product_type === 'bg' && !empty($_POST['collateral_deposit_note_enabled']) && trim((string)($_POST['collateral_deposit_note_details'] ?? '')) !== '' ? trim((string)$_POST['collateral_deposit_note_details']) : null,
            $product_type === 'bg' && !empty($_POST['collateral_third_party_guarantee_enabled']) ? 1 : 0,
            $product_type === 'bg' && !empty($_POST['collateral_third_party_guarantee_enabled']) && trim((string)($_POST['collateral_third_party_guarantee_details'] ?? '')) !== '' ? trim((string)$_POST['collateral_third_party_guarantee_details']) : null,
            $_POST['contact_name'] ?? null,
            $_POST['contact_phone'] ?? null,
            $_POST['comment'] ?? null,
            $created_by,
            $added_by,
            $assigned_to // Ответственный (только для менеджеров; у клиентов/партнеров null)
        ]);
        $application_id = $pdo->lastInsertId();
        $created_application_id = (int)$application_id;
        
        // Обработка документов
        if (!empty($_FILES)) {
            handleApplicationDocuments($pdo, $application_id, $_POST, $_FILES);
        }

        require_once __DIR__ . '/includes/notification_events.php';
        notify_new_application_managers($pdo, (int) $application_id);
        
        $success = "Заявка успешно создана! Номер заявки: #" . $application_id;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
  
// Обновленная функция для обработки документов
function handleApplicationDocuments($pdo, $application_id, $post, $files) {
    $upload_dir = 'uploads/applications/' . $application_id . '/';
    
    // Создаем директорию если не существует
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Список типов документов с описаниями
    $document_types = [
        'financial_report' => [
            'label' => 'Квартальная бухгалтерская отчетность (Ф1 + Ф2)',
            'description' => 'Формы 1 и 2 бухгалтерской отчетности за последний отчетный период'
        ],
        'contract_project' => [
            'label' => 'Проект или скан договора/контракта',
            'description' => 'Проект договора или скан подписанного контракта'
        ],
        'contract_register' => [
            'label' => 'Реестр контрактов',
            'description' => 'Реестр исполненных контрактов за последние 3 года'
        ],
        'credit_portfolio' => [
            'label' => 'Кредитный портфель', 
            'description' => 'Информация о текущем кредитном портфеле компании'
        ],
        'other_documents' => [
            'label' => 'Другие документы',
            'description' => 'Дополнительные документы по усмотрению клиента'
        ]
    ];
    
    foreach ($document_types as $key => $doc_info) {
        if (!empty($files[$key]['name'][0])) {
            foreach ($files[$key]['name'] as $index => $name) {
                if ($files[$key]['error'][$index] === UPLOAD_ERR_OK) {
                    $file_tmp = $files[$key]['tmp_name'][$index];
                    $file_size = $files[$key]['size'][$index];
                    $file_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $file_name = uniqid() . '.' . $file_ext;
                    $file_path = $upload_dir . $file_name;
                    
                    finbuild_application_document_validate_upload($name, (int) $file_size);
                    
                    if (move_uploaded_file($file_tmp, $file_path)) {
                        $file_type = finbuild_application_document_file_type($file_ext);
                        
                        $stmt = $pdo->prepare("INSERT INTO application_documents 
                            (application_id, document_type, description, file_path, original_name, file_size, file_type, uploaded_by) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $application_id, 
                            $doc_info['label'],
                            $doc_info['description'],
                            $file_path, 
                            $name, 
                            $file_size,
                            $file_type,
                            $_SESSION['user_id']
                        ]);
                    }
                }
            }
        }
    }
}

function getCompanyOrIPNameByInn($inn) {
    // Используем наш прокси-API, который умеет искать и компании, и ИП
    $apiUrl = "api_checko_proxy.php?inn=" . urlencode($inn);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['success']) && $data['success'] && isset($data['name'])) {
            return $data['name'];
        }
    }
    
    return null;
}



// ТЕПЕРЬ подключаем header.php после всей обработки
require_once 'header.php';
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="h3 mb-0">Создание заявки</h1>
            <p class="text-muted mb-0">Заполните форму для создания новой заявки</p>
        </div>
        <div class="col-auto">
            <a href="applications.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-2"></i>Назад к заявкам
            </a>
        </div>
    </div>
</div>


<style>


.application-form-section {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
}

.section-title {
    color: #2c3e50;
    border-bottom: 2px solid #3498db;
    padding-bottom: 0.75rem;
    margin-bottom: 1.5rem;
    font-weight: 600;
}

/* Чекбоксы «Продление» / «Переобеспечение» по центру поля «Срок предоставления гарантии» */
.bg-provision-extras-row .bg-provision-extras {
    min-height: calc(1.5em + 0.75rem + 2px);
}

.dynamic-field {
    transition: all 0.3s ease;
}

.file-upload-area {
    border: 2px dashed #dee2e6;
    border-radius: 8px;
    padding: 2rem;
    text-align: center;
    background: #f8f9fa;
    transition: all 0.3s ease;
    cursor: pointer;
}

.file-upload-area:hover {
    border-color: #3498db;
    background: #f1f8ff;
}

.file-upload-area.dragover {
    border-color: #3498db;
    background: #e3f2fd;
}

.file-list {
    margin-top: 1rem;
}

.file-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem;
    background: #f8f9fa;
    border-radius: 6px;
    margin-bottom: 0.5rem;
}

.file-item:hover {
    background: #e9ecef;
}

.file-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.file-icon {
    font-size: 1.25rem;
    color: #6c757d;
}

.progress-bar {
    height: 4px;
    background: #e9ecef;
    border-radius: 2px;
    overflow: hidden;
    margin-top: 0.5rem;
}

.progress-fill {
    height: 100%;
    background: #3498db;
    width: 0%;
    transition: width 0.3s ease;
}

.required-field::after {
    content: " *";
    color: #dc3545;
}
.required-field::after {
    content: " *";
    color: #dc3545;
}

.document-section {
    transition: all 0.3s ease;
}

.dynamic-document {
    animation: fadeIn 0.5s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.conditional-field {
    border-left: 4px solid #3498db;
    padding-left: 1rem;
    margin-left: -1rem;
}

.help-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    margin-left: 6px;
    border-radius: 50%;
    background: #e9ecef;
    color: #6c757d;
    font-size: 11px;
    font-weight: 700;
    cursor: help;
    vertical-align: middle;
}

.help-icon:hover {
    background: #dfe6ee;
    color: #495057;
}

@media (max-width: 768px) {
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        font-size: 14px;
        line-height: 1.45;
        color: #0f172a;
    }

    .page-header {
        padding: 0.85rem 1rem;
        border-radius: 12px;
    }

    .page-header h1 {
        font-size: 1.15rem;
        font-weight: 700;
        line-height: 1.3;
    }

    .page-header .row {
        row-gap: 0.5rem;
    }

    .page-header .btn {
        width: 100%;
    }

    .page-header .col-auto {
        width: 100%;
    }

    .application-form-section {
        padding: 1rem;
        margin-bottom: 1rem;
        border-radius: 12px;
    }

    .section-title {
        font-size: 1rem;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
    }

    .form-label {
        font-size: 0.85rem;
    }

    .form-control,
    .form-select,
    .input-group .form-control {
        font-size: 0.9rem;
    }

    .file-upload-area {
        padding: 1.25rem;
    }

    .btn {
        font-size: 0.9rem;
        padding: 0.45rem 0.75rem;
    }

    .btn-lg {
        width: 100%;
    }
}
</style>

<div class="row">
    <div class="col-12">
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                <div class="mt-2 d-flex flex-wrap gap-2">
                    <?php if (!empty($created_application_id)): ?>
                        <a href="application_details.php?id=<?= (int)$created_application_id ?>" class="btn btn-primary btn-sm">Открыть заявку</a>
                    <?php endif; ?>
                    <a href="applications.php" class="btn btn-success btn-sm">Перейти к заявкам</a>
                    <a href="create_application.php" class="btn btn-outline-success btn-sm">Создать еще заявку</a>
                </div>
            </div>
        <?php endif; ?>
           <form method="POST" id="applicationForm" enctype="multipart/form-data" novalidate>
          <!-- === БЛОК ВЫБОРА ПОЛЬЗОВАТЕЛЯ (Только для руководителя; ограниченный менеджер ставится сам) === -->
    <?php if (finbuild_has_full_manager_access()): ?>
    <div class="application-form-section">
        <h4 class="section-title">Владелец заявки</h4>
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="target_user_id" class="form-label required-field">Выберите клиента или партнера</label>
                     <select class="form-select" id="target_user_id" name="target_user_id" required>
                    <option></option> <!-- Пустой для placeholder -->

                    <?php 
                    // Разделяем пользователей на группы
                    $clients = [];
                    $partners = [];
                    
                    foreach ($usersList as $u) {
                        // Формируем красивое имя сразу здесь
                        $company = trim($u['company_name'] ?? '');
                        $inn = trim($u['inn'] ?? '');
                        $person = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));

                        if (($u['role'] ?? '') === 'client') {
                            if ($company && $inn) {
                                $displayText = "$company ($inn)";
                            } elseif ($company) {
                                $displayText = $company;
                            } elseif ($inn) {
                                $displayText = "ИНН: $inn";
                            } else {
                                $displayText = $person;
                            }
                        } else {
                            if ($person && $company) {
                                $displayText = "$person ($company)";
                            } elseif ($person) {
                                $displayText = $person;
                            } else {
                                $displayText = $company;
                            }
                        }
                        
                        // Формируем имя контакта (Имя + Фамилия)
                        $contactName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                        
                        // Формируем массив данных для опции
                        $item = [
                            'id' => $u['id'], 
                            'text' => $displayText,
                            'contact' => $contactName,     // Для автозаполнения
                            'phone' => $u['phone'] ?? '',  // Для автозаполнения
                            'inn' => $inn,
                            'role' => $u['role'] ?? ''
                        ];

                        // Раскидываем по массивам (проверяем поле role)
                        // Предполагаем, что роль в БД записана как 'client' и 'partner'
                        if (($u['role'] ?? '') === 'partner') {
                            $partners[] = $item;
                        } else {
                            $clients[] = $item;
                        }
                    }
                    ?>

                    <!-- Вывод группы ПАРТНЕРЫ -->
                    <?php if (!empty($partners)): ?>
                        <optgroup label="Партнеры">
                            <?php foreach ($partners as $p): ?>
                                <option value="<?= $p['id'] ?>" 
        data-contact="<?= htmlspecialchars($p['contact']) ?>" 
        data-phone="<?= htmlspecialchars($p['phone']) ?>"
        data-inn="<?= htmlspecialchars($p['inn']) ?>"
        data-role="<?= htmlspecialchars($p['role']) ?>">
    <?= htmlspecialchars($p['text']) ?>
</option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>

                    <!-- Вывод группы КЛИЕНТЫ -->
                    <?php if (!empty($clients)): ?>
                        <optgroup label="Клиенты">
                            <?php foreach ($clients as $c): ?>
                                 <option value="<?= $c['id'] ?>" 
                                    data-contact="<?= htmlspecialchars($c['contact']) ?>" 
                                    data-phone="<?= htmlspecialchars($c['phone']) ?>"
                                    data-inn="<?= htmlspecialchars($c['inn']) ?>"
                                    data-role="<?= htmlspecialchars($c['role']) ?>">
                                <?= htmlspecialchars($c['text']) ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>

                </select>

                <div class="form-text">
                    Если клиента/партнера нет в системе, то <a href="user_create.php" target="_blank" style="text-decoration: underline;">сначала создайте его</a>.
                </div>
            </div>

            <?php if (!empty($managersList)): ?>
            <div class="col-md-6 mb-3">
                <label class="form-label">Ответственный менеджер</label>
                <select class="form-select" name="assigned_to" id="assigned_to">
                    <?php foreach ($managersList as $m): ?>
                        <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === (int)$current_user_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars(trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <!-- ======================================================== -->




     
            <!-- Секция 1: Основная информация -->
            <div class="application-form-section">
                <h4 class="section-title">Основная информация</h4>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label required-field">Тип продукта</label>
                        <?php if (finbuild_is_manager($userRole)): ?>
                            <select class="form-select" id="productType" name="product_type">
                                <option value="bg" selected>Банковская гарантия</option>
                                <option value="credit">Кредит</option>
                            </select>
                        <?php else: ?>
                            <select class="form-select" id="productType" disabled>
                                <option value="bg" selected>Банковская гарантия</option>
                            </select>
                            <input type="hidden" name="product_type" value="bg">
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label required-field">ИНН организации</label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="inn" id="inn" 
                                   maxlength="12" required
                                   placeholder="Введите 10 или 12 цифр"
                                   <?= $userRole === 'client' ? 'readonly' : '' ?>
                                   value="<?= htmlspecialchars($_POST['inn'] ?? $currentUserInn) ?>">
                            <button type="button" class="btn btn-outline-secondary" id="fetchCompanyName">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                        <div class="form-text">Введите ИНН и нажмите поиск для автоматического заполнения</div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12 mb-3">
                        <label class="form-label required-field">Название организации</label>
                        <input type="text" class="form-control" name="company_name" id="companyName" required
                               placeholder="Название организации появится автоматически">
                    </div>
                </div>
            </div>

            <!-- Секция 2: Детали (динамическая) -->
            <div class="application-form-section" id="productDetailsSection" style="display: none;">
                <h4 class="section-title">Детали</h4>
                <div id="productDetailsContent">
                    <!-- Контент будет подгружаться динамически -->
                </div>
            </div>

            <!-- Секция: Обеспечение (только БГ) -->
            <div class="application-form-section" id="collateralSection" style="display: none;">
                <h4 class="section-title">Обеспечение</h4>
                <div id="collateralContent">
                    <!-- Контент будет подгружаться динамически -->
                </div>
            </div>

            <!-- Секция 3: Документы -->
            <div class="application-form-section" id="documentsSection" style="display: none;">
                <h4 class="section-title">Документы</h4>
                <div id="documentsContent">
                    <!-- Контент будет подгружаться динамически -->
                </div>
            </div>
            <!-- Секция 5: Комментарий -->
<div class="application-form-section" id="commentSection" style="display: none;">
    <h4 class="section-title">Комментарий</h4>
    
    <div class="row">
        <div class="col-12 mb-3">
            <textarea class="form-control" name="comment" id="comment" rows="4" 
                      placeholder="Любые дополнительные комментарии к заявке..."></textarea>
        </div>
    </div>
</div>
            <!-- Секция 4: Контактная информация -->
            <div class="application-form-section" id="contactSection" style="display: none;">
                <h4 class="section-title">Контактная информация</h4>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Контактное лицо</label>
                        <input type="text" class="form-control" name="contact_name" 
                               value="<?= (!finbuild_is_manager($user_role)) ? htmlspecialchars(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')) : '' ?>"
                               placeholder="ФИО контактного лица">
                    </div>
                    
                  <div class="col-md-6 mb-3 ">
    <label class="form-label required-field">Телефон для связи</label>
    <?php 
    // Вычисляем значение заранее, чтобы не путаться в HTML
    $phoneValue = '';
    if (!finbuild_is_manager($user_role)) {
        $phoneValue = $currentUser['phone'] ?? '';
    }
    // Если была ошибка валидации и форма вернулась, сохраняем введенное
    if (isset($_POST['contact_phone'])) {
        $phoneValue = $_POST['contact_phone'];
    }
?>

<input type="tel" class="form-control" name="contact_phone" id="contactPhone" 
       value="<?= htmlspecialchars($phoneValue) ?>" 
       placeholder="+7 (XXX) XXX-XX-XX" 
       autocomplete="off">
 </div>
                </div>
                
                <div class="text-center mt-4">
                    <button type="submit" class="btn btn-primary btn-lg px-5">
                        <i class="bi bi-send me-2"></i>Создать заявку
                    </button>
                </div>
            </div>

        </form>
    </div>
</div>

<!-- Шаблоны для динамического контента -->
<template id="bgTemplate">
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label required-field">Вид ФЗ</label>
            <select class="form-select" name="fz_type" required>
                <option value="">Выберите вид ФЗ</option>
                <option value="44-ФЗ">44-ФЗ</option>
                <option value="223-ФЗ">223-ФЗ</option>
                <option value="185-ФЗ (615-ПП)">185-ФЗ (615-ПП)</option>
                <option value="Коммерция">Коммерция</option>
            </select>
        </div>
        
        <div class="col-md-6 mb-3">
            <label class="form-label required-field">Вид гарантии</label>
            <select class="form-select" name="guarantee_type" required>
                <option value="">Выберите вид гарантии</option>
                <option value="Участие">Участие</option>
                <option value="Исполнение">Исполнение</option>
                <option value="Исполнение с авансом">Исполнение с авансом</option>
                <option value="Возврат аванса">Возврат аванса</option>
                <option value="Гарантийный период">Гарантийный период</option>
                <option value="Платежная">Платежная</option>
                <option value="НДС в пользу ФНС">НДС в пользу ФНС</option>
            </select>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label required-field">Сумма гарантии (руб)</label>
           <input type="text" class="form-control" name="amount" required
       placeholder="Например: 1 000 000,50"
       pattern="[\d\s,\.]+">
        </div>
        
       <div class="col-md-6 mb-3">
            <label class="form-label required-field">Срок гарантии до</label>
            <input type="date" class="form-control" name="term_bg" required
                   min="<?= date('Y-m-d') ?>"
                   placeholder="Выберите дату окончания гарантии">
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Номер закупки</label>
            <div class="input-group">
                <input type="text" class="form-control" name="purchase_number" id="purchaseNumber"
                       placeholder="Например: 1280110146015000001" inputmode="numeric" autocomplete="off">
                <button type="button" class="btn btn-outline-secondary" id="fetchPurchaseByNumber"
                        title="Найти данные по номеру закупки">
                    <i class="bi bi-search"></i>
                </button>
            </div>
            <div class="mt-3">
                <label class="form-label">Предмет контракта
                    <span class="help-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="Точный и правильный предмет контракта. Копируем из наименования закупки, аукционной документации или из раздела контракта/договора «объект/предмет работ»">?</span>
                </label>
                <input type="text" class="form-control" name="contract_subject" id="bgContractSubject"
                       placeholder="Опишите предмет контракта">
            </div>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Цена контракта (руб)</label>
            <input type="text" class="form-control" name="contract_price" id="bgContractPrice"
                   placeholder="Например: 5 000 000,75"
                   pattern="[\d\s,\.]+">
            <div class="row mt-3">
                <div class="col-md-6">
                    <label class="form-label">ИНН заказчика
                        <span class="help-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="ИНН можно найти в реквизитах проекта или скане контракта.">?</span>
                    </label>
                    <div class="input-group">
                        <input type="text" class="form-control" name="customer_inn" id="customerInn" 
                               maxlength="12"
                               placeholder="10 или 12 цифр">
                        <button type="button" class="btn btn-outline-secondary" id="fetchCustomerName">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Наименование заказчика</label>
                    <input type="text" class="form-control" name="customer_name" id="customerName" 
                           readonly placeholder="Появится автоматически">
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Ссылка на закупку</label>
            <input type="url" class="form-control" name="purchase_link" id="purchaseLink"
                   placeholder="https://zakupki.gov.ru/...">
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">В каких банках были отказы</label>
            <input type="text" class="form-control" name="declined_banks"
                   placeholder="Перечислите банки через запятую">
        </div>
    </div>

    <div class="row bg-provision-extras-row mb-3">
        <div class="col-md-6">
            <label class="form-label">Срок предоставления гарантии
                <span class="help-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="Когда крайний срок предоставления гарантии">?</span>
            </label>
            <input type="text" class="form-control" name="guarantee_provision_deadline"
                   placeholder="Точная дата или примерное описание">
        </div>
        <div class="col-md-6">
            <label class="form-label invisible" aria-hidden="true">&#8203;</label>
            <div class="bg-provision-extras d-flex align-items-center flex-wrap gap-3">
                <div class="form-check form-check-inline mb-0">
                    <input type="hidden" name="is_extension" value="0">
                    <input class="form-check-input" type="checkbox" name="is_extension" id="is_extension_bg" value="1">
                    <label class="form-check-label" for="is_extension_bg">
                        Продление
                        <span class="help-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="Продление ранее полученной гарантии.">?</span>
                    </label>
                </div>
                <div class="form-check form-check-inline mb-0">
                    <input type="hidden" name="is_replacement" value="0">
                    <input class="form-check-input" type="checkbox" name="is_replacement" id="is_replacement_bg" value="1">
                    <label class="form-check-label" for="is_replacement_bg">
                        Переобеспечение
                        <span class="help-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="Переобеспечение банковской гарантии другого банка или замена обеспечения денежными средствами на банковскую гарантию.">?</span>
                    </label>
                </div>
            </div>
        </div>
    </div>

</template>

<template id="bgCollateralTemplate">
    <div class="row">
        <div class="col-md-6 mb-3">
            <div class="border rounded-3 p-3 h-100">
                <div class="form-check mb-2">
                    <input class="form-check-input collateral-toggle" type="checkbox" id="collateral_transport_enabled" name="collateral_transport_enabled" value="1" data-target="#collateral_transport_details_wrap">
                    <label class="form-check-label" for="collateral_transport_enabled">Обеспечение транспортом</label>
                </div>
                <div class="mb-3" id="collateral_transport_details_wrap" style="display:none;">
                    <input type="text" class="form-control" name="collateral_transport_details" placeholder="Опишите детали">
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input collateral-toggle" type="checkbox" id="collateral_real_estate_enabled" name="collateral_real_estate_enabled" value="1" data-target="#collateral_real_estate_details_wrap">
                    <label class="form-check-label" for="collateral_real_estate_enabled">Обеспечение недвижимостью</label>
                </div>
                <div class="mb-0" id="collateral_real_estate_details_wrap" style="display:none;">
                    <input type="text" class="form-control" name="collateral_real_estate_details" placeholder="Опишите детали">
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-3">
            <div class="border rounded-3 p-3 h-100">
                <div class="form-check mb-2">
                    <input class="form-check-input collateral-toggle" type="checkbox" id="collateral_deposit_note_enabled" name="collateral_deposit_note_enabled" value="1" data-target="#collateral_deposit_note_details_wrap">
                    <label class="form-check-label" for="collateral_deposit_note_enabled">Обеспечение депозит/вексель</label>
                </div>
                <div class="mb-3" id="collateral_deposit_note_details_wrap" style="display:none;">
                    <input type="text" class="form-control" name="collateral_deposit_note_details" placeholder="Опишите детали">
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input collateral-toggle" type="checkbox" id="collateral_third_party_guarantee_enabled" name="collateral_third_party_guarantee_enabled" value="1" data-target="#collateral_third_party_guarantee_details_wrap">
                    <label class="form-check-label" for="collateral_third_party_guarantee_enabled">Поручительство третьих юр. лиц</label>
                </div>
                <div class="mb-0" id="collateral_third_party_guarantee_details_wrap" style="display:none;">
                    <input type="text" class="form-control" name="collateral_third_party_guarantee_details" placeholder="Опишите детали">
                </div>
            </div>
        </div>
    </div>
</template>

<template id="creditTemplate">
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label required-field">Вид кредита</label>
            <select class="form-select" name="loan_type" required>
                <option value="">Выберите вид кредита</option>
                <option value="возобновляемая кредитная линия">Возобновляемая кредитная линия</option>
                <option value="невозобновляемая кредитная линия">Невозобновляемая кредитная линия</option>
                <option value="овердрафт">Овердрафт</option>
                <option value="факторинг">Факторинг</option>
                <option value="другой вид">Другой вид</option>
            </select>
        </div>
        
        <div class="col-md-6 mb-3">
            <label class="form-label required-field">Сумма кредита (руб)</label>
          <input type="text" class="form-control" name="amount" required
       placeholder="Например: 5 000 000,99"
       pattern="[\d\s,\.]+">
        </div>
    </div>
    
    <div class="row">
        <div class="col-12 mb-3">
            <label class="form-label required-field">Срок кредита (мес)</label>
            <input type="number" class="form-control" name="term" required min="1" max="120"
                   placeholder="Например: 36">
        </div>
    </div>

</template>

<script>
const FINBUILD_APP_DOC_ACCEPT = <?= json_encode(finbuild_application_document_accept_attribute(), JSON_UNESCAPED_UNICODE) ?>;
const FINBUILD_APP_DOC_FORMATS_HINT = <?= json_encode('Поддерживаются: ' . finbuild_application_document_upload_hint(), JSON_UNESCAPED_UNICODE) ?>;
// JavaScript код остается без изменений
document.addEventListener('DOMContentLoaded', function() {
    const productType = document.getElementById('productType');
    const productDetailsSection = document.getElementById('productDetailsSection');
    const productDetailsContent = document.getElementById('productDetailsContent');
    const collateralSection = document.getElementById('collateralSection');
    const collateralContent = document.getElementById('collateralContent');
    const documentsSection = document.getElementById('documentsSection');
    const documentsContent = document.getElementById('documentsContent');
    const contactSection = document.getElementById('contactSection');
    const innInput = document.getElementById('inn');
    const companyNameInput = document.getElementById('companyName');
    const fetchCompanyBtn = document.getElementById('fetchCompanyName');
    
    // Шаблоны
    const bgTemplate = document.getElementById('bgTemplate');
    const bgCollateralTemplate = document.getElementById('bgCollateralTemplate');
    const creditTemplate = document.getElementById('creditTemplate');
    
    // Bootstrap tooltips
    function initTooltips() {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            if (!el._tooltipInstance) {
                el._tooltipInstance = new bootstrap.Tooltip(el);
            }
        });
    }
    initTooltips();
    
    // Ограничение ввода только цифр для ИНН
    innInput.addEventListener('input', function(e) {
        this.value = this.value.replace(/[^\d]/g, '');
    });
    
// Получение названия компании по ИНН
    async function fetchCompanyNameByInn(inn, showErrors = true) {
        const value = String(inn || '').trim();

        if (!value) {
            if (showErrors) alert('Введите ИНН организации');
            return false;
        }

        if (!/^\d{10,12}$/.test(value)) {
            if (showErrors) alert('ИНН должен состоять из 10–12 цифр');
            return false;
        }

        fetchCompanyBtn.disabled = true;
        fetchCompanyBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';

        try {
            const response = await fetch('api_checko_proxy.php?inn=' + value);
            const data = await response.json();

            if (data.success && data.name) {
                companyNameInput.value = data.name;
                showNextSections();
                return true;
            } else {
                if (showErrors) alert('Организация или ИП с таким ИНН не найден');
                return false;
            }
        } catch (error) {
            if (showErrors) alert('Ошибка при получении данных: ' + error.message);
            return false;
        } finally {
            fetchCompanyBtn.disabled = false;
            fetchCompanyBtn.innerHTML = '<i class="bi bi-search"></i>';
        }
    }

    window.fetchCompanyNameByInn = fetchCompanyNameByInn;

    function tryFetchCompanyName() {
        const inn = innInput.value.trim();
        if (/^\d{10,12}$/.test(inn)) {
            fetchCompanyNameByInn(inn, false);
        }
    }

fetchCompanyBtn.addEventListener('click', function() {
    fetchCompanyNameByInn(innInput.value.trim(), true);
});
    
    // Автоматический поиск при вводе ИНН (после 10-12 цифр)
    innInput.addEventListener('blur', function() {
        if (/^\d{10,12}$/.test(this.value)) {
            fetchCompanyNameByInn(this.value, false);
        }
    });

    if (innInput.value.trim() && !companyNameInput.value.trim()) {
        tryFetchCompanyName();
    }
    // Добавить этот код в DOMContentLoaded или в конец script секции

// Делегирование события click на всю страницу
document.addEventListener('click', function(e) {
    // Если нажата кнопка с id fetchCustomerName или внутри нее
    if (e.target.closest('#fetchCustomerName')) {
        e.preventDefault();
        
        const customerInnInput = document.getElementById('customerInn');
        const customerNameInput = document.getElementById('customerName');
        const fetchBtn = e.target.closest('#fetchCustomerName');
        
        if (!customerInnInput || !customerNameInput) {
            alert('Элементы формы не найдены. Выберите тип продукта "Банковская гарантия"');
            return;
        }
        
        const inn = customerInnInput.value.trim();
        
        if (!inn) {
            alert('Введите ИНН заказчика');
            return;
        }
        
        const originalHtml = fetchBtn.innerHTML;
        fetchBtn.disabled = true;
        fetchBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
        
        fetch('api_checko_proxy.php?inn=' + inn)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Ошибка сети');
                }
                return response.json();
            })
            .then(data => {
                console.log('Ответ от API:', data); // Добавьте для отладки
                if (data.success && data.name) {
                    customerNameInput.value = data.name;
                    console.log('Найден заказчик:', data.name); // Для отладки
                } else {
                    customerNameInput.value = '';
                    alert(data.error || 'Организация или ИП с таким ИНН не найден');
                }
            })
            .catch(error => {
                console.error('Ошибка:', error); // Для отладки
                alert('Ошибка при получении данных: ' + error.message);
                customerNameInput.value = '';
            })
            .finally(() => {
                fetchBtn.disabled = false;
                fetchBtn.innerHTML = originalHtml;
            });
    }
});

// Автоматический поиск при blur (ИНН заказчика, номер закупки)
document.addEventListener('focusout', function(e) {
    if (e.target && e.target.id === 'customerInn') {
        const inn = e.target.value.trim();
        if (inn) {
            const fetchBtn = document.getElementById('fetchCustomerName');
            if (fetchBtn) {
                setTimeout(() => fetchBtn.click(), 100);
            }
        }
    }
    if (e.target && e.target.id === 'purchaseNumber') {
        const digits = String(e.target.value || '').replace(/\D/g, '');
        if (digits.length >= 10) {
            const fetchBtn = document.getElementById('fetchPurchaseByNumber');
            if (fetchBtn) {
                setTimeout(() => fetchBtn.click(), 100);
            }
        }
    }
}, true);

// Заполнение полей по номеру закупки (Checko)
function fetchPurchaseContractByNumber(showErrors) {
    const numInput = document.getElementById('purchaseNumber');
    const btn = document.getElementById('fetchPurchaseByNumber');
    const linkInput = document.getElementById('purchaseLink');
    const subjectInput = document.getElementById('bgContractSubject');
    const priceInput = document.getElementById('bgContractPrice');
    const customerInnInput = document.getElementById('customerInn');
    const customerNameInput = document.getElementById('customerName');
    if (!numInput || !btn) {
        if (showErrors) {
            alert('Поле номера закупки не найдено. Выберите продукт «Банковская гарантия».');
        }
        return;
    }
    const digits = String(numInput.value || '').replace(/\D/g, '');
    if (digits.length < 10) {
        if (showErrors) {
            alert('Введите номер закупки (не менее 10 цифр).');
        }
        return;
    }
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
    fetch('api_checko_proxy.php?number=' + encodeURIComponent(digits))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                alert(data.error || 'Не удалось получить данные');
                return;
            }
            if (data.purchase_link && linkInput) {
                linkInput.value = data.purchase_link;
            }
            if (data.contract_subject && subjectInput) {
                subjectInput.value = data.contract_subject;
            }
            if (data.contract_price != null && priceInput && typeof formatAmountWithSpaces === 'function') {
                var p = Number(data.contract_price);
                if (!isNaN(p)) {
                    priceInput.value = formatAmountWithSpaces(p.toFixed(2).replace('.', ','));
                }
            }
            if (data.customer_inn && customerInnInput) {
                customerInnInput.value = data.customer_inn;
            }
            if (data.customer_name && customerNameInput) {
                customerNameInput.value = data.customer_name;
            }
        })
        .catch(function(err) {
            alert('Ошибка запроса: ' + (err && err.message ? err.message : String(err)));
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        });
}

document.addEventListener('click', function(e) {
    const btn = e.target.closest('#fetchPurchaseByNumber');
    if (!btn) return;
    e.preventDefault();
    fetchPurchaseContractByNumber(true);
});

// Также добавьте отладку для динамических элементов
console.log('Страница загружена. Элементы для БГ будут созданы динамически.');
    // Показ следующих секций после заполнения ИНН
 function showNextSections() {
    productDetailsSection.style.display = 'block';
    if (collateralSection) {
        collateralSection.style.display = currentProductType === 'bg' ? 'block' : 'none';
    }
    documentsSection.style.display = 'block';
    contactSection.style.display = 'block';
    commentSection.style.display = 'block';
    
    // productDetailsSection.scrollIntoView({ behavior: 'smooth' });
}
    
 // Основные переменные
let currentProductType = '';

    function renderProductDetails(value) {
        currentProductType = value;
        productDetailsContent.innerHTML = '';
        if (collateralContent) {
            collateralContent.innerHTML = '';
        }
        
        if (value === 'bg') {
            productDetailsContent.appendChild(bgTemplate.content.cloneNode(true));
            if (collateralContent && bgCollateralTemplate) {
                collateralContent.appendChild(bgCollateralTemplate.content.cloneNode(true));
            }
            if (collateralSection) {
                collateralSection.style.display = productDetailsSection.style.display !== 'none' ? 'block' : 'none';
            }
            updateDocumentsSection('bg');
            // Добавляем обработчики после рендера DOM
            setTimeout(() => setupDynamicListeners('bg'), 100);
        } else if (value === 'credit') {
            productDetailsContent.appendChild(creditTemplate.content.cloneNode(true));
            if (collateralSection) {
                collateralSection.style.display = 'none';
            }
            updateDocumentsSection('credit');
            // Добавляем обработчики после рендера DOM
            setTimeout(() => setupDynamicListeners('credit'), 100);
        }
        
        initTooltips();
    }

    // Переключение чекбоксов обеспечения → показать/скрыть поле "Опишите детали"
    document.addEventListener('change', function(e) {
        const t = e.target;
        if (!t || !t.classList || !t.classList.contains('collateral-toggle')) return;
        const sel = t.getAttribute('data-target') || '';
        if (!sel) return;
        const wrap = document.querySelector(sel);
        if (!wrap) return;
        wrap.style.display = t.checked ? '' : 'none';
        if (!t.checked) {
            const inp = wrap.querySelector('input, textarea');
            if (inp) inp.value = '';
        }
    });

    // Динамическое изменение полей продукта
    productType.addEventListener('change', function() {
        renderProductDetails(this.value);
    });

    // Инициализация по умолчанию (БГ)
    renderProductDetails('bg');

// Настройка динамических обработчиков
function setupDynamicListeners(productType) {
    // Удаляем старые обработчики
    const amountInput = document.querySelector('input[name="amount"]');
    const fzTypeSelect = document.querySelector('select[name="fz_type"]');
    
    if (amountInput) {
        // Клонируем и заменяем input чтобы сбросить обработчики
        const newAmountInput = amountInput.cloneNode(true);
        amountInput.parentNode.replaceChild(newAmountInput, amountInput);
        
        newAmountInput.addEventListener('input', function() {
            if (productType === 'bg') {
                this.value = formatAmountWithSpaces(this.value);
            }
            console.log('Amount changed:', this.value);
            updateDocumentsSection(productType);
        });
    }

    const contractPriceInput = document.querySelector('input[name="contract_price"]');
    if (contractPriceInput && productType === 'bg') {
        const newContractPriceInput = contractPriceInput.cloneNode(true);
        contractPriceInput.parentNode.replaceChild(newContractPriceInput, contractPriceInput);
        newContractPriceInput.addEventListener('input', function() {
            this.value = formatAmountWithSpaces(this.value);
        });
    }
    
    if (fzTypeSelect && productType === 'bg') {
        // Клонируем и заменяем select чтобы сбросить обработчики
        const newFzSelect = fzTypeSelect.cloneNode(true);
        fzTypeSelect.parentNode.replaceChild(newFzSelect, fzTypeSelect);
        
        newFzSelect.addEventListener('change', function() {
            console.log('FZ Type changed:', this.value);
            updateDocumentsSection(productType);
        });
    }
}

// Обновление секции документов
function updateDocumentsSection(productType) {
    const amountInput = document.querySelector('input[name="amount"]');
    const fzTypeSelect = document.querySelector('select[name="fz_type"]');
    
    const amount = amountInput ? parseInt(amountInput.value) || 0 : 0;
    const fzType = fzTypeSelect ? fzTypeSelect.value : '';
    
    console.log('Updating documents:', { productType, amount, fzType });
    
    let html = `
        <div class="mb-4">
            <!-- <h6 class="text-primary mb-3">
                <i class="bi bi-file-earmark-text me-2"></i>Документы
            </h6> -->
            
            <!-- Обязательные для всех -->
            <div class="mb-3">
                <label class="form-label fw-bold required-field">Квартальная бухгалтерская отчетность (Ф1 + Ф2)</label>
                <div class="file-upload-area" onclick="document.getElementById('financial_report').click()">
                    <i class="bi bi-cloud-upload fs-1 text-muted d-block mb-2"></i>
                    <p class="mb-1">Перетащите файлы сюда или нажмите для выбора</p>
                    <small class="text-muted">${FINBUILD_APP_DOC_FORMATS_HINT}</small>
                </div>
                <input type="file" id="financial_report" name="financial_report[]" 
                       multiple accept="${FINBUILD_APP_DOC_ACCEPT}" 
                       style="display: none;" onchange="handleFileSelection(this)">
                <div class="file-list" id="financial_report_list"></div>
            </div>
    `;

    // Условные документы для БГ
    if (productType === 'bg') {
        // Для коммерции
        if (fzType === 'Коммерция') {
            html += `
                <div class="mb-3 conditional-field">
                    <label class="form-label fw-bold required-field">Проект или скан договора/контракта</label>
                    <div class="file-upload-area" onclick="document.getElementById('contract_project').click()">
                        <i class="bi bi-cloud-upload fs-1 text-muted d-block mb-2"></i>
                        <p class="mb-1">Перетащите файлы сюда или нажмите для выбора</p>
                        <small class="text-muted">Проект договора или скан подписанного контракта</small>
                    </div>
                    <input type="file" id="contract_project" name="contract_project[]" 
                           multiple accept="${FINBUILD_APP_DOC_ACCEPT}" 
                           style="display: none;" onchange="handleFileSelection(this)">
                    <div class="file-list" id="contract_project_list"></div>
                </div>
            `;
        }
        
        // Для суммы >= 10 млн
        if (amount >= 10000000) {
            html += `
                <div class="mb-3 conditional-field">
                    <label class="form-label fw-bold required-field">Реестр контрактов</label>
                    <div class="file-upload-area" onclick="document.getElementById('contract_register').click()">
                        <i class="bi bi-cloud-upload fs-1 text-muted d-block mb-2"></i>
                        <p class="mb-1">Перетащите файлы сюда или нажмите для выбора</p>
                        <small class="text-muted">Реестр исполненных контрактов за последние 3 года</small>
                    </div>
                    <input type="file" id="contract_register" name="contract_register[]" 
                           multiple accept="${FINBUILD_APP_DOC_ACCEPT}" 
                           style="display: none;" onchange="handleFileSelection(this)">
                    <div class="file-list" id="contract_register_list"></div>
                </div>
            `;
        }
    }
    
    // Условные документы для кредита
    if (productType === 'credit') {
        // Для суммы >= 10 млн
        if (amount >= 10000000) {
            html += `
                <div class="mb-3 conditional-field">
                    <label class="form-label fw-bold required-field">Кредитный портфель</label>
                    <div class="file-upload-area" onclick="document.getElementById('credit_portfolio').click()">
                        <i class="bi bi-cloud-upload fs-1 text-muted d-block mb-2"></i>
                        <p class="mb-1">Перетащите файлы сюда или нажмите для выбора</p>
                        <small class="text-muted">Информация о текущем кредитном портфеле компании</small>
                    </div>
                    <input type="file" id="credit_portfolio" name="credit_portfolio[]" 
                           multiple accept="${FINBUILD_APP_DOC_ACCEPT}" 
                           style="display: none;" onchange="handleFileSelection(this)">
                    <div class="file-list" id="credit_portfolio_list"></div>
                </div>
            `;
        }
    }
    
    // Другие документы (для всех)
    html += `
        <div class="mb-3">
            <label class="form-label fw-bold">Другие документы</label>
            <div class="file-upload-area" onclick="document.getElementById('other_documents').click()">
                <i class="bi bi-cloud-upload fs-1 text-muted d-block mb-2"></i>
                <p class="mb-1">Перетащите файлы сюда или нажмите для выбора</p>
                <small class="text-muted">Любые дополнительные документы по вашему усмотрению</small>
            </div>
            <input type="file" id="other_documents" name="other_documents[]" 
                   multiple accept="${FINBUILD_APP_DOC_ACCEPT}" 
                   style="display: none;" onchange="handleFileSelection(this)">
            <div class="file-list" id="other_documents_list"></div>
        </div>
    </div>
    `;

    documentsContent.innerHTML = html;
    initializeFileUploads();
}

// Форматирование суммы с разделением по разрядам (для БГ)
function formatAmountWithSpaces(value) {
    if (!value) return '';
    const hasTrailingSeparator = /[.,]\s*$/.test(value);
    const normalized = value.replace(/\s+/g, '');
    const parts = normalized.split(/[.,]/);
    const integerPart = parts[0].replace(/\D/g, '');
    let decimalPart = parts.length > 1 ? parts.slice(1).join('').replace(/\D/g, '') : '';
    if (decimalPart.length > 2) {
        decimalPart = decimalPart.slice(0, 2);
    }
    const withSpaces = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    if (decimalPart) {
        return `${withSpaces},${decimalPart}`;
    }
    if (hasTrailingSeparator) {
        return `${withSpaces},`;
    }
    return withSpaces;
}
    
    // Условные документы
    function getConditionalDocuments(productType) {
        let html = '';
        
        if (productType === 'bg') {
            html += `
                <!-- Для коммерции -->
                <div class="mb-3 conditional-doc" data-condition='{"fz_type": "Коммерция"}'>
                    <label class="form-label fw-bold">Проект или скан договора/контракта</label>
                    <div class="file-upload-area" onclick="document.getElementById('contract_project').click()">
                        <i class="bi bi-cloud-upload fs-1 text-muted d-block mb-2"></i>
                        <p class="mb-1">Перетащите файлы сюда или нажмите для выбора</p>
                    </div>
                    <input type="file" id="contract_project" name="contract_project[]" 
                           multiple accept="${FINBUILD_APP_DOC_ACCEPT}" 
                           style="display: none;" onchange="handleFileSelection(this)">
                    <div class="file-list" id="contract_project_list"></div>
                </div>
                
                <!-- Для суммы >= 10 млн -->
                <div class="mb-3 conditional-doc" data-condition='{"min_amount": 10000000}'>
                    <label class="form-label fw-bold">Реестр контрактов</label>
                    <div class="file-upload-area" onclick="document.getElementById('contract_register').click()">
                        <i class="bi bi-cloud-upload fs-1 text-muted d-block mb-2"></i>
                        <p class="mb-1">Перетащите файлы сюда или нажмите для выбора</p>
                    </div>
                    <input type="file" id="contract_register" name="contract_register[]" 
                           multiple accept="${FINBUILD_APP_DOC_ACCEPT}" 
                           style="display: none;" onchange="handleFileSelection(this)">
                    <div class="file-list" id="contract_register_list"></div>
                </div>
            `;
        } else if (productType === 'credit') {
            html += `
                <!-- Для кредита суммы >= 10 млн -->
                <div class="mb-3 conditional-doc" data-condition='{"min_amount": 10000000}'>
                    <label class="form-label fw-bold">Кредитный портфель</label>
                    <div class="file-upload-area" onclick="document.getElementById('credit_portfolio').click()">
                        <i class="bi bi-cloud-upload fs-1 text-muted d-block mb-2"></i>
                        <p class="mb-1">Перетащите файлы сюда или нажмите для выбора</p>
                    </div>
                    <input type="file" id="credit_portfolio" name="credit_portfolio[]" 
                           multiple accept="${FINBUILD_APP_DOC_ACCEPT}" 
                           style="display: none;" onchange="handleFileSelection(this)">
                    <div class="file-list" id="credit_portfolio_list"></div>
                </div>
            `;
        }
        
        return html;
    }
    
    // Инициализация загрузки файлов
    function initializeFileUploads() {
        const uploadAreas = document.querySelectorAll('.file-upload-area');
        
        uploadAreas.forEach(area => {
            // Drag and drop
            area.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('dragover');
            });
            
            area.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
            });
            
            area.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const input = this.parentElement.querySelector('input[type="file"]');
                    input.files = files;
                    handleFileSelection(input);
                }
            });
        });
    }
});

// Глобальные функции для обработки файлов
function handleFileSelection(input) {
    const fileList = document.getElementById(input.id + '_list');
    fileList.innerHTML = '';
    
    Array.from(input.files).forEach((file, index) => {
        const fileItem = document.createElement('div');
        fileItem.className = 'file-item';
        fileItem.innerHTML = `
            <div class="file-info">
                <i class="bi bi-file-earmark file-icon"></i>
                <div>
                    <div class="fw-medium">${file.name}</div>
                    <small class="text-muted">${formatFileSize(file.size)}</small>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeFile(this, ${index})">
                <i class="bi bi-x"></i>
            </button>
        `;
        fileList.appendChild(fileItem);
    });
}

function removeFile(button, index) {
    const fileItem = button.closest('.file-item');
    const inputId = fileItem.closest('.file-list').id.replace('_list', '');
    const input = document.getElementById(inputId);
    
    // Создаем новый FileList без удаленного файла
    const dt = new DataTransfer();
    Array.from(input.files).forEach((file, i) => {
        if (i !== index) {
            dt.items.add(file);
        }
    });
    input.files = dt.files;
    
    fileItem.remove();
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Добавление обработчиков для динамического обновления документов
function addDynamicDocumentListeners(productType) {
    // Следим за изменением суммы
    const amountInput = document.querySelector('input[name="amount"]');
    if (amountInput) {
        amountInput.addEventListener('input', function() {
            updateDocumentsSection(productType);
        });
    }
    
    // Следим за изменением вида ФЗ (только для БГ)
    if (productType === 'bg') {
        const fzTypeSelect = document.querySelector('select[name="fz_type"]');
        if (fzTypeSelect) {
            fzTypeSelect.addEventListener('change', function() {
                updateDocumentsSection(productType);
            });
        }
    }
}

// Показ секции комментария
function showCommentSection() {
    const commentSection = document.getElementById('commentSection');
    if (commentSection) {
        commentSection.style.display = 'block';
        commentSection.scrollIntoView({ behavior: 'smooth' });
    }
}


</script>
<script>
// Валидация формы
document.getElementById('applicationForm').addEventListener('submit', function(e) {
    if (!validateForm()) {
        e.preventDefault();
        return false;
    }
});

function validateForm() {
    const productType = document.getElementById('productType').value;
    const inn = document.getElementById('inn').value;
    const companyName = document.getElementById('companyName').value;
    const contactPhone = document.getElementById('contactPhone'); // Добавлено
    
    let isValid = true;
    
    // Очищаем предыдущие ошибки
    clearErrors();
    
    // Проверка обязательных полей
    if (!productType) {
        showError('productType', 'Выберите тип продукта');
        isValid = false;
    }
    
    if (!inn) {
        showError('inn', 'Введите ИНН организации');
        isValid = false;
    } else if (!/^\d{10,12}$/.test(inn)) {
        showError('inn', 'ИНН должен состоять из 10-12 цифр');
        isValid = false;
    }
    
    if (!companyName) {
        showError('companyName', 'Введите название организации');
        isValid = false;
    }
    // Для менеджера обязательно выбрать владельца заявки (клиент или партнёр)
    const targetUserIdEl = document.getElementById('target_user_id');
    if (targetUserIdEl && !targetUserIdEl.value.trim()) {
        showError('target_user_id', 'Выберите клиента или партнёра');
        isValid = false;
    }
    // Проверка телефона
    if (contactPhone && !contactPhone.value.trim()) {
        showError('contactPhone', 'Введите телефон для связи');
        isValid = false;
    } else if (contactPhone && contactPhone.value.trim()) {
        // Дополнительная валидация формата телефона
        const phoneRegex = /^[\d\s\-\+\(\)]{10,20}$/;
        if (!phoneRegex.test(contactPhone.value.trim())) {
            showError('contactPhone', 'Введите корректный номер телефона');
            isValid = false;
        }
    }
    // Валидация в зависимости от типа продукта
    if (productType === 'bg') {
        const fzType = document.querySelector('select[name="fz_type"]');
        const guaranteeType = document.querySelector('select[name="guarantee_type"]');
        const amount = document.querySelector('input[name="amount"]');
    const termBg = document.querySelector('input[name="term_bg"]');
        
        if (fzType && !fzType.value) {
            showError(fzType, 'Выберите вид ФЗ');
            isValid = false;
        }
        
        if (guaranteeType && !guaranteeType.value) {
            showError(guaranteeType, 'Выберите вид гарантии');
            isValid = false;
        }
        
        if (amount && !amount.value) {
            showError(amount, 'Введите сумму гарантии');
            isValid = false;
        } else if (amount && amount.value && amount.value <= 0) {
            showError(amount, 'Сумма должна быть больше 0');
            isValid = false;
        }
        
        if (termBg && !termBg.value) {
        showError(termBg, 'Выберите дату окончания гарантии');
        isValid = false;
    } else if (termBg && termBg.value) {
        const selectedDate = new Date(termBg.value);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        if (selectedDate <= today) {
            showError(termBg, 'Дата должна быть в будущем');
            isValid = false;
        }
    }
        
    } else if (productType === 'credit') {
        const loanType = document.querySelector('select[name="loan_type"]');
        const amount = document.querySelector('input[name="amount"]');
        const term = document.querySelector('input[name="term"]');
        
        if (loanType && !loanType.value) {
            showError(loanType, 'Выберите вид кредита');
            isValid = false;
        }
        
        if (amount && !amount.value) {
            showError(amount, 'Введите сумму кредита');
            isValid = false;
        } else if (amount && amount.value && amount.value <= 0) {
            showError(amount, 'Сумма должна быть больше 0');
            isValid = false;
        }
        
        if (term && !term.value) {
            showError(term, 'Введите срок кредита');
            isValid = false;
        } else if (term && term.value && (term.value < 1 || term.value > 120)) {
            showError(term, 'Срок должен быть от 1 до 120 месяцев');
            isValid = false;
        }
    }
    
    // Проверка файлов
    const financialReport = document.getElementById('financial_report');
    if (financialReport && financialReport.files.length === 0) {
        showError(financialReport, 'Загрузите бухгалтерскую отчетность');
        isValid = false;
    }
    
    if (!isValid) {
        // Прокрутка к первой ошибке
        const firstError = document.querySelector('.is-invalid');
        if (firstError) {
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        
        // Показываем уведомление
        showNotification('Заполните все обязательные поля', 'error');
    }
    
    return isValid;
}

function showError(elementOrId, message) {
    const element = typeof elementOrId === 'string' ? document.getElementById(elementOrId) : elementOrId;
    if (!element) return;
    
    // Добавляем класс ошибки
    element.classList.add('is-invalid');
    
    // Создаем или обновляем сообщение об ошибке
    let errorElement = element.parentNode.querySelector('.invalid-feedback');
    if (!errorElement) {
        errorElement = document.createElement('div');
        errorElement.className = 'invalid-feedback';
        element.parentNode.appendChild(errorElement);
    }
    errorElement.textContent = message;
    
    // Подсвечиваем label
    const label = element.parentNode.querySelector('label');
    if (label) {
        label.classList.add('text-danger');
    }
}

function clearErrors() {
    // Убираем классы ошибок
    document.querySelectorAll('.is-invalid').forEach(el => {
        el.classList.remove('is-invalid');
    });
    
    // Убираем сообщения об ошибках
    document.querySelectorAll('.invalid-feedback').forEach(el => {
        el.remove();
    });
    
    // Убираем подсветку label
    document.querySelectorAll('label.text-danger').forEach(el => {
        el.classList.remove('text-danger');
    });
}

function showNotification(message, type = 'error') {
    // Создаем уведомление
    const notification = document.createElement('div');
    notification.className = `alert alert-${type === 'error' ? 'danger' : 'success'} alert-dismissible fade show`;
    notification.innerHTML = `
        <i class="bi bi-${type === 'error' ? 'exclamation-triangle' : 'check-circle'} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // Добавляем в начало формы
    const form = document.getElementById('applicationForm');
    form.parentNode.insertBefore(notification, form);
    
    // Автоматически скрываем через 5 секунд
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 5000);
}

// Валидация на лету
document.addEventListener('DOMContentLoaded', function() {
    // Валидация ИНН
    const innInput = document.getElementById('inn');
    if (innInput) {
        innInput.addEventListener('blur', function() {
            if (this.value && !/^\d{10,12}$/.test(this.value)) {
                showError('inn', 'ИНН должен состоять из 10-12 цифр');
            } else {
                clearFieldError('inn');
            }
        });
    }
    
    // Валидация суммы
    document.addEventListener('input', function(e) {
        if (e.target.name === 'amount' || e.target.name === 'credit_amount') {
            if (e.target.value && e.target.value <= 0) {
                showError(e.target, 'Сумма должна быть больше 0');
            } else {
                clearFieldError(e.target);
            }
        }
        
        if (e.target.name === 'term') {
            const maxTerm = document.getElementById('productType').value === 'bg' ? 60 : 120;
            if (e.target.value && (e.target.value < 1 || e.target.value > maxTerm)) {
                showError(e.target, `Срок должен быть от 1 до ${maxTerm} месяцев`);
            } else {
                clearFieldError(e.target);
            }
        }
    });

    // Валидация телефона
const contactPhoneInput = document.querySelector('input[name="contact_phone"]');
if (contactPhoneInput) {
    contactPhoneInput.addEventListener('blur', function() {
        if (!this.value.trim()) {
            showError(this, 'Введите телефон для связи');
        } else {
            const phoneRegex = /^[\d\s\-\+\(\)]{10,20}$/;
            if (!phoneRegex.test(this.value.trim())) {
                showError(this, 'Введите корректный номер телефона');
            } else {
                clearFieldError(this);
            }
        }
    });
}
});

function clearFieldError(elementOrId) {
    const element = typeof elementOrId === 'string' ? document.getElementById(elementOrId) : elementOrId;
    if (!element) return;
    
    element.classList.remove('is-invalid');
    const errorElement = element.parentNode.querySelector('.invalid-feedback');
    if (errorElement) {
        errorElement.remove();
    }
    
    const label = element.parentNode.querySelector('label');
    if (label) {
        label.classList.remove('text-danger');
    }
}

// Динамическая валидация при изменении типа продукта
document.getElementById('productType').addEventListener('change', function() {
    clearErrors();
});



</script>

<style>
/* Стили для валидации */
.is-invalid {
    border-color: #dc3545 !important;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
}

.invalid-feedback {
    display: block;
    width: 100%;
    margin-top: 0.25rem;
    font-size: 0.875rem;
    color: #dc3545;
}

.alert {
    border-radius: 12px;
    border: none;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.alert-danger {
    background: linear-gradient(135deg, #f8d7da, #f5c6cb);
    color: #721c24;
    border-left: 4px solid #dc3545;
}

.alert-success {
    background: linear-gradient(135deg, #d4edda, #c3e6cb);
    color: #155724;
    border-left: 4px solid #28a745;
}

/* Подсветка обязательных полей */
.required-field label::after {
    content: " *";
    color: #dc3545;
}

/* Анимация для ошибок */
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-5px); }
    75% { transform: translateX(5px); }
}

.is-invalid {
    animation: shake 0.5s ease-in-out;
}
</style>
<script>
$(document).ready(function() {
    // Инициализация Select2
    $('#target_user_id').select2({
        theme: "bootstrap-5",
        language: "ru",
        placeholder: "Начните вводить имя, фамилию, компанию или ИНН",
        allowClear: true,
        width: '100%'
    });

    // Автозаполнение контактов (ИСПРАВЛЕННАЯ ЛОГИКА)
    $('#target_user_id').on('change', function() {
        // Берем данные напрямую из выбранного option через jQuery
        var selectedOption = $(this).find(':selected');
        
        var contactName = selectedOption.data('contact');
        var contactPhone = selectedOption.data('phone');
        var selectedRole = selectedOption.data('role');
        var selectedInn = selectedOption.data('inn');
        var innInput = document.getElementById('inn');
        var companyNameInput = document.getElementById('companyName');
        
  
        
      // Используем оператор || '' чтобы вставить пустую строку, если данных нет
        $('input[name="contact_name"]').val(contactName || '');
        $('input[name="contact_phone"]').val(contactPhone || '');

        if (innInput) {
            if (!selectedOption.val()) {
                innInput.value = '';
                if (companyNameInput) companyNameInput.value = '';
                innInput.readOnly = false;
            } else if (selectedRole === 'client' && selectedInn) {
                innInput.value = selectedInn;
                innInput.readOnly = true;
                if (window.fetchCompanyNameByInn) {
                    window.fetchCompanyNameByInn(selectedInn, false);
                }
            } else if (selectedRole === 'partner') {
                innInput.value = '';
                if (companyNameInput) companyNameInput.value = '';
                innInput.readOnly = false;
            }
        }

        $('#target_user_id').select2('close');
    });

    
});
</script>


<?php require_once 'footer.php'; ?>