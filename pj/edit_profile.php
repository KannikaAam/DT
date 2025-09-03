<?php
session_start();

/* ---------- DB CONNECT ---------- */
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'studentregistration';
$OPTIONS_API = 'course_management.php'; // ชี้ไปไฟล์แอดมินที่มี ajax=meta, majors_by_faculty
$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) die("การเชื่อมต่อล้มเหลว: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

/* ---------- AUTH ---------- */
if (empty($_SESSION['student_id'])) { header("Location: login.php"); exit; }
$student_id = $_SESSION['student_id'];

/* ---------- HELPERS ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** map term เผื่อข้อมูลเก่ามาเป็น "1/2/ฤดูร้อน" → ใช้มาตรฐานเดียวกัน */
function normalize_term_for_ui($v){
  $v = trim((string)$v);
  if ($v==='1') return 'ภาคการศึกษาที่1';
  if ($v==='2') return 'ภาคการศึกษาที่2';
  if ($v==='3' || $v==='ฤดูร้อน') return 'ภาคการศึกษาที่3';
  if ($v==='ภาคการศึกษาที่1' || $v==='ภาคการศึกษาที่2' || $v==='ภาคการศึกษาที่3') return $v;
  return ''; // unknown / ไม่ระบุ
}
/** ทำให้ค่าที่จะบันทึกเป็นรูปแบบมาตรฐานเดียวกัน */
function normalize_term_for_db($v){
  $v = trim((string)$v);
  if (in_array($v, ['ภาคการศึกษาที่1','ภาคการศึกษาที่2','ภาคการศึกษาที่3'], true)) return $v;
  if ($v==='1') return 'ภาคการศึกษาที่1';
  if ($v==='2') return 'ภาคการศึกษาที่2';
  if ($v==='3' || $v==='ฤดูร้อน') return 'ภาคการศึกษาที่3';
  return '';
}

/* ---------- LOAD CURRENT ---------- */
$message = ''; $error = ''; $student = [];

/* แก้เฉพาะตรงนี้: เปลี่ยน e.academic_year → e.education_year (คงทุกอย่างอื่นไว้เหมือนเดิม) */
$sql = "SELECT
          p.id AS personal_id, p.full_name, p.birthdate, p.gender, p.citizen_id,
          p.address, p.phone, p.email, p.profile_picture,
          e.student_id, e.faculty, e.major, e.education_level,
          e.curriculum_name, e.program_type, e.education_year,
          e.student_group, e.gpa, e.student_status, e.education_term, e.education_year
        FROM personal_info p
        INNER JOIN education_info e ON p.id = e.personal_id
        WHERE e.student_id = ?
        LIMIT 1";
$st = $conn->prepare($sql);
$st->bind_param('s', $student_id);
$st->execute();
$res = $st->get_result();
if ($res && $res->num_rows === 1) {
  $student = $res->fetch_assoc();
} else {
  $error = "ไม่พบข้อมูลนักศึกษาในระบบ";
}
$st->close();

/* ---------- POST (UPDATE) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && empty($error)) {
  // รับข้อมูลฟอร์ม
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
  $education_year = trim($_POST['education_year'] ?? '');
  $student_group   = trim($_POST['student_group'] ?? '');
  $gpa_in          = trim($_POST['gpa'] ?? '');
  $student_status          = trim($_POST['student_status'] ?? '');
  $education_term  = normalize_term_for_db($_POST['education_term'] ?? '');
  $education_year  = trim($_POST['education_year'] ?? '');

  // ตรวจความถูกต้องเบื้องต้น
  $validation = [];
  if ($full_name==='') $validation[] = "กรุณากรอกชื่อ-นามสกุล";
  if ($email!=='' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $validation[] = "รูปแบบอีเมลไม่ถูกต้อง";
  if ($citizen_id!=='' && (!ctype_digit($citizen_id) || strlen($citizen_id)!==13)) $validation[] = "รหัสบัตรประชาชนต้องเป็นตัวเลข 13 หลัก";
  if ($phone!=='' && !preg_match('/^[0-9+\-\s().]+$/', $phone)) $validation[] = "เบอร์โทรศัพท์ไม่ถูกต้อง";
  if ($gpa_in!=='' && (!is_numeric($gpa_in) || $gpa_in<0 || $gpa_in>4)) $validation[] = "เกรดเฉลี่ยต้องเป็นตัวเลข 0–4";
  if ($education_year!=='' && !preg_match('/^\d{4}$/', $education_year)) $validation[] = "ปีหลักสูตรไม่ถูกต้อง";

  // อัปโหลดรูปโปรไฟล์ (ถ้ามี)
  $profile_picture = $student['profile_picture'] ?? '';
  if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = __DIR__ . '/uploads/profile_images/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

    $tmp = $_FILES['profile_picture']['tmp_name'];
    $name = $_FILES['profile_picture']['name'];
    $size = $_FILES['profile_picture']['size'];

    // mime/type & ext
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($tmp);
    $ok_mimes = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    if (!isset($ok_mimes[$mime])) $validation[] = "ไฟล์รูปต้องเป็น JPG, PNG, GIF, WEBP";
    if ($size > 5*1024*1024)      $validation[] = "ขนาดไฟล์รูปต้องไม่เกิน 5MB";

    if (empty($validation)) {
      $ext = $ok_mimes[$mime];
      $newname = $student_id.'_'.time().'.'.$ext;
      $dest = $upload_dir.$newname;
      if (move_uploaded_file($tmp, $dest)) {
        // ลบของเก่า (ถ้าเป็นไฟล์ภายในโฟลเดอร์เดียวกัน)
        if (!empty($profile_picture)) {
          $old = $upload_dir.$profile_picture;
          if (is_file($old)) @unlink($old);
        }
        $profile_picture = $newname;
      } else {
        $validation[] = "ไม่สามารถอัปโหลดรูปโปรไฟล์ได้";
      }
    }
  }

  if (empty($validation)) {
    // ทำให้ GPA อนุญาตค่าว่าง (NULL)
    $gpa_str = ($gpa_in==='') ? '' : (string)floatval($gpa_in);

    $conn->begin_transaction();
    try {
      // update personal_info
      $sql1 = "UPDATE personal_info
               SET full_name=?, birthdate=?, gender=?, citizen_id=?, address=?, phone=?, email=?, profile_picture=?
               WHERE id=?";
      $st1 = $conn->prepare($sql1);
      $st1->bind_param('ssssssssi',
        $full_name, $birthdate, $gender, $citizen_id, $address, $phone, $email, $profile_picture,
        $student['personal_id']
      );
      if (!$st1->execute()) throw new Exception('ไม่สามารถอัปเดตข้อมูลส่วนตัวได้');
      $st1->close();

      // update education_info (gpa = NULLIF(?, ''))
      $sql2 = "UPDATE education_info
               SET faculty=?, major=?,program=?, education_level=?, curriculum_name=?,
                   program_type=?, education_year=?, student_group=?,
                   gpa = NULLIF(?, ''), student_status=?, education_term=?, education_year=?
               WHERE personal_id=?";
      $st2 = $conn->prepare($sql2);
      $st2->bind_param('sssssssssssi',
        $faculty, $major, $education_level, $curriculum_name,
        $program_type, $education_year, $student_group,
        $gpa_str, $student_status, $education_term, $education_year,
        $student['personal_id']
      );
      if (!$st2->execute()) throw new Exception('ไม่สามารถอัปเดตข้อมูลการศึกษาได้');
      $st2->close();

      $conn->commit();
      $message = "บันทึกข้อมูลเรียบร้อยแล้ว";

      // reload
      $st = $conn->prepare($sql);
      $st->bind_param('s', $student_id);
      $st->execute(); $res = $st->get_result();
      $student = $res->fetch_assoc();
      $st->close();
    } catch (Throwable $e) {
      $conn->rollback();
      $error = $e->getMessage();
    }
  } else {
    $error = implode("<br>", $validation);
  }
}

/* ---------- AVATAR SRC ---------- */
$profile_picture_src = '';
if (!empty($student['profile_picture']) && is_file(__DIR__.'/uploads/profile_images/'.$student['profile_picture'])) {
  $profile_picture_src = 'uploads/profile_images/'.rawurlencode($student['profile_picture']);
} else {
  $g = mb_strtolower($student['gender'] ?? '');
  $bg = ($g==='ชาย' || $g==='male' || $g==='ม') ? '3498db' : (($g==='หญิง'||$g==='female'||$g==='ฟ') ? 'e91e63' : '9b59b6');
  $nm = $student['full_name'] ?? 'Student';
  $profile_picture_src = 'https://ui-avatars.com/api/?name='.rawurlencode($nm).'&background='.$bg.'&color=ffffff&size=150&font-size=0.6&rounded=true';
}
$default_avatar_src = $profile_picture_src;

/* ---------- ค่า UI ---------- */
$term_ui = normalize_term_for_ui($student['education_term'] ?? '');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>แก้ไขข้อมูลส่วนตัว</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root {
  --primary-color: #3498db;
  --primary-hover: #2980b9;
  --secondary-color: #f1f5f9;
  --secondary-hover: #e2e8f0;
  --success-color: #10b981;
  --success-bg: #f0fdf4;
  --danger-color: #ef4444;
  --danger-bg: #fef2f2;
  --text-primary: #1e293b;
  --text-secondary: #64748b;
  --border-color: #cbd5e1;
  --card-bg: #ffffff;
  --body-bg: #f8fafc;
  --radius: 8px;
  --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
  --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
  --transition: all 0.2s ease-in-out;
}
*, *::before, *::after {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}
body {
  font-family: 'Sarabun', sans-serif;
  background-color: var(--body-bg);
  color: var(--text-primary);
  line-height: 1.6;
}
.navbar {
  background-color: var(--card-bg);
  color: var(--text-primary);
  padding: 1rem 2rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  box-shadow: var(--shadow-sm);
  border-bottom: 1px solid var(--border-color);
}
.navbar-brand {
  font-weight: 700;
  font-size: 1.25rem;
  color: var(--primary-color);
}
.navbar-user {
  font-size: 0.9rem;
  color: var(--text-secondary);
}
.logout-btn {
  background: none;
  border: 1px solid var(--border-color);
  color: var(--primary-color);
  padding: 0.5rem 1rem;
  border-radius: var(--radius);
  text-decoration: none;
  transition: var(--transition);
  margin-left: 1rem;
}
.logout-btn:hover {
  background-color: var(--primary-color);
  color: white;
}
.container {
  max-width: 1100px;
  margin: 2rem auto;
  padding: 0 1.5rem;
}
.page-header {
  margin-bottom: 2rem;
}
.page-header h1 {
  font-size: 2.25rem;
  font-weight: 700;
  margin-bottom: 0.25rem;
}
.page-header p {
  color: var(--text-secondary);
  font-size: 1.1rem;
}
.card {
  background: var(--card-bg);
  border-radius: var(--radius);
  box-shadow: var(--shadow-md);
  padding: 2.5rem;
  margin-top: 1.5rem;
}
.alert {
  padding: 1rem 1.5rem;
  border-radius: var(--radius);
  margin-bottom: 1.5rem;
  border-left: 5px solid;
  font-weight: 500;
}
.alert strong {
  font-weight: 700;
}
.alert-success {
  background-color: var(--success-bg);
  border-color: var(--success-color);
  color: var(--success-color);
}
.alert-danger {
  background-color: var(--danger-bg);
  border-color: var(--danger-color);
  color: var(--danger-color);
}
.btn {
  padding: 0.75rem 1.5rem;
  border-radius: var(--radius);
  cursor: pointer;
  text-decoration: none;
  font-weight: 500;
  display: inline-flex;
  gap: 0.5rem;
  align-items: center;
  border: 1px solid transparent;
  transition: var(--transition);
  font-size: 1rem;
}
.btn-success {
  background-color: var(--success-color);
  color: #fff;
  border-color: var(--success-color);
}
.btn-success:hover {
  background-color: #059669;
}
.btn-secondary {
  background-color: var(--secondary-color);
  color: var(--text-primary);
  border-color: var(--border-color);
}
.btn-secondary:hover {
  background-color: var(--secondary-hover);
}
.btn-danger {
    background-color: var(--danger-color);
    color: #fff;
    border-color: var(--danger-color);
}
.btn-danger:hover {
    background-color: #dc2626;
}
.form-group {
  margin-bottom: 1.25rem;
}
.form-label {
  font-weight: 500;
  margin-bottom: 0.5rem;
  display: block;
  font-size: 0.95rem;
}
.form-control, .form-select {
  width: 100%;
  padding: 0.75rem 1rem;
  border: 1px solid var(--border-color);
  border-radius: var(--radius);
  background-color: var(--card-bg);
  transition: var(--transition);
  font-size: 1rem;
  color: var(--text-primary);
}
.form-control:focus, .form-select:focus {
  outline: none;
  border-color: var(--primary-color);
  box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
}
textarea.form-control {
    min-height: 100px;
    resize: vertical;
}
.form-note {
  font-size: 0.8rem;
  color: var(--text-secondary);
  margin-top: 0.5rem;
}
.form-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 1.5rem;
}
.section-title {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--primary-color);
  margin-top: 2.5rem;
  margin-bottom: 1.5rem;
  padding-bottom: 0.75rem;
  border-bottom: 2px solid var(--primary-color);
  display: flex;
  gap: 0.75rem;
  align-items: center;
}
.profile-section {
  display: grid;
  grid-template-columns: 200px 1fr;
  gap: 3rem;
  align-items: flex-start;
  margin-bottom: 2rem;
}
.profile-image-section {
  text-align: center;
}
.profile-preview {
  width: 160px;
  height: 160px;
  border-radius: 50%;
  object-fit: cover;
  border: 5px solid var(--card-bg);
  box-shadow: var(--shadow-md);
  margin-bottom: 1rem;
  background-color: #eee;
}
.file-input-wrapper {
  position: relative;
  display: inline-block;
}
.file-input-label {
    display: inline-block;
    background-color: var(--primary-color);
    color: #fff;
    padding: 0.6rem 1.2rem;
    border-radius: var(--radius);
    cursor: pointer;
    transition: var(--transition);
}
.file-input-label:hover {
    background-color: var(--primary-hover);
}
.file-input-wrapper input[type=file] {
    position: absolute;
    left: -9999px;
}
#resetImageBtn {
    margin-top: 0.75rem;
    padding: 0.5rem 1rem;
    font-size: 0.9rem;
    background-color: transparent;
    color: var(--danger-color);
    border: 1px solid var(--danger-color);
}
#resetImageBtn:hover {
    background-color: var(--danger-color);
    color: #fff;
}

