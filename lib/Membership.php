<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/DataManager.php';
require_once __DIR__ . '/Auth.php';

class Membership
{
    /** @var DataManager */
    private $dm;

    public function __construct()
    {
        $this->dm = new DataManager(DATA_DIR);
    }

    private function refreshUserSession(int $userId): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (!isset($_SESSION['user']) || (int)($_SESSION['user']['id'] ?? 0) !== $userId) {
            return;
        }
        $user = $this->dm->findById(USERS_FILE, $userId);
        if ($user) {
            $_SESSION['user'] = $user;
        }
    }

    private function syncUserGroup(int $userId, bool $hasActiveMembership): void
    {
        if ($userId <= 0) {
            return;
        }

        $user = $this->dm->findById(USERS_FILE, $userId);
        if (!$user) {
            return;
        }

        $currentRole = $user['role'] ?? Auth::ROLE_USER;

        if ($hasActiveMembership) {
            if ($currentRole === Auth::ROLE_USER) {
                $this->dm->updateById(USERS_FILE, $userId, ['role' => Auth::ROLE_PLUS]);
                $this->refreshUserSession($userId);
            }
            return;
        }

        if ($currentRole === Auth::ROLE_PLUS) {
            $this->dm->updateById(USERS_FILE, $userId, ['role' => Auth::ROLE_USER]);
            $this->refreshUserSession($userId);
        }
    }

    /**
     * Get membership settings
     */
    public function getSettings(): array
    {
        $settings = json_decode(@file_get_contents(SYSTEM_SETTINGS_FILE), true) ?: [];
        $membership = $settings['membership'] ?? [];
        if (!isset($membership['code_length'])) {
            $membership['code_length'] = 8;
        }
        if (!isset($membership['plan_description'])) {
            $membership['plan_description'] = '兑换码激活 Plus 会员';
        }
        if (!isset($membership['free_features']) || !is_array($membership['free_features'])) {
            $membership['free_features'] = [
                '无限阅读所有作品',
                '创建和发布作品',
                '每日下载 3 次',
                '书架收藏功能',
                '阅读进度同步',
                '书签功能'
            ];
        }
        if (!isset($membership['plus_features']) || !is_array($membership['plus_features'])) {
            $membership['plus_features'] = [
                '包含免费版所有功能',
                '无限次数下载',
                '支持 TXT、EPUB、PDF 格式',
                '优先获得新功能',
                '专属会员标识',
                '无广告体验'
            ];
        }
        return $membership;
    }

    /**
     * Check if user has active Plus membership
     */
    public function isPlusUser(int $userId): bool
    {
        $memberships = $this->dm->readJson(PLUS_MEMBERSHIPS_FILE, []);
        $hasActive = false;
        foreach ($memberships as $m) {
            if ((int)($m['user_id'] ?? 0) === $userId) {
                $expiresAt = $m['expires_at'] ?? null;
                if ($expiresAt && strtotime($expiresAt) > time()) {
                    $hasActive = true;
                    break;
                }
            }
        }
        $this->syncUserGroup($userId, $hasActive);
        return $hasActive;
    }

    /**
     * Get user's Plus membership info
     */
    public function getUserMembership(int $userId): ?array
    {
        $memberships = $this->dm->readJson(PLUS_MEMBERSHIPS_FILE, []);
        foreach ($memberships as $m) {
            if ((int)($m['user_id'] ?? 0) === $userId) {
                $expiresAt = $m['expires_at'] ?? null;
                if ($expiresAt && strtotime($expiresAt) > time()) {
                    $this->syncUserGroup($userId, true);
                    return $m;
                }
            }
        }
        $this->syncUserGroup($userId, false);
        return null;
    }

    /**
     * Redeem a code for Plus membership
     */
    public function redeemCode(int $userId, string $code): array
    {
        if ($userId <= 0) {
            return ['success' => false, 'error' => '无效的用户ID'];
        }
        
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['success' => false, 'error' => '兑换码不能为空'];
        }
        
        if (strlen($code) < 4 || strlen($code) > 64) {
            return ['success' => false, 'error' => '兑换码格式无效'];
        }

        $codes = $this->dm->readJson(REDEMPTION_CODES_FILE, []);
        if (!is_array($codes)) {
            $codes = [];
        }
        
        $codeRecord = null;
        $codeIndex = null;

        foreach ($codes as $i => $c) {
            if (!is_array($c)) continue;
            if (strtoupper($c['code'] ?? '') === $code) {
                $codeRecord = $c;
                $codeIndex = $i;
                break;
            }
        }

        if (!$codeRecord) {
            return ['success' => false, 'error' => '兑换码不存在'];
        }

        if (($codeRecord['status'] ?? 'active') !== 'active') {
            return ['success' => false, 'error' => '兑换码已失效或已被使用'];
        }

        $expiresAt = $codeRecord['expires_at'] ?? null;
        if ($expiresAt && strtotime($expiresAt) < time()) {
            return ['success' => false, 'error' => '兑换码已过期'];
        }

        $maxUses = (int)($codeRecord['max_uses'] ?? 1);
        $usedCount = (int)($codeRecord['used_count'] ?? 0);
        if ($usedCount >= $maxUses) {
            return ['success' => false, 'error' => '兑换码已达到最大使用次数'];
        }

        // Add or extend membership
        $durationDays = (int)($codeRecord['duration_days'] ?? 30);
        $memberships = $this->dm->readJson(PLUS_MEMBERSHIPS_FILE, []);
        $found = false;

        $newExpiresAt = null;

        foreach ($memberships as &$m) {
            if ((int)($m['user_id'] ?? 0) === $userId) {
                $currentExpires = $m['expires_at'] ?? date('c');
                $currentTimestamp = max(time(), strtotime($currentExpires));
                $newExpires = date('c', $currentTimestamp + ($durationDays * 86400));
                $m['expires_at'] = $newExpires;
                $m['updated_at'] = date('c');
                $found = true;
                $newExpiresAt = $newExpires;
                break;
            }
        }
        unset($m);

        if (!$found) {
            $newExpiresAt = date('c', time() + ($durationDays * 86400));
            $memberships[] = [
                'user_id' => $userId,
                'expires_at' => $newExpiresAt,
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ];
        }

        $this->dm->writeJson(PLUS_MEMBERSHIPS_FILE, $memberships);
        $this->syncUserGroup($userId, true);

        // Update code usage
        $codes[$codeIndex]['used_count'] = $usedCount + 1;
        if ($codes[$codeIndex]['used_count'] >= $maxUses) {
            $codes[$codeIndex]['status'] = 'used';
        }
        $codes[$codeIndex]['last_used_at'] = date('c');
        $codes[$codeIndex]['last_used_by'] = $userId;
        $this->dm->writeJson(REDEMPTION_CODES_FILE, $codes);

        return [
            'success' => true,
            'duration_days' => $durationDays,
            'expires_at' => $newExpiresAt
        ];
    }

    /**
     * Generate a new redemption code
     */
    public function generateCode(int $durationDays, int $maxUses = 1, ?string $expiresAt = null): string
    {
        $settings = $this->getSettings();
        $length = (int)($settings['code_length'] ?? 8);
        
        // Generate code with specified length
        $code = strtoupper(bin2hex(random_bytes((int)ceil($length / 2))));
        $code = substr($code, 0, $length);
        
        $codes = $this->dm->readJson(REDEMPTION_CODES_FILE, []);

        $codeData = [
            'code' => $code,
            'duration_days' => $durationDays,
            'max_uses' => $maxUses,
            'used_count' => 0,
            'status' => 'active',
            'expires_at' => $expiresAt,
            'created_at' => date('c'),
        ];

        $id = $this->dm->appendWithId(REDEMPTION_CODES_FILE, $codeData, 'id');
        return $code;
    }

    /**
     * Get all redemption codes
     */
    public function getAllCodes(): array
    {
        return $this->dm->readJson(REDEMPTION_CODES_FILE, []);
    }

    /**
     * Disable a redemption code
     */
    public function disableCode(int $codeId): bool
    {
        return $this->dm->updateById(REDEMPTION_CODES_FILE, $codeId, ['status' => 'disabled'], 'id');
    }
}

