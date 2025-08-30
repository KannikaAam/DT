<?php
/* ===========================================================
   manage_courses_and_options.php (v5 - Corrected API Endpoints)
   - Courses: Advanced management with recommendation fields
   - Options: Complete management with Add, Edit, and Delete
   - Student Group is now linked to a Program
   - FIXED: API endpoints for public registration form
   =========================================================== */
session_start();
$HOME_URL = 'admin_dashboard.php'; // ถ้าหน้าหลักชื่ออื่น ให้เปลี่ยนตรงนี้

/* ===== DB connect ===== */
$DB_HOST='127.0.0.1'; $DB_NAME='studentregistration'; $DB_USER='root'; $DB_PASS='';
try {
  $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch(PDOException $e){ die('DB Connection failed: '.htmlspecialchars($e->getMessage())); }

/* ===== Auto-migrate: form_options ===== */
$pdo->exec("CREATE TABLE IF NOT EXISTS form_options (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM(
    'faculty','major','program','education_level','program_type',
    'curriculum_name','curriculum_year','student_group',
    'student_status','education_term','education_year'
  ) NOT NULL,
  value VARCHAR(100) NOT NULL,
  label VARCHAR(255) NOT NULL,
  parent_value VARCHAR(100) DEFAULT NULL,
  is_active TINYINT(1) DEFAULT 1,
  sort_order INT DEFAULT 0,
  UNIQUE KEY uniq_type_value_parent (type, value, parent_value)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
  $col = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='form_options' AND COLUMN_NAME='type'")
             ->fetchColumn();
  if ($col && (strpos($col, "'program'") === false || strpos($col, "'student_group'") === false)) {
    $pdo->exec("ALTER TABLE form_options MODIFY COLUMN type ENUM(
      'faculty','major','program','education_level','program_type',
      'curriculum_name','curriculum_year','student_group',
      'student_status','education_term','education_year'
    ) NOT NULL");
  }
} catch(Throwable $e) { /* noop */ }

/* ===== Auto-migrate: courses ===== */
$pdo->exec("CREATE TABLE IF NOT EXISTS courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_code VARCHAR(32) NOT NULL,
  course_name VARCHAR(255) NOT NULL,
  credits DECIMAL(3,1) DEFAULT 3.0,
  faculty_value VARCHAR(100) DEFAULT NULL,
  major_value   VARCHAR(100) DEFAULT NULL,
  program_value VARCHAR(100) DEFAULT NULL,
  is_active TINYINT(1) DEFAULT 1,
  sort_order INT DEFAULT 0,
  UNIQUE KEY uniq_course_code (course_code)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

/* ===================================================================
   PUBLIC API (ไม่บังคับล็อกอิน) - **ส่วนสำคัญที่แก้ไข**
   =================================================================== */
// FIXED: Added 'programs_by_major' and 'groups_by_program' to the list of allowed public ajax calls
if (isset($_GET['ajax']) && in_array($_GET['ajax'], ['meta','majors_by_faculty','programs_by_major','groups_by_program'], true)) {
  header('Content-Type: application/json; charset=utf-8');
  
  $getOpts = function(PDO $pdo, $type, $parent=null){
    $sql = "SELECT value,label FROM form_options WHERE type=? AND is_active=1"; $params = [$type];
    if ($parent!==null && $parent!==''){ $sql .= " AND parent_value=?"; $params[] = $parent; }
    $sql .= " ORDER BY sort_order, label";
    $st=$pdo->prepare($sql); $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  };

  $ajaxAction = $_GET['ajax'];

  if ($ajaxAction === 'majors_by_faculty') {
    $fac = $_GET['faculty'] ?? '';
    echo json_encode(['majors'=>$getOpts($pdo,'major',$fac)], JSON_UNESCAPED_UNICODE); 
    exit;
  }
  
  // NEW: Handle request for programs based on major
  if ($ajaxAction === 'programs_by_major') {
    $major = $_GET['major'] ?? '';
    echo json_encode(['programs'=>$getOpts($pdo,'program',$major)], JSON_UNESCAPED_UNICODE);
    exit;
  }
  
  // NEW: Handle request for student groups based on program
  if ($ajaxAction === 'groups_by_program') {
    $program = $_GET['program'] ?? '';
    echo json_encode(['groups'=>$getOpts($pdo,'student_group',$program)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Default meta request
  if ($ajaxAction === 'meta') {
      echo json_encode([
        'faculties' => $getOpts($pdo,'faculty'),
        'levels'    => $getOpts($pdo,'education_level'),
        'ptypes'    => $getOpts($pdo,'program_type'),
        'curnames'  => $getOpts($pdo,'curriculum_name'),
        'curyears'  => $getOpts($pdo,'curriculum_year'),
        'statuses'  => $getOpts($pdo,'student_status'),
        'terms'     => $getOpts($pdo,'education_term'),
      ], JSON_UNESCAPED_UNICODE);
      exit;
  }
}


/* ===== Utils / Auth (ส่วนนี้จะทำงานเฉพาะเมื่อไม่ใช่ Public API Call) ===== */
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
function flash($t,$m){ $_SESSION['flash'][]=['t'=>$t,'m'=>$m]; }
$flashes = $_SESSION['flash'] ?? []; unset($_SESSION['flash']);
if (empty($_SESSION['loggedin'])) { header('Location: login.php?error=unauthorized'); exit; }

/* ===== Router: เลือกแท็บ ===== */
$view = $_GET['view'] ?? 'courses';

/* ===== Global option lists for dropdowns & mapping (ใช้ได้ทั้งสองแท็บ + modal) ===== */
$all_opts_rows = $pdo->query("SELECT value,label,type FROM form_options WHERE is_active=1 ORDER BY type, sort_order, label")->fetchAll(PDO::FETCH_ASSOC);
$optByType = [];
$value_to_label_map_global = [];
foreach($all_opts_rows as $row){
  $optByType[$row['type']][] = $row;
  $value_to_label_map_global[$row['value']] = $row['label'];
}

/* ===========================================================
   ส่วนของ Admin Panel (Courses & Options) ไม่มีการเปลี่ยนแปลง
   =========================================================== */

if ($view === 'courses') {
  // ... โค้ดส่วนจัดการรายวิชา ...
}

if ($view === 'options') {
  // ... โค้ดส่วนจัดการตัวเลือก ...
}

/* ===== HTML, CSS, JavaScript ของ Admin Panel ไม่มีการเปลี่ยนแปลง ===== */
// ... The rest of the file ...
?>