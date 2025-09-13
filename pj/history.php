<?php
session_start();
include 'db_connect.php';

// ตรวจสอบการเข้าสู่ระบบ
if (!isset($_SESSION['student_id'])) { header("Location: login.php"); exit(); }

$student_id = $_SESSION['student_id'];
$full_name = 'ไม่พบชื่อ';
$profile_picture_src = '';
$gender = '';

// escape
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// โหลดข้อมูลนักศึกษา
$sql_student_info = "SELECT p.full_name, p.profile_picture, p.gender
                     FROM personal_info p
                     INNER JOIN education_info e ON p.id = e.personal_id
                     WHERE e.student_id = ?";
if ($stmt = $conn->prepare($sql_student_info)) {
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
        $row = $res->fetch_assoc();
        $full_name = h($row['full_name'] ?? 'ไม่ระบุชื่อ');
        $genderRaw = $row['gender'] ?? '';
        $gender = mb_strtolower($genderRaw, 'UTF-8');
        if (!empty($row['profile_picture']) && file_exists('uploads/profile_images/'.$row['profile_picture'])) {
            $profile_picture_src = 'uploads/profile_images/'.h($row['profile_picture']);
        } else {
            $bg = ($gender==='ชาย'||$gender==='male')?'3498db':(($gender==='หญิง'||$gender==='female')?'e91e63':'9b59b6');
            $profile_picture_src = 'https://ui-avatars.com/api/?name='.urlencode($full_name?:'Student')
                                 .'&background='.$bg.'&color=ffffff&size=150&font-size=0.6&rounded=true';
        }
    }
    $stmt->close();
}

// ===== DB helpers =====
function db_name(mysqli $conn): string {
    $db='studentregistration';
    if ($r=$conn->query("SELECT DATABASE() AS dbname")) { $rw=$r->fetch_assoc(); $db=$rw['dbname']?:$db; }
    return $db;
}
function has_table(mysqli $conn,string $db,string $t):bool{
    $st=$conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $st->bind_param('ss',$db,$t); $st->execute(); $g=$st->get_result(); $st->close();
    return $g && (int)$g->fetch_assoc()['c']>0;
}
function has_col(mysqli $conn,string $db,string $t,string $c):bool{
    $st=$conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->bind_param('sss',$db,$t,$c); $st->execute(); $g=$st->get_result(); $st->close();
    return $g && (int)$g->fetch_assoc()['c']>0;
}

// ===== parse subjects =====
function parse_subjects(?string $text): array{
    if (!$text) return [];
    $norm = preg_replace('/[;\|\t\r\n]+/u', ',', $text);
    $parts = array_filter(array_map('trim', explode(',', $norm)), fn($x)=>$x!=='');
    $seen=[]; $out=[];
    foreach ($parts as $p){ $k=mb_strtolower($p,'UTF-8'); if(!isset($seen[$k])){$seen[$k]=1;$out[]=$p;} }
    return $out;
}

// ===== find course =====
function find_course_by_token(mysqli $conn,string $token):?array{
    if ($st=$conn->prepare("SELECT * FROM courses WHERE LOWER(course_code)=LOWER(?) LIMIT 1")){
        $st->bind_param('s',$token); $st->execute(); $rs=$st->get_result();
        if($rs && $rs->num_rows>0){ $row=$rs->fetch_assoc(); $st->close(); return $row; } $st->close();
    }
    if ($st=$conn->prepare("SELECT * FROM courses WHERE course_name=? LIMIT 1")){
        $st->bind_param('s',$token); $st->execute(); $rs=$st->get_result();
        if($rs && $rs->num_rows>0){ $row=$rs->fetch_assoc(); $st->close(); return $row; } $st->close();
    }
    $like='%'.$token.'%';
    if ($st=$conn->prepare("SELECT * FROM courses WHERE course_name LIKE ? ORDER BY course_code LIMIT 1")){
        $st->bind_param('s',$like); $st->execute(); $rs=$st->get_result();
        if($rs && $rs->num_rows>0){ $row=$rs->fetch_assoc(); $st->close(); return $row; } $st->close();
    }
    return null;
}

