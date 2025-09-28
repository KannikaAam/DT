<?php
/* view_teacher.php — รายการอาจารย์ + อนุมัติ/ปฏิเสธคำขอรีเซ็ตรหัส
   - เฉพาะ admin
   - ใช้ค่ารีเซ็ตเริ่มต้นเป็น 123456 (แฮชเก็บใน teacher.password_hash)
   - อัปเดตสถานะใน password_resets: approved/rejected, used, approved_by
   - เพิ่มการจัดการสถานะอาจารย์ (active/inactive) — admin ปรับได้จากตาราง
*/
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

/* ===== Guard: admin only ===== */
if (empty($_SESSION['loggedin']) || (($_SESSION['user_type'] ?? '') !== 'admin')) {
    header('Location: login.php?error=unauthorized'); exit;
}

/* ===== CSRF ===== */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* ===== Config ===== */
define('DEFAULT_RESET_PASSWORD', '123456'); // << ค่ารีเซ็ตเริ่มต้น

/* ===== DB ===== */
require_once __DIR__ . '/db_connect.php'; // ควรให้ $conn = new mysqli(...)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (isset($conn) && $conn instanceof mysqli) { $conn->set_charset('utf8mb4'); }

/* ===== Schema guard: ensure teacher.status exists ===== */
try {
    $chk = 0;
    if ($rs = $conn->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                              AND TABLE_NAME='teacher' 
                              AND COLUMN_NAME='status'")) {
        if ($row = $rs->fetch_assoc()) $chk = (int)$row['c'];
        $rs->close();
    }
    if ($chk === 0) {
        // เพิ่มคอลัมน์สถานะ ถ้ายังไม่มี
        $conn->query("ALTER TABLE teacher 
                      ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER role");
    }
} catch (Throwable $e) {
    // ไม่ทำอะไร ปล่อยให้หน้าใช้งานต่อ (กรณีสิทธิ์ ALTER ไม่มี)
}

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===== Actions: approve / reject / set_status ===== */
$toast = ['type'=>'', 'msg'=>''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'] ?? '')) {
            throw new Exception('CSRF verification failed');
        }
        $action     = $_POST['action']     ?? '';
        $reset_id   = (int)($_POST['reset_id']   ?? 0);
        $teacher_id = (int)($_POST['teacher_id'] ?? 0);

        if ($action === 'approve' || $action === 'reject') {
            if (!$reset_id || !$teacher_id) throw new Exception('ข้อมูลคำขอไม่ครบ');
        }

        if ($action === 'approve') {
            // 1) ตั้งรหัสใหม่เป็น 123456
            $newpass = DEFAULT_RESET_PASSWORD;
            $hash    = password_hash($newpass, PASSWORD_BCRYPT);

            $stmt = $conn->prepare("UPDATE teacher SET password_hash=? WHERE id=? LIMIT 1");
            $stmt->bind_param('si', $hash, $teacher_id);
            $stmt->execute();
            $stmt->close();

            // 2) ปิดคำขอใน password_resets
            $admin_name = $_SESSION['admin_username'] ?? 'admin';
            $stmt = $conn->prepare("UPDATE password_resets 
                SET status='approved', used=1, approved_by=?, expires_at=NOW()
                WHERE id=? AND user_type='teacher' AND identifier=? LIMIT 1");
            $stmt->bind_param('sii', $admin_name, $reset_id, $teacher_id);
            $stmt->execute();
            $stmt->close();

            $toast = ['type'=>'ok', 'msg'=>'รีเซ็ตรหัสผ่านสำเร็จ'];
        } elseif ($action === 'reject') {
            $admin_name = $_SESSION['admin_username'] ?? 'admin';
            $stmt = $conn->prepare("UPDATE password_resets 
                SET status='rejected', used=0, approved_by=?
                WHERE id=? AND user_type='teacher' AND identifier=? LIMIT 1");
            $stmt->bind_param('sii', $admin_name, $reset_id, $teacher_id);
            $stmt->execute();
            $stmt->close();

            $toast = ['type'=>'warn', 'msg'=>'ปฏิเสธคำขอเรียบร้อย'];
        } elseif ($action === 'set_status') {
            // === ใหม่: ปรับสถานะอาจารย์ active/inactive ===
            $teacher_id = (int)($_POST['teacher_id'] ?? 0);
            $new_status = $_POST['new_status'] ?? 'active';
            if (!$teacher_id) throw new Exception('ไม่พบรหัสอาจารย์');

            // validate ค่า
            $new_status = ($new_status === 'inactive') ? 'inactive' : 'active';

            $stmt = $conn->prepare("UPDATE teacher SET status=? WHERE id=? LIMIT 1");
            $stmt->bind_param('si', $new_status, $teacher_id);
            $stmt->execute();
            $stmt->close();

            $toast = ['type'=>'ok', 'msg'=> ($new_status==='inactive'?'ปิดการใช้งาน':'เปิดการใช้งาน') . ' อาจารย์สำเร็จ'];
        }
    } catch (Throwable $e) {
        $toast = ['type'=>'danger', 'msg'=>'ดำเนินการไม่สำเร็จ: '.$e->getMessage()];
    }
}

/* ===== For legacy query toasts ===== */
$status     = $_GET['status']     ?? '';
$created_id = $_GET['teacher_id'] ?? '';
$reset_name = '';
if ($status === 'reset_ok' && $created_id !== '') {
    $stmt = $conn->prepare("SELECT name FROM teacher WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $created_id);
    $stmt->execute();
    $stmt->bind_result($nm);
    if ($stmt->fetch()) $reset_name = $nm;
    $stmt->close();
}

/* ===== Fetch teachers (รวม status) ===== */
$teachers = []; $err = '';
$sql = "SELECT id AS teacher_id, teacher_code, name, email, username, role, status
        FROM teacher ORDER BY id ASC";
$stmt = $conn->prepare($sql);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $teachers[] = $row;
$stmt->close();

/* ===== Fetch pending password reset requests ===== */
$pending = [];
$q = "SELECT pr.id, pr.identifier AS teacher_id, pr.expires_at, pr.created_at,
             t.name, t.email, t.teacher_code
      FROM password_resets pr
      JOIN teacher t ON t.id = pr.identifier
      WHERE pr.user_type='teacher' AND pr.status='pending' AND pr.used=0
      ORDER BY pr.created_at DESC";
$r = $conn->query($q);
while ($row = $r->fetch_assoc()) $pending[] = $row;
$r->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>รายการอาจารย์</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0b1220; --card:#111827; --text:#e5e7eb; --muted:#94a3b8;
  --accent:#2563eb; --ok:#22c55e; --warn:#f59e0b; --danger:#ef4444; --line:rgba(255,255,255,.1);
}
*{box-sizing:border-box}
body{margin:0;font-family:'Sarabun',system-ui,Segoe UI,Roboto,sans-serif;background:linear-gradient(135deg,#0b1220,#0f172a);color:var(--text)}
.container{max-width:1100px;margin:32px auto;padding:16px}
h1{margin:0 0 8px} h3{margin:0 0 8px}
.card{background:rgba(255,255,255,.04);border:1px solid var(--line);border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.25);padding:18px}
.toolbar{display:flex;gap:10px;align-items:center;justify-content:space-between;margin:10px 0 14px}
.left,.right{display:flex;gap:10px;align-items:center}
.btn{padding:8px 12px;border-radius:10px;border:1px solid var(--line);background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;cursor:pointer;text-decoration:none;font-weight:600}
.btn.secondary{background:transparent;color:var(--text)}
.btn.mini{padding:6px 10px;font-size:12px}
.search{padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:rgba(17,24,39,.65);color:var(--text);min-width:240px}
.table-wrap{overflow:auto;border-radius:12px;border:1px solid var(--line)}
table{width:100%;border-collapse:collapse;min-width:980px}
th,td{padding:10px 12px;border-bottom:1px solid var(--line)}
th{text-align:left;background:rgba(255,255,255,.04);position:sticky;top:0}
.alert{padding:12px 14px;border-radius:10px;margin:10px 0;border:1px solid}
.ok{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.35)}
.warn{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.35)}
.danger{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.35)}
.muted{color:var(--muted)} .empty{padding:22px;text-align:center;color:var(--muted)}
.pill{display:inline-block;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:700}
.pill.ok{background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.35)}
.pill.danger{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.35)}
.select{padding:8px 10px;border-radius:10px;border:1px solid var(--line);background:rgba(17,24,39,.65);color:var(--text)}
@media (max-width:720px){.toolbar{flex-direction:column;align-items:stretch}.search{width:100%}}
</style>
</head>
<body>
<div class="container">

  <div class="card" style="margin-bottom:14px">
    <h1>รายการอาจารย์</h1>

    <?php if ($toast['msg']): ?>
      <div class="alert <?= h($toast['type'] ?: 'ok') ?>"><?= h($toast['msg']) ?></div>
    <?php endif; ?>

    <?php if ($status === 'created' && $created_id): ?>
      <div class="alert ok">เพิ่มบัญชีอาจารย์สำเร็จ</div>
    <?php elseif ($status === 'reset_ok' && !empty($_GET['teacher_id'])): ?>
      <div class="alert ok">✅ รีเซ็ตรหัสผ่านอาจารย์ <strong><?= h($reset_name !== '' ? $reset_name : $_GET['teacher_id']) ?></strong> เรียบร้อยแล้ว</div>
    <?php elseif ($status === 'reset_fail'): ?>
      <div class="alert danger">❌ รีเซ็ตรหัสผ่านไม่สำเร็จ กรุณาลองใหม่</div>
    <?php elseif ($status === 'forbidden'): ?>
      <div class="alert danger">⛔ คุณไม่มีสิทธิ์ดำเนินการ</div>
    <?php endif; ?>

    <div class="toolbar">
      <div class="left">
        <a href="admin_add_teacher.php" class="btn">+ เพิ่มอาจารย์</a>
        <span class="muted"><?= count($teachers) ?> รายการ</span>
      </div>
      <div class="right">
        <input type="search" id="q" class="search" placeholder="ค้นหา ชื่อ/อีเมล/รหัส/username">
        <a href="teacher_registration.php" class="btn secondary">ยกเลิก</a>
      </div>
    </div>
  </div>

  <!-- Pending reset requests -->
  <div class="card" style="margin:14px 0">
    <h3>คำขอรีเซ็ตรหัส (รออนุมัติ)</h3>
    <?php if (!$pending): ?>
      <div class="muted">ไม่มีคำขอค้าง</div>
    <?php else: ?>
      <div class="table-wrap" style="margin-top:8px">
        <table>
          <thead>
            <tr>
              <th style="width:160px;">รหัสประจำตัวอาจารย์</th>
              <th>ชื่อ</th>
              <th>อีเมล</th>
              <th style="width:160px;">สร้างเมื่อ</th>
              <th style="width:160px;">หมดอายุ</th>
              <th style="width:220px;">การดำเนินการ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($pending as $p): ?>
              <tr>
                <td><?= h($p['teacher_code']) ?></td>
                <td><?= h($p['name']) ?></td>
                <td><?= h($p['email']) ?></td>
                <td><?= h($p['created_at']) ?></td>
                <td><?= h($p['expires_at']) ?></td>
                <td>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="reset_id" value="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="teacher_id" value="<?= (int)$p['teacher_id'] ?>">
                    <button class="btn mini" type="submit">อนุมัติ</button>
                  </form>
                  <form method="post" style="display:inline;margin-left:6px">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="reset_id" value="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="teacher_id" value="<?= (int)$p['teacher_id'] ?>">
                    <button class="btn mini secondary" type="submit">ปฏิเสธ</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Teacher list -->
  <div class="card">
    <h3>รายชื่ออาจารย์ทั้งหมด</h3>
    <div class="table-wrap" style="margin-top:8px">
      <table id="tbl">
        <thead>
          <tr>
            <th style="width:140px;">รหัสประจำตัวอาจารย์</th>
            <th style="width:160px;">ชื่อ-นามสกุล</th>
            <th style="width:200px;">อีเมล</th>
            <th style="width:140px;">Username</th>
            <th style="width:120px;">Role</th>
            <th style="width:110px;">สถานะ</th>
            <th style="width:220px;">เปลี่ยนสถานะ</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$teachers): ?>
          <tr><td colspan="7" class="empty">ยังไม่มีข้อมูล</td></tr>
        <?php else: foreach ($teachers as $row): 
              $st = ($row['status'] ?? 'active');
              $isActive = ($st === 'active');
        ?>
          <tr>
            <td><?= h($row['teacher_code'] ?? '') ?></td>
            <td><?= h($row['name']) ?></td>
            <td><?= h($row['email']) ?></td>
            <td><?= h($row['username']) ?></td>
            <td><?= h($row['role']) ?></td>
            <td>
              <?php if ($isActive): ?>
                <span class="pill ok">ใช้งาน</span>
              <?php else: ?>
                <span class="pill danger">ไม่ได้ใช้งาน</span>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" style="display:inline-flex;gap:8px;align-items:center">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="teacher_id" value="<?= (int)$row['teacher_id'] ?>">
                <select class="select" name="new_status">
                  <option value="active"   <?= $isActive?'selected':'' ?>>ใช้งาน</option>
                  <option value="inactive" <?= !$isActive?'selected':'' ?>>ไม่ได้ใช้งาน</option>
                </select>
                <button class="btn mini" type="submit">บันทึก</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="muted" style="margin-top:8px">
      * ถ้ากำหนดเป็น <strong>ไม่ได้ใช้งาน</strong> แนะนำให้หน้าล็อกอินตรวจสอบ <code>status='active'</code> เพื่อป้องกันการเข้าสู่ระบบของอาจารย์ที่ถูกปิดใช้งาน
    </div>
  </div>

</div>

<script>
const q = document.getElementById('q');
const tbody = document.getElementById('tbl').querySelector('tbody');
q?.addEventListener('input', function(){
  const term = this.value.toLowerCase().trim();
  for (const tr of tbody.querySelectorAll('tr')) {
    if (tr.classList.contains('empty')) continue;
    const text = tr.innerText.toLowerCase();
    tr.style.display = text.includes(term) ? '' : 'none';
  }
});
</script>
</body>
</html>