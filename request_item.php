<?php
session_start();
include 'includes/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];

if (!isset($_GET['item_id']) || !is_numeric($_GET['item_id'])) {
    header("Location: browse_items.php");
    exit();
}

$item_id = (int) $_GET['item_id'];
$error   = "";
$success = "";

// Get item info
$stmt = $conn->prepare("
    SELECT i.item_name, i.item_description, i.item_condition, i.owner_id,
           i.availability_status,
           s.full_name AS owner_name,
           c.category_name,
           img.image_path
    FROM item i
    JOIN student s ON i.owner_id = s.student_id
    JOIN category c ON i.category_id = c.category_id
    LEFT JOIN item_image img
        ON i.item_id = img.item_id AND img.is_primary = TRUE
    WHERE i.item_id = ?
");
$stmt->bind_param("i", $item_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: browse_items.php");
    exit();
}

$item = $result->fetch_assoc();
$stmt->close();

// Check if user is the owner — block early
$is_own_item = ($student_id === (int)$item['owner_id']);

// Check if item is currently available
$is_unavailable = ($item['availability_status'] !== 'available');

// Check if this borrower already has a pending request for this item
$dup_stmt = $conn->prepare("
    SELECT request_id
    FROM borrow_request
    WHERE item_id    = ?
      AND borrower_id = ?
      AND request_status = 'pending'
    LIMIT 1
");
$dup_stmt->bind_param("ii", $item_id, $student_id);
$dup_stmt->execute();
$dup_stmt->store_result();
$has_pending = ($dup_stmt->num_rows > 0);
$dup_stmt->close();

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && !$is_own_item && !$is_unavailable && !$has_pending) {

    $from_date = $_POST['from_date'] ?? '';
    $to_date   = $_POST['to_date']   ?? '';
    $message   = trim($_POST['message'] ?? '');
    $today     = date("Y-m-d");

    if (empty($from_date) || empty($to_date)) {
        $error = "Please select both a start and end date.";
    } elseif ($from_date < $today) {
        $error = "Start date cannot be in the past.";
    } elseif ($to_date <= $from_date) {
        $error = "End date must be after the start date.";
    } else {
        $request_date = $today;
        $status       = "pending";

        $stmt2 = $conn->prepare("
            INSERT INTO borrow_request
            (item_id, borrower_id, request_date, requested_from_date, requested_to_date, request_message, request_status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt2->bind_param(
            "iisssss",
            $item_id,
            $student_id,
            $request_date,
            $from_date,
            $to_date,
            $message,
            $status
        );

        if ($stmt2->execute()) {
            $success = "Your borrow request has been sent! The owner will review it shortly.";
        } else {
            $error = "Something went wrong. Please try again.";
        }

        $stmt2->close();
    }
}

// Condition helpers
$condRaw   = $item['item_condition'] ?? 'good';
$condLabel = ucwords(str_replace('_', ' ', $condRaw));
$condEmoji = match(strtolower($condRaw)) {
    'new'      => '✨',
    'like_new' => '🌟',
    'good'     => '👍',
    'fair'     => '🙂',
    'poor'     => '⚠️',
    default    => '•',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Item | Campus Resource Sharing</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --navy:         #0a0f2c;
            --accent:       #4f6ef7;
            --accent-dark:  #3b5bdb;
            --accent-soft:  #eef1ff;
            --gold:         #f5c842;
            --surface:      #ffffff;
            --bg:           #f4f6fd;
            --border:       #e4e8f8;
            --border-focus: #4f6ef7;
            --text-h:       #0d1535;
            --text-body:    #4a5380;
            --text-muted:   #8b93c4;
            --shadow-sm:    0 2px 12px rgba(79,110,247,0.07);
            --shadow-md:    0 10px 32px rgba(79,110,247,0.13);
            --shadow-lg:    0 18px 52px rgba(79,110,247,0.17);
            --radius:       14px;

            --success-bg:   #f0fdf4; --success-bdr: #86efac; --success-t: #166534;
            --error-bg:     #fff1f2; --error-bdr:   #fda4af; --error-t:   #9f1239;
            --warn-bg:      #fffbea; --warn-bdr:    #fde68a; --warn-t:    #92400e;

            /* condition colours */
            --c-new:       #dcfce7; --c-new-t:      #166534; --c-new-b:      #86efac;
            --c-like-new:  #dbeafe; --c-like-new-t: #1e40af; --c-like-new-b: #93c5fd;
            --c-good:      #ede9fe; --c-good-t:     #5b21b6; --c-good-b:     #c4b5fd;
            --c-fair:      #fef9c3; --c-fair-t:     #854d0e; --c-fair-b:     #fde047;
            --c-poor:      #fee2e2; --c-poor-t:     #991b1b; --c-poor-b:     #fca5a5;
        }

        body {
            background: var(--bg);
            font-family: 'DM Sans', sans-serif;
            color: var(--text-body);
            min-height: 100vh;
        }

        /* ─────────────────────────────
           NAVBAR
        ───────────────────────────── */
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
            z-index: 200;
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
            color: var(--navy); letter-spacing: -0.3px;
        }
        .nav-actions { display: flex; align-items: center; gap: 10px; }
        .nav-btn {
            display: inline-flex; align-items: center; gap: 7px;
            text-decoration: none; color: var(--text-muted);
            font-size: 13.5px; font-weight: 500;
            padding: 8px 14px; border-radius: 10px;
            border: 1px solid var(--border); background: var(--surface);
            transition: color .2s, border-color .2s, box-shadow .2s, transform .2s;
        }
        .nav-btn:hover {
            color: var(--accent); border-color: rgba(79,110,247,.35);
            box-shadow: 0 4px 14px rgba(79,110,247,.1); transform: translateY(-1px);
        }
        .nav-btn .chevron { transition: transform .2s; }
        .nav-btn:hover .chevron { transform: translateX(-3px); }

        /* ─────────────────────────────
           PAGE LAYOUT
        ───────────────────────────── */
        .page {
            max-width: 960px;
            margin: 0 auto;
            padding: 38px 24px 72px;
        }

        /* ─────────────────────────────
           PAGE HEADER
        ───────────────────────────── */
        .page-header {
            margin-bottom: 28px;
            animation: fadeUp .5s cubic-bezier(.16,1,.3,1) both;
        }
        .page-header .eyebrow {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 11px; font-weight: 600;
            letter-spacing: 1.8px; text-transform: uppercase;
            color: var(--accent); margin-bottom: 8px;
        }
        .page-header .eyebrow::before {
            content: ''; width: 16px; height: 2px;
            background: var(--accent); border-radius: 2px;
        }
        .page-header h1 {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: 28px;
            color: var(--text-h); letter-spacing: -.5px; line-height: 1.2;
        }
        .page-header p {
            margin-top: 6px; font-size: 14px; color: var(--text-muted);
        }

        /* ─────────────────────────────
           ALERTS
        ───────────────────────────── */
        .alert {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 18px; border-radius: var(--radius);
            margin-bottom: 24px; font-size: 13.5px;
            font-weight: 500; border: 1px solid;
            animation: slideDown .4s cubic-bezier(.16,1,.3,1) both;
        }
        .alert-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
        .alert-body strong {
            display: block; font-weight: 700;
            font-family: 'Syne', sans-serif; font-size: 13px;
            letter-spacing: .1px; margin-bottom: 2px;
        }
        .alert.success { background: var(--success-bg); border-color: var(--success-bdr); color: var(--success-t); }
        .alert.error   { background: var(--error-bg);   border-color: var(--error-bdr);   color: var(--error-t); }
        .alert.warning { background: var(--warn-bg);    border-color: var(--warn-bdr);    color: var(--warn-t); }

        /* ─────────────────────────────
           MAIN CARD (two-column)
        ───────────────────────────── */
        .main-card {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 26px;
            overflow: hidden;
            box-shadow: var(--shadow-md);
            animation: fadeUp .55s cubic-bezier(.16,1,.3,1) .05s both;
        }

        /* ─── Left: item preview ─── */
        .item-preview {
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--border);
        }

        .item-image-wrap {
            position: relative;
            width: 100%;
            aspect-ratio: 4 / 3;
            overflow: hidden;
            background: linear-gradient(135deg, #c7d2fe 0%, #a5b4fc 50%, #818cf8 100%);
            flex-shrink: 0;
        }
        .item-image-wrap img {
            width: 100%; height: 100%;
            object-fit: cover; display: block;
            transition: transform .4s ease;
        }
        .item-image-wrap:hover img { transform: scale(1.03); }
        .img-placeholder {
            width: 100%; height: 100%;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 10px;
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 50%, #a5b4fc 100%);
        }
        .img-placeholder .ph-icon  { font-size: 48px; opacity: .5; }
        .img-placeholder .ph-label {
            font-size: 11px; font-weight: 600;
            letter-spacing: 1px; text-transform: uppercase;
            color: #6366f1; opacity: .7;
        }

        /* condition badge on image */
        .condition-badge {
            position: absolute; top: 12px; right: 12px;
            font-size: 11px; font-weight: 700;
            letter-spacing: .5px; text-transform: capitalize;
            padding: 4px 11px; border-radius: 999px;
            border: 1px solid transparent;
            backdrop-filter: blur(8px);
        }
        .cond-new       { background: var(--c-new);      color: var(--c-new-t);      border-color: var(--c-new-b); }
        .cond-like_new  { background: var(--c-like-new); color: var(--c-like-new-t); border-color: var(--c-like-new-b); }
        .cond-good      { background: var(--c-good);     color: var(--c-good-t);     border-color: var(--c-good-b); }
        .cond-fair      { background: var(--c-fair);     color: var(--c-fair-t);     border-color: var(--c-fair-b); }
        .cond-poor      { background: var(--c-poor);     color: var(--c-poor-t);     border-color: var(--c-poor-b); }

        .item-details {
            padding: 24px 26px 28px;
            display: flex; flex-direction: column; flex: 1;
        }

        .item-category {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11px; font-weight: 600;
            letter-spacing: .7px; text-transform: uppercase;
            color: var(--text-muted); margin-bottom: 8px;
        }
        .item-category::before {
            content: ''; width: 5px; height: 5px;
            background: var(--accent); border-radius: 50%;
        }

        .item-name {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: 20px;
            color: var(--text-h); letter-spacing: -.3px;
            line-height: 1.25; margin-bottom: 16px;
        }

        .owner-row {
            display: flex; align-items: center; gap: 10px;
            background: var(--accent-soft);
            border: 1px solid var(--border);
            border-radius: 12px; padding: 11px 14px;
            margin-bottom: 14px;
        }
        .owner-avatar {
            width: 34px; height: 34px; border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: 14px; color: #fff;
            flex-shrink: 0;
        }
        .owner-info .owner-label {
            font-size: 10.5px; font-weight: 600;
            letter-spacing: .7px; text-transform: uppercase;
            color: var(--text-muted); margin-bottom: 2px;
        }
        .owner-info .owner-name {
            font-size: 13.5px; font-weight: 600; color: var(--text-h);
        }

        .item-desc {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 12px; padding: 12px 14px;
            font-size: 13.5px; color: var(--text-body);
            line-height: 1.65; flex: 1;
        }
        .item-desc .desc-label {
            font-size: 10.5px; font-weight: 600;
            letter-spacing: .7px; text-transform: uppercase;
            color: var(--text-muted); margin-bottom: 6px;
        }

        /* ─── Right: request form ─── */
        .request-form-wrap {
            padding: 32px 32px 36px;
            display: flex; flex-direction: column;
        }

        .form-heading {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: 20px;
            color: var(--text-h); letter-spacing: -.3px;
            margin-bottom: 6px;
        }
        .form-subheading {
            font-size: 13.5px; color: var(--text-muted);
            margin-bottom: 26px; line-height: 1.6;
        }

        /* Section divider */
        .form-section-label {
            display: flex; align-items: center; gap: 10px;
            font-family: 'Syne', sans-serif;
            font-weight: 700; font-size: 11.5px;
            letter-spacing: 1.2px; text-transform: uppercase;
            color: var(--text-muted); margin-bottom: 16px;
        }
        .form-section-label::after {
            content: ''; flex: 1; height: 1px; background: var(--border);
        }

        .field { display: flex; flex-direction: column; gap: 7px; margin-bottom: 18px; }
        .field:last-of-type { margin-bottom: 0; }

        label {
            font-size: 12px; font-weight: 600;
            letter-spacing: .7px; text-transform: uppercase;
            color: var(--text-muted);
            display: flex; align-items: center; gap: 5px;
        }
        label .req { color: var(--accent); font-size: 14px; line-height: 1; }

        .date-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
        }

        input[type="date"],
        textarea {
            width: 100%;
            background: var(--bg);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            padding: 12px 16px;
            font-family: 'DM Sans', sans-serif;
            font-size: 14px; color: var(--text-h);
            outline: none;
            transition: border-color .22s, box-shadow .22s, background .22s;
            appearance: none; -webkit-appearance: none;
        }
        input[type="date"]:focus,
        textarea:focus {
            border-color: var(--border-focus);
            background: var(--surface);
            box-shadow: 0 0 0 3.5px rgba(79,110,247,.13);
        }
        textarea {
            resize: vertical; min-height: 100px; line-height: 1.65;
        }

        .field-hint {
            font-size: 11.5px; color: var(--text-muted); margin-top: -2px;
        }

        /* Info tip box */
        .info-tip {
            display: flex; align-items: flex-start; gap: 10px;
            background: var(--accent-soft);
            border: 1px solid var(--border);
            border-radius: 12px; padding: 12px 14px;
            font-size: 12.5px; color: var(--accent-dark);
            line-height: 1.6; margin-bottom: 22px;
        }
        .info-tip .tip-icon { font-size: 14px; flex-shrink: 0; margin-top: 1px; }

        .form-spacer { flex: 1; }

        /* Divider */
        .divider { height: 1px; background: var(--border); margin: 24px 0; }

        /* Submit button */
        .btn-submit {
            width: 100%; padding: 14px;
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%);
            color: #fff; border: none; border-radius: var(--radius);
            font-family: 'Syne', sans-serif;
            font-size: 15px; font-weight: 700; letter-spacing: .4px;
            cursor: pointer; position: relative; overflow: hidden;
            transition: transform .22s cubic-bezier(.34,1.56,.64,1), box-shadow .22s;
        }
        .btn-submit::before {
            content: ''; position: absolute; inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,.14), transparent);
            opacity: 0; transition: opacity .22s;
        }
        .btn-submit:hover {
            transform: scale(1.025) translateY(-2px);
            box-shadow: 0 14px 36px rgba(79,110,247,.38);
        }
        .btn-submit:hover::before { opacity: 1; }
        .btn-submit:active { transform: scale(.99); }
        .btn-submit:disabled {
            opacity: .55; cursor: not-allowed;
            transform: none; box-shadow: none;
        }

        /* Success state — hide form, show big tick */
        .success-state {
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            text-align: center; padding: 32px 16px;
            flex: 1;
        }
        .success-ring {
            width: 80px; height: 80px; border-radius: 50%;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            display: flex; align-items: center; justify-content: center;
            font-size: 36px; margin-bottom: 22px;
            box-shadow: 0 0 0 8px rgba(34,197,94,.12), 0 0 28px rgba(34,197,94,.25);
            animation: popIn .5s cubic-bezier(.34,1.56,.64,1) both;
        }
        .success-state h3 {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: 20px;
            color: var(--text-h); margin-bottom: 10px;
        }
        .success-state p {
            font-size: 14px; color: var(--text-muted);
            max-width: 280px; line-height: 1.65; margin-bottom: 28px;
        }
        .btn-browse {
            display: inline-flex; align-items: center; gap: 7px;
            text-decoration: none; padding: 12px 24px;
            background: var(--navy); color: #eef0ff;
            border-radius: var(--radius);
            font-family: 'Syne', sans-serif;
            font-size: 13.5px; font-weight: 700;
            transition: background .2s, transform .2s, box-shadow .2s;
        }
        .btn-browse:hover {
            background: var(--accent-dark); transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(79,110,247,.28);
        }

        /* ─────────────────────────────
           ANIMATIONS
        ───────────────────────────── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes popIn {
            from { opacity: 0; transform: scale(.5); }
            to   { opacity: 1; transform: scale(1); }
        }

        /* ─────────────────────────────
           RESPONSIVE
        ───────────────────────────── */
        @media (max-width: 820px) {
            .main-card {
                grid-template-columns: 1fr;
            }
            .item-preview {
                border-right: none;
                border-bottom: 1px solid var(--border);
            }
            .item-image-wrap { aspect-ratio: 16 / 7; }
        }
        @media (max-width: 600px) {
            .navbar { padding: 0 18px; }
            .page   { padding: 22px 14px 52px; }
            .request-form-wrap { padding: 24px 20px 28px; }
            .item-details { padding: 20px 20px 24px; }
            .date-grid { grid-template-columns: 1fr; }
            .page-header h1 { font-size: 22px; }
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
        <a href="browse_items.php" class="nav-btn">
            <span class="chevron">←</span> Back to Browse
        </a>
    </div>
</nav>

<!-- ════════════ PAGE ════════════ -->
<div class="page">

    <!-- Page header -->
    <div class="page-header">
        <p class="eyebrow">Borrowing</p>
        <h1>Request an Item</h1>
        <p>Fill in your preferred dates and an optional message to the owner.</p>
    </div>

    <!-- Alerts (non-success, non-own-item) -->
    <?php if (!empty($error)): ?>
    <div class="alert error">
        <span class="alert-icon">⚠️</span>
        <div class="alert-body">
            <strong>Something went wrong</strong>
            <?php echo htmlspecialchars($error); ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($is_own_item): ?>
    <div class="alert warning">
        <span class="alert-icon">🚫</span>
        <div class="alert-body">
            <strong>This is your own item</strong>
            You cannot send a borrow request for an item you listed yourself.
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$is_own_item && $is_unavailable): ?>
    <div class="alert warning">
        <span class="alert-icon">🔒</span>
        <div class="alert-body">
            <strong>Item not available</strong>
            This item is currently <?php echo htmlspecialchars($item['availability_status']); ?> and cannot be requested right now.
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$is_own_item && !$is_unavailable && $has_pending): ?>
    <div class="alert warning">
        <span class="alert-icon">⏳</span>
        <div class="alert-body">
            <strong>Request already pending</strong>
            You already have a pending request for this item. You can track it in
            <a href="my_requests.php" style="color:inherit;font-weight:700;">My Requests</a>.
        </div>
    </div>
    <?php endif; ?>

    <!-- Main two-column card -->
    <div class="main-card">

        <!-- ── Left: item preview ── -->
        <div class="item-preview">

            <div class="item-image-wrap">
                <?php if (!empty($item['image_path'])): ?>
                    <img
                        src="<?php echo htmlspecialchars($item['image_path']); ?>"
                        alt="<?php echo htmlspecialchars($item['item_name']); ?>"
                    >
                <?php else: ?>
                    <div class="img-placeholder">
                        <span class="ph-icon">📦</span>
                        <span class="ph-label">No image</span>
                    </div>
                <?php endif; ?>

                <?php
                    $condClass = 'cond-' . strtolower(str_replace(' ', '_', $condRaw));
                ?>
                <span class="condition-badge <?php echo $condClass; ?>">
                    <?php echo $condEmoji . ' ' . $condLabel; ?>
                </span>
            </div>

            <div class="item-details">

                <span class="item-category">
                    <?php echo htmlspecialchars($item['category_name']); ?>
                </span>

                <h2 class="item-name">
                    <?php echo htmlspecialchars($item['item_name']); ?>
                </h2>

                <div class="owner-row">
                    <div class="owner-avatar">
                        <?php echo mb_strtoupper(mb_substr($item['owner_name'], 0, 1)); ?>
                    </div>
                    <div class="owner-info">
                        <div class="owner-label">Listed by</div>
                        <div class="owner-name"><?php echo htmlspecialchars($item['owner_name']); ?></div>
                    </div>
                </div>

                <?php if (!empty($item['item_description'])): ?>
                <div class="item-desc">
                    <div class="desc-label">Description</div>
                    <?php echo nl2br(htmlspecialchars($item['item_description'])); ?>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- ── Right: form or success ── -->
        <div class="request-form-wrap">

            <?php if (!empty($success)): ?>

                <!-- Success state -->
                <div class="success-state">
                    <div class="success-ring">✓</div>
                    <h3>Request Sent!</h3>
                    <p><?php echo htmlspecialchars($success); ?></p>
                    <a href="browse_items.php" class="btn-browse">← Browse More Items</a>
                </div>

            <?php elseif ($is_own_item): ?>

                <!-- Own-item block -->
                <div class="success-state">
                    <div class="success-ring" style="background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 0 0 8px rgba(245,158,11,.12);">🚫</div>
                    <h3>Can't Request Own Item</h3>
                    <p>You listed this item. Browse other available resources instead.</p>
                    <a href="browse_items.php" class="btn-browse">← Browse Items</a>
                </div>

            <?php elseif ($is_unavailable): ?>

                <!-- Item not available block -->
                <div class="success-state">
                    <div class="success-ring" style="background: linear-gradient(135deg, #64748b, #475569); box-shadow: 0 0 0 8px rgba(100,116,139,.12);">🔒</div>
                    <h3>Item Unavailable</h3>
                    <p>
                        This item is currently
                        <strong><?php echo ucfirst(htmlspecialchars($item['availability_status'])); ?></strong>
                        and cannot be requested right now. Check back later or browse other items.
                    </p>
                    <a href="browse_items.php" class="btn-browse">← Browse Items</a>
                </div>

            <?php elseif ($has_pending): ?>

                <!-- Duplicate pending request block -->
                <div class="success-state">
                    <div class="success-ring" style="background: linear-gradient(135deg, #f59e0b, #b45309); box-shadow: 0 0 0 8px rgba(245,158,11,.12);">⏳</div>
                    <h3>Already Requested</h3>
                    <p>You already have a pending request for this item. You can track or cancel it in My Requests.</p>
                    <a href="my_requests.php" class="btn-browse">View My Requests →</a>
                </div>

            <?php else: ?>

                <h2 class="form-heading">Send a Request</h2>
                <p class="form-subheading">
                    Select your borrow dates and optionally leave a message for the owner.
                    They'll approve or decline your request.
                </p>

                <div class="info-tip">
                    <span class="tip-icon">💡</span>
                    Approving one request auto-rejects all other pending requests for the same item — so act quickly!
                </div>

                <form method="POST">

                    <div class="form-section-label">Borrow Period</div>

                    <div class="date-grid">
                        <div class="field">
                            <label for="from_date">
                                From Date <span class="req">*</span>
                            </label>
                            <input
                                type="date"
                                id="from_date"
                                name="from_date"
                                min="<?php echo date('Y-m-d'); ?>"
                                value="<?php echo htmlspecialchars($_POST['from_date'] ?? ''); ?>"
                                required
                            >
                        </div>
                        <div class="field">
                            <label for="to_date">
                                To Date <span class="req">*</span>
                            </label>
                            <input
                                type="date"
                                id="to_date"
                                name="to_date"
                                min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                                value="<?php echo htmlspecialchars($_POST['to_date'] ?? ''); ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="divider"></div>

                    <div class="form-section-label">Message</div>

                    <div class="field">
                        <label for="message">Message to Owner</label>
                        <textarea
                            id="message"
                            name="message"
                            placeholder="Introduce yourself, explain why you need the item, or any other details that might help the owner decide…"
                        ><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                        <div class="field-hint">Optional — but a friendly note goes a long way.</div>
                    </div>

                    <div class="form-spacer"></div>
                    <div class="divider"></div>

                    <button type="submit" class="btn-submit">
                        Send Borrow Request →
                    </button>

                </form>

            <?php endif; ?>

        </div><!-- /.request-form-wrap -->

    </div><!-- /.main-card -->

</div><!-- /.page -->

<script>
    // Enforce: to_date must always be > from_date
    const fromInput = document.getElementById('from_date');
    const toInput   = document.getElementById('to_date');

    if (fromInput && toInput) {
        fromInput.addEventListener('change', function () {
            const next = new Date(this.value);
            next.setDate(next.getDate() + 1);
            const yyyy = next.getFullYear();
            const mm   = String(next.getMonth() + 1).padStart(2, '0');
            const dd   = String(next.getDate()).padStart(2, '0');
            toInput.min = `${yyyy}-${mm}-${dd}`;

            // Clear to_date if it's now invalid
            if (toInput.value && toInput.value <= this.value) {
                toInput.value = '';
            }
        });
    }
</script>

</body>
</html>