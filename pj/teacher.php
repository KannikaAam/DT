<?php
// teacher.php — Dashboard อาจารย์ + เปลี่ยนรหัสผ่าน + ลิงก์รีเซ็ต
session_start();
if (empty($_SESSION['loggedin']) || (($_SESSION['user_type'] ?? '') !== 'teacher')) {
    header('Location: index.php?error=unauthorized'); exit;
}
require_once 'db_connect.php'; // ต้องมี $conn (mysqli)

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// ===== หา teacher_id =====
$teacherId = $_SESSION['teacher_id'] ?? null;
if (!$teacherId) {
    $username = $_SESSION['username'] ?? null;
    if ($username) {
        $stmt = $conn->prepare("SELECT teacher_id FROM teacher WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $stmt->bind_result($tid);
        if ($stmt->fetch()) $teacherId = $tid;
        $stmt->close();
    }
}
if (!$teacherId) { die('ไม่พบรหัสอาจารย์ในเซสชัน กรุณาออกและเข้าสู่ระบบใหม่'); }

// ===== helpers: ตาราง/คอลัมน์มีไหม =====
function tableExists(mysqli $conn, string $name): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->store_result();
    $ok = $stmt->num_rows > 0;
    $stmt->close();
    return $ok;
}
function colExists(mysqli $conn, string $table, string $col): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $stmt->store_result();
    $ok = $stmt->num_rows > 0;
    $stmt->close();
    return $ok;
}
// นิพจน์ชื่อ-รหัส นศ. แบบยืดหยุ่น (คืน string SQL ที่อ้างนามแฝง s.*)
function studentNameExpr(mysqli $conn): string {
    $parts = [];
    if (colExists($conn,'students','student_name')) $parts[] = "s.student_name";
    if (colExists($conn,'students','full_name'))   $parts[] = "s.full_name";
    if (colExists($conn,'students','name'))        $parts[] = "s.name";
    $hasF = colExists($conn,'students','first_name');
    $hasL = colExists($conn,'students','last_name');
    if ($hasF || $hasL) {
        $pp=[]; if($hasF)$pp[]='s.first_name'; if($hasL)$pp[]='s.last_name';
        $parts[] = "TRIM(CONCAT_WS(' ',".implode(',',$pp)."))";
    }
    if (!$parts) return "CAST(s.student_id AS CHAR)";
    return "COALESCE(".implode(',',$parts).", CAST(s.student_id AS CHAR))";
}
function studentCodeExpr(mysqli $conn): string {
    if (colExists($conn,'students','student_code')) return "s.student_code";
    if (colExists($conn,'students','code'))        return "s.code";
    return "CAST(s.student_id AS CHAR)";
}

// ====== จัดการเปลี่ยนรหัสผ่าน ======
$pw_error = '';
$pw_success = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'change_password') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $pw_error = 'CSRF ไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $current = trim((string)($_POST['current_password'] ?? ''));
        $new     = trim((string)($_POST['new_password'] ?? ''));
        $confirm = trim((string)($_POST['confirm_password'] ?? ''));

        if ($current === '' || $new === '' || $confirm === '') {
            $pw_error = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
        } elseif ($new !== $confirm) {
            $pw_error = 'รหัสผ่านใหม่และยืนยันไม่ตรงกัน';
        } elseif (mb_strlen($new) < 8) {
            $pw_error = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร';
        } else {
            $tid = (int)$teacherId;
            $stmt = $conn->prepare("
                SELECT 
                    COALESCE(password_hash,'')    AS password_hash,
                    COALESCE(teacher_password,'') AS teacher_password,
                    COALESCE(password,'')         AS legacy_plain
                FROM teacher
                WHERE teacher_id = ?
                LIMIT 1
            ");
            $stmt->bind_param('i', $tid);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!$row) {
                $pw_error = 'ไม่พบข้อมูลอาจารย์';
            } else {
                $match = false;
                if (!$match && $row['password_hash'] !== '' && password_get_info($row['password_hash'])['algo']) {
                    $match = password_verify($current, $row['password_hash']);
                }
                if (!$match && $row['teacher_password'] !== '' && password_get_info($row['teacher_password'])['algo']) {
                    $match = password_verify($current, $row['teacher_password']);
                }
                if (!$match && $row['legacy_plain'] !== '') {
                    if ($current === $row['legacy_plain'] || hash('sha256', $current) === $row['legacy_plain']) {
                        $match = true;
                    }
                }

                if (!$match) {
                    $pw_error = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
                } else {
                    $newHash = password_hash($new, PASSWORD_DEFAULT);
                    $up = $conn->prepare("
                        UPDATE teacher
                        SET password_hash = ?, password = NULL, teacher_password = NULL
                        WHERE teacher_id = ?
                    ");
                    $up->bind_param('si', $newHash, $tid);
                    if ($up->execute()) $pw_success = 'เปลี่ยนรหัสผ่านเรียบร้อย';
                    else $pw_error = 'ไม่สามารถอัปเดตรหัสผ่านได้: '.$conn->error;
                    $up->close();
                }
            }
        }
    }
}

