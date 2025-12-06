<?php
// Set error handler to convert errors to exceptions
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        // This error code is not included in error_reporting
        return;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Set exception handler
set_exception_handler(function($exception) {
    // Ensure headers have not been sent yet
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'error' => 'Internal Server Error: ' . $exception->getMessage(),
        'type' => get_class($exception),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT);
    // Log fatal error
    error_log("Fatal error: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
});

// Register shutdown function to handle fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        // Ensure headers have not been sent yet
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'error' => 'Fatal Error: ' . $error['message'],
            'type' => 'Fatal Error',
            'file' => $error['file'],
            'line' => $error['line'],
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
        // Log fatal error
        error_log("Fatal error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
    }
});

// Set timezone from environment variable
$tz = getenv('TZ') ?: 'UTC';
date_default_timezone_set($tz);

// If called without parameters, redirect to Swagger UI
if (empty($_GET) && empty($_POST) && $_SERVER['REQUEST_METHOD'] === 'GET' &&
    (!isset($_SERVER['HTTP_ACCEPT']) || strpos($_SERVER['HTTP_ACCEPT'], 'application/json') === false)) {
    header('Location: /api/swagger');
    exit();
}

// API endpoint
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Get request data
$input = file_get_contents('php://input');
$data = json_decode($input, true);
// If data is not JSON, try to get from GET/POST
if (!$data && !empty($_REQUEST)) {
    $data = $_REQUEST;
}

// Get secret values from environment variables
$secretKey = getenv('LICENSE_SECRET_KEY') ?: 'default_secret_key';
$salt = getenv('LICENSE_SALT') ?: 'default_salt';
$adminKey = getenv('ADMIN_KEY') ?: 'admin_secret_key_2023';
$logLevel = getenv('LOG_LEVEL') ?: 'info'; // Получаем уровень логирования из .env, по умолчанию 'info'
$logHousekeepingDays = (int) getenv('LOG_HOUSEKEEPING'); // Получаем количество дней для хранения логов, по умолчанию 0 (отключено)
// Ограничиваем значение, чтобы оно было положительным
$logHousekeepingDays = $logHousekeepingDays > 0 ? $logHousekeepingDays : 0;
$licenseKeyTemplate = getenv('LICENSE_KEY_TEMPLATE') ?: 'XXXX-XXXX-XXXX-XXXX'; // Шаблон по умолчанию

// --- Added: Loading environment variables from .env file ---
function loadEnv($path = '.env') {
    if (!file_exists($path)) {
        return [];
    }
    $env = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        // Split key and value
        if (strpos($line, '=') !== false) {
            list($key, $value) = array_map('trim', explode('=', $line, 2));
            $env[$key] = $value;
        }
    }
    return $env;
}

// Load variables from .env file
$env = loadEnv(__DIR__ . '/.env');

// Get Redis settings from environment variables or .env file
$redisHost = getenv('REDIS_HOST') ?: $env['REDIS_HOST'] ?? '127.0.0.1';
$redisPort = (int) (getenv('REDIS_PORT') ?: $env['REDIS_PORT'] ?? 6379);
$redisPassword = getenv('REDIS_PASSWORD') ?: $env['REDIS_PASSWORD'] ?? null; // Optional
$redisDatabase = (int) (getenv('REDIS_DATABASE') ?: $env['REDIS_DATABASE'] ?? 0); // Optional

// Get SMTP settings from environment variables or .env file
$smtpHost = getenv('SMTP_HOST') ?: $env['SMTP_HOST'] ?? null;
$smtpPort = (int) (getenv('SMTP_PORT') ?: $env['SMTP_PORT'] ?? 587);
$smtpUsername = getenv('SMTP_USERNAME') ?: $env['SMTP_USERNAME'] ?? null;
$smtpPassword = getenv('SMTP_PASSWORD') ?: $env['SMTP_PASSWORD'] ?? null;
$smtpEncryption = getenv('SMTP_ENCRYPTION') ?: $env['SMTP_ENCRYPTION'] ?? 'tls'; // 'tls', 'ssl' or ''
$smtpFrom = getenv('SMTP_FROM') ?: $env['SMTP_FROM'] ?? 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$smtpFromName = getenv('SMTP_FROM_NAME') ?: $env['SMTP_FROM_NAME'] ?? 'License Server';
$sendEmails = filter_var(getenv('SEND_EMAILS') ?: $env['SEND_EMAILS'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
$adminEmail = getenv('ADMIN_EMAIL') ?: $env['ADMIN_EMAIL'] ?? null;

// --- End of addition ---

// --- Added: Function to determine client locale ---
function getClientLocale() {
    // Supported locales
    $supportedLocales = ['en', 'ru'];
    $defaultLocale = 'en';
    // Check Accept-Language header
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        foreach ($langs as $lang) {
            $locale = substr(trim($lang), 0, 2); // Get first 2 characters (e.g., 'en')
            if (in_array($locale, $supportedLocales)) {
                return $locale;
            }
        }
    }
    return $defaultLocale;
}

// --- Added: Function to load language file ---
function loadLanguageFile($locale) {
    $langFile = __DIR__ . '/lang/' . $locale . '.json';
    if (file_exists($langFile)) {
        $content = file_get_contents($langFile);
        $langData = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $langData;
        }
    }
    return null;
}

