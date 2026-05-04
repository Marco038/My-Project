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

function requireLogin(): void {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Admin only.']);
        exit;
    }
}

// ── PUBLIC LANDING STATS (no auth) ────────────────────────────
if ($action === 'public_stats') {
    $q = static function (mysqli $conn, string $sql): int {
        $r = $conn->query($sql);
        if (!$r) {
            return 0;
        }
        $row = $r->fetch_assoc();

        return (int) ($row['c'] ?? $row['t'] ?? 0);
    };
    $qf = static function (mysqli $conn, string $sql): float {
        $r = $conn->query($sql);
        if (!$r) {
            return 0.0;
        }
        $row = $r->fetch_assoc();

        return (float) ($row['t'] ?? 0);
    };

    $farmers = $q($conn, "SELECT COUNT(*) AS c FROM users WHERE role='farmer' AND is_active=1");
    $buyers = $q($conn, "SELECT COUNT(*) AS c FROM users WHERE role='buyer' AND is_active=1");
    $listings = $q(
        $conn,
        "SELECT COUNT(*) AS c FROM crops c JOIN users u ON c.farmer_id=u.id
         WHERE c.status IN ('available','pre-order') AND u.is_active=1"
    );
    $completed = $q($conn, "SELECT COUNT(*) AS c FROM orders WHERE status='delivered'");
    $revenue = $qf($conn, "SELECT IFNULL(SUM(total_price),0) AS t FROM orders WHERE status='delivered'");

    echo json_encode([
        'success' => true,
        'farmers' => $farmers,
        'buyers' => $buyers,
        'listings' => $listings,
        'completed_orders' => $completed,
        'revenue_delivered' => $revenue,
    ]);
    exit;
}

// ── SCHEDULE VISIT ─────────────────────────────────────────
if ($action === 'schedule_visit') {
    requireLogin();
    if ($_SESSION['role'] !== 'buyer') {
        echo json_encode(['success' => false, 'message' => 'Buyers only.']);
        exit;
    }
    $buyer_id = (int) $_SESSION['user_id'];
    $farmer_id = (int) ($_POST['farmer_id'] ?? 0);
    $date = sanitize($conn, $_POST['visit_date'] ?? '');
    $time = sanitize($conn, $_POST['visit_time'] ?? '');
    $purpose = sanitize($conn, $_POST['purpose'] ?? '');
    $group = (int) ($_POST['group_size'] ?? 1);
    $notes = sanitize($conn, $_POST['notes'] ?? '');

    if ($farmer_id < 1 || $date === '' || $time === '') {
        echo json_encode(['success' => false, 'message' => 'Farmer, date and time are required.']);
        exit;
    }

    if (strtotime($date) < strtotime('today')) {
        echo json_encode(['success' => false, 'message' => 'Visit date must be today or in the future.']);
        exit;
    }

    $chk = $conn->prepare("SELECT id FROM farm_visits WHERE farmer_id=? AND visit_date=? AND visit_time=? AND status='approved'");
    $chk->bind_param('iss', $farmer_id, $date, $time);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $chk->close();
        echo json_encode(['success' => false, 'message' => 'That time slot is already booked.']);
        exit;
    }
    $chk->close();

    $stmt = $conn->prepare('INSERT INTO farm_visits (buyer_id,farmer_id,visit_date,visit_time,purpose,group_size,notes) VALUES (?,?,?,?,?,?,?)');
    $stmt->bind_param('iisssis', $buyer_id, $farmer_id, $date, $time, $purpose, $group, $notes);
    if ($stmt->execute()) {
        $vid = (int) $conn->insert_id;
        $stmt->close();
        notify_user($conn, $farmer_id, 'visit', 'Farm visit request', 'A buyer requested a visit on ' . $date, 'visits');
        log_event($conn, 'visit.requested', $buyer_id, 'visit', $vid, ['farmer_id' => $farmer_id]);
        echo json_encode(['success' => true, 'message' => 'Visit request sent! Waiting for farmer approval.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to schedule visit.']);
    }
    exit;
}

// ── MY VISITS ──────────────────────────────────────────────
if ($action === 'my_visits') {
    requireLogin();
    $uid = (int) $_SESSION['user_id'];
    $role = $_SESSION['role'];
    $col = $role === 'buyer' ? 'fv.buyer_id' : 'fv.farmer_id';

    $sql = "SELECT fv.*, ub.full_name AS buyer_name, uf.full_name AS farmer_name, uf.address AS farm_address
            FROM farm_visits fv
            JOIN users ub ON fv.buyer_id=ub.id
            JOIN users uf ON fv.farmer_id=uf.id
            WHERE $col=? ORDER BY fv.visit_date DESC, fv.visit_time DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $visits = [];
    while ($row = $res->fetch_assoc()) {
        $visits[] = $row;
    }
    $stmt->close();
    echo json_encode(['success' => true, 'visits' => $visits]);
    exit;
}

