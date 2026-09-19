#!/usr/bin/env python3
"""Verify the exact upstream PHP source shipped with the application."""
import hashlib
import json
from pathlib import Path
root = Path(__file__).resolve().parents[1] / 'backend/vendor/altcha'
manifest = json.loads((root / 'UPSTREAM.json').read_text())
for name, expected in manifest['sha256'].items():
    actual = hashlib.sha256((root / name).read_bytes()).hexdigest()
    if actual != expected:
        raise SystemExit(f'Vendor integrity mismatch: {name}')
expected_php = {name for name in manifest['sha256'] if name.endswith('.php')}
actual_php = {str(path.relative_to(root)) for path in root.rglob('*.php')}
if expected_php != actual_php:
    raise SystemExit('Unexpected vendor PHP files')
print(f"ALTCHA {manifest['tag']} matches {manifest['commit']} ({len(manifest['sha256'])} files)")
