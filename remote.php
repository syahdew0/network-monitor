<?php
/**
 * PhiNet Remote Management API
 * 
 * Endpoints:
 * - ?action=get_actions  : Get pending actions for device
 * - ?action=log          : Log action result from device
 * - ?action=heartbeat    : Receive heartbeat (optional, jika ingin via REMOTE_URL)
 * 
 * @version 1.0
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ===== DATABASE CONFIG =====
$DB_HOST = 'localhost';
$DB_NAME = 'network_monitor';
$DB_USER = 'user';
$DB_PASS = 'kaSjHns7kL76Ah';

// ===== DATABASE CONNECTION =====
function getDB()
{
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;

    try {
        $pdo = new PDO(
            "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
            $DB_USER,
            $DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );

        // Set MySQL timezone to match PHP timezone
        $pdo->exec("SET time_zone = '+07:00'");

        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }
}

// ===== HELPER FUNCTIONS =====
function getJsonInput()
{
    $input = file_get_contents('php://input');
    if (empty($input)) {
        return [];
    }
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}

function jsonResponse($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function validateDeviceId($id)
{
    // Validate phinet-xxxxxxxxxxxx format
    return preg_match('/^phinet-[a-f0-9]{12}$/', $id);
}

// ===== ACTION HANDLERS =====

/**
 * Get pending actions for device
 * POST body: {"id": "phinet-xxxx", "v": "1.0"}
 */
