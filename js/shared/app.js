/** Shared: auth, dashboard, orders, visits, chat, settings, checkout */

const LIVE_POLL_MS = 42000;
let livePollTimer = null;

function stopLivePoll() {
  if (livePollTimer) {
    clearInterval(livePollTimer);
    livePollTimer = null;
  }
}

function startLivePoll() {
  stopLivePoll();
  livePollTimer = setInterval(runLivePollTick, LIVE_POLL_MS);
}

async function runLivePollTick() {
  if (document.hidden || !currentUser) return;
  await refreshNotifBadge();
  const active = document.querySelector('.page.active');
  const pid = active ? active.id : '';
  if (pid === 'page-dashboard') await loadDashboard();
  else if (pid === 'page-marketplace') await loadMarketplace();
  else if (pid === 'page-orders') await loadOrders();
  else if (pid === 'page-notifications') await loadNotificationsPage();
}
function showLanding() {
  document.getElementById('landing').style.display = '';
  document.getElementById('auth-page').style.display = 'none';
  document.getElementById('app').style.display = 'none';
  if (!currentUser) void loadPublicStats();
}
function showAuth(tab) {
  document.getElementById('landing').style.display = 'none';
  document.getElementById('auth-page').style.display = 'flex';
  document.getElementById('app').style.display = 'none';
  const ff = document.getElementById('forgot-password-form');
  if (ff) ff.style.display = 'none';
  showAuthTab(tab);
}
function showAuthTab(tab) {
  document.getElementById('login-form').style.display = tab === 'login' ? '' : 'none';
  document.getElementById('register-form').style.display = tab === 'register' ? '' : 'none';
  document.getElementById('otp-form').style.display = 'none';
  const ff = document.getElementById('forgot-password-form');
  if (ff) {
    ff.style.display = tab === 'forgot' ? '' : 'none';
    if (tab === 'forgot') resetForgotWizard();
  }
}
function resetForgotWizard() {
  const s1 = document.getElementById('forgot-step-email');
  const s2 = document.getElementById('forgot-step-code');
  const lbl = document.getElementById('forgot-step-label');
  if (s1) s1.style.display = '';
  if (s2) s2.style.display = 'none';
  if (lbl) lbl.textContent = 'We’ll email you a one-time code.';
  const fe = document.getElementById('forgot-email');
  if (fe) fe.value = '';
}
async function doForgotRequest() {
  const email = document.getElementById('forgot-email').value.trim();
  if (!email) { toast('Enter your account email.', 'error'); return; }
  const res = await api('auth.php', { action: 'forgot_password_request', email });
  if (res.success) {
    toast(res.message, 'info');
    if (res.otp_info && res.otp_info.dev_otp) toast('Dev OTP: ' + res.otp_info.dev_otp, 'info');
    if (res.email) {
      document.getElementById('forgot-email-held').value = res.email;
      document.getElementById('forgot-step-email').style.display = 'none';
      document.getElementById('forgot-step-code').style.display = '';
      document.getElementById('forgot-step-label').textContent = 'Enter the code and your new password.';
    }
    if (res.csrf) window.__CSRF_TOKEN__ = res.csrf;
  } else toast(res.message, 'error');
}
async function doForgotReset() {
  const email = document.getElementById('forgot-email-held').value.trim();
  const code = document.getElementById('forgot-code').value.trim();
  const new_password = document.getElementById('forgot-newpass').value;
  const res = await api('auth.php', { action: 'forgot_password_reset', email, code, new_password });
  if (res.success) {
    toast(res.message + ' ✅');
    if (res.csrf) window.__CSRF_TOKEN__ = res.csrf;
    showAuthTab('login');
  } else toast(res.message, 'error');
}
function showOtpForm(email) {
  document.getElementById('login-form').style.display = 'none';
  document.getElementById('register-form').style.display = 'none';
  const ff = document.getElementById('forgot-password-form');
  if (ff) ff.style.display = 'none';
  document.getElementById('otp-form').style.display = '';
  document.getElementById('otp-email').value = email;
  document.getElementById('otp-email-hint').textContent = email;
  document.getElementById('otp-code').value = '';
}
function showApp(user) {
  currentUser = user;
  currentUser.id = user.id;
  if (user.csrf) window.__CSRF_TOKEN__ = user.csrf;
  document.getElementById('landing').style.display = 'none';
  document.getElementById('auth-page').style.display = 'none';
  document.getElementById('app').style.display = 'flex';
  document.getElementById('sb-name').textContent = user.full_name || user.username;
  document.getElementById('sb-role').textContent = user.role.charAt(0).toUpperCase() + user.role.slice(1);
  document.getElementById('topbar-user').textContent = '@' + user.username;
  const badge = document.getElementById('sb-badge');
  badge.className = 'role-badge role-' + user.role;
  badge.textContent = {farmer:'🌾 Farmer', buyer:'🛒 Buyer', admin:'⚙️ Admin'}[user.role] || user.role;

  buildNav(user.role);

  // Role-specific UI tweaks
  if (user.role === 'buyer') {
    document.getElementById('btn-schedule-visit').style.display = '';
    document.getElementById('orders-sub').textContent = 'Track your orders from farmers.';
    document.getElementById('orders-col1').textContent = 'Farmer';
  } else if (user.role === 'farmer') {
    document.getElementById('orders-sub').textContent = 'Manage orders from buyers.';
    document.getElementById('orders-col1').textContent = 'Buyer';
  } else if (user.role === 'admin') {
    document.getElementById('btn-post-alert').style.display = '';
  }
  document.getElementById('set-farm-group').style.display = user.role === 'farmer' ? '' : 'none';
  populateDynamicSelects();
  refreshNotifBadge();
  startLivePoll();
  showPage('dashboard');
}

