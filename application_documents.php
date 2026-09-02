<?php
$current_page = 'applications';
require_once 'config.php';
require_once __DIR__ . '/includes/application_documents_upload.php';
require_once __DIR__ . '/includes/upload_access.php';
require_once 'header.php';

$pdo = getPDO();
$currentUser = getCurrentUser();
$userRole = $_SESSION['role'] ?? 'client';
$userId = $_SESSION['user_id'];
$userIsAnalystFlag = finbuild_user_is_analyst_flag($currentUser);
$isSubmanager = finbuild_is_submanager($currentUser);

// Проверяем ID заявки
$applicationId = $_GET['id'] ?? 0;
if (!$applicationId) {
    header('Location: applications.php');
    exit();
}

// Получаем данные заявки
$stmt = $pdo->prepare("
    SELECT a.*, u.first_name, u.last_name 
    FROM applications a 
    LEFT JOIN users u ON a.created_by = u.id 
    WHERE a.id = ?
");
$stmt->execute([$applicationId]);
$application = $stmt->fetch();

// Проверяем доступ
$isAnalystView = false;

if (!$application) {
    header('Location: applications.php');
    exit();
}

if (!finbuild_can_access_application($pdo, (int) $applicationId, $userRole, (int) $userId, $userIsAnalystFlag)) {
    header('Location: applications.php');
    exit();
}

// Ограниченный менеджер: только заявки, где он ответственный (или сам создал)
if (finbuild_is_submanager($currentUser)) {
    $ownsApplication = ((int) ($application['created_by'] ?? 0) === (int) $userId)
        || ((int) ($application['assigned_to'] ?? 0) === (int) $userId);
    if (!$ownsApplication) {
        header('Location: applications.php');
        exit();
    }
}

$isAnalystView = finbuild_application_details_analyst_mode(
    $userRole,
    $userIsAnalystFlag,
    (int) $application['created_by'],
    (int) $userId
);

if (finbuild_analyst_cannot_view_failed_application($application, $userRole, $userIsAnalystFlag, (int) $userId)) {
    $redirectScope = finbuild_user_is_analyst_flag($currentUser) && (string) ($_GET['scope'] ?? '') === 'all' ? '?scope=all' : '';
    header('Location: applications.php' . $redirectScope);
    exit();
}

// Получаем документы заявки
$stmt = $pdo->prepare("
    SELECT d.*, u.first_name as uploader_first_name, u.last_name as uploader_last_name
    FROM application_documents d
    LEFT JOIN users u ON d.uploaded_by = u.id
    WHERE d.application_id = ?
    ORDER BY d.created_at DESC
");
$stmt->execute([$applicationId]);
$documents = $stmt->fetchAll();

// Группируем документы по типам
$groupedDocuments = [];
foreach ($documents as $doc) {
    $groupedDocuments[$doc['document_type']][] = $doc;
}

// Функция для форматирования размера файла
function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

// Функция для получения иконки файла
function getFileIcon($fileType) {
    $icons = [
        'pdf' => 'bi-file-earmark-pdf text-danger',
        'word' => 'bi-file-earmark-word text-primary',
        'excel' => 'bi-file-earmark-spreadsheet text-success',
        'image' => 'bi-file-earmark-image text-warning',
        'archive' => 'bi-file-earmark-zip text-secondary',
        'file' => 'bi-file-earmark text-secondary'
    ];
    
    return $icons[$fileType] ?? 'bi-file-earmark text-secondary';
}
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="h3 mb-0">Документы заявки #<?= $application['id'] ?></h1>
            <p class="text-muted mb-0">
                <?= htmlspecialchars($application['company_name']) ?> • 
                ИНН: <?= htmlspecialchars($application['inn']) ?>
            </p>
        </div>
        <div class="col-auto">
            <a href="application_details.php?id=<?= $applicationId ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-2"></i>Назад к заявке
            </a>
            <?php if (!$isAnalystView && $userRole !== 'manager'): ?>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                    <i class="bi bi-cloud-upload me-2"></i>Загрузить документ
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.document-section {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
}

.document-section h5 {
    color: #2c3e50;
    border-bottom: 2px solid #3498db;
    padding-bottom: 0.75rem;
    margin-bottom: 1.5rem;
    font-weight: 600;
}

.document-card {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
    transition: all 0.3s ease;
}

.document-card:hover {
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    border-color: #3498db;
}

.document-icon {
    font-size: 2rem;
    margin-right: 1rem;
}

.document-info {
    flex: 1;
}

.document-actions {
    display: flex;
    gap: 0.5rem;
}



.empty-documents {
    text-align: center;
    padding: 3rem 2rem;
    color: #6c757d;
}

.empty-documents i {
    font-size: 4rem;
    margin-bottom: 1.5rem;
}

/* Убираем стили для approved/pending */
.file-approved, .file-pending {
    border-left: 4px solid #e9ecef; /* нейтральный цвет */
}

.document-card {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
    transition: all 0.3s ease;
    border-left: 4px solid #3498db; /* добавляем синюю полоску для всех */
}

.document-card:hover {
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    border-color: #3498db;
    transform: translateY(-2px);
}
</style>

<div class="row">
    <div class="col-12">
        <?php if (empty($groupedDocuments)): ?>
            <div class="empty-documents">
                <i class="bi bi-folder"></i>
                <h4>Документы не загружены</h4>
                <p class="text-muted">К этой заявке еще не прикреплены документы</p>
                <?php if (!$isAnalystView && $userRole !== 'manager'): ?>
                    <button type="button" class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                        <i class="bi bi-cloud-upload me-2"></i>Загрузить первый документ
                    </button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($groupedDocuments as $documentType => $docs): ?>
                <div class="document-section">
                    <h5>
                        <?= htmlspecialchars($documentType) ?>
                        <span class="badge bg-primary ms-2"><?= count($docs) ?></span>
                    </h5>
                    
                    <?php foreach ($docs as $doc): ?>
                        <div class="document-card d-flex align-items-center <?= $doc['is_approved'] ? 'file-approved' : 'file-pending' ?>">
                            <div class="document-icon">
                                <i class="bi <?= getFileIcon($doc['file_type']) ?>"></i>
                            </div>
                            
                            <div class="document-info">
                                <h6 class="mb-1"><?= htmlspecialchars($doc['original_name']) ?></h6>
                                <div class="text-muted small">
                                    <?= formatFileSize($doc['file_size']) ?> • 
                                    Загружен: <?= date('d.m.Y H:i', strtotime($doc['created_at'])) ?>
                                    <?php if (!$isAnalystView && !$isSubmanager && $doc['uploader_first_name']): ?>
                                        • <?= htmlspecialchars($doc['uploader_first_name'] . ' ' . $doc['uploader_last_name']) ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($doc['description']): ?>
                                    <div class="mt-1">
                                        <small class="text-muted"><?= htmlspecialchars($doc['description']) ?></small>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                        <div class="document-actions">
    <a href="<?= htmlspecialchars(finbuild_upload_file_url('app_doc', (int) $doc['id'])) ?>" class="btn btn-outline-primary btn-sm" target="_blank" download="<?= htmlspecialchars($doc['original_name']) ?>">
        <i class="bi bi-download"></i>
    </a>
    <a href="<?= htmlspecialchars(finbuild_upload_file_url('app_doc', (int) $doc['id'])) ?>" class="btn btn-outline-secondary btn-sm" target="_blank">
        <i class="bi bi-eye"></i>
    </a>
    <?php if ($userRole !== 'manager' || $doc['uploaded_by'] == $userId): ?>
        <button type="button" class="btn btn-outline-danger btn-sm" 
                onclick="deleteDocument(<?= $doc['id'] ?>)">
            <i class="bi bi-trash"></i>
        </button>
    <?php endif; ?>
</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Модальное окно загрузки документа -->
<div class="modal fade" id="uploadDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Загрузка документа</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="api_upload_document.php" enctype="multipart/form-data" id="uploadDocumentForm">
                <input type="hidden" name="application_id" value="<?= $applicationId ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Тип документа</label>
                        <select class="form-select" name="document_type" required>
                            <option value="">Выберите тип документа</option>
                            <option value="Квартальная бухгалтерская отчетность">Квартальная бухгалтерская отчетность</option>
                            <option value="Проект или скан договора/контракта">Проект или скан договора/контракта</option>
                            <option value="Реестр контрактов">Реестр контрактов</option>
                            <option value="Кредитный портфель">Кредитный портфель</option>
                            <option value="Дополнительные документы">Дополнительные документы</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Описание (необязательно)</label>
                        <textarea class="form-control" name="description" rows="2" placeholder="Краткое описание документа"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Файл</label>
                        <input type="file" class="form-control" name="document_file" required 
                               accept="<?= htmlspecialchars(finbuild_application_document_accept_attribute(), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="form-text">Поддерживаемые форматы: <?= htmlspecialchars(finbuild_application_document_upload_hint(), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-cloud-upload me-2"></i>Загрузить
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleDocumentApproval(documentId, newStatus) {
    const statusText = newStatus ? 'одобрен' : 'не одобрен';
    
    if (confirm('Пометить документ как ' + statusText + '?')) {
        fetch('api_toggle_document_approval.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'document_id=' + documentId + '&is_approved=' + newStatus
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Ошибка: ' + data.error);
            }
        })
        .catch(error => {
            alert('Ошибка: ' + error.message);
        });
    }
}

function deleteDocument(documentId) {
    if (confirm('Удалить этот документ?')) {
        fetch('api_delete_document.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'document_id=' + documentId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Ошибка: ' + data.error);
            }
        })
        .catch(error => {
            alert('Ошибка: ' + error.message);
        });
    }
}

// Обработка формы загрузки документа
document.getElementById('uploadDocumentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i> Загрузка...';
    submitBtn.disabled = true;
    
    fetch('api_upload_document.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Документ успешно загружен!');
            location.reload();
        } else {
            alert('Ошибка: ' + data.error);
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    })
    .catch(error => {
        alert('Ошибка загрузки: ' + error.message);
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
});
</script>

<?php require_once 'footer.php'; ?>