#include <Wire.h>
#include <Adafruit_Sensor.h>
#include <Adafruit_BME280.h>
#include <hd44780.h>
#include <hd44780ioClass/hd44780_I2Cexp.h>
#include <esp_task_wdt.h>
#include <esp_idf_version.h>

#define SDA_PIN 21
#define SCL_PIN 22

#define NANO_ADDR 0x08

#define LCD_COLS 20
#define LCD_ROWS 4

#define SIM_RX 16
#define SIM_TX 17
#define SIM_BAUD 115200

#define CO2_RX 26
#define CO2_TX 27
#define CO2_BAUD 9600

#define ERROR_VALUE 404
#define WDT_TIMEOUT_SEC 20

float PM25_SCALE = 1.0;
int PM25_MIN = 10;
int PM25_MAX = 300;

const char* APN = "m3-world";

const char* API_URL = "https://webthongso.online/GPS_khongkhi/api/receive_data.php?api_key=AIR_MONITOR_2026";
const char* SETTINGS_URL = "https://webthongso.online/GPS_khongkhi/api/get_settings.php";

HardwareSerial SIMSerial(2);
HardwareSerial CO2Serial(1);

Adafruit_BME280 bme;
hd44780_I2Cexp lcd;

bool bme_ok = false;
bool lcd_ok = false;
bool sim_ready = false;

unsigned long lastSensorUpdate = 0;
unsigned long lastSend = 0;
unsigned long lastBmeRetry = 0;
unsigned long lastSettingsRead = 0;

unsigned long sendIntervalMs = 30000UL;
const unsigned long SETTINGS_REFRESH_MS = 60000UL;

float temperatureValue = ERROR_VALUE;
float humidityValue = ERROR_VALUE;
float pressureValue = ERROR_VALUE;

int co2Value = ERROR_VALUE;
int pm1Value = ERROR_VALUE;
int pm25RawValue = ERROR_VALUE;
int pm25Value = ERROR_VALUE;
int pm10Value = ERROR_VALUE;

double lastLat = 0;
double lastLon = 0;
bool hasLastGps = false;

uint8_t gpsScrollPos = 0;

uint8_t cmdS8ReadCO2[] = {
  0xFE, 0x04, 0x00, 0x03, 0x00, 0x01, 0xD5, 0xC5
};

uint8_t cmdMHZReadCO2[] = {
  0xFF, 0x01, 0x86, 0x00, 0x00, 0x00, 0x00, 0x00, 0x79
};

struct NanoData {
  bool ok;
  bool gpsValid;
  bool dustValid;
  uint8_t sats;
  int32_t lat_e6;
  int32_t lon_e6;
  uint16_t hdop_100;
  uint16_t pm25;
  uint8_t seq;
};

void feedWDT() {
  esp_task_wdt_reset();
}

void delayWDT(unsigned long ms) {
  unsigned long start = millis();

  while (millis() - start < ms) {
    feedWDT();
    delay(10);
  }
}

void setupWatchdog() {
#if ESP_IDF_VERSION_MAJOR >= 5
  esp_task_wdt_config_t wdt_config = {};
  wdt_config.timeout_ms = WDT_TIMEOUT_SEC * 1000;
  wdt_config.idle_core_mask = (1 << portNUM_PROCESSORS) - 1;
  wdt_config.trigger_panic = true;
  esp_task_wdt_init(&wdt_config);
#else
  esp_task_wdt_init(WDT_TIMEOUT_SEC, true);
#endif

  esp_task_wdt_add(NULL);
}

int32_t readInt32(uint8_t *p, int index) {
  uint32_t value = 0;

  value |= ((uint32_t)p[index + 0] << 24);
  value |= ((uint32_t)p[index + 1] << 16);
  value |= ((uint32_t)p[index + 2] << 8);
  value |= ((uint32_t)p[index + 3]);

  return (int32_t)value;
}

uint16_t readUint16(uint8_t *p, int index) {
  return ((uint16_t)p[index] << 8) | p[index + 1];
}

