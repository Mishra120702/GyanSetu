<?php
// ── Set timezone to IST ──
date_default_timezone_set('Asia/Kolkata');

session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// ── Ensure archived column exists ──
try {
    $col = $db->query("SHOW COLUMNS FROM tests LIKE 'archived'")->fetchAll();
    if (empty($col)) $db->exec("ALTER TABLE tests ADD COLUMN archived TINYINT(1) DEFAULT 0");
} catch (Exception $e) {}

// ── Handle single delete ──
if (isset($_GET['delete_test'])) {
    $testId = (int)$_GET['delete_test'];
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM test_answers WHERE attempt_id IN (SELECT id FROM test_attempts WHERE test_id = ?)")->execute([$testId]);
        $db->prepare("DELETE FROM test_attempts WHERE test_id = ?")->execute([$testId]);
        $db->prepare("DELETE FROM test_questions WHERE test_id = ?")->execute([$testId]);
        $db->prepare("DELETE FROM tests WHERE id = ?")->execute([$testId]);
        $db->commit();
        $_SESSION['toast'] = ['type'=>'success','msg'=>'Test deleted successfully.'];
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['toast'] = ['type'=>'error','msg'=>'Delete failed: '.$e->getMessage()];
    }
    header("Location: admin_dashboard.php"); exit;
}

// ── Bulk actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bulk_ids'])) {
    $ids  = array_map('intval', $_POST['bulk_ids']);
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $action = $_POST['bulk_action'] ?? '';

    if ($action === 'archive') {
        $db->prepare("UPDATE tests SET archived=1 WHERE id IN ($ph)")->execute($ids);
        $_SESSION['toast'] = ['type'=>'info','msg'=>count($ids).' test(s) archived.'];
    } elseif ($action === 'unarchive') {
        $db->prepare("UPDATE tests SET archived=0 WHERE id IN ($ph)")->execute($ids);
        $_SESSION['toast'] = ['type'=>'success','msg'=>count($ids).' test(s) restored.'];
    } elseif ($action === 'activate') {
        $db->prepare("UPDATE tests SET is_active=1 WHERE id IN ($ph)")->execute($ids);
        $_SESSION['toast'] = ['type'=>'success','msg'=>count($ids).' test(s) activated.'];
    } elseif ($action === 'deactivate') {
        $db->prepare("UPDATE tests SET is_active=0 WHERE id IN ($ph)")->execute($ids);
        $_SESSION['toast'] = ['type'=>'info','msg'=>count($ids).' test(s) deactivated.'];
    } elseif ($action === 'delete') {
        $db->beginTransaction();
        try {
            foreach ($ids as $id) {
                $db->prepare("DELETE FROM test_answers WHERE attempt_id IN (SELECT id FROM test_attempts WHERE test_id=?)")->execute([$id]);
                $db->prepare("DELETE FROM test_attempts WHERE test_id=?")->execute([$id]);
                $db->prepare("DELETE FROM test_questions WHERE test_id=?")->execute([$id]);
                $db->prepare("DELETE FROM tests WHERE id=?")->execute([$id]);
            }
            $db->commit();
            $_SESSION['toast'] = ['type'=>'success','msg'=>count($ids).' test(s) permanently deleted.'];
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['toast'] = ['type'=>'error','msg'=>'Bulk delete failed: '.$e->getMessage()];
        }
    } elseif ($action === 'assign_batch' && !empty($_POST['assign_batch_id'])) {
        $batchId = $_POST['assign_batch_id'];
        $db->prepare("UPDATE tests SET batch_id=? WHERE id IN ($ph)")->execute(array_merge([$batchId], $ids));
        $_SESSION['toast'] = ['type'=>'success','msg'=>count($ids).' test(s) assigned to batch '.$batchId.'.'];
    }
    header("Location: admin_dashboard.php?".http_build_query(array_filter([
        'batch'=>$_POST['filter_batch']??'', 'status'=>$_POST['filter_status']??'',
        'category'=>$_POST['filter_category']??'', 'view'=>$_POST['filter_view']??''
    ]))); exit;
}

// ── Inline edit ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inline_edit_id'])) {
    header('Content-Type: application/json');
    $id    = (int)$_POST['inline_edit_id'];
    $title = trim($_POST['inline_title'] ?? '');
    $desc  = trim($_POST['inline_desc'] ?? '');
    if ($title !== '') {
        $db->prepare("UPDATE tests SET title=?, description=?, updated_at=NOW() WHERE id=?")->execute([$title,$desc,$id]);
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'msg'=>'Title cannot be empty']);
    }
    exit;
}

// ── Toggle status via AJAX ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    header('Content-Type: application/json');
    $id = (int)$_POST['toggle_id'];
    $db->prepare("UPDATE tests SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
    $row = $db->prepare("SELECT is_active FROM tests WHERE id=?");
    $row->execute([$id]);
    $new = $row->fetchColumn();
    echo json_encode(['success'=>true,'is_active'=>(int)$new]);
    exit;
}

// ── Filters ──
$showArchived   = ($_GET['view'] ?? '') === 'archived';
$filterBatch    = $_GET['batch']    ?? '';
$filterStatus   = $_GET['status']   ?? '';
$filterCategory = $_GET['category'] ?? '';
$perPage        = 20;
$page           = max(1, (int)($_GET['page'] ?? 1));
$offset         = ($page - 1) * $perPage;

