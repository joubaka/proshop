#!/usr/bin/env bash
set -euo pipefail

APP_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[ -f "$APP_PATH/deploy.config" ] && source "$APP_PATH/deploy.config"

DEPLOY_BRANCHES="${DEPLOY_BRANCHES:-main}"
GIT_BRANCH="${GIT_BRANCH:-main}"
PUBLIC_HTML="${PUBLIC_HTML:-$APP_PATH/public}"
RUN_BACKUP="${RUN_BACKUP:-true}"
RUN_MIGRATIONS="${RUN_MIGRATIONS:-false}"
RUN_LIGHTS_MIGRATIONS="${RUN_LIGHTS_MIGRATIONS:-false}"
INSTALL_DEPENDENCIES="${INSTALL_DEPENDENCIES:-true}"
BUILD_ASSETS="${BUILD_ASSETS:-true}"
NPM_BUILD_COMMAND="${NPM_BUILD_COMMAND:-npm run production}"
FRONTEND_ASSET_ARCHIVE="${FRONTEND_ASSET_ARCHIVE:-deployment/frontend-assets.tar.gz}"
FRONTEND_ASSET_CHECKSUM="${FRONTEND_ASSET_CHECKSUM:-deployment/frontend-assets.sha256}"
SYNC_FOLDERS="${SYNC_FOLDERS:-css js fonts webfonts images modules lights-assets}"
SYNC_ROOT_FILES="${SYNC_ROOT_FILES:-favicon.ico manifest.json manifest.webmanifest mix-manifest.json offline.html service-worker.js robots.txt}"
DEPLOY_HEALTH_URL="${DEPLOY_HEALTH_URL:-}"

SKIP_BACKUP=false
SKIP_MIGRATIONS=false
SKIP_DEPS=false
SKIP_BUILD=false
WITH_LIGHTS_MIGRATIONS=false
INSTALL_COMMAND=false
SHOW_HELP=false
REQUESTED_BRANCH=""
APP_IS_DOWN=0

log() { printf '==> [%s] %s\n' "$1" "$2"; }
fail() { log ERROR "$1" >&2; exit 1; }
run_php() { php -d display_errors=Off "$@"; }
usage() {
    echo 'Usage: deploy-hsc main [--with-lights-migrations] [--skip-backup] [--skip-migrations] [--skip-deps] [--skip-build]'
    echo '       ./deploy.sh --install-command'
}
install_command() {
    local dir="${DEPLOY_COMMAND_DIR:-$HOME/bin}"
    local path="$dir/deploy-hsc"
    local profile_path="${DEPLOY_PROFILE_PATH:-$HOME/.bash_profile}"
    mkdir -p "$dir"
    ln -sfn "$APP_PATH/bin/deploy" "$path"
    case ":$PATH:" in
        *":$dir:"*) ;;
        *)
            touch "$profile_path"
            if ! grep -Fq 'export PATH="$HOME/bin:$PATH"' "$profile_path"; then
                printf '\nexport PATH="$HOME/bin:$PATH"\n' >> "$profile_path"
            fi
            log INFO "Added $dir to PATH; reconnect once before using deploy-hsc"
            ;;
    esac
    log INFO 'Deployment command ready: deploy-hsc main'
}
while [ "$#" -gt 0 ]; do
    case "$1" in
        --install-command) INSTALL_COMMAND=true ;;
        --skip-backup) SKIP_BACKUP=true ;;
        --skip-migrations) SKIP_MIGRATIONS=true ;;
        --skip-deps) SKIP_DEPS=true ;;
        --skip-build) SKIP_BUILD=true ;;
        --with-lights-migrations) WITH_LIGHTS_MIGRATIONS=true ;;
        -h|--help) SHOW_HELP=true ;;
        --branch) [ "$#" -ge 2 ] || fail 'Missing value for --branch'; REQUESTED_BRANCH="$2"; shift ;;
        --branch=*) REQUESTED_BRANCH="${1#*=}" ;;
        -*) fail "Unknown option: $1" ;;
        *) [ -z "$REQUESTED_BRANCH" ] || fail 'Only one deployment branch may be supplied'; REQUESTED_BRANCH="$1" ;;
    esac
    shift
done