// ===== ensure test_history =====
$db = db_name($conn);
$create_error = '';
$conn->query("CREATE TABLE IF NOT EXISTS test_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL,
    recommended_group VARCHAR(255) DEFAULT NULL,
    recommended_subjects TEXT DEFAULT NULL,
    no_count INT DEFAULT 0,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_time (username, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;") or $create_error = "สร้างตาราง test_history ไม่สำเร็จ: ".h($conn->error);

// columns (เผื่อเวอร์ชันที่ไม่มี IF NOT EXISTS)
$cols = ['username'=>'VARCHAR(255) NOT NULL','recommended_group'=>'VARCHAR(255) NULL','recommended_subjects'=>'TEXT NULL','no_count'=>'INT DEFAULT 0','timestamp'=>'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'];
foreach($cols as $c=>$def){ @$conn->query("ALTER TABLE test_history ADD COLUMN IF NOT EXISTS $c $def"); }
@$conn->query("ALTER TABLE test_history ADD INDEX IF NOT EXISTS idx_user_time (username, timestamp)");
$needFix=[];
foreach(array_keys($cols) as $c){ if(!has_col($conn,$db,'test_history',$c)) $needFix[]=$c; }
if($needFix){
    foreach($needFix as $c){ @$conn->query("ALTER TABLE test_history ADD COLUMN $c ".$cols[$c]); }
    if($conn->query("SHOW INDEX FROM test_history WHERE Key_name='idx_user_time'")->num_rows===0){
        @$conn->query("ALTER TABLE test_history ADD INDEX idx_user_time (username, `timestamp`)");
    }
}

// ===== read history =====
$history_result=null; $history_error='';
if (has_table($conn,$db,'test_history')){
    $idCol = has_col($conn,$db,'test_history','username') ? 'username'
           : (has_col($conn,$db,'test_history','student_id') ? 'student_id' : null);
    if(!$idCol){
        @$conn->query("ALTER TABLE test_history ADD COLUMN username VARCHAR(255) NULL");
        if (has_col($conn,$db,'test_history','student_id')) { @$conn->query("UPDATE test_history SET username = COALESCE(username, student_id)"); }
        @$conn->query("ALTER TABLE test_history MODIFY COLUMN username VARCHAR(255) NOT NULL");
        $idCol='username';
    }
    $timeCol=null; foreach(['timestamp','created_at','taken_at','updated_at','createdAt'] as $c){ if(has_col($conn,$db,'test_history',$c)){$timeCol=$c;break;} }
    if(!$timeCol){ @$conn->query("ALTER TABLE test_history ADD COLUMN `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); $timeCol='timestamp'; }

    $sel = "`$timeCol` AS dt";
    if (has_col($conn,$db,'test_history','recommended_group'))    $sel .= ", recommended_group";
    if (has_col($conn,$db,'test_history','recommended_subjects')) $sel .= ", recommended_subjects";
    $sql = "SELECT $sel FROM test_history WHERE `$idCol`=? ORDER BY `$timeCol` DESC";
    if ($st=$conn->prepare($sql)){ $st->bind_param("s",$student_id); $st->execute(); $history_result=$st->get_result(); $st->close(); }
    else { $history_error = "ไม่สามารถเตรียมคำสั่งอ่านประวัติได้: ".h($conn->error); }
} else {
    $history_error = "ไม่พบตาราง test_history — หน้านี้จึงยังแสดงประวัติไม่ได้";
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ประวัติการใช้งาน</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --ink:#111827; --sub:#6b7280; --bg:#f8fafc; --card:#ffffff; --border:#e5e7eb;
  --primary:#1f6feb; --muted:#94a3b8; --radius:12px;
}
*{box-sizing:border-box}
body{margin:0; background:var(--bg); color:var(--ink); font-family:'Prompt',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
a{color:inherit; text-decoration:none}

/* navbar */
.navbar{background:var(--primary); color:#fff; padding:14px 18px; display:flex; justify-content:space-between; align-items:center}
.brand{font-weight:700}
.user{display:flex; align-items:center; gap:12px}
.user small{opacity:.9}
.btn-link{background:rgba(255,255,255,.16); color:#fff; padding:6px 10px; border-radius:8px}
.btn-link:hover{background:rgba(255,255,255,.25)}

/* container */
.container{max-width:1100px; margin:22px auto; padding:0 16px}
h1{margin:0 0 4px; font-size:22px}
.sub{color:var(--sub); margin:0 0 16px}

/* actions */
.actions{display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px}
.btn{background:#fff; border:1px solid var(--border); padding:8px 12px; border-radius:9px; font-weight:500}
.btn:hover{background:#f3f4f6}

/* card */
.card{background:var(--card); border:1px solid var(--border); border-radius:var(--radius)}
.card-h{padding:12px 14px; border-bottom:1px solid var(--border); font-weight:700}
.card-b{padding:10px 14px}

/* alert */
.alert{padding:10px 12px; border:1px solid #fde68a; background:#fef9c3; border-radius:8px; color:#92400e; margin-bottom:10px}

/* table */
.table{width:100%; border-collapse:collapse; font-size:14px}
.table th,.table td{border:1px solid var(--border); padding:10px; vertical-align:top; text-align:left}
.table th{background:#f3f4f6; font-weight:600}
.table tbody tr:nth-child(even){background:#fafafa}

/* badge */
.badge{display:inline-block; padding:2px 8px; font-size:12px; border-radius:999px; background:#e0f2fe; color:#075985; border:1px solid #bae6fd}

/* subject list */
.sublist{margin:6px 0 0 16px; padding-left:0}
.sublist li{margin:2px 0; color:#111827}

/* detail table (compact) */
.subtable{width:100%; border-collapse:collapse; margin-top:8px; font-size:13px}
.subtable th,.subtable td{border:1px solid var(--border); padding:8px}
.subtable th{background:#f9fafb}
.muted{color:var(--muted); font-size:13px}

/* empty */
.empty{padding:28px 6px; text-align:center; color:var(--sub); font-style:italic}

/* responsive */
@media (max-width:768px){
  .table, .table thead, .table tbody, .table th, .table td, .table tr{display:block}
  .table thead{display:none}
  .table tr{border:1px solid var(--border); margin-bottom:10px}
  .table td{border:none; border-bottom:1px solid var(--border); position:relative; padding-left:44%}
  .table td:before{position:absolute; left:8px; top:8px; width:36%; font-weight:600; color:#374151}
  .td-dt:before{content:"วันที่/เวลา";}
  .td-grp:before{content:"กลุ่มที่แนะนำ";}
  .td-sub:before{content:"รายวิชาที่แนะนำ";}
}
</style>
</head>
<body>
  <div class="navbar">
    <div class="brand">ระบบทะเบียนนักศึกษา</div>
    <div class="user">
      <div>
        <div style="font-weight:600"><?php echo $full_name; ?></div>
        <small>รหัสนักศึกษา: <?php echo h($student_id); ?></small>
      </div>
      <a class="btn-link" href="index.php">ออกจากระบบ</a>
    </div>
  </div>

  <div class="container">
    <h1>ประวัติการใช้งาน</h1>
    <p class="sub">ผลแบบทดสอบและรายวิชาที่แนะนำ (รูปแบบอ่านง่าย)</p>

    <div class="actions">
      <a class="btn" href="student_dashboard.php">🏠 กลับหน้าหลัก</a>
      <a class="btn" href="edit_profile.php">✏️ แก้ไขข้อมูลส่วนตัว</a>
      <a class="btn" href="history.php">📋 ประวัติการใช้งาน</a>
      <a class="btn" href="quiz.php">📝 ทำแบบทดสอบ</a>
    </div>

    <div class="card">
      <div class="card-h">ผลแบบทดสอบของคุณ</div>
      <div class="card-b">

        <?php if (!empty($create_error)): ?>
          <div class="alert">⚠️ <?php echo $create_error; ?></div>
        <?php endif; ?>
        <?php if (!empty($history_error)): ?>
          <div class="alert">ℹ️ <?php echo $history_error; ?></div>
        <?php endif; ?>

        <?php if ($history_result && $history_result->num_rows > 0): ?>
          <table class="table">
            <thead>
              <tr>
                <th style="width:170px">วันที่/เวลา</th>
                <th style="width:220px">กลุ่มที่แนะนำ</th>
                <th>รายวิชาที่แนะนำ</th>
              </tr>
            </thead>
            <tbody>
            <?php while($r=$history_result->fetch_assoc()): ?>
              <?php
                $dt = h($r['dt'] ?? ($r['timestamp'] ?? ''));
                $grp = trim((string)($r['recommended_group'] ?? ''));
                $subText = (string)($r['recommended_subjects'] ?? '');
                $tokens = parse_subjects($subText);

                // ดึงรายละเอียดวิชาแบบเรียบง่าย (ไม่มีปุ่ม/ลูกเล่น)
                $details=[];
                foreach($tokens as $tok){
                  $course = find_course_by_token($conn, $tok);
                  if($course){ $details[]=$course; }
                  else{
                    $details[]=['course_code'=>$tok,'course_name'=>'(ไม่พบในฐานข้อมูล)','credits'=>null,'recommended_year'=>null,'prereq_text'=>null,'is_compulsory'=>0];
                  }
                }
              ?>
              <tr>
                <td class="td-dt"><?php echo $dt ?: '—'; ?></td>
                <td class="td-grp">
                  <?php if($grp!==''): ?>
                    <span class="badge"><?php echo h($grp); ?></span>
                  <?php else: ?>
                    <span class="muted">ไม่ระบุ</span>
                  <?php endif; ?>
                </td>
                <td class="td-sub">
                  <?php if (!empty($tokens)): ?>
                    <ul class="sublist">
                      <?php
                        $maxList = 8; // โชว์ชื่อวิชาสั้นๆ ไม่เกิน 8 บรรทัด
                        foreach ($tokens as $i=>$t):
                          if ($i >= $maxList) break;
                      ?>
                        <li><?php echo h($t); ?></li>
                      <?php endforeach; ?>
                      <?php if (count($tokens) > $maxList): ?>
                        <li class="muted">…และอีก <?php echo count($tokens)-$maxList; ?> วิชา</li>
                      <?php endif; ?>
                    </ul>

                    <!-- ตารางรายละเอียดแบบเรียบ -->
                    <table class="subtable">
                      <thead>
                        <tr>
                          <th style="width:120px">รหัสวิชา</th>
                          <th>ชื่อวิชา</th>
                          <th style="width:84px">หน่วยกิต</th>
                          <th style="width:120px">ปีที่แนะนำ</th>
                          <th>วิชาบังคับก่อน</th>
                          <th style="width:90px">สถานะ</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach($details as $c): ?>
                          <tr>
                            <td><?php echo h($c['course_code'] ?? '—'); ?></td>
                            <td><?php echo h($c['course_name'] ?? '—'); ?></td>
                            <td><?php echo isset($c['credits']) ? h((string)(float)$c['credits']) : '—'; ?></td>
                            <td><?php echo !empty($c['recommended_year']) ? h($c['recommended_year']) : '—'; ?></td>
                            <td><?php echo !empty($c['prereq_text']) ? nl2br(h($c['prereq_text'])) : '—'; ?></td>
                            <td><?php echo !empty($c['is_compulsory']) ? 'บังคับ' : 'เลือกได้'; ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        <?php elseif (empty($history_error)): ?>
          <div class="empty">ยังไม่มีประวัติการทำแบบทดสอบ</div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</body>
</html>
