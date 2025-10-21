<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/DataManager.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Cache.php';

class Statistics
{
    private DataManager $dm;
    private Cache $cache;

    public function __construct()
    {
        $this->dm = new DataManager(DATA_DIR);
        $this->cache = new Cache(CACHE_DIR);
    }

    public function computeUserStats(int $userId): array
    {
        $key = "user_stats_{$userId}";
        return $this->cache->remember($key, 60, function() use ($userId) {
            $novels = array_values(array_filter(load_novels(), fn($n)=> (int)($n['author_id']??0) === $userId));
            $worksCount = count($novels);
            $totalChapters = 0; $totalWords = 0;
            foreach ($novels as $n) {
                $chs = list_chapters((int)$n['id']);
                $totalChapters += count($chs);
                foreach ($chs as $c) {
                    $text = (string)($c['content'] ?? '');
                    $totalWords += $this->countWords($text);
                }
            }

            // shelf count
            $shelf = $this->dm->readJson(BOOKSHELVES_FILE, []);
            $shelfCount = 0;
            foreach ($shelf as $r) if ((int)($r['user_id']??0) === $userId) $shelfCount++;

            // reading progress -> estimate
            $prog = $this->dm->readJson(READING_PROGRESS_FILE, []);
            $finished = 0; $readingMinutes = 0; $readBooks = [];
            foreach ($prog as $p) {
                if ((int)($p['user_id']??0) !== $userId) continue;
                $nid = (int)($p['novel_id'] ?? 0);
                $nov = $nid ? find_novel($nid) : null;
                if (!$nov) continue;
                $chs = list_chapters($nid, 'published');
                if (!$chs) continue;
                $lastId = (int)($nov['last_chapter_id'] ?? end($chs)['id'] ?? 0);
                if ($lastId > 0 && (int)($p['chapter_id'] ?? 0) >= $lastId) {
                    $finished++;
                    $readBooks[$nid] = true;
                }
                // estimate reading minutes by words read / 600
                $wordsRead = 0;
                foreach ($chs as $ch) {
                    $wordsRead += $this->countWords((string)($ch['content'] ?? ''));
                    if ((int)$ch['id'] === (int)($p['chapter_id'] ?? 0)) break;
                }
                $readingMinutes += (int)ceil($wordsRead / 600);
            }

            // interactions: favorites received & comments received (as author)
            $favoritesReceived = 0; $commentsReceived = 0;
            // favorites: count shelves where novel_id is in my novels
            $mineIds = array_map(fn($n)=> (int)$n['id'], $novels);
            foreach ($this->dm->readJson(BOOKSHELVES_FILE, []) as $r) if (in_array((int)($r['novel_id']??0), $mineIds, true)) $favoritesReceived++;
            // comments: iterate reviews by novel
            foreach ($mineIds as $nid) {
                $reviews = $this->loadReviews($nid);
                $commentsReceived += count($reviews);
            }

            return [
                'works_count' => $worksCount,
                'total_words' => $totalWords,
                'total_chapters' => $totalChapters,
                'reading_minutes' => $readingMinutes,
                'finished_books' => $finished,
                'shelf_books' => $shelfCount,
                'favorites_received' => $favoritesReceived,
                'comments_received' => $commentsReceived,
            ];
        });
    }

    public function computePlatformOverview(): array
    {
        return $this->cache->remember('platform_overview', 60, function(){
            $users = load_users();
            $novels = load_novels();
            $chaptersTotal = 0; $wordsTotal = 0; $views = 0; $reads = 0;
            foreach ($novels as $n) {
                $chs = list_chapters((int)$n['id']);
                $chaptersTotal += count($chs);
                foreach ($chs as $c) {
                    $wordsTotal += $this->countWords((string)($c['content'] ?? ''));
                }
                $views += (int)($n['stats']['views'] ?? 0);
            }
            // daily active (approx): distinct users in reading_progress updated today
            $prog = $this->dm->readJson(READING_PROGRESS_FILE, []);
            $today = date('Y-m-d'); $activeUsers = [];
            foreach ($prog as $p) {
                $u = $p['user_id'] ?? null; $ts = $p['updated_at'] ?? null;
                if ($u && $ts && strncmp($ts, $today, 10) === 0) $activeUsers[$u] = true;
            }
            $shelf = $this->dm->readJson(BOOKSHELVES_FILE, []);
            return [
                'users_total' => count($users),
                'novels_total' => count($novels),
                'chapters_total' => $chaptersTotal,
                'words_total' => $wordsTotal,
                'views_total' => $views,
                'favorites_total' => count($shelf),
                'daily_active' => count($activeUsers),
            ];
        });
    }

    public function computeNovelStats(int $novelId): array
    {
        $novel = find_novel($novelId);
        $reviews = $this->loadReviews($novelId);
        $ratingSum = 0; $ratingCount = 0; $likes = 0;
        foreach ($reviews as $r) {
            $rating = (int)($r['rating'] ?? 0);
            if ($rating > 0) { $ratingSum += $rating; $ratingCount++; }
            $likes += (int)($r['likes'] ?? 0);
        }
        $avgRating = $ratingCount ? round($ratingSum / $ratingCount, 1) : 0.0;
        $chapters = list_chapters($novelId, 'published');
        $lastUpdated = $novel['updated_at'] ?? null;
        return [
            'rating_avg' => $avgRating,
            'rating_count' => $ratingCount,
            'reviews_count' => count($reviews),
            'chapters_count' => count($chapters),
            'last_updated' => $lastUpdated,
            'views' => (int)($novel['stats']['views'] ?? 0),
            'favorites' => $this->countFavorites($novelId),
            'recommend_score' => $this->calcRecommendScore($avgRating, $ratingCount, (int)($novel['stats']['views'] ?? 0), $this->countFavorites($novelId), $likes),
        ];
    }

