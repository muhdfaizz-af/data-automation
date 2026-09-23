<?php
/**
 * ONE-TIME SCRIPT - untuk create admin user pertama dalam table admin_users.
 *
 * CARA GUNA:
 * 1. Tukar $username dan $plainPassword bawah ni.
 * 2. Run script ni SEKALI je (php create_admin.php DI TERMINAL, bukan browser -
 *    supaya password plaintext tak terdedah dalam URL/access log).
 * 3. Lepas admin user berjaya masuk database, PADAM/rename file ni terus
 *    dari server (jangan biar duduk kat public folder - risiko security).
 */

require_once __DIR__ . '/config/db.php';

$username      = 'Denish';           // <-- tukar
$plainPassword = '123456';    // <-- tukar, guna password kuat

try {
    $dsn  = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $pdo  = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $hashedPassword = password_hash($plainPassword, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare('INSERT INTO admin_users (username, password) VALUES (?, ?)');
    $stmt->execute([$username, $hashedPassword]);

    echo "Admin user '{$username}' berjaya dicipta.\n";
    echo "SILA PADAM FILE INI SEKARANG (create_admin.php) dari server.\n";

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        echo "Error: username '{$username}' dah wujud dalam database.\n";
    } else {
        echo "Database error: " . $e->getMessage() . "\n";
    }
}