<?php
// teacher.php — Dashboard อาจารย์ + เปลี่ยนรหัสผ่าน (มีปุ่มรีเซ็ตฟอร์ม) + ลิงก์รีเซ็ต
session_start();
if (empty($_SESSION['loggedin']) || (($_SESSION['user_type'] ?? '') !== 'teacher')) {
    header('Location: index.php?error=unauthorized'); exit;
}
require_once 'db_connect.php'; // ต้องมีตัวแปร $conn (mysqli)

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// เตรียม CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// หา teacher_id
$teacherId = $_SESSION['teacher_id'] ?? null;
if (!$teacherId) {
    $username = $_SESSION['username'] ?? null;
    if ($username) {
        $stmt = $conn->prepare("SELECT teacher_id FROM teacher WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $stmt->bind_result($tid);
        if ($stmt->fetch()) $teacherId = $tid;
        $stmt->close();
    }
}
if (!$teacherId) { die('ไม่พบรหัสอาจารย์ในเซสชัน กรุณาออกและเข้าสู่ระบบใหม่'); }

// helper: ตรวจตารางมีอยู่ไหม
function tableExists(mysqli $conn, string $name): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->store_result();
    $ok = $stmt->num_rows > 0;
    $stmt->close();
    return $ok;
}

// ====== จัดการเปลี่ยนรหัสผ่าน ======
// ====== จัดการเปลี่ยนรหัสผ่าน ======
$pw_error = '';
$pw_success = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'change_password') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $pw_error = 'CSRF ไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $current = trim((string)($_POST['current_password'] ?? ''));
        $new     = trim((string)($_POST['new_password'] ?? ''));
        $confirm = trim((string)($_POST['confirm_password'] ?? ''));

        if ($current === '' || $new === '' || $confirm === '') {
            $pw_error = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
        } elseif ($new !== $confirm) {
            $pw_error = 'รหัสผ่านใหม่และยืนยันไม่ตรงกัน';
        } elseif (mb_strlen($new) < 8) {
            $pw_error = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร';
        } else {
            // ดึงค่ารหัสที่เก็บไว้ทั้งสามคอลัมน์
            $tid = (int)$teacherId;
            $stmt = $conn->prepare("
                SELECT 
                    COALESCE(password_hash,'')    AS password_hash,
                    COALESCE(teacher_password,'') AS teacher_password,
                    COALESCE(password,'')         AS legacy_plain
                FROM teacher
                WHERE teacher_id = ?
                LIMIT 1
            ");
            $stmt->bind_param('i', $tid);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!$row) {
                $pw_error = 'ไม่พบข้อมูลอาจารย์';
            } else {
                $match = false;

                // 1) ตรวจกับ password_hash (คอลัมน์หลัก)
                if (!$match && $row['password_hash'] !== '' && password_get_info($row['password_hash'])['algo']) {
                    $match = password_verify($current, $row['password_hash']);
                }

                // 2) ตรวจกับ teacher_password (ถ้าเป็น hash เก่า)
                if (!$match && $row['teacher_password'] !== '' && password_get_info($row['teacher_password'])['algo']) {
                    $match = password_verify($current, $row['teacher_password']);
                }

                // 3) ตรวจกับ password แบบ legacy (plain / sha256)
                if (!$match && $row['legacy_plain'] !== '') {
                    if ($current === $row['legacy_plain'] || hash('sha256', $current) === $row['legacy_plain']) {
                        $match = true;
                    }
                }

                if (!$match) {
                    $pw_error = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
                } else {
                    // บันทึกรหัสใหม่: ใช้ password_hash เป็นมาตรฐาน และล้างคอลัมน์เก่า
                    $newHash = password_hash($new, PASSWORD_DEFAULT);
                    $up = $conn->prepare("
                        UPDATE teacher
                        SET password_hash = ?, password = NULL, teacher_password = NULL
                        WHERE teacher_id = ?
                    ");
                    $up->bind_param('si', $newHash, $tid);
                    if ($up->execute()) {
                        $pw_success = 'เปลี่ยนรหัสผ่านเรียบร้อย';
                    } else {
                        $pw_error = 'ไม่สามารถอัปเดตรหัสผ่านได้: '.$conn->error;
                    }
                    $up->close();
                }
            }
        }
    }
}

// ====== เตรียมข้อมูลกลุ่มและนักศึกษา ======
$memberTables = ['course_group_students', 'group_members', 'enrollments'];
$memberTable = null;
foreach ($memberTables as $t) { if (tableExists($conn, $t)) { $memberTable = $t; break; } }

$hasStudentsView = tableExists($conn, 'students');
$hasPersonalInfo  = tableExists($conn, 'personal_info');

