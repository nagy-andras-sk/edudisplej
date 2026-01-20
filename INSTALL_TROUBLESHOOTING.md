# Riešenie Problémov pri Inštalácii / Installation Troubleshooting

## Problém: Loader Zamrzne počas Sťahovania / Loader Freezes During Download

### Symptómy / Symptoms:
- Inštalačný skript sa zdá zamrznutý
- Žiadny výstup na obrazovke dlhú dobu
- Sťahovanie súborov sa "zasekne"

### Riešenie / Solution:

**V novej verzii (po oprave):**
- Každý sťahovaný súbor teraz zobrazuje:
  ```
  [1/10] (10%) Stahovanie: common.sh
      Velkost: 15234 bajtov
      [OK] Stiahnuty uspesne
  ```
- Ak sťahovanie trvá dlho, uvidíte bodky: `...` (heartbeat)
- Automatické časové limity:
  - Pripojenie: max 10 sekúnd
  - Sťahovanie zoznamu: max 30 sekúnd
  - Sťahovanie súboru: max 60 sekúnd
- Automatický opakovaný pokus pri zlyhaní

**Ak problém pretrváva:**

1. **Skontrolujte internetové pripojenie:**
   ```bash
   ping -c 5 google.com
   ```

2. **Skontrolujte dostupnosť servera:**
   ```bash
   curl -I https://install.edudisplej.sk/
   ```

3. **Skúste manuálne sťahovanie:**
   ```bash
   curl -v https://install.edudisplej.sk/init/download.php?getfiles
   ```

4. **Skontrolujte firewall/proxy:**
   - Niektoré siete môžu blokovať sťahovanie
   - Skúste z inej siete alebo použite mobilné dáta

---

## Problém: Systém Zamrzne po Inštalácii / System Freezes After Installation

### Symptómy / Symptoms:
- Inštalácia sa dokončí, ale systém sa zdá zamrznutý
- Raspberry Pi sa nereštartuje automaticky
- Obrazovka zostane prázdna alebo čierna

### Riešenie / Solution:

**V novej verzii (po oprave):**
- Interaktívna výzva na reštart:
  ```
  Restartovať teraz? [Y/n] (automaticky za 30s):
  ```
- Pred reštartom sa zastavujú služby korektne
- Synchronizácia diskov pred reštartom (`sync`)
- Možnosť preskočiť automatický reštart (napísať `n`)

**Ak sa systém nezreštartoval:**

1. **Manuálny reštart:**
   ```bash
   sudo reboot
   ```

2. **Kontrola stavu inštalácie:**
   ```bash
   ls -la /opt/edudisplej/
   ls -la /etc/systemd/system/edudisplej-kiosk.service
   ```

3. **Kontrola systemd služby:**
   ```bash
   systemctl status edudisplej-kiosk.service
   ```

---

## Problém: Raspberry Pi sa Nenabootuje po Inštalácii / Raspberry Pi Won't Boot After Installation

### Symptómy / Symptoms:
- Systém sa reštartuje, ale zasekne sa počas bootowania
- Obrazovka zostane čierna
- Systém sa nedostane k login prompt-u

### Možné Príčiny / Possible Causes:

1. **Display Manager Konflikt**
2. **X Server Chyba**
3. **Nesprávne Permissions**
4. **Chýbajúce Balíčky**

### Riešenie / Solution:

#### Metóda 1: SSH Prístup

Ak môžete pripojiť cez SSH:

```bash
# Pripojiť sa na Pi
ssh pi@<raspberry-pi-ip>

# Kontrola logov
sudo journalctl -xe
tail -50 /var/log/syslog

# Kontrola EduDisplej logov
tail -50 /opt/edudisplej/session.log

# Dočasné zakázanie služby
sudo systemctl disable edudisplej-kiosk.service
sudo systemctl stop edudisplej-kiosk.service

# Reštart
sudo reboot
```

#### Metóda 2: Recovery Mode

1. **Pridať monitor a klávesnicu**
2. **Počas bootu stlačiť Shift** (pre GRUB menu)
3. **Vybrať Recovery Mode**
4. **Alebo stlačiť Ctrl+Alt+F2** pre textový terminál

