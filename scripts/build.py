#!/usr/bin/env python3
"""Build an exact, deterministic WordPress distribution and public update manifest."""
import argparse
import hashlib
import json
from pathlib import Path
import re
from zipfile import ZipFile, ZipInfo, ZIP_STORED

ROOT = Path(__file__).resolve().parents[1]
REPOSITORY = "https://github.com/amirrad98/intramurals"
VERSION = r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)"
ROOT_FILES = ("leagueflow.php", "uninstall.php", "README.md", "ARCHITECTURE.md", "SPORTS.md")
RUNTIME = {
    "includes": {".php"}, "templates": {".php", ".html"}, "blocks": {".json"},
    "assets": {".css", ".js", ".png", ".jpg", ".jpeg", ".gif", ".svg", ".webp", ".woff", ".woff2", ".ttf"},
}


def metadata(root):
    source = (root / "leagueflow.php").read_text()
    header = re.search(r"^ \* Version: (.+)$", source, re.M)
    constant = re.search(r"define\(\s*'LEAGUEFLOW_VERSION',\s*'([^']+)'\s*\)", source)
    if not header or not constant or not re.fullmatch(VERSION, header[1]) or header[1] != constant[1]:
        raise ValueError("Plugin header and version constant must match a stable X.Y.Z version")
    requirements = {}
    for field, label in (("requires", "Requires at least"), ("requires_php", "Requires PHP")):
        match = re.search(r"^ \* " + label + r": ([0-9]+\.[0-9]+(?:\.[0-9]+)?)$", source, re.M)
        if not match:
            raise ValueError("Missing supported-version header: " + label)
        requirements[field] = match[1]
    if " * Update URI: " + REPOSITORY not in source:
        raise ValueError("Update URI must identify the public LeagueFlow repository")
    return {"version": header[1], **requirements}


def package_files(root):
    files = [root / name for name in ROOT_FILES]
    for directory, suffixes in RUNTIME.items():
        base = root / directory
        if base.is_symlink() or not base.is_dir():
            raise ValueError("Missing or unsafe runtime directory: " + directory)
        for path in sorted(base.rglob("*")):
            relative = path.relative_to(root)
            if path.is_symlink() or any(part.startswith(".") for part in relative.parts) or "\\" in str(relative):
                raise ValueError("Unsafe runtime entry: " + str(relative))
            if path.is_file():
                if path.suffix not in suffixes:
                    raise ValueError("Unexpected runtime file type: " + str(relative))
                files.append(path)
    for path in files:
        if path.is_symlink() or not path.is_file():
            raise ValueError("Missing or unsafe package file: " + str(path))
    return sorted(files, key=lambda p: p.relative_to(root).as_posix())


def build(root=ROOT, output=None, tag=None):
    root = Path(root)
    data = metadata(root)
    version = data["version"]
    if tag is not None and tag != "v" + version:
        raise ValueError("Release tag must exactly match v" + version)
    files = package_files(root)
    output = Path(output) if output else root / "dist"
    output.mkdir(parents=True, exist_ok=True)
    archive = output / ("leagueflow-" + version + ".zip")
    with ZipFile(archive, "w", compression=ZIP_STORED) as package:
        for path in files:
            entry = ZipInfo("leagueflow/" + path.relative_to(root).as_posix(), (2020, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            package.writestr(entry, path.read_bytes())
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    archive.with_suffix(".zip.sha256").write_text(digest + "  " + archive.name + "\n")
    manifest = {"schema": 1, "slug": "leagueflow", **data, "sha256": digest,
                "package": REPOSITORY + "/releases/download/v" + version + "/" + archive.name}
    (output / "latest.json").write_text(json.dumps(manifest, indent=2) + "\n")
    return archive, manifest


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--tag", help="Stable release tag; must match plugin metadata")
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    try:
        archive, manifest = build(output=args.output, tag=args.tag)
    except ValueError as error:
        parser.error(str(error))
    print(archive)
    print(manifest["sha256"])
