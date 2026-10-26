# Files that only ever existed on the production host

`toggle_gate.php.txt` was found in `master/public/` on the live panel (2026-09-24, never in git).
It flips `forwards.enabled` from the command line with no authentication, so it is archived
here as text and deliberately not placed under `master/public/`, which would redeploy it to
the web root. It is safe to delete from the server; run it as `php toggle_gate.php on|off ID1 ID2`
from `master/public` only if you really need it.

`master/public/privacy.html`, `master/public/support.html` (the Bridge iOS privacy and support
pages, last edited 2026-10-23) and `master/cli/republish-combo9.php` were copied from the host
byte for byte so a full `public/` deploy no longer loses them.
