# EduDisplej – Sync és Offline Tárolási Architektúra

> Verzió: 2026-02-23
> Cél: részletes, üzemeltetői és fejlesztői leírás arról, hogyan épül fel a kijelzők szinkronizációja és teljes offline futtatása.

---

## 1. Rövid célkép

Az architektúra célja, hogy a kijelző:

1. időbélyeg (timestamp) változáskor új konfigurációt húzzon,
2. a teljes loop és schedule tervet lokálisan eltárolja,
3. minden szükséges modult és statikus fájlt előre letöltsön,
4. a loop modul-beállításai alapján külső asseteket (képek, PDF, videó stb.) is lokális cache-be tegyen,
5. hálózatkimaradáskor is folyamatosan működjön.

---

## 2. Trigger és szinkron döntési logika

### 2.1 Trigger

A kijelző periodikusan futtatja a sync ciklust (alapértelmezés szerint 5 perc, fast loop módban 30 másodperc).

Ha a szerver oldali konfiguráció frissült, a kliens új loop letöltést indít.

### 2.2 Milyen változás indít új letöltést?

Tipikusan:

- loop/schedule módosítás,
- group planner változás,
- module lista / module settings változás,
- plan version / loop last update változás,
- maintenance által kiváltott plan version bump.

---

## 3. End-to-end folyamat (szinkronkor)

Az offline előkészítés fő lépései:

1. **Loop payload lekérése**
   - API: `api/kiosk_loop.php`
   - Tartalom: aktív loop, offline_plan, preload_modules, runtime metadata.

2. **Loop és schedule mentése lokálisan**
   - Kimenet: `loop.json`
   - Tartalom:
     - aktív loop,
     - offline_plan (base_loop + time_blocks),
     - plan verzió/meta.

3. **Modullista feloldása**
   - Források egyesítése:
     - `loop_config`,
     - `preload_modules`,
     - `offline_plan.base_loop`,
     - `offline_plan.time_blocks[].loops[]`.

4. **Modulok teljes letöltése**
   - API: `api/download_module.php`
   - Retry stratégia: több próbálkozás/modul.
   - Sikertelen kötelező modul esetén a folyamat hibával áll meg.

5. **Runtime metadata normalizálás**
   - `module_folder`, `module_main_file`, `module_renderer` kitöltése.
   - Invalid loop bejegyzések szűrése.

6. **Module settings asset prefetch**
   - A loop beállításaiban szereplő külső fájl URL-ek letöltése lokális cache-be.
   - Beállítások átírása lokális elérési útra (`file://...`).

7. **Meal module offline JSON gyártás**
   - Előre betöltött étlapból lokális fájlok:
     - `offline/mai.json`
     - `offline/holnapi.json` (ha releváns).
   - A modul settings kiegészítése fájl-hivatkozásokkal.

8. **Loop player frissítés**
   - Lokális lejátszó HTML újragenerálása.
   - Lejátszás csak akkor indul, ha az offline csomag konzisztens.

---

## 4. Lokális tárolási struktúra

Kiemelt könyvtárak:

```text
/opt/edudisplej/
├─ localweb/
│  ├─ loop_player.html
│  ├─ unconfigured.html
│  ├─ assets/                         # cache-elt külső assetek (file://)
│  └─ modules/
│     ├─ loop.json                    # teljes offline loop + schedule terv
│     ├─ <module_key>/
│     │  ├─ ... modul statikus fájlok
│     │  └─ offline/
│     │     ├─ mai.json               # meal modul napi offline adat
│     │     └─ holnapi.json           # meal modul holnapi offline adat
│     └─ .download_info.json
├─ logs/
└─ lic/token
```

---

## 5. Loop és schedule működés offline módban

### 5.1 Offline terv

A `loop.json` két szintet tartalmaz:

- `base_loop`: alap loop,
- `time_blocks`: időablakok, saját loop listával.

### 5.2 Lejátszás közbeni váltás

A loop player nem csak induláskor, hanem **loop-határnál** is újraértékeli az aktuális időt:

- ha időablak váltás történt, átáll a megfelelő blokk loopjára,
- ha nem, újraindítja ugyanazt a loopot.

