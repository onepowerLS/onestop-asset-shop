<?php
/**
 * Create a Firebase Auth user plus a users/{uid} Firestore profile with role Auditor (read-only in AM).
 * Requires an AM Admin session; Firestore rules require permissionLevel >= 5 to create users docs.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/firebase.php';
require_login();

if (($_SESSION['role'] ?? '') !== 'Admin') {
    $_SESSION['flash_error'] = 'Admin access required.';
    header('Location: ' . base_url('index.php'));
    exit;
}

$page_title = 'Provision auditor account';
/** Default shared test/auditor login email (override in the form if needed). */
$default_auditor_email = 'testuser@1pwrafrica.com';
$generatedPassword = null;
$generatedEmail = null;
$provisionError = null;
$provisionOk = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if ($email === '') {
        $email = $default_auditor_email;
    }
    $first = trim($_POST['first_name'] ?? 'External');
    $last = trim($_POST['last_name'] ?? 'Auditor');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $provisionError = 'Enter a valid email address.';
    } else {
        $password = am_firebase_generate_random_password(22);
        $signUp = am_firebase_sign_up($email, $password);
        if (!$signUp['ok']) {
            $provisionError = $signUp['message'] ?? 'Sign-up failed.';
        } else {
            $uid = (string)($signUp['uid'] ?? '');
            if ($uid === '') {
                $provisionError = 'Sign-up returned no user id.';
            } else {
                $doc = [
                    'firstName' => $first,
                    'lastName' => $last,
                    'role' => 'Auditor',
                    'permissionLevel' => 2,
                    'department' => 'External Audit',
                    'organization' => '1PWR',
                    'isActive' => true,
                    'email' => $email,
                ];
                $cr = am_firestore_create_document('users', $doc, $uid);
                if (!$cr['ok']) {
                    $provisionError = 'Firebase Auth user was created but Firestore profile failed: '
                        . ($cr['error'] ?? 'Unknown')
                        . ' Delete the Auth user in Firebase Console if you need to retry.';
                } else {
                    $provisionOk = true;
                    $generatedPassword = $password;
                    $generatedEmail = $email;
                }
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between align-items-center py-4">
        <div>
            <h1 class="h2"><?php echo htmlspecialchars($page_title); ?></h1>
            <p class="mb-0 text-gray-600">Creates read-only access to Asset Management (no edits). The usual auditor test email is <strong><?php echo htmlspecialchars($default_auditor_email); ?></strong>. Share the password once; rotate it in Firebase if needed.</p>
        </div>
        <a href="<?php echo base_url('index.php'); ?>" class="btn btn-secondary btn-sm">Dashboard</a>
    </div>

    <?php if ($provisionError): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($provisionError); ?></div>
    <?php endif; ?>

    <?php if ($provisionOk && $generatedEmail && $generatedPassword): ?>
    <div class="card border-0 shadow mb-4 border-success">
        <div class="card-header bg-success text-white"><strong>Account created</strong></div>
        <div class="card-body">
            <p class="mb-2">Send these to the auditor (copy before leaving this page):</p>
            <ul class="mb-0">
                <li><strong>Login URL:</strong> <code>https://am.1pwrafrica.com/login.php</code></li>
                <li><strong>Email:</strong> <code><?php echo htmlspecialchars($generatedEmail); ?></code></li>
                <li><strong>Password:</strong> <code id="auditor-pass"><?php echo htmlspecialchars($generatedPassword); ?></code></li>
            </ul>
            <p class="small text-muted mt-3 mb-0">This password is shown only here. To change it later, use Firebase Authentication for the project or ask the user to use “Forgot password” if enabled.</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow">
        <div class="card-body">
            <form method="post" action="" class="row g-3" autocomplete="off">
                <div class="col-12 col-md-6">
                    <label class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control" required
                        value="<?php echo htmlspecialchars($_POST['email'] ?? $default_auditor_email); ?>"
                        placeholder="<?php echo htmlspecialchars($default_auditor_email); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">First name</label>
                    <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($_POST['first_name'] ?? 'External'); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">Last name</label>
                    <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($_POST['last_name'] ?? 'Auditor'); ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-plus me-2"></i>Create auditor account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
