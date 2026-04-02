<?php
// websites.php - Manage Websites with Pagination (10 per page)
include 'db.php';
include 'menu.php';

session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create'])) {
        $name = trim($_POST['website_name'] ?? '');
        $desc = trim($_POST['website_description'] ?? '');
        if (empty($name)) {
            $error = "Website name is required.";
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Websites WHERE website_name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetchColumn() > 0) {
                $error = "A website with this name already exists.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO Websites (website_name, website_description) VALUES (?, ?)");
                $stmt->execute([$name, $desc]);
                logActivity($pdo, $_SESSION['admin_id'], 'website_create', 'website', $pdo->lastInsertId(),
                    "Added new website: $name");
                header("Location: websites.php");
                exit;
            }
        }
    } elseif (isset($_POST['update'])) {
        $id = (int)($_POST['website_id'] ?? 0);
        $name = trim($_POST['website_name'] ?? '');
        $desc = trim($_POST['website_description'] ?? '');
        if ($id <= 0 || empty($name)) {
            $error = "Invalid or missing data.";
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Websites WHERE website_name = ? AND website_id != ?");
            $stmt->execute([$name, $id]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Another website already uses this name.";
            } else {
                $stmt = $pdo->prepare("UPDATE Websites SET website_name = ?, website_description = ? WHERE website_id = ?");
                $stmt->execute([$name, $desc, $id]);
                logActivity($pdo, $_SESSION['admin_id'], 'website_update', 'website', $id,
                    "Updated website: $name");
                header("Location: websites.php");
                exit;
            }
        }
    } elseif (isset($_POST['delete'])) {
        $id = (int)($_POST['website_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM Permissions WHERE website_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM Websites WHERE website_id = ?")->execute([$id]);
            logActivity($pdo, $_SESSION['admin_id'], 'website_delete', 'website', $id,
                "Deleted website ID $id");
            header("Location: websites.php");
            exit;
        }
    }
}

// Pagination settings
$per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $per_page;

// Get total websites for pagination
$total_websites = $pdo->query("SELECT COUNT(*) FROM Websites")->fetchColumn();

