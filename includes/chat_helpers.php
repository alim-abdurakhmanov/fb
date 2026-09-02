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
     * Субменеджер не видит ФИО агента/клиента; агент/клиент не видят ФИО субменеджера.
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
        $viewerIsSub = function_exists('finbuild_is_submanager')
            ? finbuild_is_submanager($viewer)
            : ($viewerRole === 'manager' && !empty($viewer['is_submanager']));
        $senderIsSub = $senderRole === 'manager' && !empty($message['is_submanager']);
        $name = trim(((string) ($message['first_name'] ?? '')) . ' ' . ((string) ($message['last_name'] ?? '')));

        if (!$isOwn && $viewerIsSub && ($senderRole === 'partner' || $senderRole === 'client')) {
            if ($senderRole === 'partner') {
                return finbuild_chat_role_badge_html('Агент', 'bg-info text-dark');
            }
            return finbuild_chat_role_badge_html('Клиент', 'bg-secondary');
        }

        if (!$isOwn && ($viewerRole === 'partner' || $viewerRole === 'client') && $senderIsSub) {
            return finbuild_chat_role_badge_html('Менеджер', 'bg-primary text-white');
        }

        $html = htmlspecialchars($name);
        if ($senderRole === 'manager') {
            $directorIds = defined('FINBUILD_DIRECTOR_USER_IDS') ? FINBUILD_DIRECTOR_USER_IDS : [];
            $managerLabel = in_array($senderId, $directorIds, true) ? 'Руководитель' : 'Менеджер';
            $html .= finbuild_chat_role_badge_html($managerLabel, 'bg-primary text-white', true);
        }

        return $html;
    }
}

if (!function_exists('finbuild_chat_render_messages_html')) {
    /**
     * @param array<int,array<string,mixed>> $messages Rows from application_product_chats join users
     * @param array<int,array<int,array<string,mixed>>> $messageFiles message_id => files[]
     * @param array<string, mixed>|null $viewer
     */
    function finbuild_chat_render_messages_html(array $messages, array $messageFiles, int $currentUserId, ?array $viewer = null): string
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
            if (!$isOwn && $role === 'manager') {
                $classes[] = 'other-manager';
            }
            $classes[] = ($role === 'manager') ? 'from-manager' : 'from-client';

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
                        ? finbuild_upload_file_url('chat', $fileId)
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

