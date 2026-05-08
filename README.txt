EduDisplej

A szerver biztosítja a vezérlőpanelt és az API-t.
A kiosk egy Debian/Raspberry Pi alapú gép, amely a szerver által küldött tartalmat jeleníti meg teljes képernyős módban.

FONTOS: A csomagok nevei és verziói változhatnak a disztribúciók frissítéseivel.
Az aktuális verziót és a legfrissebb telepítési útmutatót mindig a GitHub-on kövesd:
  https://github.com/nagy-andras-sk/edudisplej

------------------
1. SZERVER BEÁLLÍTÁSA

Követelmények:
  - Linux szerver (Debian/Ubuntu)
  - Apache2 + PHP 8.x + MySQL/MariaDB
  - Két domain (pl. control.valamidomain.sk és install.valamidomain.sk)

Lépések:

1.1  Csomagok telepítése:

     sudo apt-get update
     sudo apt-get install -y apache2 php php-mysql php-curl php-mbstring mariadb-server

     Megjegyzés: a csomagnév és verzió disztribúciónként eltérhet.
     Például PHP 8.x esetén előfordulhat: php8.2-mysql, php8.2-curl, php8.2-mbstring
     Az aktuális csomagneveket a GitHubon találod:
       https://github.com/nagy-andras-sk/edudisplej

1.2  Adatbázis létrehozása:

     sudo mysql -e "CREATE DATABASE edudisplej;
                    CREATE USER 'edudisplej'@'localhost' IDENTIFIED BY 'VALAMI_JELSZO';
                    GRANT ALL ON edudisplej.* TO 'edudisplej'@'localhost';
                    FLUSH PRIVILEGES;"

1.3  Webszerver fájlok másolása:

     sudo cp -r webserver/control_edudisplej_sk/. /var/www/control.valamidomain.sk/
     sudo cp -r webserver/install/.              /var/www/install.valamidomain.sk/

1.4  Adatbázis konfiguráció szerkesztése:

     sudo nano /var/www/control.valamidomain.sk/dbkonfiguracia.php

       $db_host = "localhost";
       $db_name = "edudisplej";
       $db_user = "edudisplej";
       $db_pass = "VALAMI_JELSZO";

1.5  Apache virtual hostok beállítása:

    Létre kell hozni két virtual host konfiguráció fájlt:

     /etc/apache2/sites-available/control.conf:
       <VirtualHost *:80>
           ServerName control.valamidomain.sk
           DocumentRoot /var/www/control.valamidomain.sk
           <Directory /var/www/control.valamidomain.sk>
               AllowOverride All
               Require all granted
           </Directory>
       </VirtualHost>

     /etc/apache2/sites-available/install.conf:
       <VirtualHost *:80>
           ServerName install.valamidomain.sk
           DocumentRoot /var/www/install.valamidomain.sk
           <Directory /var/www/install.valamidomain.sk>
               AllowOverride All
               Require all granted
           </Directory>
       </VirtualHost>

     sudo a2ensite control.conf install.conf
     sudo a2enmod rewrite
     sudo systemctl restart apache2

1.6  DNS / domain beállítás:

      mindkét domain (control.valamidomain.sk és
     install.valamidomain.sk) a szerver IP-jére mutasson.

1.7  API URL beállítása a kiosk install scriptben:

     Ha nem a default control.edudisplej.sk URL-t lenne használatban ,
     a webserver/install/install.sh fájlt kell szerkeszeni és lecserélni:
       INIT_BASE="https://install.vvalamidomain.skk/init"
     és a kiosk/edudisplej-sync.service fájlban:
       Environment=EDUDISPLEJ_API_URL=https://control.valamidomain.sk

1.8  Belépés a vezérlőpultba:

     https://control.valamidomain.sk
    

------------------
2. KIOSK TELEPÍTÉSE

Követelmények:
  - Raspberry Pi 3/4 , Zero WH , vagy más Debian alapú gép (Hálózat elérés,Grafika)
  - Root hozzáférés

Lépések:

2.1  API tokent a vezérlőpultból (control.valamidomain.sk).

2.2  Telepítő futtatása:

     sudo bash -c "$(curl -fsSL https://install.valamidomain.sk/install.sh)" -- --token=AZ_API_TOKEN

     A telepítő automatikusan:
       - Letölti a szükséges fájlokat
       - Telepíti a systemd szolgáltatásokat
       - Újraindítja a gépet

2.3  Újraindítás után a kiosk automatikusan elindul és megjelenik
     a szerveren konfigurált tartalom.


