import hashlib
import json
from pathlib import Path
import shutil
import tempfile
import unittest
from zipfile import ZipFile, ZIP_STORED

from scripts.build import ROOT, ROOT_FILES, RUNTIME, build, metadata


class BuildTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name) / "source"
        shutil.copytree(ROOT, self.root, ignore=shutil.ignore_patterns(".git", "dist", "__pycache__"))

    def tearDown(self):
        self.temp.cleanup()

    def test_deterministic_exact_inventory_and_manifest(self):
        first, manifest = build(self.root, self.root / "first")
        second, again = build(self.root, self.root / "second")
        self.assertEqual(first.read_bytes(), second.read_bytes())
        self.assertEqual(manifest, again)
        expected = set(ROOT_FILES)
        for directory in RUNTIME:
            expected.update(p.relative_to(self.root).as_posix() for p in (self.root / directory).rglob("*") if p.is_file())
        with ZipFile(first) as z:
            self.assertEqual(set(z.namelist()), {"leagueflow/" + name for name in expected})
            self.assertEqual(z.namelist(), sorted(z.namelist()))
            for info in z.infolist():
                self.assertEqual(info.date_time, (2020, 1, 1, 0, 0, 0))
                self.assertEqual(info.external_attr >> 16, 0o100644)
                self.assertEqual(info.compress_type, ZIP_STORED)
                self.assertEqual(z.read(info), (self.root / info.filename.removeprefix("leagueflow/")).read_bytes())
        self.assertEqual(manifest["sha256"], hashlib.sha256(first.read_bytes()).hexdigest())
        self.assertEqual(manifest["version"], metadata(self.root)["version"])
        self.assertEqual(manifest["requires"], "6.5")
        self.assertEqual(manifest["requires_php"], "8.1")
        self.assertEqual(json.loads((first.parent / "latest.json").read_text()), manifest)
        self.assertEqual(first.with_suffix(".zip.sha256").read_text(), manifest["sha256"] + "  " + first.name + "\n")

    def test_invalid_tags_and_version_metadata(self):
        version = metadata(self.root)["version"]
        build(self.root, tag="v" + version)
        for tag in ["v9.9.9", "v" + version + "-rc.1", version, "vbad", "v01.0.2"]:
            with self.subTest(tag=tag), self.assertRaises(ValueError):
                build(self.root, tag=tag)
        plugin = self.root / "leagueflow.php"
        source = plugin.read_text()
        mismatch = str(int(version.split(".")[0]) + 1) + ".0.0"
        for bad in ["01.0.2", "1.0.2-beta.1", "1.2", mismatch]:
            plugin.write_text(source.replace(" * Version: " + version, " * Version: " + bad))
            with self.subTest(version=bad), self.assertRaises(ValueError):
                build(self.root)
        plugin.write_text(source.replace(" * Update URI:", " * Broken Update URI:"))
        with self.assertRaises(ValueError):
            build(self.root)

    def test_unsafe_runtime_entries_fail(self):
        for name in [".env", "credentials.txt", "bad\\path.php"]:
            path = self.root / "includes" / name
            path.write_text("not distributable")
            with self.subTest(name=name), self.assertRaises(ValueError):
                build(self.root)
            path.unlink()
        link = self.root / "includes" / "linked.php"
        link.symlink_to(self.root / "leagueflow.php")
        with self.assertRaises(ValueError):
            build(self.root)
        link.unlink()
        directory = self.root / "includes" / "linked-dir"
        directory.symlink_to(self.root / "templates", target_is_directory=True)
        with self.assertRaises(ValueError):
            build(self.root)


if __name__ == "__main__":
    unittest.main()
