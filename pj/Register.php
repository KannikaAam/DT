<?php
// กำหนดค่าการเชื่อมต่อฐานข้อมูล
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'studentregistration';

// สร้างการเชื่อมต่อกับฐานข้อมูล
$conn = new mysqli($host, $username, $password, $database);

// ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    die("การเชื่อมต่อล้มเหลว: " . $conn->connect_error);
}

// ตั้งค่าการเข้ารหัส UTF-8
$conn->set_charset("utf8");

$error_message = '';
$success_message = '';

// ตรวจสอบว่ามีการส่งแบบฟอร์มหรือไม่
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // รับข้อมูลจากฟอร์ม
        $full_name       = trim($_POST['full_name'] ?? '');
        $birthdate       = !empty($_POST['birthdate']) ? trim($_POST['birthdate']) : null;
        $gender          = trim($_POST['gender'] ?? '');
        $citizen_id      = trim($_POST['citizen_id'] ?? '');
        $address         = trim($_POST['address'] ?? '');
        $phone           = trim($_POST['phone'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        
        // ข้อมูลการศึกษา
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
        $student_status  = trim($_POST['student_status'] ?? 'กำลังศึกษา');
        $education_term  = trim($_POST['education_term'] ?? '');
        $education_year  = trim($_POST['education_year'] ?? '');
        $password        = $_POST['password'] ?? '';
        
        // ตรวจสอบข้อมูลที่จำเป็น
        if (empty($full_name) || empty($student_id) || empty($password)) {
            throw new Exception("กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน: ชื่อ-นามสกุล, รหัสนักศึกษา, และรหัสผ่าน");
        }
        
        // ตรวจสอบว่ารหัสนักศึกษาซ้ำหรือไม่
        $check_sql = "SELECT student_id FROM education_info WHERE student_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $student_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            throw new Exception("รหัสนักศึกษา '$student_id' นี้มีอยู่ในระบบแล้ว");
        }
        
        // เริ่ม Transaction
        $conn->begin_transaction();
        
        // 1. เพิ่มข้อมูลส่วนตัว
        $sql_personal = "INSERT INTO personal_info (full_name, birthdate, gender, citizen_id, address, phone, email) 
                         VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt_personal = $conn->prepare($sql_personal);
        $stmt_personal->bind_param("sssssss", $full_name, $birthdate, $gender, $citizen_id, $address, $phone, $email);
        
        if (!$stmt_personal->execute()) {
            throw new Exception("ไม่สามารถบันทึกข้อมูลส่วนตัวได้: " . $stmt_personal->error);
        }
        $personal_id = $conn->insert_id;
        
        // 2. เพิ่มข้อมูลการศึกษา (แก้ไขแล้ว ✅)
        $sql_education = "INSERT INTO education_info (
            personal_id,
            student_id,
            faculty,
            major,
            program,
            education_level,
            curriculum_name,
            program_type,
            curriculum_year,
            student_group,
            gpa,
            student_status,
            education_term,
            education_year
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt_education = $conn->prepare($sql_education);
        $stmt_education->bind_param(
            "isssssssssdsss",
            $personal_id,
            $student_id,
            $faculty,
            $major,
            $program,
            $education_level,
            $curriculum_name,
            $program_type,
            $curriculum_year,
            $student_group,
            $gpa,
            $student_status,
            $education_term,
            $education_year
        );
        
        if (!$stmt_education->execute()) {
            throw new Exception("ไม่สามารถบันทึกข้อมูลการศึกษาได้: " . $stmt_education->error);
        }
        
        // 3. เพิ่มข้อมูลการเข้าสู่ระบบ
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $sql_login = "INSERT INTO user_login (student_id, password) VALUES (?, ?)";
        $stmt_login = $conn->prepare($sql_login);
        $stmt_login->bind_param("ss", $student_id, $hashed_password);
        
        if (!$stmt_login->execute()) {
            throw new Exception("ไม่สามารถบันทึกข้อมูลการเข้าสู่ระบบได้: " . $stmt_login->error);
        }
        
        // ยืนยัน Transaction
        $conn->commit();
        
        $success_message = "ลงทะเบียนสำเร็จ! ยินดีต้อนรับคุณ " . htmlspecialchars($full_name);
        header("refresh:3;url=login.php");
        
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
    <style>
        :root {
            --primary-color: #007bff;
            --primary-hover: #0056b3;
            --secondary-color: #6c757d;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --text-primary: #212529;
            --text-secondary: #6c757d;
            --border-color: #dee2e6;
            --body-bg: #f4f7f9;
            --card-bg: #ffffff;
            --radius: 8px;
            --shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            --transition: all 0.2s ease-in-out;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: var(--body-bg);
            color: var(--text-primary);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 2rem 1rem;
        }
        .container { max-width: 900px; width: 100%; background-color: var(--card-bg); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
        .header { background: linear-gradient(135deg, var(--primary-color) 0%, #0056b3 100%); color: white; padding: 2.5rem 2rem; text-align: center; }
        .header h1 { font-size: 2rem; font-weight: 700; margin-bottom: 0.5rem; }
        .header p { opacity: 0.9; }
        .form-container { padding: 2.5rem; }
        .message { margin-bottom: 1.5rem; padding: 1rem 1.5rem; border-radius: var(--radius); border-left: 5px solid; font-weight: 500; }
        .success { background-color: #e9f7ef; color: var(--success-color); border-color: var(--success-color); }
        .error { background-color: #fbebed; color: var(--danger-color); border-color: var(--danger-color); }
        .form-group { margin-bottom: 1.25rem; position: relative; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.95rem; }
        .form-control { width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--border-color); border-radius: var(--radius); font-size: 1rem; transition: var(--transition); background-color: #fff; }
        .form-control:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.2); }
        .form-control:disabled { background-color: #e9ecef; cursor: not-allowed; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; }
        .section-title { font-size: 1.5rem; font-weight: 700; color: var(--primary-color); margin-top: 2.5rem; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 2px solid var(--primary-color); }
        .btn { padding: 0.8rem 1.75rem; border: none; border-radius: var(--radius); font-size: 1rem; font-weight: 500; cursor: pointer; transition: var(--transition); display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; text-decoration: none; }
        .btn-primary { background-color: var(--primary-color); color: white; }
        .btn-primary:hover { background-color: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .btn-primary:disabled { background-color: #a0c9f5; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-secondary { background-color: #f8f9fa; color: var(--text-primary); border: 1px solid var(--border-color); }
        .btn-secondary:hover { background-color: #e9ecef; }
        .form-actions { display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid #e9ecef; }
        .password-toggle { position: absolute; top: 70%; right: 1rem; transform: translateY(-50%); cursor: pointer; color: var(--text-secondary); }
        @media (max-width: 768px) {
            body { padding: 1rem 0.5rem; }
            .form-container, .header { padding: 1.5rem; }
            .form-actions { flex-direction: column-reverse; }
            .btn { width: 100%; }
        }
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
                <div class="message error"><strong>เกิดข้อผิดพลาด:</strong> <?php echo $error_message; ?></div>
            <?php endif; ?>
            
            <?php if (empty($success_message)): ?>
            <form method="POST" action="">
                <h2 class="section-title">ข้อมูลส่วนตัว</h2>
                <div class="form-group"><label for="full_name">ชื่อ-นามสกุล *</label><input type="text" id="full_name" name="full_name" class="form-control" required placeholder="เช่น นายสมชาย ใจดี"></div>
                <div class="form-row">
                    <div class="form-group"><label for="birthdate">วันเดือนปีเกิด</label><input type="date" id="birthdate" name="birthdate" class="form-control"></div>
                    <div class="form-group"><label for="gender">เพศ</label><select id="gender" name="gender" class="form-control"><option value="">-- เลือกเพศ --</option><option value="ชาย">ชาย</option><option value="หญิง">หญิง</option></select></div>
                </div>
                <div class="form-group"><label for="citizen_id">เลขบัตรประชาชน</label><input type="text" id="citizen_id" name="citizen_id" class="form-control" maxlength="13" pattern="\d{13}" placeholder="เลข 13 หลัก ไม่ต้องมีขีด"></div>
                <div class="form-group"><label for="address">ที่อยู่ปัจจุบัน</label><textarea id="address" name="address" class="form-control" rows="3" placeholder="บ้านเลขที่, ถนน, ตำบล, อำเภอ, จังหวัด, รหัสไปรษณีย์"></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label for="phone">เบอร์โทรศัพท์</label><input type="tel" id="phone" name="phone" class="form-control" placeholder="เช่น 0812345678"></div>
                    <div class="form-group"><label for="email">อีเมล</label><input type="email" id="email" name="email" class="form-control" placeholder="เช่น example@email.com"></div>
                </div>
                
                <h2 class="section-title">ข้อมูลการศึกษา</h2>
                <div class="form-row">
                    <div class="form-group"><label for="faculty">คณะ</label><select id="faculty" name="faculty" class="form-control"></select></div>
                    <div class="form-group"><label for="major">สาขา</label><select id="major" name="major" class="form-control" disabled></select></div>
                    <div class="form-group"><label for="program">สาขาวิชา</label><select id="program" name="program" class="form-control" disabled></select></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="education_level">ระดับการศึกษา</label><select id="education_level" name="education_level" class="form-control"></select></div>
                    <div class="form-group"><label for="student_id">รหัสนักศึกษา *</label><input type="text" id="student_id" name="student_id" class="form-control" required placeholder="กรอกรหัสนักศึกษา"></div>
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
                    <div class="form-group"><label for="student_status">สถานะ</label><select id="student_status" name="student_status" class="form-control"></select></div>
                </div>
                 <div class="form-row">
                    <div class="form-group"><label for="education_term">ภาคการศึกษาแรกเข้า</label><select id="education_term" name="education_term" class="form-control"></select></div>
                    <div class="form-group"><label for="education_year">ปีการศึกษาแรกเข้า (พ.ศ.)</label><input type="number" id="education_year" name="education_year" class="form-control" min="2550" max="<?php echo date('Y') + 544; ?>" placeholder="เช่น 2567"></div>
                </div>

                <h2 class="section-title">ข้อมูลเข้าสู่ระบบ</h2>
                <div class="form-group">
                    <label for="password">ตั้งรหัสผ่าน *</label>
                    <div style="position: relative;">
                      <input type="password" id="password" name="password" class="form-control" required>
                      <span class="password-toggle" onclick="togglePasswordVisibility()">👁️</span>
                    </div>
                </div>
                
                <div class="form-actions">
                    <a href="login.php" class="btn btn-secondary">กลับหน้าเข้าสู่ระบบ</a>
                    <button type="submit" id="submitBtn" class="btn btn-primary">✔️ ลงทะเบียน</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', async () => {
    // === Get Elements ===
    const submitBtn = document.getElementById('submitBtn');
    const facEl = document.getElementById('faculty');
    const majorEl = document.getElementById('major');
    const programEl = document.getElementById('program');
    const groupEl = document.getElementById('student_group');
    const endpoint = 'course_management.php';

    // === Helper Function to fill a dropdown ===
    const fill = (element, list, withBlank = true, blankText = '-- เลือก --') => {
        if (!element) return;
        element.innerHTML = '';
        if (withBlank) element.append(new Option(blankText, ''));
        (list || []).forEach(o => element.append(new Option(o.label, o.id)));
    };

    // === Functions to load data based on parent selection ===
    const loadMajors = async (facultyValue) => {
        majorEl.disabled = true;
        fill(majorEl, [], true, facultyValue ? ' กำลังโหลด...' : '-- โปรดเลือกคณะก่อน --');
        await loadPrograms(''); // Reset children
        if (!facultyValue) return;

        try {
            const res = await fetch(`${endpoint}?ajax=majors_by_faculty&faculty=${encodeURIComponent(facultyValue)}`);
            const data = await res.json();
            fill(majorEl, data.majors, true, '-- เลือกสาขา --');
            majorEl.disabled = false;
        } catch (e) { console.error('Failed to load majors:', e); fill(majorEl, [], true, ' โหลดผิดพลาด'); }
    };

    const loadPrograms = async (majorValue) => {
        programEl.disabled = true;
        fill(programEl, [], true, majorValue ? ' กำลังโหลด...' : '-- โปรดเลือกสาขาก่อน --');
        await loadGroups(''); // Reset child
        if (!majorValue) return;

        try {
            const res = await fetch(`${endpoint}?ajax=programs_by_major&major=${encodeURIComponent(majorValue)}`);
            const data = await res.json();
            fill(programEl, data.programs, true, '-- เลือกสาขาวิชา --');
            programEl.disabled = false;
        } catch (e) { console.error('Failed to load programs:', e); fill(programEl, [], true, ' โหลดผิดพลาด'); }
    };

    const loadGroups = async (programValue) => {
        groupEl.disabled = true;
        fill(groupEl, [], true, programValue ? ' กำลังโหลด...' : '-- โปรดเลือกสาขาวิชาก่อน --');
        if (!programValue) return;

        try {
            const res = await fetch(`${endpoint}?ajax=groups_by_program&program=${encodeURIComponent(programValue)}`);
            const data = await res.json();
            fill(groupEl, data.groups, true, '-- เลือกกลุ่มเรียน --');
            groupEl.disabled = false;
        } catch (e) { console.error('Failed to load groups:', e); fill(groupEl, [], true, ' โหลดผิดพลาด'); }
    };

    // === Initial Load ===
    const initForm = async () => {
        submitBtn.disabled = true;
        submitBtn.innerHTML = ' กำลังโหลดข้อมูล...';

        try {
            const res = await fetch(`${endpoint}?ajax=meta`);
            if (!res.ok) throw new Error('ไม่สามารถโหลดข้อมูลตัวเลือกได้');
            const meta = await res.json();
            
            // Fill static dropdowns
            fill(facEl, meta.faculties);
            fill(document.getElementById('education_level'), meta.levels);
            fill(document.getElementById('program_type'), meta.ptypes);
            fill(document.getElementById('curriculum_name'), meta.curnames);
            fill(document.getElementById('curriculum_year'), meta.curyears, false);
            fill(document.getElementById('student_status'), meta.statuses);
            fill(document.getElementById('education_term'), meta.terms);
            
            // Set initial state for dependent dropdowns
            await loadMajors('');

        } catch (error) {
            console.error(error);
            submitBtn.textContent = ' เกิดข้อผิดพลาด';
            return; // Stop execution if initial load fails
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = ' ลงทะเบียน';
        }
    };

    // === Add Event Listeners ===
    facEl.addEventListener('change', (e) => loadMajors(e.target.value));
    majorEl.addEventListener('change', (e) => loadPrograms(e.target.value));
    programEl.addEventListener('change', (e) => loadGroups(e.target.value));

    // === Run Initialization ===
    await initForm();
});

function togglePasswordVisibility() {
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.querySelector('.password-toggle');
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.textContent = '🙈';
    } else {
        passwordInput.type = 'password';
        toggleIcon.textContent = '👁️';
    }
}
</script>

</body>
</html>