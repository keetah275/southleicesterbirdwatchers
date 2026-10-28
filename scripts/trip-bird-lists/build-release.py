"""Build an installable ZIP containing only the plugin's release files."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import hashlib
import re

root = Path(__file__).resolve().parents[2]
plugin = root / 'app/public/wp-content/plugins/slb-trip-bird-lists'
version = re.search(r'\* Version: ([0-9.]+)', (plugin / 'slb-trip-bird-lists.php').read_text()).group(1)
files = [
    'slb-trip-bird-lists.php', 'includes-admin.php', 'readme.txt',
    'assets/editor.js', 'assets/editor.css', 'assets/report.css',
]
destination = root / 'dist' / f'slb-trip-bird-lists-{version}.zip'
destination.parent.mkdir(exist_ok=True)
with ZipFile(destination, 'w', ZIP_DEFLATED) as archive:
    for filename in files:
        archive.write(plugin / filename, f'slb-trip-bird-lists/{filename}')
with ZipFile(destination) as archive:
    assert archive.testzip() is None
    assert len(archive.namelist()) == len(files)
print(destination)
print('SHA256:', hashlib.sha256(destination.read_bytes()).hexdigest())
