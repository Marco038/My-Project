"""Split js/_bundle_raw.js into css/html already done; this writes js modules."""
import pathlib

root = pathlib.Path(__file__).resolve().parents[1]
lines = (root / "js" / "_bundle_raw.js").read_text(encoding="utf-8").splitlines(keepends=True)


def L(a, b):  # 1-based inclusive line numbers
    return "".join(lines[a - 1 : b])


config = """/**
 * BUKID CONNECT — API path & global state (html/ → ../php/)
 */
const PHP = (() => {
  const m = document.querySelector('meta[name="apidir"]');
  const base = (m && m.content) ? m.content.trim() : '../php/';
  return base.endsWith('/') ? base : base + '/';
})();
let currentUser = null;
let currentChatWith = null;
let allCrops = [];
let currentOrderCrop = null;
let _ratingVal = 0;
"""

utils = (
    "/** Shared: DOM helpers & API (load before role modules) */\n"
    + L(487, 491)  # escapeHtml
    + L(879, 897)  # api
    + L(6, 13)  # refreshNotifBadge
    + L(899, 918)  # toast, pillClass, alertIcon, cropEmoji
    + L(872, 877)  # openModal, closeModal
    + "document.addEventListener('click', e => {\n"
    + "  if (e.target.classList.contains('modal-overlay')) e.target.classList.remove('open');\n"
    + "});\n"
)

navigation = "/** Sidebar navigation */\n" + L(17, 97)

farmer = "/** Farmer role */\n" + L(367, 387) + L(571, 582)

buyer = "/** Buyer role */\n" + L(321, 365) + L(584, 605) + L(858, 862)

# Skip lines 648–661 (loadAlerts — lives in shared app.js)
admin = "/** Admin role */\n" + L(614, 647) + L(663, 703) + L(846, 856)

app = (
    "/** Shared: auth, dashboard, orders, visits, chat, settings, checkout */\n"
    + L(100, 246)
    + L(248, 319)
    + L(389, 421)
    + L(423, 442)
    + L(444, 486)
    + L(493, 532)
    + L(537, 569)
    + L(607, 612)
    + L(648, 661)
    + L(704, 869)
)

(root / "js" / "shared").mkdir(parents=True, exist_ok=True)
(root / "js" / "farmer").mkdir(parents=True, exist_ok=True)
(root / "js" / "buyer").mkdir(parents=True, exist_ok=True)
(root / "js" / "admin").mkdir(parents=True, exist_ok=True)

(root / "js" / "config.js").write_text(config, encoding="utf-8")
(root / "js" / "shared" / "utils.js").write_text(utils, encoding="utf-8")
(root / "js" / "shared" / "navigation.js").write_text(navigation, encoding="utf-8")
(root / "js" / "farmer" / "farmer-pages.js").write_text(farmer, encoding="utf-8")
(root / "js" / "buyer" / "buyer-pages.js").write_text(buyer, encoding="utf-8")
(root / "js" / "admin" / "admin-pages.js").write_text(admin, encoding="utf-8")
(root / "js" / "shared" / "app.js").write_text(app, encoding="utf-8")

print("Wrote js modules OK")
