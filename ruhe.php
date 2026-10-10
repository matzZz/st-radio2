<?php
// Kompatibilität für alte Links (z.B. Shelly): ruhe.php stoppt die Wiedergabe.
$_GET = ['action' => 'stop', 'redirect' => '1'];
require __DIR__ . '/api.php';
