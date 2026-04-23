/**
 * ClearA11y Evidence Extractor
 *
 * Extracts comprehensive evidence from axe-core results for each violation.
 * This data helps with identifying issues across scans and building ignore features.
 *
 * Usage:
 *   const results = await axe.run();
 *   const evidence = await extractEvidenceFromAxeResults(results);
 *
 * @package ClearA11y
 */

/**
 * Extract evidence from axe-core results.
 *
 * @param {Object} results    Axe-core scan results.
 * @param {Object} options    Configuration options.
 * @return {Array} Array of violation records with evidence.
 */
async function extractEvidenceFromAxeResults(results, options = {}) {
  const {
    maxSnippetLen = 4000,
    maxTextLen = 400,
    ancestorDepth = 6,
    allowDataAttrs = true,
    dataAttrWhitelist = ["data-testid", "data-qa", "data-cy"],
  } = options;

  const out = [];

  for (const v of results.violations || []) {
    for (const node of v.nodes || []) {
      const selector = node?.target?.[0] ?? null;

      const record = {
        rule_id: v.id,
        impact: v.impact || null,
        message: v.description || v.help || null,
        help_url: v.helpUrl || null,
        failure_summary: node.failureSummary || null,

        selector: selector,
        selector_match_count: null,
        selector_score: null,
        node_evidence: null,

        axe_node_raw: {
          html: node.html || null,
          target: node.target || null,
          any: node.any || null,
          all: node.all || null,
          none: node.none || null,
        },
      };

      if (!selector) {
        out.push(record);
        continue;
      }

      // Resolve element and match count
      const { matchCount, element } = resolveSelector(selector, document);
      record.selector_match_count = matchCount;

      // Score selector even if element not found
      record.selector_score = scoreCssSelector(selector, matchCount);

      if (!element) {
        out.push(record);
        continue;
      }

      // Extract evidence from element
      record.node_evidence = await buildNodeEvidence(element, {
        maxSnippetLen,
        maxTextLen,
        ancestorDepth,
        allowDataAttrs,
        dataAttrWhitelist,
      });

      out.push(record);
    }
  }

  return out;
}

/**
 * Resolve a CSS selector to an element.
 *
 * @param {string} selector  CSS selector.
 * @param {Document} rootDoc Root document.
 * @return {Object} Object with matchCount and element.
 */
function resolveSelector(selector, rootDoc) {
  let matchCount = 0;
  let element = null;

  try {
    const matches = rootDoc.querySelectorAll(selector);
    matchCount = matches.length;
    element = matches[0] || null;
  } catch (e) {
    matchCount = 0;
    element = null;
  }

  return { matchCount, element };
}

/**
 * Score a CSS selector for stability and uniqueness.
 *
 * @param {string} selector   CSS selector.
 * @param {number} matchCount Number of matching elements.
 * @return {Object} Score object with score, tier, warnings, and features.
 */
