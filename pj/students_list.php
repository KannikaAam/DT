<?php
// students_list.php (FULL) — index + clickable name -> detail + robust attempts + recommendations
session_start();
if (empty($_SESSION['loggedin']) || (($_SESSION['user_type'] ?? '') !== 'admin')) {
  header('Location: login.php?error=unauthorized'); exit;
}
require 'db_connect.php'; // $pdo (PDO)
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Helpers: check table/column ---------- */
function hasTable(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=?");
  $st->execute([$table]); return (bool)$st->fetchColumn();
}
function hasColumn(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $st->execute([$table,$col]); return (bool)$st->fetchColumn();
}

/* ---------- Detect schema variants ---------- */
$hasSubjectGroupsTable = hasTable($pdo,'subject_groups');
$hasGroupIdOnEducation = hasColumn($pdo,'education_info','group_id');
$enableGroupFilter     = ($hasSubjectGroupsTable && $hasGroupIdOnEducation);

$hasStudentRecs = hasTable($pdo, 'student_recommendations')
  && hasColumn($pdo,'student_recommendations','student_id')
  && hasColumn($pdo,'student_recommendations','group_id');

/* Pick group-course mapping table + key style */
$gcTables = [
  ['name'=>'group_courses',              'gid'=>'group_id', 'cid_candidates'=>['course_id','course_code']],
  ['name'=>'recommended_group_courses',  'gid'=>'group_id', 'cid_candidates'=>['course_id','course_code']],
  ['name'=>'group_subjects',             'gid'=>'group_id', 'cid_candidates'=>['course_id','course_code']],
];
$gcTable     = null;  // chosen table name
$gcGidCol    = null;  // group key
$gcCourseKey = null;  // 'course_id' or 'course_code'

foreach($gcTables as $cand){
  if (!hasTable($pdo,$cand['name']) || !hasColumn($pdo,$cand['name'],$cand['gid'])) continue;
  foreach($cand['cid_candidates'] as $cc){
    if (hasColumn($pdo,$cand['name'],$cc)) { $gcTable=$cand['name']; $gcGidCol=$cand['gid']; $gcCourseKey=$cc; break; }
  }
  if ($gcTable) break;
}

/* courses + curriculum labels preloading (for fast mapping) */
$courses = [];
$courseById = []; $courseByCode=[];
if (hasTable($pdo,'courses')){
  $courseCols = [];
  if (hasColumn($pdo,'courses','id')) $courseCols[] = 'id';
  if (hasColumn($pdo,'courses','course_code')) $courseCols[] = 'course_code';
  if (hasColumn($pdo,'courses','course_name')) $courseCols[] = 'course_name';
  if (hasColumn($pdo,'courses','curriculum_name_value')) $courseCols[] = 'curriculum_name_value';
  if (hasColumn($pdo,'courses','curriculum_year_value')) $courseCols[] = 'curriculum_year_value';

  if (!empty($courseCols)) {
    $sql = "SELECT ".implode(',', $courseCols)." FROM courses";
    $rowsC = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach($rowsC as $r){
      $courses[] = $r;
      if (isset($r['id']))          { $courseById[(string)$r['id']] = $r; }
      if (isset($r['course_code'])) { $courseByCode[(string)$r['course_code']] = $r; }
    }
  }
}
/* form_options map for curriculum labels */
$optLabel = [];
if (hasTable($pdo,'form_options')){
  $ops = $pdo->query("SELECT id,label FROM form_options")->fetchAll(PDO::FETCH_ASSOC);
  foreach($ops as $o) $optLabel[(string)$o['id']] = $o['label'];
}

/* group list */
$groups = [];
$groupName = [];
if ($hasSubjectGroupsTable){
  $groups = $pdo->query("SELECT group_id, group_name FROM subject_groups ORDER BY group_name")->fetchAll(PDO::FETCH_ASSOC);
  foreach($groups as $gRow) $groupName[(string)$gRow['group_id']] = $gRow['group_name'];
}