// ── Count enrolled students per batch ──
$enrolledByBatch = [];
try {
    $enRows = $db->query("
        SELECT batch_name, COUNT(*) as cnt FROM students
        WHERE current_status='active' GROUP BY batch_name
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($enRows as $r) $enrolledByBatch[$r['batch_name']] = (int)$r['cnt'];
} catch(Exception $e){}

// ── Main tests query with pagination ──
$where = $showArchived ? "WHERE t.archived=1" : "WHERE (t.archived=0 OR t.archived IS NULL)";
$params = [];
if ($filterBatch)    { $where .= " AND t.batch_id=?";        $params[] = $filterBatch; }
if ($filterStatus === 'active')   { $where .= " AND t.is_active=1"; }
if ($filterStatus === 'inactive') { $where .= " AND t.is_active=0"; }
if ($filterCategory) { $where .= " AND t.test_category=?";   $params[] = $filterCategory; }

$countRow = $db->prepare("SELECT COUNT(DISTINCT t.id) FROM tests t $where");
$countRow->execute($params);
$totalTests = (int)$countRow->fetchColumn();
$totalPages = max(1, ceil($totalTests / $perPage));

$testsStmt = $db->prepare("
    SELECT t.*,
        b.batch_name,
        COUNT(DISTINCT tq.id)  as question_count,
        COUNT(DISTINCT ta.id)  as total_attempts,
        COUNT(DISTINCT CASE WHEN ta.status='submitted' THEN ta.student_id END) as completed_attempts,
        ROUND(AVG(CASE WHEN ta.status='submitted' THEN ta.percentage END),2) as avg_score
    FROM tests t
    LEFT JOIN batches b ON t.batch_id = b.batch_id
    LEFT JOIN test_questions tq ON t.id = tq.test_id
    LEFT JOIN test_attempts ta ON t.id = ta.test_id
    $where
    GROUP BY t.id
    ORDER BY t.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$testsStmt->execute($params);
$tests = $testsStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Summary stats ──
$allStats = $db->query("
    SELECT COUNT(*) as total,
           SUM(is_active) as active_count,
           (SELECT SUM(1) FROM test_attempts) as attempts,
           (SELECT ROUND(AVG(percentage),1) FROM test_attempts WHERE status='submitted') as avg_score
    FROM tests WHERE (archived=0 OR archived IS NULL)
")->fetch(PDO::FETCH_ASSOC);

$batches = $db->query("SELECT DISTINCT batch_id, batch_name FROM batches WHERE status IN ('ongoing','upcoming') ORDER BY batch_name")->fetchAll(PDO::FETCH_ASSOC);
$categories = $db->query("SELECT DISTINCT test_category FROM tests WHERE test_category IS NOT NULL AND test_category!='' ORDER BY test_category")->fetchAll(PDO::FETCH_COLUMN);

$toast = $_SESSION['toast'] ?? null;
unset($_SESSION['toast']);

// ── Build filter chip data ──
$activeChips = [];
if ($filterBatch) {
    $bLabel = $filterBatch;
    foreach ($batches as $b) if ($b['batch_id']==$filterBatch) { $bLabel=$b['batch_name']; break; }
    $activeChips['batch'] = ['label'=>'Batch','val'=>$bLabel];
}
if ($filterStatus)   $activeChips['status']   = ['label'=>'Status','val'=>ucfirst($filterStatus)];
if ($filterCategory) $activeChips['category'] = ['label'=>'Category','val'=>ucwords(str_replace('_',' ',$filterCategory))];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Test Management Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
    theme: {
        extend: {
            colors: {
                indigo: {
                    50: '#F5F7FA',
                    100: '#E8ECF2',
                    200: '#D2C1B6',
                    300: '#A4B4C4',
                    400: '#456882',
                    500: '#234C6A',
                    600: '#1B3C53',
                    700: '#153043',
                    800: '#0F2332',
                    900: '#0A1721',
                },
                blue: {
                    50: '#F7F5F3',
                    100: '#EAE5E1',
                    200: '#D2C1B6',
                    300: '#A39185',
                    400: '#456882',
                    500: '#234C6A',
                    600: '#1B3C53',
                    700: '#153043',
                    800: '#0F2332',
                    900: '#0A1721',
                },
                purple: {
                    50: '#F5F7FA',
                    100: '#EAE5E1',
                    200: '#D2C1B6',
                    300: '#A4B4C4',
                    400: '#456882',
                    500: '#234C6A',
                    600: '#1B3C53',
                    700: '#153043',
                    800: '#0F2332',
                    900: '#0A1721',
                }
            }
        }
    }
}
</script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
<style>
    *{font-family:'Inter',sans-serif;}
    body{
        background-color: #f7f5f3;
        background-image:
            radial-gradient(ellipse 80% 60% at 0% 0%, rgba(210,193,182,0.40) 0%, transparent 55%),
            radial-gradient(ellipse 60% 50% at 100% 0%, rgba(69,104,130,0.15) 0%, transparent 50%),
            radial-gradient(ellipse 70% 50% at 50% 110%, rgba(27,60,83,0.12) 0%, transparent 55%);
        min-height:100vh;
    }
    body::before{content:'';position:fixed;inset:0;background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60'%3E%3Ccircle cx='30' cy='30' r='1' fill='%23456882' opacity='0.07'/%3E%3C/svg%3E");pointer-events:none;z-index:-1;}

    .glass-effect{
        background:rgba(255,255,255,0.85);
        backdrop-filter:blur(16px);
        border:2px solid rgba(69,104,130,0.25);
        box-shadow:0 8px 32px rgba(27,60,83,0.10), inset 0 1px 0 rgba(255,255,255,0.5);
        border-radius:20px;
    }
    .glass-card{background:rgba(255,255,255,0.80);backdrop-filter:blur(12px);border:1px solid rgba(69,104,130,0.12);box-shadow:0 4px 20px rgba(27,60,83,0.08);}

    /* ── KPI Cards ── */
    .kpi-card{
        background:#ffffff;
        border-radius:18px;
        padding:14px 16px 12px;
        border:2px solid rgba(69,104,130,0.25);
        box-shadow:0 4px 20px rgba(27,60,83,0.08), inset 0 1px 0 rgba(255,255,255,0.7);
        transition:transform .3s cubic-bezier(.4,0,.2,1), box-shadow .3s;
        position:relative;
        overflow:hidden;
        cursor:pointer;
        color: #1B3C53;
        display:flex;
        flex-direction:column;
        gap:4px;
    }
    .kpi-card:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(27,60,83,0.18), inset 0 1px 0 rgba(255,255,255,0.7);}

    .kpi-icon{
        position:absolute; top:12px; right:12px;
        width:40px; height:40px; border-radius:14px;
        display:flex; align-items:center; justify-content:center;
        font-size:1.1rem; box-shadow:0 4px 16px rgba(0,0,0,0.15);
    }
    .kpi-icon-blue   { background:linear-gradient(135deg,#234C6A,#456882); }
    .kpi-icon-green  { background:linear-gradient(135deg,#D2C1B6,#456882); }
    .kpi-icon-purple { background:linear-gradient(135deg,#1B3C53,#234C6A); }
    .kpi-icon-violet { background:linear-gradient(135deg,#456882,#D2C1B6); }
    .kpi-icon-pink   { background:linear-gradient(135deg,#1B3C53,#456882); }

    .kpi-label{ font-size:.65rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#456882; }
    .kpi-value{ font-size:1.6rem; font-weight:900; line-height:1.1; color:#1B3C53; }
    .kpi-sub  { font-size:.7rem; color:#456882; font-weight:500; margin-top:0; }

    .kpi-bar-wrap{ height:4px; border-radius:99px; background:#e2e8f0; margin-top:6px; overflow:hidden; }
    .kpi-bar     { height:100%; border-radius:99px; background:linear-gradient(90deg,#1B3C53,#234C6A,#456882); }

    /* ── Test Card ── */
    .test-card{
        transition:all .35s cubic-bezier(.4,0,.2,1);
        position:relative; overflow:visible;
        border-radius:20px;
        border:1px solid rgba(69,104,130,0.12);
        box-shadow:0 8px 32px rgba(27,60,83,0.08);
        background:#ffffff;
        color: #1B3C53;
    }
    .test-card:hover{transform:translateY(-7px) scale(1.01);box-shadow:0 24px 56px rgba(27,60,83,0.18);}
    .test-card.selected-card{box-shadow:0 0 0 3px rgba(35, 76, 106,.55), 0 12px 32px rgba(35, 76, 106, 0.18);}

    /* ── Card Header ── */
    .card-header{
        position:relative;
        padding:14px 14px 16px 14px;
        border-radius:20px 20px 0 0;
        overflow:visible;
        display:flex;
        flex-direction:column;
        gap:10px;
        min-height:160px;
    }
    .header-top-row{
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:8px;
        position:relative;
        z-index:15;
    }
    .card-checkbox{
        width:16px;height:16px;
        accent-color:#234C6A;
        cursor:pointer;
        flex-shrink:0;
        margin:0;
    }
    .card-header-title{
        color:#ffffff;
        font-weight:800;
        font-size:clamp(1.1rem, 1.6vw, 1.4rem);
        line-height:1.3;
        text-shadow:0 1px 2px rgba(0,0,0,0.3);
        letter-spacing:-.01em;
        flex:1;
        overflow:hidden;
        display:-webkit-box;
        -webkit-line-clamp:2;
        -webkit-box-orient:vertical;
        margin:0;
    }
    .card-menu-btn{
        width:28px;height:28px;
        border-radius:6px;
        background:rgba(255,255,255,0.25);
        border:1px solid rgba(255,255,255,0.2);
        display:flex;
        align-items:center;
        justify-content:center;
        color:#ffffff;
        cursor:pointer;
        transition:background .2s;
        font-size:.8rem;
        flex-shrink:0;
        padding:0;
    }
    .card-menu-btn:hover{background:rgba(255,255,255,0.4);}
    .header-bottom-row{
        display:flex;
        align-items:center;
        gap:14px;
        position:relative;
        z-index:10;
        flex-wrap:nowrap;
    }
    .card-header-icon{
        width:44px;height:44px;
        border-radius:12px;
        background:rgba(255,255,255,.25);
        backdrop-filter:blur(8px);
        border:1.5px solid rgba(255,255,255,.3);
        display:flex;
        align-items:center;
        justify-content:center;
        font-size:1.1rem;
        font-weight:800;
        color:#ffffff;
        box-shadow:0 4px 14px rgba(0,0,0,.12);
        flex-shrink:0;
    }
    .card-header-badges{
        display:flex;
        flex-wrap:nowrap;
        gap:5px;
        overflow:hidden;
        flex:1;
        justify-content:flex-start;
    }
    .hdr-badge{
        display:inline-flex;
        align-items:center;
        gap:4px;
        white-space:nowrap;
        padding:3px 9px;
        border-radius:20px;
        font-size:.67rem;
        font-weight:700;
        background:rgba(255,255,255,.25);
        backdrop-filter:blur(6px);
        border:1px solid rgba(255,255,255,.2);
        color:#ffffff;
        flex-shrink:0;
    }
    .hdr-badge-active  { background:rgba(35, 76, 106, 0.45);border-color:rgba(35, 76, 106, 0.35);color:#fff; }
    .hdr-badge-inactive{ background:rgba(100,116,139,.35);border-color:rgba(100,116,139,.25);color:#fff; }
    .hdr-badge-healthy { background:rgba(35, 76, 106, 0.35);border-color:rgba(35, 76, 106, 0.25);color:#fff; }
    .hdr-badge-low     { background:rgba(210, 193, 182, 0.35);border-color:rgba(210, 193, 182, 0.25);color:#fff; }
    .hdr-badge-moderate{ background:rgba(69, 104, 130, 0.35);border-color:rgba(69, 104, 130, 0.25);color:#fff; }

    .card-header-cpp   { background:linear-gradient(135deg,#1B3C53 0%,#234C6A 45%,#456882 100%); }
    .card-header-java  { background:linear-gradient(135deg,#234C6A 0%,#456882 45%,#D2C1B6 100%); }
    .card-header-python{ background:linear-gradient(135deg,#1B3C53 0%,#456882 50%,#D2C1B6 100%); }
    .card-header-web   { background:linear-gradient(135deg,#234C6A 0%,#1B3C53 45%,#456882 100%); }
    .card-header-default{ background:linear-gradient(135deg,#1B3C53 0%,#234C6A 45%,#D2C1B6 100%); }

    .card-header-bg-clip{
        position:absolute;inset:0;
        border-radius:20px 20px 0 0;
        overflow:hidden;pointer-events:none;
    }
    .card-header-bg-clip::after{
        content:'';position:absolute;bottom:0;left:0;right:0;height:28px;
        background:rgba(255,255,255,0.97);
        border-radius:18px 18px 0 0;
    }

    .card-body{padding:4px 16px 16px;}
    .card-tag{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:#f1f5f9;border-radius:8px;font-size:.72rem;color:#456882;font-weight:600;}
    .stat-box{
        flex:1;text-align:center;padding:10px 6px;border-radius:14px;
    }
    .stat-box-blue  {background:linear-gradient(135deg,rgba(69,104,130,0.08),rgba(69,104,130,0.18));}
    .stat-box-green {background:linear-gradient(135deg,rgba(35,76,106,0.08),rgba(35,76,106,0.18));}
    .stat-box-purple{background:linear-gradient(135deg,rgba(210,193,182,0.15),rgba(210,193,182,0.3));}
    .stat-num{font-size:1.25rem;font-weight:800;line-height:1.1;}
    .stat-lbl{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-top:2px;}
    .health-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;}
    .h-green{background:rgba(35,76,106,0.12);color:#234C6A;}
    .h-yellow{background:rgba(69,104,130,0.12);color:#456882;}
    .h-red{background:rgba(210,193,182,0.2);color:#1B3C53;}

    .progress-ring-wrap{position:relative;width:56px;height:56px;flex-shrink:0;}
    .progress-ring-wrap svg{transform:rotate(-90deg);}
    .ring-bg{fill:none;stroke:#e2e8f0;stroke-width:5;}
    .ring-fill{fill:none;stroke-width:5;stroke-linecap:round;transition:stroke-dashoffset .8s cubic-bezier(.4,0,.2,1);}
    .ring-label{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:.6rem;font-weight:700;color:#1B3C53;line-height:1.1;}

    .bulk-bar{
        position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(80px);
        background:linear-gradient(135deg,#1B3C53,#234C6A);color:#fff;
        border-radius:16px;padding:14px 20px;
        display:flex;align-items:center;gap:12px;flex-wrap:wrap;
        box-shadow:0 16px 40px rgba(27,60,83,.4);z-index:200;
        transition:transform .35s cubic-bezier(.4,0,.2,1), opacity .35s;
        opacity:0; min-width:320px; justify-content:center;
    }
    .bulk-bar.visible{transform:translateX(-50%) translateY(0);opacity:1;}
    .bulk-btn{border:1.5px solid rgba(255,255,255,.3);background:rgba(255,255,255,.1);color:#fff;border-radius:10px;padding:7px 14px;font-size:.8rem;font-weight:600;display:inline-flex;align-items:center;gap:6px;cursor:pointer;transition:all .2s;}
    .bulk-btn:hover{background:rgba(255,255,255,.2);}
    .bulk-btn.danger{border-color:rgba(248,113,113,.6);background:rgba(239,68,68,.25);}
    .bulk-btn.danger:hover{background:rgba(239,68,68,.45);}

    .toast-wrap{position:fixed;top:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px;}
    .toast{
        display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:14px;
        font-size:.875rem;font-weight:600;min-width:260px;max-width:380px;
        box-shadow:0 8px 24px rgba(0,0,0,.15);
        animation:toastIn .4s cubic-bezier(.4,0,.2,1);
    }
    @keyframes toastIn{from{opacity:0;transform:translateX(40px);}to{opacity:1;transform:translateX(0);}}
    .toast.success{background:#f0fdf4;color:#16a34a;border-left:4px solid #16a34a;}
    .toast.error{background:#fef2f2;color:#dc2626;border-left:4px solid #dc2626;}
    .toast.info{background:#eff6ff;color:#3b82f6;border-left:4px solid #3b82f6;}
    .toast.warning{background:#fffbeb;color:#d97706;border-left:4px solid #d97706;}

    .filter-chip{display:inline-flex;align-items:center;gap:6px;background:#f5f3f0;color:#234C6A;border:1px solid #D2C1B6;border-radius:20px;padding:5px 8px 5px 14px;font-size:.78rem;font-weight:600;}
    .chip-x{border:none;background:#fff;color:#234C6A;width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.6rem;cursor:pointer;transition:all .2s;}
    .chip-x:hover{background:#234C6A;color:#fff;}
    .clear-all-chip{display:inline-flex;align-items:center;gap:5px;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:20px;padding:5px 14px;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .2s;text-decoration:none;}
    .clear-all-chip:hover{background:#fee2e2;}

    .expand-row{display:none;background:#fcfbfa;border-top:1px solid #f5f3f0;}
    .expand-row.open{display:table-row;}
    .inline-edit-input{border:1.5px solid #D2C1B6;border-radius:8px;padding:6px 10px;font-size:.875rem;width:100%;outline:none;}
    .inline-edit-input:focus{border-color:#234C6A;box-shadow:0 0 0 3px rgba(35, 76, 106,.15);}

    .modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:1000;animation:modalBg .3s ease;}
    @keyframes modalBg{from{opacity:0;}to{opacity:1;}}
    .modal-box{background:#fff;border-radius:20px;width:90%;max-width:480px;margin:8% auto;box-shadow:0 25px 50px rgba(0,0,0,.25);animation:modalSlide .4s cubic-bezier(.4,0,.2,1);}
    @keyframes modalSlide{from{transform:translateY(-20px);opacity:.6;}to{transform:translateY(0);opacity:1;}}

    .status-active{background:linear-gradient(135deg,#234C6A,#456882);color:#fff;font-weight:700;}
    .status-inactive{background:linear-gradient(135deg,#64748b,#94a3b8);color:#fff;font-weight:700;}

    .card-enter{animation:cardEnter .5s cubic-bezier(.4,0,.2,1) forwards;opacity:0;transform:translateY(16px);}
    @keyframes cardEnter{to{opacity:1;transform:translateY(0);}}

    .gradient-text{background:linear-gradient(135deg,#1B3C53,#234C6A);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
    .float{animation:float 6s ease-in-out infinite;}
    @keyframes float{0%,100%{transform:translateY(0);}50%{transform:translateY(-10px);}}
    .floating-btn{animation:floatBtn 3s ease-in-out infinite;box-shadow:0 10px 25px rgba(27,60,83,.35);}
    @keyframes floatBtn{0%,100%{transform:translateY(0);}50%{transform:translateY(-6px);}}

    .scrollbar-thin::-webkit-scrollbar{height:5px;}
    .scrollbar-thin::-webkit-scrollbar-track{background:#f1f5f9;border-radius:10px;}
    .scrollbar-thin::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:10px;}

    .page-btn{padding:6px 14px;border-radius:10px;font-size:.85rem;font-weight:600;transition:all .2s;}
    .page-btn.active{background:linear-gradient(135deg,#1B3C53,#234C6A);color:#fff;box-shadow:0 4px 12px rgba(27,60,83,.35);}
    .page-btn:not(.active){background:rgba(255,255,255,.75);color:#456882;}
    .page-btn:not(.active):hover{background:rgba(255,255,255,.95);}

    .batch-opt{padding:10px 14px;border-radius:10px;cursor:pointer;transition:background .15s;}
    .batch-opt:hover{background:#f5f3f0;}
    .batch-opt.sel{background:#f5f3f0;font-weight:700;color:#234C6A;}

    /* ── Hero Banner ── */
    .hero-banner {
        background: linear-gradient(135deg, #1B3C53 0%, #234C6A 30%, #456882 60%, #D2C1B6 100%);
        border-radius: 28px;
        box-shadow: 0 20px 60px rgba(27,60,83,0.35), 0 6px 20px rgba(35,76,106,0.25);
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(210,193,182,0.20);
    }
    .hero-banner::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image:
            radial-gradient(circle at 20% 30%, rgba(255,255,255,0.15) 0%, transparent 8%),
            radial-gradient(circle at 80% 70%, rgba(255,255,255,0.12) 0%, transparent 12%),
            radial-gradient(circle at 40% 80%, rgba(255,255,255,0.10) 0%, transparent 10%),
            radial-gradient(circle at 10% 90%, rgba(255,255,255,0.08) 0%, transparent 6%),
            radial-gradient(circle at 70% 20%, rgba(255,255,255,0.18) 0%, transparent 14%),
            radial-gradient(circle at 50% 50%, rgba(255,255,255,0.06) 0%, transparent 20%);
        pointer-events: none;
        z-index: 1;
    }
    .hero-banner .bubble {
        position: absolute;
        border-radius: 50%;
        background: rgba(255,255,255,0.10);
        border: 1px solid rgba(255,255,255,0.08);
        pointer-events: none;
        z-index: 0;
    }
    .hero-banner .bubble:nth-child(1) { width: 80px; height: 80px; top: 10%; left: 5%; animation: floatBubble 12s infinite ease-in-out; }
    .hero-banner .bubble:nth-child(2) { width: 120px; height: 120px; bottom: 5%; right: 10%; animation: floatBubble 18s infinite ease-in-out reverse; }
    .hero-banner .bubble:nth-child(3) { width: 60px; height: 60px; top: 60%; left: 80%; animation: floatBubble 14s infinite ease-in-out 2s; }
    .hero-banner .bubble:nth-child(4) { width: 40px; height: 40px; top: 20%; right: 25%; animation: floatBubble 10s infinite ease-in-out 1s; }
    @keyframes floatBubble {
        0% { transform: translate(0, 0) scale(1); }
        33% { transform: translate(20px, -30px) scale(1.05); }
        66% { transform: translate(-10px, 20px) scale(0.95); }
        100% { transform: translate(0, 0) scale(1); }
    }
    .hero-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 6px 14px;
        border-radius: 999px;
        background: rgba(255,255,255,0.20);
        backdrop-filter: blur(4px);
        border: 1px solid rgba(255,255,255,0.25);
        color: #f7f5f3;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    /* ── KPI Cards (with sliding accent) ── */
    .kpi-card{
        background:#ffffff;
        border-radius:18px;
        padding:14px 16px 12px;
        border:2px solid rgba(69,104,130,0.25);
        box-shadow:0 4px 20px rgba(27,60,83,0.08), inset 0 1px 0 rgba(255,255,255,0.7);
        transition:transform .3s cubic-bezier(.4,0,.2,1), box-shadow .3s;
        position:relative;
        overflow:hidden;
        cursor:pointer;
        color: #1B3C53;
        display:flex;
        flex-direction:column;
        gap:4px;
    }
    .kpi-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 6px;
        height: 100%;
        background: linear-gradient(180deg, #1B3C53, #234C6A);
        transition: width 0.4s ease;
        border-radius: 18px 0 0 18px;
    }
    .kpi-card:hover {
        transform: translateY(-4px) scale(1.02);
        box-shadow: 0 20px 40px rgba(27,60,83,0.18), inset 0 1px 0 rgba(255,255,255,0.7);
    }
    .kpi-card:hover::before {
        width: 100%;
        opacity: 0.08;
    }

    /* ── View Toggle ── */
    .view-toggle-btn {
        color: #456882;
        transition: all 0.2s;
    }
    .view-toggle-btn:hover {
        color: #234C6A;
    }
    .view-toggle-btn.active {
        background: #ffffff;
        color: #1B3C53;
        box-shadow: 0 4px 12px rgba(27,60,83,0.08);
    }

    /* ── Timezone pill ── */
    .timezone-pill {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: rgba(255,255,255,0.15);
        backdrop-filter: blur(4px);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 20px;
        padding: 3px 12px;
        font-size: 0.7rem;
        font-weight: 600;
        color: #fff;
        letter-spacing: 0.03em;
    }
    .timezone-pill i {
        font-size: 0.6rem;
        opacity: 0.7;
    }
</style>
</head>
<body class="min-h-screen">
<?php include '../header.php'; ?>
<?php include '../sidebar.php'; ?>

<!-- ── Toast Container ── -->
<div class="toast-wrap" id="toastWrap">
<?php if ($toast): ?>
    <div class="toast <?= $toast['type'] ?>" id="phpToast">
        <i class="fas <?= $toast['type']==='success'?'fa-check-circle':($toast['type']==='error'?'fa-times-circle':($toast['type']==='info'?'fa-info-circle':'fa-exclamation-circle')) ?>"></i>
        <span><?= htmlspecialchars($toast['msg']) ?></span>
        <button onclick="this.parentElement.remove()" class="ml-auto text-current opacity-60 hover:opacity-100"><i class="fas fa-times text-xs"></i></button>
    </div>
<?php endif; ?>
</div>

<!-- ── Bulk Action Bar ── -->
<div class="bulk-bar" id="bulkBar">
    <span id="bulkCount" class="font-bold text-sm mr-2">0 selected</span>
    <form method="POST" id="bulkForm">
        <input type="hidden" name="filter_batch"    value="<?= htmlspecialchars($filterBatch) ?>">
        <input type="hidden" name="filter_status"   value="<?= htmlspecialchars($filterStatus) ?>">
        <input type="hidden" name="filter_category" value="<?= htmlspecialchars($filterCategory) ?>">
        <input type="hidden" name="filter_view"     value="<?= $showArchived?'archived':'' ?>">
        <div id="bulkIdsContainer"></div>
        <div class="flex gap-2 flex-wrap justify-center">
            <?php if (!$showArchived): ?>
            <button type="button" class="bulk-btn" onclick="submitBulk('activate')"><i class="fas fa-play-circle"></i> Activate</button>
            <button type="button" class="bulk-btn" onclick="submitBulk('deactivate')"><i class="fas fa-pause-circle"></i> Deactivate</button>
            <button type="button" class="bulk-btn" onclick="openAssignModal()"><i class="fas fa-layer-group"></i> Assign Batch</button>
            <button type="button" class="bulk-btn" onclick="submitBulk('archive')"><i class="fas fa-archive"></i> Archive</button>
            <?php else: ?>
            <button type="button" class="bulk-btn" onclick="submitBulk('unarchive')"><i class="fas fa-box-open"></i> Restore</button>
            <?php endif; ?>
            <button type="button" class="bulk-btn danger" onclick="submitBulk('delete','Are you sure? This permanently deletes all data.')"><i class="fas fa-trash"></i> Delete</button>
            <input type="hidden" name="bulk_action" id="bulkActionInput">
            <input type="hidden" name="assign_batch_id" id="assignBatchIdInput">
        </div>
    </form>
    <button onclick="clearSelection()" class="ml-2 text-white/60 hover:text-white text-xs"><i class="fas fa-times"></i></button>
</div>

<!-- ── Assign Batch Modal ── -->
<div id="assignBatchModal" class="modal">
    <div class="modal-box p-6">
        <h3 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-layer-group mr-2 text-blue-500"></i>Assign to Batch</h3>
        <div class="space-y-2 max-h-60 overflow-y-auto pr-1">
            <?php foreach ($batches as $b): ?>
            <div class="batch-opt" onclick="selectBatch('<?= htmlspecialchars($b['batch_id']) ?>','<?= htmlspecialchars($b['batch_name']) ?>')">
                <span class="font-semibold text-gray-700"><?= htmlspecialchars($b['batch_name']) ?></span>
                <span class="text-xs text-gray-400 ml-2"><?= htmlspecialchars($b['batch_id']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="flex justify-end gap-3 mt-5">
            <button onclick="closeModal('assignBatchModal')" class="px-4 py-2 rounded-xl border border-gray-300 text-gray-700 font-semibold text-sm">Cancel</button>
            <button id="assignConfirmBtn" onclick="confirmAssignBatch()" class="px-4 py-2 rounded-xl bg-gradient-to-r from-blue-500 to-purple-600 text-white font-semibold text-sm" disabled>Assign</button>
        </div>
    </div>
</div>

<!-- ── Delete Modal ── -->
<div id="deleteModal" class="modal">
    <div class="modal-box">
        <div class="bg-gradient-to-r from-red-50 to-pink-50 p-7 rounded-t-2xl">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 bg-gradient-to-br from-red-500 to-pink-500 rounded-full flex items-center justify-center shadow-lg">
                    <i class="fas fa-exclamation-triangle text-white text-xl"></i>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-red-800">Delete Test</h3>
                    <p id="deleteMessage" class="text-red-600 mt-1 text-sm font-medium"></p>
                </div>
            </div>
        </div>
        <div class="p-7">
            <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r-xl mb-5 text-sm text-red-700">
                <i class="fas fa-radiation mr-2"></i><strong>Cannot be undone.</strong> All attempts, questions, and results will be permanently deleted.
            </div>
            <div class="flex justify-end gap-3">
                <button onclick="closeModal('deleteModal')" class="px-5 py-2.5 border-2 border-gray-300 text-gray-700 rounded-xl font-semibold text-sm hover:bg-gray-50">Cancel</button>
                <a href="#" id="confirmDeleteBtn" class="px-5 py-2.5 bg-gradient-to-r from-red-600 to-pink-600 text-white rounded-xl font-semibold text-sm shadow-md hover:from-red-700 hover:to-pink-700 transition-all">
                    <i class="fas fa-trash mr-2"></i>Delete
                </a>
            </div>
        </div>
    </div>
</div>

<div class="ml-0 md:ml-64 min-h-screen p-4 md:p-8">
    <div class="fixed top-0 right-0 w-96 h-96 rounded-full mix-blend-multiply filter blur-3xl opacity-30 animate-pulse pointer-events-none" style="background:radial-gradient(circle,#D2C1B6,transparent);"></div>
    <div class="fixed bottom-0 left-0 w-96 h-96 rounded-full mix-blend-multiply filter blur-3xl opacity-25 float pointer-events-none" style="background:radial-gradient(circle,#456882,transparent);"></div>

    <div class="max-w-7xl mx-auto relative z-10">

        <!-- ── HERO BANNER ── -->
        <div class="hero-banner p-5 md:p-6 mb-6 relative overflow-hidden">
            <div class="bubble"></div>
            <div class="bubble"></div>
            <div class="bubble"></div>
            <div class="bubble"></div>

            <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div>
                    <div class="hero-pill mb-2">
                        <i class="fas fa-chart-line text-white"></i> Admin Dashboard
                    </div>
                    <h1 class="text-3xl md:text-4xl font-black text-white leading-tight" style="font-family:'Poppins',sans-serif; text-shadow: 0 2px 4px rgba(0,0,0,0.10);">
                        Test Management
                    </h1>
                    <p class="text-gray-100/90 mt-1 text-base max-w-2xl" style="text-shadow: 0 1px 2px rgba(0,0,0,0.08);">
                        Create, manage, and monitor MCQ tests with advanced analytics
                    </p>
                    <div class="flex flex-wrap gap-4 mt-2 text-sm text-white/80">
                        <span class="flex items-center gap-1.5"><i class="fas fa-file-alt text-white/60"></i> <strong class="text-white"><?= $allStats['total'] ?? 0 ?></strong> total tests</span>
                        <span class="flex items-center gap-1.5"><i class="fas fa-user-graduate text-white/60"></i> <strong class="text-white"><?= $allStats['attempts'] ?? 0 ?></strong> attempts</span>
                        <span class="timezone-pill"><i class="far fa-clock"></i> IST (UTC+5:30)</span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="admin_dashboard.php?view=archived" class="<?= $showArchived?'bg-indigo-600 text-white':'bg-white/20 text-white hover:bg-white/30' ?> px-5 py-2.5 rounded-xl font-semibold text-sm flex items-center gap-2 backdrop-blur-sm transition-all border border-white/15">
                        <i class="fas fa-archive"></i> <?= $showArchived ? 'View Active' : 'Archived' ?>
                    </a>
                    <a href="create_test.php" class="bg-white text-[#1B3C53] px-6 py-2.5 rounded-xl font-semibold flex items-center gap-2 shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all duration-300">
                        <i class="fas fa-plus-circle"></i> Create New Test
                    </a>
                </div>
            </div>
        </div>

        <!-- ── KPI Strip ── -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="kpi-card" onclick="applyStatusFilter('')">
                <div class="kpi-icon kpi-icon-blue"><i class="fas fa-file-alt text-white"></i></div>
                <div class="kpi-label">Total Tests</div>
                <div class="kpi-value"><?= $allStats['total'] ?? 0 ?></div>
                <div class="kpi-sub">All published records</div>
                <div class="kpi-bar-wrap"><div class="kpi-bar" style="width:<?= min(100, ($allStats['total']??0)*10) ?>%"></div></div>
            </div>
            <div class="kpi-card" onclick="applyStatusFilter('active')">
                <div class="kpi-icon kpi-icon-green"><i class="fas fa-check-circle text-white"></i></div>
                <div class="kpi-label">Active Tests</div>
                <div class="kpi-value"><?= $allStats['active_count'] ?? 0 ?></div>
                <div class="kpi-sub">Currently live</div>
                <div class="kpi-bar-wrap"><div class="kpi-bar" style="width:<?= ($allStats['total']??0)>0 ? round(($allStats['active_count']??0)/($allStats['total']??1)*100) : 0 ?>%"></div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon-purple"><i class="fas fa-users text-white"></i></div>
                <div class="kpi-label">Attempts</div>
                <div class="kpi-value"><?= $allStats['attempts'] ?? 0 ?></div>
                <div class="kpi-sub">Total submissions</div>
                <div class="kpi-bar-wrap"><div class="kpi-bar" style="width:<?= min(100, ($allStats['attempts']??0)*5) ?>%"></div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon-violet"><i class="fas fa-star text-white"></i></div>
                <div class="kpi-label">Avg Score</div>
                <div class="kpi-value"><?= $allStats['avg_score'] ?? '—' ?><?= $allStats['avg_score'] ? '%' : '' ?></div>
                <div class="kpi-sub">Class + assignment avg</div>
                <div class="kpi-bar-wrap"><div class="kpi-bar" style="width:<?= $allStats['avg_score'] ?? 0 ?>%"></div></div>
            </div>
        </div>

        <!-- ── Filters ── -->
        <div class="glass-effect rounded-2xl p-6 mb-6 shadow-xl">
            <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
                <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2"><i class="fas fa-filter text-blue-500"></i>Filter & Search Tests</h2>
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-indigo-400 text-sm"></i>
                    <input type="text" id="searchInput" placeholder="Search by title, subject, batch…" class="pl-9 pr-4 py-2.5 rounded-xl text-sm focus:outline-none w-72" style="background:linear-gradient(135deg,#f7f5f3,#D2C1B6);border:2px solid #456882;box-shadow:0 0 0 4px rgba(69,104,130,0.15), 0 4px 12px rgba(27,60,83,0.12);color:#1B3C53;font-weight:500;" onfocus="this.style.boxShadow='0 0 0 5px rgba(69,104,130,0.25), 0 4px 16px rgba(27,60,83,0.20)';this.style.borderColor='#234C6A'" onblur="this.style.boxShadow='0 0 0 4px rgba(69,104,130,0.15), 0 4px 12px rgba(27,60,83,0.12)';this.style.borderColor='#456882'">
                </div>
            </div>
            <form method="GET" id="filterForm" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5"><i class="fas fa-users mr-1 text-blue-400"></i>Batch</label>
                    <select name="batch" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 bg-white text-sm focus:ring-2 focus:ring-blue-300">
                        <option value="">All Batches</option>
                        <?php foreach ($batches as $b): ?>
                        <option value="<?= $b['batch_id'] ?>" <?= $filterBatch==$b['batch_id']?'selected':'' ?>><?= htmlspecialchars($b['batch_name']) ?> (<?= $b['batch_id'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5"><i class="fas fa-power-off mr-1 text-green-400"></i>Status</label>
                    <select name="status" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 bg-white text-sm focus:ring-2 focus:ring-blue-300">
                        <option value="">All Status</option>
                        <option value="active"   <?= $filterStatus==='active'?'selected':'' ?>>Active</option>
                        <option value="inactive" <?= $filterStatus==='inactive'?'selected':'' ?>>Inactive</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5"><i class="fas fa-tag mr-1 text-purple-400"></i>Category</label>
                    <select name="category" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 bg-white text-sm focus:ring-2 focus:ring-blue-300">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= $filterCategory==$cat?'selected':'' ?>><?= ucwords(str_replace('_',' ',$cat)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="flex-1 bg-gradient-to-r from-blue-500 to-purple-600 text-white px-4 py-2.5 rounded-xl font-semibold text-sm flex items-center justify-center gap-2 shadow-md hover:shadow-lg hover:-translate-y-0.5 transition-all">
                        <i class="fas fa-sliders-h"></i> Apply
                    </button>
                    <a href="admin_dashboard.php<?= $showArchived?'?view=archived':'' ?>" class="flex-1 flex items-center justify-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl font-semibold text-sm transition-all">
                        <i class="fas fa-redo"></i> Clear
                    </a>
                </div>
            </form>

            <!-- Active filter chips -->
            <?php if (!empty($activeChips)): ?>
            <div class="flex flex-wrap gap-2 mt-4 items-center">
                <span class="text-xs font-bold text-gray-400 uppercase tracking-wider">Active:</span>
                <?php foreach ($activeChips as $param => $chip):
                    $p = $_GET; unset($p[$param]); $p['page']=1;
                    $url = 'admin_dashboard.php?'.http_build_query($p);
                ?>
                <span class="filter-chip">
                    <?= htmlspecialchars($chip['label']) ?>: <?= htmlspecialchars($chip['val']) ?>
                    <a href="<?= htmlspecialchars($url) ?>" class="chip-x"><i class="fas fa-times" style="font-size:.55rem;"></i></a>
                </span>
                <?php endforeach; ?>
                <a href="admin_dashboard.php<?= $showArchived?'?view=archived':'' ?>" class="clear-all-chip"><i class="fas fa-times-circle"></i> Clear All</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Results header ── -->
        <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
            <div class="flex items-center gap-3">
                <h2 class="text-xl font-bold text-gray-800 flex items-center gap-2" style="font-family:'Poppins',sans-serif;">
                    <i class="fas fa-<?= $showArchived?'archive':'list-alt' ?> text-purple-600"></i>
                    <?= $showArchived ? 'Archived Tests' : 'Published Tests' ?>
                </h2>
                <span class="bg-gray-100 text-gray-700 text-sm font-bold px-3 py-1 rounded-full"><?= $totalTests ?> tests</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-gray-600 text-sm" id="selectAllWrap">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="selectAllCards" onchange="toggleSelectAll(this)" class="w-4 h-4 accent-indigo-600 cursor-pointer">
                        <span class="text-sm font-medium">Select all</span>
                    </label>
                </span>
                <div class="flex gap-1 bg-gray-100/70 rounded-xl p-1 ml-2">
                    <button id="gridViewBtn" onclick="setView('grid')" class="view-toggle-btn active p-2 rounded-lg text-sm"><i class="fas fa-th-large"></i></button>
                    <button id="listViewBtn" onclick="setView('list')" class="view-toggle-btn p-2 rounded-lg text-sm"><i class="fas fa-list"></i></button>
                </div>
            </div>
        </div>

        <!-- ── Grid View ── -->
        <form method="POST" id="mainBulkForm">
        <input type="hidden" name="filter_batch"    value="<?= htmlspecialchars($filterBatch) ?>">
        <input type="hidden" name="filter_status"   value="<?= htmlspecialchars($filterStatus) ?>">
        <input type="hidden" name="filter_category" value="<?= htmlspecialchars($filterCategory) ?>">
        <input type="hidden" name="filter_view"     value="<?= $showArchived?'archived':'' ?>">
        <input type="hidden" name="bulk_action" id="mainBulkAction">
        <input type="hidden" name="assign_batch_id" id="mainAssignBatchId">

        <div id="gridView" class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
            <?php if (empty($tests)): ?>
            <div class="col-span-full glass-card rounded-2xl p-12 text-center card-enter">
                <div class="w-20 h-20 bg-gradient-to-br from-blue-100 to-purple-100 rounded-full flex items-center justify-center mx-auto mb-5">
                    <i class="fas fa-<?= $showArchived?'archive':'file-alt' ?> text-3xl text-purple-400"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-700 mb-2"><?= $showArchived ? 'No archived tests' : 'No tests found' ?></h3>
                <p class="text-gray-500 mb-5">Adjust your filters or create a new test</p>
                <?php if (!$showArchived): ?>
                <a href="create_test.php" class="inline-flex items-center gap-2 bg-gradient-to-r from-blue-500 to-purple-600 text-white px-6 py-3 rounded-xl font-semibold text-sm shadow-md hover:shadow-lg transition-all">
                    <i class="fas fa-plus"></i> Create Test
                </a>
                <?php endif; ?>
            </div>
            <?php else: foreach ($tests as $i => $test):
                $enrolled      = $enrolledByBatch[$test['batch_id']] ?? 0;
                $attempts      = (int)$test['total_attempts'];
                $completed     = (int)$test['completed_attempts'];
                $avg           = (float)($test['avg_score'] ?? 0);
                $compRate      = $enrolled > 0 ? round($completed / $enrolled * 100) : ($attempts > 0 ? 100 : 0);
                $ringPct       = $enrolled > 0 ? round($attempts / $enrolled * 100) : 0;
                $circumference = 2 * pi() * 22;
                $dashOffset    = $circumference - ($circumference * min($ringPct,100) / 100);
                $ringColor     = $ringPct >= 80 ? '#234C6A' : ($ringPct >= 50 ? '#456882' : '#D2C1B6');
                // health
                if ($compRate >= 80)      { $healthClass='h-green'; $healthLabel='Healthy'; $healthIcon='fa-check-circle'; }
                elseif ($compRate >= 50)  { $healthClass='h-yellow'; $healthLabel='Moderate'; $healthIcon='fa-exclamation-circle'; }
                else                      { $healthClass='h-red'; $healthLabel='Low'; $healthIcon='fa-times-circle'; }
                // avg score indicator
                $threshold = $test['passing_marks'] && $test['total_marks'] ? round($test['passing_marks']/$test['total_marks']*100) : 40;
                $avgClass  = $avg >= $threshold ? 'text-green-700' : ($avg > 0 ? 'text-red-600' : 'text-gray-400');
            ?>
            <?php
                $subj = strtolower($test['subject'] ?? '');
                if (str_contains($subj,'c++') || str_contains($subj,'cpp'))    $hdrClass = 'card-header-cpp';
                elseif (str_contains($subj,'java'))   $hdrClass = 'card-header-java';
                elseif (str_contains($subj,'python')) $hdrClass = 'card-header-python';
                elseif (str_contains($subj,'web') || str_contains($subj,'html') || str_contains($subj,'css')) $hdrClass = 'card-header-web';
                else $hdrClass = 'card-header-default';
                $activeClass   = $test['is_active'] ? 'hdr-badge-active' : 'hdr-badge-inactive';
                $healthBdgClass = $healthLabel === 'Healthy' ? 'hdr-badge-healthy' : ($healthLabel === 'Low' ? 'hdr-badge-low' : 'hdr-badge-moderate');
            ?>
            <div class="test-card card-enter" style="animation-delay:<?= $i*.07 ?>s;"
                 data-id="<?= $test['id'] ?>"
                 data-searchable="<?= htmlspecialchars(strtolower($test['title'].' '.($test['subject']??'').' '.($test['description']??'').' '.($test['batch_name']??'').' '.($test['batch_id']??''))) ?>">

                <!-- ── Card Header ── -->
                <div class="card-header <?= $hdrClass ?>">
                    <div class="card-header-bg-clip">
                        <svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" style="position:absolute;inset:0;">
                            <circle cx="90%" cy="20%" r="60" fill="rgba(255,255,255,.15)"/>
                            <circle cx="10%" cy="85%" r="45" fill="rgba(255,255,255,.12)"/>
                            <circle cx="55%" cy="50%" r="30" fill="rgba(255,255,255,.08)"/>
                        </svg>
                    </div>

                    <div class="header-top-row">
                        <input type="checkbox" name="bulk_ids[]" value="<?= $test['id'] ?>" class="card-checkbox bulk-cb" onclick="event.stopPropagation(); updateBulkBar()">
                        <h3 class="card-header-title" title="<?= htmlspecialchars($test['title']) ?>">
                            <?= htmlspecialchars($test['title']) ?>
                        </h3>
                        <div style="position:relative;">
                            <button type="button" class="card-menu-btn" onclick="toggleDropdown(<?= $test['id'] ?>)">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <!-- Dropdown -->
                            <div id="dropdown-<?= $test['id'] ?>" class="absolute right-0 top-8 mt-1 w-52 bg-white rounded-xl shadow-xl z-50 hidden border border-gray-100">
                                <div class="p-2">
                                    <a href="view_test_results.php?test_id=<?= $test['id'] ?>" class="flex items-center px-3 py-2.5 text-sm text-gray-700 hover:bg-blue-50 rounded-lg gap-3">
                                        <i class="fas fa-chart-bar text-blue-500"></i> View Results
                                    </a>
                                    <a href="edit_test.php?test_id=<?= $test['id'] ?>" class="flex items-center px-3 py-2.5 text-sm text-gray-700 hover:bg-green-50 rounded-lg gap-3">
                                        <i class="fas fa-edit text-green-500"></i> Edit Test
                                    </a>
                                    <button type="button" onclick="toggleStatus(<?= $test['id'] ?>, <?= $test['is_active'] ?>)" class="flex items-center w-full px-3 py-2.5 text-sm text-gray-700 hover:bg-yellow-50 rounded-lg gap-3">
                                        <i class="fas fa-power-off text-yellow-500"></i> <?= $test['is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                    <button type="button" onclick="archiveSingle(<?= $test['id'] ?>)" class="flex items-center w-full px-3 py-2.5 text-sm text-gray-700 hover:bg-indigo-50 rounded-lg gap-3">
                                        <i class="fas fa-archive text-indigo-500"></i> Archive
                                    </button>
                                    <div class="border-t my-1.5"></div>
                                    <button type="button" onclick="confirmDelete(<?= $test['id'] ?>,'<?= htmlspecialchars(addslashes($test['title'])) ?>')" class="flex items-center w-full px-3 py-2.5 text-sm text-red-600 hover:bg-red-50 rounded-lg gap-3">
                                        <i class="fas fa-trash text-red-500"></i> Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="header-bottom-row">
                        <div class="card-header-icon"><?= strtoupper(substr($test['title'],0,1)) ?></div>
                        <div class="card-header-badges">
                            <span class="hdr-badge <?= $activeClass ?>">
                                <i class="fas fa-circle" style="font-size:.4rem;"></i> <?= $test['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                            <span class="hdr-badge <?= $healthBdgClass ?>">
                                <i class="fas <?= $healthIcon ?>" style="font-size:.5rem;"></i> <?= $healthLabel ?>
                            </span>
                            <?php if ($test['batch_name']): ?>
                            <span class="hdr-badge" title="<?= htmlspecialchars($test['batch_name']) ?>">
                                <i class="fas fa-users" style="font-size:.55rem;"></i>
                                <span style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($test['batch_name']) ?></span>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ── Card Body ── -->
                <div class="card-body">
                    <div class="flex flex-wrap gap-1.5 mb-3 mt-1">
                        <?php if ($test['subject']): ?>
                        <span class="card-tag"><i class="fas fa-book text-blue-400 text-[10px]"></i><?= htmlspecialchars($test['subject']) ?></span>
                        <?php endif; ?>
                        <span class="card-tag"><i class="fas fa-question-circle text-green-400 text-[10px]"></i><?= $test['question_count'] ?> Qs</span>
                        <?php if ($test['duration_minutes']): ?>
                        <span class="card-tag"><i class="fas fa-clock text-yellow-500 text-[10px]"></i><?= $test['duration_minutes'] ?>m</span>
                        <?php endif; ?>
                        <?php if ($test['test_category']): ?>
                        <span class="card-tag" style="background:#ede9fe;color:#6d28d9;"><i class="fas fa-tag text-[9px]"></i><?= ucwords(str_replace('_',' ',$test['test_category'])) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($test['description']): ?>
                    <p class="text-gray-400 text-xs bg-gray-50 px-3 py-2 rounded-lg mb-3 line-clamp-2 border border-gray-100">
                        <i class="fas fa-align-left mr-1 text-gray-300"></i><?= htmlspecialchars(substr($test['description'],0,100)) ?>…
                    </p>
                    <?php endif; ?>

                    <!-- Stats -->
                    <div class="flex items-center gap-2 mb-3">
                        <div class="stat-box stat-box-blue">
                            <div class="stat-num text-blue-700"><?= $attempts ?></div>
                            <div class="stat-lbl text-blue-500">Attempts</div>
                        </div>
                        <div class="stat-box stat-box-green">
                            <div class="stat-num text-green-700"><?= $completed ?></div>
                            <div class="stat-lbl text-green-500">Submitted</div>
                        </div>
                        <div class="stat-box stat-box-purple">
                            <div class="stat-num <?= $avgClass ?>"><?= $avg > 0 ? $avg.'%' : '—' ?></div>
                            <div class="stat-lbl text-purple-500">Avg Score</div>
                        </div>
                        <div class="progress-ring-wrap" title="<?= $ringPct ?>% of enrolled attempted" style="margin-left:4px;">
                            <svg width="56" height="56" viewBox="0 0 56 56">
                                <circle class="ring-bg" cx="28" cy="28" r="22"/>
                                <circle class="ring-fill" cx="28" cy="28" r="22"
                                    stroke="<?= $ringColor ?>"
                                    stroke-dasharray="<?= round($circumference,2) ?>"
                                    stroke-dashoffset="<?= round($dashOffset,2) ?>"/>
                            </svg>
                            <div class="ring-label">
                                <span style="color:<?= $ringColor ?>;font-size:.7rem;font-weight:800;"><?= $ringPct ?>%</span>
                                <span style="font-size:.55rem;color:#94a3b8;">tried</span>
                            </div>
                        </div>
                    </div>

                    <!-- ── Footer with date+time (IST) ── -->
                    <div class="border-t border-gray-100 pt-2.5 flex flex-wrap justify-between items-center text-xs text-gray-400 gap-1">
                        <span><i class="fas fa-calendar-plus mr-1 text-gray-300"></i><?= date('d M Y', strtotime($test['created_at'])) ?></span>
                        <?php if ($test['start_date']): ?>
                        <span><i class="fas fa-play mr-1 text-green-400"></i><?= date('d M Y, h:i A', strtotime($test['start_date'])) ?></span>
                        <?php endif; ?>
                        <?php if ($test['end_date']): ?>
                        <span class="font-semibold <?= strtotime($test['end_date']) < time() ? 'text-red-400' : 'text-emerald-500' ?>">
                            <i class="fas fa-stop-circle mr-1"></i><?= date('d M Y, h:i A', strtotime($test['end_date'])) ?>
                        </span>
                        <?php endif; ?>
                        <?php if (!$test['start_date'] && !$test['end_date']): ?>
                        <span class="text-gray-400 italic">No time restrictions</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- ── List View ── -->
        <div id="listView" class="hidden">
            <div class="glass-card rounded-2xl overflow-hidden shadow-xl">
                <div class="overflow-x-auto scrollbar-thin">
                    <table class="w-full">
                        <thead>
                            <tr style="background:linear-gradient(135deg,#f7f5f3,#e8ecef);border-bottom:2px solid #D2C1B6;">
                                <th class="px-4 py-3.5 text-left w-10"><input type="checkbox" id="selectAllList" onchange="toggleSelectAll(this)" class="w-4 h-4 accent-indigo-600 cursor-pointer"></th>
                                <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider" style="color:#234C6A;">Test</th>
                                <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider" style="color:#234C6A;">Batch</th>
                                <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider" style="color:#234C6A;">Status</th>
                                <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider" style="color:#234C6A;">Health</th>
                                <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider" style="color:#234C6A;">Attempts</th>
                                <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider" style="color:#234C6A;">Progress</th>
                                <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-wider" style="color:#234C6A;">Avg Score</th>
                                <th class="px-4 py-3.5 text-center text-xs font-bold uppercase tracking-wider" style="color:#234C6A;">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                        <?php foreach ($tests as $i => $test):
                            $enrolled  = $enrolledByBatch[$test['batch_id']] ?? 0;
                            $completed = (int)$test['completed_attempts'];
                            $attempts  = (int)$test['total_attempts'];
                            $avg       = (float)($test['avg_score'] ?? 0);
                            $compRate  = $enrolled > 0 ? round($completed/$enrolled*100) : ($attempts>0?100:0);
                            $ringPct   = $enrolled > 0 ? round($attempts/$enrolled*100) : 0;
                            $healthClass = $compRate>=80?'h-green':($compRate>=50?'h-yellow':'h-red');
                            $healthLabel = $compRate>=80?'Healthy':($compRate>=50?'Moderate':'Low');
                            $circumference = 2*pi()*16;
                            $dashOffset2   = $circumference - ($circumference*min($ringPct,100)/100);
                            $ringColor2 = $ringPct>=80?'#234C6A':($ringPct>=50?'#456882':'#D2C1B6');
                        ?>
                        <tr class="hover:bg-blue-50/30 transition-colors" data-id="<?= $test['id'] ?>"
                            data-searchable="<?= htmlspecialchars(strtolower($test['title'].' '.($test['subject']??''))) ?>">
                            <td class="px-4 py-3"><input type="checkbox" name="bulk_ids[]" value="<?= $test['id'] ?>" class="list-cb bulk-cb w-4 h-4 accent-indigo-600 cursor-pointer" onchange="updateBulkBar()"></td>
                            <td class="px-4 py-3">
                                <div class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($test['title']) ?></div>
                                <div class="text-xs text-gray-400"><?= htmlspecialchars($test['subject'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($test['batch_name'] ?? $test['batch_id'] ?? '—') ?></td>
                            <td class="px-4 py-3"><span class="px-2.5 py-1 text-xs rounded-full <?= $test['is_active']?'status-active':'status-inactive' ?>"><?= $test['is_active']?'Active':'Inactive' ?></span></td>
                            <td class="px-4 py-3"><span class="health-badge <?= $healthClass ?>"><?= $healthLabel ?></span></td>
                            <td class="px-4 py-3 text-sm font-bold text-gray-700"><?= $attempts ?> / <?= $completed ?></td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="relative" style="width:38px;height:38px;" title="<?= $ringPct ?>% enrolled attempted">
                                        <svg width="38" height="38" viewBox="0 0 38 38" style="transform:rotate(-90deg)">
                                            <circle fill="none" stroke="#e2e8f0" stroke-width="4" cx="19" cy="19" r="16"/>
                                            <circle fill="none" stroke="<?= $ringColor2 ?>" stroke-width="4" stroke-linecap="round" cx="19" cy="19" r="16"
                                                stroke-dasharray="<?= round($circumference,2) ?>"
                                                stroke-dashoffset="<?= round($dashOffset2,2) ?>"/>
                                        </svg>
                                        <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:.58rem;font-weight:800;color:<?= $ringColor2 ?>;"><?= $ringPct ?>%</div>
                                    </div>
                                    <span class="text-xs text-gray-400"><?= $ringPct ?>% tried</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm font-bold <?= $avg>0?($avg>=40?'text-green-600':'text-red-600'):'text-gray-300' ?>"><?= $avg>0?$avg.'%':'—' ?></td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex items-center justify-center gap-1">
                                    <button type="button" onclick="toggleListRow(<?= $test['id'] ?>)" class="p-1.5 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 text-xs" title="Expand"><i class="fas fa-chevron-down" id="expand-icon-<?= $test['id'] ?>"></i></button>
                                    <a href="view_test_results.php?test_id=<?= $test['id'] ?>" class="p-1.5 rounded-lg bg-purple-50 text-purple-600 hover:bg-purple-100 text-xs" title="Results"><i class="fas fa-chart-bar"></i></a>
                                    <a href="edit_test.php?test_id=<?= $test['id'] ?>" class="p-1.5 rounded-lg bg-green-50 text-green-600 hover:bg-green-100 text-xs" title="Edit"><i class="fas fa-edit"></i></a>
                                    <button type="button" onclick="toggleStatus(<?= $test['id'] ?>,<?= $test['is_active'] ?>)" class="p-1.5 rounded-lg bg-yellow-50 text-yellow-600 hover:bg-yellow-100 text-xs" title="Toggle"><i class="fas fa-power-off"></i></button>
                                    <button type="button" onclick="confirmDelete(<?= $test['id'] ?>,'<?= htmlspecialchars(addslashes($test['title'])) ?>')" class="p-1.5 rounded-lg bg-red-50 text-red-600 hover:bg-red-100 text-xs" title="Delete"><i class="fas fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                        <!-- Expandable detail row -->
                        <tr class="expand-row" id="expand-<?= $test['id'] ?>">
                            <td colspan="9" class="px-8 py-5">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <div>
                                        <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Description</div>
                                        <div id="desc-display-<?= $test['id'] ?>" class="text-sm text-gray-600 bg-white p-3 rounded-xl border border-gray-100">
                                            <?= $test['description'] ? htmlspecialchars($test['description']) : '<span class="text-gray-300 italic">No description</span>' ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Quick Info</div>
                                        <div class="grid grid-cols-2 gap-2 text-sm">
                                            <div class="bg-white p-2.5 rounded-xl border border-gray-100"><span class="text-gray-400 text-xs">Questions</span><br><span class="font-bold text-gray-700"><?= $test['question_count'] ?></span></div>
                                            <div class="bg-white p-2.5 rounded-xl border border-gray-100"><span class="text-gray-400 text-xs">Duration</span><br><span class="font-bold text-gray-700"><?= $test['duration_minutes'] ?> min</span></div>
                                            <div class="bg-white p-2.5 rounded-xl border border-gray-100"><span class="text-gray-400 text-xs">Total Marks</span><br><span class="font-bold text-gray-700"><?= $test['total_marks'] ?></span></div>
                                            <div class="bg-white p-2.5 rounded-xl border border-gray-100"><span class="text-gray-400 text-xs">Passing</span><br><span class="font-bold text-gray-700"><?= $test['passing_marks'] ?></span></div>
                                            <?php if ($test['start_date']): ?>
                                            <div class="bg-white p-2.5 rounded-xl border border-gray-100 col-span-2"><span class="text-gray-400 text-xs">Availability</span><br><span class="font-bold text-gray-700">
                                                <i class="fas fa-play text-green-500 mr-1"></i><?= date('d M Y, h:i A', strtotime($test['start_date'])) ?>
                                                <?php if ($test['end_date']): ?>
                                                → <i class="fas fa-stop-circle text-red-500 mr-1"></i><?= date('d M Y, h:i A', strtotime($test['end_date'])) ?>
                                                <?php endif; ?>
                                            </span></div>
                                            <?php elseif ($test['end_date']): ?>
                                            <div class="bg-white p-2.5 rounded-xl border border-gray-100 col-span-2"><span class="text-gray-400 text-xs">Availability</span><br><span class="font-bold text-gray-700">
                                                Ends: <i class="fas fa-stop-circle text-red-500 mr-1"></i><?= date('d M Y, h:i A', strtotime($test['end_date'])) ?>
                                            </span></div>
                                            <?php else: ?>
                                            <div class="bg-white p-2.5 rounded-xl border border-gray-100 col-span-2"><span class="text-gray-400 text-xs">Availability</span><br><span class="font-bold text-gray-700 text-gray-400">Always active (no time limits)</span></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <!-- Inline edit -->
                                <div class="mt-4 border-t border-indigo-100 pt-4">
                                    <div class="text-xs font-bold text-indigo-500 uppercase tracking-wider mb-3"><i class="fas fa-pen mr-1"></i>Inline Edit</div>
                                    <div class="flex flex-wrap gap-3">
                                        <div class="flex-1 min-w-[200px]">
                                            <label class="text-xs text-gray-500 mb-1 block">Title</label>
                                            <input type="text" class="inline-edit-input" id="inline-title-<?= $test['id'] ?>" value="<?= htmlspecialchars($test['title']) ?>">
                                        </div>
                                        <div class="flex-1 min-w-[200px]">
                                            <label class="text-xs text-gray-500 mb-1 block">Description</label>
                                            <input type="text" class="inline-edit-input" id="inline-desc-<?= $test['id'] ?>" value="<?= htmlspecialchars($test['description'] ?? '') ?>">
                                        </div>
                                        <div class="flex items-end">
                                            <button type="button" onclick="saveInlineEdit(<?= $test['id'] ?>)" class="px-4 py-2.5 bg-gradient-to-r from-indigo-500 to-purple-500 text-white rounded-xl text-sm font-semibold flex items-center gap-2 hover:from-indigo-600 hover:to-purple-600 transition-all">
                                                <i class="fas fa-save"></i> Save
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        </form>

        <!-- ── Pagination ── -->
        <?php if ($totalPages > 1): ?>
        <div class="flex items-center justify-between mt-7 flex-wrap gap-3">
            <span class="text-gray-600 text-sm">Showing <?= ($offset+1) ?> – <?= min($offset+$perPage,$totalTests) ?> of <?= $totalTests ?> tests</span>
            <div class="flex gap-1.5">
                <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>" class="page-btn"><i class="fas fa-chevron-left text-xs"></i></a>
                <?php endif; ?>
                <?php for ($pg = max(1,$page-2); $pg <= min($totalPages,$page+2); $pg++): ?>
                <a href="?<?= http_build_query(array_merge($_GET,['page'=>$pg])) ?>" class="page-btn <?= $pg==$page?'active':'' ?>"><?= $pg ?></a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>" class="page-btn"><i class="fas fa-chevron-right text-xs"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
// ── Toast system ──
function showToast(msg, type='success') {
    const wrap = document.getElementById('toastWrap');
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    const icons = {success:'fa-check-circle',error:'fa-times-circle',info:'fa-info-circle',warning:'fa-exclamation-circle'};
    t.innerHTML = `<i class="fas ${icons[type]||'fa-info-circle'}"></i><span>${msg}</span><button onclick="this.parentElement.remove()" class="ml-auto opacity-60 hover:opacity-100"><i class="fas fa-times text-xs"></i></button>`;
    wrap.appendChild(t);
    setTimeout(()=>{ t.style.animation='toastIn .4s ease reverse'; setTimeout(()=>t.remove(),350); },4000);
}
// Auto-remove PHP toast
const phpToast = document.getElementById('phpToast');
if (phpToast) setTimeout(()=>{ phpToast.style.animation='toastIn .4s ease reverse'; setTimeout(()=>phpToast.remove(),350); },4000);

// ── Dropdown ──
function toggleDropdown(id) {
    const d = document.getElementById('dropdown-'+id);
    document.querySelectorAll('[id^="dropdown-"]').forEach(x=>{ if(x.id!=='dropdown-'+id) x.classList.add('hidden'); });
    d.classList.toggle('hidden');
}
document.addEventListener('click', e=>{
    if (!e.target.closest('[id^="dropdown-"]') && !e.target.closest('button[onclick^="toggleDropdown"]')) {
        document.querySelectorAll('[id^="dropdown-"]').forEach(d=>d.classList.add('hidden'));
    }
});

// ── Modal ──
function closeModal(id){ document.getElementById(id).style.display='none'; document.body.style.overflow='auto'; }
function openModal(id) { document.getElementById(id).style.display='block'; document.body.style.overflow='hidden'; }
window.addEventListener('click', e=>{ document.querySelectorAll('.modal').forEach(m=>{ if(e.target===m) closeModal(m.id); }); });

// ── Delete ──
function confirmDelete(id, title) {
    document.getElementById('deleteMessage').textContent = `Delete "${title}"?`;
    document.getElementById('confirmDeleteBtn').href = `admin_dashboard.php?delete_test=${id}`;
    openModal('deleteModal');
}

// ── Toggle status (AJAX) ──
function toggleStatus(id, current) {
    fetch('admin_dashboard.php', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`toggle_id=${id}`
    }).then(r=>r.json()).then(d=>{
        if (d.success) { showToast(d.is_active?'Test activated!':'Test deactivated!', d.is_active?'success':'info'); setTimeout(()=>location.reload(),1200); }
        else showToast('Failed to toggle status','error');
    }).catch(()=>showToast('Network error','error'));
}

// ── Archive single ──
function archiveSingle(id) {
    if (!confirm('Archive this test? You can restore it from the Archived tab.')) return;
    const f = document.createElement('form'); f.method='POST'; f.style.display='none';
    f.innerHTML = `<input name="bulk_ids[]" value="${id}"><input name="bulk_action" value="archive">`;
    document.body.appendChild(f); f.submit();
}

// ── Bulk selection ──
function updateBulkBar() {
    const checked = document.querySelectorAll('.bulk-cb:checked');
    const bar = document.getElementById('bulkBar');
    document.getElementById('bulkCount').textContent = checked.length + ' selected';
    bar.classList.toggle('visible', checked.length > 0);
    checked.length > 0 ? document.getElementById('selectAllCards').indeterminate = true : (document.getElementById('selectAllCards').indeterminate = false);
}
function toggleSelectAll(cb) {
    document.querySelectorAll('.bulk-cb').forEach(c=>{
        const row = c.closest('tr') || c.closest('.test-card');
        c.checked = cb.checked;
        if (row) row.classList.toggle('selected-card', cb.checked);
    });
    updateBulkBar();
}
function clearSelection() {
    document.querySelectorAll('.bulk-cb').forEach(c=>{ c.checked=false; (c.closest('.test-card')||c.closest('tr'))?.classList.remove('selected-card'); });
    document.getElementById('selectAllCards').checked = false;
    updateBulkBar();
}
function submitBulk(action, confirmMsg) {
    const ids = [...document.querySelectorAll('.bulk-cb:checked')].map(c=>c.value);
    if (!ids.length) { showToast('No tests selected','warning'); return; }
    if (confirmMsg && !confirm(confirmMsg)) return;
    document.getElementById('mainBulkAction').value = action;
    const container = document.getElementById('mainBulkForm');
    container.querySelectorAll('input[name="bulk_ids[]"]').forEach(x=>x.remove());
    ids.forEach(id=>{ const i=document.createElement('input'); i.type='hidden'; i.name='bulk_ids[]'; i.value=id; container.appendChild(i); });
    document.getElementById('mainBulkForm').submit();
}

// ── Assign batch modal ──
let selectedAssignBatch = '';
function openAssignModal() {
    const ids = [...document.querySelectorAll('.bulk-cb:checked')].map(c=>c.value);
    if (!ids.length) { showToast('Select tests first','warning'); return; }
    selectedAssignBatch = '';
    document.querySelectorAll('.batch-opt').forEach(o=>o.classList.remove('sel'));
    document.getElementById('assignConfirmBtn').disabled = true;
    openModal('assignBatchModal');
}
function selectBatch(id, name) {
    selectedAssignBatch = id;
    document.querySelectorAll('.batch-opt').forEach(o=>o.classList.remove('sel'));
    event.currentTarget.classList.add('sel');
    document.getElementById('assignConfirmBtn').disabled = false;
}
function confirmAssignBatch() {
    if (!selectedAssignBatch) return;
    document.getElementById('mainBulkAction').value = 'assign_batch';
    document.getElementById('mainAssignBatchId').value = selectedAssignBatch;
    const ids = [...document.querySelectorAll('.bulk-cb:checked')].map(c=>c.value);
    ids.forEach(id=>{ const i=document.createElement('input'); i.type='hidden'; i.name='bulk_ids[]'; i.value=id; document.getElementById('mainBulkForm').appendChild(i); });
    closeModal('assignBatchModal');
    document.getElementById('mainBulkForm').submit();
}

// ── View toggle ──
let currentView = 'grid';
function setView(v) {
    currentView = v;
    document.getElementById('gridView').classList.toggle('hidden', v!=='grid');
    document.getElementById('listView').classList.toggle('hidden', v!=='list');
    const gridBtn = document.getElementById('gridViewBtn');
    const listBtn = document.getElementById('listViewBtn');
    if (v==='grid') {
        gridBtn.className = 'view-toggle-btn active p-2 rounded-lg text-sm';
        listBtn.className = 'view-toggle-btn p-2 rounded-lg text-sm';
    } else {
        gridBtn.className = 'view-toggle-btn p-2 rounded-lg text-sm';
        listBtn.className = 'view-toggle-btn active p-2 rounded-lg text-sm';
    }
}

// ── Live search ──
document.getElementById('searchInput').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('[data-searchable]').forEach(el=>{
        const match = !q || el.dataset.searchable.includes(q);
        el.style.display = match ? '' : 'none';
        const expandRow = document.getElementById('expand-'+el.dataset.id);
        if (expandRow && !match) expandRow.classList.remove('open');
    });
});

// ── KPI quick filter ──
function applyStatusFilter(val) {
    const url = new URL(window.location);
    if (val) url.searchParams.set('status', val); else url.searchParams.delete('status');
    url.searchParams.delete('page');
    window.location = url.toString();
}

// ── Expandable list rows ──
function toggleListRow(id) {
    const row = document.getElementById('expand-'+id);
    const icon = document.getElementById('expand-icon-'+id);
    const open = row.classList.toggle('open');
    icon.className = open ? 'fas fa-chevron-up' : 'fas fa-chevron-down';
}

// ── Inline edit (AJAX) ──
function saveInlineEdit(id) {
    const title = document.getElementById('inline-title-'+id).value.trim();
    const desc  = document.getElementById('inline-desc-'+id).value.trim();
    if (!title) { showToast('Title cannot be empty','error'); return; }
    const body = new URLSearchParams({ inline_edit_id: id, inline_title: title, inline_desc: desc });
    fetch('admin_dashboard.php', { method:'POST', body })
        .then(r=>r.json()).then(d=>{
            if (d.success) {
                showToast('Saved successfully!','success');
                document.querySelectorAll(`[data-id="${id}"] .card-header-title`).forEach(el=>el.textContent=title);
                document.getElementById('desc-display-'+id).textContent = desc || 'No description';
            } else showToast(d.msg||'Save failed','error');
        }).catch(()=>showToast('Network error','error'));
}

// ── Card enter animation delays ──
document.querySelectorAll('.card-enter').forEach((c,i)=>c.style.animationDelay=`${i*.06}s`);
</script>
</body>
</html>