// ====== เตรียมข้อมูลกลุ่ม ======
$memberTables = ['course_group_students', 'group_members', 'enrollments'];
$memberTable = null;
foreach ($memberTables as $t) { if (tableExists($conn, $t)) { $memberTable = $t; break; } }

$hasStudentsTable  = tableExists($conn, 'students');
$hasPersonalInfo   = tableExists($conn, 'personal_info');
$hasEducationInfo  = tableExists($conn, 'education_info');

// รายการกลุ่มของอาจารย์ (เพิ่ม curriculum_value เพื่อ fallback education_info)
$groups = [];
$sql = "SELECT g.group_id, g.group_name, g.course_id,
               ".(colExists($conn,'course_groups','curriculum_value')?'g.curriculum_value':'NULL')." AS curriculum_value,
               COALESCE(c.course_name, CONCAT('Course#', g.course_id)) AS course_name
        FROM course_groups g
        LEFT JOIN courses c ON c.course_id = g.course_id
        WHERE g.teacher_id = ?
        ORDER BY ".(colExists($conn,'course_groups','created_at')?'g.created_at DESC':'g.group_id DESC');
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('s', $teacherId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $groups[] = $row;
    $stmt->close();
}

$selectedGroupId = isset($_GET['group_id']) ? trim($_GET['group_id']) : null;
$students = [];
$quizStats = [];
$sourceNote = ''; // ใช้บอกว่าใช้แหล่งสมาชิกจากไหน

