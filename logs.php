<?php
// logs.php - bolder row background coloring
include 'db.php';
include 'menu.php';

session_start(); 

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$logs = $pdo->query("
    SELECT 
        l.*,
        CONCAT(u.first_name, ' ', u.last_name) AS admin_name,
        u.email AS admin_email
    FROM ActivityLogs l
    LEFT JOIN Users u ON l.admin_id = u.user_id
    ORDER BY l.created_at DESC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Activity Logs</title>
    <link rel="stylesheet" href="styles.css">
    <style>
    table.log-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-top: 1em;
    }
    table.log-table th,
    table.log-table td {
        padding: 10px 12px;
        border: 1px solid #e0e0e0;
        text-align: left;
        font-size: 13px;
        vertical-align: top;
    }
    table.log-table th {
        background: #f5f5f5;
        font-weight: 600;
        color: #333;
        position: sticky;
        top: 0;
        z-index: 1;
    }

    .log-details {
        max-width: 560px;
        white-space: pre-wrap;
        word-break: break-word;
        color: #222;
    }

    small { color: #666; font-size: 11px; }

    /* ─── Softer, lower-saturation row backgrounds ─── */
    tr.action-login     { background-color: #e3f2fd; }      /* very light blue   */
    tr.action-logout    { background-color: #f5f5f5; }     /* almost white      */
    tr.action-create    { background-color: #e8f5e9; }     /* very light green  */
    tr.action-update    { background-color: #fff3e0; }     /* very light orange */
    tr.action-delete    { background-color: #ffebee; }     /* very light red    */
    tr.action-terminate { background-color: #f3e5f5; }     /* very light purple */
    tr.action-suspend   { background-color: #fffde7; }     /* very light yellow */
    tr.action-other     { background-color: #fafafa; }     /* neutral gray      */

    /* Hover effect – subtle */
    tr:hover {
        background-color: #f0f0f0 !important;
        filter: brightness(0.98);
        transition: background-color 0.12s;
    }

    /* Text contrast – darker on lighter backgrounds */
    tr.action-login td,
    tr.action-create td,
    tr.action-update td,
    tr.action-delete td,
    tr.action-terminate td,
    tr.action-suspend td {
        color: #1a1a1a;
    }

    tr {
        box-shadow: inset 0 1px 0 rgba(0,0,0,0.04), inset 0 -1px 0 rgba(0,0,0,0.04);
    }

    tr td:first-child {
        border-left-width: 3px;
    }
    tr.action-login     td:first-child { border-left-color: #90caf9; }
    tr.action-create    td:first-child { border-left-color: #a5d6a7; }
    tr.action-update    td:first-child { border-left-color: #ffcc80; }
    tr.action-delete    td:first-child { border-left-color: #ef9a9a; }
    tr.action-terminate td:first-child { border-left-color: #ce93d8; }
    tr.action-suspend   td:first-child { border-left-color: #fff176; }
    tr.action-logout    td:first-child,
    tr.action-other     td:first-child { border-left-color: #bdbdbd; }

    thead tr {
        box-shadow: none;
    }
</style>
</head>
<body>

<div class="content">

    <div style="margin: 1rem; font-size: 14px; text-align: center;">
        <p><strong>Purpose: </strong><em>This page keeps detailed logs of who did what, what they did, and when they did it.</em></p>
    </div>

    <h1>Activity Logs <small>(most recent 500)</small></h1>

    <?php if (empty($logs)): ?>
        <p><em>No logs yet.</em></p>
    <?php else: ?>
    <table class="log-table">
        <tr>
            <th>Date</th>
            <th>User</th>
            <th>Action</th>
            <th>Details</th>
        </tr>

        <?php
        function getActionClass($type) {
            $t = strtolower($type);

            if (str_contains($t, 'login')  || str_contains($t, 'sign_in'))  return 'action-login';
            if (str_contains($t, 'logout') || str_contains($t, 'sign_out')) return 'action-logout';
            if (str_contains($t, 'create') || str_contains($t, 'add') || str_contains($t, 'new')) return 'action-create';
            if (str_contains($t, 'update') || str_contains($t, 'edit') || str_contains($t, 'modify') || str_contains($t, 'change')) return 'action-update';
            if (str_contains($t, 'delete') || str_contains($t, 'remove') || str_contains($t, 'destroy')) return 'action-delete';
            if (str_contains($t, 'terminate') || str_contains($t, 'term') || str_contains($t, 'suspend') || str_contains($t, 'ban')) return 'action-terminate';

            return 'action-other';
        }
        ?>

        <?php foreach ($logs as $log): 
            $rowClass = getActionClass($log['action_type']);
        ?>
        <tr class="<?= $rowClass ?>">
            <td><?= date('Y-m-d H:i', strtotime($log['created_at'])) ?></td>
            <td>
                <?= htmlspecialchars($log['admin_name'] ?: 'System') ?><br>
                <small><?= htmlspecialchars($log['admin_email'] ?: '-') ?></small>
            </td>
            <td>
                <strong><?= str_replace('_', ' ', ucfirst(htmlspecialchars($log['action_type']))) ?></strong>
            </td>
            <td class="log-details"><?= nl2br(htmlspecialchars($log['details'] ?: '-')) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

</body>
</html>