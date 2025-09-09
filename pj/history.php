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
if ($stmt_student_info = $conn->prepare($sql_student_info)) {
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
            $avatar_bg =
                ($gender === 'ชาย' || $gender === 'male') ? '3498db' :
                (($gender === 'หญิง' || $gender === 'female') ? 'e91e63' : '9b59b6');
            $profile_picture_src = 'https://ui-avatars.com/api/?name=' . urlencode($full_name ?: 'Student')
                . '&background=' . $avatar_bg . '&color=ffffff&size=150&font-size=0.6&rounded=true';
        }
    }
    $stmt_student_info->close();
}

/* ----------------- DB helpers ----------------- */
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
    $g = $st->get_result(); $st->close();
    return $g && ((int)$g->fetch_assoc()['c'] > 0);
}
function has_col(mysqli $conn, string $db, string $table, string $col): bool {
    $sql = "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?";
    $st = $conn->prepare($sql);
    $st->bind_param('sss', $db, $table, $col);
    $st->execute();
    $g = $st->get_result(); $st->close();
    return $g && ((int)$g->fetch_assoc()['c'] > 0);
}

/* ----------------- ทำให้ตาราง test_history ใช้งานได้แน่นอน ----------------- */
$db = db_name($conn);

/** สร้างตารางถ้ายังไม่มี (สคีมาตามที่ quiz.php ใช้บันทึก) */
if (!$conn->query("
    CREATE TABLE IF NOT EXISTS test_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(255) NOT NULL,
        recommended_group VARCHAR(255) DEFAULT NULL,
        recommended_subjects TEXT DEFAULT NULL,
        no_count INT DEFAULT 0,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user_time (username, timestamp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
")) {
    // ถ้าสร้างไม่ได้ ให้โชว์ข้อความเตือน แต่อย่าให้หน้าเด้ง
    $create_error = "สร้างตาราง test_history ไม่สำเร็จ: ".h($conn->error);
} else {
    $create_error = '';
}

/** เติมคอลัมน์ที่ขาด (รองรับ MySQL 8+: ADD COLUMN IF NOT EXISTS; ถ้าเวอร์ชันต่ำกว่า ให้ข้ามเงียบ ๆ) */
@$conn->query("ALTER TABLE test_history ADD COLUMN IF NOT EXISTS username VARCHAR(255) NOT NULL");
@$conn->query("ALTER TABLE test_history ADD COLUMN IF NOT EXISTS recommended_group VARCHAR(255) NULL");
@$conn->query("ALTER TABLE test_history ADD COLUMN IF NOT EXISTS recommended_subjects TEXT NULL");
@$conn->query("ALTER TABLE test_history ADD COLUMN IF NOT EXISTS no_count INT DEFAULT 0");
@$conn->query("ALTER TABLE test_history ADD COLUMN IF NOT EXISTS timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
@$conn->query("ALTER TABLE test_history ADD INDEX IF NOT EXISTS idx_user_time (username, timestamp)");

/* ถ้าเวอร์ชัน MySQL ไม่รองรับ IF NOT EXISTS ให้เช็คซ้ำแบบละเอียด แล้วเติมแบบมีเงื่อนไข */
$needFix = [];
foreach (['username','recommended_group','recommended_subjects','no_count','timestamp'] as $col) {
    if (!has_col($conn, $db, 'test_history', $col)) $needFix[] = $col;
}
if (!empty($needFix)) {
    // เติมทีละคอลัมน์ด้วย ALTER (สำหรับ MySQL 5.7)
    foreach ($needFix as $col) {
        if ($col === 'username') {
            @$conn->query("ALTER TABLE test_history ADD COLUMN username VARCHAR(255) NOT NULL");
        } elseif ($col === 'recommended_group') {
            @$conn->query("ALTER TABLE test_history ADD COLUMN recommended_group VARCHAR(255) NULL");
        } elseif ($col === 'recommended_subjects') {
            @$conn->query("ALTER TABLE test_history ADD COLUMN recommended_subjects TEXT NULL");
        } elseif ($col === 'no_count') {
            @$conn->query("ALTER TABLE test_history ADD COLUMN no_count INT DEFAULT 0");
        } elseif ($col === 'timestamp') {
            @$conn->query("ALTER TABLE test_history ADD COLUMN `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        }
    }
    // ดัชนี
    if ($conn->query("SHOW INDEX FROM test_history WHERE Key_name='idx_user_time'")->num_rows === 0) {
        @$conn->query("ALTER TABLE test_history ADD INDEX idx_user_time (username, `timestamp`)");
    }
}

/* ----------------- คิวรีประวัติแบบแข็งแรง ----------------- */
/** โค้ด quiz.php ใช้คอลัมน์ username ตอนบันทึก ดังนั้นเราจะอ่านจากคอลัมน์นี้ก่อน
 *  ถ้าไม่มีจริง ๆ (ระบบเก่าบันทึกเป็น student_id) จะ fallback ไป student_id
 */
$history_result = null;
$history_error = '';

if (has_table($conn, $db, 'test_history')) {
    // หา id column ที่ใช้งานได้จริง ๆ
    $idCol = null;
    if (has_col($conn, $db, 'test_history', 'username')) $idCol = 'username';
    elseif (has_col($conn, $db, 'test_history', 'student_id')) $idCol = 'student_id';
    else {
        // ถ้าไม่มีสักอัน ให้สร้าง username แล้วคัดลอกจาก student_id ถ้ามี (ทำครั้งเดียว)
        if (!has_col($conn, $db, 'test_history', 'username')) {
            @$conn->query("ALTER TABLE test_history ADD COLUMN username VARCHAR(255) NULL");
        }
        if (has_col($conn, $db, 'test_history', 'student_id')) {
            @$conn->query("UPDATE test_history SET username = COALESCE(username, student_id)");
        }
        @$conn->query("ALTER TABLE test_history MODIFY COLUMN username VARCHAR(255) NOT NULL");
        $idCol = 'username';
    }

    // หา time column
    $timeCandidates = ['timestamp', 'created_at', 'taken_at', 'updated_at', 'createdAt'];
    $timeCol = null;
    foreach ($timeCandidates as $c) {
        if (has_col($conn, $db, 'test_history', $c)) { $timeCol = $c; break; }
    }
    // ถ้าไม่มีจริง ๆ ให้สร้าง timestamp
    if (!$timeCol) {
        @$conn->query("ALTER TABLE test_history ADD COLUMN `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        $timeCol = 'timestamp';
    }

    // columns to select
    $cols = [];
    $cols[] = "`$timeCol` AS dt";
    if (has_col($conn, $db, 'test_history', 'recommended_group')) $cols[] = "recommended_group";
    if (has_col($conn, $db, 'test_history', 'recommended_subjects')) $cols[] = "recommended_subjects";

    $sel = implode(', ', $cols);
    $history_sql = "SELECT $sel FROM test_history WHERE `$idCol` = ? ORDER BY `$timeCol` DESC";

    if ($stmt_history = $conn->prepare($history_sql)) {
        $stmt_history->bind_param("s", $student_id);
        $stmt_history->execute();
        $history_result = $stmt_history->get_result();
        $stmt_history->close();
    } else {
        $history_error = "ไม่สามารถเตรียมคำสั่งอ่านประวัติได้: " . h($conn->error);
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
      <?php if (!empty($create_error)): ?>
        <div class="alert">⚠️ <?php echo $create_error; ?></div>
      <?php endif; ?>

      <?php
      // ข้อความ error จากขั้นอ่านข้อมูล
      if (!empty($history_error)) {
          echo '<div class="alert">ℹ️ '. $history_error .'</div>';
      }
      ?>

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
      <?php elseif (empty($history_error)): ?>
        <p style="text-align:center;color:#7f8c8d;font-style:italic;padding:18px">
          ยังไม่มีประวัติการทำแบบทดสอบในขณะนี้ ข้อมูลจะปรากฏขึ้นเมื่อคุณทำแบบทดสอบเสร็จสิ้น
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
