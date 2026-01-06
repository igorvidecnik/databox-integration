<?php
//samo za test sql lite cc
$db = new PDO('sqlite:data/app.db');

$stmt = $db->query("SELECT provider, access_token, refresh_token, expires_at FROM oauth_tokens");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "No rows in oauth_tokens\n";
    exit;
}

foreach ($rows as $r) {
    echo $r['provider'] . 
         "  access=" . substr($r['access_token'], 0, 8) . "..." .
         "  refresh=" . substr($r['refresh_token'], 0, 8) . "..." .
         "  expires_at=" . $r['expires_at'] . PHP_EOL;
}