async function doLogin() {
  const username = document.getElementById('login-user').value.trim();
  const password = document.getElementById('login-pass').value;
  if (!username || !password) { toast('Please fill in all fields.', 'error'); return; }
  const res = await api('auth.php', {action:'login', username, password});
  if (res.success) {
    toast('Welcome back! ✅');
    if (res.csrf) window.__CSRF_TOKEN__ = res.csrf;
    const check = await api('auth.php', {}, 'GET', {action:'check'});
    if (check.csrf) window.__CSRF_TOKEN__ = check.csrf;
    showApp(check);
  } else {
    if (res.need_verification && res.email) {
      toast(res.message, 'info');
      showOtpForm(res.email);
      if (res.otp_info && res.otp_info.dev_otp) toast('Dev OTP: ' + res.otp_info.dev_otp, 'info');
    } else {
      toast(res.message, 'error');
    }
  }
}

async function doRegister() {
  const role = document.getElementById('reg-role').value;
  const data = {
    action:'register',
    role,
    full_name: document.getElementById('reg-name').value.trim(),
    username: document.getElementById('reg-user').value.trim(),
    email: document.getElementById('reg-email').value.trim(),
    phone: document.getElementById('reg-phone').value.trim(),
    password: document.getElementById('reg-pass').value,
    address: document.getElementById('reg-addr').value.trim(),
    province: document.getElementById('reg-province').value.trim(),
    farm_name: document.getElementById('reg-farm').value.trim(),
  };
  const res = await api('auth.php', data);
  if (res.success) {
    toast(res.message + ' ✅');
    if (res.need_verification && res.email) {
      showOtpForm(res.email);
      if (res.otp_info && res.otp_info.dev_otp) toast('Dev OTP: ' + res.otp_info.dev_otp, 'info');
    } else {
      showAuthTab('login');
    }
  } else {
    toast(res.message, 'error');
  }
}

async function doVerifyOtp() {
  const email = document.getElementById('otp-email').value;
  const code = document.getElementById('otp-code').value.trim();
  const res = await api('auth.php', {action:'verify_otp', email, code});
  if (res.success) {
    toast('Email verified. You can sign in. ✅');
    showAuthTab('login');
  } else toast(res.message, 'error');
}

async function doResendOtp() {
  const email = document.getElementById('otp-email').value;
  const res = await api('auth.php', {action:'resend_otp', email});
  if (res.success) {
    toast(res.message);
    if (res.otp_info && res.otp_info.dev_otp) toast('Dev OTP: ' + res.otp_info.dev_otp, 'info');
  } else toast(res.message, 'error');
}

async function doLogout() {
  stopLivePoll();
  await api('auth.php', {action:'logout'});
  currentUser = null;
  showLanding();
  toast('Signed out successfully.');
}

