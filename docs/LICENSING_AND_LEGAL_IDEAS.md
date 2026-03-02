# EduDisplej – Licencelési és jogi ötletek

Ez a dokumentum koncepcionális ötletanyag a projekt jövőbeli licencelési és jogi kereteihez.
Nem minősül jogi tanácsadásnak.

## 1) Licencelési modellek (ötletek)

- **Per-kijelző licenc:** minden aktív kioszk külön licencslotot használ.
- **Céges csomag licenc:** intézményenként fix csomag (pl. 5 / 20 / 50 kijelző).
- **Modul alapú licenc:** alapcsomag + külön fizetős modulok (pl. PDF, menza, occupancy).
- **Időalapú licenc:** havi/éves előfizetés, lejárat után korlátozott funkciók.

## 2) Jogi alapelvek (ötletek)

- **Proprietary státusz egyértelműsítése:** "Minden jog fenntartva" minden hivatalos felületen.
- **Felhasználási feltételek:** külön ToS/ÁSZF dokumentum, tiltott felhasználások pontos listájával.
- **Szerzői jogi nyilatkozat:** forráskód megtekinthető, de felhasználás/módosítás/terjesztés csak külön engedéllyel.
- **Felelősségkorlátozás:** szolgáltatáskimaradás, adatvesztés és harmadik fél szolgáltatásai esetére.

## 3) Fájltárolás és feldolgozás (ötletek)

- **Fájlbesorolás:** publikus média, vállalati tartalom, bizalmas admin export külön kezelése.
- **Metaadat-kezelés:** feltöltő, időbélyeg, cégazonosító, ellenőrző hash tárolása.
- **Feldolgozási pipeline:** feltöltés → validáció → átkódolás/optimalizálás → publikálás.
- **Retenciós szabályok:** napló, screenshot és ideiglenes fájlok automatikus törlési ideje.
- **Auditálhatóság:** ki, mikor, mit töltött fel / módosított / törölt.

## 4) Felület- és UX ötletek

- **Licenc állapot kártya:** admin nézetben egyszerűen látszódjon: aktív, lejárt, korlátozott.
- **Korlát visszajelzés:** ha elfogy a slot, egyértelmű hiba + teendő javaslat.
- **Jogi státusz jelölés:** forráskód-oldalakon diszkrét, de jól látható jogi badge.
- **Feltöltési visszajelzés:** pipeline lépések állapota (validálás, feldolgozás, kész).

## 5) Rövid megvalósítási javaslat

1. Egységes `LICENSE_NOTICE.md` + rövid kivonat a fő README-ben.
2. Külön `TERMS_OF_USE.md` dokumentum jogi nyelvezettel.
3. Admin felületen licenc státusz és limit API endpointok bevezetése.
4. Fájlfeldolgozási naplózás és automatikus retenciós szabályok finomítása.
