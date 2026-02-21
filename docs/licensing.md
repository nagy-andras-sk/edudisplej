# EduDisplej – Licensz Kezelés

> **Verzió:** 2026 Q2

---

## Tartalomjegyzék

1. [Licensz modell](#1-licensz-modell)
2. [Admin dashboard – Cég Licenszek](#2-admin-dashboard--cég-licenszek)
3. [Eszköz hozzárendelés (device slot)](#3-eszköz-hozzárendelés-device-slot)
4. [Lejárati politika](#4-lejárati-politika)
5. [E-mail értesítések](#5-e-mail-értesítések)
6. [Adatbázis séma](#6-adatbázis-séma)
7. [API referencia](#7-api-referencia)
8. [Audit napló](#8-audit-napló)

---

## 1. Licensz modell

Minden licensz **egy céghez** tartozik, és egy adott **időszakra** szól. A korlát kizárólag a **kiosk eszközök számára** vonatkozik – felhasználószám nincs limitálva.

### Licensz entitás

| Mező | Leírás |
|---|---|
| `company_id` | A cég azonosítója |
| `valid_from` | Licensz kezdő dátuma |
| `valid_until` | Licensz lejárati dátuma |
| `device_limit` | Maximálisan engedélyezett aktív kiosk eszközök száma |
| `status` | `active` / `suspended` / `expired` |
| `notes` | Opcionális megjegyzés |

---

## 2. Admin dashboard – Cég Licenszek

Az admin **Cég Licenszek** menüpontban:

- Cégenkénti licensz lista: érvényesség, device limit, használt slotok száma
- Lejárati figyelmeztetések:
  - 🟡 Sárga figyelmeztetés: lejárat ≤ 30 napon belül
  - 🔴 Piros figyelmeztetés: lejárt licensz (de a rendszer **nem tiltja le** az eszközöket)
- Új licensz létrehozása / meglévő szerkesztése
- Eszközlista cégenkénti bontásban:
  - `hostname`, `device_id`, `last_seen`, `activated_at`, státusz
  - Gyors műveletek: **Deactivate** (slot felszabadítás) / **Activate** (slot foglalás)

---

## 3. Eszköz hozzárendelés (device slot)

A kiosk eszközök a meglévő `device_id` mező alapján azonosítódnak (`kiosks.device_id`).

### Slot logika

- `kiosks.license_active = 1`: az eszköz aktív, foglal 1 slotot
- `kiosks.license_active = 0`: az eszköz deaktivált, nem foglal slotot
- `kiosks.activated_at`: az eszköz első aktiválásának időpontja

### Slot számítás

```
used_slots = COUNT(kiosks WHERE company_id = X AND license_active = 1)
free_slots  = device_limit - used_slots
```

### Admin deaktiválás

Az admin a **Cég Licenszek** oldalon egy kattintással deaktiválja az adott eszközt → slot felszabadul → más eszköz aktiválható.

### Új kiosk regisztráció

Amikor egy új kiosk csatlakozik/regisztrál (`api/registration.php`), a `license_active = 1` és `activated_at = NOW()` értékkel jön létre, ha van szabad slot a cégnek. Ha nincs szabad slot, a kiosk regisztrálódik, de figyelmeztetés jelenik meg az admin felületen.

---

## 4. Lejárati politika

**Nincs azonnali tiltás lejáratkor.** A rendszer folyamatosan működik lejárat után is, de:

- Az admin dashboard-on jól látható figyelmeztetés jelenik meg
- Opcionális e-mail értesítés lejárat előtt (30, 7, 1 nappal) – `license_expiring` sablon alapján
- A `company_licenses.status` értéke `expired`-ra vált, de az eszközök tovább működnek

---

## 5. E-mail értesítések

A lejárat előtti e-mail értesítések a `license_expiring` email sablonnal mennek ki (ha az SMTP konfigurálva van).

Elérhető változók a sablonban:

| Változó | Leírás |
|---|---|
| `{{company_name}}` | Cég neve |
| `{{valid_until}}` | Lejárati dátum |
| `{{device_limit}}` | Engedélyezett eszközszám |
| `{{used_devices}}` | Aktuálisan aktív eszközök száma |
| `{{days_left}}` | Hátralévő napok száma |

Az értesítések küldése külső cron job-ból triggelhető (jövőbeli fejlesztés), vagy manuálisan az admin felületről.

---

## 6. Adatbázis séma

```sql
-- Cég licenszek
CREATE TABLE company_licenses (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    company_id   INT  NOT NULL,
    valid_from   DATE NOT NULL,
    valid_until  DATE NOT NULL,
    device_limit INT  NOT NULL DEFAULT 10,
    status       ENUM('active','suspended','expired') NOT NULL DEFAULT 'active',
    notes        TEXT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- Kiosk eszközök licensz slot mezői
ALTER TABLE kiosks
    ADD COLUMN license_active TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN activated_at   DATETIME   NULL;
```

Migration fájl: `webserver/install/migrations/001_email_mfa_licensing.sql`

---

## 7. API referencia

### `POST /api/licenses.php`

Admin session szükséges (`isadmin = 1`).

#### `action=save_license`

Licensz létrehozása/frissítése.

**POST paraméterek:** `license_id` (0 = új), `company_id`, `valid_from`, `valid_until`, `device_limit`, `notes`

#### `action=deactivate_device`

Eszköz deaktiválása (slot felszabadítás).

**POST paraméterek:** `kiosk_id`

#### `action=activate_device`

Eszköz aktiválása (slot foglalás).

**POST paraméterek:** `kiosk_id`

---

## 8. Audit napló

Minden licensz változás és eszköz aktiválás/deaktiválás naplózódik a `security_logs` táblában:

| Event típus | Leírás |
|---|---|
| `license_change` | Licensz létrehozása vagy módosítása |
| `device_deactivated` | Kiosk eszköz deaktiválása |
| `device_activated` | Kiosk eszköz aktiválása |
