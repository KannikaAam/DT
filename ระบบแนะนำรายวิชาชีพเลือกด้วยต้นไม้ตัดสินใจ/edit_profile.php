<?php

ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);
session_start();

/* ---------- DB CONNECT ---------- */
require_once __DIR__.'/db_connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (!isset($conn) || !($conn instanceof mysqli)) {
  if (defined('DB_HOST')) $conn = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_DATABASE);
  else $conn = new mysqli('127.0.0.1','root','', 'studentregistration');
}
$conn->set_charset('utf8mb4');

/* ---------- AUTH ---------- */
if (empty($_SESSION['student_id'])) { header("Location: login.php?error=unauthorized"); exit; }
$student_id = $_SESSION['student_id'];

/* ---------- UPLOAD CFG ---------- */
define('UPLOAD_DIR', __DIR__.'/uploads/profile_images/');
define('PUBLIC_UPLOAD_BASE', 'uploads/profile_images/');

/* ---------- HELPERS ---------- */
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function be_from_input($y){
  $y = trim((string)$y);
  if ($y==='') return '';
  if (!ctype_digit($y) || strlen($y)!==4) return '';
  $n = (int)$y;
  if ($n < 2400) $n += 543;
  return (string)$n;
}

/* ---------- JSON Endpoints ---------- */
if (isset($_GET['json']) && $_GET['json']!=='') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  $labels_by_type = function(mysqli $conn, string $type): array {
    $sql="SELECT label FROM form_options WHERE type=? AND label<>'' ORDER BY label";
    $st=$conn->prepare($sql); $st->bind_param("s",$type); $st->execute();
    $rs=$st->get_result(); $out=[]; while($r=$rs->fetch_assoc()) $out[]=$r['label']; $st->close();
    return $out;
  };

  $mode = $_GET['json'];
  try{
    if ($mode==='meta'){
      echo json_encode([
        'faculties' => $labels_by_type($conn,'faculty'),
        'levels'    => $labels_by_type($conn,'education_level'),
        'ptypes'    => $labels_by_type($conn,'program_type'),
        'terms'     => $labels_by_type($conn,'education_term'),
        'curyears'  => $labels_by_type($conn,'curriculum_year'),
        'eduyears'  => $labels_by_type($conn,'education_year'),
      ], JSON_UNESCAPED_UNICODE); exit;
    }

    if ($mode==='majors' && isset($_GET['faculty'])){
      $sql="SELECT m.label
            FROM form_options m
            JOIN form_options f ON m.parent_value=f.id
            WHERE m.type='major' AND f.type='faculty' AND f.label=?
            ORDER BY m.label";
      $st=$conn->prepare($sql); $st->bind_param("s",$_GET['faculty']); $st->execute();
      $rs=$st->get_result(); $out=[]; while($r=$rs->fetch_assoc()) $out[]=$r['label'];
      echo json_encode($out,JSON_UNESCAPED_UNICODE); exit;
    }

    if ($mode==='programs' && isset($_GET['major'])){
      $sql="SELECT p.label
            FROM form_options p
            JOIN form_options m ON p.parent_value=m.id
            WHERE p.type='program' AND m.type='major' AND m.label=?
            ORDER BY p.label";
      $st=$conn->prepare($sql); $st->bind_param("s",$_GET['major']); $st->execute();
      $rs=$st->get_result(); $out=[]; while($r=$rs->fetch_assoc()) $out[]=$r['label'];
      echo json_encode($out,JSON_UNESCAPED_UNICODE); exit;
    }

    if ($mode==='groups' && isset($_GET['program'])){
      $sql="SELECT g.label
            FROM form_options g
            JOIN form_options p ON g.parent_value=p.id
            WHERE g.type='student_group' AND p.type='program' AND p.label=?
            ORDER BY g.label";
      $st=$conn->prepare($sql); $st->bind_param("s",$_GET['program']); $st->execute();
      $rs=$st->get_result(); $out=[]; while($r=$rs->fetch_assoc()) $out[]=$r['label'];
      echo json_encode($out,JSON_UNESCAPED_UNICODE); exit;
    }

    if ($mode==='curricula' && isset($_GET['major'])){
      // ผูก parent ถ้ามี, ถ้าไม่มี ดึงทุกหลักสูตรเป็น fallback
      $sql="SELECT c.label
            FROM form_options c
            JOIN form_options m ON c.parent_value=m.id
            WHERE c.type='curriculum_name' AND m.type='major' AND m.label=?
            ORDER BY c.label";
      $st=$conn->prepare($sql); $st->bind_param("s",$_GET['major']); $st->execute();
      $rs=$st->get_result(); $out=[]; while($r=$rs->fetch_assoc()) $out[]=$r['label']; $st->close();
      if (count($out)===0) $out = $labels_by_type($conn,'curriculum_name');
      echo json_encode($out,JSON_UNESCAPED_UNICODE); exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Unknown json endpoint'],JSON_UNESCAPED_UNICODE); exit;
  }catch(Throwable $e){
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); exit;
  }
}

