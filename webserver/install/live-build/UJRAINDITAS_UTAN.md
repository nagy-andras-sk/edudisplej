# EduDisplej ISO készítése - Lépések újraindítás után

## 1. Nyiss egy PowerShell ablakot (Admin jogokkal)

## 2. Navigálj a live-build mappába:
```powershell
cd C:\web\SynologyDrive\edudisplejsk\edudisplej\webserver\install\live-build
```

## 3. Debian első indítása és konfigurálása
```powershell
wsl -d Debian
```

Amikor először elindul, bekéri a felhasználónevet és jelszót:
- Válassz egy felhasználónevet (pl: `admin`)
- Adj meg egy jelszót (pl: `admin123`)

Ezután lépj ki:
```bash
exit
```

## 4. Futtasd az ISO build scriptet:
```powershell
.\build-live-image-windows.ps1 -WslDistro Debian
```

## 5. Várd meg, amíg elkészül (5-15 perc)

A script:
- Telepíti a szükséges csomagokat (live-build, debootstrap, stb.)
- Átmásolja a wizard fájlokat
- Elkészíti a bootolható ISO-t

## 6. A kész ISO helye:
```
C:\web\SynologyDrive\edudisplejsk\edudisplej\webserver\install\live-build\out\
```

Fájlnév: `live-image-amd64.hybrid.iso`

---

## Hibaelhárítás

Ha a build közben hiba lenne, próbáld újra tiszta build-del:
```powershell
.\build-live-image-windows.ps1 -WslDistro Debian
```

Ha a Debian nem található:
```powershell
wsl --list --verbose
wsl --install -d Debian
```
