# Boot Splash IP megjelenítés és Update zárolás

Ez a dokumentáció a boot splash IP megjelenítő és az update folyamat zárolás scriptek telepítését és használatát ismerteti.

## Szükséges csomagok

### Splash overlay-hez:
- **imagemagick** - a kép módosításához (`convert` parancs)
- **fbi** (framebuffer image viewer) vagy **plymouth** - a kép megjelenítéséhez
- **fonts-dejavu-core** - DejaVu fontok (vagy bármely más TTF font)

Telepítés Debian/Raspberry Pi OS alatt:
```bash
sudo apt update
sudo apt install imagemagick fbi fonts-dejavu-core
```

### Update zároláshoz:
- **util-linux** - a `flock` parancshoz (általában alapból telepítve van)

## Scriptek

### 1. `bin/overlay-splash.sh`
Generál egy PNG képet a meglévő logóból, és ráírja az aktuális IPv4 címet a jobb alsó sarokba.

**Használat:**
```bash
chmod +x bin/overlay-splash.sh
./bin/overlay-splash.sh
# Eredmény: /tmp/edudisplej_splash.png
```

**Megjelenítés fbi-vel:**
```bash
sudo fbi -T 1 -d /dev/fb0 --noverbose /tmp/edudisplej_splash.png
```

**Fontos beállítások a scriptben:**
- `LOGO_PATH` - a logó fájl elérési útja (alapértelmezett: `/opt/edudisplej/logo.png`)
- `FONT` - a használt font elérési útja
- `FONTSIZE` - betűméret

### 2. `bin/update-with-lock.sh`
Lockfile + PID ellenőrzés alapú megoldás, amely biztosítja, hogy egyszerre csak egy update folyamat fusson.

**Használat:**
```bash
chmod +x bin/update-with-lock.sh
./bin/update-with-lock.sh
```

### 3. `bin/update-with-flock.sh`
Alternatív megoldás `flock` használatával (util-linux csomag szükséges).

**Használat:**
```bash
chmod +x bin/update-with-flock.sh
./bin/update-with-flock.sh
```

## Systemd integráció

### Splash megjelenítés boot során

Hozd létre a `/etc/systemd/system/edudisplej-splash.service` fájlt:

```ini
[Unit]
Description=EduDisplej Boot Splash with IP
DefaultDependencies=no
After=local-fs.target
Before=basic.target

[Service]
Type=oneshot
ExecStart=/opt/edudisplej/bin/overlay-splash.sh
ExecStartPost=/usr/bin/fbi -T 1 -d /dev/fb0 --noverbose /tmp/edudisplej_splash.png
RemainAfterExit=yes

[Install]
WantedBy=sysinit.target
```

Engedélyezés:
```bash
sudo systemctl daemon-reload
sudo systemctl enable edudisplej-splash.service
```

### Update szolgáltatás (timer-rel)

Hozd létre a `/etc/systemd/system/edudisplej-update.service` fájlt:

```ini
[Unit]
Description=EduDisplej Auto Update
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/opt/edudisplej/bin/update-with-lock.sh
User=root

[Install]
WantedBy=multi-user.target
```

Timer (opcionális, napi frissítéshez) `/etc/systemd/system/edudisplej-update.timer`:

```ini
[Unit]
Description=EduDisplej Daily Update Timer

[Timer]
OnCalendar=daily
Persistent=true

[Install]
WantedBy=timers.target
```

Engedélyezés:
```bash
sudo systemctl daemon-reload
sudo systemctl enable edudisplej-update.timer
sudo systemctl start edudisplej-update.timer
```

## Tesztelés

### Splash overlay tesztelése:
```bash
sh bin/overlay-splash.sh
# Nyisd meg a /tmp/edudisplej_splash.png fájlt egy képnézegetővel
```

### Update zárolás tesztelése:
```bash
# Első terminálban:
sh bin/update-with-lock.sh &
sleep 1

# Második futtatás azonnal kilép:
sh bin/update-with-lock.sh
# Kimenet: "Update már fut (PID XXXX). Kilépés."
```

## Megjegyzések

- A `LOGO_PATH` változót módosítsd a tényleges logó elérési útjára
- Ellenőrizd, hogy a megadott font elérhető-e a rendszeren
- Plymouth használata esetén a plymouth API-t kell használni az fbi helyett
- A scriptek POSIX shell kompatibilisek (`#!/bin/sh`)
