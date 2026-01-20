# File Stream Error - Fájl Letöltési Hiba

## Probléma leírása / Popis problému

### Magyar verzió

A rendszer telepítése során előfordulhat, hogy a letöltött fájlok csonkítottak (truncated) lesznek, azaz nem töltődnek le teljesen. 

**Példa:**
- A `common.sh` utolsó sora helyesen: `load_config`
- De a szerveren csak: `load_confi` (hiányzik a végső 'g' betű)

### Slovenská verzia

Počas inštalácie systému sa môže stať, že stiahnuté súbory sú skrátené (truncated), teda nie sú úplne stiahnuté.

**Príklad:**
- Posledný riadok `common.sh` správne: `load_config`
- Ale na serveri len: `load_confi` (chýba koncové písmeno 'g')

---

## Mi okozza a problémát? / Čo spôsobuje problém?

### 1. PHP Output Buffering

**Magyar:**
A PHP alapértelmezetten kimeneti pufferelést (output buffering) használ. Ez azt jelenti, hogy a `readfile()` függvény által küldött adatok először egy pufferbe kerülnek, és csak akkor kerülnek továbbításra a kliensnek, amikor:
- A puffer megtelik
- A szkript véget ér
- Explicit módon meghívják az `ob_flush()` vagy `flush()` függvényt

Ha a puffer mérete kisebb, mint a fájl mérete, vagy ha a PHP/webszerver korlátoz bizonyos beállításokat, a fájl vége lecsúszhat.

**Slovensky:**
PHP predvolene používa výstupné buffering (output buffering). To znamená, že dáta odoslané funkciou `readfile()` sa najprv ukladajú do bufferu a odošlú sa klientovi len vtedy, keď:
- Buffer sa naplní
- Skript sa skončí
- Explicitne sa zavolá `ob_flush()` alebo `flush()`

Ak je veľkosť bufferu menšia ako veľkosť súboru, alebo ak PHP/webserver obmedzuje určité nastavenia, koniec súboru môže byť orezaný.

### 2. Hálózati problémák / Sieťové problémy

**Magyar:**
- Időszakos hálózati kapcsolat megszakadások
- Időtúllépés (timeout) a letöltés közben
- Sávszélesség korlátozások

**Slovensky:**
- Občasné prerušenia sieťového pripojenia
- Časový limit (timeout) počas sťahovania
- Obmedzenia šírky pásma

### 3. Webszerver beállítások / Nastavenia webservera

**Magyar:**
- `max_execution_time`: PHP szkript maximális futási ideje
- `memory_limit`: Memória korlát
- Apache/Nginx timeout beállítások

**Slovensky:**
- `max_execution_time`: Maximálny čas behu PHP skriptu
- `memory_limit`: Limit pamäte
- Apache/Nginx nastavenia timeout

---

## Tünetek / Príznaky

### 1. Fájl csonkítás / Skrátenie súboru

**Magyar:**
- Hiányzó karakterek a fájl végéről
- Bash függvények nem teljes nevei (pl. `load_confi` helyett `load_config`)
- Syntax hibák a bash szkriptekben

**Slovensky:**
- Chýbajúce znaky na konci súboru
- Neúplné názvy Bash funkcií (napr. `load_confi` namiesto `load_config`)
- Syntaxové chyby v bash skriptoch

### 2. Végtelen ciklus / Nekonečná slučka

**Magyar:**
A rendszer inicializálása során a képernyőn ismételten ugyanaz a szöveg jelenik meg:

```
===========================================
      E D U D I S P L E J
===========================================

Modulok betöltése... / Načítavam moduly...
```

Ez akkor történik, amikor:
- A `common.sh` hiányos, így a `source` parancs hibával tér vissza
- A systemd service újraindítja a folyamatot
- Ez végtelen ciklusba fut

**Slovensky:**
Počas inicializácie systému sa na obrazovke opakovane zobrazuje ten istý text:

```
===========================================
      E D U D I S P L E J
===========================================

Modulok betöltése... / Načítavam moduly...
```

To sa stane, keď:
- `common.sh` je neúplný, takže príkaz `source` vráti chybu
- systemd služba reštartuje proces
- To vedie k nekonečnej slučke

---

## Megoldás / Riešenie

### 1. Javítások a telepítő szkriptben / Opravy v inštalačnom skripte

**Magyar:**
Az `install.sh` szkript mostantól:
- Ellenőrzi a letöltött fájl méretét a várható mérettel
- Legfeljebb 3x próbálkozik a letöltéssel
- Hibaüzenetet ad, ha a fájl nem teljes
- Megállítja a telepítést hibás fájl esetén

**Slovensky:**
Skript `install.sh` teraz:
- Overuje veľkosť stiahnutého súboru s očakávanou veľkosťou
- Pokúša sa o stiahnutie maximálne 3-krát
- Zobrazuje chybové hlásenie, ak súbor nie je úplný
- Zastaví inštaláciu v prípade chybného súboru

### 2. Javítások a PHP letöltő szkriptben / Opravy v PHP sťahovacom skripte

**Magyar:**
A `download.php` mostantól:
- Kikapcsolja a kimeneti pufferelést (`ob_end_clean()`)
- Chunk-okban (8KB darabokban) küldi a fájlt
- Minden chunk után explicit `flush()` meghívás
- Nincs időkorlát nagy fájloknál (`set_time_limit(0)`)

**Slovensky:**
`download.php` teraz:
- Vypína výstupný buffering (`ob_end_clean()`)
- Posiela súbor po častiach (chunks po 8KB)
- Po každom chunku explicitne volá `flush()`
- Žiadny časový limit pre veľké súbory (`set_time_limit(0)`)

### 3. Végtelen ciklus megakadályozása / Prevencia nekonečnej slučky

