<?php

declare(strict_types=1);

/**
 * Контент и вспомогательные функции публичного лендинга (без авторизации).
 */

/** @param array<string, string|int|float|null> $qs */
function finbuild_landing_register_url(array $qs = []): string
{
    if ($qs === []) {
        return 'register.php';
    }
    return 'register.php?' . http_build_query($qs);
}

/**
 * Признак сегмента «КОРП» в названии банка (не часть слова «корпорация»).
 */
function finbuild_landing_bank_name_has_korp_segment(string $bankName): bool
{
    return (bool) preg_match('/(?<!\p{Cyrillic})КОРП(?!\p{Cyrillic})/u', $bankName);
}

/**
 * Заглушка каталога («другой банк») — не показываем на публичном подборе.
 */
function finbuild_landing_bank_name_is_other_bank(string $bankName): bool
{
    $s = trim($bankName);
    if ($s === '') {
        return false;
    }

    // Без mb_strcasecmp() (только PHP ≥8.2): регистронезависимое сравнение по Юникоду через PCRE.
    return preg_match('/^другой\s+банк$/iu', $s) === 1;
}

/** Банк скрыт с лендинга: КОРП в названии или запись «Другой банк». */
function finbuild_landing_bank_name_excluded_from_public(string $bankName): bool
{
    return finbuild_landing_bank_name_has_korp_segment($bankName)
        || finbuild_landing_bank_name_is_other_bank($bankName);
}

/** Резерв, если каталог продуктов пуст или БД недоступна. */
function finbuild_landing_fallback_bank_names(): array
{
    return [
        'Камкомбанк',
    ];
}

/**
 * Имена банков для лендинга: из каталога bank_products или fallback.
 *
 * @return list<string>
 */
function finbuild_landing_bank_names(): array
{
    if (!function_exists('getPDO')) {
        return array_values(array_filter(
            finbuild_landing_fallback_bank_names(),
            static fn(string $n): bool => !finbuild_landing_bank_name_excluded_from_public($n)
        ));
    }
    try {
        $pdo = getPDO();
        $stmt = $pdo->query(
            "SELECT DISTINCT TRIM(bank_name) AS bn FROM bank_products
             WHERE bank_name IS NOT NULL AND TRIM(bank_name) <> ''
             ORDER BY bn ASC"
        );
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $names = [];
        foreach ($rows as $r) {
            $n = trim((string) ($r['bn'] ?? ''));
            if ($n !== '' && !finbuild_landing_bank_name_excluded_from_public($n)) {
                $names[] = $n;
            }
        }
        if ($names !== []) {
            return array_values(array_unique($names));
        }

        return array_values(array_filter(
            finbuild_landing_fallback_bank_names(),
            static fn(string $n): bool => !finbuild_landing_bank_name_excluded_from_public($n)
        ));
    } catch (Throwable $e) {
        return array_values(array_filter(
            finbuild_landing_fallback_bank_names(),
            static fn(string $n): bool => !finbuild_landing_bank_name_excluded_from_public($n)
        ));
    }
}

/**
 * Относительные URL файлов логотипов из каталога assets/banks (бегущая строка на лендинге).
 *
 * @return list<string>
 */
function finbuild_landing_bank_logo_assets(): array
{
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'banks';
    if (!is_dir($dir)) {
        return [];
    }

    $allowed = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
    $names = [];
    foreach (scandir($dir, SCANDIR_SORT_ASCENDING) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $dir . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($full)) {
            continue;
        }
        $ext = strtolower((string) pathinfo($entry, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            continue;
        }
        $names[] = $entry;
    }

    natcasesort($names);

    $urls = [];
    foreach (array_values($names) as $entry) {
        // Один уровень вложенности: только имена файлов из banks/.
        $urls[] = 'assets/banks/' . rawurlencode($entry);
    }

    return $urls;
}

/**
 * Дополнительный класс для слота логотипа (увеличенные варианты по имени файла).
 */
function finbuild_landing_bank_logo_extra_slot_class(string $logoUrl): string
{
    $path = $logoUrl;
    $qpos = strpos($path, '?');
    if ($qpos !== false) {
        $path = substr($path, 0, $qpos);
    }
    $base = basename(str_replace('\\', '/', $path));
    $stem = strtolower((string) pathinfo(rawurldecode($base), PATHINFO_FILENAME));
    if ($stem === 'nbs') {
        return 'landing-bank-logo-slot--nbs';
    }
    if ($stem === 'ubrir') {
        return 'landing-bank-logo-slot--ubrir';
    }

    return '';
}

/**
 * Пытается подобрать логотип банка из assets/banks/ по названию банка из каталога.
 *
 * Возвращает относительный URL (например, assets/banks/kamkombank.png) или null.
 */
