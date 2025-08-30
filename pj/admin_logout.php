<?php
session_start();

// ทำลาย session ทั้งหมด
$_SESSION = array();

// ทำลาย session cookie ถ้ามี
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-42000, '/');
}

// ทำลาย session
session_destroy();

// ส่งกลับไปหน้าล็อกอินพร้อมข้อความ
header("Location: index.php?message=" . urlencode("ออกจากระบบเรียบร้อยแล้ว"));
exit();
?>