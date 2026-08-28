(function () {
  const config = window.LEIKWAN_I18N || { defaultLocale: "en", supported: ["en"], basePath: "/i18n" };
  const storageKey = "leikwan_locale";
  const cookieName = config.cookieName || "leikwan_locale";
  const skipTags = new Set(["SCRIPT", "STYLE", "CODE", "PRE", "TEXTAREA"]);

  function writeLocaleCookie(locale) {
    document.cookie = `${cookieName}=${encodeURIComponent(locale)}; Path=/; Max-Age=31536000; SameSite=Lax`;
  }

  function readLocaleCookie() {
    for (const part of document.cookie.split(";")) {
      const separator = part.indexOf("=");
      const name = (separator === -1 ? part : part.slice(0, separator)).trim();
      if (name !== cookieName) continue;
      const value = separator === -1 ? "" : part.slice(separator + 1);
      try {
        return decodeURIComponent(value);
      } catch (error) {
        return null;
      }
    }
    return null;
  }

  function getLocale() {
    const params = new URLSearchParams(window.location.search);
    if (params.has("lang")) {
      const queryLocales = params.getAll("lang");
      const fromQuery = queryLocales[queryLocales.length - 1];
      const resolved = fromQuery && config.supported.includes(fromQuery)
        ? fromQuery
        : "en";
      localStorage.setItem(storageKey, resolved);
      writeLocaleCookie(resolved);
      return resolved;
    }

    const fromCookie = readLocaleCookie();
    if (fromCookie && config.supported.includes(fromCookie)) {
      localStorage.setItem(storageKey, fromCookie);
      return fromCookie;
    }

    const fromStorage = localStorage.getItem(storageKey);
    if (fromStorage && config.supported.includes(fromStorage)) {
      writeLocaleCookie(fromStorage);
      return fromStorage;
    }

    return "en";
  }

  function migrateLegacyKnowledgebaseLocale() {
    const currentUrl = new URL(window.location.href);
    const fromCookie = readLocaleCookie();
    if (currentUrl.searchParams.has("lang") || (fromCookie && config.supported.includes(fromCookie)) || currentUrl.pathname !== "/knowledgebase") {
      return false;
    }

    const fromStorage = localStorage.getItem(storageKey);
    if (!fromStorage || !config.supported.includes(fromStorage)) {
      return false;
    }

    writeLocaleCookie(fromStorage);
    currentUrl.searchParams.set("lang", fromStorage);
    window.location.replace(currentUrl.toString());
    return true;
  }

  function setLocale(locale) {
    localStorage.setItem(storageKey, locale);
    writeLocaleCookie(locale);
    const nextUrl = new URL(window.location.href);
    nextUrl.searchParams.set("lang", locale);
    window.location.assign(nextUrl.toString());
  }

  function normalizeKey(value) {
    return value.replace(/\s+/g, " ").trim();
  }

  function patchTextNodes(root, dictionary) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode(node) {
        const parent = node.parentElement;
        if (!parent || skipTags.has(parent.tagName)) {
          return NodeFilter.FILTER_REJECT;
        }
        if (!node.nodeValue || normalizeKey(node.nodeValue) === "") {
          return NodeFilter.FILTER_REJECT;
        }
        return NodeFilter.FILTER_ACCEPT;
      }
    });

    const textMap = dictionary.text || {};
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((node) => {
      const original = node.nodeValue || "";
      const normalized = normalizeKey(original);
      if (!textMap[normalized]) return;
      const leading = original.match(/^\s*/)?.[0] || "";
      const trailing = original.match(/\s*$/)?.[0] || "";
      node.nodeValue = leading + textMap[normalized] + trailing;
    });
  }

  function patchAttributes(root, dictionary) {
    const attrMap = dictionary.attributes || {};
    root.querySelectorAll("*").forEach((element) => {
      ["placeholder", "title", "aria-label", "value", "data-confirm"].forEach((attr) => {
        if (!element.hasAttribute(attr)) return;
        const value = element.getAttribute(attr) || "";
        const normalized = normalizeKey(value);
        if (attrMap[normalized]) {
          element.setAttribute(attr, attrMap[normalized]);
        }
      });
    });
  }

  function patchSelectLabels(locale) {
    const switcher = document.getElementById("locale-switcher");
    if (!switcher) return;
    switcher.value = locale;
    switcher.addEventListener("change", function () {
      setLocale(this.value);
    });
  }

  async function applyPatch() {
    const locale = getLocale();
    patchSelectLabels(locale);
    document.documentElement.lang = locale;
    if (locale === "en") return;

    const url = `${config.basePath}/${locale}.json`;
    const response = await fetch(url, { credentials: "same-origin" });
    if (!response.ok) return;
    const dictionary = await response.json();

    patchTextNodes(document.body, dictionary);
    patchAttributes(document.body, dictionary);

    const observer = new MutationObserver(() => {
      patchTextNodes(document.body, dictionary);
      patchAttributes(document.body, dictionary);
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  function bootstrap() {
    if (!migrateLegacyKnowledgebaseLocale()) {
      applyPatch();
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bootstrap);
  } else {
    bootstrap();
  }
})();
