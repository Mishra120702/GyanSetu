<?php
session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../login.php");
    exit;
}

$student_user_id = $_SESSION['user_id'];
$student_query = $db->prepare("SELECT s.* FROM students s WHERE s.user_id = :user_id");
$student_query->execute([':user_id' => $student_user_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

$categories_stmt = $db->query("SELECT * FROM faq_categories WHERE status = 'active' ORDER BY order_num ASC, name ASC");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

$active_cat_id = $_GET['cat'] ?? (!empty($categories) ? $categories[0]['id'] : null);

$faqs = [];
if ($active_cat_id) {
    $faqs_stmt = $db->prepare("SELECT * FROM faqs WHERE category_id = ? AND status = 'active' ORDER BY order_num ASC, id ASC");
    $faqs_stmt->execute([$active_cat_id]);
    $faqs = $faqs_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAQ & Help Center | ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1B3C53',
                        secondary: '#234C6A',
                        cardColor: '#456882',
                        contentColor: '#D2C1B6',
                    }
                }
            }
        }
    </script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #1B3C53;
            --secondary: #234C6A;
            --card: #456882;
            --content: #D2C1B6;
        }

        .accordion-content {
            transition: max-height 0.35s ease, opacity 0.3s ease;
            max-height: 0;
            opacity: 0;
            overflow: hidden;
        }

        .accordion-content.active {
            max-height: 1000px;
            opacity: 1;
        }

        .accordion-icon {
            transition: transform 0.3s ease;
        }

        .accordion-btn.active .accordion-icon {
            transform: rotate(180deg);
        }
    </style>
</head>

