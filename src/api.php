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

// Get SMTP settings from environment variables or .env file
$smtpHost = getenv('SMTP_HOST') ?: $env['SMTP_HOST'] ?? null;
$smtpPort = getenv('SMTP_PORT') ?: $env['SMTP_PORT'] ?? 587;
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

// --- Added: Forced creation of directories and files ---
// Define file paths
$keysFile = '/var/www/data/keys.json';
$logFile = '/var/www/logs/license.log';

// If files don't exist in the main path, use path relative to the script
if (!file_exists(dirname($keysFile))) {
    $keysFile = __DIR__ . '/data/keys.json';
}
if (!file_exists(dirname($logFile))) {
    $logFile = __DIR__ . '/logs/license.log';
}

// Forced creation of directories and files
ensureFilesAndDirectories($keysFile, $logFile);
// --- End of addition ---

// --- Added: Function for creating directories and files ---
function ensureFilesAndDirectories($keysFile, $logFile) {
    // Create directory for keys.json
    $keysDir = dirname($keysFile);
    if (!is_dir($keysDir)) {
        if (!mkdir($keysDir, 0755, true)) {
            // If directory creation failed, try creating in __DIR__
            $keysDir = __DIR__ . '/data';
            $keysFile = $keysDir . '/keys.json';
            if (!is_dir($keysDir)) {
                mkdir($keysDir, 0755, true);
            }
        }
    }

    // Create directory for license.log
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0755, true)) {
            // If directory creation failed, try creating in __DIR__
            $logDir = __DIR__ . '/logs';
            $logFile = $logDir . '/license.log';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
        }
    }

    // Create keys.json file if it doesn't exist
    if (!file_exists($keysFile)) {
        if (file_put_contents($keysFile, '{}') === false) {
            // Try creating in __DIR__ if the main path doesn't work
            $keysFile = __DIR__ . '/data/keys.json';
            if (!file_exists(dirname($keysFile))) {
                mkdir(dirname($keysFile), 0755, true);
            }
            file_put_contents($keysFile, '{}');
        }
    }

    // Create license.log file if it doesn't exist
    if (!file_exists($logFile)) {
        if (file_put_contents($logFile, '') === false) {
            // Try creating in __DIR__ if the main path doesn't work
            $logFile = __DIR__ . '/logs/license.log';
            if (!file_exists(dirname($logFile))) {
                mkdir(dirname($logFile), 0755, true);
            }
            file_put_contents($logFile, '');
        }
    }

    // Set permissions (optional)
    // chmod($keysFile, 0666);
    // chmod($logFile, 0666);
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
    // If not, files need to be included manually
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        // Attempt to connect via Composer autoload
        if (file_exists(__DIR__ . '/vendor/autoload.php')) {
            require_once __DIR__ . '/vendor/autoload.php';
        } else {
            // If Composer is not used, attempt manual connection
            // You will need to specify the correct path to PHPMailer files
            // For example:
            // require_once __DIR__ . '/path/to/PHPMailer/src/PHPMailer.php';
            // require_once __DIR__ . '/path/to/PHPMailer/src/SMTP.php';
            // require_once __DIR__ . '/path/to/PHPMailer/src/Exception.php';
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
function logAction($action, $details = [], $keysFile) {
    $logDir = dirname($keysFile) . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/license.log';

    // Use ISO 8601 format with timezone information
    $timestamp = date('c'); // This will respect the container's timezone

    $logEntry = [
        'timestamp' => $timestamp,
        'action' => $action,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'details' => $details
    ];

    $logLine = json_encode($logEntry) . "\n";

    // Append to log file
    file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}

// Function to load keys with permission check
function loadKeys($keysFile) {
    // Check if directory exists
    $dir = dirname($keysFile);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            throw new Exception("Cannot create directory: $dir");
        }
    }

    // Check if file exists
    if (!file_exists($keysFile)) {
        // Create empty file
        if (file_put_contents($keysFile, '{}') === false) {
            throw new Exception("Cannot create keys file: $keysFile");
        }
        //chmod($keysFile, 0666);
    }

    // Check read permissions
    if (!is_readable($keysFile)) {
        throw new Exception("Keys file is not readable: $keysFile");
    }

    $content = file_get_contents($keysFile);
    $keys = json_decode($content, true);

    return is_array($keys) ? $keys : [];
}

