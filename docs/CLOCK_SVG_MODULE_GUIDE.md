# Clock SVG modul útmutató

Ez a dokumentum a `clock` modul jelenlegi, SVG-alapú analóg megjelenítését írja le:
- hogyan mozognak a mutatók,
- miért ilyen a felépítés,
- mit és hogyan érdemes szerkeszteni.

Érintett fő fájl:
- `webserver/control_edudisplej_sk/modules/clock/m_clock.html`

Kapcsolódó beállítások:
- `webserver/control_edudisplej_sk/modules/clock/config/default_settings.json`
- `webserver/control_edudisplej_sk/modules/module_policy.php`
- `webserver/control_edudisplej_sk/modules/module_registry.php`
- `webserver/control_edudisplej_sk/dashboard/group_loop/assets/js/app.js`

---

## 1) Miért SVG az analóg óra?

Az analóg órát SVG-ben rendereljük, mert:
- **felbontásfüggetlen** (éles marad minden kijelzőn),
- **egyszerűen forgatható** (`transform="rotate(...)"`),
- **könnyen skálázható** viewport alapján,
- kevesebb DOM/CSS trükk kell, mint div-alapú rajzolásnál.

A modul teljes képernyős (kiosk) használatra készült, ezért a cél:
- **stabil, olvasható óra**,
- minimális render-komplexitás,
- kis karbantartási költség.

---

## 2) Teljes képernyő és méretezés

Az analóg SVG a magasságot tölti ki:
- `height: 100vh;`
- `width: auto;`
- `max-width: 100vw;`

Ez azt jelenti:
- a kör alak **nem torzul**,
- a teljes óra mindig látható marad,
- álló/fekvő kijelzőn is konzisztens.

Miért nem `width: 100vw` elsődlegesen?
- Mert akkor bizonyos képarányokon könnyebben túlnőhetne függőlegesen.
- Kiosk esetben a vertikális kitöltés ad stabilabb vizuális eredményt.

---

## 3) SVG koordinátarendszer röviden

A `viewBox="-1 -1 2 2"` középpontja `(0,0)`.

- Külső skála: kb. `-1..+1` tartomány.
- Órajelölők: `cy="-0.95"` környékén.
- Mutatók kezdőpontja: `(0,0)`.

Előny:
- könnyű matematikai forgatás a középpont körül,
- minden elem ugyanabban a normalizált térben van.

---

## 4) Mutatók mozgásának matematikája

A program minden tick-nél kiszámolja a szögeket:

- Óra mutató:
  - `hourDeg = ((hours + minutes/60 + seconds/3600) / 12) * 360`
- Perc mutató:
  - `minuteDeg = ((minutes + seconds/60) / 60) * 360`
- Másodperc mutató:
  - `secondDeg = (seconds / 60) * 360`

Miért vannak benne a törtrészek (pl. `minutes/60` az óránál)?
- Ettől lesz a mozgás **folyamatosabb és pontosabb**,
- nem „ugrál” nagy lépésekben (különösen az óramutató).

A forgatás SVG attribútummal történik:
- `transform="rotate(<fok>)"`

---

## 5) Tick időzítés – miért nem sima `setInterval(1000)`?

A modul időzítése:
- `setTimeout(tick, 1000 - (now.getTime() % 1000) + 20)`

Miért jó ez?
- a frissítés a valós másodperchatárhoz igazodik,
- hosszabb futásnál kisebb drift,
- stabilabb „óraérzet” kiosk kijelzőn.

A `+20` ms kis puffer:
- elkerüli, hogy a callback túl korán fusson a határ előtt.

---

## 6) Digitális overlay az analóg órán

Új kapcsolható funkció:
- `digitalOverlayEnabled` (bool)
- `digitalOverlayPosition` (`auto | top | center | bottom`)

### Pozíciók
- `top`: fent-közép
- `center`: közép
- `bottom`: lent-közép
- `auto`: dinamikus top/bottom

### Auto mód logika
Auto módban a rendszer nézi, hogy a mutatók elfoglalják-e a felső közép zónát.
- Ha igen → overlay alul (`bottom`)
- Ha nem → overlay felül (`top`)

Miért így?
- jobb olvashatóság,
- kisebb vizuális ütközés a mutatókkal,
- fix szabály, mégis „okos” viselkedés.

---

## 7) Beállítások (runtime + admin)

A modul URL paraméterből és mentett modulbeállításból dolgozik.
Fontosabb kulcsok:
- `type` (`analog` / `digital`)
- `format` (`24h` / `12h`)
- `timeColor`, `dateColor`, `bgColor`
- `showSeconds`
- `timeFontSize`
- `digitalOverlayEnabled`
- `digitalOverlayPosition`

Alapértelmezések:
- `modules/clock/config/default_settings.json`

Érvényesítés/policy:
- `modules/module_policy.php`

Regisztrált schema:
- `modules/module_registry.php`

Szerkesztő UI:
- `dashboard/group_loop/assets/js/app.js`

---

## 8) Mit érdemes szerkeszteni, és mit nem?

### Biztonságosan szerkeszthető
- színek (`timeColor`, `dateColor`, `bgColor`)
- mutató vastagságok (`stroke-width`)
- mutató hosszak (`y2` értékek)
- overlay betűméret clamp tartomány
- overlay pozíciós százalékok (`top: 33%`, `67%`)

### Óvatosan / ne változtasd indok nélkül
- `viewBox` koordináta-rendszer
- időzítés formula (`1000 - now%1000 + 20`)
- fokszám képletek
- az `auto` ütközésdetektálás küszöbei

Ha ezeket módosítod, ellenőrizd:
- hogy nem csúszik-e az óra,
- nem lesz-e vibrálás/jitter,
- és marad-e olvasható minden képarányon.

---

## 9) Rövid fejlesztői ellenőrzőlista

Módosítás után érdemes megnézni:
1. analóg mód teljes képernyőn (különböző felbontáson)
2. másodpercmutató on/off
3. overlay off / top / center / bottom / auto
4. világos-sötét háttér kontraszt
5. 24h és 12h digitális formátum

---

## 10) Miért így készült a jelenlegi implementáció?

A cél **MVP+stabilitás** volt kiosk környezetre:
- minimális, áttekinthető kód,
- megbízható időzítés,
- skálázható SVG rajz,
- opcionális digitális rásegítés az olvashatóságért,
- adminból kapcsolható működés.

Ez a kompromisszum gyors, jól karbantartható, és gyakorlatban stabilan működik teljes kijelzős üzemmódban.
