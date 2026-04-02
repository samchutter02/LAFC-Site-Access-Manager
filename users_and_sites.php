<?php
// users_and_sites.php - Combined Users per Site + Sites per User with Pagination (10 per page)
include 'db.php';
include 'menu.php';

session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

// Handle tabs
$valid_tabs = ['users', 'sites'];
$active_tab = isset($_GET['tab']) && in_array($_GET['tab'], $valid_tabs) ? $_GET['tab'] : 'users';

// Pagination settings
$per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $per_page;

// Fetch data for Users per Site (List by Sites)
$total_websites = $pdo->query("SELECT COUNT(*) FROM Websites")->fetchColumn();
$websites = $pdo->query("
    SELECT * FROM Websites 
    ORDER BY website_id ASC 
    LIMIT $per_page OFFSET $offset
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch data for Sites per User (List by Users)
$total_users = $pdo->query("SELECT COUNT(*) FROM Users")->fetchColumn();
$users = $pdo->query("
    SELECT * FROM Users 
    ORDER BY user_id ASC 
    LIMIT $per_page OFFSET $offset
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Users & Sites Overview</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .tabs {
            margin: 20px 0 0 0;
            border-bottom: 2px solid #666;
        }
        .tab-links {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
        }
        .tab-links li {
            margin: 0;
        }
        .tab-links a {
            display: inline-block;
            padding: 8px 18px;
            background: #e0e0e0;
            border: 1px solid #999;
            border-bottom: none;
            text-decoration: none;
            color: #003366;
            font-weight: bold;
            margin-right: 4px;
        }
        .tab-links a:hover {
            background: #f0f0f0;
        }
        .tab-links a.active {
            background: #fff;
            border-bottom: 1px solid #fff;
            position: relative;
            top: 1px;
        }
        .tab-content {
            border: 1px solid #999;
            border-top: none;
            background: #fff;
            padding: 16px;
        }
        .tab-pane {
            display: none;
        }
        .tab-pane.active {
            display: block;
        }

        .tab-pane table {
            table-layout: fixed;    
            width: 100%;               
        }

        .tab-pane th, .tab-pane td {
            overflow: hidden;    
            text-overflow: ellipsis;
            white-space: nowrap;
            padding: 8px;
        }

        .tab-pane th:nth-child(1) { width:  6%; }  /* ID */
        .tab-pane th:nth-child(2) { width: 15%; }  /* First Name */
        .tab-pane th:nth-child(3) { width: 15%; }  /* Last Name */
        .tab-pane th:nth-child(4) { width: 28%; }  /* Email */
        .tab-pane th:nth-child(5) { width: 12%; }  /* Status */
        .tab-pane th:nth-child(6) { width: 24%; }  /* Permission Level */

        .pagination {
            margin: 20px 0;
            text-align: center;
        }
        .pagination a, .pagination span {
            display: inline-block;
            padding: 8px 12px;
            margin: 0 3px;
            border: 1px solid #999;
            text-decoration: none;
            color: #003366;
        }
        .pagination a:hover {
            background: #f0f0f0;
        }
        .pagination .current {
            background: #003366;
            color: white;
            border-color: #003366;
        }
    </style>
</head>
<body>

<div class="content">

    <div style="margin: 1rem; font-size: 14px; text-align: center;">
        <p><strong>Purpose:</strong> <em>This page lets you view relationships between websites and users.</em></p>
    </div>

    <div class="tabs">
        <ul class="tab-links">
            <li><a href="?tab=users"  class="<?= $active_tab === 'users'  ? 'active' : '' ?>">List by Sites</a></li>
            <li><a href="?tab=sites"  class="<?= $active_tab === 'sites'  ? 'active' : '' ?>">List by Users</a></li>
        </ul>

        <div class="tab-content">

            <!-- ====================== TAB 1: USERS PER SITE ====================== -->
            <div class="tab-pane <?= $active_tab === 'users' ? 'active' : '' ?>">
                <h2>List by Sites (Users with Access)</h2>
                
                <?php foreach ($websites as $site): 
                    $stmt = $pdo->prepare("
                        SELECT u.*, p.permission_level 
                        FROM Users u 
                        JOIN Permissions p ON u.user_id = p.user_id 
                        WHERE p.website_id = ? 
                          AND p.permission_level != 'none' 
                        ORDER BY u.user_id ASC
                    ");
                    $stmt->execute([$site['website_id']]);
                    $site_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $user_count = count($site_users);

                    $countStyle = 'background-color:#e3f2fd; color:#1565c0; display:inline-block; padding:5px 10px; border-radius:12px; font-weight:700; font-size:0.95em; min-width:36px; text-align:center; margin-left:12px; border:1px solid rgba(0,0,0,0.12);';
                ?>
                    <h3 style="display:flex; align-items:center; margin: 20px 0 12px 0;">
                        <?= htmlspecialchars($site['website_description'] ?? $site['website_name']) ?>
                        <span style="<?= $countStyle ?>"><?= $user_count ?></span>
                    </h3>

                    <?php if ($user_count === 0): ?>
                        <p><em>No users with permissions for this site.</em></p>
                    <?php else: ?>
                        <table>
                            <tr>
                                <th>ID</th>
                                <th>First Name</th>
                                <th>Last Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Permission Level</th>
                            </tr>
                            <?php foreach ($site_users as $user): ?>
                            <tr>
                                <td><?= $user['user_id'] ?></td>
                                <td><?= htmlspecialchars($user['first_name']) ?></td>
                                <td><?= htmlspecialchars($user['last_name']) ?></td>
                                <td><?= htmlspecialchars($user['email']) ?></td>
                                <td><?= htmlspecialchars($user['status']) ?></td>
                                <td><?= htmlspecialchars($user['permission_level']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>
                    <br>
                <?php endforeach; ?>

                <!-- Pagination for Websites -->
                <?php if ($total_websites > $per_page): ?>
                <div class="pagination">
                    <?php
                    $total_pages = ceil($total_websites / $per_page);
                    $query_string = http_build_query(['tab' => 'users', 'page' => '']);
                    for ($i = 1; $i <= $total_pages; $i++):
                        if ($i == $page):
                    ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?tab=users&page=<?= $i ?>"><?= $i ?></a>
                    <?php endif; endfor; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ====================== TAB 2: SITES PER USER ====================== -->
            <div class="tab-pane <?= $active_tab === 'sites' ? 'active' : '' ?>">
                <h2>List by Users (Sites Assigned)</h2>

                <?php foreach ($users as $user): 
                    $status = $user['status'];
                    $badgeStyle = 'display:inline-block; padding:5px 11px; border-radius:10px; font-weight:600; font-size:0.92em; text-align:center; min-width:80px; border:1px solid rgba(0,0,0,0.1); text-transform:capitalize;';

                    if ($status === 'active') {
                        $badgeStyle .= 'background-color:#e8f5e9; color:#2e7d32; border-color:#a5d6a7;';
                    } elseif ($status === 'suspended') {
                        $badgeStyle .= 'background-color:#fff3e0; color:#ef6c00; border-color:#ffcc80;';
                    } elseif ($status === 'terminated') {
                        $badgeStyle .= 'background-color:#ffebee; color:#c62828; border-color:#ef9a9a;';
                    } elseif ($status === 'contract') {
                        $badgeStyle .= 'background-color:#e3f2fd; color:#1565c0; border-color:#90caf9;';
                    }
                ?>
                    <h3><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h3>

                    <p style="margin: -8px 0 12px 0; color:#555;">
                        <strong>Email:</strong> <?= htmlspecialchars($user['email']) ?>  
                        <span style="margin-left:20px;">
                            <span style="<?= $badgeStyle ?>"><?= ucfirst($status) ?></span>
                        </span>
                    </p>

                    <?php
                    $stmt = $pdo->prepare("
                        SELECT w.*, p.permission_level 
                        FROM Websites w 
                        JOIN Permissions p ON w.website_id = p.website_id 
                        WHERE p.user_id = ? 
                          AND p.permission_level != 'none' 
                        ORDER BY w.website_name
                    ");
                    $stmt->execute([$user['user_id']]);
                    $user_sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>

                    <?php if (empty($user_sites)): ?>
                        <p><em>No sites assigned to this user.</em></p>
                    <?php else: ?>
                        <table>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Permission Level</th>
                            </tr>
                            <?php foreach ($user_sites as $site): ?>
                            <tr>
                                <td><?= $site['website_id'] ?></td>
                                <td><?= htmlspecialchars($site['website_name']) ?></td>
                                <td><?= htmlspecialchars($site['website_description'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($site['permission_level']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>
                    <br><hr style="margin:20px 0; border-color:#ddd;">
                <?php endforeach; ?>

                <!-- Pagination for Users -->
                <?php if ($total_users > $per_page): ?>
                <div class="pagination">
                    <?php
                    $total_pages = ceil($total_users / $per_page);
                    for ($i = 1; $i <= $total_pages; $i++):
                        if ($i == $page):
                    ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?tab=sites&page=<?= $i ?>"><?= $i ?></a>
                    <?php endif; endfor; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

</div>

</body>
</html>