// Fetch websites with pagination
$sites = $pdo->query("
    SELECT * FROM Websites 
    ORDER BY website_description ASC 
    LIMIT $per_page OFFSET $offset
")->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Websites</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.1em 1.4em;
            max-width: 720px;
            margin-bottom: 1.5em;
            font-size: 14px;
        }
        .form-grid label {
            display: block;
            margin-bottom: 4px;
            font-weight: 500;
        }
        .form-grid input, .form-grid textarea {
            width: 100%;
            padding: 7px;
            box-sizing: border-box;
        }
        .form-grid textarea {
            min-height: 68px;
            resize: vertical;
        }
        .form-grid .full-width {
            grid-column: span 2;
        }
        .btn-create {
            padding: 9px 28px;
            font-size: 15px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .btn-update {
            padding: 9px 28px;
            font-size: 15px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5em;
            margin-bottom: 2em;
        }
        th, td {
            padding: 10px 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background: #f5f5f5;
            font-weight: 600;
            color: #333;
        }
        td {
            vertical-align: top;
        }
        td:nth-child(2) {
            font-weight: 500;
            color: #1a1a1a;
        }
        td:last-child {
            text-align: right;
        }
        .action-btn {
            padding: 6px 12px;
            margin: 0 4px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            color: white;
            text-decoration: none;
            font-size: 0.92rem;
            transition: all 0.12s ease;
        }
        .action-btn:hover {
            opacity: 0.92;
            transform: translateY(-1px);
        }

        .content {
            max-width: 1200px;
            margin: 0 auto; 
            padding: 20px; 
        }

        /* Pagination */
        .pagination {
            margin: 25px 0;
            text-align: center;
        }
        .pagination a, .pagination span {
            display: inline-block;
            padding: 8px 14px;
            margin: 0 4px;
            border: 1px solid #999;
            text-decoration: none;
            color: #003366;
            border-radius: 4px;
        }
        .pagination a:hover {
            background: #f0f0f0;
        }
        .pagination .current {
            background: #003366;
            color: white;
            border-color: #003366;
            font-weight: bold;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background-color: #fff;
            padding: 25px 30px;
            border-radius: 8px;
            width: 100%;
            max-width: 680px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            position: relative;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.2em;
            border-bottom: 1px solid #eee;
            padding-bottom: 12px;
        }
        .modal-header h2 {
            margin: 0;
            font-size: 1.35rem;
        }
        .close-modal {
            font-size: 28px;
            font-weight: bold;
            color: #aaa;
            cursor: pointer;
            line-height: 1;
        }
        .close-modal:hover {
            color: #000;
        }
        .modal form {
            margin: 0;
        }
    </style>
</head>
<body>

<div class="content">
    <?php if (!empty($error)): ?>
    <div style="background:#fee; color:#900; padding:10px 14px; border:1px solid #900; margin:1em 0; border-radius:4px;">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <div style="margin: 1rem; font-size: 14px; text-align: center;">
        <p><strong>Purpose: </strong><em>This page lets you view a list of all sites & their admins, as well as add/edit/delete sites.</em></p>
    </div>

    <details>
        <summary style="font-size: 1.2rem">Add New Website</summary>
        <form method="POST">
            <input type="hidden" name="create" value="1">

            <div class="form-grid">
                <div>
                    <label>URL</label>
                    <input type="text" name="website_name" required>
                </div>

                <div class="full-width">
                    <label>Description</label>
                    <textarea name="website_description" rows="3"></textarea>
                </div>
            </div>

            <button type="submit" class="btn-create">Add Website</button>
        </form>
    </details>

    <!-- Websites List -->
    <h2 style="margin: 2.2em 0 0.8em;">All Websites</h2>

    <table>
        <thead>
            <tr>
                <!-- <th>ID</th> -->
                <th>URL</th>
                <th>Description</th>
                <th>Admins</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sites as $site):
            $desc = htmlspecialchars($site['website_description'] ?: '-');

            // Get admins
            $stmt = $pdo->prepare("
                SELECT CONCAT(u.first_name, ' ', u.last_name) AS name
                FROM Users u
                JOIN Permissions p ON u.user_id = p.user_id
                WHERE p.website_id = ? AND p.permission_level = 'admin'
                ORDER BY u.last_name, u.first_name
            ");
            $stmt->execute([$site['website_id']]);
            $admin_list = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $admins_display = $admin_list ? implode(', ', array_map('htmlspecialchars', $admin_list)) : 'None';
        ?>
            <tr>
                <!-- <td><?= $site['website_id'] ?></td> -->
                <td style="font-weight: 500;"><?= htmlspecialchars($site['website_name']) ?></td>
                <td><?= $desc ?></td>
                <td><?= $admins_display ?></td>
                <td style="text-align:right; white-space:nowrap;">
                    <button onclick="openEditModal(<?= $site['website_id'] ?>, 
                        '<?= htmlspecialchars(addslashes($site['website_name'])) ?>', 
                        '<?= htmlspecialchars(addslashes($site['website_description'] ?? '')) ?>')" 
                        class="action-btn" style="background:#007bff;">Edit</button>
                    
                    <form method="POST" style="display:inline;" 
                        onsubmit="return confirm('Delete this website and all associated permissions?\nThis cannot be undone.');">
                        <input type="hidden" name="website_id" value="<?= $site['website_id'] ?>">
                        <input type="hidden" name="delete" value="1">
                        <button type="submit" class="action-btn" style="background:#dc3545;">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($total_websites > $per_page): ?>
    <div class="pagination">
        <?php
        $total_pages = ceil($total_websites / $per_page);
        for ($i = 1; $i <= $total_pages; $i++):
            if ($i == $page):
        ?>
            <span class="current"><?= $i ?></span>
        <?php else: ?>
            <a href="?page=<?= $i ?>"><?= $i ?></a>
        <?php endif; endfor; ?>
    </div>
    <?php endif; ?>

</div>

<!-- Edit Website Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Edit Website</h2>
            <span class="close-modal" onclick="closeEditModal()">&times;</span>
        </div>
        
        <form id="editForm" method="POST">
            <input type="hidden" name="update" value="1">
            <input type="hidden" id="modal_website_id" name="website_id" value="">

            <div class="form-grid">
                <div>
                    <label>Name *</label>
                    <input type="text" id="modal_website_name" name="website_name" required>
                </div>

                <div class="full-width">
                    <label>Description</label>
                    <textarea id="modal_website_description" name="website_description" rows="3"></textarea>
                </div>
            </div>

            <button type="submit" class="btn-update">Update Website</button>
            <button type="button" onclick="closeEditModal()" 
                    style="padding:9px 20px; font-size:15px; background:#6c757d; color:white; border:none; border-radius:5px; margin-left:10px; cursor:pointer;">
                Cancel
            </button>
        </form>
    </div>
</div>

<script>
function openEditModal(id, name, description) {
    document.getElementById('modal_website_id').value = id;
    document.getElementById('modal_website_name').value = name;
    document.getElementById('modal_website_description').value = description;
    document.getElementById('editModal').style.display = 'flex';
    
    setTimeout(() => {
        document.getElementById('modal_website_name').focus();
    }, 100);
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('editModal');
    if (event.target === modal) {
        closeEditModal();
    }
}

document.addEventListener('keydown', function(event) {
    if (event.key === "Escape") {
        closeEditModal();
    }
});
</script>

</body>
</html>