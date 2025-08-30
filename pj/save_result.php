<?php
session_start();
include 'db_connect.php'; // เชื่อมต่อฐานข้อมูล

// ตรวจสอบว่า session มีข้อมูลผู้ใช้หรือไม่
$studentID = $_SESSION['student_id'] ?? '';
if ($studentID === '') {
    die("ไม่พบข้อมูลผู้ใช้ใน session");
}

// รับค่าจากแบบฟอร์มที่ส่งมาหรือจากการคลิก link
$group = $_POST['group'] ?? ''; // กลุ่มที่แนะนำ
$subjects = $_POST['subjects'] ?? ''; // รายวิชาที่แนะนำ
$noCount = $_POST['noCount'] ?? 0; // จำนวนคำตอบ "ไม่ใช่"

// SQL สำหรับบันทึกข้อมูลผลการทดสอบ
$sql = "INSERT INTO test_history (student_id, recommended_group, recommended_subjects, no_count) 
        VALUES (?, ?, ?, ?)";

// ใช้ prepare statement ป้องกัน SQL injection
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssi", $studentID, $group, $subjects, $noCount);

// บันทึกข้อมูล
if ($stmt->execute()) {
    echo "บันทึกข้อมูลสำเร็จ";
} else {
    echo "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $stmt->error;
}

// ปิด statement และการเชื่อมต่อ
$stmt->close();
$conn->close();
?>
