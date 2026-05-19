<?php
// INTENTIONALLY VULNERABLE - SSRF.
// Useful target: http://169.254.170.2/v2/credentials/...  (ECS task creds)
header('Content-Type: text/plain');
$url = $_GET['url'] ?? 'http://example.com';
$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_TIMEOUT        => 5,
]);
echo curl_exec($ch);
curl_close($ch);
