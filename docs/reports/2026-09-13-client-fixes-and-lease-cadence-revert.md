# Client fixes 0.1.20.45, and what I deliberately did NOT ship (2026-09-13)

## Shipped

**App 0.1.20.45** - `253683f74d96631c05f4d489280ff59be813687c98a961fe1488bed13c39b8d5`,
69,541,750 B, runtime pin unchanged (`226cc3aa` = 2026.09.13.2, so no runtime
drift). Published to both entrances; served bytes hash to the published value and
the publish script asserts pin == published runtime.

1. **The startup `proxy_mode_required` abort.** `PopulateProxyUi()` runs while
   the window is still being built, before any proxy mode is selected, and
   `UpdateProxyModeVisibility()` asked for the selected mode unguarded. The throw
   abandoned the rest of the local-proxy setup and logged

       [WRN] local proxy initialization failed
       System.InvalidOperationException: proxy_mode_required
          at MainWindow.GetSelectedProxyMode()

   on **every start**. The visibility update now treats "nothing selected yet" as
   "keep the card hidden" (`TryGetSelectedProxyMode`), while
   `GetSelectedProxyMode` keeps its contract for its other caller.

2. **`wg binary not found ... skipping WireGuard identity` was a Warning for an
   expected state.** The Windows installer ships no `wg.exe` - it is not one of
   `build-windows-client-setup.ps1`'s inputs - so every fresh install reported a
   fault-looking line. It is Debug now and says what it means: the WireGuard
   identity is optional and the panel treats a missing key as unknown.

Tests: Core **508/508**, App **106/106**.

## Built, tested, reverted: the lease cadence change

I implemented the fix I proposed - fall back to the expiry of the grant the
runtime is already running with when the policy's renewal point is unusable, plus
a hard floor between two grant requests for one forward.

**It broke two deliberate tests**, and they are right to exist:

* `SyncOnceAsync_NotModified_RefreshesRunningUltraLease` - an unchanged poll is
  *supposed* to refresh the running grant.
* `SyncOnceAsync_TargetedGrantRefreshDoesNotProbeUnrelatedRunningUltraForwards` -
  a targeted grant refresh is *supposed* to go out immediately.

Both are the "no usable `renew_after` -> refresh every poll" contract that the
churn comes from, but they are the documented behaviour, not an accident.

**And the premise is now in doubt.** The panel *does* emit a renewal point on
every v2 grant (`LeigodTokenBroker.php:122`), so the intended gate exists. For
the churn to happen that field has to be unusable in the client's copy - or the
batches come from `ReconcileForwardAsync`, the full-pull path, which has **no
renewal guard at all**. I could not tell which: the panel caches grants
encrypted (`u2s.` + binary, not JSON), so the live `renew_after` cannot be read
out of the database, and I have no captured client payload.

So I reverted it rather than ship a semantic change that contradicts two tests
and rests on a premise I cannot yet demonstrate. Next step is a measurement, not
a patch: log the effective renewal point per forward on the client (policy value
vs delivered-grant expiry vs which path requested), then fix the path that
actually mints the batches.

Current state, for the record: ~8.7 grant rows a minute per forward - one
four-candidate batch every ~19 seconds.

## Not fixed, worth knowing

* **Two device identities report for client 3.** `legacy-8631a9c47b28828b1`
  carries `udp_arm_failed`, `windows-client-3-205f0cb...` carries
  `probe_udp_failed`. The `legacy-` form is the **panel's** fallback
  (`ClientOnlineSessionService.php:256` = `legacy-<sha1(user-agent|ip)>`) for a
  request that arrives without a device id, so some client path is not sending
  one - and that splits per-device reputation attribution. I looked for a second
  install, service or scheduled task on this machine and found none; the other
  sender is most likely another device on client 3. Not fixed.
* **`versions.json` re-hashing, the 30 s download timeout, the update-helper
  quoting, the probe-failure evidence and the panel deploy of it** are all done
  and deployed earlier today.
