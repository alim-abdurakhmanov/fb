<?php
declare(strict_types=1);

/**
 * Общие хелперы для UI чата (форматирование + HTML-рендер сообщений).
 * Используются в виджете чата на странице заявки и в API.
 */

if (!function_exists('finbuild_chat_format_message_date')) {
    function finbuild_chat_format_message_date(string $date): string
    {
        $today = date('Y-m-d');
        $messageDate = date('Y-m-d', strtotime($date));

        if ($today === $messageDate) {
            return 'Сегодня в ' . date('H:i', strtotime($date));
        }
        return date('d.m.Y в H:i', strtotime($date));
    }
}

if (!function_exists('finbuild_chat_format_file_size')) {
    function finbuild_chat_format_file_size(int|float|string $bytes): string
    {
        $bytes = (float)$bytes;
        if ($bytes <= 0) {
            return '0 Bytes';
        }
        $k = 1024;
        $sizes = ['Bytes', 'KB', 'MB', 'GB'];
        $i = (int)floor(log($bytes) / log($k));
        $i = max(0, min($i, count($sizes) - 1));
        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }
}

if (!function_exists('finbuild_chat_get_file_icon')) {
    function finbuild_chat_get_file_icon(string $fileType): string
    {
        $icons = [
            'pdf' => 'bi-file-earmark-pdf text-danger',
            'word' => 'bi-file-earmark-word text-primary',
            'excel' => 'bi-file-earmark-spreadsheet text-success',
            'image' => 'bi-file-earmark-image text-warning',
            'archive' => 'bi-file-earmark-zip text-secondary',
            'text' => 'bi-file-earmark-text text-info',
            'file' => 'bi-file-earmark text-secondary',
        ];

        return $icons[$fileType] ?? 'bi-file-earmark text-secondary';
    }
}

if (!function_exists('finbuild_chat_get_file_type')) {
    function finbuild_chat_get_file_type(string $extension): string
    {
        $extension = strtolower(trim($extension));
        $types = [
            'pdf' => 'pdf',
            'doc' => 'word',
            'docx' => 'word',
            'xls' => 'excel',
            'xlsx' => 'excel',
            'jpg' => 'image',
            'jpeg' => 'image',
            'png' => 'image',
            'txt' => 'text',
            'zip' => 'archive',
            'rar' => 'archive',
        ];

        return $types[$extension] ?? 'file';
    }
}

