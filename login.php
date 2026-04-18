<?php
session_start();
include 'includes/db.php';

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $university_email = trim($_POST['university_email']);
    $password = $_POST['password'];

    if (empty($university_email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        $stmt = $conn->prepare("SELECT student_id, full_name, university_email, password_hash, account_status FROM student WHERE university_email = ?");
        $stmt->bind_param("s", $university_email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ($user['account_status'] !== 'active') {
                $error = "Your account is not active.";
            } elseif (password_verify($password, $user['password_hash'])) {
                $_SESSION['student_id'] = $user['student_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['university_email'] = $user['university_email'];

                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Incorrect password.";
            }
        } else {
            $error = "No account found with this email.";
        }

        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Campus Resource Sharing</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --navy:        #0a0f2c;
            --deep:        #0d1535;
            --accent:      #4f6ef7;
            --accent-glow: #6c85ff;
            --gold:        #f5c842;
            --text-primary:#eef0ff;
            --text-muted:  #8b93c4;
            --glass:       rgba(255,255,255,0.04);
            --glass-border:rgba(255,255,255,0.09);
            --input-bg:    rgba(255,255,255,0.05);
            --error-bg:    rgba(255,80,80,0.12);
        }

        body {
            min-height: 100vh;
            background-color: var(--navy);
            display: flex;
            align-items: stretch;
            font-family: 'DM Sans', sans-serif;
            overflow-x: hidden;
        }

        /* ══════════════════════════════
           LEFT PANEL
        ══════════════════════════════ */
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

        /* Grid lines */
        .left-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(79,110,247,0.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(79,110,247,0.06) 1px, transparent 1px);
            background-size: 44px 44px;
        }

        /* Glow orbs */
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(72px);
            pointer-events: none;
        }
        .orb-1 {
            width: 360px; height: 360px;
            background: radial-gradient(circle, rgba(79,110,247,0.36) 0%, transparent 70%);
            top: -80px; left: -80px;
        }
        .orb-2 {
            width: 260px; height: 260px;
            background: radial-gradient(circle, rgba(245,200,66,0.16) 0%, transparent 70%);
            bottom: 60px; right: -60px;
        }

        /* Brand */
        .brand {
            position: relative;
            z-index: 1;
        }
        .brand-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 64px;
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
            margin-bottom: 0;
        }
        .panel-headline .tag {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 11.5px;
            font-weight: 600;
            letter-spacing: 1.8px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 14px;
        }
        .panel-headline .tag::before {
            content: '';
            display: inline-block;
            width: 22px; height: 2px;
            background: var(--gold);
            border-radius: 2px;
        }
        .panel-headline h2 {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: clamp(30px, 3vw, 46px);
            line-height: 1.12;
            color: var(--text-primary);
            margin-bottom: 18px;
        }
        .panel-headline h2 em {
            font-style: normal;
            color: var(--gold);
        }
        .panel-headline p {
            font-size: 14.5px;
            color: var(--text-muted);
            line-height: 1.78;
            max-width: 290px;
        }

        /* Stats row */
        .stats-row {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }
        .stat-card {
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: 14px;
            padding: 16px 14px;
            text-align: center;
            backdrop-filter: blur(10px);
            animation: slideIn 0.6s ease both;
        }
        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .stat-card .num {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 22px;
            color: var(--text-primary);
            line-height: 1;
            margin-bottom: 5px;
        }
        .stat-card .num span {
            color: var(--accent-glow);
        }
        .stat-card .lbl {
            font-size: 11px;
            color: var(--text-muted);
            letter-spacing: 0.5px;
        }

        /* Feature list */
        .feature-list {
            position: relative;
            z-index: 1;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 13px;
        }
        .feature-list li {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            color: var(--text-muted);
            animation: slideIn 0.5s ease both;
        }
        .feature-list li:nth-child(1) { animation-delay: 0.15s; }
        .feature-list li:nth-child(2) { animation-delay: 0.25s; }
        .feature-list li:nth-child(3) { animation-delay: 0.35s; }

        .check-icon {
            width: 22px; height: 22px;
            background: rgba(79,110,247,0.15);
            border: 1px solid rgba(79,110,247,0.3);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px;
            flex-shrink: 0;
            color: var(--accent-glow);
        }

        /* ══════════════════════════════
           RIGHT PANEL
        ══════════════════════════════ */
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
        .right-panel::after {
            content: '';
            position: absolute;
            top: -100px; right: -100px;
            width: 380px; height: 380px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(79,110,247,0.07) 0%, transparent 70%);
            pointer-events: none;
        }

        .form-card {
            width: 100%;
            max-width: 380px;
            animation: fadeUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Welcome back avatar area */
        .welcome-area {
            text-align: center;
            margin-bottom: 34px;
        }
        .avatar-ring {
            width: 66px; height: 66px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent) 0%, var(--gold) 100%);
            display: flex; align-items: center; justify-content: center;
            font-size: 28px;
            margin: 0 auto 16px;
            box-shadow: 0 0 0 6px rgba(79,110,247,0.12), 0 0 28px rgba(79,110,247,0.25);
        }
        .welcome-area .eyebrow {
            font-size: 11.5px;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--accent-glow);
            margin-bottom: 6px;
        }
        .welcome-area h1 {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 28px;
            color: var(--text-primary);
            letter-spacing: -0.5px;
        }
        .welcome-area p {
            color: var(--text-muted);
            font-size: 13.5px;
            margin-top: 5px;
        }

        /* Message */
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

        /* Form groups */
        .form-group {
            margin-bottom: 18px;
            position: relative;
        }

        label {
            display: block;
            font-size: 11.5px;
            font-weight: 600;
            letter-spacing: 0.9px;
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
            padding: 13px 14px 13px 42px;
            background: var(--input-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            outline: none;
            transition: border-color 0.25s, box-shadow 0.25s, background 0.25s;
        }
        input::placeholder { color: rgba(139,147,196,0.45); }
        input:focus {
            border-color: var(--accent);
            background: rgba(79,110,247,0.07);
            box-shadow: 0 0 0 3px rgba(79,110,247,0.15);
        }

        /* Forgot link */
        .forgot-row {
            display: flex;
            justify-content: flex-end;
            margin-top: -10px;
            margin-bottom: 20px;
        }
        .forgot-row a {
            font-size: 12.5px;
            color: var(--accent-glow);
            text-decoration: none;
            transition: color 0.2s;
        }
        .forgot-row a:hover { color: #fff; }

        /* Submit button */
        .btn-login {
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
            position: relative;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-login::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.12), transparent);
            opacity: 0;
            transition: opacity 0.25s;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(79,110,247,0.4);
        }
        .btn-login:hover::before { opacity: 1; }
        .btn-login:active { transform: translateY(0); }

        /* Divider */
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

        /* ══════════════════════════════
           RESPONSIVE
        ══════════════════════════════ */
        @media (max-width: 820px) {
            body { flex-direction: column; }
            .left-panel {
                flex: 0 0 auto;
                padding: 36px 28px 30px;
            }
            .stats-row { grid-template-columns: repeat(3, 1fr); }
            .right-panel { padding: 36px 20px; }
        }

        @media (max-width: 500px) {
            .left-panel { display: none; }
            .right-panel { padding: 36px 20px; }
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
                <p class="tag">Welcome back</p>
                <h2>Your campus,<br><em>your resources.</em></h2>
                <p>Pick up right where you left off — browse listings, manage requests, and connect with your community.</p>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="num">1<span>K+</span></div>
                <div class="lbl">Active Users</div>
            </div>
            <div class="stat-card">
                <div class="num">4<span>K+</span></div>
                <div class="lbl">Resources</div>
            </div>
            <div class="stat-card">
                <div class="num">98<span>%</span></div>
                <div class="lbl">Satisfaction</div>
            </div>
        </div>

        <!-- Feature list -->
        <ul class="feature-list">
            <li>
                <span class="check-icon">✓</span>
                Manage your listed items easily
            </li>
            <li>
                <span class="check-icon">✓</span>
                Track requests &amp; transactions
            </li>
            <li>
                <span class="check-icon">✓</span>
                Share useful campus resources
            </li>
        </ul>
    </aside>

    <!-- ═══ Right form panel ═══ -->
    <main class="right-panel">
        <div class="form-card">

            <div class="welcome-area">
                <div class="avatar-ring">🎓</div>
                <p class="eyebrow">Secure Sign-In</p>
                <h1>Welcome Back</h1>
                <p>Sign in to continue to your dashboard</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="message error">⚠️ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="">

                <div class="form-group">
                    <label for="university_email">University Email</label>
                    <div class="input-wrap">
                        <span class="input-icon">✉️</span>
                        <input
                            type="email"
                            id="university_email"
                            name="university_email"
                            placeholder="you@university.edu"
                            value="<?php echo isset($university_email) ? htmlspecialchars($university_email) : ''; ?>"
                            required
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <span class="input-icon">🔒</span>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            required
                        >
                    </div>
                </div>

                <div class="forgot-row">
                    <a href="#">Forgot password?</a>
                </div>

                <button type="submit" class="btn-login">Sign In →</button>
            </form>

            <div class="divider"><span>new here?</span></div>

            <div class="footer-text">
                <a href="register.php">Create a free account</a>
            </div>

        </div>
    </main>

</body>
</html>