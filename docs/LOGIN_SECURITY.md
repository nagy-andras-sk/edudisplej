# Login Security

Ez a dokumentum az EduDisplej webes bejelentkezes vedelmet foglalja ossze a jelenlegi kod alapjan.

## 1. Jelszo tarolas

- A felhasznaloi jelszo nem plaintext formaban tarolodik.
- Jelszo letrehozasnal/modositasnal a rendszer `password_hash(..., PASSWORD_DEFAULT)` hasht keszit.
- Belepeskor `password_verify(...)` ellenorzi a beirt jelszot a tarolt hash ellen.

Kodhelyek:
- `webserver/control_edudisplej_sk/users.php`
- `webserver/control_edudisplej_sk/api/manage_users.php`
- `webserver/control_edudisplej_sk/admin.php`

Megjegyzes:
- `PASSWORD_DEFAULT` a futo PHP verzio alapertelmezett algoritmusat hasznalja (jelenleg tipikusan bcrypt), es kesobb valtozhat.

## 2. Password reset vedelem

- A reset link tokenje nem plaintextkent tarolodik adatbazisban.
- A token tarolt formaja: `hash('sha256', token)`.
- A token egyszer hasznalhato, es lejari ideje van.
- Uj jelszo beallitasakor uj hash keszul (`password_hash(..., PASSWORD_BCRYPT)`).

Kodhely:
- `webserver/control_edudisplej_sk/login.php`

## 3. Session es cookie biztonsag

Session cookie beallitasok:
- `HttpOnly = 1`
- `SameSite = Strict`
- `Secure = 1` csak HTTPS keresenel
- `session.use_only_cookies = 1`

Kodhely:
- `webserver/control_edudisplej_sk/security_config.php`

## 4. HTTPS szerepe

- A jelszo tavoli atvitele csak HTTPS mellett vedett a halozaton.
- A kodban van HTTPS felismeres es optionalis HTTPS kenyszerites (`force_https()`), de ennek alkalmazasa uzemeltetesi konfiguraciofuggo.

Kodhely:
- `webserver/control_edudisplej_sk/security_config.php`

## 5. Gyakorlati kovetkeztetes

- A tarolt jelszo hash-elt (helyes gyakorlat).
- A login/session oldalon alapveto vedelmek be vannak epitve.
- A legfontosabb uzemeltetesi kovetelmeny: login oldalak HTTPS kenyszeritese (reverse proxy/webserver szinten is).
