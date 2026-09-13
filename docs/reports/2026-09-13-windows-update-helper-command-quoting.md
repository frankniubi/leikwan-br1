# Windows update helper: the pasted line could not run in PowerShell (2026-09-13)

## Report

On `/tenant/clients` the Windows Update Helper card prints a one-liner to paste
on a stuck host. Pasting it produced:

    At line:1 char:190
    + ... nhost.com/artifacts/leikwan-bridge-repair.cmd?v=2026.09.13.1&sha256=a ...
    +                                                                 ~
    The ampersand (&) character is not allowed. The & operator is reserved for
    future use; wrap an ampersand in double quotation marks ("&") to pass it as
    part of a string.

## Why

The line is parsed **twice** - once by the shell it is pasted into, once by the
child `powershell` that parses the `-Command` payload - and the old generator
was wrong for both:

    powershell -NoProfile -ExecutionPolicy Bypass -Command "$h = Join-Path $env:TEMP leikwan-bridge-repair.cmd; Invoke-WebRequest -UseBasicParsing <URL> -OutFile $h; Start-Process -FilePath $h -Verb RunAs"

1. **`$h` / `$env:TEMP` are expanded by the parent.** They sit inside double
   quotes, so a PowerShell parent substitutes them *before* the child runs. The
   child then received ` = Join-Path C:\Users\...\Temp ...` and could not
   work at all. (Pasted into cmd it happened to survive, because cmd does not
   expand `$` - which is why this shipped.)
2. **The URL is unquoted.** `clientAppArtifactUrl()` always appends
   `?v=<version>&sha256=<hash>`, so once the outer quotes are consumed the
   child's parser met a bare `&` and refused the whole line.

Verified by executing both variants in both shells on Windows 11:

| pasted into | old line | new line |
|---|---|---|
| Windows PowerShell 5.1 | `= : The term '=' is not recognized` | downloads, 11,610 B |
| cmd | downloads | downloads, 11,610 B |

## Fix

    powershell -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -UseBasicParsing '<URL>' -OutFile (Join-Path ([System.IO.Path]::GetTempPath()) leikwan-bridge-repair.cmd); Start-Process -FilePath (Join-Path ([System.IO.Path]::GetTempPath()) leikwan-bridge-repair.cmd) -Verb RunAs"

Two rules, which hold in cmd, Windows PowerShell and pwsh alike:

* **No `$` anywhere in the payload.** The temp path is resolved by the *child*
  through `[System.IO.Path]::GetTempPath()`, so there is nothing left for a
  parent shell to expand.
* **The URL is a single-quoted PowerShell literal** (`powershellSingleQuote()`,
  quotes escaped by doubling), so the child's parser never meets a bare `&`.
  A hostile URL containing a quote cannot break out of the literal.

## Verification

* Live panel: the rendered command is 433 chars, contains no `$`, wraps the real
  `...?v=2026.09.13.1&sha256=ad46bc95...` URL in single quotes, parses as a
  payload, and its download leg executed for real under **both** a PowerShell and
  a cmd parent - 11,610 bytes, which is exactly `windows_update_helper.size_bytes`
  in the manifest.
* `master/tests/windows_update_helper_quoting_test.php` (8 checks) pins: no `$`,
  single double-quoted outer argument, single-quoted URL, ampersands only inside
  quotes, `-Verb RunAs` preserved, temp path resolved in the child, and quote
  escaping for a hostile URL.

## Not the same bug

`clientWindowsManagerCommand()` and `clientForwardWindowsInstallCommand()` build
PowerShell snippets that are pasted **directly** into PowerShell (no nested
`-Command`), so their double-quoted URLs are already safe. Checked rather than
assumed.