/* group -> courses map (if mapping table exists) */
$groupCourses = []; // [group_id] => [ [course_code,course_name,curName,curYear], ... ]
if ($gcTable){
  $stmt = $pdo->query("SELECT {$gcGidCol} AS gid, {$gcCourseKey} AS ck FROM {$gcTable}");
  while($r = $stmt->fetch(PDO::FETCH_ASSOC)){
    $gid = (string)$r['gid']; $ck = (string)$r['ck'];
    $rec = null;
    if ($gcCourseKey==='course_id' && isset($courseById[$ck])) {
      $rec = $courseById[$ck];
    } elseif ($gcCourseKey==='course_code' && isset($courseByCode[$ck])) {
      $rec = $courseByCode[$ck];
    }
    if ($rec){
      $groupCourses[$gid][] = [
        'course_code' => $rec['course_code'] ?? '',
        'course_name' => $rec['course_name'] ?? '',
        'cur_name'    => !empty($rec['curriculum_name_value']) ? ($optLabel[(string)$rec['curriculum_name_value']] ?? null) : null,
        'cur_year'    => !empty($rec['curriculum_year_value']) ? ($optLabel[(string)$rec['curriculum_year_value']] ?? null) : null,
      ];
    }
  }
}

/* Fallback: ถ้าไม่เจอ $gcTable แต่มีตาราง subjects ให้ใช้รายวิชาในกลุ่ม */
if (!$gcTable && hasTable($pdo,'subjects')) {
  $stmt = $pdo->query("SELECT group_id, subject_name FROM subjects ORDER BY subject_name");
  while($r = $stmt->fetch(PDO::FETCH_ASSOC)){
    $gid = (string)$r['group_id'];
    $groupCourses[$gid][] = [
      'course_code' => '',
      'course_name' => $r['subject_name'],
      'cur_name'    => null,
      'cur_year'    => null,
    ];
  }
}

/* ---------- Filters ---------- */
$q        = trim($_GET['q'] ?? '');
$gFilter  = trim($_GET['group'] ?? '');
$ast      = trim($_GET['academic_status'] ?? '');
$quiz     = trim($_GET['quiz_state'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20; $offset = ($page-1)*$per_page;

$where = []; $params = [];

// รวมจำนวนครั้งจากหลายแหล่ง
$attemptExpr = "GREATEST(
  COALESCE(sqs.quiz_attempts,0),
  COALESCE(th.attempts,0),
  COALESCE(qr.attempts,0)
)";

if ($q !== '') { $where[]="(ei.student_id LIKE :kw OR pi.full_name LIKE :kw)"; $params[':kw']="%{$q}%"; }
if ($ast !== ''){ $where[]="COALESCE(sqs.academic_status,'active') = :ast"; $params[':ast']=$ast; }
if ($quiz==='did') { $where[]="$attemptExpr > 0"; }
elseif ($quiz==='not') { $where[]="$attemptExpr = 0"; }

if ($enableGroupFilter && $gFilter!==''){
  if (ctype_digit($gFilter)) { $where[]="ei.group_id = :gid"; $params[':gid']=$gFilter; }
  else { $where[]="sg.group_name = :gname"; $params[':gname']=$gFilter; }
}
$wh = $where ? ('WHERE '.implode(' AND ',$where)) : '';

$joinGroup = $enableGroupFilter ? "LEFT JOIN subject_groups sg ON ei.group_id = sg.group_id" : "";

// ซับเควรีนับ attempts จาก test_history และ quiz_results
$joinTH = "LEFT JOIN (
    SELECT username AS sid, COUNT(*) AS attempts
    FROM test_history
    GROUP BY username
  ) th ON th.sid = ei.student_id";

$joinQR = "LEFT JOIN (
    SELECT CAST(student_id AS CHAR) AS sid, COUNT(*) AS attempts
    FROM quiz_results
    GROUP BY CAST(student_id AS CHAR)
  ) qr ON qr.sid = ei.student_id";

/* ---------- Count ---------- */
$sqlCount = "
  SELECT COUNT(*)
  FROM education_info ei
  INNER JOIN personal_info pi ON pi.id = ei.personal_id
  LEFT JOIN student_quiz_status sqs ON ei.student_id = sqs.student_id
  $joinGroup
  $joinTH
  $joinQR
  $wh
";
$pc = $pdo->prepare($sqlCount); $pc->execute($params);
$total = (int)$pc->fetchColumn(); $total_pages = max(1, (int)ceil($total/$per_page));

