<?php
session_start();
include 'includes/db.php';
include 'config.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

function generateItemDescriptionFromAI(string $prompt): string|false
{
    $prompt = trim($prompt);

    if ($prompt === '') {
        return false;
    }

    
    $apiKey = GEMINI_API_KEY;

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent';

    $payload = [
        'contents' => [
            [
                'parts' => [
                    [
                        'text' => 'Write a concise, natural marketplace item description in about 15 to 20 words based on this prompt: ' . $prompt
                    ]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        curl_close($ch);
        return false;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        return false;
    }

    $data = json_decode($response, true);

    return trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '') ?: false;
}

$success = "";
$error = "";

/* Preserve old values after submit */
$old_item_name   = "";
$old_category_id = "";
$old_description = "";
$old_condition   = "";
$old_ai_flag     = 0;
$old_ai_prompt   = "";

/* Handle form submission */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $owner_id    = $_SESSION['student_id'];
    $item_name   = trim($_POST['item_name'] ?? '');
    $category_id = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
    $description = trim($_POST['description'] ?? '');
    $condition   = trim($_POST['condition'] ?? '');
    $ai_flag     = isset($_POST['ai_flag']) ? 1 : 0;
    $ai_prompt   = trim($_POST['ai_prompt'] ?? '');

    /* Preserve form state */
    $old_item_name   = $item_name;
    $old_category_id = $category_id;
    $old_description = $description;
    $old_condition   = $condition;
    $old_ai_flag     = $ai_flag;
    $old_ai_prompt   = $ai_prompt;

    $final_description = "";
    $ai_used = 0;
    $db_ai_prompt = null;
    $uploaded_image_path = null;

    if ($item_name === "" || $category_id <= 0 || $condition === "") {
        $error = "Please fill all required fields.";
    } elseif (
        $description === "" &&
        !($ai_flag === 1 && $ai_prompt !== "")
    ) {
        $error = "Please provide a description or use the AI generator.";
    } elseif (!isset($_FILES['item_image']) || $_FILES['item_image']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = "Please upload an image.";
    } else {
        /* Description priority */
        if ($description !== "") {
            $final_description = $description;
            $ai_used = 0;
            $db_ai_prompt = null;
        } elseif ($ai_flag === 1 && $ai_prompt !== "") {
            $generated = generateItemDescriptionFromAI($ai_prompt);

            if ($generated === false || trim($generated) === "") {
                $error = "Failed to generate AI description.";
            } else {
                $final_description = trim($generated);
                $ai_used = 1;
                $db_ai_prompt = $ai_prompt;
            }
        }

        /* Secure image upload */
        if ($error === "") {
            $file = $_FILES['item_image'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = "Image upload failed.";
            } else {
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp'];
                $max_file_size = 5 * 1024 * 1024; // 5MB

                $original_name = $file['name'] ?? '';
                $tmp_path = $file['tmp_name'] ?? '';
                $file_size = (int)($file['size'] ?? 0);

                $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

                if (!in_array($extension, $allowed_extensions, true)) {
                    $error = "Only JPG, JPEG, PNG, and WEBP images are allowed.";
                } elseif ($file_size > $max_file_size) {
                    $error = "Image must be smaller than 5 MB.";
                } elseif (!is_uploaded_file($tmp_path)) {
                    $error = "Invalid uploaded file.";
                } else {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_file($finfo, $tmp_path);
                    finfo_close($finfo);

                    $allowed_mime_types = [
                        'jpg'  => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'png'  => 'image/png',
                        'webp' => 'image/webp'
                    ];

                    if (!isset($allowed_mime_types[$extension]) || $mime_type !== $allowed_mime_types[$extension]) {
                        $error = "Invalid image file type.";
                    } else {
                        $upload_dir = __DIR__ . '/uploads/items/';
                        $relative_dir = 'uploads/items/';

                        if (!is_dir($upload_dir)) {
                            if (!mkdir($upload_dir, 0777, true) && !is_dir($upload_dir)) {
                                $error = "Failed to create upload directory.";
                            }
                        }

                        if ($error === "") {
                            try {
                                $unique_name = 'item_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
                            } catch (Exception $e) {
                                $unique_name = 'item_' . time() . '_' . uniqid() . '.' . $extension;
                            }

                            $target_path = $upload_dir . $unique_name;
                            $uploaded_image_path = $relative_dir . $unique_name;

                            if (!move_uploaded_file($tmp_path, $target_path)) {
                                $error = "Failed to save uploaded image.";
                            }
                        }
                    }
                }
            }
        }

        /* Insert item + image */
        if ($error === "") {
            $date_listed = date("Y-m-d");
            $availability_status = "unavailable";

            $conn->begin_transaction();

            try {
                $stmt = $conn->prepare("
                    INSERT INTO item (
                        owner_id,
                        category_id,
                        item_name,
                        item_description,
                        item_condition,
                        availability_status,
                        date_listed,
                        ai_generated_description_flag,
                        ai_prompt_text
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$stmt) {
                    throw new Exception("Failed to prepare item insert statement.");
                }

                $stmt->bind_param(
                    "iisssssis",
                    $owner_id,
                    $category_id,
                    $item_name,
                    $final_description,
                    $condition,
                    $availability_status,
                    $date_listed,
                    $ai_used,
                    $db_ai_prompt
                );

                if (!$stmt->execute()) {
                    throw new Exception("Insert failed: " . $stmt->error);
                }

                $item_id = $stmt->insert_id;
                $stmt->close();

                $stmt2 = $conn->prepare("
                    INSERT INTO item_image (
                        item_id,
                        image_path,
                        upload_date,
                        is_primary
                    ) VALUES (?, ?, ?, TRUE)
                ");

                if (!$stmt2) {
                    throw new Exception("Failed to prepare image insert statement.");
                }

                $upload_date = date("Y-m-d");
                $stmt2->bind_param("iss", $item_id, $uploaded_image_path, $upload_date);

                if (!$stmt2->execute()) {
                    throw new Exception("Image insert failed: " . $stmt2->error);
                }

                $stmt2->close();

                $conn->commit();
                $success = "Item added successfully!";

                $old_item_name   = "";
                $old_category_id = "";
                $old_description = "";
                $old_condition   = "";
                $old_ai_flag     = 0;
                $old_ai_prompt   = "";
            } catch (Exception $e) {
                $conn->rollback();

                if ($uploaded_image_path !== null) {
                    $full_uploaded_path = __DIR__ . '/' . $uploaded_image_path;
                    if (file_exists($full_uploaded_path)) {
                        @unlink($full_uploaded_path);
                    }
                }

                $error = $e->getMessage();
            }
        }
    }
}

/* Fetch categories */
$categories = $conn->query("SELECT category_id, category_name FROM category ORDER BY category_name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Item | Campus Resource Sharing</title>
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
            --text-placeholder: rgba(139,147,196,0.65);
            --shadow-sm:    0 2px 12px rgba(79,110,247,0.07);
            --shadow-md:    0 8px 28px rgba(79,110,247,0.12);
            --shadow-lg:    0 16px 48px rgba(79,110,247,0.15);
            --success-bg:   #f0fdf4;
            --success-bdr:  #86efac;
            --success-text: #166534;
            --error-bg:     #fff1f2;
            --error-bdr:    #fda4af;
            --error-text:   #9f1239;
            --radius:       14px;
        }

        body {
            background: var(--bg);
            font-family: 'DM Sans', sans-serif;
            color: var(--text-body);
            min-height: 100vh;
        }

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
            width: 38px;
            height: 38px;
            background: linear-gradient(135deg, var(--accent), var(--gold));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
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
            transition: color 0.2s, border-color 0.2s, box-shadow 0.2s, transform 0.2s;
        }

        .nav-back:hover {
            color: var(--accent);
            border-color: rgba(79,110,247,0.35);
            box-shadow: 0 4px 14px rgba(79,110,247,0.1);
            transform: translateY(-1px);
        }

        .nav-back .chevron {
            font-size: 15px;
            transition: transform 0.2s;
        }

        .nav-back:hover .chevron {
            transform: translateX(-3px);
        }

        .page {
            max-width: 780px;
            margin: 0 auto;
            padding: 38px 22px 64px;
        }

        .page-header {
            margin-bottom: 28px;
            animation: fadeUp 0.5s cubic-bezier(0.16,1,0.3,1) both;
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
            width: 16px;
            height: 2px;
            background: var(--accent);
            border-radius: 2px;
        }

        .page-header h1 {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 28px;
            color: var(--text-h);
            letter-spacing: -0.5px;
            line-height: 1.2;
        }

        .page-header p {
            margin-top: 6px;
            font-size: 14px;
            color: var(--text-muted);
        }

        .alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            border-radius: var(--radius);
            margin-bottom: 22px;
            font-size: 13.5px;
            font-weight: 500;
            border: 1px solid;
            animation: slideDown 0.4s cubic-bezier(0.16,1,0.3,1) both;
        }

        .alert-icon {
            font-size: 18px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .alert-body strong {
            display: block;
            font-weight: 700;
            font-family: 'Syne', sans-serif;
            font-size: 13px;
            letter-spacing: 0.1px;
            margin-bottom: 2px;
        }

        .alert.success {
            background: var(--success-bg);
            border-color: var(--success-bdr);
            color: var(--success-text);
        }

        .alert.error {
            background: var(--error-bg);
            border-color: var(--error-bdr);
            color: var(--error-text);
        }

        .form-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 36px 38px 40px;
            box-shadow: var(--shadow-sm);
            animation: fadeUp 0.55s cubic-bezier(0.16,1,0.3,1) 0.05s both;
        }

        .form-section {
            margin-bottom: 30px;
        }

        .form-section:last-of-type {
            margin-bottom: 0;
        }

        .section-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            font-size: 12px;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 18px;
        }

        .section-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.7px;
            text-transform: uppercase;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        label .req {
            color: var(--accent);
            font-size: 14px;
            line-height: 1;
        }

        input[type="text"],
        input[type="file"],
        select,
        textarea {
            width: 100%;
            background: var(--bg);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            padding: 12px 16px;
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            color: var(--text-h);
            outline: none;
            transition: border-color 0.22s, box-shadow 0.22s, background 0.22s;
            appearance: none;
            -webkit-appearance: none;
        }

        input::placeholder,
        textarea::placeholder {
            color: var(--text-placeholder);
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: var(--border-focus);
            background: var(--surface);
            box-shadow: 0 0 0 3.5px rgba(79,110,247,0.13);
        }

        input[type="file"] {
            padding: 11px 14px;
            background: var(--surface);
        }

        textarea {
            resize: vertical;
            min-height: 110px;
            line-height: 1.65;
        }

        .select-wrap {
            position: relative;
        }

        .select-wrap::after {
            content: '▾';
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 14px;
            pointer-events: none;
        }

        .select-wrap select {
            padding-right: 36px;
        }

        .field-hint {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-top: -2px;
        }

        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 18px;
            cursor: pointer;
            transition: border-color 0.22s, box-shadow 0.22s;
            user-select: none;
        }

        .toggle-row:hover {
            border-color: rgba(79,110,247,0.35);
            box-shadow: 0 0 0 3px rgba(79,110,247,0.07);
        }

        .toggle-row.active {
            border-color: var(--accent);
            background: var(--accent-soft);
            box-shadow: 0 0 0 3.5px rgba(79,110,247,0.1);
        }

        .toggle-info strong {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-h);
            font-family: 'Syne', sans-serif;
        }

        .toggle-info span {
            font-size: 12.5px;
            color: var(--text-muted);
        }

        .switch {
            position: relative;
            width: 46px;
            height: 26px;
            flex-shrink: 0;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
            position: absolute;
        }

        .switch-track {
            position: absolute;
            inset: 0;
            background: #d1d9f5;
            border-radius: 999px;
            transition: background 0.25s;
        }

        .switch-knob {
            position: absolute;
            top: 3px;
            left: 3px;
            width: 20px;
            height: 20px;
            background: #fff;
            border-radius: 50%;
            box-shadow: 0 1px 4px rgba(0,0,0,0.18);
            transition: transform 0.25s cubic-bezier(0.34,1.56,0.64,1);
        }

        .switch input:checked ~ .switch-track {
            background: var(--accent);
        }

        .switch input:checked ~ .switch-knob {
            transform: translateX(20px);
        }

        .ai-prompt-wrap {
            display: grid;
            grid-template-rows: 0fr;
            transition: grid-template-rows 0.4s cubic-bezier(0.16,1,0.3,1);
            margin-top: 0;
        }

        .ai-prompt-wrap.open {
            grid-template-rows: 1fr;
            margin-top: 14px;
        }

        .ai-prompt-inner {
            overflow: hidden;
        }

        .ai-prompt-inner .field {
            padding-top: 2px;
        }

        .ai-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: linear-gradient(90deg, var(--accent-soft), #fff);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: var(--accent-dark);
            margin-bottom: 8px;
        }

        .ai-chip::before {
            content: '✦';
            font-size: 10px;
            color: var(--gold);
        }

        .divider {
            height: 1px;
            background: var(--border);
            margin: 28px 0;
        }

        .btn-submit {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%);
            color: #fff;
            border: none;
            border-radius: var(--radius);
            font-family: 'Syne', sans-serif;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.4px;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: transform 0.22s cubic-bezier(0.34,1.56,0.64,1), box-shadow 0.22s;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.14), transparent);
            opacity: 0;
            transition: opacity 0.22s;
        }

        .btn-submit:hover {
            transform: scale(1.025) translateY(-2px);
            box-shadow: 0 14px 36px rgba(79,110,247,0.38);
        }

        .btn-submit:hover::before {
            opacity: 1;
        }

        .btn-submit:active {
            transform: scale(0.99);
        }

        .btn-submit .btn-icon {
            margin-right: 8px;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 600px) {
            .navbar {
                padding: 0 18px;
            }

            .page {
                padding: 24px 14px 52px;
            }

            .form-card {
                padding: 24px 20px 28px;
            }

            .field-grid {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: unset;
            }

            .page-header h1 {
                font-size: 23px;
            }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <a class="nav-brand" href="dashboard.php">
        <div class="icon">🎓</div>
        <span>CampusShare</span>
    </a>
    <a href="dashboard.php" class="nav-back">
        <span class="chevron">←</span> Back to Dashboard
    </a>
</nav>

<div class="page">
    <div class="page-header">
        <p class="eyebrow">Resource Management</p>
        <h1>List a New Item</h1>
        <p>Fill in the details below to share a resource with your campus community.</p>
    </div>

    <?php if ($error): ?>
    <div class="alert error">
        <span class="alert-icon">⚠️</span>
        <div class="alert-body">
            <strong>Something went wrong</strong>
            <?php echo htmlspecialchars($error); ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert success">
        <span class="alert-icon">✅</span>
        <div class="alert-body">
            <strong>Item listed successfully!</strong>
            <?php echo htmlspecialchars($success); ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="form-card">
        <form method="POST" id="addItemForm" enctype="multipart/form-data">

            <div class="form-section">
                <div class="section-label">Basic Information</div>

                <div class="field-grid">
                    <div class="field full">
                        <label for="item_name">Item Name <span class="req">*</span></label>
                        <input
                            type="text"
                            id="item_name"
                            name="item_name"
                            placeholder="e.g. Organic Chemistry 101 Textbook"
                            required
                            value="<?php echo htmlspecialchars($old_item_name); ?>"
                        >
                    </div>

                    <div class="field">
                        <label for="category_id">Category <span class="req">*</span></label>
                        <div class="select-wrap">
                            <select id="category_id" name="category_id" required>
                                <option value="">Select a category…</option>
                                <?php if ($categories): ?>
                                    <?php while ($row = $categories->fetch_assoc()): ?>
                                        <option
                                            value="<?php echo (int)$row['category_id']; ?>"
                                            <?php echo ((string)$old_category_id === (string)$row['category_id']) ? 'selected' : ''; ?>
                                        >
                                            <?php echo htmlspecialchars($row['category_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <div class="field">
                        <label for="condition">Condition <span class="req">*</span></label>
                        <div class="select-wrap">
                            <select id="condition" name="condition" required>
                                <option value="">Select condition…</option>
                                <option value="new" <?php echo $old_condition === 'new' ? 'selected' : ''; ?>>✨ New</option>
                                <option value="like_new" <?php echo $old_condition === 'like_new' ? 'selected' : ''; ?>>🌟 Like New</option>
                                <option value="good" <?php echo $old_condition === 'good' ? 'selected' : ''; ?>>👍 Good</option>
                                <option value="fair" <?php echo $old_condition === 'fair' ? 'selected' : ''; ?>>👌 Fair</option>
                                <option value="poor" <?php echo $old_condition === 'poor' ? 'selected' : ''; ?>>🛠 Poor</option>
                            </select>
                        </div>
                    </div>

                    <div class="field full">
                        <label for="item_image">Item Image <span class="req">*</span></label>
                        <input
                            type="file"
                            id="item_image"
                            name="item_image"
                            accept="image/*"
                            required
                        >
                        <div class="field-hint">Allowed: JPG, JPEG, PNG, WEBP. Maximum size: 5 MB.</div>
                    </div>
                </div>
            </div>

            <div class="divider"></div>

            <div class="form-section">
                <div class="section-label">Description</div>

                <div class="field">
                    <label for="description">Manual Description</label>
                    <textarea
                        id="description"
                        name="description"
                        placeholder="Describe the item, its features, condition details, and anything borrowers should know..."
                    ><?php echo htmlspecialchars($old_description); ?></textarea>
                    <div class="field-hint">If you write a description here, it will be used first.</div>
                </div>

                <div style="margin-top: 18px;">
                    <label class="toggle-row <?php echo $old_ai_flag ? 'active' : ''; ?>" id="aiToggleRow">
                        <div class="toggle-info">
                            <strong>Use AI Description Generator</strong>
                            <span>If manual description is empty, AI can generate one from your prompt.</span>
                        </div>

                        <div class="switch">
                            <input
                                type="checkbox"
                                id="ai_flag"
                                name="ai_flag"
                                <?php echo $old_ai_flag ? 'checked' : ''; ?>
                            >
                            <span class="switch-track"></span>
                            <span class="switch-knob"></span>
                        </div>
                    </label>

                    <div class="ai-prompt-wrap <?php echo $old_ai_flag ? 'open' : ''; ?>" id="aiPromptWrap">
                        <div class="ai-prompt-inner">
                            <div class="field">
                                <span class="ai-chip">AI Prompt</span>
                                <label for="ai_prompt">Prompt for AI</label>
                                <textarea
                                    id="ai_prompt"
                                    name="ai_prompt"
                                    placeholder="e.g. A lightly used scientific calculator suitable for university engineering students, fully functional and easy to carry."
                                ><?php echo htmlspecialchars($old_ai_prompt); ?></textarea>
                                <div class="field-hint">Used only when manual description is empty.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="divider"></div>

            <button type="submit" class="btn-submit">
                <span class="btn-icon">➕</span> Add Item
            </button>
        </form>
    </div>
</div>

<script>
    const aiCheckbox = document.getElementById('ai_flag');
    const aiPromptWrap = document.getElementById('aiPromptWrap');
    const aiToggleRow = document.getElementById('aiToggleRow');

    function syncAiUI() {
        if (aiCheckbox.checked) {
            aiPromptWrap.classList.add('open');
            aiToggleRow.classList.add('active');
        } else {
            aiPromptWrap.classList.remove('open');
            aiToggleRow.classList.remove('active');
        }
    }

    aiCheckbox.addEventListener('change', syncAiUI);
    syncAiUI();
</script>

</body>
</html>