if ($selectedGroupId) {
    // ---- ดึงข้อมูลกลุ่มที่เลือก (เพื่อรู้ group_name, curriculum_value, course_id) ----
    $grpInfo = null;
    $gsql = "SELECT group_id, group_name, course_id,
                    ".(colExists($conn,'course_groups','curriculum_value')?'curriculum_value':'NULL')." AS curriculum_value
             FROM course_groups WHERE group_id = ? LIMIT 1";
    if ($stmt = $conn->prepare($gsql)) {
        $stmt->bind_param('s', $selectedGroupId);
        $stmt->execute();
        $res = $stmt->get_result();
        $grpInfo = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }

    // ---- วิธีที่ 1: ใช้ตารางสมาชิกหากมี ----
    $studentIds = [];
    if ($memberTable) {
        $memberQuery = "SELECT m.student_id FROM {$memberTable} m WHERE m.group_id = ?";
        if ($stmt = $conn->prepare($memberQuery)) {
            $stmt->bind_param('s', $selectedGroupId);
            $stmt->execute();
            $memberRes = $stmt->get_result();
            while ($r = $memberRes->fetch_assoc()) {
                if (!empty($r['student_id'])) $studentIds[] = $r['student_id'];
            }
            $stmt->close();
        }
        if ($studentIds) $sourceNote = "ใช้ตารางสมาชิก: {$memberTable}";
    }

    // ---- วิธีที่ 2 (fallback): education_info ← ผูกด้วย curriculum/group_name ----
    if (!$studentIds && $hasEducationInfo && $grpInfo) {
        // ต้องมี curriculum_value และ group_name
        $curVal = (string)($grpInfo['curriculum_value'] ?? '');
        $grpName= (string)($grpInfo['group_name'] ?? '');
        if ($curVal !== '' && $grpName !== '') {
            $esql = "SELECT DISTINCT ei.student_id
                     FROM education_info ei
                     WHERE ei.curriculum_name = ? AND ei.student_group = ?";
            if ($stmt = $conn->prepare($esql)) {
                $stmt->bind_param('ss', $curVal, $grpName);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    if (!empty($r['student_id'])) $studentIds[] = $r['student_id'];
                }
                $stmt->close();
                if ($studentIds) $sourceNote = "ใช้ education_info (curriculum_name/student_group)";
            }
        }
    }

    // ---- ดึงข้อมูลนักศึกษาจาก studentIds ที่ได้ ----
    if ($studentIds) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $types = str_repeat('s', count($studentIds));

        if ($hasStudentsTable) {
            $nameExpr = studentNameExpr($conn);
            $codeExpr = studentCodeExpr($conn);
            // สร้าง SQL พร้อมชื่อแบบยืดหยุ่น
            $sqlStd = "SELECT s.student_id, {$nameExpr} AS student_name, {$codeExpr} AS student_code,
                              ".(colExists($conn,'students','email')?'COALESCE(s.email, \'\')':'\'\'')." AS email
                       FROM students s
                       WHERE s.student_id IN ($placeholders)
                       ORDER BY student_name";
            $stmt = $conn->prepare($sqlStd);
            $stmt->bind_param($types, ...$studentIds);
        } else {
            // ไม่มี students → สำรองไป user_login + personal_info ถ้ามี
            if ($hasPersonalInfo) {
                $sqlStd = "SELECT u.student_id,
                                  COALESCE(p.full_name, u.student_id) AS student_name,
                                  COALESCE(p.email,'') AS email
                           FROM user_login u
                           LEFT JOIN personal_info p ON p.id = u.personal_id
                           WHERE u.student_id IN ($placeholders)
                           ORDER BY student_name";
            } else {
                $sqlStd = "SELECT u.student_id,
                                  u.student_id AS student_name,
                                  '' AS email
                           FROM user_login u
                           WHERE u.student_id IN ($placeholders)
                           ORDER BY u.student_id";
            }
            $stmt = $conn->prepare($sqlStd);
            $stmt->bind_param($types, ...$studentIds);
        }
        $stmt->execute();
        $resStd = $stmt->get_result();
        while ($row = $resStd->fetch_assoc()) $students[] = $row;
        $stmt->close();
    }

    // ---- คะแนนแบบทดสอบล่าสุดต่อ quiz ต่อคน (ถ้ามีตาราง) ----
    $hasQuizzes  = tableExists($conn, 'quizzes');
    $hasAttempts = tableExists($conn, 'quiz_attempts');

    if ($students && $hasQuizzes && $hasAttempts && !empty($grpInfo['course_id'])) {
        $courseId = $grpInfo['course_id'];
        $quizMap = [];
        $stmt = $conn->prepare("SELECT quiz_id, title FROM quizzes WHERE course_id = ?");
        $stmt->bind_param('s', $courseId);
        $stmt->execute();
        $resQ = $stmt->get_result();
        while ($q = $resQ->fetch_assoc()) $quizMap[$q['quiz_id']] = $q['title'];
        $stmt->close();

        if ($quizMap) {
            $place = implode(',', array_fill(0, count($students), '?'));
            $types = str_repeat('s', count($students));
            $studentIdsForA = array_column($students, 'student_id');

            // quiz_id ใส่ตรง ๆ หลัง intval (ภายในระบบ)
            $quizIdsStr = implode(',', array_map('intval', array_keys($quizMap)));

            $sqlA = "
                SELECT a.student_id, a.quiz_id, a.score, a.status, a.submitted_at
                FROM quiz_attempts a
                INNER JOIN (
                    SELECT student_id, quiz_id, MAX(submitted_at) AS last_time
                    FROM quiz_attempts
                    WHERE student_id IN ($place) AND quiz_id IN ($quizIdsStr)
                    GROUP BY student_id, quiz_id
                ) t
                ON a.student_id = t.student_id AND a.quiz_id = t.quiz_id AND a.submitted_at = t.last_time
                ORDER BY a.student_id, a.quiz_id
            ";
            $stmt = $conn->prepare($sqlA);
            $stmt->bind_param($types, ...$studentIdsForA);
            $stmt->execute();
            $resA = $stmt->get_result();
            while ($a = $resA->fetch_assoc()) {
                $sid = $a['student_id'];
                if (!isset($quizStats[$sid])) $quizStats[$sid] = [];
                $quizStats[$sid][] = [
                    'quiz_id'    => $a['quiz_id'],
                    'quiz_title' => $quizMap[$a['quiz_id']] ?? ('Quiz#'.$a['quiz_id']),
                    'score'      => $a['score'],
                    'status'     => $a['status'],
                    'time'       => $a['submitted_at'],
                ];
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ระบบจัดการเรียนการสอน - อาจารย์</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --primary-color: #1e40af;
    --primary-hover: #1d4ed8;
    --secondary-color: #475569;
    --success-color: #059669;
    --warning-color: #d97706;
    --danger-color: #dc2626;
    --info-color: #0891b2;
    --light-bg: #f8fafc;
    --white: #ffffff;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;
    --border-radius: 12px;
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Sarabun', sans-serif;
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    color: var(--gray-800);
    line-height: 1.6;
    min-height: 100vh;
}

.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 2rem 1rem;
}

/* Header Styles */
.header {
    background: var(--white);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-lg);
    padding: 2rem;
    margin-bottom: 2rem;
    border: 1px solid var(--gray-200);
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1.5rem;
}

