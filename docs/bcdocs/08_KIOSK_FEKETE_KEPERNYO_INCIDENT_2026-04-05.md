# 08 – Kiosk fekete képernyő incidens (2026-04-05)

## 1) Rövid helyzetkép

Egyetlen prototípus kijelzőn a következő tünet jelent meg:
- fekete képernyő,
- látható egérkurzor,
- tényleges tartalom nem töltődött be.

Érintett eszköz:
- host/IP: `192.168.37.170`
- SSH felhasználó: `edudisplej`

A felhasználói megfigyelés pontos volt: „nem indul el semmi, csak a kurzor és fekete képernyő”.

---

## 2) Mire lettünk figyelmesek először

A fekete háttér + kurzor mintázat tipikusan azt jelzi, hogy:
1. a grafikus stack (X + WM) legalább részben fut,
2. de az alkalmazásréteg (kiosk böngésző/loop player) nem fut vagy korán kilép.

Ezért a nyomozást nem hálózati irányból kezdtük, hanem service és folyamat oldalon.

---

## 3) Nyomozási stratégia és első lépések

### 3.1 Alap állapotellenőrzés

Első körben ezt vizsgáltuk:
- rendszer futási állapot,
- failed unitok,
- kiosk service állapot,
- boot errorok.

Megfigyelés:
- `edudisplej-kiosk.service` aktív volt,
- Xorg és Openbox processzek jelen voltak,
- viszont böngészőfolyamat nem látszott.

Következtetés:
- a kijelző „váz” él, de a tartalmat megjelenítő folyamat hiányzik.

### 3.2 Célzott log- és processzvizsgálat

Elemzett források:
- `/tmp/kiosk-startup.log`
- `/tmp/openbox-autostart.log`
- `/opt/edudisplej/logs/terminal_script.log`
- `journalctl -u edudisplej-kiosk.service`
- `ps -ef` folyamatlista

Megfigyelés:
- `kiosk-start.sh` rendben elindította az X-et és Openboxot,
- Openbox autostart elindította az xterm-et és a terminal scriptet,
- a terminal script rendszeresen itt állt meg:
  - `Module download failed with exit code: 1`
- ebből a script korábban `exit 1`-gyel kilépett,
- emiatt a böngészőindítás (surf) sok esetben nem történt meg.

Fő következtetés:
- gyökérok: module letöltési hiba + hiányzó működő fallback ág.
- eredmény: UI folyamat hiánya -> fekete képernyő + kurzor.

---

## 4) Miért volt ez különösen alattomos hiba

A service „zöld” (running) állapota félrevezető volt.

A `edudisplej-kiosk.service` attól még futott, hogy:
- az X szerver ment,
- az Openbox ment,
- de a tényleges tartalom-folyamat nem ment stabilan.

Tehát a rendszer részben „életben” volt, de funkcionálisan mégsem szolgáltatott képet.

---

## 5) Mit módosítottunk és miért

## 5.1 Terminal script hibatűrés javítása

Fájl:
- `webserver/install/init/edudisplej_terminal_script.sh`

Módosítások:
1. module letöltési hiba többé nem azonnali végállapot (`exit 1`),
2. hiba után a rendszer folytatja az indulást a legutóbbi lokális tartalommal,
3. bevezettünk recovery oldalt arra az esetre, ha a loop player fájl hiányozna,
4. részletesebb logpontok kerültek be a fallback ághoz.

Miért:
- ne maradjon fekete képernyő csak azért, mert egy frissítés/letöltés megbukik,
- a „last known good” tartalom megjelenítése jobb, mint teljes UI kiesés,
- incidens esetén a log egyértelműen mutassa, hogy fallback útvonalon fut a rendszer.

## 5.2 Watchdog aktív önjavítás

Fájl:
- `webserver/install/init/edudisplej-watchdog.sh`

Módosítások:
1. a watchdog már nem csak passzívan logol,
2. figyeli az X/Openbox és UI (surf/xterm) folyamatokat,
3. több egymást követő hiány esetén restartolja a `edudisplej-kiosk.service`-t,
4. restart cooldown és startup grace került be a restart-loop elkerülésére.

Miért:
- átmeneti vagy versenyhelyzetes indulási hibákból automatikusan fel tudjon állni,
- ne legyen szükség minden alkalommal kézi SSH beavatkozásra,
- a helyreállítás ne legyen agresszív (cooldown + grace), hogy stabil maradjon.

---

## 6) Mit láttunk a javítás után

Ellenőrzési pontok:
- `edudisplej-kiosk.service`: active
- `edudisplej-watchdog.service`: active
- UI folyamatok (`surf`/`xterm`) újra láthatók
- terminal logban új milestone:
  - `Module download failed with exit code: 1`
  - `Starting Step 3: Launch browser`
  - `Download failed, launching browser with last known local content`
  - `Launching: surf -F file:///opt/edudisplej/localweb/loop_player.html`

Következtetés:
- ugyan a letöltési probléma továbbra is előfordulhat,
- de a kijelző többé nem marad tartósan fekete,
- a rendszer automatikusan degradált, de működő állapotba áll.

---

## 7) Root cause összefoglaló

Elsődleges ok:
- a module letöltés hibázott,
- a startup script ezt végzetes hibának kezelte,
- nem volt megbízható „mindig legyen látható tartalom” fallback.

Másodlagos ok:
- a watchdog eredetileg csak riportolt, nem gyógyított.

---

## 8) Megelőzési és üzemeltetési tanulságok

1. A kiosk UI indulását külön health-kritériummal kell mérni (nem elég a service running státusz).
2. A tartalomfrissítés hibája ne blokkolja a megjelenítést.
3. Az önjavító restart logikát küszöbökkel és cooldownnal kell védeni.
4. Incidensnél a legjobb triázs sorrend:
   - processzek,
   - kiosk/autostart log,
   - terminal script log,
   - service journal.

---

## 9) Rövid timeline

- T0: tünetjelzés (fekete képernyő + kurzor)
- T0 + néhány perc: service/processz triázs, böngésző hiányának azonosítása
- T0 + ~15 perc: logokból module download fail + korai kilépés bizonyítása
- T0 + ~30 perc: fallback + self-heal módosítások elkészítése
- T0 + ~45 perc: prototípusra hotfix telepítés és service restart
- T0 + ~60 perc: validáció: fallback ágon böngésző indul, watchdog stabil

---

## 10) Érintett fájlok

- `webserver/install/init/edudisplej_terminal_script.sh`
- `webserver/install/init/edudisplej-watchdog.sh`
- (dokumentáció) `docs/bcdocs/08_KIOSK_FEKETE_KEPERNYO_INCIDENT_2026-04-05.md`

---

## 11) Nyitott technikai következő lépés

A mostani javítás a „fekete képernyő” tünetet megszüntette, de a module letöltési hiba gyökérokát külön vizsgálni kell (API elérés, timeoutok, endpoint válasz, vagy payload validációs hiba).

Javasolt külön technikai bontás:
- `edudisplej-download-modules.sh` hibapontok (exit code branch-ek),
- hálózati / DNS / TLS környezet,
- szerver oldali API log korreláció időbélyeggel.
