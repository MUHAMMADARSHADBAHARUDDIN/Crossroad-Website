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
Asset Managment