function handleGetActions()
{
    $input = getJsonInput();

    if (empty($input['id'])) {
        jsonResponse(['error' => 'Missing device id'], 400);
    }

    $deviceId = $input['id'];
    $version = $input['v'] ?? 'unknown';

    if (!validateDeviceId($deviceId)) {
        jsonResponse(['error' => 'Invalid device id format'], 400);
    }

    $db = getDB();

    // Update device last seen
    $stmt = $db->prepare("
        INSERT INTO device (hardware_id, status, last_sent) 
        VALUES (:id, 'online', NOW())
        ON DUPLICATE KEY UPDATE 
            status = 'online', 
            last_sent = NOW()
    ");
    $stmt->execute(['id' => $deviceId]);

    // Get pending action (oldest first, not executed yet)
    $stmt = $db->prepare("
        SELECT actionid, action, value, additional 
        FROM device_actions 
        WHERE hardware_id = :id 
          AND status = 'pending'
        ORDER BY created_at ASC 
        LIMIT 1
    ");
    $stmt->execute(['id' => $deviceId]);
    $action = $stmt->fetch();

    if (!$action) {
        // No pending actions
        jsonResponse([]);
    }

    // Mark action as sent
    $updateStmt = $db->prepare("
        UPDATE device_actions 
        SET status = 'sent', sent_at = NOW() 
        WHERE actionid = :actionid
    ");
    $updateStmt->execute(['actionid' => $action['actionid']]);

    // Parse additional JSON if exists
    $additional = [];
    if (!empty($action['additional'])) {
        $additional = json_decode($action['additional'], true) ?: [];
    }

    jsonResponse([
        'actionid' => $action['actionid'],
        'action' => $action['action'],
        'value' => $action['value'],
        'additional' => $additional
    ]);
}

/**
 * Log action result from device
 * POST body: {"id": "phinet-xxxx", "actionid": "123", "msg": "..."}
 */
function handleLog()
{
    $input = getJsonInput();

    if (empty($input['id']) || empty($input['actionid']) || empty($input['msg'])) {
        jsonResponse(['error' => 'Missing required fields'], 400);
    }

    $deviceId = $input['id'];
    $actionId = $input['actionid'];
    $message = $input['msg'];
    $version = $input['v'] ?? 'unknown';

    if (!validateDeviceId($deviceId)) {
        jsonResponse(['error' => 'Invalid device id format'], 400);
    }

    $db = getDB();

    // Insert log
    $stmt = $db->prepare("
        INSERT INTO action_logs (actionid, hardware_id, message, version, logged_at)
        VALUES (:actionid, :hardware_id, :message, :version, NOW())
    ");
    $stmt->execute([
        'actionid' => $actionId,
        'hardware_id' => $deviceId,
        'message' => $message,
        'version' => $version
    ]);

    // Update action status based on message
    $status = 'completed';
    if (stripos($message, 'gagal') !== false || stripos($message, 'error') !== false) {
        $status = 'failed';
    }

    $updateStmt = $db->prepare("
        UPDATE device_actions 
        SET status = :status, completed_at = NOW() 
        WHERE actionid = :actionid
    ");
    $updateStmt->execute([
        'status' => $status,
        'actionid' => $actionId
    ]);

    jsonResponse(['success' => true, 'status' => $status]);
}

/**
 * Complete action (mark action as completed/failed)
 * POST body: {"hardware_id": "phinet-xxxx", "actionid": 123, "status": "completed", "msg": "...", "v": "1.0"}
 */
function handleCompleteAction()
{
    $input = getJsonInput();

    if (empty($input['hardware_id']) || empty($input['actionid'])) {
        jsonResponse(['error' => 'Missing required fields'], 400);
    }

    $hardwareId = $input['hardware_id'];
    $actionId = (int) $input['actionid'];
    $status = $input['status'] ?? 'completed';
    $msg = $input['msg'] ?? null;
    $version = $input['v'] ?? 'unknown';

    if (!validateDeviceId($hardwareId)) {
        jsonResponse(['error' => 'Invalid hardware_id format'], 400);
    }

    $db = getDB();

    // (opsional) insert log kalau msg ada
    if (!empty($msg)) {
        $stmt = $db->prepare("
            INSERT INTO action_logs (actionid, hardware_id, message, version, logged_at)
            VALUES (:actionid, :hardware_id, :message, :version, NOW())
        ");
        $stmt->execute([
            'actionid' => $actionId,
            'hardware_id' => $hardwareId,
            'message' => $msg,
            'version' => $version
        ]);
    }

    // update status action (PAKAI hardware_id + actionid biar aman)
    $stmt = $db->prepare("
        UPDATE device_actions
        SET status = :status,
            completed_at = IF(:status IN ('completed','failed'), NOW(), completed_at)
        WHERE actionid = :actionid
          AND hardware_id = :hardware_id
    ");
    $stmt->execute([
        'status' => $status,
        'actionid' => $actionId,
        'hardware_id' => $hardwareId
    ]);

    jsonResponse(['success' => true, 'status' => $status]);
}

/**
 * Receive heartbeat (alternative endpoint via REMOTE_URL)
 * POST body: {"id": "phinet-xxxx", "device_id": "name", "ssid": "...", "rssi": -42, "ip": "192.168.x.x", "v": "1.0"}
 */
function handleHeartbeat()
{
    $input = getJsonInput();

    if (empty($input['id'])) {
        jsonResponse(['error' => 'Missing device id'], 400);
    }

    $hwId = $input['id'];
    $deviceName = $input['device_id'] ?? $hwId;
    $ssid = $input['ssid'] ?? '';
    $rssi = $input['rssi'] ?? 0;
    $localIp = $input['ip'] ?? '';  // IP lokal device (192.168.x.x)
    $version = $input['v'] ?? 'unknown';

    if (!validateDeviceId($hwId)) {
        jsonResponse(['error' => 'Invalid device id format'], 400);
    }

    $db = getDB();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Upsert device - now uses hardware_id as unique key, devices_id as display name
    $stmt = $db->prepare("
        INSERT INTO device (hardware_id, devices_id, firmware_version, last_ssid, last_rssi, last_ip, status, last_sent, created_at) 
        VALUES (:hardware_id, :devices_id, :version, :ssid, :rssi, :ip, 'online', NOW(), NOW())
        ON DUPLICATE KEY UPDATE 
            devices_id = :devices_id2,
            firmware_version = :version2,
            last_ssid = :ssid2,
            last_rssi = :rssi2,
            last_ip = :ip2,
            status = 'online', 
            last_sent = NOW()
    ");
    $stmt->execute([
        'hardware_id' => $hwId,
        'devices_id' => $deviceName,
        'version' => $version,
        'ssid' => $ssid,
        'rssi' => $rssi,
        'ip' => $localIp,
        'devices_id2' => $deviceName,
        'version2' => $version,
        'ssid2' => $ssid,
        'rssi2' => $rssi,
        'ip2' => $localIp
    ]);

    // Insert heartbeat log
    $stmt = $db->prepare("
        INSERT INTO heartbeats (hardware_id, heartbeaton, ipaddress, useragent)
        VALUES (:hardware_id, NOW(), :ipaddress, :useragent)
    ");
    $stmt->execute([
        'hardware_id' => $hwId,
        'ipaddress' => $localIp,
        'useragent' => substr($userAgent, 0, 100)
    ]);

    jsonResponse([
        'success' => true,
        'received' => [
            'id' => $hwId,
            'device_name' => $deviceName,
            'ssid' => $ssid,
            'rssi' => $rssi,
            'version' => $version
        ]
    ]);
}

/**
 * Create new action for device (for admin/management)
 * POST body: {"hardware_id": "phinet-xxxx", "action": "update", "value": "https://...", "additional": {}}
 */
function handleCreateAction()
{
    $input = getJsonInput();

    // Support both hardware_id and devices_id as input param for backwards compatibility
    $hardwareId = $input['hardware_id'] ?? ($input['devices_id'] ?? '');

    if (empty($hardwareId) || empty($input['action'])) {
        jsonResponse(['error' => 'Missing required fields (hardware_id, action)'], 400);
    }

    $action = $input['action'];
    $value = $input['value'] ?? '';
    $additional = isset($input['additional']) ? json_encode($input['additional']) : '{}';

    // Validate action type
    $validActions = ['update', 'changename', 'changeendpoint', 'changeinterval'];
    if (!in_array($action, $validActions)) {
        jsonResponse(['error' => 'Invalid action type. Valid: ' . implode(', ', $validActions)], 400);
    }

    $db = getDB();

    // Check if device exists by hardware_id
    $stmt = $db->prepare("SELECT hardware_id FROM device WHERE hardware_id = :id");
    $stmt->execute(['id' => $hardwareId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Device not found'], 404);
    }

    // Create action
    $stmt = $db->prepare("
        INSERT INTO device_actions (hardware_id, action, value, additional, status, created_at)
        VALUES (:hardware_id, :action, :value, :additional, 'pending', NOW())
    ");
    $stmt->execute([
        'hardware_id' => $hardwareId,
        'action' => $action,
        'value' => $value,
        'additional' => $additional
    ]);

    $actionId = $db->lastInsertId();

    jsonResponse([
        'success' => true,
        'actionid' => $actionId,
        'message' => "Action '$action' created for device $hardwareId"
    ]);
}

/**
 * List all devices (for admin/management)
 */
function handleListDevices()
{
    $db = getDB();

    $stmt = $db->query("
        SELECT 
            d.hardware_id,
            d.devices_id,
            d.firmware_version,
            d.last_ssid,
            d.last_rssi,
            d.last_ip,
            d.status,
            d.last_sent,
            (SELECT COUNT(*) FROM device_actions WHERE hardware_id = d.hardware_id AND status = 'pending') as pending_actions
        FROM device d
        ORDER BY d.last_sent DESC
    ");

    $devices = $stmt->fetchAll();

    jsonResponse([
        'success' => true,
        'count' => count($devices),
        'devices' => $devices
    ]);
}

/**
 * Get device actions history
 */
function handleGetDeviceActions()
{
    $input = getJsonInput();
    // Support both hardware_id and devices_id param for backwards compatibility
    $hardwareId = $input['hardware_id'] ?? ($input['devices_id'] ?? ($_GET['hardware_id'] ?? ($_GET['devices_id'] ?? '')));

    if (empty($hardwareId)) {
        jsonResponse(['error' => 'Missing hardware_id'], 400);
    }

    $db = getDB();

    $stmt = $db->prepare("
        SELECT 
            a.actionid,
            a.action,
            a.value,
            a.status,
            a.created_at,
            a.sent_at,
            a.completed_at
        FROM device_actions a
        WHERE a.hardware_id = :hardware_id
        ORDER BY a.created_at DESC
        LIMIT 50
    ");
    $stmt->execute(['hardware_id' => $hardwareId]);

    $actions = $stmt->fetchAll();

    jsonResponse([
        'success' => true,
        'hardware_id' => $hardwareId,
        'actions' => $actions
    ]);
}

// ===== ROUTING =====
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_actions':
        handleGetActions();
        break;

    case 'log':
        handleLog();
        break;

    case 'complete_action':
        handleCompleteAction();
        break;

    case 'heartbeat':
        handleHeartbeat();
        break;

    case 'create_action':
        handleCreateAction();
        break;

    case 'list_devices':
        handleListDevices();
        break;

    case 'get_device_actions':
        handleGetDeviceActions();
        break;

    default:
        jsonResponse([
            'name' => 'PhiNet Remote Management API',
            'version' => '1.0',
            'endpoints' => [
                'POST ?action=get_actions' => 'Get pending actions for device',
                'POST ?action=log' => 'Log action result from device',
                'POST ?action=complete_action' => 'Complete action and update status',
                'POST ?action=heartbeat' => 'Receive heartbeat from device',
                'POST ?action=create_action' => 'Create new action for device (admin)',
                'GET  ?action=list_devices' => 'List all devices (admin)',
                'GET  ?action=get_device_actions&devices_id=xxx' => 'Get device action history (admin)'
            ]
        ]);
}
