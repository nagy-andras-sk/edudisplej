echo '--- http log tail ---'
tail -n 120 /tmp/edudisplej-runtime-http.log 2>/dev/null || true

echo '--- quick url checks ---'
for u in /loop_player.html /unconfigured.html /modules/clock/m_clock.html /modules/clock/module.json /modules/clock/.metadata.json; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8765${u}" || true)
  echo "${u} ${code}"
done

echo '--- surf cmd ---'
ps -ef | grep -E 'surf -F' | grep -v grep
