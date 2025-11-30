#!/bin/sh
# Generál egy splash képet, ami tartalmazza a logót + az aktuális IP címet.
# Feltételek: ImageMagick (convert) telepítve; grafikus megjelenítéshez fbi vagy a rendszer meglévő splash-mechanizmusa.
# Használat: boot során futtatva, majd a létrehozott /tmp/edudisplej_splash.png megjelenítése.

set -e

LOGO_PATH="/opt/edudisplej/logo.png"   # módosítsd ha a logó másutt van
OUT_PATH="/tmp/edudisplej_splash.png"
FONT="/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"  # létező font a rendszeren
FONTSIZE=28
TEXT_COLOR="white"
TEXT_BG="rgba(0,0,0,0.4)"  # átlátszó sötét háttér a jobb olvashatóságért

# Ellenőrizzük, hogy a logó létezik-e
if [ ! -f "$LOGO_PATH" ]; then
  echo "Hiba: A logó nem található: $LOGO_PATH" >&2
  exit 1
fi

# Szerezzük meg az első nem-loopback IP-t (IPv4)
IP=$(hostname -I 2>/dev/null | awk '{print $1}')
if [ -z "$IP" ]; then
  # alternatív módszer (POSIX-kompatibilis)
  IP=$(ip -4 addr show scope global 2>/dev/null | awk '/inet / {split($2, a, "/"); print a[1]; exit}')
fi
[ -z "$IP" ] && IP="nincs IP"

# Ha nincs ImageMagick, csak másoljuk a logót (fallback)
if ! command -v convert >/dev/null 2>&1; then
  cp -a "$LOGO_PATH" "$OUT_PATH"
  exit 0
fi

# Ellenőrizzük a font létezését, és keressünk alternatívát ha szükséges
if [ ! -f "$FONT" ]; then
  # Próbáljunk más gyakori fontokat
  for alt_font in \
    "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf" \
    "/usr/share/fonts/truetype/freefont/FreeSans.ttf" \
    "/usr/share/fonts/TTF/DejaVuSans.ttf"; do
    if [ -f "$alt_font" ]; then
      FONT="$alt_font"
      break
    fi
  done
fi

# Méretezés: ha kell, alakítsd a canvas méretét a kijelződhöz
# Itt egyszerűen felülírjuk a logót egy sötét dobozzal és rajta az IP-t
convert "$LOGO_PATH" \
  -gravity SouthEast \
  -pointsize "$FONTSIZE" \
  -font "$FONT" \
  -fill "$TEXT_COLOR" \
  -undercolor "$TEXT_BG" \
  -annotate +20+20 "IP: $IP" \
  "$OUT_PATH"

exit 0
