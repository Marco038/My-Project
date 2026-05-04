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

// ── PLACE ORDER ────────────────────────────────────────────
if ($action === 'place_order') {
    requireLogin();
    if ($_SESSION['role'] !== 'buyer') {
        echo json_encode(['success' => false, 'message' => 'Buyers only.']);
        exit;
    }

    $buyer_id = (int) $_SESSION['user_id'];
    $crop_id = (int) ($_POST['crop_id'] ?? 0);
    $qty = (float) ($_POST['quantity'] ?? 0);
    $dtype = in_array($_POST['delivery_type'] ?? '', ['pickup', 'delivery'], true) ? $_POST['delivery_type'] : 'pickup';
    $daddr = sanitize($conn, $_POST['delivery_address'] ?? '');
    $notes = sanitize($conn, $_POST['notes'] ?? '');
    $otype = in_array($_POST['order_type'] ?? '', ['instant', 'pre-order'], true) ? $_POST['order_type'] : 'instant';

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM crops WHERE id=? AND status IN ('available','pre-order')");
        $stmt->bind_param('i', $crop_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 0) {
            $stmt->close();
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Crop not found or unavailable.']);
            exit;
        }
        $crop = $res->fetch_assoc();
        $stmt->close();

        $farmer_id = (int) $crop['farmer_id'];
        if ($farmer_id === $buyer_id) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'You cannot order from your own listing.']);
            exit;
        }

        if ($dtype === 'delivery' && trim($daddr) === '') {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Please enter a delivery address for door-to-door delivery.']);
            exit;
        }

        if ($qty <= 0 || $qty > (float) $crop['quantity_available']) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Invalid quantity for this listing.']);
            exit;
        }

        $total = round($qty * (float) $crop['price_per_unit'], 2);

        $ins = $conn->prepare(
            'INSERT INTO orders (buyer_id,farmer_id,crop_id,quantity,total_price,order_type,delivery_type,delivery_address,notes) VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $ins->bind_param('iiiddssss', $buyer_id, $farmer_id, $crop_id, $qty, $total, $otype, $dtype, $daddr, $notes);
        if (!$ins->execute()) {
            $ins->close();
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Order failed.']);
            exit;
        }
        $orderId = (int) $conn->insert_id;
        $ins->close();

        $new_qty = (float) $crop['quantity_available'] - $qty;
        $new_status = $new_qty <= 0 ? 'sold_out' : $crop['status'];
        $up = $conn->prepare('UPDATE crops SET quantity_available=?, status=? WHERE id=?');
        $up->bind_param('dsi', $new_qty, $new_status, $crop_id);
        $up->execute();
        $up->close();

        $conn->commit();

        if ($new_qty > 0 && $new_qty <= 10) {
            notify_user(
                $conn,
                $farmer_id,
                'inventory',
                'Low stock: ' . $crop['crop_name'],
                'Only ' . $new_qty . ' ' . $crop['unit'] . ' left after this order. Consider restocking or updating your listing.',
                'my-crops'
            );
            log_event($conn, 'inventory.low', $buyer_id, 'crop', $crop_id, ['qty' => $new_qty]);
        }

        notify_user($conn, $farmer_id, 'order', 'New order received', 'Order #' . $orderId . ' for ' . $crop['crop_name'], 'orders');
        notify_user($conn, $buyer_id, 'order', 'Order placed', 'Your order #' . $orderId . ' is pending farmer confirmation.', 'orders');
        log_event($conn, 'order.placed', $buyer_id, 'order', $orderId, ['crop_id' => $crop_id, 'qty' => $qty]);

        echo json_encode(['success' => true, 'message' => 'Order placed successfully!', 'total' => $total, 'order_id' => $orderId]);
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Order failed.']);
    }
    exit;
}

// ── GET MY ORDERS ──────────────────────────────────────────
if ($action === 'my_orders') {
    requireLogin();
    $uid = (int) $_SESSION['user_id'];
    $role = $_SESSION['role'];
    $col = $role === 'buyer' ? 'o.buyer_id' : 'o.farmer_id';

    $sql = "SELECT o.*, c.crop_name, c.unit, c.image,
            ub.full_name AS buyer_name, uf.full_name AS farmer_name
            FROM orders o
            JOIN crops c ON o.crop_id=c.id
            JOIN users ub ON o.buyer_id=ub.id
            JOIN users uf ON o.farmer_id=uf.id
            WHERE $col=? ORDER BY o.created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $orders = [];
    while ($row = $res->fetch_assoc()) {
        $orders[] = $row;
    }
    $stmt->close();
    echo json_encode(['success' => true, 'orders' => $orders]);
    exit;
}