// ── SESSION CHECK ON LOAD ────────────────────────────────────
async function loadPublicStats() {
  const wrap = document.getElementById('landing');
  const app = document.getElementById('app');
  if (!wrap) return;
  if (app && getComputedStyle(app).display === 'flex') return;

  const res = await api('api.php', {}, 'GET', {action:'public_stats'});
  const fmtMoney = n => '₱' + Number(n || 0).toLocaleString('en-PH', {maximumFractionDigits: 0});
  const set = (id, text) => { const el = document.getElementById(id); if (el) el.textContent = text; };
  if (!res.success) {
    ['stat-farmers', 'stat-buyers', 'stat-listings', 'stat-revenue'].forEach(id => set(id, '—'));
    return;
  }
  set('stat-farmers', String(res.farmers));
  set('stat-buyers', String(res.buyers));
  set('stat-listings', String(res.listings));
  set('stat-revenue', fmtMoney(res.revenue_delivered));
}

window.addEventListener('DOMContentLoaded', async () => {
  await fetchCsrf();
  populateDynamicSelects();

  const regRole = document.getElementById('reg-role');
  if (regRole) regRole.addEventListener('change', function() {
    document.getElementById('reg-farm-wrap').style.display = this.value === 'farmer' ? '' : 'none';
  });

  const res = await api('auth.php', {}, 'GET', {action:'check'});
  if (res.logged_in) {
    if (res.csrf) window.__CSRF_TOKEN__ = res.csrf;
    showApp(res);
  } else {
    showLanding();
    const join = new URLSearchParams(window.location.search).get('join');
    if (join === 'farmer' || join === 'buyer') {
      showAuth('register');
      if (regRole) {
        regRole.value = join;
        document.getElementById('reg-farm-wrap').style.display = join === 'farmer' ? '' : 'none';
      }
    } else if (join === 'admin') {
      showAuth('login');
    }
  }
  // Delivery address toggle
  document.getElementById('order-delivery').addEventListener('change', function() {
    document.getElementById('delivery-addr-wrap').style.display = this.value === 'delivery' ? '' : 'none';
  });
  // Order qty preview
  document.getElementById('order-qty').addEventListener('input', updateOrderTotal);

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && currentUser) runLivePollTick();
  });
});

