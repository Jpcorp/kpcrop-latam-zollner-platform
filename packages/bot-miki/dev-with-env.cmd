@echo off
REM "pnpm --filter bot-miki dev" no carga .env solo (no hay dotenv en el
REM proyecto) -- falla con "Variables de entorno invalidas" a menos que ya
REM esten exportadas en el shell. Este wrapper las carga desde .env antes de
REM arrancar. Uso: dev-with-env.cmd (o "cmd /c dev-with-env.cmd" desde WSL).
REM Recordatorio: hay que "pnpm --filter bot-miki build" antes -- este script
REM solo corre el dist/ ya compilado (node --watch), no compila TypeScript.
cd /d "%~dp0"
for /f "usebackq tokens=1,* delims==" %%A in (`findstr /v "^#" .env ^| findstr /v "^$"`) do (
  set "%%A=%%B"
)
node --watch --experimental-specifier-resolution=node dist/index.js
