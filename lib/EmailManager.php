<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/DataManager.php';

class EmailManager
{
    private DataManager $dm;

    public function __construct()
    {
        $this->dm = new DataManager(DATA_DIR);
    }

    /**
     * Get SMTP settings
     */
    public function getSettings(): array
    {
        $settings = json_decode(@file_get_contents(SYSTEM_SETTINGS_FILE), true) ?: [];
        return $settings['smtp_settings'] ?? [
            'enabled' => false,
            'host' => '',
            'port' => 587,
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => 'NovelHub',
            'encryption' => 'tls'
        ];
    }

    /**
     * Check if email verification is enabled
     */
    public function isEnabled(): bool
    {
        $settings = $this->getSettings();
        return (bool)($settings['enabled'] ?? false);
    }

    /**
     * Generate verification token
     */
    public function generateVerificationToken(int $userId, string $email): string
    {
        $token = bin2hex(random_bytes(32));
        $verifications = $this->dm->readJson(EMAIL_VERIFICATIONS_FILE, []);

        $verification = [
            'user_id' => $userId,
            'email' => $email,
            'token' => $token,
            'status' => 'pending',
            'expires_at' => date('c', time() + 86400), // 24 hours
            'created_at' => date('c'),
        ];

        $this->dm->appendWithId(EMAIL_VERIFICATIONS_FILE, $verification, 'id');
        return $token;
    }

    /**
     * Verify email token
     */
    public function verifyToken(string $token): array
    {
        $verifications = $this->dm->readJson(EMAIL_VERIFICATIONS_FILE, []);

        foreach ($verifications as $i => $v) {
            if (($v['token'] ?? '') === $token) {
                if (($v['status'] ?? '') !== 'pending') {
                    return ['success' => false, 'error' => '验证链接已使用'];
                }
                
                $expiresAt = $v['expires_at'] ?? null;
                if ($expiresAt && strtotime($expiresAt) < time()) {
                    return ['success' => false, 'error' => '验证链接已过期'];
                }

                // Mark as verified
                $verifications[$i]['status'] = 'verified';
                $verifications[$i]['verified_at'] = date('c');
                $this->dm->writeJson(EMAIL_VERIFICATIONS_FILE, $verifications);

                return [
                    'success' => true,
                    'user_id' => (int)($v['user_id'] ?? 0),
                    'email' => $v['email'] ?? ''
                ];
            }
        }

        return ['success' => false, 'error' => '验证链接无效'];
    }

    /**
     * Check if user email is verified
     */
    public function isEmailVerified(int $userId): bool
    {
        $verifications = $this->dm->readJson(EMAIL_VERIFICATIONS_FILE, []);

        foreach ($verifications as $v) {
            if ((int)($v['user_id'] ?? 0) === $userId && ($v['status'] ?? '') === 'verified') {
                return true;
            }
        }

        return false;
    }

    /**
     * Send verification email
     */
    public function sendVerificationEmail(int $userId, string $email, string $token): bool
    {
        $settings = $this->getSettings();
        
        if (!$settings['enabled']) {
            return true; // If SMTP is disabled, consider it successful
        }

        // Build verification URL
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $verifyUrl = $baseUrl . '/verify_email.php?token=' . urlencode($token);

        $subject = '验证您的邮箱 - ' . ($settings['from_name'] ?? 'NovelHub');
        $message = "
            <html>
            <body>
                <h2>欢迎注册 {$settings['from_name']}！</h2>
                <p>请点击下方链接验证您的邮箱地址：</p>
                <p><a href=\"{$verifyUrl}\">{$verifyUrl}</a></p>
                <p>此链接将在24小时后失效。</p>
                <p>如果您没有注册账号，请忽略此邮件。</p>
            </body>
            </html>
        ";

        return $this->sendEmail($email, $subject, $message);
    }

    /**
     * Send email via SMTP
     */
    private function sendEmail(string $to, string $subject, string $message): bool
    {
        $settings = $this->getSettings();

        if (!$settings['enabled'] || empty($settings['host'])) {
            // Log the email instead of sending if SMTP is not configured
            error_log("Email would be sent to {$to}: {$subject}");
            return true;
        }

        // Use PHP mail() as fallback, or implement proper SMTP
        // For production, consider using PHPMailer or similar
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=utf-8',
            'From: ' . ($settings['from_name'] ?? 'NovelHub') . ' <' . ($settings['from_email'] ?? 'noreply@localhost') . '>',
        ];

        return mail($to, $subject, $message, implode("\r\n", $headers));
    }

    /**
     * Resend verification email
     */
    public function resendVerificationEmail(int $userId): array
    {
        $dm = new DataManager(DATA_DIR);
        $user = $dm->findById(USERS_FILE, $userId);
        
        if (!$user) {
            return ['success' => false, 'error' => '用户不存在'];
        }

        $email = $user['email'] ?? '';
        if (empty($email)) {
            return ['success' => false, 'error' => '邮箱地址为空'];
        }

        // Check if already verified
        if ($this->isEmailVerified($userId)) {
            return ['success' => false, 'error' => '邮箱已验证'];
        }

        // Generate new token
        $token = $this->generateVerificationToken($userId, $email);
        
        // Send email
        if ($this->sendVerificationEmail($userId, $email, $token)) {
            return ['success' => true, 'message' => '验证邮件已发送'];
        }

        return ['success' => false, 'error' => '邮件发送失败'];
    }
}