// ── UPDATE VISIT STATUS ────────────────────────────────────
if ($action === 'update_visit') {
    requireLogin();
    if ($_SESSION['role'] !== 'farmer') {
        echo json_encode(['success' => false, 'message' => 'Farmers only.']);
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $status = sanitize($conn, $_POST['status'] ?? '');
    $uid = (int) $_SESSION['user_id'];
    $valid = ['approved', 'declined', 'rescheduled', 'completed'];
    if (!in_array($status, $valid, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status.']);
        exit;
    }
    $stmt = $conn->prepare('UPDATE farm_visits SET status=? WHERE id=? AND farmer_id=?');
    $stmt->bind_param('sii', $status, $id, $uid);
    $stmt->execute();
    $stmt->close();

    $q = $conn->prepare('SELECT buyer_id FROM farm_visits WHERE id=? AND farmer_id=?');
    $q->bind_param('ii', $id, $uid);
    $q->execute();
    $bid = (int) ($q->get_result()->fetch_assoc()['buyer_id'] ?? 0);
    $q->close();
    if ($bid) {
        notify_user($conn, $bid, 'visit', 'Visit ' . $status, 'Your farm visit request #' . $id . ' is ' . $status . '.', 'visits');
    }
    log_event($conn, 'visit.status_changed', $uid, 'visit', $id, ['status' => $status]);
    echo json_encode(['success' => true, 'message' => 'Visit status updated.']);
    exit;
}

// ── SEND MESSAGE ───────────────────────────────────────────
if ($action === 'send_message') {
    requireLogin();
    $sender_id = (int) $_SESSION['user_id'];
    $receiver_id = (int) ($_POST['receiver_id'] ?? 0);
    $message = sanitize($conn, $_POST['message'] ?? '');

    if ($receiver_id < 1 || $message === '') {
        echo json_encode(['success' => false, 'message' => 'Receiver and message required.']);
        exit;
    }

    $stmt = $conn->prepare('INSERT INTO messages (sender_id,receiver_id,message) VALUES (?,?,?)');
    $stmt->bind_param('iis', $sender_id, $receiver_id, $message);
    if ($stmt->execute()) {
        $stmt->close();
        notify_user($conn, $receiver_id, 'message', 'New message', substr($message, 0, 120), 'messages');
        log_event($conn, 'message.sent', $sender_id, 'message', $receiver_id, []);
        echo json_encode(['success' => true, 'message' => 'Message sent.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send.']);
    }
    exit;
}

// ── CONVERSATION PARTNERS ───────────────────────────────────
if ($action === 'get_conversations') {
    requireLogin();
    $uid = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare(
        'SELECT DISTINCT CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS oid
         FROM messages WHERE sender_id = ? OR receiver_id = ?'
    );
    $stmt->bind_param('iii', $uid, $uid, $uid);
    $stmt->execute();
    $ids = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        if ((int) $row['oid'] !== $uid) {
            $ids[] = (int) $row['oid'];
        }
    }
    $stmt->close();

    $conversations = [];
    foreach ($ids as $oid) {
        $u = $conn->prepare('SELECT id, full_name, username, role FROM users WHERE id=?');
        $u->bind_param('i', $oid);
        $u->execute();
        $person = $u->get_result()->fetch_assoc();
        $u->close();
        if (!$person) {
            continue;
        }
        $m = $conn->prepare(
            'SELECT message, created_at, sender_id FROM messages
             WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)
             ORDER BY created_at DESC LIMIT 1'
        );
        $m->bind_param('iiii', $uid, $oid, $oid, $uid);
        $m->execute();
        $last = $m->get_result()->fetch_assoc();
        $m->close();
        $conversations[] = [
            'user' => $person,
            'preview' => $last['message'] ?? '',
            'last_at' => $last['created_at'] ?? null,
            'last_from_me' => isset($last['sender_id']) && (int) $last['sender_id'] === $uid,
        ];
    }
    usort($conversations, function ($a, $b) {
        return strcmp($b['last_at'] ?? '', $a['last_at'] ?? '');
    });
    echo json_encode(['success' => true, 'conversations' => $conversations]);
    exit;
}

