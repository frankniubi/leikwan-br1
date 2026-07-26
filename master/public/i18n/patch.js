(function () {
  const config = window.LEIKWAN_I18N || { defaultLocale: "en", supported: ["en"], basePath: "/i18n" };
  const storageKey = "leikwan_locale";
  const skipTags = new Set(["SCRIPT", "STYLE", "CODE", "PRE", "TEXTAREA"]);
  const sourceText = new WeakMap();
  const sourceAttributes = new WeakMap();
  let observer = null;

  function isSupportedLocale(locale) {
    return typeof locale === "string" && config.supported.includes(locale);
  }

  function getLocale() {
    const fromQuery = new URLSearchParams(window.location.search).get("lang");
    if (isSupportedLocale(fromQuery)) {
      localStorage.setItem(storageKey, fromQuery);
      return fromQuery;
    }

    const fromStorage = localStorage.getItem(storageKey);
    if (isSupportedLocale(fromStorage)) {
      return fromStorage;
    }

    return isSupportedLocale(config.defaultLocale) ? config.defaultLocale : "en";
  }

  function setLocale(locale) {
    if (!isSupportedLocale(locale)) return;
    localStorage.setItem(storageKey, locale);
    const url = new URL(window.location.href);
    url.searchParams.set("lang", locale);
    window.location.assign(url.toString());
  }

  function normalizeKey(value) {
    return String(value || "").replace(/\s+/g, " ").trim();
  }

  function sourceForTextNode(node) {
    if (!sourceText.has(node)) {
      sourceText.set(node, node.nodeValue || "");
    }
    return sourceText.get(node) || "";
  }

  function sourceForAttribute(element, attr) {
    let values = sourceAttributes.get(element);
    if (!values) {
      values = new Map();
      sourceAttributes.set(element, values);
    }
    if (!values.has(attr)) {
      values.set(attr, element.getAttribute(attr) || "");
    }
    return values.get(attr) || "";
  }

  function patternEntries(dictionary) {
    const patterns = dictionary.patterns || {};
    if (Array.isArray(patterns)) return patterns;
    return Object.entries(patterns).map(([source, replace]) => ({ source, replace }));
  }

  function applyGlossary(value, dictionary) {
    return Object.entries(dictionary.glossary || {}).reduce((result, [source, replacement]) => {
      return result.split(source).join(String(replacement));
    }, String(value));
  }

  function translateValue(value, dictionary) {
    const normalized = normalizeKey(value);
    const textMap = dictionary.text || {};
    if (Object.prototype.hasOwnProperty.call(textMap, normalized)) {
      return applyGlossary(textMap[normalized], dictionary);
    }

    for (const entry of patternEntries(dictionary)) {
      const source = typeof entry.source === "string" ? entry.source : "";
      if (!source.startsWith("^") || !source.endsWith("$") || typeof entry.replace !== "string") {
        continue;
      }
      try {
        const pattern = new RegExp(source);
        if (pattern.test(normalized)) {
          return applyGlossary(normalized.replace(pattern, entry.replace), dictionary);
        }
      } catch (_error) {
        // A malformed local dictionary entry must not break the whole page.
      }
    }

    return undefined;
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

    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((node) => {
      const original = sourceForTextNode(node);
      const translation = translateValue(original, dictionary);
      if (translation === undefined) return;
      const leading = original.match(/^\s*/)?.[0] || "";
      const trailing = original.match(/\s*$/)?.[0] || "";
      const nextValue = leading + translation + trailing;
      if (node.nodeValue !== nextValue) node.nodeValue = nextValue;
    });
  }

  function patchAttributes(root, dictionary) {
    const attrMap = dictionary.attributes || {};
    const elements = root.nodeType === Node.ELEMENT_NODE ? [root, ...root.querySelectorAll("*")] : [...root.querySelectorAll("*")];
    elements.forEach((element) => {
      ["placeholder", "title", "aria-label", "value"].forEach((attr) => {
        if (!element.hasAttribute(attr)) return;
        const isFormValue = attr === "value" && ["INPUT", "TEXTAREA", "SELECT"].includes(element.tagName);
        const preserveUserValue = isFormValue && element.getAttribute("data-i18n-static") !== "true";
        if (preserveUserValue) return;

        const original = sourceForAttribute(element, attr);
        const translation = Object.prototype.hasOwnProperty.call(attrMap, normalizeKey(original))
          ? applyGlossary(attrMap[normalizeKey(original)], dictionary)
          : undefined;
        if (translation !== undefined && element.getAttribute(attr) !== translation) {
          element.setAttribute(attr, translation);
        }
      });
    });
  }

  function patchRoot(root, dictionary) {
    if (!root) return;
    patchTextNodes(root, dictionary);
    patchAttributes(root, dictionary);
  }

  function patchSelectLabels() {
    const switcher = document.getElementById("locale-switcher");
    if (!switcher) return;
    switcher.value = getLocale();
    if (switcher.dataset.i18nBound === "true") return;
    switcher.dataset.i18nBound = "true";
    switcher.addEventListener("change", function () {
      setLocale(this.value);
    });
  }

  async function applyPatch() {
    const locale = getLocale();
    document.documentElement.lang = locale;
    patchSelectLabels();
    if (locale === "en") return;

    const url = `${config.basePath}/${encodeURIComponent(locale)}.json`;
    let response;
    try {
      response = await fetch(url, { credentials: "same-origin" });
    } catch (_error) {
      return;
    }
    if (!response.ok) return;

    let dictionary;
    try {
      dictionary = await response.json();
    } catch (_error) {
      return;
    }

    patchRoot(document.body, dictionary);
    if (observer) observer.disconnect();
    observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType === Node.ELEMENT_NODE) patchRoot(node, dictionary);
          if (node.nodeType === Node.TEXT_NODE) patchRoot(node.parentElement, dictionary);
        });
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", applyPatch, { once: true });
  } else {
    applyPatch();
  }
})();
