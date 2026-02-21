# EduDisplej Raspberry Service Overview

Ez a dokumentum a Raspberry-n futó EduDisplej service-eket írja le:
- melyik service mit csinál,
- milyen sorrendben indulnak,
- milyen ciklusidőkkel dolgoznak,
- és hogyan hatnak egymásra.

---

## 1) Gyors összefoglaló (egy oldalas)

| Service | Fő feladat | Ciklus / időzítés | Kritikus fájlok |
|---|---|---|---|
| `edudisplej-kiosk.service` | X + Openbox + kijelző UI indítás | folyamatos (`Restart=always`, `RestartSec=10`) | `/opt/edudisplej/init/kiosk-start.sh` |
| `edudisplej-sync.service` | regisztráció, szerver sync, loop/modul frissítés | alap: 300s, fast loop: 30s | `/opt/edudisplej/init/edudisplej_sync_service.sh` |
| `edudisplej-health.service` | health report (CPU/mem/disk/net/service állapot) | alap: 300s, fast loop: 5s | `/opt/edudisplej/init/edudisplej-health.sh` |
| `edudisplej-screenshot-service.service` | külön screenshot capture+upload | alap: 15s (szerver felülírhatja) | `/opt/edudisplej/init/edudisplej-screenshot-service.sh` |
| `edudisplej-command-executor.service` | távoli parancsok lehúzása és végrehajtása | 30s polling | `/opt/edudisplej/init/edudisplej-command-executor.sh` |
| `edudisplej-watchdog.service` | X környezet figyelése | 10s | `/opt/edudisplej/init/edudisplej-watchdog.sh` |
| `edudisplej-terminal.service` | tty2 status dashboard | 10s refresh (5s késleltetett indulás) | `/opt/edudisplej/init/edudisplej_terminal_display.sh` |

---

## 2) Időrendi indulási sorrend (boot után)

## T0 – rendszer boot
1. `edudisplej-kiosk.service` indul (display stack):
   - futtatja a `kiosk-start.sh` scriptet,
   - első boot esetén meghívja az `edudisplej-init.sh` inicializálást,
   - ezután `startx -- :0 vt1`, Openbox elindul.

2. Openbox autostart meghívja az `edudisplej_terminal_script.sh` scriptet:
   - vár a regisztrációra,
   - letölti a modulokat (`edudisplej-download-modules.sh`),
   - elindítja a böngészőt a `loop_player.html`-lel.

## T0+ – háttér service-ek
3. `edudisplej-sync.service` indul:
   - regisztrál/szinkronizál,
   - ellenőrzi loop verziót,
   - szükség esetén forced refresh (törlés → új letöltés → kiosk restart),
   - frissíti status/state fájlokat.

4. `edudisplej-health.service` indul:
   - rendszer és service health összesítés,
   - health report küldés API-ra.

5. `edudisplej-screenshot-service.service` indul:
   - screenshot igény/policy alapján periodikusan képet küld.

6. `edudisplej-command-executor.service` indul:
   - adminból küldött parancsokat pollingolja és végrehajtja.

7. `edudisplej-watchdog.service` és `edudisplej-terminal.service`:
   - watchdog monitorozza az X stack-et,
   - tty2-n folyamatos status panel látszik.

---

## 3) Service-ek részletesen

## 3.1 `edudisplej-kiosk.service`
**Cél:** kijelző felület (X + Openbox) felhúzása.

**Lényeg:**
- User: `edudisplej`
- `ExecStart=/opt/edudisplej/init/kiosk-start.sh`
- `Restart=always`, `RestartSec=10`
- Kényszeríti a tty1-es kioszk futást.

**Script flow (`kiosk-start.sh`):**
1. Első booton futtatja az `edudisplej-init.sh` setupot.
2. Régi Xorg példányok takarítása.
3. `startx` indítás `:0`-án.
4. Végtelen keepalive loop (`sleep 60`).

---

## 3.2 `edudisplej-sync.service`
**Cél:** ez a központi “agy” a szerver-szinkronhoz és loop frissítéshez.

**Lényeg:**
- User: `root`
- `ExecStart=/opt/edudisplej/init/edudisplej_sync_service.sh start`
- `Restart=always`, `RestartSec=30`
- Alap ciklus: `SYNC_INTERVAL=300s`
- Fast loop: `FAST_LOOP_INTERVAL=30s` (ha `/opt/edudisplej/.fast_loop_enabled` létezik)

**Egy ciklusban mit csinál:**
1. Regisztráció/sync API hívás.
2. HW + technikai adat (verzió, felbontás stb.) küldés.
3. Loop verzió összehasonlítás a szerverrel.
4. Ha update kell: **forced full refresh**:
   - lokális modules törlés,
   - teljes új letöltés (`edudisplej-download-modules.sh`),
   - `edudisplej-kiosk.service` restart.
