<?php
// student_forgot_password.php — นักศึกษาตั้งรหัสใหม่ด้วยตนเอง (ยืนยันตัวตน: ชื่อ-นามสกุล + บัตร ปชช. + รหัสนักศึกษา)
session_start();
require_once 'db_connect.php'; // ต้องมี $conn (mysqli)

function h($s){ return htmlspecialchars($s??'', ENT_QUOTES, 'UTF-8'); }

/* ---------- Utilities ---------- */
function tableExists(mysqli $c, string $t): bool {
  $q=$c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->bind_param("s",$t); $q->execute(); $q->store_result();
  $ok=$q->num_rows>0; $q->close(); return $ok;
}
function colExists(mysqli $c, string $t, string $col): bool {
  $q=$c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->bind_param("ss",$t,$col); $q->execute(); $q->store_result();
  $ok=$q->num_rows>0; $q->close(); return $ok;
}
/* ตรวจเลขบัตรประชาชนไทย 13 หลัก */
function isThaiCID(string $cid): bool {
  $cid = preg_replace('/\D+/', '', $cid);
  if (strlen($cid)!==13) return false;
  $sum=0; for($i=0;$i<12;$i++) $sum += intval($cid[$i])*(13-$i);
  $chk = (11-($sum%11))%10;
  return $chk === intval($cid[12]);
}
/* เปรียบเทียบชื่อแบบยืดหยุ่น (ตัดช่องว่างซ้ำ/เว้นวรรคต้นท้าย/ตัวพิมพ์เล็ก-ใหญ่) */
function normName(string $s): string {
  $s = trim(preg_replace('/\s+/u',' ', $s));
  return mb_strtolower($s,'UTF-8');
}

