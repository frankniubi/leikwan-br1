#!/usr/bin/env python3
"""Fallback when automatic signing refuses to archive in CI: create the App Store
distribution certificate and profiles directly through the App Store Connect API.

    python ios/scripts/apple_signing_manual.py --key AuthKey.p8 --key-id X --issuer-id Y \
        [--github-repo owner/repo] [--out ios/build/signing]

Writes <out>/distribution.p12 (+ password), <out>/app.mobileprovision,
<out>/tunnel.mobileprovision and, with --github-repo, stores them as GitHub secrets:
MANUAL_P12_BASE64, MANUAL_P12_PASSWORD, MANUAL_PROFILE_APP_BASE64,
MANUAL_PROFILE_TUNNEL_BASE64. Destructive only in the sense that it creates a real
distribution certificate and two App Store profiles for the team.
"""
import argparse, base64, json, os, pathlib, secrets, subprocess, tempfile, time, urllib.error, urllib.parse, urllib.request

APP_ID = "org.forfoxsakesantuary.foxbridgeinternal"
TUNNEL_ID = APP_ID + ".tunnel"
API = "https://api.appstoreconnect.apple.com/v1"


def client(key, key_id, issuer):
    import jwt
    now = int(time.time())
    tok = jwt.encode({"iss": issuer, "iat": now, "exp": now + 1100, "aud": "appstoreconnect-v1"},
                     open(key).read(), algorithm="ES256", headers={"kid": key_id, "typ": "JWT"})
    proxy = os.environ.get("https_proxy") or os.environ.get("HTTPS_PROXY")
    opener = urllib.request.build_opener(*([urllib.request.ProxyHandler({"https": proxy})] if proxy else []))

    def call(method, path, body=None):
        req = urllib.request.Request(API + path, method=method,
                                     data=json.dumps(body).encode() if body else None)
        req.add_header("Authorization", "Bearer " + tok)
        req.add_header("Content-Type", "application/json")
        try:
            with opener.open(req, timeout=90) as r:
                raw = r.read()
                return r.status, (json.loads(raw) if raw else {})
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read() or b"{}")

    return call


def fail(data):
    return "; ".join("%s %s" % (e.get("code"), e.get("detail")) for e in data.get("errors", []))


def bundle_pk(call, identifier):
    st, d = call("GET", "/bundleIds?filter[identifier]=" + identifier)
    if st != 200 or not d.get("data"):
        raise SystemExit("bundle id %s: %s %s" % (identifier, st, fail(d)))
    return d["data"][0]["id"]


def existing_distribution_cert(call):
    st, d = call("GET", "/certificates?filter[certificateType]=IOS_DISTRIBUTION&limit=5")
    if st != 200 or not d.get("data"):
        raise SystemExit("no distribution certificate yet; run once without --profiles-only")
    return d["data"][0]["id"]


def distribution_cert(call, work, rotate, revoke):
    st, d = call("GET", "/certificates?filter[certificateType]=IOS_DISTRIBUTION&limit=5")
    existing = d.get("data", []) if st == 200 else []
    if revoke:
        # The private keys of older certificates are gone; clear them so the profile always
        # binds to the certificate whose .p12 we are about to store.
        for item in existing:
            st, err = call("DELETE", "/certificates/" + item["id"])
            print("revoked certificate %s (%s)" % (item["id"], st))
        existing = []
    elif existing and not rotate:
        raise SystemExit("a distribution certificate already exists; pass --rotate to create "
                         "a second one (Apple allows two)")
    key = work / "dist.key"
    csr = work / "dist.csr"
    subprocess.run(["openssl", "req", "-new", "-newkey", "rsa:2048", "-nodes", "-keyout", str(key),
                    "-out", str(csr), "-subj", "/CN=Bridge CI Distribution"],
                   check=True, capture_output=True)
    st, d = call("POST", "/certificates", {"data": {"type": "certificates", "attributes": {
        "certificateType": "IOS_DISTRIBUTION", "csrContent": csr.read_text()}}})
    if st != 201:
        raise SystemExit("creating the distribution certificate failed: %s %s" % (st, fail(d)))
    der = work / "dist.cer"
    der.write_bytes(base64.b64decode(d["data"]["attributes"]["certificateContent"]))
    pem = work / "dist.pem"
    subprocess.run(["openssl", "x509", "-inform", "DER", "-in", str(der), "-out", str(pem)], check=True)
    return d["data"]["id"], key, pem


