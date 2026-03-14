# EduDisplej – Teljes projektleírás

Ez a dokumentum egyben, közérthetően és technikailag is pontosan foglalja össze az EduDisplej rendszert.

---

## 1) Alapok – mire épül, min fut

## 1.1 Projekt célja
Az EduDisplej egy központilag menedzselhető digitális kijelzőrendszer, amely tipikusan iskolai és intézményi környezetben használható. A központi admin felületről kezelhető a tartalom, az ütemezés, a kijelzők állapota és az operatív működés.

## 1.2 Fő technológiai alapok
- Szerveroldal: PHP alapú webalkalmazás (vezérlőpanel, admin, dashboard, API)
- Adattárolás: MySQL/MariaDB alapú relációs adatbázis
- Kliens oldali kijelző: Raspberry Pi alapú kioszk környezet
- Kioszk futtatási stack: Linux + systemd service-ek + X/Openbox + böngésző alapú megjelenítés
- Kommunikáció: HTTP API hívások token/session alapú hitelesítéssel

## 1.3 Fő futtatási környezetek
### A) Központi vezérlő szerver
- webes admin és dashboard felületek
- API végpontok a kijelzők és integrációk számára
- jogosultságkezelés, naplózás, tenant/company izoláció

### B) Kijelző oldali eszköz (Raspberry Pi)
- automatikusan induló service-ek (kiosk, sync, health, screenshot, command executor, watchdog)
- periodikus szinkron a szerverrel
- lokális tartalomletöltés és megjelenítés

## 1.4 Fő repository zónák
- `webserver/control_edudisplej_sk/`: fő alkalmazás (admin, dashboard, API, modulok)
- `webserver/www_edudisplej_sk/`: publikus webes rész
- `install/`: telepítési és service életciklus elemek
- `docs/`: technikai, üzemeltetési és audit dokumentáció
- `tests/`: teszt és audit segédállományok

---

## 2) Hogyan működik a rendszer

## 2.1 Logikai működési modell
A rendszer központja a vezérlőpanel. A kioszk eszközök időzítetten kapcsolódnak a központhoz, lekérik a friss konfigurációt és moduladatokat, majd visszaküldik az állapotot (health, szinkron eredmény, opcionálisan screenshot és logok).

## 2.2 Tipikus adatutak
### A) Tartalomkezelési útvonal
1. Operátor/admin módosítja a tartalmat vagy modulbeállításokat.
2. A backend eltárolja az új állapotot.
3. A kioszk következő sync ciklusban lekéri a változást.
4. A kijelző lokálisan frissíti a lejátszási állapotot és megjeleníti az új tartalmat.

### B) Eszköz-telemetria útvonal
1. Kioszk health adatot gyűjt (pl. CPU, memória, hálózat, service állapot).
2. Health service elküldi a központi API-nak.
3. Admin felületen láthatóvá válik az állapot és a problémák.

### C) Távoli művelet útvonal
1. Admin oldalon műveletet kezdeményeznek (pl. restart, fast loop, update).
2. Kijelző oldali command executor service periodikusan lekéri a függő parancsokat.
3. Végrehajtja a parancsot és visszajelent a szervernek.

## 2.3 Rétegek és felelősség
- Presentation: admin/dashboard/login nézetek
- Application: kérésfeldolgozás és use-case vezérlés
- Domain: üzleti szabályok (felhasználók, tenantok, kijelzők, modulok)
- API/Integráció: kioszk és külső kliensek gépi interfésze
- Perzisztencia: adatbázis + fájlalapú állapotok
- Üzemeltetés: cron, service menedzsment, telepítés, monitoring

## 2.4 Szinkron és ciklikus működés
- Sync service periodikusan lekéri a szerver állapotot és frissítéseket.
- Health service periodikusan riportál.
- Screenshot service policy alapján küld képet.
- Watchdog ellenőrzi a kijelző futtatási környezetet.
- A rendszer képes gyorsított ciklusra is (fast loop), ha üzemeltetési célból szükséges.

---

## 3) Hogyan lehet használni

## 3.1 Alap használati forgatókönyv
1. Admin belép a vezérlőpanelre.
2. Felveszi vagy kezeli a felhasználókat és jogosultságokat.
3. Regisztrálja és céghez/tenanthoz rendeli a kijelzőket.
4. Beállítja a modulokat és a tartalom rotációt.
5. Ütemezi a megjelenítést és ellenőrzi az állapotot.

## 3.2 Napi operátori használat
- tartalmak frissítése
- ütemezés ellenőrzése
- kiosk státusz monitorozása
- hibalisták és logok átnézése

## 3.3 Több intézmény / több tenant működés
A rendszer több cég/tenant egyidejű kezelésére készült. A jogosultság és adat-hozzáférés company szinten szeparált, így az egyes tenantok normál működésben egymás adatait nem érik el.

## 3.4 Kijelző oldali használat
A kioszk eszköz tipikusan felügyelet nélkül fut:
- automatikus indulás boot után,
- automatikus szinkron a központtal,
- automatikus tartalomfrissítés,
- automatikus állapotriport.

## 3.5 Üzemeltetési ajánlott rutin
- rendszeres státuszellenőrzés admin nézetben,
- kritikus service-ek állapotának figyelése,
- naplóelemzés rendellenes esetekben,
- változások után célzott funkcionális ellenőrzés.

---

## 4) Biztonság

## 4.1 Hitelesítés és hozzáférés
- API oldalon Bearer token alapú eszköz és kliens hitelesítés támogatott.
- Webes felületen session alapú belépés és szerepkör-ellenőrzés van.
- Admin funkciók emelt jogosultsághoz kötöttek.

## 4.2 Jogosultság és tenant izoláció
- A hozzáférési ellenőrzések szerepkör- és company-szinten működnek.
- A lekérdezésekben company alapú szűrés biztosítja az adatelválasztást.
- Nem admin felhasználó normál esetben csak a saját tenant adatait éri el.

## 4.3 Bemenetkezelés és adathozzáférés
- SQL műveletek túlnyomórészt prepared statement mintát követnek.
- Input validáció és sanitization célja az injekciós/XSS felület csökkentése.
- Biztonsági utility-k külön konfigurációs pontban összpontosulnak.

## 4.4 Kriptográfia és kulcskezelési alapok
- Titkosítási segédfüggvények rendelkezésre állnak érzékeny adatokhoz.
- API token hash-elése és ellenőrzése támogatott.
- Produkcióban környezeti változóból származó kulcskezelés javasolt.

## 4.5 Védelmi kiegészítések
- Biztonsági headerek beállítása.
- HTTPS környezet támogatása és kényszerítés opció.
- Rate limit helper elérhető API védelemhez.
- Biztonsági események naplózása támogatott.

## 4.6 Üzemeltetési biztonsági gyakorlat
- Erős jelszó és role-minimum elv.
- Tokenek és kulcsok rendszeres rotációja.
- API végpontok jogosultsági auditja.
- Incidensek után visszamenőleges logelemzés és javító intézkedés.

---

## Rövid zárás

Az EduDisplej egy rétegezett, központi vezérlésű kijelzőplatform, amely a napi tartalomüzemeltetést automatizált kioszk működéssel és erős jogosultsági modellel támogatja. A rendszer gyakorlati ereje a moduláris tartalomkezelés, a periodikus állapot-visszacsatolás és a jól szétválasztható üzemeltetési felelősségek kombinációjából adódik.
