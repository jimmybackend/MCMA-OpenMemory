#!/usr/bin/env bash
set -Eeuo pipefail

ENV_FILE="/etc/mcma/mcma.env"
APP_DIR="/opt/MCMA-OpenMemory"

usage(){
  cat <<'EOF'
MCMA doctor

Usage:
  sudo scripts/mcma-doctor.sh [--env-file PATH] [--app-dir PATH]

Checks runtime requirements without printing secret values.
EOF
}

while (($#)); do
  case "$1" in
    --env-file) ENV_FILE="$2"; shift 2;;
    --app-dir) APP_DIR="$2"; shift 2;;
    -h|--help) usage; exit 0;;
    *) printf 'Unknown option: %s\n' "$1" >&2; exit 2;;
  esac
done

ok=0
warn=0
fail=0
pass(){ printf '[PASS] %s\n' "$*"; ok=$((ok+1)); }
warning(){ printf '[WARN] %s\n' "$*"; warn=$((warn+1)); }
failure(){ printf '[FAIL] %s\n' "$*"; fail=$((fail+1)); }

check_cmd(){ command -v "$1" >/dev/null 2>&1 && pass "$1 available" || failure "$1 missing"; }

check_cmd php
check_cmd nginx
check_cmd openssl
check_cmd curl

if command -v php >/dev/null 2>&1; then
  php_id="$(php -r 'echo PHP_VERSION_ID;')"
  if ((php_id>=80200)); then pass "PHP 8.2+ ($(php -r 'echo PHP_VERSION;'))"; else failure "PHP 8.2+ required"; fi
  for ext in openssl json curl; do
    php -r "exit(extension_loaded('$ext')?0:1);" && pass "PHP extension $ext" || failure "PHP extension $ext missing"
  done
fi

if [[ -f "$ENV_FILE" ]]; then
  pass "runtime env exists"
  perms="$(stat -c '%a' "$ENV_FILE" 2>/dev/null || stat -f '%Lp' "$ENV_FILE" 2>/dev/null || true)"
  [[ "$perms" == "600" ]] && pass "runtime env permissions 600" || warning "runtime env permissions are $perms (expected 600)"
else
  failure "runtime env missing: $ENV_FILE"
fi

env_value(){
  local key="$1" line value
  line="$(grep -E "^[[:space:]]*$key=" "$ENV_FILE" 2>/dev/null | tail -n1 || true)"
  [[ -n "$line" ]] || return 1
  value="${line#*=}"; value="${value#\'}"; value="${value%\'}"; value="${value#\"}"; value="${value%\"}"
  printf '%s' "$value"
}

if [[ -f "$ENV_FILE" ]]; then
  master="$(env_value MCMA_MASTER_KEY_B64 || true)"
  multi="$(env_value MCMA_MULTIUSER_PEPPER || true)"
  if [[ -n "$master" && -n "$multi" ]]; then failure "MCMA_MASTER_KEY_B64 must be unset in multi-user mode"; else pass "multi-user/global-key separation"; fi

  required=(MCMA_WEB_STORAGE_LOCATION MCMA_WEB_PUBLIC_ORIGIN MCMA_WEB_SESSION_SECRET MCMA_MULTIUSER_PEPPER MCMA_OIDC_ISSUER MCMA_OIDC_CLIENT_ID)
  for name in "${required[@]}"; do
    value="$(env_value "$name" || true)"
    if [[ -n "$value" && "$value" != *REPLACE* ]]; then pass "$name configured"; else warning "$name not configured"; fi
  done

  billing="$(env_value MCMA_BILLING_ENABLED || true)"
  if [[ "$billing" == "true" ]]; then
    stripe_key="$(env_value MCMA_STRIPE_SECRET_KEY || true)"
    stripe_hook="$(env_value MCMA_STRIPE_WEBHOOK_SECRET || true)"
    stripe_packages="$(env_value MCMA_STRIPE_PACKAGES_JSON || true)"
    if [[ -n "$stripe_key" || -n "$stripe_hook" || -n "$stripe_packages" ]]; then
      [[ -n "$stripe_key" && -n "$stripe_hook" && -n "$stripe_packages" ]] && pass "Stripe configuration complete" || failure "Stripe configuration is partial"
    else
      warning "billing enabled without Stripe (valid if credits are managed another way)"
    fi
  else
    pass "billing disabled/personal mode"
  fi
fi

[[ -f "$APP_DIR/packages/core/bootstrap.php" ]] && pass "MCMA checkout present" || failure "MCMA checkout missing at $APP_DIR"

if command -v nginx >/dev/null 2>&1; then
  nginx -t >/dev/null 2>&1 && pass "nginx configuration valid" || failure "nginx configuration invalid"
fi

if systemctl is-active --quiet nginx 2>/dev/null; then pass "nginx active"; else warning "nginx not active"; fi
if systemctl list-units --type=service --all 2>/dev/null | grep -Eq 'php.*fpm'; then
  if systemctl list-units --type=service --state=running --no-legend 2>/dev/null | grep -Eq 'php.*fpm'; then pass "PHP-FPM active"; else warning "PHP-FPM service found but not active"; fi
else
  warning "PHP-FPM service not detected"
fi

printf '\nMCMA doctor: %d pass, %d warning, %d fail\n' "$ok" "$warn" "$fail"
((fail==0))
