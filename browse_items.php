<?php
session_start();
include 'includes/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

// Search + filter
$search = isset($_GET['search']) ? trim($_GET['search']) : "";
$category = isset($_GET['category']) ? $_GET['category'] : "";

$success = "";
$error = "";

// Owner-only item deletion
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'delete_item') {
    $item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
    $student_id = (int) $_SESSION['student_id'];

    if ($item_id <= 0) {
        $error = "Invalid item selected.";
    } else {
        $check_stmt = $conn->prepare("
            SELECT item_id, item_name
            FROM item
            WHERE item_id = ? AND owner_id = ?
        ");
        $check_stmt->bind_param("ii", $item_id, $student_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows !== 1) {
            $error = "You can only remove items that you own.";
        } else {
            $item = $check_result->fetch_assoc();

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
                $error = "This item cannot be removed because it is currently in an active borrowing transaction.";
            } else {
                $image_paths = [];
                $img_stmt = $conn->prepare("SELECT image_path FROM item_image WHERE item_id = ?");
                $img_stmt->bind_param("i", $item_id);
                $img_stmt->execute();
                $img_result = $img_stmt->get_result();
                while ($img = $img_result->fetch_assoc()) {
                    if (!empty($img['image_path'])) {
                        $image_paths[] = $img['image_path'];
                    }
                }
                $img_stmt->close();

                $conn->begin_transaction();

                try {
                    // Remove image records first because they depend on item.
                    $del_img_stmt = $conn->prepare("DELETE FROM item_image WHERE item_id = ?");
                    $del_img_stmt->bind_param("i", $item_id);
                    $del_img_stmt->execute();
                    $del_img_stmt->close();

                    // Remove request records only when they do not have a transaction record.
                    $del_req_stmt = $conn->prepare("
                        DELETE br
                        FROM borrow_request br
                        LEFT JOIN borrow_transaction bt ON bt.request_id = br.request_id
                        WHERE br.item_id = ?
                          AND bt.transaction_id IS NULL
                    ");
                    $del_req_stmt->bind_param("i", $item_id);
                    $del_req_stmt->execute();
                    $del_req_stmt->close();

                    // Actual DELETE operation for CRUD requirement.
                    $del_item_stmt = $conn->prepare("DELETE FROM item WHERE item_id = ? AND owner_id = ?");
                    $del_item_stmt->bind_param("ii", $item_id, $student_id);
                    $del_item_stmt->execute();

                    if ($del_item_stmt->affected_rows !== 1) {
                        throw new Exception("Item could not be removed. It may have transaction history that must be kept.");
                    }

                    $del_item_stmt->close();
                    $conn->commit();

                    foreach ($image_paths as $path) {
                        $full_path = __DIR__ . '/' . $path;
                        if (is_file($full_path)) {
                            @unlink($full_path);
                        }
                    }

                    $success = "Item removed successfully.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Failed to remove item. " . $e->getMessage();
                }
            }

            $active_stmt->close();
        }

        $check_stmt->close();
    }
}

// Base query
$sql = "
SELECT i.item_id, i.item_name, i.item_description, i.item_condition,
       i.availability_status,
       c.category_name,
       s.student_id AS owner_id,
       s.full_name,
       img.image_path
FROM item i
JOIN category c ON i.category_id = c.category_id
JOIN student s ON i.owner_id = s.student_id
LEFT JOIN item_image img ON i.item_id = img.item_id AND img.is_primary = TRUE
WHERE i.availability_status = 'available'
";

$params = [];
$types = "";

// Search filter
if (!empty($search)) {
    $sql .= " AND i.item_name LIKE ?";
    $params[] = "%" . $search . "%";
    $types .= "s";
}

// Category filter
if (!empty($category)) {
    $sql .= " AND i.category_id = ?";
    $params[] = $category;
    $types .= "i";
}

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

// Get categories for filter dropdown
$categories = $conn->query("SELECT * FROM category");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Items | Campus Resource Sharing</title>
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
            --radius:       16px;

            /* Condition badge colours */
            --c-new:        #dcfce7; --c-new-t:      #166534; --c-new-b: #86efac;
            --c-like-new:   #dbeafe; --c-like-new-t: #1e40af; --c-like-new-b: #93c5fd;
            --c-good:       #ede9fe; --c-good-t:     #5b21b6; --c-good-b: #c4b5fd;
            --c-fair:       #fef9c3; --c-fair-t:     #854d0e; --c-fair-b: #fde047;
            --c-poor:       #fee2e2; --c-poor-t:     #991b1b; --c-poor-b: #fca5a5;
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
        }
        .nav-brand span {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 17px;
            color: var(--navy);
            letter-spacing: -0.3px;
        }
        .nav-back {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            color: var(--text-muted);
            font-size: 13.5px;
            font-weight: 500;
            padding: 8px 14px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--surface);
            transition: color .2s, border-color .2s, box-shadow .2s, transform .2s;
        }
        .nav-back:hover {
            color: var(--accent);
            border-color: rgba(79,110,247,.35);
            box-shadow: 0 4px 14px rgba(79,110,247,.1);
            transform: translateY(-1px);
        }
        .nav-back .chevron { transition: transform .2s; }
        .nav-back:hover .chevron { transform: translateX(-3px); }

        /* ─────────────────────────────
           PAGE LAYOUT
        ───────────────────────────── */
        .page {
            max-width: 1240px;
            margin: 0 auto;
            padding: 36px 24px 72px;
        }

        /* ─────────────────────────────
           PAGE HEADER
        ───────────────────────────── */
        .page-header {
            margin-bottom: 30px;
            animation: fadeUp .5s cubic-bezier(.16,1,.3,1) both;
        }
        .page-header .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1.8px;
            text-transform: uppercase;
            color: var(--accent);
            margin-bottom: 8px;
        }
        .page-header .eyebrow::before {
            content: '';
            width: 16px; height: 2px;
            background: var(--accent);
            border-radius: 2px;
        }
        .page-header h1 {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 28px;
            color: var(--text-h);
            letter-spacing: -.5px;
            line-height: 1.2;
        }
        .page-header p {
            margin-top: 6px;
            font-size: 14px;
            color: var(--text-muted);
        }

        /* ─────────────────────────────
           FILTER BAR
        ───────────────────────────── */
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 12px 16px;
            margin-bottom: 32px;
            box-shadow: var(--shadow-sm);
            animation: fadeUp .5s cubic-bezier(.16,1,.3,1) .07s both;
            flex-wrap: wrap;
        }

        .filter-search {
            position: relative;
            flex: 1 1 220px;
            min-width: 180px;
        }
        .filter-search .search-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 15px;
            color: var(--text-muted);
            pointer-events: none;
        }
        .filter-search input {
            width: 100%;
            padding: 11px 14px 11px 38px;
            background: var(--bg);
            border: 1.5px solid var(--border);
            border-radius: 12px;
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            color: var(--text-h);
            outline: none;
            transition: border-color .22s, box-shadow .22s, background .22s;
        }
        .filter-search input::placeholder { color: var(--text-muted); }
        .filter-search input:focus {
            border-color: var(--accent);
            background: var(--surface);
            box-shadow: 0 0 0 3px rgba(79,110,247,.12);
        }

        .filter-divider {
            width: 1px;
            height: 32px;
            background: var(--border);
            flex-shrink: 0;
        }

        .select-wrap {
            position: relative;
            flex: 0 1 200px;
            min-width: 150px;
        }
        .select-wrap::after {
            content: '▾';
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 13px;
            pointer-events: none;
        }
        .select-wrap select {
            width: 100%;
            padding: 11px 34px 11px 14px;
            background: var(--bg);
            border: 1.5px solid var(--border);
            border-radius: 12px;
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            color: var(--text-h);
            outline: none;
            appearance: none;
            -webkit-appearance: none;
            cursor: pointer;
            transition: border-color .22s, box-shadow .22s, background .22s;
        }
        .select-wrap select:focus {
            border-color: var(--accent);
            background: var(--surface);
            box-shadow: 0 0 0 3px rgba(79,110,247,.12);
        }

        .btn-filter {
            flex-shrink: 0;
            padding: 11px 22px;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: #fff;
            border: none;
            border-radius: 12px;
            font-family: 'Syne', sans-serif;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 7px;
            transition: transform .2s, box-shadow .2s;
            white-space: nowrap;
        }
        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(79,110,247,.32);
        }
        .btn-filter:active { transform: translateY(0); }

        /* Result meta row */
        .result-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            animation: fadeUp .5s cubic-bezier(.16,1,.3,1) .12s both;
            flex-wrap: wrap;
            gap: 10px;
        }
        .result-count {
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            font-size: 15px;
            color: var(--text-h);
        }
        .result-count span { color: var(--accent); }
        .active-filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--accent-soft);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 4px 12px;
            font-size: 12px;
            font-weight: 600;
            color: var(--accent-dark);
        }

        /* ─────────────────────────────
           ITEM GRID  ← updated min-width 300px
        ───────────────────────────── */
        .items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 22px;
        }

        /* ─────────────────────────────
           ITEM CARD
        ───────────────────────────── */
        .item-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 22px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            transition: transform .25s cubic-bezier(.34,1.56,.64,1), box-shadow .25s, border-color .25s;
            animation: cardUp .5s cubic-bezier(.16,1,.3,1) both;
        }
        .item-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-md);
            border-color: rgba(79,110,247,.22);
        }

        /* Image area */
        .card-img-wrap {
            position: relative;
            width: 100%;
            height: 192px;
            overflow: hidden;
            background: linear-gradient(135deg, #c7d2fe 0%, #a5b4fc 50%, #818cf8 100%);
            flex-shrink: 0;
        }
        .card-img-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform .4s ease;
        }
        .item-card:hover .card-img-wrap img { transform: scale(1.04); }

        /* CSS placeholder when no image */
        .card-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 50%, #a5b4fc 100%);
        }
        .card-placeholder .ph-icon  { font-size: 40px; opacity: .55; }
        .card-placeholder .ph-label {
            font-size: 11px; font-weight: 600;
            letter-spacing: 1px; text-transform: uppercase;
            color: #6366f1; opacity: .7;
        }

        /* Condition badge — floated on image */
        .condition-badge {
            position: absolute;
            top: 12px; right: 12px;
            font-size: 11px; font-weight: 700;
            letter-spacing: .5px; text-transform: capitalize;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid transparent;
            backdrop-filter: blur(8px);
        }
        .cond-new       { background: var(--c-new);      color: var(--c-new-t);      border-color: var(--c-new-b); }
        .cond-like_new  { background: var(--c-like-new); color: var(--c-like-new-t); border-color: var(--c-like-new-b); }
        .cond-good      { background: var(--c-good);     color: var(--c-good-t);     border-color: var(--c-good-b); }
        .cond-fair      { background: var(--c-fair);     color: var(--c-fair-t);     border-color: var(--c-fair-b); }
        .cond-poor      { background: var(--c-poor);     color: var(--c-poor-t);     border-color: var(--c-poor-b); }

        /* ─── Card body  ← flex:1 so card stretches full row height ─── */
        .card-body {
            padding: 20px 22px 22px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .card-category {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px; font-weight: 600;
            letter-spacing: .7px; text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 7px;
        }
        .card-category::before {
            content: '';
            width: 5px; height: 5px;
            background: var(--accent);
            border-radius: 50%; flex-shrink: 0;
        }

        .card-title {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: 16.5px;
            color: var(--text-h); letter-spacing: -.2px;
            line-height: 1.3; margin-bottom: 12px;
        }

        /* ─── Card meta  ← flex:1 pushes footer to bottom ─── */
        .card-meta {
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex: 1;
            margin-bottom: 18px;
        }

        .meta-row {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 12.5px;
            color: var(--text-muted);
            flex-wrap: wrap;
        }
        .meta-row .meta-icon { font-size: 13px; }
        .meta-row strong { color: var(--text-body); font-weight: 500; }

        /* ─── Description box  ← new, replaces inline meta-row ─── */
        .desc-box {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 11px;
            padding: 11px 13px;
            font-size: 14px;
            line-height: 1.6;
            color: var(--text-body);
            min-height: 72px;   /* comfortably holds 3-4 lines */
        }

        /* ─── Footer always pinned to bottom ─── */
        .card-footer {
            padding-top: 14px;
            border-top: 1px solid var(--border);
            margin-top: auto;
        }

        .btn-request {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            width: 100%;
            padding: 11px 16px;
            background: var(--navy);
            color: #eef0ff;
            text-decoration: none;
            border-radius: 12px;
            font-family: 'Syne', sans-serif;
            font-size: 13px; font-weight: 700;
            letter-spacing: .3px;
            transition: background .2s, transform .2s, box-shadow .2s;
        }
        .btn-request .arrow { transition: transform .2s; }
        .btn-request:hover {
            background: var(--accent-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(79,110,247,.28);
        }
        .btn-request:hover .arrow { transform: translateX(3px); }

        .owner-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .delete-form { width: 100%; }

        .btn-remove {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            width: 100%;
            padding: 11px 16px;
            background: #7f1d1d;
            color: #ffffff;
            border: none;
            text-decoration: none;
            border-radius: 12px;
            font-family: 'Syne', sans-serif;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .3px;
            cursor: pointer;
            transition: background .2s, transform .2s, box-shadow .2s;
        }
        .btn-remove:hover {
            background: #991b1b;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(127,29,29,.35);
        }

        .alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            border-radius: 14px;
            margin-bottom: 24px;
            font-size: 13.5px;
            font-weight: 500;
            border: 1px solid;
            animation: fadeUp .4s cubic-bezier(.16,1,.3,1) both;
        }
        .alert.success { background: #f0fdf4; border-color: #86efac; color: #166534; }
        .alert.error   { background: #fff1f2; border-color: #fda4af; color: #9f1239; }

        /* ─────────────────────────────
           EMPTY STATE
        ───────────────────────────── */
        .empty-state {
            grid-column: 1 / -1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 72px 24px;
            text-align: center;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: var(--shadow-sm);
            animation: fadeUp .5s cubic-bezier(.16,1,.3,1) both;
        }
        .empty-icon { font-size: 56px; margin-bottom: 18px; opacity: .55; }
        .empty-state h3 {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: 20px;
            color: var(--text-h); margin-bottom: 8px;
        }
        .empty-state p {
            font-size: 14px; color: var(--text-muted);
            max-width: 340px; line-height: 1.65; margin-bottom: 24px;
        }
        .empty-state a {
            display: inline-flex; align-items: center; gap: 7px;
            text-decoration: none; padding: 11px 22px;
            background: var(--navy); color: #eef0ff;
            border-radius: 12px;
            font-family: 'Syne', sans-serif;
            font-size: 13px; font-weight: 700;
            transition: background .2s, transform .2s;
        }
        .empty-state a:hover { background: var(--accent-dark); transform: translateY(-1px); }

        /* ─────────────────────────────
           ANIMATIONS
        ───────────────────────────── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes cardUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* stagger cards */
        .item-card:nth-child(1)   { animation-delay: .04s; }
        .item-card:nth-child(2)   { animation-delay: .08s; }
        .item-card:nth-child(3)   { animation-delay: .12s; }
        .item-card:nth-child(4)   { animation-delay: .16s; }
        .item-card:nth-child(5)   { animation-delay: .20s; }
        .item-card:nth-child(6)   { animation-delay: .24s; }
        .item-card:nth-child(n+7) { animation-delay: .28s; }

        /* ─────────────────────────────
           RESPONSIVE
        ───────────────────────────── */
        @media (max-width: 700px) {
            .navbar      { padding: 0 18px; }
            .page        { padding: 22px 14px 52px; }
            .filter-bar  { padding: 10px 12px; }
            .filter-divider { display: none; }
            .page-header h1 { font-size: 22px; }
        }
        @media (max-width: 460px) {
            .btn-filter span { display: none; }
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
    <a href="dashboard.php" class="nav-back">
        <span class="chevron">←</span> Back to Dashboard
    </a>
</nav>

<!-- ════════════ PAGE ════════════ -->
<div class="page">

    <!-- Page header -->
    <div class="page-header">
        <p class="eyebrow">Marketplace</p>
        <h1>Browse Available Items</h1>
        <p>Discover resources shared by your campus community and request what you need.</p>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert success">✅ <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert error">⚠️ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Filter bar -->
    <form method="GET">
        <div class="filter-bar">

            <div class="filter-search">
                <span class="search-icon">🔍</span>
                <input
                    type="text"
                    name="search"
                    placeholder="Search items by name…"
                    value="<?php echo htmlspecialchars($search); ?>"
                >
            </div>

            <div class="filter-divider"></div>

            <div class="select-wrap">
                <select name="category">
                    <option value="">All Categories</option>
                    <?php while($cat = $categories->fetch_assoc()): ?>
                        <option
                            value="<?php echo $cat['category_id']; ?>"
                            <?php if ($category == $cat['category_id']) echo 'selected'; ?>
                        >
                            <?php echo htmlspecialchars($cat['category_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <button type="submit" class="btn-filter">
                <span>🗂</span>
                <span>Filter Results</span>
            </button>

        </div>
    </form>

    <!-- Active filter chips + result count -->
    <?php
        $totalRows  = $result->num_rows;
        $hasFilters = !empty($search) || !empty($category);
    ?>
    <div class="result-meta">
        <div class="result-count">
            <span><?php echo $totalRows; ?></span>
            item<?php echo $totalRows !== 1 ? 's' : ''; ?> found
        </div>
        <?php if ($hasFilters): ?>
        <div class="active-filters">
            <?php if (!empty($search)): ?>
                <span class="filter-chip">🔍 "<?php echo htmlspecialchars($search); ?>"</span>
            <?php endif; ?>
            <?php if (!empty($category)): ?>
                <span class="filter-chip">🗂 Category #<?php echo htmlspecialchars($category); ?></span>
            <?php endif; ?>
            <a href="browse_items.php" class="filter-chip" style="color:var(--text-muted);text-decoration:none;">✕ Clear</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- ════════ ITEMS GRID ════════ -->
    <div class="items-grid">

        <?php if ($totalRows === 0): ?>

            <div class="empty-state">
                <div class="empty-icon">📦</div>
                <h3>No items found</h3>
                <p>
                    <?php if ($hasFilters): ?>
                        We couldn't find any items matching your search. Try adjusting your filters or clearing the search.
                    <?php else: ?>
                        There are no items available right now. Be the first to share a resource with your campus community!
                    <?php endif; ?>
                </p>
                <?php if ($hasFilters): ?>
                    <a href="browse_items.php">← Clear filters</a>
                <?php else: ?>
                    <a href="add_item.php">+ Add your first item</a>
                <?php endif; ?>
            </div>

        <?php else: ?>

            <?php while($row = $result->fetch_assoc()):

                $condRaw   = $row['item_condition'] ?? 'good';
                $condClass = 'cond-' . strtolower(str_replace(' ', '_', $condRaw));
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
            <div class="item-card">
            <!-- Image / placeholder -->
                <div class="card-img-wrap">
                    <?php if (!empty($row['image_path'])): ?>
                        <img
                            src="<?php echo htmlspecialchars($row['image_path']); ?>"
                            alt="<?php echo htmlspecialchars($row['item_name']); ?>"
                            loading="lazy"
                            class="zoomable"
                            data-title="<?php echo htmlspecialchars($row['item_name']); ?>"
                            onclick="openModal(this.src, this.dataset.title)"
                        >
                    <?php else: ?>
                        <div class="card-placeholder">
                            <span class="ph-icon">📦</span>
                            <span class="ph-label">No image</span>
                        </div>
                    <?php endif; ?>

                    <span class="condition-badge <?php echo $condClass; ?>">
                        <?php echo $condEmoji; ?> <?php echo $condLabel; ?>
                    </span>
                </div>

                <!-- Card body -->
                <div class="card-body">

                    <span class="card-category">
                        <?php echo htmlspecialchars($row['category_name']); ?>
                    </span>

                    <h3 class="card-title">
                        <?php echo htmlspecialchars($row['item_name']); ?>
                    </h3>

                    <div class="card-meta">

                        <!-- Owner + review link -->
                        <div class="meta-row">
                            <span class="meta-icon">👤</span>
                            Shared by
                            <strong><?php echo htmlspecialchars($row['full_name']); ?></strong>
                            ·
                            <a
                                href="user_reviews.php?user_id=<?php echo (int)$row['owner_id']; ?>"
                                style="color: var(--accent); text-decoration: none; font-weight: 600;"
                            >
                                View Reviews
                            </a>
                        </div>

                        <!-- Full description — no truncation -->
                        <?php if (!empty($row['item_description'])): ?>
                        <div class="desc-box">
                            <?php echo nl2br(htmlspecialchars($row['item_description'])); ?>
                        </div>
                        <?php endif; ?>

                    </div>

                    <div class="card-footer">
                        <?php if ((int)$row['owner_id'] === (int)$_SESSION['student_id']): ?>
                            <div class="owner-actions">
                                <a href="request_item.php?item_id=<?php echo (int)$row['item_id']; ?>" class="btn-request">
                                    View Item <span class="arrow">→</span>
                                </a>
                                <form method="POST" class="delete-form" onsubmit="return confirm('Remove this item permanently? This cannot be undone.');">
                                    <input type="hidden" name="action" value="delete_item">
                                    <input type="hidden" name="item_id" value="<?php echo (int)$row['item_id']; ?>">
                                    <button type="submit" class="btn-remove">
                                        Remove
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <a href="request_item.php?item_id=<?php echo (int)$row['item_id']; ?>" class="btn-request">
                                Request Item <span class="arrow">→</span>
                            </a>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

            <?php endwhile; ?>

        <?php endif; ?>

    </div><!-- /.items-grid -->

</div><!-- /.page -->
<!-- ════════════ IMAGE MODAL ════════════ -->
<div id="imgModal" class="modal-overlay" onclick="closeModal(event)">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal()">&times;</button>
        <div class="modal-img-wrap">
            <img id="modalImg" src="" alt="">
        </div>
        <p id="modalCaption"></p>
    </div>
</div>

<style>
    /* ── Modal Overlay ── */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1000;
        background: rgba(10, 15, 44, 0.82);
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
        align-items: center;
        justify-content: center;
        padding: 20px;
        animation: none;
    }
    .modal-overlay.active {
        display: flex;
        animation: modalFadeIn .22s cubic-bezier(.16,1,.3,1) both;
    }

    .modal-box {
        position: relative;
        background: var(--surface);
        border-radius: 22px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-lg);
        max-width: 780px;
        width: 100%;
        overflow: hidden;
        animation: modalSlideUp .28s cubic-bezier(.16,1,.3,1) both;
    }

    .modal-img-wrap {
        width: 100%;
        max-height: 72vh;
        overflow: hidden;
        background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .modal-img-wrap img {
        width: 100%;
        height: 100%;
        max-height: 72vh;
        object-fit: contain;
        display: block;
    }

    #modalCaption {
        padding: 14px 20px;
        font-family: 'Syne', sans-serif;
        font-weight: 700;
        font-size: 15px;
        color: var(--text-h);
        text-align: center;
        border-top: 1px solid var(--border);
        background: var(--surface);
    }

    .modal-close {
        position: absolute;
        top: 12px;
        right: 14px;
        z-index: 10;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        border: 1.5px solid var(--border);
        background: var(--surface);
        color: var(--text-h);
        font-size: 20px;
        line-height: 1;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: var(--shadow-sm);
        transition: background .18s, color .18s, transform .18s;
    }
    .modal-close:hover {
        background: var(--navy);
        color: #fff;
        transform: scale(1.1);
    }

    /* Cursor hint on zoomable images */
    .card-img-wrap img.zoomable {
        cursor: zoom-in;
    }

    @keyframes modalFadeIn {
        from { opacity: 0; }
        to   { opacity: 1; }
    }
    @keyframes modalSlideUp {
        from { opacity: 0; transform: translateY(28px) scale(.97); }
        to   { opacity: 1; transform: translateY(0)    scale(1);   }
    }
</style>

<script>
    const modal    = document.getElementById('imgModal');
    const modalImg = document.getElementById('modalImg');
    const modalCap = document.getElementById('modalCaption');

    function openModal(src, title) {
        modalImg.src    = src;
        modalImg.alt    = title || '';
        modalCap.textContent = title || '';
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(e) {
        // If called from overlay click, only close when clicking the backdrop itself
        if (e && e.target !== modal) return;
        modal.classList.remove('active');
        document.body.style.overflow = '';
        // Small delay before clearing src so the fade-out looks clean
        setTimeout(() => { modalImg.src = ''; }, 250);
    }

    // Close on Escape key
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
</script>
</body>
</html>