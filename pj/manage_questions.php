<?php
// manage_questions.php — กรองตามกลุ่ม + ลบทั้งหมด + เพิ่มหลายข้อ (ลำดับ) + Export ตามลำดับ + Bulk Delete
require 'db_connect.php';
if (!isset($pdo)) { die('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบไฟล์ db_connect.php'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Ensure schema: add order_in_group if not exists ---------- */
function hasColumn(PDO $pdo, $table, $col){
    $st = $pdo->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table,$col]);
    return (int)$st->fetchColumn() > 0;
}
function tableExists(PDO $pdo, $table){
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}
if (!hasColumn($pdo, 'questions', 'order_in_group')) {
    $pdo->exec("ALTER TABLE questions ADD COLUMN order_in_group INT NOT NULL DEFAULT 0");
}

/* ---------- Helpers ---------- */
function nextOrderForGroup(PDO $pdo, $group_id){
    $st = $pdo->prepare("SELECT COALESCE(MAX(order_in_group),0)+1 FROM questions WHERE group_id = ?");
    $st->execute([$group_id]);
    return (int)$st->fetchColumn();
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- EXPORT CSV ---------- */
if (isset($_GET['export']) && $_GET['export'] === 'questions') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="questions_'.date('Ymd_His').'.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['รหัสคำถาม','ข้อความคำถาม','รหัสกลุ่ม','ชื่อกลุ่ม','ลำดับในกลุ่ม']);
    $sql = "SELECT q.question_id, q.question_text, g.group_id, g.group_name, q.order_in_group
            FROM questions q
            LEFT JOIN subject_groups g ON q.group_id = g.group_id
            ORDER BY g.group_name ASC, q.order_in_group ASC, q.question_id ASC";
    $stmt = $pdo->query($sql);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [$r['question_id'],$r['question_text'],$r['group_id'],$r['group_name'],$r['order_in_group']]);
    }
    fclose($out); exit;
}

/* ---------- STATE ---------- */
$question_to_edit = null;
$message = '';
$message_type = 'success';

/* ---------- NUKE (ลบทั้งหมด) ---------- */
if (isset($_POST['nuke_all'])) {
    try {
        $pdo->beginTransaction();

        // ลบตารางลูกที่อ้างถึง questions ก่อน (เพิ่มได้ถ้ามีตารางลูกอื่น)
        if (tableExists($pdo, 'quiz_answers') && hasColumn($pdo, 'quiz_answers', 'question_id')) {
            $pdo->exec("DELETE FROM quiz_answers");
        }

        try {
            $pdo->exec("TRUNCATE TABLE questions");
        } catch (Throwable $te) {
            $pdo->exec("DELETE FROM questions");
        }

        $pdo->commit();
        $message = "ลบคำถามทั้งหมดเรียบร้อยแล้ว";
        $message_type = 'success';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "ลบไม่สำเร็จ: ".$e->getMessage();
        $message_type = 'error';
    }
}

/* ---------- BULK DELETE (ลบหลายข้อที่เลือก) ---------- */
if (isset($_POST['bulk_delete_selected'])) {
    try {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || count($ids) === 0) {
            header("Location: manage_questions.php?message=" . urlencode("กรุณาเลือกคำถามอย่างน้อย 1 ข้อเพื่อทำการลบ") . "&type=error"); exit;
        }
        // กรองเป็นจำนวนเต็ม
        $ids = array_values(array_filter(array_map('intval', $ids), fn($v)=>$v>0));
        if (count($ids) === 0) {
            header("Location: manage_questions.php?message=" . urlencode("รายการที่เลือกไม่ถูกต้อง") . "&type=error"); exit;
        }

        $pdo->beginTransaction();

        // ลบลูกก่อน
        if (tableExists($pdo, 'quiz_answers') && hasColumn($pdo, 'quiz_answers', 'question_id')) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stChild = $pdo->prepare("DELETE FROM quiz_answers WHERE question_id IN ($in)");
            $stChild->execute($ids);
        }

        // ลบคำถาม
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stDel = $pdo->prepare("DELETE FROM questions WHERE question_id IN ($in)");
        $stDel->execute($ids);
        $affected = $stDel->rowCount();

        if ($affected === 0) {
            $pdo->rollBack();
            header("Location: manage_questions.php?message=" . urlencode("ลบไม่สำเร็จ: ไม่พบคำถามที่เลือก หรืออาจติดข้อจำกัด FK") . "&type=error"); exit;
        }

        $pdo->commit();
        header("Location: manage_questions.php?message=" . urlencode("ลบคำถามที่เลือกสำเร็จ {$affected} ข้อ") . "&type=success"); exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: manage_questions.php?message=" . urlencode("เกิดข้อผิดพลาดในการลบหลายข้อ: ".$e->getMessage()) . "&type=error"); exit;
    }
}

