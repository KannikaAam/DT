<?php
include 'db_connect.php'; // เชื่อมต่อฐานข้อมูล

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $email = trim($_POST['email']);

    // ตรวจสอบข้อมูลเบื้องต้น
    if (empty($username) || empty($password) || empty($confirm_password) || empty($email)) {
        $error = "กรุณากรอกข้อมูลให้ครบถ้วนทุกช่อง";
    } elseif ($password !== $confirm_password) {
        $error = "รหัสผ่านและการยืนยันรหัสผ่านไม่ตรงกัน";
    } elseif (strlen($password) < 6) {
        $error = "รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "รูปแบบอีเมลไม่ถูกต้อง";
    } else {
        // ตรวจสอบว่าชื่อผู้ใช้ซ้ำหรือไม่
        $stmt = $conn->prepare("SELECT id FROM admins WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error = "ชื่อผู้ใช้นี้ถูกใช้งานแล้ว กรุณาเลือกชื่อผู้ใช้อื่น";
        } else {
            // Hash รหัสผ่านก่อนบันทึกลงฐานข้อมูล
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // บันทึกข้อมูลแอดมิน
            $stmt_insert = $conn->prepare("INSERT INTO admins (username, password, email) VALUES (?, ?, ?)");
            $stmt_insert->bind_param("sss", $username, $hashed_password, $email);

            if ($stmt_insert->execute()) {
                $message = "สร้างบัญชีแอดมินสำเร็จ! ตอนนี้คุณสามารถเข้าสู่ระบบได้แล้ว";
                // Optionally redirect to login page after successful registration
                // header("Location: admin_login.php?success=" . urlencode($message));
                // exit();
            } else {
                $error = "เกิดข้อผิดพลาดในการสร้างบัญชี: " . $stmt_insert->error;
            }
            $stmt_insert->close();
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครบัญชีผู้ดูแลระบบ</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Prompt', sans-serif;
            background: linear-gradient(135deg, #71b7e6, #9b59b6);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            color: #333;
        }
        .register-container {
            background-color: #fff;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 450px;
            text-align: center;
        }
        .register-container h2 {
            margin-bottom: 25px;
            color: #3498db;
            font-size: 28px;
        }
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }
        .form-group input[type="text"],
        .form-group input[type="password"],
        .form-group input[type="email"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            box-sizing: border-box;
        }
        .btn-register {
            background-color: #28a745; /* Green for register */
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 18px;
            font-weight: 500;
            transition: background-color 0.3s ease;
            width: 100%;
            margin-top: 10px;
        }
        .btn-register:hover {
            background-color: #218838;
        }
        .btn-back-login {
            background-color: #007bff; /* Blue for login */
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: background-color 0.3s ease;
            width: 100%;
            margin-top: 15px;
            display: block;
            text-decoration: none;
        }
        .btn-back-login:hover {
            background-color: #0056b3;
        }
        .message-success {
            color: #28a745;
            margin-top: 15px;
            font-size: 16px;
            font-weight: bold;
        }
        .message-error {
            color: #dc3545;
            margin-top: 15px;
            font-size: 16px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <h2>สมัครบัญชีผู้ดูแลระบบ</h2>
        <?php if ($message): ?>
            <p class="message-success"><?php echo $message; ?></p>
        <?php endif; ?>
        <?php if ($error): ?>
            <p class="message-error"><?php echo $error; ?></p>
        <?php endif; ?>
        <form method="POST" action="admin_register.php">
            <div class="form-group">
                <label for="username">ชื่อผู้ใช้ (Username):</label>
                <input type="text" id="username" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="email">อีเมล:</label>
                <input type="email" id="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="password">รหัสผ่าน:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">ยืนยันรหัสผ่าน:</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <button type="submit" class="btn-register">สร้างบัญชีแอดมิน</button>
        </form>
        <a href="admin_login.php" class="btn-back-login">กลับไปหน้าเข้าสู่ระบบ</a>
    </div>
</body>
</html>