<body class="bg-[#F7F5F3] flex">
    <?php include '../s_sidebar.php'; ?>

    <div class="flex-1 ml-0 md:ml-64 h-screen overflow-y-auto transition-all duration-300 w-full">

        <!-- Mobile Header -->
        <header class="shadow-md px-4 py-3 flex justify-between items-center sticky top-0 z-30 md:hidden" style="background: rgba(247, 245, 243, 0.75); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);">
            <button class="text-xl transition-colors" style="color:#1B3C53;" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            
            <h1 class="text-lg font-bold flex items-center space-x-2" style="color:#1B3C53;">
                <div class="p-2 rounded-lg" style="background:#234C6A;">
                    <i class="fas fa-question-circle text-sm" style="color:#D2C1B6;"></i>
                </div>
                <span>FAQ & Help</span>
            </h1>
            
            <div class="flex items-center space-x-3">
                <div class="w-8 h-8 rounded-full flex items-center justify-center" style="background:#234C6A;">
                    <i class="fas fa-user-graduate" style="color:#D2C1B6;"></i>
                </div>
            </div>
        </header>

        <!-- Desktop Header -->
        <!-- <header class="hidden md:flex shadow-md px-6 py-4 justify-between items-center sticky top-0 z-30" style="background: rgba(247, 245, 243, 0.75); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);">
            <div class="flex-1"></div>
            
            <h1 class="text-2xl font-bold flex items-center space-x-2" style="color:#1B3C53;">
                <div class="p-2 rounded-lg" style="background:#234C6A;">
                    <i class="fas fa-question-circle text-xl" style="color:#D2C1B6;"></i>
                </div>
                <span>FAQ & Help Center</span>
            </h1>
            
            <div class="flex-1 flex justify-end items-center space-x-4">
                <div class="animate-pulse rounded-full p-2" style="background:#234C6A;">
                    <i class="fas fa-user-graduate" style="color:#D2C1B6;"></i>
                </div>
            </div>
        </header> -->

        <!-- Hero Header -->
        <div
            class="px-6 md:px-10 py-10 relative overflow-hidden" style="background: linear-gradient(90deg, #1B3C53, #234C6A)">
            <div class="absolute -top-10 -right-10 w-52 h-52 bg-white opacity-10 rounded-full blur-3xl"></div>
            <div class="absolute -bottom-10 -left-10 w-40 h-40 bg-white opacity-5 rounded-full blur-2xl"></div>
            <div class="relative z-10 flex flex-col md:flex-row items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <div class="bg-white bg-opacity-20 p-2 rounded-xl">
                            <i class="fas fa-question-circle text-white text-xl"></i>
                        </div>
                        <span class="font-semibold text-sm uppercase tracking-widest" style="color: rgba(210,193,182,0.8)">Help Center</span>
                    </div>
                    <h1 class="text-3xl md:text-4xl font-extrabold text-white tracking-tight mb-2">Frequently Asked
                        Questions</h1>
                    <p class="text-purple-100 text-sm md:text-base max-w-xl">Browse categories and find answers to your
                        questions instantly.</p>
                </div>
                <i class="fas fa-headset text-white opacity-10 text-8xl hidden md:block"></i>
            </div>
        </div>

        <!-- Body: sidebar + content side by side, flush, same height -->
        <div class="flex flex-col lg:flex-row min-h-[calc(100vh-160px)]">

            <!-- Category Sidebar -->
            <aside
                class="w-full lg:w-64 xl:w-72 flex-shrink-0 bg-white border-r border-[#456882]/20 lg:sticky lg:top-0 lg:h-[calc(100vh-160px)] lg:overflow-y-auto">
                <div class="p-5">
                    <p class="text-xs font-bold uppercase tracking-widest mb-4 px-1" style="color: #234C6A">Categories</p>
                    <nav class="space-y-1">
                        <?php if (empty($categories)): ?>
                            <p class="text-sm text-gray-400 italic px-2">No categories available.</p>
                        <?php else: ?>
                            <?php foreach ($categories as $cat):
                                $isActive = ($cat['id'] == $active_cat_id);
                                ?>
                                <a href="?cat=<?= $cat['id'] ?>"
                                    class="flex items-center justify-between px-4 py-3 rounded-xl font-medium text-sm transition-all duration-200
                                          <?= $isActive
                                              ? 'border'
                                              : 'text-gray-500 hover:bg-[#D2C1B6] hover:text-[#1B3C53] border border-transparent' ?>"
                                    <?= $isActive ? 'style="background:#456882; color:white; border-color:#456882"' : '' ?>>
                                    <span class="flex items-center gap-3">
                                        <span
                                            class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 <?= $isActive ? '' : 'bg-[#D2C1B6]/50 text-[#1B3C53]' ?>"
                                            <?= $isActive ? 'style="background:#234C6A; color:white"' : '' ?>>
                                            <i class="fas <?= $isActive ? 'fa-folder-open' : 'fa-folder' ?> text-xs"></i>
                                        </span>
                                        <?= htmlspecialchars($cat['name']) ?>
                                    </span>
                                    <?php if ($isActive): ?>
                                        <i class="fas fa-chevron-right text-xs" style="color:white"></i>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </nav>
                </div>
            </aside>

            <!-- FAQ Content Area -->
            <main class="flex-1 bg-[#F7F5F3] p-6 md:p-8 lg:overflow-y-auto">
                
                <?php if (empty($faqs)): ?>
                    <div class="flex flex-col items-center justify-center h-full py-24 text-center">
                        <div class="w-20 h-20 bg-[#D2C1B6] rounded-full flex items-center justify-center mb-4">
                            <i class="fas fa-search text-[#456882] text-3xl"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-700 mb-1">No questions yet</h3>
                        <p class="text-gray-400 text-sm">This category has no FAQs added yet.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3 max-w-3xl">
                        <?php foreach ($faqs as $index => $faq): ?>
                            <div
                                class="bg-white rounded-2xl border border-[#456882]/25 overflow-hidden hover:border-[#456882] hover:shadow-md transition-all duration-200">

                                <button
                                    class="accordion-btn w-full px-5 py-4 flex items-start justify-between gap-4 text-left focus:outline-none hover:bg-[#D2C1B6]/30 transition-colors"
                                    onclick="toggleFaq(this)">
                                    <span class="flex items-start gap-3">
                                        <span
                                            class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 text-xs font-bold mt-0.5" style="background:#234C6A; color:white">
                                            Q
                                        </span>
                                        <span class="font-semibold text-gray-800 text-sm leading-relaxed mt-0.5">
                                            <?= htmlspecialchars($faq['question']) ?>
                                        </span>
                                    </span>
                                    <span
                                        class="w-7 h-7 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0 mt-0.5 transition-colors">
                                        <i class="fas fa-chevron-down text-gray-400 accordion-icon text-xs"></i>
                                    </span>
                                </button>

                                <div class="accordion-content border-t border-[#456882]/20">
                                    <div class="px-5 py-4 flex items-start gap-3" style="background:#D2C1B6">
                                        <span
                                            class="w-7 h-7 rounded-lg bg-green-100 text-green-600 flex items-center justify-center flex-shrink-0 text-xs font-bold mt-0.5">
                                            A
                                        </span>
                                        <div class="flex-1 text-sm leading-relaxed mt-0.5" style="color:#1B3C53">
                                            <?php if ($faq['answer_type'] === 'text'): ?>
                                                <?= nl2br(htmlspecialchars($faq['answer_text'])) ?>

                                            <?php elseif ($faq['answer_type'] === 'video_link' && !empty($faq['video_url'])): ?>
                                                <?php
                                                $url = trim($faq['video_url']);
                                                if (preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/\s]{11})%i', $url, $match)) {
                                                    $url = 'https://www.youtube.com/embed/' . $match[1];
                                                }
                                                ?>
                                                <div
                                                    class="mt-2 rounded-xl overflow-hidden border border-[#456882]/30 shadow bg-black">
                                                    <iframe src="<?= htmlspecialchars($url) ?>" frameborder="0"
                                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                                        allowfullscreen class="w-full h-64 md:h-96"></iframe>
                                                </div>

                                            <?php elseif ($faq['answer_type'] === 'video_upload' && !empty($faq['video_file'])): ?>
                                                <div
                                                    class="mt-2 rounded-xl overflow-hidden border border-[#456882]/30 shadow bg-black">
                                                    <video controls class="w-full max-h-96 outline-none">
                                                        <source src="../<?= htmlspecialchars($faq['video_file']) ?>"
                                                            type="video/mp4">
                                                        Your browser does not support the video tag.
                                                    </video>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>

        </div>
    </div>

    <script>
        function toggleFaq(btn) {
            const isActive = btn.classList.contains('active');

            document.querySelectorAll('.accordion-btn').forEach(b => {
                b.classList.remove('active', 'bg-purple-50');
                b.nextElementSibling.classList.remove('active');
                const icon = b.querySelector('.accordion-icon').parentElement;
                icon.classList.remove('bg-fuchsia-500');
                icon.classList.add('bg-gray-100');
                b.querySelector('.accordion-icon').classList.remove('text-white');
                b.querySelector('.accordion-icon').classList.add('text-gray-400');
            });

            if (!isActive) {
                btn.classList.add('active', 'bg-purple-50');
                btn.nextElementSibling.classList.add('active');
                const icon = btn.querySelector('.accordion-icon').parentElement;
                icon.classList.remove('bg-gray-100');
                icon.classList.add('bg-fuchsia-500');
                btn.querySelector('.accordion-icon').classList.remove('text-gray-400');
                btn.querySelector('.accordion-icon').classList.add('text-white');
            }
        }
    </script>
</body>

</html>