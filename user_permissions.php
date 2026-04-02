<?php
include 'db.php';
include 'menu.php';

session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) {
    die("Missing or invalid user_id");
}

$stmt = $pdo->prepare("SELECT * FROM Users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    die("User not found");
}

// Fetch websites with their description
$websites = $pdo->query("
    SELECT website_id, website_name, website_description 
    FROM Websites 
    ORDER BY website_name
")->fetchAll(PDO::FETCH_ASSOC);

$current_perms = [];
$stmt = $pdo->prepare("SELECT website_id, permission_level, notes FROM Permissions WHERE user_id = ?");
$stmt->execute([$user_id]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $current_perms[$row['website_id']] = $row;
}

$error = '';
$success = '';
$is_new_user = isset($_GET['new']) && $_GET['new'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $changes = [];
    $has_permission = false;

    foreach ($websites as $site) {
        $wid = $site['website_id'];
        $level = $_POST["level_$wid"] ?? 'none';
        $notes = trim($_POST["notes_$wid"] ?? '');

        if ($level === 'none') {
            if (isset($current_perms[$wid])) {
                $changes[] = ['action' => 'delete', 'wid' => $wid];
            }
            continue;
        }

        if (!in_array($level, ['admin', 'viewer'])) continue;

        $has_permission = true;
        $old = $current_perms[$wid] ?? ['permission_level' => 'none', 'notes' => ''];

        if ($old['permission_level'] === 'none') {
            $changes[] = ['action' => 'insert', 'wid' => $wid, 'level' => $level, 'notes' => $notes];
        } elseif ($level !== $old['permission_level'] || $notes !== $old['notes']) {
            $changes[] = ['action' => 'update', 'wid' => $wid, 'level' => $level, 'notes' => $notes];
        }
    }

    if ($is_new_user && !$has_permission) {
        $error = "New users should have at least one permission assigned.";
    } else {
        foreach ($changes as $ch) {
            if ($ch['action'] === 'delete') {
                $pdo->prepare("DELETE FROM Permissions WHERE user_id = ? AND website_id = ?")
                    ->execute([$user_id, $ch['wid']]);
            } elseif ($ch['action'] === 'insert') {
                $pdo->prepare("INSERT INTO Permissions (user_id, website_id, permission_level, notes) VALUES (?, ?, ?, ?)")
                    ->execute([$user_id, $ch['wid'], $ch['level'], $ch['notes']]);
            } elseif ($ch['action'] === 'update') {
                $pdo->prepare("UPDATE Permissions SET permission_level = ?, notes = ? WHERE user_id = ? AND website_id = ?")
                    ->execute([$ch['level'], $ch['notes'], $user_id, $ch['wid']]);
            }
        }

        $success = "Permissions saved successfully.";

        //refresh current state
        $current_perms = [];
        $stmt = $pdo->prepare("SELECT website_id, permission_level, notes FROM Permissions WHERE user_id = ?");
        $stmt->execute([$user_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $current_perms[$row['website_id']] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Permissions - <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>

<div class="content">
    <h1>Permissions</h1>
    <p>
        <a href="users.php">← Back to Users</a>
    </p>

    <h2>
        <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>
        <small style="color:#555; font-weight:normal;">(<?= htmlspecialchars($user['email']) ?>)</small>
    </h2>

    <p style="margin:0.5em 0 1.5em;">
        Status: <span class="status-badge status-<?= htmlspecialchars($user['status']) ?>">
            <?= ucfirst($user['status']) ?>
        </span>
    </p>

    <?php if ($is_new_user): ?>
    <div style="background:#fff3cd; border:1px solid #ffeeba; color:#856404; padding:16px; margin:1em 0; border-radius:4px;">
        <strong>New user created successfully.</strong><br>
        Please assign at least one permission below.
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div style="background:#fee; color:#900; padding:12px; border:1px solid #900; margin-bottom:1em;">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div style="background:#efe; color:#090; padding:12px; border:1px solid #090; margin-bottom:1em;">
        <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f4f4f4;">
                    <th style="padding:10px; text-align:left;">Website</th>
                    <th style="padding:10px; text-align:left; width:35%;">Website Description</th>
                    <th style="padding:10px; text-align:center; width:180px;">Permission Level</th>
                    <th style="padding:10px; text-align:left;">Permission Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($websites as $site): 
                $wid = $site['website_id'];
                $perm = $current_perms[$wid] ?? ['permission_level' => 'none', 'notes' => ''];
                $has_perm = $perm['permission_level'] !== 'none';
            ?>
                <tr style="background:<?= $has_perm ? '#f9fff9' : '#ffffff' ?>;">
                    <td style="padding:10px; font-weight:<?= $has_perm ? '600' : '400' ?>;">
                        <?= htmlspecialchars($site['website_name']) ?>
                    </td>
                    <td style="padding:10px; color:#555; line-height:1.4;">
                        <?= !empty($site['website_description']) 
                            ? nl2br(htmlspecialchars($site['website_description'])) 
                            : '<span style="color:#aaa; font-style:italic;">No description available</span>' ?>
                    </td>
                    <td style="padding:10px; text-align:center;">
                        <select name="level_<?= $wid ?>" style="width:140px;">
                            <option value="none"    <?= $perm['permission_level'] === 'none'    ? 'selected' : '' ?>>None</option>
                            <option value="admin"   <?= $perm['permission_level'] === 'admin'   ? 'selected' : '' ?>>Admin</option>
                            <option value="viewer"  <?= $perm['permission_level'] === 'viewer'  ? 'selected' : '' ?>>Viewer</option>
                        </select>
                    </td>
                    <td style="padding:10px;">
                        <input type="text" name="notes_<?= $wid ?>"
                               value="<?= htmlspecialchars($perm['notes']) ?>"
                               placeholder="e.g. Dashboard only, contract ends 2025-12"
                               style="width:100%; box-sizing:border-box;">
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:2em; text-align:center;">
            <button type="submit" name="save" style="padding:12px 40px; font-size:16px; background:#2c7be5; color:white; border:none; border-radius:6px; cursor:pointer;">
                Save Permissions
            </button>
        </div>
    </form>

</div>

</body>
</html>