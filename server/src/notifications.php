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
    'mention'       => 'notify_mention',
];

const NOTIFY_STATUS_LABEL = [
    'queued' => 'Queued', 'working_on' => 'Working on', 'complete' => 'Complete',
];

// Notify a user: create an in-app inbox record (when a $link is given) and
// send the email (gated by the per-category preference). Callers must already
// have excluded the actor and any user they don't want notified.
function notify_user(array $u, string $category, string $subject, string $body, ?string $link = null, ?string $htmlInner = null): void {
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
    $manageUrl = app_base_url() . '/account';
    $footer = "\n\n—\nManage your email notifications: " . $manageUrl . "\n";
    $html = $htmlInner === null ? null : email_layout(
        $htmlInner,
        'Manage your email notifications in your <a href="' . email_esc($manageUrl)
        . '" style="color:#2262e4;text-decoration:none;">account settings</a>.'
    );
    send_mail($u['email'], $u['display_name'] ?? '', $subject, $body . $footer, $html);
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
        $blocks = [
            ['text', "An administrator has reset your $app password."],
            ['fields', ['New password' => $newPassword]],
            ['button', 'Sign in', "$base/login"],
            ['note', "For security, change it from the Account page after signing in. "
                   . "If you didn't expect this, contact your administrator."],
        ];
    } else {
        $blocks = [
            ['text', "Your $app password was just changed."],
            ['note', "If this wasn't you, contact your administrator immediately."],
            ['button', 'Sign in', "$base/login"],
        ];
    }
    ['text' => $body, 'html' => $inner] = email_render($user['display_name'], $blocks);
    notify_user($user, 'password', $subject, $body, null, $inner);   // 'password' => always-on
}

// ---- Projects ---------------------------------------------------------------

function notify_project_added(array $project, array $userIds, int $actorId): void {
    $base = app_base_url();
    $app  = mail_from_name();
    foreach (notify_fetch_users($userIds) as $u) {
        if ((int)$u['id'] === $actorId) continue;
        $subject = "You've been added to \"{$project['name']}\" on $app";
        $link = "/board?project=" . (int)$project['id'];
        ['text' => $body, 'html' => $inner] = email_render($u['display_name'], [
            ['text', "You now have access to the project \"{$project['name']}\" on $app."],
            ['button', 'Open project board', "$base$link"],
        ]);
        notify_user($u, 'project_added', $subject, $body, $link, $inner);
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
    ['text' => $body, 'html' => $inner] = email_render($assignee['display_name'], [
        ['text', "You've been assigned task #$tid (\"$excerpt\") in the project \"$proj\"."],
        ['button', 'View task', "$base$link"],
    ]);
    notify_user($assignee, 'assigned', $subject, $body, $link, $inner);
}

// $excludeIds: users already notified about this reply another way (i.e. those
// @mentioned in it) — they get the mention notice instead of a duplicate reply.
function notify_reply(array $comment, string $replyBody, int $actorId, array $excludeIds = []): void {
    $base = app_base_url();
    $tid  = (int)$comment['id'];
    $exclude = array_flip(array_map('intval', $excludeIds));
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
        if (isset($exclude[(int)$u['id']])) continue;
        $link = "/task?id=$tid";
        $subject = "New reply on task #$tid in \"$proj\"";
        ['text' => $body, 'html' => $inner] = email_render($u['display_name'], [
            ['text', "There's a new reply on task #$tid (\"$excerpt\") in \"$proj\":"],
            ['quote', rtrim($replyBody)],
            ['button', 'View the task', "$base$link"],
        ]);
        notify_user($u, 'reply', $subject, $body, $link, $inner);
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
        ['text' => $body, 'html' => $inner] = email_render($u['display_name'], [
            ['text', "Task #$tid (\"$excerpt\") in \"$proj\" was moved to \"$label\"."],
            ['button', 'View the task', "$base$link"],
        ]);
        notify_user($u, 'status', $subject, $body, $link, $inner);
    }
}

// $mentioned: rows of {id, display_name} produced by parse_mentions().
// $actorName is the person who wrote the reply, for the message.
function notify_mention(array $comment, array $mentioned, string $replyBody, int $actorId, string $actorName): void {
    if (!$mentioned) return;
    $base = app_base_url();
    $tid  = (int)$comment['id'];
    $proj = notify_project_name($comment);
    $link = "/task?id=$tid";
    $ids  = array_map(fn($m) => (int)$m['id'], $mentioned);
    foreach (notify_fetch_users($ids) as $u) {
        if ((int)$u['id'] === $actorId) continue;   // no self-mention notice
        $subject = "$actorName mentioned you on task #$tid in \"$proj\"";
        ['text' => $body, 'html' => $inner] = email_render($u['display_name'], [
            ['text', "$actorName mentioned you in a reply on task #$tid in \"$proj\":"],
            ['quote', rtrim($replyBody)],
            ['button', 'View the task', "$base$link"],
        ]);
        notify_user($u, 'mention', $subject, $body, $link, $inner);
    }
}
