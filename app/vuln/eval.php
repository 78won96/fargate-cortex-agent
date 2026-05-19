<?php
// INTENTIONALLY VULNERABLE - arbitrary PHP eval.
header('Content-Type: text/plain');
$code = $_GET['code'] ?? 'echo PHP_VERSION;';
echo "Evaluating: {$code}\n---\n";
eval($code);
