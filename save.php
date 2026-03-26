<?php
/**
 * KIX Repository — save.php
 *
 * GET  ?action=load    → read and return repository.txt as JSON
 * POST ?action=save    → overwrite repository.txt with request body (plain text)
 * POST ?action=upload  → receive a file and save it to the correct folder
 *
 * All responses: { "ok": true/false, ... }
 */

// ── All data/media lives in ./home/ relative to this file ──
define('HOME_DIR',    __DIR__ . DIRECTORY_SEPARATOR . 'home' . DIRECTORY_SEPARATOR);
define('REPO_FILE',   HOME_DIR . 'data' . DIRECTORY_SEPARATOR . 'repository.txt');
define('UPLOAD_ROOT', HOME_DIR);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = trim($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// ──────────────────────────────────────────────────
// GET ?action=load  →  return repository.txt content
// ──────────────────────────────────────────────────
if ($method === 'GET' && $action === 'load') {
    if (!file_exists(REPO_FILE)) {
        // First run — file will be created on first save
        echo json_encode(['ok' => true, 'content' => '', 'note' => 'repository.txt does not exist yet']);
        exit;
    }
    $content = file_get_contents(REPO_FILE);
    if ($content === false) {
        echo json_encode(['ok' => false, 'error' => 'Cannot read repository.txt — check file permissions.', 'content' => '']);
        exit;
    }
    echo json_encode(['ok' => true, 'content' => $content]);
    exit;
}

// ──────────────────────────────────────────────────
// POST ?action=save  →  write repository.txt
// Body: full plain-text content to write
// ──────────────────────────────────────────────────
if ($method === 'POST' && $action === 'save') {
    $body = file_get_contents('php://input');
    if ($body === false || trim($body) === '') {
        echo json_encode(['ok' => false, 'error' => 'Empty body — nothing saved.']);
        exit;
    }
    // Ensure home/data/ directory exists
    $dataDir = dirname(REPO_FILE);
    if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true)) {
        echo json_encode(['ok' => false, 'error' => 'Cannot create data directory: ' . $dataDir]);
        exit;
    }

    // Atomic write: temp file then rename so we never corrupt the live file
    $tmp = REPO_FILE . '.tmp';
    if (file_put_contents($tmp, $body, LOCK_EX) === false) {
        echo json_encode(['ok' => false, 'error' => 'Cannot write temp file — check folder write permission on: ' . __DIR__]);
        exit;
    }
    // Keep a one-step backup
    if (file_exists(REPO_FILE)) {
        copy(REPO_FILE, REPO_FILE . '.bak');
    }
    if (!rename($tmp, REPO_FILE)) {
        echo json_encode(['ok' => false, 'error' => 'rename() failed — cannot replace repository.txt.']);
        exit;
    }
    echo json_encode(['ok' => true, 'bytes' => strlen($body)]);
    exit;
}