bool readNanoData(NanoData &data) {
  data.ok = false;
  data.gpsValid = false;
  data.dustValid = false;

  Wire.beginTransmission(NANO_ADDR);
  Wire.write(0x10);
  byte err = Wire.endTransmission();

  if (err != 0) {
    return false;
  }

  delay(5);

  uint8_t len = Wire.requestFrom((uint8_t)NANO_ADDR, (uint8_t)17);

  if (len < 17) {
    return false;
  }

  uint8_t packet[17];

  for (int i = 0; i < 17; i++) {
    packet[i] = Wire.read();
  }

  if (packet[0] != 0xA5) {
    return false;
  }

  uint8_t checksum = 0;

  for (int i = 0; i < 16; i++) {
    checksum ^= packet[i];
  }

  if (checksum != packet[16]) {
    return false;
  }

  uint8_t flags = packet[1];

  data.ok = true;
  data.gpsValid = flags & 0x01;
  data.dustValid = flags & 0x02;
  data.sats = packet[2];
  data.lat_e6 = readInt32(packet, 3);
  data.lon_e6 = readInt32(packet, 7);
  data.hdop_100 = readUint16(packet, 11);
  data.pm25 = readUint16(packet, 13);
  data.seq = packet[15];

  return true;
}

int applyPM25Calibration(int rawPm25) {
  if (rawPm25 == ERROR_VALUE) {
    return ERROR_VALUE;
  }

  float scaled = rawPm25 * PM25_SCALE;
  int value = (int)(scaled + 0.5);

  if (value < PM25_MIN) value = PM25_MIN;
  if (value > PM25_MAX) value = PM25_MAX;

  return value;
}

void lcdPrintLine(uint8_t row, String text) {
  if (!lcd_ok) return;

  if (text.length() > LCD_COLS) {
    text = text.substring(0, LCD_COLS);
  }

  lcd.setCursor(0, row);
  lcd.print(text);

  int remain = LCD_COLS - text.length();

  for (int i = 0; i < remain; i++) {
    lcd.print(" ");
  }
}

String getScrollText(String text) {
  if (text.length() <= LCD_COLS) {
    return text;
  }

  String padded = text + "   ";

  if (gpsScrollPos >= padded.length()) {
    gpsScrollPos = 0;
  }

  String loopText = padded + padded;
  String out = loopText.substring(gpsScrollPos, gpsScrollPos + LCD_COLS);

  gpsScrollPos++;

  return out;
}

void setupLCD() {
  int lcdStatus = lcd.begin(LCD_COLS, LCD_ROWS);

  if (lcdStatus) {
    lcd_ok = false;
    Serial.print("LCD loi, status = ");
    Serial.println(lcdStatus);
    Serial.println("He thong van tiep tuc chay khong LCD");
    return;
  }

  lcd_ok = true;
  lcd.backlight();
  lcd.clear();

  lcdPrintLine(0, "AIR MONITOR");
  lcdPrintLine(1, "BME+BGSY+GPS");
  lcdPrintLine(2, "SC8/S8 CO2 + SIM");
  lcdPrintLine(3, "Starting...");
}

void setupBME280() {
  if (bme.begin(0x76)) {
    bme_ok = true;
    Serial.println("Tim thay BME280 dia chi 0x76");
  } else if (bme.begin(0x77)) {
    bme_ok = true;
    Serial.println("Tim thay BME280 dia chi 0x77");
  } else {
    bme_ok = false;
    Serial.println("Khong tim thay BME280");
  }
}

bool readBME280Values() {
  if (!bme_ok) {
    if (millis() - lastBmeRetry > 30000) {
      lastBmeRetry = millis();
      setupBME280();
    }

    temperatureValue = ERROR_VALUE;
    humidityValue = ERROR_VALUE;
    pressureValue = ERROR_VALUE;
    return false;
  }

  float t = bme.readTemperature();
  float h = bme.readHumidity();
  float p = bme.readPressure() / 100.0F;

  if (isnan(t) || isnan(h) || isnan(p)) {
    bme_ok = false;
    temperatureValue = ERROR_VALUE;
    humidityValue = ERROR_VALUE;
    pressureValue = ERROR_VALUE;
    return false;
  }

  temperatureValue = t;
  humidityValue = h;
  pressureValue = p;

  return true;
}

