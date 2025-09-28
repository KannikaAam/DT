<?php
/* =========================================================
   history.php — ประวัติการทำแบบทดสอบ (เต็มรายละเอียดรายวิชา)
   - อ่านจาก test_history (ยืดหยุ่นคอลัมน์เวลา/คอลัมน์ผู้ใช้)
   - ถ้า test_history ว่าง → ดึงจาก quiz_results โดยตรง
   - ระบุกลุ่ม → ดึง "ทุกรายวิชาในกลุ่ม" จาก subjects (ไม่ LIMIT)
   - ถ้าระบุกลุ่มไม่ได้ → จับคู่รายวิชาตามข้อความ
   ========================================================= */
session_start();
require __DIR__ . '/db_connect.php'; // $conn (mysqli)

if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}
$student_id = (string)$_SESSION['student_id'];

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function mb_lc($s)
{
    return function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s);
}

/* ---------- schema helpers ---------- */
function has_table(mysqli $conn, string $t): bool
{
    $sql = "SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param('s', $t);
    $st->execute();
    $rs = $st->get_result();
    $row = $rs ? $rs->fetch_assoc() : ['c' => 0];
    $st->close();
    return (int)$row['c'] > 0;
}
function has_col(mysqli $conn, string $t, string $c): bool
{
    $sql = "SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param('ss', $t, $c);
    $st->execute();
    $rs = $st->get_result();
    $row = $rs ? $rs->fetch_assoc() : ['c' => 0];
    $st->close();
    return (int)$row['c'] > 0;
}
function coalesce_time_expr(mysqli $conn, string $t): string
{
    $cand = ['timestamp', 'created_at', 'taken_at', 'updated_at', 'createdAt'];
    $have = [];
    foreach ($cand as $c) {
        if (has_col($conn, $t, $c)) $have[] = "`$c`";
    }
    if (!$have) return "NOW() AS dt";
    if (count($have) === 1) return $have[0] . " AS dt";
    return "COALESCE(" . implode(',', $have) . ") AS dt";
}
function user_col(mysqli $conn, string $t): ?string
{
    if (has_col($conn, 'test_history', 'username')) return 'username';
    if (has_col($conn, 'test_history', 'student_id')) return 'student_id';
    return null;
}

/* ---------- โหลดข้อมูลโปรไฟล์ (ชื่อ/รูป) ---------- */
$full_name = 'ไม่พบชื่อ';
$avatar = '';
try {
    $sql = "SELECT p.full_name, p.profile_picture, p.gender
            FROM personal_info p
            INNER JOIN education_info e ON p.id=e.personal_id
            WHERE e.student_id=?";
    $st = $conn->prepare($sql);
    $st->bind_param('s', $student_id);
    $st->execute();
    if ($r = $st->get_result()) {
        if ($row = $r->fetch_assoc()) {
            $full_name = h($row['full_name'] ?? 'ไม่ระบุชื่อ');
            $gender = mb_lc($row['gender'] ?? '');
            if (!empty($row['profile_picture']) && file_exists(__DIR__ . '/uploads/profile_images/' . $row['profile_picture'])) {
                $avatar = 'uploads/profile_images/' . h($row['profile_picture']);
            } else {
                $bg = ($gender === 'ชาย' || $gender === 'male') ? '3498db' : (($gender === 'หญิง' || $gender === 'female') ? 'e91e63' : '9b59b6');
                $avatar = 'https://ui-avatars.com/api/?name=' . urlencode($full_name ?: 'Student') . '&background=' . $bg . '&color=ffffff&size=150&rounded=true';
            }
        }
    }
    $st->close();
} catch (Throwable $e) {
    $avatar = 'https://ui-avatars.com/api/?name=' . urlencode($full_name ?: 'Student') . '&background=9b59b6&color=ffffff&size=150&rounded=true';
}

/* ---------- ensure test_history โครงพื้นฐาน ---------- */
$conn->query("CREATE TABLE IF NOT EXISTS test_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL,
    recommended_group VARCHAR(255) NULL,
    recommended_subjects TEXT NULL,
    no_count INT DEFAULT 0,
    `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_time (username, `timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ---------- 1) ลองอ่านจาก test_history ---------- */
