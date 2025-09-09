<?php
// View_teacher.php
session_start();
if (empty($_SESSION['loggedin']) || (($_SESSION['user_type'] ?? '') !== 'admin')) {
    header('Location: login.php?error=unauthorized'); exit;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));

require_once 'db_connect.php';

// ===== Admin guard =====
if (empty($_SESSION['loggedin']) || (($_SESSION['user_type'] ?? '') !== 'admin')) {
    header('Location: login.php?error=unauthorized');
    exit;
}

// ===== CSRF (lightweight) =====
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ===== Read status from query =====
$status     = $_GET['status']     ?? '';
$created_id = $_GET['teacher_id'] ?? '';

$reset_name = '';
if ($status === 'reset_ok' && $created_id !== '') {
    if ($stmt = $conn->prepare("SELECT name FROM teacher WHERE teacher_id = ? LIMIT 1")) {
        $stmt->bind_param('s', $created_id);
        $stmt->execute();
        $stmt->bind_result($nm);
        if ($stmt->fetch()) $reset_name = $nm;
        $stmt->close();
    }
}
// ===== Fetch teachers =====
$teachers = [];
$err = '';
$sql = "SELECT teacher_id, name, email, username, role FROM teacher ORDER BY teacher_id ASC";
if ($stmt = $conn->prepare($sql)) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $teachers[] = $row;
    $stmt->close();
} else {
    $err = $conn->error;
}

// ===== Fetch pending reset requests =====
$pending = [];
$q = "
  SELECT pr.id, pr.identifier AS teacher_id, pr.expires_at, pr.created_at,
         t.name, t.email
  FROM password_resets pr
  JOIN teacher t ON t.teacher_id = pr.identifier
  WHERE pr.user_type='teacher' AND pr.status='pending' AND pr.used=0
  ORDER BY pr.created_at DESC
";
if ($r = $conn->query($q)) {
    while ($row = $r->fetch_assoc()) $pending[] = $row;
}

