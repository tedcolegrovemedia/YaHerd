<?php
// Activity email notifications. Every send is guarded by the recipient's
// per-category preference (users.notify_* columns) and their active flag, and
// callers pass the actor id so we never notify someone about their own action.
// Password notices are security-critical and ignore the per-category prefs.

// category => the users column that toggles it (null = always send).
const NOTIFY_PREF_COLUMN = [
    'project_added' => 'notify_project_added',
    'assigned'      => 'notify_assigned',
    'reply'         => 'notify_replies',
    'status'        => 'notify_status',
];

const NOTIFY_STATUS_LABEL = [
    'queued' => 'Queued', 'working_on' => 'Working on', 'complete' => 'Complete',
];

// Notify a user: create an in-app inbox record (when a $link is given) and
// send the email (gated by the per-category preference). Callers must already
// have excluded the actor and any user they don't want notified.
function notify_user(array $u, string $category, string $subject, string $body, ?string $link = null): void {
    if (!(int)($u['is_active'] ?? 1)) return;

    // In-app notification for activity categories (those carrying a deep link).
    if ($link !== null) {
        try {
            db()->prepare('INSERT INTO notifications (user_id, category, message, link) VALUES (?, ?, ?, ?)')
                ->execute([(int)$u['id'], $category, mb_substr($subject, 0, 255), mb_substr($link, 0, 500)]);
        } catch (Throwable $e) {
            error_log('notification insert failed: ' . $e->getMessage());
        }
    }

    // Email, gated by the per-category preference (null => always-on).
    if (empty($u['email'])) return;
    $col = NOTIFY_PREF_COLUMN[$category] ?? null;
    if ($col !== null && (int)($u[$col] ?? 1) !== 1) return;
    $footer = "\n\n—\nManage your email notifications: " . app_base_url() . "/account\n";
    send_mail($u['email'], $u['display_name'] ?? '', $subject, $body . $footer);
}

// Unread in-app notification count for the avatar badge. Never throws.
function unread_notification_count(int $userId): int {
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;   // table may not exist yet (pre-migration)
    }
}

// Load full user rows (with pref columns) for a set of ids, de-duplicated.
function notify_fetch_users(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM users WHERE id IN ($in)");
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

function notify_task_excerpt(array $comment): string {
    return mb_strimwidth((string)$comment['body'], 0, 60, '…');
}

// The project a task belongs to, for notification context. fetch_comment_or_404
// joins project_name onto every comment row the task notifiers receive.
function notify_project_name(array $comment): string {
    $name = trim((string)($comment['project_name'] ?? ''));
    return $name !== '' ? $name : 'a project';
}

// ---- Account ----------------------------------------------------------------

// $newPassword set => admin reset (include it); null => self-service change.
function notify_password_changed(array $user, ?string $newPassword): void {
    $base = app_base_url();
    $app  = mail_from_name();
    $subject = "Your $app password was changed";
    if ($newPassword !== null) {
        $body = "Hi {$user['display_name']},\n\n"
              . "An administrator has reset your $app password.\n\n"
              . "Your new password:\n  $newPassword\n\n"
              . "Sign in: $base/login\n\n"
              . "For security, change it from the Account page after signing in. "
              . "If you didn't expect this, contact your administrator.";
    } else {
        $body = "Hi {$user['display_name']},\n\n"
              . "Your $app password was just changed.\n\n"
              . "If this wasn't you, contact your administrator immediately.\n\n"
              . "Sign in: $base/login";
    }
    notify_user($user, 'password', $subject, $body);   // 'password' => always-on
}

// ---- Projects ---------------------------------------------------------------

function notify_project_added(array $project, array $userIds, int $actorId): void {
    $base = app_base_url();
    $app  = mail_from_name();
    foreach (notify_fetch_users($userIds) as $u) {
        if ((int)$u['id'] === $actorId) continue;
        $subject = "You've been added to \"{$project['name']}\" on $app";
        $link = "/board?project=" . (int)$project['id'];
        $body = "Hi {$u['display_name']},\n\n"
              . "You now have access to the project \"{$project['name']}\" on $app.\n\n"
              . "Open its board:\n  $base$link\n";
        notify_user($u, 'project_added', $subject, $body, $link);
    }
}

// ---- Tasks ------------------------------------------------------------------

function notify_assigned(array $comment, array $assignee, int $actorId): void {
    if ((int)$assignee['id'] === $actorId) return;  // self-assign: no email
    $base = app_base_url();
    $tid  = (int)$comment['id'];
    $excerpt = notify_task_excerpt($comment);
    $proj = notify_project_name($comment);
    $link = "/task?id=$tid";
    $subject = "You were assigned task #$tid in \"$proj\"";
    $body = "Hi {$assignee['display_name']},\n\n"
          . "You've been assigned task #$tid (\"$excerpt\") in the project \"$proj\".\n\n"
          . "View it:\n  $base$link\n";
    notify_user($assignee, 'assigned', $subject, $body, $link);
}

function notify_reply(array $comment, string $replyBody, int $actorId): void {
    $base = app_base_url();
    $tid  = (int)$comment['id'];
    $ids  = [];
    if ($comment['author_id']   !== null) $ids[] = (int)$comment['author_id'];
    if ($comment['assignee_id'] !== null) $ids[] = (int)$comment['assignee_id'];
    $stmt = db()->prepare('SELECT DISTINCT author_id FROM comment_replies WHERE comment_id = ?');
    $stmt->execute([$tid]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $aid) {
        if ($aid !== null) $ids[] = (int)$aid;
    }
    $excerpt = notify_task_excerpt($comment);
    $proj = notify_project_name($comment);
    foreach (notify_fetch_users($ids) as $u) {
        if ((int)$u['id'] === $actorId) continue;
        $link = "/task?id=$tid";
        $subject = "New reply on task #$tid in \"$proj\"";
        $body = "Hi {$u['display_name']},\n\n"
              . "There's a new reply on task #$tid (\"$excerpt\") in \"$proj\":\n\n"
              . rtrim($replyBody) . "\n\n"
              . "View the task:\n  $base$link\n";
        notify_user($u, 'reply', $subject, $body, $link);
    }
}

function notify_status(array $comment, string $newStatus, int $actorId): void {
    $base  = app_base_url();
    $tid   = (int)$comment['id'];
    $label = NOTIFY_STATUS_LABEL[$newStatus] ?? $newStatus;
    $excerpt = notify_task_excerpt($comment);
    $proj = notify_project_name($comment);
    $ids = [];
    if ($comment['author_id']   !== null) $ids[] = (int)$comment['author_id'];
    if ($comment['assignee_id'] !== null) $ids[] = (int)$comment['assignee_id'];
    foreach (notify_fetch_users($ids) as $u) {
        if ((int)$u['id'] === $actorId) continue;
        $link = "/task?id=$tid";
        $subject = "Task #$tid in \"$proj\" is now \"$label\"";
        $body = "Hi {$u['display_name']},\n\n"
              . "Task #$tid (\"$excerpt\") in \"$proj\" was moved to \"$label\".\n\n"
              . "View the task:\n  $base$link\n";
        notify_user($u, 'status', $subject, $body, $link);
    }
}
