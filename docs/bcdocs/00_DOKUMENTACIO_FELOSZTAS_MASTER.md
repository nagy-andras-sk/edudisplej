# EduDisplej – Részletes programleírás (MASTER)

Cél: egy olyan átfogó dokumentációs alap készítése, amely **rétegről rétegre** mutatja be a rendszer működését, és párhuzamosan támogatja a három fő megközelítést:
- felhasználói szempont,
- mappastruktúra alapú olvasat,
- technikai felépítés és függőségek.

---

## 1) Dokumentációs cél és olvasási stratégia

Ez a fájl nem végleges kézikönyv, hanem a teljes dokumentáció „gerince”.

### Mit ad ez a leírás?
- közös fogalmi térképet a projekthez,
- rétegezett rendszerképet (ki mit használ, mi mire épül),
- tiszta átjárást a kódstruktúra és az üzemi működés között,
- alapot a későbbi, fejezetenként részletes kidolgozáshoz.

### Milyen sorrendben érdemes olvasni?
1. Felhasználói nézőpont (üzleti és operátori folyamatok)
2. Mappastruktúra (hol található az adott funkció)
3. Technikai rétegek (hogyan működik belül)
4. Keresztmetszeti elemek: biztonság, naplózás, üzemeltetés

---

## 2) Háromnézőpontos dokumentációs keret

## A) Felhasználói szempont

Fókusz: mit lát és mit csinál a rendszerben az egyes szerepkör.

### A.1 Szereplők
- rendszeradminisztrátor,
- cég/tenant admin,
- operátor vagy tartalomkezelő,
- kijelző oldali futtatási környezet (kiosk).

### A.2 Tipikus folyamatok
- bejelentkezés és jogosultság-alapú belépés,
- felhasználó- és szerepkörkezelés,
- kijelzők és csoportok kezelése,
- modulok és tartalmak publikálása,
- ütemezés és tartalomváltás,
- hibák és események visszakeresése.

### A.3 Kritikus üzemi helyzetek
- új tenant indulása,
- tartalomcsere forgalmas időszakban,
- offline/instabil hálózat melletti működés,
- incidenskezelés és helyreállítás.

---

## B) Mappastruktúra alapú nézőpont

Fókusz: a repository fizikai felépítése és felelősségi zónái.

### B.1 Fő mappák szerepe
- `docs/`: üzleti, műszaki, audit és üzemeltetési dokumentáció,
- `tests/`: ellenőrzési és audit segédfájlok,
- `webserver/control_edudisplej_sk/`: fő alkalmazási backend + admin + dashboard + API,
- `webserver/www_edudisplej_sk/`: publikus webfelület elemei,
- `install/`: telepítési, image- és service-szintű automatizálás.

### B.2 `control_edudisplej_sk` részrendszerei
- belépési pontok és navigációs gyűjtőfájlok,
- `admin/`: adminisztrációs felületi logika,
- `api/`: gépi interfész és integrációs végpontok,
- `dashboard/`, `dashboard_user/`: operátori és felhasználói nézetek,
- `modules/`: tartalom- és funkciómodulok,
- `cron/`: időzített feladatok,
- `logs/`, `uploads/`: futás közben keletkező adat- és naplóterületek,
- `scripts/`: kiegészítő futtatási segédlogika.

### B.3 Fő konfigurációs és „kapu” fájlok
- `dbkonfiguracia.php`, `dbjavito.php`: adatkapcsolat és karbantartási segédpontok,
- `security_config.php`: biztonsági alapbeállítások,
- `auth_roles.php`: jogosultsági modell kötőpont,
- `logging.php`: naplózási csatornák,
- `i18n.php`: lokalizációs belépési felület.

---

## C) Technikai felépítés (mi mire épül)

Fókusz: rendszerarchitektúra, függőségi irányok és futási modell.

