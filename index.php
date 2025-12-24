<?php
session_start();

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// File untuk menyimpan password
$password_file = 'network_password.json';

// Function to get password from file
function getPassword($file)
{
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        return $data['password'] ?? 'secret';
    }
    return 'secret'; // default password
}

// Function to set password to file
function setPassword($file, $newPassword)
{
    $data = ['password' => $newPassword];
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

// Handle AJAX password check
if (isset($_POST['action']) && $_POST['action'] === 'check_password') {
    $correct_password = getPassword($password_file);
    $input_password = $_POST['password'] ?? '';

    $valid = ($input_password === $correct_password);

    if ($valid) {
        $_SESSION['authenticated'] = true;
    }

    header('Content-Type: application/json');
    echo json_encode(['valid' => $valid]);
    exit;
}

// Handle change password
if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current_password = getPassword($password_file);
    $old_password = $_POST['old_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';

    if ($old_password === $current_password && !empty($new_password)) {
        if (setPassword($password_file, $new_password)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Password berhasil diubah!']);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan password!']);
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Password lama salah atau password baru kosong!']);
    }
    exit;
}

// Check if session is valid
$isAuthenticated = isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;

// Handle logout
if (isset($_GET['logout'])) {
    $_SESSION = array();
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
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

    static $pdo = null;
    if ($pdo === null) {
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

        } catch (PDOException $e) {
            return null;
        }
    }
    return $pdo;
}