// ── UPDATE ORDER STATUS (farmer) ───────────────────────────
if ($action === 'update_order') {
    requireLogin();
    if ($_SESSION['role'] !== 'farmer') {
        echo json_encode(['success' => false, 'message' => 'Farmers only.']);
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $status = sanitize($conn, $_POST['status'] ?? '');
    $uid = (int) $_SESSION['user_id'];
    $valid = ['confirmed', 'packed', 'in_transit', 'delivered', 'cancelled'];
    if (!in_array($status, $valid, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status.']);
        exit;
    }

    $stmt = $conn->prepare('SELECT id, buyer_id, status, total_price, crop_id, quantity FROM orders WHERE id=? AND farmer_id=?');
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    $o = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$o) {
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }

    $flow = ['pending' => ['confirmed', 'cancelled'], 'confirmed' => ['packed', 'cancelled'], 'packed' => ['in_transit', 'cancelled'], 'in_transit' => ['delivered', 'cancelled']];
    $cur = $o['status'];
    if (!isset($flow[$cur]) || !in_array($status, $flow[$cur], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status transition.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        $u = $conn->prepare('UPDATE orders SET status=? WHERE id=? AND farmer_id=?');
        $u->bind_param('sii', $status, $id, $uid);
        $u->execute();
        $u->close();

        if ($status === 'delivered') {
            $chk = $conn->prepare('SELECT id FROM transactions WHERE order_id=?');
            $chk->bind_param('i', $id);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                $chk->close();
                $buyerId = (int) $o['buyer_id'];
                $amt = (float) $o['total_price'];
                $t = $conn->prepare('INSERT INTO transactions (order_id,buyer_id,farmer_id,amount,status) VALUES (?,?,?,?,\'recorded\')');
                $t->bind_param('iiid', $id, $buyerId, $uid, $amt);
                $t->execute();
                $t->close();
            } else {
                $chk->close();
            }
        }

        if ($status === 'cancelled' && $cur === 'pending') {
            $qtyRestore = (float) $o['quantity'];
            $cid = (int) $o['crop_id'];
            $c = $conn->prepare(
                'UPDATE crops SET quantity_available = quantity_available + ?, status = IF(status = \'sold_out\' AND quantity_available + ? > 0, \'available\', status) WHERE id=?'
            );
            $c->bind_param('ddi', $qtyRestore, $qtyRestore, $cid);
            $c->execute();
            $c->close();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Update failed.']);
        exit;
    }

    $buyerId = (int) $o['buyer_id'];
    notify_user($conn, $buyerId, 'order', 'Order update', 'Order #' . $id . ' is now: ' . $status, 'orders');
    log_event($conn, 'order.status_changed', $uid, 'order', $id, ['status' => $status]);

    echo json_encode(['success' => true, 'message' => 'Order status updated.']);
    exit;
}

// ── BUYER: CANCEL PENDING ORDER ────────────────────────────
if ($action === 'cancel_order') {
    requireLogin();
    if ($_SESSION['role'] !== 'buyer') {
        echo json_encode(['success' => false, 'message' => 'Buyers only.']);
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $buyer = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare('SELECT o.*, c.quantity_available AS crop_qty_avail, c.id AS cid FROM orders o JOIN crops c ON o.crop_id=c.id WHERE o.id=? AND o.buyer_id=? AND o.status=\'pending\'');
    $stmt->bind_param('ii', $id, $buyer);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r->num_rows === 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Cannot cancel this order.']);
        exit;
    }
    $row = $r->fetch_assoc();
    $stmt->close();

    $conn->begin_transaction();
    try {
        $u = $conn->prepare('UPDATE orders SET status=\'cancelled\' WHERE id=?');
        $u->bind_param('i', $id);
        $u->execute();
        $u->close();
        $qtyRestore = (float) $row['quantity'];
        $cid = (int) $row['cid'];
        $c = $conn->prepare(
            'UPDATE crops SET quantity_available = quantity_available + ?, status = IF(status = \'sold_out\' AND quantity_available + ? > 0, \'available\', status) WHERE id=?'
        );
        $c->bind_param('ddi', $qtyRestore, $qtyRestore, $cid);
        $c->execute();
        $c->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Cancel failed.']);
        exit;
    }

    notify_user($conn, (int) $row['farmer_id'], 'order', 'Order cancelled', 'Order #' . $id . ' was cancelled by the buyer.', 'orders');
    log_event($conn, 'order.cancelled', $buyer, 'order', $id, []);
    echo json_encode(['success' => true, 'message' => 'Order cancelled.']);
    exit;
}

// ── GET ALL ORDERS (admin) ─────────────────────────────────
if ($action === 'all_orders') {
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Admin only.']);
        exit;
    }

    $sql = "SELECT o.*, c.crop_name, ub.full_name AS buyer_name, uf.full_name AS farmer_name
            FROM orders o
            JOIN crops c ON o.crop_id=c.id
            JOIN users ub ON o.buyer_id=ub.id
            JOIN users uf ON o.farmer_id=uf.id
            ORDER BY o.created_at DESC LIMIT 200";

    $res = $conn->query($sql);
    $orders = [];
    while ($row = $res->fetch_assoc()) {
        $orders[] = $row;
    }
    echo json_encode(['success' => true, 'orders' => $orders]);
    exit;
}

// ── MY TRANSACTIONS (buyer/farmer) ─────────────────────────
if ($action === 'my_transactions') {
    requireLogin();
    $uid = (int) $_SESSION['user_id'];
    $role = $_SESSION['role'];
    if ($role === 'admin') {
        echo json_encode(['success' => false, 'message' => 'Use admin reports.']);
        exit;
    }
    $col = $role === 'buyer' ? 'buyer_id' : 'farmer_id';
    $stmt = $conn->prepare(
        "SELECT t.*, c.crop_name FROM transactions t
         JOIN orders o ON t.order_id = o.id
         JOIN crops c ON o.crop_id = c.id
         WHERE t.$col = ? ORDER BY t.created_at DESC LIMIT 100"
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    echo json_encode(['success' => true, 'transactions' => $rows]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