function finbuild_landing_bank_logo_url_for_bank_name(string $bankName): ?string
{
    $bankName = trim($bankName);
    if ($bankName === '') {
        return null;
    }

    $assets = finbuild_landing_bank_logo_assets();
    if ($assets === []) {
        return null;
    }

    $map = [];
    foreach ($assets as $url) {
        $path = $url;
        $qpos = strpos($path, '?');
        if ($qpos !== false) {
            $path = substr($path, 0, $qpos);
        }
        $base = basename(str_replace('\\', '/', $path));
        $stem = strtolower((string) pathinfo(rawurldecode($base), PATHINFO_FILENAME));
        if ($stem !== '' && !isset($map[$stem])) {
            $map[$stem] = $url;
        }
    }

    // Нормализация: кириллица → латиница, убираем пробелы/дефисы/кавычки и т.п.
    $s = preg_replace('/[\s\p{P}\p{S}]+/u', '', $bankName) ?? $bankName;

    $translit = [
        'а' => 'a', 'А' => 'a', 'б' => 'b', 'Б' => 'b', 'в' => 'v', 'В' => 'v', 'г' => 'g', 'Г' => 'g',
        'д' => 'd', 'Д' => 'd', 'е' => 'e', 'Е' => 'e', 'ё' => 'e', 'Ё' => 'e', 'ж' => 'zh', 'Ж' => 'zh',
        'з' => 'z', 'З' => 'z', 'и' => 'i', 'И' => 'i', 'й' => 'y', 'Й' => 'y', 'к' => 'k', 'К' => 'k',
        'л' => 'l', 'Л' => 'l', 'м' => 'm', 'М' => 'm', 'н' => 'n', 'Н' => 'n', 'о' => 'o', 'О' => 'o',
        'п' => 'p', 'П' => 'p', 'р' => 'r', 'Р' => 'r', 'с' => 's', 'С' => 's', 'т' => 't', 'Т' => 't',
        'у' => 'u', 'У' => 'u', 'ф' => 'f', 'Ф' => 'f', 'х' => 'h', 'Х' => 'h', 'ц' => 'ts', 'Ц' => 'ts',
        'ч' => 'ch', 'Ч' => 'ch', 'ш' => 'sh', 'Ш' => 'sh', 'щ' => 'sch', 'Щ' => 'sch', 'ъ' => '', 'Ъ' => '',
        'ы' => 'y', 'Ы' => 'y', 'ь' => '', 'Ь' => '', 'э' => 'e', 'Э' => 'e', 'ю' => 'yu', 'Ю' => 'yu',
        'я' => 'ya', 'Я' => 'ya',
    ];

    $slug = '';
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        if (isset($translit[$ch])) {
            $slug .= $translit[$ch];
            continue;
        }
        $lower = strtolower($ch);
        if (preg_match('/[a-z0-9]/', $lower) === 1) {
            $slug .= $lower;
        }
    }

    if ($slug !== '' && isset($map[$slug])) {
        return $map[$slug];
    }

    // Фолбэк: иногда банк в каталоге совпадает со stem без транслита (латиница).
    $latin = strtolower(preg_replace('/[^a-z0-9]+/i', '', $bankName) ?? '');
    if ($latin !== '' && isset($map[$latin])) {
        return $map[$latin];
    }

    return null;
}

/**
 * Логотип банка для карточек/таблиц продуктов в кабинете (с учётом особых названий из каталога).
 */
function finbuild_bank_logo_url_for_bank_name(string $bankName, string $productName = ''): ?string
{
    $bankName = trim($bankName);
    $productName = trim($productName);

    if ($productName !== '' && preg_match('/^индивидуальное\s+рассмотрение$/iu', $productName) === 1) {
        return null;
    }

    if (preg_match('/^совком\s*\(корп\)$/iu', $bankName) === 1) {
        return 'assets/banks/sovcombank.png';
    }

    if (preg_match('/^реалист\s*\(корп\)$/iu', $bankName) === 1) {
        return finbuild_landing_bank_logo_url_for_bank_name('Реалист');
    }

    return finbuild_landing_bank_logo_url_for_bank_name($bankName);
}

/**
 * Виды банковских гарантий для лендинга (карточки с тегами ФЗ).
 *
 * @return list<array{tags: list<string>, title: string, text: string}>
 */
