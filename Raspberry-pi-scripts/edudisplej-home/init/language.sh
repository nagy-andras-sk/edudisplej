#!/bin/bash

change_language() {
    CH=$(whiptail --menu "$(T lang_select)" 12 50 2 \
        "en" "$(T lang_en)" \
        "sk" "$(T lang_sk)" 3>&1 1>&2 2>&3)
    [ -n "$CH" ] && LANGUAGE="$CH"
    # persist to config
    if grep -q '^LANG=' "$CONFIG" 2>/dev/null; then
        sed -i "s/^LANG=.*/LANG=$LANGUAGE/" "$CONFIG"
    else
        echo "LANG=$LANGUAGE" >> "$CONFIG"
    fi
}
