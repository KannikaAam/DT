<?php
// reset_teacher_password.php
session_start();
require_once 'db_connect.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['loggedin']) || (($_SESSION['user_type'] ?? '') !== 'admin')) {
    echo json_encode(['success'=>false,'message'=>'unauthorized']); exit;
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    echo json_encode(['success'=>false,'message'=>'CSRF invalid']); exit;
}

$teacherId = trim($_POST['teacher_id'] ?? '');
if ($teacherId === '') {
    echo json_encode(['success'=>false,'message'=>'ไม่พบ teacher_id']); exit;
}

// ดึงคำขอที่ค้างอยู่
$stmt = $conn->prepare("
  SELECT id, expires_at
  FROM password_resets
  WHERE user_type='teacher' AND identifier=? AND status='pending' AND used=0
  LIMIT 1
");
$stmt->bind_param('s', $teacherId);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$req) {
    echo json_encode(['success'=>false,'message'=>'ไม่มีคำขอค้างของอาจารย์คนนี้']); exit;
}

// หมดอายุ? → reject + used=1
if (strtotime($req['expires_at']) < time()) {
    $stmt = $conn->prepare("UPDATE password_resets SET status='rejected', used=1 WHERE id=?");
    $stmt->bind_param('i', $req['id']);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success'=>false,'message'=>'คำขอหมดอายุแล้ว']); exit;
}

// รีเซ็ตเป็น 123456
$newPlain = '123456';
$newHash  = password_hash($newPlain, PASSWORD_DEFAULT);

// อัปเดตคอลัมน์ที่ login ใช้แน่ๆ
$stmt = $conn->prepare("UPDATE teacher SET password=? WHERE teacher_id=?");
$stmt->bind_param('ss', $newHash, $teacherId);
if (!$stmt->execute() || $stmt->affected_rows < 1) {
    $stmt->close();
    echo json_encode(['success'=>false,'message'=>'อัปเดตรหัสไม่สำเร็จ (ไม่พบ teacher_id หรือเกิดข้อผิดพลาด)']); exit;
}
$stmt->close();

// เผื่อบางฐานมี password_hash ด้วย ก็อัปเดตไว้ (ไม่ผิดแม้ไม่มีคอลัมน์)
@$conn->query(sprintf(
    "UPDATE teacher SET password_hash='%s' WHERE teacher_id='%s'",
    $conn->real_escape_string($newHash),
    $conn->real_escape_string($teacherId)
));

// ปิดคำขอ: used=1, status=approved (+approved_by ถ้ามีคอลัมน์)
$approvedBy = $_SESSION['username'] ?? 'admin';
// เช็คว่ามี approved_by ไหม
$hasApprovedBy = false;
if ($r = $conn->query("SHOW COLUMNS FROM password_resets LIKE 'approved_by'")) {
    $hasApprovedBy = ($r->num_rows > 0);
    $r->close();
}
if ($hasApprovedBy) {
    $stmt = $conn->prepare("UPDATE password_resets SET used=1, status='approved', approved_by=? WHERE id=?");
    $stmt->bind_param('si', $approvedBy, $req['id']);
} else {
    $stmt = $conn->prepare("UPDATE password_resets SET used=1, status='approved' WHERE id=?");
    $stmt->bind_param('i', $req['id']);
}
$stmt->execute();
$stmt->close();

echo json_encode(['success'=>true,'message'=>'รีเซ็ตรหัสผ่านแล้ว']);