// ──────────────────────────────────────────────────
// POST ?action=upload  →  save an uploaded file
// Form fields:
//   file  (binary, multipart)
//   dest  (target path relative to repo root,
//          e.g. "docs/reports/report.pdf"
//          e.g. "photos/symposium-2025/cover.jpg")
// ──────────────────────────────────────────────────
if ($method === 'POST' && $action === 'upload') {

    // Check upload arrived without PHP-level error
    $uploadError = $_FILES['file']['error'] ?? -1;
    if (!isset($_FILES['file']) || $uploadError !== UPLOAD_ERR_OK) {
        $msgs = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini. Increase it in .htaccess or php.ini.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE directive.',
            UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file received.',
            UPLOAD_ERR_NO_TMP_DIR => 'No temp directory available.',
            UPLOAD_ERR_CANT_WRITE => 'Cannot write to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by PHP extension.',
        ];
        echo json_encode(['ok' => false, 'error' => $msgs[$uploadError] ?? 'Upload error code ' . $uploadError]);
        exit;
    }

    // Validate and sanitise destination path
    $dest = trim($_POST['dest'] ?? '');
    if ($dest === '') {
        echo json_encode(['ok' => false, 'error' => 'No dest field provided.']);
        exit;
    }
    $dest = str_replace('\\', '/', $dest);       // normalise slashes
    $dest = preg_replace('/\.\.+/', '', $dest);  // strip path traversal
    $dest = preg_replace('/\/+/', '/', $dest);   // collapse double slashes
    $dest = ltrim($dest, '/');
    if ($dest === '') {
        echo json_encode(['ok' => false, 'error' => 'Invalid dest path after sanitisation.']);
        exit;
    }

    // Whitelist allowed extensions
    $allowed = ['pdf','doc','docx','jpg','jpeg','png','gif','webp','mp4','mov','webm','zip'];
    $ext = strtolower(pathinfo($dest, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        echo json_encode(['ok' => false, 'error' => 'File type .' . $ext . ' is not allowed.']);
        exit;
    }

    // ── Append unique 6-char alphanumeric suffix before the extension ──
    // e.g. "report.pdf" → "report_a3kX9z.pdf"
    $pathInfo  = pathinfo($dest);
    $dir_part  = $pathInfo['dirname'] !== '.' ? $pathInfo['dirname'] . '/' : '';
    $baseName  = $pathInfo['filename'];
    $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
    $chars     = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $suffix    = '';
    for ($i = 0; $i < 6; $i++) {
        $suffix .= $chars[random_int(0, strlen($chars) - 1)];
    }
    $uniqueName = $baseName . '_' . $suffix . $extension;
    $dest       = $dir_part . $uniqueName;

    $fullDest = UPLOAD_ROOT . $dest;
    $dir      = dirname($fullDest);

    // Create destination folder tree if needed
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        echo json_encode(['ok' => false, 'error' => 'Cannot create folder: ' . $dir . ' — check permissions.']);
        exit;
    }
    if (!is_writable($dir)) {
        echo json_encode(['ok' => false, 'error' => 'Folder not writable: ' . $dir . ' — run: chmod -R 755 ' . $dir]);
        exit;
    }

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $fullDest)) {
        echo json_encode(['ok' => false, 'error' => 'move_uploaded_file() failed for: ' . $fullDest]);
        exit;
    }

    echo json_encode(['ok' => true, 'path' => $dest, 'size' => $_FILES['file']['size']]);
    exit;
}

// ──────────────────────────────────────────────────
// POST ?action=delete  →  delete one or more files
//
// JSON body: { "paths": ["home/docs/reports/file_abc123.pdf", ...] }
// Only deletes files inside HOME_DIR. Ignores http:// URLs.
// ──────────────────────────────────────────────────
if ($method === 'POST' && $action === 'delete') {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (!isset($data['paths']) || !is_array($data['paths'])) {
        echo json_encode(['ok' => false, 'error' => 'No paths array in request body.']);
        exit;
    }

    $deleted = [];
    $failed  = [];
    $skipped = [];

    foreach ($data['paths'] as $rawPath) {
        $rawPath = trim($rawPath);

        // Skip empty, URLs, or anything that looks like a URL
        if ($rawPath === '' || preg_match('#^https?://#i', $rawPath)) {
            $skipped[] = $rawPath;
            continue;
        }

        // Sanitise — strip path traversal
        $safePath = str_replace('\', '/', $rawPath);
        $safePath = preg_replace('/\.\.+/', '', $safePath);
        $safePath = preg_replace('/\/+/', '/', $safePath);
        $safePath = ltrim($safePath, '/');

        $fullPath = UPLOAD_ROOT . $safePath;

        // Security: must still be inside HOME_DIR after resolution
        $realHome = realpath(HOME_DIR);
        $realFile = realpath($fullPath);

        if ($realFile === false) {
            // File doesn't exist — treat as already deleted
            $skipped[] = $rawPath;
            continue;
        }

        if (strpos($realFile, $realHome) !== 0) {
            $failed[] = $rawPath . ' (outside allowed directory)';
            continue;
        }

        if (unlink($fullPath)) {
            $deleted[] = $rawPath;
        } else {
            $failed[] = $rawPath . ' (unlink failed — check permissions)';
        }
    }

    echo json_encode([
        'ok'      => count($failed) === 0,
        'deleted' => $deleted,
        'skipped' => $skipped,
        'failed'  => $failed,
    ]);
    exit;
}

// Catch-all
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action "' . htmlspecialchars($action) . '" or wrong HTTP method.']);
