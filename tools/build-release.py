#!/usr/bin/env python3
"""Build a reviewable plugin ZIP from an explicit checked-in file allowlist."""
import hashlib
import json
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
paths = json.loads((ROOT / 'tools/release-files.json').read_text())
version = re.search(r'\* Version: ([\d.]+)', (ROOT / 'cleara11y.php').read_text()).group(1)
output = ROOT / 'release' / f'cleara11y-{version}.zip'
output.parent.mkdir(exist_ok=True)
for name in paths:
    path = ROOT / name
    if Path(name).is_absolute() or '..' in Path(name).parts or path.is_symlink() or not path.is_file():
        raise SystemExit(f'Unsafe or missing release file: {name}')
    if any(parent.is_symlink() for parent in path.parents if parent != ROOT.parent):
        raise SystemExit(f'Symlink in release path: {name}')
    if path.suffix in {'.php', '.js', '.css', '.txt'}:
        text = path.read_text()
        patterns = [r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----', r'AKIA[0-9A-Z]{16}', r'gh[pousr]_[A-Za-z0-9]{30,}', r'sk_live_[A-Za-z0-9]{20,}']
        if any(re.search(pattern, text) for pattern in patterns):
            raise SystemExit(f'Potential secret in release file: {name}')
with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for name in sorted(paths):
        info = zipfile.ZipInfo('cleara11y/' + name, (2026, 1, 1, 0, 0, 0))
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(info, (ROOT / name).read_bytes())
with zipfile.ZipFile(output) as archive:
    assert archive.testzip() is None
    assert set(archive.namelist()) == {'cleara11y/' + name for name in paths}
digest = hashlib.sha256(output.read_bytes()).hexdigest()
output.with_suffix('.sha256').write_text(f'{digest}  {output.name}\n')
output.with_suffix('.manifest.txt').write_text('\n'.join(sorted(paths)) + '\n')
print(f'{output}: {len(paths)} files; SHA-256 {digest}')