/* ---------- Page list ---------- */
$selectGroupName = $enableGroupFilter ? "sg.group_name," : "NULL AS group_name,";
$selectGroupId   = $hasGroupIdOnEducation ? "ei.group_id," : "NULL AS group_id,";
$selectComputed  = "$attemptExpr AS computed_attempts,";

$sqlList = "
  SELECT
    ei.student_id,
    $selectGroupId
    pi.full_name,
    $selectGroupName
    COALESCE(sqs.academic_status,'active') AS academic_status,
    COALESCE(sqs.quiz_attempts,0) AS quiz_attempts,
    $selectComputed
    COALESCE(sqs.recommended_count,0) AS recommended_count,
    COALESCE(sqs.admin_override_attempts,0) AS admin_override_attempts
  FROM education_info ei
  INNER JOIN personal_info pi ON pi.id = ei.personal_id
  LEFT JOIN student_quiz_status sqs ON ei.student_id = sqs.student_id
  $joinGroup
  $joinTH
  $joinQR
  $wh
  ORDER BY ei.student_id
  LIMIT :lim OFFSET :off
";
$ps = $pdo->prepare($sqlList);
foreach($params as $k=>$v){ $ps->bindValue($k,$v); }
$ps->bindValue(':lim',$per_page,PDO::PARAM_INT);
$ps->bindValue(':off',$offset,PDO::PARAM_INT);
$ps->execute();
$rows = $ps->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Load student->recommended groups (if table exists) ---------- */
$studentGroups = []; // student_id => [group_id,...]
if ($hasStudentRecs && !empty($rows)){
  $ids = array_values(array_unique(array_map(fn($r)=>$r['student_id'],$rows)));
  if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT student_id, group_id FROM student_recommendations WHERE student_id IN ($placeholders)";
    $st  = $pdo->prepare($sql);
    $st->execute($ids);
    while($r = $st->fetch(PDO::FETCH_ASSOC)){
      $studentGroups[$r['student_id']][] = (string)$r['group_id'];
    }
  }
}

/* ---------- Build per-student recommendation display ---------- */
function buildRecommendSummary(array $row, array $studentGroups, bool $hasStudentRecs, bool $hasGroupIdOnEducation, array $groupCourses, array $groupName): array {
  $sid = $row['student_id'];
  $gids = [];

  if ($hasStudentRecs && isset($studentGroups[$sid])) {
    $gids = $studentGroups[$sid];
  } elseif ($hasGroupIdOnEducation && !empty($row['group_id'])) {
    $gids = [(string)$row['group_id']];
  }

  $out = []; // array of ['gname'=>..., 'count'=>n, 'courses'=>[...]]
  foreach($gids as $gid){
    $gname = $groupName[$gid] ?? ("กลุ่ม #".$gid);
    $list  = $groupCourses[$gid] ?? [];
    $out[] = ['gname'=>$gname, 'count'=>count($list), 'courses'=>$list];
  }
  return $out;
}