$history_rows = [];
$alerts = [];
if (has_table($conn, 'test_history')) {
    $timeExpr = coalesce_time_expr($conn, 'test_history');
    $uc = user_col($conn, 'test_history');
    try {
        if ($uc) {
            $sql = "SELECT $timeExpr, COALESCE(recommended_group,'') AS recommended_group,
                    COALESCE(recommended_subjects,'') AS recommended_subjects
                FROM test_history WHERE `$uc`=? ORDER BY dt DESC";
            $st = $conn->prepare($sql);
            $st->bind_param('s', $student_id);
        } else {
            $sql = "SELECT $timeExpr, COALESCE(recommended_group,'') AS recommended_group,
                    COALESCE(recommended_subjects,'') AS recommended_subjects
                FROM test_history ORDER BY dt DESC";
            $st = $conn->prepare($sql);
        }
        $st->execute();
        $rs = $st->get_result();
        while ($row = $rs->fetch_assoc()) {
            $history_rows[] = $row;
        }
        $st->close();
    } catch (Throwable $e) {
        $alerts[] = 'อ่าน test_history ไม่สำเร็จ: ' . h($e->getMessage());
    }
}

/* ---------- 2) สำรองจาก quiz_results ---------- */
if (empty($history_rows) && has_table($conn, 'quiz_results')) {
    $timeExpr = coalesce_time_expr($conn, 'quiz_results');
    try {
        $sql = "SELECT $timeExpr, recommend_group_id FROM quiz_results
              WHERE student_id=? ORDER BY dt DESC LIMIT 50";
        $st = $conn->prepare($sql);
        $st->bind_param('s', $student_id);
        $st->execute();
        $rs = $st->get_result();
        $fb = [];
        while ($r = $rs->fetch_assoc()) {
            $fb[] = $r;
        }
        $st->close();

        if ($fb) {
            // ดึงชื่อกลุ่ม (ทีละอัน)
            $groupName = function (int $gid) use ($conn): string {
                if (!has_table($conn, 'subject_groups')) return 'กลุ่มที่ ' . $gid;
                $idCol = has_col($conn, 'subject_groups', 'group_id') ? 'group_id' : 'id';
                $nameCol = has_col($conn, 'subject_groups', 'group_name') ? 'group_name' : (has_col($conn, 'subject_groups', 'name') ? 'name' : null);
                if (!$nameCol) return 'กลุ่มที่ ' . $gid;
                $sql = "SELECT `$nameCol` FROM subject_groups WHERE `$idCol`=? LIMIT 1";
                $st = $conn->prepare($sql);
                $st->bind_param('i', $gid);
                $st->execute();
                $val = (string)($st->get_result()->fetch_column() ?? '');
                $st->close();
                return $val !== '' ? $val : ('กลุ่มที่ ' . $gid);
            };
            foreach ($fb as $row) {
                $gid = (int)($row['recommend_group_id'] ?? 0);
                $history_rows[] = [
                    'dt' => $row['dt'] ?? '',
                    'recommended_group' => $gid > 0 ? $groupName($gid) : '',
                    'recommended_subjects' => '' // จะไปดึงเต็มในขั้น render
                ];
            }
        }
    } catch (Throwable $e) {
        $alerts[] = 'อ่าน quiz_results ไม่สำเร็จ: ' . h($e->getMessage());
    }
}

