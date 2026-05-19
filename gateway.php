<?php
/**
 * ============================================================
 * gateway.php — Bale YouTube Downloader Gateway
 * ============================================================
 */

// ========== تنظیمات - توکن‌های خود را اینجا وارد کنید ==========
putenv('BALE_BOT_TOKEN=YOUR_BOT_TOKEN');
putenv('GH_PAT=YOUR_GITHUB_PAT');
putenv('GITHUB_OWNER=YOUR_GITHUB_USERNAME');
putenv('GITHUB_REPO=YOUR_REPO_NAME');
putenv('CHANNEL_ID=YOUR_CHANNEL_ID');
// =============================================================

// ══════════════════════════════════════════════════════════
// ۱. پیکربندی
// ══════════════════════════════════════════════════════════

define('BALE_BOT_TOKEN', getenv('BALE_BOT_TOKEN') ?: '');
define('GH_PAT', getenv('GH_PAT') ?: '');
define('GITHUB_OWNER', getenv('GITHUB_OWNER') ?: '');
define('GITHUB_REPO', getenv('GITHUB_REPO') ?: '');
define('GITHUB_REF', 'main');
define('WORKFLOW_FILENAME', 'yt-dl.yml');
define('RATE_LIMIT_SECONDS', 300);
define('STATUS_CHECK_SECONDS', 180);
define('DB_FILE', __DIR__ . '/rate_limit.db');

define('BALE_API_BASE', 'https://tapi.bale.ai/bot' . BALE_BOT_TOKEN);
define('GITHUB_API_BASE', 'https://api.github.com/repos/' . GITHUB_OWNER . '/' . GITHUB_REPO);

// ══════════════════════════════════════════════════════════
// ۲. دیتابیس
// ══════════════════════════════════════════════════════════

function initDatabase() {
    $db = new SQLite3(DB_FILE);
    $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (chat_id TEXT PRIMARY KEY, last_request_time INTEGER)");
    $db->exec("CREATE TABLE IF NOT EXISTS processed_updates (update_id INTEGER PRIMARY KEY)");
    $db->exec("CREATE TABLE IF NOT EXISTS user_settings (chat_id TEXT PRIMARY KEY, quality TEXT DEFAULT 'best', subtitles TEXT DEFAULT 'no')");
    $db->exec("CREATE TABLE IF NOT EXISTS file_id_cache (file_id TEXT PRIMARY KEY, chat_id TEXT, folder_name TEXT, file_name TEXT, created_at INTEGER)");
    return $db;
}

// ══════════════════════════════════════════════════════════
// ۳. توابع API بله
// ══════════════════════════════════════════════════════════

function callBaleAPI($method, $params = []) {
    $url = BALE_API_BASE . '/' . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    
    $hasFile = false;
    foreach ($params as $value) {
        if ($value instanceof CURLFile) { $hasFile = true; break; }
    }
    
    if ($hasFile) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http_code' => $httpCode, 'body' => json_decode($response, true)];
}

function sendMessage($chatId, $text, $replyMarkup = null) {
    $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown'];
    if ($replyMarkup !== null) $params['reply_markup'] = $replyMarkup;
    return callBaleAPI('sendMessage', $params);
}

function sendDocument($chatId, $document, $caption = '', $replyMarkup = null) {
    $params = ['chat_id' => $chatId, 'document' => $document, 'caption' => $caption, 'parse_mode' => 'Markdown'];
    if ($replyMarkup !== null) $params['reply_markup'] = $replyMarkup;
    return callBaleAPI('sendDocument', $params);
}

function editMessageText($chatId, $messageId, $text, $replyMarkup = null) {
    $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'Markdown'];
    if ($replyMarkup !== null) $params['reply_markup'] = $replyMarkup;
    return callBaleAPI('editMessageText', $params);
}

function answerCallbackQuery($callbackQueryId, $text = '', $showAlert = false) {
    return callBaleAPI('answerCallbackQuery', ['callback_query_id' => $callbackQueryId, 'text' => $text, 'show_alert' => $showAlert]);
}

// ══════════════════════════════════════════════════════════
// ۴. توابع نرخ درخواست
// ══════════════════════════════════════════════════════════

function isRateLimited($db, $chatId) {
    $stmt = $db->prepare("SELECT last_request_time FROM rate_limits WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        $elapsed = time() - $row['last_request_time'];
        if ($elapsed < RATE_LIMIT_SECONDS) return true;
    }
    return false;
}