.form-actions {
  display: flex;
  gap: 1rem;
  justify-content: flex-end;
  margin-top: 2.5rem;
  padding-top: 1.5rem;
  border-top: 1px solid var(--border-color);
}
.action-buttons {
    margin-bottom: 1rem;
}
@media(max-width: 992px) {
    .profile-section {
        grid-template-columns: 1fr;
        text-align: center;
    }
    .profile-image-section {
        margin-bottom: 2rem;
    }
}
@media(max-width: 768px){
  .form-row{grid-template-columns:1fr}
  .navbar { padding: 1rem; }
  .container { padding: 0 1rem; }
  .card { padding: 1.5rem; }
  .page-header h1 { font-size: 1.8rem; }
  .form-actions {
    flex-direction: column;
  }
  .btn {
    width: 100%;
    justify-content: center;
  }
}
</style>
</head>
<body>
  <div class="navbar">
    <div class="navbar-brand">ระบบทะเบียนนักศึกษา</div>
    <div class="navbar-user">
      <span style="margin-right:1rem"><?php echo h($student['full_name'] ?? 'นักศึกษา'); ?> (<?php echo h($student_id); ?>)</span>
      <a href="logout.php" class="logout-btn">ออกจากระบบ</a>
    </div>
  </div>

  <div class="container">
    <div class="page-header">
      <h1>แก้ไขข้อมูลส่วนตัว</h1>
      <p>คุณสามารถอัปเดตข้อมูลส่วนตัวและข้อมูลการศึกษาของคุณได้ที่นี่</p>
    </div>

    <div class="action-buttons">
        <a href="student_dashboard.php" class="btn btn-secondary">
            <span>🏠</span>
            กลับหน้าหลัก
        </a>
    </div>
    
    <?php if($message): ?><div class="alert alert-success"><strong>สำเร็จ!</strong> <?php echo h($message); ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger"><strong>ผิดพลาด!</strong> <?php echo $error; ?></div><?php endif; ?>

    <div class="card">
      <form method="POST" enctype="multipart/form-data">

        <div class="profile-section">
          <div class="profile-image-section">
            <img src="<?php echo h($profile_picture_src); ?>" alt="รูปโปรไฟล์" class="profile-preview" id="profilePreview">
            <div class="file-input-wrapper">
              <input type="file" name="profile_picture" id="profilePicture" accept="image/*" onchange="previewImage(this)">
              <label for="profilePicture" class="file-input-label">
                <span>🖼️</span> เลือกรูปโปรไฟล์
              </label>
            </div>
            <div class="form-note">JPG/PNG/GIF/WEBP (ไม่เกิน 5MB)</div>
            <button type="button" class="btn btn-danger" id="resetImageBtn" style="display:none;">🗑️ ใช้รูปเดิม</button>
          </div>

          <div class="personal-info-section">
            <h3 class="section-title">👤 ข้อมูลส่วนตัว</h3>
            <div class="form-group">
              <label class="form-label" for="full_name">ชื่อ-นามสกุล *</label>
              <input class="form-control" id="full_name" name="full_name" value="<?php echo h($student['full_name'] ?? ''); ?>" required>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="birthdate">วันเดือนปีเกิด</label>
                <input type="date" class="form-control" id="birthdate" name="birthdate" value="<?php echo h($student['birthdate'] ?? ''); ?>">
              </div>
              <div class="form-group">
                <label class="form-label" for="gender">เพศ</label>
                <select class="form-select" id="gender" name="gender">
                  <?php $g=$student['gender']??''; ?>
                  <option value="">ไม่ระบุ</option>
                  <option value="ชาย"   <?php echo ($g==='ชาย')?'selected':''; ?>>ชาย</option>
                  <option value="หญิง"  <?php echo ($g==='หญิง')?'selected':''; ?>>หญิง</option>
                </select>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="citizen_id">รหัสบัตรประชาชน</label>
              <input class="form-control" id="citizen_id" name="citizen_id" value="<?php echo h($student['citizen_id'] ?? ''); ?>" maxlength="13" pattern="[0-9]{13}" placeholder="xxxxxxxxxxxxx">
              <div class="form-note">กรอกตัวเลข 13 หลัก ไม่ต้องมีขีด</div>
            </div>

            <div class="form-group">
              <label class="form-label" for="address">ที่อยู่</label>
              <textarea class="form-control" id="address" name="address" rows="3"><?php echo h($student['address'] ?? ''); ?></textarea>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="phone">เบอร์โทรศัพท์</label>
                <input class="form-control" id="phone" name="phone" value="<?php echo h($student['phone'] ?? ''); ?>">
              </div>
              <div class="form-group">
                <label class="form-label" for="email">อีเมล</label>
                <input type="email" class="form-control" id="email" name="email" value="<?php echo h($student['email'] ?? ''); ?>">
              </div>
            </div>
          </div>
        </div>

        <h3 class="section-title">📚 ข้อมูลการศึกษา</h3>

        <!-- คณะ / สาขา -->