// ===== Helper =====
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
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
h1{margin:0 0 8px}
h3{margin:0 0 8px}
.sub{color:var(--muted);margin-bottom:14px}
.card{background:rgba(255,255,255,.04);border:1px solid var(--line);border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.25);padding:18px}
.toolbar{display:flex;gap:10px;align-items:center;justify-content:space-between;margin:10px 0 14px}
.left,.right{display:flex;gap:10px;align-items:center}
.btn{padding:10px 14px;border-radius:10px;border:1px solid var(--line);background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;cursor:pointer;text-decoration:none;font-weight:600}
.btn.secondary{background:transparent;color:var(--text)}
.search{padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:rgba(17,24,39,.65);color:var(--text);min-width:240px}
.table-wrap{overflow:auto;border-radius:12px;border:1px solid var(--line)}
table{width:100%;border-collapse:collapse;min-width:760px}
th,td{padding:12px 12px;border-bottom:1px solid var(--line)}
th{text-align:left;background:rgba(255,255,255,.04);position:sticky;top:0}
tr.highlight{outline:2px solid var(--ok);box-shadow:0 0 0 2px var(--ok) inset}
.pill{display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid var(--line);background:#0b1a36;color:#cbd5e1;font-size:12px}
.alert{padding:12px 14px;border-radius:10px;margin:10px 0;border:1px solid}
.ok{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.35)}
.warn{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.35)}
.danger{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.35)}
.muted{color:var(--muted)}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.action-btn{padding:6px 10px;border-radius:8px;border:1px solid var(--line);background:transparent;color:#fff;cursor:pointer}
.action-btn.reset{background:linear-gradient(135deg,#dc2626,#b91c1c)}
.action-btn[disabled]{opacity:.5;cursor:not-allowed}
.empty{padding:22px;text-align:center;color:var(--muted)}
@media (max-width:720px){.toolbar{flex-direction:column;align-items:stretch}.search{width:100%}}
</style>
</head>
<body>
<div class="container">

  <!-- Header -->
  <div class="card" style="margin-bottom:14px">
    <h1>รายการอาจารย์</h1>
   <!-- <div class="sub">
      ข้อมูลจากตาราง <span class="pill">teacher</span> · username = teacher_id · 
      default password = <span class="pill">123456</span>
    </div>-->

    <?php if ($status === 'created' && $created_id): ?>
      <div class="alert ok">✅ เพิ่มบัญชีอาจารย์ <strong><?= h($created_id) ?></strong> สำเร็จ</div>
    <?php elseif ($status === 'reset_ok' && !empty($_GET['teacher_id'])): ?>
  <div class="alert ok">
    ✅ รีเซ็ตรหัสผ่านอาจารย์
    <strong><?= h($reset_name !== '' ? $reset_name : $_GET['teacher_id']) ?></strong>
    เรียบร้อยแล้ว
  </div>

    <?php elseif ($status === 'reset_fail'): ?>
      <div class="alert danger">❌ รีเซ็ตรหัสผ่านไม่สำเร็จ กรุณาลองใหม่</div>
    <?php elseif ($status === 'forbidden'): ?>
      <div class="alert danger">⛔ คุณไม่มีสิทธิ์ดำเนินการ</div>
    <?php endif; ?>

    <?php if ($err): ?>
      <div class="alert danger">เกิดข้อผิดพลาดในการดึงข้อมูล: <?= h($err) ?></div>
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
              <th style="width:140px;">รหัสอาจารย์</th>
              <th>ชื่อ</th>
              <th>อีเมล</th>
              <th style="width:180px;">สร้างเมื่อ</th>
              <th style="width:180px;">หมดอายุ</th>
              <th style="width:240px;">การทำงาน</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($pending as $p): ?>
              <tr>
                <td><?=h($p['teacher_id'])?></td>
                <td><?=h($p['name'])?></td>
                <td><?=h($p['email'])?></td>
                <td><?=h($p['created_at'])?></td>
                <td><?=h($p['expires_at'])?></td>
                <td class="actions">
                  <form class="approve-form" method="post" action="reset_teacher_password.php">
                    <input type="hidden" name="teacher_id" value="<?=h($p['teacher_id'])?>">
                    <input type="hidden" name="csrf_token" value="<?=h($_SESSION['csrf_token'])?>">
                    <button type="submit" class="action-btn reset">อนุมัติรีเซ็ต</button>
                    </form>
                  <form class="reject-form" method="post" action="reject_reset_request.php">
                    <input type="hidden" name="teacher_id" value="<?=h($p['teacher_id'])?>">
                    <input type="hidden" name="csrf_token" value="<?=h($csrf)?>">
                    <button type="submit" class="action-btn">ปฏิเสธ</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <!--  <div class="muted" style="margin-top:8px">หมายเหตุ: อนุมัติแล้วระบบจะตั้งรหัสผ่านใหม่เป็น <b>123456</b> และปิดคำขอโดยอัตโนมัติ</div> -->
    <?php endif; ?>
  </div>

  <!-- Teacher list -->
  <div class="card">
    <h3>รายชื่ออาจารย์ทั้งหมด</h3>
    <div class="table-wrap" style="margin-top:8px">
      <table id="tbl">
        <thead>
          <tr>
            <th style="width:140px;">รหัสอาจารย์</th>
            <th>ชื่อ-นามสกุล</th>
            <th>อีเมล</th>
            <th>Username</th>
            <th style="width:120px;">Role</th>
            <th style="width:180px;">การทำงาน</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$teachers): ?>
          <tr><td colspan="6" class="empty">ยังไม่มีข้อมูล</td></tr>
        <?php else: ?>
          <?php foreach ($teachers as $row): ?>
            <?php
              $hl = ($status === 'created' && $created_id && $created_id === $row['teacher_id']) ? 'highlight' : '';
              // มีคำขอค้างไหม
              $hasPending = false;
              if (!empty($pending)) {
                foreach ($pending as $p) {
                  if ($p['teacher_id'] === $row['teacher_id']) { $hasPending = true; break; }
                }
              }
            ?>
            <tr class="<?= $hl ?>">
              <td><?= h($row['teacher_id']) ?></td>
              <td><?= h($row['name']) ?></td>
              <td><?= h($row['email']) ?></td>
              <td><?= h($row['username']) ?></td>
              <td><?= h($row['role']) ?></td>
              <td class="actions">
                <form class="reset-form" method="post" action="reset_teacher_password.php">
                  <input type="hidden" name="teacher_id" value="<?= h($row['teacher_id']) ?>">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <button type="submit" class="action-btn reset" <?= $hasPending ? '' : 'disabled title="ไม่มีคำขอรีเซ็ตจากอาจารย์"' ?>>
                    รีเซ็ตรหัส
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="muted" style="margin-top:10px">เคล็ดลับ: พิมพ์ในช่องค้นหาเพื่อกรองรายการแบบทันที</div>
  </div>

</div>

<script>
// ---- client-side filter ----
const q = document.getElementById('q');
const tbody = document.getElementById('tbl').querySelector('tbody');
q.addEventListener('input', function(){
  const term = this.value.toLowerCase().trim();
  for (const tr of tbody.querySelectorAll('tr')) {
    if (tr.classList.contains('empty')) continue;
    const text = tr.innerText.toLowerCase();
    tr.style.display = text.includes(term) ? '' : 'none';
  }
});

// ---- helper AJAX ----
function postAjax(form, url, okRedirectIfAny) {
  const fd = new FormData(form);
  return fetch(url, { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r=>r.json())
    .then(d=>{
      alert(d.message || (d.success?'สำเร็จ':'ไม่สำเร็จ'));
      if (d.success && okRedirectIfAny) {
        location.href = okRedirectIfAny;
      } else if (d.success) {
        location.reload();
      }
    })
    .catch(()=>alert('เกิดข้อผิดพลาดในการเชื่อมต่อ'));
}

// ---- approve (from Pending card) ----
document.querySelectorAll('.approve-form').forEach(f => {
  f.addEventListener('submit', e => {
    e.preventDefault();
    const id = f.querySelector('input[name="teacher_id"]').value;
    // ชื่อจาก data-name; ถ้าเผื่อไม่มี ให้ลองดึงจากคอลัมน์ชื่อ (คอลัมน์ที่ 2) หรือ fallback เป็น id
    const nm = f.dataset.name 
            || f.closest('tr')?.querySelector('td:nth-child(2)')?.textContent.trim() 
            || id;

    if (!confirm(`อนุมัติรีเซ็ตรหัสของ ${nm} ?`)) return;
    postAjax(f, 'reset_teacher_password.php', 'View_teacher.php?status=reset_ok&teacher_id=' + encodeURIComponent(id));
  });
});


// ---- reject (from Pending card) ----
document.querySelectorAll('.reject-form').forEach(f=>{
  f.addEventListener('submit', e=>{
    e.preventDefault();
    const id = f.querySelector('input[name="teacher_id"]').value;
    if (!confirm('ปฏิเสธคำขอรีเซ็ตของ '+id+' ?')) return;
    postAjax(f, 'reject_reset_request.php', null);
  });
});

// ---- fallback reset button in main table (enabled only if pending exists) ----
document.querySelectorAll('.reset-form').forEach(f=>{
  f.addEventListener('submit', function(e){
    e.preventDefault();
    const idInput = f.querySelector('input[name="teacher_id"]');
    const teacherId = idInput ? idInput.value : '';
    if (!confirm('ยืนยันรีเซ็ตรหัสผ่านของ ' + teacherId + ' เรียบร้อยแล้ว')) return;
    postAjax(f, 'reset_teacher_password.php', 'View_teacher.php?status=reset_ok&teacher_id='+encodeURIComponent(teacherId));
  });
});
</script>
</body>
</html>