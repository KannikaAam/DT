<?php
/* =========================================================
   manage_subjects.php — จัดการกลุ่มวิชา & รายวิชา (พร้อมวาง)
   - เปลี่ยนไปใช้ subject_groups (แทน groups)
   - แก้คีย์สถิติเป็น $stats['subject_groups']
   - เพิ่ม AUTO-MIGRATE:
       • ensureColumn สำหรับ subjects
       • เพิ่ม courses.curriculum_name_value หากยังไม่มี
       • ถ้าพบตารางเก่าชื่อ `groups` และยังไม่มี `subject_groups` → RENAME อัตโนมัติ
   - รองรับดึงรายวิชาจากตาราง courses ตามหลักสูตร (curriculum_name_value)
   ========================================================= */

require 'db_connect.php';
if (!isset($pdo)) { die('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบไฟล์ db_connect.php'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ใช้ path ปัจจุบันเพื่อให้ลิงก์/redirect ถูกเสมอ
$SELF   = $_SERVER['PHP_SELF'];
$SELF_H = htmlspecialchars($SELF, ENT_QUOTES, 'UTF-8');

/* ---------- Helpers ---------- */
function pickExistingColumn(PDO $pdo, string $table, array $candidates) {
    $in  = str_repeat('?,', count($candidates)-1) . '?';
    $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ($in)";
    $st  = $pdo->prepare($sql);
    $st->execute(array_merge([$table], $candidates));
    $got = $st->fetchAll(PDO::FETCH_COLUMN, 0);
    foreach ($candidates as $c) if (in_array($c, $got, true)) return $c;
    return null;
}
function ensureColumn(PDO $pdo, string $table, string $col, string $ddl){
    $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = ?
                          AND COLUMN_NAME = ?");
    $q->execute([$table,$col]);
    if (!$q->fetchColumn()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
    }
}
function tableExists(PDO $pdo, string $table): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $q->execute([$table]);
    return (bool)$q->fetchColumn();
}

/* =====================================================
   AUTO-MIGRATE: เปลี่ยนชื่อ groups -> subject_groups (ครั้งเดียว)
   ===================================================== */
try {
    $hasGroups = tableExists($pdo, 'groups');
    $hasSubjectGroups = tableExists($pdo, 'subject_groups');
    if ($hasGroups && !$hasSubjectGroups) {
        $pdo->exec("RENAME TABLE `groups` TO `subject_groups`");
    }
} catch (Throwable $e) {
    // ไม่บังคับล่ม — ถ้าสิทธิ์ไม่พอ ให้จัดการเปลี่ยนชื่อด้วยตนเอง
}

/* =====================================================
   AUTO-MIGRATE: subjects (เพิ่มปี/โค้ด/หน่วยกิต/เงื่อนไขก่อนหน้า)
   ===================================================== */
try {
    ensureColumn($pdo, 'subjects', 'subject_code',      "subject_code VARCHAR(50) NULL");
    ensureColumn($pdo, 'subjects', 'credits',           "credits INT NULL");
    ensureColumn($pdo, 'subjects', 'recommended_year',  "recommended_year TINYINT NULL");
    ensureColumn($pdo, 'subjects', 'prereq_text',       "prereq_text VARCHAR(255) NULL");
} catch (Throwable $e) {
    // ไม่บังคับล่ม
}

/* ---------- เพิ่มคอลัมน์เชื่อมหลักสูตรให้ courses ถ้ายังไม่มี ---------- */
try {
    $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'courses'
                          AND COLUMN_NAME = 'curriculum_name_value'");
    $q->execute();
    if (!$q->fetchColumn()) {
        $pdo->exec("ALTER TABLE courses ADD COLUMN curriculum_name_value VARCHAR(100) NULL DEFAULT NULL");
    }
} catch (Throwable $e) { /* ไม่บังคับให้ล่ม */ }

/* =====================================================
   AJAX (JSON only)
   ===================================================== */
if (isset($_GET['ajax'])) {
    while (ob_get_level()) { ob_end_clean(); }
    ini_set('display_errors','0');
    header('Content-Type: application/json; charset=utf-8');

    set_exception_handler(function($e){
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    });

    $foPK      = pickExistingColumn($pdo, 'form_options', ['id','option_id','value','form_option_id']);
    $foLabel   = pickExistingColumn($pdo, 'form_options', ['label','name','option_label','title']);
    $coursePK  = pickExistingColumn($pdo, 'courses',      ['id','course_id','cid']);
    $codeCol   = pickExistingColumn($pdo, 'courses',      ['course_code','code']);
    $credCol   = pickExistingColumn($pdo, 'courses',      ['credits','credit','credit_hours']);
    $yearCol   = pickExistingColumn($pdo, 'courses',      ['recommended_year','year','year_recommended']);
    $preqCol   = pickExistingColumn($pdo, 'courses',      ['prereq_text','prerequisite','prerequisites','prereq']);
    $groupCol  = pickExistingColumn($pdo, 'courses',      ['group_id','subject_group_id']);

    $ajax = $_GET['ajax'];

    if ($ajax === 'ping') {
        echo json_encode([
            'ok'=>true,'file'=>basename(__FILE__),
            'foPK'=>$foPK,'foLabel'=>$foLabel,
            'coursePK'=>$coursePK,'codeCol'=>$codeCol,'credCol'=>$credCol,'yearCol'=>$yearCol,'preqCol'=>$preqCol,'groupCol'=>$groupCol
        ]); exit;
    }

    if (!$coursePK) { echo json_encode(['ok'=>false,'error'=>'courses ไม่มีคอลัมน์คีย์หลัก'], JSON_UNESCAPED_UNICODE); exit; }

if ($ajax === 'curricula') {
    $sql = "
      SELECT label AS curriculum_value, label AS curriculum_label
      FROM form_options
      WHERE type = 'curriculum_name'
      UNION
      SELECT DISTINCT curriculum_name_value AS curriculum_value, curriculum_name_value AS curriculum_label
      FROM courses
      WHERE curriculum_name_value IS NOT NULL AND curriculum_name_value <> ''
      ORDER BY curriculum_label
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'curricula'=>$rows,'count'=>count($rows)], JSON_UNESCAPED_UNICODE);
    exit;
}


    if ($ajax === 'courses_by_curriculum') {
        $cur = trim($_GET['curriculum_name'] ?? '');
        if ($cur === '') { echo json_encode(['ok'=>false,'error'=>'missing curriculum_name'], JSON_UNESCAPED_UNICODE); exit; }
        
        // Ensure course_name is always selected
        $courseNameCol = pickExistingColumn($pdo, 'courses', ['course_name', 'name', 'title']);
        if (!$courseNameCol) {
            echo json_encode(['ok'=>false,'error'=>'courses ไม่มีคอลัมน์ชื่อรายวิชาที่รองรับ'], JSON_UNESCAPED_UNICODE); exit;
        }

        $selects = [
            "{$coursePK} AS course_id",
            "{$courseNameCol} AS course_name",
            ($codeCol ? "$codeCol AS course_code" : "NULL AS course_code"),
            ($credCol ? "$credCol AS credits" : "NULL AS credits"),
            ($yearCol ? "$yearCol AS recommended_year" : "NULL AS recommended_year"),
            ($preqCol ? "$preqCol AS prereq_text" : "NULL AS prereq_text"),
            ($groupCol ? "$groupCol AS group_id" : "NULL AS group_id"),
        ];
        $sql = "SELECT ".implode(',', $selects)."
                FROM courses
                WHERE curriculum_name_value = ?
                ORDER BY course_code, course_name";
        $st  = $pdo->prepare($sql);
        $st->execute([$cur]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'courses'=>$rows,'count'=>count($rows)], JSON_UNESCAPED_UNICODE); exit;
    }

    echo json_encode(['ok'=>false,'error'=>'unknown endpoint','uri'=>$_SERVER['REQUEST_URI']], JSON_UNESCAPED_UNICODE); exit;
}

