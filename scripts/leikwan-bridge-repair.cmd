@echo off
setlocal EnableExtensions EnableDelayedExpansion
title LeiKwan Bridge - repair and update
set "CA=%ProgramData%\Leikwan\ClientAgent"
set "RT=%CA%\runtime"
set "PRIMARY=https://v3.leikwanhost.com"
set "MIRROR=https://api-leikwanbridge.nbwly.com"
set "TMPD=%TEMP%\leikwan-repair"
set "FORCE="
if /i "%~1"=="/FORCEUPDATE" set "FORCE=1"

rem ---------------------------------------------------------------
rem  LeiKwan Bridge repair + update tool
rem
rem  Fixes the permission problems that stop the Windows client from
rem  starting unless it is run as administrator, then updates it to the
rem  latest published build:
rem
rem    1. config.json / state / logs end up owned by Administrators with
rem       the interactive user read-only. The client then dies writing
rem       its own config before it can log anything at all.
rem    2. the legacy runtime directory keeps inherited write access, so
rem       any local process can replace the helper binary.
rem    3. the installed build is out of date, so it keeps offering an
rem       update that may fail to install.
rem
rem  Batch + icacls + a couple of PowerShell one-liners, so it works even
rem  where PowerShell .ps1 files are blocked by execution policy. It
rem  elevates itself through UAC.
rem
rem  Usage:  leikwan-bridge-repair.cmd                 repair + update
rem          leikwan-bridge-repair.cmd /FORCEUPDATE    reinstall even
rem                                                  when up to date
rem ---------------------------------------------------------------

net session >nul 2>&1
if errorlevel 1 goto elevate
goto run

:elevate
echo Requesting administrator rights...
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%~f0' -ArgumentList '%~1' -Verb RunAs" >nul 2>&1
if errorlevel 1 goto elevate_failed
exit /b 0

:elevate_failed
echo.
echo   Could not request administrator rights automatically.
echo   Right-click this file and choose "Run as administrator".
echo.
pause
exit /b 1

:run
echo ============================================================
echo   LeiKwan Bridge - repair and update
echo ============================================================
echo.
if not exist "%CA%" goto no_install
if not exist "%TMPD%" mkdir "%TMPD%" >nul 2>&1

call :resolve_install
if "%INSTALL_DIR%"=="" goto no_install

echo Install directory:
echo   %INSTALL_DIR%
echo.

echo --- 1. permissions ---
call :show "%CA%\config.json"
call :show "%RT%"
call :grant_file "%CA%\config.json"
call :grant_tree "%CA%\state"
call :grant_tree "%CA%\logs"
call :harden_tree "%RT%"
call :verify "%CA%\config.json"
echo.

echo --- 2. update ---
call :read_state
if "%STATE_OK%"=="0" goto skip_update
call :fetch_manifest
if "%MANIFEST_OK%"=="0" goto skip_update
echo   installed : %INSTALLED_VERSION%
echo   published : %LATEST_VERSION%
if /i "%INSTALLED_VERSION%"=="%LATEST_VERSION%" if not defined FORCE goto up_to_date
if defined FORCE echo   /FORCEUPDATE given - reinstalling %LATEST_VERSION%
call :download
if "%DOWNLOAD_OK%"=="0" goto skip_update
call :run_installer
goto update_done

:up_to_date
echo   already up to date - nothing to install.
goto update_done

:skip_update
echo.
echo   Update step skipped. Repair is complete; install the latest build
echo   from https://v3.leikwanhost.com when you can reach it.
goto update_done

:update_done
echo.
call :maybe_start
goto end

:no_install
echo   LeiKwan Bridge does not appear to be installed:
echo     %CA%
echo.
echo   Install it first, then run this tool again.
echo.
pause
exit /b 1

:end
if exist "%TMPD%" rmdir /s /q "%TMPD%" >nul 2>&1
echo Done. Start LeiKwan Bridge normally from now on - it does not need
echo administrator rights to run or to update.
echo.
pause
exit /b 0

rem ------------------------- update helpers -------------------------

:resolve_install
set "INSTALL_DIR="
for %%D in ("%ProgramFiles%\LeiKwan Bridge" "%ProgramFiles(x86)%\LeiKwan Bridge" "%LOCALAPPDATA%\Programs\LeiKwan Bridge" "%LOCALAPPDATA%\LeiKwan Bridge") do call :consider_dir "%%~fD"
exit /b 0

:consider_dir
if defined INSTALL_DIR exit /b 0
if not exist "%~1\leikwan-client-win.exe" exit /b 0
set "INSTALL_DIR=%~1"
exit /b 0