Ezzel elkerülhető, hogy egy rossz időablakban ragadjon a kijelző.

---

## 6. Meal modul offline stratégia

### 6.1 Szerver oldali előkészítés

A `kiosk_loop.php` a meal modul settings-be előkészített payloadot tesz (`offlinePrefetchedMenuData`), beleértve:

- klasszikus nézethez a napi menüt,
- square dual-day nézethez a mai + holnapi struktúrát.

### 6.2 Kliens oldali sorrend (fallback lánc)

A meal modul renderelési sorrendje:

1. élő API lekérés,
2. lokális offline fájl (`offline/mai.json`, `offline/holnapi.json`),
3. settings-ben kapott prefetched adat,
4. localStorage cache,
5. üres fallback nézet.

Ez több szintű védelmet ad átmeneti hálózati vagy API problémákra.

---

## 7. Miért jó megoldás ez? (indoklás)

### 7.1 Üzemi stabilitás

- A kijelző nem függ folyamatos internetkapcsolattól.
- Újraindítás után is lokális csomagból tud indulni.
- Egységes, determinisztikus indulási folyamat.

### 7.2 Skálázhatóság

- Sok kijelző esetén csökken az élő API terhelés.
- A legtöbb render művelet lokálisan történik.
- Kevesebb valós idejű szerver oldali bottleneck.

### 7.3 Konzisztencia

- Azonos loop payloadból készül a futó lejátszás és az offline cache.
- A planner, base loop és time block adatok egybe kerülnek.

### 7.4 Hibatűrés

- Retry + fail-fast modul letöltésnél.
- Többszintű meal fallback.
- Runtime metadata normalizálás védi a lejátszót hibás payload ellen.

---

## 8. Előnyök / hátrányok

## Előnyök

- **Offline-first működés:** internet kiesésnél is megy a kijelzés.
- **Gyorsabb render:** modulok és assetek helyi fájlról töltődnek.
- **Prediktálható viselkedés:** időablak-váltás loop határon kontrollált.
- **Kevesebb API függés runtime-ban:** induláskor letölt, utána lokálisan szolgál ki.
- **Karbantartható fallback lánc:** meal és egyéb modulok több szintről tudnak betölteni.

## Hátrányok

- **Nagyobb lokális tárhely igény:** asset cache + modulfájlok miatt.
- **Bonyolultabb sync pipeline:** több lépés, több hibapont.
- **Verziókezelési fegyelem szükséges:** runtime metadata és modul struktúra konzisztencia kritikus.
- **Cache invalidálás komplexitás:** biztosítani kell, hogy új tartalomnál tényleg frissüljön minden.

---

## 9. Kockázatok és mitigációk

1. **Részleges letöltés kockázata**
   - Mitigáció: kötelező modul hiány esetén hard fail, nincs „félig kész” indulás.

2. **Hibás planner payload**
   - Mitigáció: planner elemek normalizálása és settings-optimalizálása sync során.

3. **Meal adat hiány runtime-ban**
   - Mitigáció: szerver prefetch + fájl fallback + cache fallback.

4. **Külső asset URL elérhetetlenség**
   - Mitigáció: sync közbeni előre letöltés lokális cache-be.

---

## 10. Üzemeltetési ellenőrzőlista

Minden nagyobb módosítás után javasolt ellenőrzések:

1. `loop.json` tartalmazza az aktuális `offline_plan` szerkezetet.
2. Minden aktív modul könyvtára létezik a `modules/` alatt.
3. Meal modulnál létezik `offline/mai.json` (és dual-day esetben `offline/holnapi.json`).
4. `assets/` mappa létrejött és tartalmaz cache-elt fájlokat.
5. Loop player át tud váltani időablak között loop-határon.

---

## 11. Összefoglalás

Ez a megoldás egy **offline-first, determinisztikus, hibára felkészített** kijelző architektúrát ad:

- központilag menedzselt tartalom,
- lokálisan futtatható teljes csomag,
- kiszámítható schedule váltás,
- többfokozatú fallback kritikus modulokra (különösen meal).

Ennek ára a nagyobb komplexitás és cache-kezelési fegyelem, de üzemi megbízhatóságban és felhasználói élményben ez jelentős előny.
