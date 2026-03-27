# help_me_stage — Guide d'installation

## Prérequis

### Arch Linux / EndeavourOS
```bash
sudo pacman -S php php-apache php-gd mariadb composer
```

### Ubuntu / Debian / WSL
```bash
sudo apt install php php-mysql libapache2-mod-php mariadb-server composer
```

---

## 1. Cloner le dépôt
```bash
git clone https://github.com/paulmsg/projet-web.git
cd projet-web
```

---

## 2. Installer les dépendances PHP
```bash
composer install
```

---

## 3. Activer le driver PDO MySQL

### Arch Linux uniquement
Le driver existe mais est désactivé par défaut :
```bash
sudo nano /etc/php/php.ini
```
Trouver et décommenter ces deux lignes (retirer le `;`) :
```ini
extension=pdo_mysql
extension=pdo
```

### Ubuntu / WSL
Déjà activé après `apt install php-mysql`. Rien à faire.

---

## 4. Configurer les variables d'environnement
```bash
cp .env.example .env
nano .env
```
Remplir avec vos identifiants :
```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=nom_de_la_BDD
DB_USER=ton_user_mysql
DB_PASS=ton_mdp_mysql
```

---

## 5. Créer et importer la base de données

### Arch Linux
```bash
# démarrer MariaDB si ce n'est pas déjà fait
sudo systemctl start mysqld

# créer la base de données
mariadb -u root -p -e "CREATE DATABASE help_me_stage;"

# importer le schéma
mariadb -u root -p help_me_stage < help_me_stage.sql
```

### Ubuntu / WSL
```bash
# démarrer MySQL si ce n'est pas déjà fait
sudo systemctl start mysql

# créer la base de données
mysql -u root -p -e "CREATE DATABASE help_me_stage;"

# importer le schéma
mysql -u root -p help_me_stage < help_me_stage.sql
```

> Note : sur Arch, `mysql` est déprécié — toujours utiliser `mariadb` à la place.

---

## 6. Lancer le serveur de développement
```bash
php -S localhost:8000 -t public
```
Puis ouvrir `http://localhost:8000` dans votre navigateur.

---

## Résolution des problèmes courants

| Erreur | Cause | Solution |
|--------|-------|----------|
| `could not find driver` | pdo_mysql non activé | Voir étape 3 |
| `Access denied for user` | mauvais identifiants dans `.env` | Vérifier étape 4 |
| `Erreur : variables .env manquantes` | fichier `.env` absent | Voir étape 4 |
| page blanche / erreur 500 | dépendances Composer manquantes | Lancer `composer install` |