    private function countFavorites(int $novelId): int
    {
        $n = 0; foreach ($this->dm->readJson(BOOKSHELVES_FILE, []) as $r) if ((int)($r['novel_id']??0) === $novelId) $n++; return $n;
    }

    private function calcRecommendScore(float $rating, int $rc, int $views, int $favs, int $likes): int
    {
        $score = ($rating * min($rc, 50)) + ($favs * 2) + ($likes) + (int)floor($views / 10);
        return (int)$score;
    }

    private function countWords(string $text): int
    {
        if (function_exists('mb_strlen')) return mb_strlen($text);
        return strlen($text);
    }

    public function computeAchievements(int $userId): array
    {
        $stats = $this->computeUserStats($userId);
        $novels = array_values(array_filter(load_novels(), fn($n)=> (int)($n['author_id']??0) === $userId));
        $ach = [];
        // 阅读里程碑
        $ach[] = ['key'=>'read_1','name'=>'读完第一本书','achieved'=> $stats['finished_books'] >= 1];
        $ach[] = ['key'=>'read_10','name'=>'读完10本书','achieved'=> $stats['finished_books'] >= 10];
        $ach[] = ['key'=>'read_50','name'=>'读完50本书','achieved'=> $stats['finished_books'] >= 50];
        // 创作里程碑
        $ach[] = ['key'=>'publish_first','name'=>'发布第一部作品','achieved'=> count($novels) >= 1];
        $ach[] = ['key'=>'write_100k','name'=>'达成10万字','achieved'=> $stats['total_words'] >= 100000];
        $ach[] = ['key'=>'write_500k','name'=>'达成50万字','achieved'=> $stats['total_words'] >= 500000];
        // 特殊成就（简单示例）
        // 连续登录：基于最近 7 天是否有阅读记录（替代）
        $ach[] = ['key'=>'streak_7','name'=>'连续登录7天','achieved'=> $this->hasReadingStreak($userId, 7)];
        $ach[] = ['key'=>'comment_master','name'=>'评论达人','achieved'=> $this->userReviewsCount($userId) >= 20];
        return $ach;
    }

    private function hasReadingStreak(int $userId, int $days): bool
    {
        $progress = $this->dm->readJson(READING_PROGRESS_FILE, []);
        $hits = [];
        foreach ($progress as $p) {
            if ((int)($p['user_id']??0) !== $userId) continue;
            $d = substr($p['updated_at'] ?? '', 0, 10); if ($d) $hits[$d] = true;
        }
        $count = 0; $today = new DateTimeImmutable('today');
        for ($i=0; $i<$days; $i++) {
            $day = $today->sub(new DateInterval('P'.$i.'D'))->format('Y-m-d');
            if (!empty($hits[$day])) $count++; else break;
        }
        return $count >= $days;
    }

    private function userReviewsCount(int $userId): int
    {
        $count = 0; foreach (glob(NOVEL_REVIEWS_DIR.'/*.json') as $f) {
            $rows = json_decode(@file_get_contents($f), true) ?: [];
            foreach ($rows as $r) if ((int)($r['user_id']??0) === $userId) $count++;
        } return $count;
    }

    public function buildUserTimeline(int $userId, int $limit = 50): array
    {
        $events = [];
        // 阅读记录
        foreach ($this->dm->readJson(READING_PROGRESS_FILE, []) as $p) {
            if ((int)($p['user_id']??0) !== $userId) continue;
            $events[] = [
                'type' => 'reading',
                'created_at' => $p['updated_at'] ?? date('c'),
                'desc' => '阅读进度更新',
                'meta' => $p,
            ];
        }
        // 发布新章
        $myNovels = array_values(array_filter(load_novels(), fn($n)=> (int)($n['author_id']??0) === $userId));
        foreach ($myNovels as $n) {
            $chs = list_chapters((int)$n['id']);
            foreach ($chs as $c) {
                $events[] = [
                    'type' => 'publish',
                    'created_at' => $c['created_at'] ?? date('c'),
                    'desc' => '发布新章节：' . ($c['title'] ?? ''),
                    'meta' => ['novel_id'=>(int)$n['id'], 'chapter_id'=>(int)($c['id']??0), 'title'=>$n['title'] ?? ''],
                ];
            }
        }
        // 收藏作品
        foreach ($this->dm->readJson(BOOKSHELVES_FILE, []) as $r) {
            if ((int)($r['user_id']??0) !== $userId) continue;
            $events[] = [
                'type' => 'favorite',
                'created_at' => $r['added_at'] ?? date('c'),
                'desc' => '收藏了作品',
                'meta' => $r,
            ];
        }
        usort($events, fn($a,$b)=> strcmp($b['created_at'],$a['created_at']));
        return array_slice($events, 0, $limit);
    }

    private function loadReviews(int $novelId): array
    {
        $file = NOVEL_REVIEWS_DIR . '/' . (int)$novelId . '.json';
        if (!file_exists($file)) return [];
        $rows = json_decode(@file_get_contents($file), true);
        if (!is_array($rows)) return [];
        return $rows;
    }
}
