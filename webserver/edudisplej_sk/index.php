<?php
$page_title = "Domov";
require_once 'header.php';
?>

<div class="content-card">
    <div style="text-align: center; margin-bottom: 3rem;">
        <img src="logo.png" alt="EduDisplej Logo" style="max-width: 200px; height: auto;">
    </div>
    
    <h2 style="text-align: center;">Vitajte v systéme EduDisplej</h2>
    
    <p style="text-align: center; font-size: 1.2rem; color: #666; margin: 2rem 0;">
        Profesionálne riešenie pre digitálne zobrazovanie na platforme Raspberry Pi
    </p>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 2rem; margin-top: 3rem;">
        <div style="padding: 1.5rem; border: 2px solid #667eea; border-radius: 8px; text-align: center;">
            <h3 style="color: #667eea;">🖥️ Kiosk Mód</h3>
            <p style="color: #666; margin-top: 1rem;">
                Plnohodnotný kiosk režim s podporou pre Chromium, Epiphany a Firefox ESR prehliadače.
            </p>
        </div>
        
        <div style="padding: 1.5rem; border: 2px solid #764ba2; border-radius: 8px; text-align: center;">
            <h3 style="color: #764ba2;">🔄 Auto-Update</h3>
            <p style="color: #666; margin-top: 1rem;">
                Automatické aktualizácie systému zabezpečujú, že váš displej je vždy aktuálny.
            </p>
        </div>
        
        <div style="padding: 1.5rem; border: 2px solid #667eea; border-radius: 8px; text-align: center;">
            <h3 style="color: #667eea;">🛡️ Watchdog</h3>
            <p style="color: #666; margin-top: 1rem;">
                Inteligentný watchdog s automatickým prechodom na Firefox ESR pri opakovaných zlyha niach.
            </p>
        </div>
    </div>
</div>

<div class="content-card">
    <h2>Funkcie systému</h2>
    
    <div style="display: grid; gap: 1.5rem;">
        <div>
            <h3>✨ Jednoduché ovládanie</h3>
            <p>F12 konfiguračné menu umožňuje nastavenie systému bez potreby SSH prístupu.</p>
        </div>
        
        <div>
            <h3>🌐 Online aj Offline režim</h3>
            <p>Systém funguje aj bez internetového pripojenia s lokálnym obsahom.</p>
        </div>
        
        <div>
            <h3>📊 Dashboard</h3>
            <p>Webové rozhranie pre správu zariadení a monitoring stavu systému.</p>
        </div>
        
        <div>
            <h3>🔧 Jednoduchá inštalácia</h3>
            <p>Inštalácia jedným príkazom na akýkoľvek Debian/Ubuntu/Raspberry Pi OS systém.</p>
        </div>
    </div>
    
    <div style="text-align: center; margin-top: 2rem;">
        <a href="dashboard/" class="btn">Prejsť na Dashboard</a>
    </div>
</div>

<div class="content-card">
    <h2>Rýchla inštalácia</h2>
    
    <p style="margin-bottom: 1rem;">Inštalujte EduDisplej na váš Raspberry Pi jedným príkazom:</p>
    
    <pre style="background: #f4f4f4; padding: 1rem; border-radius: 5px; overflow-x: auto; border-left: 4px solid #667eea;">curl https://install.edudisplej.sk/install.sh | sed 's/\r$//' | sudo bash</pre>
    
    <p style="margin-top: 1rem; color: #666; font-size: 0.95rem;">
        Systém sa automaticky nainštaluje a nastaví. Po reštarte budete môcť upraviť nastavenia pomocou F12 menu.
    </p>
</div>

<div class="content-card" style="background: linear-gradient(135deg, #667eea15 0%, #764ba215 100%);">
    <h2>Miesto pre reklamu</h2>
    <div style="text-align: center; padding: 3rem; border: 2px dashed #ccc; border-radius: 8px;">
        <p style="color: #999; font-size: 1.1rem;">Tu bude reklamný priestor</p>
        <p style="color: #ccc; margin-top: 0.5rem;">Banner alebo textová reklama</p>
    </div>
</div>

<?php
require_once 'footer.php';
?>
