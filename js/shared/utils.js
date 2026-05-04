/** Shared: DOM helpers & API (load before role modules) */
function escapeHtml(s) {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}
function togglePasswordVisibility(id) {
  const el = document.getElementById(id);
  if (el) el.type = el.type === 'password' ? 'text' : 'password';
}

async function fetchCsrf() {
  try {
    const r = await fetch(new URL('auth.php?' + new URLSearchParams({ action: 'csrf' }), PHP), { credentials: 'include' });
    const j = await r.json();
    if (j.success && j.csrf) window.__CSRF_TOKEN__ = j.csrf;
  } catch (e) {
    console.error('CSRF Fetch Error:', e);
  }
}

async function api(endpoint, body={}, method='POST', params={}) {
  try {
    // For sensitive operations, always ensure we have a fresh token first
    const sensitiveActions = ['login', 'register', 'forgot_password_reset', 'change_password'];
    const currentAction = body.action || params.action;
    
    if (method === 'POST' && (!window.__CSRF_TOKEN__ || sensitiveActions.includes(currentAction))) {
      await fetchCsrf();
    }

    let url = new URL(endpoint, PHP).href;
    if (method === 'GET' && Object.keys(params).length) {
      const u = new URL(url);
      Object.entries(params).forEach(([k, v]) => u.searchParams.set(k, v));
      url = u.href;
    }
    const opts = { method, credentials:'include' };
    if (method === 'POST') {
      const fd = new FormData();
      if (typeof window !== 'undefined' && window.__CSRF_TOKEN__) {
        fd.append('csrf_token', window.__CSRF_TOKEN__);
      }
      Object.entries(body).forEach(([k,v]) => fd.append(k,v));
      opts.body = fd;
    }
    const res = await fetch(url, opts);
    const j = await res.json();

    // If the server says the token is invalid, try to refresh it once and retry the request
    if (method === 'POST' && j.message && j.message.includes('security token')) {
      console.warn('CSRF token invalid, retrying...');
      await fetchCsrf();
      // Retry the same call once
      return await api(endpoint, body, method, params);
    }

    return j;
  } catch(e) {
    console.error('API Error:', e);
    return {success:false, message:'Network error.'};
  }
}
async function refreshNotifBadge() {
  if (!currentUser) return;
  const r = await api('api.php', {}, 'GET', {action:'get_notifications'});
  const el = document.getElementById('notif-dot');
  if (!el) return;
  if (r.success && r.unread_count > 0) el.style.display = '';
  else el.style.display = 'none';
}
function toast(msg, type='success') {
  const el = document.createElement('div');
  el.className = `toast${type==='error'?' error':type==='info'?' info':''}`;
  el.innerHTML = `<span>${type==='error'?'⚠️':'✅'}</span> ${msg}`;
  document.getElementById('toast-container').appendChild(el);
  setTimeout(() => el.remove(), 4000);
}

function pillClass(status) {
  const map = {available:'pill-green', confirmed:'pill-blue', packed:'pill-purple', in_transit:'pill-gold', delivered:'pill-green', cancelled:'pill-red', pending:'pill-gold', approved:'pill-green', declined:'pill-red', completed:'pill-green', 'pre-order':'pill-blue', sold_out:'pill-gray', inactive:'pill-gray'};
  return map[status] || 'pill-gray';
}

function alertIcon(type) {
  return {weather:'🌧', pest:'🐛', market:'📈', general:'📢'}[type] || '📢';
}

function cropEmoji(cat) {
  return {Vegetables:'🥬', Fruits:'🍎', Grains:'🌾', Livestock:'🐄', Herbs:'🌿', 'Root Crops':'🥔'}[cat] || '🌱';
}

/** Human labels for unit codes from `list_units` API */
function formatUnitLabel(unit) {
  const map = {
    kg: 'Kilogram (kg)', piece: 'Piece', bundle: 'Bundle', sack: 'Sack', tray: 'Tray',
    liter: 'Liter (L)', dozen: 'Dozen', crate: 'Crate', bunch: 'Bunch', box: 'Box'
  };
  return map[unit] || unit;
}

/** Fill marketplace / farmer modals from DB (`list_categories`, `list_units`). */
async function populateDynamicSelects() {
  try {
    const [catRes, unitRes] = await Promise.all([
      api('crops.php', {}, 'GET', { action: 'list_categories' }),
      api('crops.php', {}, 'GET', { action: 'list_units' }),
    ]);
    const cropCat = document.getElementById('crop-cat');
    if (cropCat && catRes.success && Array.isArray(catRes.categories)) {
      const prev = cropCat.value;
      cropCat.innerHTML = '<option value="">All categories</option>' +
        catRes.categories.map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
      if ([...cropCat.options].some(o => o.value === prev)) cropCat.value = prev;
    }
    const cCat = document.getElementById('c-cat');
    if (cCat && catRes.success && Array.isArray(catRes.categories)) {
      const prev = cCat.value;
      cCat.innerHTML = '<option value="">Select category…</option>' +
        catRes.categories.map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
      if ([...cCat.options].some(o => o.value === prev)) cCat.value = prev;
    }
    const cUnit = document.getElementById('c-unit');
    if (cUnit && unitRes.success && Array.isArray(unitRes.units) && unitRes.units.length) {
      const prev = cUnit.value;
      cUnit.innerHTML = unitRes.units.map(u =>
        `<option value="${escapeHtml(u)}">${escapeHtml(formatUnitLabel(u))}</option>`).join('');
      if (prev && [...cUnit.options].some(o => o.value === prev)) cUnit.value = prev;
      else cUnit.value = unitRes.units.includes('kg') ? 'kg' : unitRes.units[0];
    }
  } catch (e) {
    console.error(e);
  }
}

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) e.target.classList.remove('open');
});
