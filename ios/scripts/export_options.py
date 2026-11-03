#!/usr/bin/env python3
"""Writes an Xcode ExportOptions.plist for TestFlight upload.

    python3 ios/scripts/export_options.py <out.plist>

Reads APPLE_TEAM_ID and STYLE ("manual" or "automatic") from the environment.
Provisioning profile names are only needed for manual signing; bundle ids contain dots,
which plutil's key-path syntax cannot express.
"""
import os
import plistlib
import sys

out = sys.argv[1]
style = os.environ.get("STYLE", "automatic")
options = {
    "method": "app-store-connect",
    "destination": "upload",
    "teamID": os.environ["APPLE_TEAM_ID"],
    "signingStyle": style,
    "manageAppVersionAndBuildNumber": False,
}
if style == "manual":
    options["provisioningProfiles"] = {
        "org.forfoxsakesantuary.foxbridgeinternal": "Bridge App Store",
        "org.forfoxsakesantuary.foxbridgeinternal.tunnel": "Bridge Tunnel App Store",
    }
with open(out, "wb") as handle:
    plistlib.dump(options, handle)
