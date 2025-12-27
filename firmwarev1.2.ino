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
  String endpoint;
  String token;
  String deviceId;
  uint32_t intervalSec;

  String wifiSsid; // NEW
  String wifiPass; // NEW
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
  CFG.endpoint = prefs.getString("endpoint", "");
  CFG.token = prefs.getString("token", "");
  CFG.deviceId = prefs.getString("deviceId", "");
  CFG.intervalSec = prefs.getUInt("interval", 60);

  CFG.wifiSsid = prefs.getString("wssid", ""); // NEW
  CFG.wifiPass = prefs.getString("wpass", ""); // NEW
  prefs.end();
  if (CFG.deviceId.length() == 0)
    CFG.deviceId = defaultDeviceId();
  if (CFG.intervalSec == 0)
    CFG.intervalSec = 60;
}

void saveConfig() {
  prefs.begin("cfg", false);
  prefs.putString("endpoint", CFG.endpoint);
  prefs.putString("token", CFG.token);
  prefs.putString("deviceId", CFG.deviceId);
  prefs.putUInt("interval", CFG.intervalSec);

  prefs.putString("wssid", CFG.wifiSsid); // NEW
  prefs.putString("wpass", CFG.wifiPass); // NEW
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

  } else if (action == "changeendpoint") {
    CFG.endpoint = value;
    saveConfig();
    sendRemoteLog(actionId, "Endpoint diubah menjadi: " + value);

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

  } else if (action == "changeendpoint") {
    CFG.endpoint = value;
    saveConfig();

    String msg = "Endpoint diubah menjadi: " + value;
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

  char endpointBuf[200] = {0}, tokenBuf[128] = {0}, devidBuf[64] = {0},
       intvalBuf[12] = {0};
  char ssidBuf[64] = {0}, passBuf[64] = {0}; // NEW
  if (CFG.endpoint.length())
    strncpy(endpointBuf, CFG.endpoint.c_str(), sizeof(endpointBuf) - 1);
  if (CFG.token.length())
    strncpy(tokenBuf, CFG.token.c_str(), sizeof(tokenBuf) - 1);
  String did = CFG.deviceId.length() ? CFG.deviceId : defaultDeviceId();
  strncpy(devidBuf, did.c_str(), sizeof(devidBuf) - 1);
  snprintf(intvalBuf, sizeof(intvalBuf), "%u", (unsigned)CFG.intervalSec);
  if (CFG.wifiSsid.length())
    strncpy(ssidBuf, CFG.wifiSsid.c_str(), sizeof(ssidBuf) - 1);
  if (CFG.wifiPass.length())
    strncpy(passBuf, CFG.wifiPass.c_str(), sizeof(passBuf) - 1);

  WiFiManagerParameter pEndpoint("endpoint", "Heartbeat URL (http/https)",
                                 endpointBuf, 180);
  WiFiManagerParameter pToken("token", "Bearer Token (opsional)", tokenBuf,
                              120);
  WiFiManagerParameter pDevId("deviceId", "Device ID", devidBuf, 60);
  WiFiManagerParameter pInterval("interval", "Interval detik (mis. 60)",
                                 intvalBuf, 10);
  WiFiManagerParameter pWifiSsid("wssid", "WiFi SSID", ssidBuf, 63);
  WiFiManagerParameter pWifiPass("wpass", "WiFi Password", passBuf, 63);
  wm.addParameter(&pWifiSsid);
  wm.addParameter(&pWifiPass);
  wm.addParameter(&pEndpoint);
  wm.addParameter(&pToken);
  wm.addParameter(&pDevId);
  wm.addParameter(&pInterval);

  bool ok = wm.startConfigPortal(AP_NAME, AP_PASSWORD);

  CFG.endpoint = String(pEndpoint.getValue());
  CFG.token = String(pToken.getValue());
  CFG.deviceId = String(pDevId.getValue());
  CFG.intervalSec = String(pInterval.getValue()).toInt();
  CFG.wifiSsid = String(pWifiSsid.getValue());
  CFG.wifiPass = String(pWifiPass.getValue());

  if (CFG.deviceId.length() == 0)
    CFG.deviceId = did;
  if (CFG.intervalSec == 0)
    CFG.intervalSec = 60;
  saveConfig();

  Serial.println("[CFG] Konfigurasi disimpan:");
  Serial.print(" endpoint: ");
  Serial.println(CFG.endpoint);
  Serial.print(" token   : ");
  Serial.println(CFG.token.length() ? "<set>" : "<kosong>");
  Serial.print(" deviceId: ");
  Serial.println(CFG.deviceId);
  Serial.printf(" interval: %u detik\n", (unsigned)CFG.intervalSec);

  ledBlinkSave();

  // Pastikan kredensial tersimpan ke flash
  WiFi.softAPdisconnect(true);
  WiFi.mode(WIFI_STA);
  WiFi.persistent(true);
  WiFi.setAutoReconnect(true);
  delay(200);
  if (ok) {
    Serial.println("[CFG] Reconnect Wi-Fi...");
    if (CFG.wifiSsid.length()) {
      WiFi.begin(CFG.wifiSsid.c_str(), CFG.wifiPass.c_str());
    } else {
      WiFi.begin();
    }
  }

  digitalWrite(LED_PIN, HIGH); // LED solid = mode normal
  return ok;
}