uint16_t modbusCRC(uint8_t *buf, int len) {
  uint16_t crc = 0xFFFF;

  for (int pos = 0; pos < len; pos++) {
    crc ^= (uint16_t)buf[pos];

    for (int i = 0; i < 8; i++) {
      if (crc & 0x0001) {
        crc >>= 1;
        crc ^= 0xA001;
      } else {
        crc >>= 1;
      }
    }
  }

  return crc;
}

uint8_t mhzChecksum(uint8_t *packet) {
  uint8_t sum = 0;

  for (int i = 1; i < 8; i++) {
    sum += packet[i];
  }

  sum = 0xFF - sum;
  sum += 1;

  return sum;
}

void clearCO2Serial() {
  while (CO2Serial.available()) {
    CO2Serial.read();
  }
}

int readSC8_Modbus() {
  clearCO2Serial();

  CO2Serial.write(cmdS8ReadCO2, sizeof(cmdS8ReadCO2));
  CO2Serial.flush();

  uint8_t res[16];
  int index = 0;

  unsigned long start = millis();

  while (millis() - start < 1000) {
    feedWDT();

    while (CO2Serial.available()) {
      if (index < 16) {
        res[index++] = CO2Serial.read();
      } else {
        CO2Serial.read();
      }
    }

    if (index >= 7) break;

    delay(1);
  }

  if (index < 7) return ERROR_VALUE;
  if (res[0] != 0xFE && res[0] != 0x01) return ERROR_VALUE;
  if (res[1] != 0x04) return ERROR_VALUE;
  if (res[2] != 0x02) return ERROR_VALUE;

  uint16_t crcCalc = modbusCRC(res, index - 2);
  uint16_t crcRecv = ((uint16_t)res[index - 1] << 8) | res[index - 2];

  if (crcCalc != crcRecv) return ERROR_VALUE;

  int co2 = ((int)res[3] << 8) | res[4];

  if (co2 < 300 || co2 > 10000) return ERROR_VALUE;

  return co2;
}

int readMHZ_Style() {
  clearCO2Serial();

  CO2Serial.write(cmdMHZReadCO2, sizeof(cmdMHZReadCO2));
  CO2Serial.flush();

  uint8_t res[9];
  int index = 0;

  unsigned long start = millis();

  while (millis() - start < 1000) {
    feedWDT();

    while (CO2Serial.available()) {
      if (index < 9) {
        res[index++] = CO2Serial.read();
      } else {
        CO2Serial.read();
      }
    }

    if (index >= 9) break;

    delay(1);
  }

  if (index < 9) return ERROR_VALUE;
  if (res[0] != 0xFF) return ERROR_VALUE;
  if (res[1] != 0x86) return ERROR_VALUE;
  if (res[8] != mhzChecksum(res)) return ERROR_VALUE;

  int co2 = res[2] * 256 + res[3];

  if (co2 < 300 || co2 > 10000) return ERROR_VALUE;

  return co2;
}

int readCO2Sensor() {
  int co2 = readSC8_Modbus();

  if (co2 != ERROR_VALUE) {
    return co2;
  }

  delayWDT(200);

  co2 = readMHZ_Style();

  if (co2 != ERROR_VALUE) {
    return co2;
  }

  return ERROR_VALUE;
}

String readSIM(unsigned long timeout) {
  String response = "";
  unsigned long start = millis();

  while (millis() - start < timeout) {
    feedWDT();

    while (SIMSerial.available()) {
      char c = SIMSerial.read();
      response += c;
    }

    delay(1);
  }

  return response;
}

String sendATReturn(String cmd, unsigned long timeout = 1000) {
  while (SIMSerial.available()) {
    SIMSerial.read();
  }

  Serial.print("Gui: ");
  Serial.println(cmd);

  SIMSerial.print(cmd);
  SIMSerial.print("\r\n");

  String res = readSIM(timeout);

  Serial.println("Phan hoi:");

  if (res.length() > 0) {
    Serial.println(res);
  } else {
    Serial.println("Khong co phan hoi");
  }

  Serial.println("----------------------------");

  return res;
}

bool sendAT(String cmd, unsigned long timeout = 1000) {
  String res = sendATReturn(cmd, timeout);
  return res.indexOf("OK") >= 0;
}