class DownloadManager
{
    /** @var DataManager */
    private $dm;
    /** @var Membership */
    private $membership;
    const DAILY_LIMIT = 3;

    public function __construct()
    {
        $this->dm = new DataManager(DATA_DIR);
        $this->membership = new Membership();
    }

    /**
     * Check if user can download
     */
    public function canDownload(int $userId): array
    {
        // Plus users have unlimited downloads
        if ($this->membership->isPlusUser($userId)) {
            return ['allowed' => true, 'reason' => 'plus'];
        }

        // Check daily limit for regular users
        $today = date('Y-m-d');
        $downloads = $this->dm->readJson(DOWNLOADS_FILE, []);
        $todayCount = 0;

        foreach ($downloads as $d) {
            if ((int)($d['user_id'] ?? 0) === $userId) {
                $downloadDate = substr($d['downloaded_at'] ?? '', 0, 10);
                if ($downloadDate === $today) {
                    $todayCount++;
                }
            }
        }

        if ($todayCount >= self::DAILY_LIMIT) {
            return ['allowed' => false, 'reason' => 'limit_reached', 'count' => $todayCount, 'limit' => self::DAILY_LIMIT];
        }

        return ['allowed' => true, 'reason' => 'within_limit', 'count' => $todayCount, 'limit' => self::DAILY_LIMIT];
    }

