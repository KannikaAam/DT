<?php
// manage_students.php — จัดการสถานะแบบทดสอบ + สถานะนักศึกษา โดยไม่พึ่ง education_info.student_status
session_start();
include 'db_connect.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

/* -----------------------------------------------------------
   Helper: escape
----------------------------------------------------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* -----------------------------------------------------------
   Auto-migrate: ตรวจ/เพิ่มคอลัมน์ใน student_quiz_status แบบปลอดภัย
   - quiz_attempts                INT DEFAULT 0
   - recommended_count            INT DEFAULT 0
   - admin_override_attempts      INT DEFAULT 0
   - academic_status              ENUM('active','graduated','leave','suspended') DEFAULT 'active'
----------------------------------------------------------- */
function ensureColumn(mysqli $conn, string $db, string $table, string $column, string $addDDL){
    $sql = "SELECT COUNT(*) AS c
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?";
    $st = $conn->prepare($sql);
    $st->bind_param('sss', $db, $table, $column);
    $st->execute();
    $res = $st->get_result();
    $exists = ($res && ($row = $res->fetch_assoc()) && (int)$row['c'] > 0);
    $st->close();

    if (!$exists) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN $addDDL");
    }
}

$database = $database ?? (defined('DB_DATABASE') ? DB_DATABASE : null); // รองรับทั้งรูปแบบจาก db_connect.php ของคุณ
if (!$database) {
    // fallback ดึงฐานปัจจุบัน
    $r = $conn->query("SELECT DATABASE() AS dbname");
    $database = ($r && ($rw = $r->fetch_assoc())) ? $rw['dbname'] : 'studentregistration';
}

ensureColumn($conn, $database, 'student_quiz_status', 'quiz_attempts',            'INT NOT NULL DEFAULT 0');
ensureColumn($conn, $database, 'student_quiz_status', 'recommended_count',        'INT NOT NULL DEFAULT 0');
ensureColumn($conn, $database, 'student_quiz_status', 'admin_override_attempts',  'INT NOT NULL DEFAULT 0');
ensureColumn($conn, $database, 'student_quiz_status', 'academic_status',          "ENUM('active','graduated','leave','suspended') NOT NULL DEFAULT 'active'");

/* -----------------------------------------------------------
   Submit handler
----------------------------------------------------------- */
$message = '';
$error   = '';

