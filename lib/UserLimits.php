<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/DataManager.php';
require_once __DIR__ . '/Auth.php';

class UserLimits
{
    private DataManager $dm;
    const USER_LIMITS_FILE = DATA_DIR . '/user_limits.json';
    const USER_USAGE_FILE = DATA_DIR . '/user_usage.json';

    public function __construct()
    {
        $this->dm = new DataManager(DATA_DIR);
        $this->ensureFiles();
    }

    private function ensureFiles(): void
    {
        if (!file_exists(self::USER_LIMITS_FILE)) {
            $initial = [
                'default_limits' => $this->getDefaultLimits(),
                'group_limits' => [],
                'user_limits' => []
            ];
            file_put_contents(self::USER_LIMITS_FILE, json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $existing = json_decode(file_get_contents(self::USER_LIMITS_FILE), true) ?: [];
            $updated = false;
            if (!isset($existing['default_limits'])) {
                $existing['default_limits'] = $this->getDefaultLimits();
                $updated = true;
            }
            if (!isset($existing['group_limits']) || !is_array($existing['group_limits'])) {
                $existing['group_limits'] = [];
                $updated = true;
            }
            if (!isset($existing['user_limits']) || !is_array($existing['user_limits'])) {
                $existing['user_limits'] = [];
                $updated = true;
            }
            if ($updated) {
                file_put_contents(self::USER_LIMITS_FILE, json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
        if (!file_exists(self::USER_USAGE_FILE)) {
            file_put_contents(self::USER_USAGE_FILE, '[]');
        }
    }

    public function getDefaultLimits(): array
    {
        return [
            'enabled' => false,
            'daily_chapter_limit' => 0,
            'daily_reading_time_limit' => 0,
            'concurrent_novels_limit' => 0,
            'download_limit_per_day' => 0,
        ];
    }

    public function getUserLimit(int $userId, ?string $userRole = null): array
    {
        $limitsData = json_decode(file_get_contents(self::USER_LIMITS_FILE), true) ?: [];
        
        if (isset($limitsData['user_limits'][$userId])) {
            return array_merge($this->getDefaultLimits(), $limitsData['user_limits'][$userId]);
        }
        
        if ($userRole === null) {
            $userRole = $this->resolveUserRole($userId);
        }
        
        if ($userRole && isset($limitsData['group_limits'][$userRole])) {
            return array_merge($this->getDefaultLimits(), $limitsData['group_limits'][$userRole]);
        }
        
        return array_merge($this->getDefaultLimits(), $limitsData['default_limits'] ?? []);
    }

    private function resolveUserRole(int $userId): string
    {
        $users = $this->dm->readJson(USERS_FILE, []);
        foreach ($users as $u) {
            if ((int)($u['id'] ?? 0) === $userId) {
                return $u['role'] ?? Auth::ROLE_USER;
            }
        }
        return Auth::ROLE_USER;
    }

    public function getGroupLimit(string $role): array
    {
        $limitsData = json_decode(file_get_contents(self::USER_LIMITS_FILE), true) ?: [];
        
        if (isset($limitsData['group_limits'][$role])) {
            return array_merge($this->getDefaultLimits(), $limitsData['group_limits'][$role]);
        }
        
        return array_merge($this->getDefaultLimits(), $limitsData['default_limits'] ?? []);
    }

    public function getStoredDefaultLimits(): array
    {
        $limitsData = json_decode(file_get_contents(self::USER_LIMITS_FILE), true) ?: [];
        return array_merge($this->getDefaultLimits(), $limitsData['default_limits'] ?? []);
    }

    public function setGroupLimit(string $role, array $limits): bool
    {
        $limitsData = json_decode(file_get_contents(self::USER_LIMITS_FILE), true) ?: [];
        
        if (!isset($limitsData['group_limits'])) {
            $limitsData['group_limits'] = [];
        }
        
        $limitsData['group_limits'][$role] = array_merge($this->getDefaultLimits(), $limits);
        
        return (bool)file_put_contents(self::USER_LIMITS_FILE, json_encode($limitsData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function removeGroupLimit(string $role): bool
    {
        $limitsData = json_decode(file_get_contents(self::USER_LIMITS_FILE), true) ?: [];
        
        if (isset($limitsData['group_limits'][$role])) {
            unset($limitsData['group_limits'][$role]);
            return (bool)file_put_contents(self::USER_LIMITS_FILE, json_encode($limitsData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        
        return true;
    }

    public function getAllGroupLimits(): array
    {
        $limitsData = json_decode(file_get_contents(self::USER_LIMITS_FILE), true) ?: [];
        return $limitsData['group_limits'] ?? [];
    }

    public function setUserLimit(int $userId, array $limits): bool
    {
        $limitsData = json_decode(file_get_contents(self::USER_LIMITS_FILE), true) ?: [];
        
        if (!isset($limitsData['user_limits'])) {
            $limitsData['user_limits'] = [];
        }
        
        $limitsData['user_limits'][$userId] = array_merge($this->getDefaultLimits(), $limits);
        
        return (bool)file_put_contents(self::USER_LIMITS_FILE, json_encode($limitsData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function setDefaultLimits(array $limits): bool
    {
        $limitsData = json_decode(file_get_contents(self::USER_LIMITS_FILE), true) ?: [];
        $limitsData['default_limits'] = array_merge($this->getDefaultLimits(), $limits);
        
        return (bool)file_put_contents(self::USER_LIMITS_FILE, json_encode($limitsData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function removeUserLimit(int $userId): bool
    {
        $limitsData = json_decode(file_get_contents(self::USER_LIMITS_FILE), true) ?: [];
        
        if (isset($limitsData['user_limits'][$userId])) {
            unset($limitsData['user_limits'][$userId]);
            return (bool)file_put_contents(self::USER_LIMITS_FILE, json_encode($limitsData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        
        return true;
    }

    public function getUserUsage(int $userId, string $date = null): array
    {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        $usageData = $this->dm->readJson(self::USER_USAGE_FILE, []);
        
        foreach ($usageData as $usage) {
            if ((int)($usage['user_id'] ?? 0) === $userId && ($usage['date'] ?? '') === $date) {
                return $usage;
            }
        }
        
        return [
            'user_id' => $userId,
            'date' => $date,
            'chapters_read' => 0,
            'reading_time_minutes' => 0,
            'novels_read' => [],
            'downloads_count' => 0,
        ];
    }

    public function recordChapterRead(int $userId, int $novelId, int $chapterId): bool
    {
        $date = date('Y-m-d');
        $usageData = $this->dm->readJson(self::USER_USAGE_FILE, []);
        $found = false;
        
        foreach ($usageData as &$usage) {
            if ((int)($usage['user_id'] ?? 0) === $userId && ($usage['date'] ?? '') === $date) {
                $usage['chapters_read'] = (int)($usage['chapters_read'] ?? 0) + 1;
                
                if (!isset($usage['novels_read'])) {
                    $usage['novels_read'] = [];
                }
                if (!in_array($novelId, $usage['novels_read'])) {
                    $usage['novels_read'][] = $novelId;
                }
                
                if (!isset($usage['chapters_list'])) {
                    $usage['chapters_list'] = [];
                }
                $usage['chapters_list'][] = [
                    'novel_id' => $novelId,
                    'chapter_id' => $chapterId,
                    'timestamp' => date('c'),
                ];
                
                $found = true;
                break;
            }
        }
        unset($usage);
        
        if (!$found) {
            $usageData[] = [
                'user_id' => $userId,
                'date' => $date,
                'chapters_read' => 1,
                'reading_time_minutes' => 0,
                'novels_read' => [$novelId],
                'downloads_count' => 0,
                'chapters_list' => [
                    [
                        'novel_id' => $novelId,
                        'chapter_id' => $chapterId,
                        'timestamp' => date('c'),
                    ]
                ],
            ];
        }
        
        return $this->dm->writeJson(self::USER_USAGE_FILE, $usageData);
    }

    public function recordReadingTime(int $userId, int $minutes): bool
    {
        $date = date('Y-m-d');
        $usageData = $this->dm->readJson(self::USER_USAGE_FILE, []);
        $found = false;
        
        foreach ($usageData as &$usage) {
            if ((int)($usage['user_id'] ?? 0) === $userId && ($usage['date'] ?? '') === $date) {
                $usage['reading_time_minutes'] = (int)($usage['reading_time_minutes'] ?? 0) + $minutes;
                $found = true;
                break;
            }
        }
        unset($usage);
        
        if (!$found) {
            $usageData[] = [
                'user_id' => $userId,
                'date' => $date,
                'chapters_read' => 0,
                'reading_time_minutes' => $minutes,
                'novels_read' => [],
                'downloads_count' => 0,
            ];
        }
        
        return $this->dm->writeJson(self::USER_USAGE_FILE, $usageData);
    }

    public function checkLimit(int $userId, string $limitType = 'chapter'): array
    {
        $limits = $this->getUserLimit($userId);
        
        if (!($limits['enabled'] ?? false)) {
            return ['allowed' => true, 'reason' => 'limits_disabled'];
        }
        
        $usage = $this->getUserUsage($userId);
        
        if ($limitType === 'chapter') {
            $limit = (int)($limits['daily_chapter_limit'] ?? 0);
            if ($limit > 0) {
                $used = (int)($usage['chapters_read'] ?? 0);
                if ($used >= $limit) {
                    return ['allowed' => false, 'reason' => 'chapter_limit_reached', 'limit' => $limit, 'used' => $used];
                }
                return ['allowed' => true, 'reason' => 'within_limit', 'limit' => $limit, 'used' => $used];
            }
        }
        
        if ($limitType === 'reading_time') {
            $limit = (int)($limits['daily_reading_time_limit'] ?? 0);
            if ($limit > 0) {
                $used = (int)($usage['reading_time_minutes'] ?? 0);
                if ($used >= $limit) {
                    return ['allowed' => false, 'reason' => 'time_limit_reached', 'limit' => $limit, 'used' => $used];
                }
                return ['allowed' => true, 'reason' => 'within_limit', 'limit' => $limit, 'used' => $used];
            }
        }
        
        if ($limitType === 'concurrent_novels') {
            $limit = (int)($limits['concurrent_novels_limit'] ?? 0);
            if ($limit > 0) {
                $used = count($usage['novels_read'] ?? []);
                if ($used >= $limit) {
                    return ['allowed' => false, 'reason' => 'novel_limit_reached', 'limit' => $limit, 'used' => $used];
                }
                return ['allowed' => true, 'reason' => 'within_limit', 'limit' => $limit, 'used' => $used];
            }
        }
        
        return ['allowed' => true, 'reason' => 'no_limit_set'];
    }

    public function getAllUserLimits(): array
    {
        $limitsData = json_decode(file_get_contents(self::USER_LIMITS_FILE), true) ?: [];
        return $limitsData['user_limits'] ?? [];
    }
}
