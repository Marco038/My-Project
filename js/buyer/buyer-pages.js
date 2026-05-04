/** Buyer role */
async function loadMarketplace() {
  const res = await api('crops.php', {}, 'GET', {action:'get_crops'});
  allCrops = res.success ? res.crops : [];
  renderCropGrid(allCrops);
}

function renderCropGrid(crops) {
  const el = document.getElementById('marketplace-grid');
  if (!crops.length) { el.innerHTML = '<div class="empty-state"><div class="empty-icon">🌾</div><p>No crops found. Try adjusting your search.</p></div>'; return; }
  el.innerHTML = crops.map(c => `
    <div class="crop-card">
      <div class="crop-img">${cropEmoji(c.category)}</div>
      <div class="crop-body">
        <div class="crop-name">${c.crop_name}</div>
        <div class="crop-farmer">
          ${c.gov_id_verified ? '<span class="verified" title="Verified Farmer">✅</span>' : ''}
          by ${c.farmer_name}
        </div>
        <div class="crop-price">₱${Number(c.price_per_unit).toFixed(2)} <span>/ ${c.unit}</span></div>
        <div class="crop-meta">
          <span class="crop-meta-item">📦 ${c.quantity_available} ${c.unit}</span>
          ${c.location ? `<span class="crop-meta-item">📍 ${c.location}</span>` : ''}
          ${c.harvest_date ? `<span class="crop-meta-item">🗓 ${c.harvest_date}</span>` : ''}
        </div>
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:12px">
          <span class="stars">${'★'.repeat(Math.min(5,Math.max(0,Math.round(Number(c.avg_rating)||0))))}${'☆'.repeat(5-Math.min(5,Math.max(0,Math.round(Number(c.avg_rating)||0))))}</span>
          <span style="font-size:12px;color:var(--gray-500)">${Number(c.avg_rating||0).toFixed(1)} (${Number(c.review_count)||0})</span>
          <span class="pill ${c.status==='available'?'pill-green':'pill-blue'}" style="margin-left:auto">${c.status}</span>
        </div>
        ${currentUser.role === 'buyer' ? `<button type="button" class="btn btn-primary" style="width:100%;justify-content:center" onclick="openOrderById(${c.id})">Order now</button>` : ''}
        ${currentUser.role === 'buyer' ? `<button type="button" class="btn btn-ghost btn-sm" style="width:100%;justify-content:center;margin-top:8px" onclick="addFavoriteFarmer(${c.farmer_id})">⭐ Save farmer</button>` : ''}
        ${currentUser.role === 'buyer' ? `<button type="button" class="btn btn-ghost btn-sm" style="width:100%;justify-content:center;margin-top:8px" onclick="openVisitFromCrop(${c.farmer_id})">📅 Schedule Visit</button>` : ''}
      </div>
    </div>`).join('');
}

function filterCrops() {
  const q = document.getElementById('crop-search').value.toLowerCase();
  const cat = document.getElementById('crop-cat').value;
  const filtered = allCrops.filter(c =>
    (!q || c.crop_name.toLowerCase().includes(q) || (c.location||'').toLowerCase().includes(q)) &&
    (!cat || c.category === cat)
  );
  renderCropGrid(filtered);
}
async function loadFavoritesPage() {
  const res = await api('api.php', {}, 'GET', {action:'list_favorites'});
  const el = document.getElementById('favorites-grid');
  if (!res.success || !res.favorites.length) { el.innerHTML = '<div class="empty-state"><div class="empty-icon">⭐</div><p>No favorites yet. Save farmers from the marketplace.</p></div>'; return; }
  el.innerHTML = res.favorites.map(f => `
    <div class="crop-card">
      <div class="crop-img">🌾</div>
      <div class="crop-body">
        <div class="crop-name">${escapeHtml(f.full_name||f.username)}</div>
        <div class="crop-farmer">${f.gov_id_verified ? '<span class="verified">✅</span>' : ''} ${escapeHtml(f.province||f.address||'')}</div>
        <div style="margin:8px 0"><span class="stars">${'★'.repeat(Math.round(f.avg_rating))}${'☆'.repeat(5-Math.round(f.avg_rating))}</span></div>
        <button type="button" class="btn btn-ghost btn-sm" style="width:100%" onclick="removeFavorite(${f.id})">Remove</button>
        <button type="button" class="btn btn-primary btn-sm" style="width:100%;margin-top:8px" onclick="document.getElementById('visit-farmer-id').value=${f.id};openModal('modal-schedule-visit')">Schedule visit</button>
      </div>
    </div>`).join('');
}

async function removeFavorite(fid) {
  if (!confirm('Remove from favorites?')) return;
  const res = await api('api.php', {action:'remove_favorite', farmer_id: fid});
  if (res.success) loadFavoritesPage();
}
async function addFavoriteFarmer(fid) {
  const res = await api('api.php', {action:'add_favorite', farmer_id: fid});
  if (res.success) toast('Farmer saved to favorites ⭐');
  else toast(res.message, 'error');
}
