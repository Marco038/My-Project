/** Admin role */
async function loadAdminCropsPage() {
  const res = await api('crops.php', {}, 'GET', {action:'admin_all_crops'});
  const tb = document.getElementById('admin-crops-table');
  if (!res.success || !res.crops.length) { tb.innerHTML = '<tr><td colspan="6">None</td></tr>'; return; }
  const statuses = ['available','pre-order','sold_out','inactive'];
  tb.innerHTML = res.crops.map(c => `
    <tr>
      <td>${c.id}</td><td>${escapeHtml(c.crop_name)}</td><td>${escapeHtml(c.farmer_name)}</td><td>${c.quantity_available}</td>
      <td><span class="pill pill-gray">${c.status}</span></td>
      <td><select class="form-input" style="min-width:120px;padding:6px" onchange="adminSetCropStatus(${c.id},this.value)">
        ${statuses.map(s => `<option value="${s}" ${s===c.status?'selected':''}>${s}</option>`).join('')}
      </select></td>
    </tr>`).join('');
}

async function adminSetCropStatus(id, status) {
  const res = await api('crops.php', {action:'admin_set_crop_status', id, status});
  if (res.success) toast('Updated'); else toast(res.message,'error');
}

async function loadAuditPage() {
  const res = await api('api.php', {}, 'GET', {action:'audit_logs'});
  const tb = document.getElementById('audit-table');
  if (!res.success || !res.logs.length) { tb.innerHTML = '<tr><td colspan="5">No logs</td></tr>'; return; }
  tb.innerHTML = res.logs.map(l => `<tr><td style="font-size:12px">${l.created_at}</td><td>${escapeHtml(l.username||'—')}</td><td>${escapeHtml(l.action)}</td><td>${escapeHtml((l.details||'').slice(0,80))}</td><td>${escapeHtml(l.ip_address||'')}</td></tr>`).join('');
}

async function loadEventsPage() {
  const res = await api('api.php', {}, 'GET', {action:'event_logs'});
  const tb = document.getElementById('events-table');
  if (!res.success || !res.events.length) { tb.innerHTML = '<tr><td colspan="5">No events</td></tr>'; return; }
  tb.innerHTML = res.events.map(e => `<tr><td style="font-size:12px">${e.created_at}</td><td>${escapeHtml(e.event_type)}</td><td>${e.actor_user_id||'—'}</td><td>${escapeHtml((e.entity_type||'')+' #'+(e.entity_id||''))}</td><td style="font-size:11px;max-width:200px;overflow:hidden">${escapeHtml((e.payload_json||'').slice(0,60))}</td></tr>`).join('');
}

async function loadAdminUsers() {
  const res = await api('api.php', {}, 'GET', {action:'all_users'});
  const tb = document.getElementById('admin-users-table');
  if (!res.success || !res.users.length) { tb.innerHTML = '<tr><td colspan="9" style="text-align:center">No users found.</td></tr>'; return; }
  tb.innerHTML = res.users.map(u => `
    <tr>
      <td>${u.id}</td>
      <td>${u.full_name || '—'}</td>
      <td>@${u.username}</td>
      <td>${u.email}</td>
      <td><span class="pill role-${u.role}" style="background:${u.role==='farmer'?'rgba(82,183,136,.15)':u.role==='admin'?'rgba(69,123,157,.15)':'rgba(244,162,97,.15)'}">${u.role}</span></td>
      <td>${u.gov_id_verified ? '✅ Verified' : '⏳ Pending'}</td>
      <td><span class="pill ${u.is_active?'pill-green':'pill-red'}">${u.is_active?'Active':'Inactive'}</span></td>
      <td style="font-size:12px">${new Date(u.created_at).toLocaleDateString('en-PH')}</td>
      <td style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleUser(${u.id},${u.is_active?0:1})">
          ${u.is_active?'Deactivate':'Activate'}
        </button>
        ${u.role==='farmer' ? `<button type="button" class="btn btn-primary btn-sm" onclick="verifyFarmer(${u.id},1)">Verify farmer</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="verifyFarmer(${u.id},0)">Unverify</button>` : ''}
      </td>
    </tr>`).join('');
}

async function loadAdminOrders() {
  const res = await api('orders.php', {}, 'GET', {action:'all_orders'});
  const tb = document.getElementById('admin-orders-table');
  if (!res.success || !res.orders.length) { tb.innerHTML = '<tr><td colspan="8" style="text-align:center">No orders.</td></tr>'; return; }
  tb.innerHTML = res.orders.map(o => `
    <tr>
      <td>#${o.id}</td>
      <td>${o.crop_name}</td>
      <td>${o.farmer_name}</td>
      <td>${o.buyer_name}</td>
      <td>${o.quantity}</td>
      <td>₱${Number(o.total_price).toLocaleString()}</td>
      <td><span class="pill ${pillClass(o.status)}">${o.status}</span></td>
      <td style="font-size:12px">${new Date(o.created_at).toLocaleDateString('en-PH')}</td>
    </tr>`).join('');
}

