F=/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/ClientAgentController.php
echo '### panel vs repo size'
wc -c $F
echo '### panel lines 1010-1035'
sed -n '1005,1035p' $F
echo '### grep ingest in panel copy'
grep -n 'ultraClientStatus->ingest' $F
echo '### grep runtime_attestation in panel copy'
grep -n 'runtime_attestation' $F | head