// --- Added: Function to get localized text ---
function getLocalizedText($key, $locale = null, $placeholders = []) {
    if ($locale === null) {
        $locale = getClientLocale();
    }
    $langData = loadLanguageFile($locale);
    // If language file couldn't be loaded, use English by default
    if ($langData === null && $locale !== 'en') {
        $langData = loadLanguageFile('en');
    }
    // If still couldn't load, return the key
    if ($langData === null) {
        return $key;
    }
    $text = isset($langData[$key]) ? $langData[$key] : $key;
    // Replace placeholders
    foreach ($placeholders as $placeholder => $value) {
        $text = str_replace('{' . $placeholder . '}', $value, $text);
    }
    return $text;
}

// --- End of addition ---

// --- Added: Redis Connection and Setup ---
// Include Redis library (usually loaded as extension)
if (!extension_loaded('redis')) {
    error_log("Redis extension (phpredis) not loaded. Please install and enable it.");
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Redis extension not loaded. Server configuration error.',
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT);
    exit();
}

// Establish connection
try {
    $redis = new Redis();
    $redis->connect($redisHost, $redisPort);

    if ($redisPassword) {
        $redis->auth($redisPassword);
    }

    if ($redisDatabase !== 0) {
        $redis->select($redisDatabase);
    }

    // Optional: Ping to ensure connection is alive
    if (!$redis->ping()) {
        throw new Exception("Failed to ping Redis server");
    }

    // Define the set key for all license keys
    $licensesSetKey = 'licenses';

} catch (Exception $e) {
    error_log("Failed to connect to Redis: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to connect to Redis: ' . $e->getMessage(),
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT);
    exit();
}
// --- End of addition ---

// --- Added: Function to send email via SMTP ---
function sendLicenseEmail($to, $subject, $message) {
    global $sendEmails, $smtpHost, $smtpPort, $smtpUsername, $smtpPassword, $smtpEncryption, $smtpFrom, $smtpFromName;
    // Check if email sending is enabled
    if (!$sendEmails) {
        error_log("Email sending is disabled. Would send to $to: $subject");
        return true; // Consider successful to avoid interrupting the main process
    }
    // Check if all SMTP parameters are set
    if (!$smtpHost || !$smtpUsername || !$smtpPassword) {
        error_log("SMTP settings are not configured properly. Cannot send email to $to: $subject");
        return false;
    }
    // Include PHPMailer
    // It's assumed that PHPMailer is installed via Composer
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        if (file_exists(__DIR__ . '/vendor/autoload.php')) {
            require_once __DIR__ . '/vendor/autoload.php';
        } else {
            error_log("PHPMailer class not found. Please install it via Composer or include the files manually.");
            return false;
        }
    }
    // Create PHPMailer instance
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUsername;
        $mail->Password   = $smtpPassword;
        $mail->SMTPSecure = $smtpEncryption === 'ssl' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS :
                           ($smtpEncryption === 'tls' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '');
        $mail->Port       = $smtpPort;
        // Encoding
        $mail->CharSet = 'UTF-8';
        // Recipient and sender
        $mail->setFrom($smtpFrom, $smtpFromName);
        $mail->addAddress($to);
        // Email content
        $mail->isHTML(false); // Send as plain text
        $mail->Subject = $subject;
        $mail->Body    = $message;
        // Send email
        $mail->send();
        error_log("Email successfully sent to $to: $subject");
        return true;
    } catch (Exception $e) {
        error_log("Failed to send email to $to: $subject. Error: " . $mail->ErrorInfo);
        return false;
    }
}
// --- End of addition ---

