<?php
/**
 * CAZORLA PLANNING 2026 - API Backend v3
 *
 * MEJORAS v3:
 *  - Configuración externalizada en config.php (no en git)
 *  - Tabla de backups automática (rotación de las últimas N versiones)
 *  - Acciones nuevas: backups (listar), restore (restaurar)
 *  - Validación de tamaño y formato de los datos entrantes
 *  - CORS configurable por origen
 *  - Token API opcional (X-Api-Token) para escritura
 *
 * Compatibilidad: si no existe config.php se usan los valores embebidos
 * para no romper el despliegue actual. SE RECOMIENDA crear config.php
 * y rotar la contraseña de MySQL cuanto antes.
 */

// ──────────────────────────────────────────────────────────────────────
//  CONFIG: cargar config.php externo si existe; si no, usar fallback
// ──────────────────────────────────────────────────────────────────────
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    require $configFile;
}

// Fallback (DEPRECATED — crea config.php y rota la contraseña en Hostinger)
if (!isset($DB_HOST))         $DB_HOST = 'localhost';
if (!isset($DB_NAME))         $DB_NAME = 'u919343704_cazorla_planni';
if (!isset($DB_USER))         $DB_USER = 'u919343704_info';
if (!isset($DB_PASS))         $DB_PASS = 'Gallito9431%';
if (!isset($API_TOKEN))       $API_TOKEN = '';                       // '' = auth desactivada
if (!isset($ALLOWED_ORIGINS)) $ALLOWED_ORIGINS = ['*'];               // restringe a tu dominio en producción
if (!isset($MAX_DATA_BYTES))  $MAX_DATA_BYTES = 5 * 1024 * 1024;      // 5 MB
if (!isset($KEEP_BACKUPS))    $KEEP_BACKUPS = 50;

// ──────────────────────────────────────────────────────────────────────
//  CORS
// ──────────────────────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array('*', $ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: *');
} elseif ($origin && in_array($origin, $ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ──────────────────────────────────────────────────────────────────────
//  AUTH (solo escritura): si $API_TOKEN está vacío, queda deshabilitado
// ──────────────────────────────────────────────────────────────────────
function requireAuth(string $token): void {
    if ($token === '') return;
    $sent = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (!hash_equals($token, $sent)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// ──────────────────────────────────────────────────────────────────────
//  CONEXIÓN MYSQL
// ──────────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    try {
        $pdo = new PDO("mysql:host=$DB_HOST;charset=utf8mb4", $DB_USER, $DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$DB_NAME` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$DB_NAME`");
    } catch (PDOException $e2) {
        http_response_code(500);
        echo json_encode(['error' => 'DB connection failed', 'detail' => $e2->getMessage()]); exit;
    }
}

// ──────────────────────────────────────────────────────────────────────
//  AUTO-MIGRACIÓN
// ──────────────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `planning_data` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `data` LONGTEXT NOT NULL,
    `version` INT DEFAULT 1,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

try { $pdo->exec("ALTER TABLE `planning_data` ADD COLUMN `version` INT DEFAULT 1"); } catch (Exception $e) {}

$pdo->exec("CREATE TABLE IF NOT EXISTS `planning_backups` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `data` LONGTEXT NOT NULL,
    `version` INT DEFAULT 1,
    `note` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$count = (int)$pdo->query("SELECT COUNT(*) FROM `planning_data`")->fetchColumn();
if ($count === 0) {
    $pdo->exec("INSERT INTO `planning_data` (`data`, `version`) VALUES ('[]', 1)");
}

// ──────────────────────────────────────────────────────────────────────
//  HELPERS
// ──────────────────────────────────────────────────────────────────────
function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]); exit;
}

function rotateBackups(PDO $pdo, int $keep): void {
    if ($keep <= 0) return;
    $pdo->exec("DELETE FROM `planning_backups` WHERE `id` NOT IN (
        SELECT `id` FROM (
            SELECT `id` FROM `planning_backups` ORDER BY `id` DESC LIMIT $keep
        ) AS t
    )");
}

// ──────────────────────────────────────────────────────────────────────
//  ROUTING
// ──────────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

if ($action === 'load') {
    $stmt = $pdo->query("SELECT `data`, `version`, `updated_at` FROM `planning_data` ORDER BY `id` DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'ok'      => true,
        'data'    => json_decode($row['data']),
        'version' => (int)$row['version'],
        'updated' => $row['updated_at'],
    ]);
    exit;
}

