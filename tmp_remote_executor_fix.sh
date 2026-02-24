set -e
sudo cp -f /home/edudisplej/edudisplej-command-executor.sh /opt/edudisplej/init/edudisplej-command-executor.sh
sudo chmod +x /opt/edudisplej/init/edudisplej-command-executor.sh
sudo systemctl restart edudisplej-command-executor.service
sleep 2
systemctl status edudisplej-command-executor.service --no-pager -l | sed -n '1,30p'
journalctl -u edudisplej-command-executor.service -n 25 --no-pager || true