------------------
3. HIBAELHÁRÍTÁS
(logok ellenőrzése)
Kiosk nem indul el: 
  sudo systemctl status edudisplej-kiosk.service
  sudo journalctl -u edudisplej-kiosk.service -n 50

Szinkronizáció állapota:
  sudo journalctl -u edudisplej-sync.service -n 50
  cat /opt/edudisplej/sync_status.json

Kézi újraindítás:
  sudo systemctl restart edudisplej-kiosk.service


------------------
4. FÁJLSTRUKTÚRA

kiosk/
  common.sh                  - Közös segédfüggvények
  edudisplej-init.sh         - Első indítás beállítása
  kiosk-start.sh             - X szerver indítása
  kiosk.sh                   - Kiosk stop segédfüggvény
  edudisplej_sync_service.sh - API szinkronizáció (5 percenként)
  edudisplej-watchdog.sh     - Watchdog, automatikus újraindítás
  edudisplej-kiosk.service   - systemd: kiosk
  edudisplej-sync.service    - systemd: szinkronizáció
  edudisplej-watchdog.service- systemd: watchdog
  clock.html                 - Offline óra (fallback)
  waiting_registration.html  - Regisztrációra várakozó oldal

webserver/
  install/install.sh         - Kiosk telepítő script
  control_edudisplej_sk/     - Vezérlőpanel (PHP)
  www_edudisplej_sk/         - Publikus weboldal


------------------
5. VEZÉRLŐPANEL BEJELENTKEZÉS

Alapértelmezett adminisztrátor fiók (adatbázis nélkül is működik):
  Email:   bc@nagyandras.sk
  Jelszó:  bakalarskapraca

Ha az adatbázis nem elérhető, a login oldalon "Adatbázis hiba" üzenet jelenik meg.


------------------
6. API VÉGPONTOK (control.valamidomain.sk)

A kiosk eszközök az alábbi végpontokon kommunikálnak a szerverrel.
Minden kérés a kiosk API tokenjét igényli (Authorization: Bearer <token> fejléc).

  POST /api/registration.php
    Kiosk regisztráció / első bejelentkezés.
    Kérés (JSON): { "mac": "aa:bb:cc:dd:ee:ff", "hostname": "kiosk01", "hw_info": {...} }
    Válasz:        { "success": true, "device_id": "<mac>", "kiosk_id": 1, "is_configured": false }

  POST /api/v1/device/sync.php
    Hardver-adatok szinkronizálása (5 percenként).
    Kérés (JSON): { "mac": "...", "hostname": "...", "hw_info": {...}, "version": "1.0", "last_update": "..." }
    Válasz:        { "success": true, "sync_interval": 300 }

  POST /api/check_group_loop_update.php
    Loop-tartalom frissítésének ellenőrzése.
    Kérés (JSON): { "device_id": "<mac>" }
    Válasz:        { "success": true, "loop_updated_at": "2025-01-01 12:00:00" }

  POST /api/update_sync_timestamp.php
    Szinkronizáció időbélyegének rögzítése.
    Kérés (JSON): { "mac": "...", "last_sync": "2025-01-01 12:00:00" }
    Válasz:        { "success": true }

  GET  /api/health.php
    Szerver állapot-ellenőrzés (PHP, DB, táblák).
    Válasz:        { "status": "ok", "checks": {...} }

  GET/POST /api/meal_plan.php?action=<action>
    Étlap modul adatai. Akciók: sites, institutions, menu, admin_sites,
    admin_institutions, menus, save_site, save_institution, save_menu.


------------------
7. ADATBÁZIS SÉMA (főbb táblák)

  users         – felhasználók (id, username, email, password, isadmin, user_role, company_id, lang)
  companies     – cégek / intézmények (id, name, license_key, is_active, api_token)
  kiosks        – kiosk eszközök (id, mac_address, hostname, friendly_name, company_id, status,
                                   last_seen, last_sync, loop_last_update, hw_info, version, sync_interval)
  kiosk_groups  – kiosk csoportok (id, name, company_id, priority)
  kiosk_group_assignments – kiosk-csoport hozzárendelés (kiosk_id, group_id)
  kiosk_group_modules     – csoporthoz rendelt modulok (group_id, is_active, updated_at)
  kiosk_modules           – kiosk-szintű modulok (kiosk_id, module_id, is_active, created_at)
  modules                 – elérhető modulok (id, module_key, name, description)

Az aktuális migrációs SQL-t és a legfrissebb dokumentációt a GitHubon találod:
  https://github.com/nagy-andras-sk/edudisplej
