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
PKG_VERSION:=$(if $(LEIKWAND_VERSION),$(LEIKWAND_VERSION),1.2.0)
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
}
exit 0
endef

$(eval $(call BuildPackage,leikwand))