// Function to save keys with permission check
function saveKeys($keysFile, $keys) {
    // Check directory
    $dir = dirname($keysFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // Save with validation
    $content = json_encode($keys, JSON_PRETTY_PRINT);
    if (file_put_contents($keysFile, $content) === false) {
        throw new Exception("Failed to save keys to: $keysFile");
    }

    // Set permissions
    //chmod($keysFile, 0666);
    return true;
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

// Function to generate license key
function generateLicenseKey($user, $product, $days = 0, $customKey = null, $ipAddress = null) {
    if ($customKey) {
        // Use custom key
        $key = $customKey;
    } else {
        // Generate unique key
        $uniqueId = substr(strtoupper(md5($user . $product . time() . rand())), 0, 16);
        // Key format: XXXX-XXXX-XXXX-XXXX
        $key = implode('-', str_split($uniqueId, 4));
    }

    // Expiration date
    $expires = null;
    if ($days > 0) {
        $expires = date('c', strtotime("+$days days"));
    }

    $licenseData = [
        'user' => $user,
        'product' => $product,
        'created' => date('c'), // ISO 8601 format with timezone
        'expires' => $expires,
        'activated' => false
    ];

    // Add IP address if provided
    if ($ipAddress) {
        $licenseData['ip_address'] = $ipAddress;
    }

    return [
        'key' => $key,
        'data' => $licenseData
    ];
}

// Function to validate license
function validateLicense($licenseKey, $keysFile) {
    $validKeys = loadKeys($keysFile);

    if (!isset($validKeys[$licenseKey])) {
        return [
            'valid' => false,
            'reason' => 'Invalid license key'
        ];
    }

    $keyData = $validKeys[$licenseKey];

    // Check if the license is activated
    if (!isset($keyData['activated']) || !$keyData['activated']) {
        return [
            'valid' => false,
            'reason' => 'License not activated',
            'not_activated' => true
        ];
    }

    if (isset($keyData['expires']) && $keyData['expires'] !== null) {
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
        'activated' => $keyData['activated'] ?? false
    ];

    // Add IP address to response if it exists in license data
    if (isset($keyData['ip_address'])) {
        $response['ip_address'] = $keyData['ip_address'];
    }

    return $response;
}

// Function to activate license
function activateLicense($licenseKey, $keysFile) {
    $validKeys = loadKeys($keysFile);

    if (!isset($validKeys[$licenseKey])) {
        return false;
    }

    $validKeys[$licenseKey]['activated'] = true;
    $validKeys[$licenseKey]['activation_date'] = date('c'); // ISO 8601 format

    return saveKeys($keysFile, $validKeys);
}

// Function to create license (with or without admin key)
function createLicense($licenseData, $adminAuthKey, $requiredAdminKey, $keysFile, $adminEmail = null) {
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

    // Load existing keys
    $validKeys = loadKeys($keysFile);

    // Check if key already exists
    if (isset($validKeys[$license['key']])) {
        throw new Exception('License key already exists', 409);
    }

    // If this is not an admin, the license is created deactivated
    if (!$isAdmin) {
        $license['data']['activated'] = false;
        // Send notifications
        sendLicenseCreationNotifications($license, $user, $adminEmail);
    } else {
        // If this is an admin, the license is created activated
        $license['data']['activated'] = true;
        $license['data']['activation_date'] = date('c');
    }

    // Add new key
    $validKeys[$license['key']] = $license['data'];

    // Save changes
    saveKeys($keysFile, $validKeys);

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

// Function to delete license
function deleteLicense($licenseKey, $adminAuthKey, $requiredAdminKey, $keysFile) {
    if ($adminAuthKey !== $requiredAdminKey) {
        throw new Exception('Unauthorized: Invalid admin key', 401);
    }

    $validKeys = loadKeys($keysFile);

    if (!isset($validKeys[$licenseKey])) {
        return [
            'deleted' => false,
            'reason' => 'License key not found'
        ];
    }

    $deletedKeyInfo = $validKeys[$licenseKey];
    unset($validKeys[$licenseKey]);

    saveKeys($keysFile, $validKeys);

    return [
        'deleted' => true,
        'key' => $licenseKey,
        'deleted_info' => $deletedKeyInfo
    ];
}

// Function to list all licenses with pagination, search and filter
// Updated function to support pagination, search and filtering
function listAllLicenses($adminAuthKey, $requiredAdminKey, $keysFile, $page = 1, $limit = 20, $search = '', $status = '') {
    if ($adminAuthKey !== $requiredAdminKey) {
        throw new Exception('Unauthorized: Invalid admin key', 401);
    }

    $validKeys = loadKeys($keysFile);
    $total = count($validKeys);

    // Apply search filter
    if (!empty($search)) {
        $search = strtolower($search);
        $validKeys = array_filter($validKeys, function($license, $key) use ($search) {
            return stripos($key, $search) !== false ||
                   (isset($license['user']) && stripos($license['user'], $search) !== false) ||
                   (isset($license['product']) && stripos($license['product'], $search) !== false) ||
                   (isset($license['ip_address']) && stripos($license['ip_address'], $search) !== false);
        }, ARRAY_FILTER_USE_BOTH);
    }

    // Apply status filter
    if (!empty($status) && $status !== 'all') {
        $validKeys = array_filter($validKeys, function($license) use ($status) {
            $isExpired = isset($license['expires']) && $license['expires'] !== null &&
                         new DateTime($license['expires']) < new DateTime();
            $isActivated = isset($license['activated']) && $license['activated'];
            $isActive = $isActivated && !$isExpired;

            switch ($status) {
                case 'active':
                    return $isActive;
                case 'inactive':
                    return !$isActivated && !$isExpired;
                case 'expired':
                    return $isExpired;
                default:
                    return true;
            }
        });
    }

    // Recalculate total after filtering
    $filteredTotal = count($validKeys);

    // Calculate pagination
    $offset = ($page - 1) * $limit;
    $paginatedKeys = array_slice($validKeys, $offset, $limit, true);

    $pages = ceil($filteredTotal / $limit);

    return [
        'count' => count($paginatedKeys),
        'total' => $filteredTotal,
        'page' => $page,
        'pages' => $pages > 0 ? $pages : 1,
        'limit' => $limit,
        'licenses' => $paginatedKeys
    ];
}

// Function to get log file content with pagination
// Updated function to support log pagination
function getLogFileContent($adminAuthKey, $requiredAdminKey, $logFile, $page = 1, $limit = 50, $operationFilter = '') {
    // Check admin key
    if ($adminAuthKey !== $requiredAdminKey) {
        throw new Exception('Unauthorized: Invalid admin key', 401);
    }

    // Check if log file exists
    if (!file_exists($logFile)) {
        return [
            'content' => [],
            'count' => 0,
            'total' => 0,
            'page' => $page,
            'pages' => 1,
            'limit' => $limit,
            'file_exists' => false
        ];
    }

    // Check read permissions
    if (!is_readable($logFile)) {
        throw new Exception('Log file is not readable', 403);
    }

    // Read file content
    $content = file_get_contents($logFile);

    if (empty($content)) {
        return [
            'content' => [],
            'count' => 0,
            'total' => 0,
            'page' => $page,
            'pages' => 1,
            'limit' => $limit,
            'file_exists' => true
        ];
    }

    // Split into lines
    $lines = explode("\n", trim($content));

    // Remove empty lines
    $lines = array_filter($lines, function($line) {
        return !empty(trim($line));
    });

    // Convert JSON lines to arrays
    $logEntries = [];
    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if ($entry !== null) {
            $logEntries[] = $entry;
        }
    }

    // Reverse order (new entries first)
    $logEntries = array_reverse($logEntries);

    if ((!empty($operationFilter)) && ($operationFilter != 'all')) {
        $logEntries = array_filter($logEntries, function($entry) use ($operationFilter) {
            return isset($entry['action']) && $entry['action'] === $operationFilter;
        });
    }

    $total = count($logEntries);
    $pages = ceil($total / $limit);

    // Calculate pagination
    $offset = ($page - 1) * $limit;
    $paginatedEntries = array_slice($logEntries, $offset, $limit);

    return [
        'content' => $paginatedEntries,
        'count' => count($paginatedEntries),
        'total' => $total,
        'page' => $page,
        'pages' => $pages > 0 ? $pages : 1,
        'limit' => $limit,
        'file_exists' => true
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
            $result = validateLicense($licenseKey, $keysFile);

            // --- Added: Send email when license expires ---
            if (isset($result['expired']) && $result['expired'] === true) {
                // Load license data to get user email
                $validKeys = loadKeys($keysFile);
                if (isset($validKeys[$licenseKey])) {
                    $licenseInfo = $validKeys[$licenseKey];
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
            }
            // --- End of addition ---

            // Log validation
            logAction('validate', [
                'key' => $licenseKey,
                'valid' => $result['valid'],
                'reason' => $result['valid'] ? null : $result['reason']
            ], $keysFile);
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

            $validation = validateLicense($licenseKey, $keysFile);
            if (!$validation['valid'] && isset($validation['not_activated']) && $validation['not_activated']) {
                $activated = activateLicense($licenseKey, $keysFile);
                if ($activated) {
                    $result = [
                        'valid' => true,
                        'activated' => true,
                        'just_activated' => true,
                        'message' => 'License successfully activated'
                    ];

                    // --- Added: Send email when license is activated ---
                    // Load license data to get user email
                    $validKeys = loadKeys($keysFile);
                    if (isset($validKeys[$licenseKey])) {
                        $licenseInfo = $validKeys[$licenseKey];
                        $userEmail = $licenseInfo['user'] ?? null;
                        if ($userEmail && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                            // Determine client locale
                            $clientLocale = getClientLocale();

                            $subject = getLocalizedText('email_license_activated_subject', $clientLocale, [
                                'product' => $licenseInfo['product']
                            ]);

                            $message = getLocalizedText('email_license_activated_body', $clientLocale, [
                                'product' => $licenseInfo['product'],
                                'key' => $licenseKey,
                                'activation_date' => date('c')
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
            ], $keysFile);
            break;

        case 'create':
            $result = createLicense($licenseData, $adminKeyParam, $adminKey, $keysFile, $adminEmail);

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
            ], $keysFile);
            break;

        case 'delete':
            if (!$licenseKey) {
                throw new Exception('License key required for deletion', 400);
            }
            if (!$adminKeyParam) {
                throw new Exception('Admin key required for deletion', 401);
            }

            // --- Added: Send email when license is deleted ---
            // Load license data before deletion to get user email
            $validKeys = loadKeys($keysFile);
            if (isset($validKeys[$licenseKey])) {
                $licenseInfo = $validKeys[$licenseKey];
                $userEmail = $licenseInfo['user'] ?? null;
                if ($userEmail && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                    // Determine client locale
                    $clientLocale = getClientLocale();

                    $subject = getLocalizedText('email_license_deleted_subject', $clientLocale, [
                        'product' => $licenseInfo['product']
                    ]);

                    $message = getLocalizedText('email_license_deleted_body', $clientLocale, [
                        'product' => $licenseInfo['product'],
                        'key' => $licenseKey,
                        'deletion_date' => date('c')
                    ]);

                    sendLicenseEmail($userEmail, $subject, $message);
                }
                $result = deleteLicense($licenseKey, $adminKeyParam, $adminKey, $keysFile);
            }
            // --- End of addition ---

            // Log deletion
            logAction('delete', [
                'key' => $licenseKey,
                'deleted' => $result['deleted'],
                'reason' => $result['deleted'] ? null : $result['reason']
            ], $keysFile);
            break;

        case 'list':
            if (!$adminKeyParam) {
                throw new Exception('Admin key required', 401);
            }
            // Use the updated function with pagination, search and filtering
            $result = listAllLicenses($adminKeyParam, $adminKey, $keysFile, $page, $limit, $search, $status);

            // Log list access
            logAction('list', [
                'count' => $result['count'],
                'page' => $page,
                'limit' => $limit
            ], $keysFile);
            break;

        case 'logs':
            if (!$adminKeyParam) {
                throw new Exception('Admin key required for log access', 401);
            }
            // Get operation filter parameter
            $operationFilter = $data['operation'] ?? $_GET['operation'] ?? '' ?? 'all';

            // Pass parameter to function
            $result = getLogFileContent($adminKeyParam, $adminKey, $logFile, $page, $limit, $operationFilter);

            // Log logs access
            logAction('logs_access', [
                'limit' => $limit,
                'page' => $page,
                'entries_returned' => $result['count'],
                'operation_filter' => $operationFilter // Log applied filter
            ], $keysFile);
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
                ], $keysFile);

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
                ], $keysFile);

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
    ], $keysFile);

    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('c') // ISO 8601 format with timezone
    ], JSON_PRETTY_PRINT);
}
?>