<div class="form-row">
  <div class="form-group">
    <label class="form-label" for="faculty">คณะ</label>
    <select class="form-select" id="faculty" name="faculty"
            data-current="<?php echo h($student['faculty'] ?? ''); ?>">
      <option value="">— เลือกคณะ —</option>
    </select>
  </div>
  <div class="form-group">
    <label class="form-label" for="major">สาขา</label>
    <select class="form-select" id="major" name="major"
            data-current="<?php echo h($student['major'] ?? ''); ?>">
      <option value="">— เลือกสาขา —</option>
    </select>
    <div class="form-note">สาขาจะขึ้นตามคณะที่เลือก</div>
  </div>
</div>
<div class="form-group">
    <label class="form-label" for="program">สาขาวิชา</label>
    <select class="form-select" id="program" name="program"
            data-current="<?php echo h($student['program'] ?? ''); ?>">
      <option value="">— เลือกสาขาวิชา —</option>
    </select>
    <div class="form-note">สาขาวิชาจะขึ้นตามคณะที่เลือก</div>
  </div>
<!-- ระดับ / ชื่อหลักสูตร -->
<div class="form-row">
  <div class="form-group">
    <label class="form-label" for="education_level">ระดับการศึกษา</label>
    <select class="form-select" id="education_level" name="education_level"
            data-current="<?php echo h($student['education_level'] ?? ''); ?>">
      <option value="">— เลือกระดับ —</option>
    </select>
  </div>
  <div class="form-group">
    <label class="form-label" for="curriculum_name">ชื่อหลักสูตร</label>
    <select class="form-select" id="curriculum_name" name="curriculum_name"
            data-current="<?php echo h($student['curriculum_name'] ?? ''); ?>">
      <option value="">— เลือกหลักสูตร —</option>
    </select>
  </div>
