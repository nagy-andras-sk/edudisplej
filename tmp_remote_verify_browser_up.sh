sudo systemctl restart edudisplej-kiosk.service
sleep 20
echo '==== service states ===='
systemctl is-active edudisplej-kiosk.service || true
systemctl is-active edudisplej-command-executor.service || true
echo '==== running processes ===='
pgrep -a -f 'surf|xterm|edudisplej_terminal_script|openbox|Xorg' || true
echo '==== terminal_script recent ===='
tail -n 140 /opt/edudisplej/logs/terminal_script.log || true
echo '==== openbox recent ===='
tail -n 90 /tmp/openbox-autostart.log || true