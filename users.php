<?php
// users.php - Users list with modal-based edit + terminate + email notification + Pagination
include 'db.php';
include 'menu.php';
include 'email_handler.php';

session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$allowed_statuses = ['active', 'suspended', 'terminated', 'contract'];

$error   = '';
$success = '';

// ====================== PAGINATION SETTINGS ======================
$per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $per_page;

// Get total users count
$total_stmt = $pdo->query("SELECT COUNT(*) FROM Users");
$total_users = $total_stmt->fetchColumn();
$total_pages = ceil($total_users / $per_page);
// ================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create'])) {
        $first_name  = trim($_POST['first_name'] ?? '');
        $last_name   = trim($_POST['last_name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $status      = $_POST['status'] ?? '';
        $start_date  = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $end_date    = !empty($_POST['end_date'])   ? $_POST['end_date']   : null;

        if (empty($first_name) || empty($last_name) || empty($email) ||
            !in_array($status, $allowed_statuses) ||
            ($end_date && $start_date && $start_date > $end_date)) {
            $error = "Invalid input or dates.";
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Email already exists.";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO Users (first_name, last_name, email, status, start_date, end_date)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$first_name, $last_name, $email, $status, $start_date, $end_date]);
                $user_id = $pdo->lastInsertId();

                logActivity($pdo, $_SESSION['admin_id'], 'user_create', 'user', $user_id,
                    "Created user: $first_name $last_name <$email> (status: $status)");

                $success = "User created successfully. Redirecting to assign permissions...";
                header("Location: user_permissions.php?user_id=$user_id&new=1");
                exit;
            }
        }
    }

    elseif (isset($_POST['update'])) {
        $user_id     = (int)($_POST['user_id'] ?? 0);
        $first_name  = trim($_POST['first_name'] ?? '');
        $last_name   = trim($_POST['last_name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $status      = $_POST['status'] ?? '';
        $start_date  = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $end_date    = !empty($_POST['end_date'])   ? $_POST['end_date']   : null;

        if ($user_id <= 0 || empty($first_name) || empty($last_name) || empty($email) ||
            !in_array($status, $allowed_statuses)) {
            $error = "Invalid input.";
        } elseif ($end_date && $start_date && $start_date > $end_date) {
            $error = "Start date cannot be after end date.";
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Users WHERE email = ? AND user_id != ?");
            $stmt->execute([$email, $user_id]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Email already exists.";
            } else {
                $stmt = $pdo->prepare("
                    UPDATE Users 
                    SET first_name = ?, last_name = ?, email = ?, status = ?, start_date = ?, end_date = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([$first_name, $last_name, $email, $status, $start_date, $end_date, $user_id]);

                logActivity($pdo, $_SESSION['admin_id'], 'user_update', 'user', $user_id,
                    "Updated user: $first_name $last_name <$email> (status: $status)");

                $success = "User updated successfully.";
                header("Location: users.php?page=$page");
                exit;
            }
        }
    }

    elseif (isset($_POST['terminate'])) {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id <= 0) {
            $error = "Invalid user ID.";
        } else {
            // Get user info for logging & email
            $stmt = $pdo->prepare("SELECT first_name, last_name, email FROM Users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $error = "User not found.";
            } else {
                // Terminate
                $stmt = $pdo->prepare("
                    UPDATE Users 
                    SET status = 'terminated', 
                        end_date = NOW()
                    WHERE user_id = ?
                ");
                $stmt->execute([$user_id]);

                // Remove permissions
                $stmt = $pdo->prepare("DELETE FROM Permissions WHERE user_id = ?");
                $stmt->execute([$user_id]);

                logActivity($pdo, $_SESSION['admin_id'], 'user_terminate', 'user', $user_id,
                    "Terminated user: {$user['first_name']} {$user['last_name']} <{$user['email']}>");

                // Send notification email
                $subject = "User Terminated: {$user['first_name']} {$user['last_name']}";
                $body = "
                <h2 style='color:#c62828;'>User Termination Notification</h2>
                <p><strong>Terminated by:</strong> " . htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') . "</p>
                <p><strong>User:</strong> {$user['first_name']} {$user['last_name']} &lt;{$user['email']}&gt;</p>
                <p><strong>Termination Time:</strong> " . date('Y-m-d H:i:s') . " MST</p>
                <p><strong>Action taken:</strong> User status set to <strong>terminated</strong> and <strong>all site permissions removed</strong>.</p>
                <hr style='border:1px solid #eee;'>
                <p style='color:#555; font-size:0.95em;'>
                    This is an automated message from the LAFC Permissions System.<br>
                    Please review any affected sites if necessary.
                </p>
                ";

                $emailSent = sendAdminEmail($subject, $body);

                if ($emailSent) {
                    logActivity($pdo, $_SESSION['admin_id'], 'email_sent', 'termination_notify', $user_id,
                        "Sent termination notice to admins");
                } else {
                    logActivity($pdo, $_SESSION['admin_id'], 'email_failed', 'termination_notify', $user_id,
                        "Failed to send termination notice");
                }

                $success = "User terminated successfully and admins notified.";
                header("Location: users.php?page=$page");
                exit;
            }
        }
    }
}

// Fetch paginated users
$stmt = $pdo->prepare("
    SELECT user_id, first_name, last_name, email, status, start_date, end_date, is_dashboard_admin
    FROM Users
    ORDER BY user_id ASC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Users</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .content { max-width: 1200px; margin: 0 auto; padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 1.5em; }
        th, td { padding: 10px 12px; border: 1px solid #ddd; text-align: left; }
        th { background: #f5f5f5; }
        .status-active     { background:#e8f5e9; color:#2e7d32; padding:4px 8px; border-radius:6px; font-weight:bold; }
        .status-suspended  { background:#fff3e0; color:#ef6c00; padding:4px 8px; border-radius:6px; }
        .status-terminated { background:#ffebee; color:#c62828; padding:4px 8px; border-radius:6px; text-decoration:line-through; }
        .status-contract   { background:#e3f2fd; color:#1565c0; padding:4px 8px; border-radius:6px; }
        .action-btn { 
            padding:6px 12px; 
            margin:0 4px; 
            border:none; 
            border-radius:4px; 
            cursor:pointer; 
            color:white; 
            text-decoration:none; 
            font-size: 0.92rem;
        }
        .btn-default { background:#6c757d; }
        .btn-danger  { background:#dc3545; }
        .btn-edit    { background:#007bff; }

        /* Consistent Pagination Style */
        .pagination {
            margin: 30px 0;
            text-align: center;
        }
        .pagination a, .pagination span {
            display: inline-block;
            padding: 8px 14px;
            margin: 0 4px;
            border: 1px solid #999;
            border-radius: 4px;
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
            font-weight: bold;
        }

        /* Modal Styles - Same as websites.php */
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
        .form-grid input, .form-grid select, .form-grid textarea {
            width: 100%;
            padding: 7px;
            box-sizing: border-box;
        }
        .form-grid .full-width {
            grid-column: span 2;
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
        .success-msg { background:#e8f5e9; color:#2e7d32; padding:12px; border:1px solid #a5d6a7; border-radius:6px; margin:1em 0; }
        .error-msg   { background:#ffebee; color:#c62828; padding:12px; border:1px solid #ef9a9a; border-radius:6px; margin:1em 0; }
    </style>
</head>
<body>

<div class="content">

    <?php if ($error): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success-msg"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div style="margin: 1rem; font-size: 14px; text-align: center;">
        <p><strong>Purpose: </strong><em>This page allows you to view all users, add new ones, edit or delete existing users, terminate users while simultaneously notifying necessary admins, and manage user permissions.</em></p>
    </div>

    <!-- Create User -->
    <details>
        <summary style="font-size: 1.2rem">Create New User</summary>
        <form method="POST">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div>
                    <label>First Name *</label><br>
                    <input type="text" name="first_name" required style="width:100%; padding:8px;">
                </div>
                <div>
                    <label>Last Name *</label><br>
                    <input type="text" name="last_name" required style="width:100%; padding:8px;">
                </div>
                <div style="grid-column:1 / -1;">
                    <label>Email *</label><br>
                    <input type="email" name="email" required style="width:100%; padding:8px;">
                </div>
                <div>
                    <label>Status</label><br>
                    <select name="status" required style="width:100%; padding:8px;">
                        <option value="active">Active</option>
                        <option value="contract">Contract</option>
                        <option value="suspended">Suspended</option>
                        <option value="terminated">Terminated</option>
                    </select>
                </div>
                <div>
                    <label>Start Date</label><br>
                    <input type="date" name="start_date" style="width:100%; padding:8px;">
                </div>
                <div>
                    <label>End Date</label><br>
                    <input type="date" name="end_date" style="width:100%; padding:8px;">
                </div>
            </div>
            <div style="margin-top:1.5em;">
                <button type="submit" name="create" style="padding:10px 20px; background:#2c7be5; color:white; border:none; border-radius:4px; cursor:pointer;">
                    Create User & Assign Permissions →
                </button>
            </div>
        </form>
    </details>

    <h2>All Users (Page <?= $page ?> of <?= $total_pages ?> — <?= number_format($total_users) ?> total)</h2>

    <?php if (empty($users)): ?>
        <p><em>No users found.</em></p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Status</th>
                <th>Start</th>
                <th>End</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): 
            $full_name = htmlspecialchars($u['first_name'] . ' ' . $u['last_name']);
            $status_class = "status-" . strtolower($u['status']);
        ?>
            <tr>
                <td><?= $u['user_id'] ?></td>
                <td>
                <?php
                $is_special = (!empty($u['is_dashboard_admin']) && $u['email'] === 'chuttersam@gmail.com');
                $is_admin   = !empty($u['is_dashboard_admin']);
                ?>
                <?php if ($is_special): ?>
                    <span style="display: inline-block; background: #00b291; color: white; 
                                font-size: 12px; font-style: italic; font-weight: 800; padding: 6px 16px; 
                                margin-right: 14px; border-radius: 4px; letter-spacing: 1px; 
                                text-transform: uppercase; border: 1px double black">
                        SYSTEMS ADMIN
                    </span>
                <?php elseif ($is_admin): ?>
                    <span style="display: inline-block; background: #c62828; color: white; 
                                font-size: 11px; font-weight: 700; padding: 5px 10px; 
                                margin-right: 12px; border-radius: 4px; letter-spacing: 0.8px; 
                                text-transform: uppercase;">
                        User Admin
                    </span>
                <?php endif; ?>
                <span style="color: <?= $is_special ? '#00b291' : ($is_admin ? '#c62828' : 'inherit') ?>; 
                            font-weight: <?= ($is_special || $is_admin) ? '700' : 'normal' ?>;">
                    <?= $full_name ?>
                </span>
                </td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><span class="<?= $status_class ?>"><?= ucfirst($u['status']) ?></span></td>
                <td><?= $u['start_date'] ?: '-' ?></td>
                <td><?= $u['end_date']   ?: '-' ?></td>
                <td style="white-space:nowrap;">
                    <button onclick="openEditModal(
                        <?= $u['user_id'] ?>, 
                        '<?= htmlspecialchars(addslashes($u['first_name'])) ?>', 
                        '<?= htmlspecialchars(addslashes($u['last_name'])) ?>', 
                        '<?= htmlspecialchars(addslashes($u['email'])) ?>', 
                        '<?= $u['status'] ?>', 
                        '<?= $u['start_date'] ?>', 
                        '<?= $u['end_date'] ?>'
                    )" class="action-btn btn-edit">Edit</button>
                    
                    <a href="user_permissions.php?user_id=<?= $u['user_id'] ?>" class="action-btn btn-default">Permissions</a>
                    
                    <?php if ($u['status'] !== 'terminated'): ?>
                    <button type="button" class="action-btn btn-danger"
                            onclick="showTerminateModal(<?= $u['user_id'] ?>)">
                        Terminate
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>">← Previous</a>
        <?php endif; ?>

        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <?php if ($i == $page): ?>
                <span class="current"><?= $i ?></span>
            <?php else: ?>
                <a href="?page=<?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page + 1 ?>">Next →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div>

<!-- EDIT USER MODAL -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Edit User</h2>
            <span class="close-modal" onclick="closeEditModal()">&times;</span>
        </div>
        
        <form id="editForm" method="POST">
            <input type="hidden" name="update" value="1">
            <input type="hidden" id="modal_user_id" name="user_id" value="">

            <div class="form-grid">
                <div>
                    <label>First Name *</label>
                    <input type="text" id="modal_first_name" name="first_name" required>
                </div>
                <div>
                    <label>Last Name *</label>
                    <input type="text" id="modal_last_name" name="last_name" required>
                </div>
                <div class="full-width">
                    <label>Email *</label>
                    <input type="email" id="modal_email" name="email" required>
                </div>
                <div>
                    <label>Status</label>
                    <select id="modal_status" name="status" required>
                        <option value="active">Active</option>
                        <option value="contract">Contract</option>
                        <option value="suspended">Suspended</option>
                        <option value="terminated">Terminated</option>
                    </select>
                </div>
                <div>
                    <label>Start Date</label>
                    <input type="date" id="modal_start_date" name="start_date">
                </div>
                <div>
                    <label>End Date</label>
                    <input type="date" id="modal_end_date" name="end_date">
                </div>
            </div>

            <button type="submit" class="btn-update">Update User</button>
            <button type="button" onclick="closeEditModal()" 
                    style="padding:9px 20px; font-size:15px; background:#6c757d; color:white; border:none; border-radius:5px; margin-left:10px; cursor:pointer;">
                Cancel
            </button>
        </form>
    </div>
</div>

<!-- TERMINATE MODAL (unchanged) -->
<div id="terminateModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeTerminateModal()">×</span>
        <h2>⚠️ Terminate User</h2>
        
        <div id="modalUserInfo" style="margin:20px 0; padding:14px; background:#fff0f0; border:2px solid #c00; border-radius:6px;"></div>
        <div id="modalSitesInfo"></div>
        
        <form id="terminateForm" method="POST" style="margin-top:24px;">
            <input type="hidden" name="terminate" value="1">
            <input type="hidden" name="user_id" id="modalUserId">
            
            <div style="text-align:right; margin-top:2em;">
                <button type="button" onclick="closeTerminateModal()" style="padding:10px 20px; margin-right:12px; background:#6c757d; color:white; border:none; border-radius:4px; cursor:pointer;">
                    Cancel
                </button>
                <button type="submit" class="btn-danger" style="padding:10px 24px; background:#dc3545; color:white; border:none; border-radius:4px; cursor:pointer;"
                        onclick="return confirm('• YOU MUST NOTIFY NECESSARY ADMINS TO REMOVE THIS USER FROM THESE SITES:\n\nAction CANNOT be undone.\n\nFINAL CONFIRMATION\n\nThis will:\n• Set status = terminated\n• Set end_date = now\n• Remove ALL permissions\n\nProceed?');">
                    YES — TERMINATE THIS USER
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Edit Modal Functions
function openEditModal(id, firstName, lastName, email, status, startDate, endDate) {
    document.getElementById('modal_user_id').value = id;
    document.getElementById('modal_first_name').value = firstName;
    document.getElementById('modal_last_name').value = lastName;
    document.getElementById('modal_email').value = email;
    document.getElementById('modal_status').value = status;
    document.getElementById('modal_start_date').value = startDate || '';
    document.getElementById('modal_end_date').value = endDate || '';
    
    document.getElementById('editModal').style.display = 'flex';
    
    setTimeout(() => {
        document.getElementById('modal_first_name').focus();
    }, 100);
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

// Terminate Modal Functions (unchanged)
function escapeHtml(text) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return text.replace(/[&<>"']/g, m => map[m]);
}

function showTerminateModal(userId) {
    document.getElementById('modalUserId').value = userId;
    document.getElementById('modalUserInfo').innerHTML = '<em>Loading...</em>';
    document.getElementById('modalSitesInfo').innerHTML = '';

    fetch(`terminate_info.php?user_id=${userId}`)
        .then(r => {
            if (!r.ok) throw new Error('Network error');
            return r.json();
        })
        .then(data => {
            if (data.error) {
                alert(data.error);
                closeTerminateModal();
                return;
            }

            document.getElementById('modalUserInfo').innerHTML = `
                <strong>Terminating:</strong><br>
                ${escapeHtml(data.user.name)}<br>
                <strong>Email:</strong> ${escapeHtml(data.user.email)}
            `;

            let html = `<h3 style="color:#900; margin:1.6em 0 0.8em;">Sites & Admins to Notify:</h3>`;

            if (data.sites.length === 0) {
                html += `<p style="color:#555; font-style:italic;">No site permissions assigned.</p>`;
            } else {
                html += '<ul class="sites-list">';
                data.sites.forEach(site => {
                    let admins = site.admins?.length > 0
                        ? site.admins.map(a => `${escapeHtml(a.first_name + ' ' + a.last_name)} &lt;${escapeHtml(a.email)}&gt;`).join(', ')
                        : '<em>None</em>';

                    html += `
                        <li>
                            <strong>${escapeHtml(site.website_name)}</strong> 
                            <span style="color:#006600;">(${site.permission_level})</span><br>
                            <strong>Admins:</strong> ${admins}
                        </li>`;
                });
                html += '</ul>';
            }

            document.getElementById('modalSitesInfo').innerHTML = html;
            document.getElementById('terminateModal').style.display = 'flex';
        })
        .catch(err => {
            console.error(err);
            alert('Failed to load termination details.');
            closeTerminateModal();
        });
}

function closeTerminateModal() {
    document.getElementById('terminateModal').style.display = 'none';
}

window.onclick = function(e) {
    const editModal = document.getElementById('editModal');
    const terminateModal = document.getElementById('terminateModal');
    
    if (e.target === editModal) closeEditModal();
    if (e.target === terminateModal) closeTerminateModal();
}

document.addEventListener('keydown', function(event) {
    if (event.key === "Escape") {
        closeEditModal();
        closeTerminateModal();
    }
});
</script>

</body>
</html>