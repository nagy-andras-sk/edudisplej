# Mappastruktúra alapú áttekintés

## 1) Gyökérstruktúra
- `docs/`
- `tests/`
- `webserver/control_edudisplej_sk/`
- `webserver/www_edudisplej_sk/`
- `install/`
- gyökérszintű script-ek

## 2) `docs/` szerepe
- Mely témák vannak dokumentálva.
- Audit, üzemeltetés, modul-leírások kapcsolata.
- Hogyan hivatkozzunk vissza technikai fejezetekből.

## 3) `webserver/control_edudisplej_sk/` részletes térkép
- Belépési pontok
- `admin/`
- `api/`
- `dashboard/`, `dashboard_user/`
- `modules/`
- `cron/`
- `lang/`
- `logs/`, `uploads/`, `scripts/`

## 4) Konfigurációs és központi fájlok
- `dbkonfiguracia.php`, `dbjavito.php`
- `security_config.php`
- `auth_roles.php`
- `logging.php`
- `i18n.php`

## 5) `install/` és életciklus
- telepítési script-ek
- service fájlok
- init / migráció / live-build elemek

## 6) `tests/` és verifikációs anyagok
- Milyen tesztek vannak és mire valók.
- Mely alrendszerekhez kapcsolódnak.

## 7) Rövid összegzés
- 3–5 pontban a struktúra fő tanulságai.
