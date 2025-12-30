#include <ArduinoJson.h> // Library Manager: "ArduinoJson by Benoit Blanchon"
#include <HTTPClient.h>
#include <Preferences.h>
#include <Update.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <WiFiManager.h> // Library Manager: "WiFiManager by tzapu"
#include <nvs_flash.h>
#include <time.h>

// ===== VERSION & REMOTE =====
const char *VERSION = "1.2";
const char *REMOTE_URL = "https://monitorv2.phisoft.co.id/";

// ===== PIN & CONFIG =====
#define BTN_CFG_PIN 9 // Tombol BOOT di ESP32-C3
#define LED_PIN 8     // LED indikator
const char *AP_NAME = "PhiNet-Setup";
const char *AP_PASSWORD = "Admin123";

const unsigned long HTTP_TIMEOUT_MS = 8000;
const unsigned long RECONNECT_BASE_MS = 1000;
const unsigned long RECONNECT_MAX_MS = 60000;
const char *TZ_STRING = "WIB-7";
const char *NTP1 = "id.pool.ntp.org";
const char *NTP2 = "pool.ntp.org";

// ===== STATE =====
volatile bool wifiConnected = false;
unsigned long lastSendMs = 0;
unsigned long nextReconnectAtMs = 0;
unsigned long currentBackoffMs = RECONNECT_BASE_MS;

struct AppConfig {
  String deviceId;
  uint32_t intervalSec;
} CFG;

Preferences prefs;

// ===== LED INDICATORS =====
void ledBlinkBoot() {
  for (int i = 0; i < 3; i++) {
    digitalWrite(LED_PIN, HIGH);
    delay(100);
    digitalWrite(LED_PIN, LOW);
    delay(100);
  }
}

void ledBlinkAPMode() {
  for (int i = 0; i < 10; i++) {
    digitalWrite(LED_PIN, HIGH);
    delay(50);
    digitalWrite(LED_PIN, LOW);
    delay(50);
  }
}

void ledBlinkSave() {
  for (int i = 0; i < 3; i++) {
    digitalWrite(LED_PIN, LOW);
    delay(200);
    digitalWrite(LED_PIN, HIGH);
    delay(200);
  }
}

void ledBlinkUpdate() {
  for (int i = 0; i < 5; i++) {
    digitalWrite(LED_PIN, HIGH);
    delay(150);
    digitalWrite(LED_PIN, LOW);
    delay(150);
  }
}

// ===== WIFI EVENT =====
void onWiFiEvent(WiFiEvent_t event) {
  switch (event) {
  case ARDUINO_EVENT_WIFI_STA_CONNECTED:
    Serial.println("[WiFi] Terhubung ke AP");
    break;
  case ARDUINO_EVENT_WIFI_STA_GOT_IP:
    wifiConnected = true;
    currentBackoffMs = RECONNECT_BASE_MS;
    Serial.print("[WiFi] Dapat IP: ");
    Serial.println(WiFi.localIP());
    break;
  case ARDUINO_EVENT_WIFI_STA_DISCONNECTED:
    wifiConnected = false;
    Serial.println("[WiFi] Putus dari AP");
    nextReconnectAtMs = millis();
    currentBackoffMs = min(RECONNECT_MAX_MS, currentBackoffMs * 2);
    break;
  default:
    break;
  }
}

// ===== UTIL =====
String defaultDeviceId() {
  String mac = WiFi.macAddress(); // "AA:BB:CC:DD:EE:FF"
  mac.replace(":", "");
  mac.toLowerCase();
  return "phinet-" + mac;
}

void loadConfig() {
  prefs.begin("cfg", true);
  CFG.deviceId = prefs.getString("deviceId", "");
  CFG.intervalSec = prefs.getUInt("interval", 60);
  prefs.end();

  if (CFG.deviceId.length() == 0)
    CFG.deviceId = defaultDeviceId();
  if (CFG.intervalSec == 0)
    CFG.intervalSec = 60;
}

