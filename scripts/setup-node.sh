#!/usr/bin/env bash
# Downloads a self-contained Node.js runtime into .node/ at the repo root —
# a repo-local, gitignored toolchain, the same idea as a Python .venv.
# No sudo, no system-wide install, nothing outside this repo touched.
#
# Usage:
#   ./scripts/setup-node.sh
#
# After it finishes, use the toolchain via:
#   .node/bin/npm install
#   .node/bin/npm run build
# ...or add it to PATH for the current shell:
#   export PATH="$(pwd)/.node/bin:$PATH"

set -euo pipefail

# Pinned deliberately, not "latest," so this script is reproducible.
# Bump this line (and NODE_SHA256 below) to upgrade.
NODE_VERSION="v24.18.1"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALL_DIR="$REPO_ROOT/.node"

# ---- detect platform ----------------------------------------------------
case "$(uname -s)" in
  Linux)  NODE_OS="linux" ;;
  Darwin) NODE_OS="darwin" ;;
  *) echo "error: unsupported OS '$(uname -s)' — grab a Node build manually from https://nodejs.org/dist/${NODE_VERSION}/" >&2; exit 1 ;;
esac

case "$(uname -m)" in
  x86_64|amd64)   NODE_ARCH="x64" ;;
  arm64|aarch64)  NODE_ARCH="arm64" ;;
  *) echo "error: unsupported architecture '$(uname -m)'" >&2; exit 1 ;;
esac

NODE_DIST="node-${NODE_VERSION}-${NODE_OS}-${NODE_ARCH}"
NODE_ARCHIVE="${NODE_DIST}.tar.xz"
NODE_URL="https://nodejs.org/dist/${NODE_VERSION}/${NODE_ARCHIVE}"
SHASUMS_URL="https://nodejs.org/dist/${NODE_VERSION}/SHASUMS256.txt"

# ---- already installed? --------------------------------------------------
if [ -x "$INSTALL_DIR/bin/node" ]; then
  CURRENT="$("$INSTALL_DIR/bin/node" -v || true)"
  if [ "$CURRENT" = "$NODE_VERSION" ]; then
    echo "Node $NODE_VERSION already installed at $INSTALL_DIR — nothing to do."
    exit 0
  fi
  echo "Found Node $CURRENT at $INSTALL_DIR, replacing with $NODE_VERSION..."
  rm -rf "$INSTALL_DIR"
fi

# ---- download + verify + extract ----------------------------------------
WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

echo "Downloading $NODE_URL ..."
curl -fsSL -o "$WORKDIR/$NODE_ARCHIVE" "$NODE_URL"
curl -fsSL -o "$WORKDIR/SHASUMS256.txt" "$SHASUMS_URL"

echo "Verifying checksum..."
EXPECTED="$(grep " ${NODE_ARCHIVE}\$" "$WORKDIR/SHASUMS256.txt" | awk '{print $1}')"
if [ -z "$EXPECTED" ]; then
  echo "error: could not find checksum for $NODE_ARCHIVE in SHASUMS256.txt" >&2
  exit 1
fi
if command -v sha256sum >/dev/null 2>&1; then
  ACTUAL="$(sha256sum "$WORKDIR/$NODE_ARCHIVE" | awk '{print $1}')"
else
  ACTUAL="$(shasum -a 256 "$WORKDIR/$NODE_ARCHIVE" | awk '{print $1}')"
fi
if [ "$EXPECTED" != "$ACTUAL" ]; then
  echo "error: checksum mismatch for $NODE_ARCHIVE (expected $EXPECTED, got $ACTUAL)" >&2
  exit 1
fi

echo "Extracting into $INSTALL_DIR ..."
mkdir -p "$INSTALL_DIR"
tar -xf "$WORKDIR/$NODE_ARCHIVE" -C "$INSTALL_DIR" --strip-components=1

echo "Done. Node $("$INSTALL_DIR/bin/node" -v) + npm $(PATH="$INSTALL_DIR/bin:$PATH" "$INSTALL_DIR/bin/npm" -v) installed at $INSTALL_DIR"
echo "Run:  export PATH=\"\$(pwd)/.node/bin:\$PATH\"   (or use .node/bin/npm directly)"
