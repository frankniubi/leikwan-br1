set +e
hostname
date -Is
echo "agent_active=$(systemctl is-active leikwan-client-agent.service 2>/dev/null)"
echo "agent_enabled=$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null)"
systemctl status --no-pager -l leikwan-client-agent.service | sed -n '1,18p'
echo "f47_active=$(systemctl is-active leikwan-client-47.service 2>/dev/null)"
echo "--- listeners ---"
ss -ltnp | grep -e 11035 -e 22740 -e 2531 -e 1431 -e 5201 -e 7000 || true
echo "--- processes ---"
ps -eo pid,ppid,etime,cmd | grep -e leikwan-client-agent -e leikwan-bond -e leikwan-ingress -e iperf3 | grep -v grep || true
echo "--- files ---"
ls -l /etc/leikwan/mptcp-forward-47.json /usr/local/bin/leikwan-client-start-47.sh /var/lib/leikwan-client-agent/bond-endpoint-health.json /var/lib/leikwan-client-agent/latency-primary-state.json 2>/dev/null || true
echo "--- mptcp47 ---"
jq . /etc/leikwan/mptcp-forward-47.json 2>/dev/null | sed -n '1,220p' || true
echo "--- start47 head ---"
sed -n '1,220p' /usr/local/bin/leikwan-client-start-47.sh 2>/dev/null || true