void saveConfig() {
  prefs.begin("cfg", false);
  prefs.putString("deviceId", CFG.deviceId);
  prefs.putUInt("interval", CFG.intervalSec);
  prefs.end();
}

void syncTime() {
  configTzTime(TZ_STRING, NTP1, NTP2);
  for (int i = 0; i < 20; i++) {
    if (time(nullptr) > 1700000000)
      break;
    delay(200);
  }
}

// ===== REMOTE LOG =====
void sendRemoteLog(const String &actionId, const String &msg) {
  if (WiFi.status() != WL_CONNECTED)
    return;

  String hwId = defaultDeviceId();
  String url = String(REMOTE_URL) + "remote.php?action=log";

  JsonDocument doc;
  doc["id"] = hwId;
  doc["actionid"] = (int)actionId.toInt();
  doc["msg"] = msg;
  doc["v"] = VERSION;

  String payload;
  serializeJson(doc, payload);

  Serial.print("[LOG] Kirim ke: ");
  Serial.println(url);
  Serial.print("[LOG] Payload: ");
  Serial.println(payload);

  WiFiClientSecure client;
  client.setTimeout(HTTP_TIMEOUT_MS);
  client.setInsecure();

  HTTPClient http;
  if (!http.begin(client, url)) {
    Serial.println("[LOG] Gagal init HTTP");
    return;
  }
  http.setConnectTimeout(HTTP_TIMEOUT_MS);
  http.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
  http.addHeader("Content-Type", "application/json");

  int code = http.POST(payload);
  Serial.printf("[LOG] HTTP %d\n", code);
  if (code > 0) {
    Serial.print("[LOG] Resp: ");
    Serial.println(http.getString());
  }
  http.end();
}

// ===== OTA UPDATE =====
bool performOTAUpdate(const String &actionId, const String &updateUrl) {
  Serial.println("[OTA] Memulai update...");
  Serial.print("[OTA] URL: ");
  Serial.println(updateUrl);

  ledBlinkUpdate();

  WiFiClientSecure client;
  client.setTimeout(30000);
  client.setInsecure();

  HTTPClient http;
  if (!http.begin(client, updateUrl)) {
    sendRemoteLog(actionId, "Gagal membuka koneksi ke URL update");
    return false;
  }

  http.setConnectTimeout(30000);
  http.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
  int code = http.GET();

  if (code != HTTP_CODE_OK) {
    String errMsg = "HTTP error: " + String(code);
    sendRemoteLog(actionId, errMsg);
    http.end();
    return false;
  }

  int contentLength = http.getSize();
  if (contentLength <= 0) {
    sendRemoteLog(actionId, "Content-Length tidak valid");
    http.end();
    return false;
  }

  String contentType = http.header("Content-Type");
  if (contentType.indexOf("application/octet-stream") < 0 &&
      contentType.indexOf("binary") < 0) {
    Serial.printf("[OTA] Warning: Content-Type: %s\n", contentType.c_str());
  }

  // Validasi berhasil
  sendRemoteLog(actionId, "Validasi berhasil, Update dimulai...");

  if (!Update.begin(contentLength)) {
    String errMsg = "Update.begin gagal: " + String(Update.errorString());
    sendRemoteLog(actionId, errMsg);
    http.end();
    return false;
  }

  WiFiClient *stream = http.getStreamPtr();
  size_t written = Update.writeStream(*stream);

  if (written != contentLength) {
    String errMsg = "Written " + String(written) + " dari " +
                    String(contentLength) + " bytes";
    sendRemoteLog(actionId, errMsg);
    Update.abort();
    http.end();
    return false;
  }

  if (!Update.end()) {
    String errMsg = "Update.end gagal: " + String(Update.errorString());
    sendRemoteLog(actionId, errMsg);
    http.end();
    return false;
  }

  http.end();

  sendRemoteLog(actionId, "Update berhasil! Restart dalam 3 detik...");
  Serial.println("[OTA] Update berhasil! Restart...");

  delay(3000);
  ESP.restart();

  return true;
}

