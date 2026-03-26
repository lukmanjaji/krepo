<?php
/**
 * KIX Repository — save.php
 *
 * GET  ?action=ping    -> self-test, checks PHP + permissions
 * GET  ?action=load    -> return repository.txt contents as JSON
 * POST ?action=save    -> overwrite repository.txt with request body
 * POST ?action=upload  -> receive a file and save it to home/ folder
 * POST ?action=delete  -> delete one or more files from home/
 */

// ── All content lives in ./home/ relative to this file ──
define('HOME_DIR',    __DIR__ . '/home/');
define('REPO_FILE',   HOME_DIR . 'data/repository.txt');
define('UPLOAD_ROOT', HOME_DIR);

// ── Always return JSON, capture any stray PHP errors into the response ──
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_error_handler(function($code, $msg, $file, $line) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => "PHP error $code: $msg (line $line)"]);
    exit;
});
set_exception_handler(function($e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Exception: ' . $e->getMessage()]);
    exit;
});

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = isset($_GET['action']) ? trim($_GET['action']) : '';
$method = $_SERVER['REQUEST_METHOD'];

// ── Helper: sanitise a relative path, strip traversal ──
function safePath($raw) {
    $p = str_replace("\\", '/', $raw);
    $p = preg_replace('#\.\.+#', '', $p);
    $p = preg_replace('#/+#', '/', $p);
    return ltrim($p, '/');
}

// ──────────────────────────────────────────────
// GET ?action=ping  ->  self-test
// ──────────────────────────────────────────────
if ($method === 'GET' && $action === 'ping') {
    $dataDir       = dirname(REPO_FILE);
    $homeExists    = is_dir(HOME_DIR);
    $homeWrite     = $homeExists && is_writable(HOME_DIR);
    $dataDirExists = is_dir($dataDir);
    $dataDirWrite  = $dataDirExists && is_writable($dataDir);
    $repoExists    = file_exists(REPO_FILE);
    $repoRead      = $repoExists && is_readable(REPO_FILE);
    $repoWrite     = $repoExists && is_writable(REPO_FILE);

    echo json_encode([
        'ok'              => true,
        'php'             => PHP_VERSION,
        'home_dir'        => HOME_DIR,
        'home_exists'     => $homeExists,
        'home_write'      => $homeWrite,
        'data_dir_exists' => $dataDirExists,
        'data_dir_write'  => $dataDirWrite,
        'repo_exists'     => $repoExists,
        'repo_read'       => $repoRead,
        'repo_write'      => $repoWrite,
    ]);
    exit;
}

// ──────────────────────────────────────────────
// GET ?action=load  ->  return repository.txt
// ──────────────────────────────────────────────
if ($method === 'GET' && $action === 'load') {
    if (!file_exists(REPO_FILE)) {
        echo json_encode(['ok' => true, 'content' => '', 'note' => 'repository.txt does not exist yet — will be created on first save']);
        exit;
    }
    $content = file_get_contents(REPO_FILE);
    if ($content === false) {
        echo json_encode(['ok' => false, 'error' => 'Cannot read repository.txt — check file permissions', 'content' => '']);
        exit;
    }
    echo json_encode(['ok' => true, 'content' => $content]);
    exit;
}

// ──────────────────────────────────────────────
// POST ?action=save  ->  write repository.txt
// ──────────────────────────────────────────────
if ($method === 'POST' && $action === 'save') {
    $body = file_get_contents('php://input');
    if ($body === false || trim($body) === '') {
        echo json_encode(['ok' => false, 'error' => 'Empty body — nothing saved']);
        exit;
    }

    // Auto-create home/data/ if needed
    $dataDir = dirname(REPO_FILE);
    if (!is_dir($dataDir)) {
        if (!mkdir($dataDir, 0755, true)) {
            echo json_encode(['ok' => false, 'error' => 'Cannot create directory: ' . $dataDir]);
            exit;
        }
    }

    // Atomic write via temp file
    $tmp = REPO_FILE . '.tmp';
    if (file_put_contents($tmp, $body, LOCK_EX) === false) {
        echo json_encode(['ok' => false, 'error' => 'Cannot write ' . $tmp . ' — check write permissions on ' . $dataDir]);
        exit;
    }
    if (file_exists(REPO_FILE)) {
        copy(REPO_FILE, REPO_FILE . '.bak');
    }
    if (!rename($tmp, REPO_FILE)) {
        echo json_encode(['ok' => false, 'error' => 'rename() failed replacing repository.txt']);
        exit;
    }

    echo json_encode(['ok' => true, 'bytes' => strlen($body)]);
    exit;
}

