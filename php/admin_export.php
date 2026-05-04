<?php
/**
 * Admin-only CSV exports (Reports). Same session as SPA; opens in new tab.
 */
session_start();
require_once __DIR__ . '/includes/db.php';

header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

if (!touch_session()) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Session expired. Sign in again.';
    exit;
}

$type = preg_replace('/[^a-z_]/', '', $_GET['type'] ?? 'orders');
$allowed = ['orders', 'users', 'crops', 'transactions', 'visits'];
if (!in_array($type, $allowed, true)) {
    $type = 'orders';
}

$fname = 'bukid_' . $type . '_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

if ($type === 'orders') {
    fputcsv($out, ['id', 'buyer_id', 'farmer_id', 'crop_id', 'quantity', 'total_price', 'order_type', 'delivery_type', 'status', 'created_at', 'crop_name', 'farmer_name', 'buyer_name']);
    $sql = 'SELECT o.*, c.crop_name, uf.full_name AS farmer_name, ub.full_name AS buyer_name
            FROM orders o
            JOIN crops c ON o.crop_id = c.id
            JOIN users uf ON o.farmer_id = uf.id
            JOIN users ub ON o.buyer_id = ub.id
            ORDER BY o.created_at DESC';
    $res = $conn->query($sql);
    while ($row = $res->fetch_assoc()) {
        fputcsv($out, [
            $row['id'], $row['buyer_id'], $row['farmer_id'], $row['crop_id'],
            $row['quantity'], $row['total_price'], $row['order_type'], $row['delivery_type'],
            $row['status'], $row['created_at'], $row['crop_name'], $row['farmer_name'], $row['buyer_name'],
        ]);
    }
} elseif ($type === 'users') {
    fputcsv($out, ['id', 'username', 'email', 'role', 'full_name', 'phone', 'province', 'farm_name', 'gov_id_verified', 'email_verified', 'is_active', 'created_at']);
    $res = $conn->query('SELECT id,username,email,role,full_name,phone,province,farm_name,gov_id_verified,email_verified,is_active,created_at FROM users ORDER BY id');
    while ($row = $res->fetch_assoc()) {
        fputcsv($out, array_values($row));
    }
} elseif ($type === 'crops') {
    fputcsv($out, ['id', 'farmer_id', 'farmer_username', 'crop_name', 'category', 'price_per_unit', 'unit', 'quantity_available', 'status', 'location', 'created_at']);
    $res = $conn->query(
        'SELECT c.id, c.farmer_id, u.username AS farmer_username, c.crop_name, c.category, c.price_per_unit, c.unit,
                c.quantity_available, c.status, c.location, c.created_at
         FROM crops c JOIN users u ON c.farmer_id = u.id ORDER BY c.id'
    );
    while ($row = $res->fetch_assoc()) {
        fputcsv($out, array_values($row));
    }
} elseif ($type === 'transactions') {
    fputcsv($out, ['id', 'order_id', 'buyer_id', 'farmer_id', 'amount', 'currency', 'status', 'created_at']);
    $res = $conn->query('SELECT * FROM transactions ORDER BY created_at DESC');
    while ($row = $res->fetch_assoc()) {
        fputcsv($out, [$row['id'], $row['order_id'], $row['buyer_id'], $row['farmer_id'], $row['amount'], $row['currency'], $row['status'], $row['created_at']]);
    }
} elseif ($type === 'visits') {
    fputcsv($out, ['id', 'buyer_id', 'farmer_id', 'visit_date', 'visit_time', 'purpose', 'status', 'group_size', 'created_at', 'buyer_name', 'farmer_name']);
    $res = $conn->query(
        'SELECT fv.*, ub.full_name AS buyer_name, uf.full_name AS farmer_name
         FROM farm_visits fv
         JOIN users ub ON fv.buyer_id = ub.id
         JOIN users uf ON fv.farmer_id = uf.id
         ORDER BY fv.visit_date DESC, fv.visit_time DESC'
    );
    while ($row = $res->fetch_assoc()) {
        fputcsv($out, [
            $row['id'], $row['buyer_id'], $row['farmer_id'], $row['visit_date'], $row['visit_time'],
            $row['purpose'], $row['status'], $row['group_size'], $row['created_at'],
            $row['buyer_name'], $row['farmer_name'],
        ]);
    }
}

fclose($out);