```bash
# Prihlásiť sa ako root alebo s sudo

# Zakázať EduDisplej službu
sudo systemctl disable edudisplej-kiosk.service

# Zakázať všetky display managery
sudo systemctl disable lightdm lxdm gdm3 sddm --now
sudo systemctl mask lightdm lxdm gdm3 sddm

# Reštart
sudo reboot
```

#### Metóda 3: SD Karta na inom PC

1. **Vybrať SD kartu z Raspberry Pi**
2. **Pripojiť na PC/Mac/Linux**
3. **Mountnúť root partition**
4. **Upraviť súbory:**

```bash
# Na Linux/Mac:
cd /media/<user>/rootfs/etc/systemd/system/

# Vymazať symlink
rm multi-user.target.wants/edudisplej-kiosk.service

# Alebo zakázať autologin
rm getty@tty1.service.d/autologin.conf
```

5. **Unmount a vložiť SD kartu späť**
6. **Boot Raspberry Pi**

#### Metóda 4: Čistá Reinštalácia

Ak nič iné nepomôže:

1. **Zálohovať dôležité dáta** (ak možno)
2. **Nainštalovať čistý Raspberry Pi OS**
3. **Spustiť inštaláciu EduDisplej znova**

```bash
curl https://install.edudisplej.sk/install.sh | sed 's/\r$//' | sudo bash
```

---

## Problém: Display Manager Konflikt

### Symptómy / Symptoms:
- X server sa spúšťa viackrát
- Autologin nefunguje
- Obrazovka bliká alebo sa prepína

### Riešenie / Solution:

```bash
# Zakázať všetky display managery
sudo systemctl disable lightdm --now
sudo systemctl mask lightdm

sudo systemctl disable lxdm --now
sudo systemctl mask lxdm

sudo systemctl disable gdm3 --now
sudo systemctl mask gdm3

sudo systemctl disable sddm --now
sudo systemctl mask sddm

# Skontrolovať, či sú zakázané
systemctl list-unit-files | grep -E "lightdm|lxdm|gdm|sddm"

# Reštart
sudo reboot
```

---

## Problém: Chýbajúce Balíčky

### Symptómy / Symptoms:
- Init script hlási chýbajúce balíčky
- X server sa nespustí
- Browser sa nenájde

### Riešenie / Solution:

```bash
# Manuálna inštalácia základných balíčkov
sudo apt-get update
sudo apt-get install -y openbox xinit unclutter curl x11-utils xserver-xorg

# Pre ARMv6 (Raspberry Pi 1, Zero v1)
sudo apt-get install -y epiphany-browser

# Pre ARMv7+ (Raspberry Pi 2, 3, 4, 5, Zero 2 W)
sudo apt-get install -y chromium-browser

# Utility balíčky
sudo apt-get install -y xterm xdotool figlet dbus-x11

# Reštart init skriptu
sudo /opt/edudisplej/init/edudisplej-init.sh
```

---

## Problém: Nesprávne Permissions

### Symptómy / Symptoms:
- "Permission denied" chyby
- Skripty sa nespúšťajú
- X server sa nespustí

### Riešenie / Solution:

```bash
# Opraviť permissions na EduDisplej
sudo chmod -R 755 /opt/edudisplej
sudo chown -R pi:pi /opt/edudisplej

# Opraviť permissions na home directory
sudo chown -R pi:pi /home/pi
sudo chmod 755 /home/pi

# Skontrolovať executable flag na skriptoch
sudo chmod +x /opt/edudisplej/init/*.sh
sudo chmod +x /home/pi/kiosk-launcher.sh

# Reštart
sudo reboot
```

---

## Problém: X Server Chyby

### Symptómy / Symptoms:
- "Failed to start X server" v logoch
- Čierna obrazovka, žiadne GUI
- /var/log/Xorg.0.log obsahuje chyby

### Riešenie / Solution:

```bash
# Skontrolovať X server log
cat /var/log/Xorg.0.log

# Bežné chyby a riešenia:

# 1. Driver chyba
sudo apt-get install --reinstall xserver-xorg

# 2. GPU/Video chyba
# V /boot/config.txt pridať/upraviť:
sudo nano /boot/config.txt
# Pridať:
# dtoverlay=vc4-fkms-v3d
# gpu_mem=128

# 3. Permission chyba
sudo chmod 4755 /usr/lib/xorg/Xorg

# Reštart
sudo reboot
```

---

## Diagnostické Príkazy / Diagnostic Commands

### Kontrola Stavu Systému