/* ---------- LOAD CURRENT ---------- */
$message=''; $error=''; $student=[];

$sql = "SELECT
          p.id AS personal_id, p.full_name, p.birthdate, p.gender, p.citizen_id,
          p.address, p.phone, p.email, p.profile_picture,
          e.student_id, e.faculty, e.major, e.program, e.education_level,
          e.curriculum_name, e.program_type, e.curriculum_year,
          e.student_group, e.gpa, e.education_term, e.education_year
        FROM personal_info p
        INNER JOIN education_info e ON p.id = e.personal_id
        WHERE e.student_id = ?
        LIMIT 1";
$st=$conn->prepare($sql); $st->bind_param('s',$student_id); $st->execute();
$res=$st->get_result();
if ($res && $res->num_rows===1) $student=$res->fetch_assoc(); else $error="ไม่พบข้อมูลนักศึกษาในระบบ";
$st->close();

/* ---------- POST (UPDATE) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && empty($error)) {
  try{
    $full_name       = trim($_POST['full_name'] ?? '');
    $birthdate       = trim($_POST['birthdate'] ?? '');
    $gender          = trim($_POST['gender'] ?? '');
    $citizen_id      = trim($_POST['citizen_id'] ?? '');
    $address         = trim($_POST['address'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $email           = trim($_POST['email'] ?? '');

    $faculty         = trim($_POST['faculty'] ?? '');
    $major           = trim($_POST['major'] ?? '');
    $program         = trim($_POST['program'] ?? '');
    $education_level = trim($_POST['education_level'] ?? '');
    $curriculum_name = trim($_POST['curriculum_name'] ?? '');
    $program_type    = trim($_POST['program_type'] ?? '');
    $curriculum_year = be_from_input($_POST['curriculum_year'] ?? ''); // พ.ศ.
    $education_year  = be_from_input($_POST['education_year'] ?? '');  // พ.ศ.
    $student_group   = trim($_POST['student_group'] ?? '');
    $gpa_in          = trim($_POST['gpa'] ?? '');
    $education_term  = trim($_POST['education_term'] ?? '');           // <-- เก็บตามที่เลือกจากฐานข้อมูล (ไม่ normalize)

    $validation = [];
    if ($full_name==='') $validation[]="กรุณากรอกชื่อ-นามสกุล";
    if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) $validation[]="อีเมลไม่ถูกต้อง";
    if ($citizen_id!=='' && (!ctype_digit($citizen_id) || strlen($citizen_id)!==13)) $validation[]="บัตรประชาชนต้องเป็นตัวเลข 13 หลัก";
    if ($phone!=='' && !preg_match('/^[0-9+\-\s().]+$/',$phone)) $validation[]="เบอร์โทรศัพท์ไม่ถูกต้อง";
    if ($gpa_in!=='' && (!is_numeric($gpa_in) || $gpa_in<0 || $gpa_in>4)) $validation[]="GPA ต้องอยู่ระหว่าง 0—4";
    if ($curriculum_year!=='' && !preg_match('/^\d{4}$/',$curriculum_year)) $validation[]="ปีหลักสูตรควรเป็น พ.ศ. 4 หลัก เช่น 2565";
    if ($education_year!==''  && !preg_match('/^\d{4}$/',$education_year))  $validation[]="ปีการศึกษาควรเป็น พ.ศ. 4 หลัก เช่น 2567";

    /* Upload รูป (ถ้ามี) */
    $profile_picture = $student['profile_picture'] ?? null;
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR,0775,true);
    if (!is_writable(UPLOAD_DIR)) $validation[]="โฟลเดอร์อัปโหลดไม่สามารถเขียนได้: ".UPLOAD_DIR;

    if (!empty($_FILES['profile_picture']['name'])) {
      $f=$_FILES['profile_picture'];
      if ($f['error']!==UPLOAD_ERR_OK){
        $map=[1=>"ไฟล์ใหญ่เกิน server",2=>"ไฟล์ใหญ่เกิน form",3=>"อัปโหลดได้บางส่วน",4=>"ไม่ได้เลือกไฟล์",6=>"ไม่มี tmp",7=>"เขียนดิสก์ไม่ได้",8=>"ถูก extension บล็อก"];
        $validation[]="อัปโหลดรูปไม่สำเร็จ: ".($map[$f['error']]??"error ".$f['error']);
      }else{
        $tmp=$f['tmp_name']; $size=$f['size']; $max=2*1024*1024; if ($size>$max) $validation[]="ไฟล์รูปต้องไม่เกิน 2MB";
        $ok=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
        $mime=null; if (class_exists('finfo')) $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $ext=null;
        if ($mime && isset($ok[$mime])) $ext=$ok[$mime];
        else { $g=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION)); if (in_array($g,['jpg','jpeg','png','gif','webp'])) $ext=($g==='jpeg')?'jpg':$g; else $validation[]="ไฟล์รูปต้องเป็น JPG, PNG, GIF หรือ WEBP"; }
        if (empty($validation)){
          $newname=$student_id.'_'.time().'.'.$ext; $dest=rtrim(UPLOAD_DIR,'/').'/'.$newname;
          if (move_uploaded_file($tmp,$dest)){
            if (!empty($profile_picture)) { $old=rtrim(UPLOAD_DIR,'/').'/'.$profile_picture; if (is_file($old)) @unlink($old); }
            $profile_picture=$newname;
          } else $validation[]="ย้ายไฟล์รูปไปยังปลายทางไม่สำเร็จ";
        }
      }
    }
    if (!empty($validation)) throw new Exception(implode("<br>",$validation));

    $gpa_str = ($gpa_in==='') ? '' : (string)floatval($gpa_in);
    $conn->begin_transaction();

    $sql1="UPDATE personal_info
           SET full_name=?, birthdate=?, gender=?, citizen_id=?, address=?, phone=?, email=?, profile_picture=?
           WHERE id=?";
    $st1=$conn->prepare($sql1);
    $st1->bind_param('ssssssssi',$full_name,$birthdate,$gender,$citizen_id,$address,$phone,$email,$profile_picture,$student['personal_id']);
    $st1->execute(); $st1->close();

    // บันทึก education_year + education_term ตามค่าที่ผู้ใช้เลือก (มาจากฐานข้อมูล)
    $sql2="UPDATE education_info
           SET faculty=?, major=?, program=?, education_level=?, curriculum_name=?,
               program_type=?, curriculum_year=?, education_year=?, student_group=?,
               gpa=NULLIF(?, ''), education_term=?
           WHERE personal_id=?";
    $st2=$conn->prepare($sql2);
    $st2->bind_param('sssssssssssi',
        $faculty, $major, $program, $education_level, $curriculum_name,
        $program_type, $curriculum_year, $education_year, $student_group,
        $gpa_str, $education_term, $student['personal_id']
    );
    $st2->execute(); $st2->close();

    $conn->commit();
    $message="บันทึกข้อมูลเรียบร้อยแล้ว";

    // reload
    $st=$conn->prepare($sql); $st->bind_param('s',$student_id); $st->execute();
    $res=$st->get_result(); $student=$res->fetch_assoc(); $st->close();

  }catch(Throwable $e){
    if ($conn->errno) $conn->rollback();
    $error=$e->getMessage();
  }
}

