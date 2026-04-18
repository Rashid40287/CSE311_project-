<?php
session_start();
include 'includes/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];
$success = "";
$error = "";

/* Cancel pending request */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    $action = $_POST['action'] ?? '';

    if ($request_id <= 0 || $action !== 'cancel') {
        $error = "Invalid request action.";
    } else {
        $check_stmt = $conn->prepare("
            SELECT request_id, request_status
            FROM borrow_request
            WHERE request_id = ? AND borrower_id = ?
        ");
        $check_stmt->bind_param("ii", $request_id, $student_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows !== 1) {
            $error = "Request not found.";
        } else {
            $request = $check_result->fetch_assoc();

            if ($request['request_status'] !== 'pending') {
                $error = "Only pending requests can be cancelled.";
            } else {
                $owner_response_date = date("Y-m-d");

                $update_stmt = $conn->prepare("
                    UPDATE borrow_request
                    SET request_status = 'cancelled',
                        owner_response_date = ?
                    WHERE request_id = ?
                ");
                $update_stmt->bind_param("si", $owner_response_date, $request_id);

                if ($update_stmt->execute()) {
                    $success = "Request cancelled successfully.";
                } else {
                    $error = "Failed to cancel request.";
                }

                $update_stmt->close();
            }
        }

        $check_stmt->close();
    }
}

/* Fetch all requests of logged-in borrower */
$stmt = $conn->prepare("
    SELECT 
        br.request_id,
        br.request_date,
        br.requested_from_date,
        br.requested_to_date,
        br.request_message,
        br.request_status,
        br.owner_response_date,
        i.item_name,
        i.item_condition,
        i.owner_id,
        s.full_name AS owner_name,
        img.image_path
    FROM borrow_request br
    JOIN item i ON br.item_id = i.item_id
    JOIN student s ON i.owner_id = s.student_id
    LEFT JOIN item_image img ON i.item_id = img.item_id AND img.is_primary = TRUE
    WHERE br.borrower_id = ?
    ORDER BY br.request_date DESC, br.request_id DESC
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

/* Pre-fetch all rows and compute status counts */
$all_requests = [];
$status_counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0];
while ($row = $result->fetch_assoc()) {
    $all_requests[] = $row;
    $s = $row['request_status'];
    if (isset($status_counts[$s])) $status_counts[$s]++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Requests | Campus Resource Sharing</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --navy:         #0a0f2c;
            --accent:       #4f6ef7;
            --accent-dark:  #3b5bdb;
            --accent-soft:  #eef1ff;
            --gold:         #f5c842;
            --gold-soft:    #fffbea;
            --surface:      #ffffff;
            --bg:           #f4f6fd;
            --border:       #e4e8f8;
            --text-h:       #0d1535;
            --text-body:    #4a5380;
            --text-muted:   #8b93c4;
            --shadow-sm:    0 2px 12px rgba(79,110,247,0.07);
            --shadow-md:    0 10px 32px rgba(79,110,247,0.13);
            --shadow-lg:    0 18px 52px rgba(79,110,247,0.17);

            /* status palette */
            --s-pending-bg:   #fffbea; --s-pending-bdr:  #fde68a; --s-pending-t:  #92400e;
            --s-approved-bg:  #f0fdf4; --s-approved-bdr: #86efac; --s-approved-t: #166534;
            --s-rejected-bg:  #fff1f2; --s-rejected-bdr: #fda4af; --s-rejected-t: #9f1239;
            --s-cancelled-bg: #f1f5f9; --s-cancelled-bdr:#cbd5e1; --s-cancelled-t: #475569;

            --error-bg:     #fff1f2; --error-bdr: #fda4af; --error-t: #9f1239;
            --success-bg:   #f0fdf4; --success-bdr:#86efac; --success-t:#166534;
        }

        body {
            background: var(--bg);
            font-family: 'DM Sans', sans-serif;
            color: var(--text-body);
            min-height: 100vh;
        }

        /* ─── Navbar ─── */
        .navbar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 0 40px;
            height: 68px;
            display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 200;
            box-shadow: 0 2px 16px rgba(79,110,247,0.06);
        }
        .nav-brand {
            display: flex; align-items: center; gap: 11px;
            text-decoration: none;
        }
        .nav-brand .icon {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, var(--accent), var(--gold));
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 19px;
        }
        .nav-brand span {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: 17px;
            color: var(--navy); letter-spacing: -.3px;
        }
        .nav-actions { display: flex; align-items: center; gap: 10px; }
        .nav-btn {
            display: inline-flex; align-items: center; gap: 6px;
            text-decoration: none; font-size: 13.5px; font-weight: 500;
            padding: 8px 14px; border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--surface); color: var(--text-muted);
            transition: color .2s, border-color .2s, box-shadow .2s, transform .2s;
        }
        .nav-btn:hover {
            color: var(--accent); border-color: rgba(79,110,247,.35);
            box-shadow: 0 4px 14px rgba(79,110,247,.1);
            transform: translateY(-1px);
        }
        .nav-btn.solid {
            background: var(--navy); color: #eef0ff;
            border-color: var(--navy);
            font-family: 'Syne', sans-serif; font-weight: 700;
        }
        .nav-btn.solid:hover { background: var(--accent-dark); border-color: var(--accent-dark); }

        /* ─── Page ─── */
        .page {
            max-width: 1240px;
            margin: 0 auto;
            padding: 36px 24px 80px;
        }

        /* ─── Hero ─── */
        .hero {
            position: relative; overflow: hidden;
            background: linear-gradient(130deg, var(--navy) 0%, #1a2560 55%, #0d1f4e 100%);
            border-radius: 24px; padding: 44px 48px;
            margin-bottom: 28px;
            box-shadow: var(--shadow-lg);
            animation: fadeUp .5s cubic-bezier(.16,1,.3,1) both;
        }
        .hero::before {
            content: '';
            position: absolute; inset: 0;
            background-image:
                linear-gradient(rgba(79,110,247,.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(79,110,247,.08) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
        }
        .hero::after {
            content: '';
            position: absolute;
            width: 360px; height: 360px;
            background: radial-gradient(circle, rgba(79,110,247,.3) 0%, transparent 70%);
            border-radius: 50%;
            top: -110px; right: -50px;
            filter: blur(60px); pointer-events: none;
        }
        .hero-inner {
            position: relative; z-index: 1;
            display: flex; align-items: center;
            justify-content: space-between; gap: 28px; flex-wrap: wrap;
        }
        .hero-text .eyebrow {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 11px; font-weight: 600;
            letter-spacing: 1.8px; text-transform: uppercase;
            color: var(--gold); margin-bottom: 10px;
        }
        .hero-text .eyebrow::before {
            content: ''; width: 16px; height: 2px;
            background: var(--gold); border-radius: 2px;
        }
        .hero-text h1 {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: clamp(24px,3vw,36px);
            color: #eef0ff; letter-spacing: -.5px;
            line-height: 1.18; margin-bottom: 10px;
        }
        .hero-text p { font-size: 14px; color: #8b93c4; line-height: 1.72; max-width: 520px; }
        .hero-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px; }
        .ha-btn {
            display: inline-flex; align-items: center; gap: 7px;
            text-decoration: none; padding: 9px 18px; border-radius: 11px;
            font-family: 'Syne', sans-serif; font-size: 13px; font-weight: 700;
            transition: transform .2s, box-shadow .2s, background .2s;
        }
        .ha-btn.white { background: #fff; color: var(--accent-dark); }
        .ha-btn.white:hover { background: var(--accent-soft); transform: translateY(-1px); }
        .ha-btn.ghost {
            background: rgba(255,255,255,.1); color: #eef0ff;
            border: 1px solid rgba(255,255,255,.18);
        }
        .ha-btn.ghost:hover { background: rgba(255,255,255,.18); transform: translateY(-1px); }

        /* Hero stat pills */
        .hero-stats { display: flex; gap: 12px; flex-wrap: wrap; flex-shrink: 0; }
        .hstat {
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 16px; padding: 14px 18px;
            text-align: center; min-width: 76px;
            backdrop-filter: blur(8px);
        }
        .hstat .num {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: 22px;
            color: #eef0ff; line-height: 1; margin-bottom: 4px;
        }
        .hstat .num.gold { color: var(--gold); }
        .hstat .num.green { color: #4ade80; }
        .hstat .num.red   { color: #f87171; }
        .hstat .lbl { font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: #8b93c4; }

        /* ─── Alerts ─── */
        .alert {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 18px; border-radius: 14px;
            margin-bottom: 24px; font-size: 13.5px;
            font-weight: 500; border: 1px solid;
            animation: slideDown .4s cubic-bezier(.16,1,.3,1) both;
        }
        .alert-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
        .alert-body strong {
            display: block; font-weight: 700;
            font-family: 'Syne', sans-serif; font-size: 13px; margin-bottom: 2px;
        }
        .alert.success { background: var(--success-bg); border-color: var(--success-bdr); color: var(--success-t); }
        .alert.error   { background: var(--error-bg);   border-color: var(--error-bdr);   color: var(--error-t); }

        /* ─── Grid ─── */
        .requests-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(480px, 1fr));
            gap: 22px;
        }

        /* ─── Request card ─── */
        .request-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 22px; overflow: hidden;
            box-shadow: var(--shadow-sm);
            display: flex; flex-direction: column;
            transition: transform .25s cubic-bezier(.34,1.56,.64,1), box-shadow .25s, border-color .25s;
            animation: cardUp .5s cubic-bezier(.16,1,.3,1) both;
        }
        .request-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
            border-color: rgba(79,110,247,.22);
        }

        /* Image strip */
        .card-img-wrap {
            position: relative; height: 200px; overflow: hidden;
            background: linear-gradient(135deg, #c7d2fe 0%, #a5b4fc 50%, #818cf8 100%);
            flex-shrink: 0;
        }
        .card-img-wrap img {
            width: 100%; height: 100%; object-fit: cover;
            display: block; transition: transform .4s ease;
        }
        .request-card:hover .card-img-wrap img { transform: scale(1.04); }
        .img-placeholder {
            width: 100%; height: 100%;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 7px;
        }
        .img-placeholder .ph-icon  { font-size: 38px; opacity: .5; }
        .img-placeholder .ph-lbl   {
            font-size: 10px; font-weight: 600; letter-spacing: 1px;
            text-transform: uppercase; color: #6366f1; opacity: .65;
        }

        /* Status ribbon */
        .status-ribbon {
            position: absolute; top: 12px; left: 12px;
            font-size: 11px; font-weight: 700;
            padding: 4px 11px; border-radius: 999px;
            border: 1px solid transparent;
            backdrop-filter: blur(8px); letter-spacing: .3px;
        }
        .s-pending   { background: var(--s-pending-bg);   border-color: var(--s-pending-bdr);   color: var(--s-pending-t); }
        .s-approved  { background: var(--s-approved-bg);  border-color: var(--s-approved-bdr);  color: var(--s-approved-t); }
        .s-rejected  { background: var(--s-rejected-bg);  border-color: var(--s-rejected-bdr);  color: var(--s-rejected-t); }
        .s-cancelled { background: var(--s-cancelled-bg); border-color: var(--s-cancelled-bdr); color: var(--s-cancelled-t); }

        /* Status emoji map */
        .s-pending::before   { content: '⏳ '; }
        .s-approved::before  { content: '✅ '; }
        .s-rejected::before  { content: '❌ '; }
        .s-cancelled::before { content: '🚫 '; }

        /* Request ID pill */
        .req-id-pill {
            position: absolute; top: 12px; right: 12px;
            font-size: 10.5px; font-weight: 700;
            padding: 3px 9px; border-radius: 999px;
            background: var(--navy); color: #eef0ff;
            letter-spacing: .4px;
        }

        /* Card body */
        .card-body { padding: 20px 22px 22px; display: flex; flex-direction: column; flex: 1; }

        .card-title {
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: 18px; color: var(--text-h);
            letter-spacing: -.3px; line-height: 1.3;
            margin-bottom: 16px;
        }

        /* Meta grid */
        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 9px; margin-bottom: 16px;
        }
        .meta-cell {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 12px; padding: 10px 13px;
        }
        .meta-cell .ml {
            font-size: 10px; font-weight: 600;
            letter-spacing: .7px; text-transform: uppercase;
            color: var(--text-muted); margin-bottom: 4px;
        }
        .meta-cell .mv {
            font-size: 13.5px; color: var(--text-h);
            font-weight: 600; line-height: 1.35;
        }
        .meta-cell .mv a {
            color: var(--accent); text-decoration: none;
            font-weight: 600; font-size: 12px;
            display: inline-flex; align-items: center; gap: 3px;
        }
        .meta-cell .mv a:hover { text-decoration: underline; }

        /* Date range pill */
        .date-range {
            display: flex; align-items: center; gap: 8px;
            background: var(--accent-soft);
            border: 1px solid var(--border);
            border-radius: 12px; padding: 10px 14px;
            margin-bottom: 14px;
            font-size: 13px; color: var(--accent-dark);
            flex-wrap: wrap;
        }
        .date-range .dr-icon { font-size: 14px; flex-shrink: 0; }
        .date-range strong { font-weight: 700; color: var(--text-h); font-family: 'Syne', sans-serif; font-size: 12px; }
        .date-range .arrow { color: var(--text-muted); font-size: 13px; }

        /* Message box */
        .msg-box {
            background: var(--bg);
            border-left: 3px solid var(--accent);
            border-radius: 11px; padding: 12px 15px;
            margin-bottom: 16px; flex: 1;
        }
        .msg-box .msg-label {
            font-size: 10.5px; font-weight: 700;
            letter-spacing: 1px; text-transform: uppercase;
            color: var(--text-muted); margin-bottom: 6px;
        }
        .msg-box p {
            font-size: 13.5px; color: var(--text-body);
            line-height: 1.65;
        }

        /* Footer */
        .card-footer {
            padding-top: 14px;
            border-top: 1px solid var(--border);
            display: flex; align-items: center;
            justify-content: space-between; gap: 10px;
            margin-top: auto; flex-wrap: wrap;
        }
        .footer-id {
            font-size: 11.5px; color: var(--text-muted); letter-spacing: .2px;
        }

        /* Cancel button */
        .btn-cancel {
            display: inline-flex; align-items: center; gap: 7px;
            border: none;
            background: #fff1f2; color: #be123c;
            border: 1px solid #fda4af;
            padding: 9px 16px; border-radius: 11px;
            font-family: 'Syne', sans-serif; font-size: 12.5px; font-weight: 700;
            cursor: pointer; letter-spacing: .3px;
            transition: background .2s, transform .2s, box-shadow .2s;
        }
        .btn-cancel:hover {
            background: #ffe4e6;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(190,18,60,.18);
        }
        .btn-cancel:active { transform: translateY(0); }

        /* ─── Empty state ─── */
        .empty-box {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 24px; padding: 72px 28px;
            text-align: center; box-shadow: var(--shadow-sm);
            animation: fadeUp .5s cubic-bezier(.16,1,.3,1) both;
        }
        .empty-box .e-icon { font-size: 52px; opacity: .4; margin-bottom: 18px; display: block; }
        .empty-box h3 {
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: 22px; color: var(--text-h); margin-bottom: 8px;
        }
        .empty-box p {
            font-size: 14px; color: var(--text-muted);
            max-width: 340px; margin: 0 auto 24px; line-height: 1.65;
        }
        .empty-box a {
            display: inline-flex; align-items: center; gap: 7px;
            text-decoration: none; padding: 12px 24px;
            background: var(--navy); color: #eef0ff;
            border-radius: 12px;
            font-family: 'Syne', sans-serif; font-size: 13.5px; font-weight: 700;
            transition: background .2s, transform .2s;
        }
        .empty-box a:hover { background: var(--accent-dark); transform: translateY(-1px); }

        /* ─── Animations ─── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes cardUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .request-card:nth-child(1) { animation-delay: .04s; }
        .request-card:nth-child(2) { animation-delay: .08s; }
        .request-card:nth-child(3) { animation-delay: .12s; }
        .request-card:nth-child(4) { animation-delay: .16s; }
        .request-card:nth-child(n+5){ animation-delay: .20s; }

        /* ─── Responsive ─── */
        @media (max-width: 1000px) {
            .requests-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 700px) {
            .navbar { padding: 0 18px; }
            .page   { padding: 22px 14px 60px; }
            .hero   { padding: 28px 22px; }
            .hero-stats { display: none; }
            .meta-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 460px) {
            .nav-btn:not(.solid) { display: none; }
        }
    </style>
</head>
<body>

<!-- ════════════ NAVBAR ════════════ -->
<nav class="navbar">
    <a class="nav-brand" href="dashboard.php">
        <div class="icon">🎓</div>
        <span>CampusShare</span>
    </a>
    <div class="nav-actions">
        <a href="browse_items.php" class="nav-btn">🔎 Browse Items</a>
        <a href="dashboard.php" class="nav-btn solid">← Dashboard</a>
    </div>
</nav>

<!-- ════════════ PAGE ════════════ -->
<div class="page">

    <!-- Hero -->
    <div class="hero">
        <div class="hero-inner">
            <div class="hero-text">
                <p class="eyebrow">Borrowing Activity</p>
                <h1>My Requests</h1>
                <p>Track all the borrowing requests you have sent, along with their dates, messages, and approval status.</p>
                <div class="hero-actions">
                    <a href="browse_items.php" class="ha-btn white">🔎 Browse Items</a>
                    <a href="dashboard.php"    class="ha-btn ghost">← Dashboard</a>
                </div>
            </div>
            <div class="hero-stats">
                <div class="hstat">
                    <div class="num"><?php echo count($all_requests); ?></div>
                    <div class="lbl">Total</div>
                </div>
                <div class="hstat">
                    <div class="num gold"><?php echo $status_counts['pending']; ?></div>
                    <div class="lbl">Pending</div>
                </div>
                <div class="hstat">
                    <div class="num green"><?php echo $status_counts['approved']; ?></div>
                    <div class="lbl">Approved</div>
                </div>
                <div class="hstat">
                    <div class="num red"><?php echo $status_counts['rejected']; ?></div>
                    <div class="lbl">Rejected</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($success)): ?>
    <div class="alert success">
        <span class="alert-icon">✅</span>
        <div class="alert-body">
            <strong>Done!</strong>
            <?php echo htmlspecialchars($success); ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
    <div class="alert error">
        <span class="alert-icon">⚠️</span>
        <div class="alert-body">
            <strong>Something went wrong</strong>
            <?php echo htmlspecialchars($error); ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════════ REQUESTS GRID ════════ -->
    <?php if (!empty($all_requests)): ?>

        <div class="requests-grid">
            <?php foreach ($all_requests as $row):
                $status    = htmlspecialchars($row['request_status']);
                $statusCap = ucfirst($status);
            ?>

            <div class="request-card">

                <!-- Image strip -->
                <div class="card-img-wrap">
                    <?php if (!empty($row['image_path'])): ?>
                        <img
                            src="<?php echo htmlspecialchars($row['image_path']); ?>"
                            alt="<?php echo htmlspecialchars($row['item_name']); ?>"
                            loading="lazy"
                        >
                    <?php else: ?>
                        <div class="img-placeholder">
                            <span class="ph-icon">📦</span>
                            <span class="ph-lbl">No image</span>
                        </div>
                    <?php endif; ?>

                    <span class="status-ribbon s-<?php echo $status; ?>">
                        <?php echo $statusCap; ?>
                    </span>
                    <span class="req-id-pill">#<?php echo (int)$row['request_id']; ?></span>
                </div>

                <!-- Card body -->
                <div class="card-body">

                    <h3 class="card-title">
                        <?php echo htmlspecialchars($row['item_name']); ?>
                    </h3>

                    <!-- Meta grid -->
                    <div class="meta-grid">
                        <div class="meta-cell">
                            <div class="ml">Owner</div>
                            <div class="mv">
                                <?php echo htmlspecialchars($row['owner_name']); ?>
                                <br>
                                <a href="user_reviews.php?user_id=<?php echo (int)$row['owner_id']; ?>">
                                    ⭐ View Reviews
                                </a>
                            </div>
                        </div>
                        <div class="meta-cell">
                            <div class="ml">Condition</div>
                            <div class="mv"><?php echo htmlspecialchars($row['item_condition']); ?></div>
                        </div>
                        <div class="meta-cell">
                            <div class="ml">Request Date</div>
                            <div class="mv"><?php echo htmlspecialchars($row['request_date']); ?></div>
                        </div>
                        <div class="meta-cell">
                            <div class="ml">Owner Response</div>
                            <div class="mv">
                                <?php echo !empty($row['owner_response_date'])
                                    ? htmlspecialchars($row['owner_response_date'])
                                    : '<span style="color:var(--text-muted);font-weight:400;">Not yet</span>'; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Date range pill -->
                    <div class="date-range">
                        <span class="dr-icon">📅</span>
                        <strong>Borrow Period</strong>
                        <span class="arrow">·</span>
                        <?php echo htmlspecialchars($row['requested_from_date']); ?>
                        <span class="arrow">→</span>
                        <?php echo htmlspecialchars($row['requested_to_date']); ?>
                    </div>

                    <!-- Request message -->
                    <?php if (!empty($row['request_message'])): ?>
                    <div class="msg-box">
                        <div class="msg-label">💬 Your Message</div>
                        <p><?php echo nl2br(htmlspecialchars($row['request_message'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <!-- Footer -->
                    <div class="card-footer">
                        <span class="footer-id">
                            Request ID: <?php echo (int)$row['request_id']; ?>
                        </span>

                        <?php if ($row['request_status'] === 'pending'): ?>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="request_id" value="<?php echo (int)$row['request_id']; ?>">
                            <button type="submit" name="action" value="cancel" class="btn-cancel">
                                🚫 Cancel Request
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

            <?php endforeach; ?>
        </div>

    <?php else: ?>

        <div class="empty-box">
            <span class="e-icon">📨</span>
            <h3>No requests yet</h3>
            <p>You haven't sent any borrow requests yet. Browse available items and make your first request.</p>
            <a href="browse_items.php">🔎 Browse Items</a>
        </div>

    <?php endif; ?>

</div><!-- /.page -->

</body>
</html>