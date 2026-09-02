<?php
$current_page = 'support';
require_once 'header.php';

$pdo = getPDO();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'client';
$isSupportUser = defined('SUPPORT_USER_ID') && (int)SUPPORT_USER_ID === (int)$userId;
$statusFilter = $_GET['status'] ?? '';
$ticketId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$validStatuses = ['new', 'open', 'closed'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$where = $isSupportUser ? '1=1' : 'st.created_by = :user_id';
$params = $isSupportUser ? [] : [':user_id' => $userId];
if ($statusFilter) {
    $where .= ' AND st.status = :status';
    $params[':status'] = $statusFilter;
}

$ticketsStmt = $pdo->prepare("
    SELECT 
        st.*,
        u.first_name,
        u.last_name,
        u.company_name,
        MAX(sm.created_at) AS last_message_at,
        (SELECT sm2.message FROM support_messages sm2 WHERE sm2.ticket_id = st.id ORDER BY sm2.created_at DESC LIMIT 1) AS last_message,
        (SELECT COUNT(*) FROM support_messages sm3 WHERE sm3.ticket_id = st.id AND sm3.is_read = 0 AND sm3.author_id != :current_user) AS unread_count
    FROM support_tickets st
    LEFT JOIN users u ON u.id = st.created_by
    LEFT JOIN support_messages sm ON sm.ticket_id = st.id
    WHERE $where
    GROUP BY st.id
    ORDER BY st.updated_at DESC
");
$ticketsStmt->bindValue(':current_user', $userId, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $ticketsStmt->bindValue($key, $value);
}
$ticketsStmt->execute();
$tickets = $ticketsStmt->fetchAll();

$activeTicket = null;
$messages = [];
if ($ticketId) {
    $ticketStmt = $pdo->prepare("
        SELECT st.*, u.first_name, u.last_name, u.company_name
        FROM support_tickets st
        LEFT JOIN users u ON u.id = st.created_by
        WHERE st.id = ?
    ");
    $ticketStmt->execute([$ticketId]);
    $activeTicket = $ticketStmt->fetch();

    if ($activeTicket) {
        $canView = $isSupportUser || (int)$activeTicket['created_by'] === (int)$userId;
        if (!$canView) {
            $activeTicket = null;
        } else {
            $messagesStmt = $pdo->prepare("
                SELECT sm.*, u.first_name, u.last_name
                FROM support_messages sm
                LEFT JOIN users u ON u.id = sm.author_id
                WHERE sm.ticket_id = ?
                ORDER BY sm.created_at ASC
            ");
            $messagesStmt->execute([$ticketId]);
            $messages = $messagesStmt->fetchAll();

            $markStmt = $pdo->prepare("
                UPDATE support_messages 
                SET is_read = 1 
                WHERE ticket_id = ? AND author_id != ?
            ");
            $markStmt->execute([$ticketId, $userId]);
        }
    }
}
?>

<style>
.support-layout {
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 1.5rem;
}

.support-list-card,
.support-thread-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e9ecef;
    box-shadow: 0 2px 14px rgba(0,0,0,0.06);
}

.support-list-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e9ecef;
}

.support-list-item {
    padding: 0.85rem 1.25rem;
    border-bottom: 1px solid #f1f5f9;
    text-decoration: none;
    color: inherit;
    display: block;
}

.support-list-item:last-child {
    border-bottom: none;
}

.support-list-item.active {
    background: #f1f5ff;
}

.support-badge {
    background: #dc3545;
    color: #fff;
    border-radius: 999px;
    font-size: 0.7rem;
    padding: 0.15rem 0.45rem;
}

.support-status {
    font-size: 0.75rem;
    padding: 0.2rem 0.5rem;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

.support-status.new { background: #fff3cd; color: #b58100; }
.support-status.open { background: #e7f1ff; color: #1d5faa; }
.support-status.closed { background: #e9ecef; color: #6c757d; }

.support-thread-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e9ecef;
}

.support-thread-body {
    padding: 1rem 1.25rem;
    max-height: 520px;
    overflow-y: auto;
}

.support-message {
    margin-bottom: 1rem;
}

.support-message .meta {
    font-size: 0.8rem;
    color: #6c757d;
}

.support-message .bubble {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 0.7rem 0.9rem;
    margin-top: 0.35rem;
}

.support-message.me .bubble {
    background: #e8f4ff;
}

.support-thread-footer {
    padding: 1rem 1.25rem;
    border-top: 1px solid #e9ecef;
}

.support-create-card {
    margin: 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #f8fafc;
    padding: 1rem 1rem 1.1rem;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,0.6);
}

.support-create-card .title {
    font-weight: 700;
    font-size: 0.95rem;
    margin-bottom: 0.25rem;
}

.support-create-card .subtitle {
    color: #6c757d;
    font-size: 0.8rem;
    margin-bottom: 0.75rem;
}

.support-create-mobile {
    display: none;
}

.support-create-desktop {
    display: block;
}

.support-empty {
    text-align: center;
    padding: 2rem;
    color: #6c757d;
}

@media (max-width: 768px) {
    .page-header {
        padding: 0.85rem 1rem;
        border-radius: 12px;
    }
    .page-header h1 {
        font-size: 1.15rem;
        font-weight: 700;
        line-height: 1.3;
    }
    .page-header p {
        font-size: 0.8rem;
        line-height: 1.2;
        margin-top: 0.35rem;
    }
    .page-header .row {
        row-gap: 0.5rem;
    }
    .support-layout {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    .support-thread-body {
        max-height: none;
    }
    .support-thread-header h5 {
        font-size: 1rem;
    }
    .support-create-card {
        margin: 0.85rem;
        padding: 0.9rem;
    }
    .support-create-mobile {
        display: block;
        margin: 0.85rem;
    }
    .support-create-desktop {
        display: none;
    }
}
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="h3 mb-0">Техническая поддержка</h1>
            <p class="text-muted mb-0">Создавайте тикеты и общайтесь со службой поддержки</p>
        </div>
    </div>
</div>

<div class="support-layout">
    <div class="support-list-card">
        <div class="support-list-header">
            <div class="d-flex align-items-center justify-content-between">
                <div class="fw-semibold">Тикеты</div>
                <form method="GET" class="d-flex align-items-center gap-2">
                    <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                        <option value="">Все</option>
                        <option value="new" <?= $statusFilter === 'new' ? 'selected' : '' ?>>Новые</option>
                        <option value="open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>Открытые</option>
                        <option value="closed" <?= $statusFilter === 'closed' ? 'selected' : '' ?>>Закрытые</option>
                    </select>
                </form>
            </div>
        </div>
        <?php if (!$isSupportUser): ?>
            <div class="support-create-card support-create-mobile">
                <div class="title">Создать тикет</div>
                <div class="subtitle">Опишите проблему, и мы ответим</div>
                <form id="support-create-form">
                    <div class="mb-2">
                        <label class="form-label">Тема</label>
                        <input type="text" class="form-control" name="subject" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Описание</label>
                        <textarea class="form-control" name="message" rows="3" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Создать тикет</button>
                </form>
            </div>
        <?php endif; ?>
        <div>
            <?php if (empty($tickets)): ?>
                <div class="support-empty">Тикетов пока нет</div>
            <?php else: ?>
                <?php foreach ($tickets as $ticket): ?>
                    <a class="support-list-item <?= $ticketId === (int)$ticket['id'] ? 'active' : '' ?>" href="support.php?id=<?= $ticket['id'] ?>#support-thread-card">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div>
                                <div class="fw-semibold mb-1"><?= htmlspecialchars($ticket['subject']) ?></div>
                                <div class="small text-muted">
                                    <?= htmlspecialchars(trim(($ticket['first_name'] ?? '') . ' ' . ($ticket['last_name'] ?? ''))) ?>
                                    <?php if (!empty($ticket['company_name'])): ?>
                                        · <?= htmlspecialchars($ticket['company_name']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($ticket['unread_count'])): ?>
                                <span class="support-badge"><?= $ticket['unread_count'] ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mt-2 small text-muted">
                            <span class="support-status <?= $ticket['status'] ?>">
                                <?= $ticket['status'] === 'new' ? 'Новый' : ($ticket['status'] === 'open' ? 'Открыт' : 'Закрыт') ?>
                            </span>
                            <span><?= $ticket['last_message_at'] ? date('d.m.Y H:i', strtotime($ticket['last_message_at'])) : date('d.m.Y H:i', strtotime($ticket['created_at'])) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if (!$isSupportUser): ?>
            <div class="support-create-card support-create-desktop">
                <div class="title">Создать тикет</div>
                <div class="subtitle">Опишите проблему, и мы ответим</div>
                <form id="support-create-form-desktop">
                    <div class="mb-2">
                        <label class="form-label">Тема</label>
                        <input type="text" class="form-control" name="subject" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Описание</label>
                        <textarea class="form-control" name="message" rows="3" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Создать тикет</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="support-thread-card" id="support-thread-card">
        <?php if (!$activeTicket): ?>
            <div class="support-empty">Выберите тикет, чтобы открыть переписку</div>
        <?php else: ?>
            <div class="support-thread-header">
                <div class="d-flex align-items-start justify-content-between">
                    <div>
                        <h5 class="mb-1"><?= htmlspecialchars($activeTicket['subject']) ?></h5>
                        <div class="text-muted small">
                            <?= htmlspecialchars(trim(($activeTicket['first_name'] ?? '') . ' ' . ($activeTicket['last_name'] ?? ''))) ?>
                            <?php if (!empty($activeTicket['company_name'])): ?>
                                · <?= htmlspecialchars($activeTicket['company_name']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="support-status <?= $activeTicket['status'] ?>">
                            <?= $activeTicket['status'] === 'new' ? 'Новый' : ($activeTicket['status'] === 'open' ? 'Открыт' : 'Закрыт') ?>
                        </div>
                        <div class="mt-2">
                            <?php if ($activeTicket['status'] !== 'closed' || $isSupportUser): ?>
                                <?php if ($activeTicket['status'] === 'closed'): ?>
                                    <button type="button" class="btn btn-outline-primary btn-sm" data-support-action="open" data-ticket-id="<?= $activeTicket['id'] ?>">Открыть</button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-support-action="close" data-ticket-id="<?= $activeTicket['id'] ?>">Закрыть</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="support-thread-body" id="support-thread-body">
                <?php if (empty($messages)): ?>
                    <div class="support-empty">Сообщений пока нет</div>
                <?php else: ?>
                    <?php foreach ($messages as $message): ?>
                        <div class="support-message <?= (int)$message['author_id'] === (int)$userId ? 'me' : '' ?>">
                            <div class="meta">
                                <?= htmlspecialchars(trim(($message['first_name'] ?? '') . ' ' . ($message['last_name'] ?? ''))) ?>
                                · <?= date('d.m.Y H:i', strtotime($message['created_at'])) ?>
                            </div>
                            <div class="bubble"><?= nl2br(htmlspecialchars($message['message'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="support-thread-footer">
                <?php if ($activeTicket['status'] !== 'closed'): ?>
                    <form id="support-message-form">
                        <input type="hidden" name="ticket_id" value="<?= $activeTicket['id'] ?>">
                        <div class="mb-2">
                            <textarea class="form-control" name="message" rows="3" required placeholder="Напишите сообщение"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Отправить</button>
                    </form>
                <?php else: ?>
                    <div class="text-muted">Тикет закрыт. Открыть может только поддержка.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const createFormMobile = document.getElementById('support-create-form');
    const createFormDesktop = document.getElementById('support-create-form-desktop');
    const messageForm = document.getElementById('support-message-form');

    function handleCreateForm(form) {
        if (form) {
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                const formData = new FormData(form);

                const response = await fetch('api_support_create.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success && data.ticket_id) {
                    window.location.href = 'support.php?id=' + data.ticket_id;
                } else {
                    alert(data.error || 'Ошибка создания тикета');
                }
            });
        }
    }

    handleCreateForm(createFormMobile);
    handleCreateForm(createFormDesktop);

    if (messageForm) {
        messageForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(messageForm);

            const response = await fetch('api_support_message.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                window.location.reload();
            } else {
                alert(data.error || 'Ошибка отправки сообщения');
            }
        });
    }

    document.querySelectorAll('[data-support-action]').forEach(button => {
        button.addEventListener('click', async function() {
            const action = this.dataset.supportAction;
            const ticketId = this.dataset.ticketId;
            const formData = new FormData();
            formData.append('ticket_id', ticketId);
            formData.append('status', action === 'open' ? 'open' : 'closed');

            const response = await fetch('api_support_status.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                window.location.reload();
            } else {
                alert(data.error || 'Ошибка обновления статуса');
            }
        });
    });

    const threadBody = document.getElementById('support-thread-body');
    if (threadBody) {
        threadBody.scrollTop = threadBody.scrollHeight;
    }

});
</script>

<?php require_once 'footer.php'; ?>
