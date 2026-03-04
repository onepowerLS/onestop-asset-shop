<?php
/**
 * Login Page - Interface aligned with PR system (pr.1pwrafrica.com)
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/firebase.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = (string)($_SESSION['auth_error'] ?? '');
unset($_SESSION['auth_error']);

$firebaseCfg = am_firebase_config();
$firebaseConfigured = !empty($firebaseCfg['api_key']) && !empty($firebaseCfg['project_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In - Asset Management</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: 'Roboto', sans-serif;
            background-color: #f5f5f5;
        }
        .login-card {
            width: 100%;
            max-width: 560px;
            padding: 32px;
            border-radius: 8px;
            background-color: #ffffff;
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }
        .login-title {
            margin: 0 0 24px 0;
            font-size: 1.5rem;
            font-weight: 500;
            color: rgba(0,0,0,0.87);
        }
        .alert {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 16px;
            font-size: 0.875rem;
        }
        .alert-info {
            background-color: #e3f2fd;
            color: #1565c0;
        }
        .alert-danger {
            background-color: #ffebee;
            color: #c62828;
        }
        .field {
            margin: 16px 0;
        }
        .field label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.875rem;
            color: rgba(0,0,0,0.6);
        }
        .field input {
            width: 100%;
            padding: 14px 14px;
            font-size: 1rem;
            font-family: inherit;
            border: 1px solid rgba(0,0,0,0.23);
            border-radius: 4px;
            background: #fff;
        }
        .field input:focus {
            outline: none;
            border-color: #1976d2;
            border-width: 2px;
            padding: 13px 13px;
        }
        .button-row {
            display: flex;
            gap: 16px;
            align-items: stretch;
            margin-top: 24px;
            margin-bottom: 16px;
        }
        .btn {
            flex: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 8px 22px;
            font-size: 0.875rem;
            font-weight: 500;
            font-family: inherit;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.2s;
        }
        .btn-primary {
            background-color: #1976d2;
            color: #fff;
        }
        .btn-primary:hover:not(:disabled) {
            background-color: #1565c0;
        }
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .forgot-link {
            margin-top: 16px;
            text-align: center;
        }
        .forgot-link a {
            font-size: 0.875rem;
            color: #1976d2;
            text-decoration: none;
        }
        .forgot-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h1 class="login-title">Sign In to the Systems</h1>

        <?php if (!$firebaseConfigured): ?>
        <div class="alert alert-info">
            Firebase authentication is not configured. Add <code>FIREBASE_WEB_API_KEY</code> and <code>FIREBASE_PROJECT_ID</code> to <code>.env</code>.
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="/auth/firebase-login.php">
            <div class="field">
                <label for="identifier">Username or Email</label>
                <input type="text" id="identifier" name="identifier" placeholder="Enter your username or email" required autofocus autocomplete="username">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
            </div>

            <div class="button-row">
                <button type="submit" class="btn btn-primary" <?php echo $firebaseConfigured ? '' : 'disabled'; ?>>
                    Asset Management
                </button>
                <a href="https://pr.1pwrafrica.com/" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
                    Procurement
                </a>
                <a href="http://prod.1pwrafrica.com/" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
                    Job Cards
                </a>
            </div>
        </form>

        <div class="forgot-link">
            <a href="#">Forgot Password?</a>
        </div>
    </div>
</body>
</html>