async function loadDashboard() {
  const hour = new Date().getHours();
  const greet = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
  document.getElementById('dash-greeting').textContent = `${greet}, ${currentUser.full_name || currentUser.username}! 👋`;

  let stats = [], statsHtml = '';
  if (currentUser.role === 'admin') {
    const r = await api('api.php', {}, 'GET', {action:'admin_stats'});
    if (r.success) {
      const s = r.stats;
      stats = [
        {icon:'🌾', val:s.farmers, label:'Registered Farmers'},
        {icon:'🛒', val:s.buyers, label:'Registered Buyers'},
        {icon:'🌿', val:s.crops, label:'Active Listings'},
        {icon:'📦', val:s.orders, label:'Total Orders'},
        {icon:'💰', val:'₱'+Number(s.revenue).toLocaleString(), label:'Revenue Delivered'},
        {icon:'📅', val:s.visits, label:'Farm Visits'},
      ];
    }
  } else {
    const orders = await api('orders.php', {}, 'GET', {action:'my_orders'});
    const cnt = orders.success ? orders.orders.length : 0;
    const pending = orders.success ? orders.orders.filter(o=>o.status==='pending').length : 0;
    if (currentUser.role === 'farmer') {
      const crops = await api('crops.php', {}, 'GET', {action:'my_crops'});
      const fa = await api('api.php', {}, 'GET', {action:'farmer_analytics'});
      const vis = await api('api.php', {}, 'GET', {action:'my_visits'});
      const earnings = fa.success ? Number(fa.earnings_total) : 0;
      const pendingVisits = vis.success ? vis.visits.filter(v => v.status === 'pending').length : 0;
      stats = [
        {icon:'🌿', val: crops.success ? crops.crops.length : 0, label:'Active Listings'},
        {icon:'📦', val: cnt, label:'Total Orders'},
        {icon:'⏳', val: pending, label:'Pending Orders'},
        {icon:'✅', val: orders.success ? orders.orders.filter(o=>o.status==='delivered').length : 0, label:'Completed Orders'},
        {icon:'💰', val: '₱' + earnings.toLocaleString(), label:'Total Earnings'},
        {icon:'📅', val: pendingVisits, label:'Visit requests (pending)'},
      ];
    } else {
      const vis = await api('api.php', {}, 'GET', {action:'my_visits'});
      const vcnt = vis.success ? vis.visits.length : 0;
      stats = [
        {icon:'🛒', val: cnt, label:'My Orders'},
        {icon:'⏳', val: pending, label:'Pending'},
        {icon:'✅', val: orders.success ? orders.orders.filter(o=>o.status==='delivered').length : 0, label:'Delivered'},
        {icon:'📅', val: vcnt, label:'Farm visits'},
      ];
    }
  }
  document.getElementById('dash-stats').innerHTML = stats.map(s => `
    <div class="stat-box">
      <div class="stat-box-icon">${s.icon}</div>
      <div class="stat-box-val">${s.val}</div>
      <div class="stat-box-label">${s.label}</div>
    </div>`).join('');

  const recCard = document.getElementById('dash-buyer-rec');
  const recList = document.getElementById('dash-rec-list');
  if (recCard && recList) {
    if (currentUser.role === 'buyer') {
      recCard.style.display = '';
      const mc = await api('crops.php', {}, 'GET', {action:'get_crops'});
      if (mc.success && mc.crops.length) {
        recList.innerHTML = '<div class="dash-rec-grid">' + mc.crops.slice(0, 6).map(c => `
          <button type="button" class="dash-rec-chip" onclick="showPage('marketplace')">
            <span>${escapeHtml(c.crop_name)}</span>
            <small>₱${Number(c.price_per_unit).toFixed(0)} / ${escapeHtml(String(c.unit))} · ${escapeHtml(c.farmer_name || '')}</small>
          </button>`).join('') + '<p style="font-size:12px;color:var(--gray-500);margin-top:10px">Tap a crop, then use <strong>Order now</strong> on the marketplace.</p></div>';
      } else {
        recList.innerHTML = '<p style="color:var(--gray-500);font-size:14px">No listings yet. Check back soon.</p>';
      }
    } else {
      recCard.style.display = 'none';
    }
  }

  // Recent alerts
  const alerts = await api('api.php', {}, 'GET', {action:'get_alerts'});
  const alertsEl = document.getElementById('dash-alerts-list');
  if (alerts.success && alerts.alerts.length) {
    alertsEl.innerHTML = alerts.alerts.slice(0,3).map(a => `
      <div class="alert-banner alert-${a.type}" style="margin-bottom:8px">
        <span>${alertIcon(a.type)}</span>
        <div><strong>${escapeHtml(a.title)}</strong><br/><small>${escapeHtml((a.message || '').length > 90 ? a.message.substring(0, 90) + '…' : (a.message || ''))}</small></div>
      </div>`).join('');
  } else if (alertsEl) {
    alertsEl.innerHTML = '<div class="empty-state"><div class="empty-icon">🔔</div><p>No alerts for your region yet.</p></div>';
  }
  refreshNotifBadge();
  // Recent orders snippet
  const orders2 = await api('orders.php', {}, 'GET', {action:'my_orders'});
  const ordersEl = document.getElementById('dash-orders-list');
  if (orders2.success && orders2.orders.length) {
    ordersEl.innerHTML = orders2.orders.slice(0,4).map(o => `
      <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--gray-100)">
        <div><div style="font-size:14px;font-weight:600">${escapeHtml(o.crop_name)}</div>
        <div style="font-size:12px;color:var(--gray-500)">${o.quantity} ${escapeHtml(String(o.unit))} · ₱${Number(o.total_price).toLocaleString()}</div></div>
        <span class="pill ${pillClass(o.status)}">${o.status}</span>
      </div>`).join('');
  } else if (ordersEl) {
    ordersEl.innerHTML = '<div class="empty-state"><div class="empty-icon">📦</div><p>No orders yet.</p></div>';
  }
}
async function loadOrders() {
  const res = await api('orders.php', {}, 'GET', {action:'my_orders'});
  const tb = document.getElementById('orders-table');
  if (!res.success || !res.orders.length) { tb.innerHTML = '<tr><td colspan="10" style="text-align:center;color:var(--gray-500);padding:40px">No orders yet.</td></tr>'; return; }
  tb.innerHTML = res.orders.map(o => {
    const other = currentUser.role === 'buyer' ? o.farmer_name : o.buyer_name;
    const actions = currentUser.role === 'farmer' && o.status === 'pending'
      ? `<button type="button" class="btn btn-primary btn-sm" onclick="updateOrder(${o.id},'confirmed')">Confirm</button>
         <button type="button" class="btn btn-danger btn-sm" onclick="updateOrder(${o.id},'cancelled')">Cancel</button>`
      : currentUser.role === 'farmer' && o.status === 'confirmed'
      ? `<button type="button" class="btn btn-primary btn-sm" onclick="updateOrder(${o.id},'packed')">Mark Packed</button>`
      : currentUser.role === 'farmer' && o.status === 'packed'
      ? `<button type="button" class="btn btn-primary btn-sm" onclick="updateOrder(${o.id},'in_transit')">In Transit</button>`
      : currentUser.role === 'farmer' && o.status === 'in_transit'
      ? `<button type="button" class="btn btn-primary btn-sm" onclick="updateOrder(${o.id},'delivered')">Mark Delivered</button>`
      : currentUser.role === 'buyer' && o.status === 'delivered'
      ? `<button type="button" class="btn btn-ghost btn-sm" onclick="openRate(${o.farmer_id},${o.id})">⭐ Rate</button>`
      : currentUser.role === 'buyer' && o.status === 'pending'
      ? `<button type="button" class="btn btn-danger btn-sm" onclick="cancelOrder(${o.id})">Cancel order</button>` : '—';
    return `<tr>
      <td>#${o.id}</td>
      <td><strong>${o.crop_name}</strong></td>
      <td>${other}</td>
      <td>${o.quantity} ${o.unit}</td>
      <td>₱${Number(o.total_price).toLocaleString()}</td>
      <td><span class="pill pill-blue">${o.order_type}</span></td>
      <td>${o.delivery_type}</td>
      <td><span class="pill ${pillClass(o.status)}">${o.status}</span></td>
      <td style="font-size:12px;color:var(--gray-500)">${new Date(o.created_at).toLocaleDateString('en-PH')}</td>
      <td style="display:flex;gap:6px;flex-wrap:wrap">${actions}</td>
    </tr>`;
  }).join('');
}
async function loadVisits() {
  const res = await api('api.php', {}, 'GET', {action:'my_visits'});
  const el = document.getElementById('visits-list');
  if (!res.success || !res.visits.length) { el.innerHTML = '<div class="empty-state"><div class="empty-icon">📅</div><p>No visits yet.</p></div>'; return; }
  el.innerHTML = res.visits.map(v => `
    <div style="padding:12px 0;border-bottom:1px solid var(--gray-100)">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
        <div style="font-weight:600;font-size:14px">${v.visit_date} at ${v.visit_time}</div>
        <span class="pill ${pillClass(v.status)}">${v.status}</span>
      </div>
      <div style="font-size:13px;color:var(--gray-500);margin-bottom:8px">
        ${currentUser.role==='buyer' ? 'Farmer: '+v.farmer_name : 'Buyer: '+v.buyer_name} · ${v.purpose} · Group of ${v.group_size}
      </div>
      ${currentUser.role === 'farmer' && v.status === 'pending' ? `
        <div style="display:flex;gap:8px">
          <button type="button" class="btn btn-primary btn-sm" onclick="updateVisit(${v.id},'approved')">✅ Approve</button>
          <button type="button" class="btn btn-danger btn-sm" onclick="updateVisit(${v.id},'declined')">✕ Decline</button>
        </div>` : ''}
    </div>`).join('');
}
async function loadMessagesPage() {
  const chatList = document.getElementById('chat-list');
  const chatMsgs = document.getElementById('chat-messages');
  const res = await api('api.php', {}, 'GET', {action:'get_conversations'});
  if (!res.success || !res.conversations.length) {
    chatList.innerHTML = '<div style="padding:16px;font-size:12px;color:var(--gray-500)">No conversations yet. Message a farmer from the marketplace.</div>';
    chatMsgs.innerHTML = '<div class="empty-state"><div class="empty-icon">💬</div><p>Select a conversation.</p></div>';
    document.getElementById('chat-input-area').style.display = 'none';
    return;
  }
  chatList.innerHTML = res.conversations.map((c, i) => `
    <div class="chat-list-item ${currentChatWith === c.user.id ? 'active' : ''}" onclick="openChat(${c.user.id})">
      <div class="chat-list-name">${c.user.full_name || c.user.username}</div>
      <div style="font-size:11px;color:var(--gray-500);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${(c.preview||'').slice(0,40)}</div>
    </div>`).join('');
  if (currentChatWith) await renderChatThread(currentChatWith);
  else {
    chatMsgs.innerHTML = '<div class="empty-state"><div class="empty-icon">💬</div><p>Select a conversation on the left.</p></div>';
    document.getElementById('chat-input-area').style.display = 'none';
  }
}

