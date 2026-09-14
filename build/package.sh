#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 IB Trieb GmbH & Co. KG
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Build the release tarball for the Nextcloud app store, and sign it when a
# signing key is available.
#
# The store requires a single top-level directory named exactly like the app id.
# The Makefile's appstore target names it after the checkout directory instead,
# which only works when the checkout happens to have the right name.
#
# Usage:
#   build/package.sh              # build build/artifacts/extractplus.tar.gz
#   build/package.sh --sign       # additionally print the base64 signature
#
# Signing expects the key the app store issued for this app id at
# ~/.nextcloud/certificates/<app id>.key (see build/certificate-request.sh).

set -euo pipefail

cd "$(dirname "$0")/.."
readonly REPO_ROOT="$PWD"

readonly APP_ID="$(sed -n 's:.*<id>\(.*\)</id>.*:\1:p' appinfo/info.xml | head -1)"
readonly VERSION="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml | head -1)"
readonly OUT_DIR="$REPO_ROOT/build/artifacts"
readonly TARBALL="$OUT_DIR/${APP_ID}.tar.gz"
readonly KEY="$HOME/.nextcloud/certificates/${APP_ID}.key"

if [ -z "$APP_ID" ] || [ -z "$VERSION" ]; then
	echo "Could not read the app id or version from appinfo/info.xml" >&2
	exit 1
fi

echo "Packaging $APP_ID $VERSION"

# The bundle has to be current: the store ships js/, which is gitignored and
# therefore easy to forget.
echo "-> building the frontend"
npm run build --silent

STAGING="$(mktemp -d)"
trap 'rm -rf "$STAGING"' EXIT
readonly APP_DIR="$STAGING/$APP_ID"
mkdir -p "$APP_DIR"

# Everything the app needs at runtime, and nothing that only serves development.
for item in appinfo lib js l10n img templates COPYING LICENSES CHANGELOG.md README.md REUSE.toml; do
	if [ -e "$item" ]; then
		cp -r "$item" "$APP_DIR/"
	fi
done

# Source maps are development aids and make up most of the bundle size.
find "$APP_DIR/js" -name '*.map' -delete 2>/dev/null || true

mkdir -p "$OUT_DIR"
rm -f "$TARBALL"
tar -czf "$TARBALL" -C "$STAGING" "$APP_ID"

echo "-> $TARBALL ($(du -h "$TARBALL" | cut -f1))"

if [ "${1:-}" != "--sign" ]; then
	echo
	echo "Run with --sign to also produce the store signature."
	exit 0
fi

if [ ! -f "$KEY" ]; then
	echo "No signing key at $KEY" >&2
	echo "Request a certificate first - see build/certificate-request.sh" >&2
	exit 1
fi

echo
echo "Signature for the store release form:"
openssl dgst -sha512 -sign "$KEY" "$TARBALL" | openssl base64 -A
echo
