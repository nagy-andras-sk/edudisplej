# UI példa: stílus-öröklés és célzott felülírás (group_loop oldal)

## Rövid összefoglaló
Ebben a példában a cél az volt, hogy a felső 3 oszlopos elrendezés áttekinthetőbb legyen:
- **Elérhető modulok**: sokkal kisebb oszlop
- **Loop konfiguráció**: a legnagyobb oszlop
- **Live preview**: közepes oszlop

A feladat közben kiderült, hogy az oldal nem használja ki megfelelően a képernyő szélességét. Ennek oka egy **globálisan definiált konténer stílus** volt.

---

## Mi volt a probléma?
A projekt központi stílusfájljában (`admin/style.css`) a következő globális szabály szerepel:

```css
.container {
    max-width: 1700px;
    margin: 0 auto;
    padding: 20px;
}
```

Ez azt jelenti, hogy minden olyan oldal, amely `.container` osztályt használ, automatikusan kap egy maximum szélességet. 

Következmény:
- nagy monitoron sem tudott tovább nőni az oldal szélessége,
- a felső 3 oszlop vizuálisan „összeszorult”,
- a középső (fontos) konfigurációs terület nem kapott elég helyet.

---

## Miért „öröklés” ez a gyakorlatban?
Pontos CSS szempontból itt több mechanizmus együtt dolgozik:

1. **Globális szabály alkalmazása**: a `.container` szabály minden érintett oldalra vonatkozik.
2. **Cascading (kaszkád)**: ha nem írjuk felül helyben, a globális szabály marad érvényben.
3. **Célzott felülírás**: az adott oldalon (`dashboard/group_loop.php`) egy lokális `<style>` blokkban felül tudjuk írni a viselkedést.

A hétköznapi fejlesztői nyelvben erre gyakran mondjuk azt, hogy az oldal „örökölte” a központi stílust, ezért lett kisebb/korlátozott.

---

## Mit módosítottunk?
A `group_loop.php` oldalon lokálisan felülírtuk a konténert:

```css
.container {
    max-width: 100% !important;
    width: 100%;
    padding: 20px 14px;
}
```

És újrasúlyoztuk a három oszlop arányát:

Nagy képernyőn:
- modulok: **12%**
- konfiguráció: **62%**
- preview: **26%**

Közepes képernyőn:
- modulok: **14%**
- konfiguráció: **60%**
- preview: **26%**

Így a fontos középső terület domináns lett, a modul-lista pedig tényleg keskeny maradt.

---

## Miért jó ez munka-leírás példának?
Ez a feladat jól mutatja, hogy egy UI probléma gyakran nem komponens-szinten kezdődik, hanem:
- központi stílus (design system) hatásából ered,
- és célzott, oldalspecifikus felülírással oldható meg.

### Kompetenciák, amiket demonstrál
- gyökérok elemzés (nem csak tüneti javítás),
- CSS kaszkád/specificity gyakorlati használata,
- layout-prioritás megértése (mi kapjon több helyet),
- reszponzív viselkedés finomhangolása.

---

## Rövid „interjúbarát” megfogalmazás
„A képernyőszélesség kihasználási problémát nem komponens-hibaként, hanem globális konténer-korlátként azonosítottam. A `max-width: 1700px` szabályt oldalszinten felülírtam, majd az oszloparányokat újraterveztem úgy, hogy a középső konfigurációs panel kapja a legtöbb helyet. Ezzel az oldal áttekinthetőbb lett, és a felhasználói munkafolyamat gyorsabbá vált.”

---

## Kapcsolódó fájlok
- `webserver/control_edudisplej_sk/admin/style.css` (globális `.container` szabály)
- `webserver/control_edudisplej_sk/dashboard/group_loop.php` (lokális felülírás és oszloparányok)