async function openChat(otherId) {
  currentChatWith = otherId;
  await loadMessagesPage();
}

async function renderChatThread(otherId) {
  const res = await api('api.php', {}, 'GET', {action:'get_messages', with: otherId});
  const chatMsgs = document.getElementById('chat-messages');
  if (!res.success) { toast(res.message || 'Failed to load', 'error'); return; }
  const me = currentUser.full_name || currentUser.username;
  chatMsgs.innerHTML = res.messages.map(m => `
    <div>
      <div class="msg-bubble ${m.sender_name === me ? 'msg-me' : 'msg-other'}">
        <strong style="font-size:11px;display:block;margin-bottom:3px">${m.sender_name}</strong>
        ${escapeHtml(m.message)}
        <div class="msg-time">${new Date(m.created_at).toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'})}</div>
      </div>
    </div>`).join('');
  document.getElementById('chat-input-area').style.display = 'flex';
}

async function sendMessage() {
  const msg = document.getElementById('chat-text').value.trim();
  if (!currentChatWith) { toast('Select a conversation first.', 'error'); return; }
  if (!msg) return;
  const res = await api('api.php', {action:'send_message', receiver_id: currentChatWith, message: msg});
  if (res.success) {
    document.getElementById('chat-text').value = '';
    toast('Message sent ✅');
    await renderChatThread(currentChatWith);
    loadMessagesPage();
  } else toast(res.message, 'error');
}

