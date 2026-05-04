<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/event_log.php';
require_once __DIR__ . '/includes/csrf.php';

if (!touch_session() && isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please sign in again.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action !== '' && !csrf_validate()) {
    echo json_encode(['success' => false, 'message' => 'Invalid or missing security token. Refresh the page.']);
    exit;
}

// ── LIST CATEGORIES / UNITS (no login — drives UI dropdowns) ──
if ($action === 'list_categories') {
    echo json_encode(['success' => true, 'categories' => valid_categories($conn)]);
    exit;
}

if ($action === 'list_units') {
    echo json_encode(['success' => true, 'units' => valid_units($conn)]);
    exit;
}

/** Ordered labels from DB plus any category already used on listings (legacy/custom). */
function valid_categories(mysqli $conn): array {
    $ordered = [];
    $r = $conn->query('SELECT name FROM categories WHERE is_active=1 ORDER BY sort_order, name');
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $ordered[] = $row['name'];
        }
    }
    $seen = array_flip($ordered);
    $r2 = $conn->query("SELECT DISTINCT TRIM(category) AS n FROM crops WHERE TRIM(IFNULL(category,''))<>''");
    if ($r2) {
        while ($row = $r2->fetch_assoc()) {
            $n = $row['n'];
            if ($n !== '' && !isset($seen[$n])) {
                $ordered[] = $n;
                $seen[$n] = true;
            }
        }
    }

    return $ordered;
}

/** Defaults plus units seen on crop rows (new units appear automatically). */
function valid_units(mysqli $conn): array {
    $defaults = ['kg', 'piece', 'bundle', 'sack', 'tray', 'liter', 'dozen', 'crate', 'bunch', 'box'];
    $seen = array_flip($defaults);
    $r = $conn->query("SELECT DISTINCT TRIM(unit) AS u FROM crops WHERE TRIM(IFNULL(unit,''))<>''");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $u = $row['u'];
            if ($u !== '' && !isset($seen[$u])) {
                $defaults[] = $u;
                $seen[$u] = true;
            }
        }
    }

    return $defaults;
}

function category_is_allowed(mysqli $conn, string $cat): bool {
    $cat = trim($cat);
    if ($cat === '' || strlen($cat) > 100) {
        return false;
    }

    return in_array($cat, valid_categories($conn), true);
}

function unit_is_allowed(mysqli $conn, string $unit): bool {
    $unit = trim($unit);
    if ($unit === '' || strlen($unit) > 50) {
        return false;
    }

    return in_array($unit, valid_units($conn), true);
}

function requireLogin(): void {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
        exit;
    }
}

// ── GET ALL CROPS (marketplace) ────────────────────────────
if ($action === 'get_crops') {
    $search = sanitize($conn, $_GET['search'] ?? '');
    $category = sanitize($conn, $_GET['category'] ?? '');

    $base = "SELECT c.*, u.full_name AS farmer_name, u.gov_id_verified,
            IFNULL(ROUND(AVG(r.rating),1),0) AS avg_rating,
            COUNT(r.id) AS review_count
            FROM crops c
            JOIN users u ON c.farmer_id = u.id
            LEFT JOIN ratings r ON r.farmer_id = c.farmer_id
            WHERE c.status IN ('available','pre-order') AND u.is_active = 1";

    if ($search !== '') {
        $like = '%' . $search . '%';
        if ($category !== '') {
            $sql = $base . " AND (c.crop_name LIKE ? OR IFNULL(c.location,'') LIKE ?) AND c.category = ? GROUP BY c.id ORDER BY c.created_at DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sss', $like, $like, $category);
        } else {
            $sql = $base . " AND (c.crop_name LIKE ? OR IFNULL(c.location,'') LIKE ?) GROUP BY c.id ORDER BY c.created_at DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $like, $like);
        }
    } else {
        if ($category !== '') {
            $sql = $base . " AND c.category = ? GROUP BY c.id ORDER BY c.created_at DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $category);
        } else {
            $sql = $base . " GROUP BY c.id ORDER BY c.created_at DESC";
            $stmt = $conn->prepare($sql);
        }
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $crops = [];
    while ($row = $res->fetch_assoc()) {
        $crops[] = $row;
    }
    $stmt->close();
    echo json_encode(['success' => true, 'crops' => $crops]);
    exit;
}

// ── ADMIN: ALL CROP LISTINGS ───────────────────────────────
if ($action === 'admin_all_crops') {
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Admin only.']);
        exit;
    }
    $sql = "SELECT c.*, u.full_name AS farmer_name, u.username AS farmer_username
            FROM crops c JOIN users u ON c.farmer_id = u.id ORDER BY c.created_at DESC LIMIT 500";
    $res = $conn->query($sql);
    $crops = [];
    while ($row = $res->fetch_assoc()) {
        $crops[] = $row;
    }
    echo json_encode(['success' => true, 'crops' => $crops]);
    exit;
}

