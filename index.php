<?php
session_start();

// File untuk menyimpan password
$password_file = 'network_password.json';

// Function to get password from file
function getPassword($file)
{
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        return $data['password'] ?? 'bypsggroup';
    }
    return 'bypsggroup'; // default password
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
$DB_NAME = 'your_database_name';
$DB_USER = 'your_username';
$DB_PASS = 'your_password';

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
                devices_id,
                device_name,
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
    if (empty($datetime)) return 'Never';

    // Set timezone to GMT+7 (WIB)
    date_default_timezone_set('Asia/Jakarta');

    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;

    if ($diff < 60) return $diff . ' detik yang lalu';
    if ($diff < 3600) return floor($diff / 60) . ' menit yang lalu';
    if ($diff < 86400) return floor($diff / 3600) . ' jam yang lalu';
    if ($diff < 604800) return floor($diff / 86400) . ' hari yang lalu';

    // Format: 31 Okt 2025 18:03 WIB
    return date('d M Y H:i', $timestamp) . ' WIB';
}

function timeAgoShort($datetime)
{
    if (empty($datetime)) return '-';

    date_default_timezone_set('Asia/Jakarta');
    $timestamp = strtotime($datetime);
    if ($timestamp === false) return '-';

    $diff = time() - $timestamp;
    if ($diff < 0) $diff = 0;

    if ($diff < 60) return $diff . 's';
    if ($diff < 3600) return floor($diff / 60) . 'm';
    if ($diff < 86400) return floor($diff / 3600) . 'h';
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
                        $deviceName = $device['devices_id'] ?? 'Unknown Device';
                        $status = $device['status'] ?? '';
                        $lastSent = $device['last_sent'] ?? '';
                        $online = isOnline($status);
                        ?>
                        <div class="device-card">
                            <div class="device-header">
                                <div class="device-name"><?php echo htmlspecialchars($deviceName); ?></div>
                                <span class="status-dot <?php echo $online ? 'online' : 'offline'; ?>"></span>
                            </div>

                            <div class="device-info">
                                <div class="info-row">
                                    <svg class="info-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
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
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                </button>

                <?php if ($isAuthenticated): ?>
                    <button class="action-btn change-password" onclick="openChangePasswordModal()" title="Ganti Password">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                        </svg>
                    </button>

                    <button class="action-btn logout" onclick="logout()" title="Logout">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                        </svg>
                    </button>
                <?php endif; ?>
            </div>
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
            document.getElementById('passwordInput').addEventListener('keypress', function(e) {
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

        <?php if ($isAuthenticated): ?>
            let autoReloadTimer = null;
            let isModalOpen = false;

            function startAutoReload() {
                // Cancel existing timer
                if (autoReloadTimer) {
                    clearTimeout(autoReloadTimer);
                }

                // Only set timer if modal is not open
                if (!isModalOpen) {
                    autoReloadTimer = setTimeout(function() {
                        location.reload();
                    }, 30000);
                }
            }

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
    </script>
</body>

</html>