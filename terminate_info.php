<?php
// terminate_info.php - JSON data for the terminate modal
include 'db.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authorized']);
    exit;
}

$user_id = (int)($_GET['user_id'] ?? 0);
if (!$user_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing user_id']);
    exit;
}

// Get user
$stmt = $pdo->prepare("SELECT first_name, last_name, email FROM Users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

//gt all sites this user has access to
$stmt = $pdo->prepare("
    SELECT w.website_id, w.website_name, p.permission_level
    FROM Permissions p
    JOIN Websites w ON p.website_id = w.website_id
    WHERE p.user_id = ? AND p.permission_level != 'none'
    ORDER BY w.website_name
");
$stmt->execute([$user_id]);
$user_sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

$result = [
    'user'  => [
        'name'  => trim($user['first_name'] . ' ' . $user['last_name']),
        'email' => $user['email']
    ],
    'sites' => []
];

foreach ($user_sites as $site) {
    // Get active admins for this site
    $admins_stmt = $pdo->prepare("
        SELECT u.first_name, u.last_name, u.email
        FROM Users u
        JOIN Permissions p ON u.user_id = p.user_id
        WHERE p.website_id = ? 
          AND p.permission_level = 'admin'
          AND u.status = 'active'
        ORDER BY u.last_name, u.first_name
    ");
    $admins_stmt->execute([$site['website_id']]);
    $admins = $admins_stmt->fetchAll(PDO::FETCH_ASSOC);

    $result['sites'][] = [
        'website_name'     => $site['website_name'],
        'permission_level' => $site['permission_level'],
        'admins'           => $admins
    ];
}

header('Content-Type: application/json');
echo json_encode($result);