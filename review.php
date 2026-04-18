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

/* -------------------------------------------------
   HANDLE REVIEW SUBMISSION
------------------------------------------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
    $reviewee_id = isset($_POST['reviewee_id']) ? (int)$_POST['reviewee_id'] : 0;
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $comment = trim($_POST['comment'] ?? '');

    if ($transaction_id <= 0 || $reviewee_id <= 0 || $rating < 1 || $rating > 5) {
        $error = "Please provide valid review information.";
    } elseif ($reviewee_id === $student_id) {
        $error = "You cannot review yourself.";
    } else {
        $check_stmt = $conn->prepare("
            SELECT
                bt.transaction_id,
                bt.transaction_status,
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

            if ($tx['transaction_status'] !== 'returned') {
                $error = "You can only review after the item is returned.";
            } else {
                $borrower_id = (int)$tx['borrower_id'];
                $owner_id = (int)$tx['owner_id'];

                if ($student_id === $borrower_id) {
                    $expected_reviewee = $owner_id;
                } elseif ($student_id === $owner_id) {
                    $expected_reviewee = $borrower_id;
                } else {
                    $expected_reviewee = 0;
                }

                if ($reviewee_id !== $expected_reviewee) {
                    $error = "Invalid review target.";
                } else {
                    $dup_stmt = $conn->prepare("
                        SELECT review_id
                        FROM review
                        WHERE transaction_id = ? AND reviewer_id = ?
                    ");
                    $dup_stmt->bind_param("ii", $transaction_id, $student_id);
                    $dup_stmt->execute();
                    $dup_result = $dup_stmt->get_result();

                    if ($dup_result->num_rows > 0) {
                        $error = "You have already reviewed this transaction.";
                    } else {
                        $review_date = date("Y-m-d");

                        $insert_stmt = $conn->prepare("
                            INSERT INTO review
                            (transaction_id, reviewer_id, reviewee_id, rating, comment, review_date)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $insert_stmt->bind_param(
                            "iiiiss",
                            $transaction_id,
                            $student_id,
                            $reviewee_id,
                            $rating,
                            $comment,
                            $review_date
                        );

                        if ($insert_stmt->execute()) {
                            $success = "Review submitted successfully.";
                        } else {
                            $error = "Failed to submit review: " . $insert_stmt->error;
                        }

                        $insert_stmt->close();
                    }

                    $dup_stmt->close();
                }
            }
        }

        $check_stmt->close();
    }
}

/* -------------------------------------------------
   FETCH RETURNED TRANSACTIONS ELIGIBLE FOR REVIEW
------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT
        bt.transaction_id,
        bt.borrow_date,
        bt.due_date,
        bt.return_date,
        bt.transaction_status,
        br.request_id,
        br.borrower_id,
        i.owner_id,
        i.item_name,
        i.item_condition,
        borrower.full_name AS borrower_name,
        owner.full_name AS owner_name,
        img.image_path,

        CASE
            WHEN br.borrower_id = ? THEN owner.student_id
            ELSE borrower.student_id
        END AS reviewee_id,

        CASE
            WHEN br.borrower_id = ? THEN owner.full_name
            ELSE borrower.full_name
        END AS reviewee_name,

        CASE
            WHEN br.borrower_id = ? THEN 'Borrower'
            ELSE 'Owner'
        END AS my_role,

        r.review_id AS my_review_id,
        r.rating    AS my_rating,
        r.comment   AS my_comment,
        r.review_date AS my_review_date
    FROM borrow_transaction bt
    JOIN borrow_request br ON bt.request_id = br.request_id
    JOIN item i ON br.item_id = i.item_id
    JOIN student borrower ON br.borrower_id = borrower.student_id
    JOIN student owner ON i.owner_id = owner.student_id
    LEFT JOIN item_image img ON i.item_id = img.item_id AND img.is_primary = TRUE
    LEFT JOIN review r
        ON r.transaction_id = bt.transaction_id
       AND r.reviewer_id = ?
    WHERE bt.transaction_status = 'returned'
      AND (br.borrower_id = ? OR i.owner_id = ?)
    ORDER BY bt.transaction_id DESC
");
$stmt->bind_param(
    "iiiiii",
    $student_id,
    $student_id,
    $student_id,
    $student_id,
    $student_id,
    $student_id
);
$stmt->execute();
$result = $stmt->get_result();

/* Split into pending / completed in PHP */
$pending_rows   = [];
$completed_rows = [];
while ($row = $result->fetch_assoc()) {
    if (empty($row['my_review_id'])) {
        $pending_rows[]   = $row;
    } else {
        $completed_rows[] = $row;
    }
}

