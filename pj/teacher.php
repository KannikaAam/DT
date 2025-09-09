<?php 
// teacher.php — Dashboard อาจารย์ + เปลี่ยนรหัสผ่าน + โปรไฟล์อาจารย์ + กลุ่ม/นักศึกษา + quiz + education_info
session_start();

// ตรวจสอบการเข้าสู่ระบบและประเภทผู้ใช้
if (empty($_SESSION['loggedin']) || (($_SESSION['user_type'] ?? '') !== 'teacher')) {
    header('Location: index.php?error=unauthorized');
    exit;
}

require_once 'db_connect.php'; // ต้องมี $conn (mysqli)

/* =========================================================
   Helper: ดึงผลลัพธ์จาก mysqli_stmt ให้ใช้ได้ทั้งกรณีมี/ไม่มี mysqlnd
   ========================================================= */
function get_stmt_rows(mysqli_stmt $stmt): array {
    if (method_exists($stmt, 'get_result')) {
        $res = $stmt->get_result();
        if ($res instanceof mysqli_result) return $res->fetch_all(MYSQLI_ASSOC);
        return [];
    }
    $meta = $stmt->result_metadata();
    if (!$meta) return [];
    $fields = [];
    $row = [];
    $bindArgs = [];
    while ($f = $meta->fetch_field()) {
        $fields[] = $f->name;
        $row[$f->name] = null;
        $bindArgs[] = &$row[$f->name];
    }
    call_user_func_array([$stmt, 'bind_result'], $bindArgs);
    $out = [];
    while ($stmt->fetch()) {
        $copy = [];
        foreach ($fields as $fn) $copy[$fn] = $row[$fn];
        $out[] = $copy;
    }
    return $out;
}

// ฟังก์ชันสำหรับป้องกัน XSS
function h($s){
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

// ===== CSRF Protection =====
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===== หา teacher_id =====
$teacherId = $_SESSION['teacher_id'] ?? null;
if (!$teacherId) {
    $username = $_SESSION['username'] ?? null;
    if ($username) {
        $stmt = $conn->prepare("SELECT teacher_id FROM teacher WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $rows = get_stmt_rows($stmt);
            if (!empty($rows)) {
                $teacherId = $rows[0]['teacher_id'];
                $_SESSION['teacher_id'] = $teacherId;
            }
            $stmt->close();
        } else {
            error_log("Failed to prepare statement for teacher_id lookup: " . $conn->error);
            die('เกิดข้อผิดพลาดในการเตรียมคำสั่งฐานข้อมูล (TID)');
        }
    }
}
if (!$teacherId) {
    header('Location: index.php?error=teacher_id_missing');
    exit;
}

// ===== Helpers for DB Schema checks =====
function tableExists(mysqli $conn, string $name): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->store_result();
    $ok = $stmt->num_rows > 0;
    $stmt->close();
    return $ok;
}
function colExists(mysqli $conn, string $table, string $col): bool {
    if (!tableExists($conn, $table)) return false;
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $stmt->store_result();
    $ok = $stmt->num_rows > 0;
    $stmt->close();
    return $ok;
}
function studentNameExpr(mysqli $conn): string {
    $parts = [];
    if (colExists($conn,'students','student_name')) $parts[] = "s.student_name";
    if (colExists($conn,'students','full_name')) $parts[] = "s.full_name";
    if (colExists($conn,'students','name')) $parts[] = "s.name";
    $hasF = colExists($conn,'students','first_name');
    $hasL = colExists($conn,'students','last_name');
    if ($hasF || $hasL) {
        $pp=[]; if($hasF)$pp[]='s.first_name'; if($hasL)$pp[]='s.last_name';
        $parts[] = "TRIM(CONCAT_WS(' ',".implode(',',$pp)."))";
    }
    if (!$parts) return "CAST(s.student_id AS CHAR)";
    return "COALESCE(".implode(', ',$parts).", CAST(s.student_id AS CHAR))";
}
function studentCodeExpr(mysqli $conn): string {
    // ใช้ student_id เป็น code หลัก
    return "s.student_id";
}


// ====== โปรไฟล์อาจารย์ ======
$teacherProfile = ['name'=>null,'username'=>null,'email'=>null,'phone'=>null];
if (tableExists($conn,'teacher')) {
    $nameCols = [];
    if (colExists($conn,'teacher','full_name')) $nameCols[]='full_name';
    if (colExists($conn,'teacher','teacher_name')) $nameCols[]='teacher_name';
    if (colExists($conn,'teacher','name')) $nameCols[]='name';
    $nameExpr = $nameCols ? ("COALESCE(".implode(', ',$nameCols).")") : "CAST(teacher_id AS CHAR)";
    $fields = "teacher_id, {$nameExpr} AS tname";
    if (colExists($conn,'teacher','username')) $fields.=", username";
    if (colExists($conn,'teacher','email')) $fields.=", email";
    if (colExists($conn,'teacher','phone')) $fields.=", phone";
    $st = $conn->prepare("SELECT {$fields} FROM teacher WHERE teacher_id=? LIMIT 1");
    if ($st) {
        $st->bind_param('s',$teacherId);
        $st->execute();
        $rows = get_stmt_rows($st);
        if (!empty($rows)){
            $row = $rows[0];
            $teacherProfile['name']=$row['tname']??$teacherId;
            $teacherProfile['username']=$row['username']??($_SESSION['username']??null);
            $teacherProfile['email']=$row['email']??null;
            $teacherProfile['phone']=$row['phone']??null;
        }
        $st->close();
    } else {
        error_log("Failed to prepare statement for teacher profile: " . $conn->error);
    }
}