</div>

<!-- ประเภทหลักสูตร / ปีหลักสูตร -->
<div class="form-row">
  <div class="form-group">
    <label class="form-label" for="program_type">ประเภทหลักสูตร</label>
    <select class="form-select" id="program_type" name="program_type"
            data-current="<?php echo h($student['program_type'] ?? ''); ?>">
      <option value="">— เลือกประเภท —</option>
    </select>
  </div>
  <div class="form-group">
    <label class="form-label" for="$education_year">ปีหลักสูตร (พ.ศ.)</label>
    <select class="form-select" id="education_year" name="education_year"
            data-current="<?php echo h($student['education_year'] ?? ''); ?>">
      <option value="">— เลือกปีหลักสูตร —</option>
    </select>
  </div>
</div>

<!-- กลุ่มเรียน / GPA -->
<div class="form-row">
  <div class="form-group">
    <label class="form-label" for="student_group">กลุ่มเรียน</label>
    <select class="form-select" id="student_group" name="student_group"
            data-current="<?php echo h($student['student_group'] ?? ''); ?>">
      <option value="">— เลือกกลุ่มเรียน —</option>
    </select>
    <div class="form-note">กลุ่มเรียนจะขึ้นตามหลักสูตร</div>
  </div>
  <div class="form-group">
    <label class="form-label" for="gpa">เกรดเฉลี่ยสะสม (GPA)</label>
    <input type="number" step="0.01" min="0" max="4"
           class="form-control" id="gpa" name="gpa"
           value="<?php echo h($student['gpa'] ?? ''); ?>" placeholder="เช่น 3.50">
    <div class="form-note">0.00–4.00 (เว้นว่างได้หากยังไม่มีข้อมูล)</div>
  </div>
