<?php
/**
 * Блок «Дорожная карта» (вкладка заявки).
 *
 * @var int  $roadmapApplicationId
 * @var bool $roadmapCanEdit
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/application_roadmap.php';

$roadmapApplicationId = (int) ($roadmapApplicationId ?? 0);
$roadmapCanEdit = !empty($roadmapCanEdit);
$roadmapTablesReady = finbuild_roadmap_tables_ready(getPDO());
$roadmapBlockId = 'app-roadmap-' . $roadmapApplicationId;
?>
<style>
.app-roadmap-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1.25rem;
}
@media (max-width: 991.98px) {
    .app-roadmap-grid {
        grid-template-columns: 1fr;
    }
}
.app-roadmap-card {
    border: 1px solid #e9ecef;
    border-radius: 14px;
    background: #fff;
    display: flex;
    flex-direction: column;
    min-height: 200px;
    box-shadow: 0 2px 12px rgba(15, 23, 42, 0.04);
}
.app-roadmap-card__head {
    padding: 1rem 1.15rem;
    border-bottom: 1px solid rgba(0, 0, 0, 0.06);
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}
.app-roadmap-card__title {
    font-weight: 600;
    font-size: 1rem;
    color: #2c3e50;
    flex: 1;
    min-width: 0;
    word-break: break-word;
}
.app-roadmap-card__progress {
    font-size: 0.75rem;
    color: #6c757d;
    white-space: nowrap;
}
.app-roadmap-list {
    list-style: none;
    margin: 0;
    padding: 0.75rem 1rem 1rem;
    flex: 1;
}
.app-roadmap-item {
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    padding: 0.45rem 0;
    border-bottom: 1px solid rgba(0, 0, 0, 0.04);
}
.app-roadmap-item:last-child {
    border-bottom: none;
}
.app-roadmap-item.is-done .app-roadmap-item__title {
    color: #6c757d;
    text-decoration: line-through;
}
.app-roadmap-item__check {
    margin-top: 0.15rem;
    flex-shrink: 0;
    width: 1.1rem;
    height: 1.1rem;
    cursor: pointer;
}
.app-roadmap-item__body {
    flex: 1;
    min-width: 0;
}
.app-roadmap-item__title {
    font-size: 0.9375rem;
    line-height: 1.4;
    word-break: break-word;
}
.app-roadmap-item__meta {
    font-size: 0.75rem;
    color: #868e96;
    margin-top: 0.15rem;
}
.app-roadmap-item__actions {
    flex-shrink: 0;
    opacity: 0;
    transition: opacity 0.15s;
}
.app-roadmap-item:hover .app-roadmap-item__actions {
    opacity: 1;
}
.app-roadmap-add-item {
    padding: 0 1rem 1rem;
}
.app-roadmap-empty {
    padding: 2rem 1rem;
    text-align: center;
    color: #6c757d;
}
</style>

<div id="<?= htmlspecialchars($roadmapBlockId) ?>" class="app-roadmap-root" data-application-id="<?= (int) $roadmapApplicationId ?>" data-can-edit="<?= $roadmapCanEdit ? '1' : '0' ?>">
    <?php if (!$roadmapTablesReady): ?>
        <div class="alert alert-warning mb-0">
            Таблицы дорожной карты не найдены. Выполните миграцию <code>migrations/add_application_roadmap.sql</code>.
        </div>
    <?php else: ?>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div class="text-muted small" id="<?= htmlspecialchars($roadmapBlockId) ?>-stats">Загрузка…</div>
            <?php if ($roadmapCanEdit): ?>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary btn-sm" id="<?= htmlspecialchars($roadmapBlockId) ?>-add-group-btn">
                    <i class="bi bi-plus-lg me-1"></i>Раздел
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!$roadmapCanEdit): ?>
            <div class="alert alert-light border small mb-3">Режим просмотра: изменение дорожной карты недоступно.</div>
        <?php endif; ?>
        <div id="<?= htmlspecialchars($roadmapBlockId) ?>-grid" class="app-roadmap-grid">
            <div class="app-roadmap-empty w-100">Загрузка дорожной карты…</div>
        </div>
    <?php endif; ?>
</div>

<?php if ($roadmapTablesReady): ?>
<script>
(function () {
    const root = document.getElementById(<?= json_encode($roadmapBlockId) ?>);
    if (!root) return;

    const applicationId = parseInt(root.getAttribute('data-application-id') || '0', 10);
    let canEdit = root.getAttribute('data-can-edit') === '1';
    const gridEl = document.getElementById(<?= json_encode($roadmapBlockId . '-grid') ?>);
    const statsEl = document.getElementById(<?= json_encode($roadmapBlockId . '-stats') ?>);
    const tabBadge = document.getElementById('roadmap-tab-badge');
    const apiUrl = 'api_application_roadmap.php';

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function notify(msg, type) {
        if (typeof showNotification === 'function') {
            showNotification(msg, type === 'error' ? 'danger' : (type || 'success'));
        }
    }

    async function apiGet(params) {
        const q = new URLSearchParams(params);
        const r = await fetch(apiUrl + '?' + q.toString(), { credentials: 'same-origin' });
        const data = await r.json();
        if (!data.success) throw new Error(data.error || 'Ошибка');
        return data;
    }

    async function apiPost(body) {
        const fd = new FormData();
        Object.keys(body).forEach(function (k) { fd.append(k, body[k]); });
        const r = await fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await r.json();
        if (!data.success) throw new Error(data.error || 'Ошибка');
        return data;
    }

    function itemWasEdited(item) {
        return !!(item.updated_by || item.updated_by_name);
    }

    function buildItemMetaLines(item) {
        const lines = [];
        if (item.is_done) {
            const done = [];
            if (item.done_by_name) done.push(item.done_by_name);
            if (item.done_at_label) {
                done.push(item.done_at_label);
            } else if (item.done_at) {
                const d = String(item.done_at).replace(' ', 'T');
                const dt = new Date(d);
                if (!isNaN(dt.getTime())) {
                    done.push(dt.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }));
                }
            }
            if (done.length) lines.push('Выполнено: ' + done.join(' · '));
        }
        if (itemWasEdited(item)) {
            const updated = [];
            if (item.updated_by_name) updated.push(item.updated_by_name);
            if (item.updated_at_label) updated.push(item.updated_at_label);
            if (updated.length) lines.push('Изменено: ' + updated.join(' · '));
        }
        if (item.meta_lines && item.meta_lines.length) {
            return item.meta_lines;
        }
        return lines;
    }

    function updateStats(stats) {
        if (!statsEl) return;
        const total = stats.total || 0;
        const done = stats.done || 0;
        if (total === 0) {
            statsEl.textContent = 'Пока нет пунктов';
        } else {
            statsEl.textContent = 'Выполнено: ' + done + ' из ' + total;
        }
        if (tabBadge) {
            const left = total - done;
            if (total > 0 && left > 0) {
                tabBadge.textContent = left;
                tabBadge.classList.remove('d-none');
            } else {
                tabBadge.classList.add('d-none');
            }
        }
    }

    function buildItemHtml(item) {
        const metaLines = buildItemMetaLines(item);
        const metaHtml = metaLines.map(function (line) {
            return '<div class="app-roadmap-item__meta">' + esc(line) + '</div>';
        }).join('');
        const checkDisabled = canEdit ? '' : ' disabled';
        const actions = canEdit
            ? ('<div class="app-roadmap-item__actions">' +
                '<button type="button" class="btn btn-link btn-sm text-secondary p-0 roadmap-edit-item" title="Редактировать"><i class="bi bi-pencil"></i></button>' +
                '<button type="button" class="btn btn-link btn-sm text-danger p-0 roadmap-del-item" title="Удалить"><i class="bi bi-trash"></i></button>' +
                '</div>')
            : '';
        return '<li class="app-roadmap-item' + (item.is_done ? ' is-done' : '') + '" data-item-id="' + item.id + '">' +
            '<input type="checkbox" class="form-check-input app-roadmap-item__check"' + (item.is_done ? ' checked' : '') + checkDisabled + ' aria-label="Отметить">' +
            '<div class="app-roadmap-item__body">' +
            '<div class="app-roadmap-item__title">' + esc(item.title) + '</div>' +
            metaHtml +
            '</div>' +
            actions + '</li>';
    }

    function buildGroupHtml(group) {
        const st = group.stats || { total: 0, done: 0 };
        const isSystem = !!group.is_system;
        let itemsHtml = '';
        (group.items || []).forEach(function (it) { itemsHtml += buildItemHtml(it); });
        const groupActions = (!canEdit || isSystem) ? '' :
            '<button type="button" class="btn btn-link btn-sm text-secondary p-0 roadmap-rename-group" title="Переименовать"><i class="bi bi-pencil"></i></button>' +
            '<button type="button" class="btn btn-link btn-sm text-danger p-0 roadmap-del-group" title="Удалить раздел"><i class="bi bi-trash"></i></button>';
        const addItem = canEdit
            ? ('<div class="app-roadmap-add-item">' +
                '<div class="input-group input-group-sm">' +
                '<input type="text" class="form-control roadmap-new-item-input" placeholder="Новый пункт…" maxlength="500">' +
                '<button type="button" class="btn btn-outline-primary roadmap-add-item-btn"><i class="bi bi-plus"></i></button>' +
                '</div></div>')
            : '';
        return '<div class="app-roadmap-card' + (isSystem ? ' is-system-group' : '') + '" data-group-id="' + group.id + '"' +
            (isSystem ? ' data-is-system="1"' : '') + '>' +
            '<div class="app-roadmap-card__head">' +
            '<div class="flex-grow-1 min-w-0">' +
            '<div class="app-roadmap-card__title">' + esc(group.title) + '</div>' +
            '<div class="app-roadmap-card__progress">' + st.done + ' / ' + st.total + '</div>' +
            '</div>' +
            groupActions +
            '</div>' +
            '<ul class="app-roadmap-list">' + (itemsHtml || '<li class="text-muted small px-1 py-2">Нет пунктов</li>') + '</ul>' +
            addItem + '</div>';
    }

    function render(data) {
        updateStats(data.stats || { total: 0, done: 0 });
        const groups = data.groups || [];
        if (!groups.length) {
            gridEl.innerHTML = '<div class="app-roadmap-empty w-100">Не удалось загрузить разделы дорожной карты</div>';
            return;
        }
        gridEl.innerHTML = groups.map(buildGroupHtml).join('');
    }

    function replaceItemEl(li, item) {
        const wrap = document.createElement('div');
        wrap.innerHTML = buildItemHtml(item);
        li.replaceWith(wrap.firstElementChild);
    }

    function updateGroupProgress(card) {
        const items = card.querySelectorAll('.app-roadmap-item');
        let done = 0;
        items.forEach(function (li) { if (li.classList.contains('is-done')) done++; });
        const prog = card.querySelector('.app-roadmap-card__progress');
        if (prog) prog.textContent = done + ' / ' + items.length;
    }

    async function loadRoadmap() {
        const data = await apiGet({ action: 'get', application_id: String(applicationId) });
        if (typeof data.can_edit !== 'undefined') {
            canEdit = !!data.can_edit;
            root.setAttribute('data-can-edit', canEdit ? '1' : '0');
        }
        render(data);
    }

    gridEl.addEventListener('change', async function (e) {
        const cb = e.target.closest('.app-roadmap-item__check');
        if (!cb) return;
        if (!canEdit) {
            e.preventDefault();
            cb.checked = !cb.checked;
            return;
        }
        const li = cb.closest('.app-roadmap-item');
        if (!li) return;
        const itemId = li.getAttribute('data-item-id');
        cb.disabled = true;
        try {
            const data = await apiPost({
                action: 'toggle_item',
                application_id: String(applicationId),
                item_id: itemId,
                is_done: cb.checked ? '1' : '0'
            });
            if (data.item) replaceItemEl(li, data.item);
            updateStats(data.stats || {});
            const card = li.closest('.app-roadmap-card');
            if (card) updateGroupProgress(card);
        } catch (err) {
            cb.checked = !cb.checked;
            notify(err.message, 'error');
        } finally {
            cb.disabled = false;
        }
    });

    gridEl.addEventListener('click', async function (e) {
        const editItem = e.target.closest('.roadmap-edit-item');
        if (editItem) {
            const li = editItem.closest('.app-roadmap-item');
            const titleEl = li && li.querySelector('.app-roadmap-item__title');
            if (!li || !titleEl) return;
            const next = prompt('Текст пункта', titleEl.textContent || '');
            if (next === null) return;
            const title = next.trim();
            if (!title) return;
            try {
                const data = await apiPost({
                    action: 'rename_item',
                    application_id: String(applicationId),
                    item_id: li.getAttribute('data-item-id'),
                    title: title
                });
                if (data.item) replaceItemEl(li, data.item);
            } catch (err) { notify(err.message, 'error'); }
            return;
        }

        const delItem = e.target.closest('.roadmap-del-item');
        if (delItem) {
            const li = delItem.closest('.app-roadmap-item');
            if (!li || !confirm('Удалить пункт?')) return;
            try {
                const data = await apiPost({
                    action: 'delete_item',
                    application_id: String(applicationId),
                    item_id: li.getAttribute('data-item-id')
                });
                li.remove();
                updateStats(data.stats || {});
                const card = delItem.closest('.app-roadmap-card');
                if (card) updateGroupProgress(card);
            } catch (err) { notify(err.message, 'error'); }
            return;
        }

        const addBtn = e.target.closest('.roadmap-add-item-btn');
        if (addBtn) {
            const card = addBtn.closest('.app-roadmap-card');
            const input = card && card.querySelector('.roadmap-new-item-input');
            if (!card || !input) return;
            const title = (input.value || '').trim();
            if (!title) { input.focus(); return; }
            addBtn.disabled = true;
            try {
                const data = await apiPost({
                    action: 'add_item',
                    application_id: String(applicationId),
                    group_id: card.getAttribute('data-group-id'),
                    title: title
                });
                input.value = '';
                const list = card.querySelector('.app-roadmap-list');
                const placeholder = list.querySelector('.text-muted.small');
                if (placeholder) placeholder.remove();
                if (data.item && list) {
                    list.insertAdjacentHTML('beforeend', buildItemHtml(data.item));
                }
                updateStats(data.stats || {});
                updateGroupProgress(card);
            } catch (err) { notify(err.message, 'error'); }
            finally { addBtn.disabled = false; }
            return;
        }

        const renameGroup = e.target.closest('.roadmap-rename-group');
        if (renameGroup) {
            const card = renameGroup.closest('.app-roadmap-card');
            const titleEl = card && card.querySelector('.app-roadmap-card__title');
            if (!card || !titleEl) return;
            const next = prompt('Название раздела', titleEl.textContent || '');
            if (next === null) return;
            const title = next.trim();
            if (!title) return;
            try {
                await apiPost({
                    action: 'rename_group',
                    application_id: String(applicationId),
                    group_id: card.getAttribute('data-group-id'),
                    title: title
                });
                titleEl.textContent = title;
            } catch (err) { notify(err.message, 'error'); }
            return;
        }

        const delGroup = e.target.closest('.roadmap-del-group');
        if (delGroup) {
            const card = delGroup.closest('.app-roadmap-card');
            if (!card || !confirm('Удалить раздел и все пункты?')) return;
            try {
                const data = await apiPost({
                    action: 'delete_group',
                    application_id: String(applicationId),
                    group_id: card.getAttribute('data-group-id')
                });
                card.remove();
                updateStats(data.stats || {});
                if (!gridEl.querySelector('.app-roadmap-card')) {
                    render({ groups: [], stats: data.stats });
                }
            } catch (err) { notify(err.message, 'error'); }
        }
    });

    gridEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.classList.contains('roadmap-new-item-input')) {
            e.preventDefault();
            const btn = e.target.closest('.app-roadmap-add-item') && e.target.closest('.app-roadmap-add-item').querySelector('.roadmap-add-item-btn');
            if (btn) btn.click();
        }
    });

    const addGroupBtn = document.getElementById(<?= json_encode($roadmapBlockId . '-add-group-btn') ?>);
    if (addGroupBtn) {
        addGroupBtn.addEventListener('click', async function () {
            const title = prompt('Название раздела', '');
            if (title === null) return;
            const t = title.trim();
            if (!t) return;
            try {
                const data = await apiPost({
                    action: 'add_group',
                    application_id: String(applicationId),
                    title: t
                });
                const empty = gridEl.querySelector('.app-roadmap-empty');
                if (empty) empty.remove();
                if (data.group) {
                    gridEl.insertAdjacentHTML('beforeend', buildGroupHtml(data.group));
                }
                updateStats(data.stats || {});
            } catch (err) { notify(err.message, 'error'); }
        });
    }

    loadRoadmap().catch(function (err) {
        gridEl.innerHTML = '<div class="alert alert-danger mb-0">' + esc(err.message) + '</div>';
    });

    document.getElementById('roadmap-tab') && document.getElementById('roadmap-tab').addEventListener('shown.bs.tab', function () {
        loadRoadmap().catch(function () {});
    });
})();
</script>
<?php endif; ?>