bool waitForText(String target, unsigned long timeout = 5000) {
  String response = "";
  unsigned long start = millis();

  while (millis() - start < timeout) {
    feedWDT();

    while (SIMSerial.available()) {
      char c = SIMSerial.read();
      response += c;

      if (response.indexOf(target) >= 0) {
        Serial.println("Nhan duoc:");
        Serial.println(response);
        Serial.println("----------------------------");
        return true;
      }
    }

    delay(1);
  }

  Serial.println("Khong nhan duoc target:");
  Serial.println(target);
  Serial.println("Phan hoi:");
  Serial.println(response);
  Serial.println("----------------------------");

  return false;
}

bool checkSIM() {
  Serial.println();
  Serial.println("KIEM TRA SIM CO BAN");
  Serial.println("============================");

  if (!sendAT("AT", 1000)) return false;

  sendAT("ATE0", 1000);
  sendAT("AT+CMEE=2", 1000);
  sendAT("ATI", 1000);

  String cpin = sendATReturn("AT+CPIN?", 1000);

  if (cpin.indexOf("READY") < 0) {
    Serial.println("LOI: SIM chua READY");
    return false;
  }

  sendAT("AT+CSQ", 1000);
  sendAT("AT+CREG?", 1000);
  sendAT("AT+CGREG?", 1000);
  sendAT("AT+CEREG?", 1000);
  sendAT("AT+CGATT?", 1000);

  Serial.println("SIM CO BAN OK");
  Serial.println("============================");

  return true;
}

bool waitNetwork() {
  Serial.println();
  Serial.println("DANG DOI DANG KY MANG...");

  for (int i = 0; i < 40; i++) {
    feedWDT();

    String cereg = sendATReturn("AT+CEREG?", 1000);
    String cgreg = sendATReturn("AT+CGREG?", 1000);
    String creg  = sendATReturn("AT+CREG?", 1000);

    if (cereg.indexOf(",1") >= 0 || cereg.indexOf(",5") >= 0 ||
        cgreg.indexOf(",1") >= 0 || cgreg.indexOf(",5") >= 0 ||
        creg.indexOf(",1") >= 0 || creg.indexOf(",5") >= 0) {
      Serial.println("DA DANG KY MANG THANH CONG");
      return true;
    }

    delayWDT(1000);
  }

  Serial.println("LOI: CHUA DANG KY DUOC MANG");
  return false;
}

bool setup4G() {
  Serial.println();
  Serial.println("BAT DAU KHOI TAO 4G");
  Serial.println("============================");

  if (!checkSIM()) {
    Serial.println("SIM CHECK THAT BAI");
    return false;
  }

  if (!waitNetwork()) {
    Serial.println("DANG KY MANG THAT BAI");
    return false;
  }

  sendAT("AT+CGATT=1", 3000);

  String apnCmd = "AT+CGDCONT=1,\"IP\",\"" + String(APN) + "\"";
  sendAT(apnCmd, 1000);

  sendAT("AT+CGACT=0,1", 3000);
  delayWDT(1000);
  sendAT("AT+CGACT=1,1", 5000);

  String ip = sendATReturn("AT+CGPADDR=1", 2000);

  if (ip.indexOf("+CGPADDR") >= 0 && ip.indexOf("0.0.0.0") < 0) {
    Serial.println("4G DA SAN SANG");
    Serial.println("============================");
    return true;
  }

  Serial.println("LOI: CHUA LAY DUOC IP 4G");
  Serial.println("============================");

  return false;
}

void parseSendIntervalFromResponse(String res) {
  int idx = res.indexOf("\"send_interval_sec\"");

  if (idx < 0) return;

  int colon = res.indexOf(":", idx);

  if (colon < 0) return;

  int start = colon + 1;

  while (start < res.length() && (res[start] == ' ' || res[start] == '\"')) {
    start++;
  }

  int end = start;

  while (end < res.length() && isDigit(res[end])) {
    end++;
  }

  int sec = res.substring(start, end).toInt();

  if (sec >= 10 && sec <= 86400) {
    sendIntervalMs = (unsigned long)sec * 1000UL;

    Serial.print("Cap nhat chu ky gui tu web: ");
    Serial.print(sec);
    Serial.println(" giay");
  }
}