### C.1 Réteges architektúra
1. Megjelenítési réteg (UI/dashboard/login)
2. Alkalmazási vezérlési réteg (oldal- és folyamatvezérlés)
3. Domain/funkcionális réteg (felhasználók, modulok, kijelzők, ütemezés)
4. Integrációs/API réteg (külső és belső adatcsere)
5. Perzisztencia és állapot réteg (adatbázis + fájlalapú eszközök)
6. Üzemeltetési/infrastruktúra réteg (cron, telepítés, service-ek, logok)

### C.2 Függőségi szabály
- A felsőbb rétegek a alattuk lévő szolgáltatásokra épülnek.
- A domainlogika nem függhet közvetlenül UI-részletektől.
- Biztonság, naplózás és konfiguráció keresztmetszeti, több rétegen átívelő szolgáltatás.

---

## 3) Részletes rétegről-rétegre leírás

## 3.1 Megjelenítési réteg (Presentation)

### Felelősség
- kezelői felület megjelenítése,
- inputok begyűjtése,
- felhasználói műveletek indítása.

### Jellemző elemek
- `login.php`, `index.php`, dashboard oldalak,
- admin nézetek és űrlapok,
- modulkonfigurációs kezelőfelületek.

### Kapcsolat
- HTTP-kérésekkel hívja az alkalmazási és API réteget,
- session-alapú hozzáféréssel dolgozik,
- jogosultsági ellenőrzést központi helperre delegál.

---

## 3.2 Alkalmazási vezérlési réteg (Application)

### Felelősség
- use-case szintű folyamatok vezérlése,
- kérés-feldolgozás (validálás, branch-elés, válaszépítés),
- hibák és státuszok egységes kezelése.

### Jellemző belépési pontok
- `admin.php`, `users.php`, `companies.php`,
- `kiosk_status.php`, `kiosk_details.php`,
- API oldali endpoint belépési script-ek.

### Kapcsolat
- meghívja a domainműveleteket,
- perzisztenciát közvetve (helpereken/repository jellegű rétegen) használ,
- naplózási és biztonsági szolgáltatásokat kereszthivatkozással bevon.

---

## 3.3 Domain réteg (Business/Domain Logic)

### Felelősség
- fő üzleti entitások és szabályok kezelése:
  - felhasználók,
  - szerepkörök,
  - cégek/tenantok,
  - kijelzők és csoportok,
  - modulok és publikációs állapotok.

### Fő műveleti témák
- hozzáférési szabályok érvényesítése,
- tenant-izolációs döntések,
- tartaloméletciklus (létrehozás, módosítás, aktiválás, archiválás),
- ütemezési és kijelző-hozzárendelési döntések.

### Kapcsolat
- alkalmazási rétegtől kap inputot,
- adatrétegen keresztül állapotot olvas/ír,
- keresztmetszeti szolgáltatásokra (auth/logging/security) támaszkodik.

---

## 3.4 Integrációs és API réteg

### Felelősség
- kliens- és szolgáltatásközi adatcsere,
- szerződésalapú endpoint működés,
- jogosultság- és bemenetellenőrzés API-szinten.

### Jellemző működés
- endpoint hívás,
- auth ellenőrzés,
- payload validáció,
- domain művelet meghívás,
- strukturált válasz és naplóbejegyzés.

### Kapcsolat
- a dashboard/admin kliensek és külső integrációk innen kapnak adatot,
- függ a biztonsági konfigurációtól és adat-hozzáférési rétegtől.

---

## 3.5 Perzisztencia és állapot réteg

### Felelősség
- tartós adatok kezelése,
- fájl- és médiaállapot nyilvántartása,
- lekérdezési és frissítési műveletek stabil biztosítása.

### Tipikus elemek
- DB konfiguráció (`dbkonfiguracia.php`),
- adatjavítás/karbantartás (`dbjavito.php`),
- feltöltések és médiafájlok (`uploads/`),
- rendszerlogok (`logs/`).

### Kapcsolat
- domain és alkalmazási réteg innen olvas/ír,
- üzemeltetési réteg backup/karbantartási oldalról érinti.

---

## 3.6 Üzemeltetési/infrastruktúra réteg

### Felelősség
- időzített feladatok futtatása,
- telepítési és frissítési folyamatok,
- szolgáltatás szintű élesítési környezet biztosítása.

