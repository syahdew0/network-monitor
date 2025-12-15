-- =============================================
-- PhiNet Remote Management - Database Schema
-- =============================================
-- Jalankan script ini setelah table yang sudah ada
-- (device, devicelogs, heartbeats)
-- =============================================

-- --------------------------------------------------------
-- Table structure for table `device_actions`
-- Menyimpan pending actions untuk device
-- --------------------------------------------------------

CREATE TABLE `device_actions` (
  `actionid` int(11) NOT NULL AUTO_INCREMENT,
  `devices_id` varchar(50) NOT NULL,
  `action` enum('update','changename','changeendpoint','changeinterval') NOT NULL,
  `value` text DEFAULT NULL,
  `additional` JSON DEFAULT NULL,
  `status` enum('pending','sent','completed','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`actionid`),
  KEY `devices_id` (`devices_id`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`),
  KEY `devices_id_status` (`devices_id`, `status`),
  CONSTRAINT `fk_actions_device` FOREIGN KEY (`devices_id`) REFERENCES `device` (`devices_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------
-- Table structure for table `action_logs`
-- Menyimpan log hasil eksekusi action dari device
-- --------------------------------------------------------

CREATE TABLE `action_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `actionid` int(11) NOT NULL,
  `devices_id` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `version` varchar(20) DEFAULT NULL,
  `logged_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `actionid` (`actionid`),
  KEY `devices_id` (`devices_id`),
  KEY `logged_at` (`logged_at`),
  CONSTRAINT `fk_logs_action` FOREIGN KEY (`actionid`) REFERENCES `device_actions` (`actionid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_logs_device` FOREIGN KEY (`devices_id`) REFERENCES `device` (`devices_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------
-- Alter existing `device` table to add version tracking
-- --------------------------------------------------------

ALTER TABLE `device` 
ADD COLUMN `device_name` varchar(100) DEFAULT NULL AFTER `devices_id`,
ADD COLUMN `firmware_version` varchar(20) DEFAULT NULL AFTER `device_name`,
ADD COLUMN `last_ssid` varchar(100) DEFAULT NULL AFTER `firmware_version`,
ADD COLUMN `last_rssi` int(11) DEFAULT NULL AFTER `last_ssid`,
ADD COLUMN `last_ip` varchar(50) DEFAULT NULL AFTER `last_rssi`,
ADD COLUMN `created_at` datetime DEFAULT current_timestamp() AFTER `last_sent`;

-- --------------------------------------------------------
-- Sample data: Insert test action
-- --------------------------------------------------------

-- Contoh: Update firmware
-- INSERT INTO device_actions (devices_id, action, value, additional) 
-- VALUES ('phinet-a4cf12345678', 'update', 'https://ota-network.phisoft.co.id/firmware/v1.1.bin', '{"version": "1.1"}');

-- Contoh: Change device name
-- INSERT INTO device_actions (devices_id, action, value) 
-- VALUES ('phinet-a4cf12345678', 'changename', 'Router-Lantai-2');

-- Contoh: Change endpoint
-- INSERT INTO device_actions (devices_id, action, value) 
-- VALUES ('phinet-a4cf12345678', 'changeendpoint', 'https://api.newserver.com/heartbeat');

-- Contoh: Change interval
-- INSERT INTO device_actions (devices_id, action, value) 
-- VALUES ('phinet-a4cf12345678', 'changeinterval', '120');

-- --------------------------------------------------------
-- Views for easier querying (optional)
-- --------------------------------------------------------

-- View: Pending actions with device info
CREATE OR REPLACE VIEW `v_pending_actions` AS
SELECT 
    da.actionid,
    da.devices_id,
    d.device_name,
    da.action,
    da.value,
    da.status,
    da.created_at,
    d.status as device_status,
    d.last_sent
FROM device_actions da
JOIN device d ON da.devices_id = d.devices_id
WHERE da.status = 'pending'
ORDER BY da.created_at ASC;

-- View: Action logs with action info
CREATE OR REPLACE VIEW `v_action_logs` AS
SELECT 
    al.id,
    al.actionid,
    al.devices_id,
    d.device_name,
    da.action,
    al.message,
    al.version,
    al.logged_at,
    da.status as action_status
FROM action_logs al
JOIN device_actions da ON al.actionid = da.actionid
JOIN device d ON al.devices_id = d.devices_id
ORDER BY al.logged_at DESC;

-- View: Device summary
CREATE OR REPLACE VIEW `v_device_summary` AS
SELECT 
    d.devices_id,
    d.device_name,
    d.firmware_version,
    d.status,
    d.last_sent,
    d.last_ssid,
    d.last_rssi,
    d.last_ip,
    (SELECT COUNT(*) FROM device_actions WHERE devices_id = d.devices_id AND status = 'pending') as pending_actions,
    (SELECT COUNT(*) FROM device_actions WHERE devices_id = d.devices_id AND status = 'completed') as completed_actions,
    (SELECT COUNT(*) FROM device_actions WHERE devices_id = d.devices_id AND status = 'failed') as failed_actions,
    (SELECT COUNT(*) FROM heartbeats WHERE devices_id = d.devices_id) as total_heartbeats
FROM device d
ORDER BY d.last_sent DESC;
