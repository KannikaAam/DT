<?php
session_start();
include 'db_connect.php'; // เชื่อมต่อฐานข้อมูล (mysqli -> $conn)

if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];
$full_name = 'ไม่พบชื่อ';
$profile_picture_src = '';
$gender = '';

/* ----------------- helper: safe html ----------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ----------------- โหลดชื่อ/รูป/เพศ ----------------- */
$sql_student_info = "SELECT p.full_name, p.profile_picture, p.gender
                     FROM personal_info p
                     INNER JOIN education_info e ON p.id = e.personal_id
                     WHERE e.student_id = ?";
$stmt_student_info = $conn->prepare($sql_student_info);
if ($stmt_student_info) {
    $stmt_student_info->bind_param("s", $student_id);
    $stmt_student_info->execute();
    $res = $stmt_student_info->get_result();
    if ($res && $res->num_rows === 1) {
        $row = $res->fetch_assoc();
        $full_name = h($row['full_name'] ?? 'ไม่ระบุชื่อ');
        $genderRaw = $row['gender'] ?? '';
        $gender = mb_strtolower($genderRaw, 'UTF-8');

        if (!empty($row['profile_picture']) && file_exists('uploads/profile_images/' . $row['profile_picture'])) {
            $profile_picture_src = 'uploads/profile_images/' . h($row['profile_picture']);
        } else {
            // UI-Avatars fallback
            $avatar_bg =
                ($gender === 'ชาย' || $gender === 'male') ? '3498db' :
                (($gender === 'หญิง' || $gender === 'female') ? 'e91e63' : '9b59b6');
            $profile_picture_src = 'https://ui-avatars.com/api/?name=' . urlencode($full_name ?: 'Student')
                . '&background=' . $avatar_bg . '&color=ffffff&size=150&font-size=0.6&rounded=true';
        }
    }
    $stmt_student_info->close();
}

/* ----------------- helper: schema check ----------------- */
function db_name(mysqli $conn): string {
    $db = 'studentregistration';
    if ($r = $conn->query("SELECT DATABASE() AS dbname")) {
        if ($rw = $r->fetch_assoc()) { $db = $rw['dbname'] ?: $db; }
    }
    return $db;
}
function has_table(mysqli $conn, string $db, string $table): bool {
    $sql = "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?";
    $st = $conn->prepare($sql);
    $st->bind_param('ss', $db, $table);
    $st->execute();
    $c = 0;
    if ($g = $st->get_result()) { $c = (int)$g->fetch_assoc()['c']; }
    $st->close();
    return $c > 0;
}
function has_col(mysqli $conn, string $db, string $table, string $col): bool {
    $sql = "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?";
    $st = $conn->prepare($sql);
    $st->bind_param('sss', $db, $table, $col);
    $st->execute();
    $c = 0;
    if ($g = $st->get_result()) { $c = (int)$g->fetch_assoc()['c']; }
    $st->close();
    return $c > 0;
}

$db = db_name($conn);

/* ----------------- คิวรีประวัติแบบ auto-detect ----------------- */
$history_result = null;
$history_error = '';

