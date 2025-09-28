<?php
// manage_students.php — จัดการสถานะแบบทดสอบ + สถานะนักศึกษา (Dark/Glass UI, Compact/Responsive + Sticky Actions Right)
session_start();
include 'db_connect.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php"); exit();
}

/* ----------------- CSRF ----------------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function require_csrf($t){
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $t ?? '')) {
        http_response_code(403); exit('CSRF verification failed');
    }
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ----------------- Schema helpers ----------------- */
function currentDatabase(mysqli $conn): string {
    $db = 'studentregistration';
    if ($r = $conn->query("SELECT DATABASE() AS dbname")) {
        if ($rw = $r->fetch_assoc()) { $db = $rw['dbname'] ?: $db; }
        $r->free();
    }
    return $db;
}
function hasTable(mysqli $conn, string $db, string $table): bool {
    $sql = "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?";
    $st = $conn->prepare($sql); $st->bind_param('ss',$db,$table); $st->execute();
    $res = $st->get_result(); $c = $res ? (int)$res->fetch_assoc()['c'] : 0; $st->close(); return $c>0;
}
function hasColumn(mysqli $conn, string $db, string $table, string $col): bool {
    $sql = "SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?";
    $st = $conn->prepare($sql); $st->bind_param('sss',$db,$table,$col); $st->execute();
    $res = $st->get_result(); $c = $res ? (int)$res->fetch_assoc()['c'] : 0; $st->close(); return $c>0;
}
function ensureTable(mysqli $conn, string $table, string $ddl){
    if (!$conn->query("CREATE TABLE IF NOT EXISTS `$table` $ddl")) {
        http_response_code(500); exit("Cannot ensure table `$table`: ".$conn->error);
    }
}
function ensureColumn(mysqli $conn, string $db, string $table, string $column, string $addDDL){
    if (!hasColumn($conn,$db,$table,$column)){
        if (!$conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $addDDL")){
            http_response_code(500); exit("Cannot add column `$column` on `$table`: ".$conn->error);
        }
    }
}

