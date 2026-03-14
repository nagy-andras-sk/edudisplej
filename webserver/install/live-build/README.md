# EduDisplej beépítés Debian Live ISO-ba

Igen, beépíthető.

## Melyik ISO-t válaszd a listádból?

A te célodra (kiosk + saját wizard) a legjobb alap:

- `debian-live-13.3.0-amd64-standard.iso`

Indok: kisebb, kevesebb előtelepített GUI komponens, stabilabb saját célra testreszabni.

Ha mindenképp kell desktop UI a terepi kézi hibakezeléshez, második opció:

- `debian-live-13.3.0-amd64-xfce.iso`

---

## Ajánlott módszer (újraépítés live-build-del)

Ez a mappa (`webserver/install/live-build`) egy live-build sablon.

### 1) Build hoston telepítsd a csomagokat

```bash
sudo apt update
sudo apt install -y live-build debootstrap squashfs-tools xorriso
```

### 2) Lépj a mappába

```bash
cd webserver/install/live-build
```

### 3) Másold be a wizard fájlokat a live-build includes-ba

```bash
chmod +x prepare-live-build.sh
./prepare-live-build.sh
```

### 4) Live-build config futtatás

```bash
chmod +x auto/config
lb clean --purge
auto/config
```

### 5) ISO build

```bash
sudo lb build
```

Kimenet: az aktuális mappában létrejön egy `live-image-amd64.hybrid.iso` (vagy hasonló nevű) fájl.

---

## Windows + PowerShell (WSL2) módszer

Igen, Windowsról is működik, ha PowerShellből WSL2 Debianra delegálod a buildet.

### 0) Egyszeri előkészítés

- Telepíts WSL2-t: `wsl --install`
- Legyen egy Debian disztród (pl. `Debian` néven).

### 1) Futtasd a PowerShell build scriptet

A mappában:

```powershell
cd webserver/install/live-build
.\build-live-image-windows.ps1 -WslDistro Debian
```

Kimenet alapból ide kerül:

- `webserver/install/live-build/out`

### 2) Opcionális kapcsolók

Ha a WSL-ben már telepítve vannak a függőségek:

```powershell
.\build-live-image-windows.ps1 -WslDistro Debian -SkipDependencyInstall
```

Egyedi kimeneti mappa:

```powershell
.\build-live-image-windows.ps1 -WslDistro Debian -OutputDir "D:\\iso-out"
```

### Megjegyzés

Natív, tisztán Windowsos ISO remaster flow (squashfs + boot metadata) létezik, de megbízhatóságban gyengébb.
Gyártásra továbbra is a WSL2 + live-build ajánlott.

---

## Mi kerül bele automatikusan

- `/usr/local/bin/edudisplej-offline-installer.sh`
- `/etc/systemd/system/edudisplej-offline-installer.service`
- first-boot enable symlink (`multi-user.target.wants`)
- done flag törlése (`/opt/edudisplej/.offline_installer_done`)
- szükséges alapeszközök: `network-manager`, `whiptail`, `curl`, `ca-certificates`

---

## Első boot működés

A rendszer tty1-en elindítja az offline wizardot:
1. internetre csatlakozás ellenőrzése,
2. aktiváló kulcs bekérése,
3. online installer letöltés és futtatás.

Siker után:
- létrejön: `/opt/edudisplej/.offline_installer_done`
- a service letiltja magát.

---

## Ha mégis kész ISO-t remasterelnél

Megoldható, de törékenyebb (squashfs + boot metadata újraírás). Gyártásra jobb a fenti live-build út.
