<?php
session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$message = '';

// Handle category actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_category') {
        $name = $_POST['category_name'];
        $stmt = $db->prepare("INSERT INTO faq_categories (name) VALUES (?)");
        $stmt->execute([$name]);
        $message = "Category added successfully!";
    } elseif ($_POST['action'] === 'delete_category') {
        $id = $_POST['category_id'];
        $stmt = $db->prepare("DELETE FROM faq_categories WHERE id = ?");
        $stmt->execute([$id]);
        $message = "Category deleted successfully!";
    } elseif ($_POST['action'] === 'add_faq') {
        $category_id = $_POST['category_id'];
        $question = $_POST['question'];
        $answer_type = $_POST['answer_type'];
        $answer_text = $_POST['answer_text'] ?? null;
        $video_url = $_POST['video_url'] ?? null;
        $video_file = null;

        if ($answer_type === 'video_upload' && isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/faqs/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $filename = time() . '_' . basename($_FILES['video_file']['name']);
            $target_path = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['video_file']['tmp_name'], $target_path)) {
                $video_file = 'uploads/faqs/' . $filename;
            }
        }

        $stmt = $db->prepare("INSERT INTO faqs (category_id, question, answer_type, answer_text, video_url, video_file) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$category_id, $question, $answer_type, $answer_text, $video_url, $video_file]);
        $message = "FAQ added successfully!";
    } elseif ($_POST['action'] === 'delete_faq') {
        $id = $_POST['faq_id'];
        $stmt = $db->prepare("DELETE FROM faqs WHERE id = ?");
        $stmt->execute([$id]);
        $message = "FAQ deleted successfully!";
    }
}

// Fetch categories
$categories = $db->query("SELECT * FROM faq_categories ORDER BY order_num ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch FAQs
$faqs_query = $db->query("
    SELECT f.*, c.name as category_name 
    FROM faqs f 
    JOIN faq_categories c ON f.category_id = c.id 
    ORDER BY c.order_num ASC, f.order_num ASC
");
$faqs = $faqs_query->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage FAQs | Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --admin-dark: #1B3C53;
            --admin-primary: #234C6A;
            --admin-secondary: #456882;
            --admin-soft: #D2C1B6;
            --admin-muted: #6b7b88;
        }

        .admin-theme-bg {
            background:
                radial-gradient(circle at 10% 10%, rgba(210,193,182,.24), transparent 25%),
                radial-gradient(circle at 86% 12%, rgba(69,104,130,.12), transparent 28%),
                linear-gradient(135deg, #f8fafc 0%, #f4f1ee 48%, #eef3f6 100%);
            min-height: 100vh;
            color: #17202a;
        }

        .admin-main { padding: 0; background: transparent; }

        .admin-topbar {
            position: sticky;
            top: 0;
            z-index: 30;
            background: rgba(255,255,255,.92);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(210,193,182,.45);
            padding: 1.05rem 1.6rem;
            box-shadow: 0 8px 24px rgba(27,60,83,.06);
            margin-bottom: 1.5rem;
        }

        .admin-main > .grid,
        .admin-main > .admin-card,
        .admin-main > .admin-alert-success,
        .admin-main > .admin-alert-danger {
            margin-left: 1.6rem;
            margin-right: 1.6rem;
        }

        .admin-page-icon {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            color: #fff;
            background: linear-gradient(135deg, var(--admin-dark), var(--admin-primary), var(--admin-secondary));
            box-shadow: 0 10px 24px rgba(27,60,83,.20);
        }

        .admin-page-title {
            font-size: 1.55rem;
            line-height: 1.1;
            font-weight: 900;
            letter-spacing: -0.03em;
            color: var(--admin-dark);
        }

        .admin-page-subtitle {
            color: var(--admin-muted);
            font-size: .86rem;
            margin-top: .18rem;
        }

        .admin-card {
            position: relative;
            overflow: hidden;
            background: rgba(255,255,255,.94);
            border: 1px solid rgba(210,193,182,.42);
            border-radius: 18px;
            box-shadow: 0 12px 32px rgba(27,60,83,.08);
        }

        .admin-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: linear-gradient(90deg, var(--admin-dark), var(--admin-primary), var(--admin-secondary));
        }

        .admin-section-title {
            display: flex;
            align-items: center;
            gap: .6rem;
            font-size: 1.05rem;
            font-weight: 900;
            color: var(--admin-dark);
            letter-spacing: -0.01em;
        }

        .admin-section-title i {
            width: 30px;
            height: 30px;
            border-radius: 10px;
            display: inline-grid;
            place-items: center;
            color: #fff;
            font-size: .8rem;
            background: linear-gradient(135deg, var(--admin-dark), var(--admin-secondary));
        }

        .admin-input {
            background: rgba(255,255,255,.92);
            border-color: rgba(69,104,130,.25) !important;
            transition: all .18s ease;
        }

        .admin-input:focus {
            border-color: var(--admin-secondary) !important;
            box-shadow: 0 0 0 4px rgba(69,104,130,.12);
        }

        .admin-primary-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            color: #fff;
            background: linear-gradient(135deg, var(--admin-dark), var(--admin-primary), var(--admin-secondary));
            box-shadow: 0 10px 22px rgba(27,60,83,.18);
            transition: transform .18s ease, box-shadow .18s ease, opacity .18s ease;
        }

        .admin-primary-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 28px rgba(27,60,83,.22);
            opacity: .96;
        }

        .admin-list-item,
        .admin-category-block,
        .admin-faq-item {
            background: rgba(248,250,252,.82);
            border: 1px solid rgba(210,193,182,.45);
            border-radius: 14px;
            transition: all .18s ease;
        }

        .admin-list-item:hover,
        .admin-faq-item:hover {
            background: rgba(255,255,255,.95);
            box-shadow: 0 10px 22px rgba(27,60,83,.08);
            transform: translateY(-1px);
        }

        .admin-category-block { background: rgba(255,255,255,.70); }

        .admin-delete-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            color: #dc2626;
            background: rgba(254,226,226,.75);
            transition: all .18s ease;
        }

        .admin-delete-btn:hover {
            background: #fee2e2;
            transform: translateY(-1px);
        }

        .admin-format-pill {
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 800;
            color: var(--admin-dark);
            background: rgba(210,193,182,.32);
            border: 1px solid rgba(210,193,182,.55);
        }

        .admin-alert-success {
            color: #166534;
            background: rgba(220,252,231,.88);
            border: 1px solid rgba(22,163,74,.22);
            box-shadow: 0 10px 22px rgba(22,163,74,.07);
        }

        .admin-alert-danger {
            color: #b91c1c;
            background: rgba(254,242,242,.88);
            border: 1px solid rgba(239,68,68,.22);
        }

        label {
            color: var(--admin-dark) !important;
            font-weight: 700 !important;
        }

        h3 { color: var(--admin-dark); }

        @media (max-width: 768px) {
            .admin-topbar { padding: 1rem; }

            .admin-main > .grid,
            .admin-main > .admin-card,
            .admin-main > .admin-alert-success,
            .admin-main > .admin-alert-danger {
                margin-left: 1rem;
                margin-right: 1rem;
            }

            .admin-page-title { font-size: 1.28rem; }
            .admin-card { border-radius: 16px; }
            .grid.grid-cols-2 { grid-template-columns: 1fr; }
        }
    </style>