/* ----------------- Auto-migrate ----------------- */
$database = currentDatabase($conn);
ensureTable($conn, 'student_quiz_status', "(
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(64) NOT NULL,
    full_name VARCHAR(255) NULL,
    quiz_attempts INT NOT NULL DEFAULT 0,
    recommended_count INT NOT NULL DEFAULT 0,
    admin_override_attempts INT NOT NULL DEFAULT 0,
    academic_status ENUM('active','graduated','leave','suspended') NOT NULL DEFAULT 'active',
    updated_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uniq_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
ensureColumn($conn,$database,'student_quiz_status','full_name','VARCHAR(255) NULL');
ensureColumn($conn,$database,'student_quiz_status','quiz_attempts','INT NOT NULL DEFAULT 0');
ensureColumn($conn,$database,'student_quiz_status','recommended_count','INT NOT NULL DEFAULT 0');
ensureColumn($conn,$database,'student_quiz_status','admin_override_attempts','INT NOT NULL DEFAULT 0');
ensureColumn($conn,$database,'student_quiz_status','academic_status',"ENUM('active','graduated','leave','suspended') NOT NULL DEFAULT 'active'");
ensureColumn($conn,$database,'student_quiz_status','updated_at',"TIMESTAMP NULL DEFAULT NULL");

/* ----------------- ตรวจ test_history ----------------- */
$thExists = hasTable($conn,$database,'test_history');
$thSidCol = null; $thHasVoid = false;
if ($thExists){
    foreach (['username','student_id','studentID','sid','stu_id','student_code','std_id','std_code'] as $cand){
        if (hasColumn($conn,$database,'test_history',$cand)){ $thSidCol=$cand; break; }
    }
    $thHasVoid = hasColumn($conn,$database,'test_history','is_void');
}

/* ----------------- Submit handlers ----------------- */
$message=''; $error='';

/* (A) Update status/override (+full_name) */
if (isset($_POST['update_status'])){
    require_csrf($_POST['csrf'] ?? '');
    $student_id = trim($_POST['student_id'] ?? '');
    $full_name  = trim($_POST['full_name'] ?? '');
    $admin_override_attempts = (int)($_POST['admin_override_attempts'] ?? 0);
    $academic_status = $_POST['academic_status'] ?? 'active';
    $allowed = ['active','graduated','leave','suspended'];
    if (!in_array($academic_status,$allowed,true)) $academic_status='active';

    if ($student_id===''){ $error="ไม่พบรหัสนักศึกษา"; }
    else{
        $exists=false;
        $chk=$conn->prepare("SELECT id FROM student_quiz_status WHERE student_id=? LIMIT 1");
        $chk->bind_param("s",$student_id); $chk->execute();
        $res=$chk->get_result(); $exists=$res && $res->num_rows>0; $chk->close();

        if ($exists){
            $stmt=$conn->prepare("UPDATE student_quiz_status
                SET full_name=?, admin_override_attempts=?, academic_status=?, updated_at=NOW()
                WHERE student_id=?");
            $stmt->bind_param("siss",$full_name,$admin_override_attempts,$academic_status,$student_id);
        }else{
            $stmt=$conn->prepare("INSERT INTO student_quiz_status
                (student_id, full_name, admin_override_attempts, academic_status, updated_at)
                VALUES (?,?,?,?,NOW())");
            $stmt->bind_param("ssis",$student_id,$full_name,$admin_override_attempts,$academic_status);
        }
        if ($stmt->execute()){
            $message="อัปเดตสถานะนักศึกษา '".h($full_name ?: $student_id)."' เรียบร้อยแล้ว";
        }else{
            $error="เกิดข้อผิดพลาดในการอัปเดตสถานะ: ".h($stmt->error);
        }
        $stmt->close();
    }
}

/* (B) Clear attempts */
if (isset($_POST['clear_attempts'])){
    require_csrf($_POST['csrf'] ?? '');
    $student_id = trim($_POST['student_id'] ?? '');
    $full_name  = trim($_POST['full_name'] ?? '');
    if ($student_id===''){ $error="ไม่พบรหัสนักศึกษา"; }
    else{
        $conn->begin_transaction();
        try{
            $affected=0;
            if ($thExists && $thSidCol){
                if ($thHasVoid){
                    $sql="UPDATE test_history SET is_void=1, void_at=NOW()
                          WHERE TRIM(`$thSidCol`)=TRIM(?) AND COALESCE(is_void,0)=0";
                    $st=$conn->prepare($sql); $st->bind_param("s",$student_id);
                    if(!$st->execute()) throw new Exception("อัปเดตสถานะ void ไม่สำเร็จ: ".$st->error);
                    $affected=$st->affected_rows; $st->close();
                }else{
                    $sql="DELETE FROM test_history WHERE TRIM(`$thSidCol`)=TRIM(?)";
                    $st=$conn->prepare($sql); $st->bind_param("s",$student_id);
                    if(!$st->execute()) throw new Exception("ลบ test_history ไม่สำเร็จ: ".$st->error);
                    $affected=$st->affected_rows; $st->close();
                }
            }
            // reset fallback
            $up=$conn->prepare("UPDATE student_quiz_status SET quiz_attempts=0, updated_at=NOW() WHERE student_id=?");
            $up->bind_param("s",$student_id);
            if(!$up->execute()) throw new Exception("อัปเดต quiz_attempts ไม่สำเร็จ: ".$up->error);
            $aff=$up->affected_rows; $up->close();
            if($aff===0){
                $ins=$conn->prepare("INSERT INTO student_quiz_status (student_id, full_name, quiz_attempts, updated_at) VALUES (?,?,0,NOW())");
                $ins->bind_param("ss",$student_id,$full_name);
                if(!$ins->execute()) throw new Exception("เพิ่มแถว quiz_attempts ไม่สำเร็จ: ".$ins->error);
                $ins->close();
            }
            $conn->commit();
            $message="ล้างค่าการทำแบบทดสอบของ '".h($full_name ?: $student_id)."' เรียบร้อย"
                    .($affected>0? ($thHasVoid?" (ทำเครื่องหมาย void: {$affected} แถว)":" (ลบจาก test_history: {$affected} แถว)"):"");
        }catch(Throwable $e){
            $conn->rollback();
            $error="ไม่สามารถล้างประวัติได้: ".h($e->getMessage());
        }
    }
}

/* ----------------- Main SELECT ----------------- */
if ($thExists && $thSidCol){
    $voidFilter = $thHasVoid ? "WHERE COALESCE(is_void,0)=0" : "";
    $students_status_sql = "
        SELECT
            COALESCE(sqs.full_name, pi.full_name) AS full_name,
            ei.student_id,
            COALESCE(th.cnt,0) AS quiz_attempts_live,
            3 AS max_attempts,
            COALESCE(sqs.admin_override_attempts,0) AS admin_override_attempts,
            COALESCE(sqs.academic_status,'active') AS academic_status
        FROM personal_info pi
        INNER JOIN education_info ei ON pi.id=ei.personal_id
        LEFT JOIN student_quiz_status sqs ON ei.student_id=sqs.student_id
        LEFT JOIN (
            SELECT TRIM(`$thSidCol`) AS sid, COUNT(*) AS cnt
            FROM test_history
            $voidFilter
            GROUP BY TRIM(`$thSidCol`)
        ) th ON th.sid=ei.student_id
        ORDER BY ei.student_id ASC
    ";
}else{
    $students_status_sql = "
        SELECT
            COALESCE(sqs.full_name, pi.full_name) AS full_name,
            ei.student_id,
            COALESCE(sqs.quiz_attempts,0) AS quiz_attempts_live,
            3 AS max_attempts,
            COALESCE(sqs.admin_override_attempts,0) AS admin_override_attempts,
            COALESCE(sqs.academic_status,'active') AS academic_status
        FROM personal_info pi
        INNER JOIN education_info ei ON pi.id=ei.personal_id
        LEFT JOIN student_quiz_status sqs ON ei.student_id=sqs.student_id
        ORDER BY ei.student_id ASC
    ";
}
$students_status_result = $conn->query($students_status_sql);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>จัดการสถานะนักศึกษา - Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --navy:#0f1419;--steel:#1f2937;--slate:#334155;--sky:#0ea5e9;--cyan:#06b6d4;--emerald:#10b981;
  --amber:#f59e0b;--rose:#e11d48;--text:#f1f5f9;--muted:#94a3b8;--subtle:#64748b;--border:#374151;
  --glass:rgba(15,20,25,.85);--shadow:0 8px 32px rgba(0,0,0,.25);
}
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:'Sarabun',system-ui,Segoe UI,Roboto,Arial;
  color:var(--text);
  background:
    radial-gradient(1200px 800px at 20% 0%, rgba(14,165,233,.08), transparent 65%),
    radial-gradient(1000px 600px at 80% 100%, rgba(6,182,212,.06), transparent 65%),
    conic-gradient(from 230deg at 0% 50%, #0f1419, #1e2937, #0f1419);
  min-height:100vh; line-height:1.65;
}

/* Topbar */
.topbar{position:sticky;top:0;z-index:50;display:flex;justify-content:space-between;align-items:center;gap:8px;
  padding:12px 14px;border-bottom:1px solid var(--border);
  background:rgba(15,20,25,.85);backdrop-filter:blur(18px);box-shadow:var(--shadow)}
.brand{display:flex;align-items:center;gap:10px}
.logo{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--sky),var(--cyan));display:grid;place-items:center;box-shadow:var(--shadow)}
.title{font-weight:800;font-size:16px;background:linear-gradient(135deg,var(--sky),var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.nav-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.btn{padding:8px 12px;border-radius:10px;border:1px solid var(--border);text-decoration:none;color:var(--text);font-weight:700;
  display:inline-flex;align-items:center;gap:8px;cursor:pointer;transition:.2s ease; background:linear-gradient(135deg,#334155,#1f2937); font-size:14px}
.btn:hover{transform:translateY(-1px);box-shadow:var(--shadow)}
.btn-danger{background:linear-gradient(135deg,#e11d48,#be123c);border-color:#a31d33}
.btn-primary{background:linear-gradient(135deg,#0ea5e9,#06b6d4);border-color:#1385a8;color:#fff}

/* Container / cards */
.container{max-width:1280px;margin:10px auto;padding:10px}
.card{background:rgba(15,20,25,.85);border:1px solid var(--border);border-radius:16px;padding:14px;backdrop-filter:blur(16px);box-shadow:var(--shadow)}
.header{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end;margin-bottom:8px}
.header h1{font-size:20px;font-weight:800;background:linear-gradient(135deg,#0ea5e9,#06b6d4);-webkit-background-clip:text;-webkit-text-fill-color:transparent}

/* Toolbar */
.toolbar{display:grid;grid-template-columns:1.1fr .9fr .9fr auto;gap:8px;align-items:end;margin-top:8px}
@media (max-width:1024px){.toolbar{grid-template-columns:1fr 1fr}}
@media (max-width:640px){.toolbar{grid-template-columns:1fr}}
.input,.select{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:rgba(15,20,25,.6);color:var(--text);outline:none;font-size:14px}
.input:focus,.select:focus{border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.18)}

/* Alerts */
.alert{padding:10px 12px;border-radius:10px;margin:10px 0;border:1px solid;font-weight:700;display:flex;gap:8px;align-items:center}
.alert-success{background:rgba(16,185,129,.15);border-color:rgba(16,185,129,.3);color:#10b981}
.alert-danger{background:rgba(225,29,72,.15);border-color:rgba(225,29,72,.3);color:#e11d48}

/* Table */
.table-wrap{position:relative;overflow:auto;border-radius:12px}
table{width:100%;border-collapse:separate;border-spacing:0;min-width:980px}
thead th{
  position:sticky;top:0;z-index:3;background:rgba(15,20,25,.95);backdrop-filter:blur(8px);
  border-bottom:2px solid var(--border);padding:10px;text-align:left;font-weight:800;font-size:12px;text-transform:uppercase;letter-spacing:.3px
}
tbody td{padding:10px;border-bottom:1px solid rgba(55,65,81,.35);vertical-align:middle;font-size:14px;background:rgba(15,20,25,.85)}
tbody tr:hover td{background:rgba(14,165,233,.04)}
.badge{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:999px;font-size:12px;border:1px solid var(--border);background:rgba(255,255,255,.04)}
.st-active{background:#0b3d2a;color:#86efac;border-color:#14532d}
.st-graduated{background:#1e1b4b;color:#c7d2fe;border-color:#3730a3}
.st-leave{background:#3b2407;color:#fed7aa;border-color:#9a3412}
.st-suspended{background:#3f0a0a;color:#fecaca;border-color:#b91c1c}

/* Sticky right column (Actions) */
th.sticky-right{position:sticky; right:0; z-index:4; background:rgba(15,20,25,.98); box-shadow:-12px 0 16px rgba(0,0,0,.35)}
td.sticky-right{position:sticky; right:0; z-index:2; background:rgba(15,20,25,.95); box-shadow:-12px 0 16px rgba(0,0,0,.30)}

/* Inline inputs in table */
td input[type="number"], td select{
  width:110px; padding:7px 9px; border-radius:9px; border:1px solid var(--border);
  background:rgba(15,20,25,.55); color:var(--text); font-size:14px;
}
td .btn-update{padding:8px 12px;border:1px solid var(--border);border-radius:10px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-weight:800;cursor:pointer;font-size:13px}
td .btn-clear{padding:8px 12px;border:1px solid #a31d33;border-radius:10px;background:linear-gradient(135deg,#e11d48,#be123c);color:#fff;font-weight:800;cursor:pointer;font-size:13px}
td .btn-update:hover, td .btn-clear:hover{filter:brightness(1.05)}
.tools{display:flex;gap:6px;flex-wrap:wrap}

/* Mobile-friendly actions */
@media (max-width:860px){
  table{min-width:900px}
  td .tools{gap:6px}
}
@media (max-width:640px){
  table{min-width:840px}
  td .tools{flex-direction:column; align-items:stretch}
  td .btn-update, td .btn-clear{width:100%; padding:10px 12px; font-size:14px}
}
.fab{position:fixed;right:12px;bottom:12px;width:40px;height:40px;border-radius:50%;display:grid;place-items:center;
  background:linear-gradient(135deg,#0ea5e9,#06b6d4);color:#fff;border:1px solid #0ea5e9;cursor:pointer;box-shadow:var(--shadow);font-size:16px}
.fab:hover{transform:translateY(-2px)}
</style>
</head>
<body>

<div class="topbar">
  <div class="brand">
    <div class="logo">🎓</div>
    <div class="title">แผงควบคุมสถานะนักศึกษา</div>
  </div>
  <div class="nav-actions">
    <div style="color:var(--muted);font-size:13px">ยินดีต้อนรับ, <b><?php echo h($_SESSION['admin_username'] ?? 'Admin'); ?></b></div>
    <a class="btn" href="admin_dashboard.php">หน้าหลัก</a>
  </div>
</div>

<div class="container">
  <div class="card">
    <div class="header">
      <h1>จัดการสถานะนักศึกษา</h1>
    </div>

    <?php if ($message): ?><div class="alert alert-success" id="flash-ok">✅ <?php echo $message; ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-danger" id="flash-bad">⚠️ <?php echo $error;   ?></div><?php endif; ?>

    <div class="toolbar">
      <div>
        <label for="search" style="display:block;margin-bottom:6px;color:#94a3b8;font-size:12px">ค้นหา (รหัส/ชื่อ)</label>
        <input id="search" class="input" type="text" placeholder="เช่น 66010001 หรือ สมชาย ใจดี">
      </div>
      <div>
        <label for="filterStatus" style="display:block;margin-bottom:6px;color:#94a3b8;font-size:12px">กรองสถานะ</label>
        <select id="filterStatus" class="select">
          <option value="">— แสดงทั้งหมด —</option>
          <option value="active">กำลังศึกษา</option>
          <option value="graduated">สำเร็จการศึกษา</option>
          <option value="leave">ลาพักการศึกษา</option>
          <option value="suspended">พ้นสภาพการศึกษา</option>
        </select>
      </div>
      <div>
        <label style="display:block;margin-bottom:6px;color:#94a3b8;font-size:12px">สรุป</label>
        <div class="tools" id="summary">
          <span class="badge">ทั้งหมด: <b id="sumAll">0</b></span>
          <span class="badge">กำลังศึกษา: <b id="sumActive">0</b></span>
          <span class="badge">จบแล้ว: <b id="sumGraduated">0</b></span>
        </div>
      </div>
      <div style="align-self:end">
        <button class="btn btn-primary" id="clearFilters" type="button">ยกเลิก</button>
      </div>
    </div>

    <div class="table-wrap" style="margin-top:10px">
      <table id="studentsTable">
        <thead>
          <tr>
            <th style="width:130px">รหัสนักศึกษา</th>
            <th>ชื่อ-นามสกุล</th>
            <th style="width:190px">ทำแบบทดสอบ</th>
            <th style="width:260px">สิทธิ์ปกติ / ใช้ไป / เหลือ</th>
            <th style="width:180px">เพิ่มสิทธิ์</th>
            <th style="width:240px">สถานะนักศึกษา</th>
            <th class="sticky-right" style="width:240px">บันทึก / ล้างค่าการทำฯ</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($students_status_result && $students_status_result->num_rows > 0): ?>
          <?php while($row = $students_status_result->fetch_assoc()):
            $sid        = $row['student_id'];
            $fullname   = $row['full_name'] ?? 'ไม่พบชื่อ';
            $used       = (int)$row['quiz_attempts_live'];
            $BASE_LIMIT = 3;
            $remain     = max(0, $BASE_LIMIT - $used);
            $ov         = (int)$row['admin_override_attempts'];
            $ast        = $row['academic_status'] ?? 'active';
            $badgeCls = [
              'active'=>'st-active','graduated'=>'st-graduated','leave'=>'st-leave','suspended'=>'st-suspended'
            ][$ast] ?? 'st-active';
          ?>
          <tr data-status="<?php echo h($ast); ?>">
            <td style="font-weight:800;white-space:nowrap"><?php echo h($sid); ?></td>
            <td style="min-width:160px"><?php echo h($fullname); ?></td>
            <td>
              <span class="badge" title="นับจาก test_history ถ้ามี (และไม่ถูก void); ไม่มีก็ใช้ค่า fallback ใน student_quiz_status">
                <?php echo $used; ?> ครั้ง
              </span>
            </td>
            <td>
              <div class="tools">
                <span class="badge">สิทธิ์: <b><?php echo $BASE_LIMIT; ?></b></span>
                <span class="badge">ใช้ไป: <b><?php echo $used; ?></b></span>
                <span class="badge">เหลือ: <b><?php echo $remain; ?></b></span>
              </div>
            </td>

            <form action="manage_students.php" method="POST">
              <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="student_id" value="<?php echo h($sid); ?>">
              <input type="hidden" name="full_name" value="<?php echo h($fullname); ?>">
              <td><input type="number" name="admin_override_attempts" value="<?php echo h($ov); ?>" min="0"></td>
              <td>
                <div class="tools" style="align-items:center">
                  <select name="academic_status" class="statusSelect">
                    <?php
                      $opts=['active'=>'กำลังศึกษา','graduated'=>'สำเร็จการศึกษา','leave'=>'ลาพักการศึกษา','suspended'=>'พ้นสภาพการศึกษา'];
                      foreach($opts as $k=>$label){ $sel=($ast===$k)?'selected':''; echo "<option value=\"".h($k)."\" $sel>".h($label)."</option>"; }
                    ?>
                  </select>
                  <span class="badge <?php echo $badgeCls; ?> currentBadge"><?php echo h($opts[$ast] ?? 'กำลังศึกษา'); ?></span>
                </div>
              </td>
              <td class="sticky-right">
                <div class="tools">
                  <button type="submit" name="update_status" class="btn-update">อัปเดต</button>
                  <button type="submit" name="clear_attempts" value="1" class="btn-clear"
                          onclick="return confirm('ยืนยันล้างค่าการทำแบบทดสอบของ <?php echo h($fullname); ?> (<?php echo h($sid); ?>) ?\n- จะรีเซ็ตจำนวนครั้งที่นับ\n- ถ้ามี test_history ที่ไม่ถูก void จะถูกลบ/ทำเครื่องหมาย void ให้');">
                     ลบข้อมูล
                  </button>
                </div>
              </td>
            </form>
          </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr><td colspan="7" style="text-align:center;color:#94a3b8">ไม่พบข้อมูลนักศึกษา</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<button class="fab" id="toTop" title="เลื่อนขึ้น">⬆️</button>

<script>
/* Alerts auto-hide */
setTimeout(()=>{
  const ok=document.getElementById('flash-ok'); if(ok){ ok.style.transition='opacity .6s'; ok.style.opacity='0'; setTimeout(()=>ok.remove(),600); }
  const bad=document.getElementById('flash-bad'); if(bad){ bad.style.transition='opacity .6s'; bad.style.opacity='0'; setTimeout(()=>bad.remove(),600); }
}, 4500);

/* Filters & summary */
const q=document.getElementById('search');
const fs=document.getElementById('filterStatus');
const clearBtn=document.getElementById('clearFilters');
const rows=[...document.querySelectorAll('#studentsTable tbody tr')];
function norm(s){ return (s||'').toString().toLowerCase().trim(); }
function applyFilters(){
  const txt=norm(q.value); const st=fs.value;
  let all=0, act=0, grad=0;
  rows.forEach(r=>{
    const sid=r.cells[0]?.textContent||'', name=r.cells[1]?.textContent||'', cur=r.getAttribute('data-status')||'';
    const okTxt=!txt || norm(sid).includes(txt) || norm(name).includes(txt);
    const okSt =!st || st===cur;
    r.style.display=(okTxt&&okSt)?'':'none';
    if(r.style.display===''){ all++; if(cur==='active') act++; if(cur==='graduated') grad++; }
  });
  document.getElementById('sumAll').textContent=all;
  document.getElementById('sumActive').textContent=act;
  document.getElementById('sumGraduated').textContent=grad;
}
q.addEventListener('input',applyFilters);
fs.addEventListener('change',applyFilters);
clearBtn.addEventListener('click',()=>{ q.value=''; fs.value=''; applyFilters(); });
applyFilters();

/* Row UX: Enter to submit + live badge */
rows.forEach(r=>{
  const form=r.querySelector('form'); if(!form) return;
  const inputs=form.querySelectorAll('input, select');
  const statusSelect=form.querySelector('.statusSelect');
  const badge=r.querySelector('.currentBadge');
  inputs.forEach(el=> el.addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); form.submit(); }}));
  statusSelect?.addEventListener('change', ()=>{
    const map={active:{text:'กำลังศึกษา',cls:'st-active'}, graduated:{text:'สำเร็จการศึกษา',cls:'st-graduated'},
               leave:{text:'ลาพักการศึกษา',cls:'st-leave'}, suspended:{text:'พ้นสภาพการศึกษา',cls:'st-suspended'}};
    const v=statusSelect.value, m=map[v]||map.active;
    badge.textContent=m.text; badge.className='badge currentBadge '+m.cls; r.setAttribute('data-status',v);
    applyFilters();
  });
});

/* Scroll to top */
const toTop=document.getElementById('toTop');
toTop.addEventListener('click',()=>window.scrollTo({top:0,behavior:'smooth'}));
window.addEventListener('scroll',()=>{ toTop.style.display=(window.scrollY>400)?'grid':'none'; });
toTop.style.display='none';
</script>
</body>
</html>