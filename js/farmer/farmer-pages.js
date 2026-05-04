/** Farmer role */
async function loadMyCrops() {
  const res = await api('crops.php', {}, 'GET', {action:'my_crops'});
  const tb = document.getElementById('my-crops-table');
  if (!res.success || !res.crops.length) { tb.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--gray-500);padding:40px">No crops listed yet. Click "Add Crop" to get started!</td></tr>'; return; }
  tb.innerHTML = res.crops.map(c => `
    <tr>
      <td><strong>${c.crop_name}</strong></td>
      <td>${c.category || '—'}</td>
      <td>₱${Number(c.price_per_unit).toFixed(2)} / ${c.unit}</td>
      <td>${c.quantity_available} ${c.unit}</td>
      <td>${c.harvest_date || '—'}</td>
      <td><span class="pill ${c.status==='available'?'pill-green':c.status==='pre-order'?'pill-blue':'pill-gray'}">${c.status}</span></td>
      <td>${c.order_count}</td>
      <td style="display:flex;gap:6px;flex-wrap:wrap">
        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleCropStatus(${c.id},'${c.status==='inactive'?'available':'inactive'}')">
          ${c.status==='inactive'?'Activate':'Deactivate'}
        </button>
        <button type="button" class="btn btn-danger btn-sm" onclick="deleteCrop(${c.id})">Delete</button>
      </td>
    </tr>`).join('');
}
async function loadAnalyticsPage() {
  const res = await api('api.php', {}, 'GET', {action:'farmer_analytics'});
  const st = document.getElementById('analytics-stats');
  const top = document.getElementById('analytics-top');
  const chart = document.getElementById('analytics-chart');
  if (!res.success) { st.innerHTML=''; if (chart) chart.innerHTML=''; top.innerHTML='<p>Unable to load.</p>'; return; }
  const ob = res.orders_by_status || {};
  st.innerHTML = `
    <div class="stat-box"><div class="stat-box-icon">💰</div><div class="stat-box-val">₱${Number(res.earnings_total||0).toLocaleString()}</div><div class="stat-box-label">Total earnings (delivered)</div></div>
    <div class="stat-box"><div class="stat-box-icon">📦</div><div class="stat-box-val">${ob.delivered||0}</div><div class="stat-box-label">Delivered orders</div></div>
    <div class="stat-box"><div class="stat-box-icon">⏳</div><div class="stat-box-val">${ob.pending||0}</div><div class="stat-box-label">Pending</div></div>`;
  const entries = Object.entries(ob).filter(([, v]) => Number(v) > 0);
  const max = Math.max(1, ...entries.map(([, v]) => Number(v)));
  if (chart) {
    chart.innerHTML = entries.length
      ? '<div class="chart-wrap">' + entries.map(([k, v]) => {
        const n = Number(v);
        const pct = Math.round((n / max) * 100);
        return `<div class="chart-row"><span class="chart-label">${escapeHtml(k)}</span><div class="chart-bar-bg" aria-hidden="true"><div class="chart-bar" style="width:${pct}%"></div></div><span class="chart-val">${n}</span></div>`;
      }).join('') + '</div>'
      : '<p style="color:var(--gray-500);font-size:14px">No order status data yet.</p>';
  }
  top.innerHTML = (res.top_crops && res.top_crops.length) ? '<ul style="margin-left:18px;line-height:1.8">' + res.top_crops.map(t => `<li><strong>${escapeHtml(t.crop_name)}</strong> — ${t.sold} sold</li>`).join('') + '</ul>' : '<p class="empty-state">No delivered sales yet.</p>';
}
