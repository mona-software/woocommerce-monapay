#!/bin/sh
set -eu

PLUGIN_ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
PLUGIN_NAME=$(basename "$PLUGIN_ROOT")
PARENT_DIR=$(dirname "$PLUGIN_ROOT")
DIST_DIR="$PLUGIN_ROOT/dist"
OUTPUT_ZIP="$DIST_DIR/woocommerce-monapay-0.3.2.zip"
TEMP_DIR=$(mktemp -d "${TMPDIR:-/tmp}/woocommerce-monapay-build.XXXXXX")
TEMP_ZIP="$TEMP_DIR/woocommerce-monapay-0.3.2.zip"

cleanup() {
	rm -rf "$TEMP_DIR"
}
trap cleanup EXIT INT TERM

cd "$PARENT_DIR"
zip -rq "$TEMP_ZIP" "$PLUGIN_NAME" \
	-x "$PLUGIN_NAME/tests" \
	   "$PLUGIN_NAME/tests/*" \
	   "$PLUGIN_NAME/assets/wporg" \
	   "$PLUGIN_NAME/assets/wporg/*" \
	   "$PLUGIN_NAME/.git" \
	   "$PLUGIN_NAME/.git/*" \
	   "$PLUGIN_NAME/.github" \
	   "$PLUGIN_NAME/.github/*" \
	   "$PLUGIN_NAME/dist" \
	   "$PLUGIN_NAME/dist/*" \
	   "$PLUGIN_NAME/handoff" \
	   "$PLUGIN_NAME/handoff/*" \
	   "$PLUGIN_NAME/STATUS.md" \
	   "$PLUGIN_NAME/REPORT-WOO-PLUGIN-0.2.0.md" \
	   "$PLUGIN_NAME/REPORT-WOO-PLUGIN-0.3.1.md" \
	   "$PLUGIN_NAME/REPORT-WOO-PLUGIN-0.3.2.md" \
	   "$PLUGIN_NAME/SUBMIT-WPORG.md" \
	   "$PLUGIN_NAME/build-zip.sh"

mkdir -p "$DIST_DIR"
mv "$TEMP_ZIP" "$OUTPUT_ZIP"
rmdir "$TEMP_DIR"
trap - EXIT INT TERM
printf '%s\n' "$OUTPUT_ZIP"
