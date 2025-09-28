<?php
// reject_reset_request.php
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

$stmt = $conn->prepare("
  UPDATE password_resets
  SET status='rejected'
  WHERE user_type='teacher' AND identifier=? AND status='pending' AND used=0
");
$stmt->bind_param('s', $teacherId);
if ($stmt->execute() && $stmt->affected_rows>0) {
    echo json_encode(['success'=>true,'message'=>'ปฏิเสธคำขอแล้ว']);
} else {
    echo json_encode(['success'=>false,'message'=>'ไม่พบคำขอค้างของอาจารย์คนนี้']);
}
$stmt->close();
