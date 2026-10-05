#!/bin/sh
set -eu

PLUGIN_ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
PLUGIN_SLUG="mona-pay-for-woocommerce"
DIST_DIR="$PLUGIN_ROOT/dist"
VERSION=$(sed -n 's/^ \* Version: *//p' "$PLUGIN_ROOT/mona-pay-for-woocommerce.php" | head -1)
OUTPUT_ZIP="$DIST_DIR/mona-pay-for-woocommerce-$VERSION.zip"
TEMP_DIR=$(mktemp -d "${TMPDIR:-/tmp}/mona-pay-for-woocommerce-build.XXXXXX")
STAGE_DIR="$TEMP_DIR/$PLUGIN_SLUG"
TEMP_ZIP="$TEMP_DIR/mona-pay-for-woocommerce-$VERSION.zip"

cleanup() {
	rm -rf "$TEMP_DIR"
}
trap cleanup EXIT INT TERM

mkdir -p "$STAGE_DIR/assets" "$STAGE_DIR/languages"
cp "$PLUGIN_ROOT/LICENSE" "$PLUGIN_ROOT/readme.txt" "$PLUGIN_ROOT/mona-pay-for-woocommerce.php" "$PLUGIN_ROOT/uninstall.php" "$STAGE_DIR/"
cp -R "$PLUGIN_ROOT/includes" "$STAGE_DIR/"
cp -R "$PLUGIN_ROOT/assets/js" "$STAGE_DIR/assets/"
cp "$PLUGIN_ROOT"/languages/mona-pay-for-woocommerce.pot "$PLUGIN_ROOT"/languages/*.po "$PLUGIN_ROOT"/languages/*.mo "$STAGE_DIR/languages/"

cd "$TEMP_DIR"
zip -rq "$TEMP_ZIP" "$PLUGIN_SLUG"

mkdir -p "$DIST_DIR"
mv "$TEMP_ZIP" "$OUTPUT_ZIP"
trap - EXIT INT TERM
printf '%s\n' "$OUTPUT_ZIP"