/* ---------- DELETE (single) ---------- */
try {
    if (isset($_GET['delete_question'])) {
        $qid = (int)$_GET['delete_question'];
        if ($qid <= 0) {
            header("Location: manage_questions.php?message=" . urlencode("รหัสคำถามไม่ถูกต้อง") . "&type=error"); exit;
        }

        $pdo->beginTransaction();

        // ลบลูกก่อน
        if (tableExists($pdo, 'quiz_answers') && hasColumn($pdo, 'quiz_answers', 'question_id')) {
            $stDelChild = $pdo->prepare("DELETE FROM quiz_answers WHERE question_id = ?");
            $stDelChild->execute([$qid]);
        }

        $stDel = $pdo->prepare("DELETE FROM questions WHERE question_id = ?");
        $stDel->execute([$qid]);
        $affected = $stDel->rowCount();

        if ($affected === 0) {
            $pdo->rollBack();
            header("Location: manage_questions.php?message=" . urlencode("ไม่พบคำถามที่ต้องการลบ หรืออาจติดข้อจำกัด FK") . "&type=error"); exit;
        }

        $pdo->commit();
        header("Location: manage_questions.php?message=" . urlencode("ลบคำถามสำเร็จ!") . "&type=success"); exit;
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header("Location: manage_questions.php?message=" . urlencode("เกิดข้อผิดพลาดในการลบ: " . $e->getMessage()) . "&type=error"); exit;
}

/* ---------- IMPORT CSV (Bulk) ---------- */
try {
    if (isset($_POST['import_questions_csv'])) {
        $fixed_group_id = trim($_POST['group_id_for_import'] ?? '');

        if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            header("Location: manage_questions.php?message=" . urlencode("อัปโหลดไฟล์ไม่สำเร็จ") . "&type=error"); exit;
        }
        if ($_FILES['csv']['size'] > 2*1024*1024) {
            header("Location: manage_questions.php?message=" . urlencode("ไฟล์ใหญ่เกิน 2MB") . "&type=error"); exit;
        }

        $group_map = [];
        $rows = $pdo->query("SELECT group_id, group_name FROM subject_groups")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) { $group_map[trim($r['group_name'])] = (string)$r['group_id']; }

        $hasHeader = !empty($_POST['has_header']);

        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) { header("Location: manage_questions.php?message=" . urlencode("เปิดไฟล์ไม่สำเร็จ") . "&type=error"); exit; }

        $line = 0; $ok = 0; $skipped = 0; $errors = [];
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("INSERT INTO questions (question_text, group_id, order_in_group) VALUES (?, ?, ?)");
            $perGroupNext = [];

            while (($row = fgetcsv($fh)) !== false) {
                $line++;
                if ($line === 1 && $hasHeader) { continue; }

                $qtext = trim($row[0] ?? '');
                if ($qtext === '') { $skipped++; $errors[]="บรรทัด $line: ไม่มีข้อความคำถาม"; continue; }

                $gid = $fixed_group_id !== '' ? $fixed_group_id : null;
                if ($gid === null) {
                    $second = trim($row[1] ?? '');
                    if ($second !== '') {
                        if (ctype_digit($second)) { $gid = $second; }
                        else if (isset($group_map[$second])) { $gid = $group_map[$second]; }
                    }
                }
                if ($gid === null || $gid === '') {
                    $skipped++; $errors[]="บรรทัด $line: ไม่พบกลุ่ม (เลือกกลุ่มคงที่ หรือใส่คอลัมน์ที่ 2 เป็น group_id/ชื่อกลุ่ม)";
                    continue;
                }

                if (!isset($perGroupNext[$gid])) { $perGroupNext[$gid] = nextOrderForGroup($pdo, $gid); }
                $order = $perGroupNext[$gid]++;
                $st->execute([$qtext, $gid, $order]); $ok++;
            }
            fclose($fh);
            $pdo->commit();

            $msg = "นำเข้าคำถามสำเร็จ {$ok} รายการ";
            if ($skipped > 0) { $msg .= " (ข้าม {$skipped})"; }
            if (!empty($errors)) { $msg .= " | หมายเหตุ: " . implode(' ; ', $errors); }
            header("Location: manage_questions.php?message=" . urlencode($msg) . "&type=success"); exit;
        } catch (Throwable $e) {
            fclose($fh);
            $pdo->rollBack();
            header("Location: manage_questions.php?message=" . urlencode("เกิดข้อผิดพลาด: ".$e->getMessage()) . "&type=error"); exit;
        }
    }
} catch (PDOException $e) {
    $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    $message_type = 'error';
}