void handleAction(const String &actionId, const String &action,
                  const String &value) {
  Serial.printf("[ACT] Handle ActionID=%s Action=%s Value=%s\n",
                actionId.c_str(), action.c_str(), value.c_str());

  if (action == "update") {
    sendRemoteLog(actionId, "Menerima perintah update");
    performOTAUpdate(actionId, value);

  } else if (action == "changename") {
    CFG.deviceId = value;
    saveConfig();
    sendRemoteLog(actionId, "Nama device diubah menjadi: " + value);

  } else if (action == "changeinterval") {
    uint32_t newInterval = value.toInt();
    if (newInterval > 0) {
      CFG.intervalSec = newInterval;
      saveConfig();
      sendRemoteLog(actionId,
                    "Interval diubah menjadi: " + String(newInterval));
    } else {
      sendRemoteLog(actionId, "Interval tidak valid: " + value);
    }

  } else {
    sendRemoteLog(actionId, "Action tidak dikenal: " + action);
  }
}

// ===== COMPLETE ACTION =====
void sendCompleteAction(const String &actionId, const String &status,
                        const String &msg) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[COMPLETE] WiFi not connected, skipping");
    return;
  }

  String hwId = defaultDeviceId();
  String url = String(REMOTE_URL) + "remote.php?action=complete_action";

  JsonDocument doc;
  doc["hardware_id"] = hwId;
  doc["actionid"] = actionId.toInt();
  doc["status"] = status;
  doc["msg"] = msg;
  doc["v"] = VERSION;

  String payload;
  serializeJson(doc, payload);

  Serial.println("========== COMPLETE ACTION ==========");
  Serial.print("[COMPLETE] URL: ");
  Serial.println(url);
  Serial.print("[COMPLETE] Payload: ");
  Serial.println(payload);
  Serial.print("[COMPLETE] HardwareID: ");
  Serial.println(hwId);
  Serial.print("[COMPLETE] ActionID: ");
  Serial.println(actionId);
  Serial.print("[COMPLETE] Status: ");
  Serial.println(status);

  WiFiClientSecure client;
  client.setTimeout(HTTP_TIMEOUT_MS);
  client.setInsecure();

  HTTPClient http;
  if (!http.begin(client, url)) {
    Serial.println("[COMPLETE] ERROR: Gagal init HTTP");
    return;
  }
  http.setConnectTimeout(HTTP_TIMEOUT_MS);
  http.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
  http.addHeader("Content-Type", "application/json");

  int code = http.POST(payload);
  Serial.printf("[COMPLETE] HTTP Response Code: %d\n", code);

  if (code > 0) {
    String response = http.getString();
    Serial.print("[COMPLETE] Response Body: ");
    Serial.println(response);

    if (code >= 200 && code < 300) {
      Serial.println("[COMPLETE] SUCCESS - Action marked as completed");
    } else {
      Serial.printf("[COMPLETE] WARNING - Unexpected HTTP code: %d\n", code);
    }
  } else {
    Serial.print("[COMPLETE] ERROR: ");
    Serial.println(http.errorToString(code));
  }

  http.end();
  Serial.println("=====================================");
}

