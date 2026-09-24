#!/usr/bin/env python3
"""Validate the reviewed release ZIP and stage its exact contents for SVN."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]


def prepare(tag, output):
    header = (ROOT / 'cleara11y.php').read_text()
    version = re.search(r'^\s*\* Version: (\d+\.\d+\.\d+)\s*$', header, re.M).group(1)
    runtime_version = re.search(r"define\('CLEARA11Y_VERSION', '([^']+)'\);", header).group(1)
    stable = re.search(r'^Stable tag: (.+)$', (ROOT / 'readme.txt').read_text(), re.M).group(1).strip()
    if stable != version or runtime_version != version or (tag is not None and tag != 'v' + version):
        raise SystemExit('Git tag, plugin version and readme stable tag must agree.')
    archive_path = ROOT / 'release' / f'cleara11y-{version}.zip'
    expected_hash = archive_path.with_suffix('.sha256').read_text().split()[0]
    if hashlib.sha256(archive_path.read_bytes()).hexdigest() != expected_hash:
        raise SystemExit('Release ZIP does not match its recorded SHA-256.')
    paths = json.loads((ROOT / 'tools/release-files.json').read_text())
    if len(paths) != len(set(paths)):
        raise SystemExit('Duplicate release allowlist entry.')
    with zipfile.ZipFile(archive_path) as archive:
        expected_names = {'cleara11y/' + name for name in paths}
        if set(archive.namelist()) != expected_names or len(archive.namelist()) != len(paths):
            raise SystemExit('Release ZIP does not match the production file allowlist.')
        for name in paths:
            source = ROOT / name
            if Path(name).is_absolute() or '..' in Path(name).parts or source.resolve() != source or not source.is_file():
                raise SystemExit(f'Unsafe or missing release file: {name}')
            if archive.read('cleara11y/' + name) != source.read_bytes():
                raise SystemExit(f'Release ZIP differs from source: {name}; rebuild and review the ZIP.')
        # Refuse an existing destination, preventing stale files from being deployed.
        output.mkdir(parents=True, exist_ok=False)
        for name in paths:
            destination = output / name
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_bytes(archive.read('cleara11y/' + name))
    print(f'Validated and staged {len(paths)} files for Cleara11y {version}.')
    print(f'SHA-256: {expected_hash}')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--tag', help='Require an exact vMAJOR.MINOR.PATCH Git tag.')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    prepare(args.tag, args.output)
