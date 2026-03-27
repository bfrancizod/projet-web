# help_me_stage — Setup Guide

## Prerequisites

### Arch Linux / EndeavourOS
```bash
sudo pacman -S php php-apache php-gd mariadb composer
```

### Ubuntu / Debian / WSL
```bash
sudo apt install php php-mysql libapache2-mod-php mariadb-server composer
```

---

## 1. Clone the repository
```bash
git clone <repo-url>
cd projet-web
```

---

## 2. Install PHP dependencies
```bash
composer install
```

---

## 3. Enable PDO MySQL driver

### Arch Linux only
The driver exists but is disabled by default:
```bash
sudo nano /etc/php/php.ini
```
Find and uncomment these two lines (remove the `;`):
```ini
extension=pdo_mysql
extension=pdo
```

### Ubuntu / WSL
Already enabled after `apt install php-mysql`. Nothing to do.

---

## 4. Set up environment variables
```bash
cp .env.example .env
nano .env
```
Fill in your credentials:
```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=help_me_stage
DB_USER=root
DB_PASS=yourpassword
```

---

## 5. Set up the database

### Arch Linux
```bash
# start MariaDB if not running
sudo systemctl start mysqld

# create the database
mariadb -u root -p -e "CREATE DATABASE help_me_stage;"

# import the schema
mariadb -u root -p help_me_stage < help_me_stage.sql
```

### Ubuntu / WSL
```bash
# start MySQL if not running
sudo systemctl start mysql

# create the database
mysql -u root -p -e "CREATE DATABASE help_me_stage;"

# import the schema
mysql -u root -p help_me_stage < help_me_stage.sql
```

> Note: on Arch, `mysql` is deprecated — always use `mariadb` instead.

---

## 6. Run the development server
```bash
php -S localhost:8000 -t public
```
Then open `http://localhost:8000` in your browser.

---

## Troubleshooting

| Error | Cause | Fix |
|-------|-------|-----|
| `could not find driver` | pdo_mysql not enabled | See step 3 |
| `Access denied for user` | wrong credentials in `.env` | Check step 4 |
| `Erreur : variables .env manquantes` | `.env` file missing | See step 4 |
| blank page / 500 error | missing composer dependencies | Run `composer install` |