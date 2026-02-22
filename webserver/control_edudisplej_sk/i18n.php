<?php
/**
 * Simple i18n helper
 * Stores user language in session + users.lang
 */
require_once __DIR__ . '/dbkonfiguracia.php';

define('EDUDISPLEJ_DEFAULT_LANG', 'sk');
define('EDUDISPLEJ_I18N_OVERRIDES_DIR', __DIR__ . '/lang');

$EDUDISPLEJ_SUPPORTED_LANGS = ['hu', 'en', 'sk'];

$EDUDISPLEJ_TRANSLATIONS = [
    'hu' => [
        'app.title' => 'EduDisplej Control',
        'nav.kiosks' => 'Kijelzok',
        'nav.groups' => 'Csoportok',
        'nav.modules' => 'Modulok',
        'nav.profile' => 'Intézmény',
        'nav.settings' => 'Beállítások',
        'nav.translations' => 'Fordítások',
        'nav.admin' => 'Admin',
        'nav.logout' => 'Kilepes',
        'lang.label' => 'Nyelv',
        'lang.hu' => 'Magyar',
        'lang.en' => 'Angol',
        'lang.sk' => 'Szlovak',
        'session.timeout.warning' => '10 perc inaktivitas utan a munkamenet lejar. Ujra be kell jelentkezned.',
        'login.title' => 'Bejelentkezes - EduDisplej Control',
        'login.heading' => 'Bejelentkezes',
        'login.subheading' => 'Bejelentkezes a vezeto pulthoz',
        'login.email' => 'Email',
        'login.email_placeholder' => 'Add meg az emailed',
        'login.password' => 'Jelszo',
        'login.password_placeholder' => 'Add meg a jelszot',
        'login.otp' => 'Kettos azonosito kod',
        'login.otp_placeholder' => 'Add meg a 6 jegyu kodot',
        'login.otp_help' => 'Ird be az alkalmazas altal generalt 6 jegyu kodot',
        'login.remember' => 'Emlkezz ram',
        'login.submit' => 'Bejelentkezes',
        'login.no_account' => 'Nincs fiokod?',
        'login.create_account' => 'Uj fiok letrehozasa',
        'login.registered' => 'Sikeres regisztracio! Jelentkezz be a fiokoddal.',
        'login.info_title' => 'Bejelentkezesi informacio',
        'login.info_admin' => 'Az adminok az Admin feluletre lesznek iranyitva',
        'login.info_user' => 'A felhasznalok a dashboardot fogjak elerni',
        'login.info_last_login' => 'Az utolso bejelentkezes rogzitve lesz',
        'login.otp_required' => 'Ketfaktoros kod kotelezo',
        'login.error.required' => 'Email es jelszo kotelezo',
        'login.error.auth_state' => 'Hibas hitelesitesi allapot. Probald ujra.',
        'login.error.session_expired' => 'A munkamenet lejart. Probald ujra.',
        'login.error.invalid_otp' => 'Hibas ketfaktoros kod',
        'login.error.failed' => 'A bejelentkezes sikertelen. Probald ujra kesobb.',
        'login.invalid' => 'Hibas email vagy jelszo',
        'dashboard.title' => 'Sajat kijelzok',
        'dashboard.total' => 'Osszes',
        'dashboard.online' => 'Online',
        'dashboard.offline' => 'Offline',
        'dashboard.company_displays' => 'Az intezmenyhez rendelt osszes kijelzo',
        'dashboard.all_groups' => 'Minden csoport',
        'dashboard.none_assigned' => 'Nincs kijelzo hozzarendelve az intezmenyhez',
        'dashboard.no_company' => 'Nincs intezmeny hozzarendelve vagy nincs hozzaferes az adatokhoz.',
        'dashboard.header.id' => 'ID',
        'dashboard.header.hostname' => 'Hostname',
        'dashboard.header.status' => 'Statusz',
        'dashboard.header.group' => 'Csoport',
        'dashboard.header.preview' => 'Elonezet',
        'dashboard.header.location' => 'Hely',
        'dashboard.header.last_sync' => 'Utolso szinkronizalas',
        'dashboard.header.loop' => 'Loop',
        'dashboard.status.online' => 'Online',
        'dashboard.status.offline' => 'Offline',
        'dashboard.screenshot.none' => 'Nincs kep',
        'dashboard.screenshot.no_fresh' => 'Nincs friss kep',
        'dashboard.screenshot.time' => 'Kep ideje',
        'dashboard.screenshot.time_unknown' => 'Kep ideje: ismeretlen',
        'dashboard.sync.never' => 'Soha',
        'dashboard.loop.loading' => 'Betoltes...',
        'dashboard.loop.none' => 'Nincs loop',
        'dashboard.loop.error' => 'Loop hiba',
        'dashboard.loop.no_data' => 'Nincs elerheto loop adat',
        'dashboard.loop.info_time' => 'Loop info ideje',
        'dashboard.loop.modal_title' => 'Loop Konfiguracio',
        'dashboard.loop.module_order' => 'Modul sorrend',
        'dashboard.loop.total_duration' => 'Teljes loop idotartam',
        'dashboard.loop.seconds' => 'masodperc',
        'dashboard.loop.minutes_short' => 'perc',
        'dashboard.loop.seconds_short' => 'mp',
        'dashboard.toggle.list_view' => 'Lista Nezet',
        'dashboard.toggle.realtime_view' => 'Realtime Nezet',
        'dashboard.assign_group_missing' => 'Valassz csoportot',
        'dashboard.error' => 'Hiba tortent',
        'dashboard.loop.preview' => 'Loop',
        'dashboard.modal.kiosk_details' => 'Kijelzo Reszletek',
        'dashboard.modal.close' => 'Bezaras',
        'dashboard.loop.play' => 'Lejatszas',
        'dashboard.loop.pause' => 'Szunet',
        'dashboard.loop.stop' => 'Stop',
        'dashboard.loop.module' => 'Modul',
        'dashboard.loop.cycle' => 'Ciklus',
        'dashboard.error.no_company_assigned' => 'Nincs intezmeny hozzarendelve. Vedd fel a kapcsolatot az adminnal.',
        'dashboard.sync_interval_confirm' => 'Biztosan beallitod a szinkronizalasi idokozot {seconds} masodpercre?',
        'dashboard.sync_interval_updated' => 'Szinkronizalasi idokoz frissitve',
        'dashboard.sync_interval_label' => 'Szinkronizalasi idokoz: {seconds} masodperc',
        'dashboard.screenshot.enabled' => 'Bekapcsolva',
        'dashboard.screenshot.disabled' => 'Kikapcsolva',
        'dashboard.screenshot.waiting' => 'Meg nincs kepernyokep feltoltve. Varj a kovetkezo szinkronizalasig.',
        'dashboard.screenshot.off' => 'A kepernyokep funkcio ki van kapcsolva. Kapcsold be a kepernyokepek fogadasahoz.',
        'dashboard.screenshot.toggled' => 'Kepernyokep funkcio: {state}',
        'dashboard.screenshot.unavailable' => 'Screenshot nem elerheto',
        'dashboard.screenshot.loading' => 'Varakozas a kepernyokepre',
        'dashboard.screen.on' => 'Bekapcsolva',
        'dashboard.screen.off' => 'Kikapcsolva',
        'profile.assets.title' => '🗂️ Modul tárhely (céges)',
        'profile.assets.subtitle' => 'A céghez tartozó feltöltött modul fájlok modulonként csoportosítva.',
        'profile.assets.empty' => 'Még nincs feltöltött modul asset.',
        'profile.assets.latest_uploads' => 'Legutóbbi 25 feltöltés',
        'profile.assets.col.time' => 'Idő',
        'profile.assets.col.module' => 'Modul',
        'profile.assets.col.file' => 'Fájl',
        'profile.assets.col.kind' => 'Típus',
        'profile.assets.col.count' => 'Darab',
        'profile.assets.col.size' => 'Méret',
        'profile.assets.col.last_upload' => 'Utolsó feltöltés',
        'profile.assets.col.storage_path' => 'Tárolási útvonal'
    ],
    'en' => [
        'app.title' => 'EduDisplej Control',
        'nav.kiosks' => 'Displays',
        'nav.groups' => 'Groups',
        'nav.modules' => 'Modules',
        'nav.profile' => 'Institution',
        'nav.settings' => 'Settings',
        'nav.translations' => 'Translations',
        'nav.admin' => 'Admin',
        'nav.logout' => 'Log out',
        'lang.label' => 'Language',
        'lang.hu' => 'Hungarian',
        'lang.en' => 'English',
        'lang.sk' => 'Slovak',
        'session.timeout.warning' => 'Session expired after 10 minutes of inactivity. Please sign in again.',
        'login.title' => 'Login - EduDisplej Control',
        'login.heading' => 'Sign in',
        'login.subheading' => 'Access the control panel',
        'login.email' => 'Email',
        'login.email_placeholder' => 'Enter your email',
        'login.password' => 'Password',
        'login.password_placeholder' => 'Enter your password',
        'login.otp' => 'Two-factor code',
        'login.otp_placeholder' => 'Enter the 6-digit code',
        'login.otp_help' => 'Enter the 6-digit code from your authenticator app',
        'login.remember' => 'Remember me',
        'login.submit' => 'Sign in',
        'login.no_account' => "Don't have an account?",
        'login.create_account' => 'Create new account',
        'login.registered' => 'Registration successful! Please log in with your credentials.',
        'login.info_title' => 'Login Information',
        'login.info_admin' => 'Admins will be redirected to the Admin Portal',
        'login.info_user' => 'Regular users will access the Dashboard',
        'login.info_last_login' => 'Your last login will be recorded',
        'login.otp_required' => 'Two-factor code required',
        'login.error.required' => 'Email and password are required',
        'login.error.auth_state' => 'Invalid authentication state. Please try again.',
        'login.error.session_expired' => 'Authentication session expired. Please try again.',
        'login.error.invalid_otp' => 'Invalid two-factor authentication code',
        'login.error.failed' => 'Login failed. Please try again later.',
        'login.invalid' => 'Invalid email or password',
        'dashboard.title' => 'My displays',
        'dashboard.total' => 'Total',
        'dashboard.online' => 'Online',
        'dashboard.offline' => 'Offline',
        'dashboard.company_displays' => 'All displays assigned to your institution',
        'dashboard.all_groups' => 'All groups',
        'dashboard.none_assigned' => 'No displays assigned to your institution',
        'dashboard.no_company' => 'No institution assigned or no access to data.',
        'dashboard.header.id' => 'ID',
        'dashboard.header.hostname' => 'Hostname',
        'dashboard.header.status' => 'Status',
        'dashboard.header.group' => 'Group',
        'dashboard.header.preview' => 'Preview',
        'dashboard.header.location' => 'Location',
        'dashboard.header.last_sync' => 'Last sync',
        'dashboard.header.loop' => 'Loop',
        'dashboard.status.online' => 'Online',
        'dashboard.status.offline' => 'Offline',
        'dashboard.screenshot.none' => 'No image',
        'dashboard.screenshot.no_fresh' => 'No fresh image',
        'dashboard.screenshot.time' => 'Image time',
        'dashboard.screenshot.time_unknown' => 'Image time: unknown',
        'dashboard.sync.never' => 'Never',
        'dashboard.loop.loading' => 'Loading...',
        'dashboard.loop.none' => 'No loop',
        'dashboard.loop.error' => 'Loop error',
        'dashboard.loop.no_data' => 'No loop data available',
        'dashboard.loop.info_time' => 'Loop info time',
        'dashboard.loop.modal_title' => 'Loop configuration',
        'dashboard.loop.module_order' => 'Module order',
        'dashboard.loop.total_duration' => 'Total loop duration',
        'dashboard.loop.seconds' => 'seconds',
        'dashboard.loop.minutes_short' => 'min',
        'dashboard.loop.seconds_short' => 'sec',
        'dashboard.toggle.list_view' => 'List view',
        'dashboard.toggle.realtime_view' => 'Realtime view',
        'dashboard.assign_group_missing' => 'Select a group',
        'dashboard.error' => 'An error occurred',
        'dashboard.loop.preview' => 'Loop',
        'dashboard.modal.kiosk_details' => 'Display details',
        'dashboard.modal.close' => 'Close',
        'dashboard.loop.play' => 'Play',
        'dashboard.loop.pause' => 'Pause',
        'dashboard.loop.stop' => 'Stop',
        'dashboard.loop.module' => 'Module',
        'dashboard.loop.cycle' => 'Cycle',
        'dashboard.error.no_company_assigned' => 'No institution assigned. Please contact an administrator.',
        'dashboard.sync_interval_confirm' => 'Set sync interval to {seconds} seconds?',
        'dashboard.sync_interval_updated' => 'Sync interval updated',
        'dashboard.sync_interval_label' => 'Sync interval: {seconds} seconds',
        'dashboard.screenshot.enabled' => 'Enabled',
        'dashboard.screenshot.disabled' => 'Disabled',
        'dashboard.screenshot.waiting' => 'No screenshot yet. Wait for the next sync.',
        'dashboard.screenshot.off' => 'Screenshot feature is disabled. Turn it on to receive screenshots.',
        'dashboard.screenshot.toggled' => 'Screenshot feature: {state}',
        'dashboard.screenshot.unavailable' => 'Screenshot not available',
        'dashboard.screenshot.loading' => 'Waiting for screenshot',
        'dashboard.screen.on' => 'On',
        'dashboard.screen.off' => 'Off',
        'profile.assets.title' => '🗂️ Module storage (company)',
        'profile.assets.subtitle' => 'Uploaded module files for this company grouped by module.',
        'profile.assets.empty' => 'No uploaded module assets yet.',
        'profile.assets.latest_uploads' => 'Latest 25 uploads',
        'profile.assets.col.time' => 'Time',
        'profile.assets.col.module' => 'Module',
        'profile.assets.col.file' => 'File',
        'profile.assets.col.kind' => 'Type',
        'profile.assets.col.count' => 'Count',
        'profile.assets.col.size' => 'Size',
        'profile.assets.col.last_upload' => 'Last upload',
        'profile.assets.col.storage_path' => 'Storage path'
    ],
    'sk' => [
        'app.title' => 'EduDisplej Control',
        'nav.kiosks' => 'Displeje',
        'nav.groups' => 'Skupiny',
        'nav.modules' => 'Moduly',
        'nav.profile' => 'Inštitúcia',
        'nav.settings' => 'Nastavenia',
        'nav.translations' => 'Preklady',
        'nav.admin' => 'Admin',
        'nav.logout' => 'Odhlasit sa',
        'lang.label' => 'Jazyk',
        'lang.hu' => 'Madarsky',
        'lang.en' => 'Anglicky',
        'lang.sk' => 'Slovensky',
        'session.timeout.warning' => 'Po 10 minutach neaktivity sa relacia ukonci. Prihlaste sa znova.',
        'login.title' => 'Prihlasenie - EduDisplej Control',
        'login.heading' => 'Prihlasenie',
        'login.subheading' => 'Vstup do ovladacieho panela',
        'login.email' => 'Email',
        'login.email_placeholder' => 'Zadaj svoj email',
        'login.password' => 'Heslo',
        'login.password_placeholder' => 'Zadaj svoje heslo',
        'login.otp' => 'Dvojfaktorovy kod',
        'login.otp_placeholder' => 'Zadaj 6-miestny kod',
        'login.otp_help' => 'Zadaj 6-miestny kod z autentifikatora',
        'login.remember' => 'Zapamatat si ma',
        'login.submit' => 'Prihlasit sa',
        'login.no_account' => 'Nemas ucet?',
        'login.create_account' => 'Vytvorit novy ucet',
        'login.registered' => 'Registracia bola uspesna. Prihlaste sa do uctu.',
        'login.info_title' => 'Informacie o prihlaseni',
        'login.info_admin' => 'Admini budu presmerovani do Admin portalu',
        'login.info_user' => 'Bezni pouzivatelia budu mat pristup k dashboardu',
        'login.info_last_login' => 'Posledne prihlasenie sa zaznamena',
        'login.otp_required' => 'Dvojfaktorovy kod je povinny',
        'login.error.required' => 'Email a heslo su povinne',
        'login.error.auth_state' => 'Neplatny stav overenia. Skus znova.',
        'login.error.session_expired' => 'Relacia vyprsala. Skus znova.',
        'login.error.invalid_otp' => 'Neplatny dvojfaktorovy kod',
        'login.error.failed' => 'Prihlasenie zlyhalo. Skus znova neskor.',
        'login.invalid' => 'Neplatny email alebo heslo',
        'dashboard.title' => 'Moje displeje',
        'dashboard.total' => 'Spolu',
        'dashboard.online' => 'Online',
        'dashboard.offline' => 'Offline',
        'dashboard.company_displays' => 'Vsetky displeje priradene k institucii',
        'dashboard.all_groups' => 'Vsetky skupiny',
        'dashboard.none_assigned' => 'Nie su priradene ziadne displeje',
        'dashboard.no_company' => 'Nie je priradena institucia alebo nemate pristup k datam.',
        'dashboard.header.id' => 'ID',
        'dashboard.header.hostname' => 'Hostname',
        'dashboard.header.status' => 'Status',
        'dashboard.header.group' => 'Skupina',
        'dashboard.header.preview' => 'Nahlad',
        'dashboard.header.location' => 'Umiestnenie',
        'dashboard.header.last_sync' => 'Posledna synchronizacia',
        'dashboard.header.loop' => 'Loop',
        'dashboard.status.online' => 'Online',
        'dashboard.status.offline' => 'Offline',
        'dashboard.screenshot.none' => 'Ziadny obrazok',
        'dashboard.screenshot.no_fresh' => 'Nema novy obrazok',
        'dashboard.screenshot.time' => 'Cas obrazka',
        'dashboard.screenshot.time_unknown' => 'Cas obrazka: neznamy',
        'dashboard.sync.never' => 'Nikdy',
        'dashboard.loop.loading' => 'Nacitavanie...',
        'dashboard.loop.none' => 'Ziadny loop',
        'dashboard.loop.error' => 'Chyba loop',
        'dashboard.loop.no_data' => 'Ziadne loop data',
        'dashboard.loop.info_time' => 'Cas loop info',
        'dashboard.loop.modal_title' => 'Loop konfiguracia',
        'dashboard.loop.module_order' => 'Poradie modulov',
        'dashboard.loop.total_duration' => 'Celkova dlzka loop',
        'dashboard.loop.seconds' => 'sekundy',
        'dashboard.loop.minutes_short' => 'min',
        'dashboard.loop.seconds_short' => 'sek',
        'dashboard.toggle.list_view' => 'Zoznam',
        'dashboard.toggle.realtime_view' => 'Realtime',
        'dashboard.assign_group_missing' => 'Vyberte skupinu',
        'dashboard.error' => 'Nastala chyba',
        'dashboard.loop.preview' => 'Loop',
        'dashboard.modal.kiosk_details' => 'Detaily displeja',
        'dashboard.modal.close' => 'Zatvorit',
        'dashboard.loop.play' => 'Spustit',
        'dashboard.loop.pause' => 'Pauza',
        'dashboard.loop.stop' => 'Stop',
        'dashboard.loop.module' => 'Modul',
        'dashboard.loop.cycle' => 'Cyklus',
        'dashboard.error.no_company_assigned' => 'Nie je priradena institucia. Kontaktuj admina.',
        'dashboard.sync_interval_confirm' => 'Nastavit synchronizaciu na {seconds} sekund?',
        'dashboard.sync_interval_updated' => 'Synchronizacny interval aktualizovany',
        'dashboard.sync_interval_label' => 'Synchronizacny interval: {seconds} sekund',
        'dashboard.screenshot.enabled' => 'Zapnute',
        'dashboard.screenshot.disabled' => 'Vypnute',
        'dashboard.screenshot.waiting' => 'Ziadny screenshot. Pockaj na dalsiu synchronizaciu.',
        'dashboard.screenshot.off' => 'Funkcia screenshotov je vypnuta. Zapni ju pre prijatie screenshotov.',
        'dashboard.screenshot.toggled' => 'Screenshot funkcia: {state}',
        'dashboard.screenshot.unavailable' => 'Screenshot nie je dostupny',
        'dashboard.screenshot.loading' => 'Caka sa na screenshot',
        'dashboard.screen.on' => 'Zapnute',
        'dashboard.screen.off' => 'Vypnute',
        'profile.assets.title' => '🗂️ Modulové úložisko (firma)',
        'profile.assets.subtitle' => 'Nahrané súbory modulov tejto firmy zoskupené podľa modulu.',
        'profile.assets.empty' => 'Zatiaľ nie sú nahrané žiadne modulové súbory.',
        'profile.assets.latest_uploads' => 'Všetky nahratia',
        'profile.assets.col.time' => 'Čas',
        'profile.assets.col.module' => 'Modul',
        'profile.assets.col.file' => 'Súbor',
        'profile.assets.col.kind' => 'Typ',
        'profile.assets.col.count' => 'Počet',
        'profile.assets.col.size' => 'Veľkosť',
        'profile.assets.col.last_upload' => 'Posledné nahratie',
        'profile.assets.col.storage_path' => 'Cesta uloženia'
    ]
];