5. Timestamp/state/status/log feltöltés.
6. Ciklus végén sleep (300s vagy 30s).

**Fontos fájlok:**
- `/opt/edudisplej/sync_status.json`
- `/opt/edudisplej/sync_state.json`
- `/opt/edudisplej/last_sync_response.json`

---

## 3.3 `edudisplej-health.service`
**Cél:** telemetria + health státusz küldése control panel felé.

**Lényeg:**
- User: `pi`
- `Restart=always`, `RestartSec=10`
- Alap ciklus: `HEALTH_CHECK_INTERVAL=300s`
- Fast loop esetén: `5s`

**Mérési kör:**
- CPU temp/usage, memória, disk, uptime,
- hálózat (internet/API/wifi/signal),
- service státusz,
- konfiguráció és sync állapot.

**Flow:**
1. JSON health összerakása (`health_status.json`).
2. Küldés API-ra (`/api/health/report.php`).
3. Sleep (300s vagy fast 5s), majd ismétlés.

---

## 3.4 `edudisplej-screenshot-service.service`
**Cél:** képernyőkép készítés/feltöltés külön service-ben.

**Lényeg:**
- User: `edudisplej`
- Alap ciklus: `SCREENSHOT_INTERVAL=15s`
- Szerver policy felülírhatja (`last_sync_response.json` alapján).

**Flow ciklusonként:**
1. Megnézi, kell-e screenshot (`screenshot_requested` vagy local flag).
2. Kép készítés (`scrot`).
3. Base64 upload API-ra.
4. Sleep policy szerinti ideig.

**Megjegyzés:** ha screenshot mód `sync`, akkor a sync service is tud screenshotot kezelni.

---

## 3.5 `edudisplej-command-executor.service`
**Cél:** távoli admin parancsok végrehajtása.

**Lényeg:**
- User: `pi`
- Ciklus: 30s polling

**Parancstípus példák:**
- reboot,
- fast loop be/ki,
- full update,
- service restart,
- custom shell command (timeout-tal).

**Flow:**
1. Pending commandok lekérése API-ról.
2. Végrehajtás lokálisan.
3. Eredmény visszaküldése API-ra.
4. Sleep 30s.

---

## 3.6 `edudisplej-watchdog.service`
**Cél:** X környezet felügyelet.

**Lényeg:**
- User: `root`
- Ciklus: 10s
- Ellenőrzi: Xorg, openbox, xterm (browser check jelenleg letiltott)

**Output:**
- `/tmp/edudisplej-watchdog.log`.

---

## 3.7 `edudisplej-terminal.service`
**Cél:** tty2-re kirakott élő státusz kijelzés.

**Lényeg:**
- User: `root`
- 5s indulási késleltetés, majd 10s refresh
- `sync_status.json` alapján rajzol terminal dashboardot.

---

## 4) Kapcsolatok a service-ek között

- `sync` frissíti a `last_sync_response.json`-t → ezt olvassa a `screenshot-service` policy-hoz.
- `sync` update esetén restartolja a `kiosk` service-t.
- `health` olvassa a sync/service/network állapotot, és továbbjelenti.
- `command-executor` tud fast-loop flaget kapcsolni, service-t restartolni, full update-et indítani.

---

## 5) Ciklusidők összefoglalva

- Sync: **300s** (fast: **30s**)
- Health: **300s** (fast: **5s**)
- Screenshot service: **15s** (policy szerint változhat)
- Command executor: **30s**
- Watchdog: **10s**
- Terminal display: **10s**

---

## 6) Gyors ellenőrző parancsok (üzemeltetéshez)

```bash
systemctl status edudisplej-kiosk.service --no-pager -l
systemctl status edudisplej-sync.service --no-pager -l
systemctl status edudisplej-health.service --no-pager -l
systemctl status edudisplej-screenshot-service.service --no-pager -l
systemctl status edudisplej-command-executor.service --no-pager -l
systemctl status edudisplej-watchdog.service --no-pager -l
```

Logok:
```bash
tail -n 200 /opt/edudisplej/logs/sync.log
tail -n 200 /opt/edudisplej/logs/health.log
tail -n 200 /opt/edudisplej/logs/command_executor.log
tail -n 200 /tmp/edudisplej-watchdog.log
```

Állapotfájlok:
```bash
cat /opt/edudisplej/sync_status.json
cat /opt/edudisplej/sync_state.json
cat /opt/edudisplej/health_status.json
cat /opt/edudisplej/last_sync_response.json
```

---

## 7) Nyomtatási javaslat

Nyomtatáshoz (A4):
- 11pt betű,
- táblázatok megtartása,
- oldaltörés a 3. fejezet előtt.

Így egy rövid üzemeltetői “cheat-sheet” + részletes szekció együtt olvasható marad.
