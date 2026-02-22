# Felhasználó létrehozási incidens – automatikus admin jog

**Dátum:** 2026-02-22  
**Érintett oldal:** `webserver/control_edudisplej_sk/admin/users.php`  
**Súlyosság:** Kritikus (jogosultság-eszkaláció)

---

## Mi történt?

Az admin felületen új felhasználó létrehozásakor a rendszer hibásan **admin jogosultságot adott** akkor is, ha a felületen a „Nem” opció volt kiválasztva.

Ezzel párhuzamosan a 2FA kötelező mező is hibásan viselkedett: a választástól függetlenül aktívnak számított.

---

## Hogyan keletkezett a hiba?

A backend a `<select>` mezőket (`isadmin`, `require_otp`) így értelmezte:

- `isset($_POST['isadmin']) ? 1 : 0`
- `isset($_POST['require_otp']) ? 1 : 0`

Ez a logika checkboxnál működhet, de `<select>` mezőnél nem megfelelő, mert a mező POST-ban akkor is jelen van, ha az értéke `0`.

Következmény:

- `isadmin` mindig `1` lett (admin jog)
- `require_otp` mindig `1` lett (2FA kötelező)

---

## Hatás

- **Jogosultsági szint sérülése:** normál felhasználók admin joggal jöttek létre.
- **Biztonsági kockázat:** a hozzáférés-kezelés megbízhatósága sérült.
- **Működési anomália:** 2FA beállítás felhasználói választástól függetlenül kötelezővé vált.

---

## Javítás

A mezők feldolgozása `isset(...)` alapúról explicit értékellenőrzésre lett cserélve.

### Régi (hibás) logika

```php
$isadmin = isset($_POST['isadmin']) ? 1 : 0;
$require_otp = isset($_POST['require_otp']) ? 1 : 0;
```

### Új (helyes) logika

```php
$isadmin = isset($_POST['isadmin']) && (string)$_POST['isadmin'] === '1' ? 1 : 0;
$require_otp = isset($_POST['require_otp']) && (string)$_POST['require_otp'] === '1' ? 1 : 0;
```

Ugyanez a javítás bekerült a felhasználó szerkesztési ágba is az `isadmin` kezelésére.

---

## Root cause összefoglaló

- A frontend mezőtípus (`select`) és a backend validációs minta (`isset` checkbox-logika) nem volt összhangban.
- Nem történt explicit `0/1` érték-kikényszerítés a jogosultság mezőknél.

---

## Megelőző intézkedések

1. Minden jogosultsági mezőn explicit értékellenőrzés (`=== '1'`) használata.
2. Űrlapfeldolgozásnál mezőtípus-specifikus minta használata (checkbox vs select).
3. Kódreview checklist kiegészítése: „`isset` nem használható önmagában `select` bool mezőre”.
4. Regressziós teszteset felvétele:
   - `isadmin=0` → létrejött user ne legyen admin
   - `isadmin=1` → létrejött user legyen admin
   - `require_otp=0/1` megfelelően mentődjön

---

## Állapot

✅ A hiba javítva az érintett kódrészben.  
✅ Szerkesztői hibavizsgálat az érintett fájlon rendben.
