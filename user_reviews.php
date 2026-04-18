<?php
session_start();
include 'includes/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
    die("Invalid user.");
}

$user_id = (int) $_GET['user_id'];

/* -------------------------------------------------
   FETCH USER BASIC INFO
------------------------------------------------- */
$user_stmt = $conn->prepare("
    SELECT student_id, full_name, university_email, department
    FROM student
    WHERE student_id = ?
");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();

if ($user_result->num_rows !== 1) {
    die("User not found.");
}

$user = $user_result->fetch_assoc();
$user_stmt->close();

/* -------------------------------------------------
   FETCH RATING SUMMARY
------------------------------------------------- */
$summary_stmt = $conn->prepare("
    SELECT 
        COUNT(*) AS total_reviews,
        ROUND(AVG(rating), 1) AS avg_rating
    FROM review
    WHERE reviewee_id = ?
");
$summary_stmt->bind_param("i", $user_id);
$summary_stmt->execute();
$summary_result = $summary_stmt->get_result();
$summary = $summary_result->fetch_assoc();
$summary_stmt->close();

$total_reviews = (int) ($summary['total_reviews'] ?? 0);
$avg_rating    = $summary['avg_rating'] ?? null;

/* -------------------------------------------------
   FETCH RECEIVED REVIEWS
------------------------------------------------- */
$reviews_stmt = $conn->prepare("
    SELECT
        r.review_id,
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
$reviews_stmt->bind_param("i", $user_id);
$reviews_stmt->execute();
$reviews_result = $reviews_stmt->get_result();

/* pre-compute rating distribution */
$dist = [5=>0, 4=>0, 3=>0, 2=>0, 1=>0];
$all_reviews = [];
while ($r = $reviews_result->fetch_assoc()) {
    $dist[(int)$r['rating']]++;
    $all_reviews[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Reviews | Campus Resource Sharing</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

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
            --shadow-md:   0 10px 32px rgba(79,110,247,0.13);
            --shadow-lg:   0 18px 52px rgba(79,110,247,0.17);
            --star-fill:   #f59e0b;
            --star-empty:  #d1d9f5;
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 36px 24px 80px;
        }

        /* ─── Hero banner ─── */
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
            width: 380px; height: 380px;
            background: radial-gradient(circle, rgba(79,110,247,.32) 0%, transparent 70%);
            border-radius: 50%;
            top: -120px; right: -60px;
            filter: blur(60px);
            pointer-events: none;
        }
        .hero-inner {
            position: relative; z-index: 1;
            display: flex; align-items: center;
            justify-content: space-between; gap: 24px; flex-wrap: wrap;
        }
        .hero-left .eyebrow {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 11px; font-weight: 600;
            letter-spacing: 1.8px; text-transform: uppercase;
            color: var(--gold); margin-bottom: 10px;
        }
        .hero-left .eyebrow::before {
            content: ''; width: 16px; height: 2px;
            background: var(--gold); border-radius: 2px;
        }
        .hero-left h1 {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: clamp(22px,3vw,34px);
            color: #eef0ff; letter-spacing: -.5px; line-height: 1.2;
            margin-bottom: 8px;
        }
        .hero-left p {
            font-size: 14px; color: #8b93c4;
            line-height: 1.72; max-width: 520px;
        }
        .hero-actions {
            display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px;
        }
        .ha-btn {
            display: inline-flex; align-items: center; gap: 7px;
            text-decoration: none; padding: 9px 18px; border-radius: 11px;
            font-family: 'Syne', sans-serif; font-size: 13px; font-weight: 700;
            transition: transform .2s, box-shadow .2s, background .2s;
        }
        .ha-btn.white {
            background: #fff; color: var(--accent-dark);
        }
        .ha-btn.white:hover { background: var(--accent-soft); transform: translateY(-1px); }
        .ha-btn.ghost {
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.18);
            color: #eef0ff;
        }
        .ha-btn.ghost:hover { background: rgba(255,255,255,.18); transform: translateY(-1px); }

        /* ─── Profile + rating card ─── */
        .profile-strip {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 22px;
            margin-bottom: 28px;
            animation: fadeUp .5s cubic-bezier(.16,1,.3,1) .06s both;
        }

        /* Avatar + info */
        .profile-info-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 22px; padding: 28px 32px;
            box-shadow: var(--shadow-sm);
            display: flex; align-items: center; gap: 24px;
        }
        .avatar-ring {
            width: 72px; height: 72px; flex-shrink: 0;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--gold));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: 28px; color: #fff;
            box-shadow: 0 0 0 5px rgba(79,110,247,.13), 0 0 24px rgba(79,110,247,.22);
            letter-spacing: -.5px;
        }
        .profile-details .name {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: 22px;
            color: var(--text-h); letter-spacing: -.3px; margin-bottom: 8px;
        }
        .profile-meta-row {
            display: flex; flex-wrap: wrap; gap: 10px;
        }
        .meta-chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--bg); border: 1px solid var(--border);
            border-radius: 999px; padding: 5px 12px;
            font-size: 12.5px; color: var(--text-body);
        }
        .meta-chip .mc-icon { font-size: 13px; }

        /* Rating summary card */
        .rating-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 22px; padding: 26px 28px;
            box-shadow: var(--shadow-sm);
            min-width: 270px;
            display: flex; flex-direction: column; gap: 16px;
        }
        .rating-top {
            display: flex; align-items: center; gap: 18px;
        }
        .big-score {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: 52px;
            color: var(--text-h); line-height: 1;
            letter-spacing: -2px;
        }
        .big-score .denom {
            font-size: 20px; color: var(--text-muted);
            font-weight: 500; letter-spacing: 0;
        }
        .rating-right .stars-large {
            display: flex; gap: 3px; font-size: 22px; margin-bottom: 6px;
        }
        .s-fill  { color: var(--star-fill); }
        .s-empty { color: var(--star-empty); }
        .rating-right .rev-count {
            font-size: 12.5px; color: var(--text-muted); font-weight: 500;
        }

        /* distribution bars */
        .dist-bars { display: flex; flex-direction: column; gap: 6px; }
        .dist-row {
            display: flex; align-items: center; gap: 9px;
        }
        .dist-label {
            font-size: 11.5px; font-weight: 600;
            color: var(--text-muted); width: 14px; text-align: right;
            flex-shrink: 0;
        }
        .dist-track {
            flex: 1; height: 7px; background: var(--bg);
            border-radius: 999px; overflow: hidden;
            border: 1px solid var(--border);
        }
        .dist-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent), var(--accent-dark));
            border-radius: 999px;
            transition: width .8s cubic-bezier(.16,1,.3,1);
        }
        .dist-count {
            font-size: 11px; color: var(--text-muted);
            width: 16px; text-align: right; flex-shrink: 0;
        }

        /* ─── Section heading ─── */
        .section-heading {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 22px;
            animation: fadeUp .5s cubic-bezier(.16,1,.3,1) .1s both;
        }
        .section-heading .s-icon {
            width: 36px; height: 36px; border-radius: 10px;
            background: var(--accent-soft); border: 1px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            font-size: 17px; flex-shrink: 0;
        }
        .section-heading h2 {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: 20px;
            color: var(--text-h); letter-spacing: -.3px;
        }
        .section-heading .count-pill {
            font-size: 11.5px; font-weight: 700;
            padding: 4px 11px; border-radius: 999px;
            background: var(--accent-soft); border: 1px solid var(--border);
            color: var(--accent-dark);
        }
        .divider-line { flex: 1; height: 1px; background: var(--border); }

        /* ─── Reviews grid ─── */
        .reviews-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            animation: fadeUp .5s cubic-bezier(.16,1,.3,1) .12s both;
        }

        .review-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px; padding: 22px 24px;
            box-shadow: var(--shadow-sm);
            display: flex; flex-direction: column; gap: 12px;
            transition: transform .25s cubic-bezier(.34,1.56,.64,1), box-shadow .25s, border-color .25s;
        }
        .review-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
            border-color: rgba(79,110,247,.2);
        }

        .rc-top {
            display: flex; justify-content: space-between;
            align-items: flex-start; gap: 10px;
        }
        .rc-item {
            font-family: 'Syne', sans-serif; font-weight: 700;
            font-size: 15.5px; color: var(--text-h);
            letter-spacing: -.2px; line-height: 1.3;
        }
        .rc-date {
            font-size: 11.5px; color: var(--text-muted);
            white-space: nowrap; padding-top: 2px; flex-shrink: 0;
        }

        .rc-reviewer {
            display: flex; align-items: center; gap: 8px;
            font-size: 12.5px; color: var(--text-muted);
        }
        .reviewer-avatar {
            width: 26px; height: 26px; border-radius: 50%;
            background: linear-gradient(135deg, var(--accent-soft), var(--border));
            border: 1px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 700; color: var(--accent-dark);
            flex-shrink: 0;
        }
        .rc-reviewer strong { color: var(--text-body); font-weight: 600; }

        .rc-stars {
            display: flex; gap: 3px; font-size: 18px;
        }
        .rc-rating-badge {
            display: inline-flex; align-items: center; gap: 5px;
        }
        .rc-rating-badge .score {
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: 13px; color: var(--text-h);
        }

        .rc-comment {
            background: var(--bg);
            border-left: 3px solid var(--accent);
            border-radius: 11px; padding: 12px 15px;
            font-size: 13px; color: var(--text-body);
            line-height: 1.65; margin-top: auto;
        }
        .no-comment { color: var(--text-muted); font-style: italic; }

        /* ─── Empty state ─── */
        .empty-box {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 22px; padding: 64px 28px;
            text-align: center; box-shadow: var(--shadow-sm);
            animation: fadeUp .5s cubic-bezier(.16,1,.3,1) .12s both;
        }
        .empty-box .e-icon { font-size: 50px; opacity: .4; margin-bottom: 16px; display: block; }
        .empty-box h3 {
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: 20px; color: var(--text-h); margin-bottom: 8px;
        }
        .empty-box p { font-size: 14px; color: var(--text-muted); max-width: 320px; margin: 0 auto; line-height: 1.65; }

        /* ─── Animations ─── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* stagger cards */
        .review-card:nth-child(1) { animation: cardUp .45s cubic-bezier(.16,1,.3,1) .05s both; }
        .review-card:nth-child(2) { animation: cardUp .45s cubic-bezier(.16,1,.3,1) .10s both; }
        .review-card:nth-child(3) { animation: cardUp .45s cubic-bezier(.16,1,.3,1) .14s both; }
        .review-card:nth-child(4) { animation: cardUp .45s cubic-bezier(.16,1,.3,1) .18s both; }
        .review-card:nth-child(n+5){ animation: cardUp .45s cubic-bezier(.16,1,.3,1) .22s both; }
        @keyframes cardUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ─── Responsive ─── */
        @media (max-width: 900px) {
            .profile-strip { grid-template-columns: 1fr; }
            .rating-card { min-width: unset; }
        }
        @media (max-width: 640px) {
            .navbar { padding: 0 18px; }
            .page { padding: 22px 14px 60px; }
            .hero { padding: 28px 22px; }
            .profile-info-card { flex-direction: column; align-items: flex-start; }
            .avatar-ring { width: 56px; height: 56px; font-size: 22px; }
        }
        @media (max-width: 480px) {
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
        <a href="review.php" class="nav-btn">⭐ My Reviews</a>
        <a href="dashboard.php" class="nav-btn solid">← Dashboard</a>
    </div>
</nav>

<!-- ════════════ PAGE ════════════ -->
<div class="page">

    <!-- Hero -->
    <div class="hero">
        <div class="hero-inner">
            <div class="hero-left">
                <p class="eyebrow">Public Profile</p>
                <h1>User Reviews</h1>
                <p>Public feedback received by this user based on completed borrowing transactions across the CampusShare network.</p>
                <div class="hero-actions">
                    <a href="dashboard.php" class="ha-btn white">← Dashboard</a>
                    <a href="review.php"    class="ha-btn ghost">⭐ My Review Centre</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Profile + rating strip -->
    <div class="profile-strip">

        <!-- Left: avatar + info -->
        <div class="profile-info-card">
            <div class="avatar-ring">
                <?php echo mb_strtoupper(mb_substr($user['full_name'], 0, 1)); ?>
            </div>
            <div class="profile-details">
                <div class="name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                <div class="profile-meta-row">
                    <span class="meta-chip">
                        <span class="mc-icon">✉️</span>
                        <?php echo htmlspecialchars($user['university_email']); ?>
                    </span>
                    <span class="meta-chip">
                        <span class="mc-icon">🏛️</span>
                        <?php echo htmlspecialchars($user['department']); ?>
                    </span>
                    <span class="meta-chip">
                        <span class="mc-icon">🆔</span>
                        Student #<?php echo (int)$user['student_id']; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Right: rating summary -->
        <div class="rating-card">
            <div class="rating-top">
                <div class="big-score">
                    <?php if ($total_reviews > 0): ?>
                        <?php echo htmlspecialchars($avg_rating); ?>
                        <span class="denom">/ 5</span>
                    <?php else: ?>
                        <span style="font-size:28px;color:var(--text-muted);">—</span>
                    <?php endif; ?>
                </div>
                <div class="rating-right">
                    <div class="stars-large">
                        <?php
                        $rounded = $total_reviews > 0 ? (int) round((float)$avg_rating) : 0;
                        for ($s = 1; $s <= 5; $s++):
                        ?>
                            <span class="<?php echo $s <= $rounded ? 's-fill' : 's-empty'; ?>">★</span>
                        <?php endfor; ?>
                    </div>
                    <div class="rev-count">
                        <?php echo $total_reviews; ?> review<?php echo $total_reviews !== 1 ? 's' : ''; ?> received
                    </div>
                </div>
            </div>

            <!-- Distribution bars -->
            <div class="dist-bars">
                <?php for ($star = 5; $star >= 1; $star--):
                    $count = $dist[$star];
                    $pct   = $total_reviews > 0 ? round(($count / $total_reviews) * 100) : 0;
                ?>
                <div class="dist-row">
                    <span class="dist-label"><?php echo $star; ?></span>
                    <div class="dist-track">
                        <div class="dist-fill" style="width:<?php echo $pct; ?>%"></div>
                    </div>
                    <span class="dist-count"><?php echo $count; ?></span>
                </div>
                <?php endfor; ?>
            </div>
        </div>

    </div><!-- /.profile-strip -->

    <!-- Section heading -->
    <div class="section-heading">
        <div class="s-icon">⭐</div>
        <h2>Received Reviews</h2>
        <span class="count-pill"><?php echo count($all_reviews); ?> total</span>
        <div class="divider-line"></div>
    </div>

    <!-- Reviews -->
    <?php if (!empty($all_reviews)): ?>
        <div class="reviews-grid">
            <?php foreach ($all_reviews as $row):
                $rating  = (int)$row['rating'];
                $initial = mb_strtoupper(mb_substr($row['reviewer_name'], 0, 1));
            ?>
            <div class="review-card">

                <div class="rc-top">
                    <div class="rc-item"><?php echo htmlspecialchars($row['item_name']); ?></div>
                    <div class="rc-date"><?php echo htmlspecialchars($row['review_date']); ?></div>
                </div>

                <div class="rc-reviewer">
                    <div class="reviewer-avatar"><?php echo $initial; ?></div>
                    <span>Reviewed by <strong><?php echo htmlspecialchars($row['reviewer_name']); ?></strong></span>
                </div>

                <div class="rc-rating-badge">
                    <div class="rc-stars">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <span class="<?php echo $s <= $rating ? 's-fill' : 's-empty'; ?>">★</span>
                        <?php endfor; ?>
                    </div>
                    <span class="score">&nbsp;<?php echo $rating; ?>/5</span>
                </div>

                <div class="rc-comment">
                    <?php if (!empty($row['comment'])): ?>
                        <?php echo nl2br(htmlspecialchars($row['comment'])); ?>
                    <?php else: ?>
                        <span class="no-comment">No comment provided.</span>
                    <?php endif; ?>
                </div>

            </div>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <div class="empty-box">
            <span class="e-icon">💬</span>
            <h3>No reviews yet</h3>
            <p>This user hasn't received any reviews from completed transactions yet.</p>
        </div>
    <?php endif; ?>

</div><!-- /.page -->

</body>
</html>