// ====== เปลี่ยนรหัสผ่าน ======
$pw_error = '';
$pw_success = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'change_password') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $pw_error = 'CSRF ไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $current = trim((string)($_POST['current_password'] ?? ''));
        $new = trim((string)($_POST['new_password'] ?? ''));
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
                    COALESCE(password_hash,'') AS password_hash, 
                    COALESCE(teacher_password,'') AS teacher_password, 
                    COALESCE(password,'') AS legacy_plain 
                FROM teacher 
                WHERE teacher_id = ? LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $tid);
                $stmt->execute();
                $rows = get_stmt_rows($stmt);
                $row = $rows ? $rows[0] : null;
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
                        $up = $conn->prepare("UPDATE teacher SET password_hash = ?, password = NULL, teacher_password = NULL WHERE teacher_id = ?");
                        if ($up) {
                            $up->bind_param('si', $newHash, $tid);
                            if ($up->execute()) {
                                $pw_success = 'เปลี่ยนรหัสผ่านเรียบร้อย';
                            } else {
                                $pw_error = 'ไม่สามารถอัปเดตรหัสผ่านได้: '.$up->error;
                                error_log("Password update failed: " . $up->error);
                            }
                            $up->close();
                        } else {
                            $pw_error = 'ไม่สามารถเตรียมคำสั่งอัปเดตรหัสผ่านได้: '.$conn->error;
                            error_log("Failed to prepare password update statement: " . $conn->error);
                        }
                    }
                }
            } else {
                $pw_error = 'ไม่สามารถเตรียมคำสั่งค้นหารหัสผ่านได้: '.$conn->error;
                error_log("Failed to prepare password lookup statement: " . $conn->error);
            }
        }
    }
}

// ====== กลุ่ม/นักศึกษา/quiz Data Fetching ======
$hasStudentsTable = tableExists($conn, 'students');
$hasPersonalInfo = tableExists($conn, 'personal_info');
$hasEducationInfo = tableExists($conn, 'education_info');
$hasUserLogin = tableExists($conn, 'user_login');

$groups = [];
$groupSelectFields = "g.group_id, g.group_name, g.course_id";
$groupSelectFields .= colExists($conn,'course_groups','curriculum_value') ? ", g.curriculum_value" : ", NULL AS curriculum_value";

$sql = "SELECT {$groupSelectFields}, COALESCE(c.course_name, CONCAT('Course#', g.course_id)) AS course_name 
        FROM course_groups g 
        LEFT JOIN courses c ON c.course_id = g.course_id 
        WHERE g.teacher_id = ? 
        ORDER BY ".(colExists($conn,'course_groups','created_at')?'g.created_at DESC':'g.group_id DESC');

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('s', $teacherId);
    $stmt->execute();
    $rows = get_stmt_rows($stmt);
    foreach ($rows as $row) $groups[] = $row;
    $stmt->close();
} else {
    error_log("Failed to prepare statement for group fetching: " . $conn->error);
}

$selectedGroupId = isset($_GET['group_id']) ? trim((string)$_GET['group_id']) : null;
$students = [];
$quizStats = [];
$studentEdu = [];
$sourceNote = '';
$groupInfo = null;

