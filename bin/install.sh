#!/usr/bin/env bash
set -euo pipefail

REPO="${STACKD_REPO:-vblinden/stackd}"
INSTALL_DIR="${STACKD_INSTALL_DIR:-/usr/local/bin}"
BINARY_NAME="${STACKD_BINARY:-stackd}"

if [[ "$(uname -s)" != "Darwin" ]]; then
  echo "stackd currently supports macOS only." >&2
  exit 1
fi

if ! command -v php >/dev/null 2>&1; then
  echo "PHP is required to run stackd." >&2
  exit 1
fi

if ! command -v curl >/dev/null 2>&1; then
  echo "curl is required to download stackd." >&2
  exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "Downloading latest stackd release..."
curl -fsSL "https://github.com/${REPO}/releases/latest/download/stackd" -o "${TMP}/${BINARY_NAME}"
chmod +x "${TMP}/${BINARY_NAME}"

php "${TMP}/${BINARY_NAME}" --version >/dev/null

TARGET="${INSTALL_DIR}/${BINARY_NAME}"

if [[ -w "$INSTALL_DIR" ]]; then
  mv "${TMP}/${BINARY_NAME}" "$TARGET"
else
  echo "Installing to ${TARGET} (sudo may be required)..."
  sudo mv "${TMP}/${BINARY_NAME}" "$TARGET"
fi

echo "Installed ${BINARY_NAME} to ${TARGET}"
"${TARGET}" --version
echo
echo "Next: stackd doctor"
