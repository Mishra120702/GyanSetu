<?php
require_once '../db_connection.php';
require_once '../header.php';
require_once '../sidebar.php';

// Get filter parameters
$status = $_GET['status'] ?? 'upcoming';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$trainer_id = $_GET['trainer_id'] ?? '';

// Get all trainers for filter dropdown
$trainers_query = $db->query("SELECT id, name FROM trainers ORDER BY name");
$trainers = $trainers_query->fetchAll(PDO::FETCH_ASSOC);

// Get workshops based on filters
$query = "SELECT w.*, t.name as trainer_name 
          FROM workshops w
          LEFT JOIN trainers t ON w.trainer_id = t.id
          WHERE 1=1";

$params = [];
$types = '';

if (!empty($status)) {
    $query .= " AND w.status = ?";
    $params[] = $status;
    $types .= 's';
}

if (!empty($start_date)) {
    $query .= " AND w.start_datetime >= ?";
    $params[] = $start_date;
    $types .= 's';
}

if (!empty($end_date)) {
    $query .= " AND w.end_datetime <= ?";
    $params[] = $end_date;
    $types .= 's';
}

if (!empty($trainer_id)) {
    $query .= " AND w.trainer_id = ?";
    $params[] = $trainer_id;
    $types .= 'i';
}

$query .= " ORDER BY w.start_datetime ASC";

$stmt = $db->prepare($query);

if (!empty($params)) {
    $stmt->execute($params);
} else {
    $stmt->execute();
}

$workshops = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    /* ===== STANDARDIZED BACKGROUND ===== */
    .rpt-orb1 {
        position:fixed; top:-120px; left:-120px;
        width:400px; height:400px; border-radius:50%;
        background:radial-gradient(circle,rgba(27,60,83,0.1) 0%,transparent 70%);
        animation:rptOrb1 20s ease-in-out infinite alternate;
        pointer-events:none; z-index:0;
    }
    .rpt-orb2 {
        position:fixed; bottom:-100px; right:-100px;
        width:360px; height:360px; border-radius:50%;
        background:radial-gradient(circle,rgba(69,104,130,0.09) 0%,transparent 70%);
        animation:rptOrb2 25s ease-in-out infinite alternate;
        pointer-events:none; z-index:0;
    }
    @keyframes rptOrb1 { from{transform:translate(0,0) scale(1)} to{transform:translate(50px,40px) scale(1.1)} }
    @keyframes rptOrb2 { from{transform:translate(0,0) scale(1)} to{transform:translate(-40px,-50px) scale(1.12)} }

    /* Glass panels */
    .glass-panel {
        background: rgba(255, 255, 255, 0.85);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(27, 60, 83, 0.08) !important;
        box-shadow: 0 10px 30px rgba(27, 60, 83, 0.04) !important;
        border-radius: 20px;
    }
</style>

