# Terminal Test Mode

Az EduDisplej most **terminal teszt módban** fut.

## Mit látsz a képernyőn?

Egy **fekete hátterű, zöld szöveges xterm terminál** kellene megjelenjen:

```
===================================
   EduDisplej Terminal Test
===================================

X Display: :0
User: edudisplej
Home: /home/edudisplej

This terminal should be VISIBLE on screen!

Logs:
  - Openbox: /tmp/openbox-autostart.log
  - Watchdog: /tmp/edudisplej-watchdog.log

Press Ctrl+C to exit, or this will stay open.
```

## Ellenőrzés SSH-ról

```bash
# 1. Watchdog státusz
tail -20 /tmp/edudisplej-watchdog.log

# Várt eredmény:
# ✓ X Server running
# ✓ Openbox running
# ✓ xterm running
# ⊘ Browser check disabled (terminal test mode)

# 2. Openbox log
cat /tmp/openbox-autostart.log

# 3. xterm folyamat
ps aux | grep xterm
```

## Böngésző hozzáadása később

Ha a terminál már látszik, szerkeszd:
```bash
nano /home/edudisplej/kiosk-launcher.sh
```

Adj hozzá a végére:
```bash
# Launch Chromium
chromium-browser --kiosk --no-sandbox "https://www.time.is" &
```

## Hibaelhárítás

### Ha továbbra sem látszik a terminál:

1. **Ellenőrizd az X felbontást:**
```bash
DISPLAY=:0 xrandr
```

2. **Próbáld manuálisan:**
```bash
DISPLAY=:0 xterm -bg red -fg white -geometry 80x24+100+100 &
```

3. **Ellenőrizd az ablakokat:**
```bash
DISPLAY=:0 xdotool search --class xterm
```

4. **OpenBox config:**
```bash
cat ~/.config/openbox/rc.xml
```

## Változtatások összefoglalása

### Mi változott:

1. **Új csomagok telepítve:**
   - `x11-xserver-utils` - tartalmazza az `xset` parancsot
   - `python3-xdg` - OpenBox XDG autostart-hoz szükséges

2. **Openbox autostart frissítve:**
   - Védőmechanizmus hozzáadva (ellenőrzi hogy xset létezik-e)
   - xterm explicit `-display :0` paraméterrel indul
   - `-geometry 120x36+50+50` - pozíció és méret megadva
   - `-bg black -fg green` - látható színek
   - Teszt üzenet megjelenítése a terminálon
   - `exec bash` - terminál nyitva marad

3. **kiosk-launcher.sh egyszerűsítve:**
   - **Böngésző indítás ELTÁVOLÍTVA**
   - Csak teszt üzenet megjelenítése
   - Terminál nyitva marad

4. **Watchdog frissítve:**
   - Böngésző ellenőrzés kikapcsolva terminal test módban
   - Nem jelentett hibát hiányzó böngésző miatt

## Sikerkritérium

**SIKER = A képernyőn látható egy zöld szöveges terminál fekete háttéren, üzenettel.**

Ha ez működik, UTÁNA adjuk hozzá a böngészőt a `kiosk-launcher.sh` fájlhoz.