// ── GET MESSAGES ───────────────────────────────────────────
if ($action === 'get_messages') {
    requireLogin();
    $uid = (int) $_SESSION['user_id'];
    $other_id = (int) ($_GET['with'] ?? 0);

    if ($other_id > 0) {
        $mark = $conn->prepare('UPDATE messages SET is_read=1 WHERE receiver_id=? AND sender_id=?');
        $mark->bind_param('ii', $uid, $other_id);
        $mark->execute();
        $mark->close();

        $stmt = $conn->prepare(
            'SELECT m.*, us.full_name AS sender_name, ur.full_name AS receiver_name
             FROM messages m
             JOIN users us ON m.sender_id=us.id
             JOIN users ur ON m.receiver_id=ur.id
             WHERE (m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?)
             ORDER BY m.created_at ASC'
        );
        $stmt->bind_param('iiii', $uid, $other_id, $other_id, $uid);
    } else {
        $stmt = $conn->prepare(
            'SELECT m.*, us.full_name AS sender_name, ur.full_name AS receiver_name
             FROM messages m
             JOIN users us ON m.sender_id=us.id
             JOIN users ur ON m.receiver_id=ur.id
             WHERE m.sender_id=? OR m.receiver_id=?
             ORDER BY m.created_at DESC LIMIT 200'
        );
        $stmt->bind_param('ii', $uid, $uid);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $msgs = [];
    while ($row = $res->fetch_assoc()) {
        $msgs[] = $row;
    }
    $stmt->close();
    echo json_encode(['success' => true, 'messages' => $msgs]);
    exit;
}

// ── NOTIFICATIONS ──────────────────────────────────────────
if ($action === 'get_notifications') {
    requireLogin();
    $uid = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 100');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    $c = $conn->prepare('SELECT COUNT(*) AS n FROM notifications WHERE user_id=? AND is_read=0');
    $c->bind_param('i', $uid);
    $c->execute();
    $unread = (int) ($c->get_result()->fetch_assoc()['n'] ?? 0);
    $c->close();
    echo json_encode(['success' => true, 'notifications' => $rows, 'unread_count' => (int) $unread]);
    exit;
}

if ($action === 'mark_notification_read') {
    requireLogin();
    $nid = (int) ($_POST['id'] ?? 0);
    $uid = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?');
    $stmt->bind_param('ii', $nid, $uid);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'mark_all_notifications_read') {
    requireLogin();
    $uid = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// ── FAVORITES (buyer) ──────────────────────────────────────
if ($action === 'add_favorite') {
    requireLogin();
    if ($_SESSION['role'] !== 'buyer') {
        echo json_encode(['success' => false, 'message' => 'Buyers only.']);
        exit;
    }
    $buyer = (int) $_SESSION['user_id'];
    $farmer = (int) ($_POST['farmer_id'] ?? 0);
    $chk = $conn->prepare('SELECT id FROM users WHERE id=? AND role=\'farmer\'');
    $chk->bind_param('i', $farmer);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        $chk->close();
        echo json_encode(['success' => false, 'message' => 'Invalid farmer.']);
        exit;
    }
    $chk->close();
    $stmt = $conn->prepare('INSERT IGNORE INTO favorites (buyer_id, farmer_id) VALUES (?,?)');
    $stmt->bind_param('ii', $buyer, $farmer);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'message' => 'Added to favorites.']);
    exit;
}

if ($action === 'remove_favorite') {
    requireLogin();
    $buyer = (int) $_SESSION['user_id'];
    $farmer = (int) ($_POST['farmer_id'] ?? 0);
    $stmt = $conn->prepare('DELETE FROM favorites WHERE buyer_id=? AND farmer_id=?');
    $stmt->bind_param('ii', $buyer, $farmer);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'message' => 'Removed from favorites.']);
    exit;
}

if ($action === 'list_favorites') {
    requireLogin();
    if ($_SESSION['role'] !== 'buyer') {
        echo json_encode(['success' => false, 'message' => 'Buyers only.']);
        exit;
    }
    $buyer = (int) $_SESSION['user_id'];
    $sql = "SELECT u.id, u.full_name, u.username, u.address, u.gov_id_verified, u.province,
            IFNULL(ROUND(AVG(r.rating),1),0) AS avg_rating
            FROM favorites f
            JOIN users u ON f.farmer_id = u.id
            LEFT JOIN ratings r ON r.farmer_id = u.id
            WHERE f.buyer_id = ?
            GROUP BY u.id ORDER BY f.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $buyer);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    echo json_encode(['success' => true, 'favorites' => $rows]);
    exit;
}

