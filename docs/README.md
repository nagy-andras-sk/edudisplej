# EduDisplej

Az **EduDisplej** egy oktatási intézményekre szabott digitális kijelzőrendszer.
Célja, hogy a kioszkok központi felületről kezelhetően, ütemezetten és biztonságosan jelenítsenek meg információkat (pl. órarend, menza, PDF, hirdetések).

## Rövid technikai áttekintés

- **Architektúra:** központi webes vezérlőfelület + kliens oldali kioszk futtatókörnyezet.
- **Kommunikáció:** API alapú szinkron, a kioszk rendszeresen lekéri a konfigurációt és visszaküldi az állapotadatokat.
- **Moduláris működés:** a tartalom modulokból áll, amelyek időzítés és beállítás alapján rotálódnak.
- **Üzemeltetés:** automatikus frissítés és távoli menedzsment funkciók támogatása.
- **Biztonság:** token alapú eszköz-hitelesítés, szerveroldali jogosultságkezelés, auditálható admin folyamatok.

## Mire jó a gyakorlatban?

- Iskolai információk egységes, központi publikálása
- Kijelzők gyors tartalomfrissítése helyszíni beavatkozás nélkül
- Több intézmény / több kijelző egy rendszerből történő kezelése

## Jogi és felhasználási nyilatkozat

Az EduDisplej projekt nyilvánosan elérhető GitHubon átláthatósági és portfólió célból. A forráskód nem nyílt forráskódú, minden szerzői jog fenntartva, és a projekt csak a szerző előzetes, írásos engedélyével használható. A kód megtekintése megengedett, azonban annak felhasználása, módosítása, terjesztése vagy kereskedelmi alkalmazása nem engedélyezett külön licenc nélkül. A projekt célja bemutatni a megoldás koncepcióját, nem pedig szabad felhasználás biztosítása.

## Készítette

**Nagy András, 2026**

