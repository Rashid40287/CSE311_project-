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

/* Handle mark as returned */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
    $action = $_POST['action'] ?? '';

    if ($transaction_id <= 0 || $action !== 'mark_returned') {
        $error = "Invalid action.";
    } else {
        $check_stmt = $conn->prepare("
            SELECT 
                bt.transaction_id,
                bt.transaction_status,
                br.request_id,
                br.borrower_id,
                i.owner_id
            FROM borrow_transaction bt
            JOIN borrow_request br ON bt.request_id = br.request_id
            JOIN item i ON br.item_id = i.item_id
            WHERE bt.transaction_id = ?
              AND (br.borrower_id = ? OR i.owner_id = ?)
        ");
        $check_stmt->bind_param("iii", $transaction_id, $student_id, $student_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows !== 1) {
            $error = "Transaction not found or access denied.";
        } else {
            $tx = $check_result->fetch_assoc();

            if ($tx['transaction_status'] !== 'active' && $tx['transaction_status'] !== 'overdue') {
                $error = "Only active or overdue transactions can be marked as returned.";
            } else {
                $return_date = date("Y-m-d");
                $update_stmt = $conn->prepare("
                    UPDATE borrow_transaction
                    SET return_date = ?, transaction_status = 'returned'
                    WHERE transaction_id = ?
                ");
                $update_stmt->bind_param("si", $return_date, $transaction_id);

                if ($update_stmt->execute()) {
                    $success = "Transaction marked as returned successfully.";
                } else {
                    $error = "Failed to update transaction.";
                }

                $update_stmt->close();
            }
        }

        $check_stmt->close();
    }
}

/* Fetch transactions where user is borrower or owner */
$stmt = $conn->prepare("
    SELECT
        bt.transaction_id,
        bt.borrow_date,
        bt.due_date,
        bt.return_date,
        bt.transaction_status,
        br.request_id,
        br.borrower_id,
        br.request_message,
        i.item_id,
        i.item_name,
        i.item_condition,
        owner.full_name AS owner_name,
        borrower.full_name AS borrower_name,
        img.image_path,
        CASE
            WHEN br.borrower_id = ? THEN 'Borrower'
            ELSE 'Owner'
        END AS my_role
    FROM borrow_transaction bt
    JOIN borrow_request br ON bt.request_id = br.request_id
    JOIN item i ON br.item_id = i.item_id
    JOIN student owner ON i.owner_id = owner.student_id
    JOIN student borrower ON br.borrower_id = borrower.student_id
    LEFT JOIN item_image img ON i.item_id = img.item_id AND img.is_primary = TRUE
    WHERE br.borrower_id = ? OR i.owner_id = ?
    ORDER BY
        CASE
            WHEN bt.transaction_status = 'active'   THEN 1
            WHEN bt.transaction_status = 'overdue'  THEN 2
            WHEN bt.transaction_status = 'returned' THEN 3
            ELSE 4
        END,
        bt.transaction_id DESC
");
$stmt->bind_param("iii", $student_id, $student_id, $student_id);
$stmt->execute();
$result = $stmt->get_result();

/* Pre-fetch all rows + status counts */
$all_tx = [];
$counts = ['active' => 0, 'overdue' => 0, 'returned' => 0];
while ($row = $result->fetch_assoc()) {
    $all_tx[] = $row;
    $s = $row['transaction_status'];
    if (isset($counts[$s])) $counts[$s]++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Transactions | Campus Resource Sharing</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --navy:        #0a0f2c;
            --accent:      #4f6ef7;
            --accent-dark: #3b5bdb;
            --accent-soft: #eef1ff;
            --gold:        #f5c842;
            --surface:     #ffffff;
            --bg:          #f4f6fd;
            --border:      #e4e8f8;
            --text-h:      #0d1535;
            --text-body:   #4a5380;
            --text-muted:  #8b93c4;
            --shadow-sm:   0 2px 12px rgba(79,110,247,0.07);
            --shadow-md:   0 10px 32px rgba(79,110,247,0.13);
            --shadow-lg:   0 18px 52px rgba(79,110,247,0.17);

            /* transaction status palette */
            --s-active-bg:   #dbeafe; --s-active-bdr:   #93c5fd; --s-active-t:   #1e40af;
            --s-overdue-bg:  #fffbea; --s-overdue-bdr:  #fde68a; --s-overdue-t:  #92400e;
            --s-returned-bg: #f0fdf4; --s-returned-bdr: #86efac; --s-returned-t: #166534;

            --success-bg: #f0fdf4; --success-bdr: #86efac; --success-t: #166534;
            --error-bg:   #fff1f2; --error-bdr:   #fda4af; --error-t:   #9f1239;
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
            padding: 0 40px; height: 68px;
            display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 200;
            box-shadow: 0 2px 16px rgba(79,110,247,0.06);
        }
        .nav-brand { display: flex; align-items: center; gap: 11px; text-decoration: none; }
        .nav-brand .icon {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, var(--accent), var(--gold));
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center; font-size: 19px;
        }
        .nav-brand span {
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: 17px; color: var(--navy); letter-spacing: -.3px;
        }
        .nav-actions { display: flex; align-items: center; gap: 10px; }
        .nav-btn {
            display: inline-flex; align-items: center; gap: 6px;
            text-decoration: none; font-size: 13.5px; font-weight: 500;
            padding: 8px 14px; border-radius: 10px;
            border: 1px solid var(--border); background: var(--surface); color: var(--text-muted);
            transition: color .2s, border-color .2s, box-shadow .2s, transform .2s;
        }
        .nav-btn:hover {
            color: var(--accent); border-color: rgba(79,110,247,.35);
            box-shadow: 0 4px 14px rgba(79,110,247,.1); transform: translateY(-1px);
        }
        .nav-btn.solid {
            background: var(--navy); color: #eef0ff; border-color: var(--navy);
            font-family: 'Syne', sans-serif; font-weight: 700;
        }
        .nav-btn.solid:hover { background: var(--accent-dark); border-color: var(--accent-dark); }

        /* ─── Page ─── */
        .page { max-width: 1240px; margin: 0 auto; padding: 36px 24px 80px; }

        /* ─── Hero ─── */
        .hero {
            position: relative; overflow: hidden;
            background: linear-gradient(130deg, var(--navy) 0%, #1a2560 55%, #0d1f4e 100%);
            border-radius: 24px; padding: 44px 48px; margin-bottom: 28px;
            box-shadow: var(--shadow-lg);
            animation: fadeUp .5s cubic-bezier(.16,1,.3,1) both;
        }
        .hero::before {
            content: ''; position: absolute; inset: 0;
            background-image:
                linear-gradient(rgba(79,110,247,.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(79,110,247,.08) 1px, transparent 1px);
            background-size: 40px 40px; pointer-events: none;
        }
        .hero::after {
            content: ''; position: absolute;
            width: 360px; height: 360px;
            background: radial-gradient(circle, rgba(79,110,247,.3) 0%, transparent 70%);
            border-radius: 50%; top: -110px; right: -50px;
            filter: blur(60px); pointer-events: none;
        }
        .hero-inner {
            position: relative; z-index: 1;
            display: flex; align-items: center;
            justify-content: space-between; gap: 28px; flex-wrap: wrap;
        }
        .hero-text .eyebrow {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 11px; font-weight: 600; letter-spacing: 1.8px;
            text-transform: uppercase; color: var(--gold); margin-bottom: 10px;
        }
        .hero-text .eyebrow::before {
            content: ''; width: 16px; height: 2px; background: var(--gold); border-radius: 2px;
        }
        .hero-text h1 {
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: clamp(24px,3vw,36px); color: #eef0ff;
            letter-spacing: -.5px; line-height: 1.18; margin-bottom: 10px;
        }
        .hero-text p { font-size: 14px; color: #8b93c4; line-height: 1.72; max-width: 540px; }
        .hero-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px; }
        .ha-btn {
            display: inline-flex; align-items: center; gap: 7px;
            text-decoration: none; padding: 9px 18px; border-radius: 11px;
            font-family: 'Syne', sans-serif; font-size: 13px; font-weight: 700;
            transition: transform .2s, background .2s;
        }
        .ha-btn.white { background: #fff; color: var(--accent-dark); }
        .ha-btn.white:hover { background: var(--accent-soft); transform: translateY(-1px); }
        .ha-btn.ghost {
            background: rgba(255,255,255,.1); color: #eef0ff;
            border: 1px solid rgba(255,255,255,.18);
        }
        .ha-btn.ghost:hover { background: rgba(255,255,255,.18); transform: translateY(-1px); }

        /* Hero stats */
        .hero-stats { display: flex; gap: 12px; flex-wrap: wrap; flex-shrink: 0; }
        .hstat {
            background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
            border-radius: 16px; padding: 14px 18px; text-align: center;
            min-width: 80px; backdrop-filter: blur(8px);
        }
        .hstat .num {
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: 22px; color: #eef0ff; line-height: 1; margin-bottom: 4px;
        }
        .hstat .num.blue   { color: #93c5fd; }
        .hstat .num.gold   { color: var(--gold); }
        .hstat .num.green  { color: #4ade80; }
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

        /* Overdue notice banner */
        .overdue-banner {
            display: flex; align-items: center; gap: 12px;
            background: var(--s-overdue-bg); border: 1px solid var(--s-overdue-bdr);
            border-radius: 14px; padding: 13px 18px; margin-bottom: 24px;
            font-size: 13.5px; color: var(--s-overdue-t); font-weight: 500;
            animation: fadeUp .5s cubic-bezier(.16,1,.3,1) .08s both;
        }
        .overdue-banner strong { font-family: 'Syne', sans-serif; font-weight: 700; }

        /* ─── Grid ─── */
        .tx-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(480px, 1fr));
            gap: 22px;
        }

        /* ─── Transaction card ─── */
        .tx-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 22px; overflow: hidden;
            box-shadow: var(--shadow-sm);
            display: flex; flex-direction: column;
            transition: transform .25s cubic-bezier(.34,1.56,.64,1), box-shadow .25s, border-color .25s;
            animation: cardUp .5s cubic-bezier(.16,1,.3,1) both;
        }
        .tx-card.is-overdue {
            border-color: var(--s-overdue-bdr);
            box-shadow: var(--shadow-sm), 0 0 0 1px var(--s-overdue-bdr);
        }
        .tx-card.is-active {
            border-color: var(--s-active-bdr);
        }
        .tx-card:hover {
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
        .tx-card:hover .card-img-wrap img { transform: scale(1.04); }
        .img-placeholder {
            width: 100%; height: 100%;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 7px;
        }
        .img-placeholder .ph-icon { font-size: 38px; opacity: .5; }
        .img-placeholder .ph-lbl  {
            font-size: 10px; font-weight: 600; letter-spacing: 1px;
            text-transform: uppercase; color: #6366f1; opacity: .65;
        }

        /* Status ribbon */
        .status-ribbon {
            position: absolute; top: 12px; left: 12px;
            font-size: 11px; font-weight: 700; letter-spacing: .3px;
            padding: 4px 11px; border-radius: 999px;
            border: 1px solid transparent; backdrop-filter: blur(8px);
        }
        .s-active   { background: var(--s-active-bg);   border-color: var(--s-active-bdr);   color: var(--s-active-t); }
        .s-overdue  { background: var(--s-overdue-bg);  border-color: var(--s-overdue-bdr);  color: var(--s-overdue-t); }
        .s-returned { background: var(--s-returned-bg); border-color: var(--s-returned-bdr); color: var(--s-returned-t); }

        .s-active::before   { content: '🔵 '; }
        .s-overdue::before  { content: '⚠️ '; }
        .s-returned::before { content: '✅ '; }

        /* Role pip */
        .role-pip {
            position: absolute; top: 12px; right: 12px;
            font-size: 10.5px; font-weight: 700; letter-spacing: .4px;
            padding: 3px 9px; border-radius: 999px;
            background: var(--navy); color: #eef0ff;
        }

        /* Card body */
        .card-body { padding: 20px 22px 22px; display: flex; flex-direction: column; flex: 1; }

        .card-title {
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: 18px; color: var(--text-h);
            letter-spacing: -.3px; line-height: 1.3; margin-bottom: 16px;
        }

        /* Participants row */
        .participants {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 9px; margin-bottom: 14px;
        }
        .participant-cell {
            background: var(--accent-soft);
            border: 1px solid var(--border);
            border-radius: 12px; padding: 10px 13px;
            display: flex; align-items: center; gap: 9px;
        }
        .participant-cell .p-avatar {
            width: 30px; height: 30px; border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: 13px; color: #fff; flex-shrink: 0;
        }
        .participant-cell .p-info .p-role {
            font-size: 10px; font-weight: 600; letter-spacing: .7px;
            text-transform: uppercase; color: var(--text-muted); margin-bottom: 2px;
        }
        .participant-cell .p-info .p-name {
            font-size: 13px; font-weight: 600; color: var(--text-h); line-height: 1.2;
        }

        /* Meta grid */
        .meta-grid {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 9px; margin-bottom: 14px;
        }
        .meta-cell {
            background: var(--bg); border: 1px solid var(--border);
            border-radius: 12px; padding: 10px 13px;
        }
        .meta-cell .ml {
            font-size: 10px; font-weight: 600; letter-spacing: .7px;
            text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px;
        }
        .meta-cell .mv {
            font-size: 13.5px; color: var(--text-h); font-weight: 600; line-height: 1.35;
        }
        .meta-cell .mv.muted { color: var(--text-muted); font-weight: 400; }
        .meta-cell .mv.overdue-text { color: var(--s-overdue-t); }

        /* Timeline bar */
        .timeline-bar {
            background: var(--bg); border: 1px solid var(--border);
            border-radius: 12px; padding: 12px 14px; margin-bottom: 14px;
        }
        .tl-label {
            font-size: 10px; font-weight: 600; letter-spacing: .7px;
            text-transform: uppercase; color: var(--text-muted); margin-bottom: 10px;
        }
        .tl-track {
            display: flex; align-items: center; gap: 0;
        }
        .tl-node {
            display: flex; flex-direction: column; align-items: center; flex-shrink: 0;
        }
        .tl-dot {
            width: 10px; height: 10px; border-radius: 50%;
            border: 2px solid; margin-bottom: 5px;
        }
        .tl-dot.filled { background: var(--accent); border-color: var(--accent); }
        .tl-dot.empty  { background: var(--surface); border-color: var(--border); }
        .tl-dot.green  { background: #22c55e; border-color: #22c55e; }
        .tl-dot.amber  { background: #f59e0b; border-color: #f59e0b; }
        .tl-node-lbl   { font-size: 10px; color: var(--text-muted); white-space: nowrap; }
        .tl-node-date  { font-size: 11px; font-weight: 600; color: var(--text-h); white-space: nowrap; }
        .tl-line {
            flex: 1; height: 2px; background: var(--border);
            margin-bottom: 14px; /* align with dots */
        }
        .tl-line.done { background: linear-gradient(90deg, var(--accent), var(--accent-dark)); }

        /* Message box */
        .msg-box {
            background: var(--bg); border-left: 3px solid var(--accent);
            border-radius: 11px; padding: 12px 15px; margin-bottom: 16px;
        }
        .msg-box .msg-label {
            font-size: 10.5px; font-weight: 700; letter-spacing: 1px;
            text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px;
        }
        .msg-box p { font-size: 13.5px; color: var(--text-body); line-height: 1.65; }

        /* Card footer */
        .card-footer {
            padding-top: 14px; border-top: 1px solid var(--border);
            display: flex; align-items: center;
            justify-content: space-between; gap: 10px;
            margin-top: auto; flex-wrap: wrap;
        }
        .footer-ids { font-size: 11.5px; color: var(--text-muted); letter-spacing: .2px; }

        /* Mark returned button */
        .btn-return {
            display: inline-flex; align-items: center; gap: 7px;
            border: none; padding: 10px 18px; border-radius: 11px;
            font-family: 'Syne', sans-serif; font-size: 12.5px; font-weight: 700;
            cursor: pointer; letter-spacing: .3px;
            background: var(--s-returned-bg); color: var(--s-returned-t);
            border: 1px solid var(--s-returned-bdr);
            transition: background .2s, transform .2s, box-shadow .2s;
        }
        .btn-return:hover {
            background: #dcfce7; transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(22,163,74,.2);
        }
        .btn-return:active { transform: translateY(0); }

        /* Returned note */
        .returned-note {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12.5px; font-weight: 600; color: var(--s-returned-t);
        }

        /* ─── Empty state ─── */
        .empty-box {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 24px; padding: 72px 28px; text-align: center;
            box-shadow: var(--shadow-sm);
            animation: fadeUp .5s cubic-bezier(.16,1,.3,1) both;
        }
        .empty-box .e-icon { font-size: 52px; opacity: .4; margin-bottom: 18px; display: block; }
        .empty-box h3 {
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: 22px; color: var(--text-h); margin-bottom: 8px;
        }
        .empty-box p {
            font-size: 14px; color: var(--text-muted);
            max-width: 360px; margin: 0 auto; line-height: 1.65;
        }

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

        .tx-card:nth-child(1)  { animation-delay: .04s; }
        .tx-card:nth-child(2)  { animation-delay: .08s; }
        .tx-card:nth-child(3)  { animation-delay: .12s; }
        .tx-card:nth-child(4)  { animation-delay: .16s; }
        .tx-card:nth-child(n+5){ animation-delay: .20s; }

        /* ─── Responsive ─── */
        @media (max-width: 1040px) { .tx-grid { grid-template-columns: 1fr; } }
        @media (max-width: 700px) {
            .navbar { padding: 0 18px; }
            .page   { padding: 22px 14px 60px; }
            .hero   { padding: 28px 22px; }
            .hero-stats { display: none; }
            .meta-grid, .participants { grid-template-columns: 1fr; }
        }
        @media (max-width: 460px) { .nav-btn:not(.solid) { display: none; } }
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
        <a href="manage_requests.php" class="nav-btn">🛠️ Manage Requests</a>
        <a href="dashboard.php"       class="nav-btn solid">← Dashboard</a>
    </div>
</nav>

<!-- ════════════ PAGE ════════════ -->
<div class="page">

    <!-- Hero -->
    <div class="hero">
        <div class="hero-inner">
            <div class="hero-text">
                <p class="eyebrow">Borrowing History</p>
                <h1>My Transactions</h1>
                <p>Track all borrowing activity for items you own or have borrowed. Mark active transactions as returned once the item is back.</p>
                <div class="hero-actions">
                    <a href="manage_requests.php" class="ha-btn white">🛠️ Manage Requests</a>
                    <a href="dashboard.php"        class="ha-btn ghost">← Dashboard</a>
                </div>
            </div>
            <div class="hero-stats">
                <div class="hstat">
                    <div class="num"><?php echo count($all_tx); ?></div>
                    <div class="lbl">Total</div>
                </div>
                <div class="hstat">
                    <div class="num blue"><?php echo $counts['active']; ?></div>
                    <div class="lbl">Active</div>
                </div>
                <div class="hstat">
                    <div class="num gold"><?php echo $counts['overdue']; ?></div>
                    <div class="lbl">Overdue</div>
                </div>
                <div class="hstat">
                    <div class="num green"><?php echo $counts['returned']; ?></div>
                    <div class="lbl">Returned</div>
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

    <!-- Overdue warning banner -->
    <?php if ($counts['overdue'] > 0): ?>
    <div class="overdue-banner">
        <span style="font-size:18px;">⚠️</span>
        <span>You have <strong><?php echo $counts['overdue']; ?> overdue transaction<?php echo $counts['overdue'] !== 1 ? 's' : ''; ?></strong> — please return the item<?php echo $counts['overdue'] !== 1 ? 's' : ''; ?> as soon as possible.</span>
    </div>
    <?php endif; ?>

    <!-- ════════ TRANSACTIONS GRID ════════ -->
    <?php if (!empty($all_tx)): ?>

        <div class="tx-grid">
            <?php foreach ($all_tx as $row):
                $status    = $row['transaction_status'];
                $statusCap = ucfirst($status);
                $isActive  = ($status === 'active');
                $isOverdue = ($status === 'overdue');
                $cardClass = $isOverdue ? 'is-overdue' : ($isActive ? 'is-active' : '');

                /* Avatar initials */
                $ownerInit    = mb_strtoupper(mb_substr($row['owner_name'],    0, 1));
                $borrowerInit = mb_strtoupper(mb_substr($row['borrower_name'], 0, 1));

                /* Timeline dot states */
                $borrowDone  = true;
                $dueDone     = ($status === 'returned');
                $returnDone  = ($status === 'returned');
            ?>

            <div class="tx-card <?php echo $cardClass; ?>">

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
                    <span class="role-pip"><?php echo htmlspecialchars($row['my_role']); ?></span>
                </div>

                <!-- Card body -->
                <div class="card-body">

                    <h3 class="card-title"><?php echo htmlspecialchars($row['item_name']); ?></h3>

                    <!-- Participants -->
                    <div class="participants">
                        <div class="participant-cell">
                            <div class="p-avatar"><?php echo $ownerInit; ?></div>
                            <div class="p-info">
                                <div class="p-role">Owner</div>
                                <div class="p-name"><?php echo htmlspecialchars($row['owner_name']); ?></div>
                            </div>
                        </div>
                        <div class="participant-cell">
                            <div class="p-avatar"><?php echo $borrowerInit; ?></div>
                            <div class="p-info">
                                <div class="p-role">Borrower</div>
                                <div class="p-name"><?php echo htmlspecialchars($row['borrower_name']); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Meta grid -->
                    <div class="meta-grid">
                        <div class="meta-cell">
                            <div class="ml">Condition</div>
                            <div class="mv"><?php echo htmlspecialchars($row['item_condition']); ?></div>
                        </div>
                        <div class="meta-cell">
                            <div class="ml">Borrow Date</div>
                            <div class="mv"><?php echo htmlspecialchars($row['borrow_date']); ?></div>
                        </div>
                        <div class="meta-cell">
                            <div class="ml">Due Date</div>
                            <div class="mv <?php echo $isOverdue ? 'overdue-text' : ''; ?>">
                                <?php echo htmlspecialchars($row['due_date']); ?>
                                <?php if ($isOverdue): ?> ⚠️<?php endif; ?>
                            </div>
                        </div>
                        <div class="meta-cell">
                            <div class="ml">Return Date</div>
                            <div class="mv <?php echo empty($row['return_date']) ? 'muted' : ''; ?>">
                                <?php echo !empty($row['return_date'])
                                    ? htmlspecialchars($row['return_date'])
                                    : 'Not returned yet'; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Timeline bar -->
                    <div class="timeline-bar">
                        <div class="tl-label">📅 Transaction Timeline</div>
                        <div class="tl-track">
                            <!-- Borrow node -->
                            <div class="tl-node">
                                <div class="tl-dot filled"></div>
                                <div class="tl-node-lbl">Borrowed</div>
                                <div class="tl-node-date"><?php echo htmlspecialchars($row['borrow_date']); ?></div>
                            </div>

                            <div class="tl-line done"></div>

                            <!-- Due node -->
                            <div class="tl-node">
                                <div class="tl-dot <?php echo $isOverdue ? 'amber' : ($returnDone ? 'green' : 'filled'); ?>"></div>
                                <div class="tl-node-lbl">Due</div>
                                <div class="tl-node-date"><?php echo htmlspecialchars($row['due_date']); ?></div>
                            </div>

                            <div class="tl-line <?php echo $returnDone ? 'done' : ''; ?>"></div>

                            <!-- Return node -->
                            <div class="tl-node">
                                <div class="tl-dot <?php echo $returnDone ? 'green' : 'empty'; ?>"></div>
                                <div class="tl-node-lbl">Returned</div>
                                <div class="tl-node-date">
                                    <?php echo !empty($row['return_date']) ? htmlspecialchars($row['return_date']) : '—'; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Request message -->
                    <?php if (!empty($row['request_message'])): ?>
                    <div class="msg-box">
                        <div class="msg-label">💬 Request Message</div>
                        <p><?php echo nl2br(htmlspecialchars($row['request_message'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <!-- Footer -->
                    <div class="card-footer">
                        <span class="footer-ids">
                            Tx #<?php echo (int)$row['transaction_id']; ?>
                            &nbsp;·&nbsp;
                            Req #<?php echo (int)$row['request_id']; ?>
                        </span>

                        <?php if ($isActive || $isOverdue): ?>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="transaction_id" value="<?php echo (int)$row['transaction_id']; ?>">
                            <button type="submit" name="action" value="mark_returned" class="btn-return">
                                ✅ Mark as Returned
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="returned-note">✅ Returned</span>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

            <?php endforeach; ?>
        </div>

    <?php else: ?>

        <div class="empty-box">
            <span class="e-icon">🔄</span>
            <h3>No transactions yet</h3>
            <p>You don't have any active or completed borrowing transactions yet. Browse items and send a request to get started.</p>
        </div>

    <?php endif; ?>

</div><!-- /.page -->

</body>
</html>