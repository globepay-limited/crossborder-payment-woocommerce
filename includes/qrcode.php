<?php
require_once 'phpqrcode/phpqrcode.php';

$url = urldecode($_GET["data"]);


header("Content-Type: image/png");
QRcode::png($url);

