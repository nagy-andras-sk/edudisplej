# Debian Live ISO + EduDisplej Offline Wizard - Részletes útmutató (Windows + PowerShell + WSL)

## 1. Cél és kontextus

Ez a dokumentum azt írja le részletesen, hogyan készíts előre testreszabott Debian Live ISO-t úgy, hogy
az első bootkor automatikusan elinduljon az EduDisplej offline telepítő varázsló.

A varázsló feladata:
1. internetkapcsolat ellenőrzése,
2. aktiváló kulcs bekérése,
3. online installer letöltése,
4. online installer futtatása tokennel.

A cél üzemi szempontból: gyártható, reprodukálható image flow, minimális helyszíni kézi lépéssel.

---

## 2. Miért így? (döntések és indoklás)

## 2.1 Miért nem natív Windows ISO remaster?

Lehetséges, de törékenyebb:
- squashfs kibontás/újracsomagolás,
- boot metadata (EFI/isolinux) konzisztencia,
- könnyebb hibázni és nehezebb reprodukálni.

## 2.2 Miért WSL2 + live-build?

Mert stabilabb és jól automatizálható:
- Debian natív toolchain fut (live-build),
- scriptelhető PowerShellből,
- könnyen CI-kompatibilis később.

## 2.3 Melyik Debian Live alapot érdemes választani?

Ajánlott: debian-live-13.3.0-amd64-standard.iso

Miért:
- kisebb méret,
- kevesebb felesleges desktop komponens,
- kioszk célra jobb testreszabhatóság.

Második opció: XFCE, ha kell helyszíni GUI hibakeresés.

---

## 3. Architektúra röviden

A beépített elemek:

- offline wizard script
  - /usr/local/bin/edudisplej-offline-installer.sh
- first-boot systemd service
  - /etc/systemd/system/edudisplej-offline-installer.service
- one-time flag
  - /opt/edudisplej/.offline_installer_done

Első boot folyamat:

1) systemd elindítja a first-boot service-t (tty1),
2) wizard ellenőrzi az internetet,
3) bekéri az aktiváló kulcsot,
4) letölti az online installert,
5) futtatja --token kapcsolóval,
6) siker esetén létrehozza a done flaget,
7) service letiltja magát.

---

## 4. Kapcsolódó fájlok a repositoryban

Fő wizard fájlok:
- webserver/install/offline-installer.sh
- webserver/install/edudisplej-offline-installer.service
- webserver/install/install_offline_installer_to_image.sh

Live-build fájlok:
- webserver/install/live-build/auto/config
- webserver/install/live-build/config/package-lists/edudisplej.list.chroot
- webserver/install/live-build/config/hooks/normal/200-enable-offline-installer.chroot
- webserver/install/live-build/prepare-live-build.sh
- webserver/install/live-build/build-live-image.sh
- webserver/install/live-build/build-live-image-wsl.sh
- webserver/install/live-build/build-live-image-windows.ps1

---

## 5. Előfeltételek (Windows oldalon)

Kötelező:
- Windows 11 (vagy modern Windows 10),
- WSL2,
- Debian WSL disztró,
- internetkapcsolat a buildhez,
- elegendő tárhely (javasolt minimum 20-30 GB szabad hely).

WSL telepítés (egyszer):
- PowerShell (Admin):
  - wsl --install

Ellenőrzés:
- wsl -l -q
- a listában szerepeljen például: Debian

---

## 6. Build folyamat Windowsról (ajánlott)

## 6.1 Lépj a live-build mappába

PowerShell:
- cd webserver/install/live-build

## 6.2 Indítsd a teljes buildet

PowerShell:
- .\build-live-image-windows.ps1 -WslDistro Debian

Mi történik a háttérben:
1) PowerShell WSL path-re konvertál,
2) meghívja a build-live-image-wsl.sh scriptet,
3) WSL-ben telepíti a build függőségeket (ha nincs kikapcsolva),
4) workspace copy -> clean build workspace,
5) prepare-live-build.sh bemásolja a wizard/service fájlokat az includes.chroot-ba,
6) lb clean --purge + auto/config + sudo lb build,
7) kész ISO visszamásolása Windows oldali out mappába.