// ===== CHECK ACTIONS =====
void checkRemoteActions() {
  if (WiFi.status() != WL_CONNECTED)
    return;

  String hwId = defaultDeviceId();

  // PAKAI endpoint yang kamu test di browser/DB
  String url = String(REMOTE_URL) +
               "remote.php?action=get_device_actions&hardware_id=" + hwId;

  Serial.print("[ACT] hwId: ");
  Serial.println(hwId);
  Serial.print("[ACT] GET: ");
  Serial.println(url);

  WiFiClientSecure client;
  client.setTimeout(HTTP_TIMEOUT_MS);
  client.setInsecure();

  HTTPClient http;
  if (!http.begin(client, url)) {
    Serial.println("[ACT] Gagal init HTTP");
    return;
  }

  http.setConnectTimeout(HTTP_TIMEOUT_MS);
  http.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);

  int code = http.GET();
  String respAny = http.getString();

  Serial.printf("[ACT] HTTP %d\n", code);
  Serial.print("[ACT] Body: ");
  Serial.println(respAny);

  http.end();

  if (code != HTTP_CODE_OK) {
    Serial.println("[ACT] Stop karena HTTP bukan 200");
    return;
  }

  JsonDocument doc;
  DeserializationError err = deserializeJson(doc, respAny);
  if (err) {
    Serial.print("[ACT] JSON parse error: ");
    Serial.println(err.c_str());
    return;
  }

  if (!doc.containsKey("actions") || !doc["actions"].is<JsonArray>()) {
    Serial.println("[ACT] Tidak ada actions[]");
    return;
  }

  JsonArray arr = doc["actions"].as<JsonArray>();
  if (arr.size() == 0) {
    Serial.println("[ACT] actions kosong");
    return;
  }

  // Ambil action pending pertama
  JsonObject picked;
  for (JsonObject a : arr) {
    String st = a["status"] | "";
    if (st == "pending") {
      picked = a;
      break;
    }
  }

  if (picked.isNull()) {
    Serial.println("[ACT] Tidak ada action pending");
    return;
  }

  String actionId = String((int)(picked["actionid"] | 0));
  String action = picked["action"] | "";
  String value = picked["value"] | "";

  Serial.printf("[ACT] Picked ActionID=%s Action=%s Value=%s\n",
                actionId.c_str(), action.c_str(), value.c_str());

  // Eksekusi + ACK
  if (action == "changename") {
    CFG.deviceId = value;
    saveConfig();

    String msg = "Nama device diubah menjadi: " + value;
    sendRemoteLog(actionId, msg);
    sendCompleteAction(actionId, "completed", msg);

  } else if (action == "changeinterval") {
    uint32_t newInterval = value.toInt();
    if (newInterval > 0) {
      CFG.intervalSec = newInterval;
      saveConfig();

      String msg = "Interval diubah menjadi: " + String(newInterval);
      sendRemoteLog(actionId, msg);
      sendCompleteAction(actionId, "completed", msg);
    } else {
      String msg = "Interval tidak valid: " + value;
      sendRemoteLog(actionId, msg);
      sendCompleteAction(actionId, "failed", msg);
    }

  } else if (action == "update") {
    // Untuk update: jangan ACK completed sebelum update sukses
    String msg = "Menerima perintah update";
    sendRemoteLog(actionId, msg);

    bool ok = performOTAUpdate(actionId, value); // akan restart kalau sukses
    if (!ok) {
      sendCompleteAction(actionId, "failed", "Update gagal: " + value);
    }
    // kalau sukses, device restart di performOTAUpdate()

  } else {
    String msg = "Action tidak dikenal: " + action;
    sendRemoteLog(actionId, msg);
    sendCompleteAction(actionId, "failed", msg);
  }
}

// ===== BUTTON =====
bool longPressNow(uint16_t ms = 3000) {
  if (digitalRead(BTN_CFG_PIN) == HIGH)
    return false;
  uint32_t t0 = millis();
  while (digitalRead(BTN_CFG_PIN) == LOW) {
    if (millis() - t0 >= ms)
      return true;
    delay(10);
  }
  return false;
}

