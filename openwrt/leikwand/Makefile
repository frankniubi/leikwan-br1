#
# leikwand — Leikwan 会聚 (convergence) agent for OpenWrt
#
# The binary is cross-compiled ahead of time with CGO_ENABLED=0, which yields a
# fully static executable that runs unchanged on musl. Building Go inside the
# SDK would require the golang host package from the packages feed; staging a
# static binary keeps the SDK dependency surface at zero.
#
include $(TOPDIR)/rules.mk

PKG_NAME:=leikwand
PKG_VERSION:=$(if $(LEIKWAND_VERSION),$(LEIKWAND_VERSION),1.1.0)
PKG_RELEASE:=1

PKG_LICENSE:=Apache-2.0
PKG_MAINTAINER:=Leikwan <ops@leikwanhost.com>

include $(INCLUDE_DIR)/package.mk

define Package/leikwand
  SECTION:=net
  CATEGORY:=Network
  SUBMENU:=Web Servers/Proxies
  TITLE:=Leikwan convergence agent (panel-managed, two-layer HA)
  URL:=https://v3.leikwanhost.com
  DEPENDS:=
endef

define Package/leikwand/description
 Router-side agent for the Leikwan convergence service.

 It keeps a supervised forwarding runtime in sync with the central panel,
 delivers high-availability candidate lines over the runtime control socket,
 fails over between multiple WAN uplinks, and can act as a transparent egress
 for the whole LAN through the Panel's native outbound (restricted or
 unrestricted route) with the desktop clients' anti-pollution DNS policy.
 Gateway mode additionally needs sing-box and kmod-tun. Managed from LuCI
 (luci-app-leikwan).
endef

define Package/leikwand/conffiles
/etc/config/leikwan
endef

define Build/Prepare
	mkdir -p $(PKG_BUILD_DIR)
	$(CP) ./bin/leikwand $(PKG_BUILD_DIR)/leikwand
endef

define Build/Compile
endef

define Package/leikwand/install
	$(INSTALL_DIR) $(1)/usr/bin
	$(INSTALL_BIN) $(PKG_BUILD_DIR)/leikwand $(1)/usr/bin/leikwand

	$(INSTALL_DIR) $(1)/etc/config
	$(INSTALL_CONF) ./files/leikwan.config $(1)/etc/config/leikwan

	$(INSTALL_DIR) $(1)/etc/init.d
	$(INSTALL_BIN) ./files/leikwand.init $(1)/etc/init.d/leikwand

	$(INSTALL_DIR) $(1)/etc/uci-defaults
	$(INSTALL_BIN) ./files/leikwand.uci-defaults $(1)/etc/uci-defaults/95-leikwand

	$(INSTALL_DIR) $(1)/etc/leikwan
	$(INSTALL_DIR) $(1)/var/run/leikwand
	$(INSTALL_DIR) $(1)/usr/libexec/leikwan

	# China rule-sets for gateway mode, so sing-box never waits on GitHub.
	$(INSTALL_DIR) $(1)/usr/share/leikwan/rule-set
	$(INSTALL_DATA) ./files/rule-set/geoip-cn.srs ./files/rule-set/geosite-cn.srs $(1)/usr/share/leikwan/rule-set/
	$(INSTALL_DATA) ./files/rule-set/NOTICE.txt ./files/rule-set/LICENSE.txt ./files/rule-set/COPYING.txt $(1)/usr/share/leikwan/rule-set/
endef

define Package/leikwand/postinst
#!/bin/sh
[ -n "$${IPKG_INSTROOT}" ] || {
	/etc/init.d/leikwand enable >/dev/null 2>&1
	/etc/init.d/leikwand restart >/dev/null 2>&1
}
exit 0
endef

define Package/leikwand/prerm
#!/bin/sh
[ -n "$${IPKG_INSTROOT}" ] || {
	/etc/init.d/leikwand stop >/dev/null 2>&1
	/etc/init.d/leikwand disable >/dev/null 2>&1
	# Remove the gateway firewall zone only on a real removal, not an upgrade.
	if [ "$${PKG_UPGRADE:-0}" != "1" ] && uci -q get firewall.leikwan >/dev/null 2>&1; then
		uci -q delete firewall.leikwan
		uci -q delete firewall.leikwan_lan
		uci -q commit firewall
		/etc/init.d/firewall reload >/dev/null 2>&1
	fi
}
exit 0
endef

$(eval $(call BuildPackage,leikwand))