/* ---------- tools: resolve group + fetch subjects (เต็มรายละเอียด) ---------- */
function resolve_group(mysqli $conn, string $text): array
{
    if ($text === '') return ['id' => null, 'name' => ''];
    if (!has_table($conn, 'subject_groups')) return ['id' => null, 'name' => $text];

    $idCol = has_col($conn, 'subject_groups', 'group_id') ? 'group_id' : 'id';
    $nameCol = has_col($conn, 'subject_groups', 'group_name') ? 'group_name' : (has_col($conn, 'subject_groups', 'name') ? 'name' : null);

    // 1) ตรงตัว (case-insensitive)
    if ($nameCol) {
        $sql = "SELECT `$idCol`,`$nameCol` FROM subject_groups WHERE TRIM(LOWER(`$nameCol`))=TRIM(LOWER(?)) LIMIT 1";
        $st = $conn->prepare($sql);
        $st->bind_param('s', $text);
        $st->execute();
        if ($row = $st->get_result()->fetch_assoc()) {
            $st->close();
            return ['id' => (int)$row[$idCol], 'name' => $row[$nameCol]];
        }
        $st->close();
    }
    // 2) "กลุ่มที่ N" → หา N
    if (preg_match('/(\d+)/u', $text, $m)) {
        $n = (int)$m[1];
        $sql = "SELECT `$idCol`,`$nameCol` FROM subject_groups ORDER BY `$idCol` ASC";
        $rs = $conn->query($sql);
        $rows = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];
        if ($rows) {
            $idx = max(0, min(count($rows) - 1, $n - 1));
            return ['id' => (int)$rows[$idx][$idCol], 'name' => $rows[$idx][$nameCol] ?? ('กลุ่มที่ ' . $n)];
        }
    }
    return ['id' => null, 'name' => $text];
}
function subjects_by_group(mysqli $conn, int $gid): array
{
    if (!has_table($conn, 'subjects')) return [];
    $grpId = has_col($conn, 'subjects', 'group_id') ? 'group_id' : (has_col($conn, 'subjects', 'subject_group_id') ? 'subject_group_id' : null);
    $name = has_col($conn, 'subjects', 'subject_name') ? 'subject_name' : (has_col($conn, 'subjects', 'course_name') ? 'course_name' : null);
    if (!$name) return [];
    $code = has_col($conn, 'subjects', 'subject_code') ? 'subject_code' : (has_col($conn, 'subjects', 'course_code') ? 'course_code' : null);
    $cr = has_col($conn, 'subjects', 'credits') ? 'credits' : (has_col($conn, 'subjects', 'credit') ? 'credit' : null);
    $yr = has_col($conn, 'subjects', 'recommended_year') ? 'recommended_year' : (has_col($conn, 'subjects', 'year') ? 'year' : null);
    $pre = has_col($conn, 'subjects', 'prereq_text') ? 'prereq_text' : (has_col($conn, 'subjects', 'prerequisite') ? 'prerequisite' : null);

    $select = [];
    if ($code) $select[] = "`$code` AS course_code";
    $select[] = "`$name` AS course_name";
    if ($cr) $select[] = "`$cr` AS credits";
    if ($yr) $select[] = "`$yr` AS recommended_year";
    if ($pre) $select[] = "`$pre` AS prereq_text";

    $sql = "SELECT " . implode(',', $select) . " FROM subjects";
    $bind = '';
    $args = [];
    if ($grpId) {
        $sql .= " WHERE `$grpId`=?";
        $bind .= 'i';
        $args[] = $gid;
    }
    $sql .= " ORDER BY " . ($yr ? "`$yr` IS NULL, `$yr`, " : "") . "`$name`"; // **ไม่มี LIMIT**

    $st = $conn->prepare($sql);
    if ($bind) $st->bind_param($bind, ...$args);
    $st->execute();
    $rs = $st->get_result();
    $rows = $rs->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows ?: [];
}
/* fallback: ค้นรายวิชาเป็นรายตัว */
function find_course_by_token(mysqli $conn, string $token): ?array
{
    $cols = "course_code,course_name,credits,recommended_year,prereq_text,is_compulsory";
    if ($st = $conn->prepare("SELECT $cols FROM courses WHERE LOWER(course_code)=LOWER(?) LIMIT 1")) {
        $st->bind_param('s', $token);
        $st->execute();
        $rs = $st->get_result();
        if ($rs && $rs->num_rows > 0) {
            $row = $rs->fetch_assoc();
            $st->close();
            return $row;
        }
        $st->close();
    }
    if ($st = $conn->prepare("SELECT $cols FROM courses WHERE course_name=? LIMIT 1")) {
        $st->bind_param('s', $token);
        $st->execute();
        $rs = $st->get_result();
        if ($rs && $rs->num_rows > 0) {
            $row = $rs->fetch_assoc();
            $st->close();
            return $row;
        }
        $st->close();
    }
    $like = '%' . $token . '%';
    if ($st = $conn->prepare("SELECT $cols FROM courses WHERE course_name LIKE ? ORDER BY course_code LIMIT 1")) {
        $st->bind_param('s', $like);
        $st->execute();
        $rs = $st->get_result();
        if ($rs && $rs->num_rows > 0) {
            $row = $rs->fetch_assoc();
            $st->close();
            return $row;
        }
        $st->close();
    }
    return null;
}

