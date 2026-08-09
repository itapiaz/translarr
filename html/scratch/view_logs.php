<?php
require_once 'config.php';
$stmt = $pdo->query("SELECT * FROM system_logs ORDER BY id DESC LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
