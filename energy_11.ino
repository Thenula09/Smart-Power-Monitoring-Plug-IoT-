#include <WiFi.h>
#include <WebServer.h>
#include <HTTPClient.h>
#include <PZEM004Tv30.h>
#include "time.h"

// --- CONFIGURATION ---

const char* ssid = "YESITHA_LAN2";
const char* password = "12345678";
const char* server_ip = "192.168.1.146";
const char* server_path = "/SMART_PLUG/index.php?page=api&action=log_data";
const char* device_id = "PZEM001";
const int SSR_PIN = 23;
const int PZEM_RX = 16;
const int PZEM_TX = 17;
const int POWER_LED_PIN = 12; // New pin for Power LED
const int WIFI_LED_PIN = 13;  // New pin for WiFi LED
const int SWITCH_LED_PIN = 14; // New pin for Switch LED

// --- TIMEZONE CONFIGURATION ---
const char* ntpServer = "pool.ntp.org";
// Sri Lanka Standard Time (SLST) is UTC +5:30
const long gmtOffset_sec = 5.5 * 3600;
const int daylightOffset_sec = 0;

// --- GLOBALS ---
HardwareSerial pzemSerial(2);
PZEM004Tv30 pzem(pzemSerial, PZEM_RX, PZEM_TX);
WebServer server(80);
unsigned long lastDataSendMillis = 0;
const long dataSendInterval = 60000;
unsigned long lastMs = 0;
double lastPowerW = 0;
double accumulatedWh = 0.0;

// --- WEB SERVER HANDLERS ---
void handleStatus() {
  float v = pzem.voltage();
  float i = pzem.current();
  float p = pzem.power();
  float e = pzem.energy();

  if (isnan(v) || isnan(i) || isnan(p) || isnan(e)) {
    server.send(500, "application/json", "{\"error\":\"PZEM read failed\"}");
    return;
  }

  String json = "{";
  json += "\"voltage\":" + String(v, 2);
  json += ",\"current\":" + String(i, 3);
  json += ",\"power\":" + String(p, 2);
  json += ",\"energy\":" + String(e, 3);
  json += ",\"energy_integrated\":" + String(accumulatedWh, 4);
  json += ",\"relay\":" + String(digitalRead(SSR_PIN));
  json += "}";

  server.send(200, "application/json", json);
}

void handleOn() {
  digitalWrite(SSR_PIN, LOW);
  digitalWrite(SWITCH_LED_PIN, HIGH); // Turn on Switch LED
  server.send(200, "application/json", "{\"status\":\"success\",\"relay_state\":\"on\"}");
}

void handleOff() {
  digitalWrite(SSR_PIN, HIGH);
  digitalWrite(SWITCH_LED_PIN, LOW); // Turn off Switch LED
  server.send(200, "application/json", "{\"status\":\"success\",\"relay_state\":\"off\"}");
}

// --- CORE FUNCTIONS ---
void setup() {
  Serial.begin(115200);
  pinMode(SSR_PIN, OUTPUT);
  pinMode(POWER_LED_PIN, OUTPUT); // Initialize Power LED pin
  pinMode(WIFI_LED_PIN, OUTPUT);  // Initialize WiFi LED pin
  pinMode(SWITCH_LED_PIN, OUTPUT); // Initialize Switch LED pin
  digitalWrite(SSR_PIN, LOW);
  digitalWrite(POWER_LED_PIN, HIGH); // Turn on Power LED
  digitalWrite(WIFI_LED_PIN, LOW);   // WiFi LED off initially
  digitalWrite(SWITCH_LED_PIN, HIGH); // Switch LED off initially

  pzemSerial.begin(9600, SERIAL_8N1, PZEM_RX, PZEM_TX);

  Serial.println("Checking PZEM-004T connection...");
  delay(1000);
  float initialVoltage = pzem.voltage();
  if (isnan(initialVoltage)) {
    Serial.println("⚠️ Could not read from PZEM sensor yet. Will retry in loop.");
  } else {
    Serial.print("✅ PZEM connected. Initial voltage: ");
    Serial.println(initialVoltage);
  }

  Serial.print("Connecting to ");
  Serial.println(ssid);
  WiFi.begin(ssid, password);
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\nWiFi connected!");
  Serial.print("IP address: ");
  Serial.println(WiFi.localIP());
  digitalWrite(WIFI_LED_PIN, HIGH); // Turn on WiFi LED

  // Configure NTP and Timezone
  configTime(gmtOffset_sec, daylightOffset_sec, ntpServer);
  Serial.println("Configuring NTP Time...");
  struct tm timeinfo;
  if (!getLocalTime(&timeinfo)) {
    Serial.println("Failed to obtain time. Please check your WiFi connection and NTP server.");
  } else {
    Serial.println("Time obtained!");
  }

  server.on("/status", handleStatus);
  server.on("/on", handleOn);
  server.on("/off", handleOff);
  server.begin();
  Serial.println("HTTP server started");

  lastMs = millis();
}

String getFormattedSLTime() {
  struct tm timeinfo;
  if (!getLocalTime(&timeinfo)) {
    return ""; // Return empty string on failure
  }
  char timeString[50];
  // Format the time as a string. Example: "2025-09-12 12:00:00"
  strftime(timeString, sizeof(timeString), "%Y-%m-%d %H:%M:%S", &timeinfo);
  return String(timeString);
}

void loop() {
  server.handleClient();

  // Update WiFi LED based on connection status
  digitalWrite(WIFI_LED_PIN, WiFi.status() == WL_CONNECTED ? HIGH : LOW);

  // Poll PZEM and integrate power -> Wh
  float powerW = pzem.power();
  unsigned long now = millis();
  double dtHours = (now - lastMs) / 1000.0 / 3600.0;
  if (!isnan(powerW) && powerW >= 0) {
    accumulatedWh += (powerW * dtHours);
    lastPowerW = powerW;
  } else {
    powerW = lastPowerW;
  }
  lastMs = now;

  // Send data periodically
  if (millis() - lastDataSendMillis >= dataSendInterval) {
    sendDataToServer();
    lastDataSendMillis = millis();
  }
}

void sendDataToServer() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("WiFi not connected. Cannot send data.");
    digitalWrite(WIFI_LED_PIN, LOW); // Ensure WiFi LED is off
    return;
  }

  float v = pzem.voltage();
  float i = pzem.current();
  float p = pzem.power();
  float e = pzem.energy();

  if (isnan(v) || isnan(i) || isnan(p) || isnan(e)) {
    Serial.println("⚠️ Failed to read from PZEM. Skipping data send.");
    return;
  }

  HTTPClient http;
  String serverUrl = "http://" + String(server_ip) + String(server_path);
  http.begin(serverUrl);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");

  String postData = "device_id=" + String(device_id) +
                    "&voltage=" + String(v, 2) +
                    "&current=" + String(i, 3) +
                    "&power=" + String(p, 2) +
                    "&energy=" + String(e, 3) +
                    "&energy_integrated=" + String(accumulatedWh, 4) +
                    "&log_time=" + getFormattedSLTime();

  Serial.println("Sending data to server...");
  Serial.println(postData);

  int httpResponseCode = http.POST(postData);

  if (httpResponseCode > 0) {
    String response = http.getString();
    Serial.println("HTTP Response code: " + String(httpResponseCode));
    Serial.println(response);
  } else {
    Serial.print("Error on sending POST: ");
    Serial.println(httpResponseCode);
  }
  http.end();
}