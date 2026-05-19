<?php
// INTENTIONALLY VULNERABLE - unrestricted upload to web-served path.
// Resulting file is executable as PHP via nginx FastCGI route.
header('Content-Type: text/plain');
$dir = '/var/www/html/uploads';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['f'])) {
    $dest = $dir . '/' . basename($_FILES['f']['name']);
    if (move_uploaded_file($_FILES['f']['tmp_name'], $dest)) {
        echo "uploaded: /uploads/" . basename($dest) . "\n";
    } else {
        echo "failed\n";
    }
    exit;
}
echo "POST 'f' multipart file to upload.\n";
echo "Example: curl -F 'f=@shell.php' http://target/vuln/upload.php\n";
