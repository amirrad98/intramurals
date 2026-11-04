import importlib.util
import sys
import unittest

from scripts.build import ROOT, metadata

sys.path.insert(0, str(ROOT / "scripts"))
spec = importlib.util.spec_from_file_location("release_tagger", ROOT / "scripts" / "create-release-tag.py")
tagger = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tagger)

COMMIT = "a" * 40
OTHER = "b" * 40
TAG_OBJECT = "c" * 40


class FakeGitHub:
    def __init__(self):
        self.main = COMMIT
        self.reference = None
        self.target = COMMIT
        self.writes = []
        self.race = False

    def api(self, path, payload=None, missing_ok=False):
        if payload is not None:
            self.writes.append((path, payload))
        if path == "git/ref/heads/main":
            return {"object": {"type": "commit", "sha": self.main}}
        if path.startswith("git/ref/tags/"):
            return self.reference
        if path == "git/tags":
            self.target = payload["object"]
            return {"sha": TAG_OBJECT}
        if path == "git/refs":
            if self.race:
                raise RuntimeError("Reference already exists (HTTP 422)")
            self.reference = {"object": {"type": "tag", "sha": payload["sha"]}}
            return self.reference
        if path == "git/tags/" + TAG_OBJECT:
            return {"object": {"type": "commit", "sha": self.target}}
        raise AssertionError(path)


class ReleaseTagTests(unittest.TestCase):
    def setUp(self):
        self.tag = "v" + metadata(ROOT)["version"]
        self.client = FakeGitHub()

    def ensure(self):
        return tagger.ensure_tag(self.tag, COMMIT, COMMIT, client=self.client)

    def test_create_annotated_tag_and_rerun_without_mutation(self):
        self.assertEqual(self.ensure(), self.tag)
        self.assertEqual([path for path, _ in self.client.writes], ["git/tags", "git/refs"])
        self.assertEqual(self.client.writes[0][1]["object"], COMMIT)
        before = list(self.client.writes)
        self.ensure()
        self.assertEqual(self.client.writes, before)

    def test_existing_lightweight_tag_is_preserved(self):
        self.client.reference = {"object": {"type": "commit", "sha": COMMIT}}
        self.ensure()
        self.assertEqual(self.client.writes, [])

    def test_existing_matching_tag_can_resume_after_main_advances(self):
        self.client.reference = {"object": {"type": "tag", "sha": TAG_OBJECT}}
        self.client.main = OTHER
        self.ensure()
        self.assertEqual(self.client.writes, [])

    def test_existing_conflicting_tag_is_never_moved(self):
        self.client.reference = {"object": {"type": "tag", "sha": TAG_OBJECT}}
        self.client.target = OTHER
        with self.assertRaises(ValueError):
            self.ensure()
        self.assertEqual(self.client.writes, [])

    def test_main_advance_rejects_without_writes(self):
        self.client.main = OTHER
        with self.assertRaises(ValueError):
            self.ensure()
        self.assertEqual(self.client.writes, [])

    def test_invalid_version_or_unverified_checkout_rejects_without_network(self):
        for tag, commit, checkout in ((self.tag + "-rc.1", COMMIT, COMMIT),
                                     (self.tag, COMMIT, OTHER), (self.tag, "main", "main")):
            with self.subTest(tag=tag, commit=commit, checkout=checkout):
                with self.assertRaises(ValueError):
                    tagger.ensure_tag(tag, commit, checkout, client=self.client)
        self.assertEqual(self.client.writes, [])

    def test_concurrent_tag_creation_fails_without_force_update(self):
        self.client.race = True
        with self.assertRaises(RuntimeError):
            self.ensure()
        self.assertEqual([path for path, _ in self.client.writes], ["git/tags", "git/refs"])


if __name__ == "__main__":
    unittest.main()
