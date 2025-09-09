<?php 
session_start();

/* ====================== DB CONNECT ====================== */
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'studentregistration';

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) { die("การเชื่อมต่อล้มเหลว: " . $conn->connect_error); }
$conn->set_charset('utf8mb4');
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

/* ===== Flash message จาก quiz.php ===== */
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

/* =========================================================
   ส่วนที่ 1: ตรวจสอบและจัดการ AJAX Request (API สำหรับ dropdown)
   ========================================================= */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    // helper: คืนรูปแบบ [{value, label}, ...]
    function fetchOptions(mysqli $conn, string $sql, array $params = [], string $types = "") {
        $stmt = $conn->prepare($sql);
        if (!$stmt) { http_response_code(500); return ['error' => 'SQL prepare failed: '.$conn->error]; }
        if (!empty($params)) { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $vals = array_values($row); // คอลัมน์แรก = value, คอลัมน์สอง = label
            $out[] = ['value' => $vals[0], 'label' => $vals[1]];
        }
        $stmt->close();
        return $out;
    }

    $action = $_GET['ajax'];
    $response = [];

    switch ($action) {
        case 'meta':
            $response['faculties'] = fetchOptions($conn, "SELECT id, name FROM faculties ORDER BY name");
            $response['levels']    = fetchOptions($conn, "SELECT id, level_name FROM education_levels ORDER BY id");
            $response['ptypes']    = fetchOptions($conn, "SELECT id, type_name FROM program_types ORDER BY id");
            $response['curyears']  = fetchOptions($conn, "SELECT id, year_name FROM curriculum_years ORDER BY id DESC");
            $response['terms']     = fetchOptions($conn, "SELECT id, term_name FROM terms ORDER BY id");
            $response['eduyears']  = fetchOptions($conn, "SELECT id, year_value FROM education_years ORDER BY year_value DESC");
            break;

        case 'majors_by_faculty':
            $faculty_id = (int)($_GET['faculty'] ?? 0);
            $response['majors'] = fetchOptions($conn, "SELECT id, name FROM majors WHERE faculty_id=? ORDER BY name", [$faculty_id], "i");
            break;

        case 'curriculum_by_major':
            $major_id = (int)($_GET['major'] ?? 0);
            $response['curricula'] = fetchOptions($conn, "SELECT id, curriculum_name FROM curricula WHERE major_id=? ORDER BY curriculum_name", [$major_id], "i");
            break;

        case 'groups_by_curriculum':
            $curriculum_id = (int)($_GET['curriculum'] ?? 0);
            $response['groups'] = fetchOptions($conn, "SELECT id, group_name FROM student_groups WHERE curriculum_id=? ORDER BY group_name", [$curriculum_id], "i");
            break;

        default:
            http_response_code(400);
            $response = ['error' => 'Invalid AJAX action'];
            break;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit; // ไม่แสดง HTML ต่อ
}

/* =========================================================
   ส่วนที่ 2: Dashboard (ทำงานเมื่อไม่ใช่ AJAX)
   ========================================================= */
$OPTIONS_API = $_SERVER['PHP_SELF'];

// ต้องล็อกอิน
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

$student_id   = $_SESSION['student_id'];
$data_found   = false;
$student_data = [];

/* โหลดข้อมูลนักศึกษา (personal_info + education_info) */
$sql = "SELECT p.*, e.*
        FROM personal_info p
        INNER JOIN education_info e ON p.id = e.personal_id
        WHERE e.student_id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) { die("Query preparation failed: " . $conn->error); }
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows == 1) {
    $student_data = $result->fetch_assoc();
    $data_found = true;

    /* ===== แปลงค่า field → label โดยอาศัย form_options ===== */
    $optionTypes = [
        'faculty'         => 'faculty',
        'major'           => 'major',
        'education_level' => 'education_level',
        'curriculum_name' => 'curriculum_name',
        'program'         => 'program',
        'program_type'    => 'program_type',
        'curriculum_year' => 'curriculum_year',
        'student_group'   => 'student_group',
        'education_term'  => 'education_term',
        'education_year'  => 'education_year',
    ];

    $opt = $conn->prepare(
        "SELECT label FROM form_options
         WHERE type=? AND (id=? OR label=?) LIMIT 1"
    );

    foreach ($optionTypes as $field => $type) {
        if (!isset($student_data[$field]) || $student_data[$field] === '') continue;
        $val = (string)$student_data[$field];

        if (ctype_digit($val)) {
            $id = (int)$val;
            $opt->bind_param('sis', $type, $id, $val);
        } else {
            $id = 0;
            $opt->bind_param('sis', $type, $id, $val);
        }

        $opt->execute();
        $res = $opt->get_result();
        if ($row = $res->fetch_assoc()) {
            $student_data[$field] = $row['label'];
        }
    }
    $opt->close();

    $full_name = $student_data['full_name'] ?? 'ไม่ระบุชื่อ';

} else {
    $full_name = 'ไม่พบข้อมูล';
    $student_data = array_fill_keys([
        'full_name', 'birthdate', 'gender', 'citizen_id', 'address', 'phone', 'email',
        'faculty', 'major', 'education_level', 'student_id', 'curriculum_name',
        'program', 'program_type', 'curriculum_year', 'student_group', 'gpa', 'status',
        'education_term', 'education_year', 'profile_picture'
    ], '');
    $student_data['student_id'] = $student_id;
}
$stmt->close();

