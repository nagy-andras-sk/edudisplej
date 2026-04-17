        const groupLoopBootstrap = window.GroupLoopBootstrap || {};
        let loopItems = [];
        let loopStyles = [];
        let timeBlocks = [];
        let activeLoopStyleId = null;
        let defaultLoopStyleId = null;
        let activeScope = 'base';
        let scheduleWeekOffset = 0;
        let nextTempTimeBlockId = -1;
        const groupId = parseInt(groupLoopBootstrap.groupId || 0, 10);
        const companyId = parseInt(groupLoopBootstrap.companyId || 0, 10);
        const isDefaultGroup = !!groupLoopBootstrap.isDefaultGroup;
        const isContentOnlyMode = !!groupLoopBootstrap.isContentOnlyMode;
        const technicalModule = groupLoopBootstrap.technicalModule || null;
        const turnedOffLoopAction = groupLoopBootstrap.turnedOffLoopAction || null;
        const TURNED_OFF_LOOP_DURATION_SECONDS = 120;
        const modulesCatalog = Array.isArray(groupLoopBootstrap.modulesCatalog) ? groupLoopBootstrap.modulesCatalog : [];
        const localizedModuleNames = (groupLoopBootstrap.localizedModuleNames && typeof groupLoopBootstrap.localizedModuleNames === 'object')
            ? groupLoopBootstrap.localizedModuleNames
            : {};
        const i18nCatalog = (groupLoopBootstrap.i18n && typeof groupLoopBootstrap.i18n === 'object') ? groupLoopBootstrap.i18n : {};
        const specialOnlyMode = !!groupLoopBootstrap.specialOnly;
        const autoCreateSpecialLoop = !!groupLoopBootstrap.autoCreateSpecialLoop;
        const specialWorkflowStart = String(groupLoopBootstrap.specialWorkflowStart || '').trim();
        const specialWorkflowEnd = String(groupLoopBootstrap.specialWorkflowEnd || '').trim();
        const forcedSpecialLoopName = String(groupLoopBootstrap.forcedSpecialLoopName || '').trim();
        const queryParams = new URLSearchParams(window.location.search || '');
        const forceGroupLoopDebug = String(window.location.pathname || '').toLowerCase().includes('/dashboard/group_loop/index.php');
        const groupLoopDebugEnabled = forceGroupLoopDebug || queryParams.get('loop_debug') === '1' || localStorage.getItem('group_loop_debug') === '1';

        console.log('[GROUP_LOOP_DEBUG] app.js loaded', {
            href: window.location.href,
            path: window.location.pathname,
            debugEnabled: groupLoopDebugEnabled,
            forceDebug: forceGroupLoopDebug
        });

        function summarizeBlockTypes(blocks) {
            const summary = { weekly: 0, date: 0, datetime_range: 0, other: 0 };
            (Array.isArray(blocks) ? blocks : []).forEach((block) => {
                const type = String(block?.block_type || 'weekly').toLowerCase();
                if (Object.prototype.hasOwnProperty.call(summary, type)) {
                    summary[type] += 1;
                } else {
                    summary.other += 1;
                }
            });
            return summary;
        }

        function summarizeStyleNames(styles, limit = 8) {
            return (Array.isArray(styles) ? styles : [])
                .slice(0, limit)
                .map((style) => ({
                    id: parseInt(style?.id || 0, 10),
                    name: String(style?.name || ''),
                    items: Array.isArray(style?.items) ? style.items.length : 0
                }));
        }

        function summarizeBlockPreview(blocks, limit = 12) {
            return (Array.isArray(blocks) ? blocks : [])
                .slice(0, limit)
                .map((block) => ({
                    id: parseInt(block?.id || 0, 10),
                    type: String(block?.block_type || 'weekly'),
                    days_mask: String(block?.days_mask || ''),
                    specific_date: String(block?.specific_date || ''),
                    start: String(block?.start_time || ''),
                    end: String(block?.end_time || ''),
                    loop_style_id: parseInt(block?.loop_style_id || 0, 10),
                    block_name: String(block?.block_name || '')
                }));
        }

        function debugGroupLoop(label, details = null) {
            if (!groupLoopDebugEnabled) {
                return;
            }

            const prefix = '[GROUP_LOOP_DEBUG]';
            if (details === null || typeof details === 'undefined') {
                console.log(`${prefix} ${label}`);
                return;
            }

            console.log(`${prefix} ${label}`, details);
        }

        window.GroupLoopDebug = {
            enabled: groupLoopDebugEnabled,
            enable() {
                localStorage.setItem('group_loop_debug', '1');
                console.log('[GROUP_LOOP_DEBUG] enabled, refresh the page');
            },
            disable() {
                localStorage.removeItem('group_loop_debug');
                console.log('[GROUP_LOOP_DEBUG] disabled, refresh the page');
            },
            dump() {
                debugGroupLoop('runtime-state', {
                    groupId,
                    specialOnlyMode,
                    activeLoopStyleId,
                    defaultLoopStyleId,
                    activeScope,
                    loopStylesCount: Array.isArray(loopStyles) ? loopStyles.length : 0,
                    timeBlocksCount: Array.isArray(timeBlocks) ? timeBlocks.length : 0,
                    blockTypeSummary: summarizeBlockTypes(timeBlocks),
                    stylesPreview: summarizeStyleNames(loopStyles),
                    blocksPreview: summarizeBlockPreview(timeBlocks)
                });
            }
        };

        debugGroupLoop('bootstrap', {
            groupId,
            companyId,
            specialOnlyMode,
            autoCreateSpecialLoop,
            forcedSpecialLoopName,
            query: window.location.search || ''
        });

        function resolveSpecialWorkflowRange() {
            const pattern = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/;

            if (pattern.test(specialWorkflowStart) && pattern.test(specialWorkflowEnd)) {
                return { start: specialWorkflowStart, end: specialWorkflowEnd };
            }

            const query = new URLSearchParams(window.location.search || '');
            const qsStart = String(query.get('wf_start') || '').trim();
            const qsEnd = String(query.get('wf_end') || '').trim();
            if (pattern.test(qsStart) && pattern.test(qsEnd)) {
                return { start: qsStart, end: qsEnd };
            }

            return null;
        }

        function tr(key, fallback, vars = null) {
            let text = String(i18nCatalog[key] ?? fallback ?? key ?? '');
            if (!vars || typeof vars !== 'object') {
                return text;
            }
            Object.entries(vars).forEach(([name, value]) => {
                text = text.replace(new RegExp(`\\{${name}\\}`, 'g'), String(value ?? ''));
            });
            return text;
        }

        function resolveUiLang() {
            const customizationLabel = String(i18nCatalog['group_loop.customization'] || '').toLowerCase();
            if (customizationLabel.includes('testresz')) return 'hu';
            if (customizationLabel.includes('prispôsob') || customizationLabel.includes('prisposob')) return 'sk';
            if (customizationLabel.includes('custom')) return 'en';

            const loopHeader = String(i18nCatalog['group_loop.header.title'] || '').toLowerCase();
            if (loopHeader.includes('testresz')) return 'hu';
            if (loopHeader.includes('prispôsob') || loopHeader.includes('prisposob')) return 'sk';

            const htmlLang = String(document?.documentElement?.lang || '').toLowerCase();
            if (htmlLang.startsWith('hu')) return 'hu';
            if (htmlLang.startsWith('sk')) return 'sk';
            if (htmlLang.startsWith('en')) return 'en';
            return 'en';
        }

        function loopActionUiText(id) {
            const lang = resolveUiLang();
            const dict = {
                hu: {
                    customize: 'Testreszabás',
                    duplicate: 'Duplikálás',
                    delete: 'Törlés',
                    default_group_locked: 'A default csoport nem szerkeszthető'
                },
                sk: {
                    customize: 'Prispôsobenie',
                    duplicate: 'Duplikovať',
                    delete: 'Vymazať',
                    default_group_locked: 'Predvolená skupina sa nedá upravovať'
                },
                en: {
                    customize: 'Customize',
                    duplicate: 'Duplicate',
                    delete: 'Delete',
                    default_group_locked: 'Default group is not editable'
                }
            };
            return (dict[lang] && dict[lang][id]) || dict.en[id] || String(id || '');
        }

        function defaultLogoUiText(id) {
            const lang = resolveUiLang();
            const dict = {
                hu: {
                    fixed_content: 'Fix tartalom:',
                    tagline: 'Szívvel az oktatásért.',
                    not_editable: 'A default logo modul tartalma nem szerkeszthető.'
                },
                sk: {
                    fixed_content: 'Fixný obsah:',
                    tagline: 'Srdcom pre vzdelávanie.',
                    not_editable: 'Obsah modulu predvoleného loga nie je možné upraviť.'
                },
                en: {
                    fixed_content: 'Fixed content:',
                    tagline: 'With heart for education.',
                    not_editable: 'Default logo module content is not editable.'
                }
            };
            return (dict[lang] && dict[lang][id]) || dict.en[id] || String(id || '');
        }

        function galleryUiText(id) {
            const lang = resolveUiLang();
            const dict = {
                hu: {
                    upload_title: '🖼️ Képek feltöltése',
                    drop_or_click: 'Húzz ide képeket vagy',
                    click_to_pick: 'kattints a kiválasztáshoz',
                    limits: 'Max 10 kép, képenként max 15 MB',
                    cloud_title: '☁️ Korábban feltöltött képek (Company Cloud)',
                    refresh: 'Frissítés',
                    loading: 'Betöltés...',
                    import_selected: 'Kijelöltek importálása',
                    display_mode: 'Megjelenítési mód',
                    mode_slideshow: 'Slideshow',
                    mode_collage: 'Kollázs',
                    mode_single: 'Egy kép',
                    fit_mode: 'Kép igazítás',
                    slide_interval: 'Slideshow váltás (s)',
                    transition_enabled: 'Áttűnés bekapcsolva',
                    collage_columns: 'Kollázs oszlopok',
                    background_color: 'Háttérszín',
                    preview: 'Előnézet',
                    preview_empty: 'Tölts fel legalább 1 képet az előnézethez.'
                },
                sk: {
                    upload_title: '🖼️ Nahratie obrázkov',
                    drop_or_click: 'Pretiahnite sem obrázky alebo',
                    click_to_pick: 'kliknite pre výber',
                    limits: 'Max 10 obrázkov, max 15 MB na obrázok',
                    cloud_title: '☁️ Predtým nahraté obrázky (Company Cloud)',
                    refresh: 'Obnoviť',
                    loading: 'Načítavam...',
                    import_selected: 'Importovať vybrané',
                    display_mode: 'Režim zobrazenia',
                    mode_slideshow: 'Prezentácia',
                    mode_collage: 'Koláž',
                    mode_single: 'Jeden obrázok',
                    fit_mode: 'Prispôsobenie obrázka',
                    slide_interval: 'Interval prezentácie (s)',
                    transition_enabled: 'Zapnúť prechod',
                    collage_columns: 'Stĺpce koláže',
                    background_color: 'Farba pozadia',
                    preview: 'Náhľad',
                    preview_empty: 'Nahrajte aspoň 1 obrázok pre náhľad.'
                },
                en: {
                    upload_title: '🖼️ Upload images',
                    drop_or_click: 'Drop images here or',
                    click_to_pick: 'click to choose',
                    limits: 'Max 10 images, max 15 MB each',
                    cloud_title: '☁️ Previously uploaded images (Company Cloud)',
                    refresh: 'Refresh',
                    loading: 'Loading...',
                    import_selected: 'Import selected',
                    display_mode: 'Display mode',
                    mode_slideshow: 'Slideshow',
                    mode_collage: 'Collage',
                    mode_single: 'Single image',
                    fit_mode: 'Image fit',
                    slide_interval: 'Slideshow interval (s)',
                    transition_enabled: 'Enable transition',
                    collage_columns: 'Collage columns',
                    background_color: 'Background color',
                    preview: 'Preview',
                    preview_empty: 'Upload at least 1 image for preview.'
                }
            };
            return (dict[lang] && dict[lang][id]) || dict.en[id] || String(id || '');
        }

        function videoUiText(id) {
            const lang = resolveUiLang();
            const dict = {
                hu: {
                    upload_title: '🎬 Videó feltöltés (automatikus optimalizálás)',
                    drop_or_click: 'Húzz ide videót vagy',
                    click_to_pick: 'kattints a kiválasztáshoz',
                    limits: 'Böngészőben automatikus konvertálás: MP4 (H.264/AAC), max 1280×720, max 120 mp, max 25 MB',
                    cloud_title: '☁️ Korábban feltöltött videók',
                    refresh: 'Frissítés',
                    loading: 'Betöltés...',
                    import_selected: 'Kiválasztott importálása',
                    fit_mode: 'Kitöltés',
                    muted: 'Némítás',
                    muted_playback: 'Lejátszás némítva',
                    background_color: 'Háttérszín',
                    duration_fixed: 'Loop időtartam: {seconds} s (fix, videó hossza)',
                    duration_none: 'Loop időtartam: még nincs videó',
                    preview: 'Előnézet',
                    preview_empty: 'Tölts fel videót az előnézethez.',
                    fit_contain: 'Contain',
                    fit_cover: 'Cover',
                    fit_fill: 'Fill',
                    summary_video: 'Videó',
                    summary_muted: 'némítva',
                    summary_unmuted: 'hanggal'
                },
                sk: {
                    upload_title: '🎬 Nahratie videa (automatická optimalizácia)',
                    drop_or_click: 'Pretiahnite sem video alebo',
                    click_to_pick: 'kliknite pre výber',
                    limits: 'Automatická konverzia v prehliadači: MP4 (H.264/AAC), max 1280×720, max 120 s, max 25 MB',
                    cloud_title: '☁️ Predtým nahraté videá',
                    refresh: 'Obnoviť',
                    loading: 'Načítavam...',
                    import_selected: 'Importovať vybrané',
                    fit_mode: 'Prispôsobenie',
                    muted: 'Stlmiť zvuk',
                    muted_playback: 'Prehrávanie stlmené',
                    background_color: 'Farba pozadia',
                    duration_fixed: 'Trvanie loopu: {seconds} s (fixné podľa dĺžky videa)',
                    duration_none: 'Trvanie loopu: zatiaľ bez videa',
                    preview: 'Náhľad',
                    preview_empty: 'Nahrajte video pre náhľad.',
                    fit_contain: 'Contain',
                    fit_cover: 'Cover',
                    fit_fill: 'Fill',
                    summary_video: 'Video',
                    summary_muted: 'stlmené',
                    summary_unmuted: 'so zvukom'
                },
                en: {
                    upload_title: '🎬 Upload video (automatic optimization)',
                    drop_or_click: 'Drop video here or',
                    click_to_pick: 'click to choose',
                    limits: 'Automatic browser conversion: MP4 (H.264/AAC), max 1280×720, max 120 s, max 25 MB',
                    cloud_title: '☁️ Previously uploaded videos',
                    refresh: 'Refresh',
                    loading: 'Loading...',
                    import_selected: 'Import selected',
                    fit_mode: 'Fit',
                    muted: 'Mute',
                    muted_playback: 'Muted playback',
                    background_color: 'Background color',
                    duration_fixed: 'Loop duration: {seconds} s (fixed to video length)',
                    duration_none: 'Loop duration: no video yet',
                    preview: 'Preview',
                    preview_empty: 'Upload a video for preview.',
                    fit_contain: 'Contain',
                    fit_cover: 'Cover',
                    fit_fill: 'Fill',
                    summary_video: 'Video',
                    summary_muted: 'muted',
                    summary_unmuted: 'with sound'
                }
            };
            return (dict[lang] && dict[lang][id]) || dict.en[id] || String(id || '');
        }

        function overlayUiText(id) {
            const lang = resolveUiLang();
            const dict = {
                hu: {
                    header: '🧩 Overlay modulok (ráhúzható óra/szöveg)',
                    clock_toggle: 'Óra overlay bekapcsolása',
                    text_toggle: 'Szöveg overlay bekapcsolása',
                    position: 'Pozíció',
                    top: 'FENT',
                    bottom: 'LENT',
                    band_height: 'Sáv magasság (%)',
                    clock_color: 'Óra szín',
                    date_color: 'Dátum szín',
                    text_source: 'Szöveg forrása',
                    source_manual: 'Kézi szöveg',
                    source_collection: 'Szöveggyűjtemény',
                    source_external: 'Külső forrás (URL)',
                    font_size: 'Betűméret (px)',
                    color: 'Szín',
                    speed: 'Gördülési sebesség (px/s)',
                    text: 'Szöveg',
                    collection: 'Szöveggyűjtemény (1 sor = 1 elem)',
                    external_url: 'Külső forrás URL',
                    drop_clock_ok: '✓ Óra overlay hozzáadva a modulhoz',
                    drop_text_ok: '✓ Szöveg overlay hozzáadva a modulhoz'
                },
                sk: {
                    header: '🧩 Overlay moduly (pretiahnuteľné hodiny/text)',
                    clock_toggle: 'Zapnúť overlay hodín',
                    text_toggle: 'Zapnúť textový overlay',
                    position: 'Pozícia',
                    top: 'HORE',
                    bottom: 'DOLE',
                    band_height: 'Výška pásu (%)',
                    clock_color: 'Farba času',
                    date_color: 'Farba dátumu',
                    text_source: 'Zdroj textu',
                    source_manual: 'Ručný text',
                    source_collection: 'Kolekcia textov',
                    source_external: 'Externý zdroj (URL)',
                    font_size: 'Veľkosť písma (px)',
                    color: 'Farba',
                    speed: 'Rýchlosť posuvu (px/s)',
                    text: 'Text',
                    collection: 'Kolekcia textov (1 riadok = 1 položka)',
                    external_url: 'URL externého zdroja',
                    drop_clock_ok: '✓ Overlay hodín pridaný do modulu',
                    drop_text_ok: '✓ Textový overlay pridaný do modulu'
                },
                en: {
                    header: '🧩 Overlay modules (draggable clock/text)',
                    clock_toggle: 'Enable clock overlay',
                    text_toggle: 'Enable text overlay',
                    position: 'Position',
                    top: 'TOP',
                    bottom: 'BOTTOM',
                    band_height: 'Band height (%)',
                    clock_color: 'Clock color',
                    date_color: 'Date color',
                    text_source: 'Text source',
                    source_manual: 'Manual text',
                    source_collection: 'Text collection',
                    source_external: 'External source (URL)',
                    font_size: 'Font size (px)',
                    color: 'Color',
                    speed: 'Scroll speed (px/s)',
                    text: 'Text',
                    collection: 'Text collection (1 line = 1 item)',
                    external_url: 'External source URL',
                    drop_clock_ok: '✓ Clock overlay added to module',
                    drop_text_ok: '✓ Text overlay added to module'
                }
            };
            return (dict[lang] && dict[lang][id]) || dict.en[id] || String(id || '');
        }

        function mealUiText(id) {
            const lang = resolveUiLang();
            const dict = {
                hu: {
                    module_title: 'Étrend',
                    customization: 'Testreszabás',
                    source_type: 'Adatforrás',
                    source_manual: 'Manuális naptár',
                    source_server: 'Szerver (szinkron)',
                    static_language: 'Statikus nyelv',
                    display_profile: 'Kijelző profil',
                    mode_small: 'A) KIS KIJELZŐ — teljes képernyő, nagy sorok, lapozás (nem scroll)',
                    mode_large: 'B) NAGY KIJELZŐ — 4 egyenlő táblázatos blokk',
                    mode_hint: 'Kis kijelzőn fix betűméret használatos (2 soros töréssel).',
                    schedule_settings: 'Étkezés időzítés',
                    schedule_enabled: 'Napi étkezések láthatósága idő alapján',
                    schedule_breakfast_until: 'Reggeli vége (eddig látszik)',
                    schedule_snack_am_until: 'Tízórai vége (eddig látszik)',
                    schedule_lunch_until: 'Ebéd vége (eddig látszik)',
                    schedule_snack_pm_until: 'Uzsonna vége (eddig látszik)',
                    schedule_dinner_until: 'Vacsora vége (eddig látszik)',
                    show_tomorrow_after_passed: 'Ha a mai étkezés lejárt, mutassa a holnapi megfelelő étkezést',
                    small_row_font_size: 'Kis kijelző sor betűméret (px)',
                    small_header_row_bg: '1. sor háttérszín',
                    small_header_row_text: '1. sor bal szöveg színe',
                    small_header_row_clock: '1. sor óra színe',
                    small_header_row_title_font: '1. sor bal betűméret (px)',
                    small_header_row_clock_font: '1. sor óra betűméret (px)',
                    page_switch: 'Kis kijelző lapozás (másodperc)',
                    page_switch_hint: 'Csak A) KIS KIJELZŐ módban érvényes.',
                    small_start_mode: 'Kezdő étkezés megjelenítés',
                    small_start_current: 'Aktuális étkezéstől tovább',
                    small_start_breakfast: 'Reggelitől tovább',
                    small_start_lunch: 'Ebédtől tovább',
                    small_start_dinner: 'Vacsorától tovább',
                    small_max_meals: 'Max. megjelenített étkezések (1-5)',
                    small_header_scroll_speed: 'Felső sor vízszintes görgetés sebesség (px/mp)',
                    small_header_scroll_pause: 'Felső sor szélső megállás (ms)',
                    join_breakfast_snack: 'Raňajky + Desiata összevonás (kis kijelzőn)',
                    join_lunch_snack: 'Obed + Olovrant összevonás (kis kijelzőn)',
                    site: 'Forrás oldal',
                    institution: 'Intézmény',
                    refresh: 'Frissít',
                    loading_sources: 'Források betöltése...',
                    visible_meals: 'Megjelenítendő étkezések',
                    show_header: 'Főcím megjelenítése (Dnešné menu)',
                    custom_header: 'Egyedi főcím (opcionális)',
                    show_institution: 'Étkezde neve megjelenítése',
                    breakfast: 'Reggeli',
                    snack_am: 'Tízórai',
                    lunch: 'Ebéd',
                    snack_pm: 'Uzsonna',
                    dinner: 'Vacsora',
                    show_icons: 'Étkezés ikonok megjelenítése',
                    appetite_toggle: '„Prajeme dobrú chuť” sor megjelenítése',
                    appetite_text: 'Dobrú chuť szöveg',
                    source_url_toggle: 'Forrás URL megjelenítése alul',
                    source_url: 'Forrás URL',
                    choose_site_first: 'Válassz előbb forrás oldalt.',
                    loading_institutions: 'Intézmények betöltése...',
                    institutions_count: 'Intézmények: {count} db',
                    institution_error: 'Intézmény betöltési hiba.',
                    loading_sites: 'Forrás oldalak betöltése...',
                    no_sites: 'Nincs elérhető forrás oldal.',
                    site_error: 'Forrás oldal betöltési hiba.',
                    option_site: '-- Válassz forrás oldalt --',
                    option_institution: '-- Válassz intézményt --',
                    option_loading: 'Betöltés...',
                    no_meals_selected: 'Nincs kijelölt étkezés',
                    institution_short: 'intézmény',
                    language_short: 'nyelv',
                    icons_on: 'SVG ikon: be',
                    icons_off: 'SVG ikon: ki'
                },
                sk: {
                    module_title: 'Jedálny lístok',
                    customization: 'Prispôsobenie',
                    source_type: 'Zdroj dát',
                    source_manual: 'Manuálny kalendár',
                    source_server: 'Server (synchronizácia)',
                    static_language: 'Statický jazyk',
                    display_profile: 'Profil displeja',
                    mode_small: 'A) MALÝ DISPLEJ — celá obrazovka, veľké riadky, prepínanie strán (bez scrollu)',
                    mode_large: 'B) VEĽKÝ DISPLEJ — 4 rovnaké tabuľkové bloky',
                    mode_hint: 'Na malom displeji sa veľkosť písma nastavuje automaticky podľa dostupného priestoru.',
                    schedule_settings: 'Časovanie jedál',
                    schedule_enabled: 'Viditeľnosť denných jedál podľa času',
                    schedule_breakfast_until: 'Raňajky do času',
                    schedule_snack_am_until: 'Desiata do času',
                    schedule_lunch_until: 'Obed do času',
                    schedule_snack_pm_until: 'Olovrant do času',
                    schedule_dinner_until: 'Večera do času',
                    show_tomorrow_after_passed: 'Po prekročení dnešného času zobraz zajtrajšie zodpovedajúce jedlo',
                    small_row_font_size: 'Veľkosť písma riadkov na malom displeji (px)',
                    small_header_row_bg: 'Farba pozadia 1. riadku',
                    small_header_row_text: 'Farba textu vľavo (1. riadok)',
                    small_header_row_clock: 'Farba hodín (1. riadok)',
                    small_header_row_title_font: 'Veľkosť písma vľavo (1. riadok, px)',
                    small_header_row_clock_font: 'Veľkosť písma hodín (1. riadok, px)',
                    page_switch: 'Prepínanie strán na malom displeji (sekundy)',
                    page_switch_hint: 'Platí len pre režim A) MALÝ DISPLEJ.',
                    small_start_mode: 'Počiatočné jedlo zobrazenia',
                    small_start_current: 'Od aktuálneho jedla ďalej',
                    small_start_breakfast: 'Od raňajok ďalej',
                    small_start_lunch: 'Od obeda ďalej',
                    small_start_dinner: 'Od večere ďalej',
                    small_max_meals: 'Max. zobrazených jedál (1-5)',
                    small_header_scroll_speed: 'Rýchlosť vodorovného posuvu horného riadku (px/s)',
                    small_header_scroll_pause: 'Pauza na okrajoch horného riadku (ms)',
                    join_breakfast_snack: 'Spojiť Raňajky + Desiata (na malom displeji)',
                    join_lunch_snack: 'Spojiť Obed + Olovrant (na malom displeji)',
                    site: 'Zdrojová stránka',
                    institution: 'Inštitúcia',
                    refresh: 'Obnoviť',
                    loading_sources: 'Načítavam zdroje...',
                    visible_meals: 'Zobrazené jedlá',
                    show_header: 'Zobraziť hlavičku (Dnešné menu)',
                    custom_header: 'Vlastný nadpis (voliteľné)',
                    show_institution: 'Zobraziť názov jedálne',
                    breakfast: 'Raňajky',
                    snack_am: 'Desiata',
                    lunch: 'Obed',
                    snack_pm: 'Olovrant',
                    dinner: 'Večera',
                    show_icons: 'Zobraziť ikony jedál',
                    appetite_toggle: 'Zobraziť riadok „Prajeme dobrú chuť”',
                    appetite_text: 'Text „Dobrú chuť”',
                    source_url_toggle: 'Zobraziť URL zdroja dole',
                    source_url: 'URL zdroja',
                    choose_site_first: 'Najprv vyberte zdrojovú stránku.',
                    loading_institutions: 'Načítavam inštitúcie...',
                    institutions_count: 'Inštitúcie: {count}',
                    institution_error: 'Chyba načítania inštitúcií.',
                    loading_sites: 'Načítavam zdrojové stránky...',
                    no_sites: 'Nie je dostupná žiadna zdrojová stránka.',
                    site_error: 'Chyba načítania zdrojovej stránky.',
                    option_site: '-- Vyber zdrojovú stránku --',
                    option_institution: '-- Vyber inštitúciu --',
                    option_loading: 'Načítavam...',
                    no_meals_selected: 'Nie je vybrané žiadne jedlo',
                    institution_short: 'inštitúcia',
                    language_short: 'jazyk',
                    icons_on: 'SVG ikony: zap',
                    icons_off: 'SVG ikony: vyp'
                },
                en: {
                    module_title: 'Meal menu',
                    customization: 'Customization',
                    source_type: 'Data source',
                    source_manual: 'Manual calendar',
                    source_server: 'Server (sync)',
                    static_language: 'Static language',
                    display_profile: 'Display profile',
                    mode_small: 'A) SMALL SCREEN — fullscreen, large rows, page switching (no scroll)',
                    mode_large: 'B) LARGE SCREEN — 4 equal table blocks',
                    mode_hint: 'Small-screen rows use a fixed font size with 2-line wrapping.',
                    schedule_settings: 'Meal timing',
                    schedule_enabled: 'Daily meal visibility by time',
                    schedule_breakfast_until: 'Breakfast until',
                    schedule_snack_am_until: 'Morning snack until',
                    schedule_lunch_until: 'Lunch until',
                    schedule_snack_pm_until: 'Afternoon snack until',
                    schedule_dinner_until: 'Dinner until',
                    show_tomorrow_after_passed: 'After daily cutoff, show tomorrow equivalent meal',
                    small_row_font_size: 'Small-screen row font size (px)',
                    small_header_row_bg: 'Row 1 background color',
                    small_header_row_text: 'Row 1 left text color',
                    small_header_row_clock: 'Row 1 clock color',
                    small_header_row_title_font: 'Row 1 left font size (px)',
                    small_header_row_clock_font: 'Row 1 clock font size (px)',
                    page_switch: 'Small-screen page switch (seconds)',
                    page_switch_hint: 'Applies only to A) SMALL SCREEN mode.',
                    small_start_mode: 'Small-screen start mode',
                    small_start_current: 'From current meal onward',
                    small_start_breakfast: 'From breakfast onward',
                    small_start_lunch: 'From lunch onward',
                    small_start_dinner: 'From dinner onward',
                    small_max_meals: 'Max displayed meals (1-5)',
                    small_header_scroll_speed: 'Top-row horizontal scroll speed (px/s)',
                    small_header_scroll_pause: 'Top-row edge pause (ms)',
                    join_breakfast_snack: 'Join Breakfast + Morning snack (small screen)',
                    join_lunch_snack: 'Join Lunch + Afternoon snack (small screen)',
                    site: 'Source site',
                    institution: 'Institution',
                    refresh: 'Refresh',
                    loading_sources: 'Loading sources...',
                    visible_meals: 'Visible meals',
                    show_header: 'Show header (Dnešné menu)',
                    custom_header: 'Custom title (optional)',
                    show_institution: 'Show canteen name',
                    breakfast: 'Breakfast',
                    snack_am: 'Morning snack',
                    lunch: 'Lunch',
                    snack_pm: 'Afternoon snack',
                    dinner: 'Dinner',
                    show_icons: 'Show meal icons',
                    appetite_toggle: 'Show “Prajeme dobrú chuť” line',
                    appetite_text: '“Dobrú chuť” text',
                    source_url_toggle: 'Show source URL at bottom',
                    source_url: 'Source URL',
                    choose_site_first: 'Select a source site first.',
                    loading_institutions: 'Loading institutions...',
                    institutions_count: 'Institutions: {count}',
                    institution_error: 'Institution loading error.',
                    loading_sites: 'Loading source sites...',
                    no_sites: 'No source site available.',
                    site_error: 'Source site loading error.',
                    option_site: '-- Select source site --',
                    option_institution: '-- Select institution --',
                    option_loading: 'Loading...',
                    no_meals_selected: 'No meal selected',
                    institution_short: 'institution',
                    language_short: 'language',
                    icons_on: 'SVG icons: on',
                    icons_off: 'SVG icons: off'
                }
            };
            return (dict[lang] && dict[lang][id]) || dict.en[id] || String(id || '');
        }

        function roomOccUiText(id) {
            const lang = resolveUiLang();
            const dict = {
                hu: {
                    room: 'Terem',
                    loading: 'Betöltés...',
                    choose_room: '-- Válassz termet --',
                    refresh: 'Frissít',
                    loading_rooms: 'Termek betöltése...',
                    rooms_count: 'Termek: {count} db',
                    room_load_error: 'Terem betöltési hiba.',
                    display_title: 'Megjelenítés',
                    only_current: 'Csak aktuális foglaltság',
                    next_count: 'Következő események száma',
                    language: 'Nyelv',
                    refresh_interval: 'Frissítés (mp)',
                    summary_room: 'terem',
                    summary_only_current: 'csak aktuális',
                    summary_daily_list: 'napi lista',
                    summary_next: 'következő',
                    summary_lang: 'nyelv'
                },
                sk: {
                    room: 'Miestnosť',
                    loading: 'Načítavam...',
                    choose_room: '-- Vyber miestnosť --',
                    refresh: 'Obnoviť',
                    loading_rooms: 'Načítavam miestnosti...',
                    rooms_count: 'Miestnosti: {count}',
                    room_load_error: 'Chyba načítania miestností.',
                    display_title: 'Zobrazenie',
                    only_current: 'Len aktuálna obsadenosť',
                    next_count: 'Počet nasledujúcich udalostí',
                    language: 'Jazyk',
                    refresh_interval: 'Obnova (s)',
                    summary_room: 'miestnosť',
                    summary_only_current: 'len aktuálne',
                    summary_daily_list: 'denný zoznam',
                    summary_next: 'ďalšie',
                    summary_lang: 'jazyk'
                },
                en: {
                    room: 'Room',
                    loading: 'Loading...',
                    choose_room: '-- Select room --',
                    refresh: 'Refresh',
                    loading_rooms: 'Loading rooms...',
                    rooms_count: 'Rooms: {count}',
                    room_load_error: 'Room loading error.',
                    display_title: 'Display',
                    only_current: 'Only current occupancy',
                    next_count: 'Number of upcoming events',
                    language: 'Language',
                    refresh_interval: 'Refresh (sec)',
                    summary_room: 'room',
                    summary_only_current: 'current only',
                    summary_daily_list: 'daily list',
                    summary_next: 'next',
                    summary_lang: 'lang'
                }
            };
            return (dict[lang] && dict[lang][id]) || dict.en[id] || String(id || '');
        }

        function loopUiText(id) {
            const lang = resolveUiLang();
            const dict = {
                hu: {
                    group_title: 'Csoport lejátszási listák',
                    loop_list_label: 'Lejátszási listák',
                    edited_loop: 'Szerkesztett lista',
                    default_fallback_loop: 'Alap tartalék lista (üres idő)',
                    preview_suffix: 'előnézete',
                    total_prefix: 'Összesen',
                    seconds_short: 'mp',
                    confirm_clear_all: 'Biztosan törölni szeretnéd az összes elemet?',
                    turned_off_name: 'Kikapcsolás',
                    turned_off_desc: 'Ütemezett kijelző kikapcsolás (tartalom leáll + HDMI ki).',
                    turned_off_period: 'Kijelző kikapcsolási idősáv',
                    turned_off_mode_label: 'Kikapcsolási mód',
                    turned_off_mode_signal_off: 'Jel lekapcsolása (nincs kimeneti jel)',
                    turned_off_mode_black_screen: 'Fekete képernyő (surf bezárás)',
                    display_singular: 'kijelző',
                    display_plural: 'kijelző'
                },
                sk: {
                    group_title: 'Skupinové slučky',
                    loop_list_label: 'Slučky',
                    edited_loop: 'Upravovaná slučka',
                    default_fallback_loop: 'Predvolená záložná slučka (prázdny čas)',
                    preview_suffix: 'náhľad',
                    total_prefix: 'Celkom',
                    seconds_short: 's',
                    confirm_clear_all: 'Naozaj chcete odstrániť všetky položky?',
                    turned_off_name: 'Vypnutie',
                    turned_off_desc: 'Plánované vypnutie displeja (zastavenie obsahu + HDMI off).',
                    turned_off_period: 'Časový blok vypnutia displeja',
                    turned_off_mode_label: 'Režim vypnutia',
                    turned_off_mode_signal_off: 'Vypnúť obrazový výstup (bez signálu)',
                    turned_off_mode_black_screen: 'Čierna obrazovka (zatvoriť surf)',
                    display_singular: 'displej',
                    display_plural: 'displeje'
                },
                en: {
                    group_title: 'Group loops',
                    loop_list_label: 'Loops',
                    edited_loop: 'Edited loop',
                    default_fallback_loop: 'Default fallback loop (empty time)',
                    preview_suffix: 'preview',
                    total_prefix: 'Total',
                    seconds_short: 's',
                    confirm_clear_all: 'Are you sure you want to remove all items?',
                    turned_off_name: 'Turned Off',
                    turned_off_desc: 'Scheduled display power off (content service stop + HDMI off).',
                    turned_off_period: 'Display power-off time block',
                    turned_off_mode_label: 'Power-off behavior',
                    turned_off_mode_signal_off: 'Turn off display signal (no output signal)',
                    turned_off_mode_black_screen: 'Black screen only (close surf)',
                    display_singular: 'display',
                    display_plural: 'displays'
                }
            };
            return (dict[lang] && dict[lang][id]) || dict.en[id] || String(id || '');
        }

        function localizedDisplayUnit(count) {
            const value = Number.isFinite(parseInt(count, 10)) ? parseInt(count, 10) : 0;
            const singular = loopUiText('display_singular');
            const plural = loopUiText('display_plural');
            if (resolveUiLang() === 'sk') {
                return value === 1 ? singular : plural;
            }
            return value === 1 ? singular : plural;
        }

        function clockUiText(id) {
            const lang = resolveUiLang();
            const dict = {
                hu: {
                    type_label: 'Típus:',
                    type_digital: 'Digitális',
                    type_analog: 'Analóg',
                    format_label: 'Formátum:',
                    format_24h: '24 órás',
                    date_format_label: 'Dátum formátum:',
                    date_full: 'Teljes (év, hónap, nap, napnév)',
                    date_short: 'Rövid (év, hónap, nap)',
                    date_dmy: 'Nap.Hónap.Év (NN.HH.ÉÉÉÉ)',
                    date_numeric: 'Numerikus (ÉÉÉÉ.HH.NN)',
                    date_none: 'Nincs dátum',
                    language_label: 'Nyelv:',
                    lang_hu: 'Magyar',
                    lang_sk: 'Szlovák',
                    lang_en: 'Angol',
                    time_color: 'Óra szín:',
                    date_color: 'Dátum szín:',
                    bg_color: 'Háttérszín:',
                    time_font_size: 'Óra betűméret (px):',
                    date_font_size: 'Dátum betűméret (px):',
                    clock_size: 'Óra mérete (px):',
                    show_seconds: 'Másodpercek mutatása',
                    show_date: 'Dátum megjelenítése',
                    date_inline: 'Nap + dátum egy sorban',
                    weekday_position: 'Nap pozíció:',
                    weekday_left: 'Nap bal oldalon',
                    weekday_right: 'Nap jobb oldalon',
                    digital_overlay: 'Digitális óra a középen (analógra)',
                    digital_overlay_position: 'Digitális óra pozíció:',
                    pos_auto: 'Automatikus (fent/lent mutatók alapján)',
                    pos_top: 'Fent-közép',
                    pos_center: 'Közép',
                    pos_bottom: 'Lent-közép',
                    summary_day_date: 'nap|dátum',
                    summary_date_day: 'dátum|nap'
                },
                sk: {
                    type_label: 'Typ:',
                    type_digital: 'Digitálne',
                    type_analog: 'Analógové',
                    format_label: 'Formát:',
                    format_24h: '24-hodinový',
                    date_format_label: 'Formát dátumu:',
                    date_full: 'Plný (rok, mesiac, deň, názov dňa)',
                    date_short: 'Krátky (rok, mesiac, deň)',
                    date_dmy: 'Deň.Mesiac.Rok (DD.MM.RRRR)',
                    date_numeric: 'Numerický (RRRR.MM.DD)',
                    date_none: 'Bez dátumu',
                    language_label: 'Jazyk:',
                    lang_hu: 'Maďarčina',
                    lang_sk: 'Slovenčina',
                    lang_en: 'Angličtina',
                    time_color: 'Farba času:',
                    date_color: 'Farba dátumu:',
                    bg_color: 'Farba pozadia:',
                    time_font_size: 'Veľkosť písma času (px):',
                    date_font_size: 'Veľkosť písma dátumu (px):',
                    clock_size: 'Veľkosť hodín (px):',
                    show_seconds: 'Zobraziť sekundy',
                    show_date: 'Zobraziť dátum',
                    date_inline: 'Deň + dátum v jednom riadku',
                    weekday_position: 'Pozícia dňa:',
                    weekday_left: 'Deň vľavo',
                    weekday_right: 'Deň vpravo',
                    digital_overlay: 'Digitálne hodiny v strede (na analógových)',
                    digital_overlay_position: 'Pozícia digitálnych hodín:',
                    pos_auto: 'Automaticky (podľa ručičiek hore/dole)',
                    pos_top: 'Hore-stred',
                    pos_center: 'Stred',
                    pos_bottom: 'Dole-stred',
                    summary_day_date: 'deň|dátum',
                    summary_date_day: 'dátum|deň'
                },
                en: {
                    type_label: 'Type:',
                    type_digital: 'Digital',
                    type_analog: 'Analog',
                    format_label: 'Format:',
                    format_24h: '24-hour',
                    date_format_label: 'Date format:',
                    date_full: 'Full (year, month, day, weekday)',
                    date_short: 'Short (year, month, day)',
                    date_dmy: 'Day.Month.Year (DD.MM.YYYY)',
                    date_numeric: 'Numeric (YYYY.MM.DD)',
                    date_none: 'No date',
                    language_label: 'Language:',
                    lang_hu: 'Hungarian',
                    lang_sk: 'Slovak',
                    lang_en: 'English',
                    time_color: 'Clock color:',
                    date_color: 'Date color:',
                    bg_color: 'Background color:',
                    time_font_size: 'Clock font size (px):',
                    date_font_size: 'Date font size (px):',
                    clock_size: 'Clock size (px):',
                    show_seconds: 'Show seconds',
                    show_date: 'Show date',
                    date_inline: 'Weekday + date on one line',
                    weekday_position: 'Weekday position:',
                    weekday_left: 'Weekday on left',
                    weekday_right: 'Weekday on right',
                    digital_overlay: 'Centered digital clock (on analog)',
                    digital_overlay_position: 'Digital clock position:',
                    pos_auto: 'Automatic (based on top/bottom hands)',
                    pos_top: 'Top-center',
                    pos_center: 'Center',
                    pos_bottom: 'Bottom-center',
                    summary_day_date: 'day|date',
                    summary_date_day: 'date|day'
                }
            };
            return (dict[lang] && dict[lang][id]) || dict.en[id] || String(id || '');
        }

        function pdfUiText(id) {
            const lang = resolveUiLang();
            const dict = {
                hu: {
                    upload_title: '📄 PDF feltöltés',
                    drop_or_click: 'Húzd ide a PDF-et vagy',
                    click_to_pick: 'kattints a kiválasztáshoz',
                    max_size: 'Max. 50 MB',
                    loaded: '✓ PDF betöltve',
                    fixed_zoom: 'Fix zoom (%):',
                    horizontal_focus: 'Vízszintes fókusz (%):',
                    horizontal_hint: '0 = bal oldal, 50 = közép, 100 = jobb oldal',
                    auto_scroll: 'Automatikus görgetés',
                    scroll_speed: 'Görgetési sebesség (px/s):',
                    start_pause: 'Indulás előtti várakozás (ms):',
                    end_pause: 'Ciklus végi várakozás (ms):',
                    pause_pos: 'Megállás pozíció (%):',
                    pause_pos_hint: '-1 = nincs köztes megállás',
                    pause_duration: 'Megállás hossza (ms):',
                    section_planner: 'Szakasz tervező',
                    add_section: 'Szakasz hozzáadása',
                    section_start: 'Kezdet (%)',
                    section_end: 'Vég (%)',
                    section_pause: 'Pihenő (ms)',
                    section_horizontal: 'X fókusz (%)',
                    section_tip: 'A szakaszok sorrendben futnak, mindegyiknél külön beállítható a vízszintes nézet.',
                    preview: 'Előnézet',
                    preview_empty: 'Tölts fel PDF-et az előnézethez.'
                },
                sk: {
                    upload_title: '📄 Nahratie PDF',
                    drop_or_click: 'Sem presuň PDF alebo',
                    click_to_pick: 'klikni na výber',
                    max_size: 'Max. 50 MB',
                    loaded: '✓ PDF načítané',
                    fixed_zoom: 'Pevný zoom (%):',
                    horizontal_focus: 'Horizontálne zameranie (%):',
                    horizontal_hint: '0 = ľavá strana, 50 = stred, 100 = pravá strana',
                    auto_scroll: 'Automatické posúvanie',
                    scroll_speed: 'Rýchlosť posúvania (px/s):',
                    start_pause: 'Oneskorenie pred štartom (ms):',
                    end_pause: 'Pauza na konci cyklu (ms):',
                    pause_pos: 'Pozícia zastavenia (%):',
                    pause_pos_hint: '-1 = bez medzizastavenia',
                    pause_duration: 'Dĺžka zastavenia (ms):',
                    section_planner: 'Plánovanie úsekov',
                    add_section: 'Pridať úsek',
                    section_start: 'Začiatok (%)',
                    section_end: 'Koniec (%)',
                    section_pause: 'Pauza (ms)',
                    section_horizontal: 'X fokus (%)',
                    section_tip: 'Úseky sa prehrávajú postupne, každý môže mať vlastnú horizontálnu pozíciu.',
                    preview: 'Náhľad',
                    preview_empty: 'Nahraj PDF pre náhľad.'
                },
                en: {
                    upload_title: '📄 PDF Upload',
                    drop_or_click: 'Drop PDF here or',
                    click_to_pick: 'click to choose',
                    max_size: 'Max. 50 MB',
                    loaded: '✓ PDF loaded',
                    fixed_zoom: 'Fixed zoom (%):',
                    horizontal_focus: 'Horizontal focus (%):',
                    horizontal_hint: '0 = left side, 50 = center, 100 = right side',
                    auto_scroll: 'Automatic scrolling',
                    scroll_speed: 'Scroll speed (px/s):',
                    start_pause: 'Start delay (ms):',
                    end_pause: 'End-of-cycle pause (ms):',
                    pause_pos: 'Pause position (%):',
                    pause_pos_hint: '-1 = no intermediate pause',
                    pause_duration: 'Pause duration (ms):',
                    section_planner: 'Section planner',
                    add_section: 'Add section',
                    section_start: 'Start (%)',
                    section_end: 'End (%)',
                    section_pause: 'Pause (ms)',
                    section_horizontal: 'X focus (%)',
                    section_tip: 'Sections run in order; each section can keep a different horizontal focus.',
                    preview: 'Preview',
                    preview_empty: 'Upload a PDF for preview.'
                }
            };
            return (dict[lang] && dict[lang][id]) || dict.en[id] || String(id || '');
        }

        function textUiText(id, vars = null) {
            const lang = resolveUiLang();
            const dict = {
                hu: {
                    text_source: 'Szöveg forrása',
                    manual_edit: 'Kézi szerkesztés',
                    slide_collection: 'Slide gyűjtemény',
                    source_external: 'Külső forrás (URL)',
                    slide_item: 'Slide elem',
                    external_url: 'Külső TXT forrás URL',
                    external_url_hint: 'Pl.: https://pelda.hu/szoveg.txt',
                    loading: 'Betöltés...',
                    select_slide_item: '-- Válassz slide elemet --',
                    refresh: 'Frissít',
                    manage_collection: 'Slide gyűjtemény kezelése',
                    editor: 'Szerkesztő:',
                    bullet_list: '• Lista',
                    align_left: 'Bal',
                    align_center: 'Közép',
                    align_right: 'Jobb',
                    color: 'Szín',
                    background: 'Háttér',
                    size: 'Méret',
                    text_sizing_mode: 'Méretkezelés',
                    text_sizing_manual: 'Manuális stílus',
                    text_sizing_fit: 'Fit to screen (pont kiférjen)',
                    line_height: 'Sorköz',
                    play: '▶ Lejátszás',
                    stop: '■ Stop',
                    live_preview: 'Élő előnézet:',
                    bg_color: 'Háttérszín:',
                    bg_image_upload: 'Háttérkép feltöltés:',
                    image_set: 'Kép beállítva',
                    no_image_selected: 'Nincs kiválasztott kép',
                    image_from_collection: 'Háttérkép gyűjteményből',
                    remove_image: 'Kép törlése',
                    text_animation: 'Megjelenítési animáció:',
                    anim_none: 'Nincs animáció',
                    anim_fade: 'Halványulás',
                    anim_slide_up: 'Felfelé csúszás',
                    anim_zoom: 'Nagyítás',
                    scroll_mode: 'Görgetés mód (ha a szöveg nem fér ki)',
                    scroll_start_pause: 'Indulás előtti várakozás (s):',
                    scroll_end_pause: 'Végi várakozás (s):',
                    scroll_speed: 'Görgetési sebesség (px/s):',
                    clock_split_toggle: 'Split-screen óra + dátum (fix 30%)',
                    clock_split_unavailable: 'A Split-screen óra opció csak akkor érhető el, ha a Clock modul licencelve/elérhető ebben a cégben.',
                    clock_split_position: 'Óra sáv pozíciója',
                    clock_split_top: 'Fent (30%)',
                    clock_split_bottom: 'Lent (30%)',
                    clock_split_clock_size: 'Óra mérete (px)',
                    clock_split_date_position: 'Dátum pozíciója',
                    clock_split_date_below: 'Dátum alatta',
                    clock_split_date_right: 'Dátum mellette',
                    clock_split_date_format: 'Dátum formátuma',
                    clock_split_show_year: 'Év megjelenítése',
                    clock_split_date_full: 'Teljes (év, hónap, nap, napnév)',
                    clock_split_date_short: 'Rövid (év, hónap, nap)',
                    clock_split_date_dmy: 'Nap.Hónap.Év (NN.HH.ÉÉÉÉ)',
                    clock_split_date_numeric: 'Numerikus (ÉÉÉÉ.HH.NN)',
                    clock_split_date_none: 'Nincs dátum',
                    clock_split_language: 'Nyelv',
                    clock_split_time_color: 'Óra színe',
                    clock_split_date_color: 'Dátum színe',
                    clock_split_font_family: 'Óra betűstílus',
                    clock_split_separator_color: 'Elválasztó csík színe',
                    clock_split_separator_thickness: 'Elválasztó csík vastagsága (px)',
                    clock_split_fixed_bottom_note: 'A split-screen óra fixen az alsó 30%-os sávban jelenik meg.',
                    processing: 'Feldolgozás...',
                    only_images: '⚠️ Csak képfájl tölthető fel',
                    image_process_error: 'Kép feldolgozási hiba',
                    image_load_failed: '⚠️ Nem sikerült betölteni a képet',
                    collection_refreshed: '✓ Slide gyűjtemény frissítve',
                    collection_refresh_failed: '⚠️ A slide gyűjtemény frissítése sikertelen',
                    insert_text_here: 'Ide írd a szöveget...',
                    item_prefix: 'Elem'
                },
                sk: {
                    text_source: 'Zdroj textu',
                    manual_edit: 'Ručná úprava',
                    slide_collection: 'Kolekcia slidov',
                    source_external: 'Externý zdroj (URL)',
                    slide_item: 'Položka slidu',
                    external_url: 'URL externého TXT zdroja',
                    external_url_hint: 'Napr.: https://priklad.sk/text.txt',
                    loading: 'Načítavam...',
                    select_slide_item: '-- Vyber položku slidu --',
                    refresh: 'Obnoviť',
                    manage_collection: 'Správa kolekcie slidov',
                    editor: 'Editor:',
                    bullet_list: '• Zoznam',
                    align_left: 'Vľavo',
                    align_center: 'Na stred',
                    align_right: 'Vpravo',
                    color: 'Farba',
                    background: 'Pozadie',
                    size: 'Veľkosť',
                    text_sizing_mode: 'Režim veľkosti',
                    text_sizing_manual: 'Manuálne štýly',
                    text_sizing_fit: 'Fit to screen (presne sa zmestí)',
                    line_height: 'Riadkovanie',
                    play: '▶ Spustiť',
                    stop: '■ Stop',
                    live_preview: 'Živý náhľad:',
                    bg_color: 'Farba pozadia:',
                    bg_image_upload: 'Nahratie obrázka pozadia:',
                    image_set: 'Obrázok nastavený',
                    no_image_selected: 'Nie je vybraný obrázok',
                    image_from_collection: 'Obrázok pozadia z kolekcie',
                    remove_image: 'Odstrániť obrázok',
                    text_animation: 'Animácia zobrazenia:',
                    anim_none: 'Bez animácie',
                    anim_fade: 'Postupné zobrazenie',
                    anim_slide_up: 'Posun nahor',
                    anim_zoom: 'Priblíženie',
                    scroll_mode: 'Režim posúvania (ak sa text nezmestí)',
                    scroll_start_pause: 'Čakanie pred štartom (s):',
                    scroll_end_pause: 'Čakanie na konci (s):',
                    scroll_speed: 'Rýchlosť posúvania (px/s):',
                    clock_split_toggle: 'Split-screen hodiny + dátum (fixne 30%)',
                    clock_split_unavailable: 'Split-screen hodiny sú dostupné len vtedy, keď je modul Clock licencovaný/dostupný pre firmu.',
                    clock_split_position: 'Pozícia pásu hodín',
                    clock_split_top: 'Hore (30%)',
                    clock_split_bottom: 'Dole (30%)',
                    clock_split_clock_size: 'Veľkosť hodín (px)',
                    clock_split_date_position: 'Pozícia dátumu',
                    clock_split_date_below: 'Dátum pod časom',
                    clock_split_date_right: 'Dátum vedľa času',
                    clock_split_date_format: 'Formát dátumu',
                    clock_split_show_year: 'Zobraziť rok',
                    clock_split_date_full: 'Plný (rok, mesiac, deň, názov dňa)',
                    clock_split_date_short: 'Krátky (rok, mesiac, deň)',
                    clock_split_date_dmy: 'Deň.Mesiac.Rok (DD.MM.RRRR)',
                    clock_split_date_numeric: 'Numerický (RRRR.MM.DD)',
                    clock_split_date_none: 'Bez dátumu',
                    clock_split_language: 'Jazyk',
                    clock_split_time_color: 'Farba času',
                    clock_split_date_color: 'Farba dátumu',
                    clock_split_font_family: 'Štýl písma hodín',
                    clock_split_separator_color: 'Farba deliaceho pásika',
                    clock_split_separator_thickness: 'Hrúbka deliaceho pásika (px)',
                    clock_split_fixed_bottom_note: 'Split-screen hodiny sa zobrazujú fixne v spodnom 30% páse.',
                    processing: 'Spracovanie...',
                    only_images: '⚠️ Je možné nahrať iba obrázok',
                    image_process_error: 'Chyba spracovania obrázka',
                    image_load_failed: '⚠️ Obrázok sa nepodarilo načítať',
                    collection_refreshed: '✓ Kolekcia slidov obnovená',
                    collection_refresh_failed: '⚠️ Obnovenie kolekcie slidov zlyhalo',
                    insert_text_here: 'Sem vložte text...',
                    item_prefix: 'Položka'
                },
                en: {
                    text_source: 'Text source',
                    manual_edit: 'Manual editing',
                    slide_collection: 'Slide collection',
                    source_external: 'External source (URL)',
                    slide_item: 'Slide item',
                    external_url: 'External TXT source URL',
                    external_url_hint: 'e.g. https://example.com/text.txt',
                    loading: 'Loading...',
                    select_slide_item: '-- Select slide item --',
                    refresh: 'Refresh',
                    manage_collection: 'Manage slide collection',
                    editor: 'Editor:',
                    bullet_list: '• List',
                    align_left: 'Left',
                    align_center: 'Center',
                    align_right: 'Right',
                    color: 'Color',
                    background: 'Background',
                    size: 'Size',
                    text_sizing_mode: 'Sizing mode',
                    text_sizing_manual: 'Manual style',
                    text_sizing_fit: 'Fit to screen (exact fit)',
                    line_height: 'Line height',
                    play: '▶ Play',
                    stop: '■ Stop',
                    live_preview: 'Live preview:',
                    bg_color: 'Background color:',
                    bg_image_upload: 'Background image upload:',
                    image_set: 'Image set',
                    no_image_selected: 'No image selected',
                    image_from_collection: 'Background image from collection',
                    remove_image: 'Remove image',
                    text_animation: 'Entry animation:',
                    anim_none: 'No animation',
                    anim_fade: 'Fade in',
                    anim_slide_up: 'Slide up',
                    anim_zoom: 'Zoom in',
                    scroll_mode: 'Scroll mode (if text overflows)',
                    scroll_start_pause: 'Start pause (s):',
                    scroll_end_pause: 'End pause (s):',
                    scroll_speed: 'Scroll speed (px/s):',
                    clock_split_toggle: 'Split-screen clock + date (fixed 30%)',
                    clock_split_unavailable: 'Split-screen clock is available only when the Clock module is licensed/available for this company.',
                    clock_split_position: 'Clock band position',
                    clock_split_top: 'Top (30%)',
                    clock_split_bottom: 'Bottom (30%)',
                    clock_split_clock_size: 'Clock size (px)',
                    clock_split_date_position: 'Date position',
                    clock_split_date_below: 'Date below time',
                    clock_split_date_right: 'Date next to time',
                    clock_split_date_format: 'Date format',
                    clock_split_show_year: 'Show year',
                    clock_split_date_full: 'Full (year, month, day, weekday)',
                    clock_split_date_short: 'Short (year, month, day)',
                    clock_split_date_dmy: 'Day.Month.Year (DD.MM.YYYY)',
                    clock_split_date_numeric: 'Numeric (YYYY.MM.DD)',
                    clock_split_date_none: 'No date',
                    clock_split_language: 'Language',
                    clock_split_time_color: 'Clock color',
                    clock_split_date_color: 'Date color',
                    clock_split_font_family: 'Clock font style',
                    clock_split_separator_color: 'Separator stripe color',
                    clock_split_separator_thickness: 'Separator stripe thickness (px)',
                    clock_split_fixed_bottom_note: 'Split-screen clock is fixed to the bottom 30% band.',
                    processing: 'Processing...',
                    only_images: '⚠️ Only image files can be uploaded',
                    image_process_error: 'Image processing error',
                    image_load_failed: '⚠️ Failed to load image',
                    collection_refreshed: '✓ Slide collection refreshed',
                    collection_refresh_failed: '⚠️ Failed to refresh slide collection',
                    insert_text_here: 'Enter text here...',
                    item_prefix: 'Item'
                }
            };

            let text = (dict[lang] && dict[lang][id]) || dict.en[id] || String(id || '');
            if (vars && typeof vars === 'object') {
                Object.entries(vars).forEach(([name, value]) => {
                    text = text.replace(new RegExp(`\\{${name}\\}`, 'g'), String(value ?? ''));
                });
            }
            return text;
        }

        function getLocalizedModuleName(moduleKey, fallbackName = '') {
            const normalizedKey = String(moduleKey || '').toLowerCase();
            const fallback = String(fallbackName || '').trim();
            if (!normalizedKey) {
                return fallback || tr('group_loop.unspecified_module', 'Module');
            }

            const mapped = String(localizedModuleNames[normalizedKey] || '').trim();
            if (mapped) {
                return mapped;
            }

            return tr(`group_loop.module_name.${normalizedKey.replace(/-/g, '_')}`, fallback || normalizedKey);
        }

        function resolveLoopItemModuleName(item) {
            const key = getLoopItemModuleKey(item);
            const fallback = String(item?.module_name || '').trim();
            return getLocalizedModuleName(key, fallback);
        }

        function getDefaultUnconfiguredItem() {
            if (!technicalModule) {
                return null;
            }

            return {
                module_id: parseInt(technicalModule.id),
                module_name: getLocalizedModuleName('unconfigured', technicalModule.name),
                description: technicalModule.description || 'Technikai modul – csak üres loop esetén.',
                module_key: 'unconfigured',
                duration_seconds: 60,
                settings: {}
            };
        }
        
        // Preview variables
        let previewInterval = null;
        let previewTimeout = null;
        let currentPreviewIndex = 0;
        let currentModuleStartTime = 0;
        let totalLoopStartTime = 0;
        let isPaused = false;
        let loopCycleCount = 0;
        let autoSaveTimer = null;
        let autoSaveInFlight = false;
        let autoSaveQueued = false;
        let autoSaveToastTimer = null;
        let hasLoadedInitialLoop = false;
        let lastSavedSnapshot = '';
        let lastPublishedPayload = null;
        let planVersionToken = '';
        let draftPersistTimer = null;
        let isDraftDirty = false;
        let pendingBarPromptState = null;
        let scheduleRangeSelection = null;
        let scheduleBlockResize = null;
        let scheduleGridStepMinutes = 60;
        let groupResolutionChoices = [];
        let groupDefaultResolution = '1920x1080';
        let textCollectionsCacheDetailed = [];
        let textCollectionsCacheLoadedAt = 0;

        function showAutosaveToast(message, isError = false) {
            const toast = document.getElementById('autosave-toast');
            if (!toast) {
                return;
            }

            toast.textContent = message;
            toast.classList.toggle('error', !!isError);
            toast.classList.add('show');

            if (autoSaveToastTimer) {
                clearTimeout(autoSaveToastTimer);
            }

            autoSaveToastTimer = setTimeout(() => {
                toast.classList.remove('show');
            }, isError ? 2800 : 1400);
        }

        function deepClone(value) {
            return JSON.parse(JSON.stringify(value));
        }

        function getDraftStorageKey() {
            return `group_loop_draft_${groupId}`;
        }

        function updatePendingSaveBar() {
            const bar = document.getElementById('pending-save-bar');
            const label = document.getElementById('pending-save-text');
            const actions = bar?.querySelector('.pending-save-actions');
            if (!bar || !label) {
                return;
            }

            if (!actions) {
                return;
            }

            if (isDefaultGroup || (!hasLoadedInitialLoop && !pendingBarPromptState)) {
                bar.style.display = 'none';
                return;
            }

            if (pendingBarPromptState) {
                bar.style.display = 'flex';
                label.textContent = pendingBarPromptState.message;
                actions.innerHTML = `
                    <button type="button" class="btn pending-save-btn" id="pending-bar-confirm">${pendingBarPromptState.confirmLabel}</button>
                    <button type="button" class="btn pending-discard-btn" id="pending-bar-cancel">${pendingBarPromptState.cancelLabel}</button>
                `;
                const confirmBtn = document.getElementById('pending-bar-confirm');
                const cancelBtn = document.getElementById('pending-bar-cancel');
                confirmBtn?.addEventListener('click', () => {
                    const handler = pendingBarPromptState?.onConfirm;
                    pendingBarPromptState = null;
                    updatePendingSaveBar();
                    if (typeof handler === 'function') {
                        handler();
                    }
                });
                cancelBtn?.addEventListener('click', () => {
                    const handler = pendingBarPromptState?.onCancel;
                    pendingBarPromptState = null;
                    updatePendingSaveBar();
                    if (typeof handler === 'function') {
                        handler();
                    }
                });
                return;
            }

            bar.style.display = isDraftDirty ? 'flex' : 'none';
            const versionText = planVersionToken
                ? ` • ${tr('group_loop.version_label', 'Version')}: ${planVersionToken}`
                : '';
            label.textContent = isDraftDirty
                ? `${tr('group_loop.unsaved_changes', 'Unsaved changes')}${versionText}`
                : `${tr('group_loop.all_changes_saved', 'All changes saved')}${versionText}`;
            actions.innerHTML = `
                <button type="button" class="btn pending-save-btn" onclick="publishLoopPlan()">💾 ${tr('group_loop.save', 'Save')}</button>
                <button type="button" class="btn pending-discard-btn" onclick="discardLocalDraft()" title="${tr('group_loop.discard', 'Discard')}">✕</button>
            `;
        }

        function showPendingBarPrompt({ message, confirmLabel = 'Igen', cancelLabel = 'Mégse', onConfirm = null, onCancel = null }) {
            pendingBarPromptState = {
                message: String(message || ''),
                confirmLabel: String(confirmLabel || 'Igen'),
                cancelLabel: String(cancelLabel || 'Mégse'),
                onConfirm,
                onCancel
            };
            updatePendingSaveBar();
        }

        function setDraftDirty(flag) {
            isDraftDirty = !!flag;
            updatePendingSaveBar();
        }

        function persistDraftToCache() {
            if (!hasLoadedInitialLoop || isDefaultGroup) {
                return;
            }

            try {
                const snapshot = getLoopSnapshot();
                const payload = {
                    snapshot,
                    saved_at: new Date().toISOString()
                };
                localStorage.setItem(getDraftStorageKey(), JSON.stringify(payload));
                setDraftDirty(snapshot !== lastSavedSnapshot);
            } catch (error) {
                console.warn('Draft save failed', error);
            }
        }

        function clearDraftCache() {
            try {
                localStorage.removeItem(getDraftStorageKey());
            } catch (error) {
                console.warn('Draft cleanup failed', error);
            }
        }

        function queueDraftPersist(delayMs = 250) {
            if (draftPersistTimer) {
                clearTimeout(draftPersistTimer);
            }
            draftPersistTimer = setTimeout(() => {
                persistDraftToCache();
            }, delayMs);
        }

        function applyPlanPayload(payload) {
            if (!payload || typeof payload !== 'object') {
                return false;
            }

            const styles = Array.isArray(payload.loop_styles) ? payload.loop_styles : [];
            const parsedStyles = normalizeLoopStyles(
                styles,
                Array.isArray(payload.base_loop) ? payload.base_loop : [],
                specialOnlyMode
            );

            loopStyles = parsedStyles;
            defaultLoopStyleId = specialOnlyMode
                ? null
                : (parseInt(payload.default_loop_style_id ?? loopStyles[0]?.id ?? 0, 10) || loopStyles[0]?.id || null);
            timeBlocks = normalizeTimeBlocks(payload.schedule_blocks || payload.time_blocks || []);
            syncNextTempIdCursor();

            ensureSingleDefaultLoopStyle();
            activeLoopStyleId = parseInt(defaultLoopStyleId || loopStyles[0]?.id || 0, 10) || (loopStyles[0]?.id ?? null);
            loopItems = deepClone(getLoopStyleById(activeLoopStyleId)?.items || []);
            normalizeLoopItems();
            persistActiveLoopStyleItems();
            activeScope = 'base';
            setActiveScope('base', false);
            renderLoopStyleSelector();
            renderScopeSelector();
            renderLoop();
            return true;
        }

        function tryRestoreDraftFromCache() {
            if (isDefaultGroup) {
                return;
            }

            let parsed = null;
            try {
                parsed = JSON.parse(localStorage.getItem(getDraftStorageKey()) || 'null');
            } catch (error) {
                parsed = null;
            }

            if (!parsed || typeof parsed.snapshot !== 'string' || !parsed.snapshot.trim()) {
                setDraftDirty(false);
                return;
            }

            if (parsed.snapshot === lastSavedSnapshot) {
                clearDraftCache();
                setDraftDirty(false);
                return;
            }

            showPendingBarPrompt({
                message: 'Találtam nem mentett helyi piszkozatot. Betöltsem?',
                confirmLabel: 'Betöltés',
                cancelLabel: 'Mégse',
                onConfirm: () => {
                    try {
                        const payload = JSON.parse(parsed.snapshot);
                        if (applyPlanPayload(payload)) {
                            showAutosaveToast('✓ Helyi piszkozat betöltve');
                            setDraftDirty(true);
                        }
                    } catch (error) {
                        showAutosaveToast('⚠️ A helyi piszkozat sérült, törölve', true);
                        clearDraftCache();
                        setDraftDirty(false);
                    }
                },
                onCancel: () => {
                    setDraftDirty(true);
                    updatePendingSaveBar();
                }
            });
        }

        function publishLoopPlan() {
            saveLoop({ silent: false, source: 'publish' });
        }

        function discardLocalDraft() {
            if (!isDraftDirty) {
                return;
            }
            showPendingBarPrompt({
                message: 'Biztosan elveted a helyi módosításokat?',
                confirmLabel: 'Elvetés',
                cancelLabel: 'Mégse',
                onConfirm: () => {
                    if (lastPublishedPayload && applyPlanPayload(lastPublishedPayload)) {
                        clearDraftCache();
                        setDraftDirty(false);
                        showAutosaveToast('✓ Helyi módosítások elvetve');
                    }
                }
            });
        }

        function normalizeDaysMask(daysMask) {
            if (Array.isArray(daysMask)) {
                return daysMask.map(v => String(parseInt(v, 10))).filter(v => /^[1-7]$/.test(v)).join(',');
            }

            const raw = String(daysMask || '').trim();
            if (!raw) {
                return '1,2,3,4,5,6,7';
            }

            const unique = new Set();
            raw.split(',').forEach((part) => {
                const value = String(parseInt(part, 10));
                if (/^[1-7]$/.test(value)) {
                    unique.add(value);
                }
            });
            return unique.size ? Array.from(unique).sort().join(',') : '1,2,3,4,5,6,7';
        }

        function normalizeTimeBlocks(rawBlocks) {
            if (!Array.isArray(rawBlocks)) {
                return [];
            }

            return rawBlocks.map((block) => ({
                id: (() => {
                    const parsedId = parseInt(block?.id, 10);
                    return Number.isFinite(parsedId) && parsedId !== 0 ? parsedId : nextTempTimeBlockId--;
                })(),
                block_name: String(block.block_name || 'Időblokk'),
                block_type: (() => {
                    const normalizedType = String(block.block_type || 'weekly').toLowerCase();
                    if (normalizedType === 'date' || normalizedType === 'datetime_range') {
                        return normalizedType;
                    }
                    return 'weekly';
                })(),
                specific_date: block.specific_date ? String(block.specific_date).slice(0, 10) : null,
                start_datetime: block.start_datetime ? String(block.start_datetime).slice(0, 19) : null,
                end_datetime: block.end_datetime ? String(block.end_datetime).slice(0, 19) : null,
                start_time: String(block.start_time || '08:00:00').slice(0, 8),
                end_time: String(block.end_time || '12:00:00').slice(0, 8),
                days_mask: normalizeDaysMask(block.days_mask),
                priority: Number.isFinite(parseInt(block.priority, 10)) ? parseInt(block.priority, 10) : 100,
                loop_style_id: parseInt(block.loop_style_id || 0, 10) || null,
                is_active: block.is_active === false ? 0 : 1,
                is_locked: parseInt(block.is_locked || block.is_fixed_plan || 0, 10) ? 1 : 0,
                loops: Array.isArray(block.loops) ? block.loops : []
            }));
        }

        function normalizeLoopStyles(rawStyles, fallbackItems = [], allowEmpty = false) {
            const styles = Array.isArray(rawStyles) ? rawStyles : [];
            const nowTs = Date.now();
            const usedIds = new Set();
            let nextGeneratedId = -1;

            if (styles.length === 0) {
                if (allowEmpty) {
                    return [];
                }
                return [createFallbackLoopStyle('DEFAULT', Array.isArray(fallbackItems) ? fallbackItems : [])];
            }

            return styles.map((style, idx) => {
                const parsedId = parseInt(style?.id, 10);
                let resolvedId = Number.isFinite(parsedId) ? parsedId : 0;

                if (!resolvedId || usedIds.has(resolvedId)) {
                    while (usedIds.has(nextGeneratedId) || nextGeneratedId === 0) {
                        nextGeneratedId -= 1;
                    }
                    resolvedId = nextGeneratedId;
                    nextGeneratedId -= 1;
                }

                usedIds.add(resolvedId);

                return {
                    id: resolvedId,
                    name: String(style?.name || `Loop ${idx + 1}`),
                    items: Array.isArray(style?.items) ? style.items : [],
                    last_modified_ms: Number.isFinite(Number(style?.last_modified_ms))
                        ? Number(style.last_modified_ms)
                        : (Date.parse(String(style?.updated_at || style?.modified_at || '')) || (nowTs - idx))
                };
            });
        }

        function syncNextTempIdCursor() {
            const usedIds = [];

            (Array.isArray(loopStyles) ? loopStyles : []).forEach((style) => {
                const styleId = parseInt(style?.id, 10);
                if (Number.isFinite(styleId) && styleId !== 0) {
                    usedIds.push(styleId);
                }
            });

            (Array.isArray(timeBlocks) ? timeBlocks : []).forEach((block) => {
                const blockId = parseInt(block?.id, 10);
                if (Number.isFinite(blockId) && blockId !== 0) {
                    usedIds.push(blockId);
                }
            });

            const minUsedId = usedIds.length > 0 ? Math.min(...usedIds) : 0;
            nextTempTimeBlockId = minUsedId <= -1 ? (minUsedId - 1) : -1;
        }

        function createFallbackLoopStyle(name, items) {
            const styleId = nextTempTimeBlockId--;
            return {
                id: styleId,
                name: name || `Loop ${Math.abs(styleId)}`,
                items: Array.isArray(items) ? deepClone(items) : [],
                last_modified_ms: Date.now()
            };
        }

        function getLoopStyleLastModifiedMs(style, fallbackIndex = 0) {
            if (!style || typeof style !== 'object') {
                return 0;
            }

            const direct = Number(style.last_modified_ms);
            if (Number.isFinite(direct) && direct > 0) {
                return direct;
            }

            const parsed = Date.parse(String(style.updated_at || style.modified_at || ''));
            if (Number.isFinite(parsed) && parsed > 0) {
                return parsed;
            }

            return Math.max(0, Date.now() - fallbackIndex);
        }

        function getLoopStylesSortedByLastModified(styles) {
            if (!Array.isArray(styles)) {
                return [];
            }

            return styles
                .map((style, index) => ({ style, index }))
                .sort((left, right) => {
                    const leftTs = getLoopStyleLastModifiedMs(left.style, left.index);
                    const rightTs = getLoopStyleLastModifiedMs(right.style, right.index);

                    if (rightTs !== leftTs) {
                        return rightTs - leftTs;
                    }

                    return left.index - right.index;
                })
                .map((entry) => entry.style);
        }

        function normalizeDefaultLoopStyleName() {
            if (specialOnlyMode) {
                return;
            }
            const defaultStyle = getLoopStyleById(defaultLoopStyleId);
            if (!defaultStyle) {
                return;
            }
            if (String(defaultStyle.name || '') !== 'DEFAULT') {
                defaultStyle.name = 'DEFAULT';
            }
        }

        function getLoopStyleById(styleId) {
            const normalized = parseInt(styleId, 10);
            return loopStyles.find((style) => parseInt(style.id, 10) === normalized) || null;
        }

        function enforceSpecialActiveLoop() {
            if (!specialOnlyMode) {
                return;
            }

            const active = getLoopStyleById(activeLoopStyleId);
            const activeName = String(active?.name || '').trim();
            const activeLooksDefault = activeName === '' || /^default$/i.test(activeName);

            if (active && !activeLooksDefault) {
                return;
            }

            const special = loopStyles.find((entry) => /^special_/i.test(String(entry.name || '').trim())) || null;
            if (!special) {
                return;
            }

            activeLoopStyleId = parseInt(special.id, 10);
            loopItems = deepClone(special.items || []);
            normalizeLoopItems();
            persistActiveLoopStyleItems();
        }

        function ensureNamedSpecialLoopStyle(loopName) {
            const name = String(loopName || '').trim();
            if (!name) {
                return null;
            }

            let style = loopStyles.find((entry) => String(entry.name || '').trim() === name) || null;
            if (!style) {
                style = createFallbackLoopStyle(name, []);
                loopStyles.push(style);
                ensureSingleDefaultLoopStyle();
            }
            return style;
        }

        function persistActiveLoopStyleItems() {
            const style = getLoopStyleById(activeLoopStyleId);
            if (!style) {
                return;
            }

            const nextItems = deepClone(loopItems || []);
            const currentSerialized = JSON.stringify(Array.isArray(style.items) ? style.items : []);
            const nextSerialized = JSON.stringify(nextItems);

            if (currentSerialized === nextSerialized) {
                return;
            }

            style.items = nextItems;
            style.last_modified_ms = Date.now();
        }

        function updateLoopStyleMeta() {
            const meta = document.getElementById('loop-style-meta');
            if (!meta) {
                return;
            }
            const style = getLoopStyleById(activeLoopStyleId);
            const defaultStyle = getLoopStyleById(defaultLoopStyleId);
            const activeName = style ? style.name : '—';
            const defaultName = defaultStyle ? defaultStyle.name : '—';
            meta.textContent = `${loopUiText('edited_loop')}: ${activeName} • ${loopUiText('default_fallback_loop')}: ${defaultName}`;
        }

        function updateActiveLoopVisualState() {
            const style = getLoopStyleById(activeLoopStyleId);
            const styleName = style ? String(style.name || 'Loop') : '—';

            const configTitle = document.getElementById('loop-config-title');
            if (configTitle) {
                configTitle.textContent = `🔄 ${tr('group_loop.title', loopUiText('group_title'))} — ${styleName}`;
            }

            const previewTitle = document.getElementById('preview-title');
            if (previewTitle) {
                previewTitle.textContent = `📺 ${styleName} ${loopUiText('preview_suffix')}`;
            }

            const inlineName = document.getElementById('active-loop-inline-name');
            if (inlineName) {
                inlineName.textContent = styleName;
            }

            const inlineSchedule = document.getElementById('active-loop-inline-schedule');
            if (inlineSchedule) {
                inlineSchedule.textContent = getActiveLoopWeeklyScheduleSummary();
            }
        }

        function scheduleDayNameSk(day) {
            const names = {
                1: 'po',
                2: 'ut',
                3: 'st',
                4: 'št',
                5: 'pia',
                6: 'so',
                7: 'ne'
            };
            return names[day] || '?';
        }

        function formatSummaryTimeToken(hhmmss) {
            const raw = String(hhmmss || '00:00:00').slice(0, 5);
            const [hourPart, minutePart] = raw.split(':');
            const hour = parseInt(hourPart || '0', 10);
            if (String(minutePart || '00') === '00') {
                return String(hour);
            }
            return `${String(hour).padStart(2, '0')}:${String(minutePart || '00').padStart(2, '0')}`;
        }

        function compactDaysLabel(daysList) {
            const values = Array.from(new Set((Array.isArray(daysList) ? daysList : [])
                .map((v) => parseInt(v, 10))
                .filter((v) => v >= 1 && v <= 7)))
                .sort((a, b) => a - b);

            if (values.length === 0) {
                return '';
            }

            const groups = [];
            let start = values[0];
            let prev = values[0];

            for (let i = 1; i < values.length; i += 1) {
                const current = values[i];
                if (current === prev + 1) {
                    prev = current;
                    continue;
                }
                groups.push([start, prev]);
                start = current;
                prev = current;
            }
            groups.push([start, prev]);

            return groups.map(([groupStart, groupEnd]) => {
                if (groupStart === groupEnd) {
                    return scheduleDayNameSk(groupStart);
                }
                if (groupEnd === groupStart + 1) {
                    return `${scheduleDayNameSk(groupStart)},${scheduleDayNameSk(groupEnd)}`;
                }
                return `${scheduleDayNameSk(groupStart)}-${scheduleDayNameSk(groupEnd)}`;
            }).join(',');
        }

        function getActiveLoopWeeklyScheduleSummary() {
            const styleId = parseInt(activeLoopStyleId || 0, 10);
            if (!styleId || parseInt(defaultLoopStyleId || 0, 10) === styleId) {
                return '';
            }

            const weeklyBlocks = timeBlocks
                .filter((block) => String(block.block_type || 'weekly') === 'weekly')
                .filter((block) => parseInt(block.loop_style_id || 0, 10) === styleId);

            if (weeklyBlocks.length === 0) {
                return '';
            }

            const rangeMap = new Map();
            weeklyBlocks.forEach((block) => {
                const start = String(block.start_time || '00:00:00');
                const end = String(block.end_time || '00:00:00');
                const key = `${start}|${end}`;
                if (!rangeMap.has(key)) {
                    rangeMap.set(key, new Set());
                }
                const daySet = rangeMap.get(key);
                String(block.days_mask || '')
                    .split(',')
                    .map((v) => parseInt(v, 10))
                    .filter((v) => v >= 1 && v <= 7)
                    .forEach((day) => daySet.add(day));
            });

            const segments = Array.from(rangeMap.entries())
                .map(([key, daySet]) => {
                    const [start, end] = key.split('|');
                    const dayLabel = compactDaysLabel(Array.from(daySet.values()));
                    const timeLabel = `${formatSummaryTimeToken(start)}-${formatSummaryTimeToken(end)}`;
                    if (!dayLabel) {
                        return timeLabel;
                    }
                    return `${dayLabel} ${timeLabel}`;
                })
                .filter((label) => !!label)
                .sort((a, b) => a.localeCompare(b));

            if (segments.length === 0) {
                return '';
            }

            return `(${segments.join(' • ')})`;
        }

        function toggleLoopDetailVisibility() {
            const detailPanel = document.getElementById('loop-detail-panel');
            const placeholder = document.getElementById('loop-detail-placeholder');
            if (!detailPanel) {
                return;
            }
            const hasActive = !!getLoopStyleById(activeLoopStyleId);
            const showDetail = hasActive;
            detailPanel.style.display = showDetail ? 'block' : 'none';
            if (placeholder) {
                placeholder.style.display = showDetail ? 'none' : 'flex';
            }
        }

        function toggleModulesCatalogVisibility() {
            const wrapper = document.getElementById('modules-panel-wrapper');
            const placeholder = document.getElementById('modules-panel-placeholder');
            if (!wrapper) {
                return;
            }
            const hasActive = !!getLoopStyleById(activeLoopStyleId);
            const showModules = hasActive;
            wrapper.style.display = showModules ? 'block' : 'none';
            if (placeholder) {
                placeholder.style.display = showModules ? 'none' : 'flex';
            }
        }

        function togglePreviewPanelVisibility() {
            const wrapper = document.getElementById('preview-panel-wrapper');
            if (!wrapper) {
                return;
            }
            const hasActive = !!getLoopStyleById(activeLoopStyleId);
            const showPreview = hasActive;
            wrapper.style.display = showPreview ? 'block' : 'none';
        }

        function toggleLoopWorkspaceVisibility() {
            const workspace = document.getElementById('loop-edit-workspace');
            const placeholder = document.getElementById('loop-workspace-placeholder');
            if (!workspace || !placeholder) {
                return;
            }
            const hasActive = !!getLoopStyleById(activeLoopStyleId);
            const showWorkspace = hasActive;
            workspace.style.display = showWorkspace ? 'grid' : 'none';
            placeholder.style.display = showWorkspace ? 'none' : 'flex';
        }

        function ensureSingleDefaultLoopStyle() {
            if (specialOnlyMode) {
                defaultLoopStyleId = null;
                return;
            }
            if (!Array.isArray(loopStyles) || loopStyles.length === 0) {
                defaultLoopStyleId = null;
                return;
            }

            const currentDefault = getLoopStyleById(defaultLoopStyleId);
            if (currentDefault) {
                defaultLoopStyleId = parseInt(currentDefault.id, 10);
                normalizeDefaultLoopStyleName();
                return;
            }

            const namedBase = loopStyles.find((style) => /^(alap|default)\b/i.test(String(style.name || '').trim()));
            defaultLoopStyleId = parseInt((namedBase || loopStyles[0]).id, 10);
            normalizeDefaultLoopStyleName();
        }

        function openLoopStyleDetail(styleId) {
            setActiveLoopStyle(styleId);
        }

        function bindModuleCatalogInteractions() {
            const moduleItems = document.querySelectorAll('.modules-panel .module-item[data-module-id]');
            moduleItems.forEach((item) => {
                if (item.dataset.boundCatalogHandlers === '1') {
                    return;
                }

                item.dataset.boundCatalogHandlers = '1';

                const toggleBtn = item.querySelector('.module-toggle-btn');
                if (toggleBtn) {
                    toggleBtn.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        item.classList.toggle('is-collapsed');
                        const expanded = !item.classList.contains('is-collapsed');
                        toggleBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                    });
                }

                if (!isContentOnlyMode) {
                    item.addEventListener('click', (event) => {
                        if (event.target && event.target.closest('.module-toggle-btn')) {
                            return;
                        }
                        addModuleToLoopFromDataset(item);
                    });
                }

                if (!isContentOnlyMode && item.draggable) {
                    item.addEventListener('dragstart', (event) => {
                        handleModuleCatalogDragStartFromDataset(event, item);
                    });
                }
            });
        }

        function renderLoopStyleCards() {
            const select = document.getElementById('loop-style-select');
            if (!select) {
                return;
            }

            select.innerHTML = '';

            if (!Array.isArray(loopStyles) || loopStyles.length === 0) {
                const emptyOption = document.createElement('option');
                emptyOption.value = '';
                emptyOption.textContent = 'Nincs elérhető loop';
                select.appendChild(emptyOption);
                select.disabled = true;
                return;
            }

            select.disabled = false;

            const defaultId = parseInt(defaultLoopStyleId || 0, 10);
            const activeId = parseInt(activeLoopStyleId || 0, 10);
            const sortedStyles = getLoopStylesSortedByLastModified(loopStyles);

            sortedStyles.forEach((style) => {
                const styleId = parseInt(style.id, 10);
                const isDefaultStyle = styleId === defaultId;
                const realModuleCount = Array.isArray(style.items)
                    ? style.items.filter((item) => !isTechnicalLoopItem(item)).length
                    : 0;
                const displayName = isDefaultStyle ? 'DEFAULT' : style.name;

                const option = document.createElement('option');
                option.value = String(style.id);
                option.textContent = `${isDefaultStyle ? '● ' : ''}${displayName} (${realModuleCount} modul)`;
                option.dataset.isDefault = isDefaultStyle ? '1' : '0';
                option.selected = styleId === activeId;
                select.appendChild(option);
            });

            const activeStyle = getLoopStyleById(activeLoopStyleId);
            if (activeStyle) {
                select.value = String(activeStyle.id);
            }

            updateLoopStyleSelectAppearance();

            const deleteBtn = document.getElementById('loop-style-delete-btn');
            if (deleteBtn) {
                const activeIdValue = parseInt(activeLoopStyleId || 0, 10);
                const hasActiveStyle = !!getLoopStyleById(activeIdValue);
                const canDelete = !isDefaultGroup && !isContentOnlyMode && hasActiveStyle && activeIdValue !== defaultId;
                deleteBtn.disabled = !canDelete;
                deleteBtn.style.opacity = canDelete ? '1' : '0.5';
                deleteBtn.style.cursor = canDelete ? 'pointer' : 'not-allowed';
            }
        }

        function updateLoopStyleSelectAppearance() {
            const select = document.getElementById('loop-style-select');
            if (!select) {
                return;
            }

            const selectedOption = select.options[select.selectedIndex] || null;
            const isDefaultSelected = selectedOption && selectedOption.dataset && selectedOption.dataset.isDefault === '1';
            select.classList.toggle('loop-select-default', !!isDefaultSelected);
        }

        function renderLoopStyleSelector() {
            enforceSpecialActiveLoop();

            const dragList = document.getElementById('loop-style-drag-list');
            const fixedStyleInput = document.getElementById('fixed-plan-loop-style');
            const fixedStyleLabel = document.getElementById('fixed-plan-loop-label');
            ensureTurnedOffLoopStyle();
            const schedulableStyles = getLoopStylesSortedByLastModified(loopStyles)
                .filter((style) => parseInt(style.id, 10) !== parseInt(defaultLoopStyleId || 0, 10));

            renderLoopStyleCards();

            if (dragList) {
                const selectedSchedulableId = parseInt(fixedStyleInput?.value || activeLoopStyleId || 0, 10);
                dragList.innerHTML = `<label style="font-size:12px; font-weight:600; color:#425466;">${loopUiText('loop_list_label')}</label><div class="loop-schedule-list" id="loop-schedule-list-inner"></div>`;
                const listInner = document.getElementById('loop-schedule-list-inner');
                if (schedulableStyles.length === 0) {
                    const info = document.createElement('div');
                    info.style.fontSize = '12px';
                    info.style.color = '#8a97a6';
                    info.textContent = tr('group_loop.no_schedulable', 'No schedulable loops.');
                    if (listInner) {
                        listInner.appendChild(info);
                        if (!isDefaultGroup && !isContentOnlyMode) {
                            const createBtn = document.createElement('button');
                            createBtn.type = 'button';
                            createBtn.className = 'btn';
                            createBtn.textContent = tr('group_loop.create', 'Create');
                            createBtn.style.marginTop = '8px';
                            createBtn.addEventListener('click', () => {
                                scrollToLoopBuilderAndCreate();
                            });
                            listInner.appendChild(createBtn);
                        }
                    }
                }
                schedulableStyles.forEach((style) => {
                    const row = document.createElement('div');
                    const turnedOffStyle = isTurnedOffLoopStyle(style);
                    row.className = `loop-schedule-row${turnedOffStyle ? ' turned-off-style-row' : ''}`;

                    const left = document.createElement('div');
                    const name = document.createElement('div');
                    name.className = 'loop-schedule-row-name';
                    name.textContent = turnedOffStyle ? `⏻ ${style.name}` : style.name;
                    const meta = document.createElement('div');
                    meta.className = 'loop-schedule-row-meta';
                    if (turnedOffStyle) {
                        meta.textContent = '';
                    } else {
                        meta.textContent = parseInt(style.id, 10) === selectedSchedulableId
                            ? tr('group_loop.currently_selected', 'Selected loop')
                            : tr('group_loop.weekly_or_special', 'Can be placed into weekly or special plan');
                    }
                    left.appendChild(name);
                    left.appendChild(meta);

                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'btn';
                    button.textContent = tr('group_loop.schedule_button', 'Schedule');
                    button.addEventListener('click', () => {
                        if (fixedStyleInput) {
                            fixedStyleInput.value = String(style.id);
                        }
                        if (fixedStyleLabel) {
                            fixedStyleLabel.textContent = tr('group_loop.selected_loop', 'Selected loop: {name}', { name: style.name });
                        }
                        openLoopStyleDetail(style.id);
                        openQuickScheduleDialog(style.id);
                        renderLoopStyleSelector();
                    });

                    row.appendChild(left);
                    row.appendChild(button);
                    if (listInner) {
                        listInner.appendChild(row);
                    }
                });
            }

            if (fixedStyleInput) {
                const previousValue = String(fixedStyleInput.value || '');
                const hasPrevious = previousValue && schedulableStyles.some((style) => String(style.id) === previousValue);
                let resolvedValue = hasPrevious
                    ? previousValue
                    : String((schedulableStyles.find((style) => parseInt(style.id, 10) === parseInt(activeLoopStyleId || 0, 10))?.id) || (schedulableStyles[0]?.id || ''));
                fixedStyleInput.value = resolvedValue;
                if (fixedStyleLabel) {
                    const selectedStyle = schedulableStyles.find((style) => String(style.id) === resolvedValue) || null;
                    fixedStyleLabel.textContent = selectedStyle
                        ? tr('group_loop.selected_loop', 'Selected loop: {name}', { name: selectedStyle.name })
                        : tr('group_loop.selected_loop_empty', 'Selected loop: —');
                }
            }

            syncWeeklyPlannerFromScope();

            updateLoopStyleMeta();
            updateActiveLoopVisualState();
            toggleLoopDetailVisibility();
            toggleModulesCatalogVisibility();
            togglePreviewPanelVisibility();
            toggleLoopWorkspaceVisibility();
        }

        function clearWeeklyPlanSelection(keepDayTime = true) {
            const idInput = document.getElementById('fixed-plan-block-id');
            const addBtn = document.getElementById('fixed-plan-add-btn');
            if (idInput) {
                idInput.value = '';
            }
            if (addBtn) {
                addBtn.textContent = 'Frissítés';
            }
            closeFixedWeeklyPlannerModal();
            if (!keepDayTime) {
                document.querySelectorAll('.fixed-plan-day-checkbox').forEach((el) => {
                    el.checked = false;
                });
                const startInput = document.getElementById('fixed-plan-start');
                const endInput = document.getElementById('fixed-plan-end');
                if (startInput) startInput.value = '08:00';
                if (endInput) endInput.value = '10:00';
            }
        }

        function fillWeeklyPlanFormFromBlock(block) {
            if (!block || String(block.block_type || 'weekly') !== 'weekly') {
                clearWeeklyPlanSelection(true);
                return;
            }

            const idInput = document.getElementById('fixed-plan-block-id');
            const styleInput = document.getElementById('fixed-plan-loop-style');
            const startInput = document.getElementById('fixed-plan-start');
            const endInput = document.getElementById('fixed-plan-end');
            const addBtn = document.getElementById('fixed-plan-add-btn');
            const days = new Set(String(block.days_mask || '').split(',').map((v) => String(parseInt(v, 10))).filter((v) => /^[1-7]$/.test(v)));

            if (idInput) idInput.value = String(block.id);
            if (styleInput) styleInput.value = String(block.loop_style_id || '');
            if (startInput) startInput.value = String(block.start_time || '08:00:00').slice(0, 5);
            if (endInput) endInput.value = String(block.end_time || '10:00:00').slice(0, 5);
            document.querySelectorAll('.fixed-plan-day-checkbox').forEach((el) => {
                el.checked = days.has(String(el.value));
            });
            if (addBtn) {
                addBtn.textContent = 'Frissítés';
            }
        }

        function syncWeeklyPlannerFromScope() {
            if (activeScope === 'base') {
                clearWeeklyPlanSelection(true);
                return;
            }
            const block = getActiveTimeBlock();
            if (!block || String(block.block_type || 'weekly') !== 'weekly') {
                clearWeeklyPlanSelection(true);
                return;
            }
            fillWeeklyPlanFormFromBlock(block);
        }

        function scrollToLoopBuilderAndCreate() {
            const anchor = document.getElementById('loop-builder') || document.getElementById('loop-config-title');
            if (anchor) {
                anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        function openFixedWeeklyPlannerModal(blockId = null) {
            const modal = document.getElementById('fixed-weekly-planner');
            if (!modal) {
                return;
            }

            const resolvedId = parseInt(blockId || document.getElementById('fixed-plan-block-id')?.value || 0, 10);
            if (resolvedId !== 0) {
                const block = getWeeklyBlockById(resolvedId);
                if (!block) {
                    showAutosaveToast('⚠️ A kiválasztott heti idősáv nem található', true);
                    return;
                }
                fillWeeklyPlanFormFromBlock(block);
            }

            modal.style.display = 'flex';
            modal.classList.add('open');
        }

        function closeFixedWeeklyPlannerModal() {
            const modal = document.getElementById('fixed-weekly-planner');
            if (!modal) {
                return;
            }
            modal.classList.remove('open');
            modal.style.display = 'none';
        }

        function getLoopStyleName(styleId) {
            const style = getLoopStyleById(styleId);
            return style ? String(style.name || 'Loop') : `Loop #${styleId}`;
        }

        function findOverlappingBlocks(candidate, ignoredId = null) {
            return timeBlocks.filter((existing) => {
                if (!existing || (ignoredId !== null && parseInt(existing.id, 10) === parseInt(ignoredId, 10))) {
                    return false;
                }

                if (String(existing.block_type || 'weekly') !== String(candidate.block_type || 'weekly')) {
                    return false;
                }

                if (String(candidate.block_type || 'weekly') === 'date') {
                    if (String(existing.specific_date || '') !== String(candidate.specific_date || '')) {
                        return false;
                    }
                }

                if (String(candidate.block_type || 'weekly') === 'datetime_range') {
                    const candStart = Date.parse(String(candidate.start_datetime || '').replace(' ', 'T'));
                    const candEnd = Date.parse(String(candidate.end_datetime || '').replace(' ', 'T'));
                    const existStart = Date.parse(String(existing.start_datetime || '').replace(' ', 'T'));
                    const existEnd = Date.parse(String(existing.end_datetime || '').replace(' ', 'T'));
                    if (!Number.isFinite(candStart) || !Number.isFinite(candEnd) || !Number.isFinite(existStart) || !Number.isFinite(existEnd)) {
                        return false;
                    }
                    return candStart < existEnd && existStart < candEnd;
                }

                return hasPairOverlap(candidate, existing);
            });
        }

        function hasPairOverlap(a, b) {
            const typeA = String(a?.block_type || 'weekly');
            const typeB = String(b?.block_type || 'weekly');

            if (typeA === 'weekly' && typeB === 'weekly') {
                return weeklyBlocksOverlapByDay(a, b);
            }

            const segA = toTimeSegments(String(a?.start_time || '00:00:00'), String(a?.end_time || '00:00:00'));
            const segB = toTimeSegments(String(b?.start_time || '00:00:00'), String(b?.end_time || '00:00:00'));
            return doSegmentsOverlap(segA, segB);
        }

        function resolveScheduleConflicts(candidate, ignoredId = null) {
            const overlaps = findOverlappingBlocks(candidate, ignoredId);
            if (overlaps.length === 0) {
                return true;
            }

            const names = overlaps.map((block) => {
                const styleName = getLoopStyleName(block.loop_style_id || 0);
                return `• ${styleName} (${String(block.start_time).slice(0, 5)}-${String(block.end_time).slice(0, 5)})`;
            }).join('\n');

            const choice = prompt(
                tr('group_loop.conflict_prompt', 'There are existing loops in this time:\n{names}\n\n1 = trim conflicting blocks (end sooner)\n2 = delete conflicting blocks\n0 = cancel', { names }),
                '1'
            );

            if (choice === null || String(choice).trim() === '0') {
                return false;
            }

            if (String(choice).trim() === '2') {
                const overlapIds = new Set(overlaps.map((entry) => parseInt(entry.id, 10)));
                timeBlocks = timeBlocks.filter((entry) => !overlapIds.has(parseInt(entry.id, 10)));
                return true;
            }

            const candidateStart = String(candidate.start_time || '00:00:00');
            const candidateStartMinute = parseMinuteFromTime(candidateStart, 0);
            const updated = [];

            timeBlocks.forEach((entry) => {
                const isConflict = overlaps.some((block) => parseInt(block.id, 10) === parseInt(entry.id, 10));
                if (!isConflict) {
                    updated.push(entry);
                    return;
                }

                const entryStartMinute = parseMinuteFromTime(entry.start_time, 0);
                if (candidateStartMinute <= entryStartMinute) {
                    return;
                }

                const trimmed = {
                    ...entry,
                    end_time: candidateStart
                };

                if (parseMinuteFromTime(trimmed.start_time, 0) === parseMinuteFromTime(trimmed.end_time, 0)) {
                    return;
                }

                updated.push(trimmed);
            });

            timeBlocks = updated;
            return true;
        }

        function openQuickScheduleDialog(loopStyleId) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const normalizedStyleId = parseInt(loopStyleId || 0, 10);
            if (!normalizedStyleId || normalizedStyleId === parseInt(defaultLoopStyleId || 0, 10)) {
                showAutosaveToast(`⚠️ ${tr('group_loop.default_not_schedulable', 'DEFAULT loop cannot be scheduled')}`, true);
                return;
            }

            const host = document.getElementById('time-block-modal-host');
            const styleName = getLoopStyleName(normalizedStyleId);
            if (!host) {
                return;
            }

            host.innerHTML = `
                <div style="position:fixed; inset:0; background:rgba(0,0,0,0.45); display:flex; align-items:center; justify-content:center; z-index:3200;">
                    <div style="background:#fff; width:min(500px,92vw); border:1px solid #cfd6dd; padding:16px;">
                        <h3 style="margin:0 0 10px 0;">${tr('group_loop.quick_schedule_title', 'Weekly schedule')}</h3>
                        <div style="font-size:12px; color:#425466; margin-bottom:10px;">${tr('group_loop.quick_schedule_loop', 'Loop')}: <strong>${styleName}</strong></div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
                            ${[1,2,3,4,5,6,7].map((d) => `<label style="display:flex; align-items:center; gap:4px; font-size:12px;"><input type="checkbox" class="quick-weekly-day" value="${d}">${getDayShortLabel(String(d))}</label>`).join('')}
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:12px;">
                            <input type="text" id="quick-weekly-start" inputmode="numeric" placeholder="HH:MM" maxlength="5" pattern="^([01]\\d|2[0-3]):[0-5]\\d$" aria-label="${tr('group_loop.quick_schedule_start_aria', 'Weekly start (hour-minute)')}">
                            <input type="text" id="quick-weekly-end" inputmode="numeric" placeholder="HH:MM" maxlength="5" pattern="^([01]\\d|2[0-3]):[0-5]\\d$" aria-label="${tr('group_loop.quick_schedule_end_aria', 'Weekly end (hour-minute)')}">
                        </div>
                        <div style="display:flex; justify-content:flex-end; gap:8px;">
                            <button type="button" class="btn" onclick="closeTimeBlockModal()">${tr('common.cancel', 'Cancel')}</button>
                            <button type="button" class="btn" onclick="saveQuickScheduleDialog(${normalizedStyleId})">${tr('group_loop.add', 'Add')}</button>
                        </div>
                    </div>
                </div>
            `;

            set24HourTimeSelectValue('quick-weekly-start', '08:00');
            set24HourTimeSelectValue('quick-weekly-end', '10:00');
        }

        function saveQuickScheduleDialog(loopStyleId) {
            const selectedDays = Array.from(document.querySelectorAll('.quick-weekly-day:checked')).map((el) => String(parseInt(el.value, 10))).filter((v) => /^[1-7]$/.test(v));
            const startRaw = String(document.getElementById('quick-weekly-start')?.value || '').trim();
            const endRaw = String(document.getElementById('quick-weekly-end')?.value || '').trim();
            const styleId = parseInt(loopStyleId || 0, 10);

            if (!styleId || selectedDays.length === 0 || !startRaw || !endRaw) {
                showAutosaveToast(`⚠️ ${tr('group_loop.day_and_time_required', 'Choose at least one day and set both times')}`, true);
                return;
            }

            const startMinute = parseMinuteFromTime(`${startRaw}:00`, 0);
            const endMinute = parseMinuteFromTime(`${endRaw}:00`, 0);
            if (startMinute === endMinute) {
                showAutosaveToast(`⚠️ ${tr('group_loop.start_end_same', 'Start and end time cannot be the same')}`, true);
                return;
            }

            const payload = {
                id: nextTempTimeBlockId--,
                block_type: 'weekly',
                days_mask: normalizeDaysMask(selectedDays),
                start_time: minutesToTimeString(startMinute),
                end_time: minutesToTimeString(endMinute),
                block_name: `${tr('group_loop.quick_schedule_title', 'Weekly schedule')} ${startRaw}-${endRaw}`,
                priority: 200,
                loop_style_id: styleId,
                is_active: 1,
                is_locked: 0,
                loops: []
            };

            if (!resolveScheduleConflicts(payload, null)) {
                showAutosaveToast(`ℹ️ ${tr('group_loop.conflict_cancelled', 'Cancelled because of conflict')}`, true);
                return;
            }

            timeBlocks.push(payload);
            closeTimeBlockModal();
            activeScope = `block:${payload.id}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(120);
            showAutosaveToast(`✓ ${tr('group_loop.weekly_slot_created', 'Weekly time block created')}`);
        }

        function createFixedWeeklyBlockFromInputs() {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const blockIdInput = document.getElementById('fixed-plan-block-id');
            const styleInput = document.getElementById('fixed-plan-loop-style');
            const startInput = document.getElementById('fixed-plan-start');
            const endInput = document.getElementById('fixed-plan-end');

            const loopStyleId = parseInt(styleInput?.value || activeLoopStyleId || defaultLoopStyleId || 0, 10);
            const editBlockId = parseInt(blockIdInput?.value || '0', 10);
            const selectedDays = Array.from(document.querySelectorAll('.fixed-plan-day-checkbox:checked')).map((el) => String(parseInt(el.value, 10))).filter((v) => /^[1-7]$/.test(v));
            const startRaw = String(startInput?.value || '').trim();
            const endRaw = String(endInput?.value || '').trim();

            if (!loopStyleId || selectedDays.length === 0 || !startRaw || !endRaw) {
                showAutosaveToast('⚠️ Add meg a napot, időt és loop stílust', true);
                return;
            }

            if (parseInt(loopStyleId, 10) === parseInt(defaultLoopStyleId || 0, 10)) {
                showAutosaveToast('⚠️ A DEFAULT loop nem tervezhető, az üres időket automatikusan kitölti', true);
                return;
            }

            const startMinute = parseMinuteFromTime(`${startRaw}:00`, 0);
            const endMinute = parseMinuteFromTime(`${endRaw}:00`, 0);
            if (startMinute === endMinute) {
                showAutosaveToast('⚠️ A kezdés és befejezés nem lehet azonos', true);
                return;
            }

            const payload = {
                id: editBlockId !== 0 ? editBlockId : nextTempTimeBlockId--,
                block_type: 'weekly',
                days_mask: normalizeDaysMask(selectedDays),
                start_time: minutesToTimeString(startMinute),
                end_time: minutesToTimeString(endMinute),
                block_name: `Heti ${startRaw}-${endRaw}`,
                priority: 200,
                loop_style_id: loopStyleId,
                is_active: 1,
                is_locked: 0,
                loops: []
            };

            if (!resolveScheduleConflicts(payload, editBlockId !== 0 ? editBlockId : null)) {
                showAutosaveToast('ℹ️ Ütközés miatt megszakítva', true);
                return;
            }

            if (editBlockId !== 0) {
                timeBlocks = timeBlocks.map((entry) => parseInt(entry.id, 10) === editBlockId ? { ...entry, ...payload } : entry);
            } else {
                timeBlocks.push(payload);
            }
            activeScope = `block:${payload.id}`;
            setActiveScope(activeScope, true);
            openFixedWeeklyPlannerModal(payload.id);
            scheduleAutoSave(250);
            showAutosaveToast(editBlockId !== 0 ? '✓ Heti idősáv frissítve' : '✓ Heti idősáv létrehozva');
        }

        function deleteSelectedWeeklyPlanBlock() {
            const idInput = document.getElementById('fixed-plan-block-id');
            const blockId = parseInt(idInput?.value || '0', 10);
            if (!blockId) {
                showAutosaveToast('ℹ️ Törléshez válassz ki egy heti idősávot', true);
                return;
            }

            const block = getWeeklyBlockById(blockId);
            if (!block) {
                showAutosaveToast('⚠️ A kiválasztott heti idősáv nem található', true);
                clearWeeklyPlanSelection(true);
                return;
            }

            if (!confirm(`Törlöd a heti idősávot?\n${getScopeLabel(block)}`)) {
                return;
            }

            timeBlocks = timeBlocks.filter((entry) => parseInt(entry.id, 10) !== blockId);
            activeScope = 'base';
            clearWeeklyPlanSelection(true);
            setActiveScope('base', true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Heti idősáv törölve');
        }

        function clearEntireSchedulePlan() {
            const weeklyCount = timeBlocks.filter((entry) => String(entry.block_type || 'weekly') === 'weekly').length;
            if (weeklyCount === 0) {
                showAutosaveToast('ℹ️ Nincs törölhető heti terv', true);
                return;
            }

            if (!confirm(`Biztosan törlöd a teljes heti tervet?\n${weeklyCount} heti idősáv lesz törölve.`)) {
                return;
            }

            timeBlocks = timeBlocks.filter((entry) => String(entry.block_type || 'weekly') !== 'weekly');
            activeScope = 'base';
            clearWeeklyPlanSelection(false);
            setActiveScope('base', true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Teljes heti terv törölve');
        }

        function setActiveLoopStyle(styleId) {
            persistActiveLoopStyleItems();
            const parsed = parseInt(styleId, 10);
            const style = getLoopStyleById(parsed);
            if (!style) {
                return;
            }
            activeLoopStyleId = parsed;
            loopItems = deepClone(style.items || []);
            normalizeLoopItems();
            persistActiveLoopStyleItems();
            renderLoopStyleSelector();
            renderLoop();
            updateActiveLoopVisualState();
            showAutosaveToast(`✓ Aktív loop: ${style.name}`);
        }

        function createLoopStyle() {
            if (isDefaultGroup || isContentOnlyMode || specialOnlyMode) {
                return;
            }
            persistActiveLoopStyleItems();
            const name = prompt('Új loop neve:');
            if (!name || !String(name).trim()) {
                return;
            }
            const style = createFallbackLoopStyle(String(name).trim(), []);
            loopStyles.push(style);
            ensureSingleDefaultLoopStyle();
            setActiveLoopStyle(style.id);
            scheduleAutoSave(250);
        }

        function createAutoSpecialLoop() {
            if (!specialOnlyMode) {
                return;
            }
            const name = `special_${Date.now()}`;
            const style = createFallbackLoopStyle(name, []);
            loopStyles.push(style);
            ensureSingleDefaultLoopStyle();
            setActiveLoopStyle(style.id);
            scheduleAutoSave(250);
        }

        function ensureSpecialLoopStyleForRange(startDateObj, endDateObj) {
            const startDate = `${startDateObj.getFullYear()}-${String(startDateObj.getMonth() + 1).padStart(2, '0')}-${String(startDateObj.getDate()).padStart(2, '0')}`;
            const endDate = `${endDateObj.getFullYear()}-${String(endDateObj.getMonth() + 1).padStart(2, '0')}-${String(endDateObj.getDate()).padStart(2, '0')}`;
            const startTime = `${String(startDateObj.getHours()).padStart(2, '0')}:${String(startDateObj.getMinutes()).padStart(2, '0')}:00`;
            const endTime = `${String(endDateObj.getHours()).padStart(2, '0')}:${String(endDateObj.getMinutes()).padStart(2, '0')}:00`;

            const startDateClean = startDate.replace(/-/g, '');
            const startTimeClean = startTime.slice(0, 5).replace(':', '');
            const endDateClean = endDate.replace(/-/g, '');
            const endTimeClean = endTime.slice(0, 5).replace(':', '');
            const specialStyleName = `special_${startDateClean}${startTimeClean}-${endDateClean}${endTimeClean}`;
            const wantedName = forcedSpecialLoopName || specialStyleName;
            return ensureNamedSpecialLoopStyle(wantedName);
        }

        function createSpecialRangeBlockFromWorkflow(startDatetimeVal, endDatetimeVal) {
            if (!specialOnlyMode || !startDatetimeVal || !endDatetimeVal) {
                return false;
            }

            const startTs = Date.parse(startDatetimeVal);
            const endTs = Date.parse(endDatetimeVal);
            if (!Number.isFinite(startTs) || !Number.isFinite(endTs) || endTs <= startTs) {
                return false;
            }

            const startDateObj = new Date(startTs);
            const endDateObj = new Date(endTs);
            const startDate = `${startDateObj.getFullYear()}-${String(startDateObj.getMonth() + 1).padStart(2, '0')}-${String(startDateObj.getDate()).padStart(2, '0')}`;
            const endDate = `${endDateObj.getFullYear()}-${String(endDateObj.getMonth() + 1).padStart(2, '0')}-${String(endDateObj.getDate()).padStart(2, '0')}`;
            const startTime = `${String(startDateObj.getHours()).padStart(2, '0')}:${String(startDateObj.getMinutes()).padStart(2, '0')}:00`;
            const endTime = `${String(endDateObj.getHours()).padStart(2, '0')}:${String(endDateObj.getMinutes()).padStart(2, '0')}:00`;

            const style = ensureSpecialLoopStyleForRange(startDateObj, endDateObj);

            const styleId = parseInt(style.id || 0, 10);
            if (!styleId) {
                return false;
            }

            const payload = {
                id: nextTempTimeBlockId--,
                block_name: `Speciális ${startDate} ${startTime.slice(0, 5)}-${endDate} ${endTime.slice(0, 5)}`,
                block_type: 'datetime_range',
                start_datetime: `${startDate} ${startTime}`,
                end_datetime: `${endDate} ${endTime}`,
                specific_date: startDate,
                start_time: startTime,
                end_time: endTime,
                days_mask: '',
                priority: 400,
                loop_style_id: styleId,
                is_active: 1,
                loops: []
            };

            if (!resolveScheduleConflicts(payload, null)) {
                return false;
            }

            timeBlocks.push(payload);

            setActiveLoopStyle(styleId);

            activeScope = `block:${payload.id}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(250);
            return true;
        }

        function duplicateLoopStyleById(styleId) {
            if (isDefaultGroup || isContentOnlyMode || specialOnlyMode) {
                return;
            }

            persistActiveLoopStyleItems();
            const source = getLoopStyleById(styleId);
            if (!source) {
                showAutosaveToast('⚠️ A duplikálandó loop nem található', true);
                return;
            }

            const existingNames = new Set(loopStyles.map((entry) => String(entry.name || '').trim().toLowerCase()));
            const baseName = `${String(source.name || 'Loop').trim()} másolat`;
            let candidate = baseName;
            let suffix = 2;
            while (existingNames.has(candidate.toLowerCase())) {
                candidate = `${baseName} ${suffix}`;
                suffix += 1;
            }

            const duplicated = createFallbackLoopStyle(candidate, Array.isArray(source.items) ? source.items : []);
            loopStyles.push(duplicated);
            ensureSingleDefaultLoopStyle();
            setActiveLoopStyle(duplicated.id);
            scheduleAutoSave(250);
            showAutosaveToast(`✓ Loop duplikálva: ${duplicated.name}`);
        }

        function duplicateActiveLoopStyle() {
            duplicateLoopStyleById(activeLoopStyleId);
        }

        function renameActiveLoopStyle() {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }
            const style = getLoopStyleById(activeLoopStyleId);
            if (!style) {
                return;
            }
            if (isTurnedOffLoopStyle(style)) {
                showAutosaveToast('⚠️ A Turned Off loop fix és nem módosítható', true);
                return;
            }
            const name = prompt('Loop új neve:', style.name || '');
            if (!name || !String(name).trim()) {
                return;
            }
            style.name = String(name).trim();
            style.last_modified_ms = Date.now();
            renderLoopStyleSelector();
            renderWeeklyScheduleGrid();
            renderSpecialBlocksList();
            scheduleAutoSave(250);
        }

        function reorderPrimaryPanels() {
            const schedulePanel = document.querySelector('.planner-panel');
            const loopLayout = document.querySelector('.loop-main-layout');

            if (!schedulePanel || !loopLayout) {
                return;
            }

            const parent = loopLayout.parentElement;
            if (!parent || schedulePanel.parentElement !== parent) {
                return;
            }

            if (parent.firstElementChild !== schedulePanel) {
                parent.insertBefore(schedulePanel, loopLayout);
            }
        }

        function deleteLoopStyleById(styleId) {
            if (isDefaultGroup || isContentOnlyMode || specialOnlyMode) {
                return;
            }
            if (loopStyles.length <= 1) {
                showAutosaveToast('⚠️ Legalább egy loop stílusnak maradnia kell', true);
                return;
            }
            const style = getLoopStyleById(styleId);
            if (!style) {
                return;
            }

            if (isTurnedOffLoopStyle(style)) {
                showAutosaveToast('⚠️ A Turned Off loop fix és nem törölhető', true);
                return;
            }

            const deletedId = parseInt(style.id, 10);
            if (deletedId === parseInt(defaultLoopStyleId || 0, 10)) {
                showAutosaveToast('⚠️ A DEFAULT loop nem törölhető', true);
                return;
            }

            if (!confirm(`Törlöd ezt a loopot?\n${style.name}`)) {
                return;
            }

            loopStyles = loopStyles.filter((entry) => parseInt(entry.id, 10) !== deletedId);
            timeBlocks = timeBlocks.filter((entry) => parseInt(entry.loop_style_id || 0, 10) !== deletedId);
            ensureSingleDefaultLoopStyle();

            if (parseInt(activeLoopStyleId || 0, 10) === deletedId) {
                const fallbackActive = parseInt(defaultLoopStyleId || 0, 10) || parseInt(loopStyles[0]?.id || 0, 10);
                if (fallbackActive) {
                    setActiveLoopStyle(fallbackActive);
                }
            } else {
                renderLoopStyleSelector();
            }
            renderWeeklyScheduleGrid();
            renderSpecialBlocksList();
            scheduleAutoSave(250);
        }

        function deleteActiveLoopStyle() {
            deleteLoopStyleById(activeLoopStyleId);
        }

        function setActiveAsDefaultLoopStyle() {
            showAutosaveToast('ℹ️ A DEFAULT loop fix, másik loop nem állítható alapnak', true);
        }

        function persistCurrentScopeItems() {
            persistActiveLoopStyleItems();
        }

        function getScopeLabel(block) {
            const start = String(block.start_time || '00:00:00').slice(0, 5);
            const end = String(block.end_time || '00:00:00').slice(0, 5);
            if (block.block_type === 'datetime_range') {
                const startDt = String(block.start_datetime || '').replace('T', ' ').slice(0, 16);
                const endDt = String(block.end_datetime || '').replace('T', ' ').slice(0, 16);
                return `${startDt || '—'} → ${endDt || '—'} • ${block.block_name || 'Speciális intervallum'}`;
            }
            if (block.block_type === 'date') {
                return `${block.specific_date || '—'} ${start}-${end} • ${block.block_name || 'Speciális'}`;
            }
            return `${start}-${end} • ${block.block_name || 'Heti blokk'}`;
        }

        function renderScopeSelector() {
            const selector = document.getElementById('loop-scope-select');
            if (selector) {
                selector.innerHTML = '';

                const baseOption = document.createElement('option');
                baseOption.value = 'base';
                baseOption.textContent = 'DEFAULT loop (időblokkon kívül)';
                selector.appendChild(baseOption);

                const visibleBlocks = specialOnlyMode
                    ? timeBlocks
                    : timeBlocks.filter((block) => {
                        const type = String(block?.block_type || 'weekly').toLowerCase();
                        return type === 'weekly';
                    });

                visibleBlocks.forEach((block) => {
                    const option = document.createElement('option');
                    option.value = `block:${block.id}`;
                    option.textContent = getScopeLabel(block);
                    selector.appendChild(option);
                });

                selector.value = activeScope;
            }

            renderWeeklyScheduleGrid();
            renderSpecialBlocksList();
        }

        function dayName(day) {
            return getDayShortLabel(String(day));
        }

        function getWeeklyGridText(token) {
            const lang = resolveUiLang();
            const dict = {
                hu: {
                    hour: 'Óra',
                    today: 'Ma'
                },
                sk: {
                    hour: 'Hod',
                    today: 'Dnes'
                },
                en: {
                    hour: 'Hour',
                    today: 'Today'
                }
            };

            return (dict[lang] && dict[lang][token]) || dict.en[token] || String(token || '');
        }

        function getWeekStartDate(offsetWeeks) {
            const now = new Date();
            const day = now.getDay() === 0 ? 7 : now.getDay();
            const monday = new Date(now);
            monday.setHours(0, 0, 0, 0);
            monday.setDate(now.getDate() - (day - 1) + (offsetWeeks * 7));
            return monday;
        }

        function getDateForDayInOffsetWeek(day) {
            const monday = getWeekStartDate(scheduleWeekOffset);
            const target = new Date(monday);
            target.setDate(monday.getDate() + (day - 1));
            return target;
        }

        function toDateKey(dateObj) {
            return `${dateObj.getFullYear()}-${String(dateObj.getMonth() + 1).padStart(2, '0')}-${String(dateObj.getDate()).padStart(2, '0')}`;
        }

        function parseMinuteFromTime(timeValue, fallback = 0) {
            const raw = String(timeValue || '').trim();
            if (!raw) {
                return fallback;
            }
            const parts = raw.split(':');
            const hour = parseInt(parts[0], 10);
            const minute = parseInt(parts[1] || '0', 10);
            if (Number.isNaN(hour) || Number.isNaN(minute)) {
                return fallback;
            }
            const normalized = (hour * 60) + minute;
            return Math.max(0, Math.min(1439, normalized));
        }

        function parseDaysFromMask(daysMask) {
            return String(daysMask || '')
                .split(',')
                .map((value) => parseInt(value, 10))
                .filter((value) => value >= 1 && value <= 7);
        }

        function getPreviousWeekday(day) {
            const normalized = parseInt(day, 10);
            return normalized === 1 ? 7 : (normalized - 1);
        }

        function toTimeSegments(startRaw, endRaw) {
            const startMinute = parseMinuteFromTime(startRaw, 0);
            const endMinute = parseMinuteFromTime(endRaw, 0);

            if (endMinute === startMinute) {
                return [[0, 1440]];
            }

            if (endMinute > startMinute) {
                return [[startMinute, endMinute]];
            }

            if (endMinute === 0) {
                return [[startMinute, 1440]];
            }

            return [[startMinute, 1440], [0, endMinute]];
        }

        function getWeeklySegmentsForDay(block, day) {
            const parsedDay = parseInt(day, 10);
            if (!block || String(block.block_type || 'weekly') !== 'weekly' || parsedDay < 1 || parsedDay > 7) {
                return [];
            }

            const days = new Set(parseDaysFromMask(block.days_mask));
            const startMinute = parseMinuteFromTime(block.start_time, 0);
            const endMinute = parseMinuteFromTime(block.end_time, 0);

            if (endMinute === startMinute) {
                return days.has(parsedDay) ? [[0, 1440]] : [];
            }

            if (endMinute > startMinute) {
                return days.has(parsedDay) ? [[startMinute, endMinute]] : [];
            }

            const segments = [];
            if (days.has(parsedDay)) {
                segments.push([startMinute, 1440]);
            }

            const previousDay = getPreviousWeekday(parsedDay);
            if (endMinute > 0 && days.has(previousDay)) {
                segments.push([0, endMinute]);
            }

            return segments;
        }

        function doSegmentsOverlap(segmentsA, segmentsB) {
            return segmentsA.some(([aStart, aEnd]) => segmentsB.some(([bStart, bEnd]) => aStart < bEnd && bStart < aEnd));
        }

        function weeklyBlocksOverlapByDay(blockA, blockB) {
            for (let day = 1; day <= 7; day += 1) {
                const segmentsA = getWeeklySegmentsForDay(blockA, day);
                const segmentsB = getWeeklySegmentsForDay(blockB, day);
                if (segmentsA.length > 0 && segmentsB.length > 0 && doSegmentsOverlap(segmentsA, segmentsB)) {
                    return true;
                }
            }
            return false;
        }

        function minutesToTimeLabel(totalMinutes) {
            const safe = Math.max(0, Math.min(1439, parseInt(totalMinutes, 10) || 0));
            const hour = Math.floor(safe / 60);
            const minute = safe % 60;
            return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
        }

        function minutesToTimeString(totalMinutes) {
            const normalized = ((parseInt(totalMinutes, 10) || 0) % 1440 + 1440) % 1440;
            const hour = Math.floor(normalized / 60);
            const minute = normalized % 60;
            return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}:00`;
        }

        function getScheduleSlotCount() {
            return Math.floor(1440 / scheduleGridStepMinutes);
        }

        function clampMinuteToGrid(minuteValue) {
            const raw = Math.max(0, Math.min(1439, parseInt(minuteValue, 10) || 0));
            return Math.floor(raw / scheduleGridStepMinutes) * scheduleGridStepMinutes;
        }

        function getCurrentIsoWeekValue() {
            const today = new Date();
            const date = new Date(Date.UTC(today.getFullYear(), today.getMonth(), today.getDate()));
            const day = date.getUTCDay() || 7;
            date.setUTCDate(date.getUTCDate() + 4 - day);
            const yearStart = new Date(Date.UTC(date.getUTCFullYear(), 0, 1));
            const weekNo = Math.ceil((((date - yearStart) / 86400000) + 1) / 7);
            return `${date.getUTCFullYear()}-W${String(weekNo).padStart(2, '0')}`;
        }

        function getDateFromIsoWeek(weekValue) {
            const match = String(weekValue || '').match(/^(\d{4})-W(\d{2})$/);
            if (!match) {
                return null;
            }
            const year = parseInt(match[1], 10);
            const week = parseInt(match[2], 10);
            if (!year || !week) {
                return null;
            }

            const jan4 = new Date(year, 0, 4);
            const jan4Day = jan4.getDay() === 0 ? 7 : jan4.getDay();
            const firstMonday = new Date(jan4);
            firstMonday.setHours(0, 0, 0, 0);
            firstMonday.setDate(jan4.getDate() - (jan4Day - 1));

            const monday = new Date(firstMonday);
            monday.setDate(firstMonday.getDate() + ((week - 1) * 7));
            return monday;
        }

        function formatScheduleWeekOffsetLabel(offset) {
            const weekStart = getWeekStartDate(offset);
            const weekEnd = new Date(weekStart);
            weekEnd.setDate(weekStart.getDate() + 6);
            if (offset === 0) {
                return `Aktuális hét (${toDateKey(weekStart)} → ${toDateKey(weekEnd)})`;
            }
            if (offset === 1) {
                return `Jövő hét (${toDateKey(weekStart)} → ${toDateKey(weekEnd)})`;
            }
            if (offset === -1) {
                return `Előző hét (${toDateKey(weekStart)} → ${toDateKey(weekEnd)})`;
            }
            return `${toDateKey(weekStart)} → ${toDateKey(weekEnd)}`;
        }

        function renderScheduleWeekOffsetOptions() {
            const select = document.getElementById('schedule-week-offset');
            if (!select) {
                return;
            }

            select.innerHTML = '';
            for (let offset = -52; offset <= 52; offset += 1) {
                const option = document.createElement('option');
                option.value = String(offset);
                option.textContent = formatScheduleWeekOffsetLabel(offset);
                select.appendChild(option);
            }
            select.value = String(scheduleWeekOffset);

            const picker = document.getElementById('schedule-week-picker');
            if (picker) {
                const monday = getWeekStartDate(scheduleWeekOffset);
                const diffDays = Math.floor((monday - getWeekStartDate(0)) / 86400000);
                const targetDate = new Date();
                targetDate.setDate(targetDate.getDate() + diffDays);
                picker.value = getCurrentIsoWeekValue();
                const computed = getDateFromIsoWeek(picker.value);
                if (!computed || toDateKey(computed) !== toDateKey(monday)) {
                    const utcDate = new Date(Date.UTC(monday.getFullYear(), monday.getMonth(), monday.getDate()));
                    const day = utcDate.getUTCDay() || 7;
                    utcDate.setUTCDate(utcDate.getUTCDate() + 4 - day);
                    const yearStart = new Date(Date.UTC(utcDate.getUTCFullYear(), 0, 1));
                    const weekNo = Math.ceil((((utcDate - yearStart) / 86400000) + 1) / 7);
                    picker.value = `${utcDate.getUTCFullYear()}-W${String(weekNo).padStart(2, '0')}`;
                }
            }
        }

        function overlapsWithSlot(block, day, slotStartMinute, slotEndMinuteExclusive) {
            if (!block || block.block_type !== 'weekly') {
                return false;
            }
            const segments = getWeeklySegmentsForDay(block, day);
            return segments.some(([segmentStart, segmentEnd]) => slotStartMinute < segmentEnd && segmentStart < slotEndMinuteExclusive);
        }

        function renderWeeklyScheduleGrid() {
            const table = document.getElementById('weekly-schedule-grid');
            if (!table) {
                return;
            }

            updateActiveLoopVisualState();

            scheduleWeekOffset = Number.isFinite(scheduleWeekOffset) ? parseInt(scheduleWeekOffset, 10) : 0;

            const weekLabel = document.getElementById('schedule-week-label');
            const weekStart = getWeekStartDate(scheduleWeekOffset);
            const weekEnd = new Date(weekStart);
            weekEnd.setDate(weekStart.getDate() + 6);
            if (weekLabel) {
                weekLabel.textContent = `${toDateKey(weekStart)} → ${toDateKey(weekEnd)}`;
            }

            const todayKey = toDateKey(new Date());
            const isCurrentWeek = scheduleWeekOffset === 0;
            const rows = [];
            rows.push(`<thead><tr><th class="hour-col">${getWeeklyGridText('hour')}</th>` + [1,2,3,4,5,6,7].map((d) => {
                const dt = getDateForDayInOffsetWeek(d);
                const dateKey = toDateKey(dt);
                const isToday = isCurrentWeek && dateKey === todayKey;
                const thClass = isToday ? ' class="schedule-day-today"' : '';
                const marker = isToday ? `<br><span style="font-size:10px; color:#1f3e56; font-weight:700;">${getWeeklyGridText('today')}</span>` : '';
                return `<th${thClass}>${dayName(d)}<br><span style="font-size:10px; color:#607083;">${String(dt.getDate()).padStart(2, '0')}.${String(dt.getMonth() + 1).padStart(2, '0')}</span>${marker}</th>`;
            }).join('') + '</tr></thead>');
            rows.push('<tbody>');
            const slotCount = getScheduleSlotCount();
            for (let slotIndex = 0; slotIndex < slotCount; slotIndex += 1) {
                const slotStartMinute = slotIndex * scheduleGridStepMinutes;
                const slotEndMinuteExclusive = Math.min(1440, slotStartMinute + scheduleGridStepMinutes);
                const timeLabel = minutesToTimeLabel(slotStartMinute);
                rows.push(`<tr><td class="hour-col">${timeLabel}</td>`);
                for (let day = 1; day <= 7; day += 1) {
                    const dateKey = toDateKey(getDateForDayInOffsetWeek(day));
                    const isTodayCell = isCurrentWeek && dateKey === todayKey;
                    const isRangeSelected = !!scheduleRangeSelection
                        && scheduleRangeSelection.day === day
                        && slotStartMinute >= Math.min(scheduleRangeSelection.startMinute, scheduleRangeSelection.endMinute)
                        && slotStartMinute <= Math.max(scheduleRangeSelection.startMinute, scheduleRangeSelection.endMinute);
                    const isResizePreview = isHourInResizePreview(day, slotStartMinute);
                    const weeklyBlocks = timeBlocks.filter((block) => block.block_type === 'weekly' && overlapsWithSlot(block, day, slotStartMinute, slotEndMinuteExclusive));
                    const hasWeekly = weeklyBlocks.length > 0;
                    const isActive = weeklyBlocks.some((block) => activeScope === `block:${block.id}`);
                    const primaryBlock = weeklyBlocks.find((block) => activeScope === `block:${block.id}`) || weeklyBlocks[0] || null;
                    const primaryBlockId = primaryBlock ? parseInt(primaryBlock.id, 10) : 0;
                    const primaryLoopStyle = primaryBlock ? getLoopStyleById(primaryBlock.loop_style_id || 0) : null;
                    const hasTurnedOffStyle = isTurnedOffLoopStyle(primaryLoopStyle);
                    const hasLocked = weeklyBlocks.some((block) => parseInt(block.is_locked || 0, 10) === 1);
                    const className = `schedule-cell${hasWeekly ? ' has-weekly' : ''}${hasTurnedOffStyle ? ' has-turned-off' : ''}${isActive ? ' active-scope' : ''}${isTodayCell ? ' today' : ''}${(isRangeSelected || isResizePreview) ? ' range-select' : ''}${hasLocked ? ' locked' : ''}`;
                    const styleName = hasWeekly
                        ? (() => {
                            const styleId = parseInt(weeklyBlocks[0].loop_style_id || 0, 10);
                            const style = getLoopStyleById(styleId);
                            return style ? style.name : '';
                        })()
                        : 'DEFAULT loop (idősávon kívül)';
                    const cellLabel = hasWeekly
                        ? `${styleName}${weeklyBlocks.length > 1 ? ` +${weeklyBlocks.length - 1}` : ''}`
                        : '';
                    rows.push(`<td class="${className}" data-day="${day}" data-minute="${slotStartMinute}" ondragover="allowScheduleDrop(event)" ondrop="dropLoopStyleToGrid(event, ${day}, ${slotStartMinute})" onmousedown="handleScheduleCellMouseDown(event, ${day}, ${slotStartMinute}, ${primaryBlockId})" onmouseenter="handleScheduleCellMouseEnter(${day}, ${slotStartMinute})" onmouseup="handleScheduleCellMouseUp(${day}, ${slotStartMinute})" title="${styleName}">${cellLabel ? `<span class='schedule-cell-label'>${cellLabel}</span>` : ''}</td>`);
                }
                rows.push('</tr>');
            }
            rows.push('</tbody>');
            table.innerHTML = rows.join('');
            table.classList.remove('step-60', 'step-30', 'step-15');
            table.classList.add(`step-${scheduleGridStepMinutes}`);
            table.classList.toggle('selecting', !!scheduleRangeSelection || !!scheduleBlockResize);
        }

        function getWeeklyBlockById(blockId) {
            const normalized = parseInt(blockId, 10);
            if (!normalized) {
                return null;
            }
            return timeBlocks.find((block) => parseInt(block.id, 10) === normalized && String(block.block_type || 'weekly') === 'weekly') || null;
        }

        function getWeeklyBlockResizeBaseRange(block) {
            if (!block || String(block.block_type || 'weekly') !== 'weekly') {
                return null;
            }
            const startMinute = clampMinuteToGrid(parseMinuteFromTime(block.start_time, 0));
            const endMinuteRaw = clampMinuteToGrid(parseMinuteFromTime(block.end_time, 0));
            const endExclusive = endMinuteRaw === 0 && startMinute > 0 ? 1440 : endMinuteRaw;
            if (endExclusive <= startMinute) {
                return null;
            }
            return { startMinute, endExclusive };
        }

        function isHourInResizePreview(day, hour) {
            if (!scheduleBlockResize || parseInt(scheduleBlockResize.day, 10) !== parseInt(day, 10)) {
                return false;
            }
            const preview = getScheduleBlockResizePreview(scheduleBlockResize);
            if (!preview) {
                return false;
            }
            return hour >= preview.startMinute && hour <= preview.endMinuteInclusive;
        }

        function getScheduleBlockResizePreview(state) {
            if (!state) {
                return null;
            }

            const baseStart = parseInt(state.baseStartMinute, 10);
            const baseEndExclusive = parseInt(state.baseEndExclusive, 10);
            const currentMinute = clampMinuteToGrid(parseInt(state.currentMinute, 10));

            if (Number.isNaN(baseStart) || Number.isNaN(baseEndExclusive) || Number.isNaN(currentMinute)) {
                return null;
            }

            if (state.mode === 'start') {
                const newStart = Math.max(0, Math.min(currentMinute, baseEndExclusive - scheduleGridStepMinutes));
                return {
                    startMinute: newStart,
                    endMinuteInclusive: baseEndExclusive - scheduleGridStepMinutes
                };
            }

            const newEndInclusive = Math.min(1440 - scheduleGridStepMinutes, Math.max(currentMinute, baseStart));
            return {
                startMinute: baseStart,
                endMinuteInclusive: newEndInclusive
            };
        }

        function getSelectedLoopStyleForSchedule(forcedLoopStyleId = null) {
            const selectedStyleId = forcedLoopStyleId !== null
                ? parseInt(forcedLoopStyleId, 10)
                : parseInt(activeLoopStyleId || defaultLoopStyleId || 0, 10);
            if (!selectedStyleId) {
                showAutosaveToast('⚠️ Előbb válassz vagy hozz létre loop stílust', true);
                return null;
            }
            return selectedStyleId;
        }

        function createScheduleBlockFromRange(day, startMinute, endMinuteInclusive, loopStyleId = null) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const selectedStyleId = getSelectedLoopStyleForSchedule(loopStyleId);
            if (!selectedStyleId) {
                return;
            }
            if (parseInt(selectedStyleId, 10) === parseInt(defaultLoopStyleId || 0, 10)) {
                showAutosaveToast('⚠️ A DEFAULT loop nem tervezhető, az üres időket automatikusan kitölti', true);
                return;
            }

            const minMinute = clampMinuteToGrid(Math.min(startMinute, endMinuteInclusive));
            const maxMinute = clampMinuteToGrid(Math.max(startMinute, endMinuteInclusive));
            const start = minutesToTimeString(minMinute);
            const endExclusive = Math.min(1440, maxMinute + scheduleGridStepMinutes);
            const end = minutesToTimeString(endExclusive >= 1440 ? 0 : endExclusive);
            const payload = {
                id: nextTempTimeBlockId--,
                block_type: 'weekly',
                days_mask: String(day),
                start_time: start,
                end_time: end,
                block_name: `${dayName(day)} ${minutesToTimeLabel(minMinute)}-${minutesToTimeLabel(endExclusive >= 1440 ? 0 : endExclusive)}`,
                priority: 100,
                loop_style_id: selectedStyleId,
                is_active: 1,
                loops: []
            };

            if (!resolveScheduleConflicts(payload, null)) {
                showAutosaveToast('ℹ️ Ütközés miatt megszakítva', true);
                return;
            }

            timeBlocks.push(payload);
            activeScope = `block:${payload.id}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Idősáv létrehozva');
        }

        function startScheduleRangeSelection(event, day, hour) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }
            if (event && event.button !== 0) {
                return;
            }
            scheduleRangeSelection = {
                day: parseInt(day, 10),
                startMinute: clampMinuteToGrid(parseInt(hour, 10)),
                endMinute: clampMinuteToGrid(parseInt(hour, 10))
            };
            renderWeeklyScheduleGrid();
        }

        function startScheduleBlockResize(blockId, day, hour, mode) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const block = getWeeklyBlockById(blockId);
            if (!block) {
                return;
            }

            const days = String(block.days_mask || '').split(',').map((v) => parseInt(v, 10)).filter((v) => v >= 1 && v <= 7);
            const dayInt = parseInt(day, 10);
            if (!days.includes(dayInt)) {
                return;
            }

            const baseRange = getWeeklyBlockResizeBaseRange(block);
            if (!baseRange) {
                showAutosaveToast('⚠️ Éjfélen átnyúló blokk közvetlen nyújtása itt nem támogatott', true);
                return;
            }

            scheduleBlockResize = {
                blockId: parseInt(block.id, 10),
                day: dayInt,
                mode: mode === 'start' ? 'start' : 'end',
                baseStartMinute: baseRange.startMinute,
                baseEndExclusive: baseRange.endExclusive,
                currentMinute: clampMinuteToGrid(parseInt(hour, 10))
            };
            activeScope = `block:${parseInt(block.id, 10)}`;
            renderWeeklyScheduleGrid();
        }

        function updateScheduleBlockResize(day, hour) {
            if (!scheduleBlockResize) {
                return;
            }
            const dayInt = parseInt(day, 10);
            if (scheduleBlockResize.day !== dayInt) {
                return;
            }
            scheduleBlockResize.currentMinute = clampMinuteToGrid(parseInt(hour, 10));
            renderWeeklyScheduleGrid();
        }

        function finishScheduleBlockResize(day = null, hour = null) {
            if (!scheduleBlockResize) {
                return;
            }

            const fallbackDay = scheduleBlockResize.day;
            const fallbackHour = scheduleBlockResize.currentMinute;
            const dayInt = day === null ? fallbackDay : parseInt(day, 10);
            const hourInt = hour === null ? fallbackHour : parseInt(hour, 10);
            if (dayInt !== fallbackDay || Number.isNaN(hourInt)) {
                scheduleBlockResize = null;
                renderWeeklyScheduleGrid();
                return;
            }

            scheduleBlockResize.currentMinute = clampMinuteToGrid(hourInt);
            const preview = getScheduleBlockResizePreview(scheduleBlockResize);
            const block = getWeeklyBlockById(scheduleBlockResize.blockId);
            const resizeState = { ...scheduleBlockResize };
            scheduleBlockResize = null;

            if (!preview || !block) {
                renderWeeklyScheduleGrid();
                return;
            }

            const newStart = minutesToTimeString(preview.startMinute);
            const endExclusive = preview.endMinuteInclusive + scheduleGridStepMinutes;
            const normalizedEnd = endExclusive >= 1440 ? 0 : endExclusive;
            const newEnd = minutesToTimeString(normalizedEnd);

            const candidate = {
                ...block,
                start_time: newStart,
                end_time: newEnd,
                days_mask: String(resizeState.day)
            };

            if (!resolveScheduleConflicts(candidate, parseInt(block.id, 10))) {
                renderWeeklyScheduleGrid();
                showAutosaveToast('ℹ️ Ütközés miatt a nyújtás megszakítva', true);
                return;
            }

            timeBlocks = timeBlocks.map((entry) => {
                if (parseInt(entry.id, 10) !== parseInt(block.id, 10)) {
                    return entry;
                }
                return {
                    ...entry,
                    start_time: newStart,
                    end_time: newEnd,
                    days_mask: String(resizeState.day)
                };
            });

            activeScope = `block:${parseInt(block.id, 10)}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Időblokk frissítve');
        }

        function cancelScheduleBlockResize() {
            if (!scheduleBlockResize) {
                return;
            }
            scheduleBlockResize = null;
            renderWeeklyScheduleGrid();
        }

        function handleScheduleCellMouseDown(event, day, hour, primaryBlockId) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }
            if (event && event.button !== 0) {
                return;
            }

            const blockId = parseInt(primaryBlockId || 0, 10);
            if (blockId !== 0 && event?.currentTarget) {
                setActiveScope(`block:${blockId}`, true);
                openFixedWeeklyPlannerModal(blockId);
                return;
            }
            showAutosaveToast('ℹ️ Szerkesztéshez kattints egy meglévő eseményre', true);
        }

        function handleScheduleCellMouseEnter(day, hour) {
            if (scheduleBlockResize) {
                updateScheduleBlockResize(day, hour);
                return;
            }
            updateScheduleRangeSelection(day, hour);
        }

        function handleScheduleCellMouseUp(day, hour) {
            if (scheduleBlockResize) {
                finishScheduleBlockResize(day, hour);
                return;
            }
            finishScheduleRangeSelection(day, hour);
        }

        function updateScheduleRangeSelection(day, hour) {
            if (!scheduleRangeSelection) {
                return;
            }
            const d = parseInt(day, 10);
            if (scheduleRangeSelection.day !== d) {
                return;
            }
            scheduleRangeSelection.endMinute = clampMinuteToGrid(parseInt(hour, 10));
            renderWeeklyScheduleGrid();
        }

        function finishScheduleRangeSelection(day = null, hour = null) {
            if (!scheduleRangeSelection) {
                return;
            }

            const fallbackDay = scheduleRangeSelection.day;
            const fallbackHour = scheduleRangeSelection.endMinute;
            const d = day === null ? fallbackDay : parseInt(day, 10);
            const resolvedHour = hour === null ? fallbackHour : parseInt(hour, 10);

            if (scheduleRangeSelection.day !== d || Number.isNaN(resolvedHour)) {
                scheduleRangeSelection = null;
                renderWeeklyScheduleGrid();
                return;
            }

            const startHour = scheduleRangeSelection.startMinute;
            const endHour = resolvedHour;
            scheduleRangeSelection = null;
            renderWeeklyScheduleGrid();
            createScheduleBlockFromRange(d, startHour, endHour, null);
        }

        function cancelScheduleRangeSelection() {
            if (!scheduleRangeSelection) {
                return;
            }
            scheduleRangeSelection = null;
            renderWeeklyScheduleGrid();
        }

        function allowScheduleDrop(event) {
            if (event) {
                event.preventDefault();
            }
        }

        function dropLoopStyleToGrid(event, day, hour) {
            if (event) {
                event.preventDefault();
            }
            showAutosaveToast('ℹ️ Drag/drop helyett használd a fix heti sáv panelt', true);
        }

        function renderSpecialBlocksList() {
            const wrap = document.getElementById('special-blocks-list');
            if (!wrap) {
                return;
            }

            if (!specialOnlyMode) {
                wrap.innerHTML = '<div class="item"><span class="muted">A speciális események kezelése a Speciális terv oldalon érhető el.</span></div>';
                return;
            }

            const searchTerm = String(document.getElementById('special-date-search')?.value || '').trim().toLowerCase();
            const focusedDate = String(document.getElementById('special-day-focus')?.value || '').trim();

            const specialBlocks = timeBlocks
                .filter((block) => block.block_type === 'date' || block.block_type === 'datetime_range')
                .filter((block) => {
                    if (focusedDate) {
                        if (block.block_type === 'date' && String(block.specific_date || '') !== focusedDate) {
                            return false;
                        }
                        if (block.block_type === 'datetime_range') {
                            const startDate = String(block.start_datetime || '').slice(0, 10);
                            const endDate = String(block.end_datetime || '').slice(0, 10);
                            if (focusedDate < startDate || focusedDate > endDate) {
                                return false;
                            }
                        }
                    }
                    if (!searchTerm) {
                        return true;
                    }
                    const haystack = `${String(block.specific_date || '')} ${String(block.start_datetime || '')} ${String(block.end_datetime || '')} ${String(block.block_name || '')} ${String(block.start_time || '')} ${String(block.end_time || '')}`.toLowerCase();
                    return haystack.includes(searchTerm);
                })
                .sort((a, b) => getScopeLabel(a).localeCompare(getScopeLabel(b)));

            if (specialBlocks.length === 0) {
                wrap.innerHTML = `<div class="item"><span class="muted">${searchTerm ? 'Nincs találat a keresésre.' : 'Nincs speciális dátumos idősáv.'}</span></div>`;
                return;
            }

            wrap.innerHTML = specialBlocks.map((block) => {
                const active = activeScope === `block:${block.id}` ? ' style="font-weight:700;"' : '';
                const style = getLoopStyleById(block.loop_style_id || 0);
                return `<div class="item">
                    <span${active}>${getScopeLabel(block)} • ${style ? style.name : 'N/A'}</span>
                    <button class="btn" type="button" onclick="setActiveScope('block:${block.id}', true)">Szerkesztés</button>
                </div>`;
            }).join('');
        }

        function openSpecialDayPlanner() {
            if (!specialOnlyMode) {
                showAutosaveToast('ℹ️ Speciális eseményt csak a Speciális terv oldalon lehet létrehozni', true);
                return;
            }

            const dateVal = String(document.getElementById('special-day-focus')?.value || '').trim();
            if (!dateVal) {
                showAutosaveToast('⚠️ Előbb válassz napot', true);
                return;
            }

            const dayBlocks = timeBlocks
                .filter((block) => String(block.block_type || 'weekly') === 'date' && String(block.specific_date || '') === dateVal)
                .sort((a, b) => String(a.start_time || '').localeCompare(String(b.start_time || '')));

            const host = document.getElementById('time-block-modal-host');
            if (!host) {
                return;
            }

            host.innerHTML = `
                <div style="position:fixed; inset:0; background:rgba(0,0,0,0.45); display:flex; align-items:center; justify-content:center; z-index:3200;">
                    <div style="background:#fff; width:min(680px,94vw); border:1px solid #cfd6dd; padding:16px;">
                        <h3 style="margin:0 0 8px 0;">Speciális napi terv • ${dateVal}</h3>
                        <div style="font-size:12px; color:#425466; margin-bottom:10px;">Ez a napi terv felülírja az aznapi heti tervet az érintett idősávokban.</div>
                        <div style="max-height:220px; overflow:auto; border:1px solid #d9e0e7; margin-bottom:10px;">
                            ${dayBlocks.length === 0
                                ? '<div style="padding:8px; font-size:12px; color:#607080;">Nincs még speciális idősáv erre a napra.</div>'
                                : dayBlocks.map((block) => {
                                    const styleName = getLoopStyleName(block.loop_style_id || 0);
                                    return `<div style="display:grid; grid-template-columns:1fr auto auto; gap:8px; align-items:center; padding:8px; border-bottom:1px solid #edf1f4;">
                                        <span style="font-size:12px; color:#2b3f52;">${String(block.start_time).slice(0, 5)}-${String(block.end_time).slice(0, 5)} • ${styleName}</span>
                                        <button type="button" class="btn" onclick="setActiveScope('block:${block.id}', true); closeTimeBlockModal();">Szerkesztés</button>
                                        <button type="button" class="btn btn-danger" onclick="deleteSpecialDayBlock(${block.id}, '${dateVal}')">Törlés</button>
                                    </div>`;
                                }).join('')}
                        </div>
                        <div style="display:grid; grid-template-columns:120px 120px 1fr auto; gap:8px; align-items:center; margin-bottom:12px;">
                            <input type="text" id="special-day-plan-start" inputmode="numeric" placeholder="HH:MM" maxlength="5" pattern="^([01]\\d|2[0-3]):[0-5]\\d$" aria-label="Speciális napi kezdés (24 órás)">
                            <input type="text" id="special-day-plan-end" inputmode="numeric" placeholder="HH:MM" maxlength="5" pattern="^([01]\\d|2[0-3]):[0-5]\\d$" aria-label="Speciális napi befejezés (24 órás)">
                            <select id="special-day-plan-loop">
                                ${loopStyles
                                    .filter((style) => parseInt(style.id, 10) !== parseInt(defaultLoopStyleId || 0, 10))
                                    .map((style) => `<option value="${style.id}">${String(style.name || 'Loop')}</option>`)
                                    .join('')}
                            </select>
                            <button type="button" class="btn" onclick="addSpecialDayBlockFromPlanner('${dateVal}')">Hozzáadás</button>
                        </div>
                        <div style="font-size:12px; color:#425466; margin-bottom:6px;">Speciális intervallum (egyszeri, dátum+idő):</div>
                        <div style="display:grid; grid-template-columns:minmax(160px,1fr) minmax(160px,1fr) 1fr auto; gap:8px; align-items:center; margin-bottom:12px;">
                            <input id="special-range-start" type="datetime-local" aria-label="Intervallum kezdete">
                            <input id="special-range-end" type="datetime-local" aria-label="Intervallum vége">
                            <select id="special-range-loop">
                                ${loopStyles
                                    .filter((style) => parseInt(style.id, 10) !== parseInt(defaultLoopStyleId || 0, 10))
                                    .map((style) => `<option value="${style.id}">${String(style.name || 'Loop')}</option>`)
                                    .join('')}
                            </select>
                            <button type="button" class="btn" onclick="addSpecialRangeBlockFromPlanner()">Intervallum hozzáadása</button>
                        </div>
                        <div style="font-size:12px; color:#425466; margin-bottom:6px;">Ideiglenes kampány (szövegek egymás után, fix intervallumban):</div>
                        <div style="display:grid; grid-template-columns:minmax(160px,1fr) minmax(160px,1fr); gap:8px; align-items:center; margin-bottom:8px;">
                            <input id="special-temp-start" type="datetime-local" aria-label="Kampány kezdete">
                            <input id="special-temp-end" type="datetime-local" aria-label="Kampány vége">
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 120px auto; gap:8px; align-items:center; margin-bottom:12px;">
                            <textarea id="special-temp-texts" rows="5" placeholder="Minden sor külön szöveg kártya" style="width:100%; resize:vertical;"></textarea>
                            <input id="special-temp-duration" type="number" min="2" max="120" step="1" value="10" aria-label="Kártya időtartam (mp)">
                            <button type="button" class="btn" onclick="createTemporaryRangeCampaignFromPlanner()">Kampány létrehozása</button>
                        </div>
                        <div style="display:flex; justify-content:flex-end;">
                            <button type="button" class="btn" onclick="closeTimeBlockModal()">Bezárás</button>
                        </div>
                    </div>
                </div>
            `;

            set24HourTimeSelectValue('special-day-plan-start', '08:00');
            set24HourTimeSelectValue('special-day-plan-end', '10:00');

            const startRangeInput = document.getElementById('special-range-start');
            const endRangeInput = document.getElementById('special-range-end');
            const styleRangeSelect = document.getElementById('special-range-loop');
            const tempStartInput = document.getElementById('special-temp-start');
            const tempEndInput = document.getElementById('special-temp-end');
            if (styleRangeSelect) {
                const fallbackStyle = parseInt(document.getElementById('special-day-plan-loop')?.value || 0, 10);
                if (fallbackStyle) {
                    styleRangeSelect.value = String(fallbackStyle);
                }
            }
            if (startRangeInput && !startRangeInput.value) {
                startRangeInput.value = `${dateVal}T14:00`;
            }
            if (endRangeInput && !endRangeInput.value) {
                endRangeInput.value = `${dateVal}T16:00`;
            }
            if (tempStartInput) {
                tempStartInput.value = startRangeInput?.value || `${dateVal}T16:00`;
            }
            if (tempEndInput) {
                tempEndInput.value = endRangeInput?.value || `${dateVal}T22:00`;
            }
        }

        function createTemporaryRangeCampaignFromPlanner() {
            if (!specialOnlyMode) {
                showAutosaveToast('ℹ️ Ideiglenes kampányt csak a Speciális terv oldalon lehet létrehozni', true);
                return;
            }

            const startVal = String(document.getElementById('special-temp-start')?.value || '').trim();
            const endVal = String(document.getElementById('special-temp-end')?.value || '').trim();
            const rawTexts = String(document.getElementById('special-temp-texts')?.value || '');
            const durationRaw = parseInt(document.getElementById('special-temp-duration')?.value || '10', 10);
            const durationSeconds = Math.max(2, Math.min(120, Number.isFinite(durationRaw) ? durationRaw : 10));

            createTemporaryRangeCampaignFromInput(startVal, endVal, rawTexts, durationSeconds, true);
        }

        function createTemporaryRangeCampaignFromInput(startVal, endVal, rawTexts, durationSeconds, closeModalAfter = false) {
            if (!specialOnlyMode) {
                showAutosaveToast('ℹ️ Ideiglenes kampányt csak a Speciális terv oldalon lehet létrehozni', true);
                return false;
            }

            const normalizedDuration = Math.max(2, Math.min(120, Number.isFinite(parseInt(durationSeconds, 10)) ? parseInt(durationSeconds, 10) : 10));

            if (!startVal || !endVal) {
                showAutosaveToast('⚠️ Add meg a kampány kezdő és záró időpontját', true);
                return false;
            }

            const textModule = getModuleCatalogEntryByKey('text');
            if (!textModule || !parseInt(textModule.id || 0, 10)) {
                showAutosaveToast('⚠️ A Text modul nem érhető el ehhez a kampányhoz', true);
                return false;
            }

            const texts = rawTexts
                .split(/\r?\n/)
                .map((line) => String(line || '').trim())
                .filter(Boolean)
                .slice(0, 40);

            if (texts.length === 0) {
                showAutosaveToast('⚠️ Adj meg legalább egy szöveget (soronként egyet)', true);
                return false;
            }

            const startTs = Date.parse(startVal);
            const endTs = Date.parse(endVal);
            if (!Number.isFinite(startTs) || !Number.isFinite(endTs) || endTs <= startTs) {
                showAutosaveToast('⚠️ Az intervallum vége legyen később a kezdésnél', true);
                return false;
            }

            const startDateObj = new Date(startTs);
            const endDateObj = new Date(endTs);
            const startDate = `${startDateObj.getFullYear()}-${String(startDateObj.getMonth() + 1).padStart(2, '0')}-${String(startDateObj.getDate()).padStart(2, '0')}`;
            const endDate = `${endDateObj.getFullYear()}-${String(endDateObj.getMonth() + 1).padStart(2, '0')}-${String(endDateObj.getDate()).padStart(2, '0')}`;
            const startTime = `${String(startDateObj.getHours()).padStart(2, '0')}:${String(startDateObj.getMinutes()).padStart(2, '0')}:00`;
            const endTime = `${String(endDateObj.getHours()).padStart(2, '0')}:${String(endDateObj.getMinutes()).padStart(2, '0')}:00`;

            const styleName = `Temp ${startDateObj.getFullYear()}-${String(startDateObj.getMonth() + 1).padStart(2, '0')}-${String(startDateObj.getDate()).padStart(2, '0')} ${String(startDateObj.getHours()).padStart(2, '0')}:${String(startDateObj.getMinutes()).padStart(2, '0')}`;
            const textModuleId = parseInt(textModule.id, 10);
            const textModuleName = getLocalizedModuleName('text', String(textModule.name || 'Text'));
            const textModuleDesc = String(textModule.description || '');

            const items = texts.map((line) => {
                const item = buildLoopItemForModule(textModuleId, textModuleName, textModuleDesc, 'text');
                const baseSettings = (item.settings && typeof item.settings === 'object') ? item.settings : getDefaultSettings('text');
                item.duration_seconds = normalizedDuration;
                item.settings = {
                    ...baseSettings,
                    textSourceType: 'manual',
                    text: line
                };
                return item;
            });

            const createdStyle = createFallbackLoopStyle(styleName, items);
            loopStyles.push(createdStyle);
            ensureSingleDefaultLoopStyle();

            const payload = {
                id: nextTempTimeBlockId--,
                block_name: `Temp kampány ${startDate} ${startTime.slice(0, 5)}-${endDate} ${endTime.slice(0, 5)}`,
                block_type: 'datetime_range',
                start_datetime: `${startDate} ${startTime}`,
                end_datetime: `${endDate} ${endTime}`,
                specific_date: startDate,
                start_time: startTime,
                end_time: endTime,
                days_mask: '',
                priority: 400,
                loop_style_id: createdStyle.id,
                is_active: 1,
                loops: []
            };

            if (!resolveScheduleConflicts(payload, null)) {
                return false;
            }

            timeBlocks.push(payload);
            setActiveLoopStyle(createdStyle.id);
            activeScope = `block:${payload.id}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(120);
            showAutosaveToast('✓ Ideiglenes kampány létrehozva és ütemezve');
            if (closeModalAfter) {
                closeTimeBlockModal();
            }
            return true;
        }

        function createMobileQuickCampaign() {
            if (!specialOnlyMode) {
                showAutosaveToast('ℹ️ Ideiglenes kampányt csak a Speciális terv oldalon lehet létrehozni', true);
                return;
            }

            const startVal = String(document.getElementById('mobile-temp-start')?.value || '').trim();
            const endVal = String(document.getElementById('mobile-temp-end')?.value || '').trim();
            const rawTexts = String(document.getElementById('mobile-temp-texts')?.value || '');
            const durationRaw = parseInt(document.getElementById('mobile-temp-duration')?.value || '10', 10);
            const durationSeconds = Math.max(2, Math.min(120, Number.isFinite(durationRaw) ? durationRaw : 10));

            createTemporaryRangeCampaignFromInput(startVal, endVal, rawTexts, durationSeconds, false);
        }

        function initMobileQuickCampaignDefaults() {
            const startInput = document.getElementById('mobile-temp-start');
            const endInput = document.getElementById('mobile-temp-end');
            if (!startInput || !endInput) {
                return;
            }

            const now = new Date();
            const nextHour = new Date(now.getTime());
            nextHour.setMinutes(0, 0, 0);
            nextHour.setHours(nextHour.getHours() + 1);

            const plus6h = new Date(nextHour.getTime() + (6 * 60 * 60 * 1000));
            const toLocalDatetimeValue = (dt) => {
                const y = dt.getFullYear();
                const m = String(dt.getMonth() + 1).padStart(2, '0');
                const d = String(dt.getDate()).padStart(2, '0');
                const hh = String(dt.getHours()).padStart(2, '0');
                const mm = String(dt.getMinutes()).padStart(2, '0');
                return `${y}-${m}-${d}T${hh}:${mm}`;
            };

            if (!String(startInput.value || '').trim()) {
                startInput.value = toLocalDatetimeValue(nextHour);
            }
            if (!String(endInput.value || '').trim()) {
                endInput.value = toLocalDatetimeValue(plus6h);
            }
        }

        function addSpecialRangeBlockFromPlanner() {
            if (!specialOnlyMode) {
                showAutosaveToast('ℹ️ Speciális intervallumot csak a Speciális terv oldalon lehet létrehozni', true);
                return;
            }

            const startVal = String(document.getElementById('special-range-start')?.value || '').trim();
            const endVal = String(document.getElementById('special-range-end')?.value || '').trim();
            const styleId = parseInt(document.getElementById('special-range-loop')?.value || 0, 10);

            if (!startVal || !endVal || !styleId) {
                showAutosaveToast('⚠️ Hiányos intervallum adat', true);
                return;
            }

            const startTs = Date.parse(startVal);
            const endTs = Date.parse(endVal);
            if (!Number.isFinite(startTs) || !Number.isFinite(endTs) || endTs <= startTs) {
                showAutosaveToast('⚠️ Az intervallum vége legyen később a kezdésnél', true);
                return;
            }

            const startDateObj = new Date(startTs);
            const endDateObj = new Date(endTs);
            const startDate = `${startDateObj.getFullYear()}-${String(startDateObj.getMonth() + 1).padStart(2, '0')}-${String(startDateObj.getDate()).padStart(2, '0')}`;
            const endDate = `${endDateObj.getFullYear()}-${String(endDateObj.getMonth() + 1).padStart(2, '0')}-${String(endDateObj.getDate()).padStart(2, '0')}`;
            const startTime = `${String(startDateObj.getHours()).padStart(2, '0')}:${String(startDateObj.getMinutes()).padStart(2, '0')}:00`;
            const endTime = `${String(endDateObj.getHours()).padStart(2, '0')}:${String(endDateObj.getMinutes()).padStart(2, '0')}:00`;

            const payload = {
                id: nextTempTimeBlockId--,
                block_name: `Speciális intervallum ${startDate} ${startTime.slice(0, 5)}-${endDate} ${endTime.slice(0, 5)}`,
                block_type: 'datetime_range',
                start_datetime: `${startDate} ${startTime}`,
                end_datetime: `${endDate} ${endTime}`,
                specific_date: startDate,
                start_time: startTime,
                end_time: endTime,
                days_mask: '',
                priority: 400,
                loop_style_id: styleId,
                is_active: 1,
                loops: []
            };

            if (!resolveScheduleConflicts(payload, null)) {
                return;
            }

            timeBlocks.push(payload);
            
            if (specialOnlyMode) {
                const style = getLoopStyleById(styleId);
                if (style) {
                    const startDateClean = startDate.replace(/-/g, '');
                    const startTimeClean = startTime.slice(0, 5).replace(':', '');
                    const endDateClean = endDate.replace(/-/g, '');
                    const endTimeClean = endTime.slice(0, 5).replace(':', '');
                    style.name = `special_${startDateClean}${startTimeClean}-${endDateClean}${endTimeClean}`;
                }
            }
            
            activeScope = `block:${payload.id}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(120);
            showAutosaveToast('✓ Speciális intervallum létrehozva');
            closeTimeBlockModal();
        }

        function addSpecialDayBlockFromPlanner(dateVal) {
            if (!specialOnlyMode) {
                showAutosaveToast('ℹ️ Speciális napi idősávot csak a Speciális terv oldalon lehet létrehozni', true);
                return;
            }

            const startVal = String(document.getElementById('special-day-plan-start')?.value || '').trim();
            const endVal = String(document.getElementById('special-day-plan-end')?.value || '').trim();
            const styleId = parseInt(document.getElementById('special-day-plan-loop')?.value || 0, 10);

            if (!dateVal || !startVal || !endVal || !styleId) {
                showAutosaveToast('⚠️ Hiányos speciális napi adat', true);
                return;
            }

            const payload = {
                id: nextTempTimeBlockId--,
                block_name: `Speciális ${dateVal}`,
                block_type: 'date',
                specific_date: dateVal,
                start_time: `${startVal}:00`,
                end_time: `${endVal}:00`,
                days_mask: '',
                priority: 300,
                loop_style_id: styleId,
                is_active: 1,
                loops: []
            };

            if (!resolveScheduleConflicts(payload, null)) {
                return;
            }

            timeBlocks.push(payload);
            
            if (specialOnlyMode) {
                const style = getLoopStyleById(styleId);
                if (style) {
                    const startHM = startVal.replace(':', '');
                    const endHM = endVal.replace(':', '');
                    const cleanDate = dateVal.replace(/-/g, '');
                    style.name = `special_${cleanDate}${startHM}-${cleanDate}${endHM}`;
                }
            }
            
            activeScope = `block:${payload.id}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(120);
            showAutosaveToast('✓ Speciális napi idősáv létrehozva');
            openSpecialDayPlanner();
        }

        function deleteSpecialDayBlock(blockId, dateVal) {
            const normalized = parseInt(blockId, 10);
            if (!normalized) {
                return;
            }
            timeBlocks = timeBlocks.filter((entry) => parseInt(entry.id, 10) !== normalized);
            if (activeScope === `block:${normalized}`) {
                activeScope = 'base';
            }
            setActiveScope(activeScope, true);
            scheduleAutoSave(120);
            showAutosaveToast('✓ Speciális napi idősáv törölve');
            if (dateVal) {
                openSpecialDayPlanner();
            }
        }

        function setActiveScope(scope, shouldRender = true) {
            activeScope = scope;
            renderScopeSelector();
            syncWeeklyPlannerFromScope();
            if (shouldRender) renderSpecialBlocksList();
        }

        function handleScopeChange(scope) {
            setActiveScope(scope, true);
        }

        function blockMatchesDateTime(block, dt) {
            if (!block || !dt) {
                return false;
            }

            if (String(block.block_type || 'weekly') === 'datetime_range') {
                const startTs = Date.parse(String(block.start_datetime || '').replace(' ', 'T'));
                const endTs = Date.parse(String(block.end_datetime || '').replace(' ', 'T'));
                const nowTs = dt.getTime();
                if (!Number.isFinite(startTs) || !Number.isFinite(endTs)) {
                    return false;
                }
                return nowTs >= startTs && nowTs < endTs;
            }

            const hhmmss = `${String(dt.getHours()).padStart(2, '0')}:${String(dt.getMinutes()).padStart(2, '0')}:00`;
            const start = String(block.start_time || '00:00:00');
            const end = String(block.end_time || '00:00:00');
            const timeMatch = start <= end
                ? (hhmmss >= start && hhmmss <= end)
                : (hhmmss >= start || hhmmss <= end);
            if (!timeMatch) {
                return false;
            }

            if (String(block.block_type || 'weekly') === 'date') {
                const dateStr = `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;
                return dateStr === String(block.specific_date || '');
            }

            const weekday = dt.getDay() === 0 ? 7 : dt.getDay();
            const minuteOfDay = (dt.getHours() * 60) + dt.getMinutes();
            const segments = getWeeklySegmentsForDay(block, weekday);
            return segments.some(([segmentStart, segmentEnd]) => minuteOfDay >= segmentStart && minuteOfDay < segmentEnd);
        }

        function resolveScopeByDateTime(dt) {
            const matching = timeBlocks.filter((block) => block.is_active !== 0 && blockMatchesDateTime(block, dt));
            if (matching.length === 0) {
                return 'base';
            }

            matching.sort((a, b) => {
                const getTypeWeight = (typeRaw) => {
                    const type = String(typeRaw || 'weekly');
                    if (type === 'datetime_range') return 3;
                    if (type === 'date') return 2;
                    return 1;
                };
                const typeWeightA = getTypeWeight(a.block_type);
                const typeWeightB = getTypeWeight(b.block_type);
                if (typeWeightA !== typeWeightB) {
                    return typeWeightB - typeWeightA;
                }
                const pa = parseInt(a.priority || 0, 10);
                const pb = parseInt(b.priority || 0, 10);
                if (pa !== pb) {
                    return pb - pa;
                }
                return parseInt(a.id, 10) - parseInt(b.id, 10);
            });

            return `block:${matching[0].id}`;
        }

        function changeScheduleWeek(delta) {
            const parsedDelta = parseInt(delta, 10);
            if (!Number.isFinite(parsedDelta) || parsedDelta === 0) {
                return;
            }
            scheduleWeekOffset += parsedDelta;
            renderWeeklyScheduleGrid();
        }

        function setScheduleWeekOffset(value) {
            const parsed = parseInt(value, 10);
            if (!Number.isFinite(parsed)) {
                return;
            }
            scheduleWeekOffset = parsed;
            renderWeeklyScheduleGrid();
        }

        function openScheduleDatePicker() {
            const picker = document.getElementById('schedule-date-picker');
            if (!picker) {
                return;
            }
            picker.value = toDateKey(getWeekStartDate(scheduleWeekOffset));
            if (typeof picker.showPicker === 'function') {
                picker.showPicker();
                return;
            }
            picker.focus();
            picker.click();
        }

        function setScheduleDateFromPicker(value) {
            const selected = new Date(String(value || '').trim());
            if (Number.isNaN(selected.getTime())) {
                return;
            }

            selected.setHours(0, 0, 0, 0);
            const selectedDay = selected.getDay() === 0 ? 7 : selected.getDay();
            const selectedMonday = new Date(selected);
            selectedMonday.setDate(selected.getDate() - (selectedDay - 1));
            selectedMonday.setHours(0, 0, 0, 0);

            const currentMonday = getWeekStartDate(0);
            const diffDays = Math.round((selectedMonday - currentMonday) / 86400000);
            scheduleWeekOffset = Math.round(diffDays / 7);
            renderWeeklyScheduleGrid();
        }

        function setScheduleGridStep(value) {
            const parsed = parseInt(value, 10);
            if (![15, 30, 60].includes(parsed)) {
                return;
            }
            scheduleGridStepMinutes = parsed;
            scheduleRangeSelection = null;
            scheduleBlockResize = null;
            renderWeeklyScheduleGrid();
        }

        function buildLoopPayload() {
            persistActiveLoopStyleItems();
            const defaultStyle = specialOnlyMode
                ? { items: [] }
                : (getLoopStyleById(defaultLoopStyleId) || getLoopStyleById(activeLoopStyleId) || { items: [] });
            const expandedTimeBlocks = deepClone(timeBlocks).map((block) => {
                const style = getLoopStyleById(block.loop_style_id || 0);
                return {
                    ...block,
                    loops: deepClone(style?.items || [])
                };
            });

            const loopStylePayload = specialOnlyMode
                ? deepClone(loopStyles).filter((style) => isSpecialStyle(style))
                : deepClone(loopStyles);

            return {
                base_loop: deepClone(defaultStyle.items || []),
                time_blocks: expandedTimeBlocks,
                loop_styles: loopStylePayload,
                default_loop_style_id: specialOnlyMode ? null : defaultLoopStyleId,
                schedule_blocks: deepClone(timeBlocks)
            };
        }

        function getLoopSnapshot() {
            return JSON.stringify(buildLoopPayload());
        }

        function scheduleAutoSave(delayMs = 700) {
            if (isDefaultGroup || !hasLoadedInitialLoop) {
                return;
            }
            queueDraftPersist(delayMs);
        }

        function isTechnicalLoopItem(item) {
            if (!item) {
                return false;
            }

            const moduleKey = String(item.module_key || '').toLowerCase();
            if (moduleKey === 'turned-off') {
                return false;
            }

            if (moduleKey === 'unconfigured') {
                return true;
            }

            if (!technicalModule) {
                return false;
            }

            return parseInt(item.module_id) === parseInt(technicalModule.id);
        }

        function hasRealModules(items = loopItems) {
            return items.some(item => !isTechnicalLoopItem(item));
        }

        function normalizeLoopItems() {
            if (!technicalModule) {
                return;
            }

            const realItems = loopItems.filter(item => !isTechnicalLoopItem(item));

            if (realItems.length > 0) {
                loopItems = realItems;
                return;
            }

            const existingTechnical = loopItems.find(item => isTechnicalLoopItem(item));

            if (existingTechnical) {
                if (isTurnedOffLoopItem(existingTechnical)) {
                    loopItems = [{
                        ...existingTechnical,
                        module_key: 'turned-off',
                        duration_seconds: TURNED_OFF_LOOP_DURATION_SECONDS
                    }];
                    return;
                }

                loopItems = [{
                    ...existingTechnical,
                    module_key: 'unconfigured',
                    duration_seconds: 60
                }];
                return;
            }

            loopItems = [{
                module_id: parseInt(technicalModule.id),
                module_name: technicalModule.name,
                description: technicalModule.description || 'Technikai modul – csak üres loop esetén.',
                module_key: 'unconfigured',
                duration_seconds: 60,
                settings: {}
            }];
        }
        
        // Calculate total loop duration
        function getTotalLoopDuration() {
            return loopItems.reduce((sum, item) => sum + parseInt(item.duration_seconds || 10), 0);
        }
        
        // Get elapsed time in current loop cycle
        function getElapsedTimeInLoop() {
            let elapsed = 0;
            for (let i = 0; i < currentPreviewIndex; i++) {
                elapsed += parseInt(loopItems[i].duration_seconds || 10);
            }
            elapsed += (Date.now() - currentModuleStartTime) / 1000;
            return elapsed;
        }

        function resolveLoopStylesAndBlocksFromConfig(data) {
            const plannerStyles = Array.isArray(data.loop_styles) ? data.loop_styles : [];
            if (plannerStyles.length > 0) {
                const styles = normalizeLoopStyles(plannerStyles, [], specialOnlyMode);
                const styleById = new Map();
                const styleByItemsSignature = new Map();

                const buildItemsSignature = (items) => {
                    try {
                        return JSON.stringify(Array.isArray(items) ? items : []);
                    } catch (_) {
                        return '[]';
                    }
                };

                const registerStyle = (style) => {
                    if (!style || typeof style !== 'object') {
                        return;
                    }
                    const styleId = parseInt(style.id || 0, 10);
                    if (styleId !== 0) {
                        styleById.set(styleId, style);
                    }
                    styleByItemsSignature.set(buildItemsSignature(style.items), style);
                };

                styles.forEach(registerStyle);

                let blocks = normalizeTimeBlocks(data.schedule_blocks || data.time_blocks || []);

                // Legacy compatibility: older payloads can carry loops in time_blocks but omit loop_style_id.
                blocks = blocks.map((block, index) => {
                    const resolvedStyleId = parseInt(block?.loop_style_id || 0, 10);
                    if (resolvedStyleId > 0 && styleById.has(resolvedStyleId)) {
                        return block;
                    }

                    const blockLoops = Array.isArray(block?.loops) ? block.loops : [];
                    if (blockLoops.length === 0) {
                        return block;
                    }

                    const signature = buildItemsSignature(blockLoops);
                    let matchedStyle = styleByItemsSignature.get(signature) || null;

                    if (!matchedStyle) {
                        matchedStyle = createFallbackLoopStyle(block.block_name || `Loop ${styles.length + index + 1}`, blockLoops);
                        styles.push(matchedStyle);
                        registerStyle(matchedStyle);
                    }

                    return {
                        ...block,
                        loop_style_id: parseInt(matchedStyle.id || 0, 10) || null
                    };
                });

                return {
                    styles,
                    defaultStyleId: specialOnlyMode
                        ? null
                        : (parseInt(data.default_loop_style_id ?? plannerStyles[0]?.id ?? 0, 10) || plannerStyles[0]?.id || null),
                    blocks
                };
            }

            if (specialOnlyMode) {
                return {
                    styles: [],
                    defaultStyleId: null,
                    blocks: normalizeTimeBlocks(data.time_blocks || [])
                };
            }

            const hasStructuredPayload = Array.isArray(data.base_loop) || Array.isArray(data.time_blocks);
            const baseItems = hasStructuredPayload
                ? (Array.isArray(data.base_loop) ? data.base_loop : [])
                : (Array.isArray(data.loops) ? data.loops : []);
            const styles = [createFallbackLoopStyle('DEFAULT', baseItems)];

            let blocks = normalizeTimeBlocks(data.time_blocks || []);
            blocks = blocks.map((block, index) => {
                const style = createFallbackLoopStyle(block.block_name || `Idősáv ${index + 1}`, Array.isArray(block.loops) ? block.loops : []);
                styles.push(style);
                return { ...block, loop_style_id: style.id };
            });

            return {
                styles,
                defaultStyleId: styles[0].id,
                blocks
            };
        }

        function applyLoadedLoopConfig(data) {
            const resolved = resolveLoopStylesAndBlocksFromConfig(data);
            debugGroupLoop('applyLoadedLoopConfig:resolved', {
                responseKeys: Object.keys(data || {}),
                raw: {
                    loop_styles: Array.isArray(data?.loop_styles) ? data.loop_styles.length : 0,
                    schedule_blocks: Array.isArray(data?.schedule_blocks) ? data.schedule_blocks.length : 0,
                    time_blocks: Array.isArray(data?.time_blocks) ? data.time_blocks.length : 0,
                    default_loop_style_id: data?.default_loop_style_id ?? null,
                    plan_version: data?.plan_version ?? null
                },
                resolved: {
                    styles: Array.isArray(resolved.styles) ? resolved.styles.length : 0,
                    defaultStyleId: resolved.defaultStyleId,
                    blocks: Array.isArray(resolved.blocks) ? resolved.blocks.length : 0,
                    blockTypeSummary: summarizeBlockTypes(resolved.blocks),
                    stylesPreview: summarizeStyleNames(resolved.styles),
                    blocksPreview: summarizeBlockPreview(resolved.blocks)
                }
            });
            loopStyles = resolved.styles;
            defaultLoopStyleId = resolved.defaultStyleId;
            timeBlocks = resolved.blocks;
            syncNextTempIdCursor();

            planVersionToken = String(data.plan_version || data.plan_version_token || data.loop_version || '');

            ensureSingleDefaultLoopStyle();

            activeLoopStyleId = parseInt(defaultLoopStyleId || loopStyles[0]?.id || 0, 10) || (loopStyles[0]?.id ?? null);
            loopItems = deepClone(getLoopStyleById(activeLoopStyleId)?.items || []);
            normalizeLoopItems();
            persistActiveLoopStyleItems();
            activeScope = 'base';
            setActiveScope('base', false);
            lastSavedSnapshot = getLoopSnapshot();
            lastPublishedPayload = JSON.parse(lastSavedSnapshot);
            hasLoadedInitialLoop = true;
            tryRestoreDraftFromCache();
            updatePendingSaveBar();

            debugGroupLoop('applyLoadedLoopConfig:applied', {
                activeLoopStyleId,
                defaultLoopStyleId,
                activeScope,
                loopItemsCount: Array.isArray(loopItems) ? loopItems.length : 0,
                loopStylesCount: Array.isArray(loopStyles) ? loopStyles.length : 0,
                timeBlocksCount: Array.isArray(timeBlocks) ? timeBlocks.length : 0,
                blockTypeSummary: summarizeBlockTypes(timeBlocks)
            });

            if (autoCreateSpecialLoop && specialOnlyMode) {
                if (forcedSpecialLoopName) {
                    const forcedStyle = ensureNamedSpecialLoopStyle(forcedSpecialLoopName);
                    if (forcedStyle) {
                        activeLoopStyleId = parseInt(forcedStyle.id, 10);
                        loopItems = deepClone(forcedStyle.items || []);
                        normalizeLoopItems();
                        persistActiveLoopStyleItems();
                    }
                }

                const workflowRange = resolveSpecialWorkflowRange();
                const startDatetime = workflowRange ? workflowRange.start : '';
                const endDatetime = workflowRange ? workflowRange.end : '';
                let createdFromRange = false;

                if (startDatetime && endDatetime) {
                    const startTs = Date.parse(startDatetime);
                    const endTs = Date.parse(endDatetime);
                    if (Number.isFinite(startTs) && Number.isFinite(endTs) && endTs > startTs) {
                        const ensuredStyle = ensureSpecialLoopStyleForRange(new Date(startTs), new Date(endTs));
                        if (ensuredStyle) {
                            setActiveLoopStyle(ensuredStyle.id);
                        }
                    }
                    createdFromRange = createSpecialRangeBlockFromWorkflow(startDatetime, endDatetime);
                }

                if (!createdFromRange) {
                    const existingSpecial = loopStyles.find((entry) => /^special_/i.test(String(entry.name || '').trim()));
                    if (existingSpecial) {
                        setActiveLoopStyle(existingSpecial.id);
                    } else {
                        const forcedStyle = ensureNamedSpecialLoopStyle(forcedSpecialLoopName);
                        if (forcedStyle) {
                            setActiveLoopStyle(forcedStyle.id);
                        } else {
                            createAutoSpecialLoop();
                        }
                    }
                }
            }

            renderLoopStyleSelector();
            renderScopeSelector();
            renderLoop();

            if (loopItems.length > 0) {
                setTimeout(() => startPreview(), 500);
            }
        }
        
        // Load existing loop configuration
        function loadLoop() {
            if (isDefaultGroup) {
                const defaultItem = getDefaultUnconfiguredItem();
                loopStyles = [{ id: -1, name: 'DEFAULT', items: defaultItem ? [defaultItem] : [] }];
                activeLoopStyleId = -1;
                defaultLoopStyleId = -1;
                loopItems = deepClone(loopStyles[0].items || []);
                timeBlocks = [];
                setActiveScope('base', true);
                renderLoopStyleSelector();
                hasLoadedInitialLoop = true;
                lastSavedSnapshot = getLoopSnapshot();
                lastPublishedPayload = JSON.parse(lastSavedSnapshot);
                setDraftDirty(false);
                if (loopItems.length > 0) {
                    setTimeout(() => startPreview(), 500);
                }
                return;
            }

            const baselineItem = getDefaultUnconfiguredItem();
            if (specialOnlyMode) {
                loopStyles = [];
                defaultLoopStyleId = null;
                activeLoopStyleId = null;
                loopItems = [];
            } else {
                loopStyles = [{ id: -1, name: 'DEFAULT', items: baselineItem ? [baselineItem] : [] }];
                defaultLoopStyleId = -1;
                activeLoopStyleId = -1;
                loopItems = deepClone(loopStyles[0].items || []);
            }
            timeBlocks = [];
            activeScope = 'base';
            setActiveScope('base', false);
            renderLoopStyleSelector();
            renderScopeSelector();
            renderLoop();
            hasLoadedInitialLoop = true;
            lastSavedSnapshot = getLoopSnapshot();

            const loadUrl = `../../api/group_loop/config.php?group_id=${groupId}${specialOnlyMode ? '&special_only=1' : ''}`;
            debugGroupLoop('loadLoop:request', { url: loadUrl });

            fetch(loadUrl)
                .then(response => response.json())
                .then(data => {
                    debugGroupLoop('loadLoop:response', {
                        success: !!data?.success,
                        message: data?.message || '',
                        loop_styles: Array.isArray(data?.loop_styles) ? data.loop_styles.length : 0,
                        schedule_blocks: Array.isArray(data?.schedule_blocks) ? data.schedule_blocks.length : 0,
                        time_blocks: Array.isArray(data?.time_blocks) ? data.time_blocks.length : 0,
                        default_loop_style_id: data?.default_loop_style_id ?? null,
                        plan_version: data?.plan_version ?? null,
                        scheduleBlockTypes: summarizeBlockTypes(data?.schedule_blocks || [])
                    });
                    if (!data || !data.success) {
                        showAutosaveToast('⚠️ A loop lista betöltése sikertelen, DEFAULT loop használatban', true);
                        if (specialOnlyMode && autoCreateSpecialLoop) {
                            const workflowRange = resolveSpecialWorkflowRange();
                            const startDatetime = workflowRange ? workflowRange.start : '';
                            const endDatetime = workflowRange ? workflowRange.end : '';
                            if (startDatetime && endDatetime) {
                                createSpecialRangeBlockFromWorkflow(startDatetime, endDatetime);
                            } else {
                                createAutoSpecialLoop();
                            }
                            renderLoopStyleSelector();
                            renderScopeSelector();
                            renderLoop();
                        }
                        return;
                    }

                    applyLoadedLoopConfig(data);
                })
                .catch(error => {
                    debugGroupLoop('loadLoop:error', {
                        message: String(error?.message || error || 'unknown error')
                    });
                    console.error('Error loading loop:', error);
                    showAutosaveToast('⚠️ Hálózati hiba, DEFAULT loop használatban', true);
                    if (specialOnlyMode && autoCreateSpecialLoop) {
                        const workflowRange = resolveSpecialWorkflowRange();
                        const startDatetime = workflowRange ? workflowRange.start : '';
                        const endDatetime = workflowRange ? workflowRange.end : '';
                        if (startDatetime && endDatetime) {
                            createSpecialRangeBlockFromWorkflow(startDatetime, endDatetime);
                        } else {
                            createAutoSpecialLoop();
                        }
                        renderLoopStyleSelector();
                        renderScopeSelector();
                        renderLoop();
                    }
                });
        }

        function isLoopEditingBlocked() {
            const activeStyle = getLoopStyleById(activeLoopStyleId);
            return isDefaultGroup || isContentOnlyMode || isTurnedOffLoopStyle(activeStyle);
        }

        function ensureActiveLoopStyleSelected() {
            if (!getLoopStyleById(activeLoopStyleId)) {
                showAutosaveToast('⚠️ Nincs aktív loop kiválasztva', true);
                return false;
            }
            return true;
        }

        function normalizeRenderAndAutosaveLoop() {
            normalizeLoopItems();
            renderLoop();
            scheduleAutoSave();
        }

        function refreshDurationAndRestartPreviewIfNeeded() {
            updateTotalDuration();
            scheduleAutoSave();
            if (loopItems.length > 0) {
                startPreview();
            }
        }

        function buildLoopItemForModule(moduleId, moduleName, moduleDesc, moduleKey) {
            const normalizedKey = String(moduleKey || '').toLowerCase();
            const defaultDuration = normalizedKey === 'turned-off'
                ? TURNED_OFF_LOOP_DURATION_SECONDS
                : ((normalizedKey === 'unconfigured' || normalizedKey === 'meal-menu') ? 60 : 10);
            return {
                module_id: moduleId,
                module_name: getLocalizedModuleName(moduleKey || getModuleKeyById(moduleId), moduleName),
                description: moduleDesc,
                module_key: moduleKey || null,
                duration_seconds: defaultDuration,
                settings: getDefaultSettings(moduleKey || '')
            };
        }

        function getTurnedOffLoopTemplate() {
            const fallbackId = parseInt(technicalModule?.id || 0, 10);
            const actionId = parseInt(turnedOffLoopAction?.id || 0, 10);
            const moduleId = actionId > 0 ? actionId : (fallbackId > 0 ? fallbackId : 0);
            if (moduleId <= 0) {
                return null;
            }

            return buildLoopItemForModule(
                moduleId,
                String(turnedOffLoopAction?.name || tr('group_loop.turned_off.name', loopUiText('turned_off_name'))),
                String(turnedOffLoopAction?.description || tr('group_loop.turned_off.description', loopUiText('turned_off_desc'))),
                'turned-off'
            );
        }

        function isTurnedOffLoopItem(item) {
            if (!item || typeof item !== 'object') {
                return false;
            }
            const key = String(item.module_key || '').toLowerCase();
            return key === 'turned-off';
        }

        function isTurnedOffLoopStyle(style) {
            if (!style || typeof style !== 'object') {
                return false;
            }
            const items = Array.isArray(style.items) ? style.items : [];
            return items.length === 1 && isTurnedOffLoopItem(items[0]);
        }

        function isSpecialStyle(style) {
            if (!style || typeof style !== 'object') {
                return false;
            }

            if (isTurnedOffLoopStyle(style)) {
                return true;
            }

            const name = String(style.name || '').trim();
            if (/^special_/i.test(name)) {
                return true;
            }

            return isTurnedOffLoopStyle(style);
        }

        function ensureTurnedOffLoopStyle() {
            if (isDefaultGroup || isContentOnlyMode || !turnedOffLoopAction) {
                return null;
            }

            const desiredName = String(turnedOffLoopAction.name || tr('group_loop.turned_off.name', loopUiText('turned_off_name'))).trim() || tr('group_loop.turned_off.name', loopUiText('turned_off_name'));
            const isLegacyTurnedOffName = (name) => /kikapcsol|turned\s*off/i.test(String(name || ''));
            const defaultStyleId = parseInt(defaultLoopStyleId || 0, 10);
            const technicalModuleId = parseInt(technicalModule?.id || 0, 10);

            const turnedOffCandidates = (Array.isArray(loopStyles) ? loopStyles : []).filter((style) => {
                const styleId = parseInt(style?.id || 0, 10);
                if (!styleId || styleId === defaultStyleId) {
                    return false;
                }

                if (isTurnedOffLoopStyle(style)) {
                    return true;
                }

                const items = Array.isArray(style?.items) ? style.items : [];
                if (items.length !== 1) {
                    return false;
                }
                const itemModuleId = parseInt(items[0]?.module_id || 0, 10);
                return technicalModuleId > 0 && itemModuleId === technicalModuleId && isLegacyTurnedOffName(style?.name);
            });

            if (turnedOffCandidates.length > 1) {
                const keepStyle = turnedOffCandidates[0];
                const removedIds = new Set(turnedOffCandidates.slice(1).map((style) => parseInt(style.id, 10)));

                timeBlocks = (Array.isArray(timeBlocks) ? timeBlocks : []).map((block) => {
                    const styleId = parseInt(block?.loop_style_id || 0, 10);
                    if (removedIds.has(styleId)) {
                        return {
                            ...block,
                            loop_style_id: parseInt(keepStyle.id, 10)
                        };
                    }
                    return block;
                });

                loopStyles = loopStyles.filter((style) => !removedIds.has(parseInt(style?.id || 0, 10)));
            }

            const legacyStyle = (Array.isArray(loopStyles) ? loopStyles : []).find((style) => {
                const styleId = parseInt(style?.id || 0, 10);
                if (styleId === defaultStyleId) {
                    return false;
                }
                const items = Array.isArray(style?.items) ? style.items : [];
                if (items.length !== 1 || isTurnedOffLoopItem(items[0])) {
                    return false;
                }
                const itemModuleId = parseInt(items[0]?.module_id || 0, 10);
                return technicalModuleId > 0 && itemModuleId === technicalModuleId && isLegacyTurnedOffName(style?.name);
            }) || null;

            if (legacyStyle) {
                const turnedOffTemplate = getTurnedOffLoopTemplate();
                if (!turnedOffTemplate) {
                    return null;
                }
                legacyStyle.name = desiredName;
                legacyStyle.items = [turnedOffTemplate];
                legacyStyle.last_modified_ms = Date.now();
                return legacyStyle;
            }

            let existing = (Array.isArray(loopStyles) ? loopStyles : []).find((style) => {
                const styleId = parseInt(style?.id || 0, 10);
                return styleId !== defaultStyleId && isTurnedOffLoopStyle(style);
            }) || null;

            if (existing) {
                if (String(existing.name || '').trim() !== desiredName) {
                    existing.name = desiredName;
                    existing.last_modified_ms = Date.now();
                }
                const turnedOffTemplate = getTurnedOffLoopTemplate();
                if (turnedOffTemplate) {
                    existing.items = [turnedOffTemplate];
                }
                return existing;
            }

            const turnedOffItem = getTurnedOffLoopTemplate();
            if (!turnedOffItem) {
                return null;
            }

            const created = createFallbackLoopStyle(
                desiredName,
                [turnedOffItem]
            );
            loopStyles.push(created);
            ensureSingleDefaultLoopStyle();
            return created;
        }

        function addTurnedOffLoopItem() {
            if (isLoopEditingBlocked()) {
                return;
            }

            if (!ensureActiveLoopStyleSelected()) {
                return;
            }

            const turnedOffItem = getTurnedOffLoopTemplate();
            if (!turnedOffItem) {
                showAutosaveToast('⚠️ A turned-off loop elem most nem elérhető', true);
                return;
            }

            loopItems = [turnedOffItem];
            normalizeRenderAndAutosaveLoop();
            showAutosaveToast('✓ Kikapcsolás loop beállítva');
        }

        function parseModuleCatalogPayload(rawPayload) {
            try {
                const data = JSON.parse(rawPayload || '{}');
                const moduleId = parseInt(data.id || 0, 10);
                const moduleName = String(data.name || '').trim();
                const moduleDesc = String(data.description || '');
                if (!moduleId || !moduleName) {
                    return null;
                }

                return {
                    id: moduleId,
                    name: moduleName,
                    description: moduleDesc
                };
            } catch (_) {
                return null;
            }
        }

        function getLoopTargetIndexFromDropEvent(event) {
            const targetLoopItem = event?.target?.closest ? event.target.closest('.loop-item') : null;
            const targetIndex = parseInt(targetLoopItem?.dataset?.index || '-1', 10);
            if (!Number.isFinite(targetIndex) || targetIndex < 0 || !loopItems[targetIndex]) {
                return -1;
            }
            return targetIndex;
        }

        function tryApplyOverlayPresetFromDrop(targetIndex, droppedModuleKey) {
            if (targetIndex < 0) {
                return false;
            }

            if (droppedModuleKey !== 'clock' && droppedModuleKey !== 'text') {
                return false;
            }

            const targetItem = loopItems[targetIndex];
            const targetModuleKey = targetItem?.module_key || getModuleKeyById(targetItem?.module_id);
            if (!isOverlayCarrierModule(targetModuleKey)) {
                return false;
            }

            targetItem.settings = applyOverlayPresetToSettings(targetItem.settings || {}, droppedModuleKey);
            renderLoop();
            scheduleAutoSave();
            showAutosaveToast(droppedModuleKey === 'clock'
                ? overlayUiText('drop_clock_ok')
                : overlayUiText('drop_text_ok'));
            return true;
        }

        function reorderLoopItems(draggedIndex, targetIndex) {
            if (!Number.isFinite(draggedIndex) || !Number.isFinite(targetIndex)) {
                return false;
            }
            if (draggedIndex < 0 || targetIndex < 0) {
                return false;
            }
            if (!loopItems[draggedIndex] || !loopItems[targetIndex]) {
                return false;
            }

            const item = loopItems.splice(draggedIndex, 1)[0];
            loopItems.splice(targetIndex, 0, item);
            return true;
        }

        function applyDurationUpdateForSpecialModule(index, moduleKey) {
            if (isTechnicalLoopItem(loopItems[index])) {
                loopItems[index].duration_seconds = 60;
                refreshDurationAndRestartPreviewIfNeeded();
                return true;
            }

            if (moduleKey === 'video') {
                const fixedDuration = parseInt(loopItems[index]?.settings?.videoDurationSec || 0, 10);
                if (fixedDuration > 0) {
                    loopItems[index].duration_seconds = fixedDuration;
                    refreshDurationAndRestartPreviewIfNeeded();
                }
                return true;
            }

            if (moduleKey === 'image-gallery' || moduleKey === 'gallery') {
                loopItems[index].duration_seconds = getGalleryLoopDurationSeconds(loopItems[index]?.settings || {}, loopItems[index]?.duration_seconds);
                refreshDurationAndRestartPreviewIfNeeded();
                return true;
            }

            return false;
        }
        
        function addModuleToLoop(moduleId, moduleName, moduleDesc) {
            if (isLoopEditingBlocked()) {
                return;
            }

            if (!ensureActiveLoopStyleSelected()) {
                return;
            }

            const moduleKey = getModuleKeyById(moduleId);

            if (moduleKey === 'turned-off') {
                const turnedOffItem = buildLoopItemForModule(moduleId, moduleName, moduleDesc, moduleKey);
                loopItems = [turnedOffItem];
                normalizeRenderAndAutosaveLoop();
                return;
            }

            loopItems = loopItems.filter((item) => getLoopItemModuleKey(item) !== 'turned-off');

            if (moduleKey !== 'unconfigured') {
                loopItems = loopItems.filter(item => !isTechnicalLoopItem(item));
            } else if (loopItems.some(item => isTechnicalLoopItem(item))) {
                return;
            }

            loopItems.push(buildLoopItemForModule(moduleId, moduleName, moduleDesc, moduleKey));
            normalizeRenderAndAutosaveLoop();
        }

        function getCatalogModuleDataFromElement(element) {
            if (!element || !element.dataset) {
                return null;
            }

            const moduleId = parseInt(element.dataset.moduleId || '0', 10);
            const moduleName = String(element.dataset.moduleName || '').trim();
            const moduleDesc = String(element.dataset.moduleDesc || '');

            if (!moduleId || !moduleName) {
                return null;
            }

            return {
                id: moduleId,
                name: moduleName,
                description: moduleDesc
            };
        }

        function addModuleToLoopFromDataset(element) {
            const moduleData = getCatalogModuleDataFromElement(element);
            if (!moduleData) {
                return;
            }

            addModuleToLoop(moduleData.id, moduleData.name, moduleData.description);
        }

        function handleModuleCatalogDragStartFromDataset(event, element) {
            const moduleData = getCatalogModuleDataFromElement(element);
            if (!moduleData) {
                return;
            }

            handleModuleCatalogDragStart(event, moduleData);
        }

        function handleModuleCatalogDragStart(event, payload) {
            if (isDefaultGroup || isContentOnlyMode || !event?.dataTransfer || !payload) {
                return;
            }

            const data = {
                id: parseInt(payload.id || 0, 10),
                name: String(payload.name || ''),
                description: String(payload.description || '')
            };

            if (!data.id || !data.name) {
                return;
            }

            event.dataTransfer.effectAllowed = 'copy';
            event.dataTransfer.setData('text/module-catalog-item', JSON.stringify(data));
        }

        function allowModuleCatalogDrop(event) {
            if (!event) {
                return;
            }
            event.preventDefault();
            const container = document.getElementById('loop-container');
            if (container) {
                container.classList.add('catalog-drop-active');
            }
        }

        function handleModuleCatalogDragLeave(event) {
            const container = document.getElementById('loop-container');
            if (!container) {
                return;
            }

            const related = event?.relatedTarget;
            if (related && container.contains(related)) {
                return;
            }
            container.classList.remove('catalog-drop-active');
        }

        function dropCatalogModuleToLoop(event) {
            if (!event) {
                return;
            }

            event.preventDefault();
            const container = document.getElementById('loop-container');
            if (container) {
                container.classList.remove('catalog-drop-active');
            }

            if (isLoopEditingBlocked() || !event.dataTransfer) {
                return;
            }

            if (!ensureActiveLoopStyleSelected()) {
                return;
            }

            const raw = event.dataTransfer.getData('text/module-catalog-item');
            if (!raw) {
                return;
            }

            const payload = parseModuleCatalogPayload(raw);
            if (!payload) {
                console.error('Invalid module drop payload');
                return;
            }

            const droppedModuleKey = getModuleKeyById(payload.id);
            const targetIndex = getLoopTargetIndexFromDropEvent(event);
            if (tryApplyOverlayPresetFromDrop(targetIndex, droppedModuleKey)) {
                return;
            }

            addModuleToLoop(payload.id, payload.name, payload.description);
        }
        
        function removeFromLoop(index) {
            if (isLoopEditingBlocked()) {
                return;
            }

            loopItems.splice(index, 1);
            normalizeRenderAndAutosaveLoop();
        }

        function duplicateLoopItem(index) {
            if (isLoopEditingBlocked()) {
                return;
            }

            const sourceItem = loopItems[index];
            if (!sourceItem || isTechnicalLoopItem(sourceItem)) {
                return;
            }

            const duplicatedItem = {
                module_id: sourceItem.module_id,
                module_name: sourceItem.module_name,
                description: sourceItem.description || '',
                module_key: sourceItem.module_key || null,
                duration_seconds: parseInt(sourceItem.duration_seconds || 10),
                settings: sourceItem.settings
                    ? JSON.parse(JSON.stringify(sourceItem.settings))
                    : {}
            };

            loopItems.splice(index + 1, 0, duplicatedItem);
            normalizeRenderAndAutosaveLoop();
            showAutosaveToast('✓ Elem duplikálva');
        }
        
        function updateDuration(index, value) {
            if (isLoopEditingBlocked()) {
                return;
            }

            const moduleKey = loopItems[index]?.module_key || getModuleKeyById(loopItems[index]?.module_id);

            if (String(moduleKey || '').toLowerCase() === 'turned-off') {
                loopItems[index].duration_seconds = TURNED_OFF_LOOP_DURATION_SECONDS;
                refreshDurationAndRestartPreviewIfNeeded();
                return;
            }

            if (applyDurationUpdateForSpecialModule(index, moduleKey)) {
                return;
            }

            const durationBounds = getDurationBoundsForModule(moduleKey);
            const parsedValue = parseInt(value, 10);
            const safeValue = Number.isFinite(parsedValue) ? parsedValue : durationBounds.default;
            const clampedValue = Math.max(durationBounds.min, Math.min(durationBounds.max, safeValue || durationBounds.default));
            loopItems[index].duration_seconds = clampedValue;
            refreshDurationAndRestartPreviewIfNeeded();
        }

        function getDurationBoundsForModule(moduleKey) {
            const key = String(moduleKey || '').toLowerCase();
            if (key === 'meal-menu' || key === 'room-occupancy') {
                return { min: 5, max: 3600, step: 1, default: 10 };
            }
            return { min: 1, max: 3600, step: 1, default: 10 };
        }
        
        let draggedElement = null;
        
        function handleDragStart(e) {
            draggedElement = this;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/html', this.innerHTML);
        }
        
        function handleDragOver(e) {
            if (e.preventDefault) {
                e.preventDefault();
            }
            e.dataTransfer.dropEffect = 'move';
            return false;
        }
        
        function handleDrop(e) {
            if (!draggedElement) {
                return true;
            }

            if (e.stopPropagation) {
                e.stopPropagation();
            }
            
            if (draggedElement !== this) {
                const draggedIndex = parseInt(draggedElement.dataset.index);
                const targetIndex = parseInt(this.dataset.index);

                if (reorderLoopItems(draggedIndex, targetIndex)) {
                    renderLoop();
                    scheduleAutoSave();
                }
            }
            
            return false;
        }
        
        function handleDragEnd(e) {
            this.classList.remove('dragging');
        }
        
        function updateTotalDuration() {
            const total = loopItems.reduce((sum, item) => sum + parseInt(item.duration_seconds), 0);
            const minutes = Math.floor(total / 60);
            const seconds = total % 60;
            document.getElementById('total-duration').textContent = `${loopUiText('total_prefix')}: ${total} ${loopUiText('seconds_short')} (${minutes}:${seconds.toString().padStart(2, '0')})`;
        }
        
        function clearLoop() {
            if (isLoopEditingBlocked()) {
                return;
            }

            if (confirm(loopUiText('confirm_clear_all'))) {
                loopItems = [];
                normalizeRenderAndAutosaveLoop();
            }
        }

        function getLoopPayloadItemCount(payload) {
            return (payload.base_loop || []).length + (payload.time_blocks || []).reduce((sum, block) => {
                return sum + (Array.isArray(block.loops) ? block.loops.length : 0);
            }, 0);
        }

        function shouldAbortSaveLoop(opts, payload, currentSnapshot) {
            if (isDefaultGroup) {
                if (!opts.silent) {
                    alert('⚠️ A default csoport loopja nem szerkeszthető.');
                }
                return true;
            }

            if (getLoopPayloadItemCount(payload) === 0) {
                if (!opts.silent) {
                    alert('⚠️ A loop üres! Adj hozzá legalább egy modult.');
                }
                return true;
            }

            if (currentSnapshot === lastSavedSnapshot) {
                return true;
            }

            if (autoSaveInFlight) {
                autoSaveQueued = true;
                return true;
            }

            return false;
        }

        function handleSaveLoopSuccess(data, currentSnapshot, opts = {}) {
            lastSavedSnapshot = currentSnapshot;
            lastPublishedPayload = JSON.parse(currentSnapshot);
            planVersionToken = String(data.plan_version || data.plan_version_token || data.loop_version || planVersionToken || '');
            clearDraftCache();
            setDraftDirty(false);
            showAutosaveToast('✓ Mentés sikeres');

            if (opts.source === 'publish' && specialOnlyMode && autoCreateSpecialLoop) {
                const target = new URL(window.location.href);
                target.searchParams.delete('workflow');
                target.searchParams.delete('wf_start');
                target.searchParams.delete('wf_end');
                window.location.href = target.toString();
            }
        }

        function handleSaveLoopFailure(data) {
            showAutosaveToast('⚠️ ' + (data.message || 'Mentési hiba'), true);
        }

        function finalizeSaveLoopRequest() {
            autoSaveInFlight = false;
            if (autoSaveQueued) {
                autoSaveQueued = false;
                queueDraftPersist(150);
            }
        }

        function ensureManualSaveOverlay() {
            let styleEl = document.getElementById('group-loop-save-overlay-style');
            if (!styleEl) {
                styleEl = document.createElement('style');
                styleEl.id = 'group-loop-save-overlay-style';
                styleEl.textContent = `
                    #group-loop-save-overlay {
                        position: fixed;
                        inset: 0;
                        display: none;
                        align-items: center;
                        justify-content: center;
                        background: rgba(15, 23, 42, 0.28);
                        backdrop-filter: blur(1px);
                        z-index: 20000;
                    }
                    #group-loop-save-overlay .group-loop-save-overlay-card {
                        display: inline-flex;
                        align-items: center;
                        gap: 10px;
                        padding: 12px 16px;
                        border-radius: 10px;
                        background: rgba(15, 23, 42, 0.92);
                        color: #f8fafc;
                        border: 1px solid rgba(148, 163, 184, 0.5);
                        box-shadow: 0 10px 28px rgba(2, 6, 23, 0.4);
                        font-weight: 700;
                    }
                    #group-loop-save-overlay .group-loop-save-spinner {
                        width: 16px;
                        height: 16px;
                        border-radius: 999px;
                        border: 2px solid rgba(255, 255, 255, 0.35);
                        border-top-color: #facc15;
                        animation: group-loop-save-spin .8s linear infinite;
                    }
                    @keyframes group-loop-save-spin {
                        from { transform: rotate(0deg); }
                        to { transform: rotate(360deg); }
                    }
                    body.group-loop-saving .container {
                        filter: blur(2px);
                        pointer-events: none;
                        user-select: none;
                    }
                    body.group-loop-saving #group-loop-save-overlay {
                        display: flex;
                    }
                `;
                document.head.appendChild(styleEl);
            }

            let overlay = document.getElementById('group-loop-save-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'group-loop-save-overlay';
                overlay.setAttribute('aria-live', 'polite');
                overlay.innerHTML = '<div class="group-loop-save-overlay-card"><span class="group-loop-save-spinner" aria-hidden="true"></span><span>Mentés folyamatban...</span></div>';
                document.body.appendChild(overlay);
            }
            return overlay;
        }

        function setManualSaveUiState(isSaving) {
            ensureManualSaveOverlay();
            document.body.classList.toggle('group-loop-saving', !!isSaving);

            const saveButtons = Array.from(document.querySelectorAll('.pending-save-btn, #pending-bar-confirm'));
            saveButtons.forEach((btn) => {
                if (!(btn instanceof HTMLButtonElement)) {
                    return;
                }
                btn.disabled = !!isSaving;
            });
        }
        
        function saveLoop(options = {}) {
            const opts = {
                silent: false,
                source: 'publish',
                ...options
            };

            const showManualSaveOverlay = opts.source === 'publish' && opts.silent !== true;

            const payload = buildLoopPayload();
            const currentSnapshot = getLoopSnapshot();
            const saveUrl = `../../api/group_loop/config.php?group_id=${groupId}${specialOnlyMode ? '&special_only=1' : ''}`;

            debugGroupLoop('saveLoop:request', {
                url: saveUrl,
                source: opts.source,
                silent: !!opts.silent,
                payload: {
                    loop_styles: Array.isArray(payload?.loop_styles) ? payload.loop_styles.length : 0,
                    schedule_blocks: Array.isArray(payload?.schedule_blocks) ? payload.schedule_blocks.length : 0,
                    time_blocks: Array.isArray(payload?.time_blocks) ? payload.time_blocks.length : 0,
                    default_loop_style_id: payload?.default_loop_style_id ?? null,
                    scheduleBlockTypes: summarizeBlockTypes(payload?.schedule_blocks || []),
                    stylesPreview: summarizeStyleNames(payload?.loop_styles || []),
                    blocksPreview: summarizeBlockPreview(payload?.schedule_blocks || [])
                }
            });

            if (shouldAbortSaveLoop(opts, payload, currentSnapshot)) {
                debugGroupLoop('saveLoop:aborted', {
                    reason: 'shouldAbortSaveLoop returned true',
                    snapshotEqualsLastSaved: currentSnapshot === lastSavedSnapshot,
                    autoSaveInFlight,
                    isDefaultGroup,
                    itemCount: getLoopPayloadItemCount(payload)
                });
                return;
            }

            autoSaveInFlight = true;
            if (showManualSaveOverlay) {
                setManualSaveUiState(true);
            }
            
            fetch(saveUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(data => {
                debugGroupLoop('saveLoop:response', {
                    success: !!data?.success,
                    message: data?.message || '',
                    plan_version: data?.plan_version ?? null
                });
                if (data.success) {
                    handleSaveLoopSuccess(data, currentSnapshot, opts);
                } else {
                    handleSaveLoopFailure(data);
                }
            })
            .catch(error => {
                debugGroupLoop('saveLoop:error', {
                    message: String(error?.message || error || 'unknown error')
                });
                showAutosaveToast('⚠️ Hiba történt: ' + error, true);
            })
            .finally(() => {
                if (showManualSaveOverlay) {
                    setManualSaveUiState(false);
                }
                finalizeSaveLoopRequest();
            });
        }

        function getActiveTimeBlock() {
            if (activeScope === 'base') {
                return null;
            }
            const blockId = parseInt(String(activeScope).replace('block:', ''), 10);
            return timeBlocks.find((entry) => parseInt(entry.id, 10) === blockId) || null;
        }

        function getDayShortLabel(day) {
            const lang = resolveUiLang();
            const maps = {
                hu: {
                    '1': 'H',
                    '2': 'K',
                    '3': 'Sze',
                    '4': 'Cs',
                    '5': 'P',
                    '6': 'Szo',
                    '7': 'V'
                },
                sk: {
                    '1': 'Po',
                    '2': 'Ut',
                    '3': 'St',
                    '4': 'Št',
                    '5': 'Pi',
                    '6': 'So',
                    '7': 'Ne'
                },
                en: {
                    '1': 'Mon',
                    '2': 'Tue',
                    '3': 'Wed',
                    '4': 'Thu',
                    '5': 'Fri',
                    '6': 'Sat',
                    '7': 'Sun'
                }
            };
            const selectedMap = maps[lang] || maps.en;
            return selectedMap[String(day)] || '?';
        }

        function hasBlockOverlap(candidate, ignoredId = null) {
            const cStart = String(candidate.start_time || '00:00:00');
            const cEnd = String(candidate.end_time || '00:00:00');
            const cType = String(candidate.block_type || 'weekly');
            const cDate = String(candidate.specific_date || '');

            return timeBlocks.some((existing) => {
                if (!existing || (ignoredId !== null && parseInt(existing.id, 10) === parseInt(ignoredId, 10))) {
                    return false;
                }

                if (String(existing.block_type || 'weekly') !== cType) {
                    return false;
                }

                if (cType === 'date') {
                    if (String(existing.specific_date || '') !== cDate) {
                        return false;
                    }
                } else {
                    return weeklyBlocksOverlapByDay(candidate, existing);
                }

                const segCandidate = toTimeSegments(cStart, cEnd);
                const segExisting = toTimeSegments(
                    String(existing.start_time || '00:00:00'),
                    String(existing.end_time || '00:00:00')
                );
                return doSegmentsOverlap(segCandidate, segExisting);
            });
        }

        function createWeeklyBlockFromGrid(day, hour, forcedLoopStyleId = null) {
            createScheduleBlockFromRange(parseInt(day, 10), parseInt(hour, 10), parseInt(hour, 10), forcedLoopStyleId);
        }

        function createSpecialDateBlockFromInputs() {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }
            const dateVal = String(document.getElementById('special-date-input')?.value || '').trim();
            const startVal = String(document.getElementById('special-start-input')?.value || '').trim();
            const endVal = String(document.getElementById('special-end-input')?.value || '').trim();
            const styleId = parseInt(activeLoopStyleId || defaultLoopStyleId || 0, 10);

            if (!dateVal || !startVal || !endVal || !styleId) {
                showAutosaveToast('⚠️ Add meg a dátumot és időt', true);
                return;
            }

            if (parseInt(styleId, 10) === parseInt(defaultLoopStyleId || 0, 10)) {
                showAutosaveToast('⚠️ A DEFAULT loop nem tervezhető', true);
                return;
            }

            const payload = {
                id: nextTempTimeBlockId--,
                block_type: 'date',
                specific_date: dateVal,
                start_time: `${startVal}:00`,
                end_time: `${endVal}:00`,
                block_name: `Speciális ${dateVal}`,
                priority: 300,
                loop_style_id: styleId,
                is_active: 1,
                loops: []
            };

            if (!resolveScheduleConflicts(payload, null)) {
                return;
            }

            timeBlocks.push(payload);
            activeScope = `block:${payload.id}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(120);
            showAutosaveToast('✓ Speciális esemény hozzáadva');
        }

        function openTimeBlockModal(block = null, preset = null) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const host = document.getElementById('time-block-modal-host');
            if (!host) {
                return;
            }

            const editing = !!block;
            const merged = { ...(preset || {}), ...(block || {}) };
            const selectedDays = new Set(String(merged.days_mask || '1,2,3,4,5,6,7').split(',').map(v => String(parseInt(v, 10))).filter(v => /^[1-7]$/.test(v)));
            const blockType = String(merged.block_type || 'weekly') === 'date' ? 'date' : 'weekly';
            const specificDate = merged.specific_date ? String(merged.specific_date).slice(0, 10) : '';
            const priority = Number.isFinite(parseInt(merged.priority, 10)) ? parseInt(merged.priority, 10) : (blockType === 'date' ? 300 : 100);
            const selectedLoopStyleId = parseInt(merged.loop_style_id || activeLoopStyleId || defaultLoopStyleId || 0, 10);
            const loopStyleOptions = loopStyles.map((style) => {
                const selected = parseInt(style.id, 10) === selectedLoopStyleId ? 'selected' : '';
                return `<option value="${style.id}" ${selected}>${String(style.name || 'Loop')}</option>`;
            }).join('');

            host.innerHTML = `
                <div style="position:fixed; inset:0; background:rgba(0,0,0,0.45); display:flex; align-items:center; justify-content:center; z-index:3200;">
                    <div style="background:#fff; width:min(560px,92vw); border:1px solid #cfd6dd; padding:16px;">
                        <h3 style="margin:0 0 12px 0;">${editing ? 'Időblokk szerkesztése' : 'Új időblokk'}</h3>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div>
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Típus</label>
                                <select id="tb-type" style="width:100%;">
                                    <option value="weekly" ${blockType === 'weekly' ? 'selected' : ''}>Heti</option>
                                    <option value="date" ${blockType === 'date' ? 'selected' : ''}>Speciális dátum</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Prioritás</label>
                                <input id="tb-priority" type="number" min="1" max="999" value="${priority}" style="width:100%;">
                            </div>
                            <div style="grid-column:1 / span 2;">
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Loop stílus</label>
                                <select id="tb-loop-style" style="width:100%;">${loopStyleOptions}</select>
                            </div>
                            <div style="grid-column:1 / span 2;">
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Név</label>
                                <input id="tb-name" type="text" value="${(merged.block_name || '').replace(/"/g, '&quot;')}" style="width:100%;">
                            </div>
                            <div id="tb-date-wrap" style="grid-column:1 / span 2; ${blockType === 'date' ? '' : 'display:none;'}">
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Dátum</label>
                                <input id="tb-date" type="date" value="${specificDate}" style="width:100%;">
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Kezdés</label>
                                <input id="tb-start" type="text" style="width:100%;" inputmode="numeric" placeholder="HH:MM" maxlength="5" pattern="^([01]\\d|2[0-3]):[0-5]\\d$">
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Vége</label>
                                <input id="tb-end" type="text" style="width:100%;" inputmode="numeric" placeholder="HH:MM" maxlength="5" pattern="^([01]\\d|2[0-3]):[0-5]\\d$">
                            </div>
                            <div id="tb-days-wrap" style="grid-column:1 / span 2; ${blockType === 'weekly' ? '' : 'display:none;'}">
                                <label style="display:block; font-size:12px; margin-bottom:6px;">Napok</label>
                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    ${[1,2,3,4,5,6,7].map((day) => {
                                        const d = String(day);
                                        const checked = selectedDays.has(d) ? 'checked' : '';
                                        return `<label style=\"display:flex; align-items:center; gap:4px; font-size:12px;\"><input type=\"checkbox\" class=\"tb-day\" value=\"${d}\" ${checked}>${getDayShortLabel(d)}</label>`;
                                    }).join('')}
                                </div>
                            </div>
                        </div>
                        <div style="margin-top:14px; display:flex; justify-content:flex-end; gap:8px;">
                            <button type="button" class="btn" onclick="closeTimeBlockModal()">Mégse</button>
                            <button type="button" class="btn btn-primary" onclick="saveTimeBlockModal(${editing ? 'true' : 'false'}, ${editing ? parseInt(block.id, 10) : 'null'})">Mentés</button>
                        </div>
                    </div>
                </div>
            `;

            const typeEl = document.getElementById('tb-type');
            if (typeEl) {
                typeEl.addEventListener('change', function () {
                    const isDate = this.value === 'date';
                    const dateWrap = document.getElementById('tb-date-wrap');
                    const daysWrap = document.getElementById('tb-days-wrap');
                    if (dateWrap) dateWrap.style.display = isDate ? '' : 'none';
                    if (daysWrap) daysWrap.style.display = isDate ? 'none' : '';
                });
            }

            set24HourTimeSelectValue('tb-start', String(merged.start_time || '08:00:00').slice(0, 5));
            set24HourTimeSelectValue('tb-end', String(merged.end_time || '12:00:00').slice(0, 5));
        }

        function closeTimeBlockModal() {
            const host = document.getElementById('time-block-modal-host');
            if (host) {
                host.innerHTML = '';
            }
        }

        function saveTimeBlockModal(isEdit, editId) {
            const nameInput = document.getElementById('tb-name');
            const typeInput = document.getElementById('tb-type');
            const dateInput = document.getElementById('tb-date');
            const priorityInput = document.getElementById('tb-priority');
            const startInput = document.getElementById('tb-start');
            const endInput = document.getElementById('tb-end');
            const dayCheckboxes = Array.from(document.querySelectorAll('.tb-day:checked'));

            const name = String(nameInput?.value || '').trim();
            const blockType = String(typeInput?.value || 'weekly') === 'date' ? 'date' : 'weekly';
            const loopStyleInput = document.getElementById('tb-loop-style');
            const specificDate = String(dateInput?.value || '').trim();
            const priority = parseInt(priorityInput?.value, 10) || (blockType === 'date' ? 300 : 100);
            const loopStyleId = parseInt(loopStyleInput?.value || activeLoopStyleId || defaultLoopStyleId || 0, 10);
            const start = String(startInput?.value || '').trim();
            const end = String(endInput?.value || '').trim();
            const days = dayCheckboxes.map((el) => String(el.value));

            if (!name) {
                alert('⚠️ Adj meg blokk nevet.');
                return;
            }
            if (!start || !end) {
                alert('⚠️ Adj meg kezdési és zárási időt.');
                return;
            }
            if (blockType === 'weekly' && days.length === 0) {
                alert('⚠️ Jelölj ki legalább egy napot.');
                return;
            }
            if (blockType === 'date' && !specificDate) {
                alert('⚠️ Válassz dátumot.');
                return;
            }
            if (!loopStyleId) {
                alert('⚠️ Válassz loop stílust az idősávhoz.');
                return;
            }
            if (parseInt(loopStyleId, 10) === parseInt(defaultLoopStyleId || 0, 10)) {
                alert('⚠️ A DEFAULT loop nem tervezhető. Az üres sávokat automatikusan kitölti.');
                return;
            }

            const blockId = isEdit ? parseInt(editId, 10) : nextTempTimeBlockId--;
            const payload = {
                id: blockId,
                block_name: name,
                block_type: blockType,
                specific_date: blockType === 'date' ? specificDate : null,
                start_time: `${start}:00`,
                end_time: `${end}:00`,
                days_mask: blockType === 'weekly' ? normalizeDaysMask(days) : '',
                priority: priority,
                loop_style_id: loopStyleId,
                is_active: 1,
                loops: isEdit
                    ? (timeBlocks.find((block) => parseInt(block.id, 10) === parseInt(editId, 10))?.loops || [])
                    : []
            };

            if (!resolveScheduleConflicts(payload, isEdit ? parseInt(editId, 10) : null)) {
                alert('Ütközés miatt a mentés megszakítva.');
                return;
            }

            if (isEdit) {
                timeBlocks = timeBlocks.map((block) => parseInt(block.id, 10) === parseInt(editId, 10) ? payload : block);
                if (activeScope === `block:${editId}`) {
                    activeScope = `block:${payload.id}`;
                }
            } else {
                timeBlocks.push(payload);
                activeScope = `block:${payload.id}`;
            }

            closeTimeBlockModal();
            setActiveScope(activeScope, true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Időblokk mentve');
        }

        function editCurrentTimeBlock() {
            const block = getActiveTimeBlock();
            if (!block) {
                showAutosaveToast('ℹ️ Válassz egy időblokkot szerkesztéshez', true);
                return;
            }
            openTimeBlockModal(block);
        }

        function deleteCurrentTimeBlock() {
            const block = getActiveTimeBlock();
            if (!block) {
                showAutosaveToast('ℹ️ Nincs kiválasztott időblokk', true);
                return;
            }

            if (String(block.block_type || 'weekly') === 'date') {
                showAutosaveToast('⚠️ Speciális dátum blokk itt nem törölhető', true);
                return;
            }

            if (!confirm(`Biztosan törlöd ezt az időblokkot?\n${getScopeLabel(block)}`)) {
                return;
            }

            timeBlocks = timeBlocks.filter((entry) => parseInt(entry.id, 10) !== parseInt(block.id, 10));
            activeScope = 'base';
            setActiveScope('base', true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Időblokk törölve');
        }
        
        // Module customization
        function customizeModule(index) {
            if (isDefaultGroup) {
                return;
            }

            const item = loopItems[index];
            const moduleKey = item.module_key || getModuleKeyById(item.module_id);
            
            // Initialize settings if not exists
            if (!item.settings) {
                item.settings = getDefaultSettings(moduleKey);
            }
            
            showCustomizationModal(item, index);
        }

        function updateTechnicalModuleVisibility() {
            const unconfiguredItem = document.getElementById('unconfiguredModuleItem');
            const noModulesMessage = document.getElementById('noModulesMessage');
            const normalModuleCount = document.querySelectorAll('.modules-panel .module-item:not(#unconfiguredModuleItem)').length;
            const realModulesExist = hasRealModules();

            if (isDefaultGroup) {
                if (unconfiguredItem) {
                    unconfiguredItem.style.display = 'block';
                }
                if (noModulesMessage) {
                    noModulesMessage.style.display = 'block';
                }
                return;
            }

            if (unconfiguredItem) {
                unconfiguredItem.style.display = realModulesExist ? 'none' : 'block';
            }

            if (noModulesMessage) {
                const hasVisibleTechnical = !!unconfiguredItem && !realModulesExist;
                noModulesMessage.style.display = (normalModuleCount === 0 && !hasVisibleTechnical) ? 'block' : 'none';
            }
        }
        
        function getModuleKeyById(moduleId) {
            // Try to find module key from available modules
            const modules = modulesCatalog;
            const module = modules.find(m => m.id == moduleId);
            return module ? module.module_key : null;
        }

        function getModuleCatalogEntryByKey(moduleKey) {
            const key = String(moduleKey || '').toLowerCase();
            if (!key) {
                return null;
            }

            return modulesCatalog.find((entry) => String(entry?.module_key || '').toLowerCase() === key) || null;
        }

        function hasClockModuleAvailable() {
            return !!getModuleCatalogEntryByKey('clock');
        }

        function isOverlayCarrierModule(moduleKey) {
            return moduleKey === 'image-gallery' || moduleKey === 'gallery' || moduleKey === 'meal-menu';
        }

        function getClockOverlayDefaults() {
            return {
                clockOverlayEnabled: false,
                clockOverlayPosition: 'top',
                clockOverlayHeightPercent: 40,
                clockOverlayTimeColor: '#ffffff',
                clockOverlayDateColor: '#ffffff'
            };
        }

        function getTextOverlayDefaults() {
            return {
                textOverlayEnabled: false,
                textOverlayPosition: 'bottom',
                textOverlayHeightPercent: 20,
                textOverlaySourceType: 'manual',
                textOverlayText: textUiText('insert_text_here'),
                textOverlayCollectionJson: '[]',
                textOverlayExternalUrl: '',
                textOverlayFontSize: 52,
                textOverlayColor: '#ffffff',
                textOverlaySpeedPxPerSec: 120
            };
        }

        function ensureOverlayDefaults(settings) {
            return {
                ...getClockOverlayDefaults(),
                ...getTextOverlayDefaults(),
                ...(settings || {})
            };
        }

        function applyOverlayPresetToSettings(settings, overlayType) {
            const merged = ensureOverlayDefaults(settings);
            if (overlayType === 'clock') {
                merged.clockOverlayEnabled = true;
            }
            if (overlayType === 'text') {
                merged.textOverlayEnabled = true;
            }
            return merged;
        }

        function appendOverlayParams(params, settings, moduleKey) {
            if (!isOverlayCarrierModule(moduleKey)) {
                return;
            }

            const merged = ensureOverlayDefaults(settings);
            if (merged.clockOverlayEnabled) {
                params.append('clockOverlayEnabled', 'true');
                params.append('clockOverlayPosition', merged.clockOverlayPosition || 'top');
                params.append('clockOverlayHeightPercent', String(merged.clockOverlayHeightPercent || 40));
                params.append('clockOverlayTimeColor', merged.clockOverlayTimeColor || '#ffffff');
                params.append('clockOverlayDateColor', merged.clockOverlayDateColor || '#ffffff');
            }
            if (merged.textOverlayEnabled) {
                params.append('textOverlayEnabled', 'true');
                params.append('textOverlayPosition', merged.textOverlayPosition || 'bottom');
                params.append('textOverlayHeightPercent', String(merged.textOverlayHeightPercent || 20));
                params.append('textOverlaySourceType', merged.textOverlaySourceType || 'manual');
                params.append('textOverlayText', merged.textOverlayText || '');
                params.append('textOverlayCollectionJson', merged.textOverlayCollectionJson || '[]');
                params.append('textOverlayExternalUrl', merged.textOverlayExternalUrl || '');
                params.append('textOverlayFontSize', String(merged.textOverlayFontSize || 52));
                params.append('textOverlayColor', merged.textOverlayColor || '#ffffff');
                params.append('textOverlaySpeedPxPerSec', String(merged.textOverlaySpeedPxPerSec || 120));
            }
        }

        function collectOverlaySettingsFromForm(baseSettings) {
            const merged = ensureOverlayDefaults(baseSettings);
            merged.clockOverlayEnabled = document.getElementById('setting-clockOverlayEnabled')?.checked === true;
            merged.clockOverlayPosition = document.getElementById('setting-clockOverlayPosition')?.value || 'top';
            merged.clockOverlayHeightPercent = Math.max(20, Math.min(40, parseInt(document.getElementById('setting-clockOverlayHeightPercent')?.value || '40', 10) || 40));
            merged.clockOverlayTimeColor = document.getElementById('setting-clockOverlayTimeColor')?.value || '#ffffff';
            merged.clockOverlayDateColor = document.getElementById('setting-clockOverlayDateColor')?.value || '#ffffff';

            merged.textOverlayEnabled = document.getElementById('setting-textOverlayEnabled')?.checked === true;
            merged.textOverlayPosition = document.getElementById('setting-textOverlayPosition')?.value || 'bottom';
            merged.textOverlayHeightPercent = Math.max(12, Math.min(40, parseInt(document.getElementById('setting-textOverlayHeightPercent')?.value || '20', 10) || 20));
            const sourceType = String(document.getElementById('setting-textOverlaySourceType')?.value || 'manual');
            merged.textOverlaySourceType = ['manual', 'collection', 'external'].includes(sourceType) ? sourceType : 'manual';
            merged.textOverlayText = document.getElementById('setting-textOverlayText')?.value || textUiText('insert_text_here');
            const collectionRaw = String(document.getElementById('setting-textOverlayCollection')?.value || '');
            const collectionLines = collectionRaw
                .split(/\r?\n/)
                .map((line) => String(line || '').trim())
                .filter(Boolean)
                .slice(0, 400);
            merged.textOverlayCollectionJson = JSON.stringify(collectionLines);
            merged.textOverlayExternalUrl = String(document.getElementById('setting-textOverlayExternalUrl')?.value || '').trim();
            merged.textOverlayFontSize = Math.max(18, Math.min(120, parseInt(document.getElementById('setting-textOverlayFontSize')?.value || '52', 10) || 52));
            merged.textOverlayColor = document.getElementById('setting-textOverlayColor')?.value || '#ffffff';
            merged.textOverlaySpeedPxPerSec = Math.max(40, Math.min(320, parseInt(document.getElementById('setting-textOverlaySpeedPxPerSec')?.value || '120', 10) || 120));
            return merged;
        }
        
        function getDefaultSettings(moduleKey) {
            const defaults = {
                'clock': {
                    type: 'digital',
                    format: '24h',
                    dateFormat: 'dmy',
                    dateInline: false,
                    weekdayPosition: 'left',
                    timeColor: '#ffffff',
                    dateColor: '#ffffff',
                    bgColor: '#000000',
                    fontSize: 120,
                    timeFontSize: 120,
                    dateFontSize: 36,
                    clockSize: 300,
                    showSeconds: true,
                    showDate: true,
                    language: 'sk',
                    digitalOverlayEnabled: false,
                    digitalOverlayPosition: 'auto'
                },
                'default-logo': {
                    text: 'edudisplej.sk',
                    fontSize: 120,
                    textColor: '#ffffff',
                    bgColor: '#000000',
                    showVersion: false,
                    version: ''
                },
                'text': {
                    text: textUiText('insert_text_here'),
                    fontFamily: 'Arial, sans-serif',
                    fontSize: 72,
                    textSizingMode: 'manual',
                    fontWeight: '700',
                    fontStyle: 'normal',
                    lineHeight: 1.2,
                    textAlign: 'left',
                    textColor: '#ffffff',
                    bgColor: '#000000',
                    bgImageData: '',
                    scrollMode: false,
                    scrollStartPauseMs: 3000,
                    scrollEndPauseMs: 3000,
                    scrollSpeedPxPerSec: 35,
                    clockOverlayEnabled: false,
                    clockOverlayPosition: 'bottom',
                    clockOverlayHeightPercent: 30,
                    clockOverlayTimeColor: '#ffffff',
                    clockOverlayDateColor: '#ffffff',
                    clockOverlayClockSize: 300,
                    clockOverlayDateFormat: 'dmy',
                    clockOverlayShowYear: true,
                    clockOverlayLanguage: 'sk',
                    clockOverlayDatePosition: 'below',
                    clockOverlayFontFamily: 'Arial, sans-serif',
                    clockOverlaySeparatorColor: '#22d3ee',
                    clockOverlaySeparatorThickness: 2
                },
                'video': {
                    videoAssetUrl: '',
                    videoAssetId: '',
                    videoDurationSec: 10,
                    muted: true,
                    fitMode: 'contain',
                    bgColor: '#000000'
                },
                'turned-off': {
                    screenOffMode: 'signal_off'
                },
                'image-gallery': {
                    imageUrlsJson: '[]',
                    displayMode: 'slideshow',
                    fitMode: 'contain',
                    slideIntervalSec: 5,
                    transitionEnabled: true,
                    transitionMs: 450,
                    collageColumns: 3,
                    bgColor: '#000000',
                    ...getClockOverlayDefaults(),
                    ...getTextOverlayDefaults()
                },
                'meal-menu': {
                    companyId,
                    siteKey: 'jedalen.sk',
                    institutionId: 0,
                    sourceType: 'server',
                    mealDisplayMode: 'small_screen',
                    smallScreenPageSwitchSec: 12,
                    smallScreenStartMode: 'current_onward',
                    smallScreenMaxMeals: 5,
                    smallHeaderMarqueeSpeedPxPerSec: 22,
                    smallHeaderMarqueeEdgePauseMs: 1200,
                    smallRowFontPx: 150,
                    smallScreenHeaderFontPx: 40,
                    smallHeaderRowBgColor: '#bae6fd',
                    smallHeaderRowTextColor: '#000000',
                    smallHeaderRowClockColor: '#facc15',
                    smallHeaderRowTitleFontPx: 58,
                    smallHeaderRowClockFontPx: 62,
                    mealScheduleEnabled: true,
                    scheduleBreakfastUntil: '10:00',
                    scheduleSnackAmUntil: '11:00',
                    scheduleLunchUntil: '14:00',
                    scheduleSnackPmUntil: '18:00',
                    scheduleDinnerUntil: '23:59',
                    showTomorrowAfterMealPassed: true,
                    language: 'sk',
                    showHeaderTitle: true,
                    customHeaderTitle: '',
                    showInstitutionName: true,
                    showBreakfast: true,
                    showSnackAm: true,
                    showLunch: true,
                    showSnackPm: true,
                    showDinner: true,
                    showMealTypeEmojis: false,
                    showMealTypeSvgIcons: true,
                    centerAlign: false,
                    slowScrollOnOverflow: true,
                    slowScrollSpeedPxPerSec: 40,
                    showAppetiteMessage: false,
                    appetiteMessageText: 'Prajeme dobrú chuť!',
                    showSourceUrl: false,
                    sourceUrl: '',
                    fontFamily: 'Segoe UI, Tahoma, sans-serif',
                    mealTitleFontSize: 3.6,
                    mealTextFontSize: 2.4,
                    textFontWeight: 600,
                    lineHeight: 1.4,
                    wrapText: true,
                    apiBaseUrl: '../../api/meal_plan.php',
                    runtimeApiFetchEnabled: true,
                    runtimeRefreshIntervalSec: 300,
                    ...getClockOverlayDefaults(),
                    ...getTextOverlayDefaults()
                },
                'room-occupancy': {
                    roomId: 0,
                    showOnlyCurrent: false,
                    showNextCount: 4,
                    language: resolveUiLang(),
                    runtimeRefreshIntervalSec: 60,
                    apiBaseUrl: '../../api/room_occupancy.php'
                }
            };
            
            if (moduleKey === 'gallery') {
                return defaults['image-gallery'];
            }
            return defaults[moduleKey] || {};
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function normalizeMealDisplayMode(value) {
            return String(value || 'small_screen').toLowerCase() === 'large_screen'
                ? 'large_screen'
                : 'small_screen';
        }

        function isMealLargeScreenMode(value) {
            return normalizeMealDisplayMode(value) === 'large_screen';
        }

        function normalizeMealLanguage(value) {
            const normalized = String(value || 'sk').toLowerCase().trim();
            return ['hu', 'sk', 'en'].includes(normalized) ? normalized : 'sk';
        }

        function normalizeMealScheduleTime(value, fallback = '10:00') {
            const source = String(value || '').trim();
            const match = source.match(/^(\d{1,2}):(\d{2})$/);
            if (!match) {
                return fallback;
            }

            const hh = parseInt(match[1], 10);
            const mm = parseInt(match[2], 10);
            if (!Number.isFinite(hh) || !Number.isFinite(mm) || hh < 0 || hh > 23 || mm < 0 || mm > 59) {
                return fallback;
            }

            return `${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}`;
        }

        function setElementDisplay(element, visible, visibleDisplay = 'block') {
            if (!element) {
                return;
            }
            element.style.display = visible ? visibleDisplay : 'none';
        }

        function sanitizeRichTextHtml(value) {
            const raw = String(value || '').trim();
            if (!raw) {
                return '';
            }

            const parser = new DOMParser();
            const doc = parser.parseFromString(`<div>${raw}</div>`, 'text/html');
            const root = doc.body.firstElementChild;
            if (!root) {
                return '';
            }

            const allowedTags = new Set([
                'p', 'div', 'span', 'br', 'strong', 'b', 'em', 'i', 'u',
                'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote'
            ]);
            const allowedStyles = new Set([
                'color', 'font-weight', 'font-style', 'text-decoration', 'text-decoration-line',
                'text-align', 'font-family', 'font-size', 'line-height', 'background-color'
            ]);

            const sanitizeNode = (node) => {
                const children = Array.from(node.childNodes);
                children.forEach((child) => {
                    if (child.nodeType === Node.ELEMENT_NODE) {
                        const tag = child.tagName.toLowerCase();
                        if (!allowedTags.has(tag)) {
                            const text = doc.createTextNode(child.textContent || '');
                            child.replaceWith(text);
                            return;
                        }

                        Array.from(child.attributes).forEach((attr) => {
                            const attrName = attr.name.toLowerCase();
                            if (attrName.startsWith('on')) {
                                child.removeAttribute(attr.name);
                                return;
                            }

                            if (attrName === 'style') {
                                const safeRules = [];
                                String(attr.value || '').split(';').forEach((rule) => {
                                    const parts = rule.split(':');
                                    if (parts.length < 2) {
                                        return;
                                    }
                                    const prop = parts[0].trim().toLowerCase();
                                    const val = parts.slice(1).join(':').trim();
                                    if (!allowedStyles.has(prop)) {
                                        return;
                                    }
                                    if (/url\s*\(|expression\s*\(|javascript:/i.test(val)) {
                                        return;
                                    }
                                    safeRules.push(`${prop}: ${val}`);
                                });

                                if (safeRules.length > 0) {
                                    child.setAttribute('style', safeRules.join('; '));
                                } else {
                                    child.removeAttribute('style');
                                }
                                return;
                            }

                            child.removeAttribute(attr.name);
                        });

                        sanitizeNode(child);
                    } else if (child.nodeType === Node.COMMENT_NODE) {
                        child.remove();
                    }
                });
            };

            sanitizeNode(root);
            return root.innerHTML;
        }

        function applyInlineStyleToSelection(property, value) {
            const editor = document.getElementById('text-editor-area');
            if (!editor) {
                return;
            }

            const selection = window.getSelection();
            if (!selection || selection.rangeCount === 0) {
                return;
            }

            const range = selection.getRangeAt(0);
            if (!editor.contains(range.commonAncestorContainer)) {
                return;
            }

            if (range.collapsed) {
                let node = range.startContainer;
                if (node && node.nodeType === Node.TEXT_NODE) {
                    node = node.parentElement;
                }
                const target = node && node.closest ? node.closest('span,p,div,li,h1,h2,h3,h4,h5,h6,blockquote') : null;
                if (target && editor.contains(target)) {
                    target.style.setProperty(property, value);
                }
                return;
            }

            const wrapper = document.createElement('span');
            wrapper.style.setProperty(property, value);

            try {
                range.surroundContents(wrapper);
            } catch (error) {
                const fragment = range.extractContents();
                wrapper.appendChild(fragment);
                range.insertNode(wrapper);
            }
        }

        function applyLineHeightToCurrentBlock(lineHeightValue) {
            const editor = document.getElementById('text-editor-area');
            if (!editor) {
                return;
            }

            const selection = window.getSelection();
            if (!selection || selection.rangeCount === 0) {
                return;
            }

            let node = selection.anchorNode;
            if (!node) {
                return;
            }

            if (node.nodeType === Node.TEXT_NODE) {
                node = node.parentElement;
            }

            const block = node && node.closest ? node.closest('p,div,li,h1,h2,h3,h4,h5,h6,blockquote') : null;
            if (!block || !editor.contains(block)) {
                return;
            }

            block.style.lineHeight = String(lineHeightValue);
        }

        function readImageAsCompressedDataUrl(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => {
                    const image = new Image();
                    image.onload = () => {
                        const maxWidth = 1600;
                        const maxHeight = 900;
                        let width = image.width;
                        let height = image.height;

                        if (width > maxWidth || height > maxHeight) {
                            const ratio = Math.min(maxWidth / width, maxHeight / height);
                            width = Math.max(1, Math.round(width * ratio));
                            height = Math.max(1, Math.round(height * ratio));
                        }

                        const canvas = document.createElement('canvas');
                        canvas.width = width;
                        canvas.height = height;
                        const context = canvas.getContext('2d');
                        if (!context) {
                            resolve(String(reader.result || ''));
                            return;
                        }

                        context.drawImage(image, 0, 0, width, height);
                        resolve(canvas.toDataURL('image/jpeg', 0.82));
                    };
                    image.onerror = () => reject(new Error('A kép nem olvasható'));
                    image.src = String(reader.result || '');
                };
                reader.onerror = () => reject(new Error('A fájl nem olvasható'));
                reader.readAsDataURL(file);
            });
        }

        async function loadTextCollectionsDetailed(forceReload = false) {
            const now = Date.now();
            if (!forceReload && Array.isArray(textCollectionsCacheDetailed) && textCollectionsCacheDetailed.length > 0 && (now - textCollectionsCacheLoadedAt) < 20000) {
                return textCollectionsCacheDetailed;
            }

            const response = await fetch('../../api/text_collections.php?action=list&include_content=1', {
                credentials: 'same-origin'
            });
            const payload = await response.json();
            if (!response.ok || !payload?.success) {
                throw new Error(payload?.message || 'Nem sikerült betölteni a slide gyűjteményt');
            }

            textCollectionsCacheDetailed = Array.isArray(payload.items) ? payload.items : [];
            textCollectionsCacheLoadedAt = now;
            return textCollectionsCacheDetailed;
        }

        function getTextCollectionById(collectionId) {
            const wantedId = parseInt(collectionId, 10) || 0;
            if (wantedId <= 0 || !Array.isArray(textCollectionsCacheDetailed)) {
                return null;
            }
            return textCollectionsCacheDetailed.find((entry) => (parseInt(entry?.id, 10) || 0) === wantedId) || null;
        }

        async function loadMealSitesForModal() {
            const query = new URLSearchParams({ action: 'sites' });
            if (companyId > 0) {
                query.append('company_id', String(companyId));
            }

            const response = await fetch(`../../api/meal_plan.php?${query.toString()}`, {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const payload = await response.json();
            if (!response.ok || !payload?.success) {
                throw new Error(payload?.message || mealUiText('site_error'));
            }
            return Array.isArray(payload.items) ? payload.items : [];
        }

        async function loadMealInstitutionsForModal(siteKey) {
            const cleanedSiteKey = String(siteKey || '').trim();
            if (!cleanedSiteKey) {
                return [];
            }

            const query = new URLSearchParams({
                action: 'institutions',
                site_key: cleanedSiteKey
            });
            if (companyId > 0) {
                query.append('company_id', String(companyId));
            }

            const response = await fetch(`../../api/meal_plan.php?${query.toString()}`, {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const payload = await response.json();
            if (!response.ok || !payload?.success) {
                throw new Error(payload?.message || mealUiText('institution_error'));
            }

            const institutions = Array.isArray(payload.items) ? payload.items : [];
            const seenByIdentity = new Set();
            const deduped = [];

            institutions.forEach((institution) => {
                const id = parseInt(institution?.id || 0, 10) || 0;
                const name = String(institution?.institution_name || '').trim().toLowerCase();
                const city = String(institution?.city || '').trim().toLowerCase();
                const byLabel = `${name}|${city}`;
                const identity = byLabel !== '|' ? `label:${byLabel}` : (id > 0 ? `id:${id}` : '');

                if (!identity || seenByIdentity.has(identity)) {
                    return;
                }

                seenByIdentity.add(identity);
                deduped.push(institution);
            });

            return deduped;
        }

        function bindMealModuleModalEvents(initialSettings, loopItem) {
            const mt = (key, vars = null) => {
                let text = mealUiText(key);
                if (vars && typeof vars === 'object') {
                    Object.entries(vars).forEach(([name, value]) => {
                        text = text.replace(new RegExp(`\\{${name}\\}`, 'g'), String(value ?? ''));
                    });
                }
                return text;
            };
            const siteSelect = document.getElementById('setting-mealSiteKey');
            const institutionSelect = document.getElementById('setting-mealInstitutionId');
            const siteRefreshBtn = document.getElementById('setting-mealReloadSites');
            const institutionRefreshBtn = document.getElementById('setting-mealReloadInstitutions');
            const statusEl = document.getElementById('setting-mealStatus');

            if (!siteSelect || !institutionSelect) {
                return;
            }

            const displayModeSelect = document.getElementById('setting-mealDisplayMode');
            const mealOverlayWrap = document.getElementById('meal-overlay-wrap');
            const mealSmallSettings = document.getElementById('meal-small-settings');
            const mealLargeHeaderSettings = document.getElementById('meal-large-header-settings');
            const mealLargeMessageSettings = document.getElementById('meal-large-message-settings');
            const mealLargeSourceSettings = document.getElementById('meal-large-source-settings');

            const syncMealDisplaySpecificFields = () => {
                const isLarge = isMealLargeScreenMode(displayModeSelect?.value);
                setElementDisplay(mealSmallSettings, !isLarge, 'grid');
                setElementDisplay(mealLargeHeaderSettings, isLarge, 'grid');
                setElementDisplay(mealLargeMessageSettings, isLarge, 'grid');
                setElementDisplay(mealLargeSourceSettings, isLarge, 'grid');
            };

            const syncMealOverlayVisibility = () => {
                setElementDisplay(mealOverlayWrap, isMealLargeScreenMode(displayModeSelect?.value), 'grid');
            };

            if (displayModeSelect) {
                displayModeSelect.addEventListener('change', syncMealOverlayVisibility);
                displayModeSelect.addEventListener('change', syncMealDisplaySpecificFields);
            }
            syncMealOverlayVisibility();
            syncMealDisplaySpecificFields();

            const mealTextSizeInput = document.getElementById('setting-mealTextFontSize');
            const mealTitleSizeInput = document.getElementById('setting-mealTitleFontSize');
            const syncMealTitleFromText = () => {
                if (!mealTextSizeInput || !mealTitleSizeInput) {
                    return;
                }
                const textValue = Math.max(0.8, Math.min(4, parseFloat(mealTextSizeInput.value || '1.85') || 1.85));
                mealTitleSizeInput.value = (textValue * 1.5).toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
            };
            if (mealTextSizeInput && mealTitleSizeInput) {
                mealTextSizeInput.addEventListener('input', syncMealTitleFromText);
                mealTextSizeInput.addEventListener('change', syncMealTitleFromText);
                syncMealTitleFromText();
            }

            const selectedSite = String(initialSettings.siteKey || 'jedalen.sk');
            const selectedInstitutionId = parseInt(initialSettings.institutionId || 0, 10) || 0;

            const setStatus = (message, isError = false) => {
                if (!statusEl) {
                    return;
                }
                statusEl.textContent = message;
                statusEl.style.color = isError ? '#b91c1c' : '#475569';
            };

            const renderSiteOptions = (sites) => {
                const options = [`<option value="">${escapeHtml(mt('option_site'))}</option>`];
                sites.forEach((site) => {
                    const key = String(site?.site_key || '').trim();
                    if (!key) {
                        return;
                    }
                    const label = String(site?.site_name || key).trim();
                    options.push(`<option value="${escapeHtml(key)}">${escapeHtml(label)}</option>`);
                });
                siteSelect.innerHTML = options.join('');
            };

            const renderInstitutionOptions = (institutions, preferredInstitutionId = 0) => {
                const options = [`<option value="0">${escapeHtml(mt('option_institution'))}</option>`];
                const renderedKeys = new Set();
                institutions.forEach((institution) => {
                    const id = parseInt(institution?.id || 0, 10) || 0;
                    if (id <= 0) {
                        return;
                    }
                    const name = String(institution?.institution_name || '').trim();
                    const city = String(institution?.city || '').trim();
                    const label = city ? `${name} (${city})` : name;
                    const renderKey = `${id}|${label.toLowerCase()}`;
                    if (renderedKeys.has(renderKey)) {
                        return;
                    }
                    renderedKeys.add(renderKey);
                    options.push(`<option value="${id}">${escapeHtml(label)}</option>`);
                });
                institutionSelect.innerHTML = options.join('');

                const wanted = parseInt(preferredInstitutionId || 0, 10) || 0;
                institutionSelect.value = wanted > 0 ? String(wanted) : '0';
                if (wanted > 0 && institutionSelect.value !== String(wanted)) {
                    institutionSelect.value = '0';
                }
            };

            const dedupeInstitutions = (institutions) => {
                const list = Array.isArray(institutions) ? institutions : [];
                const uniqueByIdentity = new Map();

                list.forEach((institution) => {
                    const id = parseInt(institution?.id || 0, 10) || 0;
                    const name = String(institution?.institution_name || '').trim().toLowerCase();
                    const city = String(institution?.city || '').trim().toLowerCase();
                    const byLabel = `${name}|${city}`;
                    const key = byLabel !== '|' ? `label:${byLabel}` : (id > 0 ? `id:${id}` : '');

                    if (!key || uniqueByIdentity.has(key)) {
                        return;
                    }
                    uniqueByIdentity.set(key, institution);
                });

                return Array.from(uniqueByIdentity.values());
            };

            const refreshInstitutions = async (preferredInstitutionId = 0) => {
                const siteKey = String(siteSelect.value || '').trim();
                if (!siteKey) {
                    renderInstitutionOptions([], 0);
                    setStatus(mt('choose_site_first'));
                    return;
                }

                setStatus(mt('loading_institutions'));
                try {
                    const institutions = dedupeInstitutions(await loadMealInstitutionsForModal(siteKey));
                    renderInstitutionOptions(institutions, preferredInstitutionId);
                    setStatus(mt('institutions_count', { count: institutions.length }));
                } catch (error) {
                    renderInstitutionOptions([], 0);
                    setStatus(error.message || mt('institution_error'), true);
                }
            };

            const refreshSites = async () => {
                setStatus(mt('loading_sites'));
                try {
                    const sites = await loadMealSitesForModal();
                    renderSiteOptions(sites);

                    const availableSiteKeys = new Set(sites.map((site) => String(site?.site_key || '').trim()).filter(Boolean));
                    const effectiveSite = availableSiteKeys.has(selectedSite)
                        ? selectedSite
                        : (sites[0]?.site_key ? String(sites[0].site_key) : '');

                    siteSelect.value = effectiveSite;
                    if (effectiveSite) {
                        await refreshInstitutions(selectedInstitutionId);
                    } else {
                        renderInstitutionOptions([], 0);
                        setStatus(mt('no_sites'), true);
                    }
                } catch (error) {
                    setStatus(error.message || mt('site_error'), true);
                }
            };

            siteSelect.addEventListener('change', () => {
                refreshInstitutions(0);
            });

            if (siteRefreshBtn) {
                siteRefreshBtn.addEventListener('click', () => {
                    refreshSites();
                });
            }

            if (institutionRefreshBtn) {
                institutionRefreshBtn.addEventListener('click', () => {
                    refreshInstitutions(parseInt(institutionSelect.value || '0', 10) || 0);
                });
            }

            // Add live preview update listeners for all meal settings
            const mealSettingInputIds = [
                'setting-smallScreenPageSwitchSec',
                'setting-smallScreenStartMode',
                'setting-smallScreenMaxMeals',
                'setting-smallHeaderMarqueeSpeedPxPerSec',
                'setting-smallHeaderMarqueeEdgePauseMs',
                'setting-smallRowFontPx',
                'setting-smallScreenHeaderFontPx',
                'setting-smallHeaderRowBgColor',
                'setting-smallHeaderRowTextColor',
                'setting-smallHeaderRowClockColor',
                'setting-smallHeaderRowTitleFontPx',
                'setting-smallHeaderRowClockFontPx',
                'setting-smallScreenShowOperator',
                'setting-smallScreenShowDate',
                'setting-smallScreenShowCaptions',
                'setting-mergeBreakfastSnack',
                'setting-mergeLunchSnack',
                'setting-mealScheduleEnabled',
                'setting-scheduleBreakfastUntil',
                'setting-scheduleSnackAmUntil',
                'setting-scheduleLunchUntil',
                'setting-scheduleSnackPmUntil',
                'setting-scheduleDinnerUntil',
                'setting-showTomorrowAfterMealPassed',
                'setting-mealLanguage',
                'setting-showHeaderTitle',
                'setting-customHeaderTitle',
                'setting-showInstitutionName',
                'setting-showBreakfast',
                'setting-showSnackAm',
                'setting-showLunch',
                'setting-showSnackPm',
                'setting-showDinner',
                'setting-showMealTypeSvgIcons',
                'setting-showAppetiteMessage',
                'setting-appetiteMessageText',
                'setting-showSourceUrl',
                'setting-sourceUrl',
                'setting-mealSourceType'
            ];

            let mealPreviewUpdateTimeout = null;
            const triggerMealPreviewUpdate = () => {
                clearTimeout(mealPreviewUpdateTimeout);
                mealPreviewUpdateTimeout = setTimeout(() => {
                    // Update loopItem settings with current form values
                    if (loopItem) {
                        const mealSettings = collectMealMenuSettingsFromForm();
                        loopItem.settings = mealSettings;
                        // Trigger preview update
                        renderLoop();
                    }
                }, 300);
            };

            mealSettingInputIds.forEach((id) => {
                const el = document.getElementById(id);
                if (el) {
                    el.addEventListener('input', triggerMealPreviewUpdate);
                    el.addEventListener('change', triggerMealPreviewUpdate);
                }
            });

            ['setting-smallScreenMaxMeals', 'setting-smallHeaderMarqueeSpeedPxPerSec', 'setting-smallHeaderMarqueeEdgePauseMs'].forEach((id) => {
                const slider = document.getElementById(id);
                if (!slider) {
                    return;
                }
                const valueEl = slider.nextElementSibling;
                const refreshSliderLabel = () => {
                    if (valueEl) {
                        valueEl.textContent = String(slider.value || '');
                    }
                };
                refreshSliderLabel();
                slider.addEventListener('input', refreshSliderLabel);
                slider.addEventListener('change', refreshSliderLabel);
            });

            refreshSites();
        }

        async function loadRoomOccupancyRoomsForModal() {
            const query = new URLSearchParams({ action: 'rooms' });
            if (companyId > 0) {
                query.append('company_id', String(companyId));
            }

            const response = await fetch(`../../api/room_occupancy.php?${query.toString()}`, {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const payload = await response.json();
            if (!response.ok || !payload?.success) {
                throw new Error(payload?.message || roomOccUiText('room_load_error'));
            }
            return Array.isArray(payload.items) ? payload.items : [];
        }

        function bindRoomOccupancyModuleModalEvents(initialSettings) {
            const roomSelect = document.getElementById('setting-roomOccRoomId');
            const roomRefreshBtn = document.getElementById('setting-roomOccReloadRooms');
            const statusEl = document.getElementById('setting-roomOccStatus');
            if (!roomSelect) {
                return;
            }

            const selectedRoomId = parseInt(initialSettings.roomId || 0, 10) || 0;

            const setStatus = (message, isError = false) => {
                if (!statusEl) {
                    return;
                }
                statusEl.textContent = message;
                statusEl.style.color = isError ? '#b91c1c' : '#475569';
            };

            const renderRoomOptions = (rooms) => {
                const options = [`<option value="0">${escapeHtml(roomOccUiText('choose_room'))}</option>`];
                rooms.forEach((room) => {
                    const id = parseInt(room?.id || 0, 10) || 0;
                    if (id <= 0) {
                        return;
                    }
                    const roomName = String(room?.room_name || '').trim();
                    const roomKey = String(room?.room_key || '').trim();
                    const label = roomKey ? `${roomName} (${roomKey})` : roomName;
                    options.push(`<option value="${id}">${escapeHtml(label)}</option>`);
                });
                roomSelect.innerHTML = options.join('');

                roomSelect.value = selectedRoomId > 0 ? String(selectedRoomId) : '0';
                if (selectedRoomId > 0 && roomSelect.value !== String(selectedRoomId)) {
                    roomSelect.value = '0';
                }
            };

            const refreshRooms = async () => {
                setStatus(roomOccUiText('loading_rooms'));
                try {
                    const rooms = await loadRoomOccupancyRoomsForModal();
                    renderRoomOptions(rooms);
                    setStatus(tr('group_loop.room_occ.rooms_count', roomOccUiText('rooms_count'), { count: rooms.length }));
                } catch (error) {
                    setStatus(error.message || roomOccUiText('room_load_error'), true);
                }
            };

            if (roomRefreshBtn) {
                roomRefreshBtn.addEventListener('click', () => {
                    refreshRooms();
                });
            }

            refreshRooms();
        }

        function applyTextCollectionToTextModuleForm(collection) {
            if (!collection) {
                return;
            }

            const editor = document.getElementById('text-editor-area');
            const hiddenHtml = document.getElementById('setting-text');
            const bgColorInput = document.getElementById('setting-bgColor');
            const bgImageDataInput = document.getElementById('setting-bgImageData');
            const bgStatus = document.getElementById('setting-bgImageStatus');

            const safeHtml = sanitizeRichTextHtml(collection.content_html || '');
            if (editor) {
                editor.innerHTML = safeHtml;
            }
            if (hiddenHtml) {
                hiddenHtml.value = safeHtml;
            }
            if (bgColorInput) {
                bgColorInput.value = collection.bg_color || '#000000';
            }
            if (bgImageDataInput) {
                bgImageDataInput.value = String(collection.bg_image_data || '');
            }
            if (bgStatus) {
                bgStatus.textContent = String(collection.bg_image_data || '').trim() ? 'Háttérkép gyűjteményből' : 'Nincs kiválasztott kép';
            }
        }

        function updateTextModuleMiniPreview() {
            const previewFrame = document.getElementById('text-preview-frame');
            const previewIframe = document.getElementById('text-live-preview-iframe');
            if (!previewFrame || !previewIframe) {
                return;
            }

            const editor = document.getElementById('text-editor-area');
            const hiddenHtml = document.getElementById('setting-text');
            const sourceType = String(document.getElementById('setting-textSourceType')?.value || 'manual');
            const selectedCollectionId = parseInt(document.getElementById('setting-textCollectionId')?.value || '0', 10) || 0;
            const textExternalUrl = String(document.getElementById('setting-textExternalUrl')?.value || '').trim();
            const sanitizedHtml = sanitizeRichTextHtml(editor ? editor.innerHTML : (hiddenHtml?.value || ''));
            if (hiddenHtml) {
                hiddenHtml.value = sanitizedHtml;
            }

            let previewTextHtml = sanitizedHtml;
            let bgColor = document.getElementById('setting-bgColor')?.value || '#000000';
            let bgImageData = document.getElementById('setting-bgImageData')?.value || '';

            if (sourceType === 'collection') {
                const selectedCollection = getTextCollectionById(selectedCollectionId);
                if (selectedCollection) {
                    previewTextHtml = sanitizeRichTextHtml(selectedCollection.content_html || '');
                    bgColor = selectedCollection.bg_color || bgColor;
                    bgImageData = selectedCollection.bg_image_data || '';
                    if (hiddenHtml) {
                        hiddenHtml.value = previewTextHtml;
                    }
                }
            }

            const fontFamily = document.getElementById('setting-richFontFamily')?.value || 'Arial, sans-serif';
            const fontSize = Math.max(8, parseInt(document.getElementById('setting-richFontSize')?.value || '72', 10) || 72);
            const lineHeight = Math.max(0.8, Math.min(2.5, parseFloat(document.getElementById('setting-richLineHeight')?.value || '1.2') || 1.2));
            const resolution = document.getElementById('setting-previewResolution')?.value || groupDefaultResolution || '1920x1080';
            const [deviceWidthRaw, deviceHeightRaw] = String(resolution).split('x').map((v) => parseInt(v, 10));
            const deviceWidth = Math.max(320, deviceWidthRaw || 1920);
            const deviceHeight = Math.max(180, deviceHeightRaw || 1080);

            const availableWidth = Math.max(280, previewFrame.clientWidth - 8);
            const availableHeight = Math.max(180, previewFrame.clientHeight - 8);
            const scale = Math.max(0.1, Math.min(availableWidth / deviceWidth, availableHeight / deviceHeight));
            previewIframe.style.width = `${deviceWidth}px`;
            previewIframe.style.height = `${deviceHeight}px`;
            previewIframe.style.transform = `translate(-50%, -50%) scale(${scale})`;
            previewIframe.style.transformOrigin = 'center center';
            previewIframe.style.position = 'absolute';
            previewIframe.style.left = '50%';
            previewIframe.style.top = '50%';

            const previewModule = {
                module_key: 'text',
                duration_seconds: parseInt(document.getElementById('setting-previewDurationSec')?.value || '10', 10) || 10,
                settings: {
                    textSourceType: sourceType,
                    textCollectionId: sourceType === 'collection' ? selectedCollectionId : 0,
                    textCollectionLabel: sourceType === 'collection' ? (getTextCollectionById(selectedCollectionId)?.title || '') : '',
                    textExternalUrl: sourceType === 'external' ? textExternalUrl : '',
                    text: previewTextHtml || 'Sem vložte text...',
                    fontFamily,
                    fontSize,
                    textSizingMode: (document.getElementById('setting-textSizingMode')?.value || 'manual') === 'fit' ? 'fit' : 'manual',
                    fontWeight: '700',
                    fontStyle: 'normal',
                    lineHeight,
                    textAlign: 'left',
                    textColor: '#ffffff',
                    bgColor,
                    bgImageData,
                    textAnimationEntry: document.getElementById('setting-textAnimationEntry')?.value || 'none',
                    scrollMode: document.getElementById('setting-scrollMode')?.checked === true,
                    scrollStartPauseMs: Math.round((parseFloat(document.getElementById('setting-scrollStartPauseSec')?.value || '3') || 3) * 1000),
                    scrollEndPauseMs: Math.round((parseFloat(document.getElementById('setting-scrollEndPauseSec')?.value || '3') || 3) * 1000),
                    scrollSpeedPxPerSec: parseInt(document.getElementById('setting-scrollSpeedPxPerSec')?.value || '35', 10) || 35,
                    clockOverlayEnabled: hasClockModuleAvailable() && document.getElementById('setting-clockOverlayEnabled')?.checked === true,
                    clockOverlayPosition: 'bottom',
                    clockOverlayHeightPercent: 30,
                    clockOverlayTimeColor: document.getElementById('setting-clockOverlayTimeColor')?.value || '#ffffff',
                    clockOverlayDateColor: document.getElementById('setting-clockOverlayDateColor')?.value || '#ffffff',
                    clockOverlayClockSize: Math.max(100, Math.min(2000, parseInt(document.getElementById('setting-clockOverlayClockSize')?.value || '300', 10) || 300)),
                    clockOverlayDateFormat: document.getElementById('setting-clockOverlayDateFormat')?.value || 'dmy',
                    clockOverlayShowYear: document.getElementById('setting-clockOverlayShowYear')?.checked !== false,
                    clockOverlayLanguage: document.getElementById('setting-clockOverlayLanguage')?.value || 'sk',
                    clockOverlayDatePosition: document.getElementById('setting-clockOverlayDatePosition')?.value === 'right' ? 'right' : 'below',
                    clockOverlayFontFamily: document.getElementById('setting-clockOverlayFontFamily')?.value || 'Arial, sans-serif',
                    clockOverlaySeparatorColor: document.getElementById('setting-clockOverlaySeparatorColor')?.value || '#22d3ee',
                    clockOverlaySeparatorThickness: Math.max(1, Math.min(8, parseInt(document.getElementById('setting-clockOverlaySeparatorThickness')?.value || '2', 10) || 2))
                }
            };

            const previewUrl = buildModuleUrl(previewModule);
            const cacheBuster = `t=${Date.now()}`;
            previewIframe.src = previewUrl.includes('?')
                ? `${previewUrl}&${cacheBuster}`
                : `${previewUrl}?${cacheBuster}`;
        }

        function bindTextModuleModalEvents(settings) {
            let textPreviewPlaybackInterval = null;
            let textPreviewPlaybackStartedAt = 0;

            const stopTextPreviewPlayback = (resetProgress = true) => {
                if (textPreviewPlaybackInterval) {
                    clearInterval(textPreviewPlaybackInterval);
                    textPreviewPlaybackInterval = null;
                }

                const progressBar = document.getElementById('text-preview-progress');
                const timeLabel = document.getElementById('text-preview-time-label');
                const duration = Math.max(1, parseFloat(document.getElementById('setting-previewDurationSec')?.value || '10'));
                if (resetProgress) {
                    if (progressBar) {
                        progressBar.style.width = '0%';
                    }
                    if (timeLabel) {
                        timeLabel.textContent = `0.0s / ${duration.toFixed(1)}s`;
                    }
                }
            };

            const startTextPreviewPlayback = () => {
                stopTextPreviewPlayback(false);
                updateTextModuleMiniPreview();

                const progressBar = document.getElementById('text-preview-progress');
                const timeLabel = document.getElementById('text-preview-time-label');
                const duration = Math.max(1, parseFloat(document.getElementById('setting-previewDurationSec')?.value || '10'));

                textPreviewPlaybackStartedAt = Date.now();
                textPreviewPlaybackInterval = setInterval(() => {
                    const elapsed = (Date.now() - textPreviewPlaybackStartedAt) / 1000;
                    const ratio = Math.max(0, Math.min(1, elapsed / duration));
                    const shownElapsed = Math.min(elapsed, duration);
                    if (progressBar) {
                        progressBar.style.width = `${Math.round(ratio * 100)}%`;
                    }
                    if (timeLabel) {
                        timeLabel.textContent = `${shownElapsed.toFixed(1)}s / ${duration.toFixed(1)}s`;
                    }

                    if (ratio >= 1) {
                        stopTextPreviewPlayback(false);
                    }
                }, 100);
            };

            const applyTextEditorBackground = () => {
                const textEditor = document.getElementById('text-editor-area');
                if (!textEditor) {
                    return;
                }

                const bgColor = document.getElementById('setting-bgColor')?.value || '#000000';
                const bgImageData = String(document.getElementById('setting-bgImageData')?.value || '').trim();

                textEditor.style.backgroundColor = bgColor;
                if (bgImageData) {
                    textEditor.style.backgroundImage = `url("${bgImageData}")`;
                    textEditor.style.backgroundRepeat = 'no-repeat';
                    textEditor.style.backgroundPosition = 'center center';
                    textEditor.style.backgroundSize = 'cover';
                } else {
                    textEditor.style.backgroundImage = 'none';
                    textEditor.style.backgroundRepeat = '';
                    textEditor.style.backgroundPosition = '';
                    textEditor.style.backgroundSize = '';
                }
            };

            const previewFields = [
                'setting-bgColor',
                'setting-previewResolution',
                'setting-textExternalUrl',
                'setting-textSizingMode',
                'setting-clockOverlayClockSize',
                'setting-clockOverlayDatePosition',
                'setting-clockOverlayDateFormat',
                'setting-clockOverlayShowYear',
                'setting-clockOverlayLanguage',
                'setting-clockOverlayTimeColor',
                'setting-clockOverlayDateColor',
                'setting-clockOverlayFontFamily',
                'setting-clockOverlaySeparatorColor',
                'setting-clockOverlaySeparatorThickness'
            ];

            const textSourceSelect = document.getElementById('setting-textSourceType');
            const textCollectionWrap = document.getElementById('textCollectionSelectorWrap');
            const textExternalWrap = document.getElementById('textExternalSourceWrap');
            const textManualWrap = document.getElementById('textManualEditorWrap');
            const textCollectionSelect = document.getElementById('setting-textCollectionId');

            const updateTextSourceVisibility = () => {
                const source = String(textSourceSelect?.value || 'manual');
                if (textCollectionWrap) {
                    textCollectionWrap.style.display = source === 'collection' ? 'block' : 'none';
                }
                if (textExternalWrap) {
                    textExternalWrap.style.display = source === 'external' ? 'block' : 'none';
                }
                if (textManualWrap) {
                    textManualWrap.style.display = source === 'manual' ? 'block' : 'none';
                }
            };

            const renderTextCollectionOptions = (items) => {
                if (!textCollectionSelect) {
                    return;
                }

                const selectedBefore = parseInt(textCollectionSelect.value || String(parseInt(settings.textCollectionId, 10) || 0), 10) || 0;
                textCollectionSelect.innerHTML = `<option value="0">${textUiText('select_slide_item')}</option>`;

                (Array.isArray(items) ? items : []).forEach((entry) => {
                    const option = document.createElement('option');
                    option.value = String(parseInt(entry.id, 10) || 0);
                    option.textContent = String(entry.title || `${textUiText('item_prefix')} #${entry.id}`);
                    textCollectionSelect.appendChild(option);
                });

                textCollectionSelect.value = String(selectedBefore);
                if (textCollectionSelect.value !== String(selectedBefore)) {
                    textCollectionSelect.value = '0';
                }
            };

            if (textSourceSelect) {
                textSourceSelect.addEventListener('change', () => {
                    updateTextSourceVisibility();
                    if (String(textSourceSelect.value) === 'collection') {
                        const selectedCollection = getTextCollectionById(textCollectionSelect?.value || 0);
                        if (selectedCollection) {
                            applyTextCollectionToTextModuleForm(selectedCollection);
                            applyTextEditorBackground();
                        }
                    }
                    updateTextModuleMiniPreview();
                });
            }

            if (textCollectionSelect) {
                textCollectionSelect.addEventListener('change', () => {
                    const selectedCollection = getTextCollectionById(textCollectionSelect.value || 0);
                    if (selectedCollection) {
                        applyTextCollectionToTextModuleForm(selectedCollection);
                        applyTextEditorBackground();
                    }
                    updateTextModuleMiniPreview();
                });
            }

            const textCollectionRefreshBtn = document.getElementById('setting-textCollectionRefresh');
            if (textCollectionRefreshBtn) {
                textCollectionRefreshBtn.addEventListener('click', async () => {
                    try {
                        const items = await loadTextCollectionsDetailed(true);
                        renderTextCollectionOptions(items);
                        showAutosaveToast(textUiText('collection_refreshed'));
                    } catch (error) {
                        showAutosaveToast(textUiText('collection_refresh_failed'), true);
                    }
                });
            }

            loadTextCollectionsDetailed(false)
                .then((items) => {
                    renderTextCollectionOptions(items);
                    if ((String(textSourceSelect?.value || 'manual') === 'collection') && textCollectionSelect) {
                        const selectedCollection = getTextCollectionById(textCollectionSelect.value || 0);
                        if (selectedCollection) {
                            applyTextCollectionToTextModuleForm(selectedCollection);
                            applyTextEditorBackground();
                        }
                    }
                    updateTextModuleMiniPreview();
                })
                .catch(() => {
                    renderTextCollectionOptions([]);
                });

            updateTextSourceVisibility();

            const previewResolutionSelect = document.getElementById('setting-previewResolution');
            if (previewResolutionSelect) {
                previewResolutionSelect.innerHTML = '';
                const fallbackChoices = [
                    { value: '1920x1080', label: '1920x1080 (16:9)' },
                    { value: '1366x768', label: '1366x768 (16:9)' },
                    { value: '1280x720', label: '1280x720 (16:9)' },
                    { value: '1024x768', label: '1024x768 (4:3)' }
                ];
                const choices = Array.isArray(groupResolutionChoices) && groupResolutionChoices.length > 0
                    ? groupResolutionChoices
                    : fallbackChoices;

                choices.forEach((entry) => {
                    const option = document.createElement('option');
                    option.value = entry.value;
                    option.textContent = entry.label || entry.value;
                    previewResolutionSelect.appendChild(option);
                });

                const defaultValue = (Array.isArray(groupResolutionChoices) && groupResolutionChoices.length > 0)
                    ? groupDefaultResolution
                    : fallbackChoices[0].value;
                previewResolutionSelect.value = defaultValue;
            }

            previewFields.forEach((id) => {
                const field = document.getElementById(id);
                if (!field) {
                    return;
                }
                field.addEventListener('input', () => {
                    if (id === 'setting-bgColor') {
                        applyTextEditorBackground();
                    }
                    updateTextModuleMiniPreview();
                });
                field.addEventListener('change', () => {
                    if (id === 'setting-bgColor') {
                        applyTextEditorBackground();
                    }
                    updateTextModuleMiniPreview();
                });
            });

            const editor = document.getElementById('text-editor-area');
            let savedSelectionRange = null;

            const saveSelection = () => {
                const selection = window.getSelection();
                if (!selection || selection.rangeCount === 0) {
                    return;
                }
                const range = selection.getRangeAt(0);
                if (editor && editor.contains(range.commonAncestorContainer)) {
                    savedSelectionRange = range.cloneRange();
                }
            };

            const restoreSelection = () => {
                if (!savedSelectionRange) {
                    return;
                }
                const selection = window.getSelection();
                if (!selection) {
                    return;
                }
                selection.removeAllRanges();
                selection.addRange(savedSelectionRange);
            };

            if (editor) {
                const debouncedPreviewUpdate = () => updateTextModuleMiniPreview();
                editor.addEventListener('input', debouncedPreviewUpdate);
                editor.addEventListener('keyup', debouncedPreviewUpdate);
                editor.addEventListener('keyup', saveSelection);
                editor.addEventListener('mouseup', saveSelection);
                editor.addEventListener('focus', saveSelection);
                editor.addEventListener('paste', () => {
                    setTimeout(debouncedPreviewUpdate, 0);
                });
            }

            const textAnimationSelect = document.getElementById('setting-textAnimationEntry');
            if (textAnimationSelect) {
                textAnimationSelect.addEventListener('change', updateTextModuleMiniPreview);
            }

            const toolbarButtons = document.querySelectorAll('[data-richcmd]');
            toolbarButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    const command = button.getAttribute('data-richcmd');
                    if (!command) {
                        return;
                    }
                    if (editor) {
                        editor.focus();
                    }
                    restoreSelection();
                    if (command === 'underline') {
                        applyInlineStyleToSelection('text-decoration-line', 'underline');
                    } else {
                        document.execCommand('styleWithCSS', false, true);
                        document.execCommand(command, false, null);
                    }
                    saveSelection();
                    updateTextModuleMiniPreview();
                });
            });

            const colorPicker = document.getElementById('setting-richColor');
            if (colorPicker) {
                colorPicker.addEventListener('input', () => {
                    if (editor) {
                        editor.focus();
                    }
                    restoreSelection();
                    document.execCommand('styleWithCSS', false, true);
                    document.execCommand('foreColor', false, colorPicker.value);
                    saveSelection();
                    updateTextModuleMiniPreview();
                });
            }

            const bgColorPicker = document.getElementById('setting-richBgColor');
            if (bgColorPicker) {
                bgColorPicker.addEventListener('input', () => {
                    if (editor) {
                        editor.focus();
                    }
                    restoreSelection();
                    applyInlineStyleToSelection('background-color', bgColorPicker.value);
                    saveSelection();
                    updateTextModuleMiniPreview();
                });
            }

            const richFontFamily = document.getElementById('setting-richFontFamily');
            if (richFontFamily) {
                richFontFamily.addEventListener('change', () => {
                    if (editor) {
                        editor.focus();
                    }
                    restoreSelection();
                    applyInlineStyleToSelection('font-family', richFontFamily.value);
                    if (editor) {
                        editor.style.fontFamily = richFontFamily.value;
                    }
                    saveSelection();
                    updateTextModuleMiniPreview();
                });
            }

            const richFontSize = document.getElementById('setting-richFontSize');
            if (richFontSize) {
                richFontSize.addEventListener('change', () => {
                    if (editor) {
                        editor.focus();
                    }
                    restoreSelection();
                    const px = Math.max(8, parseInt(richFontSize.value || '32', 10));
                    applyInlineStyleToSelection('font-size', `${px}px`);
                    if (editor) {
                        editor.style.fontSize = `${px}px`;
                    }
                    saveSelection();
                    updateTextModuleMiniPreview();
                });
            }

            const richLineHeight = document.getElementById('setting-richLineHeight');
            if (richLineHeight) {
                richLineHeight.addEventListener('change', () => {
                    if (editor) {
                        editor.focus();
                    }
                    restoreSelection();
                    const value = Math.max(0.8, parseFloat(richLineHeight.value || '1.2'));
                    applyLineHeightToCurrentBlock(value);
                    if (editor) {
                        editor.style.lineHeight = String(value);
                    }
                    saveSelection();
                    updateTextModuleMiniPreview();
                });
            }

            const scrollStartSec = document.getElementById('setting-scrollStartPauseSec');
            const scrollEndSec = document.getElementById('setting-scrollEndPauseSec');
            const scrollSpeed = document.getElementById('setting-scrollSpeedPxPerSec');
            const scrollStartSecValue = document.getElementById('setting-scrollStartPauseSecValue');
            const scrollEndSecValue = document.getElementById('setting-scrollEndPauseSecValue');
            const scrollSpeedValue = document.getElementById('setting-scrollSpeedPxPerSecValue');

            if (scrollStartSec && scrollStartSecValue) {
                const render = () => {
                    scrollStartSecValue.textContent = Number(scrollStartSec.value || 0).toFixed(1);
                };
                scrollStartSec.addEventListener('input', render);
                render();
            }
            if (scrollEndSec && scrollEndSecValue) {
                const render = () => {
                    scrollEndSecValue.textContent = Number(scrollEndSec.value || 0).toFixed(1);
                };
                scrollEndSec.addEventListener('input', render);
                render();
            }
            if (scrollSpeed && scrollSpeedValue) {
                const render = () => {
                    scrollSpeedValue.textContent = String(parseInt(scrollSpeed.value || '35', 10) || 35);
                };
                scrollSpeed.addEventListener('input', render);
                render();
            }

            const previewPlay = document.getElementById('text-preview-play');
            const previewStop = document.getElementById('text-preview-stop');
            if (previewPlay) {
                previewPlay.addEventListener('click', () => {
                    startTextPreviewPlayback();
                });
            }
            if (previewStop) {
                previewStop.addEventListener('click', () => {
                    stopTextPreviewPlayback(true);
                    const iframe = document.getElementById('text-live-preview-iframe');
                    if (iframe) {
                        iframe.src = 'about:blank';
                    }
                });
            }

            const scrollMode = document.getElementById('setting-scrollMode');
            const scrollSettings = document.getElementById('textScrollSettings');
            if (scrollMode && scrollSettings) {
                const applyScrollVisibility = () => {
                    scrollSettings.style.display = scrollMode.checked ? 'grid' : 'none';
                };
                scrollMode.addEventListener('change', applyScrollVisibility);
                applyScrollVisibility();
            }

            const clockSplitToggle = document.getElementById('setting-clockOverlayEnabled');
            const clockSplitSettings = document.getElementById('clockOverlaySettings');
            if (clockSplitToggle && clockSplitSettings) {
                const applyClockSplitVisibility = () => {
                    clockSplitSettings.style.display = clockSplitToggle.checked ? 'grid' : 'none';
                };
                clockSplitToggle.addEventListener('change', () => {
                    applyClockSplitVisibility();
                    updateTextModuleMiniPreview();
                });
                applyClockSplitVisibility();
            }

            const removeBgButton = document.getElementById('setting-removeBgImage');
            const pickBgButton = document.getElementById('setting-bgImagePick');
            const bgDataInput = document.getElementById('setting-bgImageData');
            const bgStatus = document.getElementById('setting-bgImageStatus');
            const bgFileInput = document.getElementById('setting-bgImageFile');

            if (pickBgButton && bgFileInput) {
                pickBgButton.addEventListener('click', () => {
                    bgFileInput.click();
                });
            }

            if (removeBgButton && bgDataInput) {
                removeBgButton.addEventListener('click', () => {
                    bgDataInput.value = '';
                    if (bgStatus) {
                        bgStatus.textContent = textUiText('no_image_selected');
                    }
                    if (bgFileInput) {
                        bgFileInput.value = '';
                    }
                    applyTextEditorBackground();
                    updateTextModuleMiniPreview();
                });
            }

            if (bgFileInput && bgDataInput) {
                bgFileInput.addEventListener('change', async () => {
                    const file = bgFileInput.files && bgFileInput.files[0];
                    if (!file) {
                        return;
                    }
                    if (!file.type.startsWith('image/')) {
                        showAutosaveToast(textUiText('only_images'), true);
                        return;
                    }

                    if (bgStatus) {
                        bgStatus.textContent = textUiText('processing');
                    }

                    try {
                        const dataUrl = await readImageAsCompressedDataUrl(file);
                        bgDataInput.value = dataUrl;
                        if (bgStatus) {
                            bgStatus.textContent = `${file.name} (${Math.round(dataUrl.length / 1024)} KB)`;
                        }
                        applyTextEditorBackground();
                        updateTextModuleMiniPreview();
                    } catch (error) {
                        if (bgStatus) {
                            bgStatus.textContent = textUiText('image_process_error');
                        }
                        showAutosaveToast(textUiText('image_load_failed'), true);
                    }
                });
            }

            if (bgStatus && !String(settings.bgImageData || '').trim()) {
                bgStatus.textContent = textUiText('no_image_selected');
            }

            if (editor) {
                const initialFontFamily = richFontFamily?.value || settings.fontFamily || 'Arial, sans-serif';
                const initialFontSize = Math.max(8, parseInt(String(richFontSize?.value || settings.fontSize || '72'), 10) || 72);
                const initialLineHeight = Math.max(0.8, Math.min(2.5, parseFloat(String(richLineHeight?.value || settings.lineHeight || '1.2')) || 1.2));
                editor.style.fontFamily = initialFontFamily;
                editor.style.fontSize = `${initialFontSize}px`;
                editor.style.lineHeight = String(initialLineHeight);
            }

            applyTextEditorBackground();
            updateTextModuleMiniPreview();
            startTextPreviewPlayback();
        }
        
        function showCustomizationModal(item, index) {
            const moduleKey = item.module_key || getModuleKeyById(item.module_id);
            const settings = item.settings || {};
            const overlaySettings = ensureOverlayDefaults(settings);
            
            let formHtml = '';
            
            // Generate form based on module type
            if (moduleKey === 'clock') {
                formHtml = buildClockCustomizationHtml(settings);
            } else if (moduleKey === 'default-logo') {
                formHtml = buildDefaultLogoCustomizationHtml();
            } else if (moduleKey === 'turned-off') {
                formHtml = buildTurnedOffCustomizationHtml(settings);
            } else if (moduleKey === 'text') {
                formHtml = buildTextCustomizationHtml(item, settings);
            } else if (moduleKey === 'meal-menu') {
                formHtml = buildMealMenuCustomizationHtml(settings);
            } else if (moduleKey === 'room-occupancy') {
                formHtml = buildRoomOccupancyCustomizationHtml(settings);
            } else if (moduleKey === 'image-gallery' || moduleKey === 'gallery') {
                formHtml = buildGalleryCustomizationHtml(settings);
            } else if (moduleKey === 'pdf') {
                formHtml = buildPdfCustomizationHtml(item, settings);
            } else if (moduleKey === 'video') {
                formHtml = buildVideoCustomizationHtml(item, settings);
            } else {
                formHtml = '<p style="text-align: center; color: #999;">Ez a modul nem rendelkezik testreszabási lehetőségekkel.</p>';
            }

            if (isOverlayCarrierModule(moduleKey)) {
                if (moduleKey === 'meal-menu') {
                    formHtml += `<div id="meal-overlay-wrap">${buildOverlayCustomizationHtml(overlaySettings)}</div>`;
                } else {
                    formHtml += buildOverlayCustomizationHtml(overlaySettings);
                }
            }

            const modal = createCustomizationModalElement(item, index, formHtml, moduleKey);
            document.body.appendChild(modal);
            initializeCustomizationModuleUi(moduleKey, item, settings);
            
            // Add event listeners for dynamic form changes
            const typeSelect = document.getElementById('setting-type');
            if (typeSelect) {
                typeSelect.addEventListener('change', function() {
                    const digitalSettings = document.getElementById('digitalSettings');
                    const analogSettings = document.getElementById('analogSettings');
                    const analogOverlaySettings = document.getElementById('analogOverlaySettings');
                    if (this.value === 'digital') {
                        digitalSettings.style.display = 'block';
                        analogSettings.style.display = 'none';
                        if (analogOverlaySettings) {
                            analogOverlaySettings.style.display = 'none';
                        }
                    } else {
                        digitalSettings.style.display = 'none';
                        analogSettings.style.display = 'block';
                        if (analogOverlaySettings) {
                            analogOverlaySettings.style.display = 'block';
                        }
                    }
                });
            }

            const showDateCheckbox = document.getElementById('setting-showDate');
            const dateFormatSelect = document.getElementById('setting-dateFormat');
            const dateInlineCheckbox = document.getElementById('setting-dateInline');
            const weekdayPositionSelect = document.getElementById('setting-weekdayPosition');
            if (showDateCheckbox && dateFormatSelect) {
                const syncDateFormatState = () => {
                    dateFormatSelect.disabled = !showDateCheckbox.checked;
                    if (!showDateCheckbox.checked) {
                        dateFormatSelect.dataset.lastValue = dateFormatSelect.value;
                        dateFormatSelect.value = 'none';
                    } else if (dateFormatSelect.value === 'none') {
                        dateFormatSelect.value = dateFormatSelect.dataset.lastValue || 'dmy';
                    }

                    if (dateInlineCheckbox) {
                        dateInlineCheckbox.disabled = !showDateCheckbox.checked;
                    }

                    if (weekdayPositionSelect) {
                        weekdayPositionSelect.disabled = !showDateCheckbox.checked || !(dateInlineCheckbox && dateInlineCheckbox.checked);
                    }
                };
                if (dateInlineCheckbox) {
                    dateInlineCheckbox.addEventListener('change', syncDateFormatState);
                }
                showDateCheckbox.addEventListener('change', syncDateFormatState);
                syncDateFormatState();
            }
            
            const showVersionCheckbox = document.getElementById('setting-showVersion');
            if (showVersionCheckbox) {
                showVersionCheckbox.addEventListener('change', function() {
                    const versionSettings = document.getElementById('versionSettings');
                    versionSettings.style.display = this.checked ? 'block' : 'none';
                });
            }

            const clockOverlayToggle = document.getElementById('setting-clockOverlayEnabled');
            if (clockOverlayToggle) {
                clockOverlayToggle.addEventListener('change', function() {
                    const section = document.getElementById('clockOverlaySettings');
                    if (section) {
                        section.style.display = this.checked ? 'grid' : 'none';
                    }
                });
            }

            const textOverlayToggle = document.getElementById('setting-textOverlayEnabled');
            if (textOverlayToggle) {
                textOverlayToggle.addEventListener('change', function() {
                    const section = document.getElementById('textOverlaySettings');
                    if (section) {
                        section.style.display = this.checked ? 'grid' : 'none';
                    }
                    updateTextOverlaySourceVisibility();
                });
            }

            const textOverlaySourceSelect = document.getElementById('setting-textOverlaySourceType');
            const updateTextOverlaySourceVisibility = () => {
                const section = document.getElementById('textOverlaySettings');
                const manualWrap = document.getElementById('textOverlayManualWrap');
                const collectionWrap = document.getElementById('textOverlayCollectionWrap');
                const externalWrap = document.getElementById('textOverlayExternalWrap');
                const enabled = document.getElementById('setting-textOverlayEnabled')?.checked === true;
                const sourceType = String(document.getElementById('setting-textOverlaySourceType')?.value || 'manual');

                if (section) {
                    section.style.display = enabled ? 'grid' : 'none';
                }

                if (manualWrap) {
                    manualWrap.style.display = enabled && sourceType === 'manual' ? 'block' : 'none';
                }
                if (collectionWrap) {
                    collectionWrap.style.display = enabled && sourceType === 'collection' ? 'block' : 'none';
                }
                if (externalWrap) {
                    externalWrap.style.display = enabled && sourceType === 'external' ? 'block' : 'none';
                }
            };

            if (textOverlaySourceSelect) {
                textOverlaySourceSelect.addEventListener('change', updateTextOverlaySourceVisibility);
            }
            updateTextOverlaySourceVisibility();

            if (moduleKey === 'text') {
                bindTextModuleModalEvents(settings);
            }
        }

        function isWideCustomizationModule(moduleKey) {
            return moduleKey === 'text'
                || moduleKey === 'image-gallery'
                || moduleKey === 'gallery'
                || moduleKey === 'video'
                || moduleKey === 'meal-menu'
                || moduleKey === 'room-occupancy';
        }

        function buildDefaultLogoCustomizationHtml() {
            return `
                <div style="display: grid; gap: 15px;">
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">${defaultLogoUiText('fixed_content')}</label>
                        <div style="padding:10px; border:1px solid #d1d5db; border-radius:6px; background:#f8fafc; color:#1f2937;">
                            <div style="font-weight:700;">EDUdisplej.sk</div>
                            <div style="margin-top:4px; opacity:0.85;">${defaultLogoUiText('tagline')}</div>
                        </div>
                    </div>
                    <div class="muted" style="font-size:12px;">
                        ${defaultLogoUiText('not_editable')}
                    </div>
                </div>
            `;
        }

        function buildTurnedOffCustomizationHtml(settings) {
            const rawMode = String(settings.screenOffMode || settings.screen_off_mode || 'signal_off').toLowerCase();
            const mode = rawMode === 'black_screen' ? 'black_screen' : 'signal_off';

            return `
                <div style="display:grid; gap:12px;">
                    <div class="muted" style="font-size:13px; line-height:1.45;">
                        ${loopUiText('turned_off_desc')}
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:6px; font-weight:700;">${loopUiText('turned_off_mode_label')}</label>
                        <select id="setting-turnedOffMode" style="width:100%; padding:8px; border-radius:5px; border:1px solid #ccc;">
                            <option value="signal_off" ${mode === 'signal_off' ? 'selected' : ''}>${loopUiText('turned_off_mode_signal_off')}</option>
                            <option value="black_screen" ${mode === 'black_screen' ? 'selected' : ''}>${loopUiText('turned_off_mode_black_screen')}</option>
                        </select>
                    </div>
                </div>
            `;
        }

        function buildClockCustomizationHtml(settings) {
            const dateInlineEnabled = settings.dateInline === true || String(settings.dateInline) === 'true';
            const weekdayPosition = String(settings.weekdayPosition || 'left').toLowerCase() === 'right' ? 'right' : 'left';
            const digitalOverlayEnabled = settings.digitalOverlayEnabled === true || String(settings.digitalOverlayEnabled) === 'true';
            const digitalOverlayPosition = ['auto', 'top', 'center', 'bottom'].includes(String(settings.digitalOverlayPosition || 'auto').toLowerCase())
                ? String(settings.digitalOverlayPosition || 'auto').toLowerCase()
                : 'auto';
            return `
                <div style="display: grid; gap: 15px;">
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">${clockUiText('type_label')}</label>
                        <select id="setting-type" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                            <option value="digital" ${settings.type === 'digital' ? 'selected' : ''}>${clockUiText('type_digital')}</option>
                            <option value="analog" ${settings.type === 'analog' ? 'selected' : ''}>${clockUiText('type_analog')}</option>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">${clockUiText('format_label')}</label>
                        <select id="setting-format" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                            <option value="24h" selected>${clockUiText('format_24h')}</option>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">${clockUiText('date_format_label')}</label>
                        <select id="setting-dateFormat" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                            <option value="full" ${settings.dateFormat === 'full' ? 'selected' : ''}>${clockUiText('date_full')}</option>
                            <option value="short" ${settings.dateFormat === 'short' ? 'selected' : ''}>${clockUiText('date_short')}</option>
                            <option value="dmy" ${settings.dateFormat === 'dmy' ? 'selected' : ''}>${clockUiText('date_dmy')}</option>
                            <option value="numeric" ${settings.dateFormat === 'numeric' ? 'selected' : ''}>${clockUiText('date_numeric')}</option>
                            <option value="none" ${settings.dateFormat === 'none' ? 'selected' : ''}>${clockUiText('date_none')}</option>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">${clockUiText('language_label')}</label>
                        <select id="setting-language" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                            <option value="hu" ${settings.language === 'hu' ? 'selected' : ''}>${clockUiText('lang_hu')}</option>
                            <option value="sk" ${settings.language === 'sk' ? 'selected' : ''}>${clockUiText('lang_sk')}</option>
                            <option value="en" ${settings.language === 'en' ? 'selected' : ''}>${clockUiText('lang_en')}</option>
                        </select>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">${clockUiText('time_color')}</label>
                            <input type="color" id="setting-timeColor" value="${settings.timeColor || '#ffffff'}" style="width: 100%; height: 40px; border-radius: 5px;">
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">${clockUiText('date_color')}</label>
                            <input type="color" id="setting-dateColor" value="${settings.dateColor || '#ffffff'}" style="width: 100%; height: 40px; border-radius: 5px;">
                        </div>
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">${clockUiText('bg_color')}</label>
                        <input type="color" id="setting-bgColor" value="${settings.bgColor || '#000000'}" style="width: 100%; height: 40px; border-radius: 5px;">
                    </div>
                    
                    <div id="digitalSettings" style="${settings.type === 'analog' ? 'display: none;' : ''}">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">${clockUiText('time_font_size')}</label>
                                <input type="number" id="setting-timeFontSize" value="${settings.timeFontSize || settings.fontSize || 120}" min="40" max="1600" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">${clockUiText('date_font_size')}</label>
                                <input type="number" id="setting-dateFontSize" value="${settings.dateFontSize || Math.max(16, Math.round((settings.timeFontSize || settings.fontSize || 120) * 0.3))}" min="14" max="900" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                            </div>
                        </div>
                    </div>
                    
                    <div id="analogSettings" style="${settings.type === 'digital' ? 'display: none;' : ''}">
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">${clockUiText('clock_size')}</label>
                        <input type="number" id="setting-clockSize" value="${settings.clockSize || 300}" min="200" max="2000" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                    </div>
                    
                    <div>
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="setting-showSeconds" ${settings.showSeconds !== false ? 'checked' : ''} style="width: 20px; height: 20px;">
                            <span style="font-weight: bold;">${clockUiText('show_seconds')}</span>
                        </label>
                    </div>

                    <div>
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="setting-showDate" ${(settings.showDate !== false && settings.dateFormat !== 'none') ? 'checked' : ''} style="width: 20px; height: 20px;">
                            <span style="font-weight: bold;">${clockUiText('show_date')}</span>
                        </label>
                    </div>

                    <div>
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="setting-dateInline" ${dateInlineEnabled ? 'checked' : ''} style="width: 20px; height: 20px;">
                            <span style="font-weight: bold;">${clockUiText('date_inline')}</span>
                        </label>
                    </div>

                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">${clockUiText('weekday_position')}</label>
                        <select id="setting-weekdayPosition" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                            <option value="left" ${weekdayPosition === 'left' ? 'selected' : ''}>${clockUiText('weekday_left')}</option>
                            <option value="right" ${weekdayPosition === 'right' ? 'selected' : ''}>${clockUiText('weekday_right')}</option>
                        </select>
                    </div>

                    <div id="analogOverlaySettings" style="${settings.type === 'digital' ? 'display: none;' : ''}">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-bottom:8px;">
                            <input type="checkbox" id="setting-digitalOverlayEnabled" ${digitalOverlayEnabled ? 'checked' : ''} style="width: 20px; height: 20px;">
                            <span style="font-weight: bold;">${clockUiText('digital_overlay')}</span>
                        </label>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">${clockUiText('digital_overlay_position')}</label>
                        <select id="setting-digitalOverlayPosition" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                            <option value="auto" ${digitalOverlayPosition === 'auto' ? 'selected' : ''}>${clockUiText('pos_auto')}</option>
                            <option value="top" ${digitalOverlayPosition === 'top' ? 'selected' : ''}>${clockUiText('pos_top')}</option>
                            <option value="center" ${digitalOverlayPosition === 'center' ? 'selected' : ''}>${clockUiText('pos_center')}</option>
                            <option value="bottom" ${digitalOverlayPosition === 'bottom' ? 'selected' : ''}>${clockUiText('pos_bottom')}</option>
                        </select>
                    </div>
                </div>
            `;
        }

        function buildTextCustomizationHtml(item, settings) {
            const safeTextHtml = sanitizeRichTextHtml(settings.text || '');
            const safeTextInput = escapeHtml(safeTextHtml);
            const safeBgImageData = escapeHtml(settings.bgImageData || '');
            const textSourceTypeRaw = String(settings.textSourceType || 'manual');
            const textSourceType = ['manual', 'collection', 'external'].includes(textSourceTypeRaw) ? textSourceTypeRaw : 'manual';
            const textCollectionId = parseInt(settings.textCollectionId, 10) || 0;
            const textExternalUrl = escapeHtml(String(settings.textExternalUrl || ''));
            const resolvedFontFamily = String(settings.fontFamily || 'Arial, sans-serif');
            const resolvedFontSize = Math.max(8, parseInt(settings.fontSize, 10) || 72);
            const textSizingMode = String(settings.textSizingMode || 'manual').toLowerCase() === 'fit' ? 'fit' : 'manual';
            const resolvedLineHeight = Math.max(0.8, Math.min(2.5, parseFloat(settings.lineHeight) || 1.2));
            const scrollStartSec = Math.max(0, Math.min(5, (parseInt(settings.scrollStartPauseMs, 10) || 3000) / 1000));
            const scrollEndSec = Math.max(0, Math.min(5, (parseInt(settings.scrollEndPauseMs, 10) || 3000) / 1000));
            const scrollSpeed = Math.max(5, Math.min(200, parseInt(settings.scrollSpeedPxPerSec, 10) || 35));
            const textAnimationEntry = settings.textAnimationEntry || 'none';
            const clockModuleAvailable = hasClockModuleAvailable();
            const clockOverlayEnabled = clockModuleAvailable && settings.clockOverlayEnabled === true;
            const clockOverlayPosition = String(settings.clockOverlayPosition || 'top').toLowerCase() === 'bottom' ? 'bottom' : 'top';
            const clockOverlayClockSize = Math.max(100, Math.min(2000, parseInt(settings.clockOverlayClockSize, 10) || 300));
            const clockOverlayDatePosition = String(settings.clockOverlayDatePosition || 'below').toLowerCase() === 'right' ? 'right' : 'below';
            const clockOverlayFontFamily = String(settings.clockOverlayFontFamily || 'Arial, sans-serif');
            const clockOverlayDateFormat = (() => {
                const raw = String(settings.clockOverlayDateFormat || 'dmy').toLowerCase();
                return ['full', 'short', 'dmy', 'numeric', 'none'].includes(raw) ? raw : 'dmy';
            })();
            const clockOverlayShowYear = settings.clockOverlayShowYear !== false;
            const clockOverlayLanguage = (() => {
                const raw = String(settings.clockOverlayLanguage || 'sk').toLowerCase();
                return ['hu', 'sk', 'en'].includes(raw) ? raw : 'sk';
            })();
            const clockOverlaySeparatorColor = String(settings.clockOverlaySeparatorColor || '#22d3ee');
            const clockOverlaySeparatorThickness = Math.max(1, Math.min(8, parseInt(settings.clockOverlaySeparatorThickness, 10) || 2));

            return `
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; align-items:start;">
                    <div style="display: grid; gap: 10px; min-width:0;">
                        <div style="display:grid; gap:8px; border:1px solid #d9e2ec; border-radius:8px; padding:10px; background:#f8fafc;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${textUiText('text_source')}</label>
                                <select id="setting-textSourceType" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="manual" ${textSourceType === 'manual' ? 'selected' : ''}>${textUiText('manual_edit')}</option>
                                    <option value="collection" ${textSourceType === 'collection' ? 'selected' : ''}>${textUiText('slide_collection')}</option>
                                    <option value="external" ${textSourceType === 'external' ? 'selected' : ''}>${textUiText('source_external')}</option>
                                </select>
                            </div>
                            <div id="textCollectionSelectorWrap" style="display:${textSourceType === 'collection' ? 'block' : 'none'};">
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${textUiText('slide_item')}</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <select id="setting-textCollectionId" style="flex:1; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                        <option value="${textCollectionId}">${textCollectionId > 0 ? textUiText('loading') : textUiText('select_slide_item')}</option>
                                    </select>
                                    <button type="button" id="setting-textCollectionRefresh" style="padding:7px 10px; border:1px solid #1e40af; border-radius:5px; background:#fff; color:#1e40af; cursor:pointer;">${textUiText('refresh')}</button>
                                </div>
                                <div style="margin-top:6px; font-size:12px;">
                                    <a href="../text_collections.php" target="_blank" rel="noopener">${textUiText('manage_collection')}</a>
                                </div>
                            </div>
                            <div id="textExternalSourceWrap" style="display:${textSourceType === 'external' ? 'block' : 'none'};">
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${textUiText('external_url')}</label>
                                <input
                                    type="url"
                                    id="setting-textExternalUrl"
                                    value="${textExternalUrl}"
                                    placeholder="${textUiText('external_url_hint')}"
                                    pattern=".*\\.html(?:[?#].*)?$"
                                    title="${textUiText('external_url_hint')}"
                                    style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;"
                                >
                            </div>
                        </div>

                        <div id="textManualEditorWrap" style="display:${textSourceType === 'manual' ? 'block' : 'none'};">
                        <div style="display: grid; gap: 6px;">
                            <label style="display: block; font-weight: bold; margin-bottom: 0;">${textUiText('editor')}</label>
                            <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:6px;">
                                <button type="button" data-richcmd="bold" style="padding:5px 9px;">B</button>
                                <button type="button" data-richcmd="italic" style="padding:5px 9px; font-style:italic;">I</button>
                                <button type="button" data-richcmd="underline" style="padding:5px 9px; text-decoration:underline;">U</button>
                                <button type="button" data-richcmd="insertUnorderedList" style="padding:5px 9px;">${textUiText('bullet_list')}</button>
                                <button type="button" data-richcmd="justifyLeft" style="padding:5px 9px;">${textUiText('align_left')}</button>
                                <button type="button" data-richcmd="justifyCenter" style="padding:5px 9px;">${textUiText('align_center')}</button>
                                <button type="button" data-richcmd="justifyRight" style="padding:5px 9px;">${textUiText('align_right')}</button>
                                <label style="display:flex; align-items:center; gap:4px; font-size:12px;">${textUiText('color')} <input type="color" id="setting-richColor" value="#ffffff"></label>
                                <label style="display:flex; align-items:center; gap:4px; font-size:12px;">${textUiText('background')} <input type="color" id="setting-richBgColor" value="#ffd54f"></label>
                                <select id="setting-richFontFamily" style="padding:4px 6px; border:1px solid #ccc; border-radius:4px; max-width:180px;">
                                    <option value="Arial, sans-serif" ${resolvedFontFamily === 'Arial, sans-serif' ? 'selected' : ''}>Arial</option>
                                    <option value="Verdana, sans-serif" ${resolvedFontFamily === 'Verdana, sans-serif' ? 'selected' : ''}>Verdana</option>
                                    <option value="Tahoma, sans-serif" ${resolvedFontFamily === 'Tahoma, sans-serif' ? 'selected' : ''}>Tahoma</option>
                                    <option value="Trebuchet MS, sans-serif" ${resolvedFontFamily === 'Trebuchet MS, sans-serif' ? 'selected' : ''}>Trebuchet</option>
                                    <option value="Georgia, serif" ${resolvedFontFamily === 'Georgia, serif' ? 'selected' : ''}>Georgia</option>
                                    <option value="Times New Roman, serif" ${resolvedFontFamily === 'Times New Roman, serif' ? 'selected' : ''}>Times New Roman</option>
                                    <option value="Courier New, monospace" ${resolvedFontFamily === 'Courier New, monospace' ? 'selected' : ''}>Courier New</option>
                                </select>
                                <label style="display:flex; align-items:center; gap:4px; font-size:12px;">${textUiText('size')}
                                    <input type="number" id="setting-richFontSize" value="${resolvedFontSize}" min="8" max="260" style="width:72px; padding:4px 6px; border:1px solid #ccc; border-radius:4px;">
                                </label>
                                <label style="display:flex; align-items:center; gap:4px; font-size:12px;">${textUiText('line_height')}
                                    <input type="number" id="setting-richLineHeight" value="${resolvedLineHeight}" min="0.8" max="2.5" step="0.1" style="width:72px; padding:4px 6px; border:1px solid #ccc; border-radius:4px;">
                                </label>
                            </div>
                            <style>
                                #text-editor-area ul,#text-editor-area ol{margin:.25em 0 .25em 1.2em;padding-left:1em;list-style-position:outside;}
                                #text-editor-area li{margin:.1em 0;}
                            </style>
                            <div id="text-editor-area" contenteditable="true" style="min-height:360px; border:1px solid #ccc; border-radius:6px; padding:10px; background:#000; color:#fff; overflow:auto; white-space:pre-wrap; word-wrap:break-word; word-break:break-word; outline:none;">${safeTextHtml}</div>
                            <input type="hidden" id="setting-text" value="${safeTextInput}">
                            <div style="display:flex; gap:6px; align-items:center; margin-top:8px;">
                                <button type="button" id="text-preview-play" style="padding:5px 10px; border:1px solid #0d5f2e; background:#1f7a3f; color:#fff; border-radius:4px; cursor:pointer;">${textUiText('play')}</button>
                                <button type="button" id="text-preview-stop" style="padding:5px 10px; border:1px solid #8a1f1f; background:#b02a2a; color:#fff; border-radius:4px; cursor:pointer;">${textUiText('stop')}</button>
                                <small id="text-preview-time-label" style="color:#444; font-weight:600;">0.0s / ${(parseInt(item.duration_seconds || 10, 10) || 10).toFixed(1)}s</small>
                            </div>
                            <div style="height:10px; border:1px solid #cdd3da; border-radius:4px; overflow:hidden; background:#edf1f5;">
                                <div id="text-preview-progress" style="height:100%; width:0%; background:#1f3e56;"></div>
                            </div>
                        </div>
                        </div>
                    </div>

                    <div style="display: grid; gap: 14px; min-width:0;">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                            <label style="display: block; margin-bottom: 0; font-weight: bold;">${textUiText('live_preview')}</label>
                            <select id="setting-previewResolution" style="padding:6px 8px; border-radius:5px; border:1px solid #ccc; max-width:220px;">
                            </select>
                        </div>
                        <div id="text-preview-frame" style="height:360px; border:1px solid #d0d0d0; border-radius:8px; background:#f4f4f4; overflow:hidden; position:relative;">
                            <iframe id="text-live-preview-iframe" style="width:100%; height:100%; border:0; background:#000;"></iframe>
                        </div>

                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">${textUiText('bg_color')}</label>
                            <input type="color" id="setting-bgColor" value="${settings.bgColor || '#000000'}" style="width: 100%; height: 40px; border-radius: 5px;">
                        </div>

                        <div style="padding: 10px; background: #f7f7f7; border-radius: 8px; border: 1px solid #e5e5e5;">
                            <label style="display: block; margin-bottom: 6px; font-weight: bold;">${textUiText('bg_image_upload')}</label>
                            <input type="file" id="setting-bgImageFile" accept="image/*" style="display:none;">
                            <input type="hidden" id="setting-bgImageData" value="${safeBgImageData}">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 6px;">
                                <small id="setting-bgImageStatus" style="color: #555;">${settings.bgImageData ? textUiText('image_set') : textUiText('no_image_selected')}</small>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <button type="button" id="setting-bgImagePick" style="padding: 5px 10px; border: 1px solid #1e40af; border-radius: 4px; background: #fff; color: #1e40af; cursor: pointer;">${textUiText('bg_image_upload')}</button>
                                    <button type="button" id="setting-removeBgImage" style="padding: 5px 10px; border: none; border-radius: 4px; background: #dc3545; color: #fff; cursor: pointer;">${textUiText('remove_image')}</button>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">${textUiText('text_sizing_mode')}</label>
                            <select id="setting-textSizingMode" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                                <option value="manual" ${textSizingMode === 'manual' ? 'selected' : ''}>${textUiText('text_sizing_manual')}</option>
                                <option value="fit" ${textSizingMode === 'fit' ? 'selected' : ''}>${textUiText('text_sizing_fit')}</option>
                            </select>
                        </div>

                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">${textUiText('text_animation')}</label>
                            <select id="setting-textAnimationEntry" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                                <option value="none" ${textAnimationEntry === 'none' ? 'selected' : ''}>${textUiText('anim_none')}</option>
                                <option value="fadeIn" ${textAnimationEntry === 'fadeIn' ? 'selected' : ''}>Fade In (${textUiText('anim_fade')})</option>
                                <option value="slideUp" ${textAnimationEntry === 'slideUp' ? 'selected' : ''}>Slide Up (${textUiText('anim_slide_up')})</option>
                                <option value="zoomIn" ${textAnimationEntry === 'zoomIn' ? 'selected' : ''}>Zoom In (${textUiText('anim_zoom')})</option>
                            </select>
                        </div>

                        <div style="padding: 10px; border-radius: 8px; border: 1px solid #e5e5e5;">
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-bottom: 10px;">
                                <input type="checkbox" id="setting-scrollMode" ${(settings.scrollMode === true) ? 'checked' : ''} style="width: 20px; height: 20px;">
                                <span style="font-weight: bold;">${textUiText('scroll_mode')}</span>
                            </label>
                            <div id="textScrollSettings" style="display: ${(settings.scrollMode === true) ? 'grid' : 'none'}; gap: 10px; grid-template-columns: 1fr;">
                                <div>
                                    <label style="display: block; margin-bottom: 5px; font-weight: bold;">${textUiText('scroll_start_pause')} <span id="setting-scrollStartPauseSecValue">${scrollStartSec.toFixed(1)}</span></label>
                                    <input type="range" id="setting-scrollStartPauseSec" value="${scrollStartSec}" min="0" max="5" step="0.1" style="width: 100%;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 5px; font-weight: bold;">${textUiText('scroll_end_pause')} <span id="setting-scrollEndPauseSecValue">${scrollEndSec.toFixed(1)}</span></label>
                                    <input type="range" id="setting-scrollEndPauseSec" value="${scrollEndSec}" min="0" max="5" step="0.1" style="width: 100%;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 5px; font-weight: bold;">${textUiText('scroll_speed')} <span id="setting-scrollSpeedPxPerSecValue">${scrollSpeed}</span></label>
                                    <input type="range" id="setting-scrollSpeedPxPerSec" value="${scrollSpeed}" min="5" max="200" step="1" style="width: 100%;">
                                </div>
                            </div>
                        </div>

                        <div style="padding: 10px; border-radius: 8px; border: 1px solid #e5e5e5;">
                            ${clockModuleAvailable ? `
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-bottom: 10px;">
                                <input type="checkbox" id="setting-clockOverlayEnabled" ${clockOverlayEnabled ? 'checked' : ''} style="width: 20px; height: 20px;">
                                <span style="font-weight: bold;">${textUiText('clock_split_toggle')}</span>
                            </label>
                            <div id="clockOverlaySettings" style="display:${clockOverlayEnabled ? 'grid' : 'none'}; gap:10px; grid-template-columns:1fr 1fr;">
                                <div>
                                    <label style="display:block; margin-bottom:5px; font-weight:bold;">${textUiText('clock_split_font_family')}</label>
                                    <select id="setting-clockOverlayFontFamily" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                        <option value="Arial, sans-serif" ${clockOverlayFontFamily === 'Arial, sans-serif' ? 'selected' : ''}>Arial</option>
                                        <option value="Verdana, sans-serif" ${clockOverlayFontFamily === 'Verdana, sans-serif' ? 'selected' : ''}>Verdana</option>
                                        <option value="Tahoma, sans-serif" ${clockOverlayFontFamily === 'Tahoma, sans-serif' ? 'selected' : ''}>Tahoma</option>
                                        <option value="Trebuchet MS, sans-serif" ${clockOverlayFontFamily === 'Trebuchet MS, sans-serif' ? 'selected' : ''}>Trebuchet</option>
                                        <option value="Impact, Arial Black, sans-serif" ${clockOverlayFontFamily === 'Impact, Arial Black, sans-serif' ? 'selected' : ''}>Impact / Arial Black</option>
                                        <option value="Georgia, serif" ${clockOverlayFontFamily === 'Georgia, serif' ? 'selected' : ''}>Georgia</option>
                                        <option value="Times New Roman, serif" ${clockOverlayFontFamily === 'Times New Roman, serif' ? 'selected' : ''}>Times New Roman</option>
                                        <option value="Courier New, monospace" ${clockOverlayFontFamily === 'Courier New, monospace' ? 'selected' : ''}>Courier New</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:5px; font-weight:bold;">${textUiText('clock_split_clock_size')}</label>
                                    <input type="number" id="setting-clockOverlayClockSize" value="${clockOverlayClockSize}" min="100" max="2000" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:5px; font-weight:bold;">${textUiText('clock_split_date_position')}</label>
                                    <select id="setting-clockOverlayDatePosition" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                        <option value="below" ${clockOverlayDatePosition === 'below' ? 'selected' : ''}>${textUiText('clock_split_date_below')}</option>
                                        <option value="right" ${clockOverlayDatePosition === 'right' ? 'selected' : ''}>${textUiText('clock_split_date_right')}</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:5px; font-weight:bold;">${textUiText('clock_split_date_format')}</label>
                                    <select id="setting-clockOverlayDateFormat" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                        <option value="full" ${clockOverlayDateFormat === 'full' ? 'selected' : ''}>${textUiText('clock_split_date_full')}</option>
                                        <option value="short" ${clockOverlayDateFormat === 'short' ? 'selected' : ''}>${textUiText('clock_split_date_short')}</option>
                                        <option value="dmy" ${clockOverlayDateFormat === 'dmy' ? 'selected' : ''}>${textUiText('clock_split_date_dmy')}</option>
                                        <option value="numeric" ${clockOverlayDateFormat === 'numeric' ? 'selected' : ''}>${textUiText('clock_split_date_numeric')}</option>
                                        <option value="none" ${clockOverlayDateFormat === 'none' ? 'selected' : ''}>${textUiText('clock_split_date_none')}</option>
                                    </select>
                                </div>
                                <div style="display:flex; align-items:center; gap:8px; padding-top:28px;">
                                    <input type="checkbox" id="setting-clockOverlayShowYear" ${clockOverlayShowYear ? 'checked' : ''} style="width:20px; height:20px;">
                                    <label for="setting-clockOverlayShowYear" style="font-weight:bold; margin:0;">${textUiText('clock_split_show_year')}</label>
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:5px; font-weight:bold;">${textUiText('clock_split_language')}</label>
                                    <select id="setting-clockOverlayLanguage" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                        <option value="hu" ${clockOverlayLanguage === 'hu' ? 'selected' : ''}>${clockUiText('lang_hu')}</option>
                                        <option value="sk" ${clockOverlayLanguage === 'sk' ? 'selected' : ''}>${clockUiText('lang_sk')}</option>
                                        <option value="en" ${clockOverlayLanguage === 'en' ? 'selected' : ''}>${clockUiText('lang_en')}</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:5px; font-weight:bold;">${textUiText('clock_split_time_color')}</label>
                                    <input type="color" id="setting-clockOverlayTimeColor" value="${settings.clockOverlayTimeColor || '#ffffff'}" style="width:100%; height:40px; border:1px solid #ccc; border-radius:5px;">
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:5px; font-weight:bold;">${textUiText('clock_split_date_color')}</label>
                                    <input type="color" id="setting-clockOverlayDateColor" value="${settings.clockOverlayDateColor || '#ffffff'}" style="width:100%; height:40px; border:1px solid #ccc; border-radius:5px;">
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:5px; font-weight:bold;">${textUiText('clock_split_separator_color')}</label>
                                    <input type="color" id="setting-clockOverlaySeparatorColor" value="${clockOverlaySeparatorColor}" style="width:100%; height:40px; border:1px solid #ccc; border-radius:5px;">
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:5px; font-weight:bold;">${textUiText('clock_split_separator_thickness')}</label>
                                    <input type="number" id="setting-clockOverlaySeparatorThickness" value="${clockOverlaySeparatorThickness}" min="1" max="8" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                </div>
                                <div style="grid-column:1 / -1; font-size:12px; color:#475569; border:1px dashed #cbd5e1; border-radius:6px; padding:8px 10px; background:#f8fafc;">
                                    ${textUiText('clock_split_fixed_bottom_note')}
                                </div>
                            </div>
                            ` : `<div class="muted" style="font-size:12px;">${textUiText('clock_split_unavailable')}</div>`}
                            <input type="hidden" id="setting-clockOverlayHeightPercent" value="30">
                        </div>
                    </div>

                </div>
                <input type="hidden" id="setting-previewDurationSec" value="${parseInt(item.duration_seconds || 10, 10) || 10}">
            `;
        }

        function buildMealMenuCustomizationHtml(settings) {
            const mt = (key, vars = null) => {
                let text = mealUiText(key);
                if (vars && typeof vars === 'object') {
                    Object.entries(vars).forEach(([name, value]) => {
                        text = text.replace(new RegExp(`\\{${name}\\}`, 'g'), String(value ?? ''));
                    });
                }
                return text;
            };
            const siteKey = escapeHtml(String(settings.siteKey || 'jedalen.sk'));
            const institutionId = parseInt(settings.institutionId || 0, 10) || 0;
            const sourceType = String(settings.sourceType || 'manual').toLowerCase() === 'server' ? 'server' : 'manual';
            const mealDisplayMode = normalizeMealDisplayMode(settings.mealDisplayMode);
            const isSmallMode = mealDisplayMode === 'small_screen';
            const smallScreenPageSwitchSec = Math.max(1, Math.min(120, parseInt(settings.smallScreenPageSwitchSec || 12, 10) || 12));
            const smallScreenStartModeRaw = String(settings.smallScreenStartMode || 'current_onward').toLowerCase();
            const smallScreenStartMode = ['current_onward', 'breakfast_onward', 'lunch_onward', 'dinner_onward'].includes(smallScreenStartModeRaw)
                ? smallScreenStartModeRaw
                : 'current_onward';
            const smallScreenMaxMeals = Math.max(1, Math.min(5, parseInt(settings.smallScreenMaxMeals || 5, 10) || 5));
            const smallHeaderMarqueeSpeedPxPerSec = Math.max(8, Math.min(60, parseInt(settings.smallHeaderMarqueeSpeedPxPerSec || 22, 10) || 22));
            const smallHeaderMarqueeEdgePauseMs = Math.max(200, Math.min(5000, parseInt(settings.smallHeaderMarqueeEdgePauseMs || 1200, 10) || 1200));
            const smallRowFontPx = Math.max(60, Math.min(260, parseInt(settings.smallRowFontPx || 150, 10) || 150));
            const smallHeaderRowTitleFontPx = Math.max(20, Math.min(140, parseInt(settings.smallHeaderRowTitleFontPx || settings.smallScreenHeaderFontPx || 58, 10) || 58));
            const smallHeaderRowClockFontPx = Math.max(20, Math.min(160, parseInt(settings.smallHeaderRowClockFontPx || settings.smallScreenHeaderFontPx || 62, 10) || 62));
            const smallHeaderRowBgColor = escapeHtml(String(settings.smallHeaderRowBgColor || '#bae6fd'));
            const smallHeaderRowTextColor = escapeHtml(String(settings.smallHeaderRowTextColor || '#000000'));
            const smallHeaderRowClockColor = escapeHtml(String(settings.smallHeaderRowClockColor || '#facc15'));
            const mergeBreakfastSnack = settings.mergeBreakfastSnack !== false;
            const mergeLunchSnack = settings.mergeLunchSnack !== false;
            const mealScheduleEnabled = settings.mealScheduleEnabled !== false;
            const showTomorrowAfterMealPassed = settings.showTomorrowAfterMealPassed !== false;
            const scheduleBreakfastUntil = escapeHtml(normalizeMealScheduleTime(settings.scheduleBreakfastUntil, '10:00'));
            const scheduleSnackAmUntil = escapeHtml(normalizeMealScheduleTime(settings.scheduleSnackAmUntil, '11:00'));
            const scheduleLunchUntil = escapeHtml(normalizeMealScheduleTime(settings.scheduleLunchUntil, '14:00'));
            const scheduleSnackPmUntil = escapeHtml(normalizeMealScheduleTime(settings.scheduleSnackPmUntil, '18:00'));
            const scheduleDinnerUntil = escapeHtml(normalizeMealScheduleTime(settings.scheduleDinnerUntil, '23:59'));
            const language = normalizeMealLanguage(settings.language);
            const customHeaderTitle = escapeHtml(String(settings.customHeaderTitle || ''));
            const appetiteMessageText = escapeHtml(String(settings.appetiteMessageText || 'Prajeme dobrú chuť!'));
            const sourceUrl = escapeHtml(String(settings.sourceUrl || ''));

            return `
                <div style="display:grid; gap:14px;">
                    <div style="padding:12px; border:1px solid #dde3eb; border-radius:8px; background:#f8fafc; display:grid; gap:10px;">
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('source_type')}</label>
                            <select id="setting-mealSourceType" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                <option value="manual" ${sourceType === 'manual' ? 'selected' : ''}>${mt('source_manual')}</option>
                                <option value="server" ${sourceType === 'server' ? 'selected' : ''}>${mt('source_server')}</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('static_language')}</label>
                            <select id="setting-mealLanguage" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                <option value="hu" ${language === 'hu' ? 'selected' : ''}>Magyar</option>
                                <option value="sk" ${language === 'sk' ? 'selected' : ''}>Slovenčina</option>
                                <option value="en" ${language === 'en' ? 'selected' : ''}>English</option>
                            </select>
                        </div>

                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('display_profile')}</label>
                            <select id="setting-mealDisplayMode" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                <option value="small_screen" ${mealDisplayMode === 'small_screen' ? 'selected' : ''}>${mt('mode_small')}</option>
                                <option value="large_screen" ${mealDisplayMode === 'large_screen' ? 'selected' : ''}>${mt('mode_large')}</option>
                            </select>
                            <div style="font-size:12px; color:#64748b; margin-top:4px;">${mt('mode_hint')}</div>
                        </div>

                        <div id="meal-small-settings" style="display:${isSmallMode ? 'grid' : 'none'}; gap:10px;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('page_switch')}</label>
                                <input type="number" id="setting-smallScreenPageSwitchSec" min="1" max="120" step="1" value="${smallScreenPageSwitchSec}" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                <div style="font-size:12px; color:#64748b; margin-top:4px;">${mt('page_switch_hint')}</div>
                            </div>

                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('small_start_mode')}</label>
                                <select id="setting-smallScreenStartMode" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="current_onward" ${smallScreenStartMode === 'current_onward' ? 'selected' : ''}>${mt('small_start_current')}</option>
                                    <option value="breakfast_onward" ${smallScreenStartMode === 'breakfast_onward' ? 'selected' : ''}>${mt('small_start_breakfast')}</option>
                                    <option value="lunch_onward" ${smallScreenStartMode === 'lunch_onward' ? 'selected' : ''}>${mt('small_start_lunch')}</option>
                                    <option value="dinner_onward" ${smallScreenStartMode === 'dinner_onward' ? 'selected' : ''}>${mt('small_start_dinner')}</option>
                                </select>
                            </div>

                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('small_max_meals')}</label>
                                <input type="range" id="setting-smallScreenMaxMeals" min="1" max="5" step="1" value="${smallScreenMaxMeals}" style="width:100%;">
                                <div style="font-size:12px; color:#334155;">${smallScreenMaxMeals}</div>
                            </div>

                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('small_header_scroll_speed')}</label>
                                <input type="range" id="setting-smallHeaderMarqueeSpeedPxPerSec" min="8" max="60" step="1" value="${smallHeaderMarqueeSpeedPxPerSec}" style="width:100%;">
                                <div style="font-size:12px; color:#334155;">${smallHeaderMarqueeSpeedPxPerSec}</div>
                            </div>

                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('small_header_scroll_pause')}</label>
                                <input type="range" id="setting-smallHeaderMarqueeEdgePauseMs" min="200" max="5000" step="100" value="${smallHeaderMarqueeEdgePauseMs}" style="width:100%;">
                                <div style="font-size:12px; color:#334155;">${smallHeaderMarqueeEdgePauseMs}</div>
                            </div>

                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('small_row_font_size')}</label>
                                <input type="number" id="setting-smallRowFontPx" min="60" max="260" step="2" value="${smallRowFontPx}" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>

                            

                            

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                                <div>
                                    <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('small_header_row_title_font')}</label>
                                    <input type="number" id="setting-smallHeaderRowTitleFontPx" min="20" max="140" step="2" value="${smallHeaderRowTitleFontPx}" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('small_header_row_clock_font')}</label>
                                    <input type="number" id="setting-smallHeaderRowClockFontPx" min="20" max="160" step="2" value="${smallHeaderRowClockFontPx}" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                </div>
                            </div>

                            <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-mergeBreakfastSnack" ${mergeBreakfastSnack ? 'checked' : ''}> ${mt('join_breakfast_snack')}</label>
                            <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-mergeLunchSnack" ${mergeLunchSnack ? 'checked' : ''}> ${mt('join_lunch_snack')}</label>
                        </div>

                        <div style="padding:10px; border:1px solid #e2e8f0; border-radius:8px; background:#fff; display:grid; gap:8px;">
                            <div style="font-weight:700; color:#1f2937;">${mt('schedule_settings')}</div>
                            <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-mealScheduleEnabled" ${mealScheduleEnabled ? 'checked' : ''}> ${mt('schedule_enabled')}</label>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                                <div>
                                    <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('schedule_breakfast_until')}</label>
                                    <input type="text" id="setting-scheduleBreakfastUntil" value="${scheduleBreakfastUntil}" placeholder="HH:MM" inputmode="numeric" pattern="^([01]\\d|2[0-3]):[0-5]\\d$" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('schedule_snack_am_until')}</label>
                                    <input type="text" id="setting-scheduleSnackAmUntil" value="${scheduleSnackAmUntil}" placeholder="HH:MM" inputmode="numeric" pattern="^([01]\\d|2[0-3]):[0-5]\\d$" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('schedule_lunch_until')}</label>
                                    <input type="text" id="setting-scheduleLunchUntil" value="${scheduleLunchUntil}" placeholder="HH:MM" inputmode="numeric" pattern="^([01]\\d|2[0-3]):[0-5]\\d$" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('schedule_snack_pm_until')}</label>
                                    <input type="text" id="setting-scheduleSnackPmUntil" value="${scheduleSnackPmUntil}" placeholder="HH:MM" inputmode="numeric" pattern="^([01]\\d|2[0-3]):[0-5]\\d$" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('schedule_dinner_until')}</label>
                                    <input type="text" id="setting-scheduleDinnerUntil" value="${scheduleDinnerUntil}" placeholder="HH:MM" inputmode="numeric" pattern="^([01]\\d|2[0-3]):[0-5]\\d$" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                </div>
                            </div>
                            <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showTomorrowAfterMealPassed" ${showTomorrowAfterMealPassed ? 'checked' : ''}> ${mt('show_tomorrow_after_passed')}</label>
                        </div>

                        <div style="display:grid; grid-template-columns:1fr auto; gap:8px; align-items:end;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('site')}</label>
                                <select id="setting-mealSiteKey" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="${siteKey}">${siteKey || mt('option_site')}</option>
                                </select>
                            </div>
                            <button type="button" id="setting-mealReloadSites" style="padding:8px 10px; border:1px solid #1e40af; border-radius:5px; background:#fff; color:#1e40af; cursor:pointer;">${mt('refresh')}</button>
                        </div>

                        <div style="display:grid; grid-template-columns:1fr auto; gap:8px; align-items:end;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('institution')}</label>
                                <select id="setting-mealInstitutionId" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="${institutionId}">${institutionId > 0 ? mt('option_loading') : mt('option_institution')}</option>
                                </select>
                            </div>
                            <button type="button" id="setting-mealReloadInstitutions" style="padding:8px 10px; border:1px solid #1e40af; border-radius:5px; background:#fff; color:#1e40af; cursor:pointer;">${mt('refresh')}</button>
                        </div>

                        <div id="setting-mealStatus" style="font-size:12px; color:#475569;">${mt('loading_sources')}</div>
                    </div>

                    <div style="padding:12px; border:1px solid #dde3eb; border-radius:8px; background:#fff; display:grid; gap:8px;">
                        <div style="font-weight:700; color:#1f2937;">${mt('visible_meals')}</div>
                        <div id="meal-large-header-settings" style="display:${isSmallMode ? 'none' : 'grid'}; gap:8px;">
                            <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showHeaderTitle" ${settings.showHeaderTitle !== false ? 'checked' : ''}> ${mt('show_header')}</label>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('custom_header')}</label>
                                <input type="text" id="setting-customHeaderTitle" value="${customHeaderTitle}" placeholder="pl. Dnešné menu" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                            <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showInstitutionName" ${settings.showInstitutionName !== false ? 'checked' : ''}> ${mt('show_institution')}</label>
                        </div>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showBreakfast" ${settings.showBreakfast !== false ? 'checked' : ''}> ${mt('breakfast')}</label>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showSnackAm" ${settings.showSnackAm !== false ? 'checked' : ''}> ${mt('snack_am')}</label>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showLunch" ${settings.showLunch !== false ? 'checked' : ''}> ${mt('lunch')}</label>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showSnackPm" ${settings.showSnackPm === true ? 'checked' : ''}> ${mt('snack_pm')}</label>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showDinner" ${settings.showDinner === true ? 'checked' : ''}> ${mt('dinner')}</label>
                        <label style="display:flex; align-items:center; gap:8px; margin-top:6px; border-top:1px solid #eef2f7; padding-top:8px;"><input type="checkbox" id="setting-showMealTypeSvgIcons" ${settings.showMealTypeSvgIcons !== false ? 'checked' : ''}> ${mt('show_icons')}</label>
                        <div id="meal-large-message-settings" style="display:${isSmallMode ? 'none' : 'grid'}; gap:8px; margin-top:6px; border-top:1px solid #eef2f7; padding-top:8px;">
                            <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showAppetiteMessage" ${settings.showAppetiteMessage === true ? 'checked' : ''}> ${mt('appetite_toggle')}</label>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('appetite_text')}</label>
                                <input type="text" id="setting-appetiteMessageText" value="${appetiteMessageText}" placeholder="Prajeme dobrú chuť!" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                        </div>
                        <div id="meal-large-source-settings" style="display:${isSmallMode ? 'none' : 'grid'}; gap:8px;">
                            <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showSourceUrl" ${settings.showSourceUrl === true ? 'checked' : ''}> ${mt('source_url_toggle')}</label>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${mt('source_url')}</label>
                                <input type="url" id="setting-sourceUrl" value="${sourceUrl}" placeholder="https://www.jedalen.sk/Pages/EatMenu?Ident=..." style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        function buildRoomOccupancyCustomizationHtml(settings) {
            const roomId = parseInt(settings.roomId || 0, 10) || 0;
            const showNextCount = parseInt(settings.showNextCount || 4, 10) || 4;
            const language = ['hu', 'sk', 'en'].includes(String(settings.language || '').toLowerCase()) ? String(settings.language).toLowerCase() : resolveUiLang();
            const refreshIntervalSec = Math.max(30, Math.min(3600, parseInt(settings.runtimeRefreshIntervalSec || 60, 10) || 60));

            return `
                <div style="display:grid; gap:14px;">
                    <div style="padding:12px; border:1px solid #dde3eb; border-radius:8px; background:#f8fafc; display:grid; gap:10px;">
                        <div style="display:grid; grid-template-columns:1fr auto; gap:8px; align-items:end;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${roomOccUiText('room')}</label>
                                <select id="setting-roomOccRoomId" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="${roomId}">${roomId > 0 ? roomOccUiText('loading') : roomOccUiText('choose_room')}</option>
                                </select>
                            </div>
                            <button type="button" id="setting-roomOccReloadRooms" style="padding:8px 10px; border:1px solid #1e40af; border-radius:5px; background:#fff; color:#1e40af; cursor:pointer;">${roomOccUiText('refresh')}</button>
                        </div>
                        <div id="setting-roomOccStatus" style="font-size:12px; color:#475569;">${roomOccUiText('loading_rooms')}</div>
                    </div>

                    <div style="padding:12px; border:1px solid #dde3eb; border-radius:8px; background:#fff; display:grid; gap:8px;">
                        <div style="font-weight:700; color:#1f2937;">${roomOccUiText('display_title')}</div>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-roomOccShowOnlyCurrent" ${settings.showOnlyCurrent === true ? 'checked' : ''}> ${roomOccUiText('only_current')}</label>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">${roomOccUiText('next_count')}</label>
                            <input type="number" id="setting-roomOccShowNextCount" min="1" max="12" value="${showNextCount}" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${roomOccUiText('language')}</label>
                                <select id="setting-roomOccLanguage" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="sk" ${language === 'sk' ? 'selected' : ''}>Slovenčina</option>
                                    <option value="en" ${language === 'en' ? 'selected' : ''}>English</option>
                                    <option value="hu" ${language === 'hu' ? 'selected' : ''}>Magyar</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${roomOccUiText('refresh_interval')}</label>
                                <input type="number" id="setting-roomOccRefreshSec" min="30" max="3600" value="${refreshIntervalSec}" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        function buildGalleryCustomizationHtml(settings) {
            const gt = galleryUiText;
            let galleryImages = [];
            try {
                const parsed = JSON.parse(String(settings.imageUrlsJson || '[]'));
                galleryImages = Array.isArray(parsed)
                    ? parsed.map(v => String(v || '').trim()).filter(Boolean).slice(0, 10)
                    : [];
            } catch (_) {
                galleryImages = [];
            }
            const safeGalleryJson = escapeHtml(JSON.stringify(galleryImages));

            return `
                <div style="display:grid; gap:14px;">
                    <div>
                        <label style="display:block; margin-bottom:6px; font-weight:bold;">${gt('upload_title')}</label>
                        <div id="gallery-upload-area" style="border:2px dashed #1e40af; border-radius:8px; padding:20px; text-align:center; cursor:pointer; background:#f8f9fa;">
                            <input type="file" id="gallery-file-input" accept="image/*" multiple style="display:none;">
                            <div style="font-size:14px; color:#425466;">${gt('drop_or_click')} <span style="color:#1e40af; font-weight:bold; text-decoration:underline;">${gt('click_to_pick')}</span></div>
                            <div style="font-size:12px; color:#8a97a6; margin-top:6px;">${gt('limits')}</div>
                        </div>
                        <div style="margin-top:10px; padding:10px; border:1px solid #dde3eb; border-radius:8px; background:#fcfdff;">
                            <div style="display:flex; justify-content:space-between; gap:8px; align-items:center; margin-bottom:8px;">
                                <strong style="font-size:13px; color:#1f2a37;">${gt('cloud_title')}</strong>
                                <button type="button" id="gallery-library-refresh" style="padding:5px 8px; border:1px solid #1e40af; background:#fff; color:#1e40af; border-radius:5px; cursor:pointer; font-size:12px;">${gt('refresh')}</button>
                            </div>
                            <div id="gallery-library-status" style="font-size:12px; color:#425466; margin-bottom:6px;">${gt('loading')}</div>
                            <div id="gallery-library-list" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(90px, 1fr)); gap:8px;"></div>
                            <button type="button" id="gallery-library-import" style="margin-top:8px; padding:6px 10px; border:1px solid #16a34a; background:#16a34a; color:#fff; border-radius:5px; cursor:pointer; font-size:12px;">${gt('import_selected')}</button>
                        </div>
                        <input type="hidden" id="gallery-image-urls-json" value='${safeGalleryJson}'>
                        <div id="gallery-upload-status" style="font-size:12px; color:#425466; margin-top:6px;"></div>
                        <div id="gallery-upload-progress-wrap" style="display:none; margin-top:6px;">
                            <div style="height:8px; background:#e2e8f0; border-radius:999px; overflow:hidden;">
                                <div id="gallery-upload-progress-bar" style="height:100%; width:0%; background:#1e40af; transition:width .2s ease;"></div>
                            </div>
                            <div id="gallery-upload-progress-text" style="font-size:11px; color:#475569; margin-top:4px;">0%</div>
                        </div>
                        <div id="gallery-image-list" style="display:grid; gap:6px; margin-top:8px;"></div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">${gt('display_mode')}</label>
                            <select id="gallery-display-mode" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                <option value="slideshow" ${(settings.displayMode || 'slideshow') === 'slideshow' ? 'selected' : ''}>${gt('mode_slideshow')}</option>
                                <option value="collage" ${settings.displayMode === 'collage' ? 'selected' : ''}>${gt('mode_collage')}</option>
                                <option value="single" ${settings.displayMode === 'single' ? 'selected' : ''}>${gt('mode_single')}</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">${gt('fit_mode')}</label>
                            <select id="gallery-fit-mode" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                <option value="cover" ${settings.fitMode === 'cover' ? 'selected' : ''}>Cover</option>
                                <option value="contain" ${(settings.fitMode || 'contain') === 'contain' ? 'selected' : ''}>Contain</option>
                                <option value="fill" ${settings.fitMode === 'fill' ? 'selected' : ''}>Fill</option>
                            </select>
                        </div>
                        <div id="gallery-slide-interval-wrap">
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">${gt('slide_interval')}</label>
                            <input type="number" id="gallery-slide-interval" value="${settings.slideIntervalSec || 5}" min="1" max="30" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                        </div>
                        <div id="gallery-transition-toggle-wrap" style="display:flex; align-items:flex-end;">
                            <label style="display:flex; align-items:center; gap:8px; font-weight:bold; margin-bottom:4px; cursor:pointer;">
                                <input type="checkbox" id="gallery-transition-enabled" ${(settings.transitionEnabled !== false && Number(settings.transitionMs || 450) !== 0) ? 'checked' : ''}>
                                <span>${gt('transition_enabled')}</span>
                            </label>
                        </div>
                        <div id="gallery-collage-columns-wrap">
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">${gt('collage_columns')}</label>
                            <input type="number" id="gallery-collage-columns" value="${settings.collageColumns || 3}" min="2" max="5" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">${gt('background_color')}</label>
                            <input type="color" id="gallery-bg-color" value="${settings.bgColor || '#000000'}" style="width:100%; height:40px; border:1px solid #ccc; border-radius:5px;">
                        </div>
                    </div>

                    <div style="border:1px solid #d6dde8; border-radius:8px; padding:10px; background:#f8fafc;">
                        <div style="font-weight:700; color:#425466; margin-bottom:8px;">${gt('preview')}</div>
                        <div style="height:320px; border:1px solid #e0e6ed; border-radius:6px; background:#fff; overflow:hidden;">
                            <iframe id="gallery-live-preview-iframe" style="width:100%; height:100%; border:0; background:#000;"></iframe>
                        </div>
                        <div id="gallery-preview-empty" style="font-size:12px; color:#8a97a6; margin-top:8px; display:${galleryImages.length ? 'none' : 'block'};">${gt('preview_empty')}</div>
                    </div>
                </div>
            `;
        }

        function buildPdfCustomizationHtml(item, settings) {
            const pdfDataBase64 = item.settings?.pdfDataBase64 || '';
            const pdfAssetUrl = item.settings?.pdfAssetUrl || '';
            const fileSizeKB = pdfDataBase64 ? Math.round(pdfDataBase64.length / 1024) : 0;
            const hasPdfSource = !!pdfAssetUrl || !!pdfDataBase64;
            const autoScrollEnabled = settings.autoScrollEnabled === true || settings.navigationMode === 'auto';
            const horizontalStartPercent = Number.isFinite(parseInt(settings.horizontalStartPercent, 10))
                ? Math.max(0, Math.min(100, parseInt(settings.horizontalStartPercent, 10)))
                : 0;
            const autoScrollSectionsJson = typeof settings.autoScrollSectionsJson === 'string'
                ? settings.autoScrollSectionsJson
                : '[]';
            const pauseAtPercent = Number.isFinite(parseInt(settings.pauseAtPercent, 10))
                ? parseInt(settings.pauseAtPercent, 10)
                : -1;

            return `
                <div style="display: grid; gap: 16px;">
                    <div>
                        <label style="display: block; margin-bottom: 10px; font-weight: bold;">${pdfUiText('upload_title')}</label>
                        <div id="pdf-upload-area" style="
                            border: 2px dashed #1e40af;
                            border-radius: 8px;
                            padding: 30px;
                            text-align: center;
                            cursor: pointer;
                            transition: background-color 0.2s;
                            background-color: #f8f9fa;
                        ">
                            <input type="file" id="pdf-file-input" accept=".pdf" style="display: none;">
                            <div style="font-size: 14px; color: #425466;">
                                ${pdfUiText('drop_or_click')} <span style="color: #1e40af; font-weight: bold; text-decoration: underline;">${pdfUiText('click_to_pick')}</span>
                            </div>
                            <div style="font-size: 12px; color: #8a97a6; margin-top: 8px;">${pdfUiText('max_size')}</div>
                            ${hasPdfSource ? `<div style="color: #28a745; margin-top: 8px; font-size: 13px;">${pdfUiText('loaded')}${fileSizeKB > 0 ? ` (${fileSizeKB} KB)` : ''}</div>` : ''}
                        </div>
                    </div>
                    <div style="display:grid; gap:12px;">
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">${pdfUiText('fixed_zoom')}</label>
                            <input type="number" id="pdf-zoomLevel" value="${settings.zoomLevel || 100}" min="50" max="250" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">${pdfUiText('horizontal_focus')}</label>
                            <div style="display:grid; grid-template-columns:1fr 90px; gap:8px; align-items:center;">
                                <input type="range" id="pdf-horizontalStartPercent" value="${horizontalStartPercent}" min="0" max="100" step="1">
                                <input type="number" id="pdf-horizontalStartPercentNumber" value="${horizontalStartPercent}" min="0" max="100" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                            </div>
                            <div style="font-size:12px; color:#8a97a6; margin-top:4px;">${pdfUiText('horizontal_hint')}</div>
                        </div>
                        <div>
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" id="pdf-autoScrollEnabled" ${autoScrollEnabled ? 'checked' : ''} style="width: 20px; height: 20px;">
                                <span style="font-weight: bold;">${pdfUiText('auto_scroll')}</span>
                            </label>
                        </div>
                        <div class="pdf-scroll-settings" style="display:${autoScrollEnabled ? 'grid' : 'none'}; gap:12px;">
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">${pdfUiText('scroll_speed')}</label>
                                <input type="number" id="pdf-scrollSpeed" value="${settings.autoScrollSpeedPxPerSec || 30}" min="5" max="300" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">${pdfUiText('start_pause')}</label>
                                <input type="number" id="pdf-startPause" value="${settings.autoScrollStartPauseMs || 2000}" min="0" max="60000" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">${pdfUiText('end_pause')}</label>
                                <input type="number" id="pdf-endPause" value="${settings.autoScrollEndPauseMs || 2000}" min="0" max="60000" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">${pdfUiText('pause_pos')}</label>
                                <input type="number" id="pdf-pauseAtPercent" value="${pauseAtPercent}" min="-1" max="100" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                                <div style="font-size:12px; color:#8a97a6; margin-top:4px;">${pdfUiText('pause_pos_hint')}</div>
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">${pdfUiText('pause_duration')}</label>
                                <input type="number" id="pdf-pauseDurationMs" value="${settings.pauseDurationMs || 2000}" min="0" max="60000" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                            </div>
                            <div style="grid-column:1 / -1; border:1px solid #d8e2ee; border-radius:8px; padding:10px; background:#fff; display:grid; gap:8px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                                    <strong style="font-size:13px; color:#1f2a37;">${pdfUiText('section_planner')}</strong>
                                    <button type="button" id="pdf-section-add" style="padding:6px 10px; border:1px solid #1e40af; background:#1e40af; color:#fff; border-radius:5px; cursor:pointer; font-size:12px;">${pdfUiText('add_section')}</button>
                                </div>
                                <div id="pdf-section-timeline" style="height:180px; border:1px solid #d6dde8; border-radius:6px; background:linear-gradient(to bottom,#f8fafc,#eef2f7); position:relative;"></div>
                                <div style="display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:8px;">
                                    <div>
                                        <label style="display:block; margin-bottom:4px; font-size:12px; color:#425466;">${pdfUiText('section_start')}</label>
                                        <input type="number" id="pdf-section-start" value="0" min="0" max="100" style="width:100%; padding:7px; border:1px solid #c9d4e3; border-radius:5px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:4px; font-size:12px; color:#425466;">${pdfUiText('section_end')}</label>
                                        <input type="number" id="pdf-section-end" value="100" min="0" max="100" style="width:100%; padding:7px; border:1px solid #c9d4e3; border-radius:5px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:4px; font-size:12px; color:#425466;">${pdfUiText('section_pause')}</label>
                                        <input type="number" id="pdf-section-pause" value="2000" min="0" max="60000" style="width:100%; padding:7px; border:1px solid #c9d4e3; border-radius:5px;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:4px; font-size:12px; color:#425466;">${pdfUiText('section_horizontal')}</label>
                                        <input type="number" id="pdf-section-horizontal" value="${horizontalStartPercent}" min="0" max="100" style="width:100%; padding:7px; border:1px solid #c9d4e3; border-radius:5px;">
                                    </div>
                                </div>
                                <div id="pdf-sections-list" style="display:grid; gap:6px;"></div>
                                <input type="hidden" id="pdf-sections-json" value="${escapeHtml(autoScrollSectionsJson)}">
                                <div style="font-size:12px; color:#8a97a6;">${pdfUiText('section_tip')}</div>
                            </div>
                        </div>
                    </div>
                    <div id="pdf-preview-area" style="border:1px solid #d6dde8; border-radius:8px; padding:10px; background:#f8fafc;">
                        <div style="font-weight:700; color:#425466; margin-bottom:8px;">${pdfUiText('preview')}</div>
                        <div style="height:360px; overflow:auto; border:1px solid #e0e6ed; border-radius:6px; background:#fff; padding:8px;">
                            <iframe id="pdf-live-preview-iframe" style="width:100%; height:100%; border:0; background:#fff;"></iframe>
                        </div>
                        <div id="pdf-preview-empty" style="font-size:12px; color:#8a97a6; margin-top:8px; display:${hasPdfSource ? 'none' : 'block'};">${pdfUiText('preview_empty')}</div>
                    </div>
                </div>
            `;
        }

        function buildVideoCustomizationHtml(item, settings) {
            const vt = videoUiText;
            const durationSec = parseInt(settings.videoDurationSec || item.duration_seconds || 10, 10) || 10;

            return `
                <div style="display:grid; gap:14px;">
                    <div>
                        <label style="display:block; margin-bottom:6px; font-weight:bold;">${vt('upload_title')}</label>
                        <div id="video-upload-area" style="border:2px dashed #1e40af; border-radius:8px; padding:20px; text-align:center; cursor:pointer; background:#f8f9fa;">
                            <input type="file" id="video-file-input" accept="video/*,.mp4,.mov,.mkv,.webm,.avi,.m4v" style="display:none;">
                            <div style="font-size:14px; color:#425466;">${vt('drop_or_click')} <span style="color:#1e40af; font-weight:bold; text-decoration:underline;">${vt('click_to_pick')}</span></div>
                            <div style="font-size:12px; color:#8a97a6; margin-top:6px;">${vt('limits')}</div>
                        </div>
                        <div style="margin-top:10px; padding:10px; border:1px solid #dde3eb; border-radius:8px; background:#fcfdff;">
                            <div style="display:flex; justify-content:space-between; gap:8px; align-items:center; margin-bottom:8px;">
                                <strong style="font-size:13px; color:#1f2a37;">${vt('cloud_title')}</strong>
                                <button type="button" id="video-library-refresh" style="padding:5px 8px; border:1px solid #1e40af; background:#fff; color:#1e40af; border-radius:5px; cursor:pointer; font-size:12px;">${vt('refresh')}</button>
                            </div>
                            <div id="video-library-status" style="font-size:12px; color:#425466; margin-bottom:6px;">${vt('loading')}</div>
                            <div id="video-library-list" style="display:grid; gap:6px;"></div>
                            <button type="button" id="video-library-import" style="margin-top:8px; padding:6px 10px; border:1px solid #16a34a; background:#16a34a; color:#fff; border-radius:5px; cursor:pointer; font-size:12px;">${vt('import_selected')}</button>
                        </div>
                        <div id="video-upload-status" style="font-size:12px; color:#425466; margin-top:8px;"></div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">${vt('fit_mode')}</label>
                            <select id="video-fit-mode" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                <option value="contain" ${(settings.fitMode || 'contain') === 'contain' ? 'selected' : ''}>${vt('fit_contain')}</option>
                                <option value="cover" ${settings.fitMode === 'cover' ? 'selected' : ''}>${vt('fit_cover')}</option>
                                <option value="fill" ${settings.fitMode === 'fill' ? 'selected' : ''}>${vt('fit_fill')}</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">${vt('muted')}</label>
                            <label style="display:flex; align-items:center; gap:8px; border:1px solid #d1d5db; border-radius:5px; padding:8px;">
                                <input type="checkbox" id="video-muted" ${(settings.muted !== false) ? 'checked' : ''}>
                                <span style="font-size:13px;">${vt('muted_playback')}</span>
                            </label>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">${vt('background_color')}</label>
                            <input type="color" id="video-bg-color" value="${settings.bgColor || '#000000'}" style="width:100%; height:40px; border:1px solid #ccc; border-radius:5px;">
                        </div>
                    </div>

                    <div id="video-duration-badge" style="padding:10px; border:1px solid #c7e7d2; background:#ecfdf3; border-radius:8px; color:#0f5132; font-size:13px;">
                        ${vt('duration_fixed').replace('{seconds}', String(durationSec))}
                    </div>

                    <div style="border:1px solid #d6dde8; border-radius:8px; padding:10px; background:#f8fafc;">
                        <div style="font-weight:700; color:#425466; margin-bottom:8px;">${vt('preview')}</div>
                        <div style="height:320px; border:1px solid #e0e6ed; border-radius:6px; background:#fff; overflow:hidden;">
                            <iframe id="video-live-preview-iframe" style="width:100%; height:100%; border:0; background:#000;"></iframe>
                        </div>
                        <div id="video-preview-empty" style="font-size:12px; color:#8a97a6; margin-top:8px; display:${settings.videoAssetUrl ? 'none' : 'block'};">${vt('preview_empty')}</div>
                    </div>
                </div>
            `;
        }

        function buildOverlayCustomizationHtml(overlaySettings) {
            const overlayCollectionText = (() => {
                try {
                    const parsed = JSON.parse(String(overlaySettings.textOverlayCollectionJson || '[]'));
                    return Array.isArray(parsed)
                        ? parsed.map((entry) => String(entry || '').trim()).filter(Boolean).join('\n')
                        : '';
                } catch (_) {
                    return '';
                }
            })();

            return `
                <div style="display:grid; gap:12px; margin-top:16px; border:1px solid #d6dde8; border-radius:8px; padding:12px; background:#f8fafc;">
                    <div style="font-weight:700; color:#1f2a37;">${overlayUiText('header')}</div>

                    <div style="padding:10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; display:grid; gap:10px;">
                        <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                            <input type="checkbox" id="setting-clockOverlayEnabled" ${overlaySettings.clockOverlayEnabled ? 'checked' : ''} style="width:20px; height:20px;">
                            <span style="font-weight:600;">${overlayUiText('clock_toggle')}</span>
                        </label>
                        <div id="clockOverlaySettings" style="display:${overlaySettings.clockOverlayEnabled ? 'grid' : 'none'}; gap:10px; grid-template-columns:1fr 1fr;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${overlayUiText('position')}</label>
                                <select id="setting-clockOverlayPosition" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="top" ${overlaySettings.clockOverlayPosition === 'top' ? 'selected' : ''}>${overlayUiText('top')}</option>
                                    <option value="bottom" ${overlaySettings.clockOverlayPosition === 'bottom' ? 'selected' : ''}>${overlayUiText('bottom')}</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${overlayUiText('band_height')}</label>
                                <input type="number" id="setting-clockOverlayHeightPercent" value="${overlaySettings.clockOverlayHeightPercent || 40}" min="20" max="40" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${overlayUiText('clock_color')}</label>
                                <input type="color" id="setting-clockOverlayTimeColor" value="${overlaySettings.clockOverlayTimeColor || '#ffffff'}" style="width:100%; height:40px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${overlayUiText('date_color')}</label>
                                <input type="color" id="setting-clockOverlayDateColor" value="${overlaySettings.clockOverlayDateColor || '#ffffff'}" style="width:100%; height:40px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                        </div>
                    </div>

                    <div style="padding:10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; display:grid; gap:10px;">
                        <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                            <input type="checkbox" id="setting-textOverlayEnabled" ${overlaySettings.textOverlayEnabled ? 'checked' : ''} style="width:20px; height:20px;">
                            <span style="font-weight:600;">${overlayUiText('text_toggle')}</span>
                        </label>
                        <div id="textOverlaySettings" style="display:${overlaySettings.textOverlayEnabled ? 'grid' : 'none'}; gap:10px; grid-template-columns:1fr 1fr;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${overlayUiText('position')}</label>
                                <select id="setting-textOverlayPosition" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="top" ${overlaySettings.textOverlayPosition === 'top' ? 'selected' : ''}>${overlayUiText('top')}</option>
                                    <option value="bottom" ${overlaySettings.textOverlayPosition === 'bottom' ? 'selected' : ''}>${overlayUiText('bottom')}</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${overlayUiText('band_height')}</label>
                                <input type="number" id="setting-textOverlayHeightPercent" value="${overlaySettings.textOverlayHeightPercent || 20}" min="12" max="40" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                            <div style="grid-column:1 / span 2;">
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${overlayUiText('text_source')}</label>
                                <select id="setting-textOverlaySourceType" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="manual" ${(overlaySettings.textOverlaySourceType || 'manual') === 'manual' ? 'selected' : ''}>${overlayUiText('source_manual')}</option>
                                    <option value="collection" ${overlaySettings.textOverlaySourceType === 'collection' ? 'selected' : ''}>${overlayUiText('source_collection')}</option>
                                    <option value="external" ${overlaySettings.textOverlaySourceType === 'external' ? 'selected' : ''}>${overlayUiText('source_external')}</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${overlayUiText('font_size')}</label>
                                <input type="number" id="setting-textOverlayFontSize" value="${overlaySettings.textOverlayFontSize || 52}" min="18" max="120" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${overlayUiText('color')}</label>
                                <input type="color" id="setting-textOverlayColor" value="${overlaySettings.textOverlayColor || '#ffffff'}" style="width:100%; height:40px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                            <div style="grid-column:1 / span 2;">
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${overlayUiText('speed')}</label>
                                <input type="number" id="setting-textOverlaySpeedPxPerSec" value="${overlaySettings.textOverlaySpeedPxPerSec || 120}" min="40" max="320" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                            <div id="textOverlayManualWrap" style="grid-column:1 / span 2;">
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${overlayUiText('text')}</label>
                                <input type="text" id="setting-textOverlayText" value="${escapeHtml(overlaySettings.textOverlayText || 'Sem vložte text...')}" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                            <div id="textOverlayCollectionWrap" style="grid-column:1 / span 2;">
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${overlayUiText('collection')}</label>
                                <textarea id="setting-textOverlayCollection" rows="5" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px; resize:vertical;">${escapeHtml(overlayCollectionText)}</textarea>
                            </div>
                            <div id="textOverlayExternalWrap" style="grid-column:1 / span 2;">
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">${overlayUiText('external_url')}</label>
                                <input type="url" id="setting-textOverlayExternalUrl" value="${escapeHtml(overlaySettings.textOverlayExternalUrl || '')}" placeholder="https://..." style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        function getCustomizationModalWidthStyle(moduleKey) {
            return isWideCustomizationModule(moduleKey)
                ? 'max-width: 94vw; width: 94vw;'
                : 'max-width: 600px; width: 90%;';
        }

        function createCustomizationModalElement(item, index, formHtml, moduleKey) {
            const modal = document.createElement('div');
            modal.className = 'module-customization-modal';
            modal.style.cssText = `
                display: flex;
                position: fixed;
                z-index: 4000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                align-items: center;
                justify-content: center;
            `;

            const modalWidthStyle = getCustomizationModalWidthStyle(moduleKey);

            const modalModuleTitle = moduleKey === 'meal-menu'
                ? mealUiText('module_title')
                : resolveLoopItemModuleName(item);
            const modalCustomizationLabel = moduleKey === 'meal-menu'
                ? mealUiText('customization')
                : tr('group_loop.customization', 'Customization');

            modal.innerHTML = `
                <div style="
                    background: white;
                    padding: 30px;
                    border-radius: 12px;
                    ${modalWidthStyle}
                    max-height: 80vh;
                    overflow-y: auto;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                ">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="margin: 0;">⚙️ ${modalModuleTitle} - ${modalCustomizationLabel}</h2>
                        <button onclick="this.closest('div').parentElement.parentElement.remove()" style="
                            background: #1e40af;
                            color: white;
                            border: none;
                            font-size: 16px;
                            cursor: pointer;
                            width: 36px;
                            height: 36px;
                            border-radius: 50%;
                        ">✕</button>
                    </div>

                    ${formHtml}

                    <div style="margin-top: 30px; display: flex; gap: 10px; justify-content: flex-end;">
                        <button onclick="this.closest('div').parentElement.parentElement.remove()" style="
                            padding: 10px 20px;
                            background: #6c757d;
                            color: white;
                            border: none;
                            border-radius: 5px;
                            cursor: pointer;
                        ">${tr('common.cancel', 'Cancel')}</button>
                        <button onclick="saveCustomization(${index})" style="
                            padding: 10px 20px;
                            background: #28a745;
                            color: white;
                            border: none;
                            border-radius: 5px;
                            cursor: pointer;
                        ">${tr('common.save', 'Save')}</button>
                    </div>
                </div>
            `;

            return modal;
        }

        function initializeCustomizationModuleUi(moduleKey, item, settings) {
            if (moduleKey === 'pdf') {
                window.currentPdfBase64 = item.settings?.pdfDataBase64 || '';
                window.pdfModuleSettings = {
                    ...item.settings,
                    pdfDataBase64: window.currentPdfBase64
                };
                setTimeout(() => {
                    GroupLoopPdfModule.init();
                }, 0);
                return;
            }

            if (moduleKey === 'image-gallery' || moduleKey === 'gallery') {
                window.galleryModuleSettings = { ...item.settings };
                setTimeout(() => {
                    const galleryModuleApi =
                        window.GroupLoopGalleryModule ||
                        (typeof GroupLoopGalleryModule !== 'undefined' ? GroupLoopGalleryModule : null);

                    if (galleryModuleApi?.init) {
                        galleryModuleApi.init();
                    }
                }, 0);
                return;
            }

            if (moduleKey === 'video') {
                window.videoModuleSettings = {
                    videoAssetUrl: '',
                    videoAssetId: '',
                    videoDurationSec: parseInt(item.duration_seconds || 10, 10) || 10,
                    muted: true,
                    fitMode: 'contain',
                    bgColor: '#000000',
                    ...item.settings
                };
                setTimeout(() => {
                    const videoModuleApi =
                        window.GroupLoopVideoModule ||
                        (typeof GroupLoopVideoModule !== 'undefined' ? GroupLoopVideoModule : null);

                    if (videoModuleApi?.init) {
                        videoModuleApi.init();
                    }
                }, 0);
                return;
            }

            if (moduleKey === 'meal-menu') {
                bindMealModuleModalEvents(settings, item);
                return;
            }

            if (moduleKey === 'room-occupancy') {
                bindRoomOccupancyModuleModalEvents(settings);
            }
        }

        function collectClockSettingsFromForm() {
            const settings = {};
            settings.type = document.getElementById('setting-type')?.value || 'digital';
            settings.format = '24h';
            settings.dateFormat = document.getElementById('setting-dateFormat')?.value || 'full';
            settings.timeColor = document.getElementById('setting-timeColor')?.value || '#ffffff';
            settings.dateColor = document.getElementById('setting-dateColor')?.value || '#ffffff';
            settings.bgColor = document.getElementById('setting-bgColor')?.value || '#000000';
            settings.timeFontSize = parseInt(document.getElementById('setting-timeFontSize')?.value, 10) || parseInt(document.getElementById('setting-fontSize')?.value, 10) || 120;
            settings.dateFontSize = parseInt(document.getElementById('setting-dateFontSize')?.value, 10) || Math.max(16, Math.round(settings.timeFontSize * 0.3));
            settings.fontSize = settings.timeFontSize;
            settings.clockSize = parseInt(document.getElementById('setting-clockSize')?.value) || 300;
            settings.showSeconds = document.getElementById('setting-showSeconds')?.checked !== false;
            settings.showDate = document.getElementById('setting-showDate')?.checked !== false;
            settings.dateInline = document.getElementById('setting-dateInline')?.checked === true;
            settings.weekdayPosition = (document.getElementById('setting-weekdayPosition')?.value === 'right') ? 'right' : 'left';
            settings.digitalOverlayEnabled = document.getElementById('setting-digitalOverlayEnabled')?.checked === true;
            const overlayPositionValue = String(document.getElementById('setting-digitalOverlayPosition')?.value || 'auto').toLowerCase();
            settings.digitalOverlayPosition = ['auto', 'top', 'center', 'bottom'].includes(overlayPositionValue) ? overlayPositionValue : 'auto';
            if (!settings.showDate) {
                settings.dateFormat = 'none';
            }
            settings.language = document.getElementById('setting-language')?.value || 'sk';
            return settings;
        }

        function collectDefaultLogoSettings() {
            return {
                text: 'edudisplej.sk',
                fontSize: 120,
                textColor: '#ffffff',
                bgColor: '#000000',
                showVersion: false,
                version: ''
            };
        }

        function collectTextSettingsFromForm() {
            const settings = {};
            const richEditor = document.getElementById('text-editor-area');
            const sourceType = String(document.getElementById('setting-textSourceType')?.value || 'manual');
            const selectedCollectionId = parseInt(document.getElementById('setting-textCollectionId')?.value || '0', 10) || 0;
            const textExternalUrl = String(document.getElementById('setting-textExternalUrl')?.value || '').trim();
            const textHtml = sanitizeRichTextHtml(richEditor ? richEditor.innerHTML : (document.getElementById('setting-text')?.value || ''));
            const selectedCollection = sourceType === 'collection' ? getTextCollectionById(selectedCollectionId) : null;
            const isHtmlResourceUrl = (url) => /\.html(?:[?#].*)?$/i.test(String(url || '').trim());

            settings.textSourceType = ['manual', 'collection', 'external'].includes(sourceType) ? sourceType : 'manual';
            settings.textCollectionId = sourceType === 'collection' ? selectedCollectionId : 0;
            settings.textCollectionLabel = sourceType === 'collection' ? String(selectedCollection?.title || '') : '';
            settings.textCollectionVersionTs = sourceType === 'collection' ? Date.now() : 0;
            settings.textExternalUrl = sourceType === 'external' && isHtmlResourceUrl(textExternalUrl) ? textExternalUrl : '';

            if (sourceType === 'collection' && selectedCollection) {
                settings.text = sanitizeRichTextHtml(selectedCollection.content_html || '') || 'Sem vložte text...';
            } else {
                settings.text = textHtml;
            }
            settings.fontFamily = document.getElementById('setting-richFontFamily')?.value || 'Arial, sans-serif';
            settings.fontSize = Math.max(8, parseInt(document.getElementById('setting-richFontSize')?.value || '72', 10) || 72);
            settings.textSizingMode = String(document.getElementById('setting-textSizingMode')?.value || 'manual').toLowerCase() === 'fit' ? 'fit' : 'manual';
            settings.fontWeight = '700';
            settings.fontStyle = 'normal';
            settings.lineHeight = Math.max(0.8, Math.min(2.5, parseFloat(document.getElementById('setting-richLineHeight')?.value || '1.2') || 1.2));
            settings.textAlign = 'left';
            settings.textColor = '#ffffff';
            settings.bgColor = sourceType === 'collection' && selectedCollection
                ? (selectedCollection.bg_color || '#000000')
                : (document.getElementById('setting-bgColor')?.value || '#000000');
            settings.bgImageData = sourceType === 'collection' && selectedCollection
                ? String(selectedCollection.bg_image_data || '')
                : (document.getElementById('setting-bgImageData')?.value || '');
            settings.textAnimationEntry = document.getElementById('setting-textAnimationEntry')?.value || 'none';
            settings.scrollMode = document.getElementById('setting-scrollMode')?.checked === true;
            settings.scrollStartPauseMs = Math.round((parseFloat(document.getElementById('setting-scrollStartPauseSec')?.value || '3') || 3) * 1000);
            settings.scrollEndPauseMs = Math.round((parseFloat(document.getElementById('setting-scrollEndPauseSec')?.value || '3') || 3) * 1000);
            settings.scrollSpeedPxPerSec = parseInt(document.getElementById('setting-scrollSpeedPxPerSec')?.value, 10) || 35;
            settings.clockOverlayEnabled = hasClockModuleAvailable() && document.getElementById('setting-clockOverlayEnabled')?.checked === true;
            settings.clockOverlayPosition = 'bottom';
            settings.clockOverlayHeightPercent = 30;
            settings.clockOverlayTimeColor = document.getElementById('setting-clockOverlayTimeColor')?.value || '#ffffff';
            settings.clockOverlayDateColor = document.getElementById('setting-clockOverlayDateColor')?.value || '#ffffff';
            settings.clockOverlayClockSize = Math.max(100, Math.min(2000, parseInt(document.getElementById('setting-clockOverlayClockSize')?.value || '300', 10) || 300));
            const clockDateFormat = String(document.getElementById('setting-clockOverlayDateFormat')?.value || 'dmy').toLowerCase();
            settings.clockOverlayDateFormat = ['full', 'short', 'dmy', 'numeric', 'none'].includes(clockDateFormat) ? clockDateFormat : 'dmy';
            settings.clockOverlayShowYear = document.getElementById('setting-clockOverlayShowYear')?.checked !== false;
            const clockLanguage = String(document.getElementById('setting-clockOverlayLanguage')?.value || 'sk').toLowerCase();
            settings.clockOverlayLanguage = ['hu', 'sk', 'en'].includes(clockLanguage) ? clockLanguage : 'sk';
            settings.clockOverlayDatePosition = document.getElementById('setting-clockOverlayDatePosition')?.value === 'right' ? 'right' : 'below';
            settings.clockOverlayFontFamily = document.getElementById('setting-clockOverlayFontFamily')?.value || 'Arial, sans-serif';
            settings.clockOverlaySeparatorColor = document.getElementById('setting-clockOverlaySeparatorColor')?.value || '#22d3ee';
            settings.clockOverlaySeparatorThickness = Math.max(1, Math.min(8, parseInt(document.getElementById('setting-clockOverlaySeparatorThickness')?.value || '2', 10) || 2));

            if (!hasClockModuleAvailable()) {
                settings.clockOverlayEnabled = false;
            }
            return settings;
        }

        function collectPdfSettingsFromForm(item) {
            const settings = {};
            const pdfBase64 = window.pdfModuleSettings?.pdfDataBase64 || (item.settings?.pdfDataBase64 || '');
            const pdfAssetUrl = window.pdfModuleSettings?.pdfAssetUrl || (item.settings?.pdfAssetUrl || '');
            const pdfAssetId = window.pdfModuleSettings?.pdfAssetId || (item.settings?.pdfAssetId || '');
            const sectionsJson = document.getElementById('pdf-sections-json')?.value || '[]';

            settings.pdfAssetUrl = pdfAssetUrl;
            if (pdfAssetId) {
                settings.pdfAssetId = parseInt(pdfAssetId, 10) || String(pdfAssetId);
            }
            settings.pdfDataBase64 = pdfAssetUrl ? '' : pdfBase64;
            settings.zoomLevel = parseInt(document.getElementById('pdf-zoomLevel')?.value, 10) || 100;
            settings.horizontalStartPercent = Math.max(0, Math.min(100, parseInt(document.getElementById('pdf-horizontalStartPercentNumber')?.value, 10) || 0));
            settings.autoScrollEnabled = document.getElementById('pdf-autoScrollEnabled')?.checked === true;
            settings.autoScrollSpeedPxPerSec = parseInt(document.getElementById('pdf-scrollSpeed')?.value) || 30;
            settings.autoScrollStartPauseMs = parseInt(document.getElementById('pdf-startPause')?.value) || 2000;
            settings.autoScrollEndPauseMs = parseInt(document.getElementById('pdf-endPause')?.value) || 2000;
            settings.pauseAtPercent = parseInt(document.getElementById('pdf-pauseAtPercent')?.value, 10);
            if (!Number.isFinite(settings.pauseAtPercent)) {
                settings.pauseAtPercent = -1;
            }
            settings.pauseDurationMs = parseInt(document.getElementById('pdf-pauseDurationMs')?.value, 10) || 2000;
            settings.autoScrollSectionsJson = sectionsJson;
            return settings;
        }

        function collectGallerySettingsFromForm(item) {
            const rawJson = document.getElementById('gallery-image-urls-json')?.value || '[]';
            let normalizedImages = [];
            try {
                const parsed = JSON.parse(rawJson);
                normalizedImages = Array.isArray(parsed)
                    ? parsed.map(v => String(v || '').trim()).filter(Boolean).slice(0, 10)
                    : [];
            } catch (_) {
                normalizedImages = [];
            }

            const displayMode = document.getElementById('gallery-display-mode')?.value || 'slideshow';
            if (displayMode === 'single' && normalizedImages.length > 1) {
                normalizedImages = normalizedImages.slice(0, 1);
            }

            const rawSlideInterval = parseInt(document.getElementById('gallery-slide-interval')?.value, 10);
            const slideIntervalSec = Math.max(1, Math.min(30, Number.isFinite(rawSlideInterval) ? rawSlideInterval : 5));
            const transitionEnabled = document.getElementById('gallery-transition-enabled')?.checked !== false;
            const rawCollageColumns = parseInt(document.getElementById('gallery-collage-columns')?.value, 10);
            const collageColumns = Math.max(2, Math.min(5, Number.isFinite(rawCollageColumns) ? rawCollageColumns : 3));

            const settings = {
                imageUrlsJson: JSON.stringify(normalizedImages),
                displayMode,
                fitMode: document.getElementById('gallery-fit-mode')?.value || 'contain',
                slideIntervalSec,
                transitionEnabled,
                transitionMs: transitionEnabled ? 450 : 0,
                collageColumns,
                bgColor: document.getElementById('gallery-bg-color')?.value || '#000000'
            };

            return {
                settings,
                durationSeconds: getGalleryLoopDurationSeconds(settings, item.duration_seconds)
            };
        }

        function collectVideoSettingsFromForm(item) {
            const source = window.videoModuleSettings || item.settings || {};
            const videoDurationSec = Math.max(1, parseInt(source.videoDurationSec || item.duration_seconds || 10, 10) || 10);

            const settings = {
                videoAssetUrl: String(source.videoAssetUrl || ''),
                videoAssetId: source.videoAssetId ? parseInt(source.videoAssetId, 10) || String(source.videoAssetId) : '',
                videoDurationSec,
                muted: source.muted !== false,
                fitMode: ['contain', 'cover', 'fill'].includes(String(source.fitMode || 'contain'))
                    ? String(source.fitMode)
                    : 'contain',
                bgColor: String(source.bgColor || '#000000')
            };

            return {
                settings,
                durationSeconds: videoDurationSec
            };
        }

        function collectMealMenuSettingsFromForm() {
            const mealDisplayMode = normalizeMealDisplayMode(document.getElementById('setting-mealDisplayMode')?.value || 'small_screen');
            const smallScreenPageSwitchSec = Math.max(1, Math.min(120, parseInt(document.getElementById('setting-smallScreenPageSwitchSec')?.value || '12', 10) || 12));
            const smallScreenStartModeRaw = String(document.getElementById('setting-smallScreenStartMode')?.value || 'current_onward').toLowerCase();
            const smallScreenStartMode = ['current_onward', 'breakfast_onward', 'lunch_onward', 'dinner_onward'].includes(smallScreenStartModeRaw)
                ? smallScreenStartModeRaw
                : 'current_onward';
            const smallScreenMaxMeals = Math.max(1, Math.min(5, parseInt(document.getElementById('setting-smallScreenMaxMeals')?.value || '5', 10) || 5));
            const smallHeaderMarqueeSpeedPxPerSec = Math.max(8, Math.min(60, parseInt(document.getElementById('setting-smallHeaderMarqueeSpeedPxPerSec')?.value || '22', 10) || 22));
            const smallHeaderMarqueeEdgePauseMs = Math.max(200, Math.min(5000, parseInt(document.getElementById('setting-smallHeaderMarqueeEdgePauseMs')?.value || '1200', 10) || 1200));
            const smallRowFontPx = Math.max(60, Math.min(260, parseInt(document.getElementById('setting-smallRowFontPx')?.value || '150', 10) || 150));
            const smallScreenHeaderFontPx = Math.max(20, Math.min(120, parseInt(document.getElementById('setting-smallScreenHeaderFontPx')?.value || '40', 10) || 40));
            const smallHeaderRowTitleFontPx = Math.max(20, Math.min(140, parseInt(document.getElementById('setting-smallHeaderRowTitleFontPx')?.value || String(smallScreenHeaderFontPx), 10) || smallScreenHeaderFontPx));
            const smallHeaderRowClockFontPx = Math.max(20, Math.min(160, parseInt(document.getElementById('setting-smallHeaderRowClockFontPx')?.value || String(smallScreenHeaderFontPx), 10) || smallScreenHeaderFontPx));
            const mealTextFontSize = mealDisplayMode === 'large_screen' ? 1.9 : 3.0;
            const mealTitleFontSize = Math.max(0.8, Math.min(4, mealTextFontSize * 1.5));
            return {
                companyId: Math.max(0, parseInt(companyId || 0, 10) || 0),
                siteKey: String(document.getElementById('setting-mealSiteKey')?.value || 'jedalen.sk').trim() || 'jedalen.sk',
                institutionId: parseInt(document.getElementById('setting-mealInstitutionId')?.value || '0', 10) || 0,
                sourceType: (String(document.getElementById('setting-mealSourceType')?.value || 'manual').toLowerCase() === 'server') ? 'server' : 'manual',
                runtimeApiFetchEnabled: true,
                runtimeRefreshIntervalSec: 300,
                mealDisplayMode,
                smallScreenPageSwitchSec,
                smallScreenStartMode,
                smallScreenMaxMeals,
                smallHeaderMarqueeSpeedPxPerSec,
                smallHeaderMarqueeEdgePauseMs,
                smallRowFontPx,
                smallScreenHeaderFontPx,
                smallHeaderRowBgColor: String(document.getElementById('setting-smallHeaderRowBgColor')?.value || '#bae6fd').trim() || '#bae6fd',
                smallHeaderRowTextColor: String(document.getElementById('setting-smallHeaderRowTextColor')?.value || '#000000').trim() || '#000000',
                smallHeaderRowClockColor: String(document.getElementById('setting-smallHeaderRowClockColor')?.value || '#facc15').trim() || '#facc15',
                smallHeaderRowTitleFontPx,
                smallHeaderRowClockFontPx,
                mealScheduleEnabled: document.getElementById('setting-mealScheduleEnabled')?.checked !== false,
                scheduleBreakfastUntil: normalizeMealScheduleTime(document.getElementById('setting-scheduleBreakfastUntil')?.value, '10:00'),
                scheduleSnackAmUntil: normalizeMealScheduleTime(document.getElementById('setting-scheduleSnackAmUntil')?.value, '11:00'),
                scheduleLunchUntil: normalizeMealScheduleTime(document.getElementById('setting-scheduleLunchUntil')?.value, '14:00'),
                scheduleSnackPmUntil: normalizeMealScheduleTime(document.getElementById('setting-scheduleSnackPmUntil')?.value, '18:00'),
                scheduleDinnerUntil: normalizeMealScheduleTime(document.getElementById('setting-scheduleDinnerUntil')?.value, '23:59'),
                showTomorrowAfterMealPassed: document.getElementById('setting-showTomorrowAfterMealPassed')?.checked !== false,
                mergeBreakfastSnack: document.getElementById('setting-mergeBreakfastSnack')?.checked !== false,
                mergeLunchSnack: document.getElementById('setting-mergeLunchSnack')?.checked !== false,
                language: normalizeMealLanguage(document.getElementById('setting-mealLanguage')?.value || 'sk'),
                showHeaderTitle: document.getElementById('setting-showHeaderTitle')?.checked !== false,
                customHeaderTitle: String(document.getElementById('setting-customHeaderTitle')?.value || '').trim(),
                showInstitutionName: document.getElementById('setting-showInstitutionName')?.checked !== false,
                showBreakfast: document.getElementById('setting-showBreakfast')?.checked === true,
                showSnackAm: document.getElementById('setting-showSnackAm')?.checked === true,
                showLunch: document.getElementById('setting-showLunch')?.checked === true,
                showSnackPm: document.getElementById('setting-showSnackPm')?.checked === true,
                showDinner: document.getElementById('setting-showDinner')?.checked === true,
                showMealTypeEmojis: false,
                showMealTypeSvgIcons: document.getElementById('setting-showMealTypeSvgIcons')?.checked !== false,
                centerAlign: false,
                slowScrollOnOverflow: false,
                slowScrollSpeedPxPerSec: 40,
                fontFamily: 'Segoe UI, Tahoma, sans-serif',
                mealTitleFontSize,
                mealTextFontSize,
                textFontWeight: 700,
                lineHeight: 1.24,
                wrapText: true,
                showAppetiteMessage: document.getElementById('setting-showAppetiteMessage')?.checked === true,
                appetiteMessageText: String(document.getElementById('setting-appetiteMessageText')?.value || 'Prajeme dobrú chuť!').trim(),
                showSourceUrl: document.getElementById('setting-showSourceUrl')?.checked === true,
                sourceUrl: String(document.getElementById('setting-sourceUrl')?.value || '').trim(),
                apiBaseUrl: '../../api/meal_plan.php'
            };
        }

        function collectRoomOccupancySettingsFromForm() {
            const language = String(document.getElementById('setting-roomOccLanguage')?.value || resolveUiLang()).toLowerCase();
            return {
                roomId: parseInt(document.getElementById('setting-roomOccRoomId')?.value || '0', 10) || 0,
                showOnlyCurrent: document.getElementById('setting-roomOccShowOnlyCurrent')?.checked === true,
                showNextCount: Math.max(1, Math.min(12, parseInt(document.getElementById('setting-roomOccShowNextCount')?.value || '4', 10) || 4)),
                language: ['hu', 'sk', 'en'].includes(language) ? language : resolveUiLang(),
                runtimeRefreshIntervalSec: Math.max(30, Math.min(3600, parseInt(document.getElementById('setting-roomOccRefreshSec')?.value || '60', 10) || 60)),
                apiBaseUrl: '../../api/room_occupancy.php'
            };
        }

        function collectTurnedOffSettingsFromForm() {
            const rawMode = String(document.getElementById('setting-turnedOffMode')?.value || 'signal_off').toLowerCase();
            const mode = rawMode === 'black_screen' ? 'black_screen' : 'signal_off';
            return {
                screenOffMode: mode,
                screen_off_mode: mode
            };
        }
        
        function saveCustomization(index) {
            if (isDefaultGroup) {
                return;
            }

            const item = loopItems[index];
            const moduleKey = item.module_key || getModuleKeyById(item.module_id);
            
            const newSettings = {};
            
            // Collect all settings from form
            if (moduleKey === 'clock') {
                Object.assign(newSettings, collectClockSettingsFromForm());
            } else if (moduleKey === 'default-logo') {
                Object.assign(newSettings, collectDefaultLogoSettings());
            } else if (moduleKey === 'text') {
                Object.assign(newSettings, collectTextSettingsFromForm());
            } else if (moduleKey === 'pdf') {
                Object.assign(newSettings, collectPdfSettingsFromForm(item));
            } else if (moduleKey === 'image-gallery' || moduleKey === 'gallery') {
                const galleryResult = collectGallerySettingsFromForm(item);
                Object.assign(newSettings, galleryResult.settings);
                loopItems[index].duration_seconds = galleryResult.durationSeconds;
            } else if (moduleKey === 'video') {
                const videoResult = collectVideoSettingsFromForm(item);
                Object.assign(newSettings, videoResult.settings);
                loopItems[index].duration_seconds = videoResult.durationSeconds;
            } else if (moduleKey === 'meal-menu') {
                Object.assign(newSettings, collectMealMenuSettingsFromForm());
            } else if (moduleKey === 'room-occupancy') {
                Object.assign(newSettings, collectRoomOccupancySettingsFromForm());
            } else if (moduleKey === 'turned-off') {
                Object.assign(newSettings, collectTurnedOffSettingsFromForm());
            }

            if (isOverlayCarrierModule(moduleKey)) {
                const withOverlay = collectOverlaySettingsFromForm({
                    ...(item.settings || {}),
                    ...newSettings
                });
                Object.assign(newSettings, withOverlay);
            }

            if (moduleKey === 'meal-menu' && !isMealLargeScreenMode(newSettings.mealDisplayMode)) {
                newSettings.clockOverlayEnabled = false;
                newSettings.textOverlayEnabled = false;
            }
            
            loopItems[index].settings = newSettings;
            
            // Close customization modal immediately
            document.querySelectorAll('.module-customization-modal').forEach((el) => el.remove());
            
            showAutosaveToast('✓ Beállítások mentve');
            scheduleAutoSave(250);
            renderLoop();
        }
        
        // ===== LIVE PREVIEW FUNCTIONS =====

        function clearPreviewPlaybackTimers() {
            clearTimeout(previewTimeout);
            clearInterval(previewInterval);
        }

        function updatePreviewLoopCountDisplay() {
            document.getElementById('loopCount').textContent = loopCycleCount;
        }

        function updatePreviewModuleInfo(module) {
            document.getElementById('currentModule').textContent = `${currentPreviewIndex + 1}. ${resolveLoopItemModuleName(module)}`;
            document.getElementById('navInfo').textContent = `${currentPreviewIndex + 1} / ${loopItems.length}`;
        }

        function loadPreviewModuleIntoIframe(moduleUrl) {
            const iframe = document.getElementById('previewIframe');
            const emptyDiv = document.getElementById('previewEmpty');

            iframe.src = moduleUrl;
            iframe.style.display = 'block';
            emptyDiv.style.display = 'none';
            syncLoopPreviewIframeScale();
        }

        function advancePreviewIndexForward() {
            currentPreviewIndex++;
            if (currentPreviewIndex >= loopItems.length) {
                currentPreviewIndex = 0;
                loopCycleCount++;
                totalLoopStartTime = Date.now();
                updatePreviewLoopCountDisplay();
            }
        }

        function advancePreviewIndexBackward() {
            currentPreviewIndex--;
            if (currentPreviewIndex < 0) {
                currentPreviewIndex = loopItems.length - 1;
                if (loopCycleCount > 0) {
                    loopCycleCount--;
                }
            }
            updatePreviewLoopCountDisplay();
        }

        function scheduleNextPreviewModule(durationSeconds) {
            previewTimeout = setTimeout(() => {
                advancePreviewIndexForward();
                playCurrentModule();
            }, durationSeconds * 1000);
        }
        
        function startPreview() {
            if (loopItems.length === 0) {
                alert('⚠️ Nincs modul a loop-ban!');
                return;
            }
            
            stopPreview(); // Clear any existing preview
            isPaused = false;
            currentPreviewIndex = 0;
            loopCycleCount = 0;
            totalLoopStartTime = Date.now();
            
            document.getElementById('btnPlay').style.display = 'none';
            document.getElementById('btnPause').style.display = 'inline-block';
            document.getElementById('loopStatus').textContent = 'Lejátszás...';
            
            playCurrentModule();
        }
        
        function pausePreview() {
            if (isPaused) {
                // Resume
                isPaused = false;
                document.getElementById('btnPause').innerHTML = '⏸️ Szünet';
                document.getElementById('loopStatus').textContent = 'Lejátszás...';
                playCurrentModule();
            } else {
                // Pause
                isPaused = true;
                document.getElementById('btnPause').innerHTML = '▶️ Folytatás';
                document.getElementById('loopStatus').textContent = 'Szüneteltetve';
                clearPreviewPlaybackTimers();
            }
        }
        
        function stopPreview() {
            isPaused = false;
            currentPreviewIndex = 0;
            loopCycleCount = 0;

            clearPreviewPlaybackTimers();
            
            document.getElementById('btnPlay').style.display = 'inline-block';
            document.getElementById('btnPause').style.display = 'none';
            document.getElementById('loopStatus').textContent = 'Leállítva';
            document.getElementById('currentModule').textContent = '—';
            document.getElementById('progressBar').style.width = '0%';
            document.getElementById('progressText').textContent = '0s / 0s';
            document.getElementById('loopCount').textContent = '0';
            document.getElementById('navInfo').textContent = '—';
            
            // Hide iframe, show empty message
            document.getElementById('previewIframe').style.display = 'none';
            document.getElementById('previewEmpty').style.display = 'block';
        }
        
        function previousModule() {
            if (loopItems.length === 0) return;

            clearPreviewPlaybackTimers();
            advancePreviewIndexBackward();
            playCurrentModule();
        }
        
        function nextModule() {
            if (loopItems.length === 0) return;

            clearPreviewPlaybackTimers();
            currentPreviewIndex++;
            if (currentPreviewIndex >= loopItems.length) {
                currentPreviewIndex = 0;
                loopCycleCount++;
            }
            updatePreviewLoopCountDisplay();
            playCurrentModule();
        }
        
        function playCurrentModule() {
            if (isPaused) return;
            
            // Ha nincs elem a loop-ban, ne fusson
            if (loopItems.length === 0) {
                stopPreview();
                return;
            }
            
            // Ha csak 1 elem van, akkor is loopoljon
            const module = loopItems[currentPreviewIndex];
            const duration = parseInt(module.duration_seconds) || 10;

            updatePreviewModuleInfo(module);
            
            // Build module URL with settings
            const moduleUrl = buildModuleUrl(module);

            loadPreviewModuleIntoIframe(moduleUrl);
            
            // Start progress bar
            currentModuleStartTime = Date.now();
            updateProgressBar(duration);

            // MINDIG schedule-ölj következő modult (még 1 elem esetén is loop)
            scheduleNextPreviewModule(duration);
        }
        
        function updateProgressBar(duration) {
            clearInterval(previewInterval);
            
            const totalDuration = getTotalLoopDuration();
            
            previewInterval = setInterval(() => {
                if (isPaused) return;
                
                const elapsedInLoop = getElapsedTimeInLoop();
                const percentage = Math.min((elapsedInLoop / totalDuration) * 100, 100);
                
                document.getElementById('progressBar').style.width = percentage + '%';
                document.getElementById('progressText').textContent = `${Math.floor(elapsedInLoop)}s / ${totalDuration}s`;
                
                if (elapsedInLoop >= totalDuration) {
                    clearInterval(previewInterval);
                }
            }, 100);
        }
        
        function createPdfDataPreviewKey(pdfDataBase64) {
            const key = `pdf_preview_${Date.now()}_${Math.floor(Math.random() * 100000)}`;
            window.__edudisplejPdfPreviewStore = window.__edudisplejPdfPreviewStore || {};
            window.__edudisplejPdfPreviewStore[key] = String(pdfDataBase64 || '');

            const keys = Object.keys(window.__edudisplejPdfPreviewStore);
            if (keys.length > 30) {
                keys.slice(0, keys.length - 30).forEach((staleKey) => {
                    delete window.__edudisplejPdfPreviewStore[staleKey];
                });
            }

            return key;
        }

        function appendClockPreviewParams(params, settings) {
            if (settings.type) params.append('type', settings.type);
            if (settings.format) params.append('format', settings.format);
            if (settings.dateFormat) params.append('dateFormat', settings.dateFormat);
            if (settings.dateInline !== undefined) params.append('dateInline', settings.dateInline);
            if (settings.weekdayPosition) params.append('weekdayPosition', settings.weekdayPosition);
            if (settings.timeColor) params.append('timeColor', settings.timeColor);
            if (settings.dateColor) params.append('dateColor', settings.dateColor);
            if (settings.bgColor) params.append('bgColor', settings.bgColor);
            if (settings.fontSize) params.append('fontSize', settings.fontSize);
            if (settings.timeFontSize) params.append('timeFontSize', settings.timeFontSize);
            if (settings.dateFontSize) params.append('dateFontSize', settings.dateFontSize);
            if (settings.clockSize) params.append('clockSize', settings.clockSize);
            if (settings.showSeconds !== undefined) params.append('showSeconds', settings.showSeconds);
            if (settings.showDate !== undefined) params.append('showDate', settings.showDate);
            if (settings.language) params.append('language', settings.language);
            if (settings.digitalOverlayEnabled !== undefined) params.append('digitalOverlayEnabled', settings.digitalOverlayEnabled);
            if (settings.digitalOverlayPosition) params.append('digitalOverlayPosition', settings.digitalOverlayPosition);
        }

        function appendDefaultLogoPreviewParams(params) {
            params.append('text', 'edudisplej.sk');
            params.append('showVersion', 'false');
        }

        function appendTextPreviewParams(params, settings, module) {
            if (settings.textSourceType) params.append('textSourceType', settings.textSourceType);
            if (settings.textCollectionId !== undefined) params.append('textCollectionId', settings.textCollectionId);
            if (settings.textCollectionLabel) params.append('textCollectionLabel', settings.textCollectionLabel);
            if (settings.textCollectionVersionTs !== undefined) params.append('textCollectionVersionTs', settings.textCollectionVersionTs);
            if (settings.textExternalUrl) params.append('textExternalUrl', settings.textExternalUrl);
            params.append('text', settings.text || '');
            params.append('durationSeconds', String(parseInt(module.duration_seconds || 10, 10) || 10));
            if (settings.fontFamily) params.append('fontFamily', settings.fontFamily);
            if (settings.fontSize) params.append('fontSize', settings.fontSize);
            params.append('textSizingMode', String(settings.textSizingMode || '').toLowerCase() === 'fit' ? 'fit' : 'manual');
            if (settings.fontWeight) params.append('fontWeight', settings.fontWeight);
            if (settings.fontStyle) params.append('fontStyle', settings.fontStyle);
            if (settings.lineHeight) params.append('lineHeight', settings.lineHeight);
            if (settings.textAlign) params.append('textAlign', settings.textAlign);
            if (settings.textColor) params.append('textColor', settings.textColor);
            if (settings.bgColor) params.append('bgColor', settings.bgColor);
            if (settings.textAnimationEntry) params.append('textAnimationEntry', settings.textAnimationEntry);
            if (settings.scrollMode !== undefined) params.append('scrollMode', settings.scrollMode);
            if (settings.scrollStartPauseMs !== undefined) params.append('scrollStartPauseMs', settings.scrollStartPauseMs);
            if (settings.scrollEndPauseMs !== undefined) params.append('scrollEndPauseMs', settings.scrollEndPauseMs);
            if (settings.scrollSpeedPxPerSec !== undefined) params.append('scrollSpeedPxPerSec', settings.scrollSpeedPxPerSec);
            if (hasClockModuleAvailable() && settings.clockOverlayEnabled === true) {
                params.append('clockOverlayEnabled', 'true');
                params.append('clockOverlayPosition', 'bottom');
                params.append('clockOverlayHeightPercent', '30');
                params.append('clockOverlayTimeColor', settings.clockOverlayTimeColor || '#ffffff');
                params.append('clockOverlayDateColor', settings.clockOverlayDateColor || '#ffffff');
                params.append('clockOverlayClockSize', String(Math.max(100, Math.min(2000, parseInt(settings.clockOverlayClockSize, 10) || 300))));
                params.append('clockOverlayDateFormat', ['full', 'short', 'dmy', 'numeric', 'none'].includes(String(settings.clockOverlayDateFormat || '').toLowerCase()) ? String(settings.clockOverlayDateFormat).toLowerCase() : 'dmy');
                params.append('clockOverlayShowYear', settings.clockOverlayShowYear === false ? 'false' : 'true');
                params.append('clockOverlayLanguage', ['hu', 'sk', 'en'].includes(String(settings.clockOverlayLanguage || '').toLowerCase()) ? String(settings.clockOverlayLanguage).toLowerCase() : 'sk');
                params.append('clockOverlayDatePosition', String(settings.clockOverlayDatePosition || '').toLowerCase() === 'right' ? 'right' : 'below');
                params.append('clockOverlayFontFamily', String(settings.clockOverlayFontFamily || 'Arial, sans-serif'));
                params.append('clockOverlaySeparatorColor', String(settings.clockOverlaySeparatorColor || '#22d3ee'));
                params.append('clockOverlaySeparatorThickness', String(Math.max(1, Math.min(8, parseInt(settings.clockOverlaySeparatorThickness, 10) || 2))));
            }
            if (settings.bgImageData) {
                try {
                    const storageKey = `text_bg_${Date.now()}_${Math.floor(Math.random() * 100000)}`;
                    localStorage.setItem(storageKey, settings.bgImageData);
                    params.append('bgImageStorageKey', storageKey);
                } catch (error) {
                    params.append('bgImageData', settings.bgImageData);
                }
            }
        }

        function appendPdfPreviewParams(params, settings) {
            if (settings.pdfAssetUrl) {
                params.append('pdfAssetUrl', settings.pdfAssetUrl);
            } else if (settings.pdfDataBase64) {
                const dataKey = createPdfDataPreviewKey(settings.pdfDataBase64);
                params.append('pdfDataKey', dataKey);
            }
            if (settings.zoomLevel !== undefined) params.append('zoomLevel', settings.zoomLevel);
            if (settings.horizontalStartPercent !== undefined) params.append('horizontalStartPercent', settings.horizontalStartPercent);

            const autoScrollEnabled = settings.autoScrollEnabled === true || settings.navigationMode === 'auto';
            params.append('autoScrollEnabled', autoScrollEnabled ? 'true' : 'false');
            if (settings.autoScrollSpeedPxPerSec !== undefined) params.append('autoScrollSpeedPxPerSec', settings.autoScrollSpeedPxPerSec);
            if (settings.autoScrollStartPauseMs !== undefined) params.append('autoScrollStartPauseMs', settings.autoScrollStartPauseMs);
            if (settings.autoScrollEndPauseMs !== undefined) params.append('autoScrollEndPauseMs', settings.autoScrollEndPauseMs);
            if (settings.autoScrollSectionsJson !== undefined) params.append('autoScrollSectionsJson', settings.autoScrollSectionsJson);

            if (settings.pauseAtPercent !== undefined) {
                params.append('pauseAtPercent', settings.pauseAtPercent);
            } else {
                params.append('pauseAtPercent', '-1');
            }

            if (settings.pauseDurationMs !== undefined) {
                params.append('pauseDurationMs', settings.pauseDurationMs);
            }
        }

        function appendGalleryPreviewParams(params, settings) {
            params.append('imageUrlsJson', settings.imageUrlsJson || '[]');
            params.append('displayMode', settings.displayMode || 'slideshow');
            params.append('fitMode', settings.fitMode || 'contain');
            params.append('slideIntervalSec', settings.slideIntervalSec || 5);
            params.append('transitionEnabled', settings.transitionEnabled === false ? 'false' : 'true');
            params.append('transitionMs', settings.transitionEnabled === false ? 0 : 450);
            params.append('collageColumns', settings.collageColumns || 3);
            params.append('bgColor', settings.bgColor || '#000000');
        }

        function appendVideoPreviewParams(params, settings) {
            if (settings.videoAssetUrl) params.append('videoAssetUrl', settings.videoAssetUrl);
            params.append('muted', settings.muted === false ? 'false' : 'true');
            params.append('fitMode', settings.fitMode || 'contain');
            params.append('bgColor', settings.bgColor || '#000000');
        }

        function appendMealMenuPreviewParams(params, settings) {
            const mealTextFontSize = Math.max(0.8, Math.min(4, parseFloat(settings.mealTextFontSize || 1.85) || 1.85));
            const mealTitleFontSize = Math.max(0.8, Math.min(4, mealTextFontSize * 1.5));
            const smallScreenPageSwitchSec = Math.max(1, Math.min(120, parseInt(settings.smallScreenPageSwitchSec || 12, 10) || 12));
            const smallScreenStartModeRaw = String(settings.smallScreenStartMode || 'current_onward').toLowerCase();
            const smallScreenStartMode = ['current_onward', 'breakfast_onward', 'lunch_onward', 'dinner_onward'].includes(smallScreenStartModeRaw)
                ? smallScreenStartModeRaw
                : 'current_onward';
            const smallScreenMaxMeals = Math.max(1, Math.min(5, parseInt(settings.smallScreenMaxMeals || 5, 10) || 5));
            const smallHeaderMarqueeSpeedPxPerSec = Math.max(8, Math.min(60, parseInt(settings.smallHeaderMarqueeSpeedPxPerSec || 22, 10) || 22));
            const smallHeaderMarqueeEdgePauseMs = Math.max(200, Math.min(5000, parseInt(settings.smallHeaderMarqueeEdgePauseMs || 1200, 10) || 1200));
            const smallRowFontPx = Math.max(60, Math.min(260, parseInt(settings.smallRowFontPx || 150, 10) || 150));
            const smallScreenHeaderFontPx = Math.max(20, Math.min(120, parseInt(settings.smallScreenHeaderFontPx || 40, 10) || 40));
            const smallHeaderRowTitleFontPx = Math.max(20, Math.min(140, parseInt(settings.smallHeaderRowTitleFontPx || smallScreenHeaderFontPx, 10) || smallScreenHeaderFontPx));
            const smallHeaderRowClockFontPx = Math.max(20, Math.min(160, parseInt(settings.smallHeaderRowClockFontPx || smallScreenHeaderFontPx, 10) || smallScreenHeaderFontPx));
            const mealDisplayMode = normalizeMealDisplayMode(settings.mealDisplayMode);
            const language = normalizeMealLanguage(settings.language);
            params.append('siteKey', String(settings.siteKey || 'jedalen.sk'));
            params.append('institutionId', String(parseInt(settings.institutionId || 0, 10) || 0));
            params.append('mealDisplayMode', mealDisplayMode);
            params.append('smallScreenPageSwitchSec', String(smallScreenPageSwitchSec));
            params.append('smallScreenStartMode', smallScreenStartMode);
            params.append('smallScreenMaxMeals', String(smallScreenMaxMeals));
            params.append('smallHeaderMarqueeSpeedPxPerSec', String(smallHeaderMarqueeSpeedPxPerSec));
            params.append('smallHeaderMarqueeEdgePauseMs', String(smallHeaderMarqueeEdgePauseMs));
            params.append('smallRowFontPx', String(smallRowFontPx));
            params.append('smallScreenHeaderFontPx', String(smallScreenHeaderFontPx));
            params.append('smallHeaderRowBgColor', String(settings.smallHeaderRowBgColor || '#bae6fd'));
            params.append('smallHeaderRowTextColor', String(settings.smallHeaderRowTextColor || '#000000'));
            params.append('smallHeaderRowClockColor', String(settings.smallHeaderRowClockColor || '#facc15'));
            params.append('smallHeaderRowTitleFontPx', String(smallHeaderRowTitleFontPx));
            params.append('smallHeaderRowClockFontPx', String(smallHeaderRowClockFontPx));
            params.append('smallScreenShowOperator', settings.smallScreenShowOperator === false ? 'false' : 'true');
            params.append('smallScreenShowDate', settings.smallScreenShowDate === false ? 'false' : 'true');
            params.append('smallScreenShowCaptions', settings.smallScreenShowCaptions === false ? 'false' : 'true');
            params.append('mergeBreakfastSnack', settings.mergeBreakfastSnack === false ? 'false' : 'true');
            params.append('mergeLunchSnack', settings.mergeLunchSnack === false ? 'false' : 'true');
            params.append('language', language);
            params.append('showHeaderTitle', settings.showHeaderTitle === false ? 'false' : 'true');
            params.append('customHeaderTitle', String(settings.customHeaderTitle || ''));
            params.append('showInstitutionName', settings.showInstitutionName === false ? 'false' : 'true');
            params.append('showBreakfast', settings.showBreakfast === false ? 'false' : 'true');
            params.append('showSnackAm', settings.showSnackAm === false ? 'false' : 'true');
            params.append('showLunch', settings.showLunch === false ? 'false' : 'true');
            params.append('showSnackPm', settings.showSnackPm === true ? 'true' : 'false');
            params.append('showDinner', settings.showDinner === true ? 'true' : 'false');
            params.append('showMealTypeSvgIcons', settings.showMealTypeSvgIcons === false ? 'false' : 'true');
            params.append('centerAlign', settings.centerAlign === true ? 'true' : 'false');
            params.append('slowScrollOnOverflow', settings.slowScrollOnOverflow === true ? 'true' : 'false');
            params.append('slowScrollSpeedPxPerSec', String(Math.max(8, Math.min(120, parseInt(settings.slowScrollSpeedPxPerSec || 28, 10) || 28))));
            params.append('mealTitleFontSize', String(mealTitleFontSize));
            params.append('mealTextFontSize', String(mealTextFontSize));
            params.append('textFontWeight', String(parseInt(settings.textFontWeight || 600, 10) || 600));
            params.append('lineHeight', String(Math.max(1, Math.min(2.2, parseFloat(settings.lineHeight || 1.4) || 1.4))));
            params.append('fontFamily', String(settings.fontFamily || 'Segoe UI, Tahoma, sans-serif'));
            params.append('showAppetiteMessage', settings.showAppetiteMessage === true ? 'true' : 'false');
            params.append('appetiteMessageText', String(settings.appetiteMessageText || ''));
            params.append('showSourceUrl', settings.showSourceUrl === true ? 'true' : 'false');
            params.append('sourceUrl', String(settings.sourceUrl || ''));
            params.append('apiBaseUrl', settings.apiBaseUrl || '../../api/meal_plan.php');
            params.append('runtimeApiFetchEnabled', 'true');
            params.append('runtimeRefreshIntervalSec', String(Math.max(60, Math.min(3600, parseInt(settings.runtimeRefreshIntervalSec || 300, 10) || 300))));
            if (companyId > 0) {
                params.append('company_id', String(companyId));
            }
        }

        function appendRoomOccupancyPreviewParams(params, settings) {
            params.append('roomId', String(parseInt(settings.roomId || 0, 10) || 0));
            params.append('showOnlyCurrent', settings.showOnlyCurrent === true ? 'true' : 'false');
            params.append('showNextCount', String(Math.max(1, Math.min(12, parseInt(settings.showNextCount || 4, 10) || 4))));
            const language = ['hu', 'sk', 'en'].includes(String(settings.language || '').toLowerCase()) ? String(settings.language).toLowerCase() : resolveUiLang();
            params.append('language', language);
            params.append('runtimeRefreshIntervalSec', String(Math.max(30, Math.min(3600, parseInt(settings.runtimeRefreshIntervalSec || 60, 10) || 60))));
            params.append('apiBaseUrl', settings.apiBaseUrl || '../../api/room_occupancy.php');
            if (companyId > 0) {
                params.append('company_id', String(companyId));
            }
        }

        function buildModuleUrl(module) {
            const moduleKey = module.module_key || getModuleKeyById(module.module_id);
            const settings = module.settings || {};

            let baseUrl = '';
            const params = new URLSearchParams();

            switch(moduleKey) {
                case 'clock':
                    baseUrl = '../../modules/clock/m_clock.html';
                    appendClockPreviewParams(params, settings);
                    break;

                case 'default-logo':
                    baseUrl = '../../modules/default/m_default.html';
                    appendDefaultLogoPreviewParams(params);
                    break;

                case 'text':
                    baseUrl = '../../modules/text/m_text.html';
                    appendTextPreviewParams(params, settings, module);
                    break;

                case 'pdf':
                    baseUrl = '../../modules/pdf/m_pdf.html';
                    appendPdfPreviewParams(params, settings);
                    break;

                case 'image-gallery':
                case 'gallery':
                    baseUrl = '../../modules/gallery/m_gallery.html';
                    appendGalleryPreviewParams(params, settings);
                    break;

                case 'video':
                    baseUrl = '../../modules/video/m_video.html';
                    appendVideoPreviewParams(params, settings);
                    break;

                case 'meal-menu':
                    baseUrl = '../../modules/meal-menu/m_meal_menu.html';
                    appendMealMenuPreviewParams(params, settings);
                    break;

                case 'room-occupancy':
                    baseUrl = '../../modules/room-occupancy/m_room_occupancy.html';
                    appendRoomOccupancyPreviewParams(params, settings);
                    break;

                case 'turned-off':
                    baseUrl = '../../modules/default/m_default.html';
                    params.append('text', '⏻ Turned Off');
                    params.append('bgColor', '#111111');
                    break;

                default:
                    baseUrl = '../../modules/default/m_default.html';
                    params.append('text', resolveLoopItemModuleName(module));
                    params.append('bgColor', '#1a3a52');
            }

            appendOverlayParams(params, settings, moduleKey);

            const queryString = params.toString();
            return queryString ? `${baseUrl}?${queryString}` : baseUrl;
        }

        function formatLanguageCode(language) {
            const code = String(language || 'sk').toLowerCase();
            if (code === 'hu') return 'HU';
            if (code === 'sk') return 'SK';
            if (code === 'en') return 'EN';
            return code.toUpperCase();
        }

        function normalizeGalleryImageUrls(rawValue) {
            let source = rawValue;

            if (Array.isArray(source)) {
                return source.map(v => String(v || '').trim()).filter(Boolean).slice(0, 10);
            }

            if (source && typeof source === 'object' && Array.isArray(source.imageUrls)) {
                return source.imageUrls.map(v => String(v || '').trim()).filter(Boolean).slice(0, 10);
            }

            if (typeof source === 'string') {
                const text = String(source || '').trim();
                if (!text) {
                    return [];
                }

                try {
                    const parsed = JSON.parse(text);
                    if (Array.isArray(parsed)) {
                        return parsed.map(v => String(v || '').trim()).filter(Boolean).slice(0, 10);
                    }
                } catch (_) {
                    const split = text.split(',').map(v => String(v || '').trim()).filter(Boolean);
                    if (split.length > 1) {
                        return split.slice(0, 10);
                    }
                }
            }

            return [];
        }

        function getGalleryModeFromSettings(settings) {
            const mode = String(settings?.displayMode || 'slideshow');
            return ['slideshow', 'collage', 'single'].includes(mode) ? mode : 'slideshow';
        }

        function getGalleryLoopDurationSeconds(settings, fallbackDuration) {
            const slideRaw = parseInt(settings?.slideIntervalSec || 5, 10);
            const slideIntervalSec = Math.max(1, Math.min(30, Number.isFinite(slideRaw) ? slideRaw : 5));
            const images = normalizeGalleryImageUrls(settings?.imageUrlsJson ?? settings?.imageUrls ?? []);
            const mode = getGalleryModeFromSettings(settings);

            if (mode === 'slideshow') {
                return Math.max(1, slideIntervalSec * Math.max(1, images.length));
            }

            const fallback = parseInt(fallbackDuration || 0, 10);
            if (Number.isFinite(fallback) && fallback > 0) {
                return fallback;
            }
            return slideIntervalSec;
        }

        function getClockLoopItemSummary(settings) {
            const type = settings.type === 'analog' ? clockUiText('type_analog') : clockUiText('type_digital');
            const details = [type];
            const dateInlineEnabled = settings.dateInline === true || String(settings.dateInline) === 'true';

            if ((settings.type || 'digital') !== 'analog') {
                details.push('24h');
            }

            if (dateInlineEnabled) {
                details.push(settings.weekdayPosition === 'right' ? clockUiText('summary_date_day') : clockUiText('summary_day_date'));
            }

            const language = formatLanguageCode(settings.language);
            return `${details.join(' • ')}<br>${clockUiText('language_label')} ${language}`;
        }

        function getTextLoopItemSummary(settings) {
            const lang = resolveUiLang();
            const labelMap = {
                hu: {
                    module: 'Szöveg modul',
                    align: { left: 'balra', center: 'középre', right: 'jobbra' },
                    bold: 'félkövér',
                    scroll: 'gördítés',
                    bgImage: 'háttérkép'
                },
                sk: {
                    module: 'Textový modul',
                    align: { left: 'vľavo', center: 'na stred', right: 'vpravo' },
                    bold: 'tučné',
                    scroll: 'rolovanie',
                    bgImage: 'obrázok pozadia'
                },
                en: {
                    module: 'Text module',
                    align: { left: 'left', center: 'center', right: 'right' },
                    bold: 'bold',
                    scroll: 'scroll',
                    bgImage: 'background image'
                }
            };
            const labels = labelMap[lang] || labelMap.en;
            const snippet = String(settings.text || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            const baseText = snippet ? snippet.slice(0, 48) : labels.module;

            const details = [];
            if (settings.fontSize) {
                details.push(`${parseInt(settings.fontSize, 10) || 72}px`);
            }
            if (settings.textAlign) {
                const alignMap = labels.align;
                details.push(alignMap[String(settings.textAlign)] || String(settings.textAlign));
            }
            if (settings.fontWeight && String(settings.fontWeight) !== '400') {
                details.push(labels.bold);
            }
            if (settings.scrollMode) {
                details.push(labels.scroll);
            }
            if (String(settings.bgImageData || '').trim()) {
                details.push(labels.bgImage);
            }

            return details.length > 0
                ? `${baseText}<br>${details.join(' • ')}`
                : baseText;
        }

        function getGalleryLoopItemSummary(settings) {
            const lang = resolveUiLang();
            const labels = {
                hu: {
                    image: 'kép',
                    mode: 'Mód',
                    slideshow: 'slideshow',
                    collage: 'kollázs',
                    single: 'egy kép',
                    overlay: 'Overlay',
                    clock: 'óra',
                    text: 'szöveg',
                    top: 'fent',
                    bottom: 'lent'
                },
                sk: {
                    image: 'obrázok',
                    mode: 'Režim',
                    slideshow: 'prezentácia',
                    collage: 'koláž',
                    single: 'jeden obrázok',
                    overlay: 'Overlay',
                    clock: 'hodiny',
                    text: 'text',
                    top: 'hore',
                    bottom: 'dole'
                },
                en: {
                    image: 'image',
                    mode: 'Mode',
                    slideshow: 'slideshow',
                    collage: 'collage',
                    single: 'single image',
                    overlay: 'Overlay',
                    clock: 'clock',
                    text: 'text',
                    top: 'top',
                    bottom: 'bottom'
                }
            }[lang] || {
                image: 'image', mode: 'Mode', slideshow: 'slideshow', collage: 'collage', single: 'single image',
                overlay: 'Overlay', clock: 'clock', text: 'text', top: 'top', bottom: 'bottom'
            };
            const galleryImages = normalizeGalleryImageUrls(settings.imageUrlsJson ?? settings.imageUrls ?? []);
            const imageCount = galleryImages.length;

            const modeMap = {
                slideshow: labels.slideshow,
                collage: labels.collage,
                single: labels.single
            };
            const mode = modeMap[getGalleryModeFromSettings(settings)] || 'slideshow';
            const overlayFlags = [];
            if (settings.clockOverlayEnabled) {
                overlayFlags.push(`${labels.clock}:${settings.clockOverlayPosition === 'bottom' ? labels.bottom : labels.top}`);
            }
            if (settings.textOverlayEnabled) {
                overlayFlags.push(`${labels.text}:${settings.textOverlayPosition === 'bottom' ? labels.bottom : labels.top}`);
            }
            const overlayLine = overlayFlags.length ? `<br>${labels.overlay}: ${overlayFlags.join(' • ')}` : '';
            return `${imageCount} ${labels.image}<br>${labels.mode}: ${mode}${overlayLine}`;
        }

        function getVideoLoopItemSummary(item, settings) {
            const vt = videoUiText;
            const duration = parseInt(settings.videoDurationSec || item.duration_seconds || 0, 10);
            const fit = String(settings.fitMode || 'contain');
            const muted = settings.muted === false ? vt('summary_unmuted') : vt('summary_muted');
            if (duration > 0) {
                return `${duration}s • ${fit}<br>${muted}`;
            }
            return `${vt('summary_video')} • ${fit}<br>${muted}`;
        }

        function getMealMenuLoopItemSummary(settings) {
            const mt = (key) => mealUiText(key);
            const siteKey = String(settings.siteKey || 'jedalen.sk').trim() || 'jedalen.sk';
            const institutionId = parseInt(settings.institutionId || 0, 10) || 0;
            const language = ['hu', 'sk', 'en'].includes(String(settings.language || '').toLowerCase()) ? String(settings.language).toLowerCase() : 'sk';
            const visibleMeals = [];
            if (settings.showBreakfast !== false) visibleMeals.push(mt('breakfast'));
            if (settings.showSnackAm !== false) visibleMeals.push(mt('snack_am'));
            if (settings.showLunch !== false) visibleMeals.push(mt('lunch'));
            if (settings.showSnackPm === true) visibleMeals.push(mt('snack_pm'));
            if (settings.showDinner === true) visibleMeals.push(mt('dinner'));

            const mealsText = visibleMeals.length > 0 ? visibleMeals.join(', ') : mt('no_meals_selected');
            const iconText = ` • ${settings.showMealTypeSvgIcons === false ? mt('icons_off') : mt('icons_on')}`;
            return `${siteKey} • ${mt('institution_short')} #${institutionId} • ${mt('language_short')}: ${language.toUpperCase()}<br>${mealsText}${iconText}`;
        }

        function getRoomOccupancyLoopItemSummary(settings) {
            const roomId = parseInt(settings.roomId || 0, 10) || 0;
            const onlyCurrent = settings.showOnlyCurrent === true ? roomOccUiText('summary_only_current') : roomOccUiText('summary_daily_list');
            const nextCount = Math.max(1, Math.min(12, parseInt(settings.showNextCount || 4, 10) || 4));
            const language = ['hu', 'sk', 'en'].includes(String(settings.language || '').toLowerCase()) ? String(settings.language).toUpperCase() : resolveUiLang().toUpperCase();
            return `${roomOccUiText('summary_room')} #${roomId}<br>${onlyCurrent} • ${roomOccUiText('summary_next')}: ${nextCount} • ${roomOccUiText('summary_lang')}: ${language}`;
        }

        function getLoopItemSummary(item) {
            if (!item) {
                return '';
            }

            const moduleKey = item.module_key || getModuleKeyById(item.module_id);
            const settings = item.settings || {};

            if (moduleKey === 'clock') {
                return getClockLoopItemSummary(settings);
            }

            if (moduleKey === 'default-logo') {
                return `EDUdisplej.sk`;
            }

            if (moduleKey === 'text') {
                return getTextLoopItemSummary(settings);
            }

            if (moduleKey === 'image-gallery' || moduleKey === 'gallery') {
                return getGalleryLoopItemSummary(settings);
            }

            if (moduleKey === 'video') {
                return getVideoLoopItemSummary(item, settings);
            }

            if (moduleKey === 'meal-menu') {
                return getMealMenuLoopItemSummary(settings);
            }

            if (moduleKey === 'room-occupancy') {
                return getRoomOccupancyLoopItemSummary(settings);
            }

            if (moduleKey === 'turned-off') {
                const rawMode = String(settings.screenOffMode || settings.screen_off_mode || 'signal_off').toLowerCase();
                const modeLabel = rawMode === 'black_screen'
                    ? loopUiText('turned_off_mode_black_screen')
                    : loopUiText('turned_off_mode_signal_off');
                return `${loopUiText('turned_off_period')}<br>${modeLabel}`;
            }

            return '';
        }

        function getLoopItemModuleKey(item) {
            return item?.module_key || getModuleKeyById(item?.module_id);
        }

        function getLoopItemDurationValue(item, moduleKey, isTechnicalItem, isGalleryItem) {
            if (isTechnicalItem) {
                return 60;
            }

            if (String(moduleKey || '').toLowerCase() === 'turned-off') {
                return TURNED_OFF_LOOP_DURATION_SECONDS;
            }

            if (isGalleryItem) {
                return getGalleryLoopDurationSeconds(item?.settings || {}, item?.duration_seconds);
            }

            return parseInt(item?.duration_seconds || 10, 10);
        }

        function buildLoopItemDurationInputHtml(index, durationValue, durationBounds, flags) {
            const { isTechnicalItem, isVideoItem, isGalleryItem, isTurnedOffItem } = flags;
            const isReadOnly = isDefaultGroup || isTechnicalItem || isContentOnlyMode || isVideoItem || isGalleryItem || isTurnedOffItem;

            if (isReadOnly) {
                return `<input type="number" value="${durationValue}" min="${durationBounds.min}" max="${durationBounds.max}" step="${durationBounds.step}" disabled>`;
            }

            return `<input type="number" value="${durationValue}" min="${durationBounds.min}" max="${durationBounds.max}" step="${durationBounds.step}" onchange="updateDuration(${index}, this.value)" onkeydown="if (event.key === 'Enter') { event.preventDefault(); updateDuration(${index}, this.value); this.blur(); }" onclick="event.stopPropagation()">`;
        }

        function buildLoopItemActionButtonsHtml(index) {
            if (isDefaultGroup) {
                return `<button class="loop-btn" disabled title="${loopActionUiText('default_group_locked')}">🔒</button>`;
            }

            if (isContentOnlyMode) {
                return `<button class="loop-btn" onclick="customizeModule(${index}); event.stopPropagation();" title="${loopActionUiText('customize')}">⚙️</button>`;
            }

            return `<button class="loop-btn" onclick="customizeModule(${index}); event.stopPropagation();" title="${loopActionUiText('customize')}">⚙️</button>
                        <button class="loop-btn" onclick="duplicateLoopItem(${index}); event.stopPropagation();" title="${loopActionUiText('duplicate')}">📄</button>
                        <button class="loop-btn" onclick="removeFromLoop(${index}); event.stopPropagation();" title="${loopActionUiText('delete')}">🗑️</button>`;
        }

        function createLoopItemElement(item, index) {
            const loopItem = document.createElement('div');
            loopItem.className = 'loop-item';
            loopItem.draggable = !isDefaultGroup && !isContentOnlyMode;
            loopItem.dataset.index = index;

            const isTechnicalItem = isTechnicalLoopItem(item);
            const moduleKey = getLoopItemModuleKey(item);
            const isVideoItem = moduleKey === 'video';
            const isGalleryItem = moduleKey === 'image-gallery' || moduleKey === 'gallery';
            const isTurnedOffItem = moduleKey === 'turned-off';
            const durationBounds = getDurationBoundsForModule(moduleKey);
            const durationValue = getLoopItemDurationValue(item, moduleKey, isTechnicalItem, isGalleryItem);

            if (isGalleryItem) {
                item.duration_seconds = durationValue;
            }

            const durationInputHtml = buildLoopItemDurationInputHtml(index, durationValue, durationBounds, {
                isTechnicalItem,
                isVideoItem,
                isGalleryItem,
                isTurnedOffItem,
            });

            const actionButtonsHtml = buildLoopItemActionButtonsHtml(index);

            loopItem.innerHTML = `
                    <div class="loop-order">${index + 1}</div>
                    <div class="loop-details">
                        <div class="loop-module-name">${resolveLoopItemModuleName(item)}</div>
                        <div class="loop-module-desc">${getLoopItemSummary(item)}</div>
                    </div>
                    <div class="loop-duration">
                        ${durationInputHtml}
                        <span class="loop-duration-suffix">s</span>
                    </div>
                    <div class="loop-actions">
                        ${actionButtonsHtml}
                    </div>
                `;

            if (!isDefaultGroup && !isContentOnlyMode) {
                loopItem.addEventListener('dragstart', handleDragStart);
                loopItem.addEventListener('dragover', handleDragOver);
                loopItem.addEventListener('drop', handleDrop);
                loopItem.addEventListener('dragend', handleDragEnd);
            }

            return loopItem;
        }
        
        // Update preview when loop changes
        function renderLoop() {
            const container = document.getElementById('loop-container');
            updateTechnicalModuleVisibility();
            
            if (loopItems.length === 0) {
                container.className = 'empty';
                container.innerHTML = '<p>Nincs elem a loop-ban. Húzz ide modult az „Elérhető Modulok” panelről.</p>';
                updateTotalDuration();
                stopPreview(); // Stop preview if loop is empty
                return;
            }
            
            container.className = '';
            container.innerHTML = '';
            
            loopItems.forEach((item, index) => {
                const loopItem = createLoopItemElement(item, index);
                container.appendChild(loopItem);
            });
            
            updateTotalDuration();
            if (loopItems.length > 0) {
                startPreview();
            }
        }

        function normalizeTimeInputValue(preferred = '08:00') {
            const normalized = String(preferred || '').slice(0, 5);
            if (/^([01]\d|2[0-3]):[0-5]\d$/.test(normalized)) {
                return normalized;
            }
            return '08:00';
        }

        function set24HourTimeSelectValue(inputId, preferred = '08:00') {
            const inputEl = document.getElementById(inputId);
            if (!inputEl) {
                return;
            }

            const normalized = normalizeTimeInputValue(preferred);

            if (String(inputEl.tagName || '').toLowerCase() === 'input') {
                inputEl.type = 'text';
                inputEl.setAttribute('inputmode', 'numeric');
                inputEl.setAttribute('placeholder', 'HH:MM');
                inputEl.setAttribute('maxlength', '5');
                inputEl.setAttribute('pattern', '^([01]\\d|2[0-3]):[0-5]\\d$');
                inputEl.value = normalized;
                return;
            }

            const values = [];
            for (let minute = 0; minute < 24 * 60; minute += 15) {
                values.push(minutesToTimeLabel(minute));
            }

            inputEl.innerHTML = '';

            values.forEach((value) => {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = value;
                inputEl.appendChild(option);
            });

            if (normalized && !values.includes(normalized)) {
                const extra = document.createElement('option');
                extra.value = normalized;
                extra.textContent = normalized;
                inputEl.appendChild(extra);
            }

            inputEl.value = normalized && inputEl.querySelector(`option[value="${normalized}"]`)
                ? normalized
                : values[0];
        }

        function buildFixedPlanTimeOptions() {
            set24HourTimeSelectValue('fixed-plan-start', document.getElementById('fixed-plan-start')?.value || '08:00');
            set24HourTimeSelectValue('fixed-plan-end', document.getElementById('fixed-plan-end')?.value || '10:00');
            set24HourTimeSelectValue('special-start-input', document.getElementById('special-start-input')?.value || '08:00');
            set24HourTimeSelectValue('special-end-input', document.getElementById('special-end-input')?.value || '10:00');
        }
        
        // Load loop on page load
        buildFixedPlanTimeOptions();
        loadLoop();

        function toggleGroupNameEdit(enable) {
            const display = document.getElementById('group-name-display');
            const editBtn = document.getElementById('group-name-edit-btn');
            const editWrap = document.getElementById('group-name-edit-wrap');
            const input = document.getElementById('rename-group-inline-input');

            if (!display || !editBtn || !editWrap || !input) {
                return;
            }

            if (enable) {
                display.style.display = 'none';
                editBtn.style.display = 'none';
                editWrap.style.display = 'inline';
                input.focus();
                input.select();
            } else {
                display.style.display = 'inline';
                editBtn.style.display = 'inline';
                editWrap.style.display = 'none';
                input.value = display.textContent || input.value;
            }
        }

        document.addEventListener('keydown', function (event) {
            const editWrap = document.getElementById('group-name-edit-wrap');
            const input = document.getElementById('rename-group-inline-input');
            if (!editWrap || !input || editWrap.style.display !== 'inline') {
                if (event.key === 'Escape') {
                    cancelScheduleBlockResize();
                    cancelScheduleRangeSelection();
                }
                return;
            }

            if (event.key === 'Enter') {
                event.preventDefault();
                renameCurrentGroup();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                toggleGroupNameEdit(false);
            }
        });

        document.addEventListener('mouseup', function () {
            if (scheduleBlockResize) {
                finishScheduleBlockResize();
                return;
            }
            if (!scheduleRangeSelection) {
                return;
            }
            finishScheduleRangeSelection();
        });

        window.addEventListener('beforeunload', function (event) {
            if (!isDraftDirty) {
                return;
            }
            event.preventDefault();
            event.returnValue = '';
        });

        function renameCurrentGroup() {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const input = document.getElementById('rename-group-inline-input');
            if (!input) {
                return;
            }

            const newName = String(input.value || '').trim();
            if (!newName) {
                alert('⚠️ Adj meg egy csoportnevet.');
                return;
            }

            const formData = new FormData();
            formData.append('group_id', String(groupId));
            formData.append('new_name', newName);

            fetch('../../api/rename_group.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    alert('⚠️ ' + (data.message || 'Átnevezési hiba'));
                    return;
                }
                const display = document.getElementById('group-name-display');
                if (display) {
                    display.textContent = newName;
                }
                toggleGroupNameEdit(false);
            })
            .catch(() => {
                alert('⚠️ Átnevezési hiba.');
            });
        }
        
        function resolutionAspectLabel(resolution) {
            const [width, height] = String(resolution || '').split('x').map(Number);
            if (!width || !height) {
                return '';
            }
            const gcd = (a, b) => b ? gcd(b, a % b) : a;
            const divisor = gcd(width, height);
            return `${width / divisor}:${height / divisor}`;
        }

        function buildGroupResolutionChoices(kiosks) {
            const counts = new Map();
            (Array.isArray(kiosks) ? kiosks : []).forEach((kiosk) => {
                const value = String(kiosk?.screen_resolution || '').trim();
                if (!/^\d+x\d+$/i.test(value)) {
                    return;
                }
                counts.set(value, (counts.get(value) || 0) + 1);
            });

            const ranked = Array.from(counts.entries())
                .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
                .map(([value, count]) => ({
                    value,
                    count,
                    label: `${value} (${resolutionAspectLabel(value)}) • ${count} ${localizedDisplayUnit(count)}`
                }));

            return ranked;
        }

        // Load group display resolutions and populate the selector
        function loadGroupResolutions() {
            fetch(`../../api/get_group_kiosks.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    const selector = document.getElementById('resolutionSelector');
                    if (!selector) {
                        return;
                    }

                    selector.innerHTML = '';
                    groupResolutionChoices = [];
                    groupDefaultResolution = '1920x1080';

                    if (data.success && data.kiosks && data.kiosks.length > 0) {
                        groupResolutionChoices = buildGroupResolutionChoices(data.kiosks);
                        if (groupResolutionChoices.length > 0) {
                            groupDefaultResolution = groupResolutionChoices[0].value;
                            groupResolutionChoices.forEach((entry) => {
                                const option = document.createElement('option');
                                option.value = entry.value;
                                option.textContent = entry.label;
                                selector.appendChild(option);
                            });

                            const separator = document.createElement('option');
                            separator.disabled = true;
                            separator.textContent = '──────────────────';
                            selector.appendChild(separator);
                        }
                        
                        // Add standard resolutions
                        const standardResolutions = [
                            { value: '1920x1080', label: '1920x1080 (16:9 Full HD)' },
                            { value: '1280x720', label: '1280x720 (16:9 HD)' },
                            { value: '1024x768', label: '1024x768 (4:3 XGA)' },
                            { value: '1600x900', label: '1600x900 (16:9 HD+)' },
                            { value: '1366x768', label: '1366x768 (16:9 WXGA)' }
                        ];
                        
                        standardResolutions.forEach(res => {
                            const option = document.createElement('option');
                            option.value = res.value;
                            option.textContent = res.label;
                            selector.appendChild(option);
                        });
                    } else {
                        const fallbackResolutions = [
                            { value: '1920x1080', label: '1920x1080 (16:9 Full HD)' },
                            { value: '1366x768', label: '1366x768 (16:9 WXGA)' },
                            { value: '1280x720', label: '1280x720 (16:9 HD)' },
                            { value: '1024x768', label: '1024x768 (4:3 XGA)' }
                        ];
                        fallbackResolutions.forEach((res) => {
                            const option = document.createElement('option');
                            option.value = res.value;
                            option.textContent = res.label;
                            selector.appendChild(option);
                        });
                    }

                    if (!selector.querySelector(`option[value="${groupDefaultResolution}"]`)) {
                        groupDefaultResolution = selector.querySelector('option:not([disabled])')?.value || '1920x1080';
                    }
                    selector.value = groupDefaultResolution;

                    updatePreviewResolution();
                })
                .catch(err => {
                    console.error('Error loading group resolutions:', err);
                });
        }

        function fitPreviewScreen(width, height) {
            const previewScreen = document.getElementById('previewScreen');
            const previewPanel = document.querySelector('.preview-panel');
            const previewCol = document.querySelector('.loop-workspace-preview');

            if (!previewScreen || !previewPanel || !previewCol || !width || !height) {
                return;
            }

            const panelStyle = window.getComputedStyle(previewPanel);
            const panelPaddingX = (parseFloat(panelStyle.paddingLeft) || 0) + (parseFloat(panelStyle.paddingRight) || 0);

            const maxWidth = Math.max(180, previewPanel.clientWidth - panelPaddingX - 4);

            let occupiedHeight = 0;
            Array.from(previewPanel.children).forEach((child) => {
                if (child === previewScreen) {
                    return;
                }
                const childStyle = window.getComputedStyle(child);
                const marginTop = parseFloat(childStyle.marginTop) || 0;
                const marginBottom = parseFloat(childStyle.marginBottom) || 0;
                occupiedHeight += child.offsetHeight + marginTop + marginBottom;
            });

            const viewportMaxHeight = Math.max(260, window.innerHeight - 30);
            const panelPaddingY = (parseFloat(panelStyle.paddingTop) || 0) + (parseFloat(panelStyle.paddingBottom) || 0);
            const availableHeight = Math.max(120, viewportMaxHeight - panelPaddingY - occupiedHeight - 12);
            const ratio = width / height;

            let boxWidth = maxWidth;
            let boxHeight = boxWidth / ratio;

            if (boxHeight > availableHeight) {
                boxHeight = availableHeight;
                boxWidth = boxHeight * ratio;
            }

            previewScreen.style.width = `${Math.round(boxWidth)}px`;
            previewScreen.style.height = `${Math.round(boxHeight)}px`;
            previewScreen.style.aspectRatio = `${width} / ${height}`;
        }

        function syncLoopPreviewIframeScale() {
            const selector = document.getElementById('resolutionSelector');
            const previewScreen = document.getElementById('previewScreen');
            const iframe = document.getElementById('previewIframe');
            if (!selector || !previewScreen || !iframe) {
                return;
            }

            const resolution = String(selector.value || groupDefaultResolution || '1920x1080');
            const [widthRaw, heightRaw] = resolution.split('x').map((v) => parseInt(v, 10));
            const deviceWidth = Math.max(320, widthRaw || 1920);
            const deviceHeight = Math.max(180, heightRaw || 1080);

            const availableWidth = Math.max(120, previewScreen.clientWidth);
            const availableHeight = Math.max(80, previewScreen.clientHeight);
            const scale = Math.max(0.05, Math.min(availableWidth / deviceWidth, availableHeight / deviceHeight));

            iframe.style.position = 'absolute';
            iframe.style.left = '50%';
            iframe.style.top = '50%';
            iframe.style.width = `${deviceWidth}px`;
            iframe.style.height = `${deviceHeight}px`;
            iframe.style.transform = `translate(-50%, -50%) scale(${scale})`;
            iframe.style.transformOrigin = 'center center';
        }
        
        // Update preview resolution
        function updatePreviewResolution() {
            const selector = document.getElementById('resolutionSelector');
            const resolution = selector.value;
            const [width, height] = resolution.split('x').map(Number);
            
            if (width && height) {
                fitPreviewScreen(width, height);
                requestAnimationFrame(syncLoopPreviewIframeScale);
            }
        }

        window.addEventListener('resize', () => {
            updatePreviewResolution();
        });
        
        reorderPrimaryPanels();
        bindModuleCatalogInteractions();
        initMobileQuickCampaignDefaults();

        // Load resolutions on page load
        loadGroupResolutions();