[ "$SHOW_HELP" = true ] && { usage; exit 0; }
[ "$INSTALL_COMMAND" = true ] && { install_command; exit 0; }
REQUESTED_BRANCH="${REQUESTED_BRANCH:-$GIT_BRANCH}"
case "$REQUESTED_BRANCH" in *[!A-Za-z0-9._/-]*|/*|*..*) fail "Invalid deployment branch: $REQUESTED_BRANCH" ;; esac
case " $DEPLOY_BRANCHES " in *" $REQUESTED_BRANCH "*) ;; *) fail "Branch '$REQUESTED_BRANCH' is not approved" ;; esac
[ "$REQUESTED_BRANCH" = main ] || fail 'HSC production deploys only main'

[ -d "$APP_PATH/.git" ] || fail "$APP_PATH is not a Git checkout"
[ -f "$APP_PATH/.env" ] || fail 'Server .env is missing'
[ -z "$(git -C "$APP_PATH" status --porcelain)" ] || fail 'Production working tree is not clean'
[ "$(git -C "$APP_PATH" branch --show-current)" = main ] || fail 'Production checkout must be on main'
command -v php >/dev/null 2>&1 || fail 'php is unavailable'
command -v git >/dev/null 2>&1 || fail 'git is unavailable'
if [ "$SKIP_DEPS" = false ] && [ "$INSTALL_DEPENDENCIES" = true ]; then command -v composer >/dev/null 2>&1 || fail 'composer is unavailable'; fi
if [ "$SKIP_BUILD" = false ] && [ "$BUILD_ASSETS" = true ] && ! command -v npm >/dev/null 2>&1; then
    [ -f "$APP_PATH/$FRONTEND_ASSET_ARCHIVE" ] || fail 'npm is unavailable and the reviewed frontend release bundle is missing'
    [ -f "$APP_PATH/$FRONTEND_ASSET_CHECKSUM" ] || fail 'frontend release bundle checksum is missing'
    command -v tar >/dev/null 2>&1 || fail 'tar is required to install the frontend release bundle'
fi

git -C "$APP_PATH" fetch origin main

if [ "$SKIP_BACKUP" = false ] && [ "$RUN_BACKUP" = true ]; then
    log INFO 'Creating a retained database backup before deployment'
    run_php "$APP_PATH/artisan" backup:run --only-db --disable-notifications --no-interaction
fi

restore_online() {
    local status=$?
    if [ "$APP_IS_DOWN" = 1 ]; then run_php "$APP_PATH/artisan" up || true; fi
    exit "$status"
}
trap restore_online EXIT
run_php "$APP_PATH/artisan" down --retry=60
APP_IS_DOWN=1

git -C "$APP_PATH" merge --ff-only origin/main
[ -f "$APP_PATH/deploy.config" ] && source "$APP_PATH/deploy.config"
[ "$WITH_LIGHTS_MIGRATIONS" = true ] && RUN_LIGHTS_MIGRATIONS=true
REMOTE_HEAD="$(git -C "$APP_PATH" rev-parse HEAD)"

if [ "$SKIP_DEPS" = false ] && [ "$INSTALL_DEPENDENCIES" = true ]; then
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --working-dir="$APP_PATH"
fi
if [ "$SKIP_BUILD" = false ] && [ "$BUILD_ASSETS" = true ]; then
    if command -v npm >/dev/null 2>&1; then
        npm --prefix "$APP_PATH" ci --no-audit --no-fund
        (cd "$APP_PATH" && $NPM_BUILD_COMMAND)
    else
        log INFO 'npm unavailable; installing the reviewed frontend release bundle'
        EXPECTED_CHECKSUM="$(awk '{print $1}' "$APP_PATH/$FRONTEND_ASSET_CHECKSUM")"
        [ -n "$EXPECTED_CHECKSUM" ] || fail 'frontend release bundle checksum is empty'
        if command -v sha256sum >/dev/null 2>&1; then
            ACTUAL_CHECKSUM="$(sha256sum "$APP_PATH/$FRONTEND_ASSET_ARCHIVE" | awk '{print $1}')"
        elif command -v shasum >/dev/null 2>&1; then
            ACTUAL_CHECKSUM="$(shasum -a 256 "$APP_PATH/$FRONTEND_ASSET_ARCHIVE" | awk '{print $1}')"
        else
            fail 'sha256sum or shasum is required to verify the frontend release bundle'
        fi
        [ "$ACTUAL_CHECKSUM" = "$EXPECTED_CHECKSUM" ] || fail 'frontend release bundle checksum mismatch'
        tar -xzf "$APP_PATH/$FRONTEND_ASSET_ARCHIVE" -C "$APP_PATH"
    fi
fi

run_php "$APP_PATH/artisan" optimize:clear

run_approved_migrations() {
    local connection="$1"
    local paths="$2"
    [ -n "$paths" ] || fail "$connection migrations enabled without an approved path list"
    for migration in $paths; do
        case "$migration" in database/migrations/*.php|database/migrations/lights/*.php) ;; *) fail "Invalid migration path: $migration" ;; esac
        [ -f "$APP_PATH/$migration" ] || fail "Migration not found: $migration"
        if [ "$connection" = default ]; then
            run_php "$APP_PATH/artisan" migrate --force --no-interaction --path="$migration"
        else
            run_php "$APP_PATH/artisan" migrate --force --no-interaction --database="$connection" --path="$migration"
        fi
    done
}

if [ "$SKIP_MIGRATIONS" = false ] && [ "$RUN_MIGRATIONS" = true ]; then
    run_approved_migrations default "${MIGRATION_PATHS:-}"
    PENDING_MIGRATIONS="$(run_php "$APP_PATH/artisan" migrate:status --pending --no-interaction --no-ansi)"
    if ! printf '%s\n' "$PENDING_MIGRATIONS" | grep -Fq 'No pending migrations.'; then
        printf '%s\n' "$PENDING_MIGRATIONS"
        fail 'Unapproved application migrations remain pending'
    fi
fi
if [ "$SKIP_MIGRATIONS" = false ] && [ "$RUN_LIGHTS_MIGRATIONS" = true ]; then
    run_approved_migrations lights "${LIGHTS_MIGRATION_PATHS:-}"
fi

run_php "$APP_PATH/artisan" storage:link 2>&1 | grep -v 'already exists' || true
run_php "$APP_PATH/artisan" config:cache
run_php "$APP_PATH/artisan" route:cache
run_php "$APP_PATH/artisan" view:cache

if [ "$PUBLIC_HTML" != "$APP_PATH/public" ]; then
    mkdir -p "$PUBLIC_HTML"
    for folder in $SYNC_FOLDERS; do
        [ -d "$APP_PATH/public/$folder" ] || continue
        mkdir -p "$PUBLIC_HTML/$folder"
        SOURCE_FOLDER="$(cd "$APP_PATH/public/$folder" && pwd -P)"
        TARGET_FOLDER="$(cd "$PUBLIC_HTML/$folder" && pwd -P)"
        if [ "$SOURCE_FOLDER" = "$TARGET_FOLDER" ]; then
            log INFO "Skipping already-linked public folder: $folder"
            continue
        fi
        if command -v rsync >/dev/null 2>&1; then
            rsync -a --delete "$APP_PATH/public/$folder/" "$PUBLIC_HTML/$folder/"
        else
            cp -rf "$APP_PATH/public/$folder/." "$PUBLIC_HTML/$folder/"
        fi
    done
    for file in $SYNC_ROOT_FILES; do
        [ -f "$APP_PATH/public/$file" ] || continue
        [ -e "$PUBLIC_HTML/$file" ] && [ "$APP_PATH/public/$file" -ef "$PUBLIC_HTML/$file" ] && continue
        cp "$APP_PATH/public/$file" "$PUBLIC_HTML/$file"
    done
fi

# A prior release used /lights for static files, which collides with Laravel's
# /lights application route on LiteSpeed. Remove only that exact known bundle.
LEGACY_LIGHTS="$PUBLIC_HTML/lights"
if [ -d "$LEGACY_LIGHTS" ]; then
    [ ! -L "$LEGACY_LIGHTS" ] || fail 'Legacy public_html/lights is a symlink; inspect it manually before removal'
    PUBLIC_REAL="$(cd "$PUBLIC_HTML" && pwd -P)"
    LEGACY_PARENT="$(cd "$(dirname "$LEGACY_LIGHTS")" && pwd -P)"
    [ "$PUBLIC_REAL" = "$LEGACY_PARENT" ] || fail 'Legacy Lights directory is outside PUBLIC_HTML'
    [ -f "$LEGACY_LIGHTS/portal.css" ] || fail 'Unexpected public_html/lights directory; portal.css marker is missing'
    for entry in "$LEGACY_LIGHTS"/* "$LEGACY_LIGHTS"/.[!.]* "$LEGACY_LIGHTS"/..?*; do
        [ -e "$entry" ] || continue
        case "$(basename "$entry")" in
            admin.js|control.js|hardware-status.js|icon.svg|live-status.css|manifest.webmanifest|portal.css|portal.js|service-worker.js) ;;
            *) fail "Unexpected file in legacy Lights asset directory: $entry" ;;
        esac
    done
    rm -f "$LEGACY_LIGHTS/admin.js" "$LEGACY_LIGHTS/control.js" "$LEGACY_LIGHTS/hardware-status.js" \
        "$LEGACY_LIGHTS/icon.svg" "$LEGACY_LIGHTS/live-status.css" "$LEGACY_LIGHTS/manifest.webmanifest" \
        "$LEGACY_LIGHTS/portal.css" "$LEGACY_LIGHTS/portal.js" "$LEGACY_LIGHTS/service-worker.js"
    rmdir "$LEGACY_LIGHTS"
    log INFO 'Removed legacy static /lights directory so Laravel owns the route'
fi

run_php "$APP_PATH/artisan" queue:restart
run_php "$APP_PATH/artisan" schedule:list --no-ansi >/dev/null
run_php "$APP_PATH/artisan" up
APP_IS_DOWN=0
trap - EXIT

if [ -n "$DEPLOY_HEALTH_URL" ]; then
    command -v curl >/dev/null 2>&1 || fail 'curl is required for DEPLOY_HEALTH_URL'
    curl --fail --silent --show-error --location --max-time 20 "$DEPLOY_HEALTH_URL" >/dev/null
fi

echo "Deployment complete: $REMOTE_HEAD"