</head>
<body class="admin-theme-bg flex">
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 overflow-y-auto h-screen admin-main">
        <header class="admin-topbar">
            <div class="flex items-center gap-3">
                <div class="admin-page-icon">
                    <i class="fas fa-question-circle"></i>
                </div>
                <div>
                    <h1 class="admin-page-title">Manage FAQs</h1>
                    <p class="admin-page-subtitle">Create, organize, and manage FAQ content</p>
                </div>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="admin-alert-success px-4 py-3 rounded-xl relative mb-6">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Categories -->
            <div class="admin-card p-6">
                <h2 class="admin-section-title mb-4"><i class="fas fa-folder-tree"></i> FAQ Categories</h2>
                
                <form method="POST" class="mb-6 flex gap-2">
                    <input type="hidden" name="action" value="add_category">
                    <input type="text" name="category_name" required placeholder="New Category Name" class="admin-input flex-1 border rounded-xl px-3 py-2 outline-none focus:border-[#456882] focus:ring-4 focus:ring-[#456882]/10">
                    <button type="submit" class="admin-primary-btn px-4 py-2">Add</button>
                </form>

                <ul class="space-y-2">
                    <?php foreach ($categories as $cat): ?>
                        <li class="admin-list-item flex justify-between items-center p-3">
                            <span class="font-medium text-gray-700"><?= htmlspecialchars($cat['name']) ?></span>
                            <form method="POST" onsubmit="return confirm('Delete category? All its FAQs will also be deleted.');">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                <button type="submit" class="admin-delete-btn p-1.5"><i class="fas fa-trash"></i></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Add/Manage FAQs -->
            <div class="lg:col-span-2 admin-card p-6">
                <h2 class="admin-section-title mb-4"><i class="fas fa-plus-circle"></i> Add New FAQ</h2>
                
                <?php if(empty($categories)): ?>
                    <p class="admin-alert-danger p-3 rounded-xl">Please add a category first.</p>
                <?php else: ?>
                    <form method="POST" enctype="multipart/form-data" class="space-y-4">
                        <input type="hidden" name="action" value="add_faq">
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                                <select name="category_id" required class="admin-input w-full border rounded-xl px-3 py-2 outline-none focus:border-[#456882] focus:ring-4 focus:ring-[#456882]/10">
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Answer Type</label>
                                <select name="answer_type" id="answer_type" onchange="toggleAnswerFields()" required class="admin-input w-full border rounded-xl px-3 py-2 outline-none focus:border-[#456882] focus:ring-4 focus:ring-[#456882]/10">
                                    <option value="text">Text Answer</option>
                                    <option value="video_link">YouTube / External Video Link</option>
                                    <option value="video_upload">Upload Video File (.mp4)</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Question</label>
                            <input type="text" name="question" required class="admin-input w-full border rounded-xl px-3 py-2 outline-none focus:border-[#456882] focus:ring-4 focus:ring-[#456882]/10 placeholder-gray-400" placeholder="e.g., How do I access my course materials?">
                        </div>

                        <div id="field_text">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Text Answer</label>
                            <textarea name="answer_text" rows="4" class="admin-input w-full border rounded-xl px-3 py-2 outline-none focus:border-[#456882] focus:ring-4 focus:ring-[#456882]/10" placeholder="Type the answer here..."></textarea>
                        </div>

                        <div id="field_video_link" class="hidden">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Video Link (e.g., YouTube embed URL)</label>
                            <input type="url" name="video_url" class="admin-input w-full border rounded-xl px-3 py-2 outline-none focus:border-[#456882] focus:ring-4 focus:ring-[#456882]/10" placeholder="https://www.youtube.com/embed/...">
                        </div>

                        <div id="field_video_upload" class="hidden">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Upload Video File (Max limit depends on php.ini)</label>
                            <input type="file" name="video_file" accept="video/mp4,video/webm" class="admin-input w-full border rounded-xl px-3 py-2 outline-none focus:border-[#456882] focus:ring-4 focus:ring-[#456882]/10">
                        </div>

                        <button type="submit" class="admin-primary-btn w-full px-6 py-2.5 font-bold">Save FAQ</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-8 admin-card p-6">
            <h2 class="admin-section-title mb-4"><i class="fas fa-list-check"></i> Existing FAQs</h2>
            <div class="space-y-6">
                <?php foreach ($categories as $cat): ?>
                    <div class="admin-category-block p-4">
                        <h3 class="font-bold text-lg text-[#1B3C53] mb-3 flex items-center"><i class="fas fa-folder text-[#456882] mr-2"></i><?= htmlspecialchars($cat['name']) ?></h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php $hasFaqs = false; ?>
                            <?php foreach ($faqs as $faq): ?>
                                <?php if ($faq['category_id'] == $cat['id']): $hasFaqs = true; ?>
                                    <div class="admin-faq-item border p-4 relative">
                                        <form method="POST" class="absolute top-3 right-3" onsubmit="return confirm('Delete this FAQ?');">
                                            <input type="hidden" name="action" value="delete_faq">
                                            <input type="hidden" name="faq_id" value="<?= $faq['id'] ?>">
                                            <button type="submit" class="admin-delete-btn p-1.5"><i class="fas fa-trash"></i></button>
                                        </form>
                                        <p class="font-bold mb-2 pr-8 text-gray-800">Q: <?= htmlspecialchars($faq['question']) ?></p>
                                        <p class="text-sm text-gray-500 flex items-center">
                                            <i class="fas fa-info-circle mr-1"></i> Format: 
                                            <span class="ml-1 admin-format-pill px-2 py-0.5">
                                                <?= ucfirst(str_replace('_', ' ', $faq['answer_type'])) ?>
                                            </span>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (!$hasFaqs): ?>
                                <p class="text-sm text-gray-500 italic col-span-2">No FAQs in this category yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
    function toggleAnswerFields() {
        const type = document.getElementById('answer_type').value;
        document.getElementById('field_text').classList.add('hidden');
        document.getElementById('field_video_link').classList.add('hidden');
        document.getElementById('field_video_upload').classList.add('hidden');

        if (type === 'text') {
            document.getElementById('field_text').classList.remove('hidden');
        } else if (type === 'video_link') {
            document.getElementById('field_video_link').classList.remove('hidden');
        } else if (type === 'video_upload') {
            document.getElementById('field_video_upload').classList.remove('hidden');
        }
    }
    // init
    toggleAnswerFields();
    </script>
</body>
</html>