async function loadNotificationsPage() {
  const res = await api('api.php', {}, 'GET', {action:'get_notifications'});
  const el = document.getElementById('notifications-list');
  if (!res.success) { el.innerHTML = '<p>Could not load.</p>'; return; }
  if (!res.notifications.length) { el.innerHTML = '<div class="empty-state"><div class="empty-icon">🔔</div><p>No notifications yet.</p></div>'; return; }
  el.innerHTML = res.notifications.map(n => `
    <div class="notif-row" data-nid="${n.id}" data-nlink="${encodeURIComponent(n.link||'')}" style="padding:14px 0;border-bottom:1px solid var(--gray-100);cursor:pointer;opacity:${n.is_read==1?0.7:1}">
      <div style="font-weight:600">${escapeHtml(n.title)}</div>
      <div style="font-size:13px;color:var(--gray-500);margin-top:4px">${escapeHtml(n.body||'')}</div>
      <div style="font-size:11px;color:var(--gray-300);margin-top:6px">${new Date(n.created_at).toLocaleString('en-PH')}</div>
    </div>`).join('');
  el.onclick = function(e) {
    const t = e.target.closest('.notif-row');
    if (!t) return;
    const id = t.getAttribute('data-nid');
    const l = t.getAttribute('data-nlink');
    markNotifRead(id);
    if (l) { const page = decodeURIComponent(l); if (page) showPage(page); }
    refreshNotifBadge();
  };
}

async function markNotifRead(id) {
  await api('api.php', {action:'mark_notification_read', id});
}

async function markAllNotifRead() {
  const res = await api('api.php', { action: 'mark_all_notifications_read' });
  if (res.success) {
    toast('All notifications marked as read');
    await loadNotificationsPage();
    refreshNotifBadge();
  } else toast(res.message || 'Could not update notifications.', 'error');
}

async function loadSettingsPage() {
  const res = await api('auth.php', {}, 'GET', {action:'get_profile'});
  if (!res.success) return;
  const p = res.profile;
  document.getElementById('set-name').value = p.full_name || '';
  document.getElementById('set-phone').value = p.phone || '';
  document.getElementById('set-addr').value = p.address || '';
  document.getElementById('set-farm').value = p.farm_name || '';
  document.getElementById('set-province').value = p.province || '';
}

async function saveProfile() {
  const res = await api('auth.php', {
    action:'update_profile',
    full_name: document.getElementById('set-name').value.trim(),
    phone: document.getElementById('set-phone').value.trim(),
    address: document.getElementById('set-addr').value.trim(),
    farm_name: document.getElementById('set-farm').value.trim(),
    province: document.getElementById('set-province').value.trim(),
  });
  if (res.success) { toast('Profile saved'); currentUser.full_name = document.getElementById('set-name').value.trim(); document.getElementById('sb-name').textContent = currentUser.full_name || currentUser.username; }
  else toast(res.message, 'error');
}

