<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
/* admin_add_teacher.php — หน้าเพิ่มอาจารย์ (สำหรับผู้ดูแลระบบเท่านั้น) */
session_start();
require_once __DIR__.'/db_connect.php';   // ต้องมี $conn (mysqli)

/* ให้ mysqli โยน exception เพื่อจับ error ได้ชัดเจน */
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}
if (isset($conn) && $conn instanceof mysqli) {
    $conn->set_charset('utf8mb4');
}

/* ========= 1) อนุญาตเฉพาะผู้ดูแลระบบ ========= */
if (empty($_SESSION['loggedin']) || (($_SESSION['user_type'] ?? '') !== 'admin')) {
    header('Location: login.php?error=unauthorized');
    exit();
}

/* ========= 2) CSRF token ========= */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ========= 3) ตัวแปรใช้ในฟอร์ม ========= */
$errors = [];
$old = [
    'username'     => '',
    'teacher_code' => '',
    'name'         => '',
    'email'        => '',
];

/* ========= 4) เมื่อส่งฟอร์ม ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ตรวจ CSRF
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        $errors[] = 'คำขอไม่ถูกต้อง (CSRF) กรุณาลองใหม่';
    }

    // รับค่า + trim
    $username     = trim($_POST['username'] ?? '');
    $teacher_code = trim($_POST['teacher_code'] ?? '');
    $name         = trim($_POST['name'] ?? '');
    $email        = trim($_POST['email'] ?? '');

    // เก็บค่าเดิม
    $old['username']     = $username;
    $old['teacher_code'] = $teacher_code;
    $old['name']         = $name;
    $old['email']        = $email;

    // ---------- ตรวจความถูกต้องฝั่งเซิร์ฟเวอร์ ----------
    // username: A-Z a-z 0-9 _ - ความยาว 5–20
    if ($username === '' || !preg_match('/^[A-Za-z0-9_-]{5,20}$/', $username)) {
        $errors[] = 'กรุณากรอกชื่อผู้ใช้ (username) เป็นอังกฤษ/ตัวเลข/ขีด (_ -) ความยาว 5–20 ตัวอักษร';
    }
    // teacher_code: A-Z a-z 0-9 _ - ความยาว 1–12
    if ($teacher_code === '' || !preg_match('/^[A-Za-z0-9_-]{1,12}$/', $teacher_code)) {
        $errors[] = 'กรุณากรอกรหัสประจำตัวอาจารย์ (teacher_code) เป็นอังกฤษ/ตัวเลข/ขีด (_ -) ความยาว 1–12 ตัวอักษร';
    }
    // name: ไม่เกิน 100 ตัวอักษร
    if ($name === '' || mb_strlen($name) > 100) {
        $errors[] = 'กรุณากรอกชื่อ-นามสกุล และต้องไม่ยาวเกิน 100 ตัวอักษร';
    }
    // email: ต้องเป็นภาษาอังกฤษ (ASCII เท่านั้น) + รูปแบบอีเมลมาตรฐาน + ความยาว <=120
    $is_ascii = (bool)preg_match('/^[\x00-\x7F]+$/', $email);
    $email_pattern_ok = (bool)preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $email);
    if ($email === '' || mb_strlen($email) > 120 || !$is_ascii || !$email_pattern_ok || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'กรุณากรอกอีเมลเป็นภาษาอังกฤษเท่านั้น และอยู่ในรูปแบบที่ถูกต้อง (เช่น name@example.com)';
    }

    // ตรวจซ้ำ: username / teacher_code / email ต้องไม่ซ้ำ
    if (!$errors) {
        $sql = "SELECT 1 FROM teacher WHERE username = ? OR teacher_code = ? OR email = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sss', $username, $teacher_code, $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = 'พบ username, teacher_code หรืออีเมล ซ้ำในระบบแล้ว';
        }
        $stmt->close();
    }

    // ถ้าไม่ผิดพลาด -> บันทึก (execute ครั้งเดียว!)
    if (!$errors) {
        $default_password = '123456';
        $hashed = password_hash($default_password, PASSWORD_DEFAULT);
        $role   = 'teacher';
        $status = 'active';

        // บันทึก hash ลงทั้ง 3 คอลัมน์ (password / teacher_password / password_hash)
        $sql = "INSERT INTO teacher (
                    username, teacher_code, name, email,
                    password, teacher_password, password_hash,
                    role, status
                ) VALUES (?,?,?,?,?,?,?,?,?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            'sssssssss',
            $username,
            $teacher_code,
            $name,
            $email,
            $hashed,  // password
            $hashed,  // teacher_password
            $hashed,  // password_hash
            $role,
            $status
        );

        try {
            /* สำคัญ: execute แค่ครั้งเดียว */
            $stmt->execute();
            $newId = $conn->insert_id;  // id (PK) ที่เพิ่งเพิ่ม
            $stmt->close();
            header('Location: view_teacher.php?status=created&teacher_id=' . $newId);
            exit;
        } catch (mysqli_sql_exception $e) {
            $stmt->close();
            if ((int)$e->getCode() === 1062) { // duplicate key
                $errors[] = 'เพิ่มไม่ได้: พบข้อมูลซ้ำ (username / email / teacher_code)';
            } else {
                $errors[] = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            }
        }
    }
}