</div>

    <!-- สถานะ / ภาคเรียน -->
    <div class="form-group">
        <label class="form-label" for="student_status">สถานะนักศึกษา</label>
        <select class="form-select" id="student_status" name="student_status"
                data-current="<?php echo h($term_ui); ?>">
        <option value="">— เลือก —</option>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="education_term">ภาคการศึกษาปัจจุบัน</label>
        <select class="form-select" id="education_term" name="education_term"
                data-current="<?php echo h($term_ui); ?>">
        <option value="">— เลือกภาคเรียน —</option>
        </select>
    </div>
    </div>

    <!-- ปีการศึกษา -->
    <div class="form-row">
    <div class="form-group">
        <label class="form-label" for="education_year">ปีการศึกษาปัจจุบัน (พ.ศ.)</label>
        <select class="form-select" id="education_year" name="education_year"
                data-current="<?php echo h($student['education_year'] ?? ''); ?>">
        <option value="">— เลือกปีการศึกษา —</option>
        </select>
    </div>
    <div class="form-group"></div>
    </div>

        <div class="form-actions">
          <a href="student_dashboard.php" class="btn btn-secondary">❌ ยกเลิก</a>
          <button type="submit" class="btn btn-success">💾 บันทึกข้อมูล</button>
        </div>
      </form>
    </div>
  </div>

