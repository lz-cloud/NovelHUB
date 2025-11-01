<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/DataManager.php';
require_once __DIR__ . '/Membership.php';

class AdManager
{
    /** @var DataManager */
    private $dm;
    /** @var Membership */
    private $membership;
    const AD_SETTINGS_FILE = DATA_DIR . '/ad_settings.json';

    public function __construct()
    {
        $this->dm = new DataManager(DATA_DIR);
        $this->membership = new Membership();
        $this->ensureFile();
    }

    private function ensureFile(): void
    {
        if (!file_exists(self::AD_SETTINGS_FILE)) {
            file_put_contents(self::AD_SETTINGS_FILE, json_encode($this->getDefaultSettings(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }

    public function getDefaultSettings(): array
    {
        return [
            'enabled' => false,
            'platform' => 'none',
            'google_adsense' => [
                'client_id' => '',
                'enabled' => false,
                'slots' => [
                    'header_banner' => '',
                    'sidebar' => '',
                    'in_content' => '',
                    'footer_banner' => '',
                ],
            ],
            'custom_code' => [
                'enabled' => false,
                'header_code' => '',
                'body_code' => '',
                'footer_code' => '',
            ],
            'excluded_user_groups' => ['plus', 'vip', 'super_admin', 'content_admin'],
            'excluded_user_ids' => [],
            'display_positions' => [
                'reading_page' => true,
                'novel_detail' => true,
                'home_page' => true,
                'dashboard' => false,
            ],
        ];
    }

    public function getSettings(): array
    {
        $settings = json_decode(file_get_contents(self::AD_SETTINGS_FILE), true);
        if (!$settings) {
            return $this->getDefaultSettings();
        }
        return array_merge($this->getDefaultSettings(), $settings);
    }

    public function updateSettings(array $settings): bool
    {
        $currentSettings = $this->getSettings();
        $newSettings = array_merge($currentSettings, $settings);
        return (bool)file_put_contents(self::AD_SETTINGS_FILE, json_encode($newSettings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function shouldShowAds(?array $user = null, string $position = 'reading_page'): bool
    {
        $settings = $this->getSettings();
        
        if (!($settings['enabled'] ?? false)) {
            return false;
        }
        
        if (!($settings['display_positions'][$position] ?? false)) {
            return false;
        }
        
        if (!$user) {
            return true;
        }
        
        $userId = (int)($user['id'] ?? 0);
        if (in_array($userId, $settings['excluded_user_ids'] ?? [])) {
            return false;
        }
        
        $userRole = $user['role'] ?? 'user';
        if (in_array($userRole, $settings['excluded_user_groups'] ?? [])) {
            return false;
        }
        
        if ($this->membership->isPlusUser($userId)) {
            if (in_array('plus', $settings['excluded_user_groups'] ?? [])) {
                return false;
            }
        }
        
        return true;
    }

    public function renderAd(string $position = 'header_banner', ?array $user = null): string
    {
        if (!$this->shouldShowAds($user, $this->positionToPage($position))) {
            return '';
        }
        
        $settings = $this->getSettings();
        $platform = $settings['platform'] ?? 'none';
        
        if ($platform === 'google_adsense') {
            return $this->renderGoogleAdsense($position, $settings);
        } elseif ($platform === 'custom_code') {
            return $this->renderCustomCode($position, $settings);
        }
        
        return '';
    }

    private function positionToPage(string $position): string
    {
        if (strpos($position, 'reading') !== false || $position === 'in_content') {
            return 'reading_page';
        } elseif (strpos($position, 'home') !== false || $position === 'header_banner') {
            return 'home_page';
        } elseif (strpos($position, 'novel') !== false) {
            return 'novel_detail';
        } elseif (strpos($position, 'dashboard') !== false) {
            return 'dashboard';
        }
        return 'reading_page';
    }

    private function renderGoogleAdsense(string $position, array $settings): string
    {
        if (!($settings['google_adsense']['enabled'] ?? false)) {
            return '';
        }
        
        $clientId = $settings['google_adsense']['client_id'] ?? '';
        $slotId = $settings['google_adsense']['slots'][$position] ?? '';
        
        if (empty($clientId) || empty($slotId)) {
            return '';
        }
        
        $html = '<div class="ad-container ad-' . htmlspecialchars($position) . '" style="margin: 20px 0; text-align: center;">';
        $html .= '<ins class="adsbygoogle"';
        $html .= ' style="display:block"';
        $html .= ' data-ad-client="' . htmlspecialchars($clientId) . '"';
        $html .= ' data-ad-slot="' . htmlspecialchars($slotId) . '"';
        $html .= ' data-ad-format="auto"';
        $html .= ' data-full-width-responsive="true"></ins>';
        $html .= '<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>';
        $html .= '</div>';
        
        return $html;
    }

    private function renderCustomCode(string $position, array $settings): string
    {
        if (!($settings['custom_code']['enabled'] ?? false)) {
            return '';
        }
        
        $code = '';
        if ($position === 'header_banner') {
            $code = $settings['custom_code']['header_code'] ?? '';
        } elseif ($position === 'footer_banner') {
            $code = $settings['custom_code']['footer_code'] ?? '';
        } else {
            $code = $settings['custom_code']['body_code'] ?? '';
        }
        
        if (empty($code)) {
            return '';
        }
        
        return '<div class="ad-container ad-' . htmlspecialchars($position) . '" style="margin: 20px 0;">' . $code . '</div>';
    }

    public function getAdScripts(): string
    {
        $settings = $this->getSettings();
        
        if (!($settings['enabled'] ?? false)) {
            return '';
        }
        
        $platform = $settings['platform'] ?? 'none';
        
        if ($platform === 'google_adsense' && ($settings['google_adsense']['enabled'] ?? false)) {
            $clientId = $settings['google_adsense']['client_id'] ?? '';
            if (!empty($clientId)) {
                return '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . htmlspecialchars($clientId) . '" crossorigin="anonymous"></script>';
            }
        }
        
        return '';
    }
}
