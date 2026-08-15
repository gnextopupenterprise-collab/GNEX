# GNEX main website: VPS migration

Target stack: Ubuntu/Debian, Apache 2.4, PHP 8.2+, MariaDB/MySQL, HTTPS.

## 1. DNS preparation

Reduce the TTL for `gnexcenter.com` and `www.gnexcenter.com` to 300 seconds at least a few hours before cutover. Do not remove the current GitHub Pages records until the VPS copy passes the hosts-file test.

## 2. Install the runtime

```bash
sudo apt update
sudo apt install -y apache2 mariadb-server git php php-cli php-mysql php-mbstring php-curl php-json certbot python3-certbot-apache
sudo a2enmod rewrite headers expires ssl
```

## 3. Deploy code

```bash
sudo install -d -o www-data -g www-data /var/www/gnex-main
sudo -u www-data git clone https://github.com/gnextopupenterprise-collab/GNEX.git /var/www/gnex-main
sudo install -d -o www-data -g www-data -m 700 /var/www/gnex-main/data/sessions
```

Copy `scrim-db-config.php` and any password/push configuration directly to `/var/www/gnex-main`. These files are intentionally ignored by Git. Set owner `www-data:www-data` and mode `640`.

## 4. Apache and HTTPS

```bash
sudo cp /var/www/gnex-main/deploy/gnex-main.conf /etc/apache2/sites-available/gnex-main.conf
sudo a2ensite gnex-main.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Before DNS cutover, test locally with a hosts-file entry pointing `gnexcenter.com` to the VPS IP. Verify homepage, `api/topup.php?action=state`, customer registration/login, guest chat, Clash League admin login and existing tournament APIs.

After the A/AAAA records point to the VPS:

```bash
sudo certbot --apache -d gnexcenter.com -d www.gnexcenter.com
```

## 5. Database

Use the same database configured for Clash League. `api/topup.php` creates the `gt_*` tables and references the existing `cl_admin_users` table. Back up the database before first production request.

## 6. Cutover and rollback

Keep the old GitHub Pages configuration intact until HTTPS and APIs pass. Rollback is changing DNS records back to GitHub Pages. Once the VPS has been stable for at least 24 hours, GitHub remains the source repository but is no longer the public web host.

## 7. Updates after migration

Run deployments as the `www-data` owner or a dedicated deploy user:

```bash
cd /var/www/gnex-main
git fetch origin main
git pull --ff-only origin main
```

Never commit live database credentials, passwords, session files or TLS private keys.