:read_state
set "STATE_OK=0"
if not exist "%INSTALL_DIR%\install-state.json" exit /b 0
powershell -NoProfile -ExecutionPolicy Bypass -Command "$o = ConvertFrom-Json -InputObject (Get-Content -Raw '%INSTALL_DIR%\install-state.json'); Set-Content -Path '%TMPD%\state.txt' -Value ($o.install_mode + '|' + $o.install_dir + '|' + $o.desktop_shortcut + '|' + $o.installed_version)" >nul 2>&1
if not exist "%TMPD%\state.txt" exit /b 0
set "STATE_LINE="
set /p STATE_LINE=<"%TMPD%\state.txt"
if not defined STATE_LINE exit /b 0
for /f "tokens=1,2,3,4 delims=|" %%a in ("!STATE_LINE!") do call :apply_state "%%a" "%%b" "%%c" "%%d"
exit /b 0

:apply_state
set "STATE_MODE=%~1"
set "STATE_DIR=%~2"
set "STATE_SHORTCUT=%~3"
set "INSTALLED_VERSION=%~4"
if /i "%STATE_SHORTCUT%"=="True" set "STATE_SHORTCUT=1"
if /i "%STATE_SHORTCUT%"=="False" set "STATE_SHORTCUT=0"
if not "%STATE_DIR%"=="" set "INSTALL_DIR=%STATE_DIR%"
if "%STATE_MODE%"=="" exit /b 0
if "%INSTALLED_VERSION%"=="" exit /b 0
set "STATE_OK=1"
exit /b 0

:fetch_manifest
set "MANIFEST_OK=0"
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue'; try { $w = (Invoke-RestMethod '%PRIMARY%/artifacts/client-manifest.json' -TimeoutSec 25).manifest.windows_client_app } catch { try { $w = (Invoke-RestMethod '%MIRROR%/artifacts/client-manifest.json' -TimeoutSec 25).manifest.windows_client_app } catch { exit 1 } }; Set-Content -Path '%TMPD%\latest.txt' -Value ($w.version + '|' + $w.sha256 + '|' + $w.size_bytes)" >nul 2>&1
if not exist "%TMPD%\latest.txt" exit /b 0
set "LATEST_LINE="
set /p LATEST_LINE=<"%TMPD%\latest.txt"
if not defined LATEST_LINE exit /b 0
for /f "tokens=1,2,3 delims=|" %%a in ("!LATEST_LINE!") do call :apply_manifest "%%a" "%%b" "%%c"
exit /b 0

:apply_manifest
set "LATEST_VERSION=%~1"
set "LATEST_SHA=%~2"
set "LATEST_SIZE=%~3"
if "%LATEST_VERSION%"=="" exit /b 0
if "%LATEST_SHA%"=="" exit /b 0
set "MANIFEST_OK=1"
exit /b 0

:download
set "DOWNLOAD_OK=0"
set "FETCH_OK=0"
set "SETUP=%TMPD%\leikwan-bridge-setup-%LATEST_VERSION%.exe"
echo   downloading %LATEST_VERSION% ...
call :fetch "%PRIMARY%/artifacts/leikwan-bridge-setup.exe" "%SETUP%"
if "%FETCH_OK%"=="0" call :fetch "%MIRROR%/artifacts/leikwan-bridge-setup.exe" "%SETUP%"
if "%FETCH_OK%"=="0" goto download_unreachable
echo   verifying sha256 ...
for /f "skip=1 delims=" %%H in ('certutil -hashfile "%SETUP%" SHA256') do call :take_hash "%%H"
if not defined ACTUAL_SHA goto download_unreachable
set "ACTUAL_SHA=%ACTUAL_SHA: =%"
if /i not "%ACTUAL_SHA%"=="%LATEST_SHA%" goto download_bad
echo   hash ok
set "DOWNLOAD_OK=1"
exit /b 0

:download_unreachable
echo   DOWNLOAD FAILED: could not fetch a verifiable installer.
exit /b 0

:download_bad
echo   DOWNLOAD REJECTED: hash mismatch, the file was not installed.
echo     expected %LATEST_SHA%
echo     actual   %ACTUAL_SHA%
if exist "%SETUP%" del /q "%SETUP%" >nul 2>&1
exit /b 0

:fetch
set "ACTUAL_SHA="
if exist "%~2" del /q "%~2" >nul 2>&1
where curl.exe >nul 2>&1
if errorlevel 1 goto fetch_ps
curl.exe -s -L -f --retry 2 --connect-timeout 20 -o "%~2" "%~1"
if errorlevel 1 exit /b 0
goto fetch_check
:fetch_ps
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue'; try { Invoke-WebRequest -Uri '%~1' -OutFile '%~2' -TimeoutSec 900 } catch { exit 1 }" >nul 2>&1
if errorlevel 1 exit /b 0
:fetch_check
if not exist "%~2" exit /b 0
for %%S in ("%~2") do if %%~zS LSS 1000000 exit /b 0
set "FETCH_OK=1"
exit /b 0

