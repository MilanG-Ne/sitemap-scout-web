#!/usr/bin/env python3
"""Build a deployable archive using an explicit allowlist; never package config or jobs."""
from pathlib import Path
import hashlib
import tarfile

root = Path(__file__).resolve().parents[1]
if not (root / 'public/index.html').is_file():
    raise SystemExit('Run pnpm build first.')
files = sorted((root / 'backend/src').rglob('*.php'))
files += [root / 'public' / name for name in ('index.html', 'api.php', '.htaccess', 'favicon.svg', 'robots.txt', 'sitemap.xml')]
files += sorted((root / 'public/assets').glob('*'))
files += [root / name for name in ('config.example.php', 'LICENSE', 'README.md')]
output = root / 'release/sitemap-scout-web.tar.gz'
output.parent.mkdir(exist_ok=True)
with tarfile.open(output, 'w:gz') as archive:
    for path in files:
        if path.is_symlink() or not path.is_file():
            raise SystemExit(f'Unexpected package entry: {path}')
        archive.add(path, arcname=path.relative_to(root), recursive=False)
digest = hashlib.sha256(output.read_bytes()).hexdigest()
output.with_suffix(output.suffix + '.sha256').write_text(f'{digest}  {output.name}\n')
print(f'{output.name}: {len(files)} files, {output.stat().st_size:,} bytes')
print(f'SHA256 {digest}')
