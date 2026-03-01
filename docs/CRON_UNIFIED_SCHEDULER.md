# Unified Cron Scheduler (Single PHP Entry)

Ez a dokumentáció a **single-cron** működést írja le: csak **1 db PHP** fájlt kell ütemezni, és az dönti el belül, mely feladatok fussanak.

## 1) Ütemezendő fájl

- Fájl: `webserver/control_edudisplej_sk/cron.php`
- Ütemezés: **5 percenként**

Javasolt cron sor:

```cron
*/5 * * * * /usr/bin/php /path/to/webserver/control_edudisplej_sk/cron.php --maintenance-min-interval-minutes=15 --email-min-interval-minutes=5 --email-limit=50 >> /path/to/webserver/control_edudisplej_sk/logs/maintenance-cron.log 2>&1
```

> A `/usr/bin/php` és `/path/to/...` útvonalakat a szerveredhez igazítsd.

---

## 2) Mit csinál a scheduler egy futás során?

A `cron.php` (belső scheduler: `run_maintenance.php`) minden 5 perces induláskor:

1. **Lock-ol** (ne fusson párhuzamosan több példány).
2. Ellenőrzi az **email queue** futási intervallumát.
   - Ha esedékes, futtatja a queue feldolgozást (`process_email_queue`).
   - Ha még nem esedékes, kihagyja és logolja.
3. Ellenőrzi a **maintenance** futási intervallumát.
   - Ha esedékes, futtatja a teljes maintenance pipeline-t (`maintenance_task.php`).
   - Ha még nem esedékes, kihagyja és logolja.
4. Záró log, lock feloldás.

Így egyetlen cron entry mellett is külön-külön optimalizált frekvencián mennek a részfeladatok.

---

## 3) Belső ütemezés (alapértelmezés)

- Cron trigger: **5 percenként**
- Email queue minimum intervallum: **5 perc** (`--email-min-interval-minutes=5`)
- Maintenance minimum intervallum: **15 perc** (`--maintenance-min-interval-minutes=15`)
- Email queue batch méret: **50** (`--email-limit=50`)

### Miért ez az optimális alap?

- Az email-ek nem késnek sokat (max ~5 perc tipikusan).
- A maintenance nem fut túl sűrűn (kisebb DB/IO terhelés).
- A lock és marker fájlok miatt stabil marad ütközésnél is.

---

## 4) CLI kapcsolók

A scheduler támogatott opciói:

- `--maintenance-min-interval-minutes=<N>`
  - maintenance minimum futási időköz percben
- `--email-min-interval-minutes=<N>`
  - email queue minimum futási időköz percben
- `--email-limit=<N>`
  - egy queue feldolgozás batch mérete
- `--force-maintenance`
  - maintenance futtatás azonnal (intervallum guard megkerülése)
- `--force-email-queue`
  - email queue futtatás azonnal (intervallum guard megkerülése)
- Jedalen opciók (korábbi működés megtartva):
  - `--force-jedalen-sync`
  - `--only-jedalen-sync`
  - `--jedalen-fetch-institutions-only`
  - `--jedalen-fetch-menus-only`
  - `--jedalen-institution-ids=<csv>`

---

## 5) Logok és állapotfájlok

### Log

- `webserver/control_edudisplej_sk/logs/maintenance-cron.log`

A log tartalmazza:
- scheduler start/finish
- email queue futott-e vagy skip
- maintenance futott-e vagy skip
- feldolgozott queue darabszámok

### Runtime marker/lock

A scheduler runtime könyvtárban marker fájlokat használ:
- `maintenance.lock`
- `maintenance-last-run.txt`
- `email-queue-last-run.txt`

Ezek biztosítják:
- párhuzamosság elleni védelmet
- minimum intervallum guard működését

---

## 6) Telepítés (gyors)

Használhatod a helper scriptet is:

- `webserver/control_edudisplej_sk/cron/maintenance/install_cron.sh`

Ez egyetlen cron sort telepít (5 perc), a unified schedulerhez.

---

## 9) Egyvégpontos működési elv (step-by-step)

A single endpoint (`cron.php`) futáskor:

1. **Email queue step**
  - megnézi, hogy az email queue futott-e már az utolsó `email_min_interval_minutes` ablakban;
  - ha igen: skip + log;
  - ha nem: feldolgoz `email_limit` darab queue elemet.

2. **Maintenance step**
  - megnézi, hogy a maintenance futott-e már az utolsó `maintenance_min_interval_minutes` ablakban;
  - ha igen: skip + log;
  - ha nem: lefuttatja a teljes maintenance pipeline-t.

3. **Jedalen/étrend step (maintenance részeként)**
  - a maintenance pipeline-on belül a meglévő logika ellenőrzi, hogy az adott napi sync már megtörtént-e;
  - ha igen: nem tölti le újra;
  - ha nem: letölti, frissíti a cache-t és verziókat.

Tehát egyetlen cron végpontot ütemezel, és az dönt minden részfeladatról.

---

## 7) Ellenőrzés

### Cron ellenőrzés

```bash
crontab -l
```

### Log követés

```bash
tail -f /path/to/webserver/control_edudisplej_sk/logs/maintenance-cron.log
```

Várt minták:
- `Unified cron scheduler start`
- `Email queue: processed=... sent=... failed=...`
- vagy `Email queue skipped by interval guard ...`
- `Maintenance skipped by interval guard ...` (nem minden futásnál, mert 15 percenként fut)

---

## 8) Javasolt finomhangolás

- Nagy email forgalomnál:
  - `--email-limit=100`
- Erősebb szerveren, gyors maintenance mellett:
  - `--maintenance-min-interval-minutes=10`
- Gyengébb szerveren:
  - `--maintenance-min-interval-minutes=20`

Ha továbbra is 1 cron entry marad, ezekkel biztonságosan finomhangolható a terhelés.
