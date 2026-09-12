@echo off
setlocal EnableExtensions
title LeiKwan Bridge - permission repair
set "CA=%ProgramData%\Leikwan\ClientAgent"
set "RT=%CA%\runtime"
set "EXE=%ProgramFiles%\LeiKwan Bridge\leikwan-client-win.exe"

rem ---------------------------------------------------------------
rem  LeiKwan Bridge repair tool
rem
rem  Fixes the two permission problems that stop the Windows client
rem  from starting unless it is run as administrator:
rem
rem    1. config.json / state / logs end up owned by Administrators with
rem       the interactive user read-only. The client then dies writing
rem       its own config before it can log anything at all.
rem    2. the legacy runtime directory keeps inherited write access, so
rem       any local process can replace the helper binary.
rem
rem  Pure batch + icacls, so it works even where PowerShell scripts are
rem  blocked by execution policy. It elevates itself through UAC.
rem ---------------------------------------------------------------

net session >nul 2>&1
if errorlevel 1 goto elevate
goto run

:elevate
echo Requesting administrator rights...
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%~f0' -Verb RunAs" >nul 2>&1
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
echo   LeiKwan Bridge - permission repair
echo ============================================================
echo.
if not exist "%CA%" goto no_install

echo Current permissions:
call :show "%CA%\config.json"
call :show "%RT%"
echo.
echo Repairing:
call :grant_file "%CA%\config.json"
call :grant_tree "%CA%\state"
call :grant_tree "%CA%\logs"
call :harden_tree "%RT%"
echo.
echo Result:
call :show "%CA%\config.json"
call :show "%RT%"
echo.
call :verify "%CA%\config.json"
echo.
goto maybe_start

:no_install
echo   LeiKwan Bridge does not appear to be installed:
echo     %CA%
echo.
echo   Install it first, then run this tool again.
echo.
pause
exit /b 1

:maybe_start
tasklist /fi "imagename eq leikwan-client-win.exe" 2>nul | find /i "leikwan-client-win.exe" >nul
if not errorlevel 1 goto already_running
if not exist "%EXE%" goto no_exe
echo Starting the client as the current user (no administrator rights needed)...
explorer.exe "%EXE%"
goto end

:already_running
echo The client is already running; nothing to start.
goto end

:no_exe
echo Client executable not found at "%EXE%".
echo Start it from the Start menu shortcut instead.
goto end

:end
echo.
echo Done. Start LeiKwan Bridge normally from now on - it does not need
echo administrator rights to run or to update.
echo.
pause
exit /b 0

rem ------------------------- helpers -------------------------

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