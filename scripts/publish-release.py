#!/usr/bin/env python3
"""Publish verified assets as a draft, then expose a complete immutable release."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import tempfile

from build import ROOT, REPOSITORY, VERSION, metadata


class GitHub:
    repository = "amirrad98/intramurals"

    def command(self, *arguments):
        result = subprocess.run(["gh", *arguments, "--repo", self.repository], capture_output=True, text=True)
        if result.returncode:
            raise RuntimeError(result.stderr.strip())
        return result.stdout

    def view(self, tag=None):
        args = ["gh", "release", "view"] + ([tag] if tag else [])
        result = subprocess.run(args + ["--repo", self.repository, "--json", "tagName,isDraft,isPrerelease,assets"], capture_output=True, text=True)
        if result.returncode:
            if "release not found" in result.stderr.lower() or "http 404" in result.stderr.lower():
                return None
            raise RuntimeError(result.stderr.strip())
        return json.loads(result.stdout)

    def create(self, tag, notes):
        self.command("release", "create", tag, "--draft", "--verify-tag", "--title", "LeagueFlow " + tag[1:], "--notes-file", str(notes))

    def upload(self, tag, path):
        self.command("release", "upload", tag, str(path))

    def download(self, tag, name):
        with tempfile.TemporaryDirectory(prefix="leagueflow-release-") as directory:
            self.command("release", "download", tag, "--pattern", name, "--dir", directory)
            return (Path(directory) / name).read_bytes()

    def make_public(self, tag, latest):
        self.command("release", "edit", tag, "--draft=false", "--latest=" + str(latest).lower())


def publish(root=ROOT, tag=None, client=None):
    root = Path(root)
    info = metadata(root)
    if tag != "v" + info["version"]:
        raise ValueError("Publication tag must match the stable plugin version")
    notes = root / "releases" / (info["version"] + ".md")
    if not notes.is_file():
        raise ValueError("Release notes are required")
    dist = root / "dist"
    zip_name = "leagueflow-" + info["version"] + ".zip"
    paths = [dist / zip_name, dist / (zip_name + ".sha256"), dist / "latest.json"]
    expected = {path.name: path.read_bytes() for path in paths}
    digest = hashlib.sha256(expected[zip_name]).hexdigest()
    manifest = json.loads(expected["latest.json"])
    if manifest != {"schema": 1, "slug": "leagueflow", **info, "sha256": digest,
                    "package": REPOSITORY + "/releases/download/" + tag + "/" + zip_name}:
        raise ValueError("Manifest does not match built package metadata/checksum")
    if expected[zip_name + ".sha256"] != (digest + "  " + zip_name + "\n").encode():
        raise ValueError("Checksum sidecar does not match package")

    client = client or GitHub()
    latest = client.view()
    promote = True
    if latest:
        latest_tag = latest["tagName"]
        if not re.fullmatch("v" + VERSION, latest_tag) or latest.get("isPrerelease"):
            raise ValueError("Latest stable release has unexpected metadata")
        promote = tuple(map(int, info["version"].split("."))) > tuple(map(int, latest_tag[1:].split(".")))
    existing = client.view(tag)
    if existing and existing.get("isPrerelease"):
        raise ValueError("Stable tag already belongs to a prerelease")
    if not existing:
        client.create(tag, notes)
        existing = client.view(tag)
        if not existing or not existing["isDraft"]:
            raise RuntimeError("Draft release creation could not be verified")
    asset_names = {asset["name"] for asset in existing["assets"]}
    missing = set(expected) - asset_names
    if missing and not existing["isDraft"]:
        raise ValueError("Published release is incomplete; existing versions cannot be overwritten")
    # Check every existing asset before any upload, including partially completed drafts.
    for name in sorted(set(expected) & asset_names):
        if client.download(tag, name) != expected[name]:
            raise ValueError("Existing release asset has different bytes: " + name)
    for path in paths:
        if path.name in missing:
            client.upload(tag, path)
    for name, data in expected.items():
        if client.download(tag, name) != data:
            raise ValueError("Uploaded release asset could not be verified: " + name)
    if existing["isDraft"]:
        client.make_public(tag, promote)
    return tag


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--tag", required=True)
    args = parser.parse_args()
    try:
        print("Published verified release " + publish(tag=args.tag))
    except (ValueError, RuntimeError, OSError) as error:
        parser.exit(1, str(error) + "\n")
