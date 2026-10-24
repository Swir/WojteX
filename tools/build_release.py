#!/usr/bin/env python3
"""Build a clean dependency-inclusive installer from a prepared source export."""
from pathlib import Path
import hashlib
import json
import re
import sys
import zipfile

root = Path(__file__).resolve().parents[1]
version = (root / 'VERSION').read_text().strip()
if not re.fullmatch(r'\d+\.\d+\.\d+(?:-[a-z0-9.]+)?', version):
    raise SystemExit('Invalid VERSION')
if (root / '.env').exists():
    raise SystemExit('Refusing to build from a configured installation. Use a clean source export.')
if not (root / 'vendor/autoload.php').is_file():
    raise SystemExit('Install production Composer dependencies first.')
if not (root / 'database/migrations/0001_01_01_000000_create_users_table.php').is_file():
    raise SystemExit('Database migrations are missing from the source export.')
installed = json.loads((root / 'vendor/composer/installed.json').read_text(encoding='utf-8'))
locked = json.loads((root / 'composer.lock').read_text(encoding='utf-8'))
if installed.get('dev') or {(p['name'], p['version']) for p in installed['packages']} != {(p['name'], p['version']) for p in locked['packages']}:
    raise SystemExit('Production vendor packages must exactly match composer.lock; run composer install --no-dev.')
destination = Path(sys.argv[1]).resolve() if len(sys.argv) == 2 else root.parent / 'dist'
if destination == root or root in destination.parents:
    raise SystemExit('Output must be outside the source directory.')
destination.mkdir(parents=True, exist_ok=True)
archive = destination / f'WojteX-{version}-install.zip'
allowed_dirs = {'app','bootstrap','config','database','docs','extensions','lang','public','resources','routes','storage','themes','tools','vendor'}
allowed_files = {'.env.example','artisan','composer.json','composer.lock','LICENSE','README.md','THIRD_PARTY_NOTICES.md','VERSION','start-local.cmd','start-local.sh','package.json'}
files = []
for path in sorted(root.rglob('*')):
    rel = path.relative_to(root)
    if path.is_symlink():
        raise SystemExit(f'Symlinks are not supported: {rel}')
    if not path.is_file() or (rel.parts[0] not in allowed_dirs and str(rel) not in allowed_files):
        continue
    posix = rel.as_posix()
    if path.name.startswith('.env') and posix != '.env.example':
        continue
    if rel.parts[0] == 'storage' and path.name != '.gitignore':
        continue
    if posix.startswith('bootstrap/cache/') and path.name != '.gitignore':
        continue
    if rel.parts[0] == 'database' and len(rel.parts) < 3 and path.name != '.gitignore':
        continue
    if path.suffix in {'.log','.sqlite','.sqlite3','.sql'} or path.name in {'auth.json','install-token','installed.lock','prepare.lock'}:
        continue
    if any(part in {'.git','.github','.phpunit.cache','node_modules','__pycache__'} for part in rel.parts):
        continue
    if posix.startswith('vendor/composer/tmp-'):
        continue
    files.append((path,posix))
if archive.exists():
    raise SystemExit(f'Archive already exists: {archive}')
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as package:
    for path,rel in files:
        package.write(path,'wojtex-forum/'+rel)
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
(destination / f'WojteX-{version}-SHA256SUMS.txt').write_text(f'{digest}  {archive.name}\n',encoding='ascii')
print(f'Built {archive.name}: {len(files)} files, {archive.stat().st_size} bytes, SHA256 {digest}')
