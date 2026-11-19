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
PKG_VERSION:=$(if $(LEIKWAND_VERSION),$(LEIKWAND_VERSION),1.3.0)
PKG_RELEASE:=1

PKG_LICENSE:=Apache-2.0
PKG_MAINTAINER:=Leikwan <ops@leikwanhost.com>

include $(INCLUDE_DIR)/package.mk

define Package/leikwand
  SECTION:=net
  CATEGORY:=Network
  SUBMENU:=Web Servers/Proxies
  TITLE:=Leikwan convergence agent (panel-managed forwarding + native relay)
  URL:=https://v3.leikwanhost.com
  DEPENDS:=
endef

define Package/leikwand/description
 Router-side agent for the Leikwan convergence service.

 It keeps one supervised forwarding runtime per assigned line in sync with
 the central panel, delivers high-availability candidate lines over the
 runtime control socket, and exposes the panel's native outbound as a local
 SOCKS5 relay on 127.0.0.1:2080 when the plan grants it. Managed from LuCI
 (luci-app-leikwan); onboarding is the panel-issued client id + agent token.

 Opt-in gateway mode (mode=gateway) routes the LAN, or selected destination
 prefixes, over the native outbound through a sing-box TUN, with optional
 split carriers for listed prefixes. Gateway mode additionally needs sing-box
 and kmod-tun.
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
	# Destination lists for the gateway split carriers (SHIXP members).
	$(INSTALL_DIR) $(1)/usr/share/leikwan/split
	$(INSTALL_DATA) ./files/split/shixp-v4.txt $(1)/usr/share/leikwan/split/
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
