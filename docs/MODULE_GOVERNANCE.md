# Module Governance (EduDisplej)

Cél: a modulrendszer hosszú távon bővíthető legyen, de központi szabályokkal.

## 1) Kötelező modul csomagstruktúra

Minden modulnak követnie kell a standardot:

- `module.json`
- `config/default_settings.json`
- renderer fájl (`m_<module-key>.html` vagy manifestben megadott)

Részletek: `docs/MODULE_STRUCTURE.md`.

## 2) Modul policy réteg (szerveroldali contract)

Központi policy fájl:

- `webserver/control_edudisplej_sk/modules/module_policy.php`

Ez tartalmazza modulonként:

- engedélyezett settings mezők (whitelist)
- mezőtípusok (enum/int/float/bool/color/string)
- határértékek (min/max/maxLen)
- default értékek
- időtartam szabály (duration min/max/default vagy fixed)

Aktív policy jelenleg:

- `clock` (+ aliasok: `datetime`, `dateclock`)
- `default-logo`
- `text`
- `unconfigured`

## 3) Mentéskori kikényszerítés

A group loop mentés API (`api/group_loop/config.php`) mentés előtt:

- feloldja a `module_key`-t (ha kliensből hiányzik)
- normalizálja és validálja a `settings` payloadot modul policy alapján
- clampeli a `duration_seconds` értéket policy alapján
- csak szanitizált adatot ír DB-be

Ez biztosítja, hogy hibás vagy túl bő payload ne kerüljön tartós tárolásba.

## 4) Jogosultsági modell (modultartalom)

Szerepkör helper: `auth_roles.php`

- `admin`, `user`, `loop_manager`: teljes loop szerkesztés
- `content_editor`: csak meglévő modulok content/settings módosítás (szerkezetet nem változtat)

A tartalom mentés oldali korlát a loop config API-ban érvényesül.

## 5) Tárolási szabályok

- Futó loop adat: `kiosk_group_modules` (+ `kiosk_group_time_blocks`)
- Planner nézet/payload: `kiosk_group_loop_plans.plan_json`
- Modul törzsadat: `modules`
- Licenc hozzárendelés: `module_licenses`

Általános elv:

- `module_id` az elsődleges referencia
- `module_key` redundáns/olvasási segédmező, konzisztensen töltve
- settings JSON mindig policy-n át mentve

## 5.1) Cégszintű engedélyezés kötelező

- Minden modul csak admin engedélyezés (licenc hozzárendelés) után használható egy cégnél.
- UI oldalon a loop szerkesztő csak a cégnek engedélyezett modulokat listázza.
- Szerveroldalon mentéskor is kötelező ellenőrzés fut: nem engedélyezett modul nem menthető.
- Kivétel: `unconfigured` technikai fallback modul.

## 6) Bővítési workflow új modulhoz

1. Hozd létre a csomagot a standard szerint.
2. Add hozzá a modult a registry-hez (`module_registry.php`).
3. Add hozzá a policy-t (`module_policy.php`):
   - settings mezők + validáció
   - duration szabály
4. Add hozzá a loop editor defaultokat/UI mezőket, ha kell.
5. Add hozzá maintenance seedbe (`maintenance_task.php`) ha core modul.
6. Ellenőrizd API mentést és kiosk runtime letöltést.

## 7) Mit lehet / mit nem

Lehet:

- új modul felvétele policy-vel és standard csomaggal
- meglévő modul settings bővítése policy frissítéssel

Nem lehet:

- policy-ben nem definiált settings mezőt perzisztálni
- duration-t policy határon kívül tárolni
- default csoport loopját módosítani

---

Ez az alap governance réteg már alkalmas arra, hogy kevés modulról stabilan, kontrolláltan skálázzatok sok modulra.