// Fetch devices from database
$devices = [];
$db = getDB();
if ($db) {
    try {
        $stmt = $db->query("
            SELECT 
                hardware_id,
                devices_id,
                status,
                last_sent,
                firmware_version,
                last_ssid,
                last_rssi,
                last_ip
            FROM device 
            ORDER BY 
                CASE WHEN status = 'online' THEN 0 ELSE 1 END,
                last_sent DESC
        ");
        $devices = $stmt->fetchAll();
    } catch (PDOException $e) {
        $devices = [];
    }
}

// Function to determine if device is online based on status field
function isOnline($status)
{
    return strtolower($status) === 'online';
}

// Function to format time ago
function timeAgo($datetime)
{
    if (empty($datetime))
        return 'Never';

    // Set timezone to GMT+7 (WIB)
    date_default_timezone_set('Asia/Jakarta');

    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;

    if ($diff < 60)
        return $diff . ' detik yang lalu';
    if ($diff < 3600)
        return floor($diff / 60) . ' menit yang lalu';
    if ($diff < 86400)
        return floor($diff / 3600) . ' jam yang lalu';
    if ($diff < 604800)
        return floor($diff / 86400) . ' hari yang lalu';

    // Format: 31 Okt 2025 18:03 WIB
    return date('d M Y H:i', $timestamp) . ' WIB';
}

function timeAgoShort($datetime)
{
    if (empty($datetime))
        return '-';

    date_default_timezone_set('Asia/Jakarta');
    $timestamp = strtotime($datetime);
    if ($timestamp === false)
        return '-';

    $diff = time() - $timestamp;
    if ($diff < 0)
        $diff = 0;

    if ($diff < 60)
        return $diff . 's';
    if ($diff < 3600)
        return floor($diff / 60) . 'm';
    if ($diff < 86400)
        return floor($diff / 3600) . 'h';
    return floor($diff / 86400) . 'd';
}

$totalDevices = count($devices);
$onlineDevices = 0;
$offlineDevices = 0;

foreach ($devices as $device) {
    $status = $device['status'] ?? '';
    if (isOnline($status)) {
        $onlineDevices++;
    } else {
        $offlineDevices++;
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon2.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon2.png">
    <link rel="icon" type="image/png" sizes="192x192" href="favicon2.png">
    <link rel="apple-touch-icon" href="favicon2.png">
    <link rel="shortcut icon" href="favicon2.png">
    <title>Senyaman Network Monitor</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            padding-bottom: 90px;
        }

        .auth-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.95);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
        }

        .auth-box {
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 400px;
            width: 90%;
            text-align: center;
        }

        .auth-box h2 {
            color: #333;
            margin-bottom: 10px;
            font-size: 1.8rem;
        }

        .auth-box p {
            color: #666;
            margin-bottom: 30px;
        }

        .auth-box input {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            margin-bottom: 20px;
            transition: border-color 0.3s;
        }

        .auth-box input:focus {
            outline: none;
            border-color: #667eea;
        }

        .auth-box button {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .auth-box button:hover {
            transform: scale(1.02);
        }

        .auth-box button:active {
            transform: scale(0.98);
        }

        .error-message {
            color: #ef4444;
            font-size: 0.9rem;
            margin-top: 10px;
            display: none;
        }

        .content {
            display: none;
        }

        .content.unlocked {
            display: block;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        header {
            text-align: center;
            color: white;
            margin-bottom: 24px;
            animation: fadeInDown 0.6s ease;
        }

        header h1 {
            font-size: 1.9rem;
            margin-bottom: 6px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        header h1 .title-icon {
            width: 36px;
            height: 36px;
            object-fit: contain;
        }

        header p {
            display: none;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 8px;
            margin-bottom: 16px;
            animation: fadeIn 0.8s ease;
        }

        .stat-card {
            background: white;
            padding: 14px;
            border-radius: 10px;
            box-shadow: 0 3px 5px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .stat-card h3 {
            color: #666;
            font-size: 0.8rem;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .stat-card .value {
            font-size: 1.5rem;
            font-weight: 600;
            color: #333;
        }

        .stat-card.online .value {
            color: #10b981;
        }

        .stat-card.offline .value {
            color: #ef4444;
        }

        .devices-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 10px;
            animation: fadeIn 1s ease;
        }

        .device-card {
            background: white;
            border-radius: 12px;
            padding: 14px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            animation: slideUp 0.6s ease;
        }

        .device-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
        }

        .device-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }

        .device-name {
            font-size: 1.05rem;
            font-weight: 600;
            color: #333;
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
            position: relative;
        }

        .status-dot::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.6;
            animation: statusWave 1.5s infinite;
        }

        .status-dot.online {
            background: #10b981;
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.6);
        }

        .status-dot.online::after {
            background: rgba(16, 185, 129, 0.4);
        }

        .status-dot.offline {
            background: #ef4444;
            box-shadow: 0 0 8px rgba(239, 68, 68, 0.6);
        }

        .status-dot.offline::after {
            background: rgba(239, 68, 68, 0.4);
        }

        .device-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .info-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-icon {
            width: 20px;
            height: 20px;
            color: #667eea;
        }

        .info-label {
            font-size: 0.9rem;
            color: #666;
            font-weight: 500;
        }

        .info-value {
            font-size: 0.95rem;
            color: #333;
            margin-left: 0;
            font-weight: 500;
        }

        .floating-actions {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            z-index: 9000;
        }

        .action-btn {
            background: white;
            border: none;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.3s ease;
        }

        .action-btn:hover {
            transform: scale(1.08);
        }

        .action-btn svg {
            width: 24px;
            height: 24px;
        }

        .action-btn.refresh svg {
            color: #667eea;
        }

        .action-btn.change-password svg {
            color: #667eea;
        }

        .action-btn.logout svg {
            color: #ef4444;
        }

        .action-btn.refresh:hover {
            transform: scale(1.08) rotate(90deg);
        }

        .no-devices {
            text-align: center;
            color: white;
            font-size: 1.2rem;
            padding: 60px 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            margin-bottom: 100px;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 16px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .modal-content h3 {
            margin-bottom: 20px;
            color: #333;
        }

        .modal-content input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            margin-bottom: 15px;
        }

        .modal-content input:focus {
            outline: none;
            border-color: #667eea;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .modal-buttons button {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .modal-buttons button:hover {
            transform: scale(1.02);
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-secondary {
            background: #e0e0e0;
            color: #333;
        }

        .success-message,
        .error-message-modal {
            padding: 10px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 0.9rem;
            display: none;
        }

        .success-message {
            background: #d1fae5;
            color: #065f46;
        }

        .error-message-modal {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Device Detail Modal Styles */
        .device-card.clickable {
            cursor: pointer;
        }

        .device-detail-modal .modal-content {
            max-width: 450px;
        }

        .device-detail-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .device-detail-header h3 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .device-detail-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .device-detail-status.online {
            background: #d1fae5;
            color: #065f46;
        }

        .device-detail-status.offline {
            background: #fee2e2;
            color: #991b1b;
        }

        .device-detail-grid {
            display: grid;
            gap: 15px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: #f9fafb;
            border-radius: 10px;
        }

        .detail-item-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .detail-item-icon svg {
            width: 20px;
            height: 20px;
            color: white;
        }

        .detail-item-content {
            flex: 1;
        }

        .detail-item-label {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 2px;
        }

        .detail-item-value {
            font-size: 1rem;
            font-weight: 600;
            color: #333;
        }

        .rssi-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .rssi-bars {
            display: flex;
            gap: 2px;
            align-items: flex-end;
        }

        .rssi-bar {
            width: 4px;
            background: #e0e0e0;
            border-radius: 2px;
        }

        .rssi-bar.active {
            background: #10b981;
        }

        .close-modal-btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 20px;
            transition: transform 0.2s;
        }

        .close-modal-btn:hover {
            transform: scale(1.02);
        }

        /* Edit Form Styles */
        .edit-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .edit-section-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: #667eea;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .edit-form-grid {
            display: grid;
            gap: 12px;
        }

        .edit-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .edit-field label {
            font-size: 0.8rem;
            color: #666;
            font-weight: 500;
        }

        .edit-field input {
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: border-color 0.2s;
        }

        .edit-field input:focus {
            outline: none;
            border-color: #667eea;
        }

        .edit-field input::placeholder {
            color: #aaa;
        }

        .edit-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .edit-btn {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .edit-btn:hover {
            transform: scale(1.02);
        }

        .edit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .edit-btn.save-name {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .edit-btn.save-interval {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }

        .edit-feedback {
            padding: 10px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-top: 10px;
            display: none;
        }

        .edit-feedback.success {
            background: #d1fae5;
            color: #065f46;
        }

        .edit-feedback.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .edit-feedback.show {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            25% {
                transform: translateX(-10px);
            }

            75% {
                transform: translateX(10px);
            }
        }

        @keyframes statusWave {
            0% {
                transform: translate(-50%, -50%) scale(1);
                opacity: 0.6;
            }

            100% {
                transform: translate(-50%, -50%) scale(2.2);
                opacity: 0;
            }
        }

        @media (max-width: 768px) {
            header h1 {
                font-size: 1rem;
                margin-bottom: 4px;
            }

            .devices-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 6px;
            }

            .device-card {
                padding: 10px;
            }

            .device-name {
                font-size: 0.9rem;
            }

            .info-label,
            .info-value {
                font-size: 0.75rem;
            }

            .info-icon {
                width: 16px;
                height: 16px;
            }

            .stat-card {
                padding: 8px;
            }

            .stat-card h3 {
                font-size: 0.7rem;
            }

            .stat-card .value {
                font-size: 1.2rem;
            }

            .stats {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 4px;
            }

            .auth-box {
                padding: 30px 20px;
            }
        }
    </style>
</head>

<body>
    <!-- Authentication Overlay -->
    <div class="auth-overlay" id="authOverlay" style="<?php echo $isAuthenticated ? 'display: none;' : ''; ?>">
        <div class="auth-box">
            <h2>🔒 Senyaman Network</h2>
            <p>Masukkan password untuk akses</p>
            <input type="password" id="passwordInput" placeholder="Password" autocomplete="off">
            <button onclick="checkPassword()">Masuk</button>
            <div class="error-message" id="errorMessage">❌ Password salah!</div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="content <?php echo $isAuthenticated ? 'unlocked' : ''; ?>" id="mainContent">
        <div class="container">
            <header>
                <h1><img src="favicon2.png" alt="Senyaman icon" class="title-icon">Senyaman Network Monitor</h1>
                <p>Real-time Device Monitoring Dashboard</p>
            </header>

            <div class="stats">
                <div class="stat-card">
                    <h3>Total</h3>
                    <div class="value"><?php echo $totalDevices; ?></div>
                </div>
                <div class="stat-card online">
                    <h3>Online</h3>
                    <div class="value"><?php echo $onlineDevices; ?></div>
                </div>
                <div class="stat-card offline">
                    <h3>Offline</h3>
                    <div class="value"><?php echo $offlineDevices; ?></div>
                </div>
            </div>

            <?php if (empty($devices)): ?>
                <div class="no-devices">
                    <p>📭 Tidak ada device yang terdaftar</p>
                </div>
            <?php else: ?>
                <div class="devices-grid">
                    <?php #for($i = 0; $i < 9; $i++){ //dummy loop 
                        ?>
                    <?php foreach ($devices as $device): ?>
                        <?php
                        // hardware_id = MAC-based permanent ID (for API calls)
                        $hardwareId = $device['hardware_id'] ?? ($device['devices_id'] ?? 'Unknown');
                        // devices_id = user-configurable display name
                        $deviceName = $device['devices_id'] ?? $hardwareId;
                        $status = $device['status'] ?? '';
                        $lastSent = $device['last_sent'] ?? '';
                        $firmwareVersion = $device['firmware_version'] ?? '-';
                        $lastSsid = $device['last_ssid'] ?? '-';
                        $lastRssi = $device['last_rssi'] ?? null;
                        $lastIp = $device['last_ip'] ?? '-';
                        $online = isOnline($status);
                        ?>
                        <div class="device-card clickable" onclick="openDeviceDetail(this)"
                            data-id="<?php echo htmlspecialchars($hardwareId); ?>"
                            data-name="<?php echo htmlspecialchars($deviceName); ?>"
                            data-status="<?php echo $online ? 'online' : 'offline'; ?>"
                            data-lastsent="<?php echo htmlspecialchars($lastSent); ?>"
                            data-lastsent-formatted="<?php echo timeAgo($lastSent); ?>"
                            data-firmware="<?php echo htmlspecialchars($firmwareVersion); ?>"
                            data-ssid="<?php echo htmlspecialchars($lastSsid); ?>"
                            data-rssi="<?php echo $lastRssi !== null ? intval($lastRssi) : ''; ?>"
                            data-ip="<?php echo htmlspecialchars($lastIp); ?>">
                            <div class="device-header">
                                <div class="device-name"><?php echo htmlspecialchars($deviceName); ?></div>
                                <span class="status-dot <?php echo $online ? 'online' : 'offline'; ?>"></span>
                            </div>

                            <div class="device-info">
                                <div class="info-row">
                                    <svg class="info-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                        </path>
                                    </svg>
                                    <span class="info-value"><?php echo timeAgoShort($lastSent); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php #} //end dummy loop 
                        ?>
                </div>
            <?php endif; ?>

            <div class="floating-actions">
                <button class="action-btn refresh" onclick="location.reload();" title="Refresh">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                        </path>
                    </svg>
                </button>

                <?php if ($isAuthenticated): ?>
                    <button class="action-btn change-password" onclick="openChangePasswordModal()" title="Ganti Password">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z">
                            </path>
                        </svg>
                    </button>

                    <button class="action-btn logout" onclick="logout()" title="Logout">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1">
                            </path>
                        </svg>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Device Detail Modal -->
    <div class="modal device-detail-modal" id="deviceDetailModal">
        <div class="modal-content">
            <div class="device-detail-header">
                <h3>📱 <span id="detailDeviceName">Device</span></h3>
                <span class="device-detail-status" id="detailDeviceStatus">Online</span>
            </div>

            <div class="device-detail-grid">
                <div class="detail-item">
                    <div class="detail-item-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0">
                            </path>
                        </svg>
                    </div>
                    <div class="detail-item-content">
                        <div class="detail-item-label">WiFi SSID</div>
                        <div class="detail-item-value" id="detailSsid">-</div>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-item-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z">
                            </path>
                        </svg>
                    </div>
                    <div class="detail-item-content">
                        <div class="detail-item-label">Signal Strength (RSSI)</div>
                        <div class="detail-item-value">
                            <div class="rssi-indicator">
                                <span id="detailRssi">-</span>
                                <div class="rssi-bars" id="rssiBars">
                                    <div class="rssi-bar" style="height: 6px;"></div>
                                    <div class="rssi-bar" style="height: 10px;"></div>
                                    <div class="rssi-bar" style="height: 14px;"></div>
                                    <div class="rssi-bar" style="height: 18px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-item-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z">
                            </path>
                        </svg>
                    </div>
                    <div class="detail-item-content">
                        <div class="detail-item-label">Firmware Version</div>
                        <div class="detail-item-value" id="detailFirmware">-</div>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-item-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9">
                            </path>
                        </svg>
                    </div>
                    <div class="detail-item-content">
                        <div class="detail-item-label">IP Address</div>
                        <div class="detail-item-value" id="detailIp">-</div>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-item-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div class="detail-item-content">
                        <div class="detail-item-label">Last Seen</div>
                        <div class="detail-item-value" id="detailLastSeen">-</div>
                    </div>
                </div>
            </div>

            <!-- Hidden Device ID for edit actions - outside auth check so JS can always access it -->
            <input type="hidden" id="editDeviceId" value="">

            <!-- Edit Section - only visible when authenticated -->
            <?php if ($isAuthenticated): ?>
                <div class="edit-section">
                    <div class="edit-section-title">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                            </path>
                        </svg>
                        Kirim Action ke Device
                    </div>

                    <div class="edit-form-grid">
                        <div class="edit-field">
                            <label for="editDeviceName">Nama Device Baru</label>
                            <div style="display: flex; gap: 8px;">
                                <input type="text" id="editDeviceName" placeholder="Contoh: Router-Lantai-1"
                                    style="flex: 1;">
                                <button class="edit-btn save-name" onclick="sendChangeNameAction()"
                                    id="btnSaveName">Kirim</button>
                            </div>
                        </div>

                        <div class="edit-field">
                            <label for="editInterval">Interval Heartbeat (detik)</label>
                            <div style="display: flex; gap: 8px;">
                                <input type="number" id="editInterval" placeholder="Contoh: 60" min="10" max="3600"
                                    style="flex: 1;">
                                <button class="edit-btn save-interval" onclick="sendChangeIntervalAction()"
                                    id="btnSaveInterval">Kirim</button>
                            </div>
                        </div>
                    </div>

                    <div class="edit-feedback" id="editFeedback"></div>
                </div>
            <?php endif; ?>

            <button class="close-modal-btn" onclick="closeDeviceDetail()">Tutup</button>
        </div>
    </div>

    <?php if ($isAuthenticated): ?>
        <!-- Change Password Modal -->
        <div class="modal" id="changePasswordModal">
            <div class="modal-content">
                <h3>🔐 Ganti Password</h3>
                <input type="password" id="oldPassword" placeholder="Password Lama" autocomplete="off">
                <input type="password" id="newPassword" placeholder="Password Baru" autocomplete="off">
                <input type="password" id="confirmPassword" placeholder="Konfirmasi Password Baru" autocomplete="off">
                <div class="success-message" id="successMessage"></div>
                <div class="error-message-modal" id="errorMessageModal"></div>
                <div class="modal-buttons">
                    <button class="btn-secondary" onclick="closeChangePasswordModal()">Batal</button>
                    <button class="btn-primary" onclick="changePassword()">Simpan</button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        <?php if (!$isAuthenticated): ?>
            // Focus on password input
            document.getElementById('passwordInput').focus();

            // Allow Enter key to submit
            document.getElementById('passwordInput').addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    checkPassword();
                }
            });
        <?php endif; ?>

        function checkPassword() {
            const input = document.getElementById('passwordInput').value;
            const errorMsg = document.getElementById('errorMessage');
            const button = document.querySelector('.auth-box button');

            // Disable button and show loading
            button.disabled = true;
            button.textContent = 'Memeriksa...';
            errorMsg.style.display = 'none';

            // Send AJAX request to verify password
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=check_password&password=' + encodeURIComponent(input)
            })
                .then(response => response.json())
                .then(data => {
                    if (data.valid) {
                        // Password correct, reload page
                        window.location.reload();
                    } else {
                        // Password incorrect
                        errorMsg.style.display = 'block';
                        document.getElementById('passwordInput').value = '';
                        document.getElementById('passwordInput').focus();

                        // Shake animation
                        document.querySelector('.auth-box').style.animation = 'shake 0.5s';
                        setTimeout(() => {
                            document.querySelector('.auth-box').style.animation = '';
                        }, 500);

                        // Re-enable button
                        button.disabled = false;
                        button.textContent = 'Masuk';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    errorMsg.textContent = '❌ Terjadi kesalahan!';
                    errorMsg.style.display = 'block';
                    button.disabled = false;
                    button.textContent = 'Masuk';
                });
        }

        function logout() {
            if (confirm('Yakin ingin logout?')) {
                window.location.href = '?logout=1';
            }
        }

        // Global auto-reload variables and functions (available for all users)
        let autoReloadTimer = null;
        let isModalOpen = false;

        function startAutoReload() {
            // Cancel existing timer
            if (autoReloadTimer) {
                clearTimeout(autoReloadTimer);
            }

            // Only set timer if modal is not open and user is authenticated
            if (!isModalOpen) {
                autoReloadTimer = setTimeout(function () {
                    location.reload();
                }, 30000);
            }
        }

        function stopAutoReload() {
            if (autoReloadTimer) {
                clearTimeout(autoReloadTimer);
                autoReloadTimer = null;
            }
        }

        <?php if ($isAuthenticated): ?>

            function openChangePasswordModal() {
                isModalOpen = true;
                clearTimeout(autoReloadTimer); // Stop auto-reload
                document.getElementById('changePasswordModal').classList.add('active');
                document.getElementById('oldPassword').focus();
            }

            function closeChangePasswordModal() {
                isModalOpen = false;
                document.getElementById('changePasswordModal').classList.remove('active');
                document.getElementById('oldPassword').value = '';
                document.getElementById('newPassword').value = '';
                document.getElementById('confirmPassword').value = '';
                document.getElementById('successMessage').style.display = 'none';
                document.getElementById('errorMessageModal').style.display = 'none';

                // Restart auto-reload after closing modal
                startAutoReload();
            }

            function changePassword() {
                const oldPassword = document.getElementById('oldPassword').value;
                const newPassword = document.getElementById('newPassword').value;
                const confirmPassword = document.getElementById('confirmPassword').value;
                const successMsg = document.getElementById('successMessage');
                const errorMsg = document.getElementById('errorMessageModal');

                // Hide messages
                successMsg.style.display = 'none';
                errorMsg.style.display = 'none';

                // Validation
                if (!oldPassword || !newPassword || !confirmPassword) {
                    errorMsg.textContent = '❌ Semua field harus diisi!';
                    errorMsg.style.display = 'block';
                    return;
                }

                if (newPassword !== confirmPassword) {
                    errorMsg.textContent = '❌ Password baru tidak cocok!';
                    errorMsg.style.display = 'block';
                    return;
                }

                if (newPassword.length < 6) {
                    errorMsg.textContent = '❌ Password minimal 6 karakter!';
                    errorMsg.style.display = 'block';
                    return;
                }

                // Send AJAX request
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=change_password&old_password=' + encodeURIComponent(oldPassword) +
                        '&new_password=' + encodeURIComponent(newPassword)
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            successMsg.textContent = '✅ ' + data.message;
                            successMsg.style.display = 'block';

                            // Close modal after 2 seconds
                            setTimeout(() => {
                                closeChangePasswordModal();
                            }, 2000);
                        } else {
                            errorMsg.textContent = '❌ ' + data.message;
                            errorMsg.style.display = 'block';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        errorMsg.textContent = '❌ Terjadi kesalahan!';
                        errorMsg.style.display = 'block';
                    });
            }

            // Auto refresh every 30 seconds (only when modal is not open)
            startAutoReload();
        <?php endif; ?>

        // Device Detail Modal Functions
        function openDeviceDetail(element) {
            const modal = document.getElementById('deviceDetailModal');

            // Get data from element attributes
            const deviceId = element.dataset.id || '-';
            const deviceName = element.dataset.name || deviceId;
            const status = element.dataset.status || 'offline';
            const lastSentFormatted = element.dataset.lastsentFormatted || '-';
            const firmware = element.dataset.firmware || '-';
            const ssid = element.dataset.ssid || '-';
            const rssi = element.dataset.rssi || '';
            const ip = element.dataset.ip || '-';

            // Populate modal content
            document.getElementById('detailDeviceName').textContent = deviceId;

            const statusEl = document.getElementById('detailDeviceStatus');
            statusEl.textContent = status === 'online' ? 'Online' : 'Offline';
            statusEl.className = 'device-detail-status ' + status;

            document.getElementById('detailSsid').textContent = ssid || '-';
            document.getElementById('detailFirmware').textContent = firmware || '-';
            document.getElementById('detailIp').textContent = ip || '-';
            document.getElementById('detailLastSeen').textContent = lastSentFormatted || '-';

            // RSSI display with bars
            const rssiEl = document.getElementById('detailRssi');
            const rssiBarsEl = document.getElementById('rssiBars');

            if (rssi && rssi !== '' && rssi !== 'null') {
                const rssiValue = parseInt(rssi);
                rssiEl.textContent = rssiValue + ' dBm';

                // Calculate signal strength (RSSI typically -30 to -90)
                // -30 to -50: Excellent (4 bars)
                // -50 to -60: Good (3 bars)
                // -60 to -70: Fair (2 bars)
                // -70 to -90: Poor (1 bar)
                let activeBars = 1;
                if (rssiValue >= -50) activeBars = 4;
                else if (rssiValue >= -60) activeBars = 3;
                else if (rssiValue >= -70) activeBars = 2;

                const bars = rssiBarsEl.querySelectorAll('.rssi-bar');
                bars.forEach((bar, index) => {
                    if (index < activeBars) {
                        bar.classList.add('active');
                    } else {
                        bar.classList.remove('active');
                    }
                });
                rssiBarsEl.style.display = 'flex';
            } else {
                rssiEl.textContent = '-';
                rssiBarsEl.style.display = 'none';
            }

            // Show modal
            modal.classList.add('active');
            isModalOpen = true;
            stopAutoReload();

            // Set device ID for edit form
            const editDeviceIdField = document.getElementById('editDeviceId');
            if (editDeviceIdField) {
                editDeviceIdField.value = deviceId;
            }

            // Clear edit form fields
            const editNameField = document.getElementById('editDeviceName');
            const editIntervalField = document.getElementById('editInterval');
            const editFeedback = document.getElementById('editFeedback');
            if (editNameField) editNameField.value = '';
            if (editIntervalField) editIntervalField.value = '';
            if (editFeedback) {
                editFeedback.className = 'edit-feedback';
                editFeedback.textContent = '';
            }
        }

        function closeDeviceDetail() {
            const modal = document.getElementById('deviceDetailModal');
            modal.classList.remove('active');
            isModalOpen = false;
            startAutoReload();
        }

        // Close device detail modal when clicking outside
        document.getElementById('deviceDetailModal').addEventListener('click', function (e) {
            if (e.target === this) {
                closeDeviceDetail();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                const deviceModal = document.getElementById('deviceDetailModal');
                if (deviceModal.classList.contains('active')) {
                    closeDeviceDetail();
                }
            }
        });

        // Remote API URL
        const REMOTE_API_URL = 'remote.php';

        // Show feedback message
        function showEditFeedback(message, isSuccess) {
            const feedback = document.getElementById('editFeedback');
            if (feedback) {
                feedback.textContent = message;
                feedback.className = 'edit-feedback show ' + (isSuccess ? 'success' : 'error');

                // Auto hide after 5 seconds
                setTimeout(() => {
                    feedback.className = 'edit-feedback';
                }, 5000);
            }
        }

        // Send action to remote.php (uses hardware_id as unique identifier)
        async function sendDeviceAction(hardwareId, action, value) {
            try {
                const response = await fetch(REMOTE_API_URL + '?action=create_action', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        hardware_id: hardwareId,
                        action: action,
                        value: value
                    })
                });

                const data = await response.json();

                if (data.success) {
                    return { success: true, message: data.message, actionid: data.actionid };
                } else {
                    return { success: false, message: data.error || 'Unknown error' };
                }
            } catch (error) {
                console.error('Error sending action:', error);
                return { success: false, message: 'Network error: ' + error.message };
            }
        }

        // Send change name action
        async function sendChangeNameAction() {
            const deviceId = document.getElementById('editDeviceId').value;
            const newName = document.getElementById('editDeviceName').value.trim();
            const btn = document.getElementById('btnSaveName');

            if (!deviceId) {
                showEditFeedback('❌ Device ID tidak ditemukan', false);
                return;
            }

            if (!newName) {
                showEditFeedback('❌ Nama device tidak boleh kosong', false);
                return;
            }

            // Disable button
            btn.disabled = true;
            btn.textContent = 'Mengirim...';

            const result = await sendDeviceAction(deviceId, 'changename', newName);

            if (result.success) {
                showEditFeedback('✅ Action "changename" berhasil dikirim! Device akan menerima saat online.', true);
                document.getElementById('editDeviceName').value = '';
            } else {
                showEditFeedback('❌ Gagal: ' + result.message, false);
            }

            // Re-enable button
            btn.disabled = false;
            btn.textContent = 'Kirim';
        }

        // Send change interval action
        async function sendChangeIntervalAction() {
            const deviceId = document.getElementById('editDeviceId').value;
            const newInterval = document.getElementById('editInterval').value.trim();
            const btn = document.getElementById('btnSaveInterval');

            if (!deviceId) {
                showEditFeedback('❌ Device ID tidak ditemukan', false);
                return;
            }

            if (!newInterval) {
                showEditFeedback('❌ Interval tidak boleh kosong', false);
                return;
            }

            const intervalNum = parseInt(newInterval);
            if (isNaN(intervalNum) || intervalNum < 10 || intervalNum > 3600) {
                showEditFeedback('❌ Interval harus antara 10-3600 detik', false);
                return;
            }

            // Disable button
            btn.disabled = true;
            btn.textContent = 'Mengirim...';

            const result = await sendDeviceAction(deviceId, 'changeinterval', newInterval);

            if (result.success) {
                showEditFeedback('✅ Action "changeinterval" berhasil dikirim! Device akan menerima saat online.', true);
                document.getElementById('editInterval').value = '';
            } else {
                showEditFeedback('❌ Gagal: ' + result.message, false);
            }

            // Re-enable button
            btn.disabled = false;
            btn.textContent = 'Kirim';
        }
    </script>
</body>

</html>