// ──────────────────────────────────────────────
// POST ?action=upload  ->  save an uploaded file
// Form fields: file (binary), dest (relative path)
// ──────────────────────────────────────────────
if ($method === 'POST' && $action === 'upload') {

    $uploadError = isset($_FILES['file']['error']) ? $_FILES['file']['error'] : -1;
    if (!isset($_FILES['file']) || $uploadError !== UPLOAD_ERR_OK) {
        $msgs = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL    => 'File only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file received',
            UPLOAD_ERR_NO_TMP_DIR => 'No temp directory',
            UPLOAD_ERR_CANT_WRITE => 'Cannot write to disk',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by PHP extension',
        ];
        $msg = isset($msgs[$uploadError]) ? $msgs[$uploadError] : ('Upload error code ' . $uploadError);
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    }

    $dest = isset($_POST['dest']) ? trim($_POST['dest']) : '';
    if ($dest === '') {
        echo json_encode(['ok' => false, 'error' => 'No dest field provided']);
        exit;
    }

    $dest = safePath($dest);
    if ($dest === '') {
        echo json_encode(['ok' => false, 'error' => 'Invalid dest path']);
        exit;
    }

    // Whitelist extensions
    $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'webm', 'zip'];
    $ext = strtolower(pathinfo($dest, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        echo json_encode(['ok' => false, 'error' => 'File type .' . $ext . ' is not allowed']);
        exit;
    }

    // Append unique 6-char suffix: report.pdf -> report_aB3kX9.pdf
    $info      = pathinfo($dest);
    $dirPart   = ($info['dirname'] !== '.') ? $info['dirname'] . '/' : '';
    $base      = $info['filename'];
    $dotExt    = isset($info['extension']) ? '.' . $info['extension'] : '';
    $chars     = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charsLen  = strlen($chars);
    $suffix    = '';
    for ($i = 0; $i < 6; $i++) {
        $suffix .= $chars[mt_rand(0, $charsLen - 1)];
    }
    $dest = $dirPart . $base . '_' . $suffix . $dotExt;

    $fullDest = UPLOAD_ROOT . $dest;
    $dir      = dirname($fullDest);

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            echo json_encode(['ok' => false, 'error' => 'Cannot create directory: ' . $dir]);
            exit;
        }
    }

    if (!is_writable($dir)) {
        echo json_encode(['ok' => false, 'error' => 'Directory not writable: ' . $dir]);
        exit;
    }

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $fullDest)) {
        echo json_encode(['ok' => false, 'error' => 'move_uploaded_file failed to: ' . $fullDest]);
        exit;
    }

    echo json_encode(['ok' => true, 'path' => $dest, 'size' => $_FILES['file']['size']]);
    exit;
}

// ──────────────────────────────────────────────
// POST ?action=delete  ->  delete files from home/
// JSON body: { "paths": ["home/docs/file.pdf", ...] }
// ──────────────────────────────────────────────
if ($method === 'POST' && $action === 'delete') {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (!isset($data['paths']) || !is_array($data['paths'])) {
        echo json_encode(['ok' => false, 'error' => 'No paths array in request body']);
        exit;
    }

    $deleted = [];
    $failed  = [];
    $skipped = [];
    $realHome = realpath(HOME_DIR);

    foreach ($data['paths'] as $rawPath) {
        $rawPath = trim($rawPath);

        // Skip empty or http URLs (YouTube thumbs etc)
        if ($rawPath === '' || preg_match('#^https?://#i', $rawPath)) {
            $skipped[] = $rawPath;
            continue;
        }

        $safe     = safePath($rawPath);
        $fullPath = UPLOAD_ROOT . $safe;
        $realFile = realpath($fullPath);

        if ($realFile === false) {
            // Already gone
            $skipped[] = $rawPath;
            continue;
        }

        // Must be inside home/ — prevent escaping the allowed tree
        if ($realHome === false || strpos($realFile, $realHome) !== 0) {
            $failed[] = $rawPath . ' (outside allowed directory)';
            continue;
        }

        if (unlink($fullPath)) {
            $deleted[] = $rawPath;
        } else {
            $failed[] = $rawPath . ' (unlink failed)';
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
echo json_encode(['ok' => false, 'error' => 'Unknown action "' . htmlspecialchars($action) . '" or wrong HTTP method']);
