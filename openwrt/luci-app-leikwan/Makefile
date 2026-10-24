# SPDX-License-Identifier: Apache-2.0
#
# LuCI application for the Leikwan convergence agent (leikwand).
#
# This is deliberately a plain OpenWrt package rather than a luci.mk one:
#   - the app is script only (ucode rpcd backend, JSON menu/ACL, JS views);
#   - it ships no translatable .po files (labels are bilingual inline), so
#     luci.mk's i18n machinery buys nothing;
#   - depending on luci.mk would force every build to clone the whole LuCI
#     feed, which makes the build slower and less reproducible for no gain.
# The only real requirement is that luci-base is present on the target.

include $(TOPDIR)/rules.mk

PKG_NAME:=luci-app-leikwan
PKG_VERSION:=1.1.0
PKG_RELEASE:=1

PKG_LICENSE:=Apache-2.0
PKG_MAINTAINER:=Leikwan <ops@leikwanhost.com>

include $(INCLUDE_DIR)/package.mk

define Package/luci-app-leikwan
  SECTION:=luci
  CATEGORY:=LuCI
  SUBMENU:=3. Applications
  TITLE:=LuCI app for the Leikwan convergence agent
  URL:=https://v3.leikwanhost.com
  DEPENDS:=+luci-base +leikwand +PACKAGE_sing-box:sing-box
  PKGARCH:=all
endef

define Package/luci-app-leikwan/description
 Web UI for the Leikwan convergence agent: onboarding, forward lines with
 live latency and candidate counts, transparent gateway routing, multi-WAN
 uplink high availability, logs and diagnostics.
endef

define Build/Compile
endef

define Package/luci-app-leikwan/install
	$(INSTALL_DIR) $(1)/usr/share/luci/menu.d
	$(INSTALL_DATA) ./root/usr/share/luci/menu.d/luci-app-leikwan.json \
		$(1)/usr/share/luci/menu.d/luci-app-leikwan.json

	$(INSTALL_DIR) $(1)/usr/share/rpcd/acl.d
	$(INSTALL_DATA) ./root/usr/share/rpcd/acl.d/luci-app-leikwan.json \
		$(1)/usr/share/rpcd/acl.d/luci-app-leikwan.json

	$(INSTALL_DIR) $(1)/usr/libexec/rpcd
	$(INSTALL_BIN) ./root/usr/libexec/rpcd/luci.leikwand $(1)/usr/libexec/rpcd/luci.leikwand

	$(INSTALL_DIR) $(1)/etc/uci-defaults
	$(INSTALL_BIN) ./root/etc/uci-defaults/90-luci-leikwan $(1)/etc/uci-defaults/90-luci-leikwan

	$(INSTALL_DIR) $(1)/www/luci-static/resources/view/leikwan
	$(INSTALL_DATA) ./htdocs/luci-static/resources/view/leikwan/*.js \
		$(1)/www/luci-static/resources/view/leikwan/
endef

define Package/luci-app-leikwan/postinst
#!/bin/sh
[ -n "$${IPKG_INSTROOT}" ] || {
	rm -f /tmp/luci-indexcache 2>/dev/null
	rm -rf /tmp/luci-modulecache 2>/dev/null
	/etc/init.d/rpcd reload >/dev/null 2>&1
}
exit 0
endef

$(eval $(call BuildPackage,luci-app-leikwan))
