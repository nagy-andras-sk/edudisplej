#!/bin/bash

show_resolution() {
    RES=$(fbset | grep geometry | awk '{print $2"x"$3}')
    whiptail --infobox "$(T res_current) $RES" 8 60
    sleep 3
}

set_resolution() {
    NEWRES=$(whiptail --inputbox "$(T res_prompt)" 8 60 --title "Display" 3>&1 1>&2 2>&3)
    [ -n "$NEWRES" ] && sudo fbset -g $(echo "$NEWRES" | sed 's/x/ /') 32
    whiptail --infobox "$(T res_set_to) $NEWRES" 8 60
    sleep 2
