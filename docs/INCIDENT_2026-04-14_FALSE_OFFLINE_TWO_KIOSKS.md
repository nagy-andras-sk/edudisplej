# Incident Report - 2026-04-14

## Osszefoglalo

Két kijelző (10.153.162.7 és 10.153.162.8) fizikailag rendben működött (kép megjelent, kiosk futott), de a control panel oldalon offline státuszt mutatott.

A kivizsgálás alapján ez nem vizuális kijelzőhiba volt, hanem státusz-frissítési probléma: a panel a friss sync/heartbeat időbélyeg alapján számolja az online/offline állapotot.

## Érintett eszközök

- 10.153.162.7 (hostname: edudisplej-499C6D)
- 10.153.162.8 (hostname: edudisplej-886BEA)

## Tünetek

- A kijelzőkön tartalom látszott, ezért első ránézésre működőnek tűntek.
- A control panel offline-ként listázta őket.
- A synchez kötött szolgáltatások nem futottak stabilan.

## Technikai háttér (miért lehet ilyen?)

A dashboard az "effective status" logikát használja:

- 30 perc (1800 mp) frissességi timeout után offline lesz a státusz.
- A frissesség nem a képernyőn látott állapotból jön, hanem időbélyegekből (last_sync, last_seen, heartbeat stb.).
- Ha a sync szolgáltatás nem fut, a last_seen/last_sync nem frissül, így a panel offline-t mutat, még ha a kijelzőn tartalom fut is.

Releváns fájlok:

- webserver/control_edudisplej_sk/kiosk_status.php
- webserver/control_edudisplej_sk/admin/dashboard.php
- webserver/control_edudisplej_sk/api/v1/device/sync.php

## Megállapítások a kivizsgálás során

### 10.153.162.7

- edudisplej-sync.service: inactive
- edudisplej-health.service: failed
- edudisplej-command-executor.service: folyamatos restart loop

### 10.153.162.8

- edudisplej-sync.service: inactive
- edudisplej-health.service: időszakosan inactive
- edudisplej-command-executor.service: folyamatos restart loop

### Közös root cause

A command executor service az alábbi hibával folyamatosan elhasalt:

- Permission denied: /opt/edudisplej/localweb/offline_status.json

Közben a service user:

- User=edudisplej

Viszont a cél könyvtár root tulajdonban volt:

- /opt/edudisplej/localweb -> root:root

Ez jogosultsági konfliktust okozott.

## Feltételezett kiváltó ok

Nagy valószínűséggel egy update/restart folyamat közben service stop/start történt, és nem minden szolgáltatás állt vissza stabilan.

Az update script tartalmaz tömeges service stop/start lépést az összes edudisplej* service-re:

- webserver/install/init/update.sh

Ez önmagában nem hiba, de jogosultsági drift esetén könnyen tartós üzemi problémát eredményezhet.

## Elvégzett javítások (2026-04-14)

1. Két eszközön a kulcs service-ek kézi újraindítása:
   - edudisplej-sync.service
   - edudisplej-health.service
   - edudisplej-screenshot-service.service
   - edudisplej-command-executor.service

2. Jogosultság helyreállítása mindkét eszközön:
   - chown -R edudisplej:edudisplej /opt/edudisplej/localweb

3. Command executor újraindítás és ellenőrzés.

## Eredmény

A javítás után mindkét kioszkon:

- sync: active
- screenshot: active
- command-executor: active
- kiosk: active
- health: active vagy activating (rövid átmeneti állapot)

A control panel státusz visszaáll online állapotba a következő sync ciklusokban.

## Megelőző intézkedések

1. Post-update permission guard:
   - minden update végén kötelező jogosultság-helyreállítás a /opt/edudisplej/localweb útvonalon.

2. Service health check bővítése:
   - figyelmeztetés, ha sync service >10 percig nem active.

3. Command executor hardening:
   - write fallback / atomic write + hibatűrő működés, ne álljon le teljesen egy fájl-írási hibától.

4. Operátori runbook frissítés:
   - "kép van, de panel offline" gyorsdiagnosztika külön fejezet.

## Gyors ellenőrző parancsok (runbook)

```bash
hostname
systemctl is-active edudisplej-sync.service edudisplej-health.service edudisplej-screenshot-service.service edudisplej-command-executor.service edudisplej-kiosk.service
journalctl -u edudisplej-sync.service -n 50 --no-pager
journalctl -u edudisplej-command-executor.service -n 50 --no-pager
ls -ld /opt/edudisplej/localweb
```

## Incidens státusz

- Állapot: Stabilizálva
- Dátum: 2026-04-14
- Következő lépés: megelőző fixek beépítése update folyamatba és service script hardening
