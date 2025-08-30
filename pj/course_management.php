<?php 
/* ===========================================================
   manage_courses_and_options.php (v5 - Bulk Add Feature)
   - Courses: Added bulk add via textarea
   - Options: Added bulk add for general options
   - Student Group Hierarchy remains
   - REMOVED usage of: value, is_active, sort_order
   =========================================================== */
session_start();
$HOME_URL = 'admin_dashboard.php'; // ถ้าหน้าหลักชื่ออื่น ให้เปลี่ยนตรงนี้

/* ===== DB connect ===== */
$DB_HOST='127.0.0.1'; $DB_NAME='studentregistration'; $DB_USER='root'; $DB_PASS='';
try {
  $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch(PDOException $e){ die('DB Connection failed: '.htmlspecialchars($e->getMessage())); }

/* ===== Auto-migrate: form_options =====
   (ตัดคอลัมน์ value, is_active, sort_order และเปลี่ยน UNIQUE KEY ให้ใช้ label) */
$pdo->exec("CREATE TABLE IF NOT EXISTS form_options (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM(
    'faculty','major','program','education_level','program_type',
    'curriculum_name','curriculum_year','student_group',
    'student_status','education_term','education_year'
  ) NOT NULL,
  label VARCHAR(255) NOT NULL,
  parent_value VARCHAR(100) DEFAULT NULL,
  UNIQUE KEY uniq_type_label_parent (type, label, parent_value)
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

/* ===== Auto-migrate: courses =====
   (ตัดคอลัมน์ is_active, sort_order) */
$pdo->exec("CREATE TABLE IF NOT EXISTS courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_code VARCHAR(32) NOT NULL,
  course_name VARCHAR(255) NOT NULL,
  credits DECIMAL(3,1) DEFAULT 3.0,
  faculty_value VARCHAR(100) DEFAULT NULL,
  major_value   VARCHAR(100) DEFAULT NULL,
  program_value VARCHAR(100) DEFAULT NULL,
  UNIQUE KEY uniq_course_code (course_code)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

/* ===== Auto-migrate: courses =====
   (ตัดคอลัมน์ is_active, sort_order) */
$pdo->exec("CREATE TABLE IF NOT EXISTS courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_code VARCHAR(32) NOT NULL,
  course_name VARCHAR(255) NOT NULL,
  credits DECIMAL(3,1) DEFAULT 3.0,
  faculty_value VARCHAR(100) DEFAULT NULL,
  major_value   VARCHAR(100) DEFAULT NULL,
  program_value VARCHAR(100) DEFAULT NULL,
  -- ใหม่: อ้างอิงหลักสูตร
  curriculum_name_value VARCHAR(100) DEFAULT NULL,
  curriculum_year_value VARCHAR(100) DEFAULT NULL,
  UNIQUE KEY uniq_course_code (course_code)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

/* ถ้าตารางมีอยู่แล้ว ให้พยายามเพิ่มคอลัมน์ใหม่ (เงียบๆ ถ้ามีแล้ว) */
try { $pdo->exec("ALTER TABLE courses ADD COLUMN curriculum_name_value VARCHAR(100) NULL"); } catch(Throwable $e) { /* already */ }
try { $pdo->exec("ALTER TABLE courses ADD COLUMN curriculum_year_value VARCHAR(100) NULL"); } catch(Throwable $e) { /* already */ }


/* ---------- PUBLIC API (No changes needed here) ---------- */
if (isset($_GET['ajax']) && in_array($_GET['ajax'], ['meta','majors_by_faculty'], true)) {
  header('Content-Type: application/json; charset=utf-8');
  $getOpts = function(PDO $pdo, $type, $parent=null){
    $sql = "SELECT id AS value, label FROM form_options WHERE type=?";
    $params = [$type];
    if ($parent!==null){ $sql .= " AND parent_value=?"; $params[] = $parent; }
    $sql .= " ORDER BY label";
    $st=$pdo->prepare($sql); $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  };

    if ($_GET['ajax']==='majors_by_faculty') {
        $fac = $_GET['faculty'] ?? '';
        echo json_encode(['majors'=>$getOpts($pdo,'major',$fac)], JSON_UNESCAPED_UNICODE); exit;
    }
    
        echo json_encode([
        'faculties' => $getOpts($pdo,'faculty'),
        'levels'    => $getOpts($pdo,'education_level'),
        'ptypes'    => $getOpts($pdo,'program_type'),
        'curnames'  => $getOpts($pdo,'curriculum_name'),
        'curyears'  => $getOpts($pdo,'curriculum_year'),
        'groups'    => $getOpts($pdo,'student_group'),
        'statuses'  => $getOpts($pdo,'student_status'),
        'student_status' => $getOpts($pdo,'student_status'), // <-- เพิ่มบรรทัดนี้ถ้าฟรอนต์ใช้คีย์นี้
        'terms'     => $getOpts($pdo,'education_term'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

/* ===== Utils / Auth (No changes needed here) ===== */
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
function flash($t,$m){ $_SESSION['flash'][]=['t'=>$t,'m'=>$m]; }
$flashes = $_SESSION['flash'] ?? []; unset($_SESSION['flash']);
if (empty($_SESSION['loggedin'])) { header('Location: login.php?error=unauthorized'); exit; }

/* ===== Router: เลือกแท็บ (No changes needed here) ===== */
$view = $_GET['view'] ?? 'courses';

/* ---------- EXPORT CSV (courses/options) ---------- */
if (isset($_GET['export'])) {
  $what = $_GET['export'];
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$what.'_'.date('Ymd_His').'.csv"');
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'w');

  if ($what === 'courses') {
    fputcsv($out, ['course_code','course_name','credits','faculty_value','major_value','program_value','curriculum_name_value','curriculum_year_value']);
    $rs = $pdo->query("SELECT course_code,course_name,credits,faculty_value,major_value,program_value,curriculum_name_value,curriculum_year_value FROM courses ORDER BY course_code");
    while ($r = $rs->fetch(PDO::FETCH_NUM)) fputcsv($out, $r);
    fclose($out); exit;
  }
  if ($what === 'options') {
    fputcsv($out, ['id','type','label','parent_value']);
    $rs = $pdo->query("SELECT id,type,label,parent_value FROM form_options ORDER BY type, parent_value, label");
    while ($r = $rs->fetch(PDO::FETCH_NUM)) fputcsv($out, $r);
    fclose($out); exit;
  }
  fclose($out); exit;
}

/* ===== Global option lists (No changes needed here) ===== */
$all_opts_rows = $pdo->query(
  "SELECT id,label,type,parent_value FROM form_options ORDER BY type, label"
)->fetchAll(PDO::FETCH_ASSOC);
$optByType = [];
$value_to_label_map_global = [];
foreach($all_opts_rows as $row){
  $optByType[$row['type']][] = $row;
  $value_to_label_map_global[$row['id']] = $row['label'];
}

/* ===========================================================
   VIEW: COURSES (จัดการรายวิชา) - MODIFIED FOR BULK ADD
   =========================================================== */
if ($view === 'courses') {
  if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action_course'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'],$csrf)) { flash('danger','CSRF ไม่ถูกต้อง'); header('Location: '.$_SERVER['PHP_SELF'].'?view=courses'); exit; }
    $act = $_POST['action_course'];

    // ===== IMPORT COURSES from CSV =====
    if ($act === 'import_csv') {
      $csrf = $_POST['csrf_token'] ?? '';
      if (!hash_equals($_SESSION['csrf_token'],$csrf)) { flash('danger','CSRF ไม่ถูกต้อง'); header('Location: '.$_SERVER['PHP_SELF'].'?view=courses'); exit; }

      $faculty = trim($_POST['faculty_value'] ?? '') ?: null;
      $major   = trim($_POST['major_value'] ?? '') ?: null;
      $program = trim($_POST['program_value'] ?? '') ?: null;
      $curName = trim($_POST['curriculum_name_value'] ?? '') ?: null;
      $curYear = trim($_POST['curriculum_year_value'] ?? '') ?: null;

      if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        flash('danger', 'อัปโหลดไฟล์ไม่สำเร็จ'); header('Location: '.$_SERVER['PHP_SELF'].'?view=courses'); exit;
      }
      if ($_FILES['csv']['size'] > 2*1024*1024) {
        flash('danger','ไฟล์ใหญ่เกิน 2MB'); header('Location: '.$_SERVER['PHP_SELF'].'?view=courses'); exit;
      }

      $fh = fopen($_FILES['csv']['tmp_name'], 'r');
      if (!$fh) { flash('danger','เปิดไฟล์ไม่สำเร็จ'); header('Location: '.$_SERVER['PHP_SELF'].'?view=courses'); exit; }

      $success=0; $skipped=0; $line=0;
      $pdo->beginTransaction();
      try {
        $st = $pdo->prepare("INSERT IGNORE INTO courses(course_code,course_name,credits,faculty_value,major_value,program_value,curriculum_name_value,curriculum_year_value)
        VALUES (?,?,?,?,?,?,?,?)");

        while (($row = fgetcsv($fh)) !== false) {
          $line++;
          if ($line===1 && isset($_POST['has_header'])) { continue; }
          $code = trim($row[0] ?? '');
          $name = trim($row[1] ?? '');
          $credits = isset($row[2]) && is_numeric(trim($row[2])) ? (float)trim($row[2]) : 3.0;
          if ($code==='' || $name==='') { $skipped++; continue; }
          $st->execute([$code,$name,$credits,$faculty,$major,$program,$curName,$curYear]);
          $success += ($st->rowCount() > 0) ? 1 : 0;
        }
        fclose($fh);
        $pdo->commit();
        $msg = "นำเข้า courses สำเร็จ {$success} รายการ";
        if ($skipped>0) $msg .= " (ข้าม {$skipped})";
        flash('ok',$msg);
      } catch(Throwable $e){
        fclose($fh);
        $pdo->rollBack();
        flash('danger','เกิดข้อผิดพลาด: '.$e->getMessage());
      }

      header('Location: '.$_SERVER['PHP_SELF'].'?view=courses'); exit;
    }

    // ===== BULK ADD LOGIC =====
    if ($act === 'add_bulk') {
      $bulk_data = trim($_POST['bulk_courses_data'] ?? '');
      $faculty = trim($_POST['faculty_value'] ?? '') ?: null;
      $major   = trim($_POST['major_value'] ?? '') ?: null;
      $program = trim($_POST['program_value'] ?? '') ?: null;
      $curName = trim($_POST['curriculum_name_value'] ?? '') ?: null;
      $curYear = trim($_POST['curriculum_year_value'] ?? '') ?: null;

      if (empty($bulk_data)) {
        flash('danger', 'กรุณาใส่ข้อมูลรายวิชา');
      } else {
        $lines = preg_split('/\\r\\n|\\r|\\n/', $bulk_data);
        $success_count = 0;
        $error_lines = [];
        $pdo->beginTransaction();
        try {
         $st = $pdo->prepare("INSERT INTO courses(course_code,course_name,credits,faculty_value,major_value,program_value,curriculum_name_value,curriculum_year_value)
        VALUES (?,?,?,?,?,?,?,?)");
          foreach($lines as $i => $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $parts = str_getcsv($line);
            $code = trim($parts[0] ?? '');
            $name = trim($parts[1] ?? '');
            $credits = isset($parts[2]) && is_numeric(trim($parts[2])) ? (float)trim($parts[2]) : 3.0;

            if ($code === '' || $name === '') {
              $error_lines[] = ($i + 1);
              continue;
            }
            $st->execute([$code,$name,$credits,$faculty,$major,$program,$curName,$curYear]);
            $success_count++;
          }

          if (!empty($error_lines)) {
             $pdo->rollBack();
             flash('danger', 'ข้อมูลไม่ถูกต้องในบรรทัดที่: ' . implode(', ', $error_lines) . ' | การเพิ่มทั้งหมดถูกยกเลิก');
          } else {
            $pdo->commit();
            flash('ok', "เพิ่มรายวิชาสำเร็จ {$success_count} รายการ");
          }
        } catch(Throwable $e) {
          $pdo->rollBack();
          flash('danger', 'เกิดข้อผิดพลาดร้ายแรง: ' . $e->getMessage());
        }
      }
    }
    // ===== END: BULK ADD LOGIC =====
    
    if ($act === 'edit') {
      $id = (int)($_POST['id'] ?? 0);
      $code = trim($_POST['course_code'] ?? '');
      $name = trim($_POST['course_name'] ?? '');
      $credits = (float)($_POST['credits'] ?? 3.0);
      $faculty = trim($_POST['faculty_value'] ?? '') ?: null;
      $major   = trim($_POST['major_value'] ?? '') ?: null;
      $program = trim($_POST['program_value'] ?? '') ?: null;
      $curName = trim($_POST['curriculum_name_value'] ?? '') ?: null;
      $curYear = trim($_POST['curriculum_year_value'] ?? '') ?: null;

      if ($code==='' || $name==='') { flash('danger','กรอก “รหัสวิชา” และ “ชื่อวิชา”'); }
      else {
        try{
            $st = $pdo->prepare("UPDATE coursesSET course_code=?, course_name=?, credits=?, faculty_value=?, major_value=?, program_value=?, curriculum_name_value=?, curriculum_year_value=?WHERE id=?");
            $st->execute([$code,$name,$credits,$faculty,$major,$program,$curName,$curYear,$id]);
            flash('ok','แก้ไขรายวิชาเรียบร้อย');
        } catch(Throwable $e){ flash('danger','ดำเนินการไม่สำเร็จ: '.$e->getMessage()); }
      }
    }
    if ($act === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id>0) {
        $pdo->prepare("DELETE FROM courses WHERE id=?")->execute([$id]);
        flash('ok','ลบรายวิชาเรียบร้อย');
      }
    }
    header('Location: '.$_SERVER['PHP_SELF'].'?view=courses'); exit;
  }
  $faculties = $optByType['faculty'] ?? [];
  $majors    = $optByType['major'] ?? [];
  $programs  = $optByType['program'] ?? [];
  $curNames  = $optByType['curriculum_name'] ?? [];
$curYears  = $optByType['curriculum_year'] ?? [];

  $q = trim($_GET['q'] ?? '');
  $params=[]; $where='';
  if ($q !== '') { $where = "WHERE course_code LIKE ? OR course_name LIKE ?"; $params = ["%$q%","%$q%"]; }
  $st = $pdo->prepare("SELECT * FROM courses $where ORDER BY course_code");
  $st->execute($params);
  $courses = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ===========================================================
   VIEW: OPTIONS (จัดการตัวเลือกดรอปดาว) - MODIFIED FOR BULK ADD
   =========================================================== */
if ($view === 'options') {
  if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action_opt'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'],$csrf)) { flash('danger','CSRF ไม่ถูกต้อง'); header("Location: ".$_SERVER['PHP_SELF']."?view=options"); exit; }
    $act = $_POST['action_opt'];

    // ===== IMPORT OPTIONS from CSV (type,label,parent_value) =====
    if ($act === 'import_opts_csv') {
      $csrf = $_POST['csrf_token'] ?? '';
      if (!hash_equals($_SESSION['csrf_token'],$csrf)) { flash('danger','CSRF ไม่ถูกต้อง'); header("Location: ".$_SERVER['PHP_SELF']."?view=options"); exit; }

      if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        flash('danger','อัปโหลดไฟล์ไม่สำเร็จ'); header("Location: ".$_SERVER['PHP_SELF']."?view=options"); exit;
      }
      if ($_FILES['csv']['size'] > 2*1024*1024) {
        flash('danger','ไฟล์ใหญ่เกิน 2MB'); header("Location: ".$_SERVER['PHP_SELF']."?view=options"); exit;
      }

      $fh = fopen($_FILES['csv']['tmp_name'], 'r');
      if (!$fh) { flash('danger','เปิดไฟล์ไม่สำเร็จ'); header("Location: ".$_SERVER['PHP_SELF']."?view=options"); exit; }

      $hasHeader = !empty($_POST['has_header']);
      $success=0; $skipped=0; $line=0;

      $pdo->beginTransaction();
      try {
        $insertSt = $pdo->prepare("INSERT INTO form_options (type, label, parent_value) VALUES (?, ?, ?)");

        $allowed = ['faculty','major','program','education_level','program_type','curriculum_name','curriculum_year','student_group','student_status','education_term','education_year'];

        while (($row = fgetcsv($fh)) !== false) {
          $line++;
          if ($line===1 && $hasHeader) continue;

          $type   = trim($row[0] ?? '');
          $label  = trim($row[1] ?? '');
          $parent = trim($row[2] ?? '') ?: null;

          if ($type==='' || $label==='' || !in_array($type, $allowed, true)) { $skipped++; continue; }

          $insertSt->execute([$type, $label, $parent]);
          $success++;
        }
        fclose($fh);
        $pdo->commit();
        $msg = "นำเข้า options สำเร็จ {$success} รายการ";
        if ($skipped>0) $msg .= " (ข้าม {$skipped})";
        flash('ok',$msg);
      } catch(Throwable $e){
        fclose($fh);
        $pdo->rollBack();
        flash('danger','เกิดข้อผิดพลาด: '.$e->getMessage());
      }

      header("Location: ".$_SERVER['PHP_SELF']."?view=options"); exit;
    }

    // ===== BULK ADD LOGIC for general options (type + labels per line) =====
    if ($act==='add_bulk_opt') {
      $type = trim($_POST['opt_type'] ?? '');
      $bulk_labels = trim($_POST['bulk_opt_labels'] ?? '');
      if ($type === '' || $bulk_labels === '') {
        flash('danger', 'กรุณาเลือกชนิดและใส่ข้อมูลให้ครบ');
      } else {
        $lines = preg_split('/\\r\\n|\\r|\\n/', $bulk_labels);
        $success_count = 0;
        $pdo->beginTransaction();
        try {
          $st = $pdo->prepare("INSERT INTO form_options (type, label, parent_value) VALUES (?, ?, NULL)");
          foreach ($lines as $label) {
            $label = trim($label);
            if ($label==='') continue;
            $st->execute([$type, $label]);
            $success_count++;
          }
          $pdo->commit();
          flash('ok', "เพิ่มตัวเลือกสำเร็จ {$success_count} รายการ");
        } catch (Throwable $e) {
          $pdo->rollBack();
          flash('danger', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
      }
    }
    // ===== END: BULK ADD LOGIC =====

    if ($act==='add_opt'){ // hierarchical additions
      $type = trim($_POST['opt_type'] ?? '');
      $label = trim($_POST['opt_label'] ?? '');
      $parent = trim($_POST['opt_parent'] ?? '') ?: null;
      if ($type===''||$label===''){ flash('danger','กรอกข้อมูลให้ครบ'); }
      else {
        try {
          $st=$pdo->prepare("INSERT INTO form_options (type, label, parent_value) VALUES (?, ?, ?)");
          $st->execute([$type, $label, $parent]);
          flash('ok','เพิ่มตัวเลือกเรียบร้อย');
        }
        catch(Throwable $e){ flash('danger','ข้อมูลซ้ำ หรือไม่ถูกต้อง: ' . $e->getMessage()); }
      }
    }
    if ($act==='edit_opt') {
      $id = (int)($_POST['opt_id'] ?? 0);
      $label = trim($_POST['opt_label'] ?? '');
      $parent = trim($_POST['opt_parent'] ?? '') ?: null;
      if ($id > 0 && $label !== '') {
        try {
          $st = $pdo->prepare("UPDATE form_options SET label = ?, parent_value = ? WHERE id = ?");
          $st->execute([$label, $parent, $id]);
          flash('ok', 'แก้ไขข้อมูลเรียบร้อย');
        } catch (Throwable $e) { flash('danger', 'เกิดข้อผิดพลาดในการแก้ไข: ' . $e->getMessage()); }
      } else { flash('danger', 'ข้อมูลสำหรับแก้ไขไม่ถูกต้อง'); }
    }
    if ($act==='del_opt'){
      $id = (int)($_POST['opt_id'] ?? 0);
      $pdo->prepare("DELETE FROM form_options WHERE id=?")->execute([$id]);
      flash('ok','ลบข้อมูลเรียบร้อย');
    }
    header("Location: ".$_SERVER['PHP_SELF']."?view=options"); exit;
  }

  $opts_types = [
    'faculty' => 'คณะ', 'major' => 'สาขา', 'program' => 'สาขาวิชา', 'student_group' => 'กลุ่มนักศึกษา',
    'education_level' => 'ระดับการศึกษา', 'program_type' => 'ประเภทหลักสูตร', 'curriculum_name' => 'หลักสูตร',
    'curriculum_year' => 'ปีของหลักสูตร', 'student_status'  => 'สถานะ', 'education_term' => 'ภาคการศึกษา', 'education_year' => 'ปีการศึกษา',
  ];
  $all_opts = $pdo->query("SELECT * FROM form_options ORDER BY type, parent_value, label")->fetchAll(PDO::FETCH_ASSOC);
  $grouped_opts = [];
  $value_to_label_map = [];
  foreach ($all_opts as $o) {
    $grouped_opts[$o['type']][] = $o;
    $value_to_label_map[$o['id']] = $o['label'];
  }
  
  $faculties_for_dropdown = $grouped_opts['faculty'] ?? [];
  $majors_for_dropdown = $grouped_opts['major'] ?? [];
  $programs_for_dropdown = $grouped_opts['program'] ?? [];
}