if ($action === 'save') {
    requireAuth($API_TOKEN);

    $raw = file_get_contents('php://input');
    if (strlen($raw) > $MAX_DATA_BYTES) fail(413, 'Payload too large');

    $input = json_decode($raw, true);
    if (!is_array($input) || !array_key_exists('data', $input)) fail(400, 'Invalid request');
    if (!is_array($input['data']))                              fail(400, 'data must be an array');

    $encoded = json_encode($input['data'], JSON_UNESCAPED_UNICODE);
    if ($encoded === false)                                     fail(400, 'JSON encode failed');
    if (strlen($encoded) > $MAX_DATA_BYTES)                     fail(413, 'Data too large');

    $version = isset($input['version']) ? (int)$input['version'] : 1;
    $note    = isset($input['note']) ? mb_substr((string)$input['note'], 0, 200) : null;

    $pdo->beginTransaction();
    try {
        // Backup de la versión vigente antes de sobrescribir
        $noteSql = $note === null ? "NULL" : $pdo->quote($note);
        $pdo->exec("INSERT INTO `planning_backups` (`data`, `version`, `note`)
                    SELECT `data`, `version`, $noteSql
                    FROM `planning_data` ORDER BY `id` DESC LIMIT 1");

        $stmt = $pdo->prepare("UPDATE `planning_data`
                               SET `data` = ?, `version` = ?
                               WHERE `id` = (SELECT `id` FROM (SELECT `id` FROM `planning_data` ORDER BY `id` DESC LIMIT 1) AS t)");
        $stmt->execute([$encoded, $version]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        fail(500, 'Save failed: ' . $e->getMessage());
    }

    rotateBackups($pdo, $KEEP_BACKUPS);
    echo json_encode(['ok' => true, 'version' => $version, 'updated' => date('Y-m-d H:i:s')]);
    exit;
}

if ($action === 'check') {
    $since = $_GET['since'] ?? '';
    $stmt = $pdo->query("SELECT `updated_at`, `version` FROM `planning_data` ORDER BY `id` DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'ok'      => true,
        'updated' => $row['updated_at'],
        'version' => (int)$row['version'],
        'changed' => (!$since || $row['updated_at'] > $since),
    ]);
    exit;
}

if ($action === 'backups') {
    $stmt = $pdo->query("SELECT `id`, `version`, `note`, `created_at`,
                                CHAR_LENGTH(`data`) AS `size`,
                                JSON_LENGTH(`data`) AS `groups`
                         FROM `planning_backups`
                         ORDER BY `id` DESC LIMIT 100");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'backups' => $rows]);
    exit;
}

if ($action === 'restore') {
    requireAuth($API_TOKEN);

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) fail(400, 'Missing backup id');

    $stmt = $pdo->prepare("SELECT `data`, `version` FROM `planning_backups` WHERE `id` = ?");
    $stmt->execute([$id]);
    $bk = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bk) fail(404, 'Backup not found');

    $pdo->beginTransaction();
    try {
        // Backup de la versión vigente antes de restaurar (para poder volver atrás)
        $pdo->exec("INSERT INTO `planning_backups` (`data`, `version`, `note`)
                    SELECT `data`, `version`, 'pre-restore' FROM `planning_data` ORDER BY `id` DESC LIMIT 1");

        $stmt = $pdo->prepare("UPDATE `planning_data`
                               SET `data` = ?, `version` = ?
                               WHERE `id` = (SELECT `id` FROM (SELECT `id` FROM `planning_data` ORDER BY `id` DESC LIMIT 1) AS t)");
        $stmt->execute([$bk['data'], (int)$bk['version']]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        fail(500, 'Restore failed: ' . $e->getMessage());
    }
    rotateBackups($pdo, $KEEP_BACKUPS);
    echo json_encode(['ok' => true, 'restored' => $id, 'updated' => date('Y-m-d H:i:s')]);
    exit;
}

echo json_encode([
    'ok'      => true,
    'message' => 'Cazorla Planning API v3',
    'actions' => ['load', 'save', 'check', 'backups', 'restore'],
]);