/* ---------- Handle POST ---------- */
$okMsg=''; $errMsg='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $full_name = trim($_POST['full_name'] ?? '');
  $citizen_id = trim($_POST['citizen_id'] ?? '');
  $student_id = trim($_POST['student_id'] ?? '');
  $new_pass   = $_POST['new_password'] ?? '';
  $confirm    = $_POST['confirm_password'] ?? '';

  // Validate
  if ($full_name==='' || $citizen_id==='' || $student_id==='' || $new_pass==='' || $confirm==='') {
    $errMsg = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
  // ตั้งค่านี้เป็น true ถ้าต้องการบังคับตรวจเลขตรวจสอบ (strict)
$STRICT_CID = false;

$cid_digits = preg_replace('/\D+/', '', $citizen_id);
if (strlen($cid_digits) !== 13) {
    $errMsg = 'เลขประจำตัวประชาชนต้องเป็นตัวเลข 13 หลัก';
} elseif ($STRICT_CID && !isThaiCID($cid_digits)) {
    $errMsg = 'เลขประจำตัวประชาชนไม่ผ่านการตรวจสอบ';
} else {
    // ใช้เฉพาะตัวเลขที่กรอก
    $citizen_id = $cid_digits;
}
  } elseif (mb_strlen($full_name) > 150) {
    $errMsg = 'ชื่อ-นามสกุลยาวเกินไป';
  } elseif (mb_strlen($student_id) > 30) {
    $errMsg = 'รหัสนักศึกษายาวเกินไป';
  } elseif ($new_pass !== $confirm) {
    $errMsg = 'รหัสผ่านใหม่และยืนยันไม่ตรงกัน';
  } elseif (mb_strlen($new_pass) < 8) {
    $errMsg = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร';
  } else {
    // เริ่มตรวจตัวตน
    $found=false;

    // 1) join user_login -> personal_info (ถ้ามีคอลัมน์ครบ)
    if (!$found && tableExists($conn,'user_login') && tableExists($conn,'personal_info')) {
      $nameCols = ['full_name','name','student_name'];
      $idCols   = ['citizen_id','national_id','id_card','idcard','card_id'];

      // หา nameCol / idCol ที่มีจริง
      $nameCol = null; foreach($nameCols as $c) if (colExists($conn,'personal_info',$c)){ $nameCol=$c; break; }
      $cidCol  = null; foreach($idCols as $c) if (colExists($conn,'personal_info',$c)){ $cidCol=$c; break; }

      if ($nameCol && $cidCol && colExists($conn,'user_login','personal_id')) {
        $sql = "
          SELECT 1
          FROM user_login u
          JOIN personal_info p ON p.id = u.personal_id
          WHERE u.student_id=? AND p.$cidCol=? AND LOWER(TRIM(REPLACE(p.$nameCol,'  ',' '))) = ?
          LIMIT 1
        ";
        $stmt=$conn->prepare($sql);
        $normName = normName($full_name);
        $stmt->bind_param('sss', $student_id, $citizen_id, $normName);
        // ปรับ normalize ที่ฝั่ง SQL ให้ตรงกับ PHP (ใช้ LOWER+TRIM+REPLACE แทน)
        $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows>0) $found=true;
        $stmt->close();
      }
    }

    // 2) ตรวจในตาราง students โดยตรง (ถ้ามี)
    if (!$found && tableExists($conn,'students')) {
      $nameCols = ['student_name','full_name','name'];
      $cidCols  = ['citizen_id','national_id','id_card','idcard','card_id'];

      // เลือกคอลัมน์ที่มีจริง
      $nameCol=null; foreach($nameCols as $c) if (colExists($conn,'students',$c)){ $nameCol=$c; break; }
      $cidCol =null; foreach($cidCols as $c) if (colExists($conn,'students',$c)){ $cidCol=$c; break; }

      if ($nameCol && $cidCol && colExists($conn,'students','student_id')) {
        $sql = "SELECT 1 FROM students WHERE student_id=? AND $cidCol=? AND LOWER(TRIM(REPLACE($nameCol,'  ',' ')))=? LIMIT 1";
        $stmt=$conn->prepare($sql);
        $normName = normName($full_name);
        $stmt->bind_param('sss',$student_id,$citizen_id,$normName);
        $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows>0) $found=true;
        $stmt->close();
      }
    }

    // 3) เผื่อไม่มีคอลัมน์ชื่อ/บัตรในฐาน (สุดท้าย: ไม่แนะนำ แต่ให้เลือกเปิดใช้งานได้)
    if (!$found && isset($_GET['fallback']) && $_GET['fallback']==='1') {
      // ตรวจ student_id อย่างเดียว (ไม่ปลอดภัย — เปิดใช้เฉพาะกรณีพิเศษ)
      if (tableExists($conn,'user_login')) {
        $stmt=$conn->prepare("SELECT 1 FROM user_login WHERE student_id=? LIMIT 1");
        $stmt->bind_param('s',$student_id);
        $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows>0) $found=true;
        $stmt->close();
      }
    }

    if (!$found) {
      $errMsg = 'ไม่สามารถยืนยันตัวตนได้ กรุณาตรวจสอบข้อมูลที่กรอก';
    } else {
      // ตั้งรหัสใหม่ (บันทึกเป็น hash)
      if (!tableExists($conn,'user_login')) {
        $errMsg='ไม่พบตาราง user_login';
      } else {
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);

        $sql = "UPDATE user_login SET password=?";
        if (colExists($conn,'user_login','password_changed_at')) {
          $sql .= ", password_changed_at=NOW()";
        }
        $sql .= " WHERE student_id=?";

        $up = $conn->prepare($sql);
        $up->bind_param('ss', $hash, $student_id);
        if ($up->execute() && $up->affected_rows>=0) {
          $okMsg = 'ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว คุณสามารถเข้าสู่ระบบด้วยรหัสใหม่ได้ทันที';
        } else {
          $errMsg = 'ไม่สามารถบันทึกรหัสผ่านใหม่ได้: '.$conn->error;
        }
        $up->close();
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>ลืมรหัสผ่าน (นักศึกษา)</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
<style>
body{font-family:'Sarabun',system-ui;background:#0f172a;color:#e5e7eb;margin:0}
.container{max-width:560px;margin:40px auto;padding:16px}
.card{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:18px}
.input{width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.15);background:rgba(17,24,39,.7);color:#e5e7eb}
.label{display:block;margin:10px 0 6px;font-weight:600}
.btn{padding:10px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;cursor:pointer}
.btn-ghost{background:transparent;color:#e5e7eb}
.alert{padding:10px;border-radius:10px;margin:10px 0}
.alert-ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35)}
.alert-err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35)}
.req{color:#ef4444}
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <h2 style="margin-top:0">ลืมรหัสผ่าน (นักศึกษา)</h2>
    <p style="color:#94a3b8">กรอกข้อมูลต่อไปนี้เพื่อยืนยันตัวตนและตั้งรหัสผ่านใหม่</p>

    <?php if($okMsg): ?><div class="alert alert-ok">✔ <?=h($okMsg)?></div><?php endif; ?>
    <?php if($errMsg): ?><div class="alert alert-err">✖ <?=h($errMsg)?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <label class="label"><span class="req">ชื่อ-นามสกุล</span></label>
      <input class="input" type="text" name="full_name" placeholder="เช่น สมชาย ใจดี" required>

      <label class="label"><span class="req">รหัสประจำตัวประชาชน</span></label>
      <input class="input" type="text" name="citizen_id" placeholder="เลข 13 หลัก" maxlength="20" required>

      <label class="label"><span class="req">รหัสนักศึกษา</span></label>
      <input class="input" type="text" name="student_id" placeholder="เช่น 6522xxxxxx" required>

      <label class="label"><span class="req">รหัสผ่านใหม่</span></label>
      <input class="input" type="password" name="new_password" minlength="8" placeholder="อย่างน้อย 8 ตัวอักษร" required>

      <label class="label">ยืนยันรหัสผ่านใหม่</label>
      <input class="input" type="password" name="confirm_password" minlength="8" required>

      <div style="margin-top:12px">
        <button class="btn" type="submit">ตั้งรหัสผ่านใหม่</button>
        <a class="btn btn-ghost" href="forgot_password.php">กลับ</a>
        <a class="btn btn-ghost" href="index.php">ไปหน้าเข้าสู่ระบบ</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
