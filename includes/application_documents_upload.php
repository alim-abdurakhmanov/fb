<?php
/**
 * Загрузка документов заявки: допустимые форматы и проверки.
 */
declare(strict_types=1);

function finbuild_application_document_allowed_extensions(): array
{
    return ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'zip', 'rar', '7z'];
}

function finbuild_application_document_archive_extensions(): array
{
    return ['zip', 'rar', '7z'];
}

function finbuild_application_document_max_size_bytes(string $extension): int
{
    $ext = strtolower($extension);
    if (in_array($ext, finbuild_application_document_archive_extensions(), true)) {
        return 50 * 1024 * 1024;
    }
    return 10 * 1024 * 1024;
}

function finbuild_application_document_accept_attribute(): string
{
    return '.' . implode(',.', finbuild_application_document_allowed_extensions());
}

function finbuild_application_document_formats_hint(): string
{
    return 'PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, ZIP, RAR, 7Z';
}

function finbuild_application_document_size_hint(): string
{
    return 'документы до 10 МБ, архивы до 50 МБ';
}

function finbuild_application_document_upload_hint(): string
{
    return finbuild_application_document_formats_hint() . ' (' . finbuild_application_document_size_hint() . ')';
}

function finbuild_application_document_file_type(string $extension): string
{
    $types = [
        'pdf' => 'pdf',
        'doc' => 'word',
        'docx' => 'word',
        'xls' => 'excel',
        'xlsx' => 'excel',
        'jpg' => 'image',
        'jpeg' => 'image',
        'png' => 'image',
        'zip' => 'archive',
        'rar' => 'archive',
        '7z' => 'archive',
    ];

    return $types[strtolower($extension)] ?? 'file';
}

/**
 * @throws Exception
 */
function finbuild_application_document_validate_upload(string $originalName, int $fileSize): void
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, finbuild_application_document_allowed_extensions(), true)) {
        throw new Exception(
            'Недопустимый формат файла: ' . $originalName . '. Разрешены: '
            . finbuild_application_document_formats_hint()
        );
    }

    $maxSize = finbuild_application_document_max_size_bytes($ext);
    if ($fileSize > $maxSize) {
        $maxMb = (int) round($maxSize / (1024 * 1024));
        throw new Exception('Файл слишком большой: ' . $originalName . ' (макс. ' . $maxMb . ' МБ)');
    }
}