/* ========= ฟังก์ชัน escape ========= */
function h($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>เพิ่มบัญชีอาจารย์ (Admin)</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0f172a;
            --card: #111827;
            --accent: #2563eb;
            --text: #e5e7eb;
            --muted: #94a3b8;
        }
        * { box-sizing: border-box; }
        body { margin:0; font-family:'Sarabun', system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: linear-gradient(135deg,#0b1220,#111827); color:var(--text); }
        .container{ max-width:780px; margin:40px auto; padding:24px; }
        .card{ background:linear-gradient(180deg,rgba(255,255,255,.02),rgba(255,255,255,.01)); border:1px solid rgba(255,255,255,.08); border-radius:16px; padding:24px; box-shadow:0 10px 30px rgba(0,0,0,.25); backdrop-filter:blur(8px); }
        h1{ margin:0 0 4px; font-size:28px; letter-spacing:.3px; }
        .sub{ color:var(--muted); margin-bottom:20px; }
        .row{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .row-1{ grid-template-columns:1fr; }
        label{ display:block; font-weight:600; margin-bottom:6px; }
        input[type="text"], input[type="email"]{
            width:100%; padding:12px 14px; border-radius:10px; border:1px solid rgba(255,255,255,.15);
            background:rgba(17,24,39,.65); color:var(--text); outline:none;
        }
        input::placeholder{ color:#94a3b8; }
        .help{ color:var(--muted); font-size:13px; margin-top:6px; }
        .alert{ padding:12px 14px; border-radius:10px; margin-bottom:14px; background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.35); }
        .btn{ display:inline-block; padding:12px 18px; border-radius:10px; border:1px solid rgba(255,255,255,.14);
              background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; font-weight:600; text-decoration:none; cursor:pointer; }
        .pill{ padding:4px 10px; background:#0b1a36; border:1px solid rgba(255,255,255,.1); border-radius:999px; font-size:12px; color:#cbd5e1; margin-right:6px; }
        @media (max-width:720px){ .row{ grid-template-columns:1fr; } }
        .code{ font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>เพิ่มบัญชีอาจารย์</h1>

        <?php if ($errors): ?>
            <div class="alert">
                <ul style="margin:0 0 0 16px; padding:0 0 0 4px;">
                    <?php foreach ($errors as $e): ?>
                        <li><?= h($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="" autocomplete="off" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

            <div class="row">
                <div>
                    <label for="username">ชื่อผู้ใช้ (username)</label>
                    <input type="text" id="username" name="username" placeholder="เช่น T001 หรือ kan123"
                           value="<?= h($old['username']) ?>" required
                           pattern="[A-Za-z0-9_\-]{5,20}"
                           title="ใช้ภาษาอังกฤษ/ตัวเลข/ขีด (_ -) ความยาว 5–20">
                    <div class="help">ใช้ตัวอักษร/ตัวเลข/ขีด (_ -) ความยาว 5–20</div>
                </div>
                <div>
                    <label for="teacher_code">รหัสประจำตัวอาจารย์</label>
                    <input type="text" id="teacher_code" name="teacher_code" placeholder="เช่น 1001 หรือ 123456"
                           value="<?= h($old['teacher_code']) ?>" required
                           pattern="[A-Za-z0-9_\-]{1,12}"
                           title="ใช้ภาษาอังกฤษ/ตัวเลข/ขีด (_ -) ความยาว 1–12">
                    <div class="help">ใช้ตัวอักษร/ตัวเลข/ขีด (_ -) ความยาว 1–12</div>
                </div>
            </div>

            <div class="row">
                <div>
                    <label for="name">ชื่อ - นามสกุล</label>
                    <input type="text" id="name" name="name" placeholder="ชื่อ - นามสกุล"
                           value="<?= h($old['name']) ?>" required>
                </div>
                <div>
                    <label for="email">อีเมล (ภาษาอังกฤษเท่านั้น)</label>
                    <input type="email" id="email" name="email" placeholder="example@domain.com"
                           value="<?= h($old['email']) ?>" required
                           pattern="[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}"
                           title="ใช้อักษรอังกฤษ/ตัวเลขเท่านั้น เช่น name@example.com">
                    <div class="help">ต้องเป็นอีเมลภาษาอังกฤษเท่านั้น (เช่น name@example.com)</div>
                </div>
            </div>

            <div style="margin-top:14px;">
                <span class="pill">PK: id</span>
                <span class="pill">ต้องไม่ซ้ำ: username / teacher_code / email</span>
                <span class="pill">default password = 123456</span>
                <span class="pill">role = teacher · status = active</span>
            </div>

            <div style="margin-top:16px;">
                <button type="submit" class="btn">บันทึกอาจารย์</button>
                <a href="view_teacher.php" class="btn" style="background:transparent; color:#e5e7eb;">ยกเลิก</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>