<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Возвращает человекочитаемый текст из сообщения:
 * - text
 * - caption (для фото/видео/документов)
 */
function extractMessageText(array $message): string
{
    $text = $message['text'] ?? '';
    $caption = $message['caption'] ?? '';

    // иногда полезно объединить (если и text, и caption есть — склеим)
    $combined = trim($text . ' ' . $caption);
    return $combined;
}