<script>
const originalAvatarSrc = <?php echo json_encode($default_avatar_src); ?>;
const profilePreview = document.getElementById('profilePreview');
const profilePictureInput = document.getElementById('profilePicture');
const resetImageBtn = document.getElementById('resetImageBtn');

function previewImage(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => {
      profilePreview.src = e.target.result;
    };
    reader.readAsDataURL(input.files[0]);
    resetImageBtn.style.display = 'inline-flex';
  }
}

function resetImage() {
  profilePictureInput.value = '';
  profilePreview.src = originalAvatarSrc;
  resetImageBtn.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', () => {
    // Show reset button only if there's a custom image uploaded initially
    if (profilePreview.src && !profilePreview.src.includes('ui-avatars.com')) {
        // This logic might need adjustment if your default image isn't from ui-avatars
    }
    resetImageBtn.addEventListener('click', resetImage);
});

// Basic client-side validation for required fields
document.querySelector('form').addEventListener('submit', function(e) {
  const fullName = document.getElementById('full_name').value.trim();
  if (!fullName) {
    e.preventDefault();
    alert('กรุณากรอกชื่อ-นามสกุล');
    document.getElementById('full_name').focus();
  }
});

// Input sanitization helpers
document.getElementById('phone').addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9+\-\s().]/g, '');
});
document.getElementById('citizen_id').addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '');
});