// ===== HEARTBEAT =====
bool sendHeartbeat() {
  if (CFG.endpoint.length() == 0) {
    Serial.println("[HB] Endpoint kosong, abaikan.");
    return false;
  }

  // Jangan kirim kalau belum dapat IP
  if (WiFi.status() != WL_CONNECTED || WiFi.localIP().toString() == "0.0.0.0") {
    Serial.println("[HB] Wi-Fi belum siap, tunda.");
    return false;
  }

  long rssi = WiFi.RSSI();
  String hwId = defaultDeviceId();
  String localIp = WiFi.localIP().toString();

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

  Serial.print("POST ke: ");
  Serial.println(CFG.endpoint);
  Serial.print("Payload: ");
  Serial.println(payload);
  Serial.print("Interval: ");
  Serial.println(CFG.intervalSec);

  WiFiClientSecure client;
  client.setTimeout(HTTP_TIMEOUT_MS);
  client.setInsecure();

  HTTPClient http;
  if (!http.begin(client, CFG.endpoint)) {
    Serial.println("[HTTP] Gagal init HTTP");
    return false;
  }
  http.setConnectTimeout(HTTP_TIMEOUT_MS);
  http.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
  http.addHeader("Content-Type", "application/json");
  if (CFG.token.length())
    http.addHeader("Authorization", String("Bearer ") + CFG.token);

  int code = http.POST(payload);
  Serial.print("HTTP ");
  Serial.println(code);

  bool ok = false;
  if (code > 0) {
    String resp = http.getString();
    Serial.print("Resp: ");
    Serial.println(resp);
    if (code >= 200 && code < 300) {
      Serial.println("Heartbeat OK");
      ok = true;
    }
  } else {
    Serial.print("Resp: ");
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
    // Use stored credentials from config
    if (CFG.wifiSsid.length()) {
      WiFi.begin(CFG.wifiSsid.c_str(), CFG.wifiPass.c_str());
    } else {
      WiFi.begin();
    }
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

  // 1) kalau endpoint kosong -> paksa portal
  if (CFG.endpoint.length() == 0) {
    runConfigPortalForced();
  } else {
    // 2) coba connect pakai kredensial yang kita simpan sendiri
    if (CFG.wifiSsid.length()) {
      Serial.printf("[WiFi] Connect pakai stored SSID: %s\n",
                    CFG.wifiSsid.c_str());
      WiFi.begin(CFG.wifiSsid.c_str(), CFG.wifiPass.c_str());
    } else {
      Serial.println("[WiFi] Tidak ada stored SSID, coba WiFi.begin() default");
      WiFi.begin();
    }

    unsigned long start = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - start < 10000) {
      delay(250);
      Serial.print(".");
    }
    Serial.println();

    // 3) kalau gagal -> buka portal untuk input ulang ssid/pass + endpoint
    // tetap
    if (WiFi.status() != WL_CONNECTED) {
      Serial.println("[WiFi] Gagal konek, buka AP...");
      runConfigPortalForced();
    }
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