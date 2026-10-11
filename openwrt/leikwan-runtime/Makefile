#
# leikwan-runtime — the forwarding runtime supervised by leikwand
#
# This is tools/ultra-client, cross-compiled CGO_ENABLED=0 for the target
# architecture. It speaks the Ultra relay protocol and owns candidate probing,
# ranking, isolation and fast failover.
#
# On OpenWrt the runtime is updated through opkg (this package), not through a
# private self-updater: one update authority per box is the whole point of
# shipping it as a package.
#
include $(TOPDIR)/rules.mk

PKG_NAME:=leikwan-runtime
PKG_VERSION:=$(if $(LEIKWAN_RUNTIME_VERSION),$(LEIKWAN_RUNTIME_VERSION),1.0.1)
PKG_RELEASE:=1

PKG_LICENSE:=Apache-2.0
PKG_MAINTAINER:=Leikwan <ops@leikwanhost.com>

include $(INCLUDE_DIR)/package.mk

define Package/leikwan-runtime
  SECTION:=net
  CATEGORY:=Network
  SUBMENU:=Web Servers/Proxies
  TITLE:=Leikwan Ultra forwarding runtime
  URL:=https://v3.leikwanhost.com
  DEPENDS:=
endef

define Package/leikwan-runtime/description
 Ultra forwarding runtime for OpenWrt.

 Terminates the Ultra relay protocol, exposes a local listener per forward,
 holds the panel-issued candidate set, probes candidates, isolates failing
 ones and switches to a healthy candidate without dropping the listener.
endef

define Build/Prepare
	mkdir -p $(PKG_BUILD_DIR)
	$(CP) ./bin/ultra-client $(PKG_BUILD_DIR)/ultra-client
endef

define Build/Compile
endef

define Package/leikwan-runtime/install
	$(INSTALL_DIR) $(1)/usr/libexec/leikwan
	$(INSTALL_BIN) $(PKG_BUILD_DIR)/ultra-client $(1)/usr/libexec/leikwan/ultra-client
endef

$(eval $(call BuildPackage,leikwan-runtime))
