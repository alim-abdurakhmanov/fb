<?php
// Создание дефолтного аватара с инициалами
function generateDefaultAvatar($name, $size = 200) {
    // Генерируем цвет на основе имени
    $hash = md5($name);
    $color = substr($hash, 0, 6);
    
    // Получаем инициалы
    $parts = explode(' ', $name);
    $initials = '';
    foreach ($parts as $part) {
        if (!empty($part)) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }
    if (empty($initials)) {
        $initials = '?';
    }
    
    // Создаем изображение
    $image = imagecreatetruecolor($size, $size);
    $bgColor = imagecolorallocate($image, hexdec(substr($color, 0, 2)), hexdec(substr($color, 2, 2)), hexdec(substr($color, 4, 2)));
    $textColor = imagecolorallocate($image, 255, 255, 255);
    
    imagefill($image, 0, 0, $bgColor);
    
    // Добавляем текст (инициалы)
    $font = 5; // Встроенный шрифт GD
    $fontSize = $size / 2;
    $bbox = imagettfbbox($fontSize, 0, __DIR__ . '/arial.ttf', $initials); // Нужен шрифт
    $x = ($size - ($bbox[2] - $bbox[0])) / 2;
    $y = ($size - ($bbox[7] - $bbox[1])) / 2;
    
    // Используем imagestring для простоты (не требует шрифтов)
    $textWidth = imagefontwidth($font) * strlen($initials);
    $textHeight = imagefontheight($font);
    $x = ($size - $textWidth) / 2;
    $y = ($size - $textHeight) / 2;
    
    imagestring($image, $font, $x, $y, $initials, $textColor);
    
    // Сохраняем или выводим
    ob_start();
    imagepng($image);
    $imageData = ob_get_clean();
    imagedestroy($image);
    
    return 'data:image/png;base64,' . base64_encode($imageData);
}