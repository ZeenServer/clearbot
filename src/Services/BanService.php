<?php
declare(strict_types=1);

namespace App\Services;

use Redis;
use PDO;

class BanService
{
    private Redis $redis;
    private PDO $db;
    private TelegramApiService $telegram;

    public function __construct(TelegramApiService $telegram)
    {
        $this->redis = new Redis();
        $this->redis->connect($_ENV['REDIS_HOST'], (int)$_ENV['REDIS_PORT']);

        $this->db = new PDO($_ENV['DB_DSN'], $_ENV['DB_USER'], $_ENV['DB_PASS'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        $this->telegram = $telegram;
    }

    /**
     * Локальный бан пользователя в чате (запись в БД + кэш в Redis).
     * $originalText — исходное сообщение (для публикации в спец-чат).
     */
    public function banUser(int $chatId, int $userId, int $adminId, ?string $originalText): void
    {
        // MySQL
        $stmt = $this->db->prepare("INSERT IGNORE INTO local_bans (chat_id, user_id, admin_id) VALUES (?, ?, ?)");
        $stmt->execute([$chatId, $userId, $adminId]);

        // Redis
        $this->redis->sAdd("banned:$chatId", (string)$userId);

        // Лог
        $this->telegram->log("🚫 Локбан: user {$userId} в чате {$chatId}, админ {$adminId}");

        // Сообщение в глобальный лог-чат с предложением добавить в глобальный ЧС
        $globalChat = (int)($_ENV['GLOBAL_LOG_CHAT_ID'] ?? 0);
        if ($globalChat !== 0) {
            // Получим имя чата
            $chatInfo = $this->telegram->getChat($chatId);
            $chatTitle = $chatInfo['result']['title'] ?? (string)$chatId;
            $msg = "👮 <b>Локальный бан</b>\n"
                . "Чат: <b>" . htmlspecialchars($chatTitle) . "</b> (<code>{$chatId}</code>)\n"
                . "Админ: <code>{$adminId}</code>\n"
                . "Пользователь: <code>{$userId}</code>\n\n";

            if ($originalText) {
                $msg .= "Исходное сообщение:\n<blockquote>" . htmlspecialchars(mb_strimwidth($originalText, 0, 1000, '…')) . "</blockquote>\n";
            }

            $keyboard = [
                'inline_keyboard' => [[
                    ['text' => '🌐 Добавить в глобальный ЧС', 'callback_data' => "globalban:{$userId}"]
                ]]
            ];

            $this->telegram->sendMessage($globalChat, $msg, $keyboard);
        }
    }

    public function globalBan(int $userId): void
    {
        // MySQL
        $stmt = $this->db->prepare("INSERT IGNORE INTO global_bans (user_id) VALUES (?)");
        $stmt->execute([$userId]);

        // Redis
        $this->redis->sAdd("global:banned", (string)$userId);

        $this->telegram->log("🌐 Глоббан: {$userId}");
    }

    public function isLocallyBanned(int $chatId, int $userId): bool
    {
        return $this->redis->sIsMember("banned:$chatId", (string)$userId);
    }

    public function isGloballyBanned(int $userId): bool
    {
        return $this->redis->sIsMember("global:banned", (string)$userId);
    }

    /**
     * Полная перезагрузка кэша банов из БД
     */
    public function reloadCache(): void
    {
        // Очистка прошлых ключей
        foreach ($this->redis->keys("banned:*") as $key) {
            $this->redis->del($key);
        }
        $this->redis->del("global:banned");

        // Локальные баны
        $stmt = $this->db->query("SELECT chat_id, user_id FROM local_bans");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->redis->sAdd("banned:{$row['chat_id']}", (string)$row['user_id']);
        }

        // Глобальные баны
        $stmt = $this->db->query("SELECT user_id FROM global_bans");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->redis->sAdd("global:banned", (string)$row['user_id']);
        }

        $this->telegram->log("🔄 Кэш банов перезагружен из БД");
    }
}
