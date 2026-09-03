<?php
/**
 * Права доступа — только для руководителей (access_rights.manage).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

checkAuth();
$currentUser = getCurrentUser();
if (!finbuild_can('access_rights.manage', $currentUser)) {
    header('Location: index.php');
    exit;
}

$pdo = getPDO();
$config = finbuild_access_config($pdo);
$defs = finbuild_access_permission_defs();
$roleKeys = finbuild_access_role_keys();

$updatedMeta = null;
try {
    $metaStmt = $pdo->prepare(
        'SELECT s.updated_at, u.first_name, u.last_name
         FROM system_settings s
         LEFT JOIN users u ON u.id = s.updated_by
         WHERE s.setting_key = ?
         LIMIT 1'
    );
    $metaStmt->execute([FINBUILD_ACCESS_SETTING_KEY]);
    $updatedMeta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $updatedMeta = null;
}

require_once __DIR__ . '/header.php';

$groups = [];
foreach ($defs as $key => $def) {
    $groups[$def['group']][$key] = $def;
}
?>

<style>
.access-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}
.access-matrix-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.access-matrix {
    min-width: 920px;
    font-size: 0.875rem;
    margin-bottom: 0;
}
.access-matrix th,
.access-matrix td {
    vertical-align: middle;
    text-align: center;
    padding: 0.65rem 0.5rem;
}
.access-matrix th.perm-col,
.access-matrix td.perm-col {
    text-align: left;
    min-width: 220px;
    position: sticky;
    left: 0;
    background: #fff;
    z-index: 1;
}
.access-matrix thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #e9ecef;
    font-weight: 600;
    color: #495057;
    white-space: nowrap;
}
.access-matrix .group-row td {
    background: #f1f3f5;
    font-weight: 600;
    text-align: left;
    color: #343a40;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.access-matrix .form-check-input {
    width: 1.15rem;
    height: 1.15rem;
    margin: 0;
    cursor: pointer;
}
.access-matrix .form-check-input:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.role-desc-card {
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 1rem 1.1rem;
    height: 100%;
    background: #fafbfc;
}
.role-desc-card .form-control,
.role-desc-card .form-label {
    font-size: 0.875rem;
}
.perm-hint {
    display: block;
    font-size: 0.75rem;
    color: #6c757d;
    font-weight: 400;
    margin-top: 0.15rem;
}
.access-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
    justify-content: space-between;
}
.access-status {
    font-size: 0.85rem;
    color: #6c757d;
}
</style>

<div class="page-header mb-4">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="h3 mb-0">Права доступа</h1>
            <p class="text-muted mb-0">Матрица прав по ролям и описания ролей</p>
        </div>
        <div class="col-auto access-toolbar">
            <span class="access-status" id="accessSaveStatus">
                <?php if ($updatedMeta && !empty($updatedMeta['updated_at'])): ?>
                    Изменено: <?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $updatedMeta['updated_at']))) ?>
                    <?php
                    $by = trim(((string) ($updatedMeta['first_name'] ?? '')) . ' ' . ((string) ($updatedMeta['last_name'] ?? '')));
                    if ($by !== ''): ?> · <?= htmlspecialchars($by) ?><?php endif; ?>
                <?php else: ?>
                    Используются значения по умолчанию
                <?php endif; ?>
            </span>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="accessResetBtn">Сбросить</button>
            <button type="button" class="btn btn-primary btn-sm" id="accessSaveBtn">
                <i class="bi bi-check2 me-1"></i>Сохранить
            </button>
        </div>
    </div>
</div>

<div class="access-card">
    <h2 class="h5 mb-3">Роли</h2>
    <p class="text-muted small mb-3">Краткое описание отображается в интерфейсе и помогает команде понимать назначение роли.</p>
    <div class="row g-3" id="roleDescriptions">
        <?php foreach ($roleKeys as $roleKey):
            $meta = $config['roles'][$roleKey] ?? ['label' => $roleKey, 'description' => ''];
            ?>
            <div class="col-md-6 col-xl-4">
                <div class="role-desc-card" data-role="<?= htmlspecialchars($roleKey) ?>">
                    <label class="form-label fw-semibold">Название</label>
                    <input type="text" class="form-control mb-2 role-label-input"
                           maxlength="80"
                           value="<?= htmlspecialchars((string) $meta['label']) ?>">
                    <label class="form-label fw-semibold">Описание</label>
                    <textarea class="form-control role-desc-input" rows="3" maxlength="500"><?= htmlspecialchars((string) $meta['description']) ?></textarea>
                    <div class="form-text mt-1"><code><?= htmlspecialchars($roleKey) ?></code></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="access-card">
    <h2 class="h5 mb-2">Матрица прав</h2>
    <p class="text-muted small mb-3">Отметьте, каким ролям доступен раздел или функция. Серые галочки у руководителя нельзя снять — это защита от блокировки администрирования.</p>
    <div class="access-matrix-wrap">
        <table class="table table-bordered access-matrix" id="accessMatrix">
            <thead>
                <tr>
                    <th class="perm-col">Раздел / функция</th>
                    <?php foreach ($roleKeys as $roleKey): ?>
                        <th title="<?= htmlspecialchars($roleKey) ?>">
                            <?= htmlspecialchars((string) ($config['roles'][$roleKey]['label'] ?? $roleKey)) ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $groupName => $perms): ?>
                    <tr class="group-row">
                        <td colspan="<?= 1 + count($roleKeys) ?>"><?= htmlspecialchars($groupName) ?></td>
                    </tr>
                    <?php foreach ($perms as $permKey => $def): ?>
                        <tr data-permission="<?= htmlspecialchars($permKey) ?>">
                            <td class="perm-col">
                                <strong><?= htmlspecialchars($def['label']) ?></strong>
                                <?php if (!empty($def['hint'])): ?>
                                    <span class="perm-hint"><?= htmlspecialchars($def['hint']) ?></span>
                                <?php endif; ?>
                            </td>
                            <?php foreach ($roleKeys as $roleKey):
                                $checked = !empty($config['matrix'][$permKey][$roleKey]);
                                $locked = !empty($def['lock_director']) && $roleKey === 'director';
                                ?>
                                <td>
                                    <input type="checkbox"
                                           class="form-check-input matrix-check"
                                           data-perm="<?= htmlspecialchars($permKey) ?>"
                                           data-role="<?= htmlspecialchars($roleKey) ?>"
                                           <?= $checked ? 'checked' : '' ?>
                                           <?= $locked ? 'disabled' : '' ?>
                                           title="<?= $locked ? 'Нельзя отключить у руководителя' : '' ?>">
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const saveBtn = document.getElementById('accessSaveBtn');
    const resetBtn = document.getElementById('accessResetBtn');
    const statusEl = document.getElementById('accessSaveStatus');

    function collectPayload() {
        const roles = {};
        document.querySelectorAll('#roleDescriptions .role-desc-card').forEach(function (card) {
            const key = card.getAttribute('data-role');
            roles[key] = {
                label: (card.querySelector('.role-label-input') || {}).value || '',
                description: (card.querySelector('.role-desc-input') || {}).value || ''
            };
        });
        const matrix = {};
        document.querySelectorAll('.matrix-check').forEach(function (cb) {
            const perm = cb.getAttribute('data-perm');
            const role = cb.getAttribute('data-role');
            if (!matrix[perm]) matrix[perm] = {};
            matrix[perm][role] = !!cb.checked;
            if (cb.disabled && role === 'director') {
                matrix[perm][role] = true;
            }
        });
        return { roles: roles, matrix: matrix };
    }

    function setStatus(text, isError) {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.style.color = isError ? '#dc3545' : '#6c757d';
    }

    async function postAction(action, extra) {
        const body = Object.assign({ action: action }, extra || {});
        if (action === 'save') {
            Object.assign(body, collectPayload());
        }
        const res = await fetch('api_access_rights.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        });
        return res.json();
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', async function () {
            saveBtn.disabled = true;
            setStatus('Сохранение…');
            try {
                const data = await postAction('save');
                if (!data.success) {
                    setStatus(data.error || 'Ошибка сохранения', true);
                    return;
                }
                setStatus('Сохранено: ' + new Date().toLocaleString('ru-RU'));
            } catch (e) {
                setStatus('Сеть или сервер недоступны', true);
            } finally {
                saveBtn.disabled = false;
            }
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', async function () {
            if (!confirm('Сбросить матрицу и описания ролей к значениям по умолчанию?')) {
                return;
            }
            resetBtn.disabled = true;
            setStatus('Сброс…');
            try {
                const data = await postAction('reset');
                if (!data.success) {
                    setStatus(data.error || 'Ошибка сброса', true);
                    return;
                }
                window.location.reload();
            } catch (e) {
                setStatus('Сеть или сервер недоступны', true);
            } finally {
                resetBtn.disabled = false;
            }
        });
    }
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
