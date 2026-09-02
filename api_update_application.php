<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'client') !== 'manager') {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
    exit;
}

$applicationId = intval($_POST['application_id'] ?? 0);
$field = $_POST['field'] ?? '';
$value = $_POST['value'] ?? '';

if (!$applicationId || empty($field)) {
    echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
    exit;
}

// Разрешенные поля для редактирования
$allowedFields = [
    'company_name', 'inn', 'fz_type', 'guarantee_type', 'loan_type',
    'amount', 'term', 'term_bg', 'purchase_number', 'purchase_link', 'contract_subject', 'declined_banks',
    'contract_price', 'customer_inn', 'customer_name',
    'contact_name', 'contact_phone', 'comment', 'is_extension', 'is_replacement',
    'assigned_to', // Ответственный (ID менеджера или пусто = null)
    'guarantee_provision_deadline',
    'collateral_transport_enabled', 'collateral_transport_details',
    'collateral_real_estate_enabled', 'collateral_real_estate_details',
    'collateral_deposit_note_enabled', 'collateral_deposit_note_details',
    'collateral_third_party_guarantee_enabled', 'collateral_third_party_guarantee_details',
];

if (!in_array($field, $allowedFields)) {
    echo json_encode(['success' => false, 'error' => 'Недопустимое поле для редактирования']);
    exit;
}

// Ограниченный менеджер (submanager) не может менять ответственного
if ($field === 'assigned_to' && finbuild_is_submanager()) {
    echo json_encode(['success' => false, 'error' => 'Недостаточно прав для смены ответственного']);
    exit;
}

try {
    $pdo = getPDO();
    
    // Проверяем существование заявки
    $stmt = $pdo->prepare("SELECT * FROM applications WHERE id = ?");
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch();
    
    if (!$application) {
        echo json_encode(['success' => false, 'error' => 'Заявка не найдена']);
        exit;
    }

    $bgOnlyFields = [
        'guarantee_provision_deadline',
        'collateral_transport_enabled', 'collateral_transport_details',
        'collateral_real_estate_enabled', 'collateral_real_estate_details',
        'collateral_deposit_note_enabled', 'collateral_deposit_note_details',
        'collateral_third_party_guarantee_enabled', 'collateral_third_party_guarantee_details',
    ];
    if (in_array($field, $bgOnlyFields, true) && ($application['product_type'] ?? '') !== 'bg') {
        echo json_encode(['success' => false, 'error' => 'Поле доступно только для заявок на банковскую гарантию']);
        exit;
    }

    if ($field === 'term_bg' && $value) {
    // Проверяем, что это заявка на БГ
    if ($application['product_type'] === 'bg') {
        // Рассчитываем количество месяцев
        $currentDate = new DateTime();
        $endDate = new DateTime($value);
        $interval = $currentDate->diff($endDate);
        $months = $interval->y * 12 + $interval->m;
        if ($interval->d > 0) {
            $months++;
        }
        
        // Обновляем оба поля
        $stmt = $pdo->prepare("UPDATE applications SET term_bg = ?, term = ? WHERE id = ?");
        $stmt->execute([$value, $months, $applicationId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Дата и срок гарантии обновлены'
        ]);
        exit;
    }
}
    // Подготавливаем значение в зависимости от типа поля
    $preparedValue = $value;
    if ($field === 'amount' || $field === 'contract_price') {
        $preparedValue = $value ? floatval($value) : null;
    } elseif ($field === 'term') {
        $preparedValue = $value ? intval($value) : null;
    } elseif ($field === 'is_extension' || $field === 'is_replacement') {
        $preparedValue = ($value === '1' || $value === 1 || $value === 'true') ? 1 : 0;
    } elseif (
        $field === 'collateral_transport_enabled'
        || $field === 'collateral_real_estate_enabled'
        || $field === 'collateral_deposit_note_enabled'
        || $field === 'collateral_third_party_guarantee_enabled'
    ) {
        $preparedValue = ($value === '1' || $value === 1 || $value === 'true') ? 1 : 0;
    } elseif ($field === 'assigned_to') {
        // Ответственный: пусто = null, иначе ID пользователя (должен быть менеджер)
        $preparedValue = ($value === '' || $value === null) ? null : (int)$value;
        if ($preparedValue !== null) {
            $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'manager' LIMIT 1");
            $check->execute([$preparedValue]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Ответственным может быть только менеджер']);
                exit;
            }
        }
    } elseif ($value === '') {
        $preparedValue = null;
    }
    if ($field === 'term' && $application['product_type'] === 'bg') {
    echo json_encode(['success' => false, 'error' => 'Для БГ редактируется только дата окончания']);
    exit;
}


    
    // Старое значение ответственного — для уведомления «вам назначили заявку»
    $prevAssigned = isset($application['assigned_to']) ? $application['assigned_to'] : null;

    // Обновляем поле
    $stmt = $pdo->prepare("UPDATE applications SET $field = ? WHERE id = ?");
    $stmt->execute([$preparedValue, $applicationId]);

    // Если галочку обеспечения сняли — очищаем "детали" (чтобы не висели старые описания)
    if (($field === 'collateral_transport_enabled' && (int)$preparedValue === 0)
        || ($field === 'collateral_real_estate_enabled' && (int)$preparedValue === 0)
        || ($field === 'collateral_deposit_note_enabled' && (int)$preparedValue === 0)
        || ($field === 'collateral_third_party_guarantee_enabled' && (int)$preparedValue === 0)
    ) {
        $map = [
            'collateral_transport_enabled' => 'collateral_transport_details',
            'collateral_real_estate_enabled' => 'collateral_real_estate_details',
            'collateral_deposit_note_enabled' => 'collateral_deposit_note_details',
            'collateral_third_party_guarantee_enabled' => 'collateral_third_party_guarantee_details',
        ];
        $detailsField = $map[$field] ?? '';
        if ($detailsField !== '') {
            $pdo->prepare("UPDATE applications SET $detailsField = NULL WHERE id = ?")->execute([$applicationId]);
        }
    }

    if ($field === 'assigned_to' && $preparedValue !== null && (int) $preparedValue !== (int) $prevAssigned) {
        require_once __DIR__ . '/includes/notification_events.php';
        notify_manager_assigned($pdo, $applicationId, (int) $preparedValue);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Поле успешно обновлено'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка сервера: ' . $e->getMessage()]);
}
?>