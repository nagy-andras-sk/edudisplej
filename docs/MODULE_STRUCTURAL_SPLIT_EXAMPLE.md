# Modul strukturális felosztás – gyakorlati példa (Étrend modul)

## Miért fontos ez a felosztás?

A mostani módosítások jól mutatják, hogy az EduDisplej moduloknál a változtatásokat nem egy nagy fájlban, hanem funkcionális blokkokban végezzük.

Ennek a célja:
- gyorsabb hibakeresés,
- kisebb regressziós kockázat,
- könnyebb továbbfejlesztés,
- tisztább felelősségi határok.

## A jelenlegi gyakorlati példa

Az étrend modulnál az alábbi igények egyszerre kerültek bevezetésre:
- kis kijelzős sávos, oldallapozós megjelenítés,
- nagy kijelzős táblázatos mód,
- felső fejlécben óra + változó étkezéscímke,
- sorvégi allergénkódok külön megjelenítése,
- admin oldali konfigurációs opciók (join kapcsolók).

A módosítások strukturáltan, külön rétegekben történtek.

## Felosztás rétegenként

### 1) Admin konfigurációs réteg
Fájl: webserver/control_edudisplej_sk/dashboard/group_loop/assets/js/app.js

Feladat:
- beállítási mezők kirajzolása,
- értékek mentése,
- preview/runtime paraméterezés.

Példák ebből a rétegből:
- új kapcsolók: reggeli+dezsi összevonás, ebéd+olovrant összevonás,
- kis kijelző lapozási idő kezelése,
- beállítások query paraméterként átadása a modul renderer felé.

### 2) Runtime renderer réteg
Fájl: webserver/control_edudisplej_sk/modules/meal-menu/m_meal_menu.html

Feladat:
- tényleges kijelzőn megjelenő layout és viselkedés,
- lapozási logika,
- allergén-string feldolgozás,
- sorok automatikus betűméret-illesztése.

Példák ebből a rétegből:
- kis kijelzőn teljes oldal váltása beállított idő szerint,
- felső címke oldalankénti váltása,
- sorokban csak ételnév megjelenítése,
- zárójeles allergénkódok külön badge-re bontása.

### 3) API/adatforrás réteg
Fájlok: API endpointok (pl. meal_plan.php), valamint admin oldali betöltő logika

Feladat:
- adatok lekérése,
- deduplikáció,
- hibakezelés és státusz visszajelzés.

Példa:
- intézménylista duplikáció kezelés (azonos étkezde ne jelenjen meg többször).

## Mit nyertünk ezzel a felosztással?

1. Lokális módosíthatóság
- UI beállítás bővítéshez elég az admin réteget módosítani.
- Layout viselkedéshez elég a renderer réteget módosítani.

2. Gyorsabb hibaanalízis
- Ha csak render hiba van, nem kell az API vagy admin mentéslogika teljes láncát újraírni.

3. Biztonságosabb iteráció
- Kisebb diffek, kisebb kockázat.
- Pontosabban mérhető, hol történt regresszió.

4. Új funkciók egyszerűbb bevezetése
- Például join kapcsolók: admin oldalon felvesszük, renderer oldalon alkalmazzuk, API nem sérül.

## Javasolt fejlesztői minta minden modulhoz

1) Először döntsd el, melyik réteget érinti a változás:
- beállítás?
- runtime megjelenítés?
- adatforrás?

2) Rétegenként külön commit logikát kövess:
- admin/settings,
- runtime/layout,
- data/api.

3) Minden új kapcsolóhoz legyen:
- UI mező,
- collect/save,
- preview átadás,
- runtime default + parse + apply.

## Rövid összegzés

A strukturális felosztás nem „extra adminisztráció”, hanem közvetlen fejlesztési előny:
- gyorsabb változtatás,
- kevesebb hibalehetőség,
- tisztább karbantartás,
- stabilabb modul evolúció.

Az étrend modul mostani fejlesztése ennek egy valós, működő példája.