bool httpGet(String url, String &bodyOut) {
  bodyOut = "";

  sendAT("AT+HTTPTERM", 1000);

  String res = sendATReturn("AT+HTTPINIT", 3000);

  if (res.indexOf("OK") < 0) {
    return false;
  }

  sendAT("AT+HTTPPARA=\"CID\",1", 1000);
  sendAT("AT+HTTPSSL=1", 2000);

  String urlCmd = "AT+HTTPPARA=\"URL\",\"" + url + "\"";
  res = sendATReturn(urlCmd, 3000);

  if (res.indexOf("OK") < 0) {
    sendAT("AT+HTTPTERM", 1000);
    return false;
  }

  while (SIMSerial.available()) {
    SIMSerial.read();
  }

  Serial.println("Gui: AT+HTTPACTION=0");
  SIMSerial.print("AT+HTTPACTION=0\r\n");

  String actionRes = "";
  unsigned long start = millis();

  while (millis() - start < 20000) {
    feedWDT();

    while (SIMSerial.available()) {
      char c = SIMSerial.read();
      actionRes += c;
    }

    if (actionRes.indexOf("+HTTPACTION:") >= 0) {
      delayWDT(500);

      while (SIMSerial.available()) {
        char c = SIMSerial.read();
        actionRes += c;
      }

      break;
    }

    delay(1);
  }

  Serial.println("Phan hoi HTTPACTION GET:");
  Serial.println(actionRes);

  bool ok = false;

  if (actionRes.indexOf("+HTTPACTION: 0,200") >= 0 ||
      actionRes.indexOf(",200,") >= 0) {
    ok = true;
  }

  bodyOut = sendATReturn("AT+HTTPREAD", 5000);

  sendAT("AT+HTTPTERM", 1000);

  return ok;
}

void updateSettingsFromWeb() {
  if (!sim_ready) {
    sim_ready = setup4G();

    if (!sim_ready) {
      return;
    }
  }

  String body;

  Serial.println("DOC CAI DAT TU WEB...");

  bool ok = httpGet(String(SETTINGS_URL), body);

  if (ok) {
    parseSendIntervalFromResponse(body);
  } else {
    Serial.println("Khong doc duoc settings tu web");
    sim_ready = false;
  }
}

bool httpPostJson(String jsonData) {
  Serial.println();
  Serial.println("BAT DAU GUI HTTP POST");
  Serial.println("============================");

  sendAT("AT+HTTPTERM", 1000);

  String res = sendATReturn("AT+HTTPINIT", 3000);

  if (res.indexOf("OK") < 0) {
    Serial.println("LOI: HTTPINIT THAT BAI");
    return false;
  }

  sendAT("AT+HTTPPARA=\"CID\",1", 1000);

  res = sendATReturn("AT+HTTPSSL=1", 2000);

  if (res.indexOf("ERROR") >= 0) {
    Serial.println("CANH BAO: HTTPSSL=1 bi ERROR");
  }

  String urlCmd = "AT+HTTPPARA=\"URL\",\"" + String(API_URL) + "\"";
  res = sendATReturn(urlCmd, 3000);

  if (res.indexOf("OK") < 0) {
    Serial.println("LOI: SET URL THAT BAI");
    sendAT("AT+HTTPTERM", 1000);
    return false;
  }

  sendAT("AT+HTTPPARA=\"CONTENT\",\"application/json\"", 1000);

  String dataCmd = "AT+HTTPDATA=" + String(jsonData.length()) + ",10000";

  while (SIMSerial.available()) {
    SIMSerial.read();
  }

  Serial.print("Gui: ");
  Serial.println(dataCmd);

  SIMSerial.print(dataCmd);
  SIMSerial.print("\r\n");

  if (!waitForText("DOWNLOAD", 5000)) {
    Serial.println("LOI: KHONG NHAN DUOC DOWNLOAD");
    sendAT("AT+HTTPTERM", 1000);
    return false;
  }

  Serial.println("Gui JSON:");
  Serial.println(jsonData);

  SIMSerial.print(jsonData);

  delayWDT(1500);

  String afterData = readSIM(2000);

  Serial.println("Phan hoi sau khi gui JSON:");
  Serial.println(afterData);
  Serial.println("----------------------------");

  while (SIMSerial.available()) {
    SIMSerial.read();
  }

  Serial.println("Gui: AT+HTTPACTION=1");
  SIMSerial.print("AT+HTTPACTION=1\r\n");

  String actionRes = "";
  unsigned long start = millis();

  while (millis() - start < 25000) {
    feedWDT();

    while (SIMSerial.available()) {
      char c = SIMSerial.read();
      actionRes += c;
    }

    if (actionRes.indexOf("+HTTPACTION:") >= 0) {
      delayWDT(500);

      while (SIMSerial.available()) {
        char c = SIMSerial.read();
        actionRes += c;
      }

      break;
    }

    delay(1);
  }

  Serial.println("Phan hoi HTTPACTION:");
  Serial.println(actionRes);
  Serial.println("----------------------------");

  bool ok = false;

  if (actionRes.indexOf("+HTTPACTION: 1,200") >= 0 ||
      actionRes.indexOf(",200,") >= 0) {
    ok = true;
  }

  String readRes = sendATReturn("AT+HTTPREAD", 5000);

  parseSendIntervalFromResponse(readRes);

  sendAT("AT+HTTPTERM", 1000);

  Serial.println("============================");

  return ok;
}

