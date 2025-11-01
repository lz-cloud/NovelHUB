<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/DataManager.php';

class Notifier
{
    /** @var DataManager */
    private $dm;
    public function __construct()
    {
        $this->dm = new DataManager(DATA_DIR);
    }

    public function notify(int $userId, string $type, string $title, string $message, ?string $link = null): int
    {
        $list = $this->dm->readJson(NOTIFICATIONS_FILE, []);
        $max = 0; foreach ($list as $n) { $max = max($max, (int)($n['id'] ?? 0)); }
        $row = [
            'id' => $max + 1,
            'user_id' => $userId,
            'type' => $type, // system|interaction|update
            'title' => $title,
            'message' => $message,
            'link' => $link,
            'read' => false,
            'created_at' => date('c'),
        ];
        $list[] = $row;
        $this->dm->writeJson(NOTIFICATIONS_FILE, $list);
        return $row['id'];
    }

    public function fetch(int $userId, bool $onlyUnread = false, int $limit = 50): array
    {
        $list = $this->dm->readJson(NOTIFICATIONS_FILE, []);
        $rows = array_values(array_filter($list, function($n) use ($userId, $onlyUnread){
            if ((int)($n['user_id'] ?? 0) !== $userId) return false;
            if ($onlyUnread && !empty($n['read'])) return false;
            return true;
        }));
        usort($rows, function($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });
        return array_slice($rows, 0, $limit);
    }

    public function markRead(int $userId, int $id): bool
    {
        $list = $this->dm->readJson(NOTIFICATIONS_FILE, []);
        $changed = false;
        foreach ($list as &$n) {
            if ((int)($n['user_id'] ?? 0) === $userId && (int)($n['id'] ?? 0) === $id) {
                $n['read'] = true; $changed = true; break;
            }
        }
        unset($n);
        if ($changed) return $this->dm->writeJson(NOTIFICATIONS_FILE, $list);
        return false;
    }
}
