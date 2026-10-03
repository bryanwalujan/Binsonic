#!/usr/bin/env python3
"""Create a fresh whitelisted cPanel upload zip, excluding all local secrets and data."""
import argparse
import hashlib
from pathlib import Path
import shutil
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--with-vendor', action='store_true', help='Install locked production dependencies in staging, not the workspace')
    args = parser.parse_args()
    if not (ROOT / 'public/build/manifest.json').is_file():
        parser.error('Run npm ci && npm run build first')
    out = ROOT / 'dist'
    out.mkdir(exist_ok=True)
    archive = out / ('binsonic-terra-cpanel-with-vendor.zip' if args.with_vendor else 'binsonic-terra-cpanel.zip')
    with tempfile.TemporaryDirectory(prefix='binsonic-cpanel-') as temporary:
        stage = Path(temporary) / 'binsonic-terra'
        stage.mkdir()
        ignored = shutil.ignore_patterns('__pycache__', '.DS_Store', '*.sqlite', '*.sqlite-*', '*.log')
        for name in ('app', 'config', 'resources', 'routes'):
            shutil.copytree(ROOT / name, stage / name, ignore=ignored)
        for name in ('artisan', 'composer.json', 'composer.lock', '.env.example', 'README.md'):
            shutil.copy2(ROOT / name, stage / name)
        (stage / 'bootstrap/cache').mkdir(parents=True)
        for source in (ROOT / 'bootstrap').glob('*.php'):
            shutil.copy2(source, stage / 'bootstrap' / source.name)
        for name in ('migrations', 'seeders', 'factories'):
            shutil.copytree(ROOT / 'database' / name, stage / 'database' / name, ignore=ignored)
        shutil.copytree(ROOT / 'public', stage / 'public', ignore=shutil.ignore_patterns('hot', 'storage', '.env*', '*.zip'))
        shutil.copytree(ROOT / 'deploy', stage / 'deploy', ignore=ignored)
        (stage / 'docs').mkdir()
        for name in ('DEPLOY_CPANEL.md', 'API_CONTRACT.md', 'HOSTING_TEST.md', 'MQ7_TREND.md', 'DEPLOYMENT_VERIFICATION.md'):
            shutil.copy2(ROOT / 'docs' / name, stage / 'docs' / name)
        shutil.copytree(ROOT / 'docs/examples', stage / 'docs/examples')
        (stage / 'docs/verification').mkdir()
        for name in ('cpanel-package.json', 'cpanel-local-http.json'):
            if (ROOT / 'docs/verification' / name).exists():
                shutil.copy2(ROOT / 'docs/verification' / name, stage / 'docs/verification' / name)
        shutil.copytree(ROOT / 'docs/verification/mq7', stage / 'docs/verification/mq7')
        for folder in ('bridge', 'simulator'):
            (stage / folder).mkdir()
            for source in (ROOT / folder).iterdir():
                if source.is_file() and source.name in ('serial_bridge.py', 'simulate.py', 'requirements.txt', '__init__.py', 'README.md', 'SERIAL_PROTOCOL.md'):
                    shutil.copy2(source, stage / folder / source.name)
        shutil.copytree(ROOT / 'bridge/examples', stage / 'bridge/examples')
        for name in ('app/private/events', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'):
            folder = stage / 'storage' / name
            folder.mkdir(parents=True, exist_ok=True)
            (folder / '.gitignore').write_text('*\n!.gitignore\n')
        if args.with_vendor:
            subprocess.run(['composer', 'install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction'], cwd=stage, check=True)
            subprocess.run(['composer', 'check-platform-reqs', '--no-dev'], cwd=stage, check=True)
        # Never ship bootstrap cache generated using build-machine environment/absolute paths.
        for cached in (stage / 'bootstrap/cache').glob('*'):
            if cached.is_file():
                cached.unlink()
        (stage / 'bootstrap/cache/.gitignore').write_text('*\n!.gitignore\n')
        with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as output:
            for source in sorted(stage.rglob('*')):
                if source.is_file():
                    if source.name == '.env' or source.name.startswith('.env.') and source.name != '.env.example':
                        raise RuntimeError('Unexpected environment file in staging')
                    output.write(source, source.relative_to(Path(temporary)))
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    archive.with_suffix('.zip.sha256').write_text(f'{digest}  {archive.name}\n')
    print(f'Created {archive.name}: {archive.stat().st_size:,} bytes; SHA256 {digest}')


if __name__ == '__main__':
    main()
