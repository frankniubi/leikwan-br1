@echo off
rem leikwan - control the windowless LeiKwan Bridge client.
rem
rem   leikwan status ^| start ^| stop ^| restart ^| logs [-n N] ^| doctor ^| version
rem   leikwan update [--check]
rem
rem Service control and updates need an elevated shell.
setlocal
set "EXE=%~dp0leikwan-bridge-headless.exe"
if not exist "%EXE%" set "EXE=%ProgramFiles%\LeiKwan Bridge Headless\leikwan-bridge-headless.exe"
if not exist "%EXE%" (
  echo leikwan: client not found ^(looked next to this script and in "%%ProgramFiles%%\LeiKwan Bridge Headless"^) 1>&2
  exit /b 1
)
"%EXE%" --cli %*
exit /b %ERRORLEVEL%