<div class="ml-64 p-8 transition-all duration-300 min-h-screen" style="background: linear-gradient(145deg, #f4f6f8 0%, #eef3f7 50%, #f4f6f8 100%); position:relative; overflow-x:hidden;">
    <div class="rpt-orb1"></div>
    <div class="rpt-orb2"></div>

    <div class="relative z-10">
        <!-- Main Navigation Tabs -->
        <div class="mb-8">
            <?php include 'navbar.php'?>
        </div>

    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Workshop Management</h1>
        <div class="flex space-x-4">
            <a href="add_workshop.php" class="btn-brand-primary px-5 py-2.5 rounded-xl font-medium inline-flex items-center gap-2">
                <i class="fas fa-plus"></i> Add New Workshop
            </a>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="glass-panel p-6 mb-8 transition-all animate-fade-in">
        <h2 class="text-xl font-semibold mb-4 text-gray-700">Filter Workshops</h2>
        <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#234C6A]/20 focus:border-[#234C6A] transition-all duration-300">
                    <option value="">All Statuses</option>
                    <option value="upcoming" <?= $status === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                    <option value="ongoing" <?= $status === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                    <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Trainer</label>
                <select name="trainer_id" class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#234C6A]/20 focus:border-[#234C6A] transition-all duration-300">
                    <option value="">All Trainers</option>
                    <?php foreach ($trainers as $trainer): ?>
                        <option value="<?= $trainer['id'] ?>" <?= $trainer_id == $trainer['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($trainer['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                <input type="date" name="start_date" value="<?= $start_date ?>" class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#234C6A]/20 focus:border-[#234C6A] transition-all duration-300">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                <input type="date" name="end_date" value="<?= $end_date ?>" class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#234C6A]/20 focus:border-[#234C6A] transition-all duration-300">
            </div>
            
            <div class="md:col-span-4 flex justify-end space-x-4">
                <button type="reset" class="bg-gray-100 text-gray-700 border border-gray-200 px-6 py-2.5 rounded-xl hover:bg-gray-200 transition-all duration-300 font-semibold flex items-center gap-2 transform hover:-translate-y-0.5">
                    <i class="fas fa-redo"></i> Reset
                </button>
                <button type="submit" class="btn-brand-primary px-6 py-2.5 rounded-xl font-semibold flex items-center gap-2">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- Workshops List -->
    <div class="glass-panel overflow-hidden mb-8 transition-all animate-slide-up">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Title</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Trainer</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date & Time</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Registrations</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($workshops)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-calendar-times text-4xl text-gray-400 mb-3 block"></i>
                                <span class="text-sm font-medium text-gray-500">No workshops found matching your criteria</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($workshops as $workshop): ?>
                            <tr class="hover:bg-gradient-to-r hover:from-slate-50 hover:to-white transition-all duration-300">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10">
                                            <?php if ($workshop['cover_image']): ?>
                                                <img class="h-10 w-10 rounded-xl object-cover" src="<?= htmlspecialchars($workshop['cover_image']) ?>" alt="Workshop cover">
                                            <?php else: ?>
                                                <div class="h-10 w-10 rounded-xl bg-[#234C6A]/10 flex items-center justify-center text-[#234C6A]">
                                                    <i class="fas fa-laptop-code"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($workshop['title']) ?></div>
                                            <div class="text-xs text-gray-500">Fee: $<?= number_format($workshop['fee'], 2) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-800 font-medium"><?= htmlspecialchars($workshop['trainer_name'] ?? 'N/A') ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900 font-medium">
                                        <?= date('M d, Y', strtotime($workshop['start_datetime'])) ?>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        <?= date('h:i A', strtotime($workshop['start_datetime'])) ?> - <?= date('h:i A', strtotime($workshop['end_datetime'])) ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-800"><?= htmlspecialchars($workshop['location']) ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php 
                                        $status_color = [
                                            'upcoming' => 'blue',
                                            'ongoing' => 'green',
                                            'completed' => 'gray',
                                            'cancelled' => 'red'
                                        ][$workshop['status']] ?? 'gray';
                                    ?>
                                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold border bg-<?= $status_color ?>-50/50 border-<?= $status_color ?>-200 text-<?= $status_color ?>-700">
                                        <?= ucfirst($workshop['status']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-gray-900">
                                        <?= $workshop['current_registrations'] ?> / <?= $workshop['max_participants'] ?>
                                    </div>
                                    <div class="w-24 bg-gray-200 rounded-full h-1.5 mt-1 overflow-hidden">
                                        <div class="bg-gradient-to-r from-[#234C6A] to-[#1B3C53] h-1.5 rounded-full" 
                                             style="width: <?= $workshop['max_participants'] > 0 ? ($workshop['current_registrations'] / $workshop['max_participants'] * 100) : 0 ?>%"></div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-3 text-base">
                                        <a href="view_workshop.php?id=<?= $workshop['workshop_id'] ?>" class="text-[#234C6A] hover:text-[#1B3C53] transition-colors">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="edit_workshop.php?id=<?= $workshop['workshop_id'] ?>" class="text-[#456882] hover:text-[#234C6A] transition-colors">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="workshop_registrations.php?id=<?= $workshop['workshop_id'] ?>" class="text-green-600 hover:text-green-800 transition-colors">
                                            <i class="fas fa-users"></i>
                                        </a>
                                        <?php if ($workshop['status'] !== 'cancelled'): ?>
                                            <a href="#" onclick="confirmCancel('<?= $workshop['workshop_id'] ?>')" class="text-red-600 hover:text-red-800 transition-colors">
                                                <i class="fas fa-times"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div>
</div>

<script>
function confirmCancel(workshopId) {
    if (confirm('Are you sure you want to cancel this workshop?')) {
        window.location.href = 'cancel_workshop.php?id=' + workshopId;
    }
    return false;
}
</script>

<?php require_once '../footer.php'; ?>