/* ---------- AJAX: quiz_detail (click name) ---------- */
if (isset($_GET['ajax']) && $_GET['ajax']==='quiz_detail') {
  header('Content-Type: application/json; charset=utf-8');
  $sid = trim($_GET['sid'] ?? '');
  if ($sid===''){ echo json_encode(['ok'=>false,'error'=>'missing sid'], JSON_UNESCAPED_UNICODE); exit; }

  $selGroupName = $enableGroupFilter ? "sg.group_name," : "NULL AS group_name,";
  $selGroupId   = $hasGroupIdOnEducation ? "ei.group_id," : "NULL AS group_id,";
  $sql = "
    SELECT
      ei.student_id,
      $selGroupId
      pi.full_name,
      $selGroupName
      COALESCE(sqs.academic_status,'active') AS academic_status,
      GREATEST(
        COALESCE(sqs.quiz_attempts,0),
        COALESCE(th.attempts,0),
        COALESCE(qr.attempts,0)
      ) AS computed_attempts,
      COALESCE(sqs.recommended_count,0) AS recommended_count,
      COALESCE(sqs.admin_override_attempts,0) AS admin_override_attempts
    FROM education_info ei
    INNER JOIN personal_info pi ON pi.id = ei.personal_id
    LEFT JOIN student_quiz_status sqs ON ei.student_id = sqs.student_id
    ".($enableGroupFilter ? "LEFT JOIN subject_groups sg ON ei.group_id=sg.group_id" : "")."
    LEFT JOIN (
      SELECT username AS sid, COUNT(*) AS attempts
      FROM test_history
      GROUP BY username
    ) th ON th.sid = ei.student_id
    LEFT JOIN (
      SELECT CAST(student_id AS CHAR) AS sid, COUNT(*) AS attempts
      FROM quiz_results
      GROUP BY CAST(student_id AS CHAR)
    ) qr ON qr.sid = ei.student_id
    WHERE ei.student_id = :sid
    LIMIT 1
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':sid'=>$sid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row){ echo json_encode(['ok'=>false,'error'=>'not found'], JSON_UNESCAPED_UNICODE); exit; }

  $sgids = [];
  if ($hasStudentRecs){
    $r = $pdo->prepare("SELECT group_id FROM student_recommendations WHERE student_id = ?");
    $r->execute([$sid]);
    $sgids = $r->fetchAll(PDO::FETCH_COLUMN);
  }
  $recs = buildRecommendSummary($row, [$sid=>$sgids], $hasStudentRecs, $hasGroupIdOnEducation, $groupCourses, $groupName);

  echo json_encode([
    'ok'=>true,
    'student'=>[
      'student_id'=>$row['student_id'],
      'full_name'=>$row['full_name'],
      'group_name'=>$row['group_name'] ?? null
    ],
    'stats'=>[
      'academic_status'=>$row['academic_status'],
      'quiz_attempts'=>(int)$row['computed_attempts'],
      'recommended_count'=>(int)$row['recommended_count'],
      'admin_override_attempts'=>(int)$row['admin_override_attempts'],
    ],
    'recs'=>$recs
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- Export CSV ---------- */
if (isset($_GET['export']) && $_GET['export']==='csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=students_'.date('Ymd_His').'.csv');
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output','w');
  fputcsv($out, ['student_id','full_name','group','academic_status','quiz_attempts(computed)','recommended_count','admin_override_attempts','recommended_groups_and_courses']);
  foreach($rows as $r){
    $recs = buildRecommendSummary($r,$studentGroups,$hasStudentRecs,$hasGroupIdOnEducation,$groupCourses,$groupName);
    $parts = [];
    foreach($recs as $rec){
      $codes = array_map(fn($c)=>$c['course_code'], array_slice($rec['courses'],0,5));
      $parts[] = $rec['gname'].' ('.count($rec['courses']).' วิชา: '.implode('|',$codes).')';
    }
    $computed = (int)($r['computed_attempts'] ?? $r['quiz_attempts'] ?? 0);
    fputcsv($out, [
      $r['student_id'],$r['full_name'],$r['group_name'] ?? '',
      $r['academic_status'],$computed,$r['recommended_count'],$r['admin_override_attempts'],
      implode(' ; ',$parts)
    ]);
  }
  fclose($out); exit;
}

/* ---------- Labels ---------- */
$asts = ['active'=>'กำลังศึกษา','graduated'=>'สำเร็จการศึกษา','leave'=>'ลาพัก','suspended'=>'พักการเรียน'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>รายชื่อนักศึกษา</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#111827; --p1:#1F2937; --p2:#374151; --line:#374151;
  --text:#F9FAFB; --muted:#9CA3AF; --cyan:#22d3ee; --purple:#a78bfa; --danger:#f43f5e; --green:#10b981;
}
*{box-sizing:border-box} body{margin:0;font-family:'Sarabun',sans-serif;background:var(--bg);color:var(--text);line-height:1.6}
.header{position:sticky;top:0;z-index:10;background:var(--p1);border-bottom:1px solid var(--line);padding:12px 16px}
.header .wrap{max-width:1200px;margin:0 auto;display:flex;gap:12px;align-items:center;justify-content:space-between}
.title{font-weight:700;color:var(--cyan)}
.container{max-width:1200px;margin:24px auto;padding:0 16px}
.filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;background:var(--p1);border:1px solid var(--line);border-radius:12px;padding:12px;margin-bottom:16px}
.input, .select{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--p2);color:var(--text);outline:none}
.btn{display:inline-flex;gap:8px;align-items:center;border:1px solid var(--line);background:var(--p2);color:var(--text);padding:10px 14px;border-radius:10px;text-decoration:none;cursor:pointer}
.btn:hover{border-color:var(--cyan);color:var(--cyan)}
.btn-primary{background:linear-gradient(135deg,var(--cyan),var(--purple));border-color:transparent;color:#0b1220;font-weight:800}
.table-wrap{overflow:auto;border-radius:12px;border:1px solid var(--line);background:var(--p1)}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{padding:12px;border-bottom:1px solid rgba(255,255,255,.06)}
th{position:sticky;top:0;background:var(--p1);text-align:left;color:#E5E7EB}
.badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;border:1px solid var(--line);background:var(--p2);color:var(--muted)}
.st-active{background:rgba(16,185,129,.1);color:var(--green);border-color:rgba(16,185,129,.25)}
.st-graduated{background:rgba(167,139,250,.12);color:#c4b5fd;border-color:rgba(167,139,250,.3)}
.st-leave{background:rgba(234,179,8,.12);color:#fde68a;border-color:rgba(234,179,8,.3)}
.st-suspended{background:rgba(244,63,94,.12);color:#fecdd3;border-color:rgba(244,63,94,.35)}
.pagination{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}
.page-link{padding:8px 12px;border-radius:10px;border:1px solid var(--line);background:var(--p2);color:var(--text);text-decoration:none}
.page-link.active{background:linear-gradient(135deg,var(--cyan),var(--purple));border-color:transparent;color:#0b1220;font-weight:800}
.home-btn{position:fixed;bottom:18px;right:18px;background:linear-gradient(135deg,var(--cyan),var(--purple));color:#0b1220;font-weight:800;padding:12px 16px;border-radius:12px;text-decoration:none}

/* recommend cell + modal */
.rec-cell{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.rec-pill{background:rgba(34,211,238,.12);border:1px solid rgba(34,211,238,.35);color:#9ee7f7;padding:4px 8px;border-radius:999px;font-size:12px}
.rec-btn{padding:6px 10px;border-radius:8px;background:var(--p2);border:1px solid var(--line);color:var(--text);cursor:pointer}
.rec-btn:hover{border-color:var(--cyan);color:var(--cyan)}

/* modal (shared detail) */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center;padding:16px}
.modal-card{background:var(--p1);border:1px solid var(--line);border-radius:12px;max-width:820px;width:100%;box-shadow:0 12px 30px rgba(0,0,0,.3)}
.modal-header{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid var(--line)}
.modal-body{padding:12px 16px;max-height:70vh;overflow:auto}
.close{background:transparent;border:1px solid var(--line);color:var(--text);padding:6px 10px;border-radius:8px;cursor:pointer}
.close:hover{border-color:var(--danger);color:#fff;background:var(--danger)}
.course-item{border-bottom:1px dashed rgba(255,255,255,.08);padding:8px 0}
.course-item small{color:var(--muted)}

.student-link{color:#9ee7f7;cursor:pointer;text-decoration:underline}
.student-link:hover{color:#22d3ee}
.idx-col{color:#9CA3AF}
</style>
</head>
<body>
<header class="header">
  <div class="wrap">
    <div class="title"><i class="fas fa-users"></i> รายชื่อนักศึกษา</div>
    <div style="display:flex;gap:8px">
      <a class="btn" href="admin_dashboard.php"><i class="fas fa-home"></i> หน้าหลัก</a>
      <?php
        $exportLink = '?' . http_build_query(array_merge($_GET, ['export' => 'csv']));
      ?>
      <a class="btn" href="<?php echo htmlspecialchars($exportLink, ENT_QUOTES, 'UTF-8'); ?>">
        <i class="fas fa-file-download"></i> ส่งออก CSV (ตามตัวกรอง)
      </a>
    </div>
  </div>
</header>

<main class="container">
  <form class="filters" method="get">
    <input class="input" type="text" name="q" placeholder="ค้นหา: รหัส/ชื่อ" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">

    <?php if ($enableGroupFilter): ?>
      <select class="select" name="group">
        <option value="">ทุกกลุ่ม</option>
        <?php foreach($groups as $gg): $sel = ((string)$gg['group_id']===$gFilter)?'selected':''; ?>
          <option value="<?php echo $gg['group_id']; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($gg['group_name'], ENT_QUOTES, 'UTF-8'); ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <select class="select" name="academic_status">
      <option value="">สถานะนักศึกษาทั้งหมด</option>
      <?php foreach($asts as $k=>$v){ $sel = ($ast===$k)?'selected':''; echo "<option value=\"$k\" $sel>$v</option>"; } ?>
    </select>

    <select class="select" name="quiz_state">
      <option value="">สถานะแบบทดสอบทั้งหมด</option>
      <option value="did" <?php echo $quiz==='did'?'selected':''; ?>>ทำแล้ว</option>
      <option value="not" <?php echo $quiz==='not'?'selected':''; ?>>ยังไม่ทำ</option>
    </select>

    <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> ค้นหา</button>
  </form>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th style="width:70px">ลำดับ</th>
          <th style="width:120px">รหัส</th>
          <th>ชื่อ-นามสกุล</th>
          <th style="width:200px">กลุ่ม</th>
          <th style="width:160px">สถานะนักศึกษา</th>
          <th style="width:130px">ทำแบบทดสอบ (ครั้ง)</th>
          <th style="width:120px">แนะนำสำเร็จ</th>
          <th style="width:120px">สิทธิ์เพิ่ม</th>
          <th style="width:220px"># แนะนำ</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($rows): $rowIdx=0; foreach($rows as $r):
          $cls = [
            'active'    =>'st-active','graduated'=>'st-graduated','leave'=>'st-leave','suspended'=>'st-suspended'
          ][$r['academic_status']] ?? 'st-active';

          $recs  = buildRecommendSummary($r,$studentGroups,$hasStudentRecs,$hasGroupIdOnEducation,$groupCourses,$groupName);
          $sid   = (string)$r['student_id'];
          $rid   = htmlspecialchars($sid, ENT_QUOTES, 'UTF-8');
          $order = $offset + (++$rowIdx);
          $computedAttempts = (int)($r['computed_attempts'] ?? $r['quiz_attempts'] ?? 0);
        ?>
        <tr>
          <td class="idx-col"><?php echo $order; ?></td>
          <td><?php echo htmlspecialchars($r['student_id'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><a class="student-link" data-sid="<?php echo $rid; ?>"><?php echo htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8'); ?></a></td>
          <td><?php echo htmlspecialchars($r['group_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
          <td><span class="badge <?php echo $cls; ?>"><?php echo htmlspecialchars($asts[$r['academic_status']] ?? 'กำลังศึกษา', ENT_QUOTES, 'UTF-8'); ?></span></td>
          <td><?php echo $computedAttempts; ?></td>
          <td><?php echo (int)$r['recommended_count']; ?></td>
          <td><?php echo (int)$r['admin_override_attempts']; ?></td>
          <td>
            <?php if ($recs): ?>
              <div class="rec-cell">
                <?php foreach($recs as $i=>$rc): ?>
                  <span class="rec-pill"><?php echo htmlspecialchars($rc['gname'], ENT_QUOTES, 'UTF-8'); ?> • <?php echo (int)$rc['count']; ?> วิชา</span>
                <?php endforeach; ?>
                <button class="rec-btn" onclick="openDetailFromId('<?php echo $rid; ?>')"><i class="fas fa-eye"></i> ดู</button>
              </div>
            <?php else: ?>
              <span class="badge">— ไม่มีข้อมูลแนะนำ —</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="9" style="color:var(--muted);text-align:center">ไม่พบข้อมูล</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
  <div class="pagination">
    <?php
      $qs=$_GET; unset($qs['page']);
      for($i=1;$i<=$total_pages;$i++):
        $link='?'.http_build_query($qs+['page'=>$i]);
        $isActive = ($i===$page) ? 'active' : '';
    ?>
      <a class="page-link <?php echo $isActive; ?>" href="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $i; ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</main>

<a href="admin_dashboard.php" class="home-btn"><i class="fas fa-home"></i> หน้าหลัก</a>

<!-- Shared detail modal -->
<div id="detail-modal" class="modal" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-header">
      <strong id="detail-title">รายละเอียด</strong>
      <button class="close" onclick="closeDetail()">ปิด</button>
    </div>
    <div class="modal-body" id="detail-body">
      กำลังโหลด...
    </div>
  </div>
</div>

<script>
document.addEventListener('click', async (e) => {
  const a = e.target.closest('.student-link');
  if (!a) return;
  e.preventDefault();
  const sid = a.getAttribute('data-sid');
  openDetail();
  await loadDetail(sid);
});

async function openDetailFromId(sid){
  openDetail();
  await loadDetail(sid);
}

async function loadDetail(sid){
  try{
    const res = await fetch(`?ajax=quiz_detail&sid=${encodeURIComponent(sid)}`, {cache:'no-store'});
    const js  = await res.json();
    if(!js.ok) throw new Error(js.error || 'โหลดข้อมูลล้มเหลว');

    const mTitle = document.getElementById('detail-title');
    const mBody  = document.getElementById('detail-body');

    const astMap = {
      'active':'กำลังศึกษา',
      'graduated':'สำเร็จการศึกษา',
      'leave':'ลาพัก',
      'suspended':'พักการเรียน'
    };

    mTitle.textContent = `ผลแบบทดสอบ: ${js.student.student_id} — ${js.student.full_name}`;

    let html = `
      <div style="margin-bottom:10px;color:#9CA3AF">
        กลุ่ม: ${js.student.group_name ? js.student.group_name : '—'}
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:12px">
        <div class="badge">สถานะ: ${astMap[js.stats.academic_status] || 'กำลังศึกษา'}</div>
        <div class="badge">ทำแบบทดสอบ (ครั้ง): ${js.stats.quiz_attempts}</div>
        <div class="badge">แนะนำสำเร็จ: ${js.stats.recommended_count}</div>
        <div class="badge">สิทธิ์เพิ่ม(แอดมิน): ${js.stats.admin_override_attempts}</div>
      </div>
    `;

    if (Array.isArray(js.recs) && js.recs.length){
      js.recs.forEach(block => {
        html += `
          <h4 style="margin:8px 0 6px;color:#9ee7f7">${block.gname} <small style="color:#9CA3AF">• ${block.count} วิชา</small></h4>
        `;
        if (Array.isArray(block.courses) && block.courses.length){
          block.courses.forEach(c => {
            html += `
              <div class="course-item">
                <div><strong>${(c.course_code||'')} - ${(c.course_name||'')}</strong></div>
                <small>หลักสูตร: ${(c.cur_name||'—')} / ปีหลักสูตร: ${(c.cur_year||'—')}</small>
              </div>
            `;
          });
        }else{
          html += `<div class="course-item"><small>— ไม่พบวิชาในกลุ่มนี้ —</small></div>`;
        }
      });
    }else{
      html += `<div class="course-item"><small>— ไม่มีคำแนะนำ —</small></div>`;
    }

    mBody.innerHTML = html;

  }catch(err){
    document.getElementById('detail-body').innerHTML =
      `<div class="course-item"><small style="color:#fca5a5">เกิดข้อผิดพลาด: ${err.message}</small></div>`;
  }
}

function openDetail(){
  const m = document.getElementById('detail-modal');
  m.style.display='flex'; document.body.style.overflow='hidden';
  document.getElementById('detail-title').textContent = 'รายละเอียด';
  document.getElementById('detail-body').textContent = 'กำลังโหลด...';
}
function closeDetail(){
  const m = document.getElementById('detail-modal');
  m.style.display='none'; document.body.style.overflow='auto';
}

document.addEventListener('keydown', e=>{
  if (e.key==='Escape'){
    document.querySelectorAll('.modal').forEach(m=>m.style.display='none');
    document.body.style.overflow='auto';
  }
});
document.addEventListener('click', e=>{
  if (e.target.classList.contains('modal')){ e.target.style.display='none'; document.body.style.overflow='auto'; }
});
</script>
</body>
</html>
