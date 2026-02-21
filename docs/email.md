# EduDisplej – E-mail Alrendszer

> **Verzió:** 2026 Q2

---

## Tartalomjegyzék

1. [SMTP Beállítások (Admin)](#1-smtp-beállítások-admin)
2. [E-mail Sablonok (Admin szerkeszthető)](#2-e-mail-sablonok-admin-szerkeszthető)
3. [Jelszó visszaállítás](#3-jelszó-visszaállítás)
4. [E-mail Napló](#4-e-mail-napló)
5. [Adatbázis séma](#5-adatbázis-séma)
6. [Biztonság](#6-biztonság)

---

## 1. SMTP Beállítások (Admin)

Az admin portálon az **Email Beállítások** menüpont alatt konfigurálható az SMTP szerver.

### Elérhető mezők

| Mező | Leírás | Alapértelmezett |
|---|---|---|
| SMTP Host | Szerver neve / IP | – |
| SMTP Port | Port szám | 587 |
| Titkosítás | `none` / `tls` (STARTTLS) / `ssl` | `tls` |
| Felhasználónév | SMTP auth felhasználónév | – |
| Jelszó | SMTP auth jelszó (titkosítva tárolva) | – |
| Feladó neve | `From:` megjelenített neve | EduDisplej |
| Feladó email | `From:` e-mail cím | – |
| Reply-To | Opcionális válasz cím | – |
| Timeout | Kapcsolat timeout másodpercben | 30 |

### Teszt e-mail

Az **Email Beállítások** oldalon a „Teszt email küldése" gombbal ellenőrizhetjük, hogy az SMTP konfiguráció helyes-e. Meg kell adni egy cél e-mail címet, a rendszer elküld egy tesztüzenetet, és a sikeres/hibás eredményt megjeleníti.

### Technikai megvalósítás

- Beállítások tárolása: `system_settings` tábla (kulcs–érték párok)
- SMTP jelszó tárolása: AES-256-CBC titkosítással (`encrypt_data()` / `decrypt_data()` a `security_config.php`-ból)
- Küldés: `email_helper.php` – PHP `fsockopen()` alapú SMTP kliens
  - `none`: plain socket, port 25/587
  - `tls`: STARTTLS (port 587), `stream_socket_enable_crypto()` upgrade
  - `ssl`: közvetlen SSL socket, port 465

---

## 2. E-mail Sablonok (Admin szerkeszthető)

Az **Email Sablonok** menüpont alatt a sablonok tetszőlegesen szerkeszthetők.

### Sablon típusok

| Kulcs | Leírás |
|---|---|
| `password_reset` | Jelszó visszaállítási link |
| `mfa_enabled` | 2FA bekapcsolás visszaigazolás |
| `mfa_disabled` | 2FA kikapcsolás értesítő |
| `license_expiring` | Licensz lejárat figyelmeztetés |
| `welcome` | Üdvözlő e-mail |

### Támogatott nyelvek

`hu`, `en`, `sk` – ha az adott nyelvű sablon nem létezik, a rendszer automatikusan visszaesik `en`-re.

### Változók

A sablonokban `{{változó_neve}}` formátumban lehet változókat használni:

| Változó | Leírás |
|---|---|
| `{{app_name}}` | Alkalmazás neve (`EduDisplej`) |
| `{{user_name}}` | Felhasználónév |
| `{{reset_link}}` | Jelszó-visszaállítási link |
| `{{company_name}}` | Cég neve |
| `{{valid_until}}` | Licensz lejárati dátum |
| `{{device_limit}}` | Engedélyezett eszközök száma |
| `{{used_devices}}` | Felhasznált eszközök száma |
| `{{site_url}}` | Az alkalmazás URL-je |

### Preview

Az **Email Sablonok** szerkesztő oldalon az „Előnézet" gomb sandboxolt `iframe`-ben jeleníti meg a sablon HTML tartalmát – csak admin számára elérhető.

### Teszt küldés

A szerkesztő oldalon a „Teszt küldés" gomb az admin saját e-mail-jére elküldi a sablont példa adatokkal.

---

## 3. Jelszó visszaállítás

### Folyamat

1. Felhasználó megadja e-mail-jét a `password_reset.php` oldalon
2. Rendszer generál egy 32 bájtos véletlenszerű tokent, SHA-256 hash-t tárol a `password_reset_tokens` táblában (lejárat: 1 óra)
3. Elküldi a `password_reset` sablonnal az e-mailt (ha SMTP konfigurálva van)
4. Az e-mailben lévő linkre kattintva a felhasználó megadja az új jelszót
5. Token érvényesítés (hash ellenőrzés, lejárat, egyszeri használat)
6. Jelszó frissítése, token megjelölése `used_at`-tal
7. Biztonsági esemény naplózása (`security_logs`)

### Rate limiting

- Maximum 3 kérés / e-mail cím / óra
- Enumeration protection: az oldal mindig ugyanolyan választ ad, függetlenül attól, hogy az e-mail cím létezik-e

---

## 4. E-mail Napló

Az elküldött e-mailek naplója az `email_logs` táblában tárolódik:

| Oszlop | Leírás |
|---|---|
| `template_key` | Sablon kulcsa (ha alkalmazható) |
| `to_email` | Cél e-mail cím |
| `subject` | Tárgy |
| `result` | `success` / `error` |
| `error_message` | Hibaüzenet küldési hiba esetén |
| `created_at` | Timestamp |

**Fontos:** A napló soha nem tartalmazza az SMTP jelszót.

---

## 5. Adatbázis séma

```sql
-- SMTP / e-mail beállítások
CREATE TABLE system_settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NULL,
    is_encrypted  TINYINT(1) NOT NULL DEFAULT 0,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Többnyelvű sablonok
CREATE TABLE email_templates (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(100) NOT NULL,
    lang         VARCHAR(10)  NOT NULL DEFAULT 'hu',
    subject      VARCHAR(500) NOT NULL,
    body_html    TEXT         NOT NULL,
    body_text    TEXT         NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_template_lang (template_key, lang)
);

-- E-mail napló
CREATE TABLE email_logs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    template_key  VARCHAR(100) NULL,
    to_email      VARCHAR(255) NOT NULL,
    subject       VARCHAR(500) NULL,
    result        ENUM('success','error') NOT NULL,
    error_message TEXT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Jelszó visszaállítási tokenek
CREATE TABLE password_reset_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT         NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME    NOT NULL,
    used_at    DATETIME    NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (token_hash),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

Migration fájl: `webserver/install/migrations/001_email_mfa_licensing.sql`

---

## 6. Biztonság

- SMTP jelszó titkosítva tárolva (AES-256-CBC)
- Jelszó soha nem kerül naplóba
- Password reset: token hash tárolódik, plain text soha nem kerül DB-be
- Preview funkció: csak admin számára elérhető, sandboxolt iframe
- HTML sablonok: a `preview` és `test_send` kizárólag admin session-nel érhető el
