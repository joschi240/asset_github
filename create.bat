@echo off
setlocal EnableExtensions EnableDelayedExpansion

echo ==> Erzeuge Projektstruktur (modular, ISO-tauglich, erweiterbar)

REM -----------------------------
REM Basis
REM -----------------------------
call :MKDIR "src\css"
call :MKDIR "module\wartungstool\admin"
call :MKDIR "module\stoerungstool\admin"
call :MKDIR "module\energie\admin"
call :MKDIR "module\audit\admin"
call :MKDIR "module\ersatzteile\admin"

REM -----------------------------
REM Uploads (modulbasiert)
REM -----------------------------
call :MKDIR "uploads\wartungstool\wartungspunkte"
call :MKDIR "uploads\wartungstool\maschinen"
call :MKDIR "uploads\stoerungstool\tickets"
call :MKDIR "uploads\energie"
call :MKDIR "uploads\audit"
call :MKDIR "uploads\ersatzteile"

REM -----------------------------
REM Platzhalterdateien
REM -----------------------------
call :TOUCH "src\config.php"
call :TOUCH "src\db.php"
call :TOUCH "src\auth.php"
call :TOUCH "src\helpers.php"
call :TOUCH "src\css\main.css"

call :TOUCH "module\wartungstool\dashboard.php"
call :TOUCH "module\wartungstool\uebersicht.php"
call :TOUCH "module\stoerungstool\melden.php"
call :TOUCH "module\stoerungstool\inbox.php"
call :TOUCH "module\audit\report.php"

call :TOUCH "index.php"
call :TOUCH "login.php"
call :TOUCH "logout.php"

echo ==> Done.
echo Hinweis:
echo  - uploads\ muss vom Webserver beschreibbar sein
echo  - empfohlen: passende NTFS-Rechte setzen (z. B. via icacls)

endlocal
exit /b 0


:MKDIR
REM Erstellt Verzeichnis, wenn es nicht existiert. Bricht bei Fehler ab.
if not exist "%~1" (
  mkdir "%~1" >nul 2>nul
  if errorlevel 1 (
    echo [FEHLER] Konnte Verzeichnis nicht erstellen: %~1
    exit /b 1
  )
)
exit /b 0


:TOUCH
REM Legt eine leere Datei an, falls sie nicht existiert (oder aktualisiert Timestamp).
REM In Batch: "type nul >> file" erstellt Datei, falls nötig; Existenz bleibt erhalten.
if not exist "%~1" (
  type nul > "%~1"
) else (
  copy /b "%~1" +,, >nul
)
if errorlevel 1 (
  echo [FEHLER] Konnte Datei nicht erstellen/anfassen: %~1
  exit /b 1
)
exit /b 0