**Magyar:**
Az `edudisplej-init.sh` mostantól:
- Ellenőrzi a `common.sh` integritását betöltés előtt
- Leellenőrzi, hogy a `load_config` függvény létezik-e
- Informatív hibaüzenetet ad, és megáll (nem újraindít végtelen ciklusban)
- 10 másodperces várakozás a hibaüzenet olvashatósága érdekében

**Slovensky:**
`edudisplej-init.sh` teraz:
- Overuje integritu `common.sh` pred načítaním
- Kontroluje, či existuje funkcia `load_config`
- Zobrazuje informatívne chybové hlásenie a zastaví sa (nereštartuje v nekonečnej slučke)
- 10-sekundová pauza pre čitateľnosť chybového hlásenia

---

## Mit tegyek, ha találkozom a problémával? / Čo robiť, ak sa stretnem s problémom?

### 1. Újratelepítés / Preinštalovanie

**Magyar:**
```bash
sudo curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash
```

A javított telepítő most már ellenőrzi a fájlokat és automatikusan újrapróbálkozik.

**Slovensky:**
```bash
sudo curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash
```

Opravený inštalátor teraz overuje súbory a automaticky opakuje pokusy.

### 2. Manuális javítás (ha szükséges) / Manuálna oprava (ak je potrebná)

**Magyar:**
Ha a telepítés után is problémát tapasztalsz:

```bash
# Ellenőrizd a common.sh utolsó sorát
tail /opt/edudisplej/init/common.sh

# Ha hiányzik a "load_config", javítsd:
sudo nano /opt/edudisplej/init/common.sh
# Adj hozzá egy új sort a fájl végére:
load_config

# Mentsd és lépj ki (Ctrl+O, Enter, Ctrl+X)

# Indítsd újra a szolgáltatást
sudo systemctl restart edudisplej-kiosk.service
```

**Slovensky:**
Ak stále máte problém po inštalácii:

```bash
# Skontrolujte posledný riadok common.sh
tail /opt/edudisplej/init/common.sh

# Ak chýba "load_config", opravte:
sudo nano /opt/edudisplej/init/common.sh
# Pridajte nový riadok na koniec súboru:
load_config

# Uložte a ukončite (Ctrl+O, Enter, Ctrl+X)

# Reštartujte službu
sudo systemctl restart edudisplej-kiosk.service
```

### 3. Logok ellenőrzése / Kontrola logov

**Magyar:**
```bash
# Telepítési hiba részletei
cat /opt/edudisplej/session.log

# Systemd szolgáltatás állapota
sudo systemctl status edudisplej-kiosk.service

# Systemd journal
sudo journalctl -u edudisplej-kiosk.service -n 50
```

**Slovensky:**
```bash
# Detaily inštalačnej chyby
cat /opt/edudisplej/session.log

# Stav systemd služby
sudo systemctl status edudisplej-kiosk.service

# Systemd journal
sudo journalctl -u edudisplej-kiosk.service -n 50
```

---

## Technikai részletek / Technické detaily

### Fájl integritás ellenőrzés / Kontrola integrity súboru

**Magyar:**
A telepítő most összehasonlítja a letöltött fájl méretét a szervertől kapott várt mérettel:

```bash
ACTUAL_SIZE=$(stat -c%s "${INIT_DIR}/${NAME}")
if [ "$ACTUAL_SIZE" -eq "$SIZE" ]; then
    # OK
else
    # HIBA - újrapróbálkozás
fi
```

**Slovensky:**
Inštalátor teraz porovnáva veľkosť stiahnutého súboru s očakávanou veľkosťou zo servera:

```bash
ACTUAL_SIZE=$(stat -c%s "${INIT_DIR}/${NAME}")
if [ "$ACTUAL_SIZE" -eq "$SIZE" ]; then
    # OK
else
    # CHYBA - opakovanie pokusu
fi
```

### PHP streaming optimalizáció / Optimalizácia PHP streamingu

**Magyar:**
```php
// Pufferelés kikapcsolása
if (ob_get_level()) {
    ob_end_clean();
}

// Chunk-okra bontott küldés
$chunkSize = 8192;
while (!feof($handle)) {
    $buffer = fread($handle, $chunkSize);
    echo $buffer;
    flush(); // Azonnali küldés
}
```

**Slovensky:**
```php
// Vypnutie bufferingu
if (ob_get_level()) {
    ob_end_clean();
}

// Odosielanie po častiach
$chunkSize = 8192;
while (!feof($handle)) {
    $buffer = fread($handle, $chunkSize);
    echo $buffer;
    flush(); // Okamžité odoslanie
}
```

---

## Összefoglalás / Zhrnutie

**Magyar:**
A fájl csonkítási probléma a PHP output buffering és hálózati problémák kombinációjából ered. A javítások háromszintű megközelítést alkalmaznak:

1. **Szerveroldal**: Chunk-okra bontott streaming, buffering kikapcsolva
2. **Kliens oldal**: Fájlméret ellenőrzés, automatikus újrapróbálkozás
3. **Inicializálás**: Integritás ellenőrzés, informatív hibaüzenetek végtelen ciklus helyett

**Slovensky:**
Problém skracovania súborov vzniká z kombinácie PHP output bufferingu a sieťových problémov. Opravy používajú trojúrovňový prístup:

1. **Serverová strana**: Streaming po častiach, buffering vypnutý
2. **Klientská strana**: Kontrola veľkosti súboru, automatické opakovanie pokusov
3. **Inicializácia**: Kontrola integrity, informatívne chybové hlásenia namiesto nekonečnej slučky

---

**Dátum vytvorenia / Creation Date:** 2026-01-20  
**Verzia / Version:** 1.0  
**Autor / Author:** EduDisplej Development Team
