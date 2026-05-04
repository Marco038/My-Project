/** Sidebar navigation */
// ── NAVIGATION & ROUTING ────────────────────────────────────
const NAV_FARMER = [
  {id:'dashboard', icon:'🏠', label:'Dashboard'},
  {id:'my-crops',  icon:'🌿', label:'My Crops'},
  {id:'orders',    icon:'📦', label:'Orders'},
  {id:'visits',    icon:'📅', label:'Farm Visits'},
  {id:'messages',  icon:'💬', label:'Messages'},
  {id:'analytics', icon:'📊', label:'Analytics'},
  {id:'transactions', icon:'💳', label:'Transactions'},
  {id:'alerts',    icon:'🌧', label:'Weather & Alerts'},
  {id:'notifications', icon:'🔔', label:'Notifications'},
  {id:'settings',  icon:'⚙️', label:'Settings'},
];
const NAV_BUYER = [
  {id:'dashboard',   icon:'🏠', label:'Dashboard'},
  {id:'marketplace', icon:'🛒', label:'Marketplace'},
  {id:'orders',      icon:'📦', label:'My Orders'},
  {id:'favorites',   icon:'⭐', label:'Favorites'},
  {id:'visits',      icon:'📅', label:'Farm Visits'},
  {id:'messages',    icon:'💬', label:'Messages'},
  {id:'transactions', icon:'💳', label:'Transactions'},
  {id:'alerts',      icon:'🌧', label:'Weather & Alerts'},
  {id:'notifications', icon:'🔔', label:'Notifications'},
  {id:'settings',    icon:'⚙️', label:'Settings'},
];
const NAV_ADMIN = [
  {id:'dashboard',      icon:'🏠', label:'Dashboard'},
  {id:'admin-users',    icon:'👥', label:'Users'},
  {id:'admin-crops',    icon:'🌿', label:'Crop Listings'},
  {id:'admin-orders',   icon:'📦', label:'Transactions'},
  {id:'admin-analytics', icon:'📊', label:'Analytics'},
  {id:'admin-reports',  icon:'📄', label:'Reports'},
  {id:'marketplace',    icon:'🛒', label:'Marketplace'},
  {id:'alerts',         icon:'📡', label:'Broadcast Alerts'},
  {id:'admin-audit',    icon:'📜', label:'Audit Logs'},
  {id:'admin-events',   icon:'⚡', label:'Event Log'},
  {id:'notifications',  icon:'🔔', label:'Notifications'},
  {id:'settings',       icon:'⚙️', label:'Settings'},
];

function buildNav(role) {
  const map = {farmer:NAV_FARMER, buyer:NAV_BUYER, admin:NAV_ADMIN};
  const items = map[role] || NAV_BUYER;
  const nav = document.getElementById('sidebar-nav');
  nav.innerHTML = items.map(i => `
    <div class="nav-item" id="nav-${i.id}" onclick="showPage('${i.id}')">
      <span class="nav-icon">${i.icon}</span> ${i.label}
    </div>`).join('');
}

function showPage(id) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  const page = document.getElementById('page-' + id);
  if (page) page.classList.add('active');
  const nav = document.getElementById('nav-' + id);
  if (nav) nav.classList.add('active');
  const titles = {
    dashboard:'Dashboard', marketplace:'Marketplace', 'my-crops':'My Crops',
    orders:'Orders', visits:'Farm Visits', messages:'Messages', alerts:'Weather & Alerts',
    'admin-users':'User Management', 'admin-orders':'All Transactions', analytics:'Earnings & Analytics',
    notifications:'Notifications', settings:'Settings', favorites:'Favorite Farmers',
    transactions:'Transaction History', 'admin-crops':'Crop Listings', 'admin-audit':'Audit Logs', 'admin-events':'Event Log',
    'admin-analytics':'Platform Analytics', 'admin-reports':'Reports & Export'
  };
  document.getElementById('topbar-title').textContent = titles[id] || 'Dashboard';
  if (id === 'marketplace') loadMarketplace();
  else if (id === 'my-crops') loadMyCrops();
  else if (id === 'orders') loadOrders();
  else if (id === 'visits') loadVisits();
  else if (id === 'messages') loadMessagesPage();
  else if (id === 'alerts') loadAlerts();
  else if (id === 'admin-users') loadAdminUsers();
  else if (id === 'admin-orders') loadAdminOrders();
  else if (id === 'dashboard') loadDashboard();
  else if (id === 'notifications') loadNotificationsPage();
  else if (id === 'settings') loadSettingsPage();
  else if (id === 'analytics') loadAnalyticsPage();
  else if (id === 'favorites') loadFavoritesPage();
  else if (id === 'transactions') loadTransactionsPage();
  else if (id === 'admin-crops') loadAdminCropsPage();
  else if (id === 'admin-analytics') loadAdminAnalyticsPage();
  else if (id === 'admin-reports') loadAdminReportsPage();
  else if (id === 'admin-audit') loadAuditPage();
  else if (id === 'admin-events') loadEventsPage();
}