String createJsonData() {
  String json = "{";

  json += "\"device_id\":\"BUS_01\",";
  json += "\"temperature\":" + String(temperatureValue, 1) + ",";
  json += "\"humidity\":" + String(humidityValue, 1) + ",";
  json += "\"pressure\":" + String(pressureValue, 1) + ",";
  json += "\"co2\":" + String(co2Value) + ",";
  json += "\"pm1\":" + String(pm1Value) + ",";
  json += "\"pm25\":" + String(pm25Value) + ",";
  json += "\"pm10\":" + String(pm10Value) + ",";
  json += "\"lat\":" + String(lastLat, 6) + ",";
  json += "\"lon\":" + String(lastLon, 6);

  json += "}";

  return json;
}

bool sendDataToWeb() {
  if (!hasLastGps) {
    Serial.println("CHUA CO GPS HOP LE, KHONG GUI WEB");
    return false;
  }

  if (!sim_ready) {
    sim_ready = setup4G();

    if (!sim_ready) {
      return false;
    }
  }

  String json = createJsonData();

  Serial.println("DU LIEU GUI WEB:");
  Serial.println(json);

  bool ok = httpPostJson(json);

  if (!ok) {
    sim_ready = false;
  }

  return ok;
}

void updateLCD() {
  if (!lcd_ok) return;

  String line0;
  String line1;
  String line2;
  String line3;

  if (hasLastGps) {
    String gpsText = "A:";
    gpsText += String(lastLat, 5);
    gpsText += "   O:";
    gpsText += String(lastLon, 5);

    line0 = getScrollText(gpsText);
  } else {
    line0 = "GPS: NO FIX";
    gpsScrollPos = 0;
  }

  if (temperatureValue == ERROR_VALUE) {
    line1 = "BME280 ERROR 404";
  } else {
    line1 = "T:";
    line1 += String(temperatureValue, 1);
    line1 += "C H:";
    line1 += String(humidityValue, 0);
    line1 += "%";
  }

  line2 = "P:";
  line2 += String(pressureValue, 0);
  line2 += " CO2:";
  line2 += String(co2Value);

  line3 = "PM:";
  line3 += String(pm25Value);
  line3 += " SIM:";
  line3 += sim_ready ? "OK" : "NO";

  lcdPrintLine(0, line0);
  lcdPrintLine(1, line1);
  lcdPrintLine(2, line2);
  lcdPrintLine(3, line3);
}

