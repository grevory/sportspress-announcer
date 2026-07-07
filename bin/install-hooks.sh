#!/usr/bin/env bash
# Install the version-controlled git hooks. Run once after cloning.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
cp bin/pre-commit .git/hooks/pre-commit
chmod +x .git/hooks/pre-commit
echo "✓ Installed pre-commit hook (tests + code-smell lint)."
