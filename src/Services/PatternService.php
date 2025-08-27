<?php

declare(strict_types=1);

namespace App\Services;

use Redis;
use PDO;

class PatternService
{
    private Redis $redis;
    private PDO $db;

    public function __construct()
    {
        $this->redis = new Redis();
        $this->redis->connect($_ENV['REDIS_HOST'], (int)$_ENV['REDIS_PORT']);

        $this->db = new PDO($_ENV['DB_DSN'], $_ENV['DB_USER'], $_ENV['DB_PASS'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    }

    /**
     * Проверка строки на наличие любого из паттернов (регистр игнорируем)
     */
    public function match(string $text): bool
    {
        $text = mb_strtolower($text);
        if ($text === '') {
            return false;
        }

        $patterns = $this->getPatterns();
        foreach ($patterns as $pattern) {
            if ($pattern === '') continue;
            if (str_contains($text, mb_strtolower($pattern))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Возвращает кэш паттернов, при пустом — перечитывает из БД
     */
    public function getPatterns(): array
    {
        $patterns = $this->redis->lRange('patterns', 0, -1);
        if (empty($patterns)) {
            $stmt = $this->db->query("SELECT pattern FROM patterns ORDER BY id ASC");
            $patterns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $this->reloadCache($patterns);
        }
        return $patterns;
    }

    /**
     * Полная перезагрузка кэша паттернов
     */
    public function reloadCache(?array $patterns = null): void
    {
        $this->redis->del('patterns');

        if ($patterns === null) {
            $stmt = $this->db->query("SELECT pattern FROM patterns ORDER BY id ASC");
            $patterns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        if (!empty($patterns)) {
            // загружаем в том же порядке
            foreach ($patterns as $p) {
                $this->redis->rPush('patterns', (string)$p);
            }
        }
    }
}
