#!/bin/bash
#Alap configok
LOGFILE="/var/log/edudisplej.log"
CONFIG="/home/edudisplej/edudisplej.conf"
MODEFILE="/home/edudisplej/.mode"
DEFAULT_URL="https://www.edudisplej.sk/edserver/demo/client"
COUNTDOWN=60

# Load language from config if present
LANGUAGE="en"
if [ -f "$CONFIG" ]; then
    . "$CONFIG"
    [ -n "$LANG" ] && LANGUAGE="$LANG"
fi

# ASCII i18n
T() {
    case "$LANGUAGE" in
      sk)
        case "$1" in
          title) echo "EDUDISPLEJ";;
          connecting) echo "Pripajam sa na siet...";;
          waiting_net) echo "Cakam na pripojenie na internet...";;
          hostname_change) echo "Menim hostname na:";;
          auto_server_set) echo "Automaticky nastavujem EDUDISPLEJ SERVER s URL:";;
          will_reboot) echo "System sa restartuje...";;
          menu_title) echo "EduDisplej Menu";;
          m_server) echo "Edudisplej server";;
          m_standalone) echo "Standalone (lokalny server)";;
          m_lang) echo "Change language";;
          m_display) echo "Change display settings";;
          m_network) echo "Change network settings";;
          m_exit) echo "Exit";;
          countdown_hint) echo "Ak neurobite nic, za";;
          seconds) echo "sekund bude zvolena aktualna polozka";;
          press_any_key) echo "Stlacte lubovolnu klavesu pre interakciu";;
          res_current) echo "Aktualne rozlisenie:";;
          res_prompt) echo "Zadajte rozlisenie (napr. 1024x768):";;
          res_set_to) echo "Rozlisenie nastavene na:";;
          wifi_ssid) echo "Zadajte Wi-Fi SSID:";;
          wifi_pass) echo "Zadajte Wi-Fi heslo:";;
          wifi_done) echo "Wi-Fi pripojene / ulozene";;
          net_mode) echo "Vyberte typ siete:";;
          net_dhcp) echo "DHCP (automaticky)";;
          net_static) echo "Static (manualne)";;
          ip_addr) echo "IP adresa (napr. 192.168.1.50/24):";;
          gateway) echo "Brana (napr. 192.168.1.1):";;
          dns) echo "DNS (napr. 1.1.1.1,8.8.8.8):";;
          net_applied) echo "Sietove nastavenia aplikovane";;
          lang_select) echo "Select language:";;
          lang_en) echo "English";;
          lang_sk) echo "Slovak (ASCII)";;
          ed_banner) echo "EDUDISPLEJ";;
          *) echo "$1";;
        esac
        ;;
      en|*)
        case "$1" in
          title) echo "EDUDISPLEJ";;
          connecting) echo "Connecting to network...";;
          waiting_net) echo "Waiting for internet...";;
          hostname_change) echo "Changing hostname to:";;
          auto_server_set) echo "Auto-setting EDUDISPLEJ SERVER with URL:";;
          will_reboot) echo "System will reboot...";;
          menu_title) echo "EduDisplej Menu";;
          m_server) echo "Edudisplej server";;
          m_standalone) echo "Standalone (local server)";;
          m_lang) echo "Change language";;
          m_display) echo "Change display settings";;
          m_network) echo "Change network settings";;
          m_exit) echo "Exit";;
          countdown_hint) echo "If you do nothing, in";;
          seconds) echo "seconds the current item will be executed";;
          press_any_key) echo "Press any key to interact";;
          res_current) echo "Current resolution:";;
          res_prompt) echo "Enter resolution (e.g. 1024x768):";;
          res_set_to) echo "Resolution set to:";;
          wifi_ssid) echo "Enter Wi-Fi SSID:";;
          wifi_pass) echo "Enter Wi-Fi password:";;
          wifi_done) echo "Wi-Fi connected / saved";;
          net_mode) echo "Select network type:";;
          net_dhcp) echo "DHCP (automatic)";;
          net_static) echo "Static (manual)";;
          ip_addr) echo "IP address (e.g. 192.168.1.50/24):";;
          gateway) echo "Gateway (e.g. 192.168.1.1):";;
          dns) echo "DNS (e.g. 1.1.1.1,8.8.8.8):";;
          net_applied) echo "Network settings applied";;
          lang_select) echo "Select language:";;
          lang_en) echo "English";;
          lang_sk) echo "Slovak (ASCII)";;
          ed_banner) echo "EDUDISPLEJ";;
          *) echo "$1";;
        esac
        ;;
    esac
}

