"""Generate the installable WordPress release ZIP."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib
import json
import re

root = Path(__file__).resolve().parent.parent
metadata = json.loads((root / "update.json").read_text(encoding="utf-8"))
version = metadata["version"]
assert re.fullmatch(r"\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?", version)
bootstrap = (root / "igreja-erp-core.php").read_text(encoding="utf-8")
assert f" * Version: {version}\n" in bootstrap
assert f"define('IGREJA_ERP_CORE_VERSION', '{version}');" in bootstrap
output = root / "dist" / f"igreja-erp-core-{version}.zip"
assert metadata["download_url"] == f"https://github.com/paulommaeda/appshalom/releases/download/v{version}/{output.name}"
output.parent.mkdir(exist_ok=True)
files = [root / "igreja-erp-core.php", root / "README.md", root / "LICENSE"]
files += sorted((root / "includes").rglob("*.php"))
with ZipFile(output, "w", compression=ZIP_DEFLATED, compresslevel=9) as archive:
    for file in sorted(files):
        item = ZipInfo("igreja-erp-core/" + file.relative_to(root).as_posix(), (2026, 11, 16, 0, 0, 0))
        item.create_system = 3
        item.compress_type = ZIP_DEFLATED
        item.external_attr = 0o100644 << 16
        archive.writestr(item, file.read_bytes(), compresslevel=9)
print(json.dumps({"file": str(output), "bytes": output.stat().st_size, "sha256": hashlib.sha256(output.read_bytes()).hexdigest()}))
