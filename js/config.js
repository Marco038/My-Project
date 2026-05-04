/**
 * BUKID CONNECT — API base URL (works from html/en/, html/tl/, etc.)
 * Resolves meta[name="apidir"] relative to the current page URL so fetches always hit /…/php/.
 */
const PHP = (() => {
  const m = document.querySelector('meta[name="apidir"]');
  const rel = (m && m.content) ? m.content.trim() : '../../php/';
  try {
    const abs = new URL(rel, window.location.href);
    let href = abs.href;
    if (!href.endsWith('/')) href += '/';
    return href;
  } catch (e) {
    const b = rel.endsWith('/') ? rel : rel + '/';
    return b;
  }
})();
let currentUser = null;
let currentChatWith = null;
let allCrops = [];
let currentOrderCrop = null;
let _ratingVal = 0;
