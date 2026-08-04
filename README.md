# Crossroad Website

## Planner email reminders

Copy `.env.example` to `.env`, configure the SMTP settings, then schedule this
command every five minutes:

```text
C:\xampp\php\php-win.exe C:\www\Crossroad-Website\backend\send_planner_email_reminders.php
```

Use XAMPP's windowless PHP executable on Windows so scheduled runs do not open a
command window. It loads `C:\xampp\php\php.ini` and the required OpenSSL
extension. The runner emails each PIC using the email stored on their user or
administrator account. It sends once inside the one-day window and once inside
the three-hour window.

### Telegram planner reminders

Planner reminders can also be delivered through Telegram:

1. Create a Telegram bot with BotFather.
2. Set the four Telegram values shown in `.env.example`.
3. On the production server, run `php backend/configure_telegram_webhook.php`
   once to register the secure webhook.
4. Each user opens **Telegram Notifications** in Crossroad System, taps
   **Connect Telegram**, then taps **Start** in Telegram. Their chat ID is
   captured and linked automatically.

Telegram uses the same one-day and three-hour reminder windows and task details
as email. Email and Telegram delivery attempts are logged separately, so either
channel can retry independently.

For localhost development, Telegram cannot reach a private LAN address. Run
`php backend/run_telegram_bot_local.php` in a terminal to use long polling
instead. Keep that terminal open while testing. Stop it before registering the
production webhook.

## Shared-hosting deployment

The application remains compatible with the domain's current PHP 7.1 runtime,
although PHP 8.2 or newer is recommended because PHP 7.1 is no longer supported.

Configure production database credentials in `.env` instead of editing PHP files:

```text
CROSSROAD_DB_HOST=localhost
CROSSROAD_DB_PORT=3306
CROSSROAD_DB_NAME=your_database
CROSSROAD_DB_USER=your_database_user
CROSSROAD_DB_PASS=your_database_password
```

The included `.htaccess` blocks web access to `.env`. Keep the production `.env`
when replacing extracted application files so each new ZIP deployment preserves
the domain database and SMTP settings.

## Realtime WebSocket updates

Install the realtime service dependencies in the project directory:

```text
npm install --omit=dev
```

Set the realtime values in `.env`. Generate the secret with
`openssl rand -hex 32` and use the same value for PHP and the Node service:

```text
CROSSROAD_REALTIME_PUBLIC_URL=wss://operations.crossroad.my/realtime/ws
CROSSROAD_REALTIME_PUBLISH_URL=http://127.0.0.1:8081/publish
CROSSROAD_REALTIME_PORT=8081
CROSSROAD_REALTIME_SECRET=replace_with_random_secret
```

Run `npm start` as a persistent Plesk service. The process listens only on
127.0.0.1. Add this to the domain's **Additional nginx directives** so HTTPS
and WebSocket upgrades are handled by Plesk:

```text
location = /realtime/ws {
    proxy_pass http://127.0.0.1:8081/ws;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 65s;
}

location = /realtime/health {
    proxy_pass http://127.0.0.1:8081/health;
    proxy_set_header Host $host;
}
```

After restarting nginx, `/realtime/health` should return JSON with `ok: true`.
Logged changes publish module-specific events through `logActivity()`. Bulletin
and visitor changes publish directly because those modules do not use the
shared activity logger.
Asset Managment