async function toggleUser(id, active) {
  const res = await api('api.php', {action:'toggle_user', id, is_active:active});
  if (res.success) { toast('User updated ✅'); loadAdminUsers(); }
  else toast(res.message, 'error');
}

async function verifyFarmer(id, verified) {
  const res = await api('api.php', {action:'verify_farmer', id, verified});
  if (res.success) { toast('Verification updated'); loadAdminUsers(); }
  else toast(res.message, 'error');
}

function openAdminExport(type) {
  window.open(new URL('admin_export.php?type=' + encodeURIComponent(type), PHP).href, '_blank');
}

async function loadAdminReportsPage() {
  const wrap = document.getElementById('admin-reports-body');
  if (!wrap) return;
  wrap.innerHTML = `
    <p style="color:var(--gray-600);margin-bottom:20px;max-width:640px">
      Download UTF-8 CSV files for capstone documentation, LGU reporting, or offline analysis. You must stay signed in as admin.
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:12px">
      <button type="button" class="btn btn-primary" onclick="openAdminExport('orders')">Export orders</button>
      <button type="button" class="btn btn-earth" onclick="openAdminExport('users')">Export users</button>
      <button type="button" class="btn btn-earth" onclick="openAdminExport('crops')">Export crop listings</button>
      <button type="button" class="btn btn-earth" onclick="openAdminExport('transactions')">Export transactions</button>
      <button type="button" class="btn btn-earth" onclick="openAdminExport('visits')">Export farm visits</button>
    </div>`;
}

async function loadAdminAnalyticsPage() {
  const res = await api('api.php', {}, 'GET', {action:'admin_analytics'});
  const statsEl = document.getElementById('admin-analytics-stats');
  const statusEl = document.getElementById('admin-analytics-status');
  const monthsEl = document.getElementById('admin-analytics-months');
  const catsEl = document.getElementById('admin-analytics-cats');
  if (!res.success || !statsEl) return;

  const s = res.stats;
  statsEl.innerHTML = [
    {icon:'🌾', val: s.farmers, label:'Farmers'},
    {icon:'🛒', val: s.buyers, label:'Buyers'},
    {icon:'🌿', val: s.crops, label:'Active listings'},
    {icon:'📦', val: s.orders, label:'Orders'},
    {icon:'💰', val: '₱' + Number(s.revenue).toLocaleString(), label:'Delivered revenue'},
    {icon:'📅', val: s.visits, label:'Farm visits'},
    {icon:'⏳', val: s.pendingFarmers, label:'Farmers pending verification'},
    {icon:'💳', val: s.txn, label:'Transaction records'},
  ].map(x => `
    <div class="stat-box">
      <div class="stat-box-icon">${x.icon}</div>
      <div class="stat-box-val">${x.val}</div>
      <div class="stat-box-label">${x.label}</div>
    </div>`).join('');

  const obs = res.orders_by_status || {};
  statusEl.innerHTML = Object.keys(obs).length
    ? '<ul style="margin:0;padding-left:20px">' + Object.entries(obs).map(([k,v]) =>
        `<li><strong>${escapeHtml(k)}</strong>: ${v}</li>`).join('') + '</ul>'
    : '<p style="color:var(--gray-500)">No orders yet.</p>';

  const months = res.orders_by_month || [];
  monthsEl.innerHTML = months.length
    ? '<table class="table-compact"><thead><tr><th>Month</th><th>Orders</th><th>Delivered revenue</th></tr></thead><tbody>' +
      months.map(m => `<tr><td>${escapeHtml(m.month)}</td><td>${m.orders}</td><td>₱${Number(m.revenue).toLocaleString()}</td></tr>`).join('') +
      '</tbody></table>'
    : '<p style="color:var(--gray-500)">No data in range.</p>';

  const cats = res.top_categories || [];
  catsEl.innerHTML = cats.length
    ? '<table class="table-compact"><thead><tr><th>Category</th><th>Order lines</th><th>Delivered revenue</th></tr></thead><tbody>' +
      cats.map(c => `<tr><td>${escapeHtml(c.category)}</td><td>${c.orders}</td><td>₱${Number(c.revenue).toLocaleString()}</td></tr>`).join('') +
      '</tbody></table>'
    : '<p style="color:var(--gray-500)">No categories yet.</p>';
}
