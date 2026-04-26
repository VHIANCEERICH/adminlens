<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/constants.php';

function adminlens_normalize_ph_phone(string $phoneNumber): string
{
    $clean = preg_replace('/[^0-9+]/', '', trim($phoneNumber)) ?? '';

    if (preg_match('/^9\d{9}$/', $clean) === 1) {
        return '+63' . $clean;
    }

    if (str_starts_with($clean, '09')) {
        return '+63' . substr($clean, 1);
    }

    if (str_starts_with($clean, '639')) {
        return '+' . $clean;
    }

    if (str_starts_with($clean, '+639')) {
        return $clean;
    }

    return $clean;
}

function adminlens_is_valid_ph_phone(string $phoneNumber): bool
{
    return (bool) preg_match('/^\+639\d{9}$/', $phoneNumber);
}

function adminlens_verify_firebase_phone_token(string $idToken, string $expectedPhoneNumber, ?string &$error = null): bool
{
    $error = null;

    if (FIREBASE_API_KEY === '') {
        $error = 'Firebase API key is missing in config/constants.php.';
        return false;
    }

    $payload = json_encode(['idToken' => $idToken]);
    if ($payload === false) {
        $error = 'Unable to prepare Firebase verification request.';
        return false;
    }

    $ch = curl_init('https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . rawurlencode(FIREBASE_API_KEY));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        $firebaseMessage = null;
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                    $firebaseMessage = $decoded['error']['message'];
                } elseif (isset($decoded['message']) && is_string($decoded['message'])) {
                    $firebaseMessage = $decoded['message'];
                }
            }
        }

        $error = 'Firebase phone verification failed.';
        if ($firebaseMessage !== null && $firebaseMessage !== '') {
            $error .= ' ' . $firebaseMessage;
        } elseif ($curlError !== '') {
            $error .= ' ' . $curlError;
        } else {
            $error .= ' HTTP ' . $httpCode . '.';
        }

        return false;
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded) || !isset($decoded['users'][0]) || !is_array($decoded['users'][0])) {
        $error = 'Firebase did not return a verified user.';
        return false;
    }

    $user = $decoded['users'][0];
    $phoneNumber = isset($user['phoneNumber']) && is_string($user['phoneNumber']) ? $user['phoneNumber'] : '';
    if ($phoneNumber === '') {
        $error = 'Firebase user is missing a verified phone number.';
        return false;
    }

    if ($phoneNumber !== $expectedPhoneNumber) {
        $error = 'The verified Firebase phone number does not match the phone number you entered.';
        return false;
    }

    return true;
}

$pdo = adminlens_db();
$errors = [];
$registerErrors = [];
$successMessage = null;
$identifier = '';
$registerData = [
    'full_name' => '',
    'username' => '',
    'email' => '',
    'address' => '',
    'phone_number' => '+63',
];
$showRegisterModal = false;