/* ---------- AVATAR ---------- */
$profile_picture_src='';
if (!empty($student['profile_picture']) && is_file(UPLOAD_DIR.$student['profile_picture'])) {
  $profile_picture_src = PUBLIC_UPLOAD_BASE.rawurlencode($student['profile_picture']);
} else {
  $g=mb_strtolower($student['gender']??'');
  $bg = ($g==='ชาย'||$g==='male'||$g==='ม') ? '3498db' : (($g==='หญิง'||$g==='female'||$g==='ฟ') ? 'e91e63' : '9b59b6');
  $nm = $student['full_name'] ?? 'Student';
  $profile_picture_src='https://ui-avatars.com/api/?name='.rawurlencode($nm).'&background='.$bg.'&color=ffffff&size=160&rounded=true';
}

/* ---------- UI values (ใช้ค่าจากฐานข้อมูลตรง ๆ ) ---------- */
$term_ui    = (string)($student['education_term'] ?? '');
$curyear_ui = (string)($student['curriculum_year'] ?? '');
$eduyear_ui = (string)($student['education_year']  ?? '');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>แก้ไขข้อมูลส่วนตัว</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--ink:#0f172a;--muted:#64748b;--bg:#f7f8fb;--card:#fff;--line:#e5e7eb;--brand:#2563eb;--success:#10b981;--warning:#f59e0b;--info:#6366f1;--danger:#ef4444;--radius:12px}
*{box-sizing:border-box} html,body{height:100%}
body{margin:0;background:var(--bg);color:var(--ink);font-family:'Sarabun',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;line-height:1.65;font-size:16px}
.topbar{background:#fff;border-bottom:1px solid var(--line);padding:14px 16px;position:sticky;top:0;z-index:10}
.topbar-inner{max-width:1100px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px}
.brand{font-weight:700} .userbox{display:flex;align-items:center;gap:12px} .user-name{font-weight:600} .userbox small{color:var(--muted)}
.logout-btn{background:#e5e7eb;color:var(--ink);border:none;padding:8px 15px;border-radius:var(--radius);text-decoration:none;font-size:14px;transition:.2s}
.logout-btn:hover{background:var(--danger);color:#fff}
.container{max-width:1100px;margin:22px auto;padding:0 16px}
h1{font-size:24px;margin:0 0 4px;font-weight:700} .p-sub{color:var(--muted);margin:0 0 14px}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 18px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border:1px solid var(--line);background:#fff;border-radius:var(--radius);text-decoration:none;color:var(--ink);font-weight:500;transition:.2s;cursor:pointer;font-size:14px}
.btn:hover{background:#f9fafb;transform:translateY(-1px)} .btn-success{border-left:3px solid var(--success)} .btn-warning{border-left:3px solid var(--warning)} .btn-info{border-left:3px solid var(--info)}
.btn-primary{background:var(--brand);color:#fff;border-color:var(--brand)} .btn-primary:hover{background:#1d4ed8;color:#fff} .btn-danger{background:var(--danger);border-color:#fecaca;color:#991b1b}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:0 4px 6px rgba(0,0,0,.05);margin-bottom:20px;padding:24px}
.alert{padding:12px 16px;border-radius:var(--radius);margin:16px 0;border-left:4px solid;font-weight:500}
.alert-success{background:#ecfdf5;border-color:var(--success);color:#065f46} .alert-danger{background:#fef2f2;border-color:var(--danger);color:#991b1b}
.form-row{display:grid;grid-template-columns:repeat(12,1fr);gap:16px;margin-bottom:16px}
.col-12{grid-column:span 12}.col-6{grid-column:span 6}.col-4{grid-column:span 4}.col-3{grid-column:span 3}
.label{display:block;font-weight:600;margin-bottom:6px;color:var(--ink)}
.input,.select,textarea{width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:var(--radius);background:#fff;font-size:15px;font-family:inherit;transition:.2s}
.input:focus,.select:focus,textarea:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
textarea{resize:vertical;min-height:100px}
.profile-wrap{display:grid;grid-template-columns:220px 1fr;gap:26px;margin-bottom:24px}
.profile-image-section{text-align:center}
.avatar{width:170px;height:170px;border-radius:50%;object-fit:cover;border:4px solid #f1f5f9;box-shadow:0 8px 16px rgba(0,0,0,.1);margin-bottom:16px}
.file-upload-area{margin-top:10px}.file-note{color:var(--muted);font-size:13px;margin-top:8px;line-height:1.4}
.section-title{font-weight:600;color:var(--brand);margin:24px 0 16px;font-size:18px;display:flex;align-items:center;gap:8px;padding-bottom:8px;border-bottom:2px solid var(--brand)}
.actions-bar{display:flex;gap:10px;justify-content:flex-end;margin-top:24px;padding-top:20px;border-top:1px solid var(--line)}
@media (max-width:900px){.col-6,.col-4,.col-3{grid-column:span 12}.profile-wrap{grid-template-columns:1fr;text-align:center}.avatar{width:140px;height:140px}}
@media (max-width:768px){.container{padding:0 12px}h1{font-size:20px;text-align:center}.p-sub{text-align:center}.actions{justify-content:center}.btn{flex:1;justify-content:center;min-width:120px}.userbox small{display:none}.topbar-inner{flex-direction:column;gap:8px}.card{padding:16px}.actions-bar{flex-direction:column;gap:8px}.actions-bar .btn{width:100%}}
</style>
</head>
<body>
  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-inner">
      <div class="brand">ระบบแนะนำรายวิชาชีพเลือกด้วยต้นไม้ตัดสินใจ</div>
      <div class="userbox">
        <div>
          <div class="user-name"><?= h($student['full_name'] ?? 'นักศึกษา') ?></div>
          <small>รหัสนักศึกษา: <?= h($student_id) ?></small>
        </div>
        <a href="logout.php" class="logout-btn">ออกจากระบบ</a>
      </div>
    </div>
  </div>

  <div class="container">
    <h1>แก้ไขข้อมูลส่วนตัว</h1>
    <p class="p-sub">อัปเดตข้อมูลส่วนตัวและข้อมูลการศึกษาของคุณ</p>
     <div class="actions">
            <a href="student_dashboard.php" class="btn btn-success">
                กลับหน้าหลัก
            </a>
            <a href="history.php" class="btn btn-warning">
                ประวัติการใช้งาน
            </a>
            <a href="quiz.php" class="btn btn-info">
                ทำแบบทดสอบ
            </a>
        </div>

    <?php if (!empty($message)): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <div class="card">
      <form method="POST" enctype="multipart/form-data" id="profile-form" novalidate>
        <div class="profile-wrap">
          <div class="profile-image-section">
            <img src="<?= h($profile_picture_src) ?>" class="avatar" alt="รูปโปรไฟล์" id="profile-preview">
            <div class="file-upload-area">
              <label for="profile_picture" class="btn" style="cursor:pointer;">📷 เลือกรูปใหม่</label>
              <input type="file" id="profile_picture" name="profile_picture" accept="image/*" style="display:none;">
              <div class="file-note">รองรับ: JPG, PNG, GIF, WEBP (≤ 2MB)</div>
            </div>
          </div>

          <div class="profile-form">
            <h2 class="section-title">👤 ข้อมูลส่วนตัว</h2>

            <div class="form-row">
              <div class="col-12">
                <label class="label">ชื่อ-นามสกุล *</label>
                <input type="text" name="full_name" class="input" value="<?= h($student['full_name'] ?? '') ?>" required>
              </div>
            </div>

            <div class="form-row">
              <div class="col-6">
                <label class="label">วันเดือนปีเกิด</label>
                <input type="date" name="birthdate" class="input" value="<?= h($student['birthdate'] ?? '') ?>">
              </div>
              <div class="col-6">
                <label class="label">เพศ</label>
                <select name="gender" class="select">
                  <option value="">เลือกเพศ</option>
                  <option value="ชาย"   <?= (($student['gender']??'')==='ชาย')?'selected':''; ?>>ชาย</option>
                  <option value="หญิง" <?= (($student['gender']??'')==='หญิง')?'selected':''; ?>>หญิง</option>
                </select>
              </div>
            </div>

            <div class="form-row">
              <div class="col-12">
                <label class="label">รหัสบัตรประชาชน</label>
                <input type="text" name="citizen_id" class="input" value="<?= h($student['citizen_id'] ?? '') ?>" maxlength="13" pattern="[0-9]{13}">
              </div>
            </div>

            <div class="form-row">
              <div class="col-12">
                <label class="label">ที่อยู่</label>
                <textarea name="address" class="input"><?= h($student['address'] ?? '') ?></textarea>
              </div>
            </div>

            <div class="form-row">
              <div class="col-6">
                <label class="label">เบอร์โทรศัพท์</label>
                <input type="tel" name="phone" class="input" value="<?= h($student['phone'] ?? '') ?>">
              </div>
              <div class="col-6">
                <label class="label">อีเมล</label>
                <input type="email" name="email" class="input" value="<?= h($student['email'] ?? '') ?>">
              </div>
            </div>

            <h2 class="section-title">🎓 ข้อมูลการศึกษา</h2>

            <!-- แถว 1: คณะ / สาขา -->
            <div class="form-row">
              <div class="col-6">
                <label class="label">คณะ</label>
                <select name="faculty" id="faculty" class="select"><option value="">— เลือกคณะ —</option></select>
              </div>
              <div class="col-6">
                <label class="label">สาขา</label>
                <select name="major" id="major" class="select" disabled><option value="">— เลือกสาขา —</option></select>
              </div>
            </div>

            <!-- แถว 2: สาขาวิชา / ระดับการศึกษา -->
            <div class="form-row">
              <div class="col-6">
                <label class="label">สาขาวิชา</label>
                <select name="program" id="program" class="select" disabled><option value="">— เลือกสาขาวิชา —</option></select>
              </div>
              <div class="col-6">
                <label class="label">ระดับการศึกษา</label>
                <select name="education_level" id="education_level" class="select"><option value="">— เลือกระดับการศึกษา —</option></select>
              </div>
            </div>

            <!-- แถว 3: หลักสูตร / ประเภทหลักสูตร -->
            <div class="form-row">
              <div class="col-6">
                <label class="label">หลักสูตร</label>
                <select name="curriculum_name" id="curriculum_name" class="select" disabled><option value="">— เลือกหลักสูตร —</option></select>
              </div>
              <div class="col-6">
                <label class="label">ประเภทหลักสูตร</label>
                <select name="program_type" id="program_type" class="select"><option value="">— เลือกประเภทหลักสูตร —</option></select>
              </div>
            </div>

            <!-- แถว 4: ปีหลักสูตร (พ.ศ.) / กลุ่มนักศึกษา -->
            <div class="form-row">
              <div class="col-6">
                <label class="label">ปีหลักสูตร (พ.ศ.)</label>
                <input type="text" name="curriculum_year" class="input" value="<?= h($curyear_ui) ?>" pattern="[0-9]{4}" maxlength="4" placeholder="เช่น 2565">
              </div>
              <div class="col-6">
                <label class="label">กลุ่มนักศึกษา</label>
                <select name="student_group" id="student_group" class="select" disabled><option value="">— เลือกกลุ่มนักศึกษา —</option></select>
              </div>
            </div>

            <!-- แถว 5: GPA / ภาคการศึกษา (จาก DB เท่านั้น) -->
            <div class="form-row">
              <div class="col-6">
                <label class="label">เกรดเฉลี่ยรวม (GPA)</label>
                <input type="number" name="gpa" class="input" value="<?= h($student['gpa'] ?? '') ?>" step="0.01" min="0" max="4" placeholder="0.00 – 4.00">
              </div>
              <div class="col-6">
                <label class="label">ภาคการศึกษา</label>
                <select name="education_term" id="education_term" class="select"><option value="">— เลือกภาคการศึกษา —</option></select>
              </div>
            </div>

            <!-- แถว 6: ปีการศึกษา (พ.ศ.) -->
            <div class="form-row">
              <div class="col-6">
                <label class="label">ปีการศึกษา (พ.ศ.)</label>
                <select name="education_year" id="education_year" class="select"><option value="">— เลือกปีการศึกษา —</option></select>
                <div class="field-note" style="color:#64748b;font-size:13px;margin-top:4px">
                </div>
              </div>
            </div>

          </div><!-- /.profile-form -->
        </div><!-- /.profile-wrap -->

        <div class="actions-bar">
          <button type="button" class="btn" onclick="document.getElementById('profile-form').reset()">รีเซ็ตฟอร์ม</button>
          <button type="submit" class="btn btn-primary" id="submit-btn">บันทึกข้อมูล</button>
        </div>
      </form>
    </div>
  </div>

<script>
/* Utils */
const $ = (q,root=document)=>root.querySelector(q);
function resetSelect(sel, ph){ sel.innerHTML=''; sel.appendChild(new Option(ph||'— เลือก —','')); sel.disabled=true; }
function fillSelect(sel, list, ph){ resetSelect(sel, ph||'— เลือก —'); (list||[]).forEach(v=>sel.appendChild(new Option(v,v))); sel.disabled=(list||[]).length===0; }
async function jget(u){ const r=await fetch(u,{credentials:'same-origin'}); if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }

/* Elements */
const selFaculty=$('#faculty'), selMajor=$('#major'), selProgram=$('#program');
const selCurr=$('#curriculum_name'), selGroup=$('#student_group');
const selEduLevel=$('#education_level'), selProgType=$('#program_type');
const selTerm=$('#education_term'), selEduYear=$('#education_year');

/* Pre from PHP */
const pre = {
  faculty:<?= json_encode((string)($student['faculty'] ?? '')) ?>,
  major:<?= json_encode((string)($student['major'] ?? '')) ?>,
  program:<?= json_encode((string)($student['program'] ?? '')) ?>,
  curriculum_name:<?= json_encode((string)($student['curriculum_name'] ?? '')) ?>,
  student_group:<?= json_encode((string)($student['student_group'] ?? '')) ?>,
  education_level:<?= json_encode((string)($student['education_level'] ?? '')) ?>,
  program_type:<?= json_encode((string)($student['program_type'] ?? '')) ?>,
  education_term:<?= json_encode((string)($term_ui ?? '')) ?>,
  education_year:<?= json_encode((string)($eduyear_ui ?? '')) ?>,
};

/* Bootstrap meta */
async function bootstrapMeta(){
  const meta = await jget('<?= basename(__FILE__) ?>?json=meta');

  fillSelect(selFaculty, meta.faculties, '— เลือกคณะ —');
  fillSelect(selEduLevel, meta.levels, '— เลือกระดับการศึกษา —');
  fillSelect(selProgType, meta.ptypes, '— เลือกประเภทหลักสูตร —');

  // ภาคการศึกษา: ใช้ตามที่ลงทะเบียนในฐานข้อมูลเท่านั้น
  const terms = Array.isArray(meta.terms) ? meta.terms.slice() : [];
  fillSelect(selTerm, terms, '— เลือกภาคการศึกษา —');

  // ปีการศึกษา (พ.ศ.)
  let eduyears = Array.isArray(meta.eduyears) ? meta.eduyears.slice() : [];
  if (eduyears.length===0){
    const now = new Date(); const ce = now.getUTCFullYear(); const be = ce + 543;
    for (let y=be+1; y>=be-6; y--) eduyears.push(String(y));
  }
  fillSelect(selEduYear, eduyears, '— เลือกปีการศึกษา —');

  if (pre.education_level) selEduLevel.value = pre.education_level;
  if (pre.program_type)    selProgType.value = pre.program_type;
  if (pre.education_term)  selTerm.value     = pre.education_term; // set ตรง ๆ
  if (pre.education_year)  selEduYear.value  = pre.education_year;

  if (pre.faculty){ selFaculty.value = pre.faculty; await onFacultyChange(true); }
}

/* Cascades */
async function onFacultyChange(initial=false){
  const fac = selFaculty.value;
  resetSelect(selMajor, fac?'กำลังโหลด...':'— เลือกคณะ —');
  resetSelect(selProgram,'— เลือกสาขาก่อน —'); resetSelect(selCurr,'— เลือกสาขาก่อน —'); resetSelect(selGroup,'— เลือกสาขาวิชาก่อน —');
  if (!fac) return;
  const majors = await jget('<?= basename(__FILE__) ?>?json=majors&faculty='+encodeURIComponent(fac));
  fillSelect(selMajor, majors, '— เลือกสาขา —'); if (initial && pre.major) selMajor.value=pre.major;
  await onMajorChange(initial);
}
async function onMajorChange(initial=false){
  const major = selMajor.value;
  resetSelect(selProgram, major?'กำลังโหลด...':'— เลือกสาขาก่อน —'); resetSelect(selCurr,'— เลือกสาขาก่อน —'); resetSelect(selGroup,'— เลือกสาขาวิชาก่อน —');
  if (!major) return;
  const programs = await jget('<?= basename(__FILE__) ?>?json=programs&major='+encodeURIComponent(major));
  fillSelect(selProgram, programs, '— เลือกสาขาวิชา —'); if (initial && pre.program) selProgram.value=pre.program;

  const curricula = await jget('<?= basename(__FILE__) ?>?json=curricula&major='+encodeURIComponent(major));
  fillSelect(selCurr, curricula, '— เลือกหลักสูตร —'); if (initial && pre.curriculum_name) selCurr.value=pre.curriculum_name;

  await onProgramChange(initial);
}
async function onProgramChange(initial=false){
  const program = selProgram.value;
  resetSelect(selGroup, program?'กำลังโหลด...':'— เลือกสาขาวิชาก่อน —');
  if (!program) return;
  const groups = await jget('<?= basename(__FILE__) ?>?json=groups&program='+encodeURIComponent(program));
  fillSelect(selGroup, groups, '— เลือกกลุ่มนักศึกษา —'); if (initial && pre.student_group) selGroup.value=pre.student_group;
}

/* Init */
  document.addEventListener('DOMContentLoaded', async ()=>{
  await bootstrapMeta();
  selFaculty.addEventListener('change', ()=> onFacultyChange(false));
  selMajor.addEventListener('change',   ()=> onMajorChange(false));
  selProgram.addEventListener('change', ()=> onProgramChange(false));

  // ===== รูปโปรไฟล์ =====
  const fileInput = document.getElementById('profile_picture');
  const preview   = document.getElementById('profile-preview');
  if (fileInput && preview) {
    const originalSrc = preview.getAttribute('src');
    let lastObjectURL = null;

    function resetToOriginal(){
      if (lastObjectURL) { URL.revokeObjectURL(lastObjectURL); lastObjectURL = null; }
      preview.src = originalSrc;
    }

    fileInput.addEventListener('change', (e)=>{
      const file = e.target.files && e.target.files[0];
      if (!file) { resetToOriginal(); return; }

      const okTypes = ['image/jpeg','image/png','image/gif','image/webp'];
      const maxSize = 2 * 1024 * 1024; // 2MB
      if (!okTypes.includes(file.type)) {
        alert('ไฟล์รูปต้องเป็น JPG, PNG, GIF หรือ WEBP');
        fileInput.value = ''; resetToOriginal(); return;
      }
      if (file.size > maxSize) {
        alert('ไฟล์รูปต้องไม่เกิน 2MB');
        fileInput.value = ''; resetToOriginal(); return;
      }

      if (lastObjectURL) URL.revokeObjectURL(lastObjectURL);
      lastObjectURL = URL.createObjectURL(file);
      preview.src = lastObjectURL;

      preview.onload = ()=> {
        if (lastObjectURL) { URL.revokeObjectURL(lastObjectURL); lastObjectURL = null; }
      };
    });

    // ถ้ากดรีเซ็ตฟอร์ม ให้กลับรูปเดิม
    const form = document.getElementById('profile-form');
    if (form) {
      form.addEventListener('reset', ()=>{
        fileInput.value = '';
        resetToOriginal();
      });
    }
  }
});
</script>
</body>
</html>
