# Incidens riport – 2026-02-22

## Összefoglaló

Az első telepítés közben a kiosk init folyamat látszólag "megakadt" kb. 57–85% környékén.
A rendszer nem fagyott le, SSH elérés és service-ek tovább futottak, de az install progressz nem haladt tovább.

## Mi történt pontosan

- A telepítő lánc futott: `kiosk-start.sh -> edudisplej-init.sh -> edudisplej-system.sh`.
- Csomagtelepítés közben (`apt-get install unclutter`, később `apt-get install x11-xserver-utils`) az `apt-get` folyamat `T (stopped)` állapotba került.
- A logokban az utolsó APT sorok még sikeres telepítést mutattak, de a következő lépésre nem lépett tovább.

## Root cause (miért keletkezett a hiba)

A probléma terminál/jobbvezérlés (job-control) jellegű volt:

1. Az `apt-get` nem teljesen TTY-független módon futott.
2. A folyamat a shell process group kezelés miatt olyan állapotba került, ahol a standard input olvasás terminálhoz kötődött.
3. A kernel ilyenkor `SIGTTIN` jelzéssel megállítja a háttérből terminált olvasó processzt.
4. Ennek eredménye `STAT = T (stopped)` lett, ezért az installer "várt", de valójában sosem tudott továbbmenni.

Ezért volt reprodukálható ugyanaz a jelenség több különböző `apt-get install ...` lépésnél is.

## Miért kritikus tömeges telepítésnél

- Headless / automata deploy esetén nincs operátor, aki manuálisan beavatkozik.
- Több száz eszköz telepítésekor ugyanaz a futási minta sok gépen ugyanígy megakadhat.
- Ettől a rollout kiszámíthatatlanná válik (véletlenszerűnek tűnő, de valójában determinisztikus megállás).

## Bevezetett globális javítás

Minden kritikus installer útvonalon egységesen TTY-független APT futtatás lett bevezetve:

- `Dpkg::Use-Pty=0` opció (APT pseudo-TTY tiltása)
- `DEBIAN_FRONTEND=noninteractive` + kapcsolódó non-interactive env
- `apt-get ... < /dev/null` (stdin leválasztás a terminálról)

Érintett fájlok:

- `webserver/install/init/edudisplej-system.sh`
- `webserver/install/install.sh`
- `webserver/install/init/update.sh`
- `webserver/install/init/edudisplej-download-modules.sh`

## Eredmény

- Az `apt-get` folyamat nem tud többé terminal-input miatt `T (stopped)` állapotba kerülni.
- Az installer lépések determinisztikusan végigfutnak unattended környezetben is.
- A javítás nem csak egy adott csomagra, hanem az összes érintett telepítési útvonalra vonatkozik.

## Tanulság / megelőzés

Unattended telepítési szabály:

- Minden `apt-get` hívás legyen explicit non-interactive.
- Minden `apt-get` hívás stdin-je legyen leválasztva (`< /dev/null`).
- Tömeges rollout előtt kötelező "headless dry-run" validáció.