check_internet() {
    ping -c 1 google.com &> /dev/null
    return $?
}

# EDUDISPLEJ banner: (Generált felirat - dizájn)
ascii_letter_banner() {
    local text="$1"
    local font="standard"
    local -a letters
    local height=0
    local i r seg line

    # Build letter arts
    for ((i=0; i<${#text}; i++)); do
        local ch="${text:i:1}"
        mapfile -t letter_lines < <(figlet -f "$font" "$ch")
        # replace non-spaces with the letter itself
        for ((r=0; r<${#letter_lines[@]}; r++)); do
            letter_lines[$r]=$(echo "${letter_lines[$r]}" | sed "s/[^ ]/$ch/g")
        done
        # track max height
        if [ ${#letter_lines[@]} -gt $height ]; then height=${#letter_lines[@]}; fi
        # store into per-letter arrays via eval (bash limitation)
        eval "L_${i}=(\"\${letter_lines[@]}\")"
        letters+=("$i")
    done

    # print row by row, add one space between letter blocks
    for ((r=0; r<height; r++)); do
        line=""
        for idx in "${letters[@]}"; do
            eval "arr=(\"\${L_${idx}[@]}\")"
            seg="${arr[$r]}"
            [ -z "$seg" ] && seg=""
            if [ -n "$line" ]; then
                line="$line $seg"
            else
                line="$seg"
            fi
        done
        echo "$line"
    done
}

# Generalt felirathoz szukseges csomagok ellenorzese
ensure_base_deps() {
    sudo apt update
    sudo apt install -y figlet whiptail
}

# Menu vezerles (arrow keys, Enter, inactivity timeout -> current item)
draw_menu() {
    local items=("$@")
    clear
    figlet "$(T title)"
    echo ""
    echo "$(T countdown_hint) $COUNTDOWN $(T seconds)."
    echo "$(T press_any_key)"
    echo ""
    for i in "${!items[@]}"; do
        if [ "$i" -eq "$CUR" ]; then
            printf "> %s\n" "${items[$i]}"
        else
            printf "  %s\n" "${items[$i]}"
        fi
    done
    echo ""
    echo "Use UP/DOWN arrows, Enter to select."
}

menu_loop() {
    ensure_base_deps
    local items=("$@")
    local start_ts=$(date +%s)
    local last_ts=$start_ts
    while true; do
        draw_menu "${items[@]}"
        local now=$(date +%s)
        local remain=$((COUNTDOWN - (now - start_ts)))
        [ "$remain" -lt 0 ] && remain=0
        echo "Auto-select in: $remain s"
        read -rsn1 -t 1 key
        if [ $? -eq 0 ]; then
            last_ts=$(date +%s)
            if [[ "$key" == $'\e' ]]; then
                read -rsn2 -t 0.001 key2
                key+="$key2"
                case "$key" in
                    $'\e[A') [ "$CUR" -gt 0 ] && CUR=$((CUR-1));;
                    $'\e[B') [ "$CUR" -lt $(( ${#items[@]} - 1 )) ] && CUR=$((CUR+1));;
                esac
            elif [[ "$key" == "" ]]; then
                return 0
            fi
        else
            now=$(date +%s)
            if [ $((now - start_ts)) -ge "$COUNTDOWN" ]; then
                return 0
            fi
        fi
    done
}
