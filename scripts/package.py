"""Build deterministic, separately installable Core and addon packages."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
import json
import re

root = Path(__file__).resolve().parent.parent

def package(source, slug, constant, tag_prefix):
    metadata = json.loads((source / 'update.json').read_text(encoding='utf-8'))
    version = metadata['version']
    assert re.fullmatch(r'\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?', version)
    bootstrap = (source / (slug + '.php')).read_text(encoding='utf-8')
    assert f' * Version: {version}\n' in bootstrap
    assert f"define('{constant}', '{version}');" in bootstrap
    output = root / 'dist' / f'{slug}-{version}.zip'
    assert metadata['download_url'] == f'https://github.com/paulommaeda/appshalom/releases/download/{tag_prefix}{version}/{output.name}'
    output.parent.mkdir(exist_ok=True)
    files = [source / (slug + '.php'), source / 'README.md', source / 'LICENSE']
    files += sorted((source / 'includes').rglob('*.php'))
    files += sorted((source / 'docs').rglob('*.md'))
    files += sorted(p for p in (source / 'assets').rglob('*') if p.is_file())
    with ZipFile(output, 'w', compression=ZIP_DEFLATED, compresslevel=9) as archive:
        for file in sorted(files):
            item = ZipInfo(slug + '/' + file.relative_to(source).as_posix(), (2026, 11, 16, 0, 0, 0))
            item.create_system = 3
            item.compress_type = ZIP_DEFLATED
            item.external_attr = 0o100644 << 16
            archive.writestr(item, file.read_bytes(), compresslevel=9)
    print(json.dumps({'file': str(output), 'bytes': output.stat().st_size, 'sha256': hashlib.sha256(output.read_bytes()).hexdigest()}))

package(root, 'igreja-erp-core', 'IGREJA_ERP_CORE_VERSION', 'v')
package(root / 'addons/igreja-erp-membros', 'igreja-erp-membros', 'IGREJA_ERP_MEMBROS_VERSION', 'membros-v')
