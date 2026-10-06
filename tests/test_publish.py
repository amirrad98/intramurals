import copy
import importlib.util
from pathlib import Path
import shutil
import sys
import tempfile
import unittest

from scripts.build import ROOT, build, metadata

sys.path.insert(0, str(ROOT / "scripts"))
spec = importlib.util.spec_from_file_location("publisher", ROOT / "scripts" / "publish-release.py")
publisher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)


class FakeGitHub:
    def __init__(self):
        self.releases = {}
        self.assets = {}
        self.latest = None
        self.events = []

    def view(self, tag=None):
        tag = tag or self.latest
        result = copy.deepcopy(self.releases.get(tag))
        if result:
            result["assets"] = [{"name": name} for name in self.assets.get(tag, {})]
        return result

    def create(self, tag, notes):
        self.events.append(("create-draft", tag))
        self.releases[tag] = {"tagName": tag, "isDraft": True, "isPrerelease": False}
        self.assets[tag] = {}

    def upload(self, tag, path):
        self.events.append(("upload", path.name))
        self.assets[tag][path.name] = path.read_bytes()

    def download(self, tag, name):
        return self.assets[tag][name]

    def make_public(self, tag, latest):
        self.events.append(("publish", latest))
        self.releases[tag]["isDraft"] = False
        if latest:
            self.latest = tag


class PublishTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name) / "source"
        shutil.copytree(ROOT, self.root, ignore=shutil.ignore_patterns(".git", "dist", "__pycache__"))
        build(self.root)
        self.tag = "v" + metadata(self.root)["version"]
        self.client = FakeGitHub()

    def tearDown(self):
        self.temp.cleanup()

    def publish(self):
        return publisher.publish(self.root, self.tag, self.client)

    def test_new_release_is_complete_before_publication_and_rerun_is_unchanged(self):
        self.assertEqual(self.publish(), self.tag)
        self.assertEqual(self.client.events[0], ("create-draft", self.tag))
        self.assertEqual(self.client.events[-1], ("publish", True))
        self.assertEqual(len(self.client.assets[self.tag]), 3)
        before = list(self.client.events)
        self.publish()
        self.assertEqual(before, self.client.events)

    def test_partial_draft_resumes_without_overwriting_matching_asset(self):
        self.client.create(self.tag, None)
        self.client.upload(self.tag, self.root / "dist" / "latest.json")
        self.client.events = []
        self.publish()
        self.assertEqual(len([e for e in self.client.events if e[0] == "upload"]), 2)
        self.assertEqual(self.client.events[-1], ("publish", True))

    def test_conflicting_asset_fails_without_upload_or_publication(self):
        self.client.create(self.tag, None)
        self.client.assets[self.tag]["latest.json"] = b"changed"
        self.client.events = []
        with self.assertRaises(ValueError):
            self.publish()
        self.assertEqual(self.client.events, [])
        self.assertTrue(self.client.releases[self.tag]["isDraft"])

    def test_incomplete_public_release_cannot_be_mutated(self):
        self.client.create(self.tag, None)
        self.client.releases[self.tag]["isDraft"] = False
        before = list(self.client.events)
        with self.assertRaises(ValueError):
            self.publish()
        self.assertEqual(before, self.client.events)

    def test_older_version_cannot_replace_latest(self):
        newer = "v9.0.0"
        self.client.releases[newer] = {"tagName": newer, "isDraft": False, "isPrerelease": False}
        self.client.latest = newer
        self.publish()
        self.assertEqual(self.client.events[-1], ("publish", False))
        self.assertEqual(self.client.latest, newer)

    def test_prerelease_tag_and_modified_manifest_fail_before_network_changes(self):
        with self.assertRaises(ValueError):
            publisher.publish(self.root, self.tag + "-rc.1", self.client)
        (self.root / "dist" / "latest.json").write_text("{}")
        with self.assertRaises(ValueError):
            self.publish()
        self.assertEqual(self.client.events, [])


if __name__ == "__main__":
    unittest.main()