async function changePassword() {
  const res = await api('auth.php', {
    action:'change_password',
    current_password: document.getElementById('set-pass-old').value,
    new_password: document.getElementById('set-pass-new').value,
  });
  if (res.success) { toast('Password updated'); document.getElementById('set-pass-old').value=''; document.getElementById('set-pass-new').value=''; }
  else toast(res.message, 'error');
}
async function loadTransactionsPage() {
  const res = await api('orders.php', {}, 'GET', {action:'my_transactions'});
  const tb = document.getElementById('txn-table');
  if (!res.success || !res.transactions.length) { tb.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:var(--gray-500)">No transactions yet.</td></tr>'; return; }
  tb.innerHTML = res.transactions.map(t => `<tr><td>#${t.order_id}</td><td>${escapeHtml(t.crop_name)}</td><td>₱${Number(t.amount).toLocaleString()}</td><td>${new Date(t.created_at).toLocaleDateString('en-PH')}</td></tr>`).join('');
}
async function loadAlerts() {
  const res = await api('api.php', {}, 'GET', {action:'get_alerts'});
  const el = document.getElementById('alerts-list');
  if (!res.success || !res.alerts.length) { el.innerHTML = '<div class="empty-state"><div class="empty-icon">🔔</div><p>No alerts posted yet.</p></div>'; return; }
  el.innerHTML = res.alerts.map(a => `
    <div class="alert-banner alert-${a.type}" style="margin-bottom:12px;align-items:flex-start">
      <span style="font-size:24px">${alertIcon(a.type)}</span>
      <div>
        <strong style="font-size:15px">${a.title}</strong>
        <div style="margin-top:6px;font-size:14px;line-height:1.5">${a.message}</div>
        <div style="margin-top:8px;font-size:12px;opacity:.7">Posted by ${a.admin_name} · ${new Date(a.created_at).toLocaleDateString('en-PH')}</div>
      </div>
    </div>`).join('');
}
// ── ACTIONS ──────────────────────────────────────────────────
function openOrderById(cropId) {
  const c = allCrops.find(x => Number(x.id) === Number(cropId));
  if (!c) {
    toast('This listing is no longer available. Refresh the marketplace.', 'error');
    return;
  }
  if (currentUser.role === 'buyer' && Number(c.farmer_id) === Number(currentUser.id)) {
    toast('You cannot order from your own farm listing.', 'error');
    return;
  }
  openOrder(c);
}

function openOrder(crop) {
  currentOrderCrop = crop;
  const desc = (crop.description || '').trim();
  document.getElementById('order-crop-info').innerHTML = `
    <strong style="font-size:16px">${escapeHtml(crop.crop_name)}</strong>
    <div style="font-size:13px;color:var(--gray-500);margin-top:4px">by ${escapeHtml(crop.farmer_name)} · ₱${Number(crop.price_per_unit).toFixed(2)} per ${escapeHtml(String(crop.unit))}</div>
    ${desc ? `<p style="font-size:13px;margin-top:10px;line-height:1.45;color:var(--gray-700)">${escapeHtml(desc.slice(0, 320))}${desc.length > 320 ? '…' : ''}</p>` : ''}
    <div style="font-size:13px;margin-top:8px">Available: <strong>${crop.quantity_available} ${escapeHtml(String(crop.unit))}</strong></div>`;
  document.getElementById('order-unit').textContent = crop.unit;
  document.getElementById('order-total-preview').textContent = '';
  const q = document.getElementById('order-qty');
  q.min = '0.5';
  q.step = '0.5';
  q.max = String(Math.max(0.5, Number(crop.quantity_available) || 0));
  q.value = '';
  document.getElementById('order-delivery').value = 'pickup';
  document.getElementById('delivery-addr-wrap').style.display = 'none';
  document.getElementById('order-delivery-addr').value = '';
  openModal('modal-order');
}

function updateOrderTotal() {
  if (!currentOrderCrop) return;
  const qty = parseFloat(document.getElementById('order-qty').value) || 0;
  const total = qty * currentOrderCrop.price_per_unit;
  document.getElementById('order-total-preview').textContent = qty > 0 ? `Total: ₱${total.toLocaleString('en-PH',{minimumFractionDigits:2})}` : '';
}

async function submitOrder() {
  const qty = parseFloat(document.getElementById('order-qty').value);
  const dtype = document.getElementById('order-delivery').value;
  const daddr = document.getElementById('order-delivery-addr').value.trim();
  if (!qty || qty <= 0) { toast('Please enter a valid quantity.', 'error'); return; }
  if (currentOrderCrop && qty > Number(currentOrderCrop.quantity_available)) {
    toast('Quantity exceeds what the farmer has available.', 'error');
    return;
  }
  if (dtype === 'delivery' && daddr.length < 8) {
    toast('Please enter a full delivery address (street, barangay, city).', 'error');
    return;
  }
  const res = await api('orders.php', {
    action: 'place_order',
    crop_id: currentOrderCrop.id,
    quantity: qty,
    order_type: document.getElementById('order-type').value,
    delivery_type: dtype,
    delivery_address: daddr,
    notes: document.getElementById('order-notes').value,
  });
  if (res.success) {
    toast(`Order placed! Total: ₱${Number(res.total).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}`);
    closeModal('modal-order');
    if (typeof loadMarketplace === 'function') loadMarketplace();
    if (typeof loadDashboard === 'function') loadDashboard();
    refreshNotifBadge();
  } else toast(res.message, 'error');
}

async function submitAddCrop() {
  const res = await api('crops.php', {
    action:'add_crop',
    crop_name: document.getElementById('c-name').value,
    category: document.getElementById('c-cat').value,
    description: document.getElementById('c-desc').value,
    price_per_unit: document.getElementById('c-price').value,
    unit: document.getElementById('c-unit').value,
    quantity_available: document.getElementById('c-qty').value,
    harvest_date: document.getElementById('c-harvest').value,
    location: document.getElementById('c-location').value,
    status: document.getElementById('c-status').value,
  });
  if (res.success) {
    toast(res.message + ' ✅');
    closeModal('modal-add-crop');
    loadMyCrops();
  } else toast(res.message, 'error');
}

async function toggleCropStatus(id, status) {
  const res = await api('crops.php', {action:'update_crop', id, status});
  if (res.success) { toast('Crop updated ✅'); loadMyCrops(); }
  else toast(res.message, 'error');
}

async function deleteCrop(id) {
  if (!confirm('Delete this crop listing? This cannot be undone.')) return;
  const res = await api('crops.php', {action:'delete_crop', id});
  if (res.success) { toast('Crop deleted.'); loadMyCrops(); }
  else toast(res.message, 'error');
}

async function updateOrder(id, status) {
  const res = await api('orders.php', {action:'update_order', id, status});
  if (res.success) { toast('Order updated ✅'); loadOrders(); }
  else toast(res.message, 'error');
}

async function submitVisit() {
  const res = await api('api.php', {
    action:'schedule_visit',
    farmer_id: document.getElementById('visit-farmer-id').value,
    visit_date: document.getElementById('visit-date').value,
    visit_time: document.getElementById('visit-time').value,
    purpose: document.getElementById('visit-purpose').value,
    group_size: document.getElementById('visit-group').value,
    notes: document.getElementById('visit-notes').value,
  });
  if (res.success) { toast(res.message + ' ✅'); closeModal('modal-schedule-visit'); loadVisits(); }
  else toast(res.message, 'error');
}

function openVisitFromCrop(farmerId) {
  document.getElementById('visit-farmer-id').value = farmerId;
  openModal('modal-schedule-visit');
}

async function updateVisit(id, status) {
  const res = await api('api.php', {action:'update_visit', id, status});
  if (res.success) { toast('Visit ' + status + ' ✅'); loadVisits(); }
  else toast(res.message, 'error');
}

async function submitAlert() {
  const res = await api('api.php', {
    action:'post_alert',
    title: document.getElementById('alert-title').value,
    message: document.getElementById('alert-message').value,
    type: document.getElementById('alert-type').value,
    target_province: document.getElementById('alert-province').value.trim(),
  });
  if (res.success) { toast('Alert broadcasted! 📡'); closeModal('modal-post-alert'); loadAlerts(); }
  else toast(res.message, 'error');
}

function openRate(farmerId, orderId) {
  document.getElementById('rate-farmer-id').value = farmerId;
  document.getElementById('rate-order-id').value = orderId;
  document.getElementById('rate-value').value = 0;
  setRating(0);
  openModal('modal-rate');
}

function setRating(n) {
  _ratingVal = n;
  document.getElementById('rate-value').value = n;
  document.querySelectorAll('#star-input button').forEach((b,i) => {
    b.classList.toggle('filled', i < n);
  });
}

async function submitRating() {
  if (_ratingVal < 1) { toast('Please select a rating.', 'error'); return; }
  const res = await api('api.php', {
    action:'submit_rating',
    farmer_id: document.getElementById('rate-farmer-id').value,
    order_id: document.getElementById('rate-order-id').value,
    rating: _ratingVal,
    review: document.getElementById('rate-review').value,
  });
  if (res.success) { toast('Rating submitted ⭐'); closeModal('modal-rate'); }
  else toast(res.message, 'error');
}

async function cancelOrder(oid) {
  if (!confirm('Cancel this pending order?')) return;
  const res = await api('orders.php', {action:'cancel_order', id: oid});
  if (res.success) { toast('Order cancelled'); loadOrders(); }
  else toast(res.message, 'error');
}