// ── ADMIN: SET CROP LISTING STATUS ─────────────────────────
if ($action === 'admin_set_crop_status') {
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Admin only.']);
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $status = sanitize($conn, $_POST['status'] ?? '');
    $allowed = ['available', 'pre-order', 'sold_out', 'inactive'];
    if (!in_array($status, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status.']);
        exit;
    }
    $stmt = $conn->prepare('UPDATE crops SET status=? WHERE id=?');
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    $stmt->close();
    audit_admin_crop($conn, (int) $_SESSION['user_id'], $id, $status);
    echo json_encode(['success' => true, 'message' => 'Listing updated.']);
    exit;
}

function audit_admin_crop(mysqli $conn, int $adminId, int $cropId, string $status): void {
    $stmt = $conn->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?,?,?,?)');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $d = "Admin set crop #$cropId to $status";
    $a = 'ADMIN_CROP_STATUS';
    $stmt->bind_param('isss', $adminId, $a, $d, $ip);
    $stmt->execute();
    $stmt->close();
}

// ── GET FARMER'S OWN CROPS ─────────────────────────────────
if ($action === 'my_crops') {
    requireLogin();
    $uid = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare(
        'SELECT c.*, COUNT(o.id) AS order_count FROM crops c
         LEFT JOIN orders o ON o.crop_id = c.id WHERE c.farmer_id = ? GROUP BY c.id ORDER BY c.created_at DESC'
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $crops = [];
    while ($row = $res->fetch_assoc()) {
        $crops[] = $row;
    }
    $stmt->close();
    echo json_encode(['success' => true, 'crops' => $crops]);
    exit;
}

// ── ADD CROP ───────────────────────────────────────────────
if ($action === 'add_crop') {
    requireLogin();
    if ($_SESSION['role'] !== 'farmer') {
        echo json_encode(['success' => false, 'message' => 'Farmers only.']);
        exit;
    }

    $farmer_id = (int) $_SESSION['user_id'];
    $name = sanitize($conn, $_POST['crop_name'] ?? '');
    $cat = sanitize($conn, $_POST['category'] ?? '');
    $desc = sanitize($conn, $_POST['description'] ?? '');
    $price = (float) ($_POST['price_per_unit'] ?? 0);
    $unit = sanitize($conn, $_POST['unit'] ?? 'kg');
    $qty = (float) ($_POST['quantity_available'] ?? 0);
    $harvest = sanitize($conn, $_POST['harvest_date'] ?? '');
    $location = sanitize($conn, $_POST['location'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['available', 'pre-order'], true) ? $_POST['status'] : 'available';

    if ($name === '' || $price <= 0 || $qty <= 0) {
        echo json_encode(['success' => false, 'message' => 'Crop name, price, and quantity are required.']);
        exit;
    }
    if ($cat === '' || !category_is_allowed($conn, $cat)) {
        echo json_encode(['success' => false, 'message' => 'Choose a valid category from the list.']);
        exit;
    }
    if (!unit_is_allowed($conn, $unit)) {
        echo json_encode(['success' => false, 'message' => 'Choose a valid unit of measure.']);
        exit;
    }

    $stmt = $conn->prepare(
        'INSERT INTO crops (farmer_id,crop_name,category,description,price_per_unit,unit,quantity_available,harvest_date,location,status) VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->bind_param('isssdsdsss', $farmer_id, $name, $cat, $desc, $price, $unit, $qty, $harvest, $location, $status);
    if ($stmt->execute()) {
        $newId = (int) $conn->insert_id;
        $stmt->close();
        log_event($conn, 'listing.created', $farmer_id, 'crop', $newId, ['name' => $name]);
        echo json_encode(['success' => true, 'message' => 'Crop listed successfully!', 'id' => $newId]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add crop.']);
    }
    exit;
}

// ── UPDATE CROP (farmer inventory / details) ───────────────
if ($action === 'update_crop') {
    requireLogin();
    if ($_SESSION['role'] !== 'farmer') {
        echo json_encode(['success' => false, 'message' => 'Farmers only.']);
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $uid = (int) $_SESSION['user_id'];

    if (isset($_POST['status'])) {
        $status = sanitize($conn, $_POST['status']);
        $allowed = ['available', 'pre-order', 'sold_out', 'inactive'];
        if (!in_array($status, $allowed, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status.']);
            exit;
        }
        $stmt = $conn->prepare('UPDATE crops SET status=? WHERE id=? AND farmer_id=?');
        $stmt->bind_param('sii', $status, $id, $uid);
        $stmt->execute();
        $stmt->close();
        log_event($conn, 'listing.status_changed', $uid, 'crop', $id, ['status' => $status]);
        echo json_encode(['success' => true, 'message' => 'Crop updated.']);
        exit;
    }

    $name = sanitize($conn, $_POST['crop_name'] ?? '');
    $cat = sanitize($conn, $_POST['category'] ?? '');
    $desc = sanitize($conn, $_POST['description'] ?? '');
    $price = (float) ($_POST['price_per_unit'] ?? 0);
    $unit = sanitize($conn, $_POST['unit'] ?? 'kg');
    $qty = (float) ($_POST['quantity_available'] ?? 0);
    $harvest = sanitize($conn, $_POST['harvest_date'] ?? '');
    $location = sanitize($conn, $_POST['location'] ?? '');

    if ($name === '' || $price <= 0 || $qty < 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid crop data.']);
        exit;
    }
    if ($cat === '' || !category_is_allowed($conn, $cat)) {
        echo json_encode(['success' => false, 'message' => 'Choose a valid category from the list.']);
        exit;
    }
    if (!unit_is_allowed($conn, $unit)) {
        echo json_encode(['success' => false, 'message' => 'Choose a valid unit of measure.']);
        exit;
    }

    $stmt = $conn->prepare(
        'UPDATE crops SET crop_name=?, category=?, description=?, price_per_unit=?, unit=?, quantity_available=?, harvest_date=?, location=? WHERE id=? AND farmer_id=?'
    );
    $stmt->bind_param('sssdsdssii', $name, $cat, $desc, $price, $unit, $qty, $harvest, $location, $id, $uid);
    $stmt->execute();
    $stmt->close();
    log_event($conn, 'listing.updated', $uid, 'crop', $id, []);
    echo json_encode(['success' => true, 'message' => 'Listing saved.']);
    exit;
}

// ── DELETE CROP ────────────────────────────────────────────
if ($action === 'delete_crop') {
    requireLogin();
    if ($_SESSION['role'] !== 'farmer') {
        echo json_encode(['success' => false, 'message' => 'Farmers only.']);
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $uid = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare('DELETE FROM crops WHERE id=? AND farmer_id=?');
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    $stmt->close();
    log_event($conn, 'listing.deleted', $uid, 'crop', $id, []);
    echo json_encode(['success' => true, 'message' => 'Crop deleted.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