function edudisplej_normalize_lang($lang) {
    global $EDUDISPLEJ_SUPPORTED_LANGS;
    $lang = strtolower(trim((string)$lang));
    if (!$lang) {
        return null;
    }
    return in_array($lang, $EDUDISPLEJ_SUPPORTED_LANGS, true) ? $lang : null;
}

function edudisplej_get_user_lang($user_id) {
    if (!$user_id) {
        return null;
    }
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT lang FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        closeDbConnection($conn);
        return edudisplej_normalize_lang($row['lang'] ?? '');
    } catch (Exception $e) {
        error_log('Lang fetch error: ' . $e->getMessage());
        return null;
    }
}

function edudisplej_set_user_lang($user_id, $lang) {
    if (!$user_id || !$lang) {
        return;
    }
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("UPDATE users SET lang = ? WHERE id = ?");
        $stmt->bind_param("si", $lang, $user_id);
        $stmt->execute();
        $stmt->close();
        closeDbConnection($conn);
    } catch (Exception $e) {
        error_log('Lang update error: ' . $e->getMessage());
    }
}

function edudisplej_set_lang($lang, $persist = true) {
    $normalized = edudisplej_normalize_lang($lang);
    if (!$normalized) {
        return;
    }
    $_SESSION['lang'] = $normalized;
    setcookie('edudisplej_lang', $normalized, time() + (365 * 24 * 60 * 60), '/', '', !empty($_SERVER['HTTPS']), true);
    if ($persist && isset($_SESSION['user_id'])) {
        edudisplej_set_user_lang((int)$_SESSION['user_id'], $normalized);
    }
}