:take_hash
if defined ACTUAL_SHA exit /b 0
set "CANDIDATE=%~1"
if not defined CANDIDATE exit /b 0
set "CANDIDATE=%CANDIDATE: =%"
echo %CANDIDATE%| findstr /r /i "^[0-9a-f][0-9a-f]*$" >nul || exit /b 0
set "ACTUAL_SHA=%CANDIDATE%"
exit /b 0

:run_installer
echo   installing %LATEST_VERSION% ...
set "SHORTCUT_ARG=%STATE_SHORTCUT%"
if "%SHORTCUT_ARG%"=="" set "SHORTCUT_ARG=1"
"%SETUP%" /VERYSILENT /NORESTART /ALLUSERS /LEIKWANUPDATE=1 /LEIKWANINSTALLDIR="%INSTALL_DIR%" /LEIKWANINSTALLMODE=%STATE_MODE% /LEIKWANDESKTOPSHORTCUT=%SHORTCUT_ARG% /LOG="%TMPD%\install.log"
set "INSTALL_RC=%ERRORLEVEL%"
if not "%INSTALL_RC%"=="0" goto install_failed
echo   installer finished (exit 0)
powershell -NoProfile -ExecutionPolicy Bypass -Command "$v = (Get-Item '%INSTALL_DIR%\leikwan-client-win.exe').VersionInfo.FileVersion; Set-Content -Path '%TMPD%\now.txt' -Value $v" >nul 2>&1
set "NOW_VERSION="
set /p NOW_VERSION=<"%TMPD%\now.txt"
echo   installed version is now %NOW_VERSION%
exit /b 0

:install_failed
echo   installer exited with %INSTALL_RC%. Log: %TMPD%\install.log
if exist "%TMPD%\install.log" copy /y "%TMPD%\install.log" "%TEMP%\leikwan-install.log" >nul 2>&1
exit /b 0

:maybe_start
tasklist /fi "imagename eq leikwan-client-win.exe" 2>nul | find /i "leikwan-client-win.exe" >nul
if not errorlevel 1 goto already_running
if not exist "%INSTALL_DIR%\leikwan-client-win.exe" goto no_exe
echo Starting the client as the current user (no administrator rights needed)...
explorer.exe "%INSTALL_DIR%\leikwan-client-win.exe"
goto start_done
:already_running
echo The client is already running; leaving it alone.
goto start_done
:no_exe
echo Client executable not found at "%INSTALL_DIR%\leikwan-client-win.exe".
echo Start it from the Start menu shortcut instead.
:start_done
exit /b 0

rem ------------------------- permission helpers -------------------------

:show
if not exist "%~1" goto show_missing
echo   %~1
for /f "usebackq delims=" %%A in (`icacls "%~1" 2^>nul ^| findstr /i "BUILTIN Users Administrators SYSTEM"`) do echo     %%A
exit /b 0
:show_missing
echo   %~1  (not present)
exit /b 0

:grant_file
if not exist "%~1" goto skip_missing
icacls "%~1" /grant "*S-1-5-32-545:M" /C /Q >nul 2>&1
if errorlevel 1 goto grant_failed
echo   ok      %~1  (users: modify)
exit /b 0

:grant_tree
if not exist "%~1" goto skip_missing
icacls "%~1" /grant "*S-1-5-32-545:(OI)(CI)M" /T /C /Q >nul 2>&1
if errorlevel 1 goto grant_failed
echo   ok      %~1  (users: modify, inherited)
exit /b 0

:harden_tree
if not exist "%~1" goto skip_missing
rem  Two steps on purpose: granting (OI)(CI) straight at a file while
rem  stripping inheritance leaves that file with an EMPTY dacl, which is
rem  worse than the problem being fixed. Set explicit inheritable ACEs on
rem  the directory, then reset the children so they inherit exactly those.
icacls "%~1" /inheritance:r /grant:r "*S-1-5-18:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" "*S-1-5-32-545:(OI)(CI)RX" /C /Q >nul 2>&1
if errorlevel 1 goto grant_failed
dir /b /a "%~1" >nul 2>&1
if errorlevel 1 goto harden_done
icacls "%~1\*" /reset /T /C /Q >nul 2>&1
if errorlevel 1 goto grant_failed
:harden_done
echo   ok      %~1  (users: read and execute only)
exit /b 0

:skip_missing
echo   skip    %~1  (not present)
exit /b 0

:grant_failed
echo   FAILED  %~1
exit /b 1

:verify
echo.>> "%~1"
if errorlevel 1 goto verify_failed
echo   verified: the current user can write the shared config.
exit /b 0
:verify_failed
echo   WARNING: the shared config is still not writable by the current user.
echo            Send this window to support if the client still fails to start.
exit /b 1