<?php
// register.php — ฟอร์มลงทะเบียนนักศึกษา (เวอร์ชัน self-contained)
// - ดึงรายการตัวเลือกผ่าน JSON endpoint ภายในไฟล์นี้เอง (ไม่พึ่ง course_management.php)
// - เก็บค่าจาก dropdown เป็น "ตัวหนังสือ (label)" ทั้งหมด

require __DIR__ . '/db_connect.php'; // ต้องมี $conn = new mysqli(...)

// ============ JSON ENDPOINT (ไม่ต้องล็อกอิน) ============
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');

    // helper สั้นๆ
    function labels_by_type(mysqli $conn, string $type): array {
        $sql = "SELECT label FROM form_options WHERE type=? ORDER BY label";
        $st  = $conn->prepare($sql);
        $st->bind_param("s", $type);
        $st->execute();
        $rs  = $st->get_result();
        $out = [];
        while ($row = $rs->fetch_assoc()) $out[] = $row['label'];
        return $out;
    }

    $mode = $_GET['json'];

    if ($mode === 'meta') {
        echo json_encode([
            'faculties' => labels_by_type($conn, 'faculty'),
            'levels'    => labels_by_type($conn, 'education_level'),
            'ptypes'    => labels_by_type($conn, 'program_type'),
            'curnames'  => labels_by_type($conn, 'curriculum_name'),
            'curyears'  => labels_by_type($conn, 'curriculum_year'),
            'terms'     => labels_by_type($conn, 'education_term'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'majors' && isset($_GET['faculty'])) {
        $faculty = trim($_GET['faculty']);
        // child = major (parent_value = id ของ faculty ที่ label = ?)
        $sql = "
            SELECT m.label
            FROM form_options m
            JOIN form_options f ON m.parent_value = f.id
            WHERE m.type='major' AND f.type='faculty' AND f.label=?
            ORDER BY m.label
        ";
        $st = $conn->prepare($sql);
        $st->bind_param("s", $faculty);
        $st->execute();
        $rs = $st->get_result();
        $out=[];
        while ($r = $rs->fetch_assoc()) $out[] = $r['label'];
        echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
    }

    if ($mode === 'programs' && isset($_GET['major'])) {
        $major = trim($_GET['major']);
        $sql = "
            SELECT p.label
            FROM form_options p
            JOIN form_options m ON p.parent_value = m.id
            WHERE p.type='program' AND m.type='major' AND m.label=?
            ORDER BY p.label
        ";
        $st = $conn->prepare($sql);
        $st->bind_param("s", $major);
        $st->execute();
        $rs = $st->get_result();
        $out=[];
        while ($r = $rs->fetch_assoc()) $out[] = $r['label'];
        echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
    }

    if ($mode === 'groups' && isset($_GET['program'])) {
        $program = trim($_GET['program']);
        $sql = "
            SELECT g.label
            FROM form_options g
            JOIN form_options p ON g.parent_value = p.id
            WHERE g.type='student_group' AND p.type='program' AND p.label=?
            ORDER BY g.label
        ";
        $st = $conn->prepare($sql);
        $st->bind_param("s", $program);
        $st->execute();
        $rs = $st->get_result();
        $out=[];
        while ($r = $rs->fetch_assoc()) $out[] = $r['label'];
        echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'bad request'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============ โค้ดเดิม: รับ POST และบันทึก ============
$error_message = '';
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // --- รับข้อมูล ---
        $full_name       = trim($_POST['full_name'] ?? '');
        $birthdate       = !empty($_POST['birthdate']) ? trim($_POST['birthdate']) : null;
        $gender          = trim($_POST['gender'] ?? '');
        $citizen_id      = trim($_POST['citizen_id'] ?? '');
        $address         = trim($_POST['address'] ?? '');
        $phone           = trim($_POST['phone'] ?? '');
        $email           = trim($_POST['email'] ?? '');

        // การศึกษา (ทั้งหมดเป็น label)
        $faculty         = trim($_POST['faculty'] ?? '');
        $major           = trim($_POST['major'] ?? '');
        $program         = trim($_POST['program'] ?? '');
        $education_level = trim($_POST['education_level'] ?? '');
        $student_id      = trim($_POST['student_id'] ?? '');
        $curriculum_name = trim($_POST['curriculum_name'] ?? '');
        $program_type    = trim($_POST['program_type'] ?? '');
        $curriculum_year = trim($_POST['curriculum_year'] ?? '');
        $student_group   = trim($_POST['student_group'] ?? '');
        $gpa             = isset($_POST['gpa']) && $_POST['gpa'] !== '' ? (float)$_POST['gpa'] : null;
        $student_status  = 'กำลังศึกษา';
        $education_term  = trim($_POST['education_term'] ?? '');
        $education_year  = trim($_POST['education_year'] ?? '');
        $password        = $_POST['password'] ?? '';
        $password_confirm= $_POST['password_confirm'] ?? '';

        if ($full_name === '' || $student_id === '' || $password === '') {
            throw new Exception("กรุณากรอก: ชื่อ-นามสกุล, รหัสนักศึกษา, รหัสผ่าน");
        }
        if ($student_id !== '' && !ctype_digit($student_id)) throw new Exception("รหัสนักศึกษาต้องเป็นตัวเลขเท่านั้น");
        if ($phone !== '') {
            if (!ctype_digit($phone)) throw new Exception("เบอร์โทรต้องเป็นตัวเลขเท่านั้น");
            if (strlen($phone) < 9 || strlen($phone) > 10) throw new Exception("เบอร์โทรควรมีความยาว 9-10 หลัก");
        }
        if ($citizen_id !== '') {
            if (!ctype_digit($citizen_id)) throw new Exception("เลขบัตรประชาชนต้องเป็นตัวเลขเท่านั้น");
            if (strlen($citizen_id) !== 13) throw new Exception("เลขบัตรประชาชนต้องมี 13 หลัก");
        }
        if ($gpa !== null && ($gpa < 0 || $gpa > 4)) throw new Exception("กรุณากรอก GPA ระหว่าง 0 - 4");
        if ($password_confirm === '' || $password_confirm !== $password) throw new Exception("รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน");

        // ตรวจซ้ำรหัสนักศึกษา
        $check_sql = "SELECT student_id FROM education_info WHERE student_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $student_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows > 0) throw new Exception("รหัสนักศึกษา '$student_id' นี้มีอยู่ในระบบแล้ว");

        $conn->begin_transaction();

        // personal_info
        $sql_personal = "INSERT INTO personal_info (full_name, birthdate, gender, citizen_id, address, phone, email)
                         VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt_personal = $conn->prepare($sql_personal);
        $stmt_personal->bind_param("sssssss", $full_name, $birthdate, $gender, $citizen_id, $address, $phone, $email);
        if (!$stmt_personal->execute()) throw new Exception("ไม่สามารถบันทึกข้อมูลส่วนตัวได้: " . $stmt_personal->error);
        $personal_id = $conn->insert_id;

        // education_info (เก็บ label ทั้งหมด)
        $sql_education = "INSERT INTO education_info (
            personal_id, student_id, faculty, major, program, education_level,
            curriculum_name, program_type, curriculum_year, student_group,
            gpa, student_status, education_term, education_year
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_education = $conn->prepare($sql_education);
        $stmt_education->bind_param(
            "isssssssssdsss",
            $personal_id, $student_id, $faculty, $major, $program, $education_level,
            $curriculum_name, $program_type, $curriculum_year, $student_group,
            $gpa, $student_status, $education_term, $education_year
        );
        if (!$stmt_education->execute()) throw new Exception("ไม่สามารถบันทึกข้อมูลการศึกษาได้: " . $stmt_education->error);

        // user_login
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $sql_login = "INSERT INTO user_login (username, student_id, password) VALUES (?, ?, ?)";
        $stmt_login = $conn->prepare($sql_login);
        $stmt_login->bind_param("sss", $student_id, $student_id, $hashed_password);
        if (!$stmt_login->execute()) throw new Exception("ไม่สามารถบันทึกข้อมูลการเข้าสู่ระบบได้: " . $stmt_login->error);

      $conn->commit();

      if (session_status() === PHP_SESSION_NONE) { session_start(); }
      $_SESSION['loggedin']    = true;
      $_SESSION['user_type']   = 'student';
      $_SESSION['student_id']  = $student_id;
      $_SESSION['personal_id'] = $personal_id;
      $_SESSION['username']    = $student_id;
      $_SESSION['full_name']   = $full_name;
header('Location: student_dashboard.php');
exit;

    } catch (Exception $e) {
        $conn->rollback();
        $error_message = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ลงทะเบียนนักศึกษา</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
<style>
:root { --primary-color:#007bff; --primary-hover:#0056b3; --secondary-color:#6c757d; --success-color:#28a745; --danger-color:#dc3545; --text-primary:#212529; --text-secondary:#6c757d; --border-color:#dee2e6; --body-bg:#f4f7f9; --card-bg:#ffffff; --radius:8px; --shadow:0 4px 15px rgba(0,0,0,.08); --transition:all .2s ease-in-out; }
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Sarabun',sans-serif;background:var(--body-bg);color:var(--text-primary);display:flex;justify-content:center;align-items:center;min-height:100vh;padding:2rem 1rem}
.container{max-width:900px;width:100%;background:var(--card-bg);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
.header{background:linear-gradient(135deg,var(--primary-color) 0%,#0056b3 100%);color:#fff;padding:2.5rem 2rem;text-align:center}
.header h1{font-size:2rem;font-weight:700;margin-bottom:.5rem}
.header p{opacity:.9}
.form-container{padding:2.5rem}
.message{margin-bottom:1.5rem;padding:1rem 1.5rem;border-radius:var(--radius);border-left:5px solid;font-weight:500}
.success{background:#e9f7ef;color:var(--success-color);border-color:var(--success-color)}
.error{background:#fbebed;color:var(--danger-color);border-color:var(--danger-color)}
.form-group{margin-bottom:1.25rem;position:relative}
.form-group label{display:block;margin-bottom:.5rem;font-weight:500;font-size:.95rem}
.form-control{width:100%;padding:.75rem 1rem;border:1px solid var(--border-color);border-radius:var(--radius);font-size:1rem;transition:var(--transition);background:#fff}
.form-control:focus{outline:none;border-color:var(--primary-color);box-shadow:0 0 0 3px rgba(0,123,255,.2)}
.form-control:disabled{background:#e9ecef;cursor:not-allowed}
.form-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:1.5rem}
.section-title{font-size:1.5rem;font-weight:700;color:var(--primary-color);margin-top:2.5rem;margin-bottom:1.5rem;padding-bottom:.75rem;border-bottom:2px solid var(--primary-color)}
.btn{padding:.8rem 1.75rem;border:none;border-radius:var(--radius);font-size:1rem;font-weight:500;cursor:pointer;transition:var(--transition);display:inline-flex;align-items:center;justify-content:center;gap:.5rem;text-decoration:none}
.btn-primary{background:var(--primary-color);color:#fff}
.btn-primary:hover{background:var(--primary-hover);transform:translateY(-2px);box-shadow:0 4px 8px rgba(0,0,0,.1)}
.btn-primary:disabled{background:#a0c9f5;cursor:not-allowed;transform:none;box-shadow:none}
.btn-secondary{background:#f8f9fa;color:var(--text-primary);border:1px solid var(--border-color)}
.btn-secondary:hover{background:#e9ecef}
.form-actions{display:flex;justify-content:flex-end;gap:1rem;margin-top:2.5rem;padding-top:1.5rem;border-top:1px solid #e9ecef}
.password-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; font-size: 18px; color: #666; }
.password-toggle:hover { color: #000; }
.hint{font-size:.9rem;color:var(--text-secondary);margin-top:.25rem}
@media (max-width:768px){ body{padding:1rem .5rem} .form-container,.header{padding:1.5rem} .form-actions{flex-direction:column-reverse} .btn{width:100%} }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>ลงทะเบียนนักศึกษา</h1>
    <p>กรอกข้อมูลเพื่อสร้างบัญชีผู้ใช้สำหรับเข้าสู่ระบบแนะนำรายวิชาชีพเลือกด้วยต้นไม้ตัดสินใจ</p>
  </div>

  <div class="form-container">
    <?php if (!empty($success_message)): ?>
      <div class="message success"><strong>สำเร็จ!</strong> <?php echo $success_message; ?><br><small>กำลังนำคุณไปยังหน้าเข้าสู่ระบบใน 3 วินาที...</small></div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
      <div class="message error"><strong>เกิดข้อผิดพลาด:</strong> <?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <?php if (empty($success_message)): ?>
    <form method="POST" action="" id="regForm" novalidate>
      <h2 class="section-title">ข้อมูลส่วนตัว</h2>
      <div class="form-group">
        <label for="full_name">ชื่อ-นามสกุล *</label>
        <input type="text" id="full_name" name="full_name" class="form-control" required placeholder="เช่น นายสมชาย ใจดี">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="birthdate">วันเดือนปีเกิด</label>
          <input type="date" id="birthdate" name="birthdate" class="form-control">
        </div>
        <div class="form-group">
          <label for="gender">เพศ</label>
          <select id="gender" name="gender" class="form-control">
            <option value=""> เลือกเพศ </option>
            <option value="ชาย">ชาย</option>
            <option value="หญิง">หญิง</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label for="citizen_id">เลขบัตรประชาชน</label>
        <input type="text" id="citizen_id" name="citizen_id" class="form-control" maxlength="13" pattern="\d{13}" inputmode="numeric" autocomplete="off" placeholder="เลข 13 หลัก ไม่ต้องมีขีด">
      </div>
      <div class="form-group">
        <label for="address">ที่อยู่ปัจจุบัน</label>
        <textarea id="address" name="address" class="form-control" rows="3" placeholder="บ้านเลขที่, ถนน, ตำบล, อำเภอ, จังหวัด, รหัสไปรษณีย์"></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="phone">เบอร์โทรศัพท์</label>
          <input type="text" id="phone" name="phone" class="form-control" inputmode="numeric" pattern="\d{9,10}" maxlength="10" autocomplete="off" placeholder="เช่น 0812345678">
        </div>
        <div class="form-group">
          <label for="email">อีเมล</label>
          <input type="email" id="email" name="email" class="form-control" placeholder="เช่น example@email.com">
        </div>
      </div>

      <h2 class="section-title">ข้อมูลการศึกษา</h2>
      <div class="form-row">
        <div class="form-group"><label for="faculty">คณะ</label><select id="faculty" name="faculty" class="form-control"></select></div>
        <div class="form-group"><label for="major">สาขา</label><select id="major" name="major" class="form-control" disabled></select></div>
        <div class="form-group"><label for="program">สาขาวิชา</label><select id="program" name="program" class="form-control" disabled></select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label for="education_level">ระดับการศึกษา</label><select id="education_level" name="education_level" class="form-control"></select></div>
        <div class="form-group">
          <label for="student_id">รหัสนักศึกษา *</label>
          <input type="text" id="student_id" name="student_id" class="form-control" required inputmode="numeric" pattern="\d+" maxlength="15" autocomplete="off" placeholder="กรอกรหัสนักศึกษา (ตัวเลขเท่านั้น)">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label for="curriculum_name">หลักสูตร</label><select id="curriculum_name" name="curriculum_name" class="form-control"></select></div>
        <div class="form-group"><label for="program_type">ประเภทหลักสูตร</label><select id="program_type" name="program_type" class="form-control"></select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label for="curriculum_year">ปีของหลักสูตร</label><select id="curriculum_year" name="curriculum_year" class="form-control"></select></div>
        <div class="form-group"><label for="student_group">กลุ่มเรียน</label><select id="student_group" name="student_group" class="form-control" disabled></select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label for="gpa">เกรดเฉลี่ยสะสม (GPA)</label><input type="number" step="0.01" min="0" max="4" id="gpa" name="gpa" class="form-control" placeholder="ถ้ามี"></div>
        <div class="form-group"><label for="education_term">ภาคการศึกษาแรกเข้า</label><select id="education_term" name="education_term" class="form-control"></select></div>
        <div class="form-group"><label for="education_year">ปีการศึกษาแรกเข้า (พ.ศ.)</label>
          <input type="number" id="education_year" name="education_year" class="form-control" min="2550" max="<?php echo (int)date('Y') + 544; ?>" placeholder="เช่น 2567">
        </div>
      </div>

      <h2 class="section-title">ข้อมูลเข้าสู่ระบบ</h2>
      <div class="form-group">
        <label for="password">ตั้งรหัสผ่าน *</label>
        <div style="position: relative;">
            <input type="password" id="password" name="password" class="form-control" required minlength="8" autocomplete="new-password">
            <span class="password-toggle" onclick="togglePasswordVisibility('password', this)"><i class="fi fi-rr-eye"></i></span>
        </div>
        <div class="hint">อย่างน้อย 8 ตัวอักษร แนะนำผสม A-Z, a-z, 0-9</div>
      </div>
      <div class="form-group">
        <label for="password_confirm">ยืนยันรหัสผ่าน *</label>
        <div style="position: relative;">
            <input type="password" id="password_confirm" name="password_confirm" class="form-control" required autocomplete="new-password">
            <span class="password-toggle" onclick="togglePasswordVisibility('password_confirm', this)"><i class="fi fi-rr-eye"></i></span>
        </div>
        <div id="pwMatchHint" class="hint"></div>
      </div>

      <div class="form-actions">
        <a href="login.php" class="btn btn-secondary">ยกเลิก</a>
        <button type="submit" id="submitBtn" class="btn btn-primary">ลงทะเบียน</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', async () => {
  const submitBtn  = document.getElementById('submitBtn');
  const facEl      = document.getElementById('faculty');
  const majorEl    = document.getElementById('major');
  const programEl  = document.getElementById('program');
  const groupEl    = document.getElementById('student_group');

  // ===== Numeric guards =====
  const onlyDigits = (el, maxLen = null) => {
    el.addEventListener('input', () => {
      const digits = el.value.replace(/\D+/g, '');
      el.value = maxLen ? digits.slice(0, maxLen) : digits;
    });
  };
  onlyDigits(document.getElementById('citizen_id'), 13);
  onlyDigits(document.getElementById('phone'), 10);
  onlyDigits(document.getElementById('student_id'));

  // ===== Password match hint =====
  const pw = document.getElementById('password');
  const pw2 = document.getElementById('password_confirm');
  const pwHint = document.getElementById('pwMatchHint');
  function checkPwMatch(){
    if (!pw.value && !pw2.value) { pwHint.textContent = ''; return; }
    if (pw.value.length < 8) { pwHint.textContent = 'รหัสผ่านควรยาวอย่างน้อย 8 ตัวอักษร'; return; }
    if (pw.value !== pw2.value) { pwHint.textContent = 'รหัสผ่านไม่ตรงกัน'; }
    else { pwHint.textContent = 'รหัสผ่านตรงกัน ✔️'; }
  }
  pw.addEventListener('input', checkPwMatch);
  pw2.addEventListener('input', checkPwMatch);

  // ===== UI helpers =====
  function resetSelect(el, holder='-- เลือก --'){
    el.innerHTML = '';
    el.append(new Option(holder, ''));
    el.disabled = true;
  }
  function fillSelect(el, arr, holder='-- เลือก --'){
    resetSelect(el, holder);
    (arr || []).forEach(x => el.append(new Option(String(x), String(x))));
    el.disabled = (arr || []).length === 0;
  }

  // ===== JSON fetch (ภายในไฟล์นี้เอง) =====
  async function jget(url){
    const r = await fetch(url, { credentials: 'same-origin' });
    if (!r.ok) throw new Error('HTTP '+r.status);
    return r.json();
  }

  async function loadMajorsByFacultyLabel(facultyLabel){
    resetSelect(majorEl, facultyLabel ? ' กำลังโหลด...' : ' โปรดเลือกคณะก่อน ');
    resetSelect(programEl, ' โปรดเลือกสาขาก่อน ');
    resetSelect(groupEl,   ' โปรดเลือกสาขาวิชาก่อน ');
    if (!facultyLabel) return;
    const list = await jget(`register.php?json=majors&faculty=${encodeURIComponent(facultyLabel)}`);
    fillSelect(majorEl, list, ' เลือกสาขา ');
  }
  async function loadProgramsByMajorLabel(majorLabel){
    resetSelect(programEl, majorLabel ? ' กำลังโหลด...' : ' โปรดเลือกสาขาก่อน ');
    resetSelect(groupEl,   ' โปรดเลือกสาขาวิชาก่อน ');
    if (!majorLabel) return;
    const list = await jget(`register.php?json=programs&major=${encodeURIComponent(majorLabel)}`);
    fillSelect(programEl, list, 'เลือกสาขาวิชา ');
  }
  async function loadGroupsByProgramLabel(programLabel){
    resetSelect(groupEl, programLabel ? ' กำลังโหลด...' : ' โปรดเลือกสาขาวิชาก่อน ');
    if (!programLabel) return;
    const list = await jget(`register.php?json=groups&program=${encodeURIComponent(programLabel)}`);
    fillSelect(groupEl, list, ' เลือกกลุ่มเรียน ');
  }

  async function initMeta(){
    submitBtn.disabled = true;
    const saved = submitBtn.innerHTML;
    submitBtn.innerHTML = ' กำลังโหลดข้อมูล...';
    try{
      const meta = await jget('register.php?json=meta');
      fillSelect(facEl, meta.faculties, ' เลือกคณะ ');
      fillSelect(document.getElementById('education_level'), meta.levels, ' เลือกระดับการศึกษา ');
      fillSelect(document.getElementById('program_type'),    meta.ptypes, ' เลือกประเภทหลักสูตร ');
      fillSelect(document.getElementById('curriculum_name'), meta.curnames, ' เลือกหลักสูตร ');
      fillSelect(document.getElementById('curriculum_year'), meta.curyears, ' เลือกปีหลักสูตร ');
      fillSelect(document.getElementById('education_term'),  meta.terms, ' เลือกภาคการศึกษา ');
      // chain เริ่ม
      resetSelect(majorEl,  ' โปรดเลือกคณะก่อน ');
      resetSelect(programEl,' โปรดเลือกสาขาก่อน ');
      resetSelect(groupEl,  ' โปรดเลือกสาขาวิชาก่อน ');
    }catch(err){
      console.error(err);
      submitBtn.textContent = ' โหลดข้อมูลผิดพลาด';
    }finally{
      submitBtn.disabled = false;
      submitBtn.innerHTML = saved;
    }
  }

  // events
  facEl.addEventListener('change',  () => loadMajorsByFacultyLabel(facEl.value));
  majorEl.addEventListener('change',() => loadProgramsByMajorLabel(majorEl.value));
  programEl.addEventListener('change',() => loadGroupsByProgramLabel(programEl.value));

  document.getElementById('regForm').addEventListener('submit', (ev) => {
    if (pw.value.length < 8) { ev.preventDefault(); alert('รหัสผ่านควรยาวอย่างน้อย 8 ตัวอักษร'); return; }
    if (pw.value !== pw2.value) { ev.preventDefault(); alert('รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน'); }
  });

  await initMeta();
});

function togglePasswordVisibility(id, el) {
  const input = document.getElementById(id);
  if (input.type === "password") {
    input.type = "text";
    el.innerHTML = '<i class="fi fi-rr-eye-crossed"></i>';
  } else {
    input.type = "password";
    el.innerHTML = '<i class="fi fi-rr-eye"></i>';
  }
}
</script>
</body>
</html>
