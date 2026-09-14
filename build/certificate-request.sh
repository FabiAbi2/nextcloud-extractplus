#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 IB Trieb GmbH & Co. KG
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Generate the signing key and certificate request the Nextcloud app store needs.
#
# Publishing to the store requires a certificate issued by Nextcloud. The flow is:
#
#   1. run this script - it writes the private key and the CSR
#   2. open a pull request against
#      https://github.com/nextcloud/app-certificate-requests
#      adding <app id>/<app id>.csr, and wait for it to be merged (days to weeks)
#   3. save the issued .crt next to the key as ~/.nextcloud/certificates/<app id>.crt
#   4. register the app id at https://apps.nextcloud.com/developer/apps/new
#      using the certificate and the signature `--app-signature` prints
#   5. build and sign the release with build/package.sh --sign
#
# The private key never leaves this machine. Keep it: losing it means losing the
# ability to publish updates for this app id.

set -euo pipefail

cd "$(dirname "$0")/.."

readonly APP_ID="$(sed -n 's:.*<id>\(.*\)</id>.*:\1:p' appinfo/info.xml | head -1)"
readonly CERT_DIR="$HOME/.nextcloud/certificates"
readonly KEY="$CERT_DIR/${APP_ID}.key"
readonly CSR="$CERT_DIR/${APP_ID}.csr"
readonly CRT="$CERT_DIR/${APP_ID}.crt"

if [ -z "$APP_ID" ]; then
	echo "Could not read the app id from appinfo/info.xml" >&2
	exit 1
fi

# Git Bash ships MSYS paths ("/c/Users/...") that the native openssl.exe cannot
# open, so paths are handed over in the form the local openssl understands.
native_path() {
	if command -v cygpath >/dev/null 2>&1; then
		cygpath -w "$1"
	else
		printf '%s' "$1"
	fi
}

# Mode: print the ownership signature the store's registration form asks for.
if [ "${1:-}" = "--app-signature" ]; then
	if [ ! -f "$KEY" ]; then
		echo "No key at $KEY - run this script without arguments first" >&2
		exit 1
	fi
	echo "Signature proving ownership of the app id '$APP_ID':"
	echo -n "$APP_ID" | openssl dgst -sha512 -sign "$(native_path "$KEY")" | openssl base64 -A
	echo
	if [ -f "$CRT" ]; then
		echo
		echo "Public certificate to paste into the same form:"
		cat "$CRT"
	fi
	exit 0
fi

mkdir -p "$CERT_DIR"

if [ -s "$KEY" ]; then
	echo "A key already exists at $KEY - refusing to overwrite it." >&2
	echo "Publishing updates depends on this key; delete it by hand if that is really intended." >&2
	exit 1
fi

echo "Generating a 4096 bit key and a certificate request for '$APP_ID'"

# The store requires the common name to be exactly the app id. MSYS_NO_PATHCONV
# keeps Git Bash from rewriting "/CN=..." into a Windows path; the file
# arguments are already converted above, so disabling it here is safe.
MSYS_NO_PATHCONV=1 openssl req -nodes -newkey rsa:4096 \
	-keyout "$(native_path "$KEY")" \
	-out "$(native_path "$CSR")" \
	-subj "/CN=$APP_ID" 2>/dev/null

chmod 600 "$KEY"

echo
echo "Private key: $KEY  (keep this, never publish it)"
echo "Request:     $CSR"
echo
echo "Next: open a pull request against nextcloud/app-certificate-requests that adds"
echo "  $APP_ID/$APP_ID.csr"
echo "with this content:"
echo
cat "$CSR"
