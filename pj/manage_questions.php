<?php
// manage_questions.php (Dark/Glass UX) — ไม่เปลี่ยน endpoints/logic เดิม
require 'db_connect.php';
if (!isset($pdo)) { die('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบไฟล์ db_connect.php'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- EXPORT CSV ---------- */
if (isset($_GET['export']) && $_GET['export'] === 'questions') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="questions_'.date('Ymd_His').'.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['question_id','question_text','group_id','group_name']);
    $sql = "SELECT q.question_id, q.question_text, g.group_id, g.group_name
            FROM questions q
            LEFT JOIN subject_groups g ON q.group_id = g.group_id
            ORDER BY q.question_id ASC";
    $stmt = $pdo->query($sql);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [$r['question_id'],$r['question_text'],$r['group_id'],$r['group_name']]);
    }
    fclose($out); exit;
}

/* ---------- STATE ---------- */
$question_to_edit = null;
$message = '';
$message_type = 'success';

/* ---------- DELETE ---------- */
try {
    if (isset($_GET['delete_question'])) {
        $stmt = $pdo->prepare("DELETE FROM questions WHERE question_id = ?");
        $stmt->execute([$_GET['delete_question']]);
        header("Location: manage_questions.php?message=" . urlencode("ลบคำถามสำเร็จ!") . "&type=success"); exit;
    }
} catch (PDOException $e) {
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

        // map ชื่อกลุ่ม -> group_id (รองรับไฟล์ที่ใส่ชื่อกลุ่ม)
        $group_map = [];
        $rows = $pdo->query("SELECT group_id, group_name FROM subject_groups")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) { $group_map[trim($r['group_name'])] = (string)$r['group_id']; }

        $hasHeader = !empty($_POST['has_header']);

        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) { header("Location: manage_questions.php?message=" . urlencode("เปิดไฟล์ไม่สำเร็จ") . "&type=error"); exit; }

        $line = 0; $ok = 0; $skipped = 0; $errors = [];
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("INSERT INTO questions (question_text, group_id) VALUES (?, ?)");
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

                $st->execute([$qtext, $gid]); $ok++;
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
        $stmt = $pdo->prepare("INSERT INTO questions (question_text, group_id) VALUES (?, ?)");
        $stmt->execute([$_POST['question_text'], $_POST['group_id_for_question']]);
        header("Location: manage_questions.php?message=" . urlencode("เพิ่มคำถามสำเร็จ!") . "&type=success"); exit;
    }
    if (isset($_POST['update_question'])) {
        $stmt = $pdo->prepare("UPDATE questions SET question_text = ?, group_id = ? WHERE question_id = ?");
        $stmt->execute([$_POST['question_text'], $_POST['group_id_for_question'], $_POST['question_id']]);
        header("Location: manage_questions.php?message=" . urlencode("แก้ไขคำถามสำเร็จ!") . "&type=success"); exit;
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

/* ---------- LIST FETCH ---------- */
try {
    $groups = $pdo->query("SELECT * FROM subject_groups ORDER BY group_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $questions_sql = "
        SELECT q.question_id, q.question_text, g.group_name, g.group_id
        FROM questions q
        LEFT JOIN subject_groups g ON q.group_id = g.group_id
        ORDER BY q.question_id DESC
    ";
    $questions = $pdo->query($questions_sql)->fetchAll(PDO::FETCH_ASSOC);
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

/* Top header */
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

/* Container / sections */
.container{max-width:1250px;margin:18px auto;padding:16px}
.grid{display:grid;grid-template-columns:1.1fr .9fr;gap:16px}
@media (max-width: 1024px){ .grid{grid-template-columns:1fr} }

.card{background:var(--glass);border:1px solid var(--border);border-radius:20px;padding:18px;backdrop-filter:blur(20px);box-shadow:var(--shadow-lg);position:relative;overflow:hidden}
.card h2{font-size:20px;font-weight:800;margin-bottom:12px;background:var(--grad-primary);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.sub{color:var(--muted);font-size:13px;margin-bottom:10px}

/* Stat chips */
.stats{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px}
.badge{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,.04);font-weight:700;font-size:12px}
.badge i{opacity:.85}

/* Inputs */
.input,.select,textarea,input[type=file]{
  width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--border);
  background:rgba(15,20,25,.6);color:var(--text);outline:none;transition:.2s;
}
.input:focus,.select:focus,textarea:focus{border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.25)}
textarea{min-height:120px}

/* List */
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

/* Toolbar */
.toolbar{display:grid;grid-template-columns:1.2fr .9fr auto;gap:10px;align-items:end;margin-top:6px}
@media (max-width: 1024px){ .toolbar{grid-template-columns:1fr 1fr} }
@media (max-width: 640px){ .toolbar{grid-template-columns:1fr} }

/* Alerts */
.alert{padding:12px 14px;border-radius:12px;margin:12px 0;border:1px solid;font-weight:700;display:flex;gap:10px;align-items:center}
.success{background:rgba(16,185,129,.15);border-color:rgba(16,185,129,.3);color:#34d399}
.error{background:rgba(225,29,72,.15);border-color:rgba(225,29,72,.3);color:#fda4af}

/* Sticky section headers */
.sticky-head{position:sticky;top:70px;z-index:10;background:rgba(15,20,25,.9);backdrop-filter:blur(10px);border-radius:14px;padding:8px 12px;border:1px solid var(--border)}

/* Floating top button */
.fab{position:fixed;right:16px;bottom:16px;width:46px;height:46px;border-radius:50%;display:grid;place-items:center;background:var(--grad-primary);color:#fff;border:1px solid #0ea5e9;cursor:pointer;box-shadow:var(--shadow-lg);display:none}

/* Tiny helpers */
.help{font-size:12px;color:var(--muted);margin-top:6px}
.hr{height:1px;background:linear-gradient(90deg,transparent,rgba(255,255,255,.12),transparent);margin:14px 0}
.code{font-family:ui-monospace, SFMono-Regular, Menlo, monospace; background:#0b1220;border:1px solid #243045;border-radius:8px;padding:2px 6px}
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

  <!-- Toolbar: ค้นหา + กรองกลุ่ม + ปุ่มล้าง -->
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
              <option value="<?= htmlspecialchars($g['group_name']) ?>"><?= htmlspecialchars($g['group_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:flex;gap:8px;align-items:end">
          <button id="clearFilters" class="btn btn-primary" type="button"><i class="fa-solid fa-arrows-rotate"></i> ล้างตัวกรอง</button>
          <a class="btn ghost" href="manage_questions.php?export=questions"><i class="fa-solid fa-file-arrow-down"></i> ส่งออก CSV</a>
        </div>
      </div>
    </div>
    <div class="stats" id="liveStats" style="margin-top:10px">
      <span class="badge"><i class="fa-regular fa-rectangle-list"></i> ทั้งหมด: <b id="sumAll"><?= (int)$stats['questions']; ?></b></span>
      <span class="badge"><i class="fa-solid fa-filter"></i> หลังกรอง: <b id="sumFiltered">0</b></span>
    </div>
  </div>

  <div class="grid" style="margin-top:16px">
    <!-- ซ้าย: รายการคำถาม -->
    <section class="card">
      <h2><i class="fa-solid fa-list-check"></i> คลังคำถามทั้งหมด</h2>
      <p class="sub">คลิกไอคอน ✏️ เพื่อแก้ไข หรือ 🗑️ เพื่อลบ</p>
      <div class="hr"></div>
      <div class="list" id="questionList">
        <?php if (!empty($questions)): foreach ($questions as $q): ?>
          <div class="item" data-q="<?= htmlspecialchars(mb_strtolower($q['question_text'])) ?>" data-g="<?= htmlspecialchars(mb_strtolower($q['group_name'] ?? '')) ?>">
            <div style="flex:1">
              <strong>“<?= htmlspecialchars($q['question_text']) ?>”</strong>
              <small>กลุ่ม: <?= htmlspecialchars($q['group_name'] ?? 'N/A') ?></small>
            </div>
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

    <!-- ขวา: ฟอร์มนำเข้า/เพิ่ม/แก้ไข -->
    <section class="card" id="question-form">
      <h2><i class="fa-solid fa-pen-to-square"></i> <?= $question_to_edit ? 'แก้ไขคำถาม' : 'เพิ่มคำถามใหม่' ?></h2>
      <p class="sub"><?= $question_to_edit ? 'แก้ไขข้อความ/กลุ่ม แล้วกดบันทึก' : 'พิมพ์คำถามและเลือกกลุ่ม' ?></p>

      <form method="POST" action="manage_questions.php" style="display:grid;gap:12px">
        <?php if ($question_to_edit): ?>
          <input type="hidden" name="question_id" value="<?= $question_to_edit['question_id'] ?>">
        <?php endif; ?>
        <div>
          <label style="display:block;margin-bottom:6px">ข้อความคำถาม</label>
          <textarea name="question_text" class="input" required placeholder="เช่น คุณชอบแก้ปัญหาที่ซับซ้อนด้วยตรรกะหรือไม่?"><?= $question_to_edit ? htmlspecialchars($question_to_edit['question_text']) : '' ?></textarea>
        </div>
        <div>
          <label style="display:block;margin-bottom:6px">ให้คะแนนกลุ่ม</label>
          <select name="group_id_for_question" class="select" required>
            <?php if (!$question_to_edit): ?><option value="">— เลือกกลุ่มวิชา —</option><?php endif; ?>
            <?php foreach ($groups as $g): ?>
              <option value="<?= $g['group_id'] ?>" <?= ($question_to_edit && $question_to_edit['group_id']==$g['group_id'])?'selected':'' ?>>
                <?= htmlspecialchars($g['group_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <?php if ($question_to_edit): ?>
            <button type="submit" name="update_question" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> บันทึก</button>
            <a href="manage_questions.php" class="btn"><i class="fa-solid fa-circle-xmark"></i> ยกเลิก</a>
          <?php else: ?>
            <button type="submit" name="add_question" class="btn btn-primary" <?= empty($groups)?'disabled':'' ?>><i class="fa-solid fa-circle-plus"></i> เพิ่มคำถาม</button>
          <?php endif; ?>
        </div>
      </form>

      <div class="hr"></div>

      <h2 style="margin-top:4px"><i class="fa-solid fa-file-import"></i> นำเข้า / ส่งออก (CSV)</h2>
      <p class="sub">รองรับรูปแบบ <span class="code">question_text,group_id</span> หรือ <span class="code">question_text,group_name</span></p>

      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
        <a class="btn" href="manage_questions.php?export=questions"><i class="fa-solid fa-file-arrow-down"></i> ส่งออก CSV (ทั้งหมด)</a>
      </div>

      <form method="POST" enctype="multipart/form-data" action="manage_questions.php" style="display:grid;gap:12px">
        <div>
          <label style="display:block;margin-bottom:6px">เลือกไฟล์ CSV</label>
          <input type="file" name="csv" accept=".csv,text/csv" class="input" required>
          <div class="help">ถ้าเลือก “กลุ่มคงที่” ด้านล่าง ระบบจะใช้ค่านั้นกับทุกแถว</div>
          <label style="display:flex;gap:8px;align-items:center;margin-top:6px;font-size:14px;color:var(--muted)">
            <input type="checkbox" name="has_header" value="1" style="width:auto"> ไฟล์มีหัวตาราง (Header)
          </label>
        </div>
        <div>
          <label style="display:block;margin-bottom:6px">กลุ่มคงที่ (ไม่บังคับ)</label>
          <select name="group_id_for_import" class="select">
            <option value="">— ไม่ระบุ (อ่านจากคอลัมน์ที่ 2) —</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= $g['group_id'] ?>"><?= htmlspecialchars($g['group_name']) ?></option>
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

/* ----- Filters ----- */
const $ = s => document.querySelector(s);
const $$ = s => Array.from(document.querySelectorAll(s));
const list = $('#questionList');
const items = $$('.item');
const search = $('#search');
const filterGroup = $('#filterGroup');
const sumAll = $('#sumAll');
const sumFiltered = $('#sumFiltered');
const clearBtn = $('#clearFilters');

function norm(s){ return (s||'').toString().toLowerCase().trim(); }

function applyFilters(){
  const q = norm(search.value);
  const g = norm(filterGroup.value);
  let shown = 0;
  items.forEach(it=>{
    const tq = it.getAttribute('data-q') || '';
    const tg = it.getAttribute('data-g') || '';
    const okText = !q || tq.includes(q) || tg.includes(q);
    const okGroup = !g || tg === g;
    const show = okText && okGroup;
    it.style.display = show ? '' : 'none';
    if (show) shown++;
  });
  sumFiltered.textContent = shown;
}
search.addEventListener('input', applyFilters);
filterGroup.addEventListener('change', applyFilters);
clearBtn.addEventListener('click', ()=>{ search.value=''; filterGroup.value=''; applyFilters(); });
applyFilters();

/* ----- Scroll to top ----- */
const toTop = document.getElementById('toTop');
window.addEventListener('scroll', ()=>{ toTop.style.display = (window.scrollY>400) ? 'grid' : 'none'; });
toTop.addEventListener('click', ()=>window.scrollTo({top:0, behavior:'smooth'}));
</script>
</body>
</html>
