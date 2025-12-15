#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <WiFiManager.h> // Library Manager: "WiFiManager by tzapu"
#include <time.h>
#include <nvs_flash.h>

// ===== PIN & CONFIG =====
#define BTN_CFG_PIN   9      // Tombol BOOT di ESP32-C3
#define LED_PIN       8      // LED indikator
const char* AP_NAME     = "PhiNet-Setup";
const char* AP_PASSWORD = "Admin123";

const unsigned long HTTP_TIMEOUT_MS   = 8000;
const unsigned long RECONNECT_BASE_MS = 1000;
const unsigned long RECONNECT_MAX_MS  = 60000;
const char* TZ_STRING = "WIB-7";
const char* NTP1 = "id.pool.ntp.org";
const char* NTP2 = "pool.ntp.org";

// ===== STATE =====
volatile bool wifiConnected = false;
unsigned long lastSendMs = 0;
unsigned long nextReconnectAtMs = 0;
unsigned long currentBackoffMs  = RECONNECT_BASE_MS;

struct AppConfig {
  String endpoint;
  String token;
  String deviceId;
  uint32_t intervalSec;
} CFG;

Preferences prefs;

// ===== LED INDICATORS =====
void ledBlinkBoot() {
  for (int i = 0; i < 3; i++) {
    digitalWrite(LED_PIN, HIGH); delay(100);
    digitalWrite(LED_PIN, LOW);  delay(100);
  }
}

void ledBlinkAPMode() {
  for (int i = 0; i < 10; i++) {
    digitalWrite(LED_PIN, HIGH); delay(50);
    digitalWrite(LED_PIN, LOW);  delay(50);
  }
}

void ledBlinkSave() {
  for (int i = 0; i < 3; i++) {
    digitalWrite(LED_PIN, LOW);  delay(200);
    digitalWrite(LED_PIN, HIGH); delay(200);
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
    default: break;
  }
}

// ===== UTIL =====
String defaultDeviceId() {
  uint8_t mac[6];
  WiFi.macAddress(mac);
  char macStr[20];
  sprintf(macStr, "phinet-%02x%02x%02x%02x%02x%02x", 
          mac[0], mac[1], mac[2], mac[3], mac[4], mac[5]);
  return String(macStr);
}

void loadConfig() {
  prefs.begin("cfg", true);
  CFG.endpoint    = prefs.getString("endpoint", "");
  CFG.token       = prefs.getString("token", "");
  CFG.deviceId    = prefs.getString("deviceId", "");
  CFG.intervalSec = prefs.getUInt("interval", 60);
  prefs.end();
  if (CFG.deviceId.length() == 0) CFG.deviceId = defaultDeviceId();
  if (CFG.intervalSec == 0) CFG.intervalSec = 60;
}

void saveConfig() {
  prefs.begin("cfg", false);
  prefs.putString("endpoint", CFG.endpoint);
  prefs.putString("token",    CFG.token);
  prefs.putString("deviceId", CFG.deviceId);
  prefs.putUInt("interval",   CFG.intervalSec);
  prefs.end();
}

void syncTime() {
  configTzTime(TZ_STRING, NTP1, NTP2);
  for (int i=0; i<20; i++) {
    if (time(nullptr) > 1700000000) break;
    delay(200);
  }
}

// ===== BUTTON =====
bool longPressNow(uint16_t ms=3000) {
  if (digitalRead(BTN_CFG_PIN) == HIGH) return false;
  uint32_t t0 = millis();
  while (digitalRead(BTN_CFG_PIN) == LOW) {
    if (millis() - t0 >= ms) return true;
    delay(10);
  }
  return false;
}

