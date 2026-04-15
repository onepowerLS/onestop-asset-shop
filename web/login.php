<?php
/**
 * Login Page — Uses Firebase JS SDK client-side (same as PR portal)
 * so that Firebase auth state is established in the browser.
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
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='24' font-size='24' font-weight='bold' fill='%231976d2'>1P</text></svg>">
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
            max-width: 420px;
            padding: 32px;
            border-radius: 8px;
            background-color: #ffffff;
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }
        .login-title {
            margin: 0 0 4px 0;
            font-size: 1.5rem;
            font-weight: 500;
            color: rgba(0,0,0,0.87);
        }
        .login-subtitle {
            margin: 0 0 24px 0;
            font-size: 0.875rem;
            color: rgba(0,0,0,0.54);
        }
        .alert {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 16px;
            font-size: 0.875rem;
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
            padding: 14px;
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
            padding: 13px;
        }
        .btn-signin {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 10px 22px;
            margin-top: 24px;
            font-size: 0.95rem;
            font-weight: 500;
            font-family: inherit;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            background-color: #1976d2;
            color: #fff;
            transition: background-color 0.2s;
        }
        .btn-signin:hover:not(:disabled) {
            background-color: #1565c0;
        }
        .btn-signin:disabled {
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
        .spinner { display: none; width: 18px; height: 18px; border: 2px solid rgba(255,255,255,0.4); border-top-color: #fff; border-radius: 50%; animation: spin .6s linear infinite; margin-right: 8px; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="login-card">
        <h1 class="login-title">Asset Management</h1>
        <p class="login-subtitle">Sign in with your 1PWR account</p>

        <div id="errorAlert" class="alert alert-danger" style="display:<?php echo $error ? 'block' : 'none'; ?>;">
            <span id="errorText"><?php echo htmlspecialchars($error); ?></span>
        </div>

        <form id="loginForm" onsubmit="return handleLogin(event)">
            <div class="field">
                <label for="identifier">Email</label>
                <input type="email" id="identifier" name="identifier" placeholder="Enter your 1PWR email" required autofocus autocomplete="username">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn-signin" id="signinBtn" <?php echo $firebaseConfigured ? '' : 'disabled'; ?>>
                <span class="spinner" id="spinner"></span>
                <span id="btnLabel">Sign In</span>
            </button>
        </form>

        <div class="forgot-link">
            <a href="#" onclick="handleForgotPassword(); return false;">Forgot Password?</a>
        </div>
    </div>

    <!-- Firebase JS SDK (same as PR portal) -->
    <script type="module">
    import { initializeApp } from 'https://www.gstatic.com/firebasejs/11.3.0/firebase-app.js';
    import { getAuth, signInWithEmailAndPassword, sendPasswordResetEmail } from 'https://www.gstatic.com/firebasejs/11.3.0/firebase-auth.js';

    const firebaseConfig = {
        apiKey: '<?php echo addslashes($firebaseCfg['api_key']); ?>',
        authDomain: 'pr-system-4ea55.firebaseapp.com',
        projectId: '<?php echo addslashes($firebaseCfg['project_id']); ?>',
        appId: '1:562987209098:web:2f788d189f1c0867cb3873'
    };

    const app = initializeApp(firebaseConfig);
    const auth = getAuth(app);

    window._firebaseAuth = auth;
    window._signInWithEmailAndPassword = signInWithEmailAndPassword;
    window._sendPasswordResetEmail = sendPasswordResetEmail;
    </script>

    <script>
    function showError(msg) {
        document.getElementById('errorText').textContent = msg;
        document.getElementById('errorAlert').style.display = 'block';
    }

    function setLoading(on) {
        var btn = document.getElementById('signinBtn');
        var spinner = document.getElementById('spinner');
        var label = document.getElementById('btnLabel');
        btn.disabled = on;
        spinner.style.display = on ? 'inline-block' : 'none';
        label.textContent = on ? 'Signing in...' : 'Sign In';
    }

    async function handleLogin(e) {
        e.preventDefault();
        var email = document.getElementById('identifier').value.trim();
        var password = document.getElementById('password').value;

        if (!email || !password) {
            showError('Please enter both email and password.');
            return false;
        }

        setLoading(true);
        document.getElementById('errorAlert').style.display = 'none';

        try {
            var cred = await window._signInWithEmailAndPassword(window._firebaseAuth, email, password);
            var idToken = await cred.user.getIdToken();

            var resp = await fetch('/auth/firebase-login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id_token: idToken,
                    uid: cred.user.uid,
                    email: cred.user.email,
                    refresh_token: cred.user.refreshToken || ''
                })
            });

            var data = await resp.json();
            if (data.ok) {
                window.location.href = data.redirect || '/index.php';
            } else {
                showError(data.error || 'Login failed.');
                setLoading(false);
            }
        } catch (err) {
            var msg = 'Sign in failed.';
            if (err.code === 'auth/invalid-credential' || err.code === 'auth/wrong-password' || err.code === 'auth/user-not-found') {
                msg = 'Invalid email or password.';
            } else if (err.code === 'auth/too-many-requests') {
                msg = 'Too many failed attempts. Please try again later.';
            } else if (err.code === 'auth/network-request-failed') {
                msg = 'Network error. Check your connection.';
            }
            showError(msg);
            setLoading(false);
        }
        return false;
    }

    function handleForgotPassword() {
        var email = document.getElementById('identifier').value.trim();
        if (!email) {
            showError('Enter your email first, then click Forgot Password.');
            return;
        }
        if (!window._sendPasswordResetEmail) return;
        window._sendPasswordResetEmail(window._firebaseAuth, email)
            .then(function() { alert('Password reset email sent to ' + email); })
            .catch(function() { showError('Could not send reset email. Check your email address.'); });
    }
    </script>
</body>
</html>