$groups = [];
$sql = "SELECT g.group_id, g.group_name, g.course_id, 
               COALESCE(c.course_name, CONCAT('Course#', g.course_id)) AS course_name
        FROM course_groups g
        LEFT JOIN courses c ON c.course_id = g.course_id
        WHERE g.teacher_id = ?
        ORDER BY g.created_at DESC";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('s', $teacherId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $groups[] = $row;
    $stmt->close();
}

$selectedGroupId = isset($_GET['group_id']) ? trim($_GET['group_id']) : null;
$students = [];
$quizStats = [];

if ($selectedGroupId && $memberTable) {
    $memberQuery = "
        SELECT m.group_id, m.student_id AS student_id
        FROM {$memberTable} m
        WHERE m.group_id = ?
    ";
    if ($stmt = $conn->prepare($memberQuery)) {
        $stmt->bind_param('s', $selectedGroupId);
        $stmt->execute();
        $memberRes = $stmt->get_result();
        $studentIds = [];
        while ($r = $memberRes->fetch_assoc()) { if (!empty($r['student_id'])) $studentIds[] = $r['student_id']; }
        $stmt->close();

        if ($studentIds) {
            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            $types = str_repeat('s', count($studentIds));

            if ($hasStudentsView) {
                $sqlStd = "SELECT student_id, COALESCE(student_name, full_name, student_id) AS student_name, COALESCE(email, '') AS email
                           FROM students WHERE student_id IN ($placeholders) ORDER BY student_name";
                $stmt = $conn->prepare($sqlStd);
                $stmt->bind_param($types, ...$studentIds);
            } else {
                if ($hasPersonalInfo) {
                    $sqlStd = "SELECT u.student_id, COALESCE(p.full_name, u.student_id) AS student_name, COALESCE(p.email, '') AS email
                               FROM user_login u LEFT JOIN personal_info p ON p.id = u.personal_id
                               WHERE u.student_id IN ($placeholders) ORDER BY student_name";
                } else {
                    $sqlStd = "SELECT u.student_id, u.student_id AS student_name, '' AS email
                               FROM user_login u WHERE u.student_id IN ($placeholders) ORDER BY u.student_id";
                }
                $stmt = $conn->prepare($sqlStd);
                $stmt->bind_param($types, ...$studentIds);
            }
            $stmt->execute();
            $resStd = $stmt->get_result();
            while ($row = $resStd->fetch_assoc()) $students[] = $row;
            $stmt->close();

            $hasQuizzes = tableExists($conn, 'quizzes');
            $hasAttempts = tableExists($conn, 'quiz_attempts');
            if ($hasQuizzes && $hasAttempts) {
                $stmt = $conn->prepare("SELECT course_id FROM course_groups WHERE group_id = ? LIMIT 1");
                $stmt->bind_param('s', $selectedGroupId);
                $stmt->execute();
                $stmt->bind_result($courseId);
                $stmt->fetch();
                $stmt->close();

                if (!empty($courseId)) {
                    $quizMap = [];
                    $stmt = $conn->prepare("SELECT quiz_id, title FROM quizzes WHERE course_id = ?");
                    $stmt->bind_param('s', $courseId);
                    $stmt->execute();
                    $resQ = $stmt->get_result();
                    while ($q = $resQ->fetch_assoc()) $quizMap[$q['quiz_id']] = $q['title'];
                    $stmt->close();

                    if ($quizMap) {
                        $place = implode(',', array_fill(0, count($studentIds), '?'));
                        $types = str_repeat('s', count($studentIds));
                        $sqlA = "
                            SELECT a.student_id, a.quiz_id, a.score, a.status, a.submitted_at
                            FROM quiz_attempts a
                            INNER JOIN (
                                SELECT student_id, quiz_id, MAX(submitted_at) AS last_time
                                FROM quiz_attempts
                                WHERE student_id IN ($place) AND quiz_id IN (".implode(',', array_map('intval', array_keys($quizMap))).")
                                GROUP BY student_id, quiz_id
                            ) t
                            ON a.student_id = t.student_id AND a.quiz_id = t.quiz_id AND a.submitted_at = t.last_time
                            ORDER BY a.student_id, a.quiz_id
                        ";
                        $stmt = $conn->prepare($sqlA);
                        $stmt->bind_param($types, ...$studentIds);
                        $stmt->execute();
                        $resA = $stmt->get_result();
                        while ($a = $resA->fetch_assoc()) {
                            $sid = $a['student_id'];
                            if (!isset($quizStats[$sid])) $quizStats[$sid] = [];
                            $quizStats[$sid][] = [
                                'quiz_id' => $a['quiz_id'],
                                'quiz_title' => $quizMap[$a['quiz_id']] ?? ('Quiz#'.$a['quiz_id']),
                                'score' => $a['score'],
                                'status'=> $a['status'],
                                'time'  => $a['submitted_at'],
                            ];
                        }
                        $stmt->close();
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>หน้าหลักอาจารย์</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0f172a; --card:#111827; --text:#e5e7eb; --muted:#94a3b8; --accent:#2563eb; --line:rgba(255,255,255,.08);
}
*{box-sizing:border-box}
body{margin:0;background:linear-gradient(135deg,#0b1220,#111827);color:var(--text);font-family:'Sarabun',system-ui}
.container{max-width:1100px;margin:32px auto;padding:0 16px}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.card{background:linear-gradient(180deg,rgba(255,255,255,.02),rgba(255,255,255,.01));border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.25);backdrop-filter:blur(8px)}
h1{margin:0 0 6px}
.grid{display:grid;gap:16px}
@media(min-width:900px){.grid{grid-template-columns:320px 1fr}}
ul{list-style:none;padding:0;margin:0}
.group-item{border:1px solid var(--line);border-radius:12px;padding:12px;margin-bottom:10px;background:rgba(17,24,39,.6)}
.group-item a{color:#fff;text-decoration:none}
.badge{background:#0b1a36;border:1px solid var(--line);padding:3px 8px;border-radius:999px;font-size:12px;color:#cbd5e1}
.table{width:100%;border-collapse:collapse}
.table th,.table td{border-bottom:1px solid var(--line);padding:10px;text-align:left}
.sub{color:var(--muted)}
.toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.button{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid var(--line);text-decoration:none;color:#fff;background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.link{color:#cbd5e1;text-decoration:none}
.help{font-size:13px;color:var(--muted)}
.empty{padding:16px;border:1px dashed var(--line);border-radius:12px;text-align:center;color:#cbd5e1}
.kv{font-family:ui-monospace,Menlo,Consolas,monospace;color:#cbd5e1}

/* Modal */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000}
.modal-content{background:#ffffff;color:#111827;margin:5% auto;padding:18px;border-radius:14px;width:92%;max-width:520px}
.modal-header{display:flex;justify-content:space-between;align-items:center;padding-bottom:8px;border-bottom:1px solid #e5e7eb;margin-bottom:10px}
.close{font-size:1.6rem;cursor:pointer;color:#6b7280}
.input{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px}
.label{font-weight:600;margin:8px 0 6px 0}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div>
      <h1>แดชบอร์ดอาจารย์</h1>
        <!-- <div class="sub">เข้าสู่ระบบในชื่อ <span class="kv"><?=h($_SESSION['username'] ?? $teacherId)?></span> (teacher_id: <span class="kv"><?=h($teacherId)?></span>)</div> -->
    </div>
    <div class="toolbar">
      <button class="button" onclick="openModal('pwModal')">เปลี่ยนรหัสผ่าน</button>
      <a class="link" href="logout.php">ออกจากระบบ</a>
    </div>
  </div>

  <?php if ($pw_success): ?>
    <div class="card" style="border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.1);margin-bottom:12px">
      ✔ <?=h($pw_success)?>
    </div>
  <?php elseif ($pw_error): ?>
    <div class="card" style="border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.1);margin-bottom:12px">
      ✖ <?=h($pw_error)?>
    </div>
  <?php endif; ?>

  <div class="grid">
    <div class="card">
      <h3 style="margin-top:4px">กลุ่มเรียนของฉัน</h3>
      <?php if (!$groups): ?>
        <div class="empty">ยังไม่มีกลุ่มเรียนที่ผูกกับอาจารย์คนนี้</div>
      <?php else: ?>
        <ul>
          <?php foreach($groups as $g): ?>
            <li class="group-item">
              <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
                <div>
                  <div><strong><?=h($g['group_name'])?></strong></div>
                  <div class="sub">วิชา: <?=h($g['course_name'])?> · group_id: <span class="kv"><?=h($g['group_id'])?></span></div>
                </div>
                <div>
                  <a class="button" href="?group_id=<?=urlencode($g['group_id'])?>">ดูรายชื่อ</a>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <div class="help" style="margin-top:10px">แหล่งข้อมูล: ตาราง <span class="kv">course_groups</span> และ <span class="kv">courses</span></div>
    </div>

    <div class="card">
      <h3 style="margin-top:4px">นักศึกษาในกลุ่ม & สถานะแบบทดสอบ</h3>
      <?php if (!$selectedGroupId): ?>
        <div class="empty">เลือกกลุ่มจากด้านซ้ายเพื่อแสดงรายชื่อและคะแนน</div>
      <?php else: ?>
        <div class="sub" style="margin-bottom:10px">
          group_id: <span class="kv"><?=h($selectedGroupId)?></span>
          <?php if(!$memberTable): ?>
            · <span style="color:#fca5a5">ไม่พบตารางสมาชิกกลุ่ม (ลองสร้าง <span class="kv">course_group_students</span> หรือ <span class="kv">group_members</span>)</span>
          <?php else: ?>
            · ใช้ตารางสมาชิก: <span class="kv"><?=$memberTable?></span>
          <?php endif; ?>
        </div>

        <?php if (!$memberTable): ?>
          <div class="empty">ไม่สามารถดึงรายชื่อนักศึกษาได้เพราะไม่พบตารางสมาชิกกลุ่ม</div>
        <?php else: ?>
          <?php if (!$students): ?>
            <div class="empty">ยังไม่มีนักศึกษาในกลุ่มนี้</div>
          <?php else: ?>
            <table class="table">
              <thead>
                <tr>
                  <th style="width:20%">รหัส นศ.</th>
                  <th style="width:35%">ชื่อ-นามสกุล</th>
                  <th style="width:45%">คะแนนล่าสุด / สถานะแบบทดสอบ</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach($students as $st): ?>
                <tr>
                  <td><span class="kv"><?=h($st['student_id'])?></span></td>
                  <td><?=h($st['student_name'] ?? $st['student_id'])?><br><span class="sub"><?=h($st['email'] ?? '')?></span></td>
                  <td>
                    <?php if (!empty($quizStats[$st['student_id']])): ?>
                      <?php foreach($quizStats[$st['student_id']] as $q): ?>
                        <div style="margin-bottom:6px">
                          <span class="badge"><?=h($q['quiz_title'])?></span>
                          <span style="margin-left:8px">คะแนน: <strong><?=h($q['score'])?></strong></span>
                          <span class="sub" style="margin-left:10px"><?=h($q['status'])?></span>
                          <span class="sub" style="margin-left:10px"><?=h($q['time'])?></span>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <span class="sub">ยังไม่มีข้อมูลการทำแบบทดสอบล่าสุด</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <div class="help" style="margin-top:8px">
              แหล่งข้อมูลแบบทดสอบ: <span class="kv">quizzes</span>, <span class="kv">quiz_attempts</span> (ถ้ามี)
            </div>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal: เปลี่ยนรหัสผ่าน -->
<div id="pwModal" class="modal" aria-hidden="true">
  <div class="modal-content">
    <div class="modal-header">
      <h3>เปลี่ยนรหัสผ่าน</h3>
      <span class="close" onclick="closeModal('pwModal')">&times;</span>
    </div>
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="change_password">
      <input type="hidden" name="csrf_token" value="<?=h($_SESSION['csrf_token'])?>">
      
      <label class="label">รหัสผ่านปัจจุบัน</label>
      <input class="input" type="password" name="current_password" required>
      
      <label class="label" style="margin-top:10px">รหัสผ่านใหม่</label>
      <input class="input" type="password" name="new_password" minlength="8" required>
      
      <label class="label" style="margin-top:10px">ยืนยันรหัสผ่านใหม่</label>
      <input class="input" type="password" name="confirm_password" minlength="8" required>

      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px">
        <button type="button" class="button" style="background:linear-gradient(135deg,#6b7280,#4b5563)" onclick="resetPwForm()">รีเซ็ตฟอร์ม</button>
        <button type="button" class="button" style="background:linear-gradient(135deg,#6b7280,#4b5563)" onclick="closeModal('pwModal')">ยกเลิก</button>
        <button class="button" type="submit">บันทึก</button>
      </div>
    </form>
    <div class="help" style="margin-top:10px">เงื่อนไขรหัสผ่าน: อย่างน้อย 8 ตัวอักษร</div>
  </div>
</div>

<script>
// modal
function openModal(id){ const el=document.getElementById(id); if(!el) return; el.style.display='block'; el.setAttribute('aria-hidden','false'); }
function closeModal(id){ const el=document.getElementById(id); if(!el) return; el.style.display='none'; el.setAttribute('aria-hidden','true'); }
window.addEventListener('click', function(ev){ const m=document.getElementById('pwModal'); if(m && ev.target===m) closeModal('pwModal'); });

// reset ฟอร์มรหัสผ่าน
function resetPwForm(){
  const f = document.querySelector('#pwModal form');
  if(!f) return;
  f.current_password.value = '';
  f.new_password.value = '';
  f.confirm_password.value = '';
  f.current_password.focus();
}
</script>
</body>
</html>
