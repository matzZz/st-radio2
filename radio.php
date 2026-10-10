<?php
// Kompatibilität für alte Links (z.B. Shelly): radio.php?name=N startet Sender N.
// Ohne Parameter geht es zur neuen Oberfläche.
if (!isset($_GET['name'])) {
    header('Location: index.html');
    exit;
}
$_GET = ['action' => 'play', 'id' => trim((string) $_GET['name']), 'redirect' => '1'];
require __DIR__ . '/api.php';