    /**
     * Record a download
     */
    public function recordDownload(int $userId, int $novelId, string $format): bool
    {
        $check = $this->canDownload($userId);
        if (!$check['allowed']) {
            return false;
        }

        $download = [
            'user_id' => $userId,
            'novel_id' => $novelId,
            'format' => $format,
            'downloaded_at' => date('c'),
        ];

        $this->dm->appendWithId(DOWNLOADS_FILE, $download, 'id');
        return true;
    }

    /**
     * Get user's download history
     */
    public function getUserDownloads(int $userId, int $limit = 50): array
    {
        $downloads = $this->dm->readJson(DOWNLOADS_FILE, []);
        $userDownloads = array_filter($downloads, function($d) use ($userId) {
            return (int)($d['user_id'] ?? 0) === $userId;
        });

        usort($userDownloads, function($a, $b) {
            return strcmp($b['downloaded_at'] ?? '', $a['downloaded_at'] ?? '');
        });

        return array_slice(array_values($userDownloads), 0, $limit);
    }

    /**
     * Get download statistics
     */
    public function getDownloadStats(): array
    {
        $downloads = $this->dm->readJson(DOWNLOADS_FILE, []);
        $today = date('Y-m-d');
        $todayCount = 0;
        $totalCount = count($downloads);
        $byFormat = [];
        $byNovel = [];

        foreach ($downloads as $d) {
            $downloadDate = substr($d['downloaded_at'] ?? '', 0, 10);
            if ($downloadDate === $today) {
                $todayCount++;
            }

            $format = $d['format'] ?? 'unknown';
            $byFormat[$format] = ($byFormat[$format] ?? 0) + 1;

            $novelId = $d['novel_id'] ?? 0;
            $byNovel[$novelId] = ($byNovel[$novelId] ?? 0) + 1;
        }

        arsort($byNovel);

        return [
            'total' => $totalCount,
            'today' => $todayCount,
            'by_format' => $byFormat,
            'by_novel' => $byNovel,
        ];
    }
}
