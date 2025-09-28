<?php
// ไฟล์สำหรับ debug session - สร้างไฟล์ debug_session.php
session_start();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Session</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .debug-box { background: white; padding: 20px; border-radius: 8px; margin: 10px 0; }
        .success { color: green; } .error { color: red; } .warning { color: orange; }
        pre { background: #f8f8f8; padding: 15px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>🔍 Session Debug Tool</h1>
    
    <div class="debug-box">
        <h2>📋 ข้อมูล Session ปัจจุบัน</h2>
        <pre><?php print_r($_SESSION); ?></pre>
    </div>
    
    <div class="debug-box">
        <h2>🔍 การตรวจสอบ Session</h2>
        
        <?php if (isset($_SESSION['user_id'])): ?>
            <p class="success">✅ มี user_id: <?php echo $_SESSION['user_id']; ?></p>
        <?php else: ?>
            <p class="error">❌ ไม่มี user_id</p>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['username'])): ?>
            <p class="success">✅ มี username: <?php echo $_SESSION['username']; ?></p>
        <?php else: ?>
            <p class="warning">⚠️ ไม่มี username</p>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['student_id'])): ?>
            <p class="success">✅ มี student_id: <?php echo $_SESSION['student_id']; ?></p>
        <?php else: ?>
            <p class="warning">⚠️ ไม่มี student_id</p>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['full_name'])): ?>
            <p class="success">✅ มี full_name: <?php echo $_SESSION['full_name']; ?></p>
        <?php else: ?>
            <p class="warning">⚠️ ไม่มี full_name</p>
        <?php endif; ?>
    </div>
    
    <div class="debug-box">
        <h2>ℹ️ ข้อมูล PHP Session</h2>
        <p><strong>Session ID:</strong> <?php echo session_id(); ?></p>
        <p><strong>Session Status:</strong> <?php echo session_status(); ?></p>
        <p><strong>Session Save Path:</strong> <?php echo session_save_path(); ?></p>
        <p><strong>Session Cookie Params:</strong></p>
        <pre><?php print_r(session_get_cookie_params()); ?></pre>
    </div>
    
    <div class="debug-box">
        <h2>🔧 เครื่องมือจัดการ Session</h2>
        <a href="?action=clear" style="background: #ff4444; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin: 5px;">🗑️ ล้าง Session</a>
        <a href="?action=mock" style="background: #44ff44; color: black; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin: 5px;">🎭 สร้าง Mock Session</a>
        <a href="student_dashboard.php" style="background: #4444ff; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin: 5px;">🏠 ไปแดชบอร์ด</a>
        <a href="history.php" style="background: #ff8800; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin: 5px;">📊 ไปประวัติ</a>
    </div>
    
    <?php
    // จัดการ Actions
    if (isset($_GET['action'])) {
        echo '<div class="debug-box">';
        echo '<h2>🎬 ผลการดำเนินการ</h2>';
        
        switch ($_GET['action']) {
            case 'clear':
                session_destroy();
                session_start();
                echo '<p class="success">✅ ล้าง Session เรียบร้อยแล้ว</p>';
                echo '<script>setTimeout(() => location.reload(), 1000);</script>';
                break;
                
            case 'mock':
                $_SESSION['user_id'] = 1;
                $_SESSION['username'] = 'test_user';
                $_SESSION['student_id'] = '65001234567';
                $_SESSION['full_name'] = 'นักศึกษาทดสอบ';
                echo '<p class="success">✅ สร้าง Mock Session เรียบร้อยแล้ว</p>';
                echo '<script>setTimeout(() => location.reload(), 1000);</script>';
                break;
        }
        echo '</div>';
    }
    ?>
    
    <div class="debug-box">
        <h2>💡 คำแนะนำการแก้ไข</h2>
        <ol>
            <li><strong>ตรวจสอบไฟล์ login.php:</strong> ดูว่าตั้งค่า session ครบหรือไม่</li>
            <li><strong>ตรวจสอบ session_start():</strong> ต้องอยู่บรรทัดแรกของทุกไฟล์</li>
            <li><strong>ตรวจสอบ config.php:</strong> อาจมีการ session_destroy() โดยไม่ตั้งใจ</li>
            <li><strong>ตรวจสอบ Cookie Settings:</strong> อาจมีปัญหาเรื่อง domain หรือ path</li>
            <li><strong>ลองใช้ Mock Session:</strong> เพื่อทดสอบว่าหน้าอื่นๆ ทำงานได้ปกติ</li>
        </ol>
    </div>
</body>
</html>

<?php
// === แนะนำโค้ดสำหรับไฟล์ login.php ===
/*
ในไฟล์ login.php หลังจากตรวจสอบ username/password ถูกต้องแล้ว ให้เพิ่ม:

// หลังจากตรวจสอบ login สำเร็จ
session_start();
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['student_id'] = $user['student_id'];
$_SESSION['full_name'] = $user['full_name'];

// บันทึกประวัติการเข้าระบบ
$login_stmt = $pdo->prepare("INSERT INTO login_history (user_id, ip_address) VALUES (?, ?)");
$login_stmt->execute([$user['id'], $_SERVER['REMOTE_ADDR']]);

header('Location: dashboard.php');
exit();
*/

// === แนะนำโค้ดสำหรับการ logout ===
/*
สร้างไฟล์ logout.php:

<?php
session_start();
session_destroy();
header('Location: login.php?message=logged_out');
exit();
?>
*/
?>