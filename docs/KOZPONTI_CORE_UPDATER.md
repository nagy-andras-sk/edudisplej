# Kozponti Core Updater - Mukodesi Dokumentacio

## Celuja
Ez a dokumentum az EduDisplej kozponti core updater mukodeset irja le: hogyan lesz egy core valtozasbol kiadott verzio, hogyan indul el automatikusan a frissites a kioszkokon, es hogyan lehet admin oldalrol kenyszeritetten frissiteni.

## Rovid valasz: automatikusan frissul?
Igen.
A kioszk sync folyamat kozben megkapja a szerver altal hirdetett legujabb core verziot, osszehasonlitja a sajat verziojaval, es ha elteres van, automatikusan meghivja a core-only update folyamatot.

## 1. Kozponti verziokezeles

### 1.1 Forras
A kozponti celverzio a szerveren van tarolva:
- webserver/install/init/versions.json

Fontos mezok:
- system_version: aktualis cel core verzio
- core_checksum: a core fajlkeszlet lenyomata
- services: szolgaltatas specifikus verziok

### 1.2 Verzioszam emeles
A karbantartasi cron feladata, hogy a core fajlok valtozasat detektalja.
Ha a checksum valtozik, uj timestamp alapu system_version ertek kerul beallitasra.

Erintett komponensek:
- webserver/control_edudisplej_sk/cron/maintenance/run_maintenance.php
- webserver/control_edudisplej_sk/cron/maintenance/core_version_refresh.php

## 2. Automatikus updater folyamat (device oldal)

### 2.1 Sync valasz
A kioszk a sync API-bol olvassa ki a core update allapotot:
- webserver/control_edudisplej_sk/api/v1/device/sync.php

Lefontosabb mezok:
- core_update_required
- core_update.required
- core_update.target_version
- latest_system_version
- current_system_version

### 2.2 Trigger
Amikor core_update_required true, a kioszk a sync service-ben meghivja:
- /opt/edudisplej/init/update.sh --core-only --source=auto-sync --target-version=<latest>

A trigger logika helye:
- /opt/edudisplej/init/edudisplej_sync_service.sh

### 2.3 Vedelmi mechanizmusok
A tul gyakori vagy parhuzamos futas ellen vedelmek vannak:
- lock: /tmp/edudisplej_core_update.lock
- utolso probalkozas marker: /tmp/edudisplej_core_update.last_attempt
- retry cooldown: EDUDISPLEJ_CORE_UPDATE_RETRY_COOLDOWN (alapertelmezett: 1800 mp)

## 3. Kenyszeritett (manualis) updater

Az admin feluleten a core update gomb queue parancsot ad ki, amely core_update tipusu utasitast tesz a command queue-ba.

Erintett pontok:
- webserver/control_edudisplej_sk/admin/dashboard.php
- webserver/control_edudisplej_sk/api/kiosk/queue_core_update.php

A command executor ezt vegrehajtja, es ugyanugy core-only update fut.

## 4. Mit telepit a core updater

A core updater a structure alapjan telepit fajlokat es unitokat:
- webserver/install/init/structure.json

A folyamatban kiemelt szerepu script:
- webserver/install/init/update.sh

A jelenlegi globalis stabilitasi kiegeszitesek resze:
- edudisplej-self-heat.sh
- edudisplej-self-heat.service
- edudisplej-self-heat.timer

## 5. Sikeres frissites feltetelei

A sikeres frissites utan:
- /opt/edudisplej/VERSION egyezik a versions.json system_version ertekevel
- core_update_required hamis
- kritikus szolgaltatasok aktivak (sync, health, command-executor, watchdog, kiosk)
- self-heat timer aktiv es kovetkezo triggerrel rendelkezik

## 6. Gyors ellenorzes kioszkon

Javasolt ellenorzo parancsok:

cat /opt/edudisplej/VERSION
cat /opt/edudisplej/last_sync_response.json | jq '{core_update_required, latest_system_version, current_system_version, needs_update}'
systemctl is-active edudisplej-sync.service
systemctl is-active edudisplej-health.service
systemctl is-active edudisplej-command-executor.service
systemctl is-active edudisplej-kiosk.service
systemctl is-active edudisplej-watchdog.service
systemctl is-enabled edudisplej-self-heat.timer
systemctl is-active edudisplej-self-heat.timer
systemctl list-timers edudisplej-self-heat.timer

## 7. Javasolt rollout strategia

1. Pilot: 2-3 kioszk
2. Megfigyeles: 15-30 perc
3. Hullamos rollout: telephelyenkent vagy csoportonkent
4. Utoellenorzes: VERSION + service status + timer trigger

## 8. Hibaesetek roviden

Tipikus tunetek:
- kijelzo aktiv, backend szerint offline
- core_update_required tartosan true
- health/service komponensek leallnak update utan

Tipikus okok:
- jogosultsagi hiba a health status fajlon
- update stop/start sorrendben hianyzo visszaallitas
- timer nincs rendesen enable/start allapotban

Kapcsolodo incidens:
- INCIDENT_2026-04-17_OFFLINE_ACTIVE_DISPLAY_SELF_HEAT.md

## 9. Kapcsolodo dokumentumok

- CORE_UPDATE_AND_VERSIONS.md
- INCIDENT_2026-04-12_SYNC_OFFLINE_AFTER_DAILY_UPDATE.md
- INCIDENT_2026-04-17_OFFLINE_ACTIVE_DISPLAY_SELF_HEAT.md
