<?php
declare(strict_types=1);

namespace App\Services;

use Redis;

/**
 * Хранение краткоживущих модерационных контекстов в Redis:
 * - chat_id
 * - offender_user_id
 * - message_id
 * - original_text
 * - forward_admin_id
 *
 * Хранится 1 сутки. Это позволяет безопасно получить исходные данные в callback.
 */
class ModerationContextRepository
{
    private Redis $redis;
    private int $ttl;

    public function __construct(int $ttlSeconds = 86400)
    {
        $this->redis = new Redis();
        $this->redis->connect($_ENV['REDIS_HOST'], (int)$_ENV['REDIS_PORT']);
        $this->ttl = $ttlSeconds;
    }

    public function store(array $data): string
    {
        $id = bin2hex(random_bytes(8)); // компактный уникальный id
        $key = $this->key($id);
        $this->redis->set($key, json_encode($data, JSON_UNESCAPED_UNICODE), $this->ttl);
        return $id;
    }

    public function get(string $id): ?array
    {
        $raw = $this->redis->get($this->key($id));
        if (!$raw) return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function delete(string $id): void
    {
        $this->redis->del($this->key($id));
    }

    private function key(string $id): string
    {
        return "modctx:{$id}";
    }
}