function scoreCssSelector(selector, matchCount) {
  const features = {
    hasId: /#[A-Za-z_][\w-]*/.test(selector),
    hasDataAttr: /\[data-[^\]=]+(?:=[^\]]+)?\]/.test(selector),
    hasRole: /\[role\s*=\s*["']?[^"'\]]+["']?\]/.test(selector),
    hasAria: /\[aria-[^\]=]+(?:=[^\]]+)?\]/.test(selector),
    hasHref: /\[href(?:=[^\]]+)?\]/.test(selector),
    hasName: /\[name(?:=[^\]]+)?\]/.test(selector),
    hasType: /\[type(?:=[^\]]+)?\]/.test(selector),
    hasFor: /\[for(?:=[^\]]+)?\]/.test(selector),
    usesNth: /:nth-(child|of-type)\(/.test(selector),
    usesSiblingCombinator: /(\s[+~]\s)/.test(selector),
    depth: estimateSelectorDepth(selector),
    classCount: (selector.match(/\.[A-Za-z_][\w-]*/g) || []).length,
    generatedTokenSuspected: selectorHasGeneratedTokens(selector),
  };

  const tier =
    features.hasId && !features.generatedTokenSuspected ? "id_based"
    : features.hasDataAttr ? "data_attr_based"
    : (features.hasRole || features.hasAria) ? "aria_role_based"
    : (features.hasHref || features.hasName || features.hasType || features.hasFor) ? "attribute_based"
    : features.classCount > 0 ? "class_based"
    : "structural";

  let score = 50;
  const warnings = [];

  // Bonuses
  if (features.hasId && !features.generatedTokenSuspected) score += 35;
  if (features.hasDataAttr) score += 25;
  if (features.hasRole || features.hasAria) score += 20;
  if (features.hasHref) score += 15;
  if (features.hasName || features.hasType) score += 10;
  if (features.hasFor) score += 10;

  // Penalties
  if (features.usesNth) {
    score -= 30;
    warnings.push("uses_nth_child");
  }
  if (features.depth >= 6 && features.depth <= 8) {
    score -= 10;
    warnings.push("deep_selector");
  } else if (features.depth > 8) {
    score -= 20;
    warnings.push("very_deep_selector");
  }
  if (features.classCount > 2) {
    score -= 15;
    warnings.push("many_classes");
  }
  if (features.usesSiblingCombinator) {
    score -= 10;
    warnings.push("uses_sibling_combinator");
  }
  if (features.generatedTokenSuspected) {
    score -= 25;
    warnings.push("generated_token_suspected");
  }

  // Uniqueness adjustment
  if (matchCount === 1) score += 15;
  else if (matchCount >= 2 && matchCount <= 5) {
    score -= 10;
    warnings.push("selector_not_unique");
  } else if (matchCount >= 6) {
    score -= 25;
    warnings.push("selector_too_broad");
  } else if (matchCount === 0) {
    score = 0;
    warnings.push("selector_matches_nothing");
  }

  score = clamp(score, 0, 100);

  return { score, tier, warnings, features };
}

/**
 * Estimate selector depth by counting combinators.
 *
 * @param {string} selector CSS selector.
 * @return {number} Estimated depth.
 */
function estimateSelectorDepth(selector) {
  const gt = (selector.match(/>/g) || []).length;
  const stripped = selector.replace(/\[[^\]]*\]/g, "[]");
  const spaces = (stripped.match(/\s+/g) || []).length;
  return gt + spaces + 1;
}

/**
 * Check if selector contains generated tokens.
 *
 * @param {string} selector CSS selector.
 * @return {boolean} True if generated tokens suspected.
 */
function selectorHasGeneratedTokens(selector) {
  const tokens = selector.split(/[^A-Za-z0-9_-]+/g).filter(Boolean);

  const uuidRe = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
  const longHexRe = /^[0-9a-f]{10,}$/i;
  const cssModuleRe = /__[\w-]{6,}$/;
  const jssRe = /^jss\d+$/i;
  const scRe = /^sc-[A-Za-z0-9]{6,}$/;
  const cssDashRe = /^css-[A-Za-z0-9]{6,}$/;

  for (const t of tokens) {
    if (uuidRe.test(t)) return true;
    if (longHexRe.test(t)) return true;
    if (cssModuleRe.test(t)) return true;
    if (jssRe.test(t)) return true;
    if (scRe.test(t)) return true;
    if (cssDashRe.test(t)) return true;
    if (t.length >= 18 && /[A-Za-z]/.test(t) && /\d/.test(t)) return true;
  }

  return false;
}

/**
 * Build comprehensive evidence object for a DOM element.
 *
 * @param {Element} el      DOM element.
 * @param {Object}   options Configuration options.
 * @return {Object} Evidence object.
 */
