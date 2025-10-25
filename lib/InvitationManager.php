<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/DataManager.php';

class InvitationManager
{
    private DataManager $dm;

    public function __construct()
    {
        $this->dm = new DataManager(DATA_DIR);
    }

    /**
     * Generate a new invitation code
     */
    public function generateCode(int $maxUses = 1, ?string $expiresAt = null, int $generatedBy = 0, string $note = ''): string
    {
        $settings = $this->getSettings();
        $length = (int)($settings['code_length'] ?? 8);
        
        // Generate code with specified length
        $code = strtoupper(bin2hex(random_bytes((int)ceil($length / 2))));
        $code = substr($code, 0, $length);
        
        $invitations = $this->dm->readJson(INVITATION_CODES_FILE, []);

        $codeData = [
            'code' => $code,
            'max_uses' => $maxUses,
            'used_count' => 0,
            'status' => 'active',
            'expires_at' => $expiresAt,
            'generated_by' => $generatedBy,
            'note' => $note,
            'created_at' => date('c'),
        ];

        $this->dm->appendWithId(INVITATION_CODES_FILE, $codeData, 'id');
        return $code;
    }

    /**
     * Validate invitation code
     */
    public function validateCode(string $code): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['valid' => false, 'error' => '邀请码不能为空'];
        }

        $invitations = $this->dm->readJson(INVITATION_CODES_FILE, []);
        $codeRecord = null;
        $codeIndex = null;

        foreach ($invitations as $i => $c) {
            if (strtoupper($c['code'] ?? '') === $code) {
                $codeRecord = $c;
                $codeIndex = $i;
                break;
            }
        }

        if (!$codeRecord) {
            return ['valid' => false, 'error' => '邀请码不存在'];
        }

        if (($codeRecord['status'] ?? 'active') !== 'active') {
            return ['valid' => false, 'error' => '邀请码已失效'];
        }

        $expiresAt = $codeRecord['expires_at'] ?? null;
        if ($expiresAt && strtotime($expiresAt) < time()) {
            return ['valid' => false, 'error' => '邀请码已过期'];
        }

        $maxUses = (int)($codeRecord['max_uses'] ?? 1);
        $usedCount = (int)($codeRecord['used_count'] ?? 0);
        if ($usedCount >= $maxUses) {
            return ['valid' => false, 'error' => '邀请码已达到最大使用次数'];
        }

        return ['valid' => true, 'code_record' => $codeRecord, 'code_index' => $codeIndex];
    }

    /**
     * Use invitation code
     */
    public function useCode(string $code, int $userId): bool
    {
        $validation = $this->validateCode($code);
        if (!$validation['valid']) {
            return false;
        }

        $invitations = $this->dm->readJson(INVITATION_CODES_FILE, []);
        $codeIndex = $validation['code_index'];
        $codeRecord = $validation['code_record'];

        $usedCount = (int)($codeRecord['used_count'] ?? 0);
        $maxUses = (int)($codeRecord['max_uses'] ?? 1);

        $invitations[$codeIndex]['used_count'] = $usedCount + 1;
        if ($invitations[$codeIndex]['used_count'] >= $maxUses) {
            $invitations[$codeIndex]['status'] = 'used';
        }
        $invitations[$codeIndex]['last_used_at'] = date('c');
        $invitations[$codeIndex]['last_used_by'] = $userId;

        return $this->dm->writeJson(INVITATION_CODES_FILE, $invitations);
    }

    /**
     * Get all invitation codes
     */
    public function getAllCodes(): array
    {
        return $this->dm->readJson(INVITATION_CODES_FILE, []);
    }

    /**
     * Disable invitation code
     */
    public function disableCode(int $codeId): bool
    {
        return $this->dm->updateById(INVITATION_CODES_FILE, $codeId, ['status' => 'disabled'], 'id');
    }

    /**
     * Get invitation system settings
     */
    public function getSettings(): array
    {
        $settings = json_decode(@file_get_contents(SYSTEM_SETTINGS_FILE), true) ?: [];
        return $settings['invitation_system'] ?? ['enabled' => false, 'code_length' => 8];
    }

    /**
     * Check if invitation system is enabled
     */
    public function isEnabled(): bool
    {
        $settings = $this->getSettings();
        return (bool)($settings['enabled'] ?? false);
    }
}
