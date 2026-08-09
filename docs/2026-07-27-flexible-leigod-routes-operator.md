# Flexible Managed-Direction Route Seeder

Date: 2026-07-27

Use `master/cli/seed-flexible-leigod-routes.php` to create or update the initial
five managed-direction catalog rows, their combinations, five pinned single
routes, and one all-direction race route. The command never creates billing
plans, services, purchases, forwards, or tenant bindings.

## Safety Contract

- The default is a read-only dry-run. Database writes require `--apply`.
- Routes are inactive unless `--activate` is supplied together with `--apply`.
- Running `--apply` without `--activate` explicitly leaves or returns all six
  managed routes to inactive state.
- The command validates every TOML and expected SHA-256 before its first write.
- Output contains counts and readiness only. It does not print TOML, session
  data, or configuration hashes.
- Initial rows are found by external line ID and stable internal names, so a
  repeated apply updates them instead of adding duplicates.

## Required Environment

Install each TOML as strict base64 in the panel environment and record its
independently obtained lowercase SHA-256:

```text
LEIGOD_LINE_TOML_447=<base64 TOML>
LEIGOD_LINE_SHA256_447=<64 lowercase hex characters>
LEIGOD_LINE_TOML_451=<base64 TOML>
LEIGOD_LINE_SHA256_451=<64 lowercase hex characters>
LEIGOD_LINE_TOML_453=<base64 TOML>
LEIGOD_LINE_SHA256_453=<64 lowercase hex characters>
LEIGOD_LINE_TOML_455=<base64 TOML>
LEIGOD_LINE_SHA256_455=<64 lowercase hex characters>
LEIGOD_LINE_TOML_456=<base64 TOML>
LEIGOD_LINE_SHA256_456=<64 lowercase hex characters>
```

Generate each expected hash from the original local artifact, not from a value
copied out of the panel environment. Keep the TOMLs and account session out of
Git, command output, tickets, and normal logs.

The shared `LEIGOD_SESSION_JSON_B64` remains part of node management. The
seeder does not read, replace, or print it.

## Dry-Run

Back up the panel database and `.env`, then run from the deployed repository:

```bash
php master/cli/seed-flexible-leigod-routes.php
```

The successful result reports:

- readiness: five ready, zero missing, zero hash mismatches;
- desired: five catalog rows, five combinations, five single routes, one race
  route;
- create/update counts for the current database;
- zero writes in dry-run mode.

Any missing, invalid-base64, missing-expected-hash, or hash-mismatched line
fails the complete preflight before a database write is possible.

## Flexible Placement And Probes

Override the common node group and TCP target when the deployment differs from
the defaults:

```bash
php master/cli/seed-flexible-leigod-routes.php \
  --node-group=shanghai-alive \
  --tcp-probe-host=162.245.221.78 \
  --tcp-probe-port=80
```

UDP remains unconfigured by default. Supply all three UDP options only after a
complete SOCKS5 UDP data-plane reply has been proven:

```bash
php master/cli/seed-flexible-leigod-routes.php \
  --udp-probe-host=<host> \
  --udp-probe-port=<port> \
  --udp-probe-method=dns
```

`--udp-probe-method` accepts `dns` or `echo`. A SOCKS5 `UDP ASSOCIATE`
handshake without a returned data-plane packet is not proof.

## Apply Inactive State

After reviewing the dry-run counts, apply the catalog and route definitions
without making them tenant-eligible:

```bash
php master/cli/seed-flexible-leigod-routes.php \
  --apply \
  --node-group=shanghai-alive \
  --tcp-probe-host=162.245.221.78 \
  --tcp-probe-port=80
```

Run the same command a second time and confirm that all desired objects report
as updates and the object counts remain `5 + 5 + 6`.

## Activation Gate

Do not activate from catalog readiness alone. First confirm on the intended
node group that:

1. Each line service is active with the expected configuration hash.
2. Each single candidate has fresh healthy TCP state and reaches the intended
   target through its SOCKS path.
3. One node has at least two distinct fresh healthy combinations before the
   race route is exposed.
4. Stopping the selected line moves new traffic to a different candidate, and
   recovery observes probation and minimum-hold behavior.

Then explicitly activate using the same placement and probe overrides used for
the inactive apply:

```bash
php master/cli/seed-flexible-leigod-routes.php \
  --apply \
  --activate \
  --node-group=shanghai-alive \
  --tcp-probe-host=162.245.221.78 \
  --tcp-probe-port=80
```

`--activate` without `--apply` is rejected.

## Rollback

Disable the six seeded routes while preserving catalog and combination state:

```bash
php master/cli/seed-flexible-leigod-routes.php \
  --apply \
  --node-group=shanghai-alive \
  --tcp-probe-host=162.245.221.78 \
  --tcp-probe-port=80
```

Verify the inactive route state before rolling back agent or panel artifacts.
Do not delete unrelated forwards, services, plans, or tenant data. Restore the
database backup only when a schema rollback is actually required.