// ── POST ALERT (admin) + fan-out notifications ─────────────
if ($action === 'post_alert') {
    requireAdmin();
    $admin_id = (int) $_SESSION['user_id'];
    $title = sanitize($conn, $_POST['title'] ?? '');
    $message = sanitize($conn, $_POST['message'] ?? '');
    $type = in_array($_POST['type'] ?? '', ['weather', 'pest', 'market', 'general'], true) ? $_POST['type'] : 'general';
    $targetProvince = trim(sanitize($conn, $_POST['target_province'] ?? ''));

    if ($title === '' || $message === '') {
        echo json_encode(['success' => false, 'message' => 'Title and message required.']);
        exit;
    }

    $tp = $targetProvince === '' ? null : $targetProvince;
    if ($tp === null) {
        $stmt = $conn->prepare('INSERT INTO alerts (admin_id,title,message,type,target_province) VALUES (?,?,?,?,NULL)');
        $stmt->bind_param('isss', $admin_id, $title, $message, $type);
    } else {
        $stmt = $conn->prepare('INSERT INTO alerts (admin_id,title,message,type,target_province) VALUES (?,?,?,?,?)');
        $stmt->bind_param('issss', $admin_id, $title, $message, $type, $tp);
    }
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Failed to post alert.']);
        exit;
    }
    $stmt->close();

    if ($tp === null) {
        $users = $conn->query("SELECT id FROM users WHERE is_active=1 AND role IN ('farmer','buyer')");
    } else {
        $pst = $conn->prepare("SELECT id FROM users WHERE is_active=1 AND role IN ('farmer','buyer') AND LOWER(TRIM(IFNULL(province,''))) = LOWER(?)");
        $pst->bind_param('s', $targetProvince);
        $pst->execute();
        $users = $pst->get_result();
    }
    $notified = 0;
    while ($u = $users->fetch_assoc()) {
        notify_user($conn, (int) $u['id'], 'alert', $title, $message, 'alerts');
        ++$notified;
    }
    if (isset($pst)) {
        $pst->close();
    }
    log_event($conn, 'alert.broadcast', $admin_id, 'alert', null, ['type' => $type, 'target_province' => $tp]);
    echo json_encode([
        'success' => true,
        'message' => $tp === null ? 'Alert broadcast to all users.' : ('Alert sent to ' . $notified . ' users in ' . $targetProvince . '.'),
    ]);
    exit;
}

