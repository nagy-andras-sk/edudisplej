<?php
$page_title = "Dashboard";
require_once '../header.php';
?>

<div class="content-card">
    <h2>📊 EduDisplej Dashboard</h2>
    <p style="color: #666; margin-bottom: 2rem;">
        Centrálne ovládanie a monitoring vašich EduDisplej zariadení.
    </p>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
    <div class="content-card" style="background: linear-gradient(135deg, #667eea15 0%, #764ba215 100%);">
        <h3>🔐 Prihlásenie</h3>
        <p style="color: #666; margin: 1rem 0;">
            Prihláste sa pre prístup k správe zariadení.
        </p>
        <button class="btn" onclick="alert('Prihlasovací systém sa pripravuje');">Prihlásiť sa</button>
    </div>
    
    <div class="content-card">
        <h3>📱 Registrované zariadenia</h3>
        <p style="color: #666; margin: 1rem 0;">
            Počet registrovaných zariadení: <strong>0</strong>
        </p>
        <button class="btn" onclick="alert('Funkcia sa pripravuje');" style="background: #764ba2;">Zobraziť zariadenia</button>
    </div>
    
    <div class="content-card">
        <h3>📈 Štatistiky</h3>
        <p style="color: #666; margin: 1rem 0;">
            Systémové štatistiky a monitoring.
        </p>
        <button class="btn" onclick="alert('Funkcia sa pripravuje');" style="background: #667eea;">Zobra ziť štatistiky</button>
    </div>
</div>

<div class="content-card">
    <h3>🛠️ Správa systému</h3>
    
    <div style="display: grid; gap: 1rem; margin-top: 1.5rem;">
        <div style="padding: 1rem; background: #f9f9f9; border-radius: 5px; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <strong>Pridať nové zariadenie</strong>
                <p style="color: #666; margin: 0.3rem 0 0 0; font-size: 0.9rem;">Registrovať nový EduDisplej displej</p>
            </div>
            <button class="btn" onclick="alert('Funkcia sa pripravuje');">Pridať</button>
        </div>
        
        <div style="padding: 1rem; background: #f9f9f9; border-radius: 5px; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <strong>Nastavenia</strong>
                <p style="color: #666; margin: 0.3rem 0 0 0; font-size: 0.9rem;">Konfigurácia dashboardu a systému</p>
            </div>
            <button class="btn" onclick="alert('Funkcia sa pripravuje');" style="background: #764ba2;">Nastaviť</button>
        </div>
        
        <div style="padding: 1rem; background: #f9f9f9; border-radius: 5px; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <strong>Logy a diagnostika</strong>
                <p style="color: #666; margin: 0.3rem 0 0 0; font-size: 0.9rem;">Zobraziť systémové logy a diagnostické informácie</p>
            </div>
            <button class="btn" onclick="alert('Funkcia sa pripravuje');" style="background: #667eea;">Zobraziť</button>
        </div>
    </div>
</div>

<div class="content-card">
    <h3>📋 Rýchly prístup</h3>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem;">
        <a href="#" onclick="alert('Funkcia sa pripravuje'); return false;" style="padding: 1rem; background: #667eea; color: white; text-decoration: none; border-radius: 5px; text-align: center; transition: transform 0.3s;">
            <strong>📺 Živé náhľady</strong>
        </a>
        
        <a href="#" onclick="alert('Funkcia sa pripravuje'); return false;" style="padding: 1rem; background: #764ba2; color: white; text-decoration: none; border-radius: 5px; text-align: center; transition: transform 0.3s;">
            <strong>⚙️ Vzdialená konfigurácia</strong>
        </a>
        
        <a href="#" onclick="alert('Funkcia sa pripravuje'); return false;" style="padding: 1rem; background: #667eea; color: white; text-decoration: none; border-radius: 5px; text-align: center; transition: transform 0.3s;">
            <strong>📊 Reporty</strong>
        </a>
        
        <a href="#" onclick="alert('Funkcia sa pripravuje'); return false;" style="padding: 1rem; background: #764ba2; color: white; text-decoration: none; border-radius: 5px; text-align: center; transition: transform 0.3s;">
            <strong>🔔 Notifikácie</strong>
        </a>
    </div>
</div>

<div class="content-card" style="background: #fff3cd; border-left: 4px solid #ffc107;">
    <h3 style="color: #856404;">ℹ️ Informácia</h3>
    <p style="color: #856404;">
        Dashboard je momentálne v štádiu vývoja. Jednotlivé funkcie budú postupne aktivované.
        Pre prístup k základným funkciám použite F12 menu priamo na zariadení.
    </p>
</div>

<?php
require_once '../footer.php';
?>