</script>
<script>
const OPTIONS_API = <?php echo json_encode($OPTIONS_API); ?>;

// เติม option ให้ select
function fillSelect(sel, items, current = '') {
  sel.innerHTML = '<option value="">— เลือก —</option>';
  (items || []).forEach(it => {
    const opt = document.createElement('option');
    opt.value = it.value;
    opt.textContent = it.label;
    if (current && String(current) === String(it.value)) opt.selected = true;
    sel.appendChild(opt);
  });
}

// โหลด meta ทั้งก้อน: faculties, levels, ptypes, curnames, curyears, groups, statuses, terms
async function loadMeta() {
  const res = await fetch(`${OPTIONS_API}?ajax=meta`, {cache:'no-store'});
  if (!res.ok) throw new Error('โหลดตัวเลือกไม่สำเร็จ');
  return await res.json();
}

// เมื่อเลือกคณะ → โหลดสาขาเฉพาะคณะนั้น
async function loadMajorsByFaculty(facultyValue) {
  const url = `${OPTIONS_API}?ajax=majors_by_faculty&faculty=${encodeURIComponent(facultyValue || '')}`;
  const res = await fetch(url, {cache:'no-store'});
  if (!res.ok) return {majors: []};
  return await res.json();
}

// filter ตาม parent_value (ใช้กรณีหลักสูตร/กลุ่มเรียนถ้าคุณจัดโครงสร้าง parent ใน form_options)
function filterByParent(list, parent) {
  // meta.curnames / meta.groups จาก ?ajax=meta ไม่มี parent_value มาด้วย
  // ถ้าคุณอยากคัดตาม parent จริง ๆ แนะนำเพิ่ม endpoint เหมือน majors_by_faculty
  // ที่นี่จะไม่ filter ถ้า API หลักไม่ได้ส่ง parent_value มา
  return list;
}

