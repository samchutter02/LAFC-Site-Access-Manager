<?php
include 'db.php';
include 'menu.php';

session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

// ─────────
// QUERIES
// ─────────
$total_users       = $pdo->query("SELECT COUNT(*) FROM Users")->fetchColumn();
$active_users      = $pdo->query("
    SELECT COUNT(*) FROM Users 
    WHERE status IN ('active', 'contract') 
      AND (end_date IS NULL OR end_date > NOW())
")->fetchColumn();
$total_websites    = $pdo->query("SELECT COUNT(*) FROM Websites")->fetchColumn();
$total_permissions = $pdo->query("
    SELECT COUNT(*) FROM Permissions 
    WHERE permission_level != 'none'
")->fetchColumn();

$admin_count  = $pdo->query("SELECT COUNT(*) FROM Permissions WHERE permission_level = 'admin'")->fetchColumn();
$viewer_count = $pdo->query("SELECT COUNT(*) FROM Permissions WHERE permission_level = 'viewer'")->fetchColumn();
$total_active_perms = $admin_count + $viewer_count;
$admin_pct = $total_active_perms > 0 ? round(($admin_count / $total_active_perms) * 100) : 0;

// Risk indicators
$no_admin_sites = $pdo->query("
    SELECT w.website_name
    FROM Websites w
    LEFT JOIN Permissions p ON w.website_id = p.website_id AND p.permission_level = 'admin'
    LEFT JOIN Users u ON p.user_id = u.user_id 
                     AND u.status = 'active'
                     AND (u.end_date IS NULL OR u.end_date > NOW())
    GROUP BY w.website_id, w.website_name
    HAVING COUNT(DISTINCT u.user_id) = 0
    ORDER BY w.website_name
")->fetchAll(PDO::FETCH_COLUMN);

$single_admin_sites = $pdo->query("
    SELECT w.website_name, COUNT(DISTINCT u.user_id) AS admin_count
    FROM Websites w
    LEFT JOIN Permissions p ON w.website_id = p.website_id AND p.permission_level = 'admin'
    LEFT JOIN Users u ON p.user_id = u.user_id 
                     AND u.status = 'active'
                     AND (u.end_date IS NULL OR u.end_date > NOW())
    GROUP BY w.website_id, w.website_name
    HAVING admin_count = 1
    ORDER BY w.website_name
")->fetchAll(PDO::FETCH_ASSOC);

// widgets
$orphaned = $pdo->query("
    SELECT COUNT(*) AS permission_count
    FROM Permissions p
    JOIN Users u ON p.user_id = u.user_id
    WHERE u.status IN ('terminated', 'suspended') 
      AND p.permission_level != 'none'
")->fetchColumn();

$expiring_soon = $pdo->query("
    SELECT first_name, last_name, email, end_date
    FROM Users
    WHERE status IN ('active', 'contract')
      AND end_date IS NOT NULL
      AND end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 60 DAY)
    ORDER BY end_date ASC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$top_users = $pdo->query("
    SELECT 
        u.user_id, u.first_name, u.last_name, u.email,
        COUNT(p.website_id) AS site_count,
        MAX(CASE WHEN p.permission_level = 'admin' THEN 1 ELSE 0 END) AS has_admin
    FROM Users u
    LEFT JOIN Permissions p ON u.user_id = p.user_id AND p.permission_level != 'none'
    WHERE u.status != 'terminated'
    GROUP BY u.user_id, u.first_name, u.last_name, u.email
    ORDER BY site_count DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$recent_terminated = $pdo->query("
    SELECT first_name, last_name, email, end_date
    FROM Users
    WHERE status = 'terminated'
      AND end_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY end_date DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// recent Activity Feed (last 8)
$recent_logs = $pdo->query("
    SELECT 
        l.created_at,
        CONCAT(u.first_name, ' ', u.last_name) AS admin_name,
        l.action_type,
        l.details
    FROM ActivityLogs l
    LEFT JOIN Users u ON l.admin_id = u.user_id
    ORDER BY l.created_at DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>LAFC Permissions • Dashboard</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .content { max-width: 1280px; margin: 0 auto; padding: 20px; }
        
        .welcome {
            font-size: 28px;
            color: #003366;
            margin-bottom: 12px;
            font-style: italic;
        }
        
        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 28px;
            background: #e8e8e8;
            padding: 14px 20px;
            border-radius: 8px;
            border: 2px outset #c0c0c0;
        }
        
        .quick-btn {
            padding: 12px 24px;
            font-size: 15px;
            font-weight: bold;
            background: #003366;
            color: white;
            border: 2px outset #c0c0c0;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.15s;
        }
        
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            background: white;
            border: 1px solid #999;
            padding: 20px;
            text-align: center;
            border-radius: 8px;
            box-shadow: 3px 3px 0 #aaa;
            transition: transform 0.15s;
        }
        
        .stat-card .big {
            font-size: 42px;
            font-weight: bold;
            color: #003366;
            margin: 8px 0 4px;
        }
        
        .risk-section {
            background: #fff0f0;
            border: 3px solid #c00;
            border-radius: 8px;
            padding: 10px;
            margin: 32px 0;
        }
        
        .activity-feed {
            max-height: 420px;
            overflow-y: auto;
        }
        
        .log-item {
            padding: 10px 14px;
            border-bottom: 1px solid #ddd;
            font-size: 13px;
        }
        .log-item:last-child { border-bottom: none; }
        
        .emoji { font-size: 1.4em; vertical-align: middle; margin-right: 8px; }
    </style>
</head>
<body>

<div class="content">

    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
            <div class="welcome">
                Welcome back, <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?> 👋
            </div>
        </div>
    </div>

    <!-- KPI ROW -->
    <div class="kpi-grid">
        <div class="stat-card">
            <h3>Active Users</h3>
            <div class="big"><?= number_format($active_users) ?></div>
            <small style="color:#2e7d32;">currently working</small>
        </div>
        <div class="stat-card">
            <h3>Websites</h3>
            <div class="big"><?= number_format($total_websites) ?></div>
        </div>
        <div class="stat-card">
            <h3>Active Permissions</h3>
            <div class="big"><?= number_format($total_permissions) ?></div>
        </div>
    </div>

    <div class="risk-section">
    <!-- RISK & HEALTH -->
    <details>
    <summary style="font-size: 16px; color: #c00; font-weight: bold;">WEBSITES IN DANGER</summary>
        
        <?php if (count($no_admin_sites) > 0 || count($single_admin_sites) > 0 || count($expiring_soon) > 0): ?>
            
            <?php if (count($no_admin_sites) > 0): ?>
            <div style="margin: 18px 0;">
                <strong>Sites with ZERO active admins:</strong>
                <ul style="margin:8px 0 0 24px; padding:0; list-style:disc;">
                    <?php foreach ($no_admin_sites as $name): ?>
                        <li><?= htmlspecialchars($name) ?></li>
                    <?php endforeach; ?>
                </ul>
                <a href="users.php" style="color:#c00; text-decoration:underline;">Assign admins now →</a>
            </div>
            <?php endif; ?>

            <?php if (count($single_admin_sites) > 0): ?>
            <div style="margin-bottom:18px;">
                <strong>Sites with only ONE active admin (single point of failure):</strong>
                <ul style="margin:8px 0 0 24px; padding:0; list-style:disc;">
                    <?php foreach ($single_admin_sites as $s): ?>
                        <li><?= htmlspecialchars($s['website_name']) ?></li>
                    <?php endforeach; ?>
                </ul>
                <a href="users.php" style="color:#c00; text-decoration:underline;">Add backup admins →</a>
            </div>
            <?php endif; ?>

            <?php if (count($expiring_soon) > 0): ?>
            <div>
                <strong>Access expiring in the next 60 days (<?= count($expiring_soon) ?> users):</strong>
                <table style="width:100%; margin-top:8px; font-size:13px;">
                    <tr><th style="text-align:left;">User</th><th style="text-align:left;">Email</th><th>Ends</th></tr>
                    <?php foreach ($expiring_soon as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><?= date('M j, Y', strtotime($u['end_date'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>

        <?php else: ?>
            <p style="color:#2e7d32; font-size:1.1em; margin:0;">All sites have proper admin coverage and no access is expiring soon.</p>
        <?php endif; ?>
    
    </details>
    </div>

    <!-- TWO-COLUMN MAIN CONTENT -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px;">

        <!--Recent Activity  -->
        <div style="background:white; border:1px solid #999; border-radius:8px; padding:20px;">
            <h2 style="margin-top:0;">Recent Activity</h2>
            <div class="activity-feed">
                <?php if (empty($recent_logs)): ?>
                    <p style="color:#777; font-style:italic;">No activity yet.</p>
                <?php else: ?>
                    <?php foreach ($recent_logs as $log): ?>
                        <div class="log-item">
                            <small style="color:#666;"><?= date('M j, g:i A', strtotime($log['created_at'])) ?></small><br>
                            <strong><?= htmlspecialchars($log['admin_name'] ?: 'System') ?></strong> 
                            <?= str_replace('_', ' ', ucfirst(htmlspecialchars($log['action_type']))) ?>
                            <div style="margin-top:4px; color:#222; font-size:12.5px; line-height:1.3;">
                                <?= htmlspecialchars($log['details']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <a href="logs.php" style="display:inline-block; margin-top:12px; color:#003366; font-weight:bold;">See all bagillion logs →</a>
        </div>

        <div>

            <div style="background:white; border:1px solid #999; border-radius:8px; padding:20px; margin-bottom:20px;">
                <h2 style="margin-top:0;">Users with Most Access</h2>
                <table style="width:100%;">
                    <tr style="background:#f4f4f4;">
                        <th style="text-align:left;">User</th>
                        <th style="text-align:center;">Sites</th>
                        <th style="text-align:center;">Role</th>
                    </tr>
                    <?php foreach ($top_users as $u): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?><br>
                            <small><?= htmlspecialchars($u['email']) ?></small>
                        </td>
                        <td style="text-align:center; font-weight:bold; font-size:1.3em;"><?= $u['site_count'] ?></td>
                        <td style="text-align:center;">
                            <?php if ($u['has_admin']): ?>
                                <span style="background:#c62828; color:white; padding:2px 10px; border-radius:4px; font-size:12px;">ADMIN</span>
                            <?php else: ?>
                                <span style="background:#28a745; color:white; padding:2px 10px; border-radius:4px; font-size:12px;">VIEWER</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <?php if (!empty($recent_terminated)): ?>
            <div style="background:white; border:1px solid #999; border-radius:8px; padding:20px;">
                <h2 style="margin-top:0;">Recently Terminated</h2>
                <div style="display:flex; flex-wrap:wrap; gap:12px;">
                    <?php foreach ($recent_terminated as $t): ?>
                        <div style="background:#ffebee; border:1px solid #c62828; border-radius:6px; padding:10px 14px; min-width:220px;">
                            <strong><?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?></strong><br>
                            <small><?= htmlspecialchars($t['email']) ?></small><br>
                            <span style="font-size:12px; color:#c62828;">Terminated <?= date('M j', strtotime($t['end_date'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

</body>
</html>