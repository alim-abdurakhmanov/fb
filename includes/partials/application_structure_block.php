<?php
/**
 * Блок «Структура заявки» (вкладка или карточка в ЛК банка).
 *
 * @var int         $structureApplicationId
 * @var bool        $structureCanEdit
 * @var array|null  $structureSectionCodes   коды разделов для показа (null — все)
 * @var bool        $structureShowDeleted    показывать удалённые пункты
 * @var bool        $structureShowReadonlyHint подсказка «только просмотр»
 * @var bool        $structureIncludeScript  JS добавления/удаления
 * @var bool        $structureShowItemMeta   подписи «Добавлено» / «Изменено»
 * @var bool        $structureEditOwnOnly    редактировать/удалять только свои пункты
 * @var bool        $structureAllowItemMutate показывать кнопки редактирования/удаления
 * @var bool        $structureProtectBankItems запретить изменение пунктов банка (ЛК менеджера)
 * @var bool        $structureShowItemMetaBankOnly мета только у пунктов, добавленных банком
 * @var int         $structureCurrentUserId  id текущего пользователя (для edit own only)
 * @var array<string, list<array<string, mixed>>> $structureGrouped
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/application_structure.php';

$structureApplicationId = (int) ($structureApplicationId ?? 0);
$structureCanEdit = !empty($structureCanEdit);
$pdoStructure = getPDO();
$structureGrouped = finbuild_application_structure_fetch_grouped(
    $pdoStructure,
    $structureApplicationId,
    false
);
$structureDeletedGrouped = finbuild_application_structure_fetch_grouped(
    $pdoStructure,
    $structureApplicationId,
    true
);
$structureSections = finbuild_structure_sections();
$structureSectionCodes = $structureSectionCodes ?? null;
if (is_array($structureSectionCodes) && $structureSectionCodes !== []) {
    $allowed = array_flip($structureSectionCodes);
    $structureSections = array_intersect_key($structureSections, $allowed);
}
$structureShowDeleted = $structureShowDeleted ?? true;
$structureShowReadonlyHint = $structureShowReadonlyHint ?? (!$structureCanEdit);
$structureIncludeScript = $structureIncludeScript ?? $structureCanEdit;
$structureShowItemMeta = $structureShowItemMeta ?? true;
$structureEditOwnOnly = !empty($structureEditOwnOnly);
$structureAllowItemMutate = !isset($structureAllowItemMutate) || !empty($structureAllowItemMutate);
$structureShowItemMetaBankOnly = !empty($structureShowItemMetaBankOnly);
if (!isset($structureProtectBankItems)) {
    $structureProtectBankItems = !$structureShowItemMetaBankOnly;
} else {
    $structureProtectBankItems = !empty($structureProtectBankItems);
}
$structureCurrentUserId = (int) ($structureCurrentUserId ?? 0);
$structureGridCols = (int) ($structureGridCols ?? 3);
if ($structureGridCols < 2 || $structureGridCols > 3) {
    $structureGridCols = 3;
}
$structureGridClass = 'app-structure-grid app-structure-grid--cols-' . $structureGridCols;
$structureBlockId = 'app-structure-' . $structureApplicationId;
if (!isset($structureAjaxUrl) || $structureAjaxUrl === '') {
    $structureAjaxUrl = finbuild_application_structure_api_url();
}
$structureTableReady = finbuild_application_structure_table_ready(getPDO());
?>
<style>
.app-structure-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1.25rem;
}
.app-structure-grid--cols-2 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}
@media (max-width: 991.98px) {
    .app-structure-grid,
    .app-structure-grid--cols-2 {
        grid-template-columns: 1fr;
    }
}
.app-structure-card {
    border: 1px solid var(--bs-border-color-translucent, #e9ecef);
    border-radius: 14px;
    background: #fff;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-height: 220px;
    box-shadow: 0 2px 12px rgba(15, 23, 42, 0.04);
}
.app-structure-card__head {
    padding: 1rem 1.15rem;
    border-bottom: 1px solid rgba(0, 0, 0, 0.06);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.app-structure-card__title {
    display: flex;
    align-items: center;
    min-height: 40px;
}
.app-structure-card__title h6 {
    line-height: 1.3;
}
.app-structure-card__icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
}
.app-structure-card__body {
    padding: 0.85rem 1.15rem 1.15rem;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.app-structure-list {
    list-style: none;
    margin: 0;
    padding: 0;
    flex: 1;
}
.app-structure-list__item {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    padding: 0.65rem 0;
    border-bottom: 1px dashed rgba(0, 0, 0, 0.07);
    font-size: 0.9375rem;
    line-height: 1.45;
}
.app-structure-list__item:last-child {
    border-bottom: none;
}
.app-structure-list__bullet {
    margin-top: 0.2rem;
    flex-shrink: 0;
    font-size: 0.75rem;
}
.app-structure-list__text {
    flex: 1;
    word-break: break-word;
}
.app-structure-list__meta {
    display: block;
    font-size: 0.78rem;
    color: #6c757d;
    margin-top: 0.2rem;
    line-height: 1.35;
}
.app-structure-list__meta + .app-structure-list__meta {
    margin-top: 0.1rem;
}
.app-structure-list__item--deleted .app-structure-list__text {
    text-decoration: line-through;
    color: #94a3b8;
}
.app-structure-deleted-wrap {
    margin-top: 0.75rem;
    border-top: 1px dashed rgba(0, 0, 0, 0.08);
    padding-top: 0.5rem;
}
.app-structure-deleted-wrap summary {
    cursor: pointer;
    font-size: 0.8125rem;
    color: #6c757d;
    user-select: none;
}
.app-structure-deleted-list {
    list-style: none;
    margin: 0.5rem 0 0;
    padding: 0;
}
.app-structure-list__actions {
    display: flex;
    flex-shrink: 0;
    align-items: flex-start;
    gap: 0.25rem;
}
.app-structure-list__actions .btn.structure-action-btn {
    flex-shrink: 0;
    padding: 0.15rem 0.4rem;
    line-height: 1;
    width: 1.85rem;
    height: 1.85rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.app-structure-list__actions .btn.structure-action-btn i {
    font-size: 0.85rem;
    line-height: 1;
}
.app-structure-edit-form textarea {
    resize: vertical;
    min-height: 72px;
    font-size: 0.9rem;
}
.app-structure-empty {
    color: #6c757d;
    font-size: 0.875rem;
    padding: 0.75rem 0;
    font-style: italic;
}
.app-structure-add {
    margin-top: auto;
    padding-top: 0.85rem;
    border-top: 1px solid rgba(0, 0, 0, 0.06);
}
.app-structure-add textarea {
    resize: vertical;
    min-height: 72px;
    font-size: 0.9rem;
}
.app-structure-readonly-hint {
    font-size: 0.8125rem;
    color: #6c757d;
    margin-bottom: 1rem;
}
/* Те же размеры/форма, что у btn-primary на карточке заявки — меняем только цвет и тень */
.app-structure-add .btn.structure-add-btn--advantages {
    background: linear-gradient(to right, #2ecc71, #27ae60);
    border: none;
    color: #fff;
    box-shadow: 0 2px 8px rgba(46, 204, 113, 0.25);
}
.app-structure-add .btn.structure-add-btn--advantages:hover,
.app-structure-add .btn.structure-add-btn--advantages:focus {
    background: linear-gradient(to right, #27ae60, #219a52);
    border: none;
    color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(46, 204, 113, 0.35);
}
.app-structure-add .btn.structure-add-btn--stop_factors {
    background: linear-gradient(to right, #e74c3c, #c0392b);
    border: none;
    color: #fff;
    box-shadow: 0 2px 8px rgba(231, 76, 60, 0.25);
}
.app-structure-add .btn.structure-add-btn--stop_factors:hover,
.app-structure-add .btn.structure-add-btn--stop_factors:focus {
    background: linear-gradient(to right, #c0392b, #a93226);
    border: none;
    color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(231, 76, 60, 0.35);
}
</style>

<div id="<?= htmlspecialchars($structureBlockId, ENT_QUOTES, 'UTF-8') ?>"
     class="app-structure-root"
     data-application-id="<?= $structureApplicationId ?>"
     data-can-edit="<?= $structureCanEdit ? '1' : '0' ?>"
     data-edit-own-only="<?= $structureEditOwnOnly ? '1' : '0' ?>"
     data-allow-item-mutate="<?= $structureAllowItemMutate ? '1' : '0' ?>"
     data-protect-bank-items="<?= $structureProtectBankItems ? '1' : '0' ?>"
     data-current-user-id="<?= $structureCurrentUserId ?>"
     data-show-item-meta="<?= $structureShowItemMeta ? '1' : '0' ?>"
     data-show-meta-bank-only="<?= $structureShowItemMetaBankOnly ? '1' : '0' ?>"
     data-table-ready="<?= $structureTableReady ? '1' : '0' ?>"
     data-api-url="<?= htmlspecialchars($structureAjaxUrl, ENT_QUOTES, 'UTF-8') ?>">

    <?php if (!$structureTableReady): ?>
        <div class="alert alert-warning mb-3">
            <i class="bi bi-database-exclamation me-2"></i>
            Таблица структуры принципала не создана. Выполните на сервере файл
            <code>migrations/add_application_structure.sql</code>, затем обновите страницу.
        </div>
    <?php endif; ?>

    <?php if ($structureShowReadonlyHint && !$structureCanEdit): ?>
        <p class="app-structure-readonly-hint mb-3">
            <i class="bi bi-eye me-1"></i> Просмотр структуры принципала.
        </p>
    <?php endif; ?>

    <?php
    $renderStructureItem = static function (array $item, string $accent, bool $structureCanEdit, bool $deleted = false) use (
        $structureShowItemMeta,
        $structureEditOwnOnly,
        $structureShowItemMetaBankOnly,
        $structureCurrentUserId,
        $structureAllowItemMutate,
        $structureProtectBankItems
    ): void {
        $itemId = (int) ($item['id'] ?? 0);
        $itemCanEdit = finbuild_structure_ui_item_editable(
            $structureCanEdit,
            $structureEditOwnOnly,
            $structureCurrentUserId,
            $item,
            $deleted,
            $structureAllowItemMutate,
            $structureProtectBankItems
        );
        $metaLines = finbuild_structure_ui_item_show_meta(
            $structureShowItemMeta,
            $structureShowItemMetaBankOnly,
            $item,
            $deleted
        )
            ? ($structureShowItemMetaBankOnly
                ? finbuild_structure_item_meta_lines_bank_lk($item, $deleted)
                : finbuild_structure_item_meta_lines($item, $deleted))
            : [];
        ?>
        <li class="app-structure-list__item<?= $deleted ? ' app-structure-list__item--deleted' : '' ?>"
            data-item-id="<?= $itemId ?>"
            data-raw-content="<?= htmlspecialchars((string) ($item['content'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <i class="bi bi-dot app-structure-list__bullet text-<?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>"></i>
            <div class="flex-grow-1 min-w-0">
                <span class="app-structure-list__text"><?= nl2br(htmlspecialchars((string) ($item['content'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></span>
                <?php foreach ($metaLines as $metaLine): ?>
                    <span class="app-structure-list__meta"><?= htmlspecialchars($metaLine, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
            </div>
            <?php if ($itemCanEdit): ?>
                <div class="app-structure-list__actions">
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary structure-action-btn structure-edit-btn"
                            title="Редактировать"
                            data-item-id="<?= $itemId ?>">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-outline-danger structure-action-btn structure-delete-btn"
                            title="Удалить"
                            data-item-id="<?= $itemId ?>">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            <?php endif; ?>
        </li>
        <?php
    };
    ?>

    <div class="<?= htmlspecialchars($structureGridClass, ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach ($structureSections as $code => $meta):
            $accent = (string) ($meta['accent'] ?? 'secondary');
            $items = $structureGrouped[$code] ?? [];
            $deletedItems = $structureDeletedGrouped[$code] ?? [];
            ?>
            <div class="app-structure-card" data-section="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" data-accent="<?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>">
                <div class="app-structure-card__head">
                    <div class="app-structure-card__icon bg-<?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>-subtle text-<?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>">
                        <i class="bi <?= htmlspecialchars((string) $meta['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                    </div>
                    <div class="app-structure-card__title flex-grow-1 min-w-0">
                        <h6 class="mb-0 fw-semibold"><?= htmlspecialchars((string) $meta['title'], ENT_QUOTES, 'UTF-8') ?></h6>
                    </div>
                </div>
                <div class="app-structure-card__body">
                    <ul class="app-structure-list" data-section-list="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">
                        <?php if ($items === []): ?>
                            <li class="app-structure-empty structure-empty-msg">Пока нет пунктов</li>
                        <?php else: ?>
                            <?php foreach ($items as $item) {
                                $renderStructureItem($item, $accent, $structureCanEdit, false);
                            } ?>
                        <?php endif; ?>
                    </ul>
                    <?php if ($structureShowDeleted && $deletedItems !== []): ?>
                        <details class="app-structure-deleted-wrap">
                            <summary>Удалённые (<?= count($deletedItems) ?>)</summary>
                            <ul class="app-structure-deleted-list" data-section-deleted-list="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">
                                <?php foreach ($deletedItems as $item) {
                                    $renderStructureItem($item, $accent, $structureCanEdit, true);
                                } ?>
                            </ul>
                        </details>
                    <?php elseif ($structureShowDeleted): ?>
                        <details class="app-structure-deleted-wrap" style="display:none;">
                            <summary>Удалённые (0)</summary>
                            <ul class="app-structure-deleted-list" data-section-deleted-list="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"></ul>
                        </details>
                    <?php endif; ?>
                    <?php if ($structureCanEdit && $structureTableReady): ?>
                        <div class="app-structure-add">
                            <textarea class="form-control form-control-sm structure-add-input mb-2"
                                      rows="2"
                                      placeholder="Текст пункта…"
                                      data-section-input="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"></textarea>
                            <button type="button"
                                    class="btn btn-sm btn-primary w-100 structure-add-btn structure-add-btn--<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
                                    data-section="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-plus-lg me-1"></i> Добавить
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($structureIncludeScript): ?>
<script>
(function () {
    if (window.__finbuildStructureUiBound) {
        return;
    }
    window.__finbuildStructureUiBound = true;

    function notify(msg, type) {
        const t = type || 'success';
        if (typeof showNotification === 'function') {
            showNotification(msg, t === 'danger' ? 'danger' : 'success');
            return;
        }
        alert(msg);
    }

    async function structureApiRequest(apiUrl, formData) {
        if (!apiUrl) {
            throw new Error('Не задан адрес сохранения. Обновите страницу.');
        }
        formData.append('structure_ajax', '1');
        let res;
        try {
            res = await fetch(apiUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
        } catch (netErr) {
            throw new Error('Не удалось отправить запрос. Проверьте интернет и обновите страницу.');
        }
        const raw = await res.text();
        let data;
        try {
            data = raw === '' ? {} : JSON.parse(raw);
        } catch (parseErr) {
            console.error('Structure API response:', res.status, raw.slice(0, 800));
            const hint = res.status === 404
                ? 'Страница сохранения не найдена (404). Обновите файлы на сервере.'
                : 'Сервер вернул не JSON. Откройте консоль (F12) — там фрагмент ответа.';
            throw new Error(hint);
        }
        if (!data || typeof data.success === 'undefined') {
            throw new Error('Некорректный ответ сервера');
        }
        if (!data.success) {
            throw new Error(data.error || ('Ошибка ' + res.status));
        }
        return data;
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function nl2brSafe(s) {
        return esc(s).replace(/\n/g, '<br>');
    }

    function removeEmptyPlaceholder(list) {
        const empty = list.querySelector('.structure-empty-msg');
        if (empty) empty.remove();
    }

    function ensureEmptyPlaceholder(list) {
        if (list.querySelector('[data-item-id]')) return;
        if (list.querySelector('.structure-empty-msg')) return;
        const li = document.createElement('li');
        li.className = 'app-structure-empty structure-empty-msg';
        li.textContent = 'Пока нет пунктов';
        list.appendChild(li);
    }

    function itemIsFromBank(item) {
        return (item.author_role || '') === 'bank';
    }

    function itemCanEdit(root, item, deleted) {
        if (root.dataset.canEdit !== '1' || deleted || root.dataset.allowItemMutate !== '1') {
            return false;
        }
        if (root.dataset.protectBankItems === '1' && itemIsFromBank(item)) {
            return false;
        }
        if (root.dataset.editOwnOnly === '1') {
            const uid = parseInt(root.dataset.currentUserId || '0', 10);
            return parseInt(String(item.created_by || '0'), 10) === uid;
        }
        return true;
    }

    function itemShowMeta(root, item, deleted) {
        if (root.dataset.showItemMeta !== '1') {
            return false;
        }
        if (deleted && root.dataset.showMetaBankOnly === '1') {
            return false;
        }
        return true;
    }

    function itemWasEdited(item) {
        if (item.updated_by || item.updated_by_name) {
            return true;
        }
        if (item.created_at && item.updated_at) {
            const created = Date.parse(String(item.created_at).replace(' ', 'T'));
            const updated = Date.parse(String(item.updated_at).replace(' ', 'T'));
            if (!isNaN(created) && !isNaN(updated) && updated > created + 60000) {
                return true;
            }
        }
        return false;
    }

    function buildItemMetaLines(item, deleted, root) {
        if (root && root.dataset.showMetaBankOnly === '1') {
        if (deleted) {
            const lines = [];
            const created = [];
            if (item.author_name) created.push(item.author_name);
            if (item.created_at_label) created.push(item.created_at_label);
            if (created.length) {
                lines.push('Добавлено: ' + created.join(' · '));
            }
            let t = 'Удалено';
            if (item.deleted_by_name) {
                t += ': ' + item.deleted_by_name;
            }
            if (item.deleted_at_label) {
                t += (item.deleted_by_name ? ', ' : ': ') + item.deleted_at_label;
            }
            lines.push(t);
            return lines;
        }
        if ((item.author_role || '') === 'bank') {
                const lines = [];
                const created = [];
                if (item.author_name) created.push(item.author_name);
                if (item.created_at_label) created.push(item.created_at_label);
                if (created.length) {
                    lines.push('Добавлено: ' + created.join(' · '));
                }
                if (itemWasEdited(item)) {
                    const updated = [];
                    if (item.updated_by_name) updated.push(item.updated_by_name);
                    if (item.updated_at_label) updated.push(item.updated_at_label);
                    if (updated.length) {
                        lines.push('Изменено: ' + updated.join(' · '));
                    }
                }
                return lines;
            }
            return ['Добавлено аналитиком платформы'];
        }
        if (root && !itemShowMeta(root, item, deleted)) {
            return [];
        }
        if (deleted) {
            const lines = [];
            const created = [];
            if (item.author_name) created.push(item.author_name);
            if (item.created_at_label) created.push(item.created_at_label);
            if (created.length) {
                lines.push('Добавлено: ' + created.join(' · '));
            }
            let t = 'Удалено';
            if (item.deleted_by_name) {
                t += ': ' + item.deleted_by_name;
            }
            if (item.deleted_at_label) {
                t += (item.deleted_by_name ? ', ' : ': ') + item.deleted_at_label;
            }
            lines.push(t);
            return lines;
        }
        const lines = [];
        const created = [];
        if (item.author_name) created.push(item.author_name);
        if (item.created_at_label) created.push(item.created_at_label);
        if (created.length) {
            lines.push('Добавлено: ' + created.join(' · '));
        }
        if (itemWasEdited(item)) {
            const updated = [];
            if (item.updated_by_name) updated.push(item.updated_by_name);
            if (item.updated_at_label) updated.push(item.updated_at_label);
            if (updated.length) {
                lines.push('Изменено: ' + updated.join(' · '));
            }
        }
        return lines;
    }

    function renderItemMetaHtml(item, deleted, root) {
        return buildItemMetaLines(item, deleted, root).map(function (line) {
            return '<span class="app-structure-list__meta">' + esc(line) + '</span>';
        }).join('');
    }

    function replaceItemMeta(li, item, deleted, root) {
        const body = li.querySelector('.flex-grow-1');
        if (!body) return;
        body.querySelectorAll('.app-structure-list__meta').forEach(function (el) {
            el.remove();
        });
        buildItemMetaLines(item, deleted, root).forEach(function (line) {
            const span = document.createElement('span');
            span.className = 'app-structure-list__meta';
            span.textContent = line;
            body.appendChild(span);
        });
    }

    function buildItemMeta(item, deleted, root) {
        return buildItemMetaLines(item, deleted, root).join(' · ');
    }

    function buildItemHtml(item, accent, canEdit, deleted, root) {
        const rawContent = item.content || '';
        let html = '<li class="app-structure-list__item' + (deleted ? ' app-structure-list__item--deleted' : '') + '" data-item-id="' + item.id + '" data-raw-content="' + esc(rawContent) + '">';
        html += '<i class="bi bi-dot app-structure-list__bullet text-' + accent + '"></i>';
        html += '<div class="flex-grow-1 min-w-0">';
        html += '<span class="app-structure-list__text">' + nl2brSafe(rawContent) + '</span>';
        html += renderItemMetaHtml(item, deleted, root);
        html += '</div>';
        if (canEdit && !deleted) {
            html += '<div class="app-structure-list__actions">';
            html += '<button type="button" class="btn btn-sm btn-outline-secondary structure-action-btn structure-edit-btn" title="Редактировать" data-item-id="' + item.id + '"><i class="bi bi-pencil"></i></button>';
            html += '<button type="button" class="btn btn-sm btn-outline-danger structure-action-btn structure-delete-btn" title="Удалить" data-item-id="' + item.id + '"><i class="bi bi-trash"></i></button>';
            html += '</div>';
        }
        html += '</li>';
        return html;
    }

    function cancelItemEdit(li) {
        if (!li) return;
        li.classList.remove('is-editing');
        const form = li.querySelector('.app-structure-edit-form');
        if (form) form.remove();
        const textEl = li.querySelector('.app-structure-list__text');
        const actions = li.querySelector('.app-structure-list__actions');
        if (textEl) textEl.style.display = '';
        if (actions) actions.style.display = '';
    }

    function startItemEdit(li) {
        if (!li || li.classList.contains('is-editing')) return;
        const raw = li.getAttribute('data-raw-content') || '';
        const body = li.querySelector('.flex-grow-1');
        const textEl = li.querySelector('.app-structure-list__text');
        const actions = li.querySelector('.app-structure-list__actions');
        if (!body || !textEl) return;

        li.classList.add('is-editing');
        textEl.style.display = 'none';
        if (actions) actions.style.display = 'none';

        const form = document.createElement('div');
        form.className = 'app-structure-edit-form w-100';
        form.innerHTML =
            '<textarea class="form-control form-control-sm structure-edit-input mb-2" rows="3"></textarea>' +
            '<div class="d-flex gap-2 flex-wrap">' +
            '<button type="button" class="btn btn-sm btn-primary structure-save-btn">Сохранить</button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary structure-cancel-btn">Отмена</button>' +
            '</div>';
        form.querySelector('textarea').value = raw;
        body.appendChild(form);
        form.querySelector('textarea').focus();
    }

    async function saveItemEdit(li, root, apiUrl, applicationId) {
        const form = li.querySelector('.app-structure-edit-form');
        const input = form && form.querySelector('.structure-edit-input');
        const saveBtn = form && form.querySelector('.structure-save-btn');
        if (!input || !saveBtn) return;

        const content = (input.value || '').trim();
        if (!content) {
            notify('Введите текст пункта', 'danger');
            input.focus();
            return;
        }

        const itemId = li.getAttribute('data-item-id') || '';
        const prevHtml = saveBtn.innerHTML;
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Сохранение…';

        try {
            const fd = new FormData();
            fd.append('action', 'update');
            fd.append('application_id', String(applicationId));
            fd.append('item_id', itemId);
            fd.append('content', content);
            const data = await structureApiRequest(apiUrl, fd);
            if (!data.item || !data.item.content) {
                throw new Error('Сервер не вернул обновлённый пункт');
            }
            li.setAttribute('data-raw-content', data.item.content);
            const textEl = li.querySelector('.app-structure-list__text');
            if (textEl) {
                textEl.innerHTML = nl2brSafe(data.item.content);
            }
            replaceItemMeta(li, data.item, false, root);
            cancelItemEdit(li);
            notify('Пункт сохранён', 'success');
        } catch (err) {
            notify(err.message || 'Ошибка запроса', 'danger');
        } finally {
            saveBtn.disabled = false;
            saveBtn.innerHTML = prevHtml;
        }
    }

    function appendItem(list, item, accent, root) {
        removeEmptyPlaceholder(list);
        const wrap = document.createElement('div');
        wrap.innerHTML = buildItemHtml(item, accent, itemCanEdit(root, item, false), false, root);
        list.appendChild(wrap.firstElementChild);
    }

    function appendDeletedItem(card, item, accent, root) {
        let details = card.querySelector('.app-structure-deleted-wrap');
        if (!details) return;
        details.style.display = '';
        let delList = details.querySelector('.app-structure-deleted-list');
        if (!delList) return;
        const wrap = document.createElement('div');
        wrap.innerHTML = buildItemHtml(item, accent, false, true, root);
        delList.appendChild(wrap.firstElementChild);
        const summary = details.querySelector('summary');
        if (summary) {
            summary.textContent = 'Удалённые (' + delList.querySelectorAll('[data-item-id]').length + ')';
        }
    }

    document.addEventListener('click', async function (e) {
        const saveBtn = e.target.closest('.structure-save-btn');
        const cancelBtn = e.target.closest('.structure-cancel-btn');
        const editBtn = e.target.closest('.structure-edit-btn');
        const addBtn = e.target.closest('.structure-add-btn');
        const delBtn = e.target.closest('.structure-delete-btn');

        if (saveBtn || cancelBtn || editBtn || addBtn || delBtn) {
            e.preventDefault();
        }

        const actionEl = saveBtn || cancelBtn || editBtn || addBtn || delBtn;
        if (!actionEl) {
            return;
        }

        const root = actionEl.closest('.app-structure-root');
        if (!root) {
            return;
        }

        const applicationId = parseInt(root.dataset.applicationId, 10);
        const canEdit = root.dataset.canEdit === '1';
        const editOwnOnly = root.dataset.editOwnOnly === '1';
        const tableReady = root.dataset.tableReady === '1';
        const apiUrl = root.getAttribute('data-api-url') || '';

        if (cancelBtn) {
            cancelItemEdit(cancelBtn.closest('.app-structure-list__item'));
            return;
        }

        if (editBtn) {
            if (!canEdit) return;
            const editLi = editBtn.closest('.app-structure-list__item');
            if (editOwnOnly && editLi && editLi.querySelector('.app-structure-list__actions') === null) {
                return;
            }
            startItemEdit(editLi);
            return;
        }

        if (saveBtn) {
            if (!canEdit || !tableReady) return;
            await saveItemEdit(saveBtn.closest('.app-structure-list__item'), root, apiUrl, applicationId);
            return;
        }

        if (addBtn) {
            if (!canEdit) {
                return;
            }
            if (!tableReady) {
                notify('Сначала выполните миграцию БД (add_application_structure.sql).', 'danger');
                return;
            }
            const card = addBtn.closest('.app-structure-card');
            const input = card && card.querySelector('.structure-add-input');
            const list = card && card.querySelector('.app-structure-list');
            if (!card || !input || !list) {
                console.error('Structure UI: card/input/list not found', { card, input, list });
                notify('Ошибка интерфейса. Обновите страницу (Ctrl+F5).', 'danger');
                return;
            }
            const section = addBtn.getAttribute('data-section') || '';
            if (!section) {
                notify('Не удалось определить раздел. Обновите страницу.', 'danger');
                return;
            }
            const content = (input.value || '').trim();
            if (!content) {
                notify('Введите текст пункта', 'danger');
                input.focus();
                return;
            }
            const prevHtml = addBtn.innerHTML;
            addBtn.disabled = true;
            addBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Сохранение…';
            try {
                const fd = new FormData();
                fd.append('action', 'add');
                fd.append('application_id', String(applicationId));
                fd.append('section', section);
                fd.append('content', content);
                const data = await structureApiRequest(apiUrl, fd);
                if (!data.item || !data.item.id) {
                    throw new Error('Сервер не вернул созданный пункт');
                }
                const accent = card.getAttribute('data-accent') || 'primary';
                appendItem(list, data.item, accent, root);
                input.value = '';
                notify('Пункт добавлен', 'success');
            } catch (err) {
                notify(err.message || 'Ошибка запроса', 'danger');
            } finally {
                addBtn.disabled = false;
                addBtn.innerHTML = prevHtml;
            }
            return;
        }

        if (delBtn) {
            if (!tableReady) {
                notify('Сначала выполните миграцию БД (add_application_structure.sql).', 'danger');
                return;
            }
            if (!confirm('Удалить этот пункт?')) return;
            const itemId = delBtn.getAttribute('data-item-id') || '';
            const li = delBtn.closest('.app-structure-list__item');
            const card = delBtn.closest('.app-structure-card');
            const list = card && card.querySelector('.app-structure-list');
            delBtn.disabled = true;
            try {
                const fd = new FormData();
                fd.append('action', 'delete');
                fd.append('application_id', String(applicationId));
                fd.append('item_id', itemId);
                const data = await structureApiRequest(apiUrl, fd);
                if (li) {
                    li.remove();
                }
                if (list) {
                    ensureEmptyPlaceholder(list);
                }
                if (data.item && card) {
                    appendDeletedItem(card, data.item, card.getAttribute('data-accent') || 'primary', root);
                }
                notify('Пункт удалён', 'success');
            } catch (err) {
                notify(err.message || 'Ошибка запроса', 'danger');
            } finally {
                delBtn.disabled = false;
            }
        }
    });
})();
</script>
<?php endif; ?>
