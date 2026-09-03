#!/usr/bin/env bash
set -Eeuo pipefail

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE_FRAGMENT="$SOURCE_DIR/config/nginx/mcma-mailit-v1.conf"
PARENT_CONF="/etc/nginx/mcma-mailit.conf"
TARGET_FRAGMENT="/etc/nginx/mcma-mailit-v1-managed.conf"
INCLUDE_LINE="include /etc/nginx/mcma-mailit-v1-managed.conf;"
MODE="${1:---apply}"

usage(){
  cat <<'EOF'
MCMA mailit.click Nginx deployer

Usage:
  sudo ./scripts/deploy-mailit-nginx.sh [--apply|--check]

--apply  Back up the live MCMA include, install the versioned V1 fragment,
         migrate the legacy inline V1 locations to one include line, validate
         Nginx, and reload it. Historical /mcma/v2/* locations are preserved.

--check  Verify that the installed fragment matches the repository, the parent
         include references it, and nginx -t succeeds. No files are changed.
EOF
}

[[ "$MODE" == "--apply" || "$MODE" == "--check" || "$MODE" == "--help" || "$MODE" == "-h" ]] || { usage >&2; exit 2; }
if [[ "$MODE" == "--help" || "$MODE" == "-h" ]]; then usage; exit 0; fi

[[ -f "$SOURCE_FRAGMENT" ]] || { echo "ERROR: missing repository fragment: $SOURCE_FRAGMENT" >&2; exit 1; }
[[ -f "$PARENT_CONF" ]] || { echo "ERROR: live MCMA include not found: $PARENT_CONF" >&2; exit 1; }
command -v nginx >/dev/null 2>&1 || { echo "ERROR: nginx not installed" >&2; exit 1; }

if [[ "$MODE" == "--check" ]]; then
  [[ -f "$TARGET_FRAGMENT" ]] || { echo "ERROR: managed fragment is not installed" >&2; exit 1; }
  cmp -s "$SOURCE_FRAGMENT" "$TARGET_FRAGMENT" || { echo "ERROR: installed MCMA V1 fragment differs from repository" >&2; exit 1; }
  grep -Fqx "$INCLUDE_LINE" "$PARENT_CONF" || { echo "ERROR: parent MCMA include does not reference managed fragment" >&2; exit 1; }
  nginx -t
  echo "OK: mailit.click MCMA Nginx matches repository"
  exit 0
fi

[[ $EUID -eq 0 ]] || { echo "ERROR: run --apply with sudo/root" >&2; exit 1; }
command -v python3 >/dev/null 2>&1 || { echo "ERROR: python3 is required for one-time safe adoption" >&2; exit 1; }

TS="$(date +%F-%H%M%S)"
PARENT_BACKUP="$PARENT_CONF.bak-repo-$TS"
TARGET_BACKUP=""

cp -a "$PARENT_CONF" "$PARENT_BACKUP"
if [[ -f "$TARGET_FRAGMENT" ]]; then
  TARGET_BACKUP="$TARGET_FRAGMENT.bak-repo-$TS"
  cp -a "$TARGET_FRAGMENT" "$TARGET_BACKUP"
fi

rollback(){
  echo "ERROR: restoring Nginx backups" >&2
  cp -a "$PARENT_BACKUP" "$PARENT_CONF"
  if [[ -n "$TARGET_BACKUP" && -f "$TARGET_BACKUP" ]]; then
    cp -a "$TARGET_BACKUP" "$TARGET_FRAGMENT"
  elif [[ -z "$TARGET_BACKUP" ]]; then
    rm -f "$TARGET_FRAGMENT"
  fi
}
trap 'rollback' ERR

install -m 0644 "$SOURCE_FRAGMENT" "$TARGET_FRAGMENT"

if ! grep -Fqx "$INCLUDE_LINE" "$PARENT_CONF"; then
  python3 - "$PARENT_CONF" "$INCLUDE_LINE" <<'PY'
import os
import re
import sys
import tempfile

path=sys.argv[1]
include_line=sys.argv[2]

with open(path,encoding="utf-8") as f:
    lines=f.readlines()

managed_headers={
    "location = /mcma",
    "location = /mcma/",
    "location = /mcma/favicon.svg",
    "location = /mcma/app.css",
    "location = /mcma/app.js",
    "location = /mcma/admin.html",
    "location = /mcma/admin.js",
    "location = /mcma/login",
    "location = /mcma/callback",
    "location = /mcma/logout",
    "location ^~ /mcma/v1/",
    "location ^~ /mcma/",
}

def canonical_header(line):
    clean=line.split("#",1)[0].strip()
    if "{" not in clean:
        return None
    return re.sub(r"\s+"," ",clean.split("{",1)[0].strip())

out=[]
removed=[]
i=0
while i < len(lines):
    header=canonical_header(lines[i])
    if header not in managed_headers:
        out.append(lines[i])
        i+=1
        continue

    start=i
    depth=0
    while i < len(lines):
        clean=lines[i].split("#",1)[0]
        depth += clean.count("{")
        depth -= clean.count("}")
        i+=1
        if depth <= 0:
            break
    removed.append(header)

if not any(h in removed for h in ("location ^~ /mcma/v1/","location = /mcma/login")):
    raise SystemExit("Refusing adoption: expected live MCMA V1 locations were not found")

while out and out[-1].strip()=="":
    out.pop()
out.extend(["\n","# MCMA V1 managed from /var/www/memory repository.\n",include_line+"\n"])

fd,tmp=tempfile.mkstemp(prefix=".mcma-mailit.",dir=os.path.dirname(path),text=True)
try:
    with os.fdopen(fd,"w",encoding="utf-8") as f:
        f.writelines(out)
    os.chmod(tmp,0o644)
    os.replace(tmp,path)
finally:
    if os.path.exists(tmp):
        os.unlink(tmp)

print("Adopted inline V1 locations:",len(removed))
for item in removed:
    print(" -",item)
PY
fi

nginx -t
systemctl reload nginx
systemctl is-active --quiet nginx

trap - ERR

echo "OK: repository-managed MCMA V1 Nginx installed"
echo "Parent backup: $PARENT_BACKUP"
echo "Managed fragment: $TARGET_FRAGMENT"