/* ===== Variables for global modals (No changes needed here) ===== */
$faculties_for_modal = $optByType['faculty'] ?? [];
$majors_for_modal    = $optByType['major'] ?? [];
$programs_for_modal  = $optByType['program'] ?? [];

?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $view==='options'?'ตัวเลือกลงทะเบียน':'จัดการรายวิชา'; ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
/* (CSS เดิมทั้งหมด) */
:root{--navy:#0f1419;--steel:#1e293b;--slate:#334155;--sky:#0ea5e9;--cyan:#06b6d4;--emerald:#10b981;--amber:#f59e0b;--orange:#ea580c;--rose:#e11d48;--text:#f1f5f9;--muted:#94a3b8;--subtle:#64748b;--border:#374151;--glass:rgba(15,20,25,.85);--overlay:rgba(0,0,0,.6);--shadow-sm:0 2px 8px rgba(0,0,0,.1);--shadow:0 4px 20px rgba(0,0,0,.15);--shadow-lg:0 8px 32px rgba(0,0,0,.25);--gradient-primary:linear-gradient(135deg,var(--sky),var(--cyan));--gradient-secondary:linear-gradient(135deg,var(--slate),var(--steel));--gradient-accent:linear-gradient(135deg,var(--amber),var(--orange));--gradient-success:linear-gradient(135deg,var(--emerald),#059669);--gradient-danger:linear-gradient(135deg,var(--rose),#be123c);}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:'Sarabun',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:var(--text);background:radial-gradient(1200px 800px at 20% 0%, rgba(14,165,233,.08), transparent 65%),radial-gradient(1000px 600px at 80% 100%, rgba(6,182,212,.06), transparent 65%),conic-gradient(from 230deg at 0% 50%, #0f1419, #1e293b, #0f1419);min-height:100vh;line-height:1.6;}
.container{max-width:1400px;margin:0 auto;padding:24px;animation:fadeIn .6s ease-out;}
@keyframes fadeIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:32px;padding:20px 24px;border-radius:20px;background:var(--glass);backdrop-filter:blur(20px);border:1px solid var(--border);box-shadow:var(--shadow-lg);}
.brand{display:flex;align-items:center;gap:16px}
.brand .logo{width:48px;height:48px;border-radius:16px;background:var(--gradient-primary);box-shadow:var(--shadow);display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;}
.brand .logo::before{content:'📚';font-size:24px;z-index:1;position:relative;}
.brand .logo::after{content:'';position:absolute;inset:0;background:linear-gradient(45deg,transparent,rgba(255,255,255,.1),transparent);animation:shimmer 3s infinite;}
@keyframes shimmer{0%,100%{transform:translateX(-100%)}50%{transform:translateX(100%)}}
.brand .title{font-weight:800;font-size:26px;background:var(--gradient-primary);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.nav{display:flex;gap:4px;padding:6px;background:rgba(15,20,25,.4);border-radius:16px;backdrop-filter:blur(10px);border:1px solid var(--border)}
.tab{padding:12px 20px;border-radius:12px;text-decoration:none;color:var(--muted);font-weight:600;transition:all .3s cubic-bezier(.4,0,.2,1);position:relative;overflow:hidden;}
.tab::before{content:'';position:absolute;inset:0;background:var(--gradient-primary);opacity:0;transition:opacity .3s ease;}
.tab span{position:relative;z-index:1}
.tab:hover{color:var(--text);transform:translateY(-1px)}
.tab:hover::before{opacity:.1}
.tab.active{color:#fff;background:var(--gradient-primary);box-shadow:var(--shadow)}
.tab.active::before{opacity:1}
.header{display:grid;grid-template-columns:1fr auto;gap:24px;align-items:start;margin-bottom:24px;padding:24px;border-radius:20px;background:var(--glass);backdrop-filter:blur(20px);border:1px solid var(--border);}
.header-content h2{font-size:32px;font-weight:800;margin-bottom:8px;background:var(--gradient-primary);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.header-content p{color:var(--muted);font-size:16px;line-height:1.5}
.input,.select,textarea{width:100%;padding:14px 16px;border-radius:14px;border:1px solid var(--border);background:rgba(15,20,25,.6);color:var(--text);outline:none;font-size:14px;transition:all .3s ease;backdrop-filter:blur(10px);font-family:inherit;}
.input:focus,.select:focus,textarea:focus{border-color:var(--sky);box-shadow:0 0 0 3px rgba(14,165,233,.2);background:rgba(15,20,25,.8);}
.btn{padding:14px 20px;border-radius:14px;border:1px solid var(--border);cursor:pointer;text-decoration:none;font-weight:600;font-size:14px;display:inline-flex;align-items:center;gap:10px;transition:all .3s cubic-bezier(.4,0,.2,1);position:relative;overflow:hidden;backdrop-filter:blur(10px);}
.btn:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
.primary{background:var(--gradient-primary);color:#fff;border-color:var(--sky)}
.secondary{background:var(--gradient-secondary);color:var(--text);border-color:var(--slate)}
.danger{background:var(--gradient-danger);color:#fff;border-color:var(--rose)}
.success{background:var(--gradient-success);color:#fff;border-color:var(--emerald)}
.btn-icon{width:44px;height:44px;padding:0;display:flex;align-items:center;justify-content:center}
.card{background:var(--glass);border:1px solid var(--border);border-radius:24px;padding:28px;backdrop-filter:blur(20px);box-shadow:var(--shadow-lg);position:relative;overflow:hidden;}
.table-wrap{position:relative;overflow:auto;border-radius:16px;box-shadow:inset 0 1px 0 rgba(255,255,255,.05);}
.table{width:100%;border-collapse:separate;border-spacing:0;}
.table thead th{position:sticky;top:0;background:rgba(15,20,25,.95);backdrop-filter:blur(20px);border-bottom:2px solid var(--border);padding:16px 20px;text-align:left;font-weight:700;color:var(--text);font-size:14px;text-transform:uppercase;letter-spacing:.5px;}
.table tbody td{padding:16px 20px;border-bottom:1px solid rgba(55,65,81,.3);vertical-align:middle;}
.table tbody tr{transition:all .3s ease;}
.table tbody tr:hover{background:rgba(14,165,233,.03);box-shadow:inset 0 0 0 1px rgba(14,165,233,.1);}
.badge{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:20px;font-size:12px;font-weight:600;background:var(--gradient-secondary);border:1px solid var(--border);box-shadow:var(--shadow-sm);}
.alert{padding:16px 20px;border-radius:16px;margin:16px 0;border:1px solid;display:flex;align-items:center;gap:12px;backdrop-filter:blur(10px);font-weight:500;animation:slideIn .4s ease-out;}
@keyframes slideIn{from{opacity:0;transform:translateX(-20px)}to{opacity:1;transform:translateX(0)}
}
.ok{background:rgba(16,185,129,.15);border-color:rgba(16,185,129,.3);color:var(--emerald);}
.ok::before{content:'✅'}
.dangerBox{background:rgba(225,29,72,.15);border-color:rgba(225,29,72,.3);color:var(--rose);}
.dangerBox::before{content:'⚠️'}
.modal{display:none;position:fixed;inset:0;background:var(--overlay);z-index:1000;padding:20px;backdrop-filter:blur(8px);animation:modalFadeIn .3s ease-out;overflow-y:auto; align-items:center; justify-content:center;}
@keyframes modalFadeIn{from{opacity:0}to{opacity:1}}
.modal-content{background:linear-gradient(145deg,var(--navy),var(--steel));color:var(--text);margin:0 auto;padding:32px;border-radius:24px;width:100%;max-width:600px;box-shadow:var(--shadow-lg);border:1px solid var(--border);backdrop-filter:blur(20px);animation:modalSlideIn .3s ease-out;}
@keyframes modalSlideIn{from{opacity:0;transform:scale(.9) translateY(20px)}to{opacity:1;transform:scale(1) translateY(0)}}
.modal-header{display:flex;justify-content:space-between;align-items:center;padding-bottom:20px;border-bottom:2px solid var(--border);margin-bottom:24px;}
.modal-header h3{font-size:24px;font-weight:800;background:var(--gradient-primary);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.close{font-size:24px;cursor:pointer;color:var(--muted);width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;transition:all .3s ease;background:rgba(15,20,25,.4);}
.close:hover{color:var(--text);background:rgba(225,29,72,.2);transform:scale(1.1)}
.form-field{display:flex;flex-direction:column;gap:8px; margin-bottom: 20px;}
.form-field:last-child{margin-bottom:0;}
.form-field label{font-weight:600;color:var(--text);font-size:14px;text-transform:uppercase;letter-spacing:.5px;}
.form-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:24px;padding-top:20px;border-top:1px solid var(--border);}
@media (max-width: 768px){.modal-content{padding:20px;}}
</style>
</head>
<body>
<div class="container">
  <div class="topbar">
    <div class="brand"><div class="logo"></div><h1 class="title">Student Registration Admin</h1></div>
    <nav class="nav">
      <a href="<?php echo e($HOME_URL); ?>" class="btn secondary" style="white-space:nowrap">🏠 ไปหน้าหลัก</a>
      <a class="tab <?php echo $view==='courses'?'active':''; ?>" href="<?php echo e($_SERVER['PHP_SELF']); ?>?view=courses"><span>📚 จัดการรายวิชา</span></a>
      <a class="tab <?php echo $view==='options'?'active':''; ?>" href="<?php echo e($_SERVER['PHP_SELF']); ?>?view=options"><span>⚙️ ตัวเลือกลงทะเบียน</span></a>
    </nav>
  </div>

  <?php foreach ($flashes as $f): ?><div class="alert <?php echo $f['t']==='ok'?'ok':'dangerBox'; ?>"><?php echo e($f['m']); ?></div><?php endforeach; ?>

  <?php if ($view==='courses'): ?>
    <!-- ===== COURSES UI ===== -->
    <div class="header">
      <div class="header-content"><h2>จัดการรายวิชา</h2><p>เพิ่ม/แก้ไข/ลบรายวิชา และกรองดูด้วยช่องค้นหา</p></div>
      <form method="get" style="display:flex; gap:12px; align-items:end">
        <input type="hidden" name="view" value="courses">
        <div class="form-field" style="margin-bottom:0;"><label for="q">ค้นหา</label><input id="q" name="q" class="input" placeholder="รหัส/ชื่อวิชา" value="<?php echo e($_GET['q'] ?? ''); ?>"></div>
        <button class="btn secondary">🔎 ค้นหา</button>
      </form>
    </div>

    <!-- ===== EXPORT & IMPORT (COURSES) ===== -->
    <div class="card" style="margin-bottom:24px; display:grid; gap:16px;">
      <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
        <a class="btn secondary" href="<?php echo e($_SERVER['PHP_SELF']); ?>?view=courses&export=courses">⬇️ ส่งออก CSV (รายวิชา)</a>
      </div>

      <form method="post" enctype="multipart/form-data" style="display:grid; grid-template-columns: 1fr; gap:12px;">
        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action_course" value="import_csv">

        <div class="form-field">
          <label for="csv">นำเข้า CSV (course_code,course_name,credits)</label>
          <div class="form-field" style="margin-bottom:0;">
  <label>ชื่อหลักสูตร (ผูกให้ทุกแถว)</label>
  <select name="curriculum_name_value" class="select">
    <option value="">— ไม่ระบุ —</option>
    <?php foreach($curNames as $cn): ?>
      <option value="<?php echo e($cn['id']); ?>"><?php echo e($cn['label']); ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="form-field" style="margin-bottom:0;">
  <label>ปีหลักสูตร (ผูกให้ทุกแถว)</label>
  <select name="curriculum_year_value" class="select">
    <option value="">— ไม่ระบุ —</option>
    <?php foreach($curYears as $cy): ?>
      <option value="<?php echo e($cy['id']); ?>"><?php echo e($cy['label']); ?></option>
    <?php endforeach; ?>
  </select>
</div>

          <input id="csv" name="csv" type="file" class="input" accept=".csv,text/csv" required>
          <label style="display:flex;gap:8px;align-items:center;margin-top:8px;">
            <input type="checkbox" name="has_header" value="1" style="width:auto"> ไฟล์มีแถวหัวตาราง (Header)
          </label>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
          <div class="form-field" style="margin-bottom:0;"><label>คณะ (ผูกให้ทุกแถว)</label><select name="faculty_value" class="select"><option value="">— ไม่ระบุ —</option><?php foreach($faculties as $f): ?><option value="<?php echo e($f['id']); ?>"><?php echo e($f['label']); ?></option><?php endforeach; ?></select></div>
          <div class="form-field" style="margin-bottom:0;"><label>สาขา (ผูกให้ทุกแถว)</label><select name="major_value" class="select"><option value="">— ไม่ระบุ —</option><?php foreach($majors as $m): ?><option value="<?php echo e($m['id']); ?>"><?php echo e($m['label']); ?></option><?php endforeach; ?></select></div>
          <div class="form-field" style="margin-bottom:0;"><label>สาขาวิชา (ผูกให้ทุกแถว)</label><select name="program_value" class="select"><option value="">— ไม่ระบุ —</option><?php foreach($programs as $p): ?><option value="<?php echo e($p['id']); ?>"><?php echo e($p['label']); ?></option><?php endforeach; ?></select></div>
        </div>

        <div style="display:flex; justify-content:flex-end;">
          <button class="btn success">📤 นำเข้ารายวิชา (CSV)</button>
        </div>
      </form>
    </div>

    <div class="card" style="margin-bottom:24px;">
      <h3 style="font-size:18px;font-weight:700;margin-bottom:16px;color:var(--emerald)">➕ เพิ่มรายวิชา (ครั้งละหลายรายการ)</h3>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action_course" value="add_bulk">
        
        <div class="form-field">
            <label for="bulk_courses_data">ข้อมูลรายวิชา (แต่ละวิชาขึ้นบรรทัดใหม่)</label>
            <textarea id="bulk_courses_data" name="bulk_courses_data" class="input" rows="8" placeholder="ตัวอย่าง:
                CS101,Introduction to Computer Science,3
                MA203,Calculus III,3
                PH101,General Physics I,2.5"></textarea>
            <p style="font-size:12px; color:var(--muted); margin-top:8px;">
              รูปแบบ: <strong>รหัสวิชา,ชื่อวิชา,หน่วยกิต</strong> (หากไม่ใส่หน่วยกิต จะเป็น 3.0 อัตโนมัติ)
            </p>
        </div>
<div class="form-field" style="margin-bottom:0;">
  <label>ชื่อหลักสูตร (สำหรับทุกวิชาที่เพิ่ม)</label>
  <select name="curriculum_name_value" class="select">
    <option value="">— ไม่ระบุ —</option>
    <?php foreach($curNames as $cn): ?>
      <option value="<?php echo e($cn['id']); ?>"><?php echo e($cn['label']); ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="form-field" style="margin-bottom:0;">
  <label>ปีหลักสูตร (สำหรับทุกวิชาที่เพิ่ม)</label>
  <select name="curriculum_year_value" class="select">
    <option value="">— ไม่ระบุ —</option>
    <?php foreach($curYears as $cy): ?>
      <option value="<?php echo e($cy['id']); ?>"><?php echo e($cy['label']); ?></option>
    <?php endforeach; ?>
  </select>
</div>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-top:16px;">
            <div class="form-field" style="margin-bottom:0;"><label>สังกัดคณะ (สำหรับทุกวิชาที่เพิ่ม)</label><select name="faculty_value" class="select"><option value="">— ไม่ระบุ —</option><?php foreach($faculties as $f): ?><option value="<?php echo e($f['id']); ?>"><?php echo e($f['label']); ?></option><?php endforeach; ?></select></div>
            <div class="form-field" style="margin-bottom:0;"><label>สังกัดสาขา (สำหรับทุกวิชาที่เพิ่ม)</label><select name="major_value" class="select"><option value="">— ไม่ระบุ —</option><?php foreach($majors as $m): ?><option value="<?php echo e($m['id']); ?>"><?php echo e($m['label']); ?></option><?php endforeach; ?></select></div>
            <div class="form-field" style="margin-bottom:0;"><label>สังกัดสาขาวิชา (สำหรับทุกวิชาที่เพิ่ม)</label><select name="program_value" class="select"><option value="">— ไม่ระบุ —</option><?php foreach($programs as $p): ?><option value="<?php echo e($p['id']); ?>"><?php echo e($p['label']); ?></option><?php endforeach; ?></select></div>
        </div>
        
        <div style="display:flex; justify-content:flex-end; margin-top:20px;">
            <button class="btn primary">➕ เพิ่มรายวิชาทั้งหมด</button>
        </div>
        
      </form>
    </div>

    <div class="card"><div class="table-wrap"><table class="table">
      <thead><tr><th style="width:80px">#</th><th style="width:140px">รหัสวิชา</th><th>ชื่อวิชา</th><th style="width:100px">หน่วยกิต</th><th>สังกัด</th><th style="width:140px">จัดการ</th></tr></thead>
      <tbody>
      <?php if (!empty($courses)): $n=1; foreach($courses as $c): ?>
        <tr>
          <td style="text-align:center;color:var(--muted);font-weight:600;"><?php echo $n++; ?>.</td>
          <td style="font-weight:700;"><?php echo e($c['course_code']); ?></td>
          <td><?php echo e($c['course_name']); ?></td>
          <td><?php echo e((float)$c['credits']); ?></td>
          <td><?php
            $parts = [];
            if (!empty($c['faculty_value'])) { $parts[] = $value_to_label_map_global[$c['faculty_value']] ?? $c['faculty_value']; }
            if (!empty($c['major_value']))   { $parts[] = $value_to_label_map_global[$c['major_value']] ?? $c['major_value']; }
            if (!empty($c['program_value'])) { $parts[] = $value_to_label_map_global[$c['program_value']] ?? $c['program_value']; }
            if (!empty($c['curriculum_name_value'])) { $parts[] = 'หลักสูตร: '.($value_to_label_map_global[$c['curriculum_name_value']] ?? $c['curriculum_name_value']); }
            if (!empty($c['curriculum_year_value'])) { $parts[] = 'ปี: '.($value_to_label_map_global[$c['curriculum_year_value']] ?? $c['curriculum_year_value']); }
            echo $parts ? '<span class="badge">'.e(implode(' › ', $parts)).'</span>' : '<span style="color:var(--subtle);font-size:12px">—</span>';
          ?></td>
          <td><div style="display:flex; gap:8px;">
            <button class="btn secondary btn-icon" onclick="openCourseEditModal(this)"
              data-id="<?php echo (int)($c['id'] ?? 0); ?>"
              data-code="<?php echo e($c['course_code'] ?? ''); ?>"
              data-name="<?php echo e($c['course_name'] ?? ''); ?>"
              data-credits="<?php echo e(isset($c['credits']) ? (string)(float)$c['credits'] : '3.0'); ?>"
              data-faculty="<?php echo e($c['faculty_value'] ?? ''); ?>"
              data-major="<?php echo e($c['major_value'] ?? ''); ?>"
              data-curname="<?php echo e($c['curriculum_name_value'] ?? ''); ?>"
              data-curyear="<?php echo e($c['curriculum_year_value'] ?? ''); ?>"
              data-program="<?php echo e($c['program_value'] ?? ''); ?>">✏️</button>
            <form method="post" onsubmit="return confirm('ลบวิชา: <?php echo e(addslashes(($c['course_code'] ?? '').' - '.($c['course_name'] ?? ''))); ?> ?')" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="action_course" value="delete">
              <input type="hidden" name="id" value="<?php echo (int)($c['id'] ?? 0); ?>">
              <button class="btn danger btn-icon">🗑️</button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="6" style="text-align:center;color:var(--muted)">ยังไม่มีข้อมูลรายวิชา</td></tr>
      <?php endif; ?>
      </tbody></table></div></div>
  <?php else: ?>
    <!-- ===== OPTIONS UI ===== -->
    <div class="header"><div class="header-content"><h2>ลงทะเบียนหลักสูตร</h2><p>จัดการตัวเลือกทั้งหมดที่ใช้ในฟอร์มลงทะเบียนนักศึกษา</p></div></div>
    <div class="card">
      <!-- ===== EXPORT & IMPORT (OPTIONS) ===== -->
      <div class="card" style="margin-bottom:24px; display:grid; gap:16px;">
        <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
          <a class="btn secondary" href="<?php echo e($_SERVER['PHP_SELF']); ?>?view=options&export=options">⬇️ ส่งออก CSV (ตัวเลือกทั้งหมด)</a>
        </div>

        <form method="post" enctype="multipart/form-data" style="display:grid; grid-template-columns: 1fr; gap:12px;">
          <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="action_opt" value="import_opts_csv">
          <div class="form-field">
            <label for="opts_csv">นำเข้า CSV (type,label,parent_value)</label>
            <input id="opts_csv" name="csv" type="file" class="input" accept=".csv,text/csv" required>
            <label style="display:flex;gap:8px;align-items:center;margin-top:8px;">
              <input type="checkbox" name="has_header" value="1" style="width:auto"> ไฟล์มีแถวหัวตาราง (Header)
            </label>
            <p style="font-size:12px;color:var(--muted);margin-top:6px;">
              * ชนิด (type) ต้องเป็นหนึ่งใน: faculty, major, program, education_level, program_type, curriculum_name, curriculum_year, student_group, student_status, education_term, education_year
            </p>
          </div>
          <div style="display:flex; justify-content:flex-end;">
            <button class="btn success">📤 นำเข้าตัวเลือก (CSV)</button>
          </div>
        </form>
      </div>

      <div style="margin-bottom:24px;padding:20px;background:rgba(16,185,129,.05);border-radius:16px;border:1px solid rgba(16,185,129,.2)">
        <h3 style="font-size:18px;font-weight:700;margin-bottom:12px;color:var(--emerald)">➕ เพิ่มข้อมูลของคุณ</h3>
        <form method="post" style="display:grid; grid-template-columns: 1fr 2fr auto; gap:16px; align-items:end">
          <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="action_opt" value="add_bulk_opt">
          <div class="form-field" style="margin-bottom:0;">
            <label for="opt_type">ตัวเลือกลงทะเบียน</label>
            <select id="opt_type" name="opt_type" class="select" required>
              <option value="">— เลือก —</option>
              <?php $parent_types = ['major', 'program', 'student_group']; foreach($opts_types as $k=>$v): if(in_array($k, $parent_types)) continue; ?>
                <option value="<?php echo e($k); ?>"><?php echo e($v); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-field" style="margin-bottom:0;">
            <label for="bulk_opt_labels">ชื่อข้อมูล (ใส่ทีละบรรทัด)</label>
            <textarea id="bulk_opt_labels" class="input" name="bulk_opt_labels" rows="5" placeholder="เช่น
                ปริญญาตรี
                ปริญญาโท
                ปริญญาเอก" required></textarea>
          </div>
          <div class="form-field" style="margin-bottom:0;"><button class="btn primary" style="width:100%">➕ เพิ่มทั้งหมด</button></div>
        </form>
      </div>

      <div style="display:grid; gap: 24px;">
        <?php if (!empty($grouped_opts)): foreach($opts_types as $type => $type_label): if (!isset($grouped_opts[$type])) continue; $options = $grouped_opts[$type]; ?>
        <div class="card" style="padding: 20px;">
          <h3 style="font-size: 20px; font-weight:700; margin-bottom:16px; color:var(--sky)"><?php echo e($type_label); ?></h3>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th style="width:80px">#</th><th>ชื่อ</th><?php if (in_array($type, ['major', 'program', 'student_group'])): ?><th style="width:220px">สังกัด</th><?php endif; ?><th style="width:140px">จัดการ</th></tr></thead>
              <tbody>
                <?php $i = 1; foreach($options as $o): ?>
                <tr>
                  <td style="text-align:center; font-weight: 600; color: var(--muted)"><?php echo $i++; ?>.</td>
                  <td style="font-weight:600"><?php echo e($o['label']); ?><span style="font-family:monospace; font-size:11px;">ID: <?php echo e($o['id']); ?></span></td>
                  <?php if (in_array($type, ['major', 'program', 'student_group'])): ?>
                  <td>
                    <?php if($o['parent_value']): $parent_label = $value_to_label_map[$o['parent_value']] ?? $o['parent_value']; ?>
                      <span class="badge" style="background:rgba(245,158,11,.15);color:var(--amber);"><?php echo e($parent_label); ?></span>
                    <?php else: ?>
                      <span style="color:var(--subtle);font-size:12px">—</span>
                    <?php endif; ?>
                  </td>
                  <?php endif; ?>
                  <td>
                    <div style="display:flex; gap: 8px;">
                      <button class="btn secondary btn-icon" onclick="openOptionEditModal(this)" data-id="<?php echo (int)$o['id']; ?>" data-label="<?php echo e($o['label']); ?>" data-type="<?php echo e($o['type']); ?>" data-parent="<?php echo e($o['parent_value']); ?>">✏️</button>
                      <form method="post" onsubmit="return confirm('⚠️ ลบ: <?php echo e(addslashes($o['label'])); ?> ?')" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action_opt" value="del_opt">
                        <input type="hidden" name="opt_id" value="<?php echo (int)$o['id']; ?>">
                        <button class="btn danger btn-icon">🗑️</button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($type === 'faculty'): ?>
            <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)">
               <h4 style="font-size:16px;font-weight:600;margin-bottom:12px;color:var(--cyan)">➕ เพิ่มสาขาใหม่ (ทีละรายการ)</h4>
               <form method="post" style="display:grid; grid-template-columns: 1fr 2fr auto; gap:16px; align-items:end">
                 <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                 <input type="hidden" name="action_opt" value="add_opt">
                 <input type="hidden" name="opt_type" value="major">
                 <div class="form-field" style="margin-bottom:0;">
                   <label for="opt_parent_major">สังกัดคณะ</label>
                   <select id="opt_parent_major" name="opt_parent" class="select" required>
                     <option value="">— เลือกคณะ —</option>
                     <?php foreach($faculties_for_dropdown as $fac): ?><option value="<?php echo e($fac['id']); ?>"><?php echo e($fac['label']); ?></option><?php endforeach; ?>
                   </select>
                 </div>
                 <div class="form-field" style="margin-bottom:0;"><label for="opt_label_major">ชื่อสาขา</label><input id="opt_label_major" class="input" name="opt_label" placeholder="เช่น วิทยาการคอมพิวเตอร์" required></div>
                 <div class="form-field" style="margin-bottom:0;"><button class="btn primary" style="width:100%">➕ เพิ่มสาขา</button></div>
               </form>
            </div>
          <?php endif; ?>
          
          <?php if ($type === 'major'): ?>
            <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)"><h4 style="font-size:16px;font-weight:600;margin-bottom:12px;color:var(--emerald)">➕ เพิ่มสาขาวิชาใหม่ (ทีละรายการ)</h4>
               <form method="post" style="display:grid; grid-template-columns: 1fr 2fr auto; gap:16px; align-items:end">
                 <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                 <input type="hidden" name="action_opt" value="add_opt">
                 <input type="hidden" name="opt_type" value="program">
                 <div class="form-field" style="margin-bottom:0;">
                   <label for="opt_parent_program">สังกัดสาขา</label>
                   <select id="opt_parent_program" name="opt_parent" class="select" required>
                     <option value="">— เลือกสาขา —</option>
                     <?php foreach($majors_for_dropdown as $maj): ?><option value="<?php echo e($maj['id']); ?>"><?php echo e($maj['label']); ?></option><?php endforeach; ?>
                   </select>
                 </div>
                 <div class="form-field" style="margin-bottom:0;"><label for="opt_label_program">ชื่อสาขาวิชา</label><input id="opt_label_program" class="input" name="opt_label" placeholder="เช่น หลักสูตรปกติ, International Program" required></div>
                 <div class="form-field" style="margin-bottom:0;"><button class="btn success" style="width:100%">➕ เพิ่มสาขาวิชา</button></div>
               </form>
            </div>
          <?php endif; ?>
          <?php if ($type === 'program'): ?>
            <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)">
               <h4 style="font-size:16px;font-weight:600;margin-bottom:12px;color:var(--orange)">➕ เพิ่มกลุ่มนักศึกษาใหม่ (ทีละรายการ)</h4>
               <form method="post" style="display:grid; grid-template-columns: 1fr 2fr auto; gap:16px; align-items:end">
                 <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                 <input type="hidden" name="action_opt" value="add_opt">
                 <input type="hidden" name="opt_type" value="student_group">
                 <div class="form-field" style="margin-bottom:0;">
                   <label for="opt_parent_group">สังกัดสาขาวิชา</label>
                   <select id="opt_parent_group" name="opt_parent" class="select" required>
                     <option value="">— เลือกสาขาวิชา —</option>
                     <?php foreach($programs_for_dropdown as $prog): ?><option value="<?php echo e($prog['id']); ?>"><?php echo e($prog['label']); ?></option><?php endforeach; ?>
                   </select>
                 </div>
                 <div class="form-field" style="margin-bottom:0;"><label for="opt_label_group">ชื่อกลุ่มนักศึกษา</label><input id="opt_label_group" class="input" name="opt_label" placeholder="เช่น กลุ่ม 1, ส-อา, รอบเช้า" required></div>
                 <div class="form-field" style="margin-bottom:0;"><button class="btn" style="background:var(--gradient-accent);color:var(--navy);border-color:var(--amber);width:100%">➕ เพิ่มกลุ่ม</button></div>
               </form>
            </div>
          <?php endif; ?>

        </div>
        <?php endforeach; else: ?>
        <div style="text-align:center;padding:40px;color:var(--muted)"><div style="font-size:48px;margin-bottom:16px">⚙️</div><div style="font-size:18px;font-weight:600">ยังไม่มีตัวเลือก</div><div style="font-size:14px;margin-top:8px">เริ่มต้นด้วยการเพิ่มตัวเลือกใหม่ข้างบน</div></div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- ===== Modal: แก้ไขตัวเลือก ===== -->
<div id="optionEditModal" class="modal">
  <div class="modal-content">
    <div class="modal-header"><h3 id="editModalTitle">แก้ไขข้อมูล</h3><button class="close" onclick="closeModal('optionEditModal')" aria-label="ปิด">&times;</button></div>
    <form method="post" id="optionEditForm">
        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action_opt" value="edit_opt">
        <input type="hidden" name="opt_id" id="edit_opt_id">
        <div class="form-field"><label for="edit_opt_label">ชื่อข้อมูล</label><input type="text" name="opt_label" id="edit_opt_label" class="input" required></div>
        <div id="edit_parent_major_container" class="form-field" style="display:none;"><label for="edit_opt_parent_major">สังกัดคณะ</label><select name="opt_parent" id="edit_opt_parent_major" class="select"><?php foreach ($faculties_for_modal as $fac): ?><option value="<?php echo e($fac['id']); ?>"><?php echo e($fac['label']); ?></option><?php endforeach; ?></select></div>
        <div id="edit_parent_program_container" class="form-field" style="display:none;"><label for="edit_opt_parent_program">สังกัดสาขา</label><select name="opt_parent" id="edit_opt_parent_program" class="select"><?php foreach ($majors_for_modal as $maj): ?><option value="<?php echo e($maj['id']); ?>"><?php echo e($maj['label']); ?></option><?php endforeach; ?></select></div>
        <div id="edit_parent_group_container" class="form-field" style="display:none;"><label for="edit_opt_parent_group">สังกัดสาขาวิชา</label><select name="opt_parent" id="edit_opt_parent_group" class="select"><?php foreach ($programs_for_modal as $prog): ?><option value="<?php echo e($prog['id']); ?>"><?php echo e($prog['label']); ?></option><?php endforeach; ?></select></div>
        <div class="form-actions"><button type="button" class="btn secondary" onclick="closeModal('optionEditModal')">❌ ยกเลิก</button><button type="submit" class="btn primary">💾 บันทึก</button></div>
    </form>
  </div>
</div>

<!-- ===== Modal: แก้ไขรายวิชา ===== -->
<div id="courseEditModal" class="modal">
  <div class="modal-content">
    <div class="modal-header"><h3 id="courseEditTitle">แก้ไขรายวิชา</h3><button class="close" onclick="closeModal('courseEditModal')" aria-label="ปิด">&times;</button></div>
    <form method="post" id="courseEditForm">
      <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
      <input type="hidden" name="action_course" value="edit">
      <input type="hidden" name="id" id="course_edit_id">
      <div class="form-field"><label>รหัสวิชา</label><input class="input" name="course_code" id="course_edit_code" required></div>
      <div class="form-field"><label>ชื่อวิชา</label><input class="input" name="course_name" id="course_edit_name" required></div>
      <div class="form-field"><label>หน่วยกิต</label><input class="input" type="number" step="0.5" min="0" name="credits" id="course_edit_credits"></div>
      <div class="form-field"><label>คณะ</label><select class="select" name="faculty_value" id="course_edit_fac"><option value="">— เลือก —</option><?php foreach($faculties_for_modal as $f): ?><option value="<?php echo e($f['id']); ?>"><?php echo e($f['label']); ?></option><?php endforeach; ?></select></div>
      <div class="form-field"><label>สาขา</label><select class="select" name="major_value" id="course_edit_maj"><option value="">— เลือก —</option><?php foreach($majors_for_modal as $m): ?><option value="<?php echo e($m['id']); ?>"><?php echo e($m['label']); ?></option><?php endforeach; ?></select></div>
      <div class="form-field"><label>สาขาวิชา</label><select class="select" name="program_value" id="course_edit_prog"><option value="">— เลือก —</option><?php foreach($programs_for_modal as $p): ?><option value="<?php echo e($p['id']); ?>"><?php echo e($p['label']); ?></option><?php endforeach; ?></select></div>
      <div class="form-field">
      <label>ชื่อหลักสูตร</label>
      <select class="select" name="curriculum_name_value" id="course_edit_curname">
        <option value="">— เลือก —</option>
        <?php foreach($curNames as $cn): ?>
          <option value="<?php echo e($cn['id']); ?>"><?php echo e($cn['label']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-field">
      <label>ปีหลักสูตร</label>
      <select class="select" name="curriculum_year_value" id="course_edit_curyear">
        <option value="">— เลือก —</option>
        <?php foreach($curYears as $cy): ?>
          <option value="<?php echo e($cy['id']); ?>"><?php echo e($cy['label']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
        <div class="form-actions"><button type="button" class="btn secondary" onclick="closeModal('courseEditModal')">❌ ยกเลิก</button><button class="btn primary">💾 บันทึก</button></div>
    </form>
  </div>
</div>

<script>
function closeModal(id){ const modal = document.getElementById(id); if(modal) { modal.style.display = 'none'; document.body.style.overflow = 'auto'; } }
document.addEventListener('DOMContentLoaded', function(){ document.querySelectorAll('.alert').forEach(a=>{ setTimeout(()=>{ a.style.transition='opacity 0.5s ease-out, transform 0.5s ease-out'; a.style.opacity='0'; a.style.transform='translateX(-100%)'; setTimeout(()=>a.remove(),500) },5000) }); });

// ===== OPTIONS: Edit modal (UPDATED for Student Group) =====
function openOptionEditModal(buttonEl) {
  const modal = document.getElementById('optionEditModal');
  const form = document.getElementById('optionEditForm');
  const dataset = buttonEl.dataset;
  document.getElementById('edit_parent_major_container').style.display = 'none';
  document.getElementById('edit_parent_program_container').style.display = 'none';
  document.getElementById('edit_parent_group_container').style.display = 'none';
  document.getElementById('edit_opt_parent_major').disabled = true;
  document.getElementById('edit_opt_parent_program').disabled = true;
  document.getElementById('edit_opt_parent_group').disabled = true;

  document.getElementById('editModalTitle').innerText = 'แก้ไข: ' + dataset.label;
  form.querySelector('#edit_opt_id').value = dataset.id;
  form.querySelector('#edit_opt_label').value = dataset.label;

  if (dataset.type === 'major') {
    const container = document.getElementById('edit_parent_major_container');
    const select = document.getElementById('edit_opt_parent_major');
    container.style.display = 'block'; select.disabled = false; select.value = dataset.parent || '';
  } else if (dataset.type === 'program') {
    const container = document.getElementById('edit_parent_program_container');
    const select = document.getElementById('edit_opt_parent_program');
    container.style.display = 'block'; select.disabled = false; select.value = dataset.parent || '';
  } else if (dataset.type === 'student_group') {
    const container = document.getElementById('edit_parent_group_container');
    const select = document.getElementById('edit_opt_parent_group');
    container.style.display = 'block'; select.disabled = false; select.value = dataset.parent || '';
  }
  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
const optionModal = document.getElementById('optionEditModal');
if (optionModal) {
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal('optionEditModal'); });
  optionModal.addEventListener('click', e => { if (e.target === optionModal) closeModal('optionEditModal'); });
}

// ===== COURSES: Edit modal =====
function openCourseEditModal(btn){
  const d = btn.dataset;
  document.getElementById('course_edit_id').value = d.id;
  document.getElementById('course_edit_code').value = d.code || '';
  document.getElementById('course_edit_name').value = d.name || '';
  document.getElementById('course_edit_credits').value = d.credits || '3.0';
  document.getElementById('course_edit_fac').value = d.faculty || '';
  document.getElementById('course_edit_maj').value = d.major || '';
  document.getElementById('course_edit_prog').value = d.program || '';
  document.getElementById('courseEditTitle').innerText = 'แก้ไข: ' + (d.code ? d.code+' - ' : '') + (d.name||'');
  document.getElementById('course_edit_curname').value = d.curname || '';
  document.getElementById('course_edit_curyear').value = d.curyear || '';

  const modal = document.getElementById('courseEditModal');
  modal.style.display='flex';
  document.body.style.overflow='hidden';
}
const courseModal = document.getElementById('courseEditModal');
if (courseModal){
  document.addEventListener('keydown', e => { if (e.key==='Escape') closeModal('courseEditModal'); });
  courseModal.addEventListener('click', e => { if (e.target===courseModal) closeModal('courseEditModal'); });
}
</script>
</body>
</html>