// ===== CONFIG PORTAL (FORCED) =====
bool runConfigPortalForced() {
  Serial.println("[CFG] FORCE AP mode...");

  // LED indikator masuk mode setup
  ledBlinkAPMode();

  WiFi.disconnect(false, false);
  delay(300);
  WiFi.mode(WIFI_AP_STA);

  WiFiManager wm;
  wm.setConfigPortalBlocking(true);
  wm.setConfigPortalTimeout(300);
  wm.setCleanConnect(false);

  char devidBuf[64] = {0}, intvalBuf[12] = {0};
  char hwIdBuf[64] = {0};

  // Get hardware ID (MAC-based, read-only)
  String hwId = defaultDeviceId();
  strncpy(hwIdBuf, hwId.c_str(), sizeof(hwIdBuf) - 1);

  // Get current device ID
  String did = CFG.deviceId.length() ? CFG.deviceId : defaultDeviceId();
  strncpy(devidBuf, did.c_str(), sizeof(devidBuf) - 1);

  snprintf(intvalBuf, sizeof(intvalBuf), "%u", (unsigned)CFG.intervalSec);

  // Hardware ID as read-only field (shown after WiFiManager's built-in
  // SSID/Password fields)
  char customHtmlHwId[300];
  snprintf(
      customHtmlHwId, sizeof(customHtmlHwId),
      "<label for='hwid'>Hardware ID</label><input type='text' id='hwid' "
      "name='hwid' value='%s' readonly style='background-color: #e0e0e0;'>",
      hwIdBuf);
  WiFiManagerParameter pHwId(customHtmlHwId);

  WiFiManagerParameter pDevId("deviceId", "Device ID", devidBuf, 60);
  WiFiManagerParameter pInterval("interval", "Interval", intvalBuf, 10);

  // Add custom parameters (WiFiManager's SSID/Password fields will appear first
  // automatically)
  wm.addParameter(&pHwId);
  wm.addParameter(&pDevId);
  wm.addParameter(&pInterval);

  bool ok = wm.startConfigPortal(AP_NAME, AP_PASSWORD);

  // Save only custom config values (WiFiManager handles WiFi credentials
  // automatically)
  CFG.deviceId = String(pDevId.getValue());
  CFG.intervalSec = String(pInterval.getValue()).toInt();

  if (CFG.deviceId.length() == 0)
    CFG.deviceId = did;
  if (CFG.intervalSec == 0)
    CFG.intervalSec = 60;
  saveConfig();

  Serial.println("[CFG] Konfigurasi disimpan:");
  Serial.print(" WiFi SSID: ");
  Serial.println(WiFi.SSID());
  Serial.print(" Hardware ID: ");
  Serial.println(hwId);
  Serial.print(" Device ID: ");
  Serial.println(CFG.deviceId);
  Serial.printf(" Interval: %u detik\n", (unsigned)CFG.intervalSec);

  ledBlinkSave();

  // Pastikan kredensial tersimpan ke flash
  WiFi.softAPdisconnect(true);
  WiFi.mode(WIFI_STA);
  WiFi.persistent(true);
  WiFi.setAutoReconnect(true);
  delay(200);
  if (ok) {
    Serial.println("[CFG] Reconnect Wi-Fi...");
    // WiFiManager already saved credentials, just reconnect
    WiFi.begin();
  }

  digitalWrite(LED_PIN, HIGH); // LED solid = mode normal
  return ok;
}

