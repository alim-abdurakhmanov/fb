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
    min-width: 960px;
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
    min-width: 240px;
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
.access-na {
    color: #adb5bd;
    font-size: 0.85rem;
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
            <p class="text-muted mb-0">Матрица прав по ролям</p>
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
    <h2 class="h5 mb-2">Матрица прав</h2>
    <p class="text-muted small mb-3">
        Отметьте, каким ролям доступен раздел или функция.
        «—» означает, что право к роли не применяется.
        Серые галочки у руководителя нельзя снять.
        Редактирование автоматически включает просмотр.
    </p>
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
                    <?php foreach ($perms as $permKey => $def):
                        $applicable = $def['applicable_roles'] ?? null;
                        ?>
                        <tr data-permission="<?= htmlspecialchars($permKey) ?>">
                            <td class="perm-col">
                                <strong><?= htmlspecialchars($def['label']) ?></strong>
                                <?php if (!empty($def['hint'])): ?>
                                    <span class="perm-hint"><?= htmlspecialchars($def['hint']) ?></span>
                                <?php endif; ?>
                            </td>
                            <?php foreach ($roleKeys as $roleKey):
                                $applies = $applicable === null || in_array($roleKey, $applicable, true);
                                $checked = !empty($config['matrix'][$permKey][$roleKey]);
                                $locked = !empty($def['lock_director']) && $roleKey === 'director';
                                ?>
                                <td>
                                    <?php if (!$applies): ?>
                                        <span class="access-na" title="Не применяется к этой роли">—</span>
                                    <?php else: ?>
                                        <input type="checkbox"
                                               class="form-check-input matrix-check"
                                               data-perm="<?= htmlspecialchars($permKey) ?>"
                                               data-role="<?= htmlspecialchars($roleKey) ?>"
                                               <?= $checked ? 'checked' : '' ?>
                                               <?= $locked ? 'disabled' : '' ?>
                                               title="<?= $locked ? 'Нельзя отключить у руководителя' : '' ?>">
                                    <?php endif; ?>
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
    const roleLabels = <?= json_encode(array_map(static function ($m) {
        return ['label' => (string) ($m['label'] ?? ''), 'description' => ''];
    }, $config['roles']), JSON_UNESCAPED_UNICODE) ?>;

    function collectPayload() {
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
        // Редактирование → просмотр
        ['structure', 'roadmap'].forEach(function (base) {
            const viewKey = base + '.view';
            const editKey = base + '.edit';
            if (!matrix[viewKey]) matrix[viewKey] = {};
            if (!matrix[editKey]) return;
            Object.keys(matrix[editKey]).forEach(function (role) {
                if (matrix[editKey][role]) matrix[viewKey][role] = true;
            });
        });
        return { roles: roleLabels, matrix: matrix };
    }

    function setStatus(text, isError) {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.style.color = isError ? '#dc3545' : '#6c757d';
    }

    async function postAction(action) {
        const body = { action: action };
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

    // Синхронизация: снятие view снимает edit; включение edit включает view
    document.querySelectorAll('.matrix-check').forEach(function (cb) {
        cb.addEventListener('change', function () {
            const perm = cb.getAttribute('data-perm') || '';
            const role = cb.getAttribute('data-role') || '';
            const m = perm.match(/^(structure|roadmap)\.(view|edit)$/);
            if (!m) return;
            const base = m[1];
            const kind = m[2];
            const viewCb = document.querySelector('.matrix-check[data-perm="' + base + '.view"][data-role="' + role + '"]');
            const editCb = document.querySelector('.matrix-check[data-perm="' + base + '.edit"][data-role="' + role + '"]');
            if (!viewCb || !editCb) return;
            if (kind === 'edit' && cb.checked) {
                viewCb.checked = true;
            }
            if (kind === 'view' && !cb.checked) {
                editCb.checked = false;
            }
        });
    });

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
            if (!confirm('Сбросить матрицу прав к значениям по умолчанию?')) {
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
