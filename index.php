<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Services\TelegramApiService;
use App\Services\PatternService;
use App\Services\BanService;
use App\Services\ModerationContextRepository;
use function App\Support\extractMessageText;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$telegram = new TelegramApiService($_ENV['TELEGRAM_BOT_TOKEN']);
$patternService = new PatternService();
$banService = new BanService($telegram);
$ctxRepo = new ModerationContextRepository();

$raw = file_get_contents('php://input');
$update = json_decode($raw, true) ?? [];

// Безопасно выходим, если апдейт пуст
if (!$update) {
    http_response_code(200);
    exit('OK');
}

try {
    if (isset($update['message'])) {
        handleMessage($update['message'], $telegram, $patternService, $banService, $ctxRepo);
    } elseif (isset($update['callback_query'])) {
        handleCallback($update['callback_query'], $telegram, $banService, $ctxRepo);
    }
} catch (Throwable $e) {
    // В лог-чат отправляем краткую ошибку
    $telegram->log("❗ Ошибка: " . $e->getMessage());
}

/**
 * Обработка входящего сообщения/медиа
 */
function handleMessage(
    array $message,
    TelegramApiService $telegram,
    PatternService $patterns,
    BanService $bans,
    ModerationContextRepository $ctxRepo
): void {
    $chatId   = (int)$message['chat']['id'];
    $msgId    = (int)$message['message_id'];
    $from     = $message['from'] ?? [];
    $userId   = (int)($from['id'] ?? 0);
    $text     = extractMessageText($message); // учитывает text и caption

    // 🔑 Команда владельца: /reload_cache
    if (($message['text'] ?? '') === '/reload_cache' && $userId === (int)$_ENV['OWNER_ID']) {
        $patterns->reloadCache();
        $bans->reloadCache();
        $telegram->sendMessage($chatId, "✅ Кэш обновлён из БД");
        $telegram->log("Владелец {$userId} выполнил /reload_cache");
        return;
    }

    // 1) Глоб/лок бан: удаляем сразу
    if ($bans->isGloballyBanned($userId) || $bans->isLocallyBanned($chatId, $userId)) {
        $telegram->deleteMessage($chatId, $msgId);
        $telegram->log("🧹 Автоудаление: пользователь {$userId} (бан) в чате {$chatId}");
        return;
    }

    // 2) Паттерны: при пустом кэше — перечитают из БД автоматически
    if ($text !== '' && $patterns->match($text)) {
        $telegram->deleteMessage($chatId, $msgId);
        $telegram->log("🧹 Удалено по паттерну в чате {$chatId} от {$userId}: " . mb_strimwidth($text, 0, 140, '…'));
        return;
    }

    // 3) Если переслано «от лица администратора чата» — показать кнопки
    if (isset($message['forward_from']) && $telegram->isAdmin($chatId, (int)$message['forward_from']['id'])) {
        // Подготовим модерационный контекст (сохраняем в Redis)
        $ctxId = $ctxRepo->store([
            'chat_id'          => $chatId,
            'offender_user_id' => $userId,                 // пользователь, сообщение которого рассматриваем
            'message_id'       => $msgId,
            'original_text'    => $text,
            'forward_admin_id' => (int)$message['forward_from']['id']
        ]);

        $keyboard = [
            'inline_keyboard' => [[
                ['text' => '🚫 Бан',      'callback_data' => "banctx:{$ctxId}"],
                ['text' => '❌ Удалить',  'callback_data' => "delctx:{$ctxId}"]
            ]]
        ];

        $telegram->sendMessage($chatId, "Что сделать с пользователем?", $keyboard);
    }
}

/**
 * Обработка нажатий на inline-кнопки
 */
function handleCallback(
    array $callback,
    TelegramApiService $telegram,
    BanService $bans,
    ModerationContextRepository $ctxRepo
): void {
    $data = $callback['data'] ?? '';
    $parts = explode(':', $data, 2);
    $action = $parts[0] ?? '';
    $arg = $parts[1] ?? '';

    // для ответов на колбэк
    $ack = fn(string $text) => $telegram->answerCallbackQuery($callback['id'], $text);

    switch ($action) {
        case 'ban':
            // устаревший вариант (оставлен для совместимости)
            [$userId, $chatId] = array_map('intval', explode(':', $arg));
            $bans->banUser($chatId, $userId, (int)$callback['from']['id'], null);
            $ack("Пользователь забанен");
            break;

        case 'del':
            [$msgId, $chatId] = array_map('intval', explode(':', $arg));
            $telegram->deleteMessage($chatId, $msgId);
            $telegram->log("🗑️ Удалено сообщение {$msgId} по кнопке админа в чате {$chatId}");
            $ack("Сообщение удалено");
            break;

        case 'globalban':
            $userId = (int)$arg;
            $bans->globalBan($userId);
            $telegram->log("🌐 Глоббан: {$userId}, инициатор {$callback['from']['id']}");
            $ack("Пользователь добавлен в глобальный ЧС");
            break;

        case 'banctx':
        case 'delctx':
            $ctxId = $arg;
            $ctx = $ctxRepo->get($ctxId);
            if (!$ctx) {
                $ack("Контекст не найден/протух");
                return;
            }

            $chatId   = (int)$ctx['chat_id'];
            $userId   = (int)$ctx['offender_user_id'];
            $msgId    = (int)$ctx['message_id'];
            $origText = (string)($ctx['original_text'] ?? '');
            $adminId  = (int)$callback['from']['id'];

            if ($action === 'banctx') {
                // бан + глоб-чат с предложением
                $bans->banUser($chatId, $userId, $adminId, $origText);
                $ack("Пользователь забанен");
            } else {
                // удаление
                $telegram->deleteMessage($chatId, $msgId);
                $telegram->log("🗑️ Удалено сообщение {$msgId} (ctx) в чате {$chatId} админом {$adminId}");
                $ack("Сообщение удалено");
            }

            // одноразовый контекст
            $ctxRepo->delete($ctxId);
            break;

        default:
            $ack("Неизвестное действие");
            break;
    }
}
