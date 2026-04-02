<?php
// website_users.php - Compact & symmetrical layout + active users green highlight
include 'db.php';
include 'menu.php';

session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$website_id = (int)($_GET['website_id'] ?? 0);
if (!$website_id) die("Missing website_id");

$stmt = $pdo->prepare("SELECT * FROM Websites WHERE website_id = ?");
$stmt->execute([$website_id]);
$website = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$website) die("Website not found");

$users = $pdo->query("
    SELECT user_id, first_name, last_name, email, status 
    FROM Users 
    ORDER BY last_name, first_name
")->fetchAll(PDO::FETCH_ASSOC);

$current_perms = [];
$stmt = $pdo->prepare("SELECT user_id, permission_level, notes FROM Permissions WHERE website_id = ?");
$stmt->execute([$website_id]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $current_perms[$row['user_id']] = [
        'level' => $row['permission_level'],
        'notes' => $row['notes']
    ];
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $changes = [];

    foreach ($users as $user) {
        $uid = $user['user_id'];
        $new_level = $_POST["level_{$uid}"] ?? 'none';
        $new_notes = trim($_POST["notes_{$uid}"] ?? '');

        if ($new_level === 'none') {
            if (isset($current_perms[$uid])) {
                $changes[] = ['action' => 'delete', 'user_id' => $uid];
            }
            continue;
        }

        if (!in_array($new_level, ['admin', 'viewer'])) continue;

        $old = $current_perms[$uid] ?? ['level' => 'none', 'notes' => ''];
        if ($old['level'] === 'none') {
            $changes[] = ['action' => 'insert', 'user_id' => $uid, 'level' => $new_level, 'notes' => $new_notes];
        } elseif ($new_level !== $old['level'] || $new_notes !== $old['notes']) {
            $changes[] = ['action' => 'update', 'user_id' => $uid, 'level' => $new_level, 'notes' => $new_notes];
        }
    }

    // Apply changes
    foreach ($changes as $ch) {
        if ($ch['action'] === 'delete') {
            $pdo->prepare("DELETE FROM Permissions WHERE website_id = ? AND user_id = ?")
                ->execute([$website_id, $ch['user_id']]);
        } elseif ($ch['action'] === 'insert') {
            $pdo->prepare("INSERT INTO Permissions (user_id, website_id, permission_level, notes) VALUES (?, ?, ?, ?)")
                ->execute([$ch['user_id'], $website_id, $ch['level'], $ch['notes']]);
        } elseif ($ch['action'] === 'update') {
            $pdo->prepare("UPDATE Permissions SET permission_level = ?, notes = ? WHERE user_id = ? AND website_id = ?")
                ->execute([$ch['level'], $ch['notes'], $ch['user_id'], $website_id]);
        }
    }

    $success = "Permissions updated successfully for " . htmlspecialchars($website['website_name']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Users – <?= htmlspecialchars($website['website_name']) ?></title>
    <link rel="stylesheet" href="styles.css">
    <style>
    .compact-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13.5px;
    }
    .compact-table th, .compact-table td {
        padding: 9px 10px;
        border: 1px solid #ddd;
        vertical-align: middle;
    }
    .compact-table th {
        background: #f4f4f4;
        font-weight: 600;
        text-align: left;
    }
    /* NEW: Highlight rows where user HAS permission on THIS site */
    .compact-table tr.has-permission {
        background-color: #f0f8ff;      /* very light blue – "has access" */
        border-left: 4px solid #4dabf7; /* stronger left border for visibility */
    }
    .compact-table tr.has-permission:hover {
        background-color: #e3f2fd;
    }
    .compact-table tr:hover {
        background-color: #f8f9fa;
    }
    .status-badge {
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
    }
    .status-active    { background:#d4edda; color:#155724; }
    .status-terminated { background:#f8d7da; color:#721c24; }
    .status-suspended  { background:#fff3cd; color:#856404; }
    .status-contract   { background:#cce5ff; color:#004085; }
    .btn-save {
        padding: 10px 28px;
        font-size: 15px;
        background: #28a745;
        color: white;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        margin-top: 1.2em;
    }
</style>
</head>
<body>

<div class="content">

    <h1 style="margin-bottom: 0.6em;">Users for <?= htmlspecialchars($website['website_name']) ?></h1>

    <p style="margin: 0 0 1.5em;">
        <a href="websites.php">← Back to Websites</a> | 
        <a href="websites.php?edit=<?= $website_id ?>">Edit Website Details</a>
    </p>

    <?php if ($success): ?>
    <div style="background:#e6ffed; color:#0f5132; padding:10px 14px; border:1px solid #badbcc; border-radius:4px; margin-bottom:1.2em;">
        <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div style="background:#fee; color:#900; padding:10px 14px; border:1px solid #900; border-radius:4px; margin-bottom:1.2em;">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <table class="compact-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Email</th>
                    <th style="width:110px;">Status</th>
                    <th style="width:160px;">Permission Level</th>
                    <th>Notes (optional)</th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($users as $user): 
    $uid = $user['user_id'];
    $curr = $current_perms[$uid] ?? ['level' => 'none', 'notes' => ''];
    $has_permission = $curr['level'] !== 'none';
    $row_class = $has_permission ? 'has-permission' : '';
    $status_class = "status-{$user['status']}";
?>
    <tr class="<?= $row_class ?>">
        <td style="font-weight:<?= $has_permission ? '600' : '400' ?>">
            <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>
        </td>
        <td><?= htmlspecialchars($user['email']) ?></td>
        <td style="text-align:center;">
            <span class="status-badge <?= $status_class ?>">
                <?= ucfirst($user['status']) ?>
            </span>
        </td>
        <td style="text-align:center;">
            <select name="level_<?= $uid ?>" style="width:100%; padding:6px;">
                <option value="none"   <?= $curr['level'] === 'none'   ? 'selected' : '' ?>>None</option>
                <option value="admin"  <?= $curr['level'] === 'admin'  ? 'selected' : '' ?>>Admin</option>
                <option value="viewer" <?= $curr['level'] === 'viewer' ? 'selected' : '' ?>>Viewer</option>
            </select>
        </td>
        <td>
            <input type="text" name="notes_<?= $uid ?>"
                   value="<?= htmlspecialchars($curr['notes']) ?>"
                   placeholder="e.g. Dashboard only, ends 2025-12"
                   style="width:100%; padding:6px; box-sizing:border-box;">
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
        </table>

        <div style="text-align:center; margin-top:1.4em;">
            <button type="submit" name="save" class="btn-save">
                Save All Permissions
            </button>
        </div>
    </form>

</div>

</body>
</html>