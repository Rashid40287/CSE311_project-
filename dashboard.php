<?php
session_start();
include 'includes/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];
$full_name = $_SESSION['full_name'];
$email = $_SESSION['university_email'];

/* 1. Count listed items by this user */
$stmt1 = $conn->prepare("SELECT COUNT(*) AS total_items FROM item WHERE owner_id = ?");
$stmt1->bind_param("i", $student_id);
$stmt1->execute();
$result1 = $stmt1->get_result();
$total_items = $result1->fetch_assoc()['total_items'];
$stmt1->close();

/* 2. Count borrow requests made by this user */
$stmt2 = $conn->prepare("SELECT COUNT(*) AS total_requests FROM borrow_request WHERE borrower_id = ?");
$stmt2->bind_param("i", $student_id);
$stmt2->execute();
$result2 = $stmt2->get_result();
$total_requests = $result2->fetch_assoc()['total_requests'];
$stmt2->close();

/* 3. Count active transactions where user is borrower OR owner */
$stmt3 = $conn->prepare("
    SELECT COUNT(*) AS total_transactions
    FROM borrow_transaction bt
    JOIN borrow_request br ON bt.request_id = br.request_id
    JOIN item i ON br.item_id = i.item_id
    WHERE bt.transaction_status = 'active'
      AND (br.borrower_id = ? OR i.owner_id = ?)
");
$stmt3->bind_param("ii", $student_id, $student_id);
$stmt3->execute();
$result3 = $stmt3->get_result();
$total_transactions = $result3->fetch_assoc()['total_transactions'];
$stmt3->close();

/* 4. Count reviews written by this user */
$stmt4 = $conn->prepare("SELECT COUNT(*) AS total_reviews FROM review WHERE reviewer_id = ?");
$stmt4->bind_param("i", $student_id);
$stmt4->execute();
$result4 = $stmt4->get_result();
$total_reviews = $result4->fetch_assoc()['total_reviews'];
$stmt4->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Campus Resource Sharing</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            margin: 0; padding: 0;
            box-sizing: border-box;
        }

        :root {
            --navy:        #0a0f2c;
            --accent:      #4f6ef7;
            --accent-dark: #3b5bdb;
            --accent-soft: #eef1ff;
            --gold:        #f5c842;
            --gold-soft:   #fffbea;
            --surface:     #ffffff;
            --bg:          #f4f6fd;
            --border:      #e4e8f8;
            --text-h:      #0d1535;
            --text-body:   #4a5380;
            --text-muted:  #8b93c4;
            --shadow-sm:   0 2px 12px rgba(79,110,247,0.07);
            --shadow-md:   0 8px 28px rgba(79,110,247,0.11);
            --shadow-lg:   0 16px 48px rgba(79,110,247,0.14);
        }

        body {
            background: var(--bg);
            color: var(--text-body);
            font-family: 'DM Sans', sans-serif;
            min-height: 100vh;
        }

        /* ══════════════════════
           NAVBAR
        ══════════════════════ */
        .navbar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 0 40px;
            height: 68px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 16px rgba(79,110,247,0.06);
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 11px;
            text-decoration: none;
        }
        .nav-brand .icon {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, var(--accent), var(--gold));
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 19px;
            flex-shrink: 0;
        }
        .nav-brand span {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 17px;
            color: var(--navy);
            letter-spacing: -0.3px;
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .nav-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--accent-soft);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 7px 14px;
            font-size: 13px;
            color: var(--accent-dark);
            font-weight: 500;
        }
        .nav-pill .dot {
            width: 7px; height: 7px;
            background: #22c55e;
            border-radius: 50%;
            flex-shrink: 0;
            box-shadow: 0 0 0 2px rgba(34,197,94,0.22);
        }

        .logout-btn {
            text-decoration: none;
            background: var(--navy);
            color: #eef0ff;
            padding: 9px 18px;
            border-radius: 10px;
            font-family: 'Syne', sans-serif;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.2px;
            transition: background 0.2s, transform 0.2s, box-shadow 0.2s;
        }
        .logout-btn:hover {
            background: var(--accent-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(79,110,247,0.25);
        }

        /* ══════════════════════
           LAYOUT
        ══════════════════════ */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 36px 24px 60px;
        }

        /* ══════════════════════
           HERO BANNER
        ══════════════════════ */
        .hero {
            position: relative;
            overflow: hidden;
            background: linear-gradient(130deg, var(--navy) 0%, #1a2560 55%, #0d1f4e 100%);
            border-radius: 24px;
            padding: 44px 48px;
            margin-bottom: 28px;
            box-shadow: var(--shadow-lg);
        }

        /* grid overlay */
        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(79,110,247,0.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(79,110,247,0.08) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
        }

        /* glow orb */
        .hero::after {
            content: '';
            position: absolute;
            width: 380px; height: 380px;
            background: radial-gradient(circle, rgba(79,110,247,0.35) 0%, transparent 70%);
            border-radius: 50%;
            top: -120px; right: -60px;
            filter: blur(60px);
            pointer-events: none;
        }

        .hero-inner {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
        }

        .hero-text .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 12px;
        }
        .hero-text .eyebrow::before {
            content: '';
            width: 18px; height: 2px;
            background: var(--gold);
            border-radius: 2px;
        }

        .hero-text h1 {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: clamp(26px, 3vw, 38px);
            color: #eef0ff;
            letter-spacing: -0.6px;
            line-height: 1.18;
            margin-bottom: 10px;
        }
        .hero-text h1 em {
            font-style: normal;
            color: var(--gold);
        }
        .hero-text p {
            font-size: 14.5px;
            color: #8b93c4;
            line-height: 1.75;
            max-width: 520px;
        }

        .hero-badge {
            flex-shrink: 0;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 18px;
            padding: 18px 24px;
            text-align: center;
            backdrop-filter: blur(8px);
            min-width: 150px;
        }
        .hero-badge .badge-icon {
            font-size: 32px;
            margin-bottom: 8px;
        }
        .hero-badge .badge-label {
            font-size: 11px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #8b93c4;
            margin-bottom: 4px;
        }
        .hero-badge .badge-value {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 15px;
            color: #eef0ff;
        }

        /* ══════════════════════
           STATS
        ══════════════════════ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 34px;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 22px 24px;
            box-shadow: var(--shadow-sm);
            transition: transform 0.22s, box-shadow 0.22s;
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            bottom: -18px; right: -18px;
            width: 80px; height: 80px;
            border-radius: 50%;
            background: var(--accent-soft);
            pointer-events: none;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            font-size: 22px;
            margin-bottom: 14px;
            display: block;
        }
        .stat-label {
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 6px;
        }
        .stat-number {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 36px;
            color: var(--navy);
            line-height: 1;
            margin-bottom: 6px;
        }
        .stat-number span { color: var(--accent); }
        .stat-note {
            font-size: 12.5px;
            color: var(--text-muted);
        }

        /* ══════════════════════
           SECTION HEADER
        ══════════════════════ */
        .section-header {
            display: flex;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 20px;
        }
        .section-header h2 {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 22px;
            color: var(--text-h);
            letter-spacing: -0.3px;
        }
        .section-header .pill {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            background: var(--accent-soft);
            color: var(--accent-dark);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 3px 10px;
        }

        /* ══════════════════════
           FEATURE CARDS
        ══════════════════════ */
        .card-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .feature-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 28px;
            box-shadow: var(--shadow-sm);
            transition: transform 0.22s, box-shadow 0.22s, border-color 0.22s;
            display: flex;
            flex-direction: column;
        }
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
            border-color: rgba(79,110,247,0.25);
        }

        .icon-box {
            width: 52px; height: 52px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px;
            margin-bottom: 18px;
            background: var(--accent-soft);
            border: 1px solid var(--border);
        }

        .feature-card h3 {
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            font-size: 17px;
            color: var(--text-h);
            margin-bottom: 8px;
            letter-spacing: -0.2px;
        }
        .feature-card p {
            font-size: 13.5px;
            color: var(--text-body);
            line-height: 1.72;
            flex: 1;
            margin-bottom: 22px;
        }

        .card-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            background: var(--navy);
            color: #eef0ff;
            padding: 10px 18px;
            border-radius: 10px;
            font-family: 'Syne', sans-serif;
            font-size: 13px;
            font-weight: 700;
            align-self: flex-start;
            transition: background 0.2s, transform 0.2s, box-shadow 0.2s;
        }
        .card-link .arrow {
            transition: transform 0.2s;
        }
        .card-link:hover {
            background: var(--accent-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(79,110,247,0.25);
        }
        .card-link:hover .arrow {
            transform: translateX(3px);
        }

        /* ══════════════════════
           TIPS BOX
        ══════════════════════ */
        .tips-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 30px 34px;
            box-shadow: var(--shadow-sm);
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 0 36px;
            align-items: start;
            position: relative;
            overflow: hidden;
        }
        .tips-box::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 4px;
            background: linear-gradient(180deg, var(--accent), var(--gold));
            border-radius: 4px 0 0 4px;
        }

        .tips-icon {
            font-size: 38px;
            line-height: 1;
            margin-top: 2px;
        }
        .tips-content h3 {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 18px;
            color: var(--text-h);
            margin-bottom: 14px;
        }
        .tips-list {
            list-style: none;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 28px;
        }
        .tips-list li {
            font-size: 13.5px;
            color: var(--text-body);
            line-height: 1.65;
            display: flex;
            align-items: flex-start;
            gap: 9px;
        }
        .tips-list li::before {
            content: '✓';
            flex-shrink: 0;
            width: 20px; height: 20px;
            background: var(--accent-soft);
            border: 1px solid var(--border);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 10px;
            color: var(--accent-dark);
            font-weight: 700;
            margin-top: 1px;
            line-height: 20px;
            text-align: center;
        }

        /* ══════════════════════
           RESPONSIVE
        ══════════════════════ */
        @media (max-width: 1000px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .card-grid  { grid-template-columns: repeat(2, 1fr); }
            .tips-list  { grid-template-columns: 1fr; }
        }

        @media (max-width: 700px) {
            .navbar { padding: 0 18px; }
            .container { padding: 22px 16px 48px; }
            .hero { padding: 28px 22px; }
            .hero-badge { display: none; }
            .stats-grid, .card-grid { grid-template-columns: 1fr; }
            .tips-box {
                grid-template-columns: 1fr;
                gap: 14px 0;
            }
            .tips-icon { font-size: 28px; }
        }

        @media (max-width: 460px) {
            .nav-pill { display: none; }
        }

        /* staggered card entrance */
        .feature-card {
            animation: cardUp 0.5s cubic-bezier(0.16,1,0.3,1) both;
        }
        .feature-card:nth-child(1) { animation-delay: 0.05s; }
        .feature-card:nth-child(2) { animation-delay: 0.10s; }
        .feature-card:nth-child(3) { animation-delay: 0.15s; }
        .feature-card:nth-child(4) { animation-delay: 0.20s; }
        .feature-card:nth-child(5) { animation-delay: 0.25s; }
        .feature-card:nth-child(6) { animation-delay: 0.30s; }

        @keyframes cardUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

    <!-- ══════════ NAVBAR ══════════ -->
    <nav class="navbar">
        <a class="nav-brand" href="#">
            <div class="icon">🎓</div>
            <span>CampusShare</span>
        </a>
        <div class="nav-right">
            <div class="nav-pill">
                <span class="dot"></span>
                <?php echo htmlspecialchars($email); ?>
            </div>
            <a href="logout.php" class="logout-btn">Sign Out</a>
        </div>
    </nav>

    <!-- ══════════ MAIN ══════════ -->
    <div class="container">

        <!-- Hero -->
        <div class="hero">
            <div class="hero-inner">
                <div class="hero-text">
                    <p class="eyebrow">Student Dashboard</p>
                    <h1>Welcome back,<br><em><?php echo htmlspecialchars($full_name); ?></em> 👋</h1>
                    <p>Manage resources, track requests, and stay connected with your campus community — all from one place.</p>
                </div>
                <div class="hero-badge">
                    <div class="badge-icon">🛡️</div>
                    <div class="badge-label">Portal Status</div>
                    <div class="badge-value">Active</div>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-icon">📦</span>
                <div class="stat-label">Listed Items</div>
                <div class="stat-number"><?php echo $total_items; ?></div>
                <div class="stat-note">Items you have shared</div>
            </div>
            <div class="stat-card">
                <span class="stat-icon">📨</span>
                <div class="stat-label">Borrow Requests</div>
                <div class="stat-number"><?php echo $total_requests; ?></div>
                <div class="stat-note">Requests you have made</div>
            </div>
            <div class="stat-card">
                <span class="stat-icon">🔄</span>
                <div class="stat-label">Active Transactions</div>
                <div class="stat-number"><?php echo $total_transactions; ?></div>
                <div class="stat-note">Current borrowing activity</div>
            </div>
            <div class="stat-card">
                <span class="stat-icon">⭐</span>
                <div class="stat-label">Reviews</div>
                <div class="stat-number"><?php echo $total_reviews; ?></div>
                <div class="stat-note">Feedback history</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="section-header">
            <h2>Quick Actions</h2>
            <span class="pill">6 features</span>
        </div>

        <div class="card-grid">
            <div class="feature-card">
                <div class="icon-box">📦</div>
                <h3>Add Item</h3>
                <p>List a new item so other students can browse and request it from you.</p>
                <a href="add_item.php" class="card-link">Go to Add Item <span class="arrow">→</span></a>
            </div>

            <div class="feature-card">
                <div class="icon-box">🔎</div>
                <h3>Browse Items</h3>
                <p>Explore available resources shared by other students across campus.</p>
                <a href="browse_items.php" class="card-link">Browse Now <span class="arrow">→</span></a>
            </div>

            <div class="feature-card">
                <div class="icon-box">📨</div>
                <h3>My Requests</h3>
                <p>View the requests you have sent and check their current approval status.</p>
                <a href="my_requests.php" class="card-link">View Requests <span class="arrow">→</span></a>
            </div>

            <div class="feature-card">
                <div class="icon-box">🛠️</div>
                <h3>Manage Requests</h3>
                <p>Approve or reject incoming requests made for the items you have listed.</p>
                <a href="manage_requests.php" class="card-link">Manage Now <span class="arrow">→</span></a>
            </div>

            <div class="feature-card">
                <div class="icon-box">📚</div>
                <h3>Transactions</h3>
                <p>Track active, returned, and overdue borrowing transactions in one view.</p>
                <a href="my_transactions.php" class="card-link">Open Transactions <span class="arrow">→</span></a>
            </div>

            <div class="feature-card">
                <div class="icon-box">⭐</div>
                <h3>Reviews</h3>
                <p>Leave feedback and review your activity after completed borrowing returns.</p>
                <a href="review.php" class="card-link">Open Reviews <span class="arrow">→</span></a>
            </div>
        </div>

        <!-- Tips -->
        <div class="tips-box">
            <div class="tips-icon">💡</div>
            <div class="tips-content">
                <h3>Getting Started</h3>
                <ul class="tips-list">
                    <li>Start by adding an item you want to share with others.</li>
                    <li>Browse available items and test the borrow request flow.</li>
                    <li>Use the dashboard cards to navigate core features quickly.</li>
                    <li>Your stats are connected to the live database in real time.</li>
                </ul>
            </div>
        </div>

    </div>

</body>
</html>