void printDebug(NanoData &nano) {
  Serial.println("========== ESP32 DATA ==========");

  Serial.print("Nano OK: ");
  Serial.println(nano.ok ? "YES" : "NO");

  if (nano.ok) {
    Serial.print("GPS valid: ");
    Serial.println(nano.gpsValid ? "YES" : "NO");

    Serial.print("A / LAT: ");
    Serial.println(nano.lat_e6 / 1000000.0, 6);

    Serial.print("O / LON: ");
    Serial.println(nano.lon_e6 / 1000000.0, 6);

    Serial.print("SAT: ");
    Serial.println(nano.sats);

    Serial.print("PM2.5 valid: ");
    Serial.println(nano.dustValid ? "YES" : "NO");

    Serial.print("PM2.5 raw: ");
    Serial.println(pm25RawValue);

    Serial.print("PM2.5 calibrated: ");
    Serial.println(pm25Value);
  }

  Serial.print("BME OK: ");
  Serial.println(bme_ok ? "YES" : "NO");

  Serial.print("Temp: ");
  Serial.println(temperatureValue);

  Serial.print("Hum: ");
  Serial.println(humidityValue);

  Serial.print("Pressure: ");
  Serial.println(pressureValue);

  Serial.print("CO2 SC8/S8: ");
  Serial.println(co2Value);

  Serial.print("GPS last valid: ");
  Serial.println(hasLastGps ? "YES" : "NO");

  Serial.print("SIM ready: ");
  Serial.println(sim_ready ? "YES" : "NO");

  Serial.print("Send interval ms: ");
  Serial.println(sendIntervalMs);

  Serial.println("================================");
  Serial.println();
}

void readAllSensors() {
  NanoData nano;
  bool nano_ok = readNanoData(nano);

  if (!nano_ok) {
    nano.ok = false;
    pm25RawValue = ERROR_VALUE;
    pm25Value = ERROR_VALUE;
  } else {
    if (nano.gpsValid) {
      lastLat = nano.lat_e6 / 1000000.0;
      lastLon = nano.lon_e6 / 1000000.0;
      hasLastGps = true;
    }

    if (nano.dustValid) {
      pm25RawValue = nano.pm25;
      pm25Value = applyPM25Calibration(pm25RawValue);
    } else {
      pm25RawValue = ERROR_VALUE;
      pm25Value = ERROR_VALUE;
    }
  }

  pm1Value = ERROR_VALUE;
  pm10Value = ERROR_VALUE;

  readBME280Values();

  co2Value = readCO2Sensor();

  updateLCD();
  printDebug(nano);
}

void setup() {
  Serial.begin(115200);
  delay(1000);

  setupWatchdog();

  Serial.println();
  Serial.println("ESP32 AIR MONITOR FULL SYSTEM");
  Serial.println("BME280 + SC8/S8 CO2 + Nano GPS/BGSY210 + SIM 4G");
  Serial.println("SIM TX -> ESP32 GPIO16 / RX2");
  Serial.println("SIM RX -> ESP32 GPIO17 / TX2");
  Serial.println("SC8/S8 TX -> ESP32 GPIO26 / RX1");
  Serial.println("SC8/S8 RX -> ESP32 GPIO27 / TX1");
  Serial.println("APN: m3-world");
  Serial.println();

  Wire.begin(SDA_PIN, SCL_PIN);
  Wire.setClock(100000);

  setupLCD();
  setupBME280();

  CO2Serial.begin(CO2_BAUD, SERIAL_8N1, CO2_RX, CO2_TX);
  delayWDT(500);

  SIMSerial.begin(SIM_BAUD, SERIAL_8N1, SIM_RX, SIM_TX);
  delayWDT(3000);

  sim_ready = setup4G();

  if (sim_ready) {
    Serial.println("KHOI TAO 4G THANH CONG");
    updateSettingsFromWeb();
  } else {
    Serial.println("KHOI TAO 4G THAT BAI, SE THU LAI KHI GUI DATA");
  }

  lastSensorUpdate = millis() - 2000;
  lastSend = millis() - sendIntervalMs + 10000;
  lastSettingsRead = millis();
}

void loop() {
  feedWDT();

  unsigned long now = millis();

  if (now - lastSensorUpdate >= 2000) {
    lastSensorUpdate = now;
    readAllSensors();
  }

  if (now - lastSettingsRead >= SETTINGS_REFRESH_MS) {
    lastSettingsRead = now;
    updateSettingsFromWeb();
  }

  if (now - lastSend >= sendIntervalMs) {
    lastSend = now;

    bool ok = sendDataToWeb();

    if (ok) {
      Serial.println("GUI DU LIEU LEN WEB THANH CONG");
    } else {
      Serial.println("GUI DU LIEU LEN WEB THAT BAI HOAC CHUA CO GPS");
    }

    Serial.println("=====================================");
  }

  delayWDT(50);
}