def app_store_profile(call, name, bundle, cert_pk):
    st, d = call("GET", "/profiles?filter[name]=%s&limit=5" % urllib.parse.quote(name))
    if st == 200:
        for item in d.get("data", []):
            if item["attributes"]["name"] == name:
                # Rebind the name to the current certificate.
                call("DELETE", "/profiles/" + item["id"])
                break
    st, d = call("POST", "/profiles", {"data": {"type": "profiles", "attributes": {
        "name": name, "profileType": "IOS_APP_STORE"},
        "relationships": {
            "bundleId": {"data": {"type": "bundleIds", "id": bundle}},
            "certificates": {"data": [{"type": "certificates", "id": cert_pk}]}}}})
    if st != 201:
        raise SystemExit("creating profile %s failed: %s %s" % (name, st, fail(d)))
    return d["data"]["id"]


def profile_bytes(call, profile_pk, path):
    st, d = call("GET", "/profiles/" + profile_pk)
    if st != 200:
        raise SystemExit("fetching profile failed: %s %s" % (st, fail(d)))
    path.write_bytes(base64.b64decode(d["data"]["attributes"]["profileContent"]))


def set_github(repo, values):
    from nacl import encoding, public
    tok = os.environ.get("GITHUB_TOKEN")
    if not tok:
        out = subprocess.run(["git", "credential", "fill"],
                             input="protocol=https\nhost=github.com\n\n", capture_output=True, text=True).stdout
        tok = next((l[9:] for l in out.splitlines() if l.startswith("password=")), "")
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
    for name, value in values.items():
        sealed = base64.b64encode(box.encrypt(value.encode())).decode()
        gh("PUT", "/actions/secrets/" + name, {"encrypted_value": sealed, "key_id": key["key_id"]})
        print("github secret set: " + name)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--key", required=True)
    ap.add_argument("--key-id", required=True)
    ap.add_argument("--issuer-id", required=True)
    ap.add_argument("--github-repo")
    ap.add_argument("--out", default="ios/build/signing")
    ap.add_argument("--rotate", action="store_true",
                    help="create a new distribution certificate even though one exists")
    ap.add_argument("--revoke", action="store_true",
                    help="revoke the team's existing distribution certificates first")
    ap.add_argument("--profiles-only", action="store_true",
                    help="reuse the installed certificate and only rebuild the App Store profiles "
                         "(e.g. after enabling the App Group in the portal)")
    a = ap.parse_args()
    out = pathlib.Path(a.out).resolve()
    out.mkdir(parents=True, exist_ok=True)
    work = pathlib.Path(tempfile.mkdtemp())
    call = client(a.key, a.key_id, a.issuer_id)

    if a.profiles_only:
        cert_pk = existing_distribution_cert(call)
        p12 = None
        password = None
    else:
        cert_pk, key, pem = distribution_cert(call, work, a.rotate, a.revoke)
        password = secrets.token_urlsafe(18)
        p12 = out / "distribution.p12"
        # macOS `security import` cannot read OpenSSL 3's default PKCS#12 encryption.
        subprocess.run(["openssl", "pkcs12", "-export", "-legacy", "-inkey", str(key), "-in", str(pem),
                        "-out", str(p12), "-password", "pass:" + password, "-name", "Bridge Distribution",
                        "-macalg", "sha1"],
                       check=True, capture_output=True)

    app_pk = bundle_pk(call, APP_ID)
    tunnel_pk = bundle_pk(call, TUNNEL_ID)
    app_profile = out / "app.mobileprovision"
    tunnel_profile = out / "tunnel.mobileprovision"
    profile_bytes(call, app_store_profile(call, "Bridge App Store", app_pk, cert_pk), app_profile)
    profile_bytes(call, app_store_profile(call, "Bridge Tunnel App Store", tunnel_pk, cert_pk), tunnel_profile)
    print("wrote %s" % out)

    if a.github_repo:
        values = {
            "MANUAL_PROFILE_APP_BASE64": base64.b64encode(app_profile.read_bytes()).decode(),
            "MANUAL_PROFILE_TUNNEL_BASE64": base64.b64encode(tunnel_profile.read_bytes()).decode(),
        }
        if p12 is not None:
            values["MANUAL_P12_BASE64"] = base64.b64encode(p12.read_bytes()).decode()
            values["MANUAL_P12_PASSWORD"] = password
        set_github(a.github_repo, values)


if __name__ == "__main__":
    main()
