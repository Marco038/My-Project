<?php
function log_event(mysqli $conn, string $type, ?int $actorId, ?string $entityType, ?int $entityId, ?array $payload): void {
    $json = $payload ? json_encode($payload) : null;
    $actor = (int) ($actorId ?? 0);
    $etype = (string) ($entityType ?? '');
    $eid = (int) ($entityId ?? 0);
    $stmt = $conn->prepare('INSERT INTO event_logs (event_type, actor_user_id, entity_type, entity_id, payload_json) VALUES (?,?,?,?,?)');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('siiss', $type, $actor, $etype, $eid, $json);
    $stmt->execute();
    $stmt->close();
}
