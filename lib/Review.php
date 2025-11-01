<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/DataManager.php';
require_once __DIR__ . '/helpers.php';

class ReviewService
{
    /** @var DataManager */
    private $dm;
    public function __construct()
    {
        $this->dm = new DataManager(DATA_DIR);
    }

    private function file(int $novelId): string
    {
        return NOVEL_REVIEWS_DIR . '/' . (int)$novelId . '.json';
    }

    public function list(int $novelId): array
    {
        $f = $this->file($novelId);
        if (!file_exists($f)) return [];
        $arr = json_decode(@file_get_contents($f), true);
        if (!is_array($arr)) return [];
        return $arr;
    }

    public function add(int $novelId, int $userId, int $rating, string $content, ?int $parentId = null)
    {
        $f = $this->file($novelId);
        $this->ensureFile($f);
        $list = json_decode(@file_get_contents($f), true) ?: [];
        $max = 0; foreach ($list as $r) { $max = max($max, (int)($r['id'] ?? 0)); }
        $row = [
            'id' => $max + 1,
            'novel_id' => $novelId,
            'user_id' => $userId,
            'rating' => max(1, min(5, (int)$rating)),
            'content' => $content,
            'parent_id' => $parentId,
            'likes' => 0,
            'created_at' => date('c'),
        ];
        $list[] = $row;
        file_put_contents($f, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $row['id'];
    }

    public function like(int $novelId, int $reviewId): bool
    {
        $f = $this->file($novelId);
        $list = json_decode(@file_get_contents($f), true) ?: [];
        $changed = false;
        foreach ($list as &$r) {
            if ((int)($r['id'] ?? 0) === $reviewId) { $r['likes'] = (int)($r['likes'] ?? 0) + 1; $changed = true; break; }
        }
        unset($r);
        if ($changed) { file_put_contents($f, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); return true; }
        return false;
    }

    public function averageRating(int $novelId): float
    {
        $rows = $this->list($novelId);
        $sum = 0; $count = 0; foreach ($rows as $r) { $sum += (int)($r['rating'] ?? 0); $count++; }
        return $count ? round($sum/$count, 1) : 0.0;
    }

    private function ensureFile(string $file): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (!file_exists($file)) file_put_contents($file, '[]');
    }
}
