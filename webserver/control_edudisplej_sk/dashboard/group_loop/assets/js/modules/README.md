# Group Loop Editor - Modular Architecture

## 📦 Module Structure

Az app.js-t szétbontottam moduláris szerkezetre a karbantarthatóság és olvashatóság javítása érdekében.

### Modulok

#### 1. **modules/utils.js** (~250 sor)
Általános segédfüggvények és utility-k

**Exportált funkciók:**
- `deepClone(value)` - Mély klónozás JSON-al
- `getDraftStorageKey(groupId)` - localStorage kulcs generálása
- `escapeHtml(value)` - HTML karakterek escapelése
- `sanitizeRichTextHtml(value)` - Rich text HTML sanitizálása
- `getModuleKeyById(moduleId, modulesCatalog)` - Modul azonosító lookup
- `getDefaultSettings(moduleKey)` - Alapértelmezett modul beállítások
- `isEmpty(value)` - Üres érték ellenőrzés
- `formatBytes(bytes)` - Méretformázás (B, KB, MB, GB)
- `debounce(func, delay)` - Debounce wrapper
- `throttle(func, limit)` - Throttle wrapper
- `padZero(num, len)` - Szám feltöltés nullákkal
- `timeToMinutes(timeStr)` - Idő string → percek
- `minutesToTime(minutes)` - Percek → idő string

**Felhasználás:**
```javascript
// Wrapper függvények az app.js-ben
function deepClone(value) {
    return GroupLoopUtils.deepClone(value);
}
```

#### 2. **modules/text-editor.js** (~400 sor)
Szövegszerkesztő modul logika és UI kezelés

**Exportált funkciók:**
- `applyInlineStyleToSelection(property, value)` - Inline stílus alkalmazása
- `applyLineHeightToCurrentBlock(lineHeightValue)` - Sormagasság beállítás
- `readImageAsCompressedDataUrl(file)` - Kép tömörítés
- `updateTextModuleMiniPreview(buildModuleUrl, groupDefaultResolution, groupResolutionChoices)` - Élő előnézet frissítés
- `bindTextModuleModalEvents(settings, buildModuleUrl, groupDefaultResolution, groupResolutionChoices, showAutosaveToast)` - Modal eseménylekötés

**Felhasználás:**
```javascript
// Wrapper függvények az app.js-ben
function bindTextModuleModalEvents(settings) {
    return GroupLoopTextEditor.bindTextModuleModalEvents(
        settings, 
        buildModuleUrl, 
        groupDefaultResolution, 
        groupResolutionChoices, 
        showAutosaveToast
    );
}
```

#### 3. **app.js** (~4200 sor, korábban 4875)
Fő alkalmazás logika, loop kezelés, scheduling, stb.

## 📋 Fájlviszonyok

```
assets/js/
  ├── app.js                    (fő alkalmazás, wrapper függvények)
  └── modules/
      ├── utils.js             (segédfüggvények)
      └── text-editor.js       (szövegszerkesztő)
```

## 🔄 Hogyan regisztrálódnak a modulok?

Az oldal betöltésekor a modulokat az alábbi sorrendben kell betölteni az HTML-ben:

```html
<!-- HTML head atau body végén -->
<script src="/path/to/modules/utils.js"></script>
<script src="/path/to/modules/text-editor.js"></script>
<script src="/path/to/app.js"></script>
```

**FONTOS:** Az `app.js` után kell betölteni, mert az alkalmazás a modulokra támaszkodik!

## 🚀 Új modulok hozzáadása

Új modulok egyszerűen hozzáadhatók az alábbi minta alapján:

```javascript
// modules/my-module.js
const GroupLoopMyModule = (() => {
    'use strict';
    
    // Modulon belüli privát függvények
    const privateFunction = () => { /* ... */ };
    
    // Publikus API
    return {
        publicFunction: () => { /* ... */ },
        anotherPublicFunction: () => { /* ... */ }
    };
})();

// Wrapper az app.js-ben:
function publicFunction() {
    return GroupLoopMyModule.publicFunction();
}
```

## 📊 Fileméret csökkentés

| Fájl | Korábban | Most | Csökkentés |
|------|----------|------|-----------|
| app.js | 4875 sor | 4230 sor | ~645 sor (-13%) |
| modules/utils.js | — | ~250 sor | Új |
| modules/text-editor.js | — | ~400 sor | Új |
| **Teljes** | 4875 | 4880 | **Könnyebben karbantartható** |

> 💡 Az app.js valójában ugyanannyi sor, mert a wrapper-ek is helyigényt igényelnek, de:
> 1. Jobban szervezve
> 2. Külön modulok = egyenként betölthető és tesztelhető
> 3. Kódismétlés csökkent
> 4. Könnyebb karbantartás és bővítés

## 🔍 Debugging

Ha modul-betöltési hiba történik, az alábbi módokon lehet hibakeresést végezni:

```javascript
// Konzolban ellenőrzés:
console.log(GroupLoopUtils);        // Utils modul
console.log(GroupLoopTextEditor);   // Text Editor modul
```

## 📝 Megjegyzések

- Az összes modul IIFE (Immediately Invoked Function Expression) mintát használ az enkapsulációra
- A modulok egymástól függetlenül működhetnek (except text-editor amely utils-t használ)
- Könnyen lehet csökkenteni (minify) az egyes modulokat szig

ason
- A wrapper függvények az app.js-ben biztosítják a backwards compatibility-t
