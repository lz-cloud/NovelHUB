# Admin User Limits and Ad Integration Features

This document describes the newly implemented fine-grained user limits and advertising integration features.

## Features Overview

### 1. Fine-Grained User Limits Management

Administrators can now control individual user access with very granular limits, including:

- **Daily Chapter Limit**: Restrict how many chapters a user can read per day (e.g., 3 chapters/day)
- **Daily Reading Time Limit**: Limit total reading time per day (in minutes)
- **Concurrent Novel Limit**: Restrict how many different novels a user can read simultaneously
- **Download Limit**: Control daily download allowances per user

#### Key Components

**Library Files:**
- `/lib/UserLimits.php` - Core library for managing user limits
  - `getUserLimit($userId)` - Get limits for a specific user
  - `setUserLimit($userId, $limits)` - Set custom limits for a user
  - `setDefaultLimits($limits)` - Set default limits for all users
  - `checkLimit($userId, $limitType)` - Check if user has exceeded limits
  - `recordChapterRead($userId, $novelId, $chapterId)` - Track chapter reading
  - `getUserUsage($userId)` - Get current usage statistics for a user

**Admin Interface:**
- `/admin_user_limits.php` - Admin page for managing user limits

**Data Files:**
- `/data/user_limits.json` - Stores limit configurations
- `/data/user_usage.json` - Tracks daily user activity

#### Usage Examples

**Default Limits:**
Set limits that apply to all users who don't have custom settings:
- Enable/disable limits globally
- Set chapter limit (e.g., 3 chapters per day)
- Set reading time limit (e.g., 60 minutes per day)
- Set concurrent novel limit (e.g., 5 novels at once)

**Individual User Limits:**
- Click "设置" (Settings) button next to any user
- Configure custom limits for that specific user
- Remove custom limits to revert to default settings

**Limit Enforcement:**
- When a user exceeds their chapter limit, they see a friendly message
- Option to upgrade to PLUS membership for unlimited access
- Limits reset daily at midnight
- PLUS members and admins are typically exempt from limits

### 2. Advertisement Integration System

A comprehensive advertising system that integrates with mainstream ad platforms while respecting user groups.

#### Key Components

**Library Files:**
- `/lib/AdManager.php` - Core library for ad management
  - `shouldShowAds($user, $position)` - Determine if ads should display
  - `renderAd($position, $user)` - Render ad at specified position
  - `getAdScripts()` - Get required ad platform scripts
  - `updateSettings($settings)` - Update ad configuration

**Admin Interface:**
- `/admin_ads.php` - Admin page for configuring advertisements

**Data Files:**
- `/data/ad_settings.json` - Stores ad configuration

#### Supported Ad Platforms

1. **Google AdSense**
   - Client ID configuration
   - Multiple ad slot positions (header, sidebar, content, footer)
   - Automatic responsive ads
   - Full AdSense integration

2. **Custom Code**
   - Support for any third-party ad platform
   - Insert custom HTML/JavaScript code
   - Flexible positioning options
   - No platform restrictions

3. **None/Disabled**
   - Completely disable ads for all users
   - Default state if not configured

#### Ad Display Positions

Ads can be configured to appear in:
- **Reading Page** (`reading_page`) - During chapter reading
- **Novel Detail Page** (`novel_detail`) - On book information pages
- **Home Page** (`home_page`) - On the main landing page
- **Dashboard** (`dashboard`) - On user dashboard (optional)

#### User Group Exemptions

Configure which user groups should NOT see ads:
- **PLUS Members** - Premium subscribers (default: no ads)
- **VIP Users** - Special VIP tier
- **Super Admins** - Site administrators
- **Content Admins** - Content managers
- **Custom User IDs** - Specific individual users by ID

#### Ad Implementation

Ads are integrated into:
- `/reading.php` - Chapter reading pages
- `/index.php` - Home page
- `/novel_detail.php` - Book detail pages

Example integration:
```php
// In page head
<?php echo $adManager->getAdScripts(); ?>

// In page body
<?php echo $adManager->renderAd('header_banner', $currentUser); ?>
```

#### Configuration Examples

**Google AdSense Setup:**
1. Enable ad system
2. Select "Google AdSense" platform
3. Enter your AdSense client ID (ca-pub-XXXXXXXXXXXXXXXX)
4. Configure ad slot IDs for different positions
5. Select user groups to exempt (e.g., PLUS members)
6. Choose display positions
7. Save settings

**Custom Ad Code Setup:**
1. Enable ad system
2. Select "Custom Code" platform
3. Paste your ad HTML/JavaScript into appropriate fields:
   - Header code for top banners
   - Body code for content ads
   - Footer code for bottom banners
4. Configure exemptions and positions
5. Save settings

**Ad Behavior:**
- If ad system is disabled: No ads shown to anyone
- If no ad platform configured: No ads shown
- If platform configured but user is exempt: No ads shown
- Otherwise: Ads displayed according to configuration

## Access Control

Both features require admin privileges:
- **Required Roles**: `super_admin` or `content_admin`
- Access via admin dashboard navigation menu

## Navigation

New menu items added to admin dashboard:
- **用户限制** (User Limits) - `/admin_user_limits.php`
- **广告管理** (Ad Management) - `/admin_ads.php`

## Benefits

### User Limits Benefits
- Monetization: Encourage free users to upgrade for unlimited access
- Content Protection: Control distribution of premium content
- Server Load Management: Limit heavy users during peak times
- Flexible Policies: Different limits for different user tiers
- Fair Usage: Ensure equitable access for all users

### Ad Integration Benefits
- Revenue Generation: Monetize free tier users
- Member Value: Ad-free experience for paying members
- Flexibility: Support any ad platform
- User Experience: Configurable to minimize disruption
- Compliance: Easy to disable ads for GDPR/CCPA compliance

## Technical Notes

### Performance
- Limits checked at chapter load time
- Usage tracked per chapter read
- Daily usage reset handled automatically
- Minimal database queries

### Data Privacy
- Usage data stored locally in JSON files
- No external tracking unless ads are enabled
- User activity logged for limit enforcement only
- Can be completely disabled

### Scalability
- JSON-based storage for easy deployment
- Can be migrated to database if needed
- Cache-friendly design
- Minimal overhead on reading experience

## Future Enhancements

Potential additions:
- Weekly/monthly limits in addition to daily
- Time-based restrictions (e.g., peak hour limits)
- Automatic limit adjustment based on membership tier
- Analytics dashboard for limit effectiveness
- A/B testing for ad positions
- Video ad support
- Native ad formats

## Troubleshooting

### Limits Not Enforcing
1. Check if limits are enabled in default settings
2. Verify user doesn't have a custom limit set to 0 (unlimited)
3. Ensure user is not an admin (admins bypass limits)
4. Check if user has PLUS membership (they may be exempt)

### Ads Not Displaying
1. Verify ad system is enabled in `/admin_ads.php`
2. Check that a platform is selected and configured
3. Ensure user is not in an exempt group
4. Verify display position is enabled
5. Check browser console for JavaScript errors
6. Confirm ad platform credentials are correct

### Performance Issues
1. Consider caching ad configuration
2. Reduce number of ad positions
3. Use lazy loading for ads
4. Optimize usage tracking frequency

## Support

For issues or questions:
1. Check system logs in `/data/admin/operations.json`
2. Review user activity in `/data/user_usage.json`
3. Verify configuration files in `/data/` directory
4. Test with different user roles and membership tiers