$currentUser = adminlens_current_user();
if ($currentUser !== null) {
    adminlens_redirect(adminlens_dashboard_path($currentUser['role']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = (string) ($_POST['form_action'] ?? 'login');

    if ($formAction === 'create_account') {
        $showRegisterModal = true;
        $registerData['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
        $registerData['username'] = trim((string) ($_POST['register_username'] ?? ''));
        $registerData['email'] = trim((string) ($_POST['register_email'] ?? ''));
        $registerData['address'] = trim((string) ($_POST['register_address'] ?? ''));
        $registerData['phone_number'] = adminlens_normalize_ph_phone((string) ($_POST['register_phone_number'] ?? ''));
        $registerPassword = (string) ($_POST['register_password'] ?? '');
        $registerPasswordConfirm = (string) ($_POST['register_password_confirm'] ?? '');
        $firebaseIdToken = trim((string) ($_POST['firebase_id_token'] ?? ''));
        $verifiedPhoneNumber = trim((string) ($_POST['verified_phone_number'] ?? ''));

        if ($registerData['full_name'] === '') {
            $registerErrors[] = 'Full name is required.';
        }

        if ($registerData['username'] === '') {
            $registerErrors[] = 'Username is required.';
        } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $registerData['username'])) {
            $registerErrors[] = 'Username must be 3 to 30 characters and can only include letters, numbers, dots, dashes, and underscores.';
        }

        if ($registerData['email'] === '') {
            $registerErrors[] = 'Email is required.';
        } elseif (!filter_var($registerData['email'], FILTER_VALIDATE_EMAIL)) {
            $registerErrors[] = 'Enter a valid email address.';
        }

        if ($registerData['address'] === '') {
            $registerErrors[] = 'Address is required.';
        }

        if ($registerData['phone_number'] === '' || $registerData['phone_number'] === '+63') {
            $registerErrors[] = 'Phone number is required.';
        } elseif (!adminlens_is_valid_ph_phone($registerData['phone_number'])) {
            $registerErrors[] = 'Phone number must start with +63 and use a valid mobile format.';
        }

        if ($registerPassword === '') {
            $registerErrors[] = 'Password is required.';
        } elseif (strlen($registerPassword) < 8) {
            $registerErrors[] = 'Password must be at least 8 characters.';
        }

        if ($registerPasswordConfirm === '') {
            $registerErrors[] = 'Please confirm your password.';
        } elseif ($registerPassword !== $registerPasswordConfirm) {
            $registerErrors[] = 'Passwords do not match.';
        }

        if ($firebaseIdToken === '' || $verifiedPhoneNumber === '') {
            $registerErrors[] = 'Phone verification is required before the account can be created.';
        }

        if ($registerErrors === []) {
            $stmt = $pdo->prepare(
                'SELECT user_id
                 FROM users
                 WHERE username = :username OR email = :email OR phone_number = :phone_number
                 LIMIT 1'
            );
            $stmt->execute([
                'username' => $registerData['username'],
                'email' => $registerData['email'],
                'phone_number' => $registerData['phone_number'],
            ]);

            if ($stmt->fetch()) {
                $registerErrors[] = 'That username, email, or phone number is already in use.';
            }
        }

        if ($registerErrors === []) {
            $firebaseError = null;
            if (!adminlens_verify_firebase_phone_token($firebaseIdToken, $registerData['phone_number'], $firebaseError)) {
                $registerErrors[] = $firebaseError ?? 'Unable to verify the Firebase phone authentication result.';
            }
        }

        if ($registerErrors === []) {
            $userColumns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
            $insertData = [
                'full_name' => $registerData['full_name'],
                'username' => $registerData['username'],
                'email' => $registerData['email'],
                'password_hash' => password_hash($registerPassword, PASSWORD_DEFAULT),
                'role' => 'customer',
                'is_active' => 1,
            ];

            if (in_array('address', $userColumns, true)) {
                $insertData['address'] = $registerData['address'];
            }

            if (in_array('phone_number', $userColumns, true)) {
                $insertData['phone_number'] = $registerData['phone_number'];
            } elseif (in_array('phone', $userColumns, true)) {
                $insertData['phone'] = $registerData['phone_number'];
            } elseif (in_array('contact_number', $userColumns, true)) {
                $insertData['contact_number'] = $registerData['phone_number'];
            }

            $columns = array_keys($insertData);
            $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
            $insert = $pdo->prepare(
                'INSERT INTO users (' . implode(', ', $columns) . ')
                 VALUES (' . implode(', ', $placeholders) . ')'
            );
            $insert->execute($insertData);

            $identifier = $registerData['email'];
            $registerData = [
                'full_name' => '',
                'username' => '',
                'email' => '',
                'address' => '',
                'phone_number' => '+63',
            ];
            $showRegisterModal = false;
            $successMessage = 'Account created successfully. You can now log in as a customer.';
        }
    } else {
        $identifier = trim((string) ($_POST['identifier'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($identifier === '') {
            $errors[] = 'Username or email is required.';
        }

        if ($password === '') {
            $errors[] = 'Password is required.';
        }

        if ($errors === []) {
            $stmt = $pdo->prepare(
                'SELECT user_id, full_name, username, email, password_hash, role, is_active
                 FROM users
                 WHERE username = :username OR email = :email
                 LIMIT 1'
            );
            $stmt->execute([
                'username' => $identifier,
                'email' => $identifier,
            ]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, (string) $user['password_hash'])) {
                $errors[] = 'Invalid username/email or password.';
            } elseif ((int) $user['is_active'] !== 1) {
                $errors[] = 'Your account is inactive. Please contact support.';
            } elseif ((string) $user['role'] !== 'customer') {
                $errors[] = 'This login page is for customer accounts only.';
            } else {
                adminlens_login_user($user);
                adminlens_redirect(adminlens_url('/customer/dashboard.php'));
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Login</title>
    <style>
        :root {
            --bg: #edf7f2;
            --card: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --accent: #146c43;
            --accent-dark: #0f5333;
            --border: #cfe7d9;
            --error-bg: #fef2f2;
            --error-text: #b91c1c;
            --success-bg: #ecfdf3;
            --success-text: #166534;
            --overlay: rgba(15, 23, 42, 0.5);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: radial-gradient(circle at top, #f6fffb 0%, #dff2e7 100%);
            color: var(--text);
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 32px;
            box-shadow: 0 20px 50px rgba(20, 108, 67, 0.12);
        }
        h1 {
            margin: 0 0 8px;
            font-size: 30px;
        }
        p {
            margin: 0 0 24px;
            color: var(--muted);
        }
        .error-box {
            background: var(--error-bg);
            color: var(--error-text);
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 18px;
        }
        .success-box {
            background: var(--success-bg);
            color: var(--success-text);
            border: 1px solid #bbf7d0;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 18px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }
        input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 15px;
        }
        input:focus {
            outline: 2px solid rgba(20, 108, 67, 0.15);
            border-color: var(--accent);
        }
        button {
            width: 100%;
            border: 0;
            border-radius: 10px;
            padding: 13px 16px;
            background: var(--accent);
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }
        button:hover {
            background: var(--accent-dark);
        }
        button[disabled] {
            opacity: 0.65;
            cursor: wait;
        }
        .switch-link {
            margin-top: 18px;
            text-align: center;
        }
        .secondary-action {
            margin-top: 14px;
        }
        .secondary-action button {
            background: #e8f3ed;
            color: var(--accent);
        }
        .secondary-action button:hover {
            background: #d7eadf;
        }
        .switch-link a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }
        .modal[hidden] {
            display: none;
        }
        .modal {
            position: fixed;
            inset: 0;
            background: var(--overlay);
            display: grid;
            place-items: center;
            padding: 20px;
            z-index: 1000;
        }
        .modal__dialog {
            width: min(100%, 460px);
            max-height: calc(100vh - 40px);
            overflow-y: auto;
            background: var(--card);
            border-radius: 20px;
            border: 1px solid var(--border);
            box-shadow: 0 24px 70px rgba(15, 23, 42, 0.18);
            padding: 28px;
            position: relative;
        }
        .modal__title {
            margin: 0 0 8px;
            font-size: 28px;
        }
        .modal__intro {
            margin-bottom: 20px;
        }
        .modal__close {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 40px;
            height: 40px;
            border-radius: 999px;
            background: #f3f4f6;
            color: var(--text);
            font-size: 22px;
            line-height: 1;
            padding: 0;
        }
        .modal__close:hover {
            background: #e5e7eb;
        }
        .modal__actions {
            display: grid;
            gap: 12px;
            margin-top: 8px;
        }
        .modal__cancel {
            background: #f3f4f6;
            color: var(--text);
        }
        .modal__cancel:hover {
            background: #e5e7eb;
        }
        .modal__hint {
            margin: 0 0 18px;
            font-size: 14px;
            color: var(--muted);
        }
        .modal__status {
            margin: 0 0 16px;
            font-size: 14px;
            color: var(--accent);
        }
        .prefix-field {
            display: flex;
            align-items: center;
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }
        .prefix-field__prefix {
            padding: 12px 14px;
            background: #f3f8f5;
            color: var(--accent);
            font-weight: 700;
            border-right: 1px solid var(--border);
        }
        .prefix-field input {
            border: 0;
            border-radius: 0;
        }
        .prefix-field:focus-within {
            outline: 2px solid rgba(20, 108, 67, 0.15);
            border-color: var(--accent);
        }
        #firebase-recaptcha {
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h1>Customer Login</h1>
        <p>Enter your username or email to continue to your account.</p>

        <?php if ($successMessage !== null): ?>
            <div class="success-box"><?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="error-box">
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <input type="hidden" name="form_action" value="login">
            <div class="form-group">
                <label for="identifier">Username or Email</label>
                <input type="text" id="identifier" name="identifier" value="<?= htmlspecialchars($identifier) ?>" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit">Login as Customer</button>
        </form>

        <div class="secondary-action">
            <button type="button" id="open-register-modal">Create Account</button>
        </div>

        <div class="switch-link">
            <a href="<?= htmlspecialchars(adminlens_url('/admin/admin_login.php')) ?>">Admin login</a>
        </div>
    </div>

    <div class="modal" id="register-modal" aria-hidden="<?= $showRegisterModal ? 'false' : 'true' ?>" <?= $showRegisterModal ? '' : 'hidden' ?>>
        <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="register-modal-title">
            <button type="button" class="modal__close" data-close-modal="register-modal" aria-label="Close create account modal">&times;</button>
            <h2 class="modal__title" id="register-modal-title">Create Account</h2>
            <p class="modal__intro">Set up your customer account and verify your phone number with Firebase.</p>

            <?php if ($registerErrors !== []): ?>
                <div class="error-box">
                    <?php foreach ($registerErrors as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="" id="register-form">
                <input type="hidden" name="form_action" value="create_account">
                <input type="hidden" name="firebase_id_token" id="firebase_id_token" value="">
                <input type="hidden" name="verified_phone_number" id="verified_phone_number" value="">

                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($registerData['full_name']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="register_username">Username</label>
                    <input type="text" id="register_username" name="register_username" value="<?= htmlspecialchars($registerData['username']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="register_email">Email</label>
                    <input type="email" id="register_email" name="register_email" value="<?= htmlspecialchars($registerData['email']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="register_address">Address</label>
                    <input type="text" id="register_address" name="register_address" value="<?= htmlspecialchars($registerData['address']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="register_phone_number">Phone Number</label>
                    <div class="prefix-field">
                        <span class="prefix-field__prefix">+63</span>
                        <input
                            type="text"
                            id="register_phone_number"
                            name="register_phone_number"
                            value="<?= htmlspecialchars(preg_replace('/^\+63/', '', $registerData['phone_number'])) ?>"
                            inputmode="numeric"
                            maxlength="10"
                            pattern="9[0-9]{9}"
                            placeholder="9XXXXXXXXX"
                            required
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="register_password">Password</label>
                    <input type="password" id="register_password" name="register_password" required>
                </div>

                <div class="form-group">
                    <label for="register_password_confirm">Confirm Password</label>
                    <input type="password" id="register_password_confirm" name="register_password_confirm" required>
                </div>

                <p class="modal__hint">Firebase Phone Auth uses a reCAPTCHA challenge before it sends the SMS verification code.</p>
                <div id="firebase-recaptcha"></div>

                <div class="modal__actions">
                    <button type="button" id="send-verification-code-btn">Send Verification Code</button>
                    <button type="button" class="modal__cancel" data-close-modal="register-modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="verify-modal" aria-hidden="true" hidden>
        <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="verify-modal-title">
            <button type="button" class="modal__close" data-close-modal="verify-modal" aria-label="Close verification modal">&times;</button>
            <h2 class="modal__title" id="verify-modal-title">Verify Phone Number</h2>
            <p class="modal__intro">Enter the 6-digit code Firebase sent to your phone number.</p>
            <p class="modal__status" id="verify-status"></p>
            <div class="error-box" id="verify-client-error" hidden></div>

            <div class="form-group">
                <label for="verification_code">Verification Code</label>
                <input
                    type="text"
                    id="verification_code"
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    placeholder="Enter 6-digit code"
                    required
                >
            </div>

            <div class="modal__actions">
                <button type="button" id="verify-code-btn">Verify Code and Create Account</button>
                <button type="button" class="modal__cancel" data-close-modal="verify-modal">Cancel</button>
            </div>
        </div>
    </div>

    <script type="module">
        import { initializeApp } from 'https://www.gstatic.com/firebasejs/11.6.1/firebase-app.js';
        import {
            getAuth,
            RecaptchaVerifier,
            signInWithPhoneNumber,
            signOut,
        } from 'https://www.gstatic.com/firebasejs/11.6.1/firebase-auth.js';

        const firebaseConfig = {
            apiKey: <?= json_encode(FIREBASE_API_KEY) ?>,
            authDomain: <?= json_encode(FIREBASE_AUTH_DOMAIN) ?>,
            projectId: <?= json_encode(FIREBASE_PROJECT_ID) ?>,
            appId: <?= json_encode(FIREBASE_APP_ID) ?>,
            messagingSenderId: <?= json_encode(FIREBASE_MESSAGING_SENDER_ID) ?>,
        };

        const body = document.body;
        const registerModal = document.getElementById('register-modal');
        const verifyModal = document.getElementById('verify-modal');
        const openRegisterButton = document.getElementById('open-register-modal');
        const phoneInput = document.getElementById('register_phone_number');
        const closeButtons = Array.from(document.querySelectorAll('[data-close-modal]'));
        const registerForm = document.getElementById('register-form');
        const sendVerificationButton = document.getElementById('send-verification-code-btn');
        const verifyCodeButton = document.getElementById('verify-code-btn');
        const verificationCodeInput = document.getElementById('verification_code');
        const firebaseIdTokenInput = document.getElementById('firebase_id_token');
        const verifiedPhoneNumberInput = document.getElementById('verified_phone_number');
        const verifyStatus = document.getElementById('verify-status');
        const verifyClientError = document.getElementById('verify-client-error');

        let auth = null;
        let recaptchaVerifier = null;
        let confirmationResult = null;
        let verifiedPhoneNumber = '';

        function syncBodyLock() {
            const modalOpen = [registerModal, verifyModal].some((modal) => modal && modal.hidden === false);
            body.style.overflow = modalOpen ? 'hidden' : '';
        }

        function openModal(modal) {
            if (!modal) {
                return;
            }

            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            syncBodyLock();
        }

        function closeModal(modal) {
            if (!modal) {
                return;
            }

            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            syncBodyLock();
        }

        function showVerifyError(message) {
            if (!verifyClientError) {
                return;
            }

            verifyClientError.hidden = false;
            verifyClientError.textContent = message;
        }

        function clearVerifyError() {
            if (!verifyClientError) {
                return;
            }

            verifyClientError.hidden = true;
            verifyClientError.textContent = '';
        }

        function normalizePhoneForFirebase() {
            if (!phoneInput) {
                return '';
            }

            const digits = phoneInput.value.replace(/\D/g, '').replace(/^63/, '').replace(/^0/, '').slice(0, 10);
            phoneInput.value = digits;
            return '+63' + digits;
        }

        function validateFirebaseConfig() {
            return firebaseConfig.apiKey !== ''
                && firebaseConfig.authDomain !== ''
                && firebaseConfig.projectId !== ''
                && firebaseConfig.appId !== ''
                && firebaseConfig.messagingSenderId !== '';
        }

        function ensureFirebase() {
            if (!validateFirebaseConfig()) {
                throw new Error('Fill the Firebase web app values in config/constants.php before using phone verification.');
            }

            if (auth !== null) {
                return auth;
            }

            const app = initializeApp(firebaseConfig);
            auth = getAuth(app);
            return auth;
        }

        async function ensureRecaptcha() {
            const currentAuth = ensureFirebase();

            if (recaptchaVerifier !== null) {
                return recaptchaVerifier;
            }

            recaptchaVerifier = new RecaptchaVerifier(currentAuth, 'firebase-recaptcha', {
                size: 'normal',
            });

            await recaptchaVerifier.render();
            return recaptchaVerifier;
        }

        if (openRegisterButton) {
            openRegisterButton.addEventListener('click', () => openModal(registerModal));
        }

        closeButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const modalId = button.getAttribute('data-close-modal');
                if (modalId === 'register-modal') {
                    closeModal(registerModal);
                }
                if (modalId === 'verify-modal') {
                    closeModal(verifyModal);
                }
            });
        });

        [registerModal, verifyModal].forEach((modal) => {
            if (!modal) {
                return;
            }

            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeModal(modal);
                }
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeModal(registerModal);
                closeModal(verifyModal);
            }
        });

        if (phoneInput) {
            phoneInput.addEventListener('input', () => {
                phoneInput.value = phoneInput.value.replace(/\D/g, '').replace(/^63/, '').replace(/^0/, '').slice(0, 10);
            });
        }

        if (sendVerificationButton && registerForm) {
            sendVerificationButton.addEventListener('click', async () => {
                clearVerifyError();
                if (!registerForm.reportValidity()) {
                    return;
                }

                try {
                    sendVerificationButton.disabled = true;
                    const currentAuth = ensureFirebase();
                    const appVerifier = await ensureRecaptcha();
                    const phoneNumber = normalizePhoneForFirebase();

                    if (!/^\+639\d{9}$/.test(phoneNumber)) {
                        throw new Error('Phone number must start with +63 and use a valid mobile format.');
                    }

                    confirmationResult = await signInWithPhoneNumber(currentAuth, phoneNumber, appVerifier);
                    verifiedPhoneNumber = phoneNumber;
                    verifiedPhoneNumberInput.value = phoneNumber;
                    verifyStatus.textContent = 'Code sent to ' + phoneNumber + '.';
                    closeModal(registerModal);
                    openModal(verifyModal);
                } catch (error) {
                    showVerifyError(error instanceof Error ? error.message : 'Unable to send the Firebase verification code.');
                } finally {
                    sendVerificationButton.disabled = false;
                }
            });
        }

        if (verifyCodeButton && verificationCodeInput && registerForm) {
            verifyCodeButton.addEventListener('click', async () => {
                clearVerifyError();

                if (confirmationResult === null) {
                    showVerifyError('Request a verification code first.');
                    return;
                }

                const code = verificationCodeInput.value.trim();
                if (!/^\d{6}$/.test(code)) {
                    showVerifyError('Verification code must be 6 digits.');
                    return;
                }

                try {
                    verifyCodeButton.disabled = true;
                    const credential = await confirmationResult.confirm(code);
                    const idToken = await credential.user.getIdToken();
                    firebaseIdTokenInput.value = idToken;
                    verifiedPhoneNumberInput.value = verifiedPhoneNumber;
                    await signOut(credential.user.auth);
                    registerForm.submit();
                } catch (error) {
                    showVerifyError(error instanceof Error ? error.message : 'Unable to confirm the Firebase verification code.');
                } finally {
                    verifyCodeButton.disabled = false;
                }
            });
        }

        syncBodyLock();
    </script>
</body>
</html>
