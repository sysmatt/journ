#!/usr/bin/env bash
# Builds the frontend into webroot/ using the repo-local Node toolchain
# (see scripts/setup-node.sh). Run from anywhere; always builds relative
# to the repo root. Does NOT commit or push — see the reminder it prints.
#
# Usage:
#   ./scripts/build.sh

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export PATH="$REPO_ROOT/.node/bin:$PATH"

cd "$REPO_ROOT"
npm run build

echo
echo "Built webroot/. Don't forget to:"
echo "  git add webroot/"
echo "  git commit -m \"Rebuild frontend\""
echo "  git push"
echo "...then git pull on the server — see docs/deployment.md § Updating."
