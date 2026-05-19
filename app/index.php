<?php
$endpoints = [
  'GET  /vuln/cmd.php?c=id'                     => 'OS command injection',
  'GET  /vuln/lfi.php?file=../../../etc/passwd' => 'Local file inclusion',
  'GET  /vuln/eval.php?code=phpinfo();'         => 'PHP eval RCE',
  'POST /vuln/upload.php  (-F f=@shell.php)'    => 'Unrestricted upload',
  'GET  /vuln/ssrf.php?url=http://example.com'  => 'Server-side request forgery',
  'GET  /vuln/info.php'                         => 'phpinfo information disclosure',
];
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Cortex CaaS testbed</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;max-width:760px;margin:40px auto;padding:0 20px;color:#222}
code{background:#f4f4f4;padding:2px 6px;border-radius:3px;font-size:13px}
.warn{background:#fff8e1;border:1px solid #f0c36d;padding:12px;border-radius:6px}
table{border-collapse:collapse;width:100%;margin-top:16px}
td{padding:8px;border-bottom:1px solid #eee;vertical-align:top}
</style></head><body>
<h1>Cortex CaaS test workload</h1>
<div class="warn"><strong>Intentionally vulnerable - for runtime protection testing only.</strong></div>
<table>
<?php foreach ($endpoints as $sig => $desc): ?>
  <tr><td><code><?= htmlspecialchars($sig) ?></code></td>
      <td><?= htmlspecialchars($desc) ?></td></tr>
<?php endforeach; ?>
</table>
</body></html>
