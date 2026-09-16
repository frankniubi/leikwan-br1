ls -ld /root/leikwan-br1-backups /root/leikwan-br1-backups/homepage-pre-redesign 2>/dev/null
ls /root/leikwan-br1-backups 2>/dev/null | head
echo ---DIG---
getent hosts v3.leikwanhost.com || true
dig +short v3.leikwanhost.com A 2>/dev/null || true
dig +short v3.leikwanhost.com CNAME 2>/dev/null || true
echo ---IP---
hostname -I
ip -4 addr | sed -n "s/inet //p"
echo ---BT-NGINX-ALT---
ls /etc/systemd/system | grep -Ei "nginx|php|httpd|caddy"
systemctl list-units --type=service --state=running | sed -n "1,80p"