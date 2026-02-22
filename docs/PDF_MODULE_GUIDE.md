# PDF Modul - Telepítési és Felhasználói Útmutató

## 🎯 Áttekintés

A **PDF modul** (module_key: `pdf`) lehetővé teszi PDF-ek megjelenítését az edudisplej kioskokon testreszabható beállításokkal.

## 📦 Telepítés

1. **Modul mérete**: A PDF fájlok base64-ben tárolódnak a settings-ben
2. **Szükséges lépések**:
   - Admin → Modulok → ZIP import
   - Vagy a rendszer már tartalmazza ezt a modult

## ⚙️ Beállítások és Konfigurációk

### Szükséges Settings Mezők

| Mező | Típus | Lehetséges értékek | Default | Magyarázat |
|------|-------|-------------------|---------|-----------|
| **pdfDataBase64** | string | Tetszőleges base64 | `""` | A PDF fájl base64 kódolása (max 50MB) |
| **orientation** | enum | `landscape`, `portrait` | `landscape` | A PDF nézet orientációja |
| **zoomLevel** | int | 50-400 | 100 | Zoom szint százalékban |
| **navigationMode** | enum | `manual`, `auto` | `manual` | Kezelés módja |
| **displayMode** | enum | `fit-page`, `fit-width`, `fit-height` | `fit-page` | Megjelenítési mód |
| **autoScrollSpeedPxPerSec** | int | 5-200 | 30 | Automatikus görgetés sebessége pixel/másodperc |
| **autoScrollStartPauseMs** | int | 0-15000 | 2000 | Kezdeti várakozás (ms) |
| **autoScrollEndPauseMs** | int | 0-15000 | 2000 | Végső várakozás (ms) |
| **pausePoints** | string | JSON array formátum | `[]` | Megállási pontok (1. rész alatt) |
| **fixedViewMode** | bool | `true`, `false` | `false` | Csak egy oldal megjelenítése |
| **fixedPage** | int | 1-9999 | 1 | Rögzített oldal száma (ha fixedViewMode=true) |
| **bgColor** | color | Hex szín (#ffffff) | `#ffffff` | Háttérszín |
| **showPageNumbers** | bool | `true`, `false` | `true` | Oldalszámok megjelenítése |

### Pause Points - Megállási Pontok

Lehetőséget ad arra, hogy az automatikus görgetés bizonyos helyeken megálljon, majd várjon.

**JSON formátum**:
```json
[
  {
    "page": 1,
    "scrollPosition": 500,
    "waitMs": 3000
  },
  {
    "page": 2,
    "scrollPosition": 0,
    "waitMs": 2000
  }
]
```

**Paraméterek**:
- `page`: Oldalszám (1-től kezdve)
- `scrollPosition`: A görgetési pozíció pixelben az oldalon belül
- `waitMs`: Várakozási idő milliszekundumban

---

## 🎮 Használati Esetek

### 1. **Egyszerű PDF megjelenítés (manual mód)**
```python
settings = {
    "pdfDataBase64": "<base64_encoded_pdf>",
    "navigationMode": "manual",
    "zoomLevel": 100,
    "displayMode": "fit-page",
    "fixedViewMode": False
}
```
- Felhasználó kézzel navigálhat a PDF-ben
- Kontroller gombok: Előző, Következő, Zoom in/out

### 2. **Automatikus görgetés (presentation mód)**
```python
settings = {
    "pdfDataBase64": "<base64_encoded_pdf>",
    "navigationMode": "auto",
    "autoScrollSpeedPxPerSec": 40,
    "autoScrollStartPauseMs": 3000,
    "autoScrollEndPauseMs": 2000,
    "durationSeconds": 30  # Az összes oldalnak 30 másodperc alatt végig kell futnia
}
```
- Automatikus görgetés az összes oldalon végig
- Eleinte várakozik 3 másodpercet, végül 2-t

### 3. **Rögzített oldal (single page mód)**
```python
settings = {
    "pdfDataBase64": "<base64_encoded_pdf>",
    "fixedViewMode": True,
    "fixedPage": 3,  # Csak a 3. oldal jelenik meg
    "navigationMode": "manual"  # Ha szeretnénk kézi kontrollert
}
```
- Csak az adott oldal jelenik meg
- Jó poster/hirdetéshez

### 4. **Megállási pontokkal (presentation + pause)**
```python
settings = {
    "pdfDataBase64": "<base64_encoded_pdf>",
    "navigationMode": "auto",
    "autoScrollSpeedPxPerSec": 50,
    "pausePoints": [
        {"page": 1, "scrollPosition": 2000, "waitMs": 5000},  # Az 1. oldal közepén 5 mp várakozás
        {"page": 3, "scrollPosition": 0, "waitMs": 3000}       # A 3. oldal tetején 3 mp várakozás
    ],
    "durationSeconds": 60
}
```

---

## 📝 PDF Feltöltés Admin Felületről

### Folyamat (Javasolt UI, ha még nincs megvalósítva):
1. Csoport Loop szerkesztés → Modulok → PDF modul hozzáadása
2. "PDF feltöltés" gomb klikk → Fájlválasztó
3. PDF → base64 konverzió (frontend-en vagy szerveren)
4. Beállítások konfigurálása:
   - Nézet: fekvő/álló
   - Zoom, navigáció típus
   - Ha auto: sebesség, pause pontok
5. Előnézet (kiosk preview vagy demo)
6. Mentés

---

## 🔧 API/Szerkesztés Végpont

A PDF beállítások ugyanúgy mentésre kerülnek, mint más modulok:

**Endpoint**: `/api/group_loop/config.php` (POST)

**Payload minta**:
```json
{
  "base_loop": [
    {
      "module_id": 25,
      "module_key": "pdf",
      "duration_seconds": 30,
      "settings": {
        "pdfDataBase64": "JVBERi0xLjQK...",
        "navigationMode": "manual",
        "zoomLevel": 100,
        "displayMode": "fit-page",
        "fixedViewMode": false,
        "fixedPage": 1,
        "autoScrollSpeedPxPerSec": 30,
        "autoScrollStartPauseMs": 2000,
        "autoScrollEndPauseMs": 2000,
        "pausePoints": [],
        "bgColor": "#ffffff",
        "showPageNumbers": true,
        "orientation": "landscape"
      }
    }
  ]
}
```

---

## 🎨 Előnézet (Preview)

A loop szerkesztőben a PDF előnézet:
- **Manual mód**: A PDF 1. oldala jelenik meg, kézzel navegálható
- **Auto mód**: A végtermék olvasható, de nem áll előnézetben (a kiosk fog futtatni)
- **Fixed mód**: Az adott oldal statikus előnézete

---

## ⚠️ Limitations & Notes

1. **PDF méret**: Base64 kódolás miatt csak ~50MB-ig támogatott
2. **Biztonsági szűrés**: A `pdfDataBase64` mező szanitizálásra kerül
3. **Böngészőkompatibilitás**: PDF.js használata (IE nem támogatott)
4. **Performance**: Nagyobb PDFs lassabb renderelés
5. **Automatikus görgetés**: Ha auto mód, akkor a `pausePoints` felül írhatja a sebességet

---

## 🐛 Hibaelhárítás

### PDF nem töltődik be:
- Ellenőrizd, hogy a `pdfDataBase64` valid base64
- Nézd meg a böngésző konzolja errort (DevTools)
- Ellenőrizd a PDF fájl korruptálva van-e

### Görgés túl gyors/lassú:
- Állítsd a `autoScrollSpeedPxPerSec` értéket
- Ha pause pontok vannak, azok figyelmen kívül hagyódnak

### Nem jelenik meg a kontroller:
- `navigationMode` = `manual` kell
- Vagy `fixedViewMode` = `false`

---

## 📚 Relacionált Fájlok

- **Renderer**: `webserver/control_edudisplej_sk/modules/pdf/m_pdf.html`
- **Config**: `webserver/control_edudisplej_sk/modules/pdf/config/default_settings.json`
- **Manifest**: `webserver/control_edudisplej_sk/modules/pdf/module.json`
- **Policy**: `webserver/control_edudisplej_sk/modules/module_policy.php` (pdf szekció)
- **Registry**: `webserver/control_edudisplej_sk/modules/module_registry.php` (pdf entry)

---

## 🎓 Fejlesztői Megjegyzések

### Base64 konverzió (PHP-ben):
```php
$pdfContent = file_get_contents('/path/to/file.pdf');
$base64 = base64_encode($pdfContent);
$settings['pdfDataBase64'] = $base64;
```

### JavaScript-ben (FileReader API):
```javascript
const fileInput = document.querySelector('input[type="file"]');
const file = fileInput.files[0];
const reader = new FileReader();
reader.onload = (e) => {
  const base64 = e.target.result.split(',')[1];
  settings.pdfDataBase64 = base64;
};
reader.readAsDataURL(file);
```

---

## 🔐 Biztonsági Megjegyzések

- A `pdfDataBase64` nagyméretű adat, fokozottan kezelendő
- Input validáció: csak base64 karakterek engedélyezve
- XSS: A PDF.js alapból sandboxolt
- CSRF: Szokásos CSRF tokenek a POST-nál

---

**Verzió**: 1.0  
**Módule Key**: `pdf`  
**Min. Támogatás**: Firefox 57+, Chrome 59+, Safari 12+
