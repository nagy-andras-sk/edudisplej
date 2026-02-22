# Incidens leírás – Kiosk service nem indult automatikusan első boot után

**Dátum:** 2026-02-22  
**Érintett komponens:** telepítő folyamat (`install.sh`), systemd service bootstrap  
**Érintett service:** `edudisplej-kiosk.service`

---

## 1) Rövid összefoglaló

Több eszközös (fleet) telepítési szcenárióban előfordult, hogy első boot után a kijelzőn nem indult el a kiosk init/installer folyamat, ezért nem jöttek létre a várt telepítési logok (`/opt/edudisplej/session.log`, `/opt/edudisplej/apt.log`) sem.

A konkrét eszközön a service állapota:
- `edudisplej-kiosk.service` = **disabled + inactive**

Ezért a first-boot init script nem futott le automatikusan.

---

## 2) Tünetek

A következő parancsok nem adtak érdemi telepítési állapotot:
- `tail -f /opt/edudisplej/session.log` → fájl nem létezik
- `tail -f /opt/edudisplej/apt.log` → fájl nem létezik
- `cat /opt/edudisplej/data/install_status.json` → nincs progress adat

SSH-ból ellenőrizve:
- `systemctl status edudisplej-kiosk.service` → inactive (dead)
- `systemctl is-enabled edudisplej-kiosk.service` → disabled

---

## 3) Mi történt (timeline)

1. A telepítő lefutott, fájlokat letöltötte és kimásolta.  
2. A service telepítés közben `structure.json` parse hiba történt (service-list fallback aktiválódott).  
3. Fallback ágon a kiosk service fájl létrejött, de a kiosk service enable/start logika nem volt kötelezően, globálisan garantálva minden ágra.  
4. Az eszköz reboot után felállt, de a kiosk service nem indult, mert disabled maradt.  
5. Kézi beavatkozással (`systemctl enable --now edudisplej-kiosk.service`) a first-boot telepítés elindult, logok megjelentek.

---

## 4) Gyökérok (Root Cause)

A telepítő service-aktiválási logikája nem kényszerítette ki elég erősen, hogy a `edudisplej-kiosk.service` minden esetben **enabled** legyen a telepítés végén.

Kritikus pont:
- a fallback branch (amikor a structure alapú service-telepítés hibázik) mellett a kiosk enablement nem volt fleet-szinten hardenelve.

Következmény:
- első booton a kiosk init nem futott automatikusan,
- ezért nem volt telepítési progress kijelzés és nem keletkeztek a várt logok.

---

## 5) Javítás (Permanent Fix)

A telepítő módosítva lett úgy, hogy fleet telepítésben is determinisztikusan működjön:

1. A `edudisplej-kiosk.service` bekerült a kötelező core service listába.  
2. Core service pass során minden ilyen service explicit `enable` lépést kap.  
3. Külön hard safety check került a folyamat végére:
   - ha a kiosk service nem enabled, a telepítő force-enable-eli.

Ennek eredménye:
- a kiosk service nem maradhat csendben disabled állapotban,
- first bootkor automatikusan indul az init,
- progress/log fájlok várhatóan minden eszközön megjelennek.

---

## 6) Érintett fájlok

- `webserver/install/install.sh` (core service + safety enable fix)
- `docs/README.md` (ellenőrző parancs kiegészítve)

---

## 7) Operatív ellenőrzés rollout után

Minden új telepítésnél ellenőrizendő:

1. `systemctl is-enabled edudisplej-kiosk.service` → **enabled**  
2. `systemctl status edudisplej-kiosk.service` → first boot után aktív/futó állapot  
3. telepítés közben keletkezik:
   - `/opt/edudisplej/session.log`
   - `/opt/edudisplej/apt.log`
   - `/opt/edudisplej/data/install_status.json`

---

## 8) Megelőzés / Tanulságok

- Minden fallback útvonalat ugyanazzal a service-enable garanciával kell zárni.
- A telepítő végén kötelező, géppel ellenőrizhető post-condition kell:
  - kiosk service enabled állapot.
- Fleet rolloutnál javasolt automata smoke-check:
  - service enabled + first boot logfájlok léteznek.