/* -------------------------------------------------
   FETCH REVIEWS RECEIVED BY LOGGED-IN USER
------------------------------------------------- */
$received_stmt = $conn->prepare("
    SELECT
        r.review_id,
        r.transaction_id,
        r.rating,
        r.comment,
        r.review_date,
        reviewer.full_name AS reviewer_name,
        i.item_name
    FROM review r
    JOIN student reviewer ON r.reviewer_id = reviewer.student_id
    JOIN borrow_transaction bt ON r.transaction_id = bt.transaction_id
    JOIN borrow_request br ON bt.request_id = br.request_id
    JOIN item i ON br.item_id = i.item_id
    WHERE r.reviewee_id = ?
    ORDER BY r.review_date DESC, r.review_id DESC
");
$received_stmt->bind_param("i", $student_id);
$received_stmt->execute();
$received_reviews = $received_stmt->get_result();

/* -------------------------------------------------
   FETCH REVIEWS WRITTEN BY LOGGED-IN USER
------------------------------------------------- */
$given_stmt = $conn->prepare("
    SELECT
        r.review_id,
        r.transaction_id,
        r.rating,
        r.comment,
        r.review_date,
        reviewee.full_name AS reviewee_name,
        i.item_name
    FROM review r
    JOIN student reviewee ON r.reviewee_id = reviewee.student_id
    JOIN borrow_transaction bt ON r.transaction_id = bt.transaction_id
    JOIN borrow_request br ON bt.request_id = br.request_id
    JOIN item i ON br.item_id = i.item_id
    WHERE r.reviewer_id = ?
    ORDER BY r.review_date DESC, r.review_id DESC
");
$given_stmt->bind_param("i", $student_id);
$given_stmt->execute();
$given_reviews = $given_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review System | Campus Resource Sharing</title>
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

            /* section accent colours */
            --pending-bg:   #fffbea;
            --pending-bdr:  #fde68a;
            --pending-text: #92400e;
            --done-bg:      #f0fdf4;
            --done-bdr:     #86efac;
            --done-text:    #166534;

            --error-bg:     #fff1f2;
            --error-bdr:    #fda4af;
            --error-text:   #9f1239;

            --star-fill:    #f59e0b;
            --star-empty:   #d1d5db;
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
            color: var(--navy); letter-spacing: -.3px;
        }
        .nav-actions { display: flex; align-items: center; gap: 10px; }
        .nav-btn {
            display: inline-flex; align-items: center; gap: 6px;
            text-decoration: none;
            font-size: 13.5px; font-weight: 500;
            padding: 8px 14px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text-muted);
            transition: color .2s, border-color .2s, box-shadow .2s, transform .2s;
        }
        .nav-btn:hover {
            color: var(--accent);
            border-color: rgba(79,110,247,.35);
            box-shadow: 0 4px 14px rgba(79,110,247,.1);
            transform: translateY(-1px);
        }
        .nav-btn.primary {
            background: var(--navy); color: #eef0ff; border-color: var(--navy);
            font-family: 'Syne', sans-serif; font-weight: 700;
        }
        .nav-btn.primary:hover { background: var(--accent-dark); border-color: var(--accent-dark); color: #fff; }

        /* ─── Page ─── */
        .page {
            max-width: 1240px;
            margin: 0 auto;
            padding: 36px 24px 80px;
        }

        /* ─── Page header ─── */
        .page-header {
            position: relative; overflow: hidden;
            background: linear-gradient(130deg, var(--navy) 0%, #1a2560 55%, #0d1f4e 100%);
            border-radius: 24px;
            padding: 40px 48px;
            margin-bottom: 32px;
            box-shadow: 0 16px 48px rgba(79,110,247,0.17);
            animation: fadeUp .5s cubic-bezier(.16,1,.3,1) both;
        }
        .page-header::before {
            content: '';
            position: absolute; inset: 0;
            background-image:
                linear-gradient(rgba(79,110,247,.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(79,110,247,.08) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
        }
        .page-header::after {
            content: '';
            position: absolute;
            width: 340px; height: 340px;
            background: radial-gradient(circle, rgba(79,110,247,.32) 0%, transparent 70%);
            border-radius: 50%;
            top: -100px; right: -60px;
            filter: blur(60px);
            pointer-events: none;
        }
        .header-inner {
            position: relative; z-index: 1;
            display: flex; align-items: center;
            justify-content: space-between; gap: 24px; flex-wrap: wrap;
        }
        .header-text .eyebrow {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 11px; font-weight: 600;
            letter-spacing: 1.8px; text-transform: uppercase;
            color: var(--gold); margin-bottom: 10px;
        }
        .header-text .eyebrow::before {
            content: ''; width: 16px; height: 2px;
            background: var(--gold); border-radius: 2px;
        }
        .header-text h1 {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: clamp(24px,3vw,36px);
            color: #eef0ff; letter-spacing: -.5px; line-height: 1.18;
            margin-bottom: 10px;
        }
        .header-text p {
            font-size: 14px; color: #8b93c4;
            line-height: 1.72; max-width: 560px;
        }
        .header-stats {
            display: flex; gap: 14px; flex-shrink: 0; flex-wrap: wrap;
        }
        .hstat {
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 16px;
            padding: 16px 20px;
            text-align: center;
            min-width: 90px;
            backdrop-filter: blur(8px);
        }
        .hstat .num {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: 26px;
            color: #eef0ff; line-height: 1;
            margin-bottom: 5px;
        }
        .hstat .num span { color: var(--gold); }
        .hstat .lbl {
            font-size: 11px; letter-spacing: 1px;
            text-transform: uppercase; color: #8b93c4;
        }

        /* ─── Alert banners ─── */
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
            font-family: 'Syne', sans-serif;
            font-size: 13px; margin-bottom: 2px;
        }
        .alert.success { background: var(--done-bg); border-color: var(--done-bdr); color: var(--done-text); }
        .alert.error   { background: var(--error-bg); border-color: var(--error-bdr); color: var(--error-text); }

        /* ─── Section block ─── */
        .section-block { margin-bottom: 48px; animation: fadeUp .5s cubic-bezier(.16,1,.3,1) both; }
        .section-block:nth-child(2) { animation-delay: .06s; }
        .section-block:nth-child(3) { animation-delay: .10s; }
        .section-block:nth-child(4) { animation-delay: .14s; }
        .section-block:nth-child(5) { animation-delay: .18s; }

        .section-heading {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 22px;
        }
        .section-heading .s-icon {
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; flex-shrink: 0;
        }
        .s-icon.pending { background: var(--pending-bg); border: 1px solid var(--pending-bdr); }
        .s-icon.done    { background: var(--done-bg);    border: 1px solid var(--done-bdr); }
        .s-icon.inbox   { background: var(--accent-soft);border: 1px solid var(--border); }
        .s-icon.outbox  { background: var(--gold-soft);  border: 1px solid #fde68a; }

        .section-heading h2 {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: 20px;
            color: var(--text-h); letter-spacing: -.3px;
        }
        .section-heading .count-pill {
            font-size: 11.5px; font-weight: 700;
            padding: 4px 11px; border-radius: 999px; border: 1px solid;
        }
        .count-pill.pending { background: var(--pending-bg); border-color: var(--pending-bdr); color: var(--pending-text); }
        .count-pill.done    { background: var(--done-bg);    border-color: var(--done-bdr);    color: var(--done-text); }
        .count-pill.neutral { background: var(--accent-soft);border-color: var(--border);      color: var(--accent-dark); }

        .divider-line {
            flex: 1; height: 1px; background: var(--border);
        }

        /* ─── Two-column grid ─── */
        .two-col {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(440px, 1fr));
            gap: 22px;
        }

        /* ─── Transaction card (pending / completed) ─── */
        .tx-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 22px; overflow: hidden;
            box-shadow: var(--shadow-sm);
            display: flex; flex-direction: column;
            transition: transform .25s cubic-bezier(.34,1.56,.64,1), box-shadow .25s, border-color .25s;
        }
        .tx-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
            border-color: rgba(79,110,247,.2);
        }

        /* image strip */
        .tx-img {
            position: relative; height: 170px; overflow: hidden;
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 50%, #a5b4fc 100%);
            flex-shrink: 0;
        }
        .tx-img img {
            width: 100%; height: 100%; object-fit: cover;
            display: block; transition: transform .4s ease;
        }
        .tx-card:hover .tx-img img { transform: scale(1.04); }
        .tx-img-placeholder {
            width: 100%; height: 100%;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 6px;
        }
        .tx-img-placeholder .ph-icon { font-size: 36px; opacity: .5; }
        .tx-img-placeholder .ph-lbl  {
            font-size: 10px; font-weight: 600;
            letter-spacing: 1px; text-transform: uppercase;
            color: #6366f1; opacity: .65;
        }

        /* status ribbon */
        .ribbon {
            position: absolute; top: 12px; left: 12px;
            font-size: 11px; font-weight: 700;
            padding: 4px 11px; border-radius: 999px;
            border: 1px solid transparent;
            backdrop-filter: blur(8px);
        }
        .ribbon.pending { background: var(--pending-bg); border-color: var(--pending-bdr); color: var(--pending-text); }
        .ribbon.done    { background: var(--done-bg);    border-color: var(--done-bdr);    color: var(--done-text); }

        /* role badge */
        .role-pip {
            position: absolute; top: 12px; right: 12px;
            font-size: 10.5px; font-weight: 700;
            padding: 3px 9px; border-radius: 999px;
            background: var(--navy); color: #eef0ff;
            letter-spacing: .4px;
        }

        /* card body */
        .tx-body { padding: 20px 22px 22px; display: flex; flex-direction: column; flex: 1; }

        .tx-category {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 10.5px; font-weight: 600; letter-spacing: .7px;
            text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px;
        }
        .tx-category::before {
            content: ''; width: 5px; height: 5px;
            background: var(--accent); border-radius: 50%;
        }
        .tx-title {
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: 17px; color: var(--text-h);
            letter-spacing: -.2px; line-height: 1.3;
            margin-bottom: 14px;
        }

        /* meta mini grid */
        .meta-mini {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 8px; margin-bottom: 18px;
        }
        .meta-cell {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 11px; padding: 10px 12px;
        }
        .meta-cell .ml { font-size: 10.5px; color: var(--text-muted); margin-bottom: 3px; font-weight: 600; letter-spacing: .5px; text-transform: uppercase; }
        .meta-cell .mv { font-size: 13.5px; color: var(--text-h); font-weight: 600; line-height: 1.3; }

        /* ── Review form (pending) ── */
        .review-form-wrap {
            background: var(--pending-bg);
            border: 1px solid var(--pending-bdr);
            border-radius: 16px; padding: 18px 20px;
            margin-top: auto;
        }
        .review-form-wrap .rfw-title {
            font-family: 'Syne', sans-serif; font-weight: 700;
            font-size: 14.5px; color: var(--text-h); margin-bottom: 14px;
            display: flex; align-items: center; gap: 8px;
        }
        .rfw-title .at-tag {
            font-size: 12px; font-weight: 600;
            color: var(--accent); background: var(--accent-soft);
            border: 1px solid var(--border); border-radius: 999px;
            padding: 2px 9px;
        }

        /* star rating */
        .star-picker {
            display: flex; flex-direction: row-reverse;
            justify-content: flex-end; gap: 4px;
            margin-bottom: 12px;
        }
        .star-picker input { display: none; }
        .star-picker label {
            font-size: 26px; cursor: pointer;
            color: var(--star-empty);
            transition: color .15s, transform .15s;
            line-height: 1;
        }
        .star-picker label:hover,
        .star-picker label:hover ~ label,
        .star-picker input:checked ~ label {
            color: var(--star-fill);
            transform: scale(1.15);
        }

        .review-form-wrap textarea {
            width: 100%;
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: 12px;
            padding: 11px 14px;
            font-family: 'DM Sans', sans-serif; font-size: 13.5px;
            color: var(--text-h); outline: none; resize: vertical;
            min-height: 80px; line-height: 1.6; margin-bottom: 12px;
            transition: border-color .22s, box-shadow .22s;
        }
        .review-form-wrap textarea::placeholder { color: var(--text-muted); }
        .review-form-wrap textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(79,110,247,.12);
        }

        .form-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

        /* hidden fallback select for rating (keep PHP compat) */
        .rating-fallback { display: none !important; }

        .btn-submit {
            flex: 1 1 auto;
            padding: 11px 18px;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: #fff; border: none; border-radius: 11px;
            font-family: 'Syne', sans-serif; font-size: 13px; font-weight: 700;
            cursor: pointer; letter-spacing: .3px;
            transition: transform .2s, box-shadow .2s;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(79,110,247,.32);
        }
        .btn-submit:active { transform: translateY(0); }

        .tx-footer-note {
            font-size: 11.5px; color: var(--text-muted);
            margin-top: 10px; letter-spacing: .2px;
        }

        /* ── Completed card review summary ── */
        .review-done-wrap {
            background: var(--done-bg);
            border: 1px solid var(--done-bdr);
            border-radius: 16px; padding: 16px 20px;
            margin-top: auto;
        }
        .review-done-wrap .rdw-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 10px;
        }
        .rdw-badge {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 700;
            color: var(--done-text);
        }
        .star-display {
            display: flex; gap: 2px; font-size: 18px; letter-spacing: 1px;
        }
        .star-display .s-fill  { color: var(--star-fill); }
        .star-display .s-empty { color: var(--star-empty); }
        .review-done-comment {
            background: var(--surface);
            border-left: 3px solid var(--accent);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 13px; color: var(--text-body); line-height: 1.6;
            margin-top: 8px;
        }
        .no-comment { color: var(--text-muted); font-style: italic; }

        /* ─── History review card ─── */
        .hist-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 18px;
        }
        .hist-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px; padding: 22px 24px;
            box-shadow: var(--shadow-sm);
            transition: transform .22s, box-shadow .22s, border-color .22s;
        }
        .hist-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            border-color: rgba(79,110,247,.2);
        }
        .hist-top {
            display: flex; align-items: flex-start;
            justify-content: space-between; gap: 12px; margin-bottom: 12px;
        }
        .hist-item {
            font-family: 'Syne', sans-serif; font-weight: 700;
            font-size: 15px; color: var(--text-h);
            letter-spacing: -.2px; line-height: 1.3;
        }
        .hist-date {
            font-size: 11.5px; color: var(--text-muted);
            white-space: nowrap; padding-top: 2px;
        }
        .hist-meta {
            font-size: 12.5px; color: var(--text-muted);
            margin-bottom: 10px;
        }
        .hist-meta strong { color: var(--text-body); font-weight: 600; }
        .hist-stars { display: flex; gap: 2px; font-size: 20px; margin-bottom: 12px; }
        .hist-comment {
            background: var(--bg);
            border-left: 3px solid var(--accent);
            border-radius: 10px;
            padding: 11px 14px;
            font-size: 13px; color: var(--text-body); line-height: 1.62;
        }

        /* ─── Empty state ─── */
        .empty-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 48px 28px;
            text-align: center;
            box-shadow: var(--shadow-sm);
        }
        .empty-box .e-icon { font-size: 44px; opacity: .45; margin-bottom: 14px; display: block; }
        .empty-box h3 {
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: 18px; color: var(--text-h); margin-bottom: 7px;
        }
        .empty-box p { font-size: 13.5px; color: var(--text-muted); line-height: 1.65; max-width: 320px; margin: 0 auto; }

        /* ─── Animations ─── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ─── Responsive ─── */
        @media (max-width: 900px) {
            .two-col { grid-template-columns: 1fr; }
            .header-stats { display: none; }
        }
        @media (max-width: 640px) {
            .navbar { padding: 0 18px; }
            .page { padding: 22px 14px 60px; }
            .page-header { padding: 28px 22px; }
            .hist-grid { grid-template-columns: 1fr; }
            .meta-mini { grid-template-columns: 1fr; }
        }
        @media (max-width: 460px) {
            .nav-btn:not(.primary) { display: none; }
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
        <a href="my_transactions.php" class="nav-btn">📋 Transactions</a>
        <a href="dashboard.php" class="nav-btn primary">← Dashboard</a>
    </div>
</nav>

<!-- ════════════ PAGE ════════════ -->
<div class="page">

    <!-- Header hero -->
    <div class="page-header">
        <div class="header-inner">
            <div class="header-text">
                <p class="eyebrow">Feedback Centre</p>
                <h1>Review Center</h1>
                <p>Leave honest feedback for completed borrowing transactions. Reviews can only be submitted once an item has been returned — one review per transaction.</p>
            </div>
            <div class="header-stats">
                <div class="hstat">
                    <div class="num"><?php echo count($pending_rows); ?></div>
                    <div class="lbl">Pending</div>
                </div>
                <div class="hstat">
                    <div class="num"><?php echo count($completed_rows); ?></div>
                    <div class="lbl">Done</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($success)): ?>
    <div class="alert success">
        <span class="alert-icon">✅</span>
        <div class="alert-body">
            <strong>Review submitted!</strong>
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


    <!-- ══════════════════════════════════════
         SECTION 1 — PENDING REVIEWS
    ══════════════════════════════════════ -->
    <div class="section-block">
        <div class="section-heading">
            <div class="s-icon pending">⏳</div>
            <h2>Pending Reviews</h2>
            <span class="count-pill pending"><?php echo count($pending_rows); ?> awaiting</span>
            <div class="divider-line"></div>
        </div>

        <?php if (empty($pending_rows)): ?>
            <div class="empty-box">
                <span class="e-icon">🎉</span>
                <h3>You're all caught up!</h3>
                <p>No transactions are waiting for a review from you right now.</p>
            </div>
        <?php else: ?>
            <div class="two-col">
                <?php foreach ($pending_rows as $row): ?>
                <div class="tx-card">

                    <!-- Image strip -->
                    <div class="tx-img">
                        <?php if (!empty($row['image_path'])): ?>
                            <img src="<?php echo htmlspecialchars($row['image_path']); ?>"
                                 alt="<?php echo htmlspecialchars($row['item_name']); ?>" loading="lazy">
                        <?php else: ?>
                            <div class="tx-img-placeholder">
                                <span class="ph-icon">📦</span>
                                <span class="ph-lbl">No image</span>
                            </div>
                        <?php endif; ?>
                        <span class="ribbon pending">⏳ Awaiting Review</span>
                        <span class="role-pip"><?php echo htmlspecialchars($row['my_role']); ?></span>
                    </div>

                    <div class="tx-body">
                        <span class="tx-category">Returned Transaction</span>
                        <h3 class="tx-title"><?php echo htmlspecialchars($row['item_name']); ?></h3>

                        <div class="meta-mini">
                            <div class="meta-cell">
                                <div class="ml">Owner</div>
                                <div class="mv"><?php echo htmlspecialchars($row['owner_name']); ?></div>
                            </div>
                            <div class="meta-cell">
                                <div class="ml">Borrower</div>
                                <div class="mv"><?php echo htmlspecialchars($row['borrower_name']); ?></div>
                            </div>
                            <div class="meta-cell">
                                <div class="ml">Condition</div>
                                <div class="mv"><?php echo htmlspecialchars($row['item_condition']); ?></div>
                            </div>
                            <div class="meta-cell">
                                <div class="ml">Returned</div>
                                <div class="mv"><?php echo htmlspecialchars($row['return_date']); ?></div>
                            </div>
                        </div>

                        <!-- Review form -->
                        <div class="review-form-wrap">
                            <div class="rfw-title">
                                ✍️ Review
                                <span class="at-tag"><?php echo htmlspecialchars($row['reviewee_name']); ?></span>
                            </div>

                            <form method="POST">
                                <input type="hidden" name="transaction_id" value="<?php echo (int)$row['transaction_id']; ?>">
                                <input type="hidden" name="reviewee_id"   value="<?php echo (int)$row['reviewee_id']; ?>">

                                <!-- Visual star picker -->
                                <div class="star-picker" id="stars_<?php echo (int)$row['transaction_id']; ?>">
                                    <?php for ($s = 5; $s >= 1; $s--): ?>
                                        <input
                                            type="radio"
                                            name="rating"
                                            id="star<?php echo $s; ?>_<?php echo (int)$row['transaction_id']; ?>"
                                            value="<?php echo $s; ?>"
                                            <?php if ($s === 5) echo 'required'; ?>
                                        >
                                        <label
                                            for="star<?php echo $s; ?>_<?php echo (int)$row['transaction_id']; ?>"
                                            title="<?php echo $s; ?> star<?php echo $s > 1 ? 's' : ''; ?>"
                                        >★</label>
                                    <?php endfor; ?>
                                </div>

                                <textarea
                                    name="comment"
                                    id="comment_<?php echo (int)$row['transaction_id']; ?>"
                                    placeholder="Share your experience — was the item as described? Was the owner/borrower reliable?…"
                                ></textarea>

                                <div class="form-row">
                                    <button type="submit" class="btn-submit">Submit Review →</button>
                                </div>
                            </form>

                            <div class="tx-footer-note">
                                Transaction #<?php echo (int)$row['transaction_id']; ?> · Request #<?php echo (int)$row['request_id']; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>


    <!-- ══════════════════════════════════════
         SECTION 2 — COMPLETED REVIEWS
    ══════════════════════════════════════ -->
    <div class="section-block">
        <div class="section-heading">
            <div class="s-icon done">✅</div>
            <h2>Completed Reviews</h2>
            <span class="count-pill done"><?php echo count($completed_rows); ?> submitted</span>
            <div class="divider-line"></div>
        </div>

        <?php if (empty($completed_rows)): ?>
            <div class="empty-box">
                <span class="e-icon">📝</span>
                <h3>No completed reviews yet</h3>
                <p>Your submitted reviews for returned transactions will appear here.</p>
            </div>
        <?php else: ?>
            <div class="two-col">
                <?php foreach ($completed_rows as $row):
                    $rating  = (int)$row['my_rating'];
                    $comment = $row['my_comment'] ?? '';
                ?>
                <div class="tx-card">

                    <div class="tx-img">
                        <?php if (!empty($row['image_path'])): ?>
                            <img src="<?php echo htmlspecialchars($row['image_path']); ?>"
                                 alt="<?php echo htmlspecialchars($row['item_name']); ?>" loading="lazy">
                        <?php else: ?>
                            <div class="tx-img-placeholder">
                                <span class="ph-icon">📦</span>
                                <span class="ph-lbl">No image</span>
                            </div>
                        <?php endif; ?>
                        <span class="ribbon done">✅ Reviewed</span>
                        <span class="role-pip"><?php echo htmlspecialchars($row['my_role']); ?></span>
                    </div>

                    <div class="tx-body">
                        <span class="tx-category">Returned Transaction</span>
                        <h3 class="tx-title"><?php echo htmlspecialchars($row['item_name']); ?></h3>

                        <div class="meta-mini">
                            <div class="meta-cell">
                                <div class="ml">Owner</div>
                                <div class="mv"><?php echo htmlspecialchars($row['owner_name']); ?></div>
                            </div>
                            <div class="meta-cell">
                                <div class="ml">Borrower</div>
                                <div class="mv"><?php echo htmlspecialchars($row['borrower_name']); ?></div>
                            </div>
                            <div class="meta-cell">
                                <div class="ml">Reviewed On</div>
                                <div class="mv"><?php echo htmlspecialchars($row['my_review_date'] ?? $row['return_date']); ?></div>
                            </div>
                            <div class="meta-cell">
                                <div class="ml">Reviewed</div>
                                <div class="mv"><?php echo htmlspecialchars($row['reviewee_name']); ?></div>
                            </div>
                        </div>

                        <!-- Review summary -->
                        <div class="review-done-wrap">
                            <div class="rdw-header">
                                <span class="rdw-badge">✅ Review Submitted</span>
                                <div class="star-display">
                                    <?php for ($s = 1; $s <= 5; $s++): ?>
                                        <span class="<?php echo $s <= $rating ? 's-fill' : 's-empty'; ?>">★</span>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <div class="review-done-comment">
                                <?php if (!empty($comment)): ?>
                                    <?php echo nl2br(htmlspecialchars($comment)); ?>
                                <?php else: ?>
                                    <span class="no-comment">No comment was added for this review.</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="tx-footer-note" style="margin-top:12px;">
                            Transaction #<?php echo (int)$row['transaction_id']; ?> · Request #<?php echo (int)$row['request_id']; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>


    <!-- ══════════════════════════════════════
         SECTION 3 — REVIEWS RECEIVED
    ══════════════════════════════════════ -->
    <div class="section-block">
        <div class="section-heading">
            <div class="s-icon inbox">📥</div>
            <h2>Reviews About Me</h2>
            <span class="count-pill neutral"><?php echo $received_reviews->num_rows; ?> received</span>
            <div class="divider-line"></div>
        </div>

        <?php if ($received_reviews->num_rows > 0): ?>
            <div class="hist-grid">
                <?php while ($row = $received_reviews->fetch_assoc()):
                    $rating = (int)$row['rating'];
                ?>
                <div class="hist-card">
                    <div class="hist-top">
                        <div class="hist-item"><?php echo htmlspecialchars($row['item_name']); ?></div>
                        <div class="hist-date"><?php echo htmlspecialchars($row['review_date']); ?></div>
                    </div>
                    <div class="hist-meta">
                        From <strong><?php echo htmlspecialchars($row['reviewer_name']); ?></strong>
                    </div>
                    <div class="hist-stars">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <span style="color:<?php echo $s <= $rating ? 'var(--star-fill)' : 'var(--star-empty)'; ?>">★</span>
                        <?php endfor; ?>
                    </div>
                    <div class="hist-comment">
                        <?php if (!empty($row['comment'])): ?>
                            <?php echo nl2br(htmlspecialchars($row['comment'])); ?>
                        <?php else: ?>
                            <span class="no-comment">No comment provided.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-box">
                <span class="e-icon">💬</span>
                <h3>No reviews yet</h3>
                <p>Reviews left by others about your transactions will show up here.</p>
            </div>
        <?php endif; ?>
    </div>


    <!-- ══════════════════════════════════════
         SECTION 4 — REVIEWS GIVEN
    ══════════════════════════════════════ -->
    <div class="section-block">
        <div class="section-heading">
            <div class="s-icon outbox">📤</div>
            <h2>Reviews I Gave</h2>
            <span class="count-pill neutral"><?php echo $given_reviews->num_rows; ?> submitted</span>
            <div class="divider-line"></div>
        </div>

        <?php if ($given_reviews->num_rows > 0): ?>
            <div class="hist-grid">
                <?php while ($row = $given_reviews->fetch_assoc()):
                    $rating = (int)$row['rating'];
                ?>
                <div class="hist-card">
                    <div class="hist-top">
                        <div class="hist-item"><?php echo htmlspecialchars($row['item_name']); ?></div>
                        <div class="hist-date"><?php echo htmlspecialchars($row['review_date']); ?></div>
                    </div>
                    <div class="hist-meta">
                        For <strong><?php echo htmlspecialchars($row['reviewee_name']); ?></strong>
                    </div>
                    <div class="hist-stars">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <span style="color:<?php echo $s <= $rating ? 'var(--star-fill)' : 'var(--star-empty)'; ?>">★</span>
                        <?php endfor; ?>
                    </div>
                    <div class="hist-comment">
                        <?php if (!empty($row['comment'])): ?>
                            <?php echo nl2br(htmlspecialchars($row['comment'])); ?>
                        <?php else: ?>
                            <span class="no-comment">No comment was added.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-box">
                <span class="e-icon">✍️</span>
                <h3>No reviews submitted yet</h3>
                <p>Reviews you write for returned transactions will be displayed here.</p>
            </div>
        <?php endif; ?>
    </div>

</div><!-- /.page -->

</body>
</html>