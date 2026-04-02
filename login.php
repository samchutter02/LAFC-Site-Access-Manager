<?php
include 'db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("
        SELECT user_id, first_name, last_name, email, password 
        FROM Users 
        WHERE email = ? 
          AND is_dashboard_admin = 1 
          AND status = 'active' 
          AND (end_date IS NULL OR end_date > NOW()) 
          AND deleted_at IS NULL
    ");
    $stmt->execute([$email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin && password_verify($password, $admin['password'])) {
        $_SESSION['admin_id']    = $admin['user_id'];
        $_SESSION['admin_name']  = trim($admin['first_name'] . ' ' . $admin['last_name']);
        $_SESSION['admin_email'] = $admin['email'];    
        header("Location: index.php");
        logActivity($pdo, $_SESSION['admin_id'], 'login', 'user', $user_id, "Successfully logged in");
        exit;
    } else {
        $error = "Invalid email or password";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - LAFC Site Permissions</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="login-container">
        <h1>LAFC Site Permissions Matrix</h1>
        <?php if (isset($error)) echo '<p style="color:#900; font-size:13px;">' . htmlspecialchars($error) . '</p>'; ?>
        <form method="POST">
            Email:<br>
            <input type="email" name="email" required autofocus><br><br>
            Password:<br>
            <input type="password" name="password" required><br><br>
            <button type="submit">Login</button>
            <a href="#"><p style="margin: 10px 0 10px 0; text-decoration: underline; color: blue;">Forgot your password?</p></a>
        </form>
    </div>
</body>
</html>