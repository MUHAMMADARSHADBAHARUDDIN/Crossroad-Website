# Plesk Deployment - Item Receive and Part Request

This package is intended for the document root:

`/var/www/vhosts/crossroad.my/operations.crossroad.my/`

The deployment ZIP deliberately excludes `.env`, existing uploads, Git metadata, local output, temporary files, and dependencies generated on the development computer.

## 1. Back up the live website

In Plesk, open **Websites & Domains > Backup Manager** and create a backup of:

- Website files
- The Crossroad database

Do not continue until the backup finishes.

## 2. Preserve server configuration

Confirm these live files and directories already exist and do not delete them:

- `.env`
- `uploads/`

The ZIP does not contain either one, so extracting it will not overwrite them.

## 3. Upload and extract the ZIP

1. Open **Websites & Domains > operations.crossroad.my > File Manager**.
2. Open `/operations.crossroad.my/`.
3. Upload `Crossroad-Website-Plesk-2026-08-12.zip`.
4. Select the ZIP and click **Extract Files**.
5. Extract directly into `/operations.crossroad.my/`.
6. Allow Plesk to overwrite application files when prompted.
7. If `frontend/client_inventory.php` still exists from the previous deployment, delete that one obsolete file. ZIP extraction cannot remove files that are no longer part of the application.

The archive already contains `frontend`, `backend`, `includes`, `image`, `PHPMailer`, `realtime`, and other application folders at its root.

Do not drop the old `client_inventory` database table during this deployment. It is no longer used by the website, but retaining it avoids accidental historical data loss.

## 4. Import the database migration

1. Open **Websites & Domains > Databases**.
2. Open **phpMyAdmin** for the Crossroad database.
3. Select the correct database.
4. Open the **Import** tab.
5. Import `database/2026_08_11_item_receive_part_request.sql` from the extracted package.

The migration is safe to run again because it uses `IF NOT EXISTS` for the new tables and columns.

## 5. Configure the 100 MB upload limit

The package contains `frontend/.user.ini`, but Plesk PHP limits should also be updated:

1. Open **Websites & Domains > operations.crossroad.my > PHP Settings**.
2. Set:

   - `upload_max_filesize` = `100M`
   - `post_max_size` = `110M`
   - `max_execution_time` = `300`
   - `max_input_time` = `300`

3. Click **Apply**.

## 6. Make the attachment directory writable

In Plesk File Manager:

1. Open `uploads/`.
2. Create the folder `item_receive` if it does not already exist.
3. Give it the same write permissions and ownership as the other working upload folders.

If using SSH as root:

```bash
cd /var/www/vhosts/crossroad.my/operations.crossroad.my
mkdir -p uploads/item_receive
chown -R crossroad.my:psacln uploads/item_receive
chmod 775 uploads/item_receive
```

If the subscription system user is not `crossroad.my`, use the owner shown by `ls -ld uploads` instead.

## 7. Confirm email configuration

Do not replace the live `.env`. Confirm it still contains the working SMTP values:

```env
CROSSROAD_SMTP_HOST=smtp.gmail.com
CROSSROAD_SMTP_PORT=587
CROSSROAD_SMTP_USER=...
CROSSROAD_SMTP_PASSWORD=...
CROSSROAD_SMTP_FROM=...
CROSSROAD_SMTP_FROM_NAME=Crossroad System
CROSSROAD_SMTP_ENCRYPTION=tls
```

Part Request emails are sent automatically to `fazdlan@crossroad.my` and CC `support@crossroad.my`.

## 8. Assign permissions

Sign in as Administrator and open **Manage Users**. Assign the required permissions:

- **Item Receive**: View Receiving Records, Receive Items, Edit Received Items, Delete Received Items
- **Part Request**: View, Create Request

Administrators already have access automatically.

## 9. Test after deployment

1. Open **Item Receive**.
2. Upload a JPG or PNG and save it.
3. Click the attachment and confirm the image preview popup opens.
4. Generate an Item Receive report and confirm the image appears in the PDF table.
5. Open **Part Request**.
6. Add at least two different items.
7. Submit the request.
8. Confirm the loading popup appears.
9. Confirm the email reaches `fazdlan@crossroad.my`, CCs `support@crossroad.my`, includes the HTML item table, and contains one PDF attachment.
10. Open the generated Part Request PDF and confirm all numbered items appear.

## 10. Clean up

After successful testing, delete the uploaded deployment ZIP from the server document root.