function edudisplej_get_lang() {
    if (isset($_SESSION['lang'])) {
        return $_SESSION['lang'];
    }
    $cookie_lang = edudisplej_normalize_lang($_COOKIE['edudisplej_lang'] ?? '');
    if ($cookie_lang) {
        $_SESSION['lang'] = $cookie_lang;
        return $cookie_lang;
    }
    if (isset($_SESSION['user_id'])) {
        $db_lang = edudisplej_get_user_lang((int)$_SESSION['user_id']);
        if ($db_lang) {
            $_SESSION['lang'] = $db_lang;
            return $db_lang;
        }
    }
    $_SESSION['lang'] = EDUDISPLEJ_DEFAULT_LANG;
    return EDUDISPLEJ_DEFAULT_LANG;
}

function edudisplej_apply_language_preferences() {
    $requested = edudisplej_normalize_lang($_GET['lang'] ?? '');
    if ($requested) {
        edudisplej_set_lang($requested, true);
        return $requested;
    }
    return edudisplej_get_lang();
}

function edudisplej_get_supported_langs() {
    global $EDUDISPLEJ_SUPPORTED_LANGS;
    return $EDUDISPLEJ_SUPPORTED_LANGS;
}

function edudisplej_ensure_i18n_override_dir() {
    if (!is_dir(EDUDISPLEJ_I18N_OVERRIDES_DIR)) {
        @mkdir(EDUDISPLEJ_I18N_OVERRIDES_DIR, 0755, true);
    }
}

