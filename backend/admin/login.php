<?php
/**
 * SiteTracker Admin - Login Portal
 */
require_once dirname(__DIR__) . '/backend/config/database.php';

// If logout is requested
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header("Location: login.php?logged_out=1");
    exit;
}

// If already logged in, redirect to dashboard
if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

if (isset($_GET['logged_out'])) {
    $success = 'You have been signed out of SiteTracker Operations Hub.';
}

// Process POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginInput = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($loginInput) || empty($password)) {
        $error = 'Please enter both your username/email and password.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `username` = ? OR `email` = ? LIMIT 1");
            $stmt->execute([$loginInput, $loginInput]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] !== 'active') {
                    $error = 'Your account has been deactivated. Please contact your administrator.';
                } else {
                    $updateStmt = $pdo->prepare("UPDATE `users` SET `last_login` = NOW() WHERE `id` = ?");
                    $updateStmt->execute([$user['id']]);

                    $nameParts = explode(' ', trim($user['full_name']));
                    $initials = count($nameParts) >= 2
                        ? strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts) - 1], 0, 1))
                        : strtoupper(substr($user['full_name'], 0, 2));

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['user_initials'] = $initials ?: 'AD';

                    header('Location: dashboard.php');
                    exit;
                }
            } else {
                $error = 'Invalid credentials. Please verify your login details.';
            }
        } catch (PDOException $e) {
            $error = 'Database service error: ' . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Sign In • SiteTracker Operations Hub</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <style>
    :root {
      --primary: #0066FF;
      --primary-hover: #0052CC;
      --primary-glow: rgba(0, 102, 255, 0.4);
      --bg-dark: #0A0F1D;
      --card-bg: rgba(15, 23, 42, 0.85);
      --card-border: rgba(255, 255, 255, 0.08);
      --input-bg: rgba(255, 255, 255, 0.04);
      --input-border: rgba(255, 255, 255, 0.12);
      --input-focus: #0066FF;
      --text-main: #FFFFFF;
      --text-muted: #94A3B8;
      --danger: #EF4444;
      --danger-bg: rgba(239, 68, 68, 0.12);
      --danger-border: rgba(239, 68, 68, 0.25);
      --success: #10B981;
      --success-bg: rgba(16, 185, 129, 0.12);
      --success-border: rgba(16, 185, 129, 0.25);
      --radius-sm: 8px;
      --radius-md: 12px;
      --radius-lg: 18px;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }

    body {
      background-color: var(--bg-dark);
      color: var(--text-main);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      overflow-x: hidden;
      padding: 24px;
    }

    .ambient-glow-1 {
      position: fixed;
      top: -150px;
      left: -150px;
      width: 550px;
      height: 550px;
      background: radial-gradient(circle, rgba(0, 102, 255, 0.18) 0%, rgba(0, 102, 255, 0) 70%);
      border-radius: 50%;
      pointer-events: none;
      z-index: 0;
    }

    .ambient-glow-2 {
      position: fixed;
      bottom: -150px;
      right: -150px;
      width: 600px;
      height: 600px;
      background: radial-gradient(circle, rgba(16, 185, 129, 0.12) 0%, rgba(16, 185, 129, 0) 70%);
      border-radius: 50%;
      pointer-events: none;
      z-index: 0;
    }

    .ambient-grid {
      position: fixed;
      inset: 0;
      background-image: 
        linear-gradient(to right, rgba(255, 255, 255, 0.02) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
      background-size: 40px 40px;
      pointer-events: none;
      z-index: 0;
    }

    .login-container {
      width: 100%;
      max-width: 440px;
      position: relative;
      z-index: 10;
    }

    .login-card {
      background: var(--card-bg);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid var(--card-border);
      border-radius: var(--radius-lg);
      padding: 40px 36px;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.05);
      animation: fadeIn 0.5s ease-out;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(16px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .brand-header {
      text-align: center;
      margin-bottom: 32px;
    }

    .brand-logo-badge {
      width: 58px;
      height: 58px;
      background: linear-gradient(135deg, #0066FF, #00C6FF);
      border-radius: 16px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 26px;
      color: white;
      box-shadow: 0 8px 24px var(--primary-glow);
      margin-bottom: 18px;
    }

    .brand-header h1 {
      font-size: 24px;
      font-weight: 800;
      letter-spacing: -0.5px;
      color: #FFFFFF;
      margin-bottom: 6px;
    }

    .brand-header p {
      color: var(--text-muted);
      font-size: 14px;
    }

    .alert {
      padding: 12px 16px;
      border-radius: var(--radius-md);
      font-size: 13.5px;
      line-height: 1.4;
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 24px;
    }

    .alert-danger {
      background: var(--danger-bg);
      border: 1px solid var(--danger-border);
      color: #FCA5A5;
    }

    .alert-success {
      background: var(--success-bg);
      border: 1px solid var(--success-border);
      color: #6EE7B7;
    }

    .form-group {
      margin-bottom: 20px;
    }

    .form-group label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      color: #CBD5E1;
      margin-bottom: 8px;
    }

    .input-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }

    .input-icon {
      position: absolute;
      left: 14px;
      color: var(--text-muted);
      font-size: 15px;
      pointer-events: none;
    }

    .form-control {
      width: 100%;
      background: var(--input-bg);
      border: 1px solid var(--input-border);
      border-radius: var(--radius-md);
      padding: 13px 42px 13px 40px;
      font-size: 14.5px;
      color: #FFFFFF;
      outline: none;
      transition: all 0.2s ease;
    }

    .form-control::placeholder {
      color: #64748B;
    }

    .form-control:focus {
      border-color: var(--input-focus);
      background: rgba(0, 102, 255, 0.05);
      box-shadow: 0 0 0 3px rgba(0, 102, 255, 0.2);
    }

    .form-control:focus + .input-icon,
    .input-wrapper:focus-within .input-icon {
      color: var(--primary);
    }

    .password-toggle-btn {
      position: absolute;
      right: 14px;
      background: none;
      border: none;
      color: var(--text-muted);
      cursor: pointer;
      font-size: 15px;
      padding: 4px;
    }

    .password-toggle-btn:hover {
      color: #FFFFFF;
    }

    .form-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 24px;
      font-size: 13px;
    }

    .checkbox-container {
      display: flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
      user-select: none;
      color: var(--text-muted);
    }

    .checkbox-container input {
      accent-color: var(--primary);
      width: 16px;
      height: 16px;
      cursor: pointer;
    }

    .forgot-link {
      color: #60A5FA;
      text-decoration: none;
      font-weight: 500;
    }

    .btn-submit {
      width: 100%;
      background: linear-gradient(135deg, #0066FF, #0052CC);
      color: #FFFFFF;
      border: none;
      border-radius: var(--radius-md);
      padding: 14px;
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      transition: all 0.2s ease;
      box-shadow: 0 4px 14px var(--primary-glow);
    }

    .btn-submit:hover {
      background: linear-gradient(135deg, #0052CC, #0040A8);
      transform: translateY(-1px);
    }

    .demo-credentials-box {
      margin-top: 24px;
      padding: 14px 16px;
      background: rgba(255, 255, 255, 0.03);
      border: 1px dashed rgba(255, 255, 255, 0.12);
      border-radius: var(--radius-md);
    }

    .demo-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 8px;
    }

    .demo-header span {
      font-size: 12px;
      font-weight: 700;
      color: #94A3B8;
      text-transform: uppercase;
    }

    .demo-fill-btn {
      background: rgba(0, 102, 255, 0.15);
      border: 1px solid rgba(0, 102, 255, 0.3);
      color: #60A5FA;
      font-size: 11.5px;
      font-weight: 600;
      padding: 4px 10px;
      border-radius: 6px;
      cursor: pointer;
    }

    .demo-fill-btn:hover {
      background: var(--primary);
      color: #FFFFFF;
    }

    .demo-info {
      font-size: 12px;
      color: #64748B;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .demo-info code {
      color: #E2E8F0;
      background: rgba(255, 255, 255, 0.06);
      padding: 2px 6px;
      border-radius: 4px;
      font-family: monospace;
    }

    .system-status {
      margin-top: 28px;
      text-align: center;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
    }

    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      background: rgba(16, 185, 129, 0.1);
      border: 1px solid rgba(16, 185, 129, 0.2);
      border-radius: 20px;
      color: #34D399;
      font-weight: 600;
      font-size: 11.5px;
    }

    .status-dot {
      width: 7px;
      height: 7px;
      background-color: #10B981;
      border-radius: 50%;
      box-shadow: 0 0 8px #10B981;
    }
  </style>
</head>
<body>

  <div class="ambient-glow-1"></div>
  <div class="ambient-glow-2"></div>
  <div class="ambient-grid"></div>

  <div class="login-container">
    <div class="login-card">
      
      <div class="brand-header">
        <div class="brand-logo-badge">
          <i class="fa-solid fa-map-location-dot"></i>
        </div>
        <h1>SiteTracker Admin</h1>
        <p>Operations Command & Construction Live Tracker</p>
      </div>

      <?php if (!empty($error)): ?>
        <div class="alert alert-danger" role="alert">
          <i class="fa-solid fa-circle-exclamation"></i>
          <div><?= htmlspecialchars($error) ?></div>
        </div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
        <div class="alert alert-success" role="alert">
          <i class="fa-solid fa-circle-check"></i>
          <div><?= htmlspecialchars($success) ?></div>
        </div>
      <?php endif; ?>

      <form action="login.php" method="POST" id="loginForm" autocomplete="on">
        <div class="form-group">
          <label for="username">Username or Email</label>
          <div class="input-wrapper">
            <i class="fa-regular fa-user input-icon"></i>
            <input 
              type="text" 
              name="username" 
              id="username" 
              class="form-control" 
              placeholder="e.g. admin or admin@sitetracker.com" 
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" 
              required 
              autofocus
            >
          </div>
        </div>

        <div class="form-group">
          <label for="password">Security Password</label>
          <div class="input-wrapper">
            <i class="fa-solid fa-lock input-icon"></i>
            <input 
              type="password" 
              name="password" 
              id="password" 
              class="form-control" 
              placeholder="••••••••" 
              required
            >
            <button type="button" class="password-toggle-btn" id="togglePasswordBtn" aria-label="Toggle password visibility">
              <i class="fa-regular fa-eye" id="toggleIcon"></i>
            </button>
          </div>
        </div>

        <div class="form-row">
          <label class="checkbox-container">
            <input type="checkbox" name="remember" id="remember">
            <span>Keep me signed in</span>
          </label>
          <a href="#" class="forgot-link" onclick="alert('Password reset available via sitetracker database.'); return false;">Forgot?</a>
        </div>

        <button type="submit" class="btn-submit" id="submitBtn">
          <span>Authorize Access</span>
          <i class="fa-solid fa-arrow-right"></i>
        </button>
      </form>

      <div class="demo-credentials-box">
        <div class="demo-header">
          <span><i class="fa-solid fa-key" style="margin-right: 5px;"></i> Pre-configured Access</span>
          <button type="button" class="demo-fill-btn" onclick="fillAdminCredentials()">Auto-fill Admin</button>
        </div>
        <div class="demo-info">
          <div>User: <code>admin</code> or <code>admin@sitetracker.com</code></div>
          <div>Pass: <code>admin123</code></div>
        </div>
      </div>

      <div class="system-status">
        <span class="status-badge">
          <span class="status-dot"></span>
          DB Connected: sitetracker
        </span>
      </div>

    </div>
  </div>

  <script>
    const togglePasswordBtn = document.getElementById('togglePasswordBtn');
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('toggleIcon');

    if (togglePasswordBtn && passwordInput && toggleIcon) {
      togglePasswordBtn.addEventListener('click', () => {
        const isPassword = passwordInput.getAttribute('type') === 'password';
        passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
        toggleIcon.classList.toggle('fa-eye', !isPassword);
        toggleIcon.classList.toggle('fa-eye-slash', isPassword);
      });
    }

    function fillAdminCredentials() {
      const usernameField = document.getElementById('username');
      const passwordField = document.getElementById('password');
      if (usernameField && passwordField) {
        usernameField.value = 'admin';
        passwordField.value = 'admin123';
        passwordField.focus();
      }
    }

    const loginForm = document.getElementById('loginForm');
    const submitBtn = document.getElementById('submitBtn');
    if (loginForm && submitBtn) {
      loginForm.addEventListener('submit', () => {
        submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Authenticating...';
        submitBtn.style.opacity = '0.85';
        submitBtn.style.pointerEvents = 'none';
      });
    }
  </script>
</body>
</html>