// ── GET ALERTS (scoped: national + user province) ──────────
if ($action === 'get_alerts') {
    requireLogin();
    $uid = (int) $_SESSION['user_id'];
    $st = $conn->prepare('SELECT TRIM(IFNULL(province, \'\')) AS p FROM users WHERE id=?');
    $st->bind_param('i', $uid);
    $st->execute();
    $provRow = $st->get_result()->fetch_assoc();
    $st->close();
    $prov = trim((string) ($provRow['p'] ?? ''));
    $provMatch = $prov;

    $sql = 'SELECT a.*, u.full_name AS admin_name FROM alerts a
            JOIN users u ON a.admin_id=u.id
            WHERE (a.target_province IS NULL OR TRIM(a.target_province) = \'\')
               OR (? <> \'\' AND LOWER(TRIM(a.target_province)) = LOWER(?))
            ORDER BY a.created_at DESC LIMIT 40';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $prov, $provMatch);
    $stmt->execute();
    $res = $stmt->get_result();
    $alerts = [];
    while ($row = $res->fetch_assoc()) {
        $alerts[] = $row;
    }
    $stmt->close();
    echo json_encode(['success' => true, 'alerts' => $alerts]);
    exit;
}

// ── ADMIN STATS ────────────────────────────────────────────
if ($action === 'admin_stats') {
    requireAdmin();
    $farmers = (int) $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='farmer'")->fetch_assoc()['c'];
    $buyers = (int) $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='buyer'")->fetch_assoc()['c'];
    $crops = (int) $conn->query("SELECT COUNT(*) AS c FROM crops WHERE status IN ('available','pre-order')")->fetch_assoc()['c'];
    $orders = (int) $conn->query('SELECT COUNT(*) AS c FROM orders')->fetch_assoc()['c'];
    $revenue = (float) $conn->query("SELECT IFNULL(SUM(total_price),0) AS t FROM orders WHERE status='delivered'")->fetch_assoc()['t'];
    $visits = (int) $conn->query('SELECT COUNT(*) AS c FROM farm_visits')->fetch_assoc()['c'];
    $pendingFarmers = (int) $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='farmer' AND gov_id_verified=0")->fetch_assoc()['c'];
    $txn = (int) $conn->query('SELECT COUNT(*) AS c FROM transactions')->fetch_assoc()['c'];

    echo json_encode([
        'success' => true,
        'stats' => compact('farmers', 'buyers', 'crops', 'orders', 'revenue', 'visits', 'pendingFarmers', 'txn'),
    ]);
    exit;
}

// ── ADMIN ANALYTICS (platform) ───────────────────────────────
if ($action === 'admin_analytics') {
    requireAdmin();
    $farmers = (int) $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='farmer'")->fetch_assoc()['c'];
    $buyers = (int) $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='buyer'")->fetch_assoc()['c'];
    $crops = (int) $conn->query("SELECT COUNT(*) AS c FROM crops WHERE status IN ('available','pre-order')")->fetch_assoc()['c'];
    $orders = (int) $conn->query('SELECT COUNT(*) AS c FROM orders')->fetch_assoc()['c'];
    $revenue = (float) $conn->query("SELECT IFNULL(SUM(total_price),0) AS t FROM orders WHERE status='delivered'")->fetch_assoc()['t'];
    $visits = (int) $conn->query('SELECT COUNT(*) AS c FROM farm_visits')->fetch_assoc()['c'];
    $pendingFarmers = (int) $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='farmer' AND gov_id_verified=0")->fetch_assoc()['c'];
    $txn = (int) $conn->query('SELECT COUNT(*) AS c FROM transactions')->fetch_assoc()['c'];
    $stats = compact('farmers', 'buyers', 'crops', 'orders', 'revenue', 'visits', 'pendingFarmers', 'txn');

    $ordersByStatus = [];
    $q = $conn->query('SELECT status, COUNT(*) AS c FROM orders GROUP BY status');
    while ($row = $q->fetch_assoc()) {
        $ordersByStatus[$row['status']] = (int) $row['c'];
    }

    $ordersByMonth = [];
    $q2 = $conn->query(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
                COUNT(*) AS cnt,
                IFNULL(SUM(CASE WHEN status='delivered' THEN total_price ELSE 0 END),0) AS rev
         FROM orders
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
         GROUP BY ym ORDER BY ym ASC"
    );
    while ($row = $q2->fetch_assoc()) {
        $ordersByMonth[] = [
            'month' => $row['ym'],
            'orders' => (int) $row['cnt'],
            'revenue' => (float) $row['rev'],
        ];
    }

    $topCategories = [];
    $q3 = $conn->query(
        "SELECT IFNULL(NULLIF(TRIM(c.category),''), 'Uncategorized') AS cat,
                COUNT(o.id) AS order_count,
                IFNULL(SUM(CASE WHEN o.status='delivered' THEN o.total_price ELSE 0 END),0) AS rev
         FROM orders o
         JOIN crops c ON o.crop_id = c.id
         GROUP BY cat
         ORDER BY rev DESC
         LIMIT 12"
    );
    while ($row = $q3->fetch_assoc()) {
        $topCategories[] = [
            'category' => $row['cat'],
            'orders' => (int) $row['order_count'],
            'revenue' => (float) $row['rev'],
        ];
    }

    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'orders_by_status' => $ordersByStatus,
        'orders_by_month' => $ordersByMonth,
        'top_categories' => $topCategories,
    ]);
    exit;
}

// ── FARMER ANALYTICS ────────────────────────────────────────
if ($action === 'farmer_analytics') {
    requireLogin();
    if ($_SESSION['role'] !== 'farmer') {
        echo json_encode(['success' => false, 'message' => 'Farmers only.']);
        exit;
    }
    $uid = (int) $_SESSION['user_id'];
    $earnings = (float) $conn->query(
        "SELECT IFNULL(SUM(t.amount),0) AS e FROM transactions t WHERE t.farmer_id=$uid"
    )->fetch_assoc()['e'];
    $ordersByStatus = [];
    $q = $conn->query(
        "SELECT status, COUNT(*) AS c FROM orders WHERE farmer_id=$uid GROUP BY status"
    );
    while ($row = $q->fetch_assoc()) {
        $ordersByStatus[$row['status']] = (int) $row['c'];
    }
    $topCrops = [];
    $q2 = $conn->query(
        "SELECT c.crop_name, SUM(o.quantity) AS sold FROM orders o JOIN crops c ON o.crop_id=c.id
         WHERE o.farmer_id=$uid AND o.status='delivered' GROUP BY c.id ORDER BY sold DESC LIMIT 5"
    );
    while ($row = $q2->fetch_assoc()) {
        $topCrops[] = $row;
    }
    echo json_encode(['success' => true, 'earnings_total' => $earnings, 'orders_by_status' => $ordersByStatus, 'top_crops' => $topCrops]);
    exit;
}

