# EduDisplej – MFA (Többtényezős Hitelesítés)

> **Verzió:** 2026 Q2

---

## Tartalomjegyzék

1. [Áttekintés](#1-áttekintés)
2. [TOTP beállítási folyamat](#2-totp-beállítási-folyamat)
3. [Backup kódok](#3-backup-kódok)
4. [Belépési folyamat MFA-val](#4-belépési-folyamat-mfa-val)
5. [Admin felület](#5-admin-felület)
6. [API referencia](#6-api-referencia)
7. [Adatbázis séma](#7-adatbázis-séma)
8. [Biztonság](#8-biztonság)

---

## 1. Áttekintés

Az EduDisplej rendszer felhasználó szinten bekapcsolható TOTP (Time-based One-Time Password) alapú kétfaktoros hitelesítést (2FA / MFA) támogat. A Google Authenticator, Authy és hasonló RFC 6238 kompatibilis alkalmazásokkal használható.

---

## 2. TOTP beállítási folyamat

### Lépések (felhasználó szemszögéből)

1. A **Profil** oldalon (vagy admin felületen) kattints a „2FA beállítása" gombra
2. A rendszer generál egy 32 karakteres Base32 titkot, majd ideiglenesen menti a `users.otp_secret` oszlopba (`otp_verified = 0`)
3. Megjelenik a QR kód és a titkos kulcs szöveges formában
4. Scanneld be a QR kódot egy TOTP alkalmazással (pl. Google Authenticator)
5. Add meg a generált 6 jegyű kódot a megerősítő mezőbe
6. Sikeres ellenőrzés esetén: `otp_enabled = 1`, `otp_verified = 1`, rendszer generál **10 backup kódot**
7. Mentsd el a backup kódokat – ezek **egyszer használatosak** és elveszett telefon esetén szükségesek

### API endpoint

```
POST /api/otp_setup.php
```

**Actions:**
- `generate` – Secret + QR adat generálás
- `verify` – Kód ellenőrzés + MFA aktiválás + backup kód generálás
- `disable` – MFA letiltás (jelszó megerősítéssel)
- `status` – MFA állapot lekérdezés
- `regenerate_backup_codes` – Backup kódok újragenerálása (jelszó szükséges)

---

## 3. Backup kódok

- A rendszer 10 db egyszer-használatos backup kódot generál az MFA bekapcsolásakor
- Minden kód: 8 hexadecimális karakter (pl. `A3F2B1C8`)
- Tárolás: SHA-256 hash-elt JSON tömb a `users.backup_codes` oszlopban
- Belépéskor megadható a TOTP kód helyett: felhasználás után az adott kód törlődik a listából
- Ha elfogynak a backup kódok, a **Profil** oldalon újragenerálhatók (jelszó megerősítéssel)

---

## 4. Belépési folyamat MFA-val

1. Felhasználó megadja e-mail + jelszó kombinációt
2. Ha `otp_enabled = 1` és `otp_verified = 1`:
   - Rendszer létrehoz egy titkosított ideiglenes session tokent (`otp_pending_token`) – 5 perces lejárattal
   - A login form megjeleníti a TOTP kód beviteli mezőt
3. Felhasználó megadja a 6 jegyű TOTP kódot **vagy** egy backup kódot
   - TOTP: RFC 6238 ellenőrzés, ±1 időablak tolerancia
   - Backup kód: SHA-256 ellenőrzés, egyszerhasználatos törlés
4. Sikeres ellenőrzés esetén session létrejön, ideiglenes token törlődik
5. Hibás kód esetén biztonsági esemény naplózódik (`failed_otp`)

---

## 5. Admin felület

Az admin **Felhasználók** oldalán:

| Funkció | Leírás |
|---|---|
| MFA státusz megtekintése | `Off` / `Pending` (beállítva, de nem megerősítve) / `On` |
| MFA kikapcsolás admin által | „2FA Off" gomb – auditálva (`security_logs`) |

Az admin által végzett MFA reset rögzítésre kerül a `security_logs` táblában `admin_mfa_reset` eseménytípussal.

---

## 6. API referencia

### `POST /api/otp_setup.php`

Érvényes session szükséges.

#### `action=generate`

Generál egy új TOTP titkot és ideiglenes QR adatot.

**Response:**
```json
{
  "success": true,
  "secret": "ABCDE...",
  "qr_data": "otpauth://totp/EduDisplej:user@example.com?secret=ABCDE...&issuer=EduDisplej",
  "message": "Scan QR code with authenticator app"
}
```

#### `action=verify`

Ellenőrzi a kódot és aktiválja az MFA-t.

**POST body:** `code=123456`

**Response:**
```json
{
  "success": true,
  "message": "Two-factor authentication enabled",
  "backup_codes": ["A3F2B1C8", "D4E5F6A7", ...]
}
```

#### `action=disable`

Letiltja az MFA-t (jelszó szükséges).

**POST body:** `password=...`

#### `action=regenerate_backup_codes`

Új backup kódok generálása (jelszó szükséges).

**POST body:** `password=...`

**Response:**
```json
{
  "success": true,
  "backup_codes": ["A3F2B1C8", ...]
}
```

---

## 7. Adatbázis séma

### `users` tábla – MFA mezők

| Oszlop | Típus | Leírás |
|---|---|---|
| `otp_enabled` | `TINYINT(1)` | `1` ha MFA bekapcsolva |
| `otp_verified` | `TINYINT(1)` | `1` ha beállítás megerősítve |
| `otp_secret` | `VARCHAR(64)` | Base32 TOTP titok |
| `backup_codes` | `TEXT` | JSON tömb SHA-256 hash-elt backup kódokkal |

```sql
-- backup_codes oszlop hozzáadása (ha még nem létezik)
ALTER TABLE users ADD COLUMN IF NOT EXISTS backup_codes TEXT NULL;
```

Migration fájl: `webserver/install/migrations/001_email_mfa_licensing.sql`

---

## 8. Biztonság

- TOTP titok Base32 formátumban tárolódik – a DB kompromittálódása esetén is védelmet nyújt, mivel a titokból önmagában nem lehet visszaállítani a jelszót
- Backup kódok hash-elve tárolódnak (SHA-256), plain text soha nem kerül DB-be
- MFA setup folyamatban a titkos token ideiglenes (`otp_verified = 0` amíg nem megerősített)
- OTP kód megerősítés előtt és után a session token törlődik (CSRF-szerű védelem)
- Biztonsági események naplózása: `failed_otp`, `admin_mfa_reset`
- Az ideiglenes session pending token max 5 percig él

### Architecture-ready globális policy

Az architektúra felkészített arra, hogy szerepkör alapú globális MFA kényszert vezessük be:
- `users.otp_enabled` flag alapján az admin kikényszeríthet kötelező MFA-t új felhasználóknak a user creation form-on keresztül (a `require_otp` mező már elérhető az admin felhasználólétrehozó formban)
- Jövőbeli fejlesztés: `system_settings` táblában `mfa_required_for_admins` / `mfa_required_for_all` beállítás
