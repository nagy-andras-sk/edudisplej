# EduDisplej Offline Installer (Debian Image Guide)

Ez a flow arra készült, hogy előre elkészített Debian image-et tudj gyártani, ami első bootkor varázslóval telepít.

## Mit csinál az offline installer

A wizard indítás után:
1. internetkapcsolatot kér és ellenőriz,
2. elkéri az aktiváló kulcsot,
3. letölti az online installert,
4. lefuttatja az online installert a kulccsal.

Sikeres első telepítés után létrehoz egy flag fájlt, és letiltja az offline first-boot service-t.

---

## Fájlok

- script: `webserver/install/offline-installer.sh`
- service: `webserver/install/edudisplej-offline-installer.service`
- image deploy helper: `webserver/install/install_offline_installer_to_image.sh`

---

## A) Gyors kézi futtatás (teszt)

Debian gépen rootként:

```bash
sudo install -m 0755 webserver/install/offline-installer.sh /usr/local/bin/edudisplej-offline-installer.sh
sudo /usr/local/bin/edudisplej-offline-installer.sh
```

---

## B) Előkészített image first-boot mód

### 1. Másold be a fájlokat az image-be

A target rendszerben/chrootban:

```bash
sudo install -m 0755 webserver/install/offline-installer.sh /usr/local/bin/edudisplej-offline-installer.sh
sudo install -m 0644 webserver/install/edudisplej-offline-installer.service /etc/systemd/system/edudisplej-offline-installer.service
```

### 2. Engedélyezd a service-t

```bash
sudo systemctl daemon-reload
sudo systemctl enable edudisplej-offline-installer.service
```

### 3. Biztosítsd, hogy újra fusson az image-ben

```bash
sudo rm -f /opt/edudisplej/.offline_installer_done
```

### 4. (Opcionális) online installer URL override

Ha nem default URL-ről akarsz tölteni:

```bash
sudo systemctl edit edudisplej-offline-installer.service
```

Majd:

```ini
[Service]
Environment=EDUDISPLEJ_ONLINE_INSTALLER_URL=https://install.edudisplej.sk/install.sh
```

---

## Varázsló működés röviden

- Ha nincs internet: retry / `nmtui` / quit opciókat ad.
- Ha van internet: aktiváló kulcs bekérése.
- Letöltés: `/tmp/edudisplej-online-install.sh`
- Futtatás: `bash /tmp/edudisplej-online-install.sh --token=<kulcs>`

Első-boot módban siker után:
- létrejön: `/opt/edudisplej/.offline_installer_done`
- service letiltás: `edudisplej-offline-installer.service`

---

## Hibakeresés

```bash
sudo journalctl -u edudisplej-offline-installer.service -b --no-pager
```

Kézi újraindítás:

```bash
sudo rm -f /opt/edudisplej/.offline_installer_done
sudo systemctl restart edudisplej-offline-installer.service
```
