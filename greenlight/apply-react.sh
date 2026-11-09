#!/usr/bin/env bash
# Sync custom/react → greenlight source, build JS bundle, deploy for live use.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
SRC="$ROOT/custom/react"
GL_ROOT="$ROOT/../greenlight"
DEST="$GL_ROOT/app/javascript"

if [[ ! -d "$DEST" ]]; then
  echo "ERROR: Greenlight source not found at $GL_ROOT"
  exit 1
fi

copy() { mkdir -p "$(dirname "$2")"; cp -f "$1" "$2"; echo "  synced $(basename "$1")"; }

echo "==> Syncing JSX into Greenlight source"
copy "$SRC/App.jsx" "$DEST/App.jsx"
copy "$SRC/home/HomePage.jsx" "$DEST/components/home/HomePage.jsx"
copy "$SRC/home/HomepageFeatureCard.jsx" "$DEST/components/home/HomepageFeatureCard.jsx"
copy "$SRC/shared_components/Header.jsx" "$DEST/components/shared_components/Header.jsx"
copy "$SRC/shared_components/Footer.jsx" "$DEST/components/shared_components/Footer.jsx"
copy "$SRC/shared_components/Logo.jsx" "$DEST/components/shared_components/Logo.jsx"
copy "$SRC/shared_components/Title.jsx" "$DEST/components/shared_components/utilities/Title.jsx"
copy "$SRC/helpers/LocaleHelper.js" "$DEST/helpers/LocaleHelper.js" 2>/dev/null || \
  copy "$SRC/shared_components/LocaleHelper.js" "$DEST/helpers/LocaleHelper.js"
copy "$SRC/rooms/Rooms.jsx" "$DEST/components/rooms/Rooms.jsx"
copy "$SRC/rooms/RoomCard.jsx" "$DEST/components/rooms/RoomCard.jsx"
copy "$SRC/rooms/room/Room.jsx" "$DEST/components/rooms/room/Room.jsx"
copy "$SRC/recordings/UserRecordings.jsx" "$DEST/components/recordings/UserRecordings.jsx"
copy "$SRC/recordings/EmptyRecordingsList.jsx" "$DEST/components/recordings/EmptyRecordingsList.jsx"
copy "$SRC/users/authentication/SignIn.jsx" "$DEST/components/users/authentication/SignIn.jsx"
copy "$SRC/users/authentication/Signup.jsx" "$DEST/components/users/authentication/Signup.jsx"
copy "$SRC/shared_components/utilities/ButtonLink.jsx" "$DEST/components/shared_components/utilities/ButtonLink.jsx"


copy "$SRC/helpers/Axios.jsx" "$DEST/helpers/Axios.jsx"
copy "$SRC/hooks/mutations/sessions/useCreateSession.jsx" "$DEST/hooks/mutations/sessions/useCreateSession.jsx"


copy "$SRC/admin/AdminNavSideBar.jsx" "$DEST/components/admin/AdminNavSideBar.jsx"

copy "$SRC/home/NavbarSignedIn.jsx" "$DEST/components/home/NavbarSignedIn.jsx"
copy "$SRC/home/NavbarNotSignedIn.jsx" "$DEST/components/home/NavbarNotSignedIn.jsx"
copy "$SRC/home/AuthButtons.jsx" "$DEST/components/home/AuthButtons.jsx"

copy "$SRC/shared_components/forms/Form.jsx" "$DEST/components/shared_components/forms/Form.jsx"

# Recording components
copy "$SRC/javascript/components/recordings/RecordingRow.jsx" "$DEST/components/recordings/RecordingRow.jsx"
copy "$SRC/javascript/components/recordings/RecordingsList.jsx" "$DEST/components/recordings/RecordingsList.jsx"
copy "$SRC/javascript/components/recordings/room_recordings/RoomsRecordingRow.jsx" "$DEST/components/recordings/room_recordings/RoomsRecordingRow.jsx"
copy "$SRC/javascript/hooks/mutations/recordings/useUpdateRecordingVisibility.jsx" "$DEST/hooks/mutations/recordings/useUpdateRecordingVisibility.jsx"
copy "$SRC/javascript/hooks/mutations/recordings/useUpdateRecording.jsx" "$DEST/hooks/mutations/recordings/useUpdateRecording.jsx"
copy "$SRC/javascript/hooks/mutations/recordings/useDeleteRecording.jsx" "$DEST/hooks/mutations/recordings/useDeleteRecording.jsx"
copy "$SRC/javascript/hooks/mutations/recordings/useRedirectRecordingUrl.jsx" "$DEST/hooks/mutations/recordings/useRedirectRecordingUrl.jsx"
copy "$SRC/javascript/hooks/queries/recordings/useRecordings.jsx" "$DEST/hooks/queries/recordings/useRecordings.jsx"
copy "$SRC/javascript/hooks/queries/recordings/useRoomRecordings.jsx" "$DEST/hooks/queries/recordings/useRoomRecordings.jsx"
copy "$SRC/javascript/hooks/queries/admin/server_recordings/useServerRecordings.jsx" "$DEST/hooks/queries/admin/server_recordings/useServerRecordings.jsx"

echo "==> Building React bundle (esbuild)"
cd "$GL_ROOT"
if [[ ! -x node_modules/.bin/esbuild ]]; then
  npm install --no-audit --no-fund
fi
npm run build

echo "==> Deploying bundle to mounted public path"
mkdir -p "$ROOT/custom/dist" "$ROOT/custom/styles"
cp -f "$GL_ROOT/app/assets/builds/main.js" "$ROOT/custom/dist/hoda-main.js"
cp -f "$ROOT/custom/dist/hoda-main.js" "$ROOT/custom/styles/hoda-main.js"

# Bust browser cache for the injector URL stamp inside rtl-fix.js
STAMP=$(date +%Y%m%d%H%M%S)
sed -i -E "s#/styles/hoda-main\\.js\\?v=[0-9]+#/styles/hoda-main.js?v=${STAMP}#" "$ROOT/custom/rtl-fix.js"

echo
echo "Done. Hard-refresh the browser (Ctrl+Shift+R)."
echo "Edit JSX in custom/react/ then re-run: ./apply-react.sh"