```bash
# Kontrola služieb
systemctl status edudisplej-kiosk.service
systemctl list-units --failed

# Kontrola procesov
ps aux | grep -E "Xorg|openbox|chromium|epiphany"

# Kontrola logov
tail -50 /opt/edudisplej/session.log
tail -50 /opt/edudisplej/apt.log
tail -50 /var/log/Xorg.0.log
journalctl -xe
```

### Kontrola Sieťového Pripojenia

```bash
# Ping test
ping -c 5 8.8.8.8

# DNS test
nslookup google.com

# Traceroute
traceroute install.edudisplej.sk
```

### Kontrola Diskovej Kapacity

```bash
# Voľné miesto
df -h

# Použité miesto v /opt/edudisplej
du -sh /opt/edudisplej/*
```

---

## Kontakt a Podpora / Contact and Support

Ak žiaden z vyššie uvedených riešení nepomohol:

1. **Zhromaždite diagnostické informácie:**
   ```bash
   # Vytvorte diagnostický balík
   mkdir ~/edudisplej-debug
   cp /opt/edudisplej/session.log ~/edudisplej-debug/
   cp /opt/edudisplej/apt.log ~/edudisplej-debug/
   cp /var/log/Xorg.0.log ~/edudisplej-debug/
   journalctl -xe > ~/edudisplej-debug/journalctl.log
   systemctl status edudisplej-kiosk.service > ~/edudisplej-debug/service-status.log
   tar -czf ~/edudisplej-debug.tar.gz ~/edudisplej-debug/
   ```

2. **Uveďte tieto informácie:**
   - Model Raspberry Pi / zariadenia
   - Verzia OS (cat /etc/os-release)
   - Symptómy problému
   - Kroky ktoré ste už skúsili
   - Obsah diagnostických logov

3. **GitHub Issues:**
   - [https://github.com/nagy-andras-sk/edudisplej/issues](https://github.com/nagy-andras-sk/edudisplej/issues)

---

## Prevencia Problémov / Problem Prevention

### Pred Inštaláciou:

1. ✅ **Aktualizovať systém:**
   ```bash
   sudo apt-get update && sudo apt-get upgrade -y
   ```

2. ✅ **Skontrolovať voľné miesto:**
   ```bash
   df -h
   # Potrebné min. 500MB voľného miesta
   ```

3. ✅ **Zabezpečiť stabilné internetové pripojenie**

4. ✅ **Použiť kvalitný zdroj napájania**
   - Min. 5V 2.5A pre Raspberry Pi 3
   - Min. 5V 3A pre Raspberry Pi 4

5. ✅ **Použiť dobrú SD kartu**
   - Class 10 alebo lepšia
   - Min. 8GB kapacita
   - Odporúčaný: SanDisk, Samsung

### Po Inštalácii:

1. ✅ **Pravidelné zálohy konfigurácie:**
   ```bash
   sudo tar -czf /home/pi/edudisplej-backup-$(date +%Y%m%d).tar.gz /opt/edudisplej
   ```

2. ✅ **Monitorovanie logov:**
   ```bash
   # Pridať do crontab
   0 0 * * * tail -100 /opt/edudisplej/session.log > /home/pi/session-daily.log
   ```

3. ✅ **Testovanie po aktualizáciách:**
   - Po každej aktualizácii skontrolovať, či systém funguje správne
   - Mať k dispozícii klávesnicu a monitor pre diagnostiku

---

## Záver / Conclusion

Nová verzia inštalačného skriptu obsahuje významné vylepšenia:

✅ **Detailné progress správy** - vidíte každý krok  
✅ **Časové limity** - predchádzanie zamrznutiu  
✅ **Automatické opakovanie** - riešenie dočasných chýb  
✅ **Lepšie chybové hlášky** - jasné informácie o problémoch  
✅ **Bezpečný reštart** - korektné zastavenie služieb  
✅ **Interaktívna kontrola** - možnosť preskočiť auto-reštart  

Väčšina problémov, ktoré spôsobovali zamrznutie, bola vyriešená v tejto aktualizácii.

Pre viac informácií pozrite:
- [ARCHITECTURE.md](ARCHITECTURE.md) - Technická dokumentácia
- [README.md](README.md) - Základné informácie
- [ERROR_HANDLING.md](ERROR_HANDLING.md) - Error handling stratégie