function edudisplej_seed_lang_file_from_embedded($lang) {
    global $EDUDISPLEJ_TRANSLATIONS;
    $normalized = edudisplej_normalize_lang($lang);
    if (!$normalized) {
        return false;
    }

    $seed = $EDUDISPLEJ_TRANSLATIONS[$normalized] ?? [];
    if (!is_array($seed) || empty($seed)) {
        return false;
    }

    edudisplej_ensure_i18n_override_dir();
    $file = edudisplej_get_i18n_override_file($normalized);
    if (!$file) {
        return false;
    }

    $payload = json_encode($seed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return false;
    }

    return @file_put_contents($file, $payload) !== false;
}

function edudisplej_get_i18n_override_file($lang) {
    $normalized = edudisplej_normalize_lang($lang);
    if (!$normalized) {
        return null;
    }
    return EDUDISPLEJ_I18N_OVERRIDES_DIR . '/' . $normalized . '.json';
}

function edudisplej_load_lang_overrides($lang) {
    $file = edudisplej_get_i18n_override_file($lang);
    if (!$file || !is_file($file)) {
        edudisplej_seed_lang_file_from_embedded($lang);
        $file = edudisplej_get_i18n_override_file($lang);
    }

    if (!$file || !is_file($file)) {
        return [];
    }

    $json = @file_get_contents($file);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        edudisplej_seed_lang_file_from_embedded($lang);
        $json = @file_get_contents($file);
        if ($json === false || trim($json) === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
    }

    if (empty($data)) {
        edudisplej_seed_lang_file_from_embedded($lang);
        $json = @file_get_contents($file);
        if ($json === false || trim($json) === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
    }

    $clean = [];
    foreach ($data as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        $clean[$key] = (string)$value;
    }
    return $clean;
}

function edudisplej_get_embedded_translation_catalog($lang = null) {
    global $EDUDISPLEJ_TRANSLATIONS;
    $lang = $lang ? edudisplej_normalize_lang($lang) : EDUDISPLEJ_DEFAULT_LANG;
    if (!$lang) {
        $lang = EDUDISPLEJ_DEFAULT_LANG;
    }

    $base = $EDUDISPLEJ_TRANSLATIONS[$lang] ?? [];
    if (!is_array($base) || empty($base)) {
        $base = $EDUDISPLEJ_TRANSLATIONS['en'] ?? [];
    }

    return is_array($base) ? $base : [];
}

function edudisplej_load_lang_catalog($lang = null) {
    $lang = $lang ? edudisplej_normalize_lang($lang) : edudisplej_get_lang();
    if (!$lang) {
        $lang = EDUDISPLEJ_DEFAULT_LANG;
    }

    $base = edudisplej_get_embedded_translation_catalog($lang);
    $overrides = edudisplej_load_lang_overrides($lang);

    if (!is_array($base)) {
        $base = [];
    }
    if (!is_array($overrides) || empty($overrides)) {
        return $base;
    }

    return array_merge($base, $overrides);
}

function edudisplej_get_translation_catalog($lang = null, $include_overrides = true) {
    $catalog = edudisplej_load_lang_catalog($lang);
    if (!is_array($catalog)) {
        return [];
    }
    return $catalog;
}

function edudisplej_save_translation_overrides($lang, $overrides) {
    $lang = edudisplej_normalize_lang($lang);
    if (!$lang || !is_array($overrides)) {
        return false;
    }

    $base = edudisplej_get_translation_catalog($lang, true);
    if (empty($base)) {
        $base = edudisplej_get_embedded_translation_catalog($lang);
    }

    $clean = [];
    foreach ($base as $key => $defaultValue) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        if (array_key_exists($key, $overrides)) {
            $clean[$key] = trim((string)$overrides[$key]);
        } else {
            $clean[$key] = (string)$defaultValue;
        }
    }

    edudisplej_ensure_i18n_override_dir();
    $file = edudisplej_get_i18n_override_file($lang);
    if (!$file) {
        return false;
    }

    $payload = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return false;
    }

    return @file_put_contents($file, $payload) !== false;
}

function t($key, $vars = []) {
    $lang = edudisplej_get_lang();
    $catalog = edudisplej_get_translation_catalog($lang, true);
    $fallback = edudisplej_get_translation_catalog('en', true);
    $value = $catalog[$key]
        ?? $fallback[$key]
        ?? $key;
    if (!empty($vars)) {
        foreach ($vars as $name => $val) {
            $value = str_replace('{' . $name . '}', (string)$val, $value);
        }
    }
    return $value;
}

function t_def($key, $defaultValue, $vars = []) {
    $translated = t($key, $vars);
    if ($translated === $key || $translated === '') {
        $translated = (string)$defaultValue;
        if (!empty($vars)) {
            foreach ($vars as $name => $val) {
                $translated = str_replace('{' . $name . '}', (string)$val, $translated);
            }
        }
    }
    return $translated;
}

function edudisplej_i18n_catalog($lang = null) {
    return edudisplej_get_translation_catalog($lang, true);
}
?>