async function buildNodeEvidence(el, options) {
  const {
    maxSnippetLen,
    maxTextLen,
    ancestorDepth,
    allowDataAttrs,
    dataAttrWhitelist,
  } = options;

  const tagName = el.tagName.toLowerCase();
  const attrs = extractAttributes(el, { allowDataAttrs, dataAttrWhitelist });

  const outerHtmlSnippet = truncateString(el.outerHTML || "", maxSnippetLen);
  const innerTextSnippet = truncateString(normalizeWhitespace(el.innerText || el.textContent || ""), maxTextLen);

  const xpath = buildXPath(el);
  const domPath = buildDomPath(el);
  const ancestorChain = buildAncestorChain(el, ancestorDepth);

  const rect = el.getBoundingClientRect();
  const boundingBox = {
    x: round2(rect.x),
    y: round2(rect.y),
    w: round2(rect.width),
    h: round2(rect.height),
  };

  const cs = window.getComputedStyle(el);
  const styleEvidence = {
    color: cs.color || null,
    backgroundColor: cs.backgroundColor || null,
    fontSize: cs.fontSize || null,
    fontWeight: cs.fontWeight || null,
    opacity: cs.opacity || null,
  };

  const accessibleName = deriveAccessibleName(el);

  const strictSource = JSON.stringify({
    tagName,
    attrs: pickStableAttrsForFingerprint(attrs),
    ancestorChain,
    accessibleName,
  });

  const looseSource = JSON.stringify({
    tagName,
    attrs: pickLooserAttrsForFingerprint(attrs),
    ancestorChain: ancestorChain.map(a => ({
      tag: a.tag,
      role: a.role || null,
      ariaLabel: a.ariaLabel || null,
      id: a.id || null,
    })),
    accessibleName: accessibleName || null,
  });

  const fingerprintStrict = await sha256Base64url(strictSource);
  const fingerprintLoose = await sha256Base64url(looseSource);

  return {
    tag_name: tagName,
    attributes: attrs,
    accessible_name: accessibleName,

    css_selector_hint: buildBestEffortSelector(el),
    xpath,
    dom_path: domPath,
    ancestor_chain: ancestorChain,

    outer_html_snippet: outerHtmlSnippet,
    inner_text_snippet: innerTextSnippet,

    bounding_box: boundingBox,
    computed_style: styleEvidence,

    fingerprint_strict: fingerprintStrict,
    fingerprint_loose: fingerprintLoose,
    signature_version: 1,
  };
}

/**
 * Extract relevant attributes from an element.
 *
 * @param {Element} el      DOM element.
 * @param {Object}   options Configuration options.
 * @return {Object} Attributes object.
 */
function extractAttributes(el, { allowDataAttrs, dataAttrWhitelist }) {
  const out = {};

  const allow = new Set([
    "id", "class", "name", "type", "role",
    "href", "src", "alt", "title",
    "for", "value",
    "aria-label", "aria-labelledby", "aria-describedby",
    "aria-hidden", "tabindex",
  ]);

  for (const attr of el.attributes) {
    const n = attr.name;
    const v = attr.value;

    if (allow.has(n)) out[n] = v;

    if (allowDataAttrs && n.startsWith("data-")) {
      if (dataAttrWhitelist.length === 0 || dataAttrWhitelist.includes(n)) {
        out[n] = v;
      }
    }
  }

  if (out.class) {
    out.class_list = out.class.split(/\s+/).filter(Boolean);
  }

  return out;
}

/**
 * Derive accessible name for an element.
 *
 * @param {Element} el DOM element.
 * @return {string|null} Accessible name.
 */
function deriveAccessibleName(el) {
  const ariaLabel = el.getAttribute("aria-label");
  if (ariaLabel) return normalizeWhitespace(ariaLabel);

  const labelledBy = el.getAttribute("aria-labelledby");
  if (labelledBy) {
    const ids = labelledBy.split(/\s+/).filter(Boolean);
    const parts = ids
      .map(id => document.getElementById(id))
      .filter(Boolean)
      .map(n => normalizeWhitespace(n.innerText || n.textContent || ""))
      .filter(Boolean);
    if (parts.length) return parts.join(" ");
  }

  const alt = el.getAttribute("alt");
  if (alt) return normalizeWhitespace(alt);

  const title = el.getAttribute("title");
  if (title) return normalizeWhitespace(title);

  const text = el.innerText || el.textContent;
  if (text) return normalizeWhitespace(text).slice(0, 120);

  return null;
}

