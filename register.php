<?php
include 'includes/db.php';

$success = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name = trim($_POST['full_name']);
    $university_email = trim($_POST['university_email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $department = trim($_POST['department']);
    $phone = trim($_POST['phone']);
    $phone = ($phone === "") ? null : $phone;

    if (
        empty($full_name) || empty($university_email) || empty($password) ||
        empty($confirm_password) || empty($department)
    ) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($university_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        $check_stmt = $conn->prepare("SELECT student_id FROM student WHERE university_email = ?");
        $check_stmt->bind_param("s", $university_email);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $error = "This email is already registered.";
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $registration_date = date("Y-m-d");
            $account_status = "active";

            $stmt = $conn->prepare("INSERT INTO student (full_name, university_email, password_hash, department, phone, registration_date, account_status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param(
            "sssssss",
            $full_name,
            $university_email,
            $password_hash,
            $department,
            $phone,
            $registration_date,
            $account_status
           );

            if ($stmt->execute()) {
                $success = "Registration successful!";
            } else {
                $error = "Something went wrong: " . $stmt->error;
            }

            $stmt->close();
        }

        $check_stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | Campus Resource Sharing</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --navy: #0a0f2c;
            --deep: #0d1535;
            --accent: #4f6ef7;
            --accent-glow: #6c85ff;
            --gold: #f5c842;
            --text-primary: #eef0ff;
            --text-muted: #8b93c4;
            --glass: rgba(255, 255, 255, 0.04);
            --glass-border: rgba(255, 255, 255, 0.09);
            --input-bg: rgba(255, 255, 255, 0.05);
            --error-bg: rgba(255, 80, 80, 0.12);
            --success-bg: rgba(52, 211, 153, 0.12);
        }

        body {
            min-height: 100vh;
            background-color: var(--navy);
            display: flex;
            align-items: stretch;
            font-family: 'DM Sans', sans-serif;
            overflow-x: hidden;
        }

        /* ── Left panel ── */
        .left-panel {
            flex: 0 0 42%;
            background: linear-gradient(160deg, #111a4a 0%, #0a0f2c 60%, #07101f 100%);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 52px 48px;
            position: relative;
            overflow: hidden;
        }

        /* geometric grid */
        .left-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(79,110,247,0.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(79,110,247,0.06) 1px, transparent 1px);
            background-size: 44px 44px;
        }

        /* radial glow orb */
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(72px);
            pointer-events: none;
        }
        .orb-1 {
            width: 360px; height: 360px;
            background: radial-gradient(circle, rgba(79,110,247,0.38) 0%, transparent 70%);
            top: -80px; left: -80px;
        }
        .orb-2 {
            width: 260px; height: 260px;
            background: radial-gradient(circle, rgba(245,200,66,0.18) 0%, transparent 70%);
            bottom: 60px; right: -60px;
        }

        .brand {
            position: relative;
            z-index: 1;
        }
        .brand-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 60px;
        }
        .brand-logo .icon {
            width: 42px; height: 42px;
            background: linear-gradient(135deg, var(--accent), var(--gold));
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
        }
        .brand-logo span {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 18px;
            color: var(--text-primary);
            letter-spacing: -0.3px;
        }

        .panel-headline {
            position: relative;
            z-index: 1;
        }
        .panel-headline h2 {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: clamp(30px, 3vw, 44px);
            line-height: 1.15;
            color: var(--text-primary);
            margin-bottom: 18px;
        }
        .panel-headline h2 em {
            font-style: normal;
            color: var(--gold);
        }
        .panel-headline p {
            font-size: 15px;
            color: var(--text-muted);
            line-height: 1.75;
            max-width: 300px;
        }

        /* feature pills */
        .features {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .feature-pill {
            display: flex;
            align-items: center;
            gap: 14px;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: 14px;
            padding: 14px 18px;
            backdrop-filter: blur(10px);
            animation: slideIn 0.6s ease both;
        }
        .feature-pill:nth-child(1) { animation-delay: 0.1s; }
        .feature-pill:nth-child(2) { animation-delay: 0.2s; }
        .feature-pill:nth-child(3) { animation-delay: 0.3s; }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        .pill-icon {
            font-size: 22px;
            flex-shrink: 0;
        }
        .pill-text strong {
            display: block;
            color: var(--text-primary);
            font-size: 14px;
            font-weight: 500;
        }
        .pill-text span {
            color: var(--text-muted);
            font-size: 12px;
        }

        /* ── Right / form panel ── */
        .right-panel {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 32px;
            background: var(--deep);
            position: relative;
            overflow: hidden;
        }

        /* subtle corner decoration */
        .right-panel::after {
            content: '';
            position: absolute;
            bottom: -120px; right: -120px;
            width: 400px; height: 400px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(79,110,247,0.08) 0%, transparent 70%);
            pointer-events: none;
        }

        .form-card {
            width: 100%;
            max-width: 420px;
            animation: fadeUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .form-header {
            margin-bottom: 34px;
        }
        .form-header .eyebrow {
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--accent-glow);
            margin-bottom: 8px;
        }
        .form-header h1 {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 30px;
            color: var(--text-primary);
            letter-spacing: -0.5px;
        }
        .form-header p {
            color: var(--text-muted);
            font-size: 14px;
            margin-top: 6px;
        }

        /* messages */
        .message {
            padding: 13px 16px;
            border-radius: 12px;
            margin-bottom: 22px;
            font-size: 13.5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .error {
            background: var(--error-bg);
            color: #ff7e7e;
            border: 1px solid rgba(255,80,80,0.2);
        }
        .success {
            background: var(--success-bg);
            color: #34d399;
            border: 1px solid rgba(52,211,153,0.2);
        }

        /* two-column grid for form */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 16px;
        }
        .form-group {
            margin-bottom: 18px;
            position: relative;
        }
        .form-group.full { grid-column: 1 / -1; }

        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .input-wrap {
            position: relative;
        }
        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 16px;
            pointer-events: none;
            opacity: 0.45;
        }

        input {
            width: 100%;
            padding: 13px 14px 13px 40px;
            background: var(--input-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            outline: none;
            transition: border-color 0.25s, box-shadow 0.25s, background 0.25s;
        }
        input::placeholder { color: rgba(139,147,196,0.5); }

        input:focus {
            border-color: var(--accent);
            background: rgba(79,110,247,0.07);
            box-shadow: 0 0 0 3px rgba(79,110,247,0.15);
        }

        /* submit button */
        .btn-register {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--accent) 0%, #3b5bdb 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-family: 'Syne', sans-serif;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.4px;
            cursor: pointer;
            margin-top: 6px;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-register::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.12), transparent);
            opacity: 0;
            transition: opacity 0.25s;
        }
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(79,110,247,0.4);
        }
        .btn-register:hover::before { opacity: 1; }
        .btn-register:active { transform: translateY(0); }

        /* divider */
        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--glass-border);
        }
        .divider span {
            font-size: 12px;
            color: var(--text-muted);
        }

        .footer-text {
            text-align: center;
            font-size: 13.5px;
            color: var(--text-muted);
        }
        .footer-text a {
            color: var(--accent-glow);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }
        .footer-text a:hover { color: #fff; }

        /* ── Responsive ── */
        @media (max-width: 820px) {
            body { flex-direction: column; }
            .left-panel {
                flex: 0 0 auto;
                padding: 36px 28px 32px;
            }
            .panel-headline { margin-bottom: 28px; }
            .features { flex-direction: row; flex-wrap: wrap; }
            .feature-pill { flex: 1 1 200px; }
            .right-panel { padding: 36px 20px; }
        }

        @media (max-width: 500px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full { grid-column: unset; }
            .left-panel { display: none; }
        }
    </style>
</head>
<body>

    <!-- ═══ Left decorative panel ═══ -->
    <aside class="left-panel">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>

        <div class="brand">
            <div class="brand-logo">
                <div class="icon">🎓</div>
                <span>CampusShare</span>
            </div>

            <div class="panel-headline">
                <h2>Share resources,<br><em>grow together.</em></h2>
                <p>Connect with your campus community, share study materials, tools, and spaces — all in one place.</p>
            </div>
        </div>

        <div class="features">
            <div class="feature-pill">
                <div class="pill-icon">📚</div>
                <div class="pill-text">
                    <strong>Study Materials</strong>
                    <span>Access notes, slides & more</span>
                </div>
            </div>
            <div class="feature-pill">
                <div class="pill-icon">🔗</div>
                <div class="pill-text">
                    <strong>Real-time Sharing</strong>
                    <span>Exchange resources instantly</span>
                </div>
            </div>
            <div class="feature-pill">
                <div class="pill-icon">🛡️</div>
                <div class="pill-text">
                    <strong>University Verified</strong>
                    <span>Trusted campus community</span>
                </div>
            </div>
        </div>
    </aside>

    <!-- ═══ Right form panel ═══ -->
    <main class="right-panel">
        <div class="form-card">

            <div class="form-header">
                <p class="eyebrow">Step 1 of 1</p>
                <h1>Create Account</h1>
                <p>It only takes a moment to get started.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="message error">⚠️ <?php echo $error; ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="message success">✅ <?php echo $success; ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-grid">

                    <div class="form-group full">
                        <label for="full_name">Full Name</label>
                        <div class="input-wrap">
                            <span class="input-icon">👤</span>
                            <input type="text" id="full_name" name="full_name" placeholder="e.g. Rashed Ahmed" required>
                        </div>
                    </div>

                    <div class="form-group full">
                        <label for="university_email">University Email</label>
                        <div class="input-wrap">
                            <span class="input-icon">✉️</span>
                            <input type="email" id="university_email" name="university_email" placeholder="you@university.edu" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="department">Department</label>
                        <div class="input-wrap">
                            <span class="input-icon">🏛️</span>
                            <input type="text" id="department" name="department" placeholder="e.g. CSE" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <div class="input-wrap">
                            <span class="input-icon">📞</span>
                            <input type="text" id="phone" name="phone" placeholder="+880 1X...">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrap">
                            <span class="input-icon">🔒</span>
                            <input type="password" id="password" name="password" placeholder="Min. 8 characters" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="input-wrap">
                            <span class="input-icon">🔑</span>
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat password" required>
                        </div>
                    </div>

                </div>

                <button type="submit" class="btn-register">Create My Account →</button>
            </form>

            <div class="divider"><span>already a member?</span></div>

            <div class="footer-text">
                <a href="login.php">Sign in to your account</a>
            </div>

        </div>
    </main>

</body>
</html>