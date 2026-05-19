<?php
// INTENTIONALLY VULNERABLE - path traversal / LFI.
header('Content-Type: text/plain');
$file = $_GET['file'] ?? 'index.php';
$path = __DIR__ . '/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    echo "Not found\n";
    exit;
}
readfile($path);