function updateRateLimit($db, $chatId) {
    $stmt = $db->prepare("INSERT OR REPLACE INTO rate_limits (chat_id, last_request_time) VALUES (:chat_id, :time)");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

function getRemainingTime($db, $chatId) {
    $stmt = $db->prepare("SELECT last_request_time FROM rate_limits WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        $elapsed = time() - $row['last_request_time'];
        $remaining = RATE_LIMIT_SECONDS - $elapsed;
        return max(0, $remaining);
    }
    return 0;
}

// ══════════════════════════════════════════════════════════
// ۵. استخراج لینک یوتیوب
// ══════════════════════════════════════════════════════════

function extractYoutubeUrls($text) {
    $urls = [];
    $patterns = [
        '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/',
        '/(?:https?:\/\/)?youtu\.be\/([a-zA-Z0-9_-]{11})/',
        '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/'
    ];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $text, $matches)) {
            foreach ($matches[1] as $videoId) {
                $urls[] = "https://www.youtube.com/watch?v={$videoId}";
            }
        }
    }
    return array_unique($urls);
}

// ══════════════════════════════════════════════════════════
// ۶. Dispatch کردن workflow (نسخه اصلاح شده)
// ══════════════════════════════════════════════════════════

function dispatchGitHubWorkflow($youtubeUrl, $chatId, $quality, $subs) {
    $url = "https://api.github.com/repos/" . GITHUB_OWNER . "/" . GITHUB_REPO . 
           "/actions/workflows/" . WORKFLOW_FILENAME . "/dispatches";
    
    // تبدیل کیفیت به فرمت yt-dl.yml
    switch ($quality) {
        case '2160p': case '4K': $internalQuality = '2160'; break;
        case '1440p': case '2K': $internalQuality = '1440'; break;
        case '1080p': $internalQuality = '1080'; break;
        case '720p': $internalQuality = '720'; break;
        case '480p': $internalQuality = '480'; break;
        case 'audio': $internalQuality = 'audio'; break;
        default: $internalQuality = 'best'; break;
    }
    
    $subsInternal = $subs === 'yes' ? 'true' : 'false';
    
    // فقط ورودی‌هایی که در yt-dl.yml تعریف شده‌اند
    $postData = [
        'ref' => GITHUB_REF,
        'inputs' => [
            'youtube_urls' => $youtubeUrl,
            'quality' => $internalQuality,
            'download_subtitles' => $subsInternal,
            'password' => ''
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/vnd.github.v3+json',
        'Authorization: Bearer ' . GH_PAT,
        'Content-Type: application/json',
        'User-Agent: Bale-YouTube-Downloader/4.0'
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'success' => ($httpCode >= 200 && $httpCode < 300),
        'http_code' => $httpCode
    ];
}

// ══════════════════════════════════════════════════════════
// ۷. Dispatch جستجو
// ══════════════════════════════════════════════════════════

function dispatchSearchWorkflow($query, $chatId) {
    $url = "https://api.github.com/repos/" . GITHUB_OWNER . "/" . GITHUB_REPO . 
           "/actions/workflows/yt-search.yml/dispatches";
    
    $postData = [
        'ref' => GITHUB_REF,
        'inputs' => [
            'query' => $query,
            'chat_id' => (string)$chatId,
            'max_results' => '5'
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/vnd.github.v3+json',
        'Authorization: Bearer ' . GH_PAT,
        'Content-Type: application/json',
        'User-Agent: Bale-YouTube-Downloader/4.0'
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['success' => ($httpCode >= 200 && $httpCode < 300), 'http_code' => $httpCode];
}

// ══════════════════════════════════════════════════════════
// ۸. توابع منو
// ══════════════════════════════════════════════════════════

function getStartMenu() {
    return ['inline_keyboard' => [
        [['text' => '🎥 دانلود ویدیو', 'callback_data' => 'menu_download'], ['text' => '🔍 جستجوی یوتیوب', 'callback_data' => 'menu_search']],
        [['text' => '⚙️ تنظیمات', 'callback_data' => 'menu_settings'], ['text' => 'ℹ️ راهنما', 'callback_data' => 'menu_help']],
        [['text' => '📊 وضعیت سرور', 'callback_data' => 'menu_status']]
    ]];
}

function getMainMenu() {
    return ['keyboard' => [
        [['text' => '🎥 دانلود ویدیو'], ['text' => '🔍 جستجوی یوتیوب']],
        [['text' => '⚙️ تنظیمات'], ['text' => 'ℹ️ راهنما']],
        [['text' => '📊 وضعیت سرور']]
    ], 'resize_keyboard' => true, 'persistent' => true];
}

function getQualitySettingsMenu() {
    return ['inline_keyboard' => [
        [['text' => '✨ Best Quality', 'callback_data' => 'quality_best']],
        [['text' => '4K (2160p)', 'callback_data' => 'quality_2160'], ['text' => '2K (1440p)', 'callback_data' => 'quality_1440']],
        [['text' => '1080p', 'callback_data' => 'quality_1080'], ['text' => '720p', 'callback_data' => 'quality_720']],
        [['text' => '480p', 'callback_data' => 'quality_480'], ['text' => '🎵 Audio Only', 'callback_data' => 'quality_audio']],
        [['text' => '🔙 بازگشت', 'callback_data' => 'settings_back']]
    ]];
}

function getSubtitleSettingsMenu($currentSubs) {
    $subsStatus = $currentSubs === 'yes' ? '✅ فعال' : '❌ غیرفعال';
    return ['inline_keyboard' => [
        [['text' => "زیرنویس: {$subsStatus}", 'callback_data' => 'toggle_subs']],
        [['text' => '🔙 بازگشت به تنظیمات', 'callback_data' => 'settings_main']]
    ]];
}

function getSettingsMainMenu() {
    return ['inline_keyboard' => [
        [['text' => '🎬 کیفیت ویدیو', 'callback_data' => 'settings_quality']],
        [['text' => '📝 تنظیمات زیرنویس', 'callback_data' => 'settings_subs']],
        [['text' => '🔙 بستن منو', 'callback_data' => 'settings_close']]
    ]];
}

function getConfirmKeyboard($quality, $subs) {
    $qualityNames = ['best' => '✨ Best Quality', '2160' => '4K', '1440' => '2K', '1080' => '1080p', '720' => '720p', '480' => '480p', 'audio' => '🎵 Audio Only'];
    $friendlyQuality = $qualityNames[$quality] ?? 'Best';
    $subsStatus = $subs === 'yes' ? '✅ فعال' : '❌ غیرفعال';
    return ['inline_keyboard' => [
        [['text' => '✅ تأیید و شروع دانلود', 'callback_data' => 'confirm_download']],
        [['text' => '❌ لغو', 'callback_data' => 'cancel_download']],
        [['text' => '⚙️ تغییر تنظیمات', 'callback_data' => 'menu_settings']]
    ]];
}

// ══════════════════════════════════════════════════════════
// ۹. تنظیمات کاربر
// ══════════════════════════════════════════════════════════

function getUserSettings($chatId) {
    $db = new SQLite3(DB_FILE);
    $stmt = $db->prepare("SELECT quality, subtitles FROM user_settings WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ?: ['quality' => 'best', 'subtitles' => 'no'];
}

function saveUserSettings($chatId, $quality, $subtitles) {
    $db = new SQLite3(DB_FILE);
    $stmt = $db->prepare("INSERT OR REPLACE INTO user_settings (chat_id, quality, subtitles) VALUES (:chat_id, :quality, :subtitles)");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
    $stmt->bindValue(':quality', $quality, SQLITE3_TEXT);
    $stmt->bindValue(':subtitles', $subtitles, SQLITE3_TEXT);
    $stmt->execute();
}

// ══════════════════════════════════════════════════════════
// ۱۰. پردازش پیام
// ══════════════════════════════════════════════════════════

function getCurrentUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $path = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
    return $protocol . $host . $path . basename($_SERVER['SCRIPT_NAME']);
}

function processMessage($message, $db) {
    $chatId = $message['chat']['id'] ?? null;
    $text = $message['text'] ?? '';
    if (!$chatId) return;
    
    if (strpos($text, '/start') === 0) {
        sendMessage($chatId, "🎬 *سلام! به ربات دانلودر یوتیوب خوش آمدید!*\n\n👇 یکی از گزینه‌های زیر را انتخاب کنید:", json_encode(getStartMenu()));
        return;
    }
    
    if ($text === '🎥 دانلود ویدیو') {
        sendMessage($chatId, "🔗 *لینک ویدیوی یوتیوب را ارسال کنید:*\n\n_مثال: https://youtu.be/abc123def45_");
        return;
    }
    
    if ($text === '⚙️ تنظیمات') {
        $settings = getUserSettings($chatId);
        $qualityNames = ['best' => '✨ Best Quality', '2160' => '4K', '1440' => '2K', '1080' => '1080p', '720' => '720p', '480' => '480p', 'audio' => '🎵 Audio Only'];
        $currentQuality = $qualityNames[$settings['quality']] ?? 'Best';
        $subsStatus = $settings['subtitles'] === 'yes' ? '✅ فعال' : '❌ غیرفعال';
        sendMessage($chatId, "⚙️ *تنظیمات فعلی:*\n\n🎬 *کیفیت:* {$currentQuality}\n📝 *زیرنویس:* {$subsStatus}\n\nبرای تغییر روی گزینه مورد نظر کلیک کنید:", json_encode(getSettingsMainMenu()));
        return;
    }
    
    if ($text === 'ℹ️ راهنما' || strpos($text, '/help') === 0) {
        sendMessage($chatId, "📖 *راهنمای ربات*\n\n🔸 لینک یوتیوب ارسال کنید → تأیید → دانلود\n🔸 تنظیم کیفیت و زیرنویس\n🔸 هر ۵ دقیقه یک درخواست");
        return;
    }
    
    if ($text === '📊 وضعیت سرور') {
        $remaining = getRemainingTime($db, $chatId);
        sendMessage($chatId, "📊 *وضعیت سرور*\n\n✅ سرویس: فعال\n⏱ درخواست بعدی شما: " . ($remaining > 0 ? gmdate("i:s", $remaining) : "آماده ✅"));
        return;
    }
    
    if ($text === '🔍 جستجوی یوتیوب') {
        sendMessage($chatId, "🔍 *جستجوی یوتیوب*\n\nلطفاً عبارت مورد نظر خود را وارد کنید:");
        return;
    }
    
    $youtubeUrls = extractYoutubeUrls($text);
    
    if (!empty($youtubeUrls)) {
        if (isRateLimited($db, $chatId)) {
            $remaining = getRemainingTime($db, $chatId);
            sendMessage($chatId, "⏳ *لطفاً کمی صبر کنید!*\n\n{$remaining} ثانیه دیگر می‌توانید درخواست دهید.");
            return;
        }
        
        $settings = getUserSettings($chatId);
        $youtubeUrl = $youtubeUrls[0];
        
        $db_temp = new SQLite3(DB_FILE);
        $db_temp->exec("CREATE TABLE IF NOT EXISTS pending_downloads (chat_id TEXT PRIMARY KEY, youtube_url TEXT, quality TEXT, subtitles TEXT, created_at INTEGER)");
        $stmt = $db_temp->prepare("INSERT OR REPLACE INTO pending_downloads (chat_id, youtube_url, quality, subtitles, created_at) VALUES (:chat_id, :url, :quality, :subs, :time)");
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        $stmt->bindValue(':url', $youtubeUrl, SQLITE3_TEXT);
        $stmt->bindValue(':quality', $settings['quality'], SQLITE3_TEXT);
        $stmt->bindValue(':subs', $settings['subtitles'], SQLITE3_TEXT);
        $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
        $stmt->execute();
        
        sendMessage($chatId, "🎬 *آماده دانلود*\n\n🔗 `$youtubeUrl`\n\nبرای شروع دکمه تأیید را بزنید:", json_encode(getConfirmKeyboard($settings['quality'], $settings['subtitles'])));
        return;
    }
    
    if (empty($youtubeUrls) && strlen($text) >= 2 && !str_starts_with($text, '/') && !in_array($text, ['🎥 دانلود ویدیو', '⚙️ تنظیمات', 'ℹ️ راهنما', '📊 وضعیت سرور', '🔍 جستجوی یوتیوب'])) {
        if (isRateLimited($db, $chatId)) {
            $remaining = getRemainingTime($db, $chatId);
            sendMessage($chatId, "⏳ *لطفاً کمی صبر کنید!*\n\n{$remaining} ثانیه دیگر می‌توانید درخواست دهید.");
            return;
        }
        
        sendMessage($chatId, "🔍 *در حال جستجو برای:* `$text`\n\n⏳ لطفاً صبر کنید...");
        $result = dispatchSearchWorkflow($text, $chatId);
        
        if ($result['success']) {
            updateRateLimit($db, $chatId);
            sendMessage($chatId, "✅ *جستجو آغاز شد!*\n\nنتایج تا چند ثانیه دیگر ارسال می‌شود.");
        } else {
            sendMessage($chatId, "❌ *خطا در جستجو!*\n\nکد خطا: {$result['http_code']}");
        }
        return;
    }
    
    sendMessage($chatId, "📋 *لطفاً یک لینک یوتیوب ارسال کنید یا از دکمه‌های منو استفاده کنید.*", json_encode(getMainMenu()));
}

// ══════════════════════════════════════════════════════════
// ۱۱. پردازش Callback
// ══════════════════════════════════════════════════════════

function processCallbackQuery($callbackQuery, $db) {
    $callbackId = $callbackQuery['id'] ?? null;
    $chatId = $callbackQuery['from']['id'] ?? null;
    $data = $callbackQuery['data'] ?? '';
    $messageId = $callbackQuery['message']['message_id'] ?? null;
    
    if (!$callbackId || !$chatId || !$data) return;
    
    if ($data === 'confirm_download') {
        $db_temp = new SQLite3(DB_FILE);
        $stmt = $db_temp->prepare("SELECT youtube_url, quality, subtitles FROM pending_downloads WHERE chat_id = :chat_id");
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$row || !$row['youtube_url']) {
            answerCallbackQuery($callbackId, '⚠️ لینک منقضی شده است.', true);
            return;
        }
        
        $stmt = $db_temp->prepare("DELETE FROM pending_downloads WHERE chat_id = :chat_id");
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        $stmt->execute();
        
        answerCallbackQuery($callbackId, '🔄 در حال ارسال به سرور...', false);
        editMessageText($chatId, $messageId, "⏳ *در حال اتصال به سرور دانلود...*");
        
        $result = dispatchGitHubWorkflow($row['youtube_url'], $chatId, $row['quality'], $row['subtitles']);
        
        if ($result['success']) {
            updateRateLimit($db, $chatId);
            editMessageText($chatId, $messageId, "✅ *دانلود با موفقیت شروع شد!*");
            sendMessage($chatId, "🚀 *دانلود شروع شد!*\n\n⏱ زمان تقریبی: ۲ تا ۵ دقیقه\n\n👇 بعد از اتمام، دکمه بررسی وضعیت را بزنید:", json_encode(['inline_keyboard' => [[['text' => '🔄 بررسی وضعیت', 'callback_data' => 'check_status']]]]));
        } else {
            editMessageText($chatId, $messageId, "❌ *خطا در اتصال به سرور!*\n\nکد خطا: {$result['http_code']}\nلطفاً چند دقیقه دیگر تلاش کنید.");
        }
        return;
    }
    
    if ($data === 'cancel_download') {
        $db_temp = new SQLite3(DB_FILE);
        $stmt = $db_temp->prepare("DELETE FROM pending_downloads WHERE chat_id = :chat_id");
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        $stmt->execute();
        answerCallbackQuery($callbackId, '❌ دانلود لغو شد.', false);
        editMessageText($chatId, $messageId, "❌ *دانلود لغو شد.*");
        return;
    }
    
    if ($data === 'check_status') {
        answerCallbackQuery($callbackId, '🔍 در حال بررسی...', false);
        sendMessage($chatId, "⏳ *در حال بررسی وضعیت دانلود...*\n\nلطفاً ۱ دقیقه دیگر دوباره امتحان کنید.");
        updateRateLimit($db, $chatId);
        return;
    }
    
    answerCallbackQuery($callbackId);
    $settings = getUserSettings($chatId);
    
    if (strpos($data, 'quality_') === 0) {
        $quality = str_replace('quality_', '', $data);
        saveUserSettings($chatId, $quality, $settings['subtitles']);
        editMessageText($chatId, $messageId, "✅ *کیفیت تنظیم شد!*", json_encode(getQualitySettingsMenu()));
        return;
    }
    
    if ($data === 'settings_quality') {
        editMessageText($chatId, $messageId, "🎬 *کیفیت ویدیوی خود را انتخاب کنید:*", json_encode(getQualitySettingsMenu()));
        return;
    }
    
    if ($data === 'settings_subs') {
        editMessageText($chatId, $messageId, "📝 *تنظیمات زیرنویس:*", json_encode(getSubtitleSettingsMenu($settings['subtitles'])));
        return;
    }
    
    if ($data === 'toggle_subs') {
        $newSubs = $settings['subtitles'] === 'yes' ? 'no' : 'yes';
        saveUserSettings($chatId, $settings['quality'], $newSubs);
        editMessageText($chatId, $messageId, "✅ *تنظیمات زیرنویس ذخیره شد!*", json_encode(getSubtitleSettingsMenu($newSubs)));
        return;
    }
    
    if ($data === 'settings_main') {
        $qualityNames = ['best' => '✨ Best Quality', '2160' => '4K', '1440' => '2K', '1080' => '1080p', '720' => '720p', '480' => '480p', 'audio' => '🎵 Audio Only'];
        $currentQuality = $qualityNames[$settings['quality']] ?? 'Best';
        $subsStatus = $settings['subtitles'] === 'yes' ? '✅ فعال' : '❌ غیرفعال';
        editMessageText($chatId, $messageId, "⚙️ *تنظیمات فعلی:*\n\n🎬 *کیفیت:* {$currentQuality}\n📝 *زیرنویس:* {$subsStatus}\n\nبرای تغییر روی گزینه مورد نظر کلیک کنید:", json_encode(getSettingsMainMenu()));
        return;
    }
    
    if ($data === 'settings_close' || $data === 'settings_back') {
        editMessageText($chatId, $messageId, "⚙️ *تنظیمات بسته شد.*");
        return;
    }
    
    if ($data === 'menu_download') {
        sendMessage($chatId, "🔗 *لینک ویدیوی یوتیوب را ارسال کنید:*");
        return;
    }
    
    if ($data === 'menu_settings') {
        $qualityNames = ['best' => '✨ Best Quality', '2160' => '4K', '1440' => '2K', '1080' => '1080p', '720' => '720p', '480' => '480p', 'audio' => '🎵 Audio Only'];
        $currentQuality = $qualityNames[$settings['quality']] ?? 'Best';
        $subsStatus = $settings['subtitles'] === 'yes' ? '✅ فعال' : '❌ غیرفعال';
        sendMessage($chatId, "⚙️ *تنظیمات فعلی:*\n\n🎬 *کیفیت:* {$currentQuality}\n📝 *زیرنویس:* {$subsStatus}", json_encode(getSettingsMainMenu()));
        return;
    }
    
    if ($data === 'menu_help') {
        sendMessage($chatId, "📖 *راهنمای ربات*\n\n🔸 لینک یوتیوب ارسال کنید → تأیید → دانلود\n🔸 هر ۵ دقیقه یک درخواست");
        return;
    }
    
    if ($data === 'menu_status') {
        $remaining = getRemainingTime($db, $chatId);
        sendMessage($chatId, "📊 *وضعیت سرور*\n\n✅ سرویس: فعال\n⏱ درخواست بعدی شما: " . ($remaining > 0 ? gmdate("i:s", $remaining) : "آماده ✅"));
        return;
    }
    
    if ($data === 'menu_search') {
        sendMessage($chatId, "🔍 *جستجوی یوتیوب*\n\nلطفاً عبارت مورد نظر خود را وارد کنید:");
        return;
    }
}

// ══════════════════════════════════════════════════════════
// ۱۲. روتر اصلی
// ══════════════════════════════════════════════════════════

$db = initDatabase();
$requestMethod = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

if ($requestMethod === 'POST' && $input && isset($input['update_id'])) {
    $update = $input;
    
    if (isset($update['update_id'])) {
        $stmt = $db->prepare("SELECT update_id FROM processed_updates WHERE update_id = :update_id");
        $stmt->bindValue(':update_id', $update['update_id'], SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        if (!$result->fetchArray()) {
            $stmt = $db->prepare("INSERT INTO processed_updates (update_id) VALUES (:update_id)");
            $stmt->bindValue(':update_id', $update['update_id'], SQLITE3_INTEGER);
            $stmt->execute();
            
            if (isset($update['message'])) processMessage($update['message'], $db);
            if (isset($update['callback_query'])) processCallbackQuery($update['callback_query'], $db);
        }
    }
    
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
    
} else {
    if ($requestMethod === 'GET') {
        echo "<h1>✅ Bale YouTube Downloader Gateway</h1>";
        echo "<p>Gateway is running! Set your webhook to this URL.</p>";
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid Request']);
    }
}
?>
