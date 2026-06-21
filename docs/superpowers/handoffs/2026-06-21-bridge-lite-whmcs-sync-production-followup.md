# Bridge-lite WHMCS sync production follow-up

## Outcome
- real production sync succeeded
- final sync output: total_clients=419, synced=419, sync_job_id=1, status=succeeded
- post-sync DB state: users=418, jobs=1

## Root causes found
1. stale `/bridge-lite/` subtree vs live top-level app
2. live schema missing `users.speed_limit_mbps`
3. WHMCS pagination drift after page 1
4. repeated `GetClientsProducts` calls leaking file descriptors

## Live fixes applied
- patched top-level `src/Support/WhmcsClient.php`
- added live `users.speed_limit_mbps`
- reran sync under `ulimit -n 8192`

## Verification
- 419 source rows fetched
- 419 synced rows reported
- 418 local users persisted
- last sync job status=succeeded

## Why 419 source rows became 418 users
- duplicate WHMCS email row collapsed under email-keyed local upsert
- duplicated source email: `lq@hujiajun.com`

## Remaining caveat
- FD leak is still present in code
- high-`ulimit` wrapper is still required for long CLI sync runs