if (has_table($conn, $db, 'test_history')) {
    // เดาว่าคอลัมน์ "รหัสนักศึกษา" ชื่ออะไร
    $idCandidates = ['student_id', 'username', 'user_id', 'sid', 'stu_id', 'student_code', 'std_id', 'std_code'];
    $idCol = null;
    foreach ($idCandidates as $c) {
        if (has_col($conn, $db, 'test_history', $c)) { $idCol = $c; break; }
    }

    // เดาว่าคอลัมน์วันที่ชื่ออะไร
    $timeCandidates = ['timestamp', 'created_at', 'taken_at', 'updated_at', 'createdAt'];
    $timeCol = null;
    foreach ($timeCandidates as $c) {
        if (has_col($conn, $db, 'test_history', $c)) { $timeCol = $c; break; }
    }
    if (!$timeCol) $timeCol = 'timestamp'; // เผื่อไว้ ใช้ใน ORDER BY ถ้าไม่มีจริงจะ error → ด้านล่างเราป้องกันอีกชั้น

    // คอลัมน์คอนเทนต์
    $cols = [];
    // เวลา
    if (has_col($conn, $db, 'test_history', $timeCol)) $cols[] = "`$timeCol` AS dt";
    // กลุ่มที่แนะนำ
    if (has_col($conn, $db, 'test_history', 'recommended_group')) $cols[] = "recommended_group";
    // วิชาที่แนะนำ
    if (has_col($conn, $db, 'test_history', 'recommended_subjects')) $cols[] = "recommended_subjects";

    if ($idCol && !empty($cols)) {
        $sel = implode(', ', $cols);
        $order = has_col($conn, $db, 'test_history', $timeCol) ? "ORDER BY `$timeCol` DESC" : "";

        $history_sql = "SELECT $sel FROM test_history WHERE `$idCol` = ? $order";
        $stmt_history = $conn->prepare($history_sql);
        if ($stmt_history) {
            $stmt_history->bind_param("s", $student_id);
            $stmt_history->execute();
            $history_result = $stmt_history->get_result();
            $stmt_history->close();
        } else {
            $history_error = "ไม่สามารถเตรียมคำสั่งอ่านประวัติได้: " . h($conn->error);
        }
    } else {
        $history_error = "พบตาราง test_history แต่ไม่รู้ชื่อคอลัมน์รหัสนักศึกษาหรือคอลัมน์ข้อมูล (ลองตรวจ schema ของ test_history)";
    }
} else {
    $history_error = "ไม่พบตาราง test_history — หน้านี้จึงยังแสดงประวัติไม่ได้";
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ประวัติการใช้งาน</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root{--primary:#3498db;--secondary:#2980b9;--text:#333;--shadow:0 4px 6px rgba(0,0,0,.1);--radius:8px;}
    *{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt',sans-serif}
    body{background:#f5f7fa;color:var(--text);line-height:1.6}
    .navbar{background:var(--primary);padding:15px 30px;color:#fff;display:flex;justify-content:space-between;align-items:center;box-shadow:var(--shadow)}
    .navbar-brand{font-size:20px;font-weight:bold}
    .navbar-user{display:flex;align-items:center}
    .user-info{margin-right:20px;text-align:right}
    .user-name{font-weight:bold;font-size:14px}
    .user-id{font-size:12px;opacity:.9}
    .logout-btn{background:rgba(255,255,255,.2);color:#fff;border:none;padding:8px 15px;border-radius:8px;cursor:pointer;font-size:14px;text-decoration:none}
    .logout-btn:hover{background:rgba(255,255,255,.3)}
    .container{max-width:1200px;margin:30px auto;padding:0 20px}
    .dashboard-header{margin-bottom:30px}
    .dashboard-header h1{font-size:28px;color:var(--secondary);margin-bottom:10px}
    .card{background:#fff;border-radius:8px;box-shadow:var(--shadow);padding:20px}
    .card-header{border-bottom:1px solid #eee;padding-bottom:12px;margin-bottom:12px}
    .card-title{font-size:18px;color:var(--secondary)}
    .action-buttons{display:flex;gap:15px;margin:20px 0 30px;flex-wrap:wrap}
    .btn{padding:12px 20px;text-decoration:none;font-size:14px;font-weight:500;border:1px solid #e5e7eb;border-radius:8px;transition:.2s;display:inline-flex;align-items:center;gap:8px;color:#374151;background:#fff}
    .btn:hover{background:#f8fafc}
    .btn-primary{border-left:3px solid #3b82f6}
    .btn-success{border-left:3px solid #10b981}
    .btn-warning{border-left:3px solid #f59e0b}
    .btn-info{border-left:3px solid #8b5cf6}
    .table{width:100%;border-collapse:collapse;margin-top:12px;font-size:14px}
    .table th,.table td{border:1px solid #e5e7eb;padding:12px;text-align:left;vertical-align:top}
    .table th{background:#f2f2f2;font-weight:600;color:#555}
    .table tbody tr:nth-child(even){background:#f9f9f9}
    .table tbody tr:hover{background:#eaf6ff}
    .alert{padding:12px 14px;border-left:4px solid #f59e0b;background:#fff7ed;color:#92400e;border-radius:8px;margin-bottom:12px}
    @media (max-width:768px){
      .navbar{flex-direction:column;text-align:center}
      .navbar-user{margin-top:10px;flex-direction:column}
      .user-info{margin:0 0 10px 0;text-align:center}
      .table,.table thead,.table tbody,.table th,.table td,.table tr{display:block}
      .table thead tr{position:absolute;top:-9999px;left:-9999px}
      .table tr{border:1px solid #e0e0e0;margin-bottom:10px}
      .table td{border:none;border-bottom:1px solid #eee;position:relative;padding-left:50%;text-align:right}
      .table td:before{position:absolute;top:0;left:6px;width:45%;padding-right:10px;white-space:nowrap;text-align:left;font-weight:700;color:#555}
      .table td:nth-of-type(1):before{content:"วันที่";}
      .table td:nth-of-type(2):before{content:"กลุ่มที่แนะนำ";}
      .table td:nth-of-type(3):before{content:"รายวิชาที่แนะนำ";}
    }
  </style>
</head>
<body>
<div class="navbar">
  <div class="navbar-brand">ระบบทะเบียนนักศึกษา</div>
  <div class="navbar-user">
    <div class="user-info">
      <div class="user-name"><?php echo $full_name; ?></div>
      <div class="user-id">รหัสนักศึกษา: <?php echo h($student_id); ?></div>
    </div>
    <a href="index.php" class="logout-btn">ออกจากระบบ</a>
  </div>
</div>

<div class="container">
  <div class="dashboard-header">
    <h1>ประวัติการใช้งาน</h1>
    <p>หน้าสำหรับแสดงประวัติการทำแบบทดสอบและกิจกรรมอื่นๆ</p>
  </div>

  <div class="action-buttons">
    <a href="student_dashboard.php" class="btn btn-primary">🏠 กลับหน้าหลัก</a>
    <a href="edit_profile.php" class="btn btn-success">✏️ แก้ไขข้อมูลส่วนตัว</a>
    <a href="history.php" class="btn btn-warning">📋 ประวัติการใช้งาน</a>
    <a href="quiz.php" class="btn btn-info">📝 ทำแบบทดสอบ</a>
  </div>

  <div class="card">
    <div class="card-header">
      <h2 class="card-title">ประวัติการทำแบบทดสอบ</h2>
    </div>
    <div class="card-body">
      <?php if ($history_error): ?>
        <div class="alert">ℹ️ <?php echo $history_error; ?></div>
      <?php endif; ?>

      <?php if ($history_result && $history_result->num_rows > 0): ?>
        <table class="table">
          <thead>
            <tr>
              <th>วันที่/เวลา</th>
              <th>กลุ่มที่แนะนำ</th>
              <th>รายวิชาที่แนะนำ</th>
            </tr>
          </thead>
          <tbody>
            <?php while($r = $history_result->fetch_assoc()): ?>
              <tr>
                <td><?php echo h($r['dt'] ?? ($r['timestamp'] ?? '')); ?></td>
                <td><?php echo h($r['recommended_group'] ?? ''); ?></td>
                <td><?php echo nl2br(h($r['recommended_subjects'] ?? '')); ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      <?php elseif (!$history_error): ?>
        <p style="text-align:center;color:#7f8c8d;font-style:italic;padding:18px">
          ยังไม่มีประวัติการทำแบบทดสอบในขณะนี้ ข้อมูลจะปรากฏขึ้นเมื่อคุณทำแบบทดสอบเสร็จสิ้น
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