.header-title {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.header-title i {
    color: var(--primary-color);
    font-size: 2rem;
    padding: 0.75rem;
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    border-radius: 50%;
}

.header-title h1 {
    font-size: 1.875rem;
    font-weight: 700;
    color: var(--gray-900);
    margin: 0;
}

.header-subtitle {
    color: var(--gray-600);
    font-size: 0.95rem;
    margin-top: 0.25rem;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 1rem;
}

/* Button Styles */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: var(--border-radius);
    font-size: 0.9rem;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: inherit;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
    color: var(--white);
    box-shadow: var(--shadow-sm);
}

.btn-primary:hover {
    background: linear-gradient(135deg, var(--primary-hover), #1e3a8a);
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}

.btn-secondary {
    background: var(--gray-100);
    color: var(--gray-700);
    border: 1px solid var(--gray-300);
}

.btn-secondary:hover {
    background: var(--gray-200);
    border-color: var(--gray-400);
}

.btn-outline {
    background: transparent;
    color: var(--gray-600);
    border: 1px solid var(--gray-300);
}

.btn-outline:hover {
    background: var(--gray-50);
    color: var(--gray-800);
}

.btn-danger {
    background: linear-gradient(135deg, var(--danger-color), #b91c1c);
    color: var(--white);
}

.btn-danger:hover {
    background: linear-gradient(135deg, #b91c1c, #991b1b);
    transform: translateY(-1px);
}

/* Alert Styles */
.alert {
    padding: 1rem 1.5rem;
    border-radius: var(--border-radius);
    margin-bottom: 2rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-weight: 500;
    box-shadow: var(--shadow-sm);
}

.alert-success {
    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
    color: #166534;
    border: 1px solid #86efac;
}

.alert-error {
    background: linear-gradient(135deg, #fef2f2, #fecaca);
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.alert i {
    font-size: 1.25rem;
}

/* Card Styles */
.card {
    background: var(--white);
    border-radius: var(--border-radius);
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
    transition: all 0.3s ease;
}

.card:hover {
    box-shadow: var(--shadow-xl);
    transform: translateY(-2px);
}

.card-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    background: linear-gradient(135deg, var(--gray-50), var(--white));
}

.card-header h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.card-header i {
    color: var(--primary-color);
    font-size: 1.125rem;
}

.card-body {
    padding: 1.5rem;
}

/* Grid Layout */
.grid {
    display: grid;
    gap: 2rem;
    grid-template-columns: 1fr;
}

@media (min-width: 1024px) {
    .grid {
        grid-template-columns: 400px 1fr;
    }
}

/* Group List Styles */
.group-list {
    list-style: none;
}

.group-item {
    background: linear-gradient(135deg, var(--white), var(--gray-50));
    border: 1px solid var(--gray-200);
    border-radius: var(--border-radius);
    padding: 1.5rem;
    margin-bottom: 1rem;
    transition: all 0.3s ease;
}

.group-item:hover {
    box-shadow: var(--shadow-md);
    border-color: var(--primary-color);
    transform: translateX(4px);
}

.group-item-content {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
}

.group-info h4 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.group-info h4 i {
    color: var(--primary-color);
    font-size: 1rem;
}

.group-meta {
    color: var(--gray-600);
    font-size: 0.9rem;
}

.group-meta .meta-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.25rem;
}

.group-meta i {
    color: var(--gray-400);
    font-size: 0.8rem;
    width: 1rem;
}

.code {
    font-family: 'Courier New', monospace;
    background: var(--gray-100);
    padding: 0.125rem 0.375rem;
    border-radius: 4px;
    font-size: 0.85rem;
    color: var(--gray-700);
    border: 1px solid var(--gray-300);
}

/* Table Styles */
.table-container {
    overflow-x: auto;
    border-radius: var(--border-radius);
    border: 1px solid var(--gray-200);
}

.table {
    width: 100%;
    border-collapse: collapse;
    background: var(--white);
}

.table th {
    background: linear-gradient(135deg, var(--gray-50), var(--gray-100));
    color: var(--gray-800);
    font-weight: 600;
    padding: 1rem;
    text-align: left;
    border-bottom: 2px solid var(--gray-300);
    font-size: 0.9rem;
}

.table td {
    padding: 1rem;
    border-bottom: 1px solid var(--gray-200);
    vertical-align: top;
}

.table tbody tr:hover {
    background: var(--gray-50);
}

.table tbody tr:last-child td {
    border-bottom: none;
}

/* Badge Styles */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.8rem;
    font-weight: 500;
    margin-bottom: 0.5rem;
    margin-right: 0.5rem;
}

.badge-primary {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: var(--primary-color);
    border: 1px solid #93c5fd;
}

.badge-success {
    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
    color: var(--success-color);
    border: 1px solid #86efac;
}

.badge-warning {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: var(--warning-color);
    border: 1px solid #fbbf24;
}

.quiz-score {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.5rem;
}

.score-value {
    font-weight: 600;
    color: var(--primary-color);
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    padding: 0.25rem 0.75rem;
    border-radius: 6px;
    border: 1px solid #93c5fd;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 3rem 2rem;
    color: var(--gray-500);
}

.empty-state i {
    font-size: 3rem;
    color: var(--gray-400);
    margin-bottom: 1rem;
}

.empty-state h4 {
    font-size: 1.125rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: var(--gray-600);
}

.empty-state p {
    font-size: 0.9rem;
    color: var(--gray-500);
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    z-index: 1000;
    animation: fadeIn 0.3s ease;
}

.modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-content {
    background: var(--white);
    border-radius: var(--border-radius);
    width: 100%;
    max-width: 500px;
    box-shadow: var(--shadow-xl);
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    background: linear-gradient(135deg, var(--gray-50), var(--white));
    border-radius: var(--border-radius) var(--border-radius) 0 0;
}

.modal-header h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--gray-900);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.modal-header i {
    color: var(--primary-color);
}

.modal-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    background: none;
    border: none;
    font-size: 1.5rem;
    color: var(--gray-400);
    cursor: pointer;
    padding: 0.5rem;
    border-radius: 50%;
    transition: all 0.3s ease;
}

