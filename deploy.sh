#!/usr/bin/env bash
set -euo pipefail

# ========== Konfiguration ==========
APP_DIR="/var/www/html/dashboardtk"
NODE_VERSION="22"
PHP_BIN="php"
COMPOSER_BIN="composer"

# ========== Helper ==========
log() {
  # Zeitstempel + Nachricht
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

run() {
  # Kommando mit schöner Ausgabe + Fehlerabbruch
  log "RUN: $*"
  "$@"
}

# ========== Start ==========
log "=== DEPLOY START ==="
run cd "$APP_DIR"

# --- NVM laden (wichtig in non-interactive shells) ---
log "Loading NVM..."
export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"

if [[ -s "$NVM_DIR/nvm.sh" ]]; then
  # shellcheck disable=SC1090
  source "$NVM_DIR/nvm.sh"
else
  echo "ERROR: nvm.sh not found at: $NVM_DIR/nvm.sh"
  echo "Hint: install nvm or adjust NVM_DIR."
  exit 1
fi

log "Using Node $NODE_VERSION..."
run nvm use "$NODE_VERSION"

log "Node: $(node -v) | npm: $(npm -v)"

# --- Git Update ---
log "Git status before pull:"
run git status -sb

log "Pulling latest changes..."
# Wenn du NIE lokale Änderungen im Server-Repo willst, nimm stattdessen:
# run git fetch origin
# run git reset --hard origin/main
run git pull --ff-only

log "Current commit:"
run git rev-parse --short HEAD

# --- Backend (Composer) ---
log "Composer install (prod)..."
run "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# --- Frontend (Vite) ---
log "NPM clean install..."
run npm ci

log "Vite build..."
run npm run build

log "Build assets (latest):"
run ls -la "$APP_DIR/public/build/assets" | head -n 25

# --- Doctrine Migrations (optional aber sinnvoll) ---
if [[ -d "$APP_DIR/migrations" ]] && compgen -G "$APP_DIR/migrations/*.php" > /dev/null; then
  log "Running Doctrine migrations (prod)..."
  # Falls du nicht willst, dass Deploy fehlschlägt, wenn nichts zu migrieren ist, ist das ohnehin ok.
  run "$PHP_BIN" bin/console doctrine:migrations:migrate --no-interaction --env=prod
else
  log "No migrations found, skipping."
fi

# --- Symfony Cache ---
log "Symfony cache clear/warmup (prod)..."
run "$PHP_BIN" bin/console cache:clear --env=prod --no-debug
run "$PHP_BIN" bin/console cache:warmup --env=prod --no-debug

# --- Permissions ---
log "Fixing permissions..."
run sudo chown -R www-data:www-data "$APP_DIR/var"
run sudo find "$APP_DIR/var" -type d -exec chmod 775 {} \;
run sudo find "$APP_DIR/var" -type f -exec chmod 664 {} \;

# Optional: build-Ordner lesbar für Apache
run sudo chown -R frankmint:www-data "$APP_DIR/public/build" || true
run sudo find "$APP_DIR/public/build" -type d -exec chmod 755 {} \; || true
run sudo find "$APP_DIR/public/build" -type f -exec chmod 644 {} \; || true

# --- Apache reload ---
log "Reloading Apache..."
run sudo systemctl reload apache2

# --- Quick health checks ---
log "Health check: homepage + API endpoint"
run curl -s -o /dev/null -w "HTTP %{http_code}\n" "http://127.0.0.1:8080/"
run curl -s -o /dev/null -w "HTTP %{http_code}\n" "http://127.0.0.1:8080/api/support_solutions"

log "=== DEPLOY DONE ==="
