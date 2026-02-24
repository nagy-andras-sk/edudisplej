# Changelog – EduDisplej

All notable changes are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [Unreleased] – 2026 Q2

### Added

#### E-mail alrendszer (SMTP + sablonok)
- `email_helper.php` – PHP `fsockopen` alapú SMTP kliens, STARTTLS (port 587) és direkt SSL (port 465) támogatással, AUTH LOGIN/PLAIN hitelesítéssel
- `admin/email_settings.php` – Admin SMTP konfiguráció oldal teszt e-mail funkcióval
- `api/email_settings.php` – SMTP beállítások mentése és teszt e-mail küldés API
- `admin/email_templates.php` – Többnyelvű (hu/en/sk) e-mail sablon szerkesztő, sandboxolt HTML preview, teszt küldés
- `api/email_templates.php` – E-mail sablon CRUD API (mentés, törlés, preview, teszt küldés)
- `system_settings` adatbázis tábla – kulcs–érték beállítás tároló, titkosítás jelöléssel
- `email_templates` adatbázis tábla – többnyelvű sablonok (template_key, lang, subject, body_html, body_text)
- `email_logs` adatbázis tábla – e-mail küldési napló (timestamp, template_key, to_email, result, error_message)

#### Jelszó visszaállítás (e-mail integráció)
- `password_reset.php` – Publikus 2-lépéses jelszó visszaállítás oldal
- `api/password_reset.php` – Token generálás és visszaállítás API
- `password_reset_tokens` adatbázis tábla – tokenek (SHA-256 hash, 1 órás lejárat, egyszeri használat)
- Rate limiting: max 3 kérés / e-mail / óra
- Enumeration védelem: az oldal mindig ugyanolyan választ ad
- Biztonsági naplózás: token kérés és felhasználás rögzítve

#### MFA – TOTP backup kódok
- `api/auth.php` – Hozzáadva: `verify_otp_code()`, `generate_backup_codes()`, `hash_backup_code()`, `verify_backup_code()`
- `api/otp_setup.php` – `verify` action most generál 10 db backup kódot és hash-elve tárolja
- `api/otp_setup.php` – Új `regenerate_backup_codes` action (jelszó megerősítéssel)
- `users.backup_codes` oszlop – JSON tömb SHA-256 hash-elt backup kódokkal
- `login.php` – Backup kód belépés fallback (egyszerhasználatos, törlődik felhasználás után)
- `login.php` – „Elfelejtett jelszó?" link hozzáadva a login form alá

#### Licensz kezelés és admin dashboard
- `admin/licenses.php` – Cég licensz kezelő oldal: lejárati figyelmeztetések, device limit, eszközlista, aktiválás/deaktiválás
- `api/licenses.php` – Licensz CRUD és eszköz aktiválás/deaktiválás API
- `company_licenses` adatbázis tábla – cég licenszek (valid_from, valid_until, device_limit, status)
- `kiosks.license_active` oszlop – slot foglalás jelző (0/1)
- `kiosks.activated_at` oszlop – első aktiválás időpontja

#### Admin navigáció
- `admin/header.php` – Új navigációs elemek: „Cég Licenszek", „Email Beállítások", „Email Sablonok"
- `module_licenses.php` label átnevezve „Modul Licenszek"-re a jobb differenciálás érdekében

#### Dokumentáció
- `docs/email.md` – SMTP konfiguráció, sablonok, jelszó visszaállítás dokumentáció
- `docs/mfa.md` – TOTP MFA beállítás, backup kódok, belépési folyamat
- `docs/licensing.md` – Licensz modell, device slot kezelés, audit napló
- `CHANGELOG.md` – Változásnapló

#### Adatbázis migráció
- `webserver/install/migrations/001_email_mfa_licensing.sql` – Minden fenti tábla/oszlop CREATE/ALTER parancsokkal

### Security

- SMTP jelszó AES-256-CBC titkosítással tárolva, soha nem kerül naplóba
- Password reset token: SHA-256 hash tárolva, plain text soha nem kerül DB-be
- MFA backup kódok SHA-256 hash-elve tárolva
- HTML sablon preview sandboxolt iframe-ben, kizárólag admin hozzáféréssel
- Összes új SQL query prepared statement-tel implementálva

### Fixed

- Installer hardening: az összes kritikus `apt-get` hívás TTY-függetlenre frissítve (`Dpkg::Use-Pty=0`, stdin redirection `< /dev/null`, non-interactive env)
- Tömeges (headless) telepítéseknél megszűnik az a hiba, ahol az install folyamat `apt-get ...` lépésnél `T (stopped)` állapotban megakadt
- Screenshot service fix: a kliens már a szerver `screenshot_enabled` flagjét is figyelembe veszi a `last_sync_response.json` fájlból (nem csak a `screenshot_requested` jelet), így a periodikus screenshot feltöltés valóban elindul bekapcsolt policy esetén
- Kiosk startup fix: a `kiosk-start.sh` már foreground módban futtatja a `startx`-et (`exec`), így X/Openbox összeomlás esetén a `systemd` megbízhatóan újraindítja a service-t
- Service orchestration fix: a structure/fallback telepítés most kötelezően tartalmazza az `edudisplej-command-executor.service` és `edudisplej-health.service` unitokat is
- Command executor stabilitás: javítva a sérült heredoc/funkció-blokk, és eltávolítva a nem létező `edudisplej-content.service` hivatkozások

---

## [2026 Q1] – Previous

### Added
- Unified device sync endpoint (`api/v1/device/sync.php`)
- Request signing (HMAC-SHA256) – `validate_request_signature()`
- TTL-based screenshot policy
- OTP/2FA alapinfrastruktúra (TOTP secret, QR kód, setup flow)
- Admin dashboard technikai nézettel (kiosks grouped by company)
- `company_licenses.module_licenses` – modul szintű licensz kezelés

### Fixed
- `get_module_file.php` – hitelesítés hozzáadva (korábban nyitott volt)
- `health/status.php` és `health/list.php` – hitelesítés hozzáadva
- `geolocation.php` – session hitelesítés hozzáadva

### Security
- API token query parameter (`?token=`) deprecálva, `X-EDU-Deprecation-Warning` header küldésével
- Nonce-alapú replay protection (`api_nonces` tábla)
