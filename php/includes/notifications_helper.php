<?php
/**
 * Insert in-app notification for a user.
 */
function notify_user(mysqli $conn, int $userId, string $type, string $title, string $body, ?string $link = null): void {
    $linkVal = $link ?? '';
    $stmt = $conn->prepare('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?,?,?,?,?)');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('issss', $userId, $type, $title, $body, $linkVal);
    $stmt->execute();
    $stmt->close();
}