// ── ALL USERS (admin) ───────────────────────────────────────
if ($action === 'all_users') {
    requireAdmin();
    $res = $conn->query('SELECT id,username,full_name,email,role,is_active,gov_id_verified,email_verified,created_at,province FROM users ORDER BY created_at DESC');
    $users = [];
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
    }
    echo json_encode(['success' => true, 'users' => $users]);
    exit;
}

// ── TOGGLE USER ACTIVE ─────────────────────────────────────
if ($action === 'toggle_user') {
    requireAdmin();
    $id = (int) ($_POST['id'] ?? 0);
    $active = (int) ($_POST['is_active'] ?? 0);
    $stmt = $conn->prepare('UPDATE users SET is_active=? WHERE id=? AND role <> \'admin\'');
    $stmt->bind_param('ii', $active, $id);
    $stmt->execute();
    $stmt->close();
    log_event($conn, 'admin.user_toggled', (int) $_SESSION['user_id'], 'user', $id, ['is_active' => $active]);
    echo json_encode(['success' => true, 'message' => 'User status updated.']);
    exit;
}

// ── VERIFY FARMER (admin) ────────────────────────────────────
if ($action === 'verify_farmer') {
    requireAdmin();
    $id = (int) ($_POST['id'] ?? 0);
    $verified = (int) ($_POST['verified'] ?? 1);
    $stmt = $conn->prepare('UPDATE users SET gov_id_verified=? WHERE id=? AND role=\'farmer\'');
    $stmt->bind_param('ii', $verified, $id);
    $stmt->execute();
    $stmt->close();
    notify_user($conn, $id, 'system', 'Verification update', $verified ? 'Your farmer account is verified.' : 'Your verification was revoked.', 'settings');
    log_event($conn, 'admin.farmer_verified', (int) $_SESSION['user_id'], 'user', $id, ['verified' => $verified]);
    echo json_encode(['success' => true, 'message' => 'Farmer verification updated.']);
    exit;
}

// ── AUDIT LOGS (admin) ─────────────────────────────────────
if ($action === 'audit_logs') {
    requireAdmin();
    $res = $conn->query('SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON a.user_id=u.id ORDER BY a.created_at DESC LIMIT 200');
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    echo json_encode(['success' => true, 'logs' => $rows]);
    exit;
}

// ── EVENT LOGS (admin) ─────────────────────────────────────
if ($action === 'event_logs') {
    requireAdmin();
    $res = $conn->query('SELECT * FROM event_logs ORDER BY created_at DESC LIMIT 150');
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    echo json_encode(['success' => true, 'events' => $rows]);
    exit;
}

// ── SUBMIT RATING ──────────────────────────────────────────
if ($action === 'submit_rating') {
    requireLogin();
    if ($_SESSION['role'] !== 'buyer') {
        echo json_encode(['success' => false, 'message' => 'Buyers only.']);
        exit;
    }
    $buyer_id = (int) $_SESSION['user_id'];
    $farmer_id = (int) ($_POST['farmer_id'] ?? 0);
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $rating = (int) ($_POST['rating'] ?? 0);
    $review = sanitize($conn, $_POST['review'] ?? '');

    if ($rating < 1 || $rating > 5) {
        echo json_encode(['success' => false, 'message' => 'Rating must be 1-5.']);
        exit;
    }

    $stmt = $conn->prepare('INSERT INTO ratings (buyer_id,farmer_id,order_id,rating,review) VALUES (?,?,?,?,?)');
    $stmt->bind_param('iiiis', $buyer_id, $farmer_id, $order_id, $rating, $review);
    if ($stmt->execute()) {
        $stmt->close();
        notify_user($conn, $farmer_id, 'review', 'New review', 'You received a ' . $rating . '-star rating.', 'orders');
        log_event($conn, 'rating.submitted', $buyer_id, 'order', $order_id, ['rating' => $rating]);
        echo json_encode(['success' => true, 'message' => 'Rating submitted!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