function finbuild_landing_bg_guarantee_kinds(): array
{
    return [
        [
            'tags' => ['44-ФЗ', '223-ФЗ'],
            'title' => 'Банковская гарантия на обеспечение заявки',
            'text' => 'Пригодится для участия в торгах. Поставщик может предоставить гарантию как подтверждение, что в случае победы он подпишет контракт.',
        ],
        [
            'tags' => ['44-ФЗ', '223-ФЗ', '615-ПП (185-ФЗ)'],
            'title' => 'Банковская гарантия исполнения контракта',
            'text' => 'Может понадобиться на этапе подписания контракта, чтобы заказчик был уверен, что поставщик точно доставит товар, выполнит работу или предоставит услугу.',
        ],
        [
            'tags' => ['44-ФЗ', '223-ФЗ', '615-ПП (185-ФЗ)'],
            'title' => 'Банковская гарантия на авансовый платеж',
            'text' => 'Если по условиям тендера поставщик получил аванс, он предоставляет такую гарантию как обещание полностью вернуть заказчику аванс.',
        ],
        [
            'tags' => ['44-ФЗ', '615-ПП (185-ФЗ)'],
            'title' => 'Обеспечение исполнения гарантийных обязательств',
            'text' => 'Если на товар или услугу действует гарантия, заказчик может запросить у поставщика подтверждение, что тот выполнит условия.',
        ],
        [
            'tags' => [],
            'title' => 'Коммерческая гарантия',
            'text' => 'Этот вид гарантии используется в коммерческих сделках и служит для обеспечения выполнения обязательств по контракту.',
        ],
    ];
}

/** @return list<array{title: string, text: string, icon: string}> */
function finbuild_landing_features(): array
{
    return [
        [
            'title' => 'Одна заявка - множество предложений банков',
            'text' => 'Создайте заявку и сравните условия от разных банков: выбирайте подходящий вариант и ведите процесс в едином окне',
            'icon' => 'bi-layers',
        ],
        [
            'title' => 'Стадии заявки',
            'text' => 'Видно, на каком этапе сейчас заявка: проверка, работа, согласование/подписание, выпуск или отказ',
            'icon' => 'bi-kanban',
        ],
        [
            'title' => 'Несколько банков в одной заявке',
            'text' => 'В заявке параллельно ведется работа по нескольким банкам. У каждого - свой статус, чат и документы',
            'icon' => 'bi-collection',
        ],
        [
            'title' => 'Аналитика компаний',
            'text' => 'Платформа проанализирует необходимые банкам параметры компании по принципу светофора для понимания слабых и сильных мест',
            'icon' => 'bi-search-heart',
        ],
        [
            'title' => 'Документы в контексте заявки',
            'text' => 'Все документы сгруппированы и для каждого банка заявки - свой запрос документов',
            'icon' => 'bi-folder2-open',
        ],
        [
            'title' => 'Переписка по конкретному банку',
            'text' => 'У каждого банка заявки - свой чат. Удобно, когда в одной заявке несколько банков и разные вопросы',
            'icon' => 'bi-chat-dots',
        ],
        [
            'title' => 'Уведомления о важных событиях',
            'text' => 'При желании получайте письма о смене статуса и новых сообщениях — настройка в профиле',
            'icon' => 'bi-envelope-check',
        ],
        [
            'title' => 'Расчет лимитов',
            'text' => 'Платформа расчитает максимальный лимит гарантии, который банки могут одобрить',
            'icon' => 'bi-diagram-3',
        ],
        [
            'title' => 'Работа с телефона и планшета',
            'text' => 'Кабинет адаптирован под мобильные устройства: посмотреть статус, открыть документы, ответить на сообщение',
            'icon' => 'bi-phone',
        ],
    ];
}

/** @return list<array{step: string, detail: string}> */
function finbuild_landing_pipeline_steps(): array
{
    return [
        [
            'step' => 'Регистрация и создание заявки',
            'detail' => '',
        ],
        [
            'step' => 'Обработка заявки и подбор подходящих банков',
            'detail' => '',
        ],
        [
            'step' => 'Выбор банка',
            'detail' => '',
        ],
        [
            'step' => 'Выпуск банковской гарантии',
            'detail' => '',
        ],
    ];
}

/** @return list<array{q: string, a: string}> */
function finbuild_landing_faq(): array
{
    return [
        [
            'q' => 'Чем аккаунт партнёра (агента) отличается от клиента?',
            'a' => 'Партнер ведет множество заявок, клиент создает заявки только для себя',
        ],
        [
            'q' => 'Откуда берётся список банков на этой странице?',
            'a' => 'Перечислены банки-партнеры, которые работают с Finbuild. Это не все банки, у нас есть возможность индивидуального рассмотрения в других.',
        ],
        [
            'q' => 'Как стать партнером?',
            'a' => 'Зарегистируйтесь как партнер и создайте первую заявку в личном кабинете. Далее с Вами свяжутся и заключат партнерский договор.',
        ],
    ];
}
