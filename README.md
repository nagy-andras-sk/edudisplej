# EduDisplej

**EduDisplej** je systém digitálnych displejov pre vzdelávacie inštitúcie (školy, univerzity). Umožňuje centralizovanú správu a zobrazovanie rôzneho obsahu na informačných kioskoch v celej budove.

## 🚀 Inštalácia

Jednoduchá inštalácia jedným príkazom:

```bash
curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash
```

Po inštalácii sa zariadenie automaticky zaregistruje a zobrazí sa nastavovacie okno až do priradenia k firme a nastavenia modulov cez administračný panel.

## 🔄 Aktualizácia

Pre aktualizáciu systému použite:

```bash
sudo /opt/edudisplej/init/update.sh
```

## 📖 Ako to funguje?

1. **Automatická registrácia** - Zariadenie sa pri prvom spustení automaticky zaregistruje do systému
2. **Webová správa** - Administrátor môže cez webové rozhraní priradiť zariadenie k organizácii a konfigurovať zobrazovaný obsah
3. **Synchronizácia modulov** - Moduly sa automaticky synchronizujú zo servera a zobrazujú sa v nastavenej sekvencii
4. **Loop systém** - Obsah sa automaticky rotuje podľa nakonfigurovaných intervalov

## 🌐 Webové rozhraní

Administračný panel je dostupný na: **https://control.edudisplej.sk**

## ✨ Funkcie

- **Automatická registrácia zariadení** - Žiadna manuálna konfigurácia
- **Multi-tenant podpora** - Podpora viacerých organizácií/škôl
- **Modulárny systém** - Hodiny, meniny, kalendár a ďalšie moduly
- **Centralizovaná správa** - Ovládanie všetkých displejov z jedného miesta
- **Screenshot monitoring** - Sledovanie aktuálneho stavu displejov
- **Používateľské role** - Super admin, admin, editor obsahu
- **Real-time sync** - Okamžitá aktualizácia obsahu na zariadeniach

## 📦 Dostupné moduly

- **📅 Hodiny** - Digitálne/analógové hodiny s dátumom
- **🎂 Meniny** - Slovenské a maďarské meniny
- **📋 Split modul** - Kombinované rozloženie pre 16:9 displeje (plánované)
- **Vlastné moduly** - Jednoducho pridávajte vlastné HTML moduly

## 🛠️ Technické požiadavky

- Linux-based systém (Raspberry Pi, x86 Linux)
- Internetové pripojenie
- Displej (kiosk mode)

## 📄 Licencia

Tento projekt je proprietárny softvér. Všetky práva vyhradené.

## 👥 Autor

**Nagy András** - [nagy-andras-sk](https://github.com/nagy-andras-sk)

## 📞 Podpora

- 📧 Email: info@edudisplej.sk
- 🐛 Issues: [GitHub Issues](https://github.com/nagy-andras-sk/edudisplej/issues)

---

**Vytvorené s ❤️ pre vzdelávacie inštitúcie**

