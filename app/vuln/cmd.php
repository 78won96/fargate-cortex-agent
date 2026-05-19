<?php
// INTENTIONALLY VULNERABLE - OS command injection.
// Cortex CaaS should flag /bin/sh, /bin/bash, nc, wget child processes.
header('Content-Type: text/plain');
$cmd = $_GET['c'] ?? 'whoami';
echo "Running: {$cmd}\n---\n";
system($cmd);
