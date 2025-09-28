<?php
// teacher_forgot_password.php — ขอรีเซ็ตรหัสผ่านโดยยืนยันด้วย username + teacher_code (เวอร์ชันแก้แล้ว)
session_start();

require_once 'db_connect.php'; // ต้องมี $conn (mysqli)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ALL);
ini_set('display_errors', 1);

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$info = '';
$error = '';
$old  = ['username'=>'','teacher_code'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username     = trim($_POST['username'] ?? '');
    $teacher_code = trim($_POST['teacher_code'] ?? '');

    $old['username']     = $username;
    $old['teacher_code'] = $teacher_code;

    if ($username === '' || $teacher_code === '') {
        $error = 'กรุณากรอกทั้ง username และ teacher_code';
    } else {
        // ✅ ตารางจริงใช้คอลัมน์ชื่อ id จึง alias เป็น teacher_id
        $sql = "SELECT id AS teacher_id, username, teacher_code
                FROM teacher
                WHERE username = ? AND teacher_code = ?
                LIMIT 1";
        try {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $username, $teacher_code);
            $stmt->execute();
            $res = $stmt->get_result();
            $teacher = $res->fetch_assoc();
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $error = 'ไม่สามารถค้นหาข้อมูลได้: ' . $e->getMessage();
        }

        if (!$error) {
            if (!$teacher) {
                $error = 'ไม่พบบัญชีอาจารย์ตาม username และ teacher_code นี้';
            } else {
                $teacherId = (string)$teacher['teacher_id'];  // ใช้ id ของอาจารย์
                $expires   = date('Y-m-d H:i:s', time() + 60*60*12); // 12 ชม.
                $token     = ''; // โหมดรอแอดมินอนุมัติ ยังไม่ต้องมี token

                // UPSERT โดยต้องมี UNIQUE KEY (user_type, identifier)
                $sqlUpsert = "
                    INSERT INTO password_resets (user_type, identifier, token, expires_at, used, status)
                    VALUES ('teacher', ?, ?, ?, 0, 'pending')
                    ON DUPLICATE KEY UPDATE
                        token = VALUES(token),
                        expires_at = VALUES(expires_at),
                        used = 0,
                        status = 'pending'
                ";
                try {
                    $stmt = $conn->prepare($sqlUpsert);
                    $stmt->bind_param('sss', $teacherId, $token, $expires);
                    $stmt->execute();
                    $stmt->close();
                    $info = 'ส่งคำขอรีเซ็ตรหัสถึงผู้ดูแลระบบแล้ว โปรดรอการอนุมัติ';
                } catch (mysqli_sql_exception $e) {
                    // เผื่อบางฐานยังไม่มีคอลัมน์ used/status
                    if (strpos($e->getMessage(), 'Unknown column') !== false) {
                        $fallback = "
                            INSERT INTO password_resets (user_type, identifier, token, expires_at)
                            VALUES ('teacher', ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                token = VALUES(token),
                                expires_at = VALUES(expires_at)
                        ";
                        $stmt = $conn->prepare($fallback);
                        $stmt->bind_param('sss', $teacherId, $token, $expires);
                        $stmt->execute();
                        $stmt->close();
                        $info = 'ส่งคำขอรีเซ็ตรหัสถึงผู้ดูแลระบบแล้ว โปรดรอการอนุมัติ';
                    } else {
                        $error = 'ไม่สามารถส่งคำขอได้: ' . $e->getMessage();
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
<title>ลืมรหัสผ่าน (อาจารย์)</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
<style>
body{font-family:'Sarabun',system-ui;background:#0f172a;color:#e5e7eb;margin:0}
.container{max-width:540px;margin:40px auto;padding:16px}
.card{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:18px}
.label{display:block;margin:10px 0 6px;font-weight:600}
.input{width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.15);background:rgba(17,24,39,.7);color:#e5e7eb}
.btn{padding:10px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;cursor:pointer}
.alert{padding:10px;border-radius:10px;margin:10px 0}
.alert-ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35)}
.alert-err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35)}
.help{color:#94a3b8;font-size:13px}
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <h2 style="margin-top:0">ลืมรหัสผ่าน (อาจารย์)</h2>
    <p class="help">กรอก <b>username</b> และ <b>teacher_code</b> เพื่อส่งคำขอไปยังผู้ดูแลระบบ</p>

    <?php if($error): ?><div class="alert alert-err"><?=h($error)?></div><?php endif; ?>
    <?php if($info):  ?><div class="alert alert-ok"><?=h($info)?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <label class="label">ชื่อผู้ใช้</label>
      <input class="input" type="text" name="username" placeholder="เช่น somchai" value="<?=h($old['username'])?>" required>

      <label class="label">รหัสอาจารย์</label>
      <input class="input" type="text" name="teacher_code" placeholder="เช่น 6522xxxx หรือรหัสอาจารย์" value="<?=h($old['teacher_code'])?>" required>

      <div style="margin-top:12px">
        <button class="btn" type="submit">ส่งคำขอรีเซ็ต</button>
        <a class="btn" style="background:transparent" href="forgot_password.php">กลับ</a>
        <a class="btn" style="background:transparent;color:#e5e7eb" href="index.php">กลับหน้าเข้าสู่ระบบ</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