/* ---------- STATE ---------- */
$group_to_edit   = null;
$subject_to_edit = null;
$message = '';
$message_type = 'success';

/* ---------- DELETE ---------- */
try {
    if (isset($_GET['delete_group'])) {
        $stmt = $pdo->prepare("DELETE FROM subject_groups WHERE group_id = ?");
        $stmt->execute([$_GET['delete_group']]);
        header("Location: {$SELF}?message=".urlencode("ลบกลุ่มวิชาสำเร็จ!")."&type=success"); exit;
    }
    if (isset($_GET['delete_subject'])) {
        $stmt = $pdo->prepare("DELETE FROM subjects WHERE subject_id = ?");
        $stmt->execute([$_GET['delete_subject']]);
        header("Location: {$SELF}?message=".urlencode("ลบรายวิชาสำเร็จ!")."&type=success"); exit;
    }
} catch (PDOException $e) {
    header("Location: {$SELF}?message=".urlencode("เกิดข้อผิดพลาดในการลบ: ".$e->getMessage())."&type=error"); exit;
}

/* ---------- ADD / UPDATE ---------- */
try {
    // กลุ่มวิชา
    if (isset($_POST['add_group'])) {
        $stmt = $pdo->prepare("INSERT INTO subject_groups (group_name) VALUES (?)");
        $stmt->execute([$_POST['group_name']]);
        header("Location: {$SELF}?message=".urlencode("เพิ่มกลุ่มวิชาสำเร็จ!")."&type=success"); exit;
    }
    if (isset($_POST['update_group'])) {
        $stmt = $pdo->prepare("UPDATE subject_groups SET group_name = ? WHERE group_id = ?");
        $stmt->execute([$_POST['group_name'], $_POST['group_id']]);
        header("Location: {$SELF}?message=".urlencode("แก้ไขกลุ่มวิชาสำเร็จ!")."&type=success"); exit;
    }

    // รายวิชา (หลายวิชา + metadata)
    if (isset($_POST['add_subject'])) {
        $gid      = $_POST['group_id_for_subject'] ?? '';
        $names    = $_POST['subject_names'] ?? null;
        $codes    = $_POST['subject_codes'] ?? [];
        $credits  = $_POST['subject_credits'] ?? [];
        $years    = $_POST['subject_years'] ?? [];
        $prereqs  = $_POST['subject_prereqs'] ?? [];
        $override_year = isset($_POST['override_year']) && $_POST['override_year'] !== '' ? (int)$_POST['override_year'] : null;

        if (!is_array($names) || count(array_filter($names, fn($x)=>trim($x)!=='')) === 0) {
            header("Location: {$SELF}?message=".urlencode("กรุณาเลือกรายวิชาอย่างน้อย 1 วิชา")."&type=error"); exit;
        }

        $pdo->beginTransaction();
        try {
            $sql = "INSERT INTO subjects (subject_name, group_id, subject_code, credits, recommended_year, prereq_text)
                    VALUES (?, ?, ?, ?, ?, ?)";
            $st  = $pdo->prepare($sql);
            $n = 0;
            foreach ($names as $i => $name) {
                $name = trim($name);
                if ($name==='') continue;

                $code = $codes[$i]   ?? null;  $code = ($code==='')? null : $code;
                $cred = $credits[$i] ?? null;  $cred = ($cred==='')? null : (int)$cred;
                $yr   = $override_year ?? ( (isset($years[$i]) && $years[$i] !== '') ? (int)$years[$i] : null );
                $pre  = $prereqs[$i]  ?? null;  $pre  = ($pre==='')? null : $pre;

                $st->execute([$name, $gid, $code, $cred, $yr, $pre]);
                $n++;
            }
            $pdo->commit();
            header("Location: {$SELF}?message=".urlencode("เพิ่มรายวิชาสำเร็จ {$n} รายการ!")."&type=success"); exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            header("Location: {$SELF}?message=".urlencode("เกิดข้อผิดพลาด: ".$e->getMessage())."&type=error"); exit;
        }
    }

    if (isset($_POST['update_subject'])) {
        $subject_id = $_POST['subject_id'];
        $subject_name = $_POST['subject_name'];
        $group_id = $_POST['group_id_for_subject'];
        $subject_code = $_POST['subject_code'] ?? null;
        $credits = $_POST['credits'] ?? null;
        $recommended_year = $_POST['recommended_year'] ?? null;
        $prereq_text = $_POST['prereq_text'] ?? null;

        $stmt = $pdo->prepare("UPDATE subjects SET subject_name = ?, group_id = ?, subject_code = ?, credits = ?, recommended_year = ?, prereq_text = ? WHERE subject_id = ?");
        $stmt->execute([
            $subject_name,
            $group_id,
            ($subject_code === '') ? null : $subject_code,
            ($credits === '') ? null : (int)$credits,
            ($recommended_year === '') ? null : (int)$recommended_year,
            ($prereq_text === '') ? null : $prereq_text,
            $subject_id
        ]);
        header("Location: {$SELF}?message=".urlencode("แก้ไขรายวิชาสำเร็จ!")."&type=success"); exit;
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
    if (isset($_GET['edit_group'])) {
        $stmt = $pdo->prepare("SELECT * FROM subject_groups WHERE group_id = ?");
        $stmt->execute([$_GET['edit_group']]);
        $group_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (isset($_GET['edit_subject'])) {
        $stmt = $pdo->prepare("SELECT * FROM subjects WHERE subject_id = ?");
        $stmt->execute([$_GET['edit_subject']]);
        $subject_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $message = "ไม่สามารถดึงข้อมูลเพื่อแก้ไขได้: " . $e->getMessage();
    $message_type = 'error';
}

/* ---------- LIST FETCH ---------- */
try {
    $groups = $pdo->query("SELECT * FROM subject_groups ORDER BY group_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $subjects_sql = "
        SELECT s.subject_id, s.subject_name, s.subject_code, s.credits, s.recommended_year, s.prereq_text,
               g.group_name, g.group_id
        FROM subjects s
        LEFT JOIN subject_groups g ON s.group_id = g.group_id
        ORDER BY g.group_name, s.subject_name
    ";
    $subjects = $pdo->query($subjects_sql)->fetchAll(PDO::FETCH_ASSOC);
    $stats = ['subject_groups' => count($groups), 'subjects' => count($subjects)];
} catch (PDOException $e) {
    $message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $message_type = 'error';
    $groups = $subjects = [];
    $stats = ['subject_groups' => 0, 'subjects' => 0];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>จัดการกลุ่มวิชา & รายวิชา</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
:root{--navy:#0f1419;--steel:#1e293b;--slate:#334155;--sky:#0ea5e9;--cyan:#06b6d4;--emerald:#10b981;--amber:#f59e0b;--orange:#ea580c;--rose:#e11d48;--text:#f1f5f9;--muted:#94a3b8;--subtle:#64748b;--border:#374151;--glass:rgba(15,20,25,.85);--overlay:rgba(0,0,0,.6);--shadow-sm:0 2px 8px rgba(0,0,0,.1);--shadow:0 4px 20px rgba(0,0,0,.15);--shadow-lg:0 8px 32px rgba(0,0,0,.25);--gradient-primary:linear-gradient(135deg,var(--sky),var(--cyan));--gradient-secondary:linear-gradient(135deg,var(--slate),var(--steel));--gradient-accent:linear-gradient(135deg,var(--amber),var(--orange));--gradient-success:linear-gradient(135deg,var(--emerald),#059669);--gradient-danger:linear-gradient(135deg,var(--rose),#be123c)}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:'Sarabun',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:var(--text);background:radial-gradient(1200px 800px at 20% 0%, rgba(14,165,233,.08), transparent 65%),radial-gradient(1000px 600px at 80% 100%, rgba(6,182,212,.06), transparent 65%),conic-gradient(from 230deg at 0% 50%, #0f1419, #1e293b, #0f1419);min-height:100vh;line-height:1.6;}
.container{max-width:1400px;margin:0 auto;padding:24px;animation:fadeIn .6s ease-out;}
@keyframes fadeIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:24px;padding:20px 24px;border-radius:20px;background:var(--glass);backdrop-filter:blur(20px);border:1px solid var(--border);box-shadow:var(--shadow-lg)}
.brand{display:flex;align-items:center;gap:16px}
.logo{width:48px;height:48px;border-radius:16px;background:var(--gradient-primary);box-shadow:var(--shadow);display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
.logo::before{content:'🧭';font-size:22px;z-index:1;position:relative}
.logo::after{content:'';position:absolute;inset:0;background:linear-gradient(45deg,transparent,rgba(255,255,255,.1),transparent);animation:shimmer 3s infinite}
@keyframes shimmer{0%,100%{transform:translateX(-100%)}50%{transform:translateX(100%)}}
.title{font-weight:800;font-size:26px;background:var(--gradient-primary);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.nav{display:flex;gap:8px;flex-wrap:wrap}
.btn{padding:12px 16px;border-radius:14px;border:1px solid var(--border);cursor:pointer;text-decoration:none;font-weight:700;font-size:14px;display:inline-flex;align-items:center;gap:10px;transition:all .3s cubic-bezier(.4,0,.2,1);position:relative;overflow:hidden;backdrop-filter:blur(10px)}
.btn:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
.primary{background:var(--gradient-primary);color:#fff;border-color:var(--sky)}
.secondary{background:var(--gradient-secondary);color:var(--text);border-color:var(--slate)}
.danger{background:var(--gradient-danger);color:#fff;border-color:var(--rose)}
.header{display:grid;grid-template-columns:1fr auto;gap:16px;align-items:end;margin-bottom:20px;padding:20px 24px;border-radius:20px;background:var(--glass);backdrop-filter:blur(20px);border:1px solid var(--border)}
.header h2{font-size:28px;font-weight:800;margin:0;background:var(--gradient-primary);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.header .chips{display:flex;gap:8px;flex-wrap:wrap}
.badge{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:20px;font-size:12px;font-weight:700;background:var(--gradient-secondary);border:1px solid var(--border);box-shadow:var(--shadow-sm)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:16px}
.card{background:var(--glass);border:1px solid var(--border);border-radius:24px;padding:20px;backdrop-filter:blur(20px);box-shadow:var(--shadow-lg);position:relative;overflow:hidden}
.section-header{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.section-header h3{font-size:18px;font-weight:800}
.input,.select,textarea{width:100%;padding:14px 16px;border-radius:14px;border:1px solid var(--border);background:rgba(15,20,25,.6);color:var(--text);outline:none;font-size:14px;transition:all .3s ease;backdrop-filter:blur(10px);font-family:inherit}
.input:focus,.select:focus,textarea:focus{border-color:var(--sky);box-shadow:0 0 0 3px rgba(14,165,233,.2);background:rgba(15,20,25,.8)}
label{font-weight:700;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;display:block}
.list{max-height:430px;overflow:auto;border-radius:16px}
.item{display:flex;justify-content:space-between;align-items:center;background:rgba(15,20,25,.6);border:1px solid var(--border);border-left:4px solid #0ea5e9;padding:14px 16px;border-radius:14px;margin-bottom:10px}
.item strong{font-weight:800}
.item small{color:var(--subtle)}
.btn-icon{width:40px;height:40px;padding:0;display:flex;align-items:center;justify-content:center;border-radius:12px}
.alert{padding:14px 16px;border-radius:16px;margin:16px 0;border:1px solid;display:flex;align-items:center;gap:10px;backdrop-filter:blur(10px);font-weight:700;animation:slideIn .4s ease-out}
@keyframes slideIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}
}
.ok{background:rgba(16,185,129,.15);border-color:rgba(16,185,129,.3);color:var(--emerald)}
.dangerBox{background:rgba(225,29,72,.15);border-color:rgba(225,29,72,.3);color:var(--rose)}
.table-wrap{position:relative;overflow:auto;border-radius:16px;box-shadow:inset 0 1px 0 rgba(255,255,255,.05)}
.table{width:100%;border-collapse:separate;border-spacing:0}
.table thead th{position:sticky;top:0;background:rgba(15,20,25,.95);backdrop-filter:blur(20px);border-bottom:2px solid var(--border);padding:14px 16px;text-align:left;font-weight:800;color:var(--text);font-size:13px;text-transform:uppercase;letter-spacing:.5px}
.table tbody td{padding:14px 16px;border-bottom:1px solid rgba(55,65,81,.3);vertical-align:middle}
.table tbody tr:hover{background:rgba(14,165,233,.03);box-shadow:inset 0 0 0 1px rgba(14,165,233,.1)}
.preview{margin-top:10px;border:1px dashed var(--border);border-radius:16px;padding:12px;background:rgba(15,20,25,.5)}
.preview h4{margin:0 0 8px 0;font-size:14px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.4px}
.preview small{color:var(--subtle)}
</style>
</head>
<body>
<div class="container">

  <!-- Topbar -->
  <div class="topbar">
    <div class="brand">
      <div class="logo"></div>
      <h1 class="title">Manage Subjects & Groups</h1>
    </div>
    <nav class="nav">
      <a href="admin_dashboard.php" class="btn secondary">หน้าหลัก</a>
      <a href="manage_questions.php" class="btn primary">หน้าจัดการคำถาม</a>
    </nav>
  </div>

  <!-- Flash/message -->
  <?php if ($message): ?>
    <div class="alert <?php echo $message_type==='success'?'ok':'dangerBox'; ?>">
      <?php echo htmlspecialchars($message); ?>
    </div>
  <?php endif; ?>

  <!-- Header + สถิติ -->
  <div class="header">
    <div>
      <h2>จัดการกลุ่มวิชา & รายวิชา</h2>
      <div class="chips" style="margin-top:10px">
        <span class="badge">📚 กลุ่มวิชา: <strong><?php echo (int)$stats['subject_groups']; ?></strong></span>
        <span class="badge">📘 รายวิชา: <strong><?php echo (int)$stats['subjects']; ?></strong></span>
      </div>
    </div>
  </div>

  <div class="grid">

    <!-- กลุ่มวิชา -->
    <section class="card" id="group-form">
      <div class="section-header">
        <span style="font-size:18px">🗂️</span>
        <h3>จัดการกลุ่มวิชา</h3>
      </div>

      <div class="card" style="padding:16px; margin-bottom:16px;">
        <?php if ($group_to_edit): ?>
          <div class="section-header" style="margin-bottom:8px"><span>✏️</span><h3>แก้ไขกลุ่มวิชา</h3></div>
          <form method="POST" action="<?php echo $SELF_H; ?>" style="display:grid; gap:12px">
            <input type="hidden" name="group_id" value="<?php echo (int)$group_to_edit['group_id']; ?>">
            <div>
              <label>ชื่อกลุ่มวิชา</label>
              <input class="input" type="text" name="group_name" value="<?php echo htmlspecialchars($group_to_edit['group_name']); ?>" required>
            </div>
            <div style="display:flex; gap:8px; justify-content:flex-end">
              <a class="btn secondary" href="<?php echo $SELF_H; ?>">❌ ยกเลิก</a>
              <button type="submit" name="update_group" class="btn primary">💾 บันทึก</button>
            </div>
          </form>
        <?php else: ?>
          <div class="section-header" style="margin-bottom:8px"><span>➕</span><h3>เพิ่มกลุ่มวิชาใหม่</h3></div>
          <form method="POST" action="<?php echo $SELF_H; ?>" style="display:grid; gap:12px">
            <div>
              <label>ชื่อกลุ่มวิชา</label>
              <input class="input" type="text" name="group_name" placeholder="เช่น เทคโนโลยีสารสนเทศ" required>
            </div>
            <div style="display:flex; justify-content:flex-end">
              <button type="submit" name="add_group" class="btn primary">➕ เพิ่ม</button>
            </div>
          </form>
        <?php endif; ?>
      </div>

      <div class="section-header" style="margin-top:4px"><span>📋</span><h3>กลุ่มวิชาที่มีอยู่ (<?php echo count($groups); ?>)</h3></div>
      <div class="list">
        <?php foreach ($groups as $group): ?>
          <div class="item">
            <div><strong><?php echo htmlspecialchars($group['group_name']); ?></strong></div>
            <div style="display:flex; gap:8px">
              <a class="btn secondary btn-icon" title="แก้ไข"
                 href="<?php echo $SELF_H; ?>?edit_group=<?php echo (int)$group['group_id']; ?>#group-form">✏️</a>
              <a class="btn danger btn-icon" title="ลบ"
                 href="<?php echo $SELF_H; ?>?delete_group=<?php echo (int)$group['group_id']; ?>"
                 onclick="return confirm('ยืนยันลบกลุ่มวิชานี้? อาจมีผลกับรายวิชา/คำถามที่เกี่ยวข้อง')">🗑️</a>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($groups)): ?>
          <div class="item" style="justify-content:center;color:var(--subtle)">ยังไม่มีกลุ่มวิชา</div>
        <?php endif; ?>
      </div>
    </section>

    <!-- รายวิชา -->
    <section class="card" id="subject-form">
      <div class="section-header">
        <span style="font-size:18px">📘</span>
        <h3>จัดการรายวิชา</h3>
      </div>

      <div class="card" style="padding:16px; margin-bottom:16px;">
        <?php if ($subject_to_edit): ?>
          <div class="section-header" style="margin-bottom:8px"><span>✏️</span><h3>แก้ไขรายวิชา</h3></div>
          <form method="POST" action="<?php echo $SELF_H; ?>" style="display:grid; gap:12px">
            <input type="hidden" name="subject_id" value="<?php echo (int)$subject_to_edit['subject_id']; ?>">
            <div>
              <label>ชื่อรายวิชา</label>
              <input class="input" type="text" name="subject_name" value="<?php echo htmlspecialchars($subject_to_edit['subject_name']); ?>" required>
            </div>
            <div>
              <label>รหัสวิชา</label>
              <input class="input" type="text" name="subject_code" value="<?php echo htmlspecialchars($subject_to_edit['subject_code'] ?? ''); ?>">
            </div>
            <div>
              <label>หน่วยกิต</label>
              <input class="input" type="number" name="credits" value="<?php echo htmlspecialchars($subject_to_edit['credits'] ?? ''); ?>" min="0">
            </div>
            <div>
              <label>ปีที่ควรศึกษา</label>
              <input class="input" type="number" name="recommended_year" value="<?php echo htmlspecialchars($subject_to_edit['recommended_year'] ?? ''); ?>" min="1" max="6">
            </div>
            <div>
              <label>เงื่อนไขก่อนหน้า (ถ้ามี)</label>
              <input class="input" type="text" name="prereq_text" value="<?php echo htmlspecialchars($subject_to_edit['prereq_text'] ?? ''); ?>">
            </div>
            <div>
              <label>กลุ่มวิชา</label>
              <select class="select" name="group_id_for_subject" required>
                <option value="">-- เลือกกลุ่มวิชา --</option>
                <?php foreach ($groups as $group): ?>
                  <option value="<?php echo (int)$group['group_id']; ?>" <?php echo ($subject_to_edit['group_id']==$group['group_id'])?'selected':''; ?>>
                    <?php echo htmlspecialchars($group['group_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div style="display:flex; gap:8px; justify-content:flex-end">
              <a class="btn secondary" href="<?php echo $SELF_H; ?>">❌ ยกเลิก</a>
              <button type="submit" name="update_subject" class="btn primary">💾 บันทึก</button>
            </div>
          </form>

        <?php else: ?>
          <div class="section-header" style="margin-bottom:8px"><span>➕</span><h3>เพิ่มรายวิชาใหม่</h3></div>
          <form method="POST" action="<?php echo $SELF_H; ?>" id="add-subject-form" style="display:grid; gap:12px">
            <div>
              <label>เลือกหลักสูตร</label>
              <select id="curriculum_select" class="select">
                <option value="">-- เลือกหลักสูตร --</option>
              </select>
            </div>

            <div>
              <label>เลือกรายวิชาในหลักสูตร (เลือกได้หลายวิชา)</label>
              <select id="course_select" class="select" multiple size="8" disabled>
                <option value="">-- เลือกรายวิชา --</option>
              </select>
              <div style="color:var(--muted);font-size:12px;margin-top:6px">กด Ctrl/Command เพื่อเลือกหลายวิชา</div>

              <!-- ตารางพรีวิว: แสดงข้อมูลวิชาที่เลือกแบบอัตโนมัติ -->
              <div id="preview_holder" class="preview" style="display:none;">
                <h4>พรีวิววิชาที่เลือก</h4>
                <div class="table-wrap" style="margin-top:8px;">
                  <table class="table" id="preview_table">
                    <thead>
                      <tr>
                        <th style="width:140px">รหัส</th>
                        <th>ชื่อวิชา</th>
                        <th style="width:100px">หน่วยกิต</th>
                        <th style="width:120px">ปีที่ควรศึกษา</th>
                        <th style="width:260px">เงื่อนไขก่อนหน้า</th>
                      </tr>
                    </thead>
                    <tbody></tbody>
                  </table>
                </div>
                <small id="preview_count"></small>
              </div>
            </div>

            <div>
              <label>สังกัดกลุ่มวิชา</label>
              <select name="group_id_for_subject" class="select" required>
                <option value="">-- เลือกกลุ่มวิชา --</option>
                <?php foreach ($groups as $group): ?>
                  <option value="<?php echo (int)$group['group_id']; ?>"><?php echo htmlspecialchars($group['group_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- hidden inputs จะถูกเติมตามวิชาที่เลือก -->
            <div id="selected_subject_names"></div>

            <div style="display:flex; justify-content:flex-end">
              <button type="submit" name="add_subject" class="btn primary" <?php echo empty($groups)?'disabled':''; ?>>➕ เพิ่ม</button>
            </div>
          </form>
        <?php endif; ?>
      </div>

      <!-- รายวิชาทั้งหมด -->
      <div class="section-header" style="margin-top:4px"><span>🗒️</span><h3>รายวิชาทั้งหมด (<?php echo count($subjects); ?>)</h3></div>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:60px">#</th>
              <th>ชื่อวิชา</th>
              <th style="width:140px">รหัส</th>
              <th style="width:100px">หน่วยกิต</th>
              <th style="width:120px">ปีที่ควรศึกษา</th>
              <th style="width:240px">กลุ่ม</th>
              <th style="width:140px">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($subjects)): $n=1; foreach ($subjects as $subject): ?>
              <tr>
                <td style="text-align:center;color:var(--muted);font-weight:700"><?php echo $n++; ?>.</td>
                <td style="font-weight:800">
                  <?php echo htmlspecialchars($subject['subject_name']); ?>
                  <?php if (!empty($subject['prereq_text'])): ?>
                    <div style="font-size:12px;color:var(--subtle)">วิชาที่บังคับก่อน: <?php echo htmlspecialchars($subject['prereq_text']); ?></div>
                  <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($subject['subject_code'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($subject['credits'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($subject['recommended_year'] ?? ''); ?></td>
                <td><span class="badge"><?php echo htmlspecialchars($subject['group_name'] ?? 'ไม่มีกลุ่ม'); ?></span></td>
                <td>
                  <div style="display:flex; gap:8px;">
                    <a class="btn secondary btn-icon" title="แก้ไข" href="<?php echo $SELF_H; ?>?edit_subject=<?php echo (int)$subject['subject_id']; ?>#subject-form">✏️</a>
                    <a class="btn danger btn-icon" title="ลบ" href="<?php echo $SELF_H; ?>?delete_subject=<?php echo (int)$subject['subject_id']; ?>" onclick="return confirm('ยืนยันลบรายวิชานี้?')">🗑️</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="7" style="text-align:center;color:var(--muted)">ยังไม่มีรายวิชา</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  </div><!-- /grid -->

</div><!-- /container -->

<script>
document.addEventListener('DOMContentLoaded', () => {
  // Auto-hide alert
  document.querySelectorAll('.alert').forEach(a=>{
    setTimeout(()=>{
      a.style.transition='opacity .5s, transform .5s';
      a.style.opacity='0'; 
      a.style.transform='translateY(-10px)';
      setTimeout(()=>a.remove(),500);
    }, 5000);
  });

  const curSel = document.getElementById('curriculum_select');
  const courseSel = document.getElementById('course_select');
  const preview = document.getElementById('preview_holder');
  const previewTable = document.getElementById('preview_table').querySelector('tbody');
  const previewCount = document.getElementById('preview_count');
  const hiddenHolder = document.getElementById('selected_subject_names');

  // โหลด curricula
  fetch('?ajax=curricula')
    .then(r=>r.json()).then(j=>{
      if (j.ok) {
        j.curricula.forEach(c=>{
          const opt = document.createElement('option');
          opt.value = c.curriculum_value;
          opt.textContent = c.curriculum_label;
          curSel.appendChild(opt);
        });
      } else {
        console.error("Error loading curricula:", j.error);
      }
    })
    .catch(e => {
        console.error("Fetch error loading curricula:", e);
    });

  // เมื่อเลือกหลักสูตร → โหลดรายวิชา
  curSel.addEventListener('change', ()=>{
    courseSel.innerHTML='<option value="">-- เลือกรายวิชา --</option>';
    courseSel.disabled=true;
    preview.style.display='none';
    hiddenHolder.innerHTML='';

    if (!curSel.value) {
      return;
    }

    fetch(`?ajax=courses_by_curriculum&curriculum_name=${encodeURIComponent(curSel.value)}`)
      .then(r=>r.json()).then(j=>{
        if (j.ok) {
          if (j.courses.length === 0) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'ไม่มีรายวิชาในหลักสูตรนี้';
            opt.disabled = true;
            courseSel.appendChild(opt);
            courseSel.disabled = true;
          } else {
            j.courses.forEach(c=>{
              const opt = document.createElement('option');
              opt.value = c.course_id;
              opt.textContent = (c.course_code?c.course_code+' - ':'')+c.course_name;
              opt.dataset.code = c.course_code||'';
              opt.dataset.credits = c.credits||'';
              opt.dataset.year = c.recommended_year||'';
              opt.dataset.prereq = c.prereq_text||'';
              courseSel.appendChild(opt);
            });
            courseSel.disabled=false;
          }
          // Trigger change to update preview
          courseSel.dispatchEvent(new Event('change')); 
        } else {
            console.error("Error loading courses:", j.error);
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'เกิดข้อผิดพลาดในการโหลดรายวิชา';
            opt.disabled = true;
            courseSel.appendChild(opt);
            courseSel.disabled = true;
            preview.style.display = 'none';
        }
      })
      .catch(e => {
          console.error("Fetch error:", e);
          const opt = document.createElement('option');
          opt.value = '';
          opt.textContent = 'เกิดข้อผิดพลาดในการเชื่อมต่อ';
          opt.disabled = true;
          courseSel.appendChild(opt);
          courseSel.disabled = true;
          preview.style.display = 'none';
      });
  });

  // เมื่อเลือกหลายรายวิชา → update preview + hidden inputs
  courseSel.addEventListener('change', ()=>{
    hiddenHolder.innerHTML='';
    previewTable.innerHTML='';
    const selected = Array.from(courseSel.selectedOptions);
    if (selected.length===0) { 
      preview.style.display='none'; 
      return; 
    }
    
    selected.forEach(opt=>{
      // hidden inputs
      hiddenHolder.insertAdjacentHTML('beforeend',`
        <input type="hidden" name="subject_names[]" value="${opt.textContent.replace(/"/g,'&quot;')}">
        <input type="hidden" name="subject_codes[]" value="${opt.dataset.code}">
        <input type="hidden" name="subject_credits[]" value="${opt.dataset.credits}">
        <input type="hidden" name="subject_years[]" value="${opt.dataset.year}">
        <input type="hidden" name="subject_prereqs[]" value="${opt.dataset.prereq}">
      `);
      // preview rows
      previewTable.insertAdjacentHTML('beforeend',`
        <tr>
          <td>${opt.dataset.code||''}</td>
          <td>${opt.textContent}</td>
          <td>${opt.dataset.credits||''}</td>
          <td>${opt.dataset.year||''}</td>
          <td>${opt.dataset.prereq||''}</td>
        </tr>
      `);
    });
    previewCount.textContent = `เลือกแล้ว ${selected.length} วิชา`;
    preview.style.display='block';
  });
});
</script>
</body>
</html>