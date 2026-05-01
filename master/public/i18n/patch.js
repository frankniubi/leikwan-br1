(function () {
  const config = window.LEIKWAN_I18N || { defaultLocale: "en", supported: ["en"], basePath: "/i18n" };
  const storageKey = "leikwan_locale";
  const skipTags = new Set(["SCRIPT", "STYLE", "CODE", "PRE", "TEXTAREA"]);

  function getLocale() {
    const fromQuery = new URLSearchParams(window.location.search).get("lang");
    if (fromQuery && config.supported.includes(fromQuery)) {
      localStorage.setItem(storageKey, fromQuery);
      return fromQuery;
    }
    const fromStorage = localStorage.getItem(storageKey);
    if (fromStorage && config.supported.includes(fromStorage)) {
      return fromStorage;
    }
    return config.defaultLocale || "en";
  }

  function setLocale(locale) {
    localStorage.setItem(storageKey, locale);
    window.location.reload();
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
      ["placeholder", "title", "aria-label", "value"].forEach((attr) => {
        if (!element.hasAttribute(attr)) return;
        const value = element.getAttribute(attr) || "";
        const normalized = normalizeKey(value);
        if (attrMap[normalized]) {
          element.setAttribute(attr, attrMap[normalized]);
        }
      });
    });
  }

  function patchSelectLabels() {
    const switcher = document.getElementById("locale-switcher");
    if (!switcher) return;
    switcher.value = getLocale();
    switcher.addEventListener("change", function () {
      setLocale(this.value);
    });
  }

  async function applyPatch() {
    patchSelectLabels();
    const locale = getLocale();
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

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", applyPatch);
  } else {
    applyPatch();
  }
})();