### Fő komponensek
- `cron/` feladatok és ütemezési rutinok,
- `install/` script-ek és service fájlok,
- offline installer és image build támogatás,
- környezeti inicializálás és migrációs lépések.

### Kapcsolat
- közvetlenül támogatja az alkalmazási és perzisztencia réteg stabil működését,
- naplózás és audit nyomvonal kötelező metszet.

---

## 4) Keresztmetszeti (cross-cutting) alrendszerek

## 4.1 Biztonság

### Fő elemek
- autentikációs ellenőrzések,
- szerepkör- és tenant-alapú jogosultságok,
- input validáció és támadási felület csökkentése,
- biztonsági konfiguráció központosítása (`security_config.php`).

### Működési elv
- minden réteg belépési pontján kötelező ellenőrzési pontok,
- API és UI útvonalakon egységes alapelvek,
- naplózható döntések és hibák.

---

## 4.2 Naplózás és auditálhatóság

### Fő elemek
- központi logolási helperek (`logging.php`),
- események konzisztens kategorizálása,
- incidens- és hibakeresés támogatása.

### Működési elv
- kritikus műveletekhez kötelező logpont,
- reprodukálható hibanyomvonal,
- üzemi és biztonsági események elkülönített kezelése.

---

## 4.3 Lokalizáció (i18n)

### Fő elemek
- nyelvi erőforrások (`lang/`),
- lokalizációs kezelő (`i18n.php`),
- UI réteg nyelvfüggő szövegfeloldása.

### Működési elv
- a felület nyelvi tartalma ne keveredjen a domainlogikával,
- fordíthatóság és karbantarthatóság rétegezett legyen.

---

## 5) Végponttól végpontig adat- és folyamatútvonal

## 5.1 Tipikus admin művelet útja
1. Admin UI kérés indítása
2. Alkalmazási réteg validáció és jogosultság ellenőrzés
3. Domain szabályok lefuttatása
4. DB/fájl állapot frissítése
5. Log bejegyzés és válasz visszaadása

## 5.2 Tipikus API művelet útja
1. Endpoint meghívás
2. Auth + input validáció
3. Domain művelet
4. Perzisztens állapot olvasás/írás
5. Strukturált API válasz + audit log

## 5.3 Tipikus ütemezett feladat útja
1. Cron trigger
2. Feladat-specifikus processzor futtatás
3. Adat/állapot szinkron vagy takarítás
4. Logolás + visszaellenőrizhető kimenet

---

## 6) Dokumentációs bontás javasolt al-fájlokra

A részletes kidolgozáshoz célszerű külön fájlokra bontani:

1. `01_BEVEZETO_ES_CELRENDSZER.md`
2. `02_FELHASZNALOI_NEZOPONT.md`
3. `03_MAPPASTRUKTURA_ALAPU_ATTEKINTES.md`
4. `04_TECHNIKAI_FELEPITES_ES_FUGGOSEGEK.md`
5. `05_UZEMELTETES_BIZTONSAG_NAPLOZAS.md`
6. `06_OSSZEFOGLALAS_ES_TOVABBLEPES.md`

---

## 7) Fejezetsablon a további kidolgozáshoz

## [Fejezet címe]

### Cél
- Mi a fejezet legfontosabb kimenete?

### Réteg és hatókör
- Mely rendszer-rétegeket érinti?
- Mely mappák/fájlok a legfontosabbak?

### Fő működés
- belépési pontok,
- vezérlési lépések,
- adatmozgás és állapotváltozás.

### Függőségek
- mit használ közvetlenül,
- mitől függ közvetetten,
- milyen keresztmetszeti szolgáltatásokat érint.

### Kockázat és üzemeltetési megjegyzés
- tipikus hibamód,
- monitorozási pont,
- helyreállítási szempont.

### Rövid összegzés
- 3–5 lényegi bullet.

---

## 8) Következő lépés

A következő körben a 6 al-fájl létrehozható üres, de címekkel és alcímekkel előkészített vázzal, így a teljes dokumentáció egységes szerkezetben lesz kitölthető.