/* ---------- utils ---------- */
function parse_subjects(?string $text): array
{
    if (!$text) return [];
    $norm = preg_replace('/[;\|\t\r\n]+/u', ',', $text);
    $parts = array_filter(array_map('trim', explode(',', $norm)), fn ($x) => $x !== '');
    $seen = [];
    $out = [];
    foreach ($parts as $p) {
        $k = mb_lc($p);
        if (!isset($seen[$k])) {
            $seen[$k] = 1;
            $out[] = $p;
        }
    }
    return $out;
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ประวัติการใช้งาน</title>
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

    /* ===== Alerts ===== */
    .alert {
        padding: 12px 16px;
        border: 1px solid #fde68a;
        background: #fffbeb;
        border-radius: var(--radius);
        color: #92400e;
        margin: 12px 0;
        border-left: 4px solid var(--warning);
    }

    /* ===== Tables ===== */
    .table-wrap {
        width: 100%;
        overflow-x: auto;
        border-radius: var(--radius);
        border: 1px solid var(--line);
    }

    .table {
        width: 100%;
        border-collapse: collapse;
        font-size: 15px;
        min-width: 720px;
        background: white;
    }

    .table th, .table td {
        padding: 12px 16px;
        text-align: left;
        vertical-align: top;
        border-bottom: 1px solid var(--line);
    }

    .table th {
        background: #f8fafc;
        font-weight: 600;
        color: var(--ink);
        position: sticky;
        top: 0;
        z-index: 1;
    }

    .table tbody tr:hover {
        background: #f9fafb;
    }

    .table tbody tr:last-child td {
        border-bottom: none;
    }

    /* ===== Inner Tables ===== */
    .inner-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
        margin-top: 8px;
        border: 1px solid var(--line);
        border-radius: 8px;
        overflow: hidden;
    }

    .inner-table th, .inner-table td {
        border: 1px solid var(--line);
        padding: 8px 12px;
    }

    .inner-table th {
        background: #f1f5f9;
        font-weight: 600;
        font-size: 13px;
    }

    .inner-table tbody tr:nth-child(even) {
        background: #f8fafc;
    }

    /* ===== Badges ===== */
    .badge {
        display: inline-block;
        padding: 4px 12px;
        border: 1px solid #bfdbfe;
        border-radius: 999px;
        background: #eff6ff;
        color: #1e40af;
        font-size: 13px;
        font-weight: 500;
    }

    .muted {
        color: var(--muted);
        font-style: italic;
    }

    .small {
        font-size: 13px;
        color: var(--muted);
        margin-top: 8px;
        font-style: italic;
    }

    /* ===== Empty State ===== */
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: var(--muted);
    }

    .empty-state-icon {
        font-size: 48px;
        margin-bottom: 16px;
        opacity: 0.5;
    }

    /* ===== Responsive ===== */
    @media (max-width: 768px) {
        .container {
            padding: 0 12px;
        }
        
        h1 {
            font-size: 20px;
            text-align: center;
        }
        
        .p-sub {
            text-align: center;
        }
        
        .actions {
            justify-content: center;
        }
        
        .btn {
            flex: 1;
            justify-content: center;
            min-width: 120px;
        }
        
        .table {
            min-width: 600px;
        }
        
        .userbox small {
            display: none;
        }
        
        .topbar-inner {
            flex-direction: column;
            gap: 8px;
        }
        
        .card-b {
            padding: 16px;
        }
    }

    @media (max-width: 480px) {
        .btn {
            font-size: 14px;
            padding: 8px 12px;
        }
        
        .table {
            min-width: 500px;
        }
        
        .inner-table th,
        .inner-table td {
            padding: 6px 8px;
            font-size: 13px;
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
                    <div class="user-name"><?php echo $full_name; ?></div>
                    <small>รหัสนักศึกษา: <?php echo h($student_id); ?></small>
                </div>
                <a href="logout.php" class="logout-btn">ออกจากระบบ</a>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Page Header -->
        <h1>ประวัติการใช้งาน</h1>
        <p class="p-sub">ผลแบบทดสอบและรายวิชาที่แนะนำล่าสุดของคุณ</p>

        <!-- Navigation Actions -->
        <div class="actions">
            <a class="btn btn-success" href="student_dashboard.php">กลับหน้าหลัก</a>
            <a class="btn btn-warning" href="edit_profile.php">แก้ไขข้อมูลส่วนตัว</a>
            <a class="btn btn-info" href="quiz.php">ทำแบบทดสอบ</a>
        </div>

        <!-- Main Content -->
        <div class="card">
            <div class="card-h">ผลแบบทดสอบของคุณ</div>
            <div class="card-b">
                <!-- Alerts -->
                <?php foreach ($alerts as $w): ?>
                    <div class="alert">⚠️ <?php echo $w; ?></div>
                <?php endforeach; ?>

                <?php if (!empty($history_rows)): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width:170px">วันที่/เวลา</th>
                                    <th style="width:220px">กลุ่มที่แนะนำ</th>
                                    <th>รายละเอียด</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history_rows as $r): ?>
                                    <?php
                                        $dt = h($r['dt'] ?? '');
                                        $grpText = trim((string)($r['recommended_group'] ?? ''));
                                        $grpInfo = resolve_group($conn, $grpText);
                                        $subs = [];
                                        if ($grpInfo['id']) {
                                            $subs = subjects_by_group($conn, (int)$grpInfo['id']);
                                        }
                                        if (!$subs) {
                                            $tokens = parse_subjects($r['recommended_subjects'] ?? '');
                                            foreach ($tokens as $t) {
                                                $c = find_course_by_token($conn, $t);
                                                $subs[] = $c ?: ['course_code' => $t, 'course_name' => '(ไม่พบในฐานข้อมูล)', 'credits' => null, 'recommended_year' => null, 'prereq_text' => null, 'is_compulsory' => 0];
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td><?php echo $dt ?: '—'; ?></td>
                                        <td>
                                            <?php echo $grpText !== '' ? ('<span class="badge">' . h($grpInfo['name'] ?: $grpText) . '</span>') : '<span class="muted">ไม่ระบุ</span>'; ?>
                                        </td>
                                        <td>
                                            <?php if ($subs): ?>
                                                <table class="inner-table">
                                                    <thead>
                                                        <tr>
                                                            <th style="width:120px">รหัสวิชา</th>
                                                            <th>ชื่อวิชา</th>
                                                            <th style="width:90px">หน่วยกิต</th>
                                                            <th style="width:100px">ปีที่ควรศึกษา</th>
                                                            <th style="width:150px">วิชาก่อน</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($subs as $c): ?>
                                                            <tr>
                                                                <td><strong><?php echo h($c['course_code'] ?? '—'); ?></strong></td>
                                                                <td><?php echo h($c['course_name'] ?? '—'); ?></td>
                                                                <td><?php echo isset($c['credits']) ? h((string)(float)$c['credits']) : '—'; ?></td>
                                                                <td><?php echo !empty($c['recommended_year']) ? h($c['recommended_year']) : '—'; ?></td>
                                                                <td><?php echo !empty($c['prereq_text']) ? nl2br(h($c['prereq_text'])) : '—'; ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                                <div class="small">รวมทั้งหมด <?php echo count($subs); ?> รายวิชา</div>
                                            <?php else: ?>
                                                <span class="muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📋</div>
                        <p>ยังไม่มีประวัติการทำแบบทดสอบสำหรับรหัสนี้</p>
                        <p class="small">เริ่มต้นด้วยการทำแบบทดสอบเพื่อรับคำแนะนำรายวิชา</p>
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

            // Auto-hide alerts after 5 seconds
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

            // Add hover effects to table rows
            const tableRows = document.querySelectorAll('.table tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#f8fafc';
                });
                row.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                });
            });
        });
    </script>
</body>
</html>