// ===== CONFIG PORTAL (FORCED) =====
bool runConfigPortalForced() {
  Serial.println("[CFG] FORCE AP mode...");

  // LED indikator masuk mode setup
  ledBlinkAPMode();

  WiFi.disconnect(true, true);
  delay(300);
  WiFi.mode(WIFI_AP_STA);

  WiFiManager wm;
  wm.setConfigPortalBlocking(true);
  wm.setConfigPortalTimeout(300);
  wm.setCleanConnect(true);

  char endpointBuf[200]={0}, tokenBuf[128]={0}, devidBuf[64]={0}, intvalBuf[12]={0};
  if (CFG.endpoint.length()) strncpy(endpointBuf, CFG.endpoint.c_str(), sizeof(endpointBuf)-1);
  if (CFG.token.length())    strncpy(tokenBuf,    CFG.token.c_str(),    sizeof(tokenBuf)-1);
  String did = CFG.deviceId.length()? CFG.deviceId : defaultDeviceId();
  strncpy(devidBuf, did.c_str(), sizeof(devidBuf)-1);
  snprintf(intvalBuf, sizeof(intvalBuf), "%u", (unsigned)CFG.intervalSec);

  WiFiManagerParameter pEndpoint("endpoint", "Heartbeat URL (http/https)", endpointBuf, 180);
  WiFiManagerParameter pToken("token", "Bearer Token (opsional)", tokenBuf, 120);
  WiFiManagerParameter pDevId("deviceId", "Device ID", devidBuf, 60);
  WiFiManagerParameter pInterval("interval", "Interval detik (mis. 60)", intvalBuf, 10);
  wm.addParameter(&pEndpoint);
  wm.addParameter(&pToken);
  wm.addParameter(&pDevId);
  wm.addParameter(&pInterval);

  bool ok = wm.startConfigPortal(AP_NAME, AP_PASSWORD);

  CFG.endpoint    = String(pEndpoint.getValue());
  CFG.token       = String(pToken.getValue());
  CFG.deviceId    = String(pDevId.getValue());
  CFG.intervalSec = String(pInterval.getValue()).toInt();
  if (CFG.deviceId.length() == 0)   CFG.deviceId = did;
  if (CFG.intervalSec == 0)         CFG.intervalSec = 60;
  saveConfig();

  Serial.println("[CFG] Konfigurasi disimpan:");
  Serial.print(" endpoint: "); Serial.println(CFG.endpoint);
  Serial.print(" token   : "); Serial.println(CFG.token.length()? "<set>" : "<kosong>");
  Serial.print(" deviceId: "); Serial.println(CFG.deviceId);
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
    WiFi.begin();
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
  char payload[300];
  snprintf(payload, sizeof(payload),
        "{\"id\":\"%s\",\"device_id\":\"%s\",\"ssid\":\"%s\",\"rssi\":%ld}",
        hwId.c_str(), CFG.deviceId.c_str(), WiFi.SSID().c_str(), rssi);

  Serial.print("POST ke: "); Serial.println(CFG.endpoint);
  Serial.print("Payload: "); Serial.println(payload);

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
  if (CFG.token.length()) http.addHeader("Authorization", String("Bearer ") + CFG.token);

  int code = http.POST((uint8_t*)payload, strlen(payload));
  Serial.print("HTTP "); Serial.println(code);

  bool ok = false;
  if (code > 0) {
    String resp = http.getString();
    Serial.print("Resp: "); Serial.println(resp);
    if (code >= 200 && code < 300) {
      Serial.println("Heartbeat OK");
      ok = true;
    }
  } else {
    Serial.print("Resp: "); Serial.println(http.errorToString(code));
  }
  http.end();
  return ok;
}

void tryReconnectIfNeeded() {
  if (wifiConnected) return;
  unsigned long now = millis();
  if (now >= nextReconnectAtMs) {
    Serial.printf("[WiFi] Reconnect... (backoff=%lums)\n", currentBackoffMs);
    WiFi.disconnect(false, false);
    delay(50);
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
  while (!Serial && millis()-t0 < 5000) { delay(10); }
  Serial.println("Booting...");
  ledBlinkBoot();

  WiFi.mode(WIFI_STA);
  WiFi.persistent(true);
  WiFi.setAutoReconnect(true);
  WiFi.onEvent(onWiFiEvent);

  loadConfig();
  syncTime();

  if (CFG.endpoint.length() == 0) {
    runConfigPortalForced();
  } else {
    Serial.println("[WiFi] Connect dengan cred tersimpan...");
    delay(300);
    WiFi.begin();

    unsigned long start = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - start < 10000) {
      delay(250);
      Serial.print(".");
    }
    Serial.println();
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
  if (wifiConnected && (now - lastSendMs >= CFG.intervalSec * 1000UL)) {
    Serial.println("[HB] Kirim heartbeat...");
    sendHeartbeat();
    lastSendMs = now;
  }

  delay(10);
}