if ($selectedGroupId) {
    // ---- ข้อมูลกลุ่ม
    $groupInfoSelectFields = "group_id, group_name, course_id";
    $groupInfoSelectFields .= colExists($conn,'course_groups','curriculum_value') ? ", curriculum_value" : ", NULL AS curriculum_value";
    $gsql = "SELECT {$groupInfoSelectFields} FROM course_groups WHERE group_id = ? LIMIT 1";
    if ($stmt = $conn->prepare($gsql)) {
        $stmt->bind_param('s', $selectedGroupId);
        $stmt->execute();
        $rows = get_stmt_rows($stmt);
        $groupInfo = $rows ? $rows[0] : null;
        $stmt->close();
    } else {
        error_log("Failed to prepare statement for selected group info: " . $conn->error);
    }

    // ---- student keys (ใช้ education_info.student_group เป็นตัวหลัก)
    $studentKeys = [];
    $foundMemberTable = false;

    if ($hasEducationInfo && $groupInfo && colExists($conn,'education_info','student_group')) {
        $grpName = (string)($groupInfo['group_name'] ?? '');
        $curVal  = (string)($groupInfo['curriculum_value'] ?? '');

        if ($grpName !== '') {
            if (colExists($conn,'education_info','curriculum_name') && $curVal !== '') {
                $esql = "SELECT DISTINCT ei.student_id
                         FROM education_info ei
                         WHERE ei.student_group = ? AND ei.curriculum_name = ?";
                if ($st = $conn->prepare($esql)) {
                    $st->bind_param('ss', $grpName, $curVal);
                    $st->execute();
                    $rs = $st->get_result();
                    while ($r = $rs->fetch_assoc()) {
                        $sid = trim((string)$r['student_id']);
                        if ($sid !== '') $studentKeys[] = $sid;
                    }
                    $st->close();
                }
            }

            if (empty($studentKeys)) {
                $esql2 = "SELECT DISTINCT ei.student_id
                          FROM education_info ei
                          WHERE ei.student_group = ?";
                if ($st = $conn->prepare($esql2)) {
                    $st->bind_param('s', $grpName);
                    $st->execute();
                    $rs = $st->get_result();
                    while ($r = $rs->fetch_assoc()) {
                        $sid = trim((string)$r['student_id']);
                        if ($sid !== '') $studentKeys[] = $sid;
                    }
                    $st->close();
                }
            }
        }

        if (!empty($studentKeys)) {
            $foundMemberTable = true;
            $sourceNote = "ใช้ education_info โดย student_group".($curVal!==''?" + curriculum_name":"");
        }
    }

    // fallback หาในตารางสมาชิกอื่น ๆ
    if (!$foundMemberTable) {
        $memberTables = ['course_group_students','group_members','enrollments','student_group_members','registrations'];
        foreach ($memberTables as $mt) {
            if (!tableExists($conn, $mt)) continue;

            $candStudentCols = ['student_id', 'student_code', 'std_id', 'std_code', 'sid'];
            $studentColUsed = null;
            foreach ($candStudentCols as $sCol) {
                if (colExists($conn, $mt, $sCol)) { $studentColUsed = $sCol; break; }
            }
            if (!$studentColUsed) continue;

            $candGroupCols = ['group_id','course_group_id','section_id','group_code','cg_id','groupid'];
            $groupColsUsed = [];
            foreach ($candGroupCols as $gc) if (colExists($conn, $mt, $gc)) $groupColsUsed[] = $gc;

            if (!empty($groupColsUsed)) {
                $conds=[]; $params=[]; $types='';
                foreach ($groupColsUsed as $gc) { $conds[]="{$gc} = ?"; $params[]=$selectedGroupId; $types.='s'; }
                $sqlM = "SELECT DISTINCT {$studentColUsed} AS s FROM {$mt} WHERE ".implode(' OR ', $conds);
                if ($st = $conn->prepare($sqlM)) {
                    $st->bind_param($types, ...$params);
                    $st->execute();
                    $rs = $st->get_result();
                    while ($r = $rs->fetch_assoc()) {
                        $vv = trim((string)$r['s']);
                        if ($vv!=='') $studentKeys[]=$vv;
                    }
                    $st->close();
                    if (!empty($studentKeys)) {
                        $foundMemberTable = true;
                        $sourceNote = "ใช้ตารางสมาชิก: {$mt} (คอลัมน์: {$studentColUsed}, เงื่อนไข: ".implode('/', $groupColsUsed).")";
                        break;
                    }
                } else {
                    error_log("Failed to prepare statement for member table {$mt}: " . $conn->error);
                }
            } elseif ($groupInfo && !empty($groupInfo['course_id']) && colExists($conn, $mt, 'course_id')) {
                $sqlM = "SELECT DISTINCT {$studentColUsed} AS s FROM {$mt} WHERE course_id = ?";
                if ($st = $conn->prepare($sqlM)) {
                    $cid = (string)$groupInfo['course_id'];
                    $st->bind_param('s', $cid);
                    $st->execute();
                    $rs = $st->get_result();
                    while ($r = $rs->fetch_assoc()) {
                        $vv = trim((string)$r['s']);
                        if ($vv!=='') $studentKeys[]=$vv;
                    }
                    $st->close();
                    if (!empty($studentKeys)) {
                        $foundMemberTable = true;
                        $sourceNote = "ใช้ตารางสมาชิก: {$mt} (ตาม course_id)";
                        break;
                    }
                } else {
                    error_log("Failed to prepare statement for member table {$mt} by course_id: " . $conn->error);
                }
            }
        }
    }

    $studentKeys = array_values(array_unique($studentKeys));

    // ---- Fallback education_info (curriculum_name + group)
    if (!$foundMemberTable && empty($studentKeys) && $hasEducationInfo && $groupInfo) {
        $curVal = (string)($groupInfo['curriculum_value'] ?? '');
        $grpName = (string)($groupInfo['group_name'] ?? '');
        if ($curVal !== '' && $grpName !== '' && colExists($conn,'education_info','curriculum_name') && colExists($conn,'education_info','student_group')) {
            $esql = "SELECT DISTINCT ei.student_id FROM education_info ei WHERE ei.curriculum_name=? AND ei.student_group=?";
            if ($st = $conn->prepare($esql)) {
                $st->bind_param('ss', $curVal, $grpName);
                $st->execute();
                $rows = get_stmt_rows($st);
                foreach ($rows as $r) { $sid=trim((string)$r['student_id']); if ($sid!=='') $studentKeys[]=$sid; }
                $st->close();
                if (!empty($studentKeys)) $sourceNote = "ใช้ education_info (curriculum_name/student_group)";
            } else {
                error_log("Failed to prepare statement for education_info fallback: " . $conn->error);
            }
        }
    }

    // ---- โหลดข้อมูลนักศึกษา (ชื่อ/เมลจาก personal_info ผ่าน education_info)
    if (!empty($studentKeys)) {
        $placeholders = implode(',', array_fill(0, count($studentKeys), '?'));
        $bindTypes = str_repeat('s', count($studentKeys));
        $foundStudentData = false;

        // ใช้ students ถ้ามี (เผื่อบางรายมีชื่ออยู่)
        if ($hasStudentsTable) {
            $nameExpr = studentNameExpr($conn);
            $codeExpr = studentCodeExpr($conn);
            $hasStudentCodeCol = colExists($conn, 'students', 'student_code') || colExists($conn, 'students', 'code');
            $emailCol = colExists($conn, 'students', 'email') ? 'COALESCE(s.email, \'\')' : '\'\''; 

            $sqlStd = "SELECT DISTINCT s.student_id, {$nameExpr} AS student_name, {$codeExpr} AS student_code, {$emailCol} AS email 
                       FROM students s 
                       WHERE s.student_id IN ({$placeholders})";
            $params = $studentKeys; $typesToBind = $bindTypes;
            if ($hasStudentCodeCol) {
                $sqlStd .= " OR {$codeExpr} IN ({$placeholders})";
                $params = array_merge($studentKeys, $studentKeys);
                $typesToBind .= $bindTypes;
            }
            $sqlStd .= " ORDER BY student_name";

            if ($st = $conn->prepare($sqlStd)) {
                $st->bind_param($typesToBind, ...$params);
                $st->execute();
                $rows = get_stmt_rows($st);
                foreach ($rows as $row) $students[] = $row;
                $st->close();
                if (!empty($students)) $foundStudentData = true;
            } else {
                error_log("Failed to prepare statement for students table: " . $conn->error);
            }
        }

        // ถ้า students ไม่พอ เอาจาก education_info + personal_info ให้ครบแน่
        if (!$foundStudentData && $hasEducationInfo) {
            $ph = implode(',', array_fill(0, count($studentKeys), '?'));
            $types = str_repeat('s', count($studentKeys));
            $sqlStd = "
                SELECT ei.student_id,
                       COALESCE(pi.full_name, ei.student_id) AS student_name,
                       COALESCE(pi.email, '') AS email,
                       COALESCE(ei.student_group, '') AS student_group,
                       COALESCE(ei.curriculum_name, '') AS curriculum_name
                FROM education_info ei
                LEFT JOIN personal_info pi ON pi.id = ei.personal_id
                WHERE ei.student_id IN ($ph)
                ORDER BY student_name
            ";
            if ($st = $conn->prepare($sqlStd)) {
                $st->bind_param($types, ...$studentKeys);
                $st->execute();
                $rows = get_stmt_rows($st);
                foreach ($rows as $r) {
                    $students[] = [
                        'student_id'   => $r['student_id'],
                        'student_name' => $r['student_name'],
                        'student_code' => $r['student_id'],
                        'email'        => $r['email']
                    ];
                    $studentEdu[$r['student_id']] = [
                        'student_group'   => $r['student_group'],
                        'curriculum_name' => $r['curriculum_name']
                    ];
                }
                $st->close();
            }
        }
    }

    // ---- เติม group/curriculum จาก education_info ให้แถวที่มาจาก students
    if (!empty($students) && $hasEducationInfo) {
        $sids = array_column($students, 'student_id');
        if (!empty($sids)) {
            $placeholders = implode(',', array_fill(0, count($sids), '?'));
            $bindTypes = str_repeat('s', count($sids));
            $curr = $groupInfo['curriculum_value'] ?? null;
            $didByCurr = false;

            if (!empty($curr) && colExists($conn, 'education_info', 'curriculum_name')) {
                $sql = "SELECT ei.student_id,ei.student_group,ei.curriculum_name 
                        FROM education_info ei 
                        WHERE ei.curriculum_name=? AND ei.student_id IN ({$placeholders})";
                if ($st = $conn->prepare($sql)) {
                    $combinedParams = array_merge([$curr], $sids);
                    $bindTypesWithCurr = 's' . $bindTypes;
                    $st->bind_param($bindTypesWithCurr, ...$combinedParams);
                    $st->execute();
                    $rows = get_stmt_rows($st);
                    foreach ($rows as $r) {
                        $sid = (string)$r['student_id'];
                        $studentEdu[$sid] = [
                            'student_group' => $r['student_group'] ?? '',
                            'curriculum_name' => $r['curriculum_name'] ?? '',
                        ];
                    }
                    $st->close();
                    $didByCurr = !empty($studentEdu);
                } else {
                    error_log("Failed to prepare statement for education_info by curriculum: " . $conn->error);
                }
            }

            if (!$didByCurr) {
                $latestCol = colExists($conn,'education_info','updated_at') ? 'updated_at' :
                             (colExists($conn,'education_info','id') ? 'id' : null);
                if ($latestCol) {
                    $sql = "SELECT ei.student_id,ei.student_group,ei.curriculum_name 
                            FROM education_info ei 
                            INNER JOIN ( 
                                SELECT student_id,MAX({$latestCol}) AS max_val 
                                FROM education_info 
                                WHERE student_id IN ({$placeholders}) 
                                GROUP BY student_id 
                            ) t ON t.student_id=ei.student_id AND t.max_val=ei.{$latestCol}";
                    if ($st = $conn->prepare($sql)) {
                        $st->bind_param($bindTypes, ...$sids);
                        $st->execute();
                        $rows = get_stmt_rows($st);
                        foreach ($rows as $r) {
                            $sid = (string)$r['student_id'];
                            $studentEdu[$sid] = [
                                'student_group' => $r['student_group'] ?? '',
                                'curriculum_name' => $r['curriculum_name'] ?? '',
                            ];
                        }
                        $st->close();
                    } else {
                        error_log("Failed to prepare statement for education_info fallback by latest record: " . $conn->error);
                    }
                }
            }
        }
    }

    /* ===== คะแนน quiz ล่าสุด (ทนสคีมา, ไม่พึ่งพา course_id ถ้าเป็น NULL, เดินสองทาง attempts/results) ===== */
    $hasQuizzes   = tableExists($conn, 'quizzes');
    $hasAttempts  = tableExists($conn, 'quiz_attempts');
    $hasQResults  = tableExists($conn, 'quiz_results');

    $debug_found_attempts = 0;
    $debug_found_results  = 0;

    if (!empty($students)) {
        // เตรียมรายการ student_id และ (เผื่อ) student_code ทั้งหมดเป็น candidate key
        $idsById   = array_map('strval', array_column($students, 'student_id'));
        $idsByCode = array_map('strval', array_filter(array_column($students, 'student_code')));
        $candidateIds = array_values(array_unique(array_merge($idsById, $idsByCode)));

        // แผนที่ชื่อ quiz (ถ้ามีตาราง quizzes)
        $quizTitleMap = [];
        if ($hasQuizzes) {
            if ($st = $conn->prepare("SELECT quiz_id, COALESCE(title, CONCAT('Quiz#',quiz_id)) AS title FROM quizzes")) {
                $st->execute();
                $rr = get_stmt_rows($st);
                foreach ($rr as $r) $quizTitleMap[$r['quiz_id']] = $r['title'];
                $st->close();
            }
        }

        // === 1) quiz_attempts (ถ้ามี) — ดึงแถวล่าสุดต่อ (student_id, quiz_id)
        if ($hasAttempts && !empty($candidateIds)) {
            $attemptTimeCol = null;
            foreach (['submitted_at','created_at','updated_at','finish_time','end_time'] as $cand) {
                if (colExists($conn,'quiz_attempts',$cand)) { $attemptTimeCol = $cand; break; }
            }
            if ($attemptTimeCol === null) {
                $attemptTimeCol = colExists($conn,'quiz_attempts','id') ? 'id' : null;
            }

            if ($attemptTimeCol !== null) {
                $ph    = implode(',', array_fill(0, count($candidateIds), '?'));
                $types = str_repeat('s', count($candidateIds));
                $sqlA = "
                    SELECT a.student_id, a.quiz_id,
                           ".(colExists($conn,'quiz_attempts','score')  ? "a.score"  : "NULL AS score").",
                           ".(colExists($conn,'quiz_attempts','status') ? "a.status" : "NULL AS status").",
                           a.`{$attemptTimeCol}` AS tstamp
                    FROM quiz_attempts a
                    INNER JOIN (
                        SELECT student_id, quiz_id, MAX(`{$attemptTimeCol}`) AS last_time
                        FROM quiz_attempts
                        WHERE CAST(student_id AS CHAR) IN ($ph)
                        GROUP BY student_id, quiz_id
                    ) t
                    ON a.student_id = t.student_id
                       AND a.quiz_id  = t.quiz_id
                       AND a.`{$attemptTimeCol}` = t.last_time
                    WHERE CAST(a.student_id AS CHAR) IN ($ph)
                    ORDER BY a.student_id, a.quiz_id
                ";
                if ($st = $conn->prepare($sqlA)) {
                    $st->bind_param($types.$types, ...array_merge($candidateIds,$candidateIds));
                    $st->execute();
                    $rowsA = get_stmt_rows($st);
                    $debug_found_attempts = count($rowsA);
                    foreach ($rowsA as $a) {
                        $sid = (string)$a['student_id'];
                        if (!isset($quizStats[$sid])) $quizStats[$sid] = [];
                        $title = $quizTitleMap[$a['quiz_id']] ?? ('Quiz#'.$a['quiz_id']);
                        $quizStats[$sid][] = [
                            'quiz_id'    => $a['quiz_id'],
                            'quiz_title' => $title,
                            'score'      => $a['score'],
                            'status'     => $a['status'],
                            'time'       => $a['tstamp'],
                        ];
                    }
                    $st->close();
                } else {
                    error_log("Failed to prepare quiz_attempts query: ".$conn->error);
                }
            }
        }

        // === 2) quiz_results (fallback/เสริม) — ไม่กรองด้วย course_id และ cast student_id เป็น CHAR กันชนิดไม่ตรง
        $processedRowsR = false; // กันเติมซ้ำกับบล็อคด้านล่าง
        if ($hasQResults && !empty($candidateIds)) {
            $resTimeCol = null;
            foreach (['submitted_at','created_at','updated_at','finish_time','end_time'] as $cand) {
                if (colExists($conn,'quiz_results',$cand)) { $resTimeCol = $cand; break; }
            }
            if ($resTimeCol === null) {
                $resTimeCol = colExists($conn,'quiz_results','id') ? 'id' : null;
            }

            if ($resTimeCol !== null) {
                $ph    = implode(',', array_fill(0, count($candidateIds), '?'));
                $types = str_repeat('s', count($candidateIds));

                $sqlR = "
                    SELECT r.student_id, r.quiz_id,
                           ".(colExists($conn,'quiz_results','score') ? "r.score" : "NULL AS score").",
                           r.`{$resTimeCol}` AS tstamp
                    FROM quiz_results r
                    INNER JOIN (
                        SELECT student_id, quiz_id, MAX(`{$resTimeCol}`) AS last_time
                        FROM quiz_results
                        WHERE CAST(student_id AS CHAR) IN ($ph)
                        GROUP BY student_id, quiz_id
                    ) t
                    ON r.student_id = t.student_id
                       AND r.quiz_id  = t.quiz_id
                       AND r.`{$resTimeCol}` = t.last_time
                    WHERE CAST(r.student_id AS CHAR) IN ($ph)
                    ORDER BY r.student_id, r.quiz_id
                ";
                if ($st = $conn->prepare($sqlR)) {
                    $st->bind_param($types.$types, ...array_merge($candidateIds,$candidateIds));
                    $st->execute();
                    $rowsR = get_stmt_rows($st);
                    $debug_found_results = count($rowsR);

                    // ✅ แก้ index ผิด และเติมผลให้ครบในบล็อคนี้เลย
                    foreach ($rowsR as $a) {
                        $sid = (string)$a['student_id'];
                        if (!isset($quizStats[$sid])) $quizStats[$sid] = [];
                        $title = $quizTitleMap[$a['quiz_id']] ?? ('Quiz#'.$a['quiz_id']);
                        $quizStats[$sid][] = [
                            'quiz_id'    => $a['quiz_id'],
                            'quiz_title' => $title,
                            'score'      => $a['score'],
                            'status'     => null,
                            'time'       => $a['tstamp'],
                        ];
                    }
                    $processedRowsR = true; // กันเติมซ้ำ
                    $st->close();
                } else {
                    error_log("Failed to prepare quiz_results query: ".$conn->error);
                }
            }
        }

        // เดิมมีบล็อคเติมผล rowsR ซ้ำ — คงไว้ แต่กันไม่ให้รันทับด้วย flag
        if (!$processedRowsR && isset($rowsR) && is_array($rowsR)) {
            foreach ($rowsR as $a) {
                $sid = (string)$a['student_id'];
                if (!isset($quizStats[$sid])) $quizStats[$sid] = [];
                $title = $quizTitleMap[$a['quiz_id']] ?? ('Quiz#'.$a['quiz_id']);
                $quizStats[$sid][] = [
                    'quiz_id'    => $a['quiz_id'],
                    'quiz_title' => $title,
                    'score'      => $a['score'],
                    'status'     => null,
                    'time'       => $a['tstamp'],
                ];
            }
        }

        // เก็บโน้ตดีบัก (ถ้าคุณใช้แสดงบนหน้า)
        if (!empty($selectedGroupId)) {
            $sourceNote .= ($sourceNote ? ' | ' : '') .
                           "quiz_attempts={$debug_found_attempts}, quiz_results={$debug_found_results}";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>หน้าหลัก - อาจารย์</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --primary-color: #1e40af; --primary-hover: #1d4ed8; --secondary-color: #475569;
    --success-color: #059669; --warning-color: #d97706; --danger-color: #dc2626; --info-color: #0891b2;
    --light-bg: #f8fafc; --white: #ffffff; --gray-50: #f9fafb; --gray-100: #f3f4f6; --gray-200: #e5e7eb;
    --gray-300: #d1d5db; --gray-400: #9ca3af; --gray-500: #6b7280; --gray-600: #4b5563; --gray-700: #374151;
    --gray-800: #1f2937; --gray-900: #111827; --border-radius: 12px; --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Sarabun',sans-serif;background:linear-gradient(135deg,#f0f9ff 0%,#e0f2fe 100%);color:var(--gray-800);line-height:1.6;min-height:100vh}
.container{max-width:1400px;margin:0 auto;padding:2rem 1rem}

/* Header */
.header{background:var(--white);border-radius:var(--border-radius);box-shadow:var(--shadow-lg);padding:2rem;margin-bottom:2rem;border:1px solid var(--gray-200)}
.header-content{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1.5rem}
.header-title{display:flex;align-items:center;gap:1rem}
.header-title i{color:var(--primary-color);font-size:2rem;padding:.75rem;background:linear-gradient(135deg,#dbeafe,#bfdbfe);border-radius:50%}
.header-title h1{font-size:1.875rem;font-weight:700;color:var(--gray-900);margin:0}
.header-subtitle{color:var(--gray-600);font-size:.95rem;margin-top:.25rem}
.header-actions{display:flex;align-items:center;gap:1rem;flex-wrap:wrap} /* ✅ กันปุ่มหาย */

/* Profile chip */
.profile{display:flex;align-items:center;gap:12px;padding:10px 14px;border:1px solid var(--gray-200);border-radius:9999px;background:linear-gradient(135deg,#fff,#f8fafc);box-shadow:var(--shadow-sm)}
.profile i{color:var(--primary-color)}
.profile .p-name{font-weight:600;color:var(--gray-900)}
.profile .p-sub{font-size:.85rem;color:var(--gray-600)}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:.5rem;padding:.75rem 1.5rem;border:none;border-radius:var(--border-radius);font-size:.9rem;font-weight:500;text-decoration:none;cursor:pointer;transition:.3s;font-family:inherit;white-space:nowrap}
.btn-primary{background:linear-gradient(135deg,var(--primary-color),var(--primary-hover));color:#fff;box-shadow:var(--shadow-sm)}
.btn-primary:hover{background:linear-gradient(135deg,var(--primary-hover),#1e3a8a);box-shadow:var(--shadow-md);transform:translateY(-1px)}
.btn-secondary{background:var(--gray-100);color:var(--gray-700);border:1px solid var(--gray-300)}
.btn-secondary:hover{background:var(--gray-200);border-color:var(--gray-400)}
.btn-outline{background:transparent;color:var(--gray-600);border:1px solid var(--gray-300)}
.btn-outline:hover{background:var(--gray-50);color:var(--gray-800)}

/* Cards / table */
.card{background:#fff;border-radius:var(--border-radius);border:1px solid var(--gray-200);box-shadow:var(--shadow-lg);overflow:hidden;transition:.3s}
.card:hover{box-shadow:var(--shadow-xl);transform:translateY(-2px)}
.card-header{padding:1.5rem;border-bottom:1px solid var(--gray-200);background:linear-gradient(135deg,var(--gray-50),#fff)}
.card-header h3{font-size:1.25rem;font-weight:600;color:var(--gray-900);margin:0;display:flex;align-items:center;gap:.75rem}
.card-header i{color:var(--primary-color);font-size:1.125rem}
.card-body{padding:1.5rem}

.grid{display:grid;gap:2rem;grid-template-columns:1fr}
@media(min-width:1024px){.grid{grid-template-columns:400px 1fr}}

.group-list{list-style:none}
.group-item{background:linear-gradient(135deg,#fff,var(--gray-50));border:1px solid var(--gray-200);border-radius:var(--border-radius);padding:1.5rem;margin-bottom:1rem;transition:.3s}
.group-item:hover{box-shadow:var(--shadow-md);border-color:var(--primary-color);transform:translateX(4px)}
.group-item-content{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem}
.group-info h4{font-size:1.1rem;font-weight:600;color:var(--gray-900);margin-bottom:.5rem;display:flex;align-items:center;gap:.5rem}
.group-meta{color:var(--gray-600);font-size:.9rem}
.group-meta .meta-item{display:flex;align-items:center;gap:.5rem;margin-bottom:.25rem}
.code{font-family:'Courier New',monospace;background:var(--gray-100);padding:.125rem .375rem;border-radius:4px;font-size:.85rem;color:var(--gray-700);border:1px solid var(--gray-300)}

.table-container{overflow-x:auto;border-radius:var(--border-radius);border:1px solid var(--gray-200)}
.table{width:100%;border-collapse:collapse;background:#fff}
.table th{background:linear-gradient(135deg,var(--gray-50),var(--gray-100));color:var(--gray-800);font-weight:600;padding:1rem;text-align:left;border-bottom:2px solid var(--gray-300);font-size:.9rem}
.table td{padding:1rem;border-bottom:1px solid var(--gray-200);vertical-align:top}
.table tbody tr:hover{background:var(--gray-50)}

.badge{display:inline-flex;align-items:center;gap:.375rem;padding:.25rem .75rem;border-radius:9999px;font-size:.8rem;font-weight:500;margin:.5rem .5rem .5rem 0}
.badge-primary{background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:var(--primary-color);border:1px solid #93c5fd}
.badge-success{background:linear-gradient(135deg,#dcfce7,#bbf7d0);color:var(--success-color);border:1px solid #86efac}
.badge-warning{background:linear-gradient(135deg,#fef3c7,#fde68a);color:var(--warning-color);border:1px solid #fbbf24}

.empty-state{text-align:center;padding:3rem 2rem;color:#6b7280}
.empty-state i{font-size:3rem;color:#9ca3af;margin-bottom:1rem}
.empty-state h4{font-size:1.125rem;font-weight:600;margin-bottom:.5rem;color:#4b5563}

@media(max-width:768px){
  .container{padding:1rem}
  .header-content{flex-direction:column;text-align:center}
  .header-title{flex-direction:column}
  .grid{grid-template-columns:1fr}
  .group-item-content{flex-direction:column;align-items:stretch}
  .table-container{font-size:.85rem}
  .table th,.table td{padding:.75rem .5rem}
}
.text-xs{font-size:.75rem}.font-semibold{font-weight:600}.text-muted{color:#6b7280}

/* Modal */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);z-index:1000}
.modal.show{display:flex;align-items:center;justify-content:center;padding:1rem}
.modal-content{background:#fff;border-radius:var(--border-radius);width:100%;max-width:500px;box-shadow:var(--shadow-xl)}
.modal-header{padding:1.5rem;border-bottom:1px solid var(--gray-200);background:linear-gradient(135deg,var(--gray-50),#fff);border-radius:var(--border-radius) var(--border-radius) 0 0;position:relative}
.modal-header h3{margin:0;font-size:1.25rem;font-weight:600;color:var(--gray-900);display:flex;align-items:center;gap:.75rem}
.modal-header .modal-close{position:absolute;top:1rem;right:1rem;background:none;border:none;font-size:1.5rem;color:#9ca3af;cursor:pointer}
.modal-body{padding:1.5rem}
.modal-footer{padding:1rem 1.5rem;border-top:1px solid var(--gray-200);background:var(--gray-50);display:flex;justify-content:flex-end;gap:1rem;border-radius:0 0 var(--border-radius) var(--border-radius)}
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
                    <h2>ระบบแนะนำรายวิชาชีพเลือกด้วยต้นไม้ตัดสินใจ</h2>
                    <div class="header-subtitle">หน้าหลักอาจารย์</div>
                </div>
            </div>
            <div class="header-actions">
                <div class="profile" title="ข้อมูลอาจารย์">
                    <i class="fas fa-user-tie"></i>
                    <div>
                        <div class="p-name"><?=h($teacherProfile['name'] ?: 'อาจารย์')?></div>
                        <div class="p-sub">
                            <?php if ($teacherProfile['username']): ?>
                                <span><i class="fas fa-at"></i> <?=h($teacherProfile['username'])?></span>
                            <?php endif; ?>
                            <?php if ($teacherProfile['email']): ?>
                                &nbsp;·&nbsp;<span><i class="fas fa-envelope"></i> <?=h($teacherProfile['email'])?></span>
                            <?php endif; ?>
                            <?php if ($teacherProfile['phone']): ?>
                                &nbsp;·&nbsp;<span><i class="fas fa-phone"></i> <?=h($teacherProfile['phone'])?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <button class="btn btn-primary" onclick="openModal('pwModal')">
                    <i class="fas fa-key"></i> เปลี่ยนรหัสผ่าน
                </button>
                <a class="btn btn-outline" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
                </a>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($pw_success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?=h($pw_success)?></div>
    <?php elseif ($pw_error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?=h($pw_error)?></div>
    <?php endif; ?>

    <!-- Main -->
    <div class="grid">
        <!-- Groups -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-users"></i> กลุ่มเรียนที่รับผิดชอบ</h3></div>
            <div class="card-body">
                <?php if (!$groups): ?>
                    <div class="empty-state"><i class="fas fa-inbox"></i><h4>ยังไม่มีกลุ่มเรียน</h4><p>ยังไม่มีกลุ่มเรียนที่ผูกกับอาจารย์คนนี้</p></div>
                <?php else: ?>
                    <ul class="group-list">
                        <?php foreach($groups as $g): ?>
                            <li class="group-item">
                                <div class="group-item-content">
                                    <div class="group-info">
                                        <h4><i class="fas fa-graduation-cap"></i> <?=h($g['group_name'])?></h4>
                                        <div class="group-meta">
                                            <div class="meta-item"><i class="fas fa-book"></i><span>วิชา: <?=h($g['course_name'])?></span></div>
                                            <div class="meta-item"><i class="fas fa-hashtag"></i><span>รหัสกลุ่ม: <span class="code"><?=h($g['group_id'])?></span></span></div>
                                            <?php if(!empty($g['curriculum_value'])): ?>
                                                <div class="meta-item"><i class="fas fa-clipboard-list"></i><span>หลักสูตร: <span class="code"><?=h($g['curriculum_value'])?></span></span></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <a class="btn btn-primary" href="?group_id=<?=urlencode($g['group_id'])?>"><i class="fas fa-eye"></i> ดูรายชื่อ</a>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Students -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-user-graduate"></i> รายชื่อนักศึกษาและผลทดสอบ</h3></div>
            <div class="card-body">
                <?php if (!$selectedGroupId): ?>
                    <div class="empty-state"><i class="fas fa-mouse-pointer"></i><h4>เลือกกลุ่มเรียน</h4><p>เลือกกลุ่มจากด้านซ้ายเพื่อแสดงรายชื่อนักศึกษาและคะแนนสอบ</p></div>
                <?php else: ?>
                    <div class="mb-2">
                        <div class="badge badge-primary"><i class="fas fa-layer-group"></i> รหัสกลุ่ม: <?=h($selectedGroupId)?></div>
                        <?php if($sourceNote): ?>
                            <div class="badge badge-success"><i class="fas fa-database"></i> <?=h($sourceNote)?></div>
                        <?php elseif($hasEducationInfo): ?>
                            <div class="badge badge-warning"><i class="fas fa-link"></i> ใช้การผูกตาม curriculum/group_name (education_info) หากไม่พบตารางสมาชิก</div>
                        <?php else: ?>
                            <div class="badge badge-warning"><i class="fas fa-exclamation-triangle"></i> ไม่พบตารางสมาชิกกลุ่ม</div>
                        <?php endif; ?>
                    </div>

                    <?php if (!$students): ?>
                        <div class="empty-state"><i class="fas fa-user-slash"></i><h4>ไม่มีนักศึกษา</h4><p>ยังไม่มีนักศึกษาในกลุ่มนี้ หรือข้อมูลสมาชิกยังไม่ถูกผูกกัน</p></div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width:18%"><i class="fas fa-id-card"></i> รหัสนักศึกษา</th>
                                        <th style="width:28%"><i class="fas fa-user"></i> ชื่อ-นามสกุล</th>
                                        <th style="width:18%"><i class="fas fa-layer-group"></i> กลุ่ม (education_info)</th>
                                        <th style="width:36%"><i class="fas fa-chart-bar"></i> ผลการทำแบบทดสอบล่าสุด</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach($students as $st): ?>
                                    <?php $edu = $studentEdu[$st['student_id']] ?? null; ?>
                                    <tr>
                                        <td>
                                            <span class="code font-semibold"><?=h($st['student_id'])?></span>
                                            <?php if (!empty($st['student_code']) && $st['student_code'] !== $st['student_id']): ?>
                                                <br><span class="text-muted text-xs">รหัส: <?=h($st['student_code'])?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="font-semibold"><?=h($st['student_name'] ?? $st['student_id'])?></div>
                                            <?php if (!empty($st['email'])): ?>
                                                <div class="text-muted text-xs"><i class="fas fa-envelope"></i> <?=h($st['email'])?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($edu && !empty($edu['student_group'])): ?>
                                                <span class="code"><?=h($edu['student_group'])?></span>
                                                <?php if (!empty($edu['curriculum_name'])): ?>
                                                    <div class="text-muted text-xs"><i class="fas fa-clipboard-list"></i> <?=h($edu['curriculum_name'])?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($quizStats[$st['student_id']])): ?>
                                                <?php foreach($quizStats[$st['student_id']] as $q): ?>
                                                    <div class="quiz-score">
                                                        <span class="badge badge-primary"><?=h($q['quiz_title'])?></span>
                                                        <?php if ($q['score'] !== null && $q['score'] !== ''): ?>
                                                            <span class="score-value"><?=h($q['score'])?> คะแนน</span>
                                                        <?php endif; ?>
                                                        <span class="text-muted text-xs"><i class="fas fa-calendar-alt"></i> <?=h($q['time'])?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="text-muted"><i class="fas fa-minus-circle"></i> ยังไม่มีข้อมูลการทำแบบทดสอบ</div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                       
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Password Modal -->
<div id="pwModal" class="modal" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-key"></i> เปลี่ยนรหัสผ่าน</h3>
            <button class="modal-close" onclick="closeModal('pwModal')" type="button"><i class="fas fa-times"></i></button>
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
                    <div class="form-help"><i class="fas fa-info-circle"></i> รหัสผ่านต้องมีความยาวอย่างน้อย 8 ตัวอักษร</div>
                </div>
                <div class="form-group">
                    <label class="form-label required">ยืนยันรหัสผ่านใหม่</label>
                    <input class="form-input" type="password" name="confirm_password" minlength="8" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="resetPwForm()"><i class="fas fa-undo"></i> รีเซ็ต</button>
                <button type="button" class="btn btn-outline" onclick="closeModal('pwModal')"><i class="fas fa-times"></i> ยกเลิก</button>
                <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> บันทึกการเปลี่ยนแปลง</button>
            </div>
        </form>
    </div>
</div>

<script>
// Modal + form behaviors (คงเดิม)
function openModal(id){const m=document.getElementById(id);if(!m)return;m.style.display='flex';m.classList.add('show');m.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';const f=m.querySelector('input[type="password"]');if(f){setTimeout(()=>f.focus(),100);}}
function closeModal(id){const m=document.getElementById(id);if(!m)return;m.classList.remove('show');m.setAttribute('aria-hidden','true');document.body.style.overflow='';setTimeout(()=>{m.style.display='none';},300);}
function resetPwForm(){const f=document.querySelector('#pwModal form');if(!f)return;f.current_password.value='';f.new_password.value='';f.confirm_password.value='';f.current_password.focus();}
window.addEventListener('click',e=>{const m=document.getElementById('pwModal');if(m && e.target===m){closeModal('pwModal');}});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){document.querySelectorAll('.modal.show').forEach(m=>closeModal(m.id));}});
document.addEventListener('DOMContentLoaded',function(){
  const f=document.querySelector('#pwModal form'); if(!f) return;
  const np=f.querySelector('input[name="new_password"]'); const cp=f.querySelector('input[name="confirm_password"]');
  function v(){ if(np.value && cp.value){ cp.setCustomValidity(np.value!==cp.value?'รหัสผ่านไม่ตรงกัน':''); } }
  np.addEventListener('input',v); cp.addEventListener('input',v);
  document.querySelectorAll('.alert').forEach(a=>{
    setTimeout(()=>{ a.style.opacity='0'; a.style.transform='translateY(-10px)'; setTimeout(()=>a.remove(),300); },5000);
  });
});
</script>
</body>
</html>