/* ---------- ADD / UPDATE ---------- */
try {
    if (isset($_POST['add_question'])) {
        $gid = $_POST['group_id_for_question'];
        $order = nextOrderForGroup($pdo, $gid);
        $stmt = $pdo->prepare("INSERT INTO questions (question_text, group_id, order_in_group) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['question_text'], $gid, $order]);
        header("Location: manage_questions.php?message=" . urlencode("เพิ่มคำถามสำเร็จ!") . "&type=success"); exit;
    }
    if (isset($_POST['update_question'])) {
        $stmt = $pdo->prepare("UPDATE questions SET question_text = ?, group_id = ? WHERE question_id = ?");
        $stmt->execute([$_POST['question_text'], $_POST['group_id_for_question'], $_POST['question_id']]);
        header("Location: manage_questions.php?message=" . urlencode("แก้ไขคำถามสำเร็จ!") . "&type=success"); exit;
    }

    /* ---------- ADD BULK (ตามลำดับบรรทัด) ---------- */
    if (isset($_POST['add_bulk_questions'])) {
        $mode = $_POST['bulk_mode'] ?? 'one';
        $raw  = trim($_POST['bulk_questions'] ?? '');
        if ($raw === '') {
            header("Location: manage_questions.php?message=" . urlencode("กรุณาวางคำถามอย่างน้อย 1 บรรทัด") . "&type=error"); exit;
        }
        $lines = array_values(array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", $raw)), fn($s)=>$s!==''));

        $gAll = $pdo->query("SELECT group_id, group_name FROM subject_groups")->fetchAll(PDO::FETCH_KEY_PAIR);
        $perGroupNext = [];
        $ins = $pdo->prepare("INSERT INTO questions (question_text, group_id, order_in_group) VALUES (?, ?, ?)");

        $pdo->beginTransaction();
        try {
            $ok=0; $ignored=0;

            if ($mode === 'one') {
                $gid = $_POST['bulk_group_id'] ?? '';
                if ($gid===''){ throw new Exception("กรุณาเลือกกลุ่มสำหรับโหมดใส่ลงกลุ่มเดียว"); }
                if (!isset($perGroupNext[$gid])) $perGroupNext[$gid] = nextOrderForGroup($pdo, $gid);
                foreach ($lines as $qtext){
                    $ins->execute([$qtext, $gid, $perGroupNext[$gid]++]);
                    $ok++;
                }
            } else { /* mode === 'three' แบ่ง 13/10/10 */
                $gid1 = $_POST['bulk_group1'] ?? '';
                $gid2 = $_POST['bulk_group2'] ?? '';
                $gid3 = $_POST['bulk_group3'] ?? '';
                if ($gid1==='' || $gid2==='' || $gid3===''){ throw new Exception("กรุณาเลือกกลุ่มให้ครบทั้ง 3 กลุ่ม"); }

                $quota = [ [$gid1,13], [$gid2,10], [$gid3,10] ];
                $idx = 0;
                foreach ($quota as [$gid,$cnt]) {
                    if (!isset($perGroupNext[$gid])) $perGroupNext[$gid] = nextOrderForGroup($pdo, $gid);
                    for ($i=0; $i<$cnt; $i++) {
                        if (!isset($lines[$idx])) break 2;
                        $qtext = $lines[$idx++];
                        $ins->execute([$qtext, $gid, $perGroupNext[$gid]++]);
                        $ok++;
                    }
                }
                if (count($lines) > 33) {
                    $ignored = count($lines) - 33;
                }
            }

            $pdo->commit();
            $msg = "เพิ่มคำถามแบบกลุ่มสำเร็จ {$ok} ข้อ";
            if ($ignored>0) $msg .= " (ตัดทิ้ง {$ignored} ข้อเกินกำหนด 13/10/10)";
            header("Location: manage_questions.php?message=" . urlencode($msg) . "&type=success"); exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            header("Location: manage_questions.php?message=" . urlencode("เพิ่มแบบกลุ่มไม่สำเร็จ: ".$e->getMessage()) . "&type=error"); exit;
        }
    }

} catch (PDOException $e) {
    $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    $message_type = 'error';
}

/* ---------- MESSAGE ---------- */
if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $message_type = $_GET['type'] ?? 'success';
}

/* ---------- EDIT FETCH ---------- */
try {
    if (isset($_GET['edit_question'])) {
        $stmt = $pdo->prepare("SELECT * FROM questions WHERE question_id = ?");
        $stmt->execute([$_GET['edit_question']]);
        $question_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $message = "ไม่สามารถดึงข้อมูลเพื่อแก้ไขได้: " . $e->getMessage();
    $message_type = 'error';
}

/* ---------- GROUP FILTER (server-side) ---------- */
$groupParam = isset($_GET['group']) && $_GET['group'] !== '' ? trim($_GET['group']) : '';

/* ---------- LIST FETCH ---------- */
try {
    $groups = $pdo->query("SELECT * FROM subject_groups ORDER BY group_name ASC")->fetchAll(PDO::FETCH_ASSOC);

    $questions_sql = "
        SELECT q.question_id, q.question_text, g.group_name, g.group_id, q.order_in_group
        FROM questions q
        LEFT JOIN subject_groups g ON q.group_id = g.group_id
    ";
    $params = [];
    if ($groupParam !== '') {
        $questions_sql .= " WHERE q.group_id = :gid ";
        $params[':gid'] = $groupParam;
    }
    $questions_sql .= " ORDER BY g.group_name ASC, q.order_in_group ASC, q.question_id DESC";

    $stmtQ = $pdo->prepare($questions_sql);
    foreach ($params as $k=>$v){ $stmtQ->bindValue($k, $v); }
    $stmtQ->execute();
    $questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

    $stats = ['questions' => count($questions)];
} catch (PDOException $e) {
    $message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $message_type = 'error';
    $groups = [];
    $questions = [];
    $stats = ['questions' => 0];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>จัดการคำถาม</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
<style>
:root{
  --navy:#0f1419;--steel:#1f2937;--slate:#334155;--sky:#0ea5e9;--cyan:#06b6d4;--emerald:#10b981;
  --amber:#f59e0b;--rose:#e11d48;--text:#f1f5f9;--muted:#94a3b8;--subtle:#64748b;--border:#374151;
  --glass:rgba(15,20,25,.85);--overlay:rgba(0,0,0,.6);
  --shadow:0 4px 20px rgba(0,0,0,.15);--shadow-lg:0 8px 32px rgba(0,0,0,.25);
  --grad-primary:linear-gradient(135deg,var(--sky),var(--cyan));
  --grad-secondary:linear-gradient(135deg,var(--slate),var(--steel));
  --grad-accent:linear-gradient(135deg,var(--amber),#ea580c);
  --grad-danger:linear-gradient(135deg,var(--rose),#be123c);
  --grad-success:linear-gradient(135deg,var(--emerald),#059669);
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{
  font-family:'Sarabun',system-ui,Segoe UI,Roboto,Arial;
  color:var(--text);
  background:
    radial-gradient(1200px 800px at 20% 0%, rgba(14,165,233,.08), transparent 65%),
    radial-gradient(1000px 600px at 80% 100%, rgba(6,182,212,.06), transparent 65%),
    conic-gradient(from 230deg at 0% 50%, #0f1419, #1e2937, #0f1419);
  min-height:100vh; line-height:1.65;
}
.topbar{position:sticky;top:0;z-index:50;display:flex;justify-content:space-between;align-items:center;gap:12px;
  padding:16px 20px;border-bottom:1px solid var(--border);
  background:var(--glass);backdrop-filter:blur(20px);box-shadow:var(--shadow);
}
.brand{display:flex;align-items:center;gap:12px}
.logo{width:42px;height:42px;border-radius:12px;background:var(--grad-primary);display:grid;place-items:center}
.title{font-weight:800;font-size:18px;background:var(--grad-primary);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.nav-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.btn{padding:10px 14px;border-radius:12px;border:1px solid var(--border);text-decoration:none;color:var(--text);font-weight:700;
  display:inline-flex;align-items:center;gap:8px;cursor:pointer;transition:.2s ease; background:var(--grad-secondary);}
.btn:hover{transform:translateY(-1px);box-shadow:var(--shadow)}
.btn-primary{background:var(--grad-primary);color:#fff;border-color:#0ea5e9}
.btn-danger{background:var(--grad-danger);color:#fff;border-color:#a31d33}
.container{max-width:1250px;margin:18px auto;padding:16px}
.grid{display:grid;grid-template-columns:1.1fr .9fr;gap:16px}
@media (max-width: 1024px){ .grid{grid-template-columns:1fr} }
.card{background:var(--glass);border:1px solid var(--border);border-radius:20px;padding:18px;backdrop-filter:blur(20px);box-shadow:var(--shadow-lg);position:relative;overflow:hidden}
.card h2{font-size:20px;font-weight:800;margin-bottom:12px;background:var(--grad-primary);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.sub{color:var(--muted);font-size:13px;margin-bottom:10px}
.stats{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px}
.badge{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,.04);font-weight:700;font-size:12px}
.badge i{opacity:.85}
.input,.select,textarea,input[type=file]{
  width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--border);
  background:rgba(15,20,25,.6);color:var(--text);outline:none;transition:.2s;
}
.input:focus,.select:focus,textarea:focus{border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.25)}
textarea{min-height:120px}
.list{max-height:640px;overflow:auto;border-radius:14px}
.item{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;background:rgba(255,255,255,.04);border:1px solid var(--border);
  border-left:4px solid #0ea5e9;border-radius:14px;padding:14px;margin-bottom:12px}
.item strong{display:block}
.item small{color:var(--muted)}
.actions{display:flex;gap:8px}
.round{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;color:#fff;text-decoration:none}
.edit{background:linear-gradient(135deg,#f59e0b,#ea580c)}
.del{background:var(--grad-danger)}
.ghost{background:rgba(255,255,255,.06);}
.toolbar{display:grid;grid-template-columns:1.1fr .9fr auto auto;gap:10px;align-items:end;margin-top:6px}
@media (max-width: 1200px){ .toolbar{grid-template-columns:1fr 1fr auto} }
@media (max-width: 900px){ .toolbar{grid-template-columns:1fr} }
.alert{padding:12px 14px;border-radius:12px;margin:12px 0;border:1px solid;font-weight:700;display:flex;gap:10px;align-items:center}
.success{background:rgba(16,185,129,.15);border-color:rgba(16,185,129,.3);color:#34d399}
.error{background:rgba(225,29,72,.15);border-color:rgba(225,29,72,.3);color:#fda4af}
.sticky-head{position:sticky;top:70px;z-index:10;background:rgba(15,20,25,.9);backdrop-filter:blur(10px);border-radius:14px;padding:8px 12px;border:1px solid var(--border)}
.fab{position:fixed;right:16px;bottom:16px;width:46px;height:46px;border-radius:50%;display:grid;place-items:center;background:var(--grad-primary);color:#fff;border:1px solid #0ea5e9;cursor:pointer;box-shadow:var(--shadow-lg);display:none}
.help{font-size:12px;color:var(--muted);margin-top:6px}
.hr{height:1px;background:linear-gradient(90deg,transparent,rgba(255,255,255,.12),transparent);margin:14px 0}
.code{font-family:ui-monospace, SFMono-Regular, Menlo, monospace; background:#0b1220;border:1px solid #243045;border-radius:8px;padding:2px 6px}
.selbox{display:flex;align-items:center;gap:10px}
.selbox input[type=checkbox]{width:18px;height:18px}
.selectedBar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
</style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
  <div class="brand">
    <div class="logo">❓</div>
    <div class="title">จัดการคำถาม</div>
  </div>
  <div class="nav-actions">
    <span style="color:var(--muted)">ทั้งหมด <b id="totalQ"><?= (int)$stats['questions']; ?></b> คำถาม</span>
    <a class="btn" href="manage_recommended_groups.php"><i class="fa-solid fa-layer-group"></i> กลุ่ม/รายวิชา</a>
    <a class="btn" href="admin_dashboard.php"><i class="fa-solid fa-house"></i> หน้าหลัก</a>
  </div>
</div>

<div class="container">

  <?php if ($message): ?>
  <div class="alert <?= $message_type==='success'?'success':'error' ?>" id="flash">
    <i class="fa-<?= $message_type==='success'?'solid fa-circle-check':'solid fa-triangle-exclamation' ?>"></i>
    <?= htmlspecialchars($message) ?>
  </div>
  <?php endif; ?>

  <!-- Toolbar -->
  <div class="card">
    <div class="sticky-head">
      <div class="toolbar">
        <div>
          <label style="display:block;color:var(--muted);font-size:12px;margin-bottom:6px">ค้นหา</label>
          <input id="search" class="input" placeholder="พิมพ์ข้อความคำถามหรือชื่อกลุ่ม…">
        </div>
        <div>
          <label style="display:block;color:var(--muted);font-size:12px;margin-bottom:6px">กรองตามกลุ่ม</label>
          <select id="filterGroup" class="select">
            <option value="">— แสดงทุกกลุ่ม —</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= h($g['group_id']) ?>" <?= ($groupParam!=='' && $groupParam==$g['group_id'])?'selected':'' ?>>
                <?= h($g['group_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="display:flex;gap:8px;align-items:end">
          <button id="clearFilters" class="btn btn-primary" type="button"><i class="fa-solid fa-arrows-rotate"></i> ยกเลิก</button>
        </div>

        <form method="POST" onsubmit="return confirm('ยืนยันลบคำถามทั้งหมด? การลบไม่สามารถย้อนกลับได้!');" style="display:flex;align-items:end">
          <button type="submit" name="nuke_all" class="btn btn-danger"><i class="fa-solid fa-trash"></i> ลบคำถามทั้งหมด</button>
        </form>
      </div>

      <!-- แถบลบหลายข้อ -->
      <div class="selectedBar" style="margin-top:10px">
        <label class="selbox">
          <input type="checkbox" id="selectAll">
          <span>เลือกทั้งหมดในรายการที่แสดง</span>
        </label>
        <span class="badge"><i class="fa-regular fa-square-check"></i> เลือกไว้: <b id="countSelected">0</b></span>
        <form id="bulkDeleteForm" method="POST" onsubmit="return confirm('ยืนยันลบคำถามที่เลือกทั้งหมด?');" style="display:inline-flex;gap:8px;align-items:center">
          <input type="hidden" name="bulk_delete_selected" value="1">
          <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash-can"></i> ลบที่เลือก</button>
        </form>
      </div>
    </div>

    <div class="stats" id="liveStats" style="margin-top:10px">
      <span class="badge"><i class="fa-regular fa-rectangle-list"></i> ทั้งหมด: <b id="sumAll"><?= (int)$stats['questions']; ?></b></span>
      <span class="badge"><i class="fa-solid fa-filter"></i> หลังกรอง <b id="sumFiltered">0</b></span>
    </div>
  </div>

  <div class="grid" style="margin-top:16px">
    <!-- ซ้าย: รายการคำถาม -->
    <section class="card">
      <h2 style="font-size:20px;font-weight:800;margin-bottom:12px;background:linear-gradient(135deg,var(--sky),var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent"><i class="fa-solid fa-list-check"></i> คลังคำถามทั้งหมด</h2>
      <p class="sub">ติ๊กช่องเพื่อเลือกหลายข้อ จากนั้นกด “ลบที่เลือก” หรือใช้ไอคอน ✏️/🗑️ สำหรับแก้ไข/ลบเดี่ยว</p>
      <div class="hr"></div>
      <div class="list" id="questionList">
        <?php if (!empty($questions)): foreach ($questions as $q): ?>
          <div class="item"
               data-q="<?= h(mb_strtolower($q['question_text'])) ?>"
               data-g="<?= h(mb_strtolower($q['group_name'] ?? '')) ?>"
               data-gid="<?= (int)$q['group_id'] ?>">
            <label class="selbox" style="align-items:flex-start">
              <input type="checkbox" class="rowChk" value="<?= (int)$q['question_id'] ?>">
              <div style="flex:1">
                <strong>“<?= h($q['question_text']) ?>”</strong>
                <small>กลุ่ม: <?= h($q['group_name'] ?? 'N/A') ?> • ลำดับ: <?= (int)$q['order_in_group'] ?></small>
              </div>
            </label>
            <div class="actions">
              <a class="round edit" title="แก้ไข" href="manage_questions.php?edit_question=<?= $q['question_id'] ?>#question-form"><i class="fa-solid fa-pen"></i></a>
              <a class="round del"  title="ลบ"   href="manage_questions.php?delete_question=<?= $q['question_id'] ?>" onclick="return confirm('ยืนยันลบคำถามนี้?')"><i class="fa-solid fa-trash"></i></a>
            </div>
          </div>
        <?php endforeach; else: ?>
          <div class="item"><div style="flex:1;color:var(--muted)">ยังไม่มีคำถาม</div></div>
        <?php endif; ?>
      </div>
    </section>

    <!-- ขวา: นำเข้า/เพิ่ม/แก้ไข + เพิ่มหลายข้อ -->
    <section class="card" id="question-form">
      <h2 style="font-size:20px;font-weight:800;margin-bottom:12px;background:linear-gradient(135deg,var(--amber),#ea580c);-webkit-background-clip:text;-webkit-text-fill-color:transparent"><i class="fa-solid fa-list-ul"></i> เพิ่มคำถาม</h2>
      <p class="sub">วาง 1 คำถามต่อ 1 บรรทัด ระบบจะรักษาลำดับนี้ไว้ในคอลัมน์ “ลำดับในกลุ่ม”</p>

      <form method="POST" action="manage_questions.php" style="display:grid;gap:12px">
        <textarea name="bulk_questions" class="input" placeholder="พิมพ์/วางคำถามทีละบรรทัดที่นี่..."></textarea>

        <div style="display:grid;gap:10px">
          <label style="display:flex;gap:8px;align-items:center">
            <input type="radio" name="bulk_mode" value="one" checked style="width:auto">
            <span>ใส่ทั้งหมดลง <b>กลุ่มเดียว</b></span>
            <select name="bulk_group_id" class="select" style="max-width:320px">
              <option value="">— เลือกกลุ่ม —</option>
              <?php foreach ($groups as $g): ?>
                <option value="<?= $g['group_id'] ?>"><?= h($g['group_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input type="radio" name="bulk_mode" value="three" style="width:auto">
            <span>แบ่งตามเงื่อนไข <b>13 / 10 / 10</b> (ตามลำดับบรรทัด):</span>
            <span style="flex-basis:100%;height:0;"></span>
            <span>กลุ่มที่ 1</span>
            <select name="bulk_group1" class="select" style="max-width:260px">
              <?php foreach ($groups as $g): ?>
                <option value="<?= $g['group_id'] ?>"><?= h($g['group_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <span style="flex-basis:100%;height:0;"></span>
            <span>กลุ่มที่ 2</span>
            <select name="bulk_group2" class="select" style="max-width:260px">
              <?php foreach ($groups as $g): ?>
                <option value="<?= $g['group_id'] ?>"><?= h($g['group_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <span style="flex-basis:100%;height:0;"></span>
            <span>กลุ่มที่ 3</span>
            <select name="bulk_group3" class="select" style="max-width:260px">
              <?php foreach ($groups as $g): ?>
                <option value="<?= $g['group_id'] ?>"><?= h($g['group_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="sub">หมายเหตุ: โหมด 13/10/10 จะรับสูงสุด 33 บรรทัด (ส่วนเกินจะถูกตัดทิ้งตามเงื่อนไข)</div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button type="submit" name="add_bulk_questions" class="btn btn-primary"><i class="fa-solid fa-plus"></i> เพิ่มหลายคำถาม</button>
        </div>
      </form>

      <div class="hr"></div>

      <h2 style="font-size:20px;font-weight:800;margin-bottom:12px;background:linear-gradient(135deg,var(--emerald),#059669);-webkit-background-clip:text;-webkit-text-fill-color:transparent"><i class="fa-solid fa-file-import"></i> นำเข้า / ส่งออก (CSV)</h2>
      <p class="sub">รองรับ <span class="code">question_text,group_id</span> หรือ <span class="code">question_text,group_name</span> (ลำดับจะต่อท้ายจากค่าปัจจุบันของกลุ่ม)</p>

      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
        <a class="btn" href="manage_questions.php?export=questions"><i class="fa-solid fa-file-arrow-down"></i> ส่งออก CSV (ตามลำดับ)</a>
      </div>

      <form method="POST" enctype="multipart/form-data" action="manage_questions.php" style="display:grid;gap:12px">
        <div>
          <label style="display:block;margin-bottom:6px">เลือกไฟล์ CSV</label>
          <input type="file" name="csv" accept=".csv,text/csv" class="input" required>
          <div class="sub">ถ้าเลือก “กลุ่มคงที่” ด้านล่าง ระบบจะใช้ค่านั้นกับทุกแถว</div>
          <label style="display:flex;gap:8px;align-items:center;margin-top:6px;font-size:14px;color:var(--muted)"><input type="checkbox" name="has_header" value="1" style="width:auto"> ไฟล์มีหัวตาราง (Header)</label>
        </div>
        <div>
          <label style="display:block;margin-bottom:6px">กลุ่มคงที่ (ไม่บังคับ)</label>
          <select name="group_id_for_import" class="select">
            <option value="">— ไม่ระบุ (อ่านจากคอลัมน์ที่ 2) —</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= $g['group_id'] ?>"><?= h($g['group_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" name="import_questions_csv" class="btn" style="background:var(--grad-success);color:#fff;border-color:#059669">
          <i class="fa-solid fa-upload"></i> นำเข้าคำถาม (CSV)
        </button>
      </form>
    </section>
  </div>
</div>

<button class="fab" id="toTop" title="เลื่อนขึ้น"><i class="fa-solid fa-arrow-up"></i></button>

<script>
/* ----- Alert fade ----- */
const flash = document.getElementById('flash');
if (flash){ setTimeout(()=>{ flash.style.transition='opacity .6s'; flash.style.opacity='0'; setTimeout(()=>flash.remove(),600); }, 4200); }

/* ----- Helpers ----- */
const $ = s => document.querySelector(s);
const $$ = s => Array.from(document.querySelectorAll(s));

/* ----- Server group filter redirect ----- */
const filterGroup = $('#filterGroup');
function onServerGroupChange(){
  const val = filterGroup.value;
  const url = new URL(window.location.href);
  if (val) url.searchParams.set('group', val); else url.searchParams.delete('group');
  window.location.href = url.toString();
}
filterGroup.addEventListener('change', onServerGroupChange);

/* ----- Client filters (ข้อความ + live count) ----- */
const items = $$('.item');
const search = $('#search');
const sumFiltered = $('#sumFiltered');
function norm(s){ return (s||'').toString().toLowerCase().trim(); }
function applyFilters(){
  const q = norm(search.value);
  let shown = 0;
  items.forEach(it=>{
    const tq = it.getAttribute('data-q') || '';
    const tg = it.getAttribute('data-g') || '';
    const show = !q || tq.includes(q) || tg.includes(q);
    it.style.display = show ? '' : 'none';
    if (show) shown++;
  });
  sumFiltered.textContent = shown;
}
search.addEventListener('input', applyFilters);
applyFilters();

/* ----- Clear filters (รีเซ็ต server param ด้วย) ----- */
const clearBtn = $('#clearFilters');
clearBtn.addEventListener('click', ()=>{
  search.value='';
  const url = new URL(window.location.href);
  url.searchParams.delete('group');
  window.location.href = url.toString();
});

/* ----- Scroll to top ----- */
const toTop = document.getElementById('toTop');
window.addEventListener('scroll', ()=>{ toTop.style.display = (window.scrollY>400) ? 'grid' : 'none'; });
toTop.addEventListener('click', ()=>window.scrollTo({top:0, behavior:'smooth'}));

/* ----- Bulk select & delete ----- */
const selectAll = $('#selectAll');
const rowChks = $$('.rowChk');
const bulkForm = $('#bulkDeleteForm');
const countSelected = $('#countSelected');

function updateSelectedHiddenInputs(){
  // ล้างของเก่าก่อน
  Array.from(bulkForm.querySelectorAll('input[name="ids[]"]')).forEach(el=>el.remove());  // เพิ่มเฉพาะที่ถูกเลือกและมองเห็นอยู่ (หลังกรอง)
  const visibleSelected = rowChks.filter(chk => chk.checked && chk.closest('.item').style.display !== 'none');
  visibleSelected.forEach(chk=>{
    const inp = document.createElement('input');
    inp.type = 'hidden';
    inp.name = 'ids[]';
    inp.value = chk.value;
    bulkForm.appendChild(inp);
  });
  countSelected.textContent = visibleSelected.length;
}

rowChks.forEach(chk=>{
  chk.addEventListener('change', updateSelectedHiddenInputs);
});

selectAll.addEventListener('change', ()=>{
  // เลือกเฉพาะรายการที่แสดงอยู่หลังกรอง
  items.forEach(it=>{
    if (it.style.display === 'none') return;
    const chk = it.querySelector('.rowChk');
    if (chk) chk.checked = selectAll.checked;
  });
  updateSelectedHiddenInputs();
});

// เมื่อค้นหา/กรอง ให้รีเฟรชจำนวนที่เลือก (แต่ไม่ยุ่งกับ checkbox เดิม)
search.addEventListener('input', ()=>setTimeout(updateSelectedHiddenInputs, 0));
filterGroup.addEventListener('change', ()=>setTimeout(updateSelectedHiddenInputs, 0));
</script>
</body>
</html>