// Logging function with proper timezone handling
// Uses Redis for logs
function logAction($action, $details = []) {
    global $redis, $logHousekeepingDays; // Access global Redis connection and housekeeping setting

    // Use ISO 8601 format with timezone information
    $timestamp = date('c'); // This will respect the container's timezone
    $logEntry = [
        'timestamp' => $timestamp,
        'action' => $action,
        'ip' => getClientIP(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'details' => $details
    ];

    // Log to Redis using LPUSH to add to the beginning of the list
    $logListKey = 'license_logs';
    $redis->lPush($logListKey, json_encode($logEntry));

    // --- NEW: Optimized Log Housekeeping ---
    if ($logHousekeepingDays > 0) {
        $cleanupKey = 'log_cleanup_last_run'; // Key to store the last cleanup timestamp
        $now = time(); // Current Unix timestamp
        $cutoffTime = $now - 86400; // 24 hours ago (in seconds)

        // Get the timestamp of the last cleanup run from Redis
        $lastRunStr = $redis->get($cleanupKey);
        $lastRun = $lastRunStr ? (int)$lastRunStr : 0;

        // Check if 24 hours have passed since the last cleanup
        if ($lastRun < $cutoffTime) {
            // Calculate the timestamp for the cutoff date (based on current time)
            $cutoffTimestamp = date('c', strtotime("-{$logHousekeepingDays} days", $now));

            // Get all log entries from Redis
            $allLogEntries = $redis->lRange($logListKey, 0, -1);

            // Filter out entries older than the cutoff
            $filteredLogEntries = array_filter($allLogEntries, function($entryJson) use ($cutoffTimestamp) {
                $entry = json_decode($entryJson, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // If JSON is invalid, keep it to avoid losing data (or log an error)
                    return true;
                }
                return isset($entry['timestamp']) && $entry['timestamp'] >= $cutoffTimestamp;
            });

            // If filtering resulted in fewer entries, overwrite the Redis list
            if (count($filteredLogEntries) < count($allLogEntries)) {
                // Delete the old list
                $redis->del($logListKey);
                // Push the filtered entries back to the list
                if (!empty($filteredLogEntries)) {
                    // Use rPush to add them back in chronological order (oldest first)
                    foreach ($filteredLogEntries as $entryJson) {
                        $redis->rPush($logListKey, $entryJson);
                    }
                }
                // Optional: Re-trim to enforce maxLogEntries limit after housekeeping
                // $maxLogEntries = 1000; // Define this constant or get from env if needed
                // $redis->lTrim($logListKey, 0, $maxLogEntries - 1);
            }

            // Update the timestamp of the last cleanup run in Redis
            $redis->set($cleanupKey, (string)$now);
        }
    }
    // --- END NEW ---

    // Optional: Limit the size of the log list to prevent unbounded growth
    // For example, keep only the last 1000 log entries
    $maxLogEntries = 1000; // Define this constant or get from env if needed
    $redis->lTrim($logListKey, 0, $maxLogEntries - 1);

    // Optionally, still log to file as well (for debugging or backup)
    // $logDir = __DIR__ . '/logs';
    // if (!is_dir($logDir)) {
    //     mkdir($logDir, 0755, true);
    // }
    // $logFile = $logDir . '/license.log';
    // $logLine = json_encode($logEntry) . "\n";
    // file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}

// Function to get client IP address
function getClientIP() {
    // Check X-Forwarded-For header (for proxy/Nginx)
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // May contain a list of IPs separated by commas. Take the first one.
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    // Check other possible headers
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    // If nothing found, use REMOTE_ADDR
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// Function to generate license key based on template
function generateLicenseKey($user, $product, $days = 0, $customKey = null, $ipAddress = null) {
    global $licenseKeyTemplate; // Access the template from global scope

    if ($customKey) {
        // Use custom key
        $key = $customKey;
    } else {
        // Generate unique part (uppercase hex)
        // Length of unique part is determined by the total length of 'X' placeholders in the template
        $template = $licenseKeyTemplate;
        $placeholderCount = substr_count($template, 'X');
        // Ensure we have at least one placeholder
        if ($placeholderCount < 1) {
             // Fallback if template has no X's, use default logic
             $uniqueId = substr(strtoupper(md5($user . $product . time() . rand())), 0, 16);
        } else {
             // Generate hex string of required length
             $uniqueId = substr(strtoupper(md5($user . $product . time() . rand())), 0, $placeholderCount);
        }

        // Replace the first occurrence of 'X' with the unique part
        // We need to replace each 'X' one by one
        $key = $template;
        $uniqueIndex = 0;
        for ($i = 0; $i < strlen($key); $i++) {
            if ($key[$i] === 'X' && $uniqueIndex < strlen($uniqueId)) {
                $key[$i] = $uniqueId[$uniqueIndex];
                $uniqueIndex++;
            }
        }
        // If for some reason uniqueId is shorter than placeholders, leave remaining 'X's or handle differently if needed
        // The above loop handles this by stopping when uniqueId runs out.
    }

    return [
        'key' => $key,
        'data' => [
            'user' => $user,
            'product' => $product,
            'created' => date('c'), // ISO 8601 format
            'expires' => $days > 0 ? date('c', strtotime("+$days days")) : null,
            'ip_address' => $ipAddress // Store IP address if provided
        ]
    ];
}

// Function to validate license using Redis
function validateLicense($licenseKey, $redis, $licensesSetKey) {
    try {
        // Check if the license key exists in the set of all keys
        if (!$redis->sIsMember($licensesSetKey, $licenseKey)) {
            return [
                'valid' => false,
                'reason' => 'Invalid license key'
            ];
        }

        // Get the license data hash
        $keyData = $redis->hGetAll("license:$licenseKey");

        if (empty($keyData)) {
             // This should not happen if sIsMember was true, but check anyway
             return [
                'valid' => false,
                'reason' => 'Invalid license key (data missing)'
            ];
        }

        // Check if the license is activated
        if (!isset($keyData['activated']) || $keyData['activated'] !== '1') { // Redis stores booleans as strings
            return [
                'valid' => false,
                'reason' => 'License not activated',
                'not_activated' => true
            ];
        }

        if (isset($keyData['expires']) && $keyData['expires'] !== null && $keyData['expires'] !== '') {
            $expiryDate = new DateTime($keyData['expires']);
            $now = new DateTime();
            if ($now > $expiryDate) {
                return [
                    'valid' => false,
                    'reason' => 'License expired',
                    'expired' => true,
                    'expiry_date' => $keyData['expires']
                ];
            }
        }

        // Prepare response, including IP address if it exists
        $response = [
            'valid' => true,
            'product' => $keyData['product'] ?? 'Unknown',
            'user' => $keyData['user'] ?? 'Anonymous',
            'expires' => $keyData['expires'] ?? null,
            'activated' => $keyData['activated'] === '1' // Convert string back to boolean
        ];
        // Add IP address to response if it exists in license data
        if (isset($keyData['ip_address'])) {
            $response['ip_address'] = $keyData['ip_address'];
        }
        return $response;
    } catch (Exception $e) {
        error_log("Database error during validation: " . $e->getMessage());
        throw $e; // Re-throw to be caught by main try-catch
    }
}

// Function to activate license using Redis
function activateLicense($licenseKey, $redis, $licensesSetKey) {
    try {
        // Check if the license key exists in the set of all keys
        if (!$redis->sIsMember($licensesSetKey, $licenseKey)) {
            return false;
        }

        // Update the activation status and set activation date in the hash
        $redis->hSet("license:$licenseKey", 'activated', '1'); // Redis stores booleans as strings
        $redis->hSet("license:$licenseKey", 'activation_date', date('c')); // ISO 8601 format

        // Optionally, you could also update the set key if needed, but for activation status, the hash is sufficient.
        return true;
    } catch (Exception $e) {
        error_log("Database error during activation: " . $e->getMessage());
        throw $e; // Re-throw to be caught by main try-catch
    }
}

// Function to create license (with or without admin key) using Redis
function createLicense($licenseData, $adminAuthKey, $requiredAdminKey, $redis, $licensesSetKey, $adminEmail = null) {
    $isAdmin = ($adminAuthKey === $requiredAdminKey);

    // Validate input data
    $user = isset($licenseData['user']) && $licenseData['user'] !== '' ? $licenseData['user'] : null;
    $product = isset($licenseData['product']) && $licenseData['product'] !== '' ? $licenseData['product'] : 'Default Product';
    $days = isset($licenseData['days']) ? (int)($licenseData['days']) : 0;
    $customKey = isset($licenseData['custom_key']) && $licenseData['custom_key'] !== '' ? $licenseData['custom_key'] : null;
    // Get IP address from request data or determine automatically
    $ipAddress = $licenseData['ip_address'] ?? getClientIP();

    if (!$user) {
        throw new Exception('User name is required', 400);
    }
    // Check if email is valid
    if (!filter_var($user, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('User name must be a valid email address', 400);
    }

    // Generate key
    $license = generateLicenseKey($user, $product, $days, $customKey, $ipAddress);

    // Check if key already exists in Redis
    if ($redis->sIsMember($licensesSetKey, $license['key'])) {
        throw new Exception('License key already exists', 409);
    }

    // If this is not an admin, the license is created deactivated
    if (!$isAdmin) {
        $license['data']['activated'] = false; // Will be stored as '0' in Redis
        // Send notifications
        sendLicenseCreationNotifications($license, $user, $adminEmail);
    } else {
        // If this is an admin, the license is created activated
        $license['data']['activated'] = true; // Will be stored as '1' in Redis
        $license['data']['activation_date'] = date('c');
    }

    // Store the license data as a hash in Redis
    $redis->hMSet("license:{$license['key']}", $license['data']);

    // Add the license key to the set of all keys
    $redis->sAdd($licensesSetKey, $license['key']);

    return [
        'created' => true,
        'key' => $license['key'],
        'license_info' => $license['data'],
        'message' => $isAdmin ? 'License created and activated' : 'License created, requires manual activation'
    ];
}

// Function to send notifications when license is created without admin key
function sendLicenseCreationNotifications($license, $userEmail, $adminEmail) {
    global $sendEmails;
    if (!$sendEmails) {
        return;
    }
    // Determine client locale
    $clientLocale = getClientLocale();
    // Send notification to user
    if ($userEmail && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        $subject = getLocalizedText('email_license_created_user_subject', $clientLocale);
        $expiresInfo = $license['data']['expires'] ?
            getLocalizedText('email_expires_on', $clientLocale, ['date' => $license['data']['expires']]) :
            getLocalizedText('email_never_expires', $clientLocale);
        $message = getLocalizedText('email_license_created_user_body', $clientLocale, [
            'product' => $license['data']['product'],
            'key' => $license['key'],
            'created' => $license['data']['created'],
            'expires_info' => $expiresInfo
        ]);
        sendLicenseEmail($userEmail, $subject, $message);
    }
    // Send notification to administrator
    if ($adminEmail && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $subject = getLocalizedText('email_license_created_admin_subject', $clientLocale);
        $expiresInfo = $license['data']['expires'] ?
            getLocalizedText('email_expires_on', $clientLocale, ['date' => $license['data']['expires']]) :
            getLocalizedText('email_never_expires', $clientLocale);
        $message = getLocalizedText('email_license_created_admin_body', $clientLocale, [
            'user' => $license['data']['user'],
            'product' => $license['data']['product'],
            'key' => $license['key'],
            'created' => $license['data']['created'],
            'expires_info' => $expiresInfo
        ]);
        sendLicenseEmail($adminEmail, $subject, $message);
    }
}

// Function to delete license using Redis
function deleteLicense($licenseKey, $adminAuthKey, $requiredAdminKey, $redis, $licensesSetKey) {
    if ($adminAuthKey !== $requiredAdminKey) {
        throw new Exception('Unauthorized: Invalid admin key', 401);
    }

    // Check if the license key exists in the set of all keys
    if (!$redis->sIsMember($licensesSetKey, $licenseKey)) {
        return [
            'deleted' => false,
            'reason' => 'License key not found'
        ];
    }

    // Get the license data *before* deletion to access user email
    $licenseToDelete = $redis->hGetAll("license:$licenseKey");

    if (empty($licenseToDelete)) {
         // This should not happen if sIsMember was true, but check anyway
         return [
            'deleted' => false,
            'reason' => 'License key not found (data missing)'
        ];
    }

    // Perform the deletion: remove the hash and the key from the set
    $redis->del("license:$licenseKey");
    $redis->sRem($licensesSetKey, $licenseKey);

    // Check if deletion was successful (both operations should have affected data)
    // The del command returns number of deleted keys, sRem returns number of removed members
    // If they were present, del should return 1, sRem should return 1.
    // However, since we checked existence first, we assume success if no exception occurred.

    return [
        'deleted' => true,
        'key' => $licenseKey,
        'deleted_info' => $licenseToDelete
    ];
}

// Function to list all licenses with pagination, search and filter using Redis
function listAllLicenses($adminAuthKey, $requiredAdminKey, $redis, $licensesSetKey, $page = 1, $limit = 20, $search = '', $status = '') {
    if ($adminAuthKey !== $requiredAdminKey) {
        throw new Exception('Unauthorized: Invalid admin key', 401);
    }

    // Get all license keys from the set
    $allKeys = $redis->sMembers($licensesSetKey);

    // Apply search filter (on keys and fields within hashes)
    $filteredKeys = [];
    if (!empty($search)) {
        $search = strtolower($search);
        foreach ($allKeys as $key) {
            $licenseData = $redis->hGetAll("license:$key");
            if (stripos($key, $search) !== false ||
                (isset($licenseData['user']) && stripos($licenseData['user'], $search) !== false) ||
                (isset($licenseData['product']) && stripos($licenseData['product'], $search) !== false) ||
                (isset($licenseData['ip_address']) && stripos($licenseData['ip_address'], $search) !== false)) {
                $filteredKeys[] = $key;
            }
        }
    } else {
        $filteredKeys = $allKeys; // No search, use all keys
    }

    // Apply status filter
    if (!empty($status) && $status !== 'all') {
        $tempFilteredKeys = [];
        foreach ($filteredKeys as $key) {
            $licenseData = $redis->hGetAll("license:$key");
            $isExpired = isset($licenseData['expires']) && $licenseData['expires'] !== null && $licenseData['expires'] !== '' &&
                         new DateTime($licenseData['expires']) < new DateTime();
            $isActivated = isset($licenseData['activated']) && $licenseData['activated'] === '1'; // Check for string '1'
            $isActive = $isActivated && !$isExpired;

            switch ($status) {
                case 'active':
                    if ($isActive) $tempFilteredKeys[] = $key;
                    break;
                case 'inactive':
                    if (!$isActivated && !$isExpired) $tempFilteredKeys[] = $key;
                    break;
                case 'expired':
                    if ($isExpired) $tempFilteredKeys[] = $key;
                    break;
                default:
                    $tempFilteredKeys[] = $key; // Should not happen if status is validated earlier
            }
        }
        $filteredKeys = $tempFilteredKeys;
    }

    $total = count($filteredKeys);

    // Calculate pagination
    $offset = ($page - 1) * $limit;
    $paginatedKeys = array_slice($filteredKeys, $offset, $limit, true);

    // Fetch the actual license data for the paginated keys
    $paginatedLicenses = [];
    foreach ($paginatedKeys as $key) {
        $licenseData = $redis->hGetAll("license:$key");
        // Ensure 'activated' is boolean for the API response
        $licenseData['activated'] = $licenseData['activated'] === '1';
        $paginatedLicenses[$key] = $licenseData;
    }

    $pages = ceil($total / $limit);

    return [
        'count' => count($paginatedLicenses),
        'total' => $total,
        'page' => $page,
        'pages' => $pages > 0 ? $pages : 1,
        'limit' => $limit,
        'licenses' => $paginatedLicenses
    ];
}

// Function to get log content with pagination and operation filter (reads from Redis)
function getLogFileContent($adminAuthKey, $requiredAdminKey, $redis, $logListKey, $page = 1, $limit = 50, $operationFilter = '', $data = []) {
    // Check admin key
    if ($adminAuthKey !== $requiredAdminKey) {
        throw new Exception('Unauthorized: Invalid admin key', 401);
    }

    // Validate page and limit (optional but recommended)
    if ($page < 1) $page = 1;
    if ($limit < 1) $limit = 50;

    // Get ALL log entries from Redis list
    // lRange with 0 and -1 gets the entire list
    // Assuming logs are stored newest first (LPUSH used in logAction)
    $allLogEntries = $redis->lRange($logListKey, 0, -1);

    // Convert JSON strings back to arrays
    $allLogEntries = array_map('json_decode', $allLogEntries, array_fill(0, count($allLogEntries), true));

    // Filter out potentially invalid entries where json_decode failed
    $allLogEntries = array_filter($allLogEntries, function($entry) {
        return is_array($entry) && isset($entry['timestamp']); // Basic check
    });

    //$searchTerm = $data['log_search'] ?? $_GET['log_search'] ?? ''; // Используем log_search, чтобы не путать с search для лицензий
    $searchTerm = $data['log_search'] ?? ''; // Получаем из $data

    // Apply operation filter BEFORE pagination
    if ((!empty($operationFilter)) && ($operationFilter != 'all')) {
        $allLogEntries = array_filter($allLogEntries, function($entry) use ($operationFilter) {
            // Ensure 'action' key exists and matches the filter
            return isset($entry['action']) && $entry['action'] === $operationFilter;
        });
    }

    if (!empty($searchTerm)) {
        $searchTerm = strtolower($searchTerm); // Для нечувствительности к регистру
        $allLogEntries = array_filter($allLogEntries, function($entry) use ($searchTerm) {
            // Проверяем наличие поискового термина в различных полях лога
            $entryString = '';
            if (isset($entry['ip'])) {
                $entryString .= ' ' . strtolower($entry['ip']);
            }
            if (isset($entry['user_agent'])) {
                $entryString .= ' ' . strtolower($entry['user_agent']);
            }
            if (isset($entry['details']) && is_array($entry['details'])) {
                // Рекурсивно преобразуем детали в строку для поиска
                $entryString .= ' ' . strtolower(json_encode($entry['details'], JSON_UNESCAPED_UNICODE));
            }
            // Добавьте другие поля, по которым нужно искать, если необходимо
            // Например, $entry['timestamp'], $entry['action'] (хотя action уже отфильтрован выше)
            return strpos($entryString, $searchTerm) !== false;
        });
    }

    // Calculate total number of entries AFTER filtering
    $total = count($allLogEntries);

    // Calculate pagination indices
    $offset = ($page - 1) * $limit;
    
    // Use array_slice for pagination on the filtered array
    // array_slice handles cases where offset is beyond the array length
    $logEntries = array_slice($allLogEntries, $offset, $limit, true); 

    // Count is the size of the current page's results (after slice)
    $count = count($logEntries);
    $pages = ceil($total / $limit);

    // Return the paginated and filtered content
    return [
        'content' => array_values($logEntries), // array_values reindexes the array if needed
        'count' => $count, // Number of entries returned in this request
        'total' => $total, // Total number of entries in Redis AFTER filtering
        'page' => $page,
        'pages' => $pages > 0 ? $pages : 1,
        'limit' => $limit,
        'file_exists' => true // Always true as it's reading from Redis
    ];
}

try {
    switch ($method) {
        case 'GET':
            $licenseKey = $_GET['key'] ?? null;
            $action = $_GET['action'] ?? 'validate';
            $adminKeyParam = $_GET['admin_key'] ?? null;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
            // GET pagination parameters
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? '';
            break;
        case 'POST':
            $licenseKey = $data['key'] ?? null;
            $action = $data['action'] ?? 'validate';
            $adminKeyParam = $data['admin_key'] ?? $_POST['admin_key'] ?? null;
            $licenseData = $data['license_data'] ?? $data;
            $limit = isset($data['limit']) ? (int)$data['limit'] : null;
            // POST pagination parameters
            $page = isset($data['page']) ? (int)$data['page'] : 1;
            $search = $data['search'] ?? '';
            $status = $data['status'] ?? '';
            break;
        case 'PUT':
            $action = 'create';
            $adminKeyParam = $data['admin_key'] ?? null;
            $licenseData = $data['license_data'] ?? $data;
            $page = 1;
            $search = '';
            $status = '';
            break;
        case 'DELETE':
            $licenseKey = $_GET['key'] ?? $data['key'] ?? null;
            $action = 'delete';
            $adminKeyParam = $_GET['admin_key'] ?? $data['admin_key'] ?? null;
            $page = 1;
            $search = '';
            $status = '';
            break;
        default:
            throw new Exception('Method not allowed', 405);
    }
    // Default limit if not set
    if ($limit === null) {
        $limit = ($action === 'logs') ? 50 : 20;
    }
    // Handle actions
    switch ($action) {
        case 'validate':
            if (!$licenseKey) {
                throw new Exception('License key required', 400);
            }
            $result = validateLicense($licenseKey, $redis, $licensesSetKey);
            // --- Added: Send email when license expires ---
            if (isset($result['expired']) && $result['expired'] === true) {
                // Load license data to get user email (already fetched in validateLicense)
                $licenseInfo = $result; // The result from validateLicense contains the data
                $userEmail = $licenseInfo['user'] ?? null;
                if ($userEmail && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                    // Determine client locale
                    $clientLocale = getClientLocale();
                    $subject = getLocalizedText('email_license_expired_subject', $clientLocale, [
                        'product' => $licenseInfo['product']
                    ]);
                    $message = getLocalizedText('email_license_expired_body', $clientLocale, [
                        'product' => $licenseInfo['product'],
                        'key' => $licenseKey,
                        'expires' => $licenseInfo['expires']
                    ]);
                    sendLicenseEmail($userEmail, $subject, $message);
                }
            }
            // --- End of addition ---
            // Log validation
            logAction('validate', [
                'key' => $licenseKey,
                'valid' => $result['valid'],
                'reason' => $result['valid'] ? null : $result['reason']
            ]);
            break;
        case 'activate':
            if (!$licenseKey) {
                throw new Exception('License key required', 400);
            }
            if (!$adminKeyParam) {
                throw new Exception('Admin key required for activation', 401);
            }
            if ($adminKeyParam !== $adminKey) {
                throw new Exception('Invalid admin key', 401);
            }
            $validation = validateLicense($licenseKey, $redis, $licensesSetKey);
            if (!$validation['valid'] && isset($validation['not_activated']) && $validation['not_activated']) {
                $activated = activateLicense($licenseKey, $redis, $licensesSetKey);
                if ($activated) {
                    $result = [
                        'valid' => true,
                        'activated' => true,
                        'just_activated' => true,
                        'message' => 'License successfully activated'
                    ];
                    // --- Added: Send email when license is activated ---
                    // Load license data to get user email (already fetched in validateLicense)
                    $licenseData = $redis->hGetAll("license:$licenseKey");
                    if (!empty($licenseData)) { // Check if the license still exists after activation
                        // Ensure 'activated' is boolean for the API response if needed elsewhere
                        $licenseData['activated'] = $licenseData['activated'] === '1';
                        $userEmail = $licenseData['user'] ?? null;
                        if ($userEmail && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                            // Determine client locale
                            $clientLocale = getClientLocale();
                            $subject = getLocalizedText('email_license_activated_subject', $clientLocale, [
                                'product' => $licenseData['product']
                            ]);
                            $message = getLocalizedText('email_license_activated_body', $clientLocale, [
                                'product' => $licenseData['product'],
                                'key' => $licenseKey,
                                'activation_date' => $licenseData['activation_date'] ?? date('c')
                            ]);
                            sendLicenseEmail($userEmail, $subject, $message);
                        }
                    }
                    // --- End of addition ---
                } else {
                    throw new Exception('Failed to activate license', 500);
                }
            } else {
                $result = $validation;
            }
            // Log activation
            logAction('activate', [
                'key' => $licenseKey,
                'success' => isset($result['just_activated']) && $result['just_activated'],
                'valid' => $result['valid']
            ]);
            break;
        case 'create':
            $result = createLicense($licenseData, $adminKeyParam, $adminKey, $redis, $licensesSetKey, $adminEmail);
            // --- Added: Send email when license is created ---
            $userEmail = $result['license_info']['user'] ?? null;
            if ($userEmail && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                if ($adminKeyParam === $adminKey) {
                    // License created by admin - send standard notification
                    // Determine client locale
                    $clientLocale = getClientLocale();
                    $subject = getLocalizedText('email_license_created_admin_direct_subject', $clientLocale, [
                        'product' => $result['license_info']['product']
                    ]);
                    $expiresInfo = $result['license_info']['expires'] ?
                        getLocalizedText('email_expires_on', $clientLocale, ['date' => $result['license_info']['expires']]) :
                        getLocalizedText('email_never_expires', $clientLocale);
                    $message = getLocalizedText('email_license_created_admin_direct_body', $clientLocale, [
                        'product' => $result['license_info']['product'],
                        'key' => $result['key'],
                        'created' => $result['license_info']['created'],
                        'expires_info' => $expiresInfo
                    ]);
                    sendLicenseEmail($userEmail, $subject, $message);
                }
                // If license is created without admin_key, notifications are already sent in createLicense function
            }
            // --- End of addition ---
            // Log creation
            logAction('create', [
                'key' => $result['key'],
                'user' => $result['license_info']['user'],
                'product' => $result['license_info']['product'],
                'expires' => $result['license_info']['expires'],
                'ip_address' => $result['license_info']['ip_address'] ?? null,
                'activated' => $result['license_info']['activated'] ?? false
            ]);
            break;
        case 'delete':
            if (!$licenseKey) {
                throw new Exception('License key required for deletion', 400);
            }
            if (!$adminKeyParam) {
                throw new Exception('Admin key required for deletion', 401);
            }
            // --- Added: Send email when license is deleted ---
            // The license data is fetched inside deleteLicense before deletion
            $result = deleteLicense($licenseKey, $adminKeyParam, $adminKey, $redis, $licensesSetKey);
            // --- End of addition ---
            // Log deletion
            logAction('delete', [
                'key' => $licenseKey,
                'deleted' => $result['deleted'],
                'reason' => $result['deleted'] ? null : $result['reason']
            ]);
            break;
        case 'list':
            if (!$adminKeyParam) {
                throw new Exception('Admin key required', 401);
            }
            // Use the updated function with pagination, search and filtering
            $result = listAllLicenses($adminKeyParam, $adminKey, $redis, $licensesSetKey, $page, $limit, $search, $status);
            // Log list access only if LOG_LEVEL is set to 'debug'
            if ($logLevel === 'debug') {
                logAction('list', [
                    'count' => $result['count'],
                    'page' => $page,
                    'limit' => $limit
                ]);
            }
            break;
        case 'logs':
            if (!$adminKeyParam) {
                throw new Exception('Admin key required for log access', 401);
            }
            // Get operation filter parameter
            $operationFilter = $data['operation'] ?? $_GET['operation'] ?? '' ?? 'all';
            // Pass parameter to function, now using Redis
            $result = getLogFileContent($adminKeyParam, $adminKey, $redis, 'license_logs', $page, $limit, $operationFilter, $data); // Новый вызов
            // Log logs access only if LOG_LEVEL is set to 'debug'
            if ($logLevel === 'debug') {
                logAction('logs_access', [
                    'limit' => $limit,
                    'page' => $page,
                    'entries_returned' => $result['count'],
                    'operation_filter' => $operationFilter // Log applied filter
                ]);
            }
            break;
        // Add new case in switch ($action) section:
        case 'test-email':
            if (!$adminKeyParam) {
                throw new Exception('Admin key required for email test', 401);
            }
            // Check if the provided admin key matches the expected one
            if ($adminKeyParam !== $adminKey) {
                throw new Exception('Invalid admin key', 401);
            }
            // Check if email sending is enabled
            if (!$sendEmails) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Email sending is disabled. Please set SEND_EMAILS=true in your .env file.',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
                exit();
            }
            // Check if SMTP parameters are configured
            if (!$smtpHost || !$smtpUsername || !$smtpPassword) {
                echo json_encode([
                    'success' => false,
                    'error' => 'SMTP settings are not configured properly. Please check your .env file.',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
                exit();
            }
            // Get administrator email from environment variables or use SMTP_FROM
            $testEmailTo = getenv('ADMIN_EMAIL') ?: $env['ADMIN_EMAIL'] ?? $smtpFrom;
            if (!filter_var($testEmailTo, FILTER_VALIDATE_EMAIL)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid test email address. Please set a valid ADMIN_EMAIL in your .env file.',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
                exit();
            }
            // Determine client locale
            $clientLocale = getClientLocale();
            // Form test message
            $subject = getLocalizedText('email_test_subject', $clientLocale);
            $message = getLocalizedText('email_test_body', $clientLocale, [
                'ip' => getClientIP(),
                'time' => date('c')
            ]);
            // Try to send email
            $emailSent = sendLicenseEmail($testEmailTo, $subject, $message);
            if ($emailSent) {
                // Log successful test email sending
                logAction('test_email', [
                    'to' => $testEmailTo,
                    'subject' => $subject,
                    'status' => 'sent'
                ]);
                echo json_encode([
                    'success' => true,
                    'message' => 'Test email sent successfully to ' . $testEmailTo,
                    'details' => [
                        'to' => $testEmailTo,
                        'subject' => $subject,
                        'sent_at' => date('c')
                    ],
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            } else {
                // Log test email sending error
                logAction('test_email', [
                    'to' => $testEmailTo,
                    'subject' => $subject,
                    'status' => 'failed'
                ]);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to send test email. Check server logs for more details.',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            exit(); // Finish execution to avoid outputting standard JSON response at the end of the script
            break;
        default:
            throw new Exception('Invalid action', 400);
    }
    $result['timestamp'] = date('c'); // ISO 8601 format with timezone
    $result['success'] = true;
    echo json_encode($result, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    // Log errors
    logAction('error', [
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'action' => $action ?? 'unknown'
    ]);
    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('c') // ISO 8601 format with timezone
    ], JSON_PRETTY_PRINT);
}

// Close the Redis connection if needed (optional, often handled automatically)
// $redis->close();
?>