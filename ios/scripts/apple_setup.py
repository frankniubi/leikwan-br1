#!/usr/bin/env python3
"""Sets up Apple signing for the Bridge iOS app from one App Store Connect API key.

Run on any machine (no Xcode needed):

    python ios/scripts/apple_setup.py --key AuthKey_XXXX.p8 --key-id XXXX --issuer-id <uuid> \
        --team-id XXXXXXXXXX [--app-name "Bridge VPN"] [--github-repo owner/repo]

What it does, idempotently:
  1. registers the two bundle ids (app + .tunnel) with the App Groups and Network
     Extensions capabilities (the group itself is registered by Xcode's automatic signing);
  2. checks for the App Store Connect app record (Apple's API cannot create one; the script
     prints the one manual step if it is missing);
  3. with --github-repo, stores APPLE_TEAM_ID / ASC_KEY_ID / ASC_ISSUER_ID / ASC_KEY_P8 as
     GitHub Actions secrets (token from `git credential fill`, or GITHUB_TOKEN).
Certificates and provisioning profiles are created later by xcodebuild in CI
(-allowProvisioningUpdates with the same key).
"""
import argparse, base64, json, os, subprocess, sys, time, urllib.error, urllib.request

APP_ID = "org.forfoxsakesantuary.foxbridgeinternal"
TUNNEL_ID = APP_ID + ".tunnel"
GROUP_ID = "group." + APP_ID
API = "https://api.appstoreconnect.apple.com/v1"


def token(key_path, key_id, issuer):
    import jwt  # pyjwt[crypto]
    now = int(time.time())
    return jwt.encode({"iss": issuer, "iat": now, "exp": now + 1100, "aud": "appstoreconnect-v1"},
                      open(key_path).read(), algorithm="ES256", headers={"kid": key_id, "typ": "JWT"})


class Client:
    def __init__(self, args):
        self.args = args
        self.jwt = token(args.key, args.key_id, args.issuer_id)
        proxy = os.environ.get("https_proxy") or os.environ.get("HTTPS_PROXY")
        handlers = [urllib.request.ProxyHandler({"https": proxy})] if proxy else []
        self.opener = urllib.request.build_opener(*handlers)

    def call(self, method, path, body=None):
        url = path if path.startswith("http") else API + path
        req = urllib.request.Request(url, method=method, data=json.dumps(body).encode() if body else None)
        req.add_header("Authorization", "Bearer " + self.jwt)
        req.add_header("Content-Type", "application/json")
        try:
            with self.opener.open(req, timeout=60) as r:
                raw = r.read()
                return r.status, (json.loads(raw) if raw else {})
        except urllib.error.HTTPError as e:
            raw = e.read()
            return e.code, (json.loads(raw) if raw else {})


def die(msg):
    print("ERROR: " + msg)
    sys.exit(1)


def errors(data):
    return "; ".join("%s %s" % (e.get("code"), e.get("detail")) for e in data.get("errors", []))


def ensure_bundle(c, identifier, name):
    st, data = c.call("GET", "/bundleIds?filter[identifier]=%s&limit=5" % identifier)
    if st != 200:
        die("listing bundle ids failed (%s): %s" % (st, errors(data)))
    for item in data.get("data", []):
        if item["attributes"]["identifier"] == identifier:
            print("bundle id exists: " + identifier)
            return item["id"]
    st, data = c.call("POST", "/bundleIds", {"data": {"type": "bundleIds", "attributes": {
        "identifier": identifier, "name": name, "platform": "IOS"}}})
    if st != 201:
        die("creating bundle id %s failed (%s): %s" % (identifier, st, errors(data)))
    print("bundle id registered: " + identifier)
    return data["data"]["id"]


def ensure_capability(c, bundle_pk, cap):
    st, data = c.call("GET", "/bundleIds/%s/bundleIdCapabilities" % bundle_pk)
    have = {i["attributes"]["capabilityType"] for i in data.get("data", [])} if st == 200 else set()
    if cap in have:
        print("  capability on: " + cap)
        return
    st, data = c.call("POST", "/bundleIdCapabilities", {"data": {
        "type": "bundleIdCapabilities",
        "attributes": {"capabilityType": cap},
        "relationships": {"bundleId": {"data": {"type": "bundleIds", "id": bundle_pk}}}}})
    if st not in (200, 201):
        die("enabling %s failed (%s): %s" % (cap, st, errors(data)))
    print("  capability enabled: " + cap)


def app_record(c):
    st, data = c.call("GET", "/apps?filter[bundleId]=" + APP_ID)
    if st != 200:
        die("listing apps failed (%s): %s" % (st, errors(data)))
    items = data.get("data", [])
    return items[0] if items else None


def set_github_secrets(repo, secrets):
    from nacl import encoding, public  # pynacl
    tok = os.environ.get("GITHUB_TOKEN")
    if not tok:
        out = subprocess.run(["git", "credential", "fill"], input="protocol=https\nhost=github.com\n\n",
                             capture_output=True, text=True).stdout
        tok = next((l[9:] for l in out.splitlines() if l.startswith("password=")), "")
    if not tok:
        die("no GitHub token (set GITHUB_TOKEN)")
    proxy = os.environ.get("https_proxy") or os.environ.get("HTTPS_PROXY")
    opener = urllib.request.build_opener(*([urllib.request.ProxyHandler({"https": proxy})] if proxy else []))

    def gh(method, path, body=None):
        req = urllib.request.Request("https://api.github.com/repos/%s%s" % (repo, path), method=method,
                                     data=json.dumps(body).encode() if body else None)
        req.add_header("Authorization", "token " + tok)
        req.add_header("Accept", "application/vnd.github+json")
        with opener.open(req, timeout=60) as r:
            raw = r.read()
            return json.loads(raw) if raw else {}

    key = gh("GET", "/actions/secrets/public-key")
    box = public.SealedBox(public.PublicKey(key["key"].encode(), encoding.Base64Encoder()))
    for name, value in secrets.items():
        sealed = base64.b64encode(box.encrypt(value.encode())).decode()
        gh("PUT", "/actions/secrets/" + name, {"encrypted_value": sealed, "key_id": key["key_id"]})
        print("github secret set: " + name)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--key", required=True)
    ap.add_argument("--key-id", required=True)
    ap.add_argument("--issuer-id", required=True)
    ap.add_argument("--team-id", required=True)
    ap.add_argument("--app-name", default="Bridge")
    ap.add_argument("--github-repo")
    args = ap.parse_args()

    c = Client(args)
    app_pk = ensure_bundle(c, APP_ID, args.app_name)
    for cap in ("APP_GROUPS", "NETWORK_EXTENSIONS"):
        ensure_capability(c, app_pk, cap)
    tunnel_pk = ensure_bundle(c, TUNNEL_ID, args.app_name + " Tunnel")
    for cap in ("APP_GROUPS", "NETWORK_EXTENSIONS"):
        ensure_capability(c, tunnel_pk, cap)

    if args.github_repo:
        set_github_secrets(args.github_repo, {
            "APPLE_TEAM_ID": args.team_id,
            "ASC_KEY_ID": args.key_id,
            "ASC_ISSUER_ID": args.issuer_id,
            "ASC_KEY_P8": open(args.key).read(),
        })

    record = app_record(c)
    if record:
        print("app record: %s (Apple id %s)" % (record["attributes"]["name"], record["id"]))
    else:
        print("APP RECORD MISSING: in App Store Connect > Apps > + > New App, pick bundle id "
              + APP_ID + " (Apple's API cannot create app records).")


if __name__ == "__main__":
    main()
