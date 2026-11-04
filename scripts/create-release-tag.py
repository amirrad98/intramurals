#!/usr/bin/env python3
"""Create an immutable annotated release tag for the tested main commit."""
import argparse
import json
import re
import subprocess

from build import ROOT, metadata


class GitHub:
    repository = "amirrad98/intramurals"

    def api(self, path, payload=None, missing_ok=False):
        command = ["gh", "api", "repos/" + self.repository + "/" + path]
        if payload is not None:
            command += ["--method", "POST", "--input", "-"]
        result = subprocess.run(command, input=json.dumps(payload) if payload is not None else None,
                                capture_output=True, text=True)
        if result.returncode:
            if missing_ok and "HTTP 404" in result.stderr:
                return None
            raise RuntimeError(result.stderr.strip())
        return json.loads(result.stdout)


def tag_commit(reference, client):
    """Accept lightweight and annotated tags, with a bounded peel on reruns."""
    target = reference["object"]
    for _ in range(8):
        if target["type"] == "commit":
            return target["sha"]
        if target["type"] != "tag" or not re.fullmatch(r"[0-9a-f]{40}", target["sha"]):
            break
        target = client.api("git/tags/" + target["sha"])["object"]
    raise ValueError("Existing release tag does not resolve to a commit")


def ensure_tag(tag, commit, checkout_commit, root=ROOT, client=None):
    if tag != "v" + metadata(root)["version"]:
        raise ValueError("Release tag must match the stable plugin version")
    if not re.fullmatch(r"[0-9a-f]{40}", commit) or commit != checkout_commit:
        raise ValueError("Release commit must be the checked-out, tested commit")
    client = client or GitHub()
    path = "git/ref/tags/" + tag
    existing = client.api(path, missing_ok=True)
    if existing:
        if tag_commit(existing, client) != commit:
            raise ValueError("Release tag already points to another commit; it will not be moved")
        return tag
    main = client.api("git/ref/heads/main")
    if main["object"]["type"] != "commit" or main["object"]["sha"] != commit:
        raise ValueError("Main has moved; run release checks again on its new commit")
    annotated = client.api("git/tags", {"tag": tag, "message": "LeagueFlow " + tag[1:],
                                       "object": commit, "type": "commit"})
    # Only create: concurrent creation fails safely, without updating any ref.
    client.api("git/refs", {"ref": "refs/tags/" + tag, "sha": annotated["sha"]})
    if tag_commit(client.api(path), client) != commit:
        raise RuntimeError("Created release tag could not be verified")
    return tag


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--tag", required=True)
    parser.add_argument("--commit", required=True)
    args = parser.parse_args()
    try:
        checkout = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip()
        print("Verified release tag " + ensure_tag(args.tag, args.commit, checkout))
    except (ValueError, RuntimeError, OSError, subprocess.CalledProcessError) as error:
        parser.exit(1, str(error) + "\n")