if (!function_exists('finbuild_chat_format_message_text')) {
    function finbuild_chat_format_message_text(string $text): string
    {
        $parts = preg_split('#(https?://[^\s<]+)#iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        }

        $out = '';
        foreach ($parts as $index => $part) {
            if ($part === '') {
                continue;
            }

            if ($index % 2 === 1) {
                $url = rtrim($part, '.,;:!?)]\'"»«');
                $suffix = substr($part, strlen($url));
                $href = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $out .= '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $href . '</a>';
                if ($suffix !== '') {
                    $out .= htmlspecialchars($suffix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                continue;
            }

            $out .= htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return nl2br($out, false);
    }
}

if (!function_exists('finbuild_chat_role_badge_html')) {
    function finbuild_chat_role_badge_html(string $label, string $class = 'bg-primary text-white', bool $spaced = false): string
    {
        $space = $spaced ? ' ms-1' : '';
        return '<span class="badge ' . htmlspecialchars($class) . $space . '">' . htmlspecialchars($label) . '</span>';
    }
}

if (!function_exists('finbuild_chat_sender_html')) {
    /**
     * Подпись отправителя в чате продукта.
     * Сотрудник без privacy.see_owner_identity не видит ФИО агента/клиента;
     * партнёр/клиент не видят ФИО сотрудника, если у роли выключено privacy.staff_identity_visible_to_owner.
     *
     * @param array<string, mixed> $message
     * @param array<string, mixed>|null $viewer
     */
    function finbuild_chat_sender_html(array $message, ?array $viewer = null): string
    {
        if ($viewer === null && function_exists('getCurrentUser')) {
            $viewer = getCurrentUser();
        }
        $viewer = is_array($viewer) ? $viewer : [];

        $senderId = (int) ($message['user_id'] ?? 0);
        $viewerId = (int) ($viewer['id'] ?? ($_SESSION['user_id'] ?? 0));
        $isOwn = $viewerId > 0 && $senderId === $viewerId;
        $senderRole = (string) ($message['role'] ?? '');
        $viewerRole = (string) ($viewer['role'] ?? ($_SESSION['role'] ?? ''));
        $viewerMasks = function_exists('finbuild_should_mask_owner_identity')
            ? finbuild_should_mask_owner_identity($viewer)
            : (function_exists('finbuild_is_case_manager') && finbuild_is_case_manager($viewer));
        $isSub = !empty($message['is_submanager']);
        $effectiveStaffRole = function_exists('finbuild_staff_privacy_role')
            ? finbuild_staff_privacy_role($senderRole, $isSub)
            : (($senderRole === 'manager' && $isSub) ? 'case_manager' : $senderRole);
        $name = trim(((string) ($message['first_name'] ?? '')) . ' ' . ((string) ($message['last_name'] ?? '')));

        if (!$isOwn && $viewerMasks && ($senderRole === 'partner' || $senderRole === 'client' || $senderRole === 'beneficiary')) {
            if ($senderRole === 'partner') {
                return finbuild_chat_role_badge_html('Агент', 'bg-info text-dark');
            }
            if ($senderRole === 'beneficiary') {
                return finbuild_chat_role_badge_html('Заказчик', 'bg-warning text-dark');
            }
            return finbuild_chat_role_badge_html('Клиент', 'bg-secondary');
        }

        $isStaffSender = in_array($effectiveStaffRole, ['director', 'manager', 'case_manager', 'analyst'], true)
            || (function_exists('finbuild_is_manager') && finbuild_is_manager($senderRole));
        $externalViewer = in_array($viewerRole, ['partner', 'client', 'beneficiary', 'bank'], true);
        $staffFioVisible = !function_exists('finbuild_staff_fio_visible_to_owner_and_bank')
            || finbuild_staff_fio_visible_to_owner_and_bank($senderRole, $isSub);

        if (!$isOwn && $externalViewer && $isStaffSender && !$staffFioVisible) {
            $maskedLabel = 'Менеджер';
            if ($effectiveStaffRole === 'director') {
                $maskedLabel = 'Руководитель';
            } elseif ($effectiveStaffRole === 'analyst') {
                $maskedLabel = 'Аналитик';
            }
            return finbuild_chat_role_badge_html($maskedLabel, 'bg-primary text-white');
        }

        $html = htmlspecialchars($name);
        if (function_exists('finbuild_is_manager') ? finbuild_is_manager($senderRole) : in_array($senderRole, ['director', 'manager', 'case_manager'], true)) {
            $managerLabel = 'Менеджер';
            if ($effectiveStaffRole === 'director' || $senderRole === 'director') {
                $managerLabel = 'Руководитель';
            } elseif ($effectiveStaffRole === 'case_manager') {
                $managerLabel = 'Менеджер по заявкам';
            }
            $html .= finbuild_chat_role_badge_html($managerLabel, 'bg-primary text-white', true);
        } elseif ($senderRole === 'analyst' || $effectiveStaffRole === 'analyst') {
            $html .= finbuild_chat_role_badge_html('Аналитик', 'bg-primary text-white', true);
        } elseif ($senderRole === 'beneficiary') {
            $html .= finbuild_chat_role_badge_html('Заказчик', 'bg-warning text-dark', true);
        }

        return $html;
    }
}

if (!function_exists('finbuild_chat_render_messages_html')) {
    /**
     * @param array<int,array<string,mixed>> $messages Rows from application(_product)_chats join users
     * @param array<int,array<int,array<string,mixed>>> $messageFiles message_id => files[]
     * @param array<string, mixed>|null $viewer
     */
    function finbuild_chat_render_messages_html(
        array $messages,
        array $messageFiles,
        int $currentUserId,
        ?array $viewer = null,
        string $fileKind = 'chat'
    ): string
    {
        if (empty($messages)) {
            return '<div class="message-system"><div class="message-content"><i class="bi bi-chat-dots me-2"></i>Чат начат. Напишите первое сообщение.</div></div>';
        }

        $out = '';
        foreach ($messages as $message) {
            $senderId = (int)($message['user_id'] ?? 0);
            $isOwn = ($senderId === $currentUserId);
            $role = (string)($message['role'] ?? '');
            $createdAt = (string)($message['created_at'] ?? '');
            $text = (string)($message['message'] ?? '');
            $mid = (int)($message['id'] ?? 0);

            $classes = ['message'];
            if ($isOwn) {
                $classes[] = 'own';
            }
            if (!$isOwn && (function_exists('finbuild_is_manager') ? finbuild_is_manager($role) : $role === 'manager')) {
                $classes[] = 'other-manager';
            }
            $classes[] = (function_exists('finbuild_is_manager') ? finbuild_is_manager($role) : $role === 'manager') ? 'from-manager' : 'from-client';

            $out .= '<div class="' . implode(' ', $classes) . '">';
            $out .= '<div class="message-header">';
            $out .= '<span class="message-sender">' . finbuild_chat_sender_html($message, $viewer) . '</span>';
            $out .= '<span class="message-time">' . htmlspecialchars(finbuild_chat_format_message_date($createdAt)) . '</span>';
            $out .= '</div>';

            if ($text !== '') {
                $out .= '<div class="message-content">' . finbuild_chat_format_message_text($text) . '</div>';
            }

            $files = $messageFiles[$mid] ?? [];
            if (!empty($files)) {
                if (!function_exists('finbuild_upload_file_url')) {
                    require_once __DIR__ . '/upload_access.php';
                }
                $out .= '<div class="message-files">';
                foreach ($files as $file) {
                    $fileId = (int) ($file['id'] ?? 0);
                    $path = $fileId > 0
                        ? finbuild_upload_file_url($fileKind, $fileId)
                        : (string) ($file['file_path'] ?? '');
                    $original = (string)($file['original_name'] ?? '');
                    $size = $file['file_size'] ?? 0;
                    $type = (string)($file['file_type'] ?? 'file');

                    $out .= '<a href="' . htmlspecialchars($path) . '" class="file-item" target="_blank" download="' . htmlspecialchars($original) . '">';
                    $out .= '<div class="file-icon"><i class="bi ' . htmlspecialchars(finbuild_chat_get_file_icon($type)) . '"></i></div>';
                    $out .= '<div class="file-info">';
                    $out .= '<div class="file-name">' . htmlspecialchars($original) . '</div>';
                    $out .= '<div class="file-size">' . htmlspecialchars(finbuild_chat_format_file_size($size)) . '</div>';
                    $out .= '</div></a>';
                }
                $out .= '</div>';
            }

            $out .= '</div>';
        }

        return $out;
    }
}

if (!function_exists('finbuild_unread_chat_union_sql')) {
    /** Сообщения чата заявки + чатов продуктов (для списков и бейджей). */
    function finbuild_unread_chat_union_sql(): string
    {
        return "(
            SELECT ac.application_id, ac.id AS chat_id, ac.created_at, ac.user_id, ac.is_read, ac.thread, u.role AS sender_role
            FROM application_chats ac
            INNER JOIN users u ON u.id = ac.user_id
            UNION ALL
            SELECT ap.application_id, apc.id AS chat_id, apc.created_at, apc.user_id, apc.is_read, apc.thread, u.role AS sender_role
            FROM application_product_chats apc
            INNER JOIN application_products ap ON ap.id = apc.application_product_id
            INNER JOIN users u ON u.id = apc.user_id
            WHERE apc.thread = 'principal'
        )";
    }
}

if (!function_exists('finbuild_unread_chat_join_condition')) {
    /**
     * Условие JOIN непрочитанных для alias `uc` и заявки `a`.
     * Для не-менеджеров использует плейсхолдер :current_user.
     */
    function finbuild_unread_chat_join_condition(string $userRole): string
    {
        if (function_exists('finbuild_is_manager') && finbuild_is_manager($userRole)) {
            return "uc.is_read = 0 AND (
                (uc.thread = 'beneficiary' AND uc.sender_role = 'beneficiary')
                OR (uc.thread = 'principal' AND uc.sender_role IN ('client', 'partner'))
            )";
        }
        if ($userRole === 'beneficiary') {
            return "uc.is_read = 0 AND uc.thread = 'beneficiary' AND uc.user_id != :current_user";
        }
        return "uc.is_read = 0 AND uc.thread = 'principal' AND uc.user_id != :current_user";
    }
}

if (!function_exists('finbuild_application_chat_counterpart_user_id')) {
    function finbuild_application_chat_counterpart_user_id(array $application, string $thread): int
    {
        $ownerId = (int) ($application['created_by'] ?? $application['application_owner_id'] ?? 0);
        $principalId = (int) ($application['principal_user_id'] ?? 0);
        if ($thread === 'beneficiary') {
            return $ownerId;
        }
        return $principalId > 0 ? $principalId : $ownerId;
    }
}

if (!function_exists('finbuild_application_chat_unread_count')) {
    function finbuild_application_chat_unread_count(
        PDO $pdo,
        int $applicationId,
        string $userRole,
        int $userId,
        array $application,
        string $activeThread
    ): int {
        $thread = function_exists('finbuild_chat_normalize_thread')
            ? finbuild_chat_normalize_thread($activeThread)
            : ($activeThread === 'beneficiary' ? 'beneficiary' : 'principal');

        if (function_exists('finbuild_is_manager') && finbuild_is_manager($userRole)) {
            if ($thread === 'beneficiary') {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM application_chats c
                     INNER JOIN users u ON u.id = c.user_id
                     WHERE c.application_id = ? AND c.thread = 'beneficiary'
                       AND c.is_read = 0 AND u.role = 'beneficiary'"
                );
                $stmt->execute([$applicationId]);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM application_chats c
                     INNER JOIN users u ON u.id = c.user_id
                     WHERE c.application_id = ? AND c.thread = 'principal'
                       AND c.is_read = 0 AND u.role IN ('client', 'partner')"
                );
                $stmt->execute([$applicationId]);
            }
        } else {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM application_chats
                 WHERE application_id = ? AND thread = ? AND is_read = 0 AND user_id != ?"
            );
            $stmt->execute([$applicationId, $thread, $userId]);
        }
        return (int) ($stmt->fetchColumn() ?: 0);
    }
}