/**
 * Build XPath for an element.
 *
 * @param {Element} el DOM element.
 * @return {string|null} XPath string.
 */
function buildXPath(el) {
  const segments = [];
  let node = el;

  while (node && node.nodeType === Node.ELEMENT_NODE) {
    const tag = node.tagName.toLowerCase();

    const id = node.getAttribute("id");
    if (id && !selectorHasGeneratedTokens(`#${id}`)) {
      segments.unshift(`//*[@id="${escapeXPathString(id)}"]`);
      break;
    }

    let index = 1;
    let sib = node.previousElementSibling;
    while (sib) {
      if (sib.tagName.toLowerCase() === tag) index++;
      sib = sib.previousElementSibling;
    }
    segments.unshift(`/${tag}[${index}]`);
    node = node.parentElement;
  }

  if (segments.length && segments[0].startsWith("//*")) return segments.join("");
  return segments.length ? segments.join("") : null;
}

/**
 * Escape string for XPath.
 *
 * @param {string} str String to escape.
 * @return {string} Escaped string.
 */
function escapeXPathString(str) {
  return String(str).replace(/"/g, '\\"');
}

/**
 * Build structured DOM path.
 *
 * @param {Element} el DOM element.
 * @return {Array} Array of path segments.
 */
function buildDomPath(el) {
  const path = [];
  let node = el;

  while (node && node.nodeType === Node.ELEMENT_NODE) {
    const tag = node.tagName.toLowerCase();
    const id = node.getAttribute("id") || null;

    let indexOfType = 1;
    let sib = node.previousElementSibling;
    while (sib) {
      if (sib.tagName.toLowerCase() === tag) indexOfType++;
      sib = sib.previousElementSibling;
    }

    path.unshift({
      tag,
      id,
      indexOfType,
    });

    if (id && !selectorHasGeneratedTokens(`#${id}`)) break;

    node = node.parentElement;
  }

  return path;
}

/**
 * Build ancestor chain.
 *
 * @param {Element} el       DOM element.
 * @param {number}   maxDepth Maximum depth.
 * @return {Array} Array of ancestor info.
 */
function buildAncestorChain(el, maxDepth) {
  const chain = [];
  let node = el;
  let depth = 0;

  while (node && node.nodeType === Node.ELEMENT_NODE && depth < maxDepth) {
    chain.push({
      tag: node.tagName.toLowerCase(),
      id: node.getAttribute("id") || null,
      class: node.getAttribute("class") || null,
      role: node.getAttribute("role") || null,
      ariaLabel: node.getAttribute("aria-label") || null,
    });
    node = node.parentElement;
    depth++;
  }

  return chain;
}

/**
 * Build best-effort stable selector.
 *
 * @param {Element} el DOM element.
 * @return {string} CSS selector.
 */
function buildBestEffortSelector(el) {
  const id = el.getAttribute("id");
  if (id && !selectorHasGeneratedTokens(`#${id}`)) return `#${cssEscape(id)}`;

  const dt = el.getAttribute("data-testid");
  if (dt) return `${el.tagName.toLowerCase()}[data-testid="${cssEscapeAttr(dt)}"]`;

  const role = el.getAttribute("role");
  const ariaLabel = el.getAttribute("aria-label");
  if (role && ariaLabel) {
    return `[role="${cssEscapeAttr(role)}"][aria-label="${cssEscapeAttr(ariaLabel)}"]`;
  }

  const href = el.getAttribute("href");
  if (href && href.startsWith("/")) {
    return `${el.tagName.toLowerCase()}[href="${cssEscapeAttr(href)}"]`;
  }

  const name = el.getAttribute("name");
  if (name) return `${el.tagName.toLowerCase()}[name="${cssEscapeAttr(name)}"]`;

  const classList = (el.getAttribute("class") || "")
    .split(/\s+/)
    .filter(Boolean)
    .filter(c => !selectorHasGeneratedTokens(`.${c}`));

  if (classList.length) {
    return `${el.tagName.toLowerCase()}.${classList.slice(0, 2).map(cssEscape).join(".")}`;
  }

  return el.tagName.toLowerCase();
}

/**
 * Pick stable attributes for strict fingerprint.
 *
 * @param {Object} attrs Attributes object.
 * @return {Object} Stable attributes.
 */
function pickStableAttrsForFingerprint(attrs) {
  const out = {};
  for (const k of ["id", "role", "aria-label", "aria-labelledby", "name", "type", "href", "for"]) {
    if (attrs[k]) out[k] = attrs[k];
  }
  if (Array.isArray(attrs.class_list)) {
    out.class_list = attrs.class_list.filter(c => !selectorHasGeneratedTokens(`.${c}`)).slice(0, 3);
  }
  return out;
}

/**
 * Pick looser attributes for loose fingerprint.
 *
 * @param {Object} attrs Attributes object.
 * @return {Object} Loose attributes.
 */
function pickLooserAttrsForFingerprint(attrs) {
  const out = {};
  for (const k of ["role", "name", "type", "href"]) {
    if (attrs[k]) out[k] = attrs[k];
  }
  return out;
}

/**
 * Generate SHA-256 hash as base64url.
 *
 * @param {string} input Input string.
 * @return {Promise<string>} Hash.
 */
async function sha256Base64url(input) {
  // Check if crypto.subtle is available (requires secure context)
  if (typeof crypto !== 'undefined' && crypto.subtle) {
    try {
      const enc = new TextEncoder();
      const data = enc.encode(input);
      const hashBuf = await crypto.subtle.digest("SHA-256", data);
      const bytes = new Uint8Array(hashBuf);
      let bin = "";
      for (const b of bytes) bin += String.fromCharCode(b);
      const b64 = btoa(bin).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
      return b64;
    } catch (e) {
      console.warn('[ClearA11y Evidence Extractor] crypto.subtle not available, using fallback hash');
    }
  }

  // Fallback: simple DJB2 hash if crypto.subtle is not available
  let hash = 5381;
  for (let i = 0; i < input.length; i++) {
    hash = ((hash << 5) + hash) + input.charCodeAt(i); /* hash * 33 + c */
  }
  // Convert to base64-like string
  const hashStr = (hash >>> 0).toString(36);
  return btoa(hashStr).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
}

/**
 * Escape CSS identifier.
 *
 * @param {string} str String to escape.
 * @return {string} Escaped string.
 */
function cssEscape(str) {
  if (window.CSS && typeof window.CSS.escape === "function") return window.CSS.escape(str);
  return String(str).replace(/[^a-zA-Z0-9_-]/g, s => `\\${s}`);
}

/**
 * Escape string for CSS attribute selector.
 *
 * @param {string} str String to escape.
 * @return {string} Escaped string.
 */
function cssEscapeAttr(str) {
  return String(str).replace(/"/g, '\\"');
}

/**
 * Normalize whitespace.
 *
 * @param {string} s Input string.
 * @return {string} Normalized string.
 */
function normalizeWhitespace(s) {
  return String(s).replace(/\s+/g, " ").trim();
}

/**
 * Truncate string to max length.
 *
 * @param {string} s       Input string.
 * @param {number} maxLen  Maximum length.
 * @return {string} Truncated string.
 */
function truncateString(s, maxLen) {
  const str = String(s);
  if (str.length <= maxLen) return str;
  return str.slice(0, maxLen);
}

/**
 * Clamp number between min and max.
 *
 * @param {number} n   Value.
 * @param {number} min Minimum.
 * @param {number} max Maximum.
 * @return {number} Clamped value.
 */
function clamp(n, min, max) {
  return Math.max(min, Math.min(max, n));
}

/**
 * Round to 2 decimal places.
 *
 * @param {number} n Value.
 * @return {number} Rounded value.
 */
function round2(n) {
  return Math.round(n * 100) / 100;
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { extractEvidenceFromAxeResults };
}

// Explicitly export to window for browser environments
if (typeof window !== 'undefined') {
  window.extractEvidenceFromAxeResults = extractEvidenceFromAxeResults;
  console.log('[ClearA11y Evidence Extractor] Function exported to window.extractEvidenceFromAxeResults');
}
