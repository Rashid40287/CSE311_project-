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

/* Handle approve / reject action */
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $request_id = isset($_POST['request_id']) ? (int) $_POST['request_id'] : 0;
    $action = isset($_POST['action']) ? $_POST['action'] : "";

    if ($request_id <= 0 || !in_array($action, ['approve', 'reject'])) {
        $error = "Invalid request action.";
    } else {
        /* Verify this request belongs to an item owned by the logged-in user */
        $check_stmt = $conn->prepare("
            SELECT 
                br.request_id,
                br.item_id,
                br.borrower_id,
                br.request_status,
                br.requested_from_date,
                br.requested_to_date
            FROM borrow_request br
            JOIN item i ON br.item_id = i.item_id
            WHERE br.request_id = ?
              AND i.owner_id = ?
        ");
        $check_stmt->bind_param("ii", $request_id, $student_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows !== 1) {
            $error = "Request not found or you are not allowed to manage it.";
        } else {
            $request = $check_result->fetch_assoc();

            if ($request['request_status'] !== 'pending') {
                $error = "Only pending requests can be updated.";
            } else {
                $owner_response_date = date("Y-m-d");

                if ($action === 'reject') {
                    $update_stmt = $conn->prepare("
                        UPDATE borrow_request
                        SET request_status = 'rejected',
                            owner_response_date = ?
                        WHERE request_id = ?
                    ");
                    $update_stmt->bind_param("si", $owner_response_date, $request_id);

                    if ($update_stmt->execute()) {
                        $success = "Request rejected successfully.";
                    } else {
                        $error = "Failed to reject request: " . $update_stmt->error;
                    }

                    $update_stmt->close();
                }

                if ($action === 'approve') {
                    $conn->begin_transaction();

                    try {
                        $item_id = (int)$request['item_id'];

                        /* Check if this item already has an active or overdue borrowing transaction */
                        $active_stmt = $conn->prepare("
                            SELECT bt.transaction_id
                            FROM borrow_transaction bt
                            JOIN borrow_request br ON bt.request_id = br.request_id
                            WHERE br.item_id = ?
                              AND bt.transaction_status IN ('active', 'overdue')
                            LIMIT 1
                        ");
                        $active_stmt->bind_param("i", $item_id);
                        $active_stmt->execute();
                        $active_result = $active_stmt->get_result();

                        if ($active_result->num_rows > 0) {
                            $active_stmt->close();
                            throw new Exception("This item is currently borrowed or overdue. You cannot approve another request until it is returned.");
                        }
                        $active_stmt->close();

                        /* 1. Approve the selected request */
                        $approve_stmt = $conn->prepare("
                            UPDATE borrow_request
                            SET request_status = 'approved',
                                owner_response_date = ?
                            WHERE request_id = ?
                              AND request_status = 'pending'
                        ");
                        $approve_stmt->bind_param("si", $owner_response_date, $request_id);

                        if (!$approve_stmt->execute()) {
                            throw new Exception("Failed to approve request.");
                        }

                        if ($approve_stmt->affected_rows !== 1) {
                            throw new Exception("This request is no longer pending.");
                        }

                        $approve_stmt->close();

                        /* 2. Reject all other pending requests for the same item */
                        $reject_stmt = $conn->prepare("
                            UPDATE borrow_request
                            SET request_status = 'rejected',
                                owner_response_date = ?
                            WHERE item_id = ?
                              AND request_id <> ?
                              AND request_status = 'pending'
                        ");
                        $reject_stmt->bind_param("sii", $owner_response_date, $item_id, $request_id);

                        if (!$reject_stmt->execute()) {
                            throw new Exception("Failed to reject other pending requests.");
                        }

                        $reject_stmt->close();

                        /* 3. Create borrow transaction */
                        $borrow_date        = $request['requested_from_date'];
                        $due_date           = $request['requested_to_date'];
                        $transaction_status = "active";

                        $transaction_stmt = $conn->prepare("
                            INSERT INTO borrow_transaction
                            (request_id, borrow_date, due_date, return_date, transaction_status)
                            VALUES (?, ?, ?, NULL, ?)
                        ");
                        $transaction_stmt->bind_param(
                            "isss",
                            $request_id,
                            $borrow_date,
                            $due_date,
                            $transaction_status
                        );

                        if (!$transaction_stmt->execute()) {
                            throw new Exception("Failed to create transaction: " . $transaction_stmt->error);
                        }

                        $transaction_stmt->close();

                        $conn->commit();
                        $success = "Request approved successfully. Other pending requests for this item were automatically rejected.";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = $e->getMessage();
                    }
                }
            }
        }

        $check_stmt->close();
    }
}

/* Fetch all requests for items owned by this user */
$stmt = $conn->prepare("
    SELECT 
        br.request_id,
        br.borrower_id,
        br.request_date,
        br.requested_from_date,
        br.requested_to_date,
        br.request_message,
        br.request_status,
        br.owner_response_date,
        i.item_id,
        i.item_name,
        i.item_condition,
        s.full_name AS borrower_name,
        s.university_email AS borrower_email,
        img.image_path
    FROM borrow_request br
    JOIN item i ON br.item_id = i.item_id
    JOIN student s ON br.borrower_id = s.student_id
    LEFT JOIN item_image img ON i.item_id = img.item_id AND img.is_primary = TRUE
    WHERE i.owner_id = ?
    ORDER BY 
        CASE 
            WHEN br.request_status = 'pending'   THEN 1
            WHEN br.request_status = 'approved'  THEN 2
            WHEN br.request_status = 'rejected'  THEN 3
            WHEN br.request_status = 'cancelled' THEN 4
            ELSE 5
        END,
        br.request_date DESC,
        br.request_id DESC
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

/* Pre-fetch all rows + count by status */
$all_requests  = [];
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
    <title>Manage Requests | Campus Resource Sharing</title>
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
            min-width: 76px; backdrop-filter: blur(8px);
        }
        .hstat .num {
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: 22px; color: #eef0ff; line-height: 1; margin-bottom: 4px;
        }
        .hstat .num.gold  { color: var(--gold); }
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

        /* ─── Pending notice banner ─── */
        .pending-banner {
            display: flex; align-items: center; gap: 12px;
            background: var(--s-pending-bg); border: 1px solid var(--s-pending-bdr);
            border-radius: 14px; padding: 13px 18px; margin-bottom: 24px;
            font-size: 13.5px; color: var(--s-pending-t); font-weight: 500;
            animation: fadeUp .5s cubic-bezier(.16,1,.3,1) .08s both;
        }
        .pending-banner strong { font-family: 'Syne', sans-serif; font-weight: 700; }

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
        /* Pending cards get a slightly stronger border */
        .request-card.is-pending {
            border-color: var(--s-pending-bdr);
            box-shadow: var(--shadow-sm), 0 0 0 1px var(--s-pending-bdr);
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
        .s-pending   { background: var(--s-pending-bg);   border-color: var(--s-pending-bdr);   color: var(--s-pending-t); }
        .s-approved  { background: var(--s-approved-bg);  border-color: var(--s-approved-bdr);  color: var(--s-approved-t); }
        .s-rejected  { background: var(--s-rejected-bg);  border-color: var(--s-rejected-bdr);  color: var(--s-rejected-t); }
        .s-cancelled { background: var(--s-cancelled-bg); border-color: var(--s-cancelled-bdr); color: var(--s-cancelled-t); }

        .s-pending::before   { content: '⏳ '; }
        .s-approved::before  { content: '✅ '; }
        .s-rejected::before  { content: '❌ '; }
        .s-cancelled::before { content: '🚫 '; }

        /* Request ID pill */
        .req-id-pill {
            position: absolute; top: 12px; right: 12px;
            font-size: 10.5px; font-weight: 700;
            padding: 3px 9px; border-radius: 999px;
            background: var(--navy); color: #eef0ff; letter-spacing: .4px;
        }

        /* Card body */
        .card-body { padding: 20px 22px 22px; display: flex; flex-direction: column; flex: 1; }

        .card-title {
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: 18px; color: var(--text-h);
            letter-spacing: -.3px; line-height: 1.3; margin-bottom: 16px;
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
        .meta-cell .mv a {
            color: var(--accent); text-decoration: none; font-weight: 600;
            font-size: 12px; display: inline-flex; align-items: center; gap: 3px;
        }
        .meta-cell .mv a:hover { text-decoration: underline; }
        .meta-cell .mv .email-text {
            font-size: 12px; word-break: break-all; font-weight: 500;
        }

        /* Date range pill */
        .date-range {
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
            background: var(--accent-soft); border: 1px solid var(--border);
            border-radius: 12px; padding: 10px 14px; margin-bottom: 14px;
            font-size: 13px; color: var(--accent-dark);
        }
        .date-range .dr-icon { font-size: 14px; flex-shrink: 0; }
        .date-range strong   { font-weight: 700; color: var(--text-h); font-family: 'Syne', sans-serif; font-size: 12px; }
        .date-range .arrow   { color: var(--text-muted); }

        /* Message box */
        .msg-box {
            background: var(--bg); border-left: 3px solid var(--accent);
            border-radius: 11px; padding: 12px 15px; margin-bottom: 16px; flex: 1;
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
        .footer-id { font-size: 11.5px; color: var(--text-muted); letter-spacing: .2px; }

        /* Action buttons — pending only */
        .action-form { display: flex; gap: 9px; flex-wrap: wrap; }

        .btn-approve {
            display: inline-flex; align-items: center; gap: 7px;
            border: none; padding: 10px 18px; border-radius: 11px;
            font-family: 'Syne', sans-serif; font-size: 12.5px; font-weight: 700;
            cursor: pointer; letter-spacing: .3px;
            background: var(--s-approved-bg); color: var(--s-approved-t);
            border: 1px solid var(--s-approved-bdr);
            transition: background .2s, transform .2s, box-shadow .2s;
        }
        .btn-approve:hover {
            background: #dcfce7; transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(22,163,74,.2);
        }

        .btn-reject {
            display: inline-flex; align-items: center; gap: 7px;
            border: none; padding: 10px 18px; border-radius: 11px;
            font-family: 'Syne', sans-serif; font-size: 12.5px; font-weight: 700;
            cursor: pointer; letter-spacing: .3px;
            background: var(--s-rejected-bg); color: var(--s-rejected-t);
            border: 1px solid var(--s-rejected-bdr);
            transition: background .2s, transform .2s, box-shadow .2s;
        }
        .btn-reject:hover {
            background: #ffe4e6; transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(159,18,57,.18);
        }
        .btn-approve:active, .btn-reject:active { transform: translateY(0); }

        /* Status note for resolved cards */
        .status-note {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12.5px; font-weight: 600; color: var(--text-muted);
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
            max-width: 340px; margin: 0 auto; line-height: 1.65;
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

        .request-card:nth-child(1)  { animation-delay: .04s; }
        .request-card:nth-child(2)  { animation-delay: .08s; }
        .request-card:nth-child(3)  { animation-delay: .12s; }
        .request-card:nth-child(4)  { animation-delay: .16s; }
        .request-card:nth-child(n+5){ animation-delay: .20s; }

        /* ─── Responsive ─── */
        @media (max-width: 1040px) { .requests-grid { grid-template-columns: 1fr; } }
        @media (max-width: 700px) {
            .navbar { padding: 0 18px; }
            .page   { padding: 22px 14px 60px; }
            .hero   { padding: 28px 22px; }
            .hero-stats { display: none; }
            .meta-grid { grid-template-columns: 1fr; }
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
        <a href="my_requests.php" class="nav-btn">📨 My Requests</a>
        <a href="dashboard.php"   class="nav-btn solid">← Dashboard</a>
    </div>
</nav>

<!-- ════════════ PAGE ════════════ -->
<div class="page">

    <!-- Hero -->
    <div class="hero">
        <div class="hero-inner">
            <div class="hero-text">
                <p class="eyebrow">Owner Panel</p>
                <h1>Manage Requests</h1>
                <p>Review borrowing requests submitted for your listed items. Approve to create a transaction, or reject to decline — approving one auto-rejects all other pending requests for the same item.</p>
                <div class="hero-actions">
                    <a href="my_requests.php" class="ha-btn white">📨 My Requests</a>
                    <a href="dashboard.php"   class="ha-btn ghost">← Dashboard</a>
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
            <strong>Success!</strong>
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

    <!-- Pending notice if any pending exist -->
    <?php if ($status_counts['pending'] > 0): ?>
    <div class="pending-banner">
        <span style="font-size:18px;">⏳</span>
        <span>You have <strong><?php echo $status_counts['pending']; ?> pending request<?php echo $status_counts['pending'] !== 1 ? 's' : ''; ?></strong> awaiting your decision — they are shown first below.</span>
    </div>
    <?php endif; ?>

    <!-- ════════ REQUESTS GRID ════════ -->
    <?php if (!empty($all_requests)): ?>

        <div class="requests-grid">
            <?php foreach ($all_requests as $row):
                $status    = htmlspecialchars($row['request_status']);
                $statusCap = ucfirst($status);
                $isPending = ($row['request_status'] === 'pending');
            ?>

            <div class="request-card <?php echo $isPending ? 'is-pending' : ''; ?>">

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

                    <h3 class="card-title"><?php echo htmlspecialchars($row['item_name']); ?></h3>

                    <!-- Meta grid -->
                    <div class="meta-grid">
                        <div class="meta-cell">
                            <div class="ml">Borrower</div>
                            <div class="mv">
                                <?php echo htmlspecialchars($row['borrower_name']); ?>
                                <br>
                                <a href="user_reviews.php?user_id=<?php echo (int)$row['borrower_id']; ?>">
                                    ⭐ View Reviews
                                </a>
                            </div>
                        </div>
                        <div class="meta-cell">
                            <div class="ml">Email</div>
                            <div class="mv">
                                <span class="email-text"><?php echo htmlspecialchars($row['borrower_email']); ?></span>
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
                            <div class="ml">Response Date</div>
                            <div class="mv">
                                <?php echo !empty($row['owner_response_date'])
                                    ? htmlspecialchars($row['owner_response_date'])
                                    : '<span style="color:var(--text-muted);font-weight:400;">Not yet</span>'; ?>
                            </div>
                        </div>
                        <div class="meta-cell">
                            <div class="ml">Item ID</div>
                            <div class="mv">#<?php echo (int)$row['item_id']; ?></div>
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

                    <!-- Borrower message -->
                    <?php if (!empty($row['request_message'])): ?>
                    <div class="msg-box">
                        <div class="msg-label">💬 Borrower Message</div>
                        <p><?php echo nl2br(htmlspecialchars($row['request_message'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <!-- Footer -->
                    <div class="card-footer">
                        <span class="footer-id">Request ID: <?php echo (int)$row['request_id']; ?></span>

                        <?php if ($isPending): ?>
                        <form method="POST" class="action-form">
                            <input type="hidden" name="request_id" value="<?php echo (int)$row['request_id']; ?>">
                            <button type="submit" name="action" value="approve" class="btn-approve">
                                ✅ Approve
                            </button>
                            <button type="submit" name="action" value="reject" class="btn-reject">
                                ❌ Reject
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="status-note">
                            <?php
                                $icons = ['approved' => '✅', 'rejected' => '❌', 'cancelled' => '🚫'];
                                $icon  = $icons[$status] ?? '•';
                                echo $icon . ' ' . ucfirst($status);
                            ?>
                        </span>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

            <?php endforeach; ?>
        </div>

    <?php else: ?>

        <div class="empty-box">
            <span class="e-icon">📭</span>
            <h3>No incoming requests yet</h3>
            <p>No students have requested your listed items yet. Once they do, all requests will appear here for you to manage.</p>
        </div>

    <?php endif; ?>

</div><!-- /.page -->

</body>
</html>