if (!function_exists('finbuild_application_chat_unread_total')) {
    /**
     * @param list<string> $threads
     */
    function finbuild_application_chat_unread_total(
        PDO $pdo,
        int $applicationId,
        string $userRole,
        int $userId,
        array $application,
        array $threads
    ): int {
        $total = 0;
        foreach ($threads as $thread) {
            $total += finbuild_application_chat_unread_count(
                $pdo,
                $applicationId,
                $userRole,
                $userId,
                $application,
                (string) $thread
            );
        }
        return $total;
    }
}

if (!function_exists('finbuild_application_chat_mark_read')) {
    function finbuild_application_chat_mark_read(
        PDO $pdo,
        int $applicationId,
        string $userRole,
        int $userId,
        array $application,
        string $activeThread
    ): void {
        $thread = function_exists('finbuild_chat_normalize_thread')
            ? finbuild_chat_normalize_thread($activeThread)
            : ($activeThread === 'beneficiary' ? 'beneficiary' : 'principal');

        if (function_exists('finbuild_is_manager') && finbuild_is_manager($userRole)) {
            if ($thread === 'beneficiary') {
                $stmt = $pdo->prepare(
                    "UPDATE application_chats c
                     INNER JOIN users u ON u.id = c.user_id
                     SET c.is_read = 1
                     WHERE c.application_id = ? AND c.thread = 'beneficiary'
                       AND c.is_read = 0 AND u.role = 'beneficiary'"
                );
                $stmt->execute([$applicationId]);
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE application_chats c
                     INNER JOIN users u ON u.id = c.user_id
                     SET c.is_read = 1
                     WHERE c.application_id = ? AND c.thread = 'principal'
                       AND c.is_read = 0 AND u.role IN ('client', 'partner')"
                );
                $stmt->execute([$applicationId]);
            }
            return;
        }

        $stmt = $pdo->prepare(
            "UPDATE application_chats
             SET is_read = 1
             WHERE application_id = ? AND thread = ? AND user_id != ? AND is_read = 0"
        );
        $stmt->execute([$applicationId, $thread, $userId]);
    }
}

