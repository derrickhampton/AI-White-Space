<?php
// api/hidden-chars.php
header('Content-Type: application/json; charset=utf-8');

// --- 1) Database credentials (edit these!) ---
define('DB_HOST', '10.30.72.172');
define('DB_NAME', 'hiddenchar');
define('DB_USER', 'hiddenchar_user');
define('DB_PASS', 'Rubb3rDuck$$');

// 2) Read and parse JSON input
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE || empty($data['text'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON or missing "text"']);
    exit;
}
$text = $data['text'];

try {
    // 3) Connect with PDO
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 4) Insert into DB
    $stmt = $pdo->prepare("INSERT INTO hidden_chars_logs (text) VALUES (:text)");
    $stmt->execute([':text' => $text]);
    $insertId = $pdo->lastInsertId();

    // 5) Return success JSON
    echo json_encode([
        'success'   => true,
        'insert_id' => $insertId,
        'message'   => 'Text logged successfully'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'Database error: ' . $e->getMessage()
    ]);
    exit;
}