// อัปเดตสถานะการทำแบบทดสอบ + สถานะนักศึกษา
if (isset($_POST['update_status'])) {
    $student_id              = trim($_POST['student_id'] ?? '');
    $new_attempts            = (int)($_POST['quiz_attempts'] ?? 0);
    $new_recommended_count   = (int)($_POST['recommended_count'] ?? 0);
    $admin_override_attempts = (int)($_POST['admin_override_attempts'] ?? 0);

    // รับค่า academic_status พร้อม whitelist
    $academic_status = $_POST['academic_status'] ?? 'active';
    $allowed_status  = ['active','graduated','leave','suspended'];
    if (!in_array($academic_status, $allowed_status, true)) {
        $academic_status = 'active';
    }

    if ($student_id === '') {
        $error = "ไม่พบรหัสนักศึกษา";
    } else {
        // ตรวจว่ามี record ใน student_quiz_status แล้วหรือยัง
        $check_stmt = $conn->prepare("SELECT id FROM student_quiz_status WHERE student_id = ?");
        $check_stmt->bind_param("s", $student_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_stmt->close();

        if ($check_result && $check_result->num_rows > 0) {
            // อัปเดต
            $stmt = $conn->prepare("
                UPDATE student_quiz_status
                SET quiz_attempts = ?, recommended_count = ?, admin_override_attempts = ?, academic_status = ?
                WHERE student_id = ?
            ");
            $stmt->bind_param("iiiss",
                $new_attempts,
                $new_recommended_count,
                $admin_override_attempts,
                $academic_status,
                $student_id
            );
        } else {
            // เพิ่มใหม่
            $stmt = $conn->prepare("
                INSERT INTO student_quiz_status (student_id, quiz_attempts, recommended_count, admin_override_attempts, academic_status)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("siiis",
                $student_id,
                $new_attempts,
                $new_recommended_count,
                $admin_override_attempts,
                $academic_status
            );
        }

        if ($stmt->execute()) {
            $message = "อัปเดตสถานะนักศึกษา '".h($student_id)."' เรียบร้อยแล้ว";
        } else {
            $error = "เกิดข้อผิดพลาดในการอัปเดตสถานะ: " . h($stmt->error);
        }
        $stmt->close();
    }
}

/* -----------------------------------------------------------
   Query list: ดึงข้อมูลนักศึกษา + สถานะแบบทดสอบ
----------------------------------------------------------- */
$students_status_sql = "
    SELECT
        pi.full_name,
        ei.student_id,
        COALESCE(sqs.quiz_attempts, 0)              AS quiz_attempts,
        COALESCE(sqs.recommended_count, 0)          AS recommended_count,
        COALESCE(sqs.admin_override_attempts, 0)    AS admin_override_attempts,
        COALESCE(sqs.academic_status, 'active')     AS academic_status
    FROM personal_info pi
    INNER JOIN education_info ei ON pi.id = ei.personal_id
    LEFT JOIN student_quiz_status sqs ON ei.student_id = sqs.student_id
    ORDER BY ei.student_id ASC
";
$students_status_result = $conn->query($students_status_sql);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>จัดการสถานะนักศึกษา - Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Prompt', sans-serif; background-color: #f4f7f6; margin: 0; padding: 0; color: #333; }
    .navbar { background-color: #2c3e50; color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    .navbar-brand { font-size: 24px; font-weight: bold; }
    .navbar-user a { color: white; text-decoration: none; margin-left: 20px; padding: 8px 15px; border-radius: 5px; background-color: #e74c3c; transition: background-color 0.3s; }
    .navbar-user a:hover { background-color: #c0392b; }
    .container { max-width: 1200px; margin: 30px auto; padding: 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
    h1 { color: #34495e; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
    .alert { padding: 15px; margin-bottom: 20px; border-radius: 5px; }
    .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .alert-danger { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th, td { padding: 12px; border: 1px solid #ddd; text-align: left; vertical-align: middle; }
    th { background-color: #f2f2f2; }
    td input[type="number"], td select { width: 120px; padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; }
    .btn-update { background-color: #3498db; color: white; padding: 8px 15px; border: none; border-radius: 5px; cursor: pointer; transition: background-color 0.3s; }
    .btn-update:hover { background-color: #2980b9; }
    .back-btn { display: inline-block; margin-top: 20px; background-color: #6c757d; color: white; text-decoration: none; padding: 10px 20px; border-radius: 5px; }
    .back-btn:hover { background-color: #5a6268; }
    .status-badge { padding: 4px 8px; border-radius: 999px; font-size: 12px; }
    .st-active     { background:#e8f5e9; color:#2e7d32; border:1px solid #c8e6c9; }
    .st-graduated  { background:#eef2ff; color:#3730a3; border:1px solid #e0e7ff; }
    .st-leave      { background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; }
    .st-suspended  { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
    .toolbar { display:flex; justify-content: space-between; align-items:center; gap:16px; flex-wrap:wrap; }
  </style>
</head>
<body>
  <div class="navbar">
    <div class="navbar-brand">ระบบผู้ดูแลระบบ</div>
    <div class="navbar-user">
      <span>ยินดีต้อนรับ, <?php echo h($_SESSION['admin_username'] ?? 'Admin'); ?></span>
      <a href="admin_logout.php">ออกจากระบบ</a>
    </div>
  </div>

  <div class="container">
    <div class="toolbar">
      <h1>จัดการสถานะนักศึกษา</h1>
      <a href="admin_dashboard.php" class="back-btn">กลับสู่หน้าหลักผู้ดูแลระบบ</a>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-danger"><?php echo $error;   ?></div><?php endif; ?>

    <table>
      <thead>
        <tr>
          <th>รหัสนักศึกษา</th>
          <th>ชื่อ-นามสกุล</th>
          <th>จำนวนครั้งที่ทำแบบทดสอบ</th>
          <th>จำนวนครั้งที่แนะนำสำเร็จ</th>
          <th>อนุญาตให้ทำเพิ่ม (แอดมิน)</th>
          <th>สถานะนักศึกษา</th>
          <th>บันทึก</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($students_status_result && $students_status_result->num_rows > 0): ?>
        <?php while($row = $students_status_result->fetch_assoc()): ?>
          <?php
            $sid      = $row['student_id'];
            $fullname = $row['full_name'] ?? 'ไม่พบชื่อ';
            $qa       = (int)$row['quiz_attempts'];
            $rc       = (int)$row['recommended_count'];
            $ov       = (int)$row['admin_override_attempts'];
            $ast      = $row['academic_status'] ?? 'active';
            $badgeCls = [
              'active'    => 'st-active',
              'graduated' => 'st-graduated',
              'leave'     => 'st-leave',
              'suspended' => 'st-suspended'
            ][$ast] ?? 'st-active';
          ?>
          <tr>
            <td><?php echo h($sid); ?></td>
            <td><?php echo h($fullname); ?></td>
            <form action="manage_students.php" method="POST">
              <input type="hidden" name="student_id" value="<?php echo h($sid); ?>">
              <td><input type="number" name="quiz_attempts" value="<?php echo h($qa); ?>" min="0"></td>
              <td><input type="number" name="recommended_count" value="<?php echo h($rc); ?>" min="0"></td>
              <td><input type="number" name="admin_override_attempts" value="<?php echo h($ov); ?>" min="0"></td>
              <td>
                <select name="academic_status">
                  <?php
                    $opts = [
                      'active'     => 'กำลังศึกษา',
                      'graduated'  => 'สำเร็จการศึกษา',
                      'leave'      => 'ลาพัก/หยุดชั่วคราว',
                      'suspended'  => 'พักการเรียน/ระงับสิทธิ์'
                    ];
                    foreach ($opts as $k=>$label) {
                      $sel = ($ast === $k) ? 'selected' : '';
                      echo "<option value=\"".h($k)."\" $sel>".h($label)."</option>";
                    }
                  ?>
                </select>
                <span class="status-badge <?php echo $badgeCls; ?>" title="สถานะปัจจุบัน">
                  <?php echo h($opts[$ast] ?? 'กำลังศึกษา'); ?>
                </span>
              </td>
              <td><button type="submit" name="update_status" class="btn-update">อัปเดต</button></td>
            </form>
          </tr>
        <?php endwhile; ?>
      <?php else: ?>
        <tr><td colspan="7">ไม่พบข้อมูลนักศึกษา</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
