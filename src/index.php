<?php
session_start();

// --- Localization Logic ---
$supportedLocales = ['en', 'ru'];
$defaultLocale = 'en';

// 1. Determine desired locale
// a. Check URL parameter (highest priority)
$requestedLocale = isset($_GET['lang']) ? $_GET['lang'] : null;
// b. Check session
$sessionLocale = isset($_SESSION['lang']) ? $_SESSION['lang'] : null;
// c. Check browser preference (Accept-Language header)
$browserLocales = [];
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    // Parse Accept-Language header (simplified)
    $langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
    foreach ($langs as $lang) {
        $locale = substr(trim($lang), 0, 2); // Get first 2 chars (e.g., 'en')
        if (in_array($locale, $supportedLocales)) {
            $browserLocales[] = $locale;
        }
    }
}
$browserLocale = !empty($browserLocales) ? $browserLocales[0] : null;

// d. Use default
$currentLocale = $defaultLocale;

// Priority order
if ($requestedLocale && in_array($requestedLocale, $supportedLocales)) {
    $currentLocale = $requestedLocale;
} elseif ($sessionLocale && in_array($sessionLocale, $supportedLocales)) {
    $currentLocale = $sessionLocale;
} elseif ($browserLocale && in_array($browserLocale, $supportedLocales)) {
    $currentLocale = $browserLocale;
}

// 2. Store locale in session
$_SESSION['lang'] = $currentLocale;

// 3. Load language file
$langFile = __DIR__ . "/lang/{$currentLocale}.json";
if (file_exists($langFile)) {
    $langJson = file_get_contents($langFile);
    $lang = json_decode($langJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Handle JSON decode error if needed, fallback to default
        $lang = json_decode(file_get_contents(__DIR__ . "/lang/{$defaultLocale}.json"), true);
        $currentLocale = $defaultLocale;
    }
} else {
    // Fallback to default language if file is missing
    $lang = json_decode(file_get_contents(__DIR__ . "/lang/{$defaultLocale}.json"), true);
    $currentLocale = $defaultLocale;
}
// --- End Localization Logic ---

// Check authentication - FIXED
$isAdmin = isset($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === true;
$adminKey = isset($_SESSION['admin_key']) ? $_SESSION['admin_key'] : '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_key'])) {
    $adminKeyInput = $_POST['admin_key'];
    $requiredAdminKey = getenv('ADMIN_KEY') ?: 'admin_secret_key_2023';
    if ($adminKeyInput === $requiredAdminKey) {
        $_SESSION['admin_auth'] = true;
        $_SESSION['admin_key'] = $adminKeyInput;
        $isAdmin = true;
        $adminKey = $adminKeyInput;
    } else {
        $loginError = $lang['invalid_admin_key'] ?? 'Invalid admin key.'; // Fallback if key is missing
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    $_SESSION['admin_auth'] = false;
    $_SESSION['admin_key'] = '';
    session_destroy();
    // Redirect to root, preserving language if needed or letting it be re-detected
    header('Location: /' . ($currentLocale !== $defaultLocale ? '?lang=' . $currentLocale : ''));
    exit();
}

// --- Preparing data for the template ---
// Pass all necessary variables to the template
$template_vars = [
    'currentLocale' => $currentLocale,
    'lang' => $lang,
    'isAdmin' => $isAdmin,
    'adminKey' => $adminKey,
    'loginError' => $loginError ?? null // Pass login error if exists
];

// Extract variables for convenience in the template
extract($template_vars);

// Include HTML template
include __DIR__ . '/main.html';

?>