<?php
session_start();

/* ====================== DB CONNECT ====================== */
$host = 'localhost';
$username = 'aprdt';
$password = 'aprdt1234';
$database = 'studentregistration';

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    die("การเชื่อมต่อล้มเหลว: " . $conn->connect_error);
}
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
        if (!$stmt) {
            http_response_code(500);
            return ['error' => 'SQL prepare failed: ' . $conn->error];
        }
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
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
            $response['levels'] = fetchOptions($conn, "SELECT id, level_name FROM education_levels ORDER BY id");
            $response['ptypes'] = fetchOptions($conn, "SELECT id, type_name FROM program_types ORDER BY id");
            $response['curyears'] = fetchOptions($conn, "SELECT id, year_name FROM curriculum_years ORDER BY id DESC");
            $response['terms'] = fetchOptions($conn, "SELECT id, term_name FROM terms ORDER BY id");
            $response['eduyears'] = fetchOptions($conn, "SELECT id, year_value FROM education_years ORDER BY year_value DESC");
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

$student_id = $_SESSION['student_id'];
$data_found = false;
$student_data = [];

/* โหลดข้อมูลนักศึกษา (personal_info + education_info) */
$sql = "SELECT p.*, e.*
        FROM personal_info p
        INNER JOIN education_info e ON p.id = e.personal_id
        WHERE e.student_id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Query preparation failed: " . $conn->error);
}
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows == 1) {
    $student_data = $result->fetch_assoc();
    $data_found = true;

    /* ===== แปลงค่า field → label โดยอาศัย form_options ===== */
    $optionTypes = [
        'faculty' => 'faculty',
        'major' => 'major',
        'education_level' => 'education_level',
        'curriculum_name' => 'curriculum_name',
        'program' => 'program',
        'program_type' => 'program_type',
        'curriculum_year' => 'curriculum_year',
        'student_group' => 'student_group',
        'education_term' => 'education_term',
        'education_year' => 'education_year',
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
    if ($gender === 'ชาย' || $gender === 'male' || $gender === 'ม') {
        $profile_picture_src = 'https://ui-avatars.com/api/?name=' . $api_name . '&background=3498db&color=ffffff&size=150&rounded=true';
    } elseif ($gender === 'หญิง' || $gender === 'female' || $gender === 'ฟ') {
        $profile_picture_src = 'https://ui-avatars.com/api/?name=' . $api_name . '&background=e91e63&color=ffffff&size=150&rounded=true';
    } else {
        $profile_picture_src = 'https://ui-avatars.com/api/?name=' . $api_name . '&background=9b59b6&color=ffffff&size=150&rounded=true';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>หน้าหลักนักศึกษา</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
  /* ======================== Unified CSS Theme ======================== */
  :root {
      --ink: #0f172a;           /* ตัวอักษรหลัก */
      --muted: #64748b;         /* ตัวอักษรรอง */
      --bg: #f7f8fb;            /* พื้นหลังอ่อน */
      --card: #ffffff;          /* กล่องข้อมูล */
      --line: #e5e7eb;          /* เส้นคั่น/กรอบ */
      --brand: #2563eb;         /* สีลิงก์/แอคเซนต์ */
      --success: #10b981;
      --warning: #f59e0b;
      --info: #6366f1;
      --danger: #ef4444;
      --radius: 12px;
  }

  * {
      box-sizing: border-box;
  }

  html, body {
      height: 100%;
  }

  body {
      margin: 0;
      background: var(--bg);
      color: var(--ink);
      font-family: 'Sarabun', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      line-height: 1.65;
      font-size: 16px;
  }

  /* ===== Topbar ===== */
  .topbar {
      background: #ffffff;
      border-bottom: 1px solid var(--line);
      padding: 14px 16px;
      position: sticky;
      top: 0;
      z-index: 10;
  }

  .topbar-inner {
      max-width: 1100px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
  }

  .brand {
      font-weight: 700;
      letter-spacing: 0.2px;
      color: var(--ink);
  }

  .userbox {
      display: flex;
      align-items: center;
      gap: 12px;
  }

  .user-name {
      font-weight: 600;
      line-height: 1.2;
  }

  .userbox small {
      color: var(--muted);
  }

  .logout-btn {
      background: #e5e7eb;
      color: var(--ink);
      border: none;
      padding: 8px 15px;
      border-radius: var(--radius);
      text-decoration: none;
      font-size: 14px;
      transition: all 0.2s ease;
  }

  .logout-btn:hover {
      background: var(--danger);
      color: white;
  }

  /* ===== Container ===== */
  .container {
      max-width: 1100px;
      margin: 22px auto;
      padding: 0 16px;
  }

  h1 {
      font-size: 24px;
      margin: 0 0 4px;
      font-weight: 700;
  }

  .p-sub {
      color: var(--muted);
      margin: 0 0 14px;
  }

  /* ===== Actions/Navigation ===== */
  .actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin: 12px 0 18px;
  }

  .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 16px;
      border: 1px solid var(--line);
      background: #fff;
      border-radius: var(--radius);
      text-decoration: none;
      color: var(--ink);
      font-weight: 500;
      transition: all 0.2s ease;
  }

  .btn:hover {
      background: #f9fafb;
      transform: translateY(-1px);
  }

  .btn-success { border-left: 3px solid var(--success); }
  .btn-warning { border-left: 3px solid var(--warning); }
  .btn-info { border-left: 3px solid var(--info); }

  /* ===== Alerts ===== */
  .alert {
      padding: 12px 16px;
      border-radius: var(--radius);
      margin-bottom: 16px;
      border-left: 4px solid;
      font-weight: 500;
  }

  .alert-success { 
      background: #ecfdf5; 
      border-color: var(--success); 
      color: #065f46; 
  }

  .alert-warning { 
      background: #fffbeb; 
      border-color: var(--warning); 
      color: #92400e; 
  }

  /* ===== Card ===== */
  .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
      margin-bottom: 20px;
  }

  .card-h {
      padding: 16px 20px;
      border-bottom: 1px solid var(--line);
      font-weight: 600;
      font-size: 18px;
  }

  .card-b {
      padding: 20px;
  }

  /* ===== Student Profile ===== */
  .student-profile {
      display: grid;
      grid-template-columns: 200px 1fr;
      gap: 30px;
      align-items: start;
  }

  .profile-image {
      text-align: center;
  }

  .profile-image img {
      width: 150px;
      height: 150px;
      border-radius: 50%;
      object-fit: cover;
      border: 4px solid #f5f7fa;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  }

  .profile-image h3 {
      margin: 15px 0 5px;
      font-size: 18px;
      font-weight: 600;
  }

  .profile-image p {
      color: var(--muted);
      margin: 0;
      font-size: 14px;
  }

  .profile-status {
      margin-top: 8px;
      font-size: 12px;
      padding: 4px 10px;
      border-radius: 12px;
      display: inline-block;
  }

  .profile-status.uploaded {
      color: #2e7d32;
      background: #e8f5e8;
  }

  .profile-status.default {
      color: #999;
  }

  /* ===== Info Items ===== */
  .info-item {
      display: flex;
      margin-bottom: 12px;
      padding: 8px 0;
      border-bottom: 1px dashed #f1f5f9;
  }

  .info-label {
      width: 180px;
      font-weight: 600;
      color: var(--muted);
      flex-shrink: 0;
  }

  .info-value {
      flex: 1;
      word-break: break-word;
  }

  .info-value.empty {
      color: #999;
      font-style: italic;
  }

  /* ===== Section Titles ===== */
  .section-title {
      font-size: 18px;
      color: var(--ink);
      margin: 20px 0 15px;
      padding-bottom: 8px;
      border-bottom: 2px solid var(--brand);
      display: flex;
      align-items: center;
      gap: 10px;
      font-weight: 600;
  }

  .icon {
      width: 20px;
      height: 20px;
      display: inline-block;
  }

  /* ===== Responsive ===== */
  @media (max-width: 768px) {
      .student-profile { 
          grid-template-columns: 1fr; 
          gap: 20px;
          text-align: center;
      }
      
      .profile-image img { 
          width: 120px; 
          height: 120px; 
      }
      
      .topbar-inner { 
          flex-direction: column; 
          gap: 8px; 
      }
      
      .info-item {
          flex-direction: column;
          gap: 4px;
      }
      
      .info-label {
          width: auto;
          font-size: 14px;
      }
      
      .userbox small {
          display: none;
      }
      
      .actions {
          justify-content: center;
      }
      
      h1 {
          font-size: 20px;
          text-align: center;
      }
      
      .p-sub {
          text-align: center;
      }
  }

  @media (max-width: 480px) {
      .container {
          padding: 0 12px;
      }
      
      .btn {
          flex: 1;
          justify-content: center;
      }
      
      .card-b {
          padding: 16px;
      }
  }
</style>
</head>
<body>
    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-inner">
            <div class="brand">ระบบแนะนำรายวิชาชีพเลือกด้วยต้นไม้ตัดสินใจ</div>
            <div class="userbox">
                <div>
                    <div class="user-name"><?= htmlspecialchars($full_name) ?></div>
                    <small>รหัสนักศึกษา: <?= htmlspecialchars($student_id) ?></small>
                </div>
                <a href="logout.php" class="logout-btn">ออกจากระบบ</a>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Page Header -->
        <h1>หน้าหลักนักศึกษา</h1>
        <p class="p-sub">ข้อมูลส่วนตัวและข้อมูลการศึกษาของคุณ</p>

        <!-- Flash Messages -->
        <?php if (!empty($flash_success)): ?>
            <div class="alert alert-success"><strong>สำเร็จ:</strong> <?= htmlspecialchars($flash_success) ?></div>
        <?php endif; ?>
        <?php if (!empty($flash_error)): ?>
            <div class="alert alert-warning"><strong>แจ้งเตือน:</strong> <?= htmlspecialchars($flash_error) ?></div>
        <?php endif; ?>

        <!-- Navigation Actions -->
        <div class="actions">
            <a href="edit_profile.php" class="btn btn-success">
                แก้ไขข้อมูลส่วนตัว
            </a>
            <a href="history.php" class="btn btn-warning">
                ประวัติการทำแบบทดสอบ
            </a>
            <a href="quiz.php" class="btn btn-info">
                ทำแบบทดสอบ
            </a>
        </div>

        <!-- Main Content Card -->
        <div class="card">
            <div class="card-b">
                <?php if ($data_found): ?>
                    <div class="student-profile">
                        <!-- Profile Image Section -->
                        <div class="profile-image">
                            <img src="<?= htmlspecialchars($profile_picture_src) ?>" alt="รูปประจำตัว">
                            <h3><?= htmlspecialchars($full_name ?: 'ไม่พบข้อมูล') ?></h3>
                            <p><?= htmlspecialchars($student_id) ?></p>
                            
                            <?php if (!empty($student_data['profile_picture']) && file_exists('uploads/profile_images/' . $student_data['profile_picture'])): ?>
                                <div class="profile-status uploaded">รูปโปรไฟล์ที่อัพโหลด</div>
                            <?php else: ?>
                                <div class="profile-status default">แก้ไขข้อมูลส่วนตัวเพื่ออัปโหลดรูปโปรไฟล์</div>
                            <?php endif; ?>
                        </div>

                        <!-- Profile Details Section -->
                        <div class="profile-details">
                            <h2 class="section-title">
                                <span class="icon">👤</span>ข้อมูลส่วนตัว
                            </h2>
                            
                            <?php
                            function display_info($label, $value) {
                                $is_empty = ($value === null || $value === '');
                                $display = htmlspecialchars($value ?: 'ไม่ระบุ');
                                echo "<div class='info-item'>";
                                echo "<div class='info-label'>{$label}:</div>";
                                echo "<div class='info-value " . ($is_empty ? 'empty' : '') . "'>{$display}</div>";
                                echo "</div>";
                            }
                            
                            display_info('ชื่อ-นามสกุล', $student_data['full_name']);
                            display_info('วันเดือนปีเกิด', $student_data['birthdate']);
                            display_info('เพศ', $student_data['gender']);
                            display_info('รหัสบัตรประชาชน', $student_data['citizen_id']);
                            display_info('ที่อยู่', $student_data['address']);
                            display_info('เบอร์โทรศัพท์', $student_data['phone']);
                            display_info('อีเมล', $student_data['email']);
                            ?>

                            <h2 class="section-title">
                                <span class="icon">📚</span>ข้อมูลการศึกษา
                            </h2>
                            
                            <?php
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
                <?php else: ?>
                    <div class="alert alert-warning">
                        <strong>คำเตือน:</strong> ไม่พบข้อมูลนักศึกษาในระบบ กรุณาติดต่อเจ้าหน้าที่
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Enhanced JavaScript for better UX
        document.addEventListener('DOMContentLoaded', function() {
            // Add loading states for buttons
            const buttons = document.querySelectorAll('.btn');
            buttons.forEach(button => {
                button.addEventListener('click', function() {
                    if (this.href && !this.href.includes('#')) {
                        this.style.opacity = '0.7';
                        this.style.pointerEvents = 'none';
                        setTimeout(() => {
                            this.style.opacity = '';
                            this.style.pointerEvents = '';
                        }, 2000);
                    }
                });
            });

            // Smooth scroll for hash links
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });

            // Auto-hide flash messages after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
                    }, 500);
                }, 5000);
            });
        });
    </script>
</body>
</html>