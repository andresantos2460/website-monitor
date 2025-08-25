# Website Monitor

A simple PHP project to monitor the status of websites listed in a `sites.txt` file and send alerts to Telegram when a site experiences a critical status (HTTP ≥ 500 or 429).

---

## 🚀 Features

- Monitor multiple websites from a list (`sites.txt`).
- Send real-time alerts to your Telegram bot for critical issues.
- Easy configuration using a `.env` file.
- Lightweight and runs with PHP + cURL.

---

## 📋 How it works

1. **List your websites** in `sites.txt`, one URL per line:
   https://example1.com
   https://example2.com
   https://example3.com


2. **Configure your Telegram bot** by creating a `.env` file in the project root:
  TELEGRAM_TOKEN=your_bot_token_here
  TELEGRAM_CHATID=your_chat_id_here


3. **Run the monitor script**:

```bash
php monitor.php


⚠️ ALERT! Site: https://example1.com
HTTP Code: 503 → Service Unavailable

$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($http_code >= 500 || $http_code == 429) {
    $message = "⚠️ ALERT! Site: $site\nHTTP Code: $http_code → $status_text";
    sendTelegram($message, $token, $chat_id);
}