Kimenet alapértelmezés szerint:
- webserver/install/live-build/out

## 6.3 Opcionális kapcsolók

Függőség telepítés kihagyása:
- .\build-live-image-windows.ps1 -WslDistro Debian -SkipDependencyInstall

Egyedi kimeneti mappa:
- .\build-live-image-windows.ps1 -WslDistro Debian -OutputDir D:\iso-out

---

## 7. Build folyamat Linux/WSL shellből közvetlenül

Ha közvetlen shellből futnál:

1) cd webserver/install/live-build
2) chmod +x prepare-live-build.sh build-live-image.sh auto/config
3) ./build-live-image.sh

---

## 8. Első boot és üzemeltetési viselkedés

## 8.1 Wizard képernyő

A wizard tty1-en indul, és:
- jelzi, ha nincs internet,
- retry opciót ad,
- nmtui opciót ad (ha elérhető),
- csak internet után lép tovább aktiváló kulcs kérésre.

## 8.2 Token és online installer

- A kulcsból token paraméter lesz,
- az online installer letöltése /tmp-be történik,
- futtatás: bash /tmp/edudisplej-online-install.sh --token=<token>

## 8.3 One-shot védelem

Sikeres futás után:
- /opt/edudisplej/.offline_installer_done létrejön,
- az edudisplej-offline-installer.service letiltja magát.

Így a wizard nem fut újra minden rebootnál.

---

## 9. Test checklist (ajánlott)

Build után javasolt ellenőrzés VM-ben:

1) ISO bootol,
2) wizard elindul tty1-en,
3) internet nélkül korrekt üzenet és retry opció,
4) internet után token bekérés,
5) online installer letöltődik,
6) telepítés lefut,
7) reboot után wizard már nem indul újra.

Hasznos diagnosztika:
- sudo journalctl -u edudisplej-offline-installer.service -b --no-pager

---

## 10. Gyakori hibák és megoldás

## 10.1 WSL disztró név hiba

Tünet:
- script nem találja a disztrót.

Megoldás:
- wsl -l -q listából pontos névvel add meg a -WslDistro paramétert.

## 10.2 Live-build csomag hiány

Tünet:
- lb parancs nem található.

Megoldás:
- ne használd a -SkipDependencyInstall kapcsolót,
  vagy telepítsd kézzel WSL-ben a csomagokat.

## 10.3 ISO nem jelenik meg output mappában

Tünet:
- build lefut, de nincs out mappában ISO.

Megoldás:
- nézd meg WSL oldalon a build workdir tartalmát,
- ellenőrizd, hogy lb build nem állt meg hibával,
- nézd át a WSL konzol kimenetet.

## 10.4 Wizard nem indul first bootkor

Tünet:
- nincs tty1 wizard.

Megoldás:
- service státusz ellenőrzés,
- done flag nincs-e már image-ben:
  /opt/edudisplej/.offline_installer_done

---

## 11. Biztonsági és üzemeltetési megfontolások

- Az aktiváló kulcsot ne hardcode-old image-be.
- A wizard csak első indításkor kérjen kulcsot.
- A beégetett online installer URL maradjon kontrollált domainen.
- Erősen ajánlott rendszeresen újra buildelni security patch-ekkel.

---

## 12. Karbantartás / verziózás

Ajánlott workflow:
1) wizard script módosítás,
2) service/hook ellenőrzés,
3) build script futtatás,
4) VM smoke test,
5) release artefact verziózása dátummal.

Javasolt ISO név mintázat:
- edudisplej-live-amd64-YYYYMMDD.iso

---

## 13. Rövid összefoglaló

Igen, Windowsról PowerShellből teljesen jól automatizálható a live ISO-ba építés,
ha a tényleges ISO buildet WSL2 Debianban futtatod.

Ez a jelenlegi megoldás:
- reprodukálható,
- first-boot varázslós,
- gyártási image folyamatra alkalmas.