document.addEventListener('DOMContentLoaded', async () => {
  const $faculty   = document.getElementById('faculty');
  const $major     = document.getElementById('major');
  const $program   = document.getElementById('program');
  const $level     = document.getElementById('education_level');
  const $curname   = document.getElementById('curriculum_name');
  const $ptype     = document.getElementById('program_type');
  const $curyear   = document.getElementById('education_year');
  const $group     = document.getElementById('student_group');
  const $term      = document.getElementById('education_term');
  const $eduyear   = document.getElementById('education_year');

  const current = {
    faculty:   $faculty?.dataset.current || '',
    major:     $major?.dataset.current || '',
    program:   $program?.dataset.current || '',
    level:     $level?.dataset.current || '',
    curname:   $curname?.dataset.current || '',
    ptype:     $ptype?.dataset.current || '',
    curyear:   $curyear?.dataset.current || '',
    group:     $group?.dataset.current || '',
    term:      $term?.dataset.current || '',
    eduyear:   $eduyear?.dataset.current || ''
  };

  try {
    const meta = await loadMeta();

    // 1) คณะ / ระดับ / ประเภท / หลักสูตร / ปีหลักสูตร / กลุ่ม / ภาคเรียน / ปีการศึกษา
    fillSelect($faculty,  meta.faculties || [], current.faculty);
    fillSelect($level,    meta.levels    || [], current.level);
    fillSelect($ptype,    meta.ptypes    || [], current.ptype);
    fillSelect($curname,  meta.curnames  || [], current.curname);
    fillSelect($curyear,  meta.curyears  || [], current.curyear);
    fillSelect($group,    meta.groups    || [], current.group);
    fillSelect($term,     meta.terms     || [], current.term);
    fillSelect($eduyear,  (meta.education_years || meta.curyears || []), current.eduyear); // ถ้าไม่มี education_years ใช้ curyears แทนชั่วคราว

    // 2) โหลดสาขาตามคณะ (ครั้งแรก)
    if (current.faculty) {
      const {majors} = await loadMajorsByFaculty(current.faculty);
      fillSelect($major, majors || [], current.major);
    }

    // 3) เมื่อเปลี่ยนคณะ → โหลดสาขาใหม่
    $faculty?.addEventListener('change', async function () {
      const fac = this.value;
      const {majors} = await loadMajorsByFaculty(fac);
      fillSelect($major, majors || [], '');
    });

    // (ออปชัน) ถ้าคุณต้องการให้หลักสูตร/กลุ่มเรียน “ตามสาขา/หลักสูตร” จริง ๆ
    // แนะนำเพิ่ม endpoint เช่น:
    //   ?ajax=curriculum_by_major&major=CS
    //   ?ajax=groups_by_curriculum&curriculum=CS61
    // แล้วผูก events เหมือนข้างบน
    // ที่โค้ดนี้ จะดึงทั้งก้อนจาก meta มาก่อน (ไม่ได้ filter ตาม parent)
  } catch (e) {
    console.error(e);
    alert('ไม่สามารถโหลดตัวเลือกจากระบบได้');
  }
});
</script>
</body>
</html>
