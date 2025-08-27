<?php

declare(strict_types=1);

namespace App\Services;

class TelegramApiService
{
    private string $token;
    private string $apiUrl;

    public function __construct(string $token)
    {
        $this->token = $token;
        $this->apiUrl = "https://api.telegram.org/bot{$token}/";
    }

    public function sendMessage(int $chatId, string $text, ?array $keyboard = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        if ($keyboard) {
            $params['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }
        return $this->request('sendMessage', $params);
    }

    public function deleteMessage(int $chatId, int $messageId): array
    {
        return $this->request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);
    }

    public function answerCallbackQuery(string $id, string $text): array
    {
        return $this->request('answerCallbackQuery', [
            'callback_query_id' => $id,
            'text' => $text,
            'show_alert' => false
        ]);
    }

    public function getChat(int $chatId): array
    {
        return $this->request('getChat', ['chat_id' => $chatId]);
    }

    public function isAdmin(int $chatId, int $userId): bool
    {
        $resp = $this->request('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
        $status = $resp['result']['status'] ?? '';
        return in_array($status, ['administrator', 'creator'], true);
    }

    /**
     * Логирование действий бота в спец-чат
     */
    public function log(string $message): void
    {
        $logChat = (int)($_ENV['LOG_CHAT_ID'] ?? 0);
        if ($logChat !== 0) {
            $this->sendMessage($logChat, "📋 <b>LOG</b>: " . htmlspecialchars($message));
        }
    }

    /**
     * Вспомогательный метод запроса к Telegram Bot API
     */
    private function request(string $method, array $params): array
    {
        $ch = curl_init($this->apiUrl . $method);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Telegram API error: {$err}");
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid Telegram response for {$method}");
        }
        return $data;
    }
}
