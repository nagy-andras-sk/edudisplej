
#!/bin/bash
# EduDisplej main init (modular, ASCII, inactivity timeout, kioskchrome.service) 
#Elore beallitott script, amely raspberry inditasakor indul
# Autor: Nagy Andras , 2025 11 23

# Load modules
. /home/edudisplej/init/common.sh
. /home/edudisplej/init/kiosk.sh
. /home/edudisplej/init/network.sh
. /home/edudisplej/init/display.sh
. /home/edudisplej/init/language.sh

clear
figlet "$(T title)"
echo "$(T connecting)"
sleep 2

# Hostname autogenerate (Csak ha meg alap)
CURRENT_HOSTNAME=$(hostname)
if [ "$CURRENT_HOSTNAME" = "raspberrypi" ]; then
    MAC=$(cat /sys/class/net/eth0/address | tr -d ':')
    SUFFIX=${MAC:6}
    NEW_HOSTNAME="edudisplej-$SUFFIX"
    echo "$(T hostname_change) $NEW_HOSTNAME"
    sudo hostnamectl set-hostname "$NEW_HOSTNAME"
	#Javitas! - Itt sajnos raspberry nem irja att automatikusan, igy manualisan irjuk att itt is.
    echo "$NEW_HOSTNAME" | sudo tee /etc/hostname >/dev/null
    sudo sed -i "s/127.0.1.1.*/127.0.1.1   $NEW_HOSTNAME/" /etc/hosts
fi

# Internetre var
until check_internet; do
    echo "$(T waiting_net)"
    sleep 5
done



# 5s window for F12 to menu 
echo "Press F12 within 5 seconds to change mode..."
for i in 5 4 3 2 1; do
    printf "\r%ds " "$i"
    # read one key with 1s timeout
    read -rsn1 -t 1 key
    # capture escape sequences
    if [[ "$key" == $'\e' ]]; then
        read -rsn3 -t 0.001 tail
        key+="$tail"
    fi
    # F12 (varies by terminal): \e[24~ or \e[12~
    if [[ "$key" == $'\e[24~' || "$key" == $'\e[12~' ]]; then
        echo -e "\nF12 pressed -> opening menu"
        rm -f "$MODEFILE"
        break
    fi
done
echo


# Ha meg nincs mod (nincs configban) - valaszto
if [ ! -f "$MODEFILE" ]; then
    MENU_ITEMS=(
        "$(T m_server)"
        "$(T m_standalone)"
        "$(T m_lang)"
        "$(T m_display)"
        "$(T m_network)"
        "$(T m_exit)"
    )
    CUR=0  # default: Edudisplej server
    menu_loop "${MENU_ITEMS[@]}"
	
	#tesztelésre vár
    case "$CUR" in
        0) auto_set_edserver_mode; exit 0 ;;
        1)
            echo "MODE=STANDALONE" > "$CONFIG"
            echo "Installing Apache2, MariaDB, PHP..." | tee -a "$LOGFILE"
            sudo apt update
			#Tesztelni - még nem stabil:
            sudo apt install -y apache2 mariadb-server php
            sudo systemctl enable apache2
            sudo systemctl enable mariadb
            echo "STANDALONE" > "$MODEFILE"
            ;;
        2) change_language ;;
        3)
		#Felbontas
            SUB_ITEMS=("Show resolution" "Set resolution" "Back")
            [ "$LANGUAGE" = "sk" ] && SUB_ITEMS=("Zobraz rozlisenie" "Nastav rozlisenie" "Spat")
            CUR=0; menu_loop "${SUB_ITEMS[@]}"
            case "$CUR" in
                0) show_resolution ;;
                1) set_resolution ;;
                *) : ;;
            esac
            ;;
        4)
            SUB_ITEMS=("Wi-Fi setup" "IP settings (DHCP/Static)" "Back")
            [ "$LANGUAGE" = "sk" ] && SUB_ITEMS=("Wi-Fi nastavenie" "IP nastavenia (DHCP/Static)" "Spat")
            CUR=0; menu_loop "${SUB_ITEMS[@]}"
            case "$CUR" in
                0) wifi_setup ;;
                1) net_setup ;;
                *) : ;;
            esac
            ;;
        5) : ;; # Exit
    esac
fi


# Final screen
clear
ascii_letter_banner "$(T ed_banner)"

if grep -q "EDUDISPLEJ_SERVER" "$MODEFILE" 2>/dev/null; then
    URL_SET="$(grep -m1 '^KIOSK_URL=' "$CONFIG" 2>/dev/null | cut -d'=' -f2)"
    [ -z "$URL_SET" ] && URL_SET="$DEFAULT_URL"
    ensure_kiosk_autostart "$URL_SET"


        echo "[INFO] preparing X display :0 (cleanup)..." | tee -a "$LOGFILE"
        sudo pkill -9 Xorg X xinit openbox chromium || true
        sudo rm -f /tmp/.X0-lock /tmp/.X11-unix/X0
        rm -f /home/edudisplej/.Xauthority

        echo "[INFO] starting X server..." | tee -a "$LOGFILE"
        # Start X without chvt and using proper xinit without keeptty flag
        # This allows X to allocate VT automatically and systemd-logind to track the session properly
        sudo -u edudisplej /usr/bin/xinit /home/edudisplej/init/xclient.sh -- :0 vt7 -nolisten tcp &

        exit 0

fi

echo "Current mode: $(cat "$MODEFILE" 2>/dev/null || echo "NONE")"
exit 0