// ===== HEARTBEAT =====
bool sendHeartbeat() {
  // Jangan kirim kalau belum dapat IP
  if (WiFi.status() != WL_CONNECTED || WiFi.localIP().toString() == "0.0.0.0") {
    Serial.println("[HB] Wi-Fi belum siap, tunda.");
    return false;
  }

  long rssi = WiFi.RSSI();
  String hwId = defaultDeviceId();
  String localIp = WiFi.localIP().toString();
  String url = String(REMOTE_URL) + "remote.php?action=heartbeat";

  JsonDocument doc;
  doc["id"] = hwId;
  doc["device_id"] = CFG.deviceId;
  doc["ssid"] = WiFi.SSID();
  doc["rssi"] = rssi;
  doc["ip"] = localIp;
  doc["v"] = VERSION;
  doc["interval"] = CFG.intervalSec;

  String payload;
  serializeJson(doc, payload);

  Serial.print("[HB] POST ke: ");
  Serial.println(url);
  Serial.print("[HB] Payload: ");
  Serial.println(payload);
  Serial.print("Interval: ");
  Serial.println(CFG.intervalSec);

  WiFiClientSecure client;
  client.setTimeout(HTTP_TIMEOUT_MS);
  client.setInsecure();

  HTTPClient http;
  if (!http.begin(client, url)) {
    Serial.println("[HB] Gagal init HTTP");
    return false;
  }
  http.setConnectTimeout(HTTP_TIMEOUT_MS);
  http.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
  http.addHeader("Content-Type", "application/json");

  int code = http.POST(payload);
  Serial.print("[HB] HTTP ");
  Serial.println(code);

  bool ok = false;
  if (code > 0) {
    String resp = http.getString();
    Serial.print("[HB] Resp: ");
    Serial.println(resp);
    if (code >= 200 && code < 300) {
      Serial.println("[HB] Heartbeat OK");
      ok = true;
    }
  } else {
    Serial.print("[HB] Error: ");
    Serial.println(http.errorToString(code));
  }
  http.end();
  return ok;
}

void tryReconnectIfNeeded() {
  if (wifiConnected)
    return;
  unsigned long now = millis();
  if (now >= nextReconnectAtMs) {
    Serial.printf("[WiFi] Reconnect... (backoff=%lums)\n", currentBackoffMs);
    WiFi.disconnect(false, false);
    delay(50);
    // WiFiManager stores credentials in flash automatically
    WiFi.begin();
    nextReconnectAtMs = now + currentBackoffMs;
  }
}

// ===== SETUP =====
void setup() {
  pinMode(BTN_CFG_PIN, INPUT_PULLUP);
  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, LOW);

  Serial.begin(115200);
  unsigned long t0 = millis();
  while (!Serial && millis() - t0 < 5000) {
    delay(10);
  }
  Serial.println("Booting...");
  Serial.printf("PhiNet Version: %s\n", VERSION);
  ledBlinkBoot();

  WiFi.mode(WIFI_STA);
  WiFi.persistent(true);
  WiFi.setAutoReconnect(true);
  WiFi.onEvent(onWiFiEvent);

  loadConfig();
  syncTime();

  // Coba connect pakai kredensial yang tersimpan di WiFiManager
  Serial.println("[WiFi] Connect dengan WiFi credentials tersimpan...");
  WiFi.begin();

  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 10000) {
    delay(250);
    Serial.print(".");
  }
  Serial.println();

  // Kalau gagal connect -> buka portal untuk setup WiFi
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[WiFi] Gagal konek, buka AP...");
    runConfigPortalForced();
  }

  nextReconnectAtMs = millis() + RECONNECT_BASE_MS;
  digitalWrite(LED_PIN, HIGH);
}

// ===== LOOP =====
void loop() {
  static bool suppressRepeat = false;
  if (longPressNow(3000)) {
    if (!suppressRepeat) {
      suppressRepeat = true;
      runConfigPortalForced();
    }
  } else {
    suppressRepeat = false;
  }

  if (WiFi.status() != WL_CONNECTED) {
    wifiConnected = false;
    tryReconnectIfNeeded();
  } else {
    wifiConnected = true;
  }

  unsigned long now = millis();

  // Kirim heartbeat DAN check remote actions sesuai interval
  if (wifiConnected && (now - lastSendMs >= CFG.intervalSec * 1000UL)) {
    Serial.println("[HB] Kirim heartbeat...");
    sendHeartbeat();

    // Check remote actions bersamaan dengan heartbeat (lebih efisien)
    checkRemoteActions();

    lastSendMs = now;
  }

  delay(10);
}