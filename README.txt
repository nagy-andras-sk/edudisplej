EduDisplej - Informačný kiosk systém
=====================================

Popis / Description
-------------------
EduDisplej je Linux-based kiosk rendszer, amelyet Raspberry Pi-n és más
Debian-alapú eszközökön futtatott információs kijelzők üzemeltetésére
terveztek.

Fő funkciók / Hlavné funkcie:
  - Automatikus óra megjelenítő (analóg és digitális)
  - Iskolai étlap / jedáleň megjelenítése (jedalen.sk integráció)
  - Teljes képernyős kiosk mód (Openbox + surf böngésző)
  - Systemd alapú, watchdoggal felügyelt működés
  - Webes adminisztrációs felület a modulok beállításához


Rendszerkövetelmények / Systémové požiadavky
---------------------------------------------
  - Raspberry Pi (ajánlott: Pi 3/4) vagy más Debian-alapú eszköz
  - Debian / Raspberry Pi OS (bullseye vagy újabb)
  - Root hozzáférés / root prístup
  - Internetkapcsolat a telepítés idején


Telepítés / Inštalácia
-----------------------
1. Nyisd meg a terminált / Otvor terminál

2. Futtasd a telepítő scriptet (API token szükséges):

   sudo bash -c "$(curl -fsSL https://install.edudisplej.sk/install.sh)" -- --token=AZ_API_TOKEN

   Ahol AZ_API_TOKEN a vezérlőpultból kapott regisztrációs token.

3. A telepítő automatikusan:
   - Ellenőrzi a jogosultságokat
   - Letölti a szükséges fájlokat
   - Telepíti a systemd szolgáltatásokat
   - Újraindul (reboot) - ezután automatikusan elindul a kiosk mód

4. Az újraindítás után a rendszer automatikusan teljes képernyős módban
   mutatja az óra vagy az étlap modult.


Webes adminisztrációs felület / Webové rozhranie
-------------------------------------------------
A vezérlőpanel a következő szerveren érhető el:
  https://control.edudisplej.sk

Bejelentkezés után:
  - Kioszk eszközök listája és állapota
  - Modulok konfigurálása (óra, étlap)
  - Megjelenítési beállítások szerkesztése


Modulok / Moduly
-----------------
1. Óra / Hodiny (clock)
   - Analóg és digitális megjelenítési mód
   - Dátum megjelenítés különböző formátumokban
   - Testreszabható színek, betűméret
   - Nyelvi támogatás: SK, HU, EN

2. Étlap / Jedáleň (meal-menu)
   - Iskolai étlap automatikus letöltése a jedalen.sk oldalról
   - Mai és holnapi menü megjelenítése
   - Automatikus frissítés
   - Testreszabható megjelenés


Fájlstruktúra / Štruktúra súborov
-----------------------------------
webserver/
  install/
    install.sh                   - Telepítő script
    init/
      common.sh                  - Közös funkciók / spoločné funkcie
      edudisplej-init.sh         - Első indítás inicializálás
      edudisplej-kiosk.service   - Kiosk systemd szolgáltatás
      edudisplej-sync.service    - Szinkronizációs szolgáltatás
      edudisplej-watchdog.service - Watchdog szolgáltatás
      edudisplej-watchdog.sh     - Watchdog script
      kiosk-start.sh             - X szerver indítás
      kiosk.sh                   - Böngésző kiosk mód
      clock.html                 - Offline óra fallback oldal
      waiting_registration.html  - Regisztrációra várakozó oldal

  control_edudisplej_sk/         - Vezérlőpanel PHP webalkalmazás
    modules/
      clock/                     - Óra modul
      meal-menu/                 - Étlap modul
    dashboard/                   - Admin dashboard
    api/
      meal_plan.php              - Étlap API végpont
    login/                       - Bejelentkezés

  www_edudisplej_sk/             - Publikus weboldal


Hibaelhárítás / Riešenie problémov
------------------------------------
Ha a kiosk nem indul el:
  - Ellenőrizd a systemd szolgáltatásokat:
      sudo systemctl status edudisplej-kiosk.service

  - Nézd meg a naplókat:
      sudo journalctl -u edudisplej-kiosk.service -n 50

  - Ellenőrizd, hogy a surf böngésző telepítve van:
      which surf

  - Kézi újraindítás:
      sudo systemctl restart edudisplej-kiosk.service


Kontakt / Kontakt
------------------
Projekt: EduDisplej
GitHub: https://github.com/nagy-andras-sk/edudisplej