.modal-close:hover {
    background: var(--gray-100);
    color: var(--gray-600);
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--gray-200);
    background: var(--gray-50);
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    border-radius: 0 0 var(--border-radius) var(--border-radius);
}

/* Form Styles */
.form-group {
    margin-bottom: 1.5rem;
}

.form-label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: var(--gray-800);
    font-size: 0.9rem;
}

.form-label.required::after {
    content: ' *';
    color: var(--danger-color);
}

.form-input {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 2px solid var(--gray-300);
    border-radius: var(--border-radius);
    font-size: 0.9rem;
    font-family: inherit;
    transition: all 0.3s ease;
    background: var(--white);
}

.form-input:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-help {
    margin-top: 0.5rem;
    font-size: 0.8rem;
    color: var(--gray-500);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-help i {
    color: var(--info-color);
}

/* Status Indicators */
.status {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.8rem;
    font-weight: 500;
}

.status-completed {
    color: var(--success-color);
}

.status-pending {
    color: var(--warning-color);
}

.status-failed {
    color: var(--danger-color);
}

.status i {
    font-size: 0.75rem;
}

/* Responsive Design */
@media (max-width: 768px) {
    .container {
        padding: 1rem;
    }
    
    .header-content {
        flex-direction: column;
        text-align: center;
    }
    
    .header-title {
        flex-direction: column;
    }
    
    .grid {
        grid-template-columns: 1fr;
    }
    
    .group-item-content {
        flex-direction: column;
        align-items: stretch;
    }
    
    .table-container {
        font-size: 0.85rem;
    }
    
    .table th,
    .table td {
        padding: 0.75rem 0.5rem;
    }
}

/* Utility Classes */
.text-center { text-align: center; }
.text-right { text-align: right; }
.mb-0 { margin-bottom: 0; }
.mb-1 { margin-bottom: 0.5rem; }
.mb-2 { margin-bottom: 1rem; }
.mt-2 { margin-top: 1rem; }
.font-mono { font-family: 'Courier New', monospace; }
.text-sm { font-size: 0.875rem; }
.text-xs { font-size: 0.75rem; }
.font-semibold { font-weight: 600; }
.text-muted { color: var(--gray-500); }

/* Loading Animation */
.loading {
    display: inline-block;
    width: 1rem;
    height: 1rem;
    border: 2px solid var(--gray-300);
    border-radius: 50%;
    border-top-color: var(--primary-color);
    animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>
</head>
<body>
<div class="container">
    <!-- Header -->
    <div class="header">
        <div class="header-content">
            <div class="header-title">
                <i class="fas fa-chalkboard-teacher"></i>
                <div>
                    <h1>ระบบจัดการเรียนการสอน</h1>
                    <div class="header-subtitle">แดชบอร์ดอาจารย์ผู้สอน</div>
                </div>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="openModal('pwModal')">
                    <i class="fas fa-key"></i>
                    เปลี่ยนรหัสผ่าน
                </button>
                <a class="btn btn-outline" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    ออกจากระบบ
                </a>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($pw_success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?=h($pw_success)?>
        </div>
    <?php elseif ($pw_error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?=h($pw_error)?>
        </div>
    <?php endif; ?>

    <!-- Main Content -->
    <div class="grid">
        <!-- Groups Section -->
        <div class="card">
            <div class="card-header">
                <h3>
                    <i class="fas fa-users"></i>
                    กลุ่มเรียนที่รับผิดชอบ
                </h3>
            </div>
            <div class="card-body">
                <?php if (!$groups): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h4>ยังไม่มีกลุ่มเรียน</h4>
                        <p>ยังไม่มีกลุ่มเรียนที่ผูกกับอาจารย์คนนี้</p>
                    </div>
                <?php else: ?>
                    <ul class="group-list">
                        <?php foreach($groups as $g): ?>
                            <li class="group-item">
                                <div class="group-item-content">
                                    <div class="group-info">
                                        <h4>
                                            <i class="fas fa-graduation-cap"></i>
                                            <?=h($g['group_name'])?>
                                        </h4>
                                        <div class="group-meta">
                                            <div class="meta-item">
                                                <i class="fas fa-book"></i>
                                                <span>วิชา: <?=h($g['course_name'])?></span>
                                            </div>
                                            <div class="meta-item">
                                                <i class="fas fa-hashtag"></i>
                                                <span>รหัสกลุ่ม: <span class="code"><?=h($g['group_id'])?></span></span>
                                            </div>
                                            <?php if(!empty($g['curriculum_value'])): ?>
                                                <div class="meta-item">
                                                    <i class="fas fa-clipboard-list"></i>
                                                    <span>หลักสูตร: <span class="code"><?=h($g['curriculum_value'])?></span></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <a class="btn btn-primary" href="?group_id=<?=urlencode($g['group_id'])?>">
                                            <i class="fas fa-eye"></i>
                                            ดูรายชื่อ
                                        </a>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    
                    <div class="form-help mt-2">
                        <i class="fas fa-info-circle"></i>
                        แหล่งข้อมูล: <span class="code">course_groups</span>, <span class="code">courses</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Students Section -->
        <div class="card">
            <div class="card-header">
                <h3>
                    <i class="fas fa-user-graduate"></i>
                    รายชื่อนักศึกษาและผลการเรียน
                </h3>
            </div>
            <div class="card-body">
                <?php if (!$selectedGroupId): ?>
                    <div class="empty-state">
                        <i class="fas fa-mouse-pointer"></i>
                        <h4>เลือกกลุ่มเรียน</h4>
                        <p>เลือกกลุ่มจากด้านซ้ายเพื่อแสดงรายชื่อนักศึกษาและคะแนนสอบ</p>
                    </div>
                <?php else: ?>
                    <div class="mb-2">
                        <div class="badge badge-primary">
                            <i class="fas fa-layer-group"></i>
                            รหัสกลุ่ม: <?=h($selectedGroupId)?>
                        </div>
                        <?php if($sourceNote): ?>
                            <div class="badge badge-success">
                                <i class="fas fa-database"></i>
                                <?=h($sourceNote)?>
                            </div>
                        <?php elseif(!$memberTable && $hasEducationInfo): ?>
                            <div class="badge badge-warning">
                                <i class="fas fa-link"></i>
                                ใช้การผูกตาม curriculum/group_name (education_info)
                            </div>
                        <?php elseif(!$memberTable): ?>
                            <div class="badge badge-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                ไม่พบตารางสมาชิกกลุ่ม
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!$students): ?>
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <h4>ไม่มีนักศึกษา</h4>
                            <p>ยังไม่มีนักศึกษาในกลุ่มนี้ หรือข้อมูลสมาชิกยังไม่ถูกผูกกัน</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width: 20%;">
                                            <i class="fas fa-id-card"></i>
                                            รหัสนักศึกษา
                                        </th>
                                        <th style="width: 30%;">
                                            <i class="fas fa-user"></i>
                                            ชื่อ-นามสกุล
                                        </th>
                                        <th style="width: 50%;">
                                            <i class="fas fa-chart-bar"></i>
                                            ผลการทำแบบทดสอบล่าสุด
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach($students as $st): ?>
                                    <tr>
                                        <td>
                                            <span class="code font-semibold"><?=h($st['student_id'])?></span>
                                            <?php if (!empty($st['student_code']) && $st['student_code'] !== $st['student_id']): ?>
                                                <br><span class="text-muted text-sm">รหัส: <?=h($st['student_code'])?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="font-semibold"><?=h($st['student_name'] ?? $st['student_id'])?></div>
                                            <?php if (!empty($st['email'])): ?>
                                                <div class="text-muted text-sm">
                                                    <i class="fas fa-envelope"></i>
                                                    <?=h($st['email'])?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($quizStats[$st['student_id']])): ?>
                                                <?php foreach($quizStats[$st['student_id']] as $q): ?>
                                                    <div class="quiz-score">
                                                        <span class="badge badge-primary"><?=h($q['quiz_title'])?></span>
                                                        <span class="score-value"><?=h($q['score'])?> คะแนน</span>
                                                        <span class="status status-<?=($q['status']=='completed'?'completed':($q['status']=='pending'?'pending':'failed'))?>">
                                                            <i class="fas fa-<?=($q['status']=='completed'?'check-circle':($q['status']=='pending'?'clock':'times-circle'))?>"></i>
                                                            <?=h($q['status'])?>
                                                        </span>
                                                        <span class="text-muted text-xs">
                                                            <i class="fas fa-calendar-alt"></i>
                                                            <?=h($q['time'])?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="text-muted">
                                                    <i class="fas fa-minus-circle"></i>
                                                    ยังไม่มีข้อมูลการทำแบบทดสอบ
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="form-help mt-2">
                            <i class="fas fa-info-circle"></i>
                            แหล่งข้อมูลแบบทดสوบ: <span class="code">quizzes</span>, <span class="code">quiz_attempts</span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Password Change Modal -->
<div id="pwModal" class="modal" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <i class="fas fa-key"></i>
                เปลี่ยนรหัสผ่าน
            </h3>
            <button class="modal-close" onclick="closeModal('pwModal')" type="button">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form method="post" autocomplete="off">
            <div class="modal-body">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf_token" value="<?=h($_SESSION['csrf_token'])?>">
                
                <div class="form-group">
                    <label class="form-label required">รหัสผ่านปัจจุบัน</label>
                    <input class="form-input" type="password" name="current_password" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">รหัสผ่านใหม่</label>
                    <input class="form-input" type="password" name="new_password" minlength="8" required>
                    <div class="form-help">
                        <i class="fas fa-info-circle"></i>
                        รหัสผ่านต้องมีความยาวอย่างน้อย 8 ตัวอักษร
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">ยืนยันรหัสผ่านใหม่</label>
                    <input class="form-input" type="password" name="confirm_password" minlength="8" required>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="resetPwForm()">
                    <i class="fas fa-undo"></i>
                    รีเซ็ต
                </button>
                <button type="button" class="btn btn-outline" onclick="closeModal('pwModal')">
                    <i class="fas fa-times"></i>
                    ยกเลิก
                </button>
                <button class="btn btn-primary" type="submit">
                    <i class="fas fa-save"></i>
                    บันทึกการเปลี่ยนแปลง
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Modal Functions
function openModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    
    modal.style.display = 'flex';
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    
    // Focus first input
    const firstInput = modal.querySelector('input[type="password"]');
    if (firstInput) {
        setTimeout(() => firstInput.focus(), 100);
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    
    setTimeout(() => {
        modal.style.display = 'none';
    }, 300);
}

function resetPwForm() {
    const form = document.querySelector('#pwModal form');
    if (!form) return;
    
    form.current_password.value = '';
    form.new_password.value = '';
    form.confirm_password.value = '';
    form.current_password.focus();
}

// Close modal when clicking outside
window.addEventListener('click', function(event) {
    const modal = document.getElementById('pwModal');
    if (modal && event.target === modal) {
        closeModal('pwModal');
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const modals = document.querySelectorAll('.modal.show');
        modals.forEach(modal => {
            closeModal(modal.id);
        });
    }
});

// Form validation
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('#pwModal form');
    if (!form) return;
    
    const newPassword = form.querySelector('input[name="new_password"]');
    const confirmPassword = form.querySelector('input[name="confirm_password"]');
    
    function validatePasswords() {
        if (newPassword.value && confirmPassword.value) {
            if (newPassword.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('รหัสผ่านไม่ตรงกัน');
            } else {
                confirmPassword.setCustomValidity('');
            }
        }
    }
    
    newPassword.addEventListener('input', validatePasswords);
    confirmPassword.addEventListener('input', validatePasswords);
});

// Auto-hide alerts
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => {
                alert.remove();
            }, 300);
        }, 5000);
    });
});
</script>
</body>
</html>