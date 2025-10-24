<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/DataManager.php';

class Membership
{
    private DataManager $dm;

    public function __construct()
    {
        $this->dm = new DataManager(DATA_DIR);
    }

    /**
     * Check if user has active Plus membership
     */
    public function isPlusUser(int $userId): bool
    {
        $memberships = $this->dm->readJson(PLUS_MEMBERSHIPS_FILE, []);
        foreach ($memberships as $m) {
            if ((int)($m['user_id'] ?? 0) === $userId) {
                $expiresAt = $m['expires_at'] ?? null;
                if ($expiresAt && strtotime($expiresAt) > time()) {
                    return true;
                }
            }
        }
        return false;
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
                    return $m;
                }
            }
        }
        return null;
    }

    /**
     * Redeem a code for Plus membership
     */
    public function redeemCode(int $userId, string $code): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['success' => false, 'error' => '兑换码不能为空'];
        }

        $codes = $this->dm->readJson(REDEMPTION_CODES_FILE, []);
        $codeRecord = null;
        $codeIndex = null;

        foreach ($codes as $i => $c) {
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
        $code = strtoupper(bin2hex(random_bytes(4))); // 8-character code
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
    private DataManager $dm;
    private Membership $membership;
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
