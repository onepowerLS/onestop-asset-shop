<?php
/**
 * Nexus SSO receiver.
 *
 * Nexus redirects here with ?sso_token=<Firebase custom token>&nonce=...&from=nexus
 * (minted by the Nexus mintSSOToken Cloud Function, same pr-system-4ea55 project).
 * Client-side we exchange the custom token for a Firebase session
 * (signInWithCustomToken), then reuse the exact same server flow as login.php:
 * POST the ID token to /auth/firebase-login.php, which verifies it, loads the
 * PR user profile and creates the PHP session.
 *
 * ?return=<path> (optional, same-site path only) is where we land afterwards.
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/firebase.php';

if (is_logged_in()) {
    redirect('index.php');
}

$firebaseCfg = am_firebase_config();

// Same-site relative path to resume after sign-in (guard against open redirect).
$return = (string)($_GET['return'] ?? '/index.php');
if ($return === '' || $return[0] !== '/' || strpos($return, '//') === 0) {
    $return = '/index.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Signing in… - Asset Management</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-family: 'Roboto', sans-serif;
            background-color: #f5f5f5;
            color: rgba(0,0,0,0.6);
        }
        .spinner {
            width: 36px;
            height: 36px;
            border: 4px solid rgba(25,118,210,0.2);
            border-top-color: #1976d2;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-bottom: 16px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        #error { color: #c62828; max-width: 420px; text-align: center; padding: 0 16px; }
        #error a { color: #1976d2; }
    </style>
</head>
<body>
    <div class="spinner" id="spinnerEl"></div>
    <p id="status">Signing you in via Nexus&hellip;</p>
    <p id="error" style="display:none"></p>

    <script type="module">
    import { initializeApp } from 'https://www.gstatic.com/firebasejs/11.3.0/firebase-app.js';
    import { getAuth, signInWithCustomToken } from 'https://www.gstatic.com/firebasejs/11.3.0/firebase-auth.js';

    const firebaseConfig = {
        apiKey: '<?php echo addslashes($firebaseCfg['api_key']); ?>',
        authDomain: 'pr-system-4ea55.firebaseapp.com',
        projectId: '<?php echo addslashes($firebaseCfg['project_id']); ?>',
        appId: '1:562987209098:web:2f788d189f1c0867cb3873'
    };

    function fail(msg) {
        document.getElementById('spinnerEl').style.display = 'none';
        document.getElementById('status').style.display = 'none';
        const el = document.getElementById('error');
        el.innerHTML = msg + ' <a href="/login.php?fallback=1">Sign in manually</a> or relaunch from <a href="https://nexus.1pwrafrica.com">Nexus</a>.';
        el.style.display = 'block';
    }

    (async () => {
        const params = new URLSearchParams(location.search);
        const token = params.get('sso_token');
        const from = params.get('from');
        if (!token || from !== 'nexus') {
            fail('Invalid sign-on link.');
            return;
        }
        try {
            const app = initializeApp(firebaseConfig);
            const auth = getAuth(app);
            const cred = await signInWithCustomToken(auth, token);
            const idToken = await cred.user.getIdToken();

            const resp = await fetch('/auth/firebase-login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id_token: idToken,
                    uid: cred.user.uid,
                    email: cred.user.email || '',
                    refresh_token: cred.user.refreshToken || ''
                })
            });
            const data = await resp.json();
            if (data.ok) {
                window.location.replace(<?php echo json_encode($return); ?>);
            } else {
                fail(data.error || 'Sign-in failed.');
            }
        } catch (err) {
            console.error('[Nexus SSO] failed', err);
            fail('Your sign-in link is invalid or expired.');
        }
    })();
    </script>
</body>
</html>