/* ===== โปรไฟล์อวาตาร์ ===== */
$profile_picture_src = '';
if (!empty($student_data['profile_picture']) && file_exists('uploads/profile_images/' . $student_data['profile_picture'])) {
    $profile_picture_src = 'uploads/profile_images/' . $student_data['profile_picture'];
} else {
    $gender = strtolower($student_data['gender'] ?? '');
    $api_name = urlencode($full_name ?: 'Student');
    $api_params = "background=random&color=ffffff&size=150&font-size=0.6&rounded=true&name=$api_name";
    if ($gender === 'ชาย' || $gender === 'male' || $gender === 'ม') {
        $profile_picture_src = 'https://ui-avatars.com/api/?background=3498db&'.$api_params;
    } elseif ($gender === 'หญิง' || $gender === 'female' || $gender === 'ฟ') {
        $profile_picture_src = 'https://ui-avatars.com/api/?background=e91e63&'.$api_params;
    } else {
        $profile_picture_src = 'https://ui-avatars.com/api/?'.$api_params;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>หน้าหลักนักศึกษา</title>
<style>
:root{--primary:#3498db;--secondary:#2980b9;--accent:#f39c12;--success:#27ae60;--text:#333;--radius:8px;--shadow:0 4px 6px rgba(0,0,0,.1)}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Prompt','Kanit',sans-serif}
body{background:#f5f7fa;color:var(--text);line-height:1.6}
.navbar{background:var(--primary);padding:15px 30px;color:#fff;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 4px rgba(0,0,0,.1)}
.navbar-brand{font-size:20px;font-weight:bold}
.navbar-user{display:flex;align-items:center}
.user-info{margin-right:20px;text-align:right}
.user-name{font-weight:bold;font-size:14px}
.user-id{font-size:12px;opacity:.9}
.logout-btn{background:rgba(255,255,255,.2);color:#fff;border:none;padding:8px 15px;border-radius:var(--radius);text-decoration:none}
.logout-btn:hover{background:rgba(255,255,255,.3)}
.container{max-width:1200px;margin:30px auto;padding:0 20px}
.alert{padding:15px;border-radius:var(--radius);margin-bottom:20px;border-left:4px solid}
.alert-success{background:#d4edda;border-color:var(--success);color:#155724}
.alert-warning{background:#fff3cd;border-color:var(--accent);color:#856404}
.card{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);padding:20px}
.student-profile{display:grid;grid-template-columns:1fr 2fr;gap:20px}
.profile-image{text-align:center;padding:20px}
.profile-image img{width:150px;height:150px;border-radius:50%;object-fit:cover;border:5px solid #f5f7fa;box-shadow:var(--shadow)}
.info-item{display:flex;margin-bottom:12px;padding:8px 0;border-bottom:1px solid #f8f9fa}
.info-label{width:180px;font-weight:bold}
.info-value{flex:1}
.info-value.empty{color:#999;font-style:italic}
.section-title{font-size:18px;color:#2980b9;margin:20px 0 15px;padding-bottom:8px;border-bottom:2px solid #3498db;display:flex;align-items:center;gap:10px}
.icon{width:20px;height:20px;display:inline-block}
.action-buttons{display:flex;gap:15px;margin:20px 0;flex-wrap:wrap}
.btn{padding:12px 20px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;color:#374151;text-decoration:none;display:inline-flex;gap:8px}
.btn-success{border-left:3px solid #10b981}
.btn-warning{border-left:3px solid #f59e0b}
.btn-info{border-left:3px solid #8b5cf6}

@media (max-width:768px){
  .student-profile{grid-template-columns:1fr}
  .profile-image img{width:120px;height:120px}
  .navbar{flex-direction:column;gap:8px}
}
</style>
</head>
<body>
    <div class="navbar">
        <div class="navbar-brand">ระบบทะเบียนนักศึกษา</div>
        <div class="navbar-user">
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($full_name) ?></div>
                <div class="user-id">รหัสนักศึกษา: <?= htmlspecialchars($student_id) ?></div>
            </div>
            <a href="logout.php" class="logout-btn">ออกจากระบบ</a>
        </div>
    </div>

    <div class="container">
        <h1>หน้าหลักนักศึกษา</h1>

        <!-- Flash จาก quiz.php -->
        <?php if (!empty($flash_success)): ?>
          <div class="alert alert-success"><strong>สำเร็จ:</strong> <?= htmlspecialchars($flash_success) ?></div>
        <?php endif; ?>
        <?php if (!empty($flash_error)): ?>
          <div class="alert alert-warning"><strong>แจ้งเตือน:</strong> <?= htmlspecialchars($flash_error) ?></div>
        <?php endif; ?>

        <div class="action-buttons">
            <a href="edit_profile.php" class="btn btn-success"><span class="icon">✏️</span>แก้ไขข้อมูลส่วนตัว</a>
            <a href="history.php" class="btn btn-warning"><span class="icon">📋</span>ประวัติการใช้งาน</a>
            <a href="quiz.php" class="btn btn-info"><span class="icon">📝</span>ทำแบบทดสอบ</a>
        </div>

        <?php if ($data_found): ?>
            <div class="alert alert-success"><strong>สำเร็จ:</strong> โหลดข้อมูลนักศึกษาเรียบร้อยแล้ว</div>
        <?php else: ?>
            <div class="alert alert-warning"><strong>คำเตือน:</strong> ไม่พบข้อมูลนักศึกษาในระบบ กรุณาติดต่อเจ้าหน้าที่</div>
        <?php endif; ?>

        <div class="card">
            <div class="student-profile">
                <div class="profile-image">
                    <img src="<?= htmlspecialchars($profile_picture_src) ?>" alt="รูปประจำตัว">
                    <h3 style="margin-top:15px;"><?= htmlspecialchars($full_name ?: 'ไม่พบข้อมูล') ?></h3>
                    <p style="color:#7f8c8d;"><?= htmlspecialchars($student_id) ?></p>
                    <?php if (!empty($student_data['profile_picture']) && file_exists('uploads/profile_images/' . $student_data['profile_picture'])): ?>
                        <div style="margin-top:6px;font-size:12px;color:#2e7d32;background:#e8f5e8;padding:4px 10px;border-radius:12px;display:inline-block;">รูปโปรไฟล์ที่อัพโหลด</div>
                    <?php else: ?>
                        <p style="font-size:12px;color:#999;margin-top:6px;">แก้ไขข้อมูลส่วนตัวเพื่ออัปโหลดรูปโปรไฟล์</p>
                    <?php endif; ?>
                </div>

                <div class="profile-details">
                    <h2 class="section-title"><span class="icon">👤</span>ข้อมูลส่วนตัว</h2>
                    <?php
                    function display_info($label, $value) {
                        $is_empty = ($value === null || $value === '');
                        $display  = htmlspecialchars($value ?: 'ไม่ระบุ');
                        echo "<div class='info-item'><div class='info-label'>{$label}:</div><div class='info-value ".($is_empty?'empty':'')."'>{$display}</div></div>";
                    }
                    display_info('ชื่อ-นามสกุล', $student_data['full_name']);
                    display_info('วันเดือนปีเกิด', $student_data['birthdate']);
                    display_info('เพศ', $student_data['gender']);
                    display_info('รหัสบัตรประชาชน', $student_data['citizen_id']);
                    display_info('ที่อยู่', $student_data['address']);
                    display_info('เบอร์โทรศัพท์', $student_data['phone']);
                    display_info('อีเมล์', $student_data['email']);

                    echo "<h2 class='section-title'><span class='icon'>📚</span>ข้อมูลการศึกษา</h2>";
                    display_info('คณะ', $student_data['faculty']);
                    display_info('สาขา', $student_data['major']);
                    display_info('สาขาวิชา', $student_data['program']);
                    display_info('ระดับการศึกษา', $student_data['education_level']);
                    display_info('รหัสนักศึกษา', $student_data['student_id']);
                    display_info('หลักสูตร', $student_data['curriculum_name']);
                    display_info('ประเภทหลักสูตร', $student_data['program_type']);
                    display_info('ปีของหลักสูตร', $student_data['curriculum_year']);
                    display_info('กลุ่มนักศึกษา', $student_data['student_group']);
                    display_info('เกรดเฉลี่ยรวม', $student_data['gpa']);
                    display_info('ภาคการศึกษา', $student_data['education_term']);
                    display_info('ปีการศึกษา', $student_data['education_year']);
                    ?>
                </div>
            </div>
        </div>
    </div>

<script>
/* =========================================================
   สคริปต์เติม dropdown (คงเดิม)
   ========================================================= */
const OPTIONS_API = <?= json_encode($OPTIONS_API) ?>;

function optionHTML(items, current='') {
  let html = '<option value="">— เลือก —</option>';
  (items||[]).forEach(it=>{
    const sel = (String(current)===String(it.value)) ? ' selected' : '';
    html += `<option value="${it.value}"${sel}>${it.label}</option>`;
  });
  return html;
}

async function fetchJSON(url){
  const res = await fetch(url, {cache:'no-store'});
  if(!res.ok) throw new Error('Fetch failed: '+url);
  return await res.json();
}

document.addEventListener('DOMContentLoaded', async ()=>{
  const $faculty   = document.getElementById('faculty');
  const $major     = document.getElementById('major');
  const $level     = document.getElementById('education_level');
  const $curname   = document.getElementById('curriculum_name');
  const $ptype     = document.getElementById('program_type');
  const $curyear   = document.getElementById('curriculum_year');
  const $group     = document.getElementById('student_group');
  const $term      = document.getElementById('education_term');
  const $eduyear   = document.getElementById('education_year');

  if (!$faculty && !$major && !$level && !$curname && !$ptype && !$curyear && !$group && !$term && !$eduyear) return;

  const current = {
    faculty: $faculty?.dataset.current || '',
    major:   $major?.dataset.current || '',
    level:   $level?.dataset.current || '',
    curname: $curname?.dataset.current || '',
    ptype:   $ptype?.dataset.current || '',
    curyear: $curyear?.dataset.current || '',
    group:   $group?.dataset.current || '',
    term:    $term?.dataset.current || '',
    eduyear: $eduyear?.dataset.current || ''
  };

  try {
    const meta = await fetchJSON(`${OPTIONS_API}?ajax=meta`);
    if($faculty) $faculty.innerHTML = optionHTML(meta.faculties, current.faculty);
    if($level)   $level.innerHTML   = optionHTML(meta.levels,    current.level);
    if($ptype)   $ptype.innerHTML   = optionHTML(meta.ptypes,    current.ptype);
    if($curyear) $curyear.innerHTML = optionHTML(meta.curyears,  current.curyear);
    if($term)    $term.innerHTML    = optionHTML(meta.terms,     current.term);
    if($eduyear) $eduyear.innerHTML = optionHTML(meta.eduyears,  current.eduyear);

    if($faculty && $major){
      if(current.faculty){
        const {majors} = await fetchJSON(`${OPTIONS_API}?ajax=majors_by_faculty&faculty=${encodeURIComponent(current.faculty)}`);
        $major.innerHTML = optionHTML(majors, current.major);
      } else {
        $major.innerHTML = optionHTML([], '');
      }
    }

    if($major && $curname){
      if(current.major){
        const {curricula} = await fetchJSON(`${OPTIONS_API}?ajax=curriculum_by_major&major=${encodeURIComponent(current.major)}`);
        $curname.innerHTML = optionHTML(curricula, current.curname);
      } else {
        $curname.innerHTML = optionHTML([], '');
      }
    }

    if($curname && $group){
      if(current.curname){
        const {groups} = await fetchJSON(`${OPTIONS_API}?ajax=groups_by_curriculum&curriculum=${encodeURIComponent(current.curname)}`);
        $group.innerHTML = optionHTML(groups, current.group);
      } else {
        $group.innerHTML = optionHTML([], '');
      }
    }

    $faculty?.addEventListener('change', async function(){
      const fac = this.value;
      if($major)   $major.innerHTML   = optionHTML([], '');
      if($curname) $curname.innerHTML = optionHTML([], '');
      if($group)   $group.innerHTML   = optionHTML([], '');
      if(!fac) return;
      const {majors} = await fetchJSON(`${OPTIONS_API}?ajax=majors_by_faculty&faculty=${encodeURIComponent(fac)}`);
      $major.innerHTML = optionHTML(majors, '');
    });

    $major?.addEventListener('change', async function(){
      const m = this.value;
      if($curname) $curname.innerHTML = optionHTML([], '');
      if($group)   $group.innerHTML   = optionHTML([], '');
      if(!m) return;
      const {curricula} = await fetchJSON(`${OPTIONS_API}?ajax=curriculum_by_major&major=${encodeURIComponent(m)}`);
      $curname.innerHTML = optionHTML(curricula, '');
    });

    $curname?.addEventListener('change', async function(){
      const cur = this.value;
      if($group) $group.innerHTML = optionHTML([], '');
      if(!cur) return;
      const {groups} = await fetchJSON(`${OPTIONS_API}?ajax=groups_by_curriculum&curriculum=${encodeURIComponent(cur)}`);
      $group.innerHTML = optionHTML(groups, '');
    });

  } catch(err){
    console.error(err);
    alert('โหลดข้อมูลตัวเลือกไม่สำเร็จ');
  }
});
</script>
</body>
</html>
