<?php
// students_list.php — FULL drop-in (guards missing enrollments.status/enrollment_date/end_date)
// AJAX returns JSON with strict content-type check + full schema guard.
// Added: map option-id from education_info to labels via form_options in reg_detail.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] !== '';

function send_json($payload, int $status=200){
  while (ob_get_level()) { ob_end_clean(); }
  http_response_code($status);
  header_remove('Content-Type');
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}
if ($isAjax){
  if (!ob_get_level()) ob_start();
  set_error_handler(fn($sev,$msg,$file,$line)=>send_json(['ok'=>false,'error'=>"PHP error: $msg at $file:$line"],500));
  set_exception_handler(fn($e)=>send_json(['ok'=>false,'error'=>"Exception: ".$e->getMessage()],500));
  register_shutdown_function(function(){
    $e=error_get_last();
    if ($e && in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])){
      send_json(['ok'=>false,'error'=>"Fatal: {$e['message']} at {$e['file']}:{$e['line']}"],500);
    }
  });
}

if (empty($_SESSION['loggedin']) || (($_SESSION['user_type'] ?? '') !== 'admin')) {
  if ($isAjax) send_json(['ok'=>false,'error'=>'unauthorized'],401);
  header('Location: login.php?error=unauthorized'); exit;
}

require 'db_connect.php';
if (!$pdo) {
    if ($isAjax) send_json(['ok'=>false,'error'=>'Database connection failed'],500);
    die('Database connection failed');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Helpers ---------- */
function hasTable(PDO $pdo, string $table): bool {
  $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=?");
  $st->execute([$table]); return (bool)$st->fetchColumn();
}
function hasColumn(PDO $pdo, string $table, string $col): bool {
  $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $st->execute([$table,$col]); return (bool)$st->fetchColumn();
}

/* ---------- Detect schema ---------- */
$hasSubjectGroupsTable = hasTable($pdo,'subject_groups');
$hasGroupIdOnEducation = hasColumn($pdo,'education_info','group_id');
$hasStudentGroupCol    = hasColumn($pdo,'education_info','student_group');
$enableGroupFilter     = ($hasSubjectGroupsTable && $hasGroupIdOnEducation);

$hasStudentRecs = hasTable($pdo,'student_recommendations')
  && hasColumn($pdo,'student_recommendations','student_id')
  && hasColumn($pdo,'student_recommendations','group_id');

/* ---------- Optional mapping tables ---------- */
$gcTables = [
  ['name'=>'group_courses','gid'=>'group_id','cid_candidates'=>['course_id','course_code']],
  ['name'=>'recommended_group_courses','gid'=>'group_id','cid_candidates'=>['course_id','course_code']],
  ['name'=>'group_subjects','gid'=>'group_id','cid_candidates'=>['course_id','course_code']],
];
$gcTable=null;$gcGidCol=null;$gcCourseKey=null;
foreach($gcTables as $cand){
  if (!hasTable($pdo,$cand['name']) || !hasColumn($pdo,$cand['name'],$cand['gid'])) continue;
  foreach($cand['cid_candidates'] as $cc){
    if (hasColumn($pdo,$cand['name'],$cc)){ $gcTable=$cand['name']; $gcGidCol=$cand['gid']; $gcCourseKey=$cc; break; }
  }
  if ($gcTable) break;
}

/* ---------- Courses & form_options ---------- */
$courses=[];$courseById=[];$courseByCode=[];
if (hasTable($pdo,'courses')){
  $cols=[]; foreach(['id','course_code','course_name','curriculum_name_value','curriculum_year_value'] as $c){ if(hasColumn($pdo,'courses',$c)) $cols[]=$c; }
  if ($cols){
    $rowsC=$pdo->query("SELECT ".implode(',',$cols)." FROM courses")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rowsC as $r){
      if(isset($r['id']))          $courseById[(string)$r['id']]=$r;
      if(isset($r['course_code'])) $courseByCode[(string)$r['course_code']]=$r;
    }
  }
}

/* form_options map (id -> label) + helper */
$optLabel=[];
if (hasTable($pdo,'form_options')){
  $ops=$pdo->query("SELECT id,label FROM form_options")->fetchAll(PDO::FETCH_ASSOC);
  foreach($ops as $o) $optLabel[(string)$o['id']]=$o['label'];
}
function mapOptionLabel($val, array $optLabel){
  if ($val===null || $val==='') return $val;
  $key=(string)$val;
  return array_key_exists($key,$optLabel) ? $optLabel[$key] : $val;
}

/* ---------- subject_groups list ---------- */
$groups=[];$groupName=[];
if ($hasSubjectGroupsTable){
  $groups=$pdo->query("SELECT group_id, group_name FROM subject_groups ORDER BY group_name")->fetchAll(PDO::FETCH_ASSOC);
  foreach($groups as $gRow) $groupName[(string)$gRow['group_id']]=$gRow['group_name'];
}

/* ---------- group -> courses map ---------- */
$groupCourses=[];
if ($gcTable){
  $stmt=$pdo->query("SELECT {$gcGidCol} AS gid, {$gcCourseKey} AS ck FROM {$gcTable}");
  while($r=$stmt->fetch(PDO::FETCH_ASSOC)){
    $gid=(string)$r['gid']; $ck=(string)$r['ck']; $rec=null;
    if ($gcCourseKey==='course_id' && isset($courseById[$ck])) $rec=$courseById[$ck];
    elseif ($gcCourseKey==='course_code' && isset($courseByCode[$ck])) $rec=$courseByCode[$ck];
    if ($rec){
      $groupCourses[$gid][]= [
        'course_code'=>$rec['course_code']??'',
        'course_name'=>$rec['course_name']??'',
        'cur_name'=>!empty($rec['curriculum_name_value'])?($optLabel[(string)$rec['curriculum_name_value']]??null):null,
        'cur_year'=>!empty($rec['curriculum_year_value'])?($optLabel[(string)$rec['curriculum_year_value']]??null):null,
      ];
    }
  }
}
if (!$gcTable && hasTable($pdo,'subjects')){
  $stmt=$pdo->query("SELECT group_id, subject_name FROM subjects ORDER BY subject_name");
  while($r=$stmt->fetch(PDO::FETCH_ASSOC)){
    $gid=(string)$r['group_id'];
    $groupCourses[$gid][]= ['course_code'=>'','course_name'=>$r['subject_name'],'cur_name'=>null,'cur_year'=>null];
  }
}

/* ---------- Recommended groups/subjects ---------- */
$recomGroupLabel=[];
if (hasTable($pdo,'recommended_groups')){
  $rows=$pdo->query("SELECT id, COALESCE(NULLIF(recommended_group,''), NULLIF(group_name,''), NULLIF(title_th,''), CONCAT('Group#',id)) AS label FROM recommended_groups")->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as $r) $recomGroupLabel[(int)$r['id']]=$r['label'];
}
$recomSubs=[];
if (hasTable($pdo,'recommended_subjects')){
  $orderBy = hasColumn($pdo,'recommended_subjects','order_index') ? " ORDER BY order_index" : "";
  $rs=$pdo->query("SELECT group_id, subject_name FROM recommended_subjects".$orderBy)->fetchAll(PDO::FETCH_ASSOC);
  foreach($rs as $r){ $recomSubs[(int)$r['group_id']][]=$r['subject_name']; }
}

/* ---------- Filters ---------- */
$q=trim($_GET['q'] ?? ''); $gFilter=trim($_GET['group'] ?? '');
$ast=trim($_GET['academic_status'] ?? ''); $quiz=trim($_GET['quiz_state'] ?? '');
$page=max(1,(int)($_GET['page'] ?? 1)); $per_page=20; $offset=($page-1)*$per_page;

/* ---------- Attempts join (flexible & normalized) ---------- */
$hasTH=hasTable($pdo,'test_history'); $hasQR=hasTable($pdo,'quiz_results');
$eiNorm = "TRIM(LEADING '0' FROM TRIM(CAST(ei.student_id AS CHAR)))";

$joinTH='';
if($hasTH){
  $joinTH="LEFT JOIN (
    SELECT TRIM(CAST(username AS CHAR)) sid_raw,
           TRIM(LEADING '0' FROM TRIM(CAST(username AS CHAR))) sid_norm,
           COUNT(*) attempts
    FROM test_history
    GROUP BY TRIM(CAST(username AS CHAR)), TRIM(LEADING '0' FROM TRIM(CAST(username AS CHAR)))
  ) th ON (th.sid_raw = TRIM(CAST(ei.student_id AS CHAR)) OR th.sid_norm = {$eiNorm})";
}
$joinQR='';
if($hasQR){
  $joinQR="LEFT JOIN (
    SELECT TRIM(CAST(student_id AS CHAR)) sid_raw,
           TRIM(LEADING '0' FROM TRIM(CAST(student_id AS CHAR))) sid_norm,
           COUNT(*) attempts,
           CAST(SUBSTRING_INDEX(
             SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(recommend_group_id,0) ORDER BY COALESCE(submitted_at, created_at) DESC SEPARATOR ','), ',', 1
           ), ',', -1) AS UNSIGNED) AS last_gid
    FROM quiz_results
    GROUP BY TRIM(CAST(student_id AS CHAR)), TRIM(LEADING '0' FROM TRIM(CAST(student_id AS CHAR)))
  ) qr ON (qr.sid_raw = TRIM(CAST(ei.student_id AS CHAR)) OR qr.sid_norm = {$eiNorm})";
}
$attemptPieces = ["COALESCE(qr.attempts,0)"]; if ($hasTH) $attemptPieces[]="COALESCE(th.attempts,0)";
$attemptPieces[]="COALESCE(sqs.quiz_attempts,0)";
$attemptExpr="GREATEST(".implode(',', $attemptPieces).")";

/* ---------- Group (enrollments) joins with column guards ---------- */
$hasEnroll = hasTable($pdo,'enrollments') && hasColumn($pdo,'enrollments','student_id') && hasColumn($pdo,'enrollments','group_id');
$hasCourseGroups = hasTable($pdo,'course_groups') && hasColumn($pdo,'course_groups','group_id') && hasColumn($pdo,'course_groups','group_name');
$hasEnrollDate = $hasEnroll && hasColumn($pdo,'enrollments','enrollment_date');
$hasEndDate    = $hasEnroll && hasColumn($pdo,'enrollments','end_date');
$hasEnrollStatus = $hasEnroll && hasColumn($pdo,'enrollments','status');

$joinCurrentGroup = "";
if($hasEnroll && $hasCourseGroups){
  $orderExpr = $hasEnrollDate ? "IFNULL(e.enrollment_date,'1970-01-01')" :
               (hasColumn($pdo,'enrollments','created_at') ? "IFNULL(e.created_at,'1970-01-01')" : "e.group_id");
  $statusFilter = $hasEnrollStatus ? "WHERE e.status IS NULL OR e.status IN ('enrolled','active')" : "";
  $joinCurrentGroup="LEFT JOIN (
    SELECT e.student_id,
           SUBSTRING_INDEX(
             SUBSTRING_INDEX(GROUP_CONCAT(cg.group_name ORDER BY $orderExpr DESC SEPARATOR ','), ',', 1),
             ',', -1
           ) AS group_name
    FROM enrollments e
    JOIN course_groups cg ON cg.group_id=e.group_id
    $statusFilter
    GROUP BY e.student_id
  ) en ON en.student_id = ei.student_id";
}
$joinLegacyGroup = $enableGroupFilter ? "LEFT JOIN subject_groups sg ON ei.group_id = sg.group_id" : "";

/* ---------- WHERE ---------- */
$where=[];$params=[];
if($q!==''){ $where[]="(ei.student_id LIKE :kw OR pi.full_name LIKE :kw)"; $params[':kw']="%{$q}%"; }
if($ast!==''){ $where[]="COALESCE(sqs.academic_status,'active')=:ast"; $params[':ast']=$ast; }
if($quiz==='did'){ $where[]="$attemptExpr>0"; } elseif($quiz==='not'){ $where[]="$attemptExpr=0"; }
if ($enableGroupFilter && $gFilter!==''){
  if (ctype_digit($gFilter)) { $where[]="ei.group_id = :gid"; $params[':gid']=$gFilter; }
  else { $where[]="sg.group_name = :gname"; $params[':gname']=$gFilter; }
}
$wh = $where ? 'WHERE '.implode(' AND ',$where) : '';

/* ---------- Count ---------- */
$sqlCount="
  SELECT COUNT(*)
  FROM education_info ei
  INNER JOIN personal_info pi ON pi.id=ei.personal_id
  LEFT JOIN student_quiz_status sqs ON ei.student_id=sqs.student_id
  $joinLegacyGroup
  $joinCurrentGroup
  $joinTH
  $joinQR
  $wh
";
$pc=$pdo->prepare($sqlCount); $pc->execute($params);
$total=(int)$pc->fetchColumn(); $total_pages=max(1,(int)ceil($total/$per_page));

/* ---------- List (GUARDED student_group) ---------- */
$selectFields = [
    "ei.student_id",
    "pi.full_name"
];
if ($hasGroupIdOnEducation) $selectFields[] = "ei.group_id"; else $selectFields[] = "NULL AS group_id";
$groupFields = [];
if ($hasEnroll && $hasCourseGroups) $groupFields[] = "en.group_name";
if ($hasStudentGroupCol)            $groupFields[] = "ei.student_group";
if (!empty($groupFields)) $selectFields[] = "COALESCE(" . implode(', ', $groupFields) . ") AS group_name";
else $selectFields[] = "NULL AS group_name";

$selectFields[] = "COALESCE(sqs.academic_status, 'active') AS academic_status";
$selectFields[] = "COALESCE(sqs.quiz_attempts, 0) AS quiz_attempts";
$selectFields[] = "$attemptExpr AS computed_attempts";
$selectFields[] = "qr.last_gid";

$sqlList = "
    SELECT " . implode(', ', $selectFields) . "
    FROM education_info ei
    INNER JOIN personal_info pi ON pi.id = ei.personal_id
    LEFT JOIN student_quiz_status sqs ON ei.student_id = sqs.student_id
    $joinLegacyGroup
    $joinCurrentGroup
    $joinTH
    $joinQR
    $wh
    ORDER BY ei.student_id
    LIMIT :lim OFFSET :off
";
$ps = $pdo->prepare($sqlList);
foreach ($params as $k => $v) $ps->bindValue($k, $v);
$ps->bindValue(':lim', $per_page, PDO::PARAM_INT);
$ps->bindValue(':off', $offset, PDO::PARAM_INT);
$ps->execute();
$rows = $ps->fetchAll(PDO::FETCH_ASSOC);

/* ---------- AJAX: DIAG ---------- */
if ($isAjax && $_GET['ajax']==='diag'){
  $diag = [
    'has_enrollments'=>$hasEnroll,
    'has_course_groups'=>$hasCourseGroups,
    'has_enrollment_date'=>$hasEnrollDate,
    'has_enrollment_status'=>$hasEnrollStatus,
    'has_end_date'=>$hasEndDate,
    'has_student_group_col'=>$hasStudentGroupCol,
    'has_quiz_results'=>$hasQR,
    'has_test_history'=>$hasTH,
    'has_recommended_groups'=>hasTable($pdo,'recommended_groups'),
    'has_recommended_subjects'=>hasTable($pdo,'recommended_subjects'),
  ];
  send_json(['ok'=>true,'diag'=>$diag]);
}

/* ---------- AJAX: registration detail (click name) ---------- */
if ($isAjax && $_GET['ajax']==='reg_detail'){
  $sid=trim($_GET['sid'] ?? '');
  if($sid===''){ send_json(['ok'=>false,'error'=>'missing sid'],400); }
  try {
    $sid_raw=trim($sid);
    $sid_norm=ltrim($sid_raw,'0');
    if($sid_norm==='') $sid_norm='0';
    $groupFields = [];
    if ($hasEnroll && $hasCourseGroups) $groupFields[] = "en.group_name";
    if ($hasStudentGroupCol)            $groupFields[] = "ei.student_group";
    $coalesceHdr = !empty($groupFields) ? "COALESCE(" . implode(', ', $groupFields) . ")" : "NULL";
    $sql="
      SELECT ei.student_id, pi.full_name,
             $coalesceHdr AS group_name,
             COALESCE(sqs.academic_status,'active') AS academic_status
      FROM education_info ei
      INNER JOIN personal_info pi ON pi.id=ei.personal_id
      LEFT JOIN student_quiz_status sqs ON ei.student_id=sqs.student_id
      $joinCurrentGroup
      WHERE TRIM(CAST(ei.student_id AS CHAR)) = :sid_raw
         OR TRIM(LEADING '0' FROM TRIM(CAST(ei.student_id AS CHAR))) = :sid_norm
      LIMIT 1
    ";
    $st=$pdo->prepare($sql);
    $st->execute([':sid_raw'=>$sid_raw, ':sid_norm'=>$sid_norm]);
    $head=$st->fetch(PDO::FETCH_ASSOC);
    if(!$head){ send_json(['ok'=>false,'error'=>'not found'],404); }

    $eiExtras=[];
    $eiCols=['program','major','faculty','admission_year','class_year','curriculum_year','email','phone'];
    if ($hasStudentGroupCol) $eiCols[]='student_group';
    $sel=[]; foreach($eiCols as $c){ if(hasColumn($pdo,'education_info',$c)) $sel[]=$c; }
    if($sel){
      $q=$pdo->prepare("SELECT ".implode(',',$sel)." FROM education_info WHERE TRIM(CAST(student_id AS CHAR))=:sid_raw OR TRIM(LEADING '0' FROM TRIM(CAST(student_id AS CHAR)))=:sid_norm LIMIT 1");
      $q->execute([':sid_raw'=>$sid_raw, ':sid_norm'=>$sid_norm]);
      $eiExtras=$q->fetch(PDO::FETCH_ASSOC) ?: [];
      $optionBacked = ['program','major','faculty','admission_year','class_year','curriculum_year'];
      foreach($optionBacked as $k){
        if (array_key_exists($k,$eiExtras)) $eiExtras[$k] = mapOptionLabel($eiExtras[$k], $optLabel);
      }
    }

    $enrollList=[];
    if($hasEnroll){
      $joinCG = $hasCourseGroups ? "LEFT JOIN course_groups cg ON cg.group_id=e.group_id" : "";
      $groupNameExpr = $hasCourseGroups ? "COALESCE(cg.group_name, CONCAT('Group#',e.group_id))" : "CONCAT('Group#',e.group_id)";
      $selCols = ["e.group_id", "$groupNameExpr AS group_name"];
      if ($hasEnrollStatus) $selCols[] = "e.status"; else $selCols[] = "NULL AS status";
      if ($hasEnrollDate)   $selCols[] = "e.enrollment_date"; else $selCols[] = "NULL AS enrollment_date";
      if ($hasEndDate)      $selCols[] = "e.end_date"; else $selCols[] = "NULL AS end_date";
      $orderExpr = $hasEnrollDate ? "IFNULL(e.enrollment_date,'1970-01-01')" :
                   (hasColumn($pdo,'enrollments','created_at') ? "IFNULL(e.created_at,'1970-01-01')" : "e.group_id");
      $statusWhere = $hasEnrollStatus ? "AND (e.status IS NULL OR e.status IN ('enrolled','active'))" : "";
      $q=$pdo->prepare("
        SELECT ".implode(',', $selCols)."
        FROM enrollments e
        $joinCG
        WHERE (TRIM(CAST(e.student_id AS CHAR))=:sid_raw
           OR TRIM(LEADING '0' FROM TRIM(CAST(e.student_id AS CHAR)))=:sid_norm)
        $statusWhere
        ORDER BY $orderExpr DESC, e.group_id
      ");
      $q->execute([':sid_raw'=>$sid_raw, ':sid_norm'=>$sid_norm]);
      while($r=$q->fetch(PDO::FETCH_ASSOC)){ $enrollList[]=$r; }
    }

    send_json([
      'ok'=>true,
      'student'=>[
        'student_id'=>$head['student_id'],
        'full_name'=>$head['full_name'],
        'group_name'=>$head['group_name'] ?? null,
        'academic_status'=>$head['academic_status'],
        'ei_extras'=>$eiExtras
      ],
      'enrollments'=>$enrollList,
    ]);
  } catch (Exception $e) {
    send_json(['ok'=>false,'error'=>$e->getMessage()],500);
  }
}

/* ---------- AJAX: quiz_detail (history) ---------- */
if ($isAjax && $_GET['ajax']==='quiz_detail'){
  $sid=trim($_GET['sid'] ?? '');
  if($sid===''){ send_json(['ok'=>false,'error'=>'missing sid'],400); }
  try {
    $sid_raw=trim($sid);
    $sid_norm=ltrim($sid_raw,'0');
    if($sid_norm==='') $sid_norm='0';
    $groupFields = [];
    if ($hasEnroll && $hasCourseGroups) $groupFields[] = "en.group_name";
    if ($hasStudentGroupCol)            $groupFields[] = "ei.student_group";
    $coalesceHdr = !empty($groupFields) ? "COALESCE(" . implode(', ', $groupFields) . ")" : "NULL";

    $hdr=$pdo->prepare("
      SELECT ei.student_id, pi.full_name, $coalesceHdr AS group_name
      FROM education_info ei
      INNER JOIN personal_info pi ON pi.id=ei.personal_id
      $joinCurrentGroup
      WHERE TRIM(CAST(ei.student_id AS CHAR))=:sid_raw OR TRIM(LEADING '0' FROM TRIM(CAST(ei.student_id AS CHAR)))=:sid_norm
      LIMIT 1
    ");
    $hdr->execute([':sid_raw'=>$sid_raw, ':sid_norm'=>$sid_norm]);
    $h=$hdr->fetch(PDO::FETCH_ASSOC);
    if(!$h){ send_json(['ok'=>false,'error'=>'not found'],404); }

    $attempts=[]; $latest_group=null;
    if ($hasQR){
      $q1=$pdo->prepare("
        SELECT quiz_id, score, submitted_at, created_at, recommend_group_id
        FROM quiz_results
        WHERE TRIM(CAST(student_id AS CHAR))=:sid_raw OR TRIM(LEADING '0' FROM TRIM(CAST(student_id AS CHAR)))=:sid_norm
        ORDER BY COALESCE(submitted_at, created_at) DESC
      ");
      $q1->execute([':sid_raw'=>$sid_raw, ':sid_norm'=>$sid_norm]);
      $first=true;
      while($r=$q1->fetch(PDO::FETCH_ASSOC)){
        $gid=(int)($r['recommend_group_id'] ?? 0);
        $label = $gid && isset($recomGroupLabel[$gid]) ? $recomGroupLabel[$gid] : null;
        $subs  = $gid && isset($recomSubs[$gid]) ? $recomSubs[$gid] : [];
        $rec=[
          'source'=>'quiz_results',
          'at'=>$r['submitted_at'] ?: $r['created_at'],
          'quiz_id'=>$r['quiz_id'],
          'score'=>$r['score'],
          'recommend_group_id'=>$gid ?: null,
          'recommend_group_label'=>$label,
          'subjects'=>$subs,
        ];
        $attempts[]=$rec;
        if($first){ $latest_group=$rec; $first=false; }
      }
    }
    if ($hasTH){
      $q2=$pdo->prepare("
        SELECT timestamp, recommended_group, recommended_subjects
        FROM test_history
        WHERE TRIM(CAST(username AS CHAR))=:sid_raw OR TRIM(LEADING '0' FROM TRIM(CAST(username AS CHAR)))=:sid_norm
        ORDER BY timestamp DESC
      ");
      $q2->execute([':sid_raw'=>$sid_raw, ':sid_norm'=>$sid_norm]);
      while($r=$q2->fetch(PDO::FETCH_ASSOC)){
        $subs=[]; if(!empty($r['recommended_subjects'])){
          $tmp=preg_split('/[\n\r,|]+/u', $r['recommended_subjects']); foreach($tmp as $t){ $t=trim($t); if($t!=='') $subs[]=$t; }
        }
        $attempts[]=[
          'source'=>'test_history',
          'at'=>$r['timestamp'],
          'quiz_id'=>null,
          'score'=>null,
          'recommend_group_id'=>null,
          'recommend_group_label'=>$r['recommended_group'] ?: null,
          'subjects'=>$subs,
        ];
      }
    }

    send_json([
      'ok'=>true,
      'student'=>['student_id'=>$h['student_id'],'full_name'=>$h['full_name'],'group_name'=>$h['group_name'] ?? null],
      'latest_recommendation'=>$latest_group,
      'attempts'=>$attempts
    ]);
  } catch (Exception $e) {
    send_json(['ok'=>false,'error'=>$e->getMessage()],500);
  }
}


/* ---------- Labels ---------- */
$asts=['active'=>'กำลังศึกษา','graduated'=>'สำเร็จการศึกษา','leave'=>'ลาพักการศึกษา','suspended'=>'พ้นสภาพการศึกษา'];
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
  :root{--bg:#0b1220;--p1:#111827;--p2:#1f2937;--line:#2b3646;--text:#F9FAFB;--muted:#9CA3AF;--cyan:#22d3ee;--purple:#a78bfa;--danger:#f43f5e;--green:#10b981}
  *{box-sizing:border-box} body{margin:0;font-family:'Sarabun',sans-serif;background:var(--bg);color:var(--text);line-height:1.6}
  .header{position:sticky;top:0;z-index:10;background:linear-gradient(180deg,var(--p1),rgba(17,24,39,.95));border-bottom:1px solid var(--line);padding:12px 16px;backdrop-filter:saturate(140%) blur(6px)}
  .header .wrap{max-width:1200px;margin:0 auto;display:flex;gap:12px;align-items:center;justify-content:space-between}
  .title{font-weight:800;color:#0b1220;background:linear-gradient(135deg,var(--cyan),var(--purple));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
  .container{max-width:1200px;margin:20px auto;padding:0 16px}
  .filters{display:grid;grid-template-columns:1.2fr .9fr .9fr .9fr auto;gap:10px;background:var(--p1);border:1px solid var(--line);border-radius:14px;padding:10px;margin-bottom:14px}
  @media (max-width:920px){.filters{grid-template-columns:repeat(2,1fr)}}
  .input,.select{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--p2);color:var(--text);outline:none}
  .btn{display:inline-flex;gap:8px;align-items:center;border:1px solid var(--line);background:var(--p2);color:var(--text);padding:10px 14px;border-radius:10px;text-decoration:none;cursor:pointer}
  .btn:hover{border-color:var(--cyan);color:var(--cyan)}
  .btn-primary{background:linear-gradient(135deg,var(--cyan),var(--purple));border-color:transparent;color:#0b1220;font-weight:800}
  .hint{grid-column:1/-1;color:var(--muted);font-size:13px}

  .summary-bar{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px}
  @media (max-width:920px){.summary-bar{grid-template-columns:repeat(2,1fr)}}
  .card{background:var(--p1);border:1px solid var(--line);border-radius:12px;padding:10px}
  .card h5{margin:0 0 6px 0;color:#9ee7f7;font-weight:700}
  .knum{font-weight:800;font-size:20px}

  .table-wrap{overflow:auto;border-radius:12px;border:1px solid var(--line);background:var(--p1)}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,.06);vertical-align:top}
  th{position:sticky;top:0;background:var(--p1);text-align:left;color:#E5E7EB}
  .row-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
  .badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;border:1px solid var(--line);background:var(--p2);color:#9CA3AF}
  .st-active{background:rgba(16,185,129,.1);color:var(--green);border-color:rgba(16,185,129,.25)}
  .st-graduated{background:rgba(167,139,250,.12);color:#c4b5fd;border-color:rgba(167,139,250,.3)}
  .st-leave{background:rgba(234,179,8,.12);color:#fde68a;border-color:rgba(234,179,8,.3)}
  .st-suspended{background:rgba(244,63,94,.12);color:#fecdd3;border-color:rgba(244,63,94,.35)}
  .pagination{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}
  .page-link{padding:8px 12px;border-radius:10px;border:1px solid var(--line);background:var(--p2);color:#fff;text-decoration:none}
  .page-link.active{background:linear-gradient(135deg,var(--cyan),var(--purple));border-color:transparent;color:#0b1220;font-weight:800}
  .home-btn{position:fixed;bottom:18px;right:18px;background:linear-gradient(135deg,var(--cyan),var(--purple));color:#0b1220;font-weight:800;padding:12px 16px;border-radius:12px;text-decoration:none}
  .rec-cell{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .rec-pill{background:rgba(34,211,238,.12);border:1px solid rgba(34,211,238,.35);color:#9ee7f7;padding:4px 8px;border-radius:999px;font-size:12px}
  .rec-btn{padding:6px 10px;border-radius:8px;background:var(--p2);border:1px solid var(--line);color:#fff;cursor:pointer}
  .rec-btn:hover{border-color:var(--cyan);color:#9ee7f7}
  .student-link{color:#9ee7f7;cursor:pointer;text-decoration:underline}
  .student-link:hover{color:#22d3ee}
  .idx-col{color:#9CA3AF}

  /* ===== Modal: clean + tabs + loader ===== */
  .modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center;padding:16px}
  .modal-card{background:var(--p1);border:1px solid var(--line);border-radius:14px;max-width:980px;width:100%;box-shadow:0 12px 30px rgba(0,0,0,.35);display:flex;flex-direction:column}
  .modal-header{position:sticky;top:0;background:var(--p1);display:flex;gap:8px;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid var(--line);z-index:2}
  .modal-title{display:flex;gap:8px;align-items:center;font-weight:700}
  .modal-actions{display:flex;gap:8px;align-items:center}
  .close{background:transparent;border:1px solid var(--line);color:#fff;padding:6px 10px;border-radius:8px;cursor:pointer}
  .close:hover{border-color:#f43f5e;background:#f43f5e}
  .modal-tabs{display:flex;gap:6px;flex-wrap:wrap;padding:10px 16px;border-bottom:1px solid var(--line);background:linear-gradient(0deg,rgba(255,255,255,.01),rgba(255,255,255,0))}
  .tab{padding:8px 12px;border-radius:999px;border:1px solid var(--line);cursor:pointer;font-size:13px;background:var(--p2);color:#E5E7EB}
  .tab.active{background:linear-gradient(135deg,var(--cyan),var(--purple));border-color:transparent;color:#0b1220;font-weight:800}
  .modal-body{padding:12px 16px;max-height:70vh;overflow:auto}
  .section{margin-bottom:14px}
  .section h4{margin:0 0 8px 0;color:#9ee7f7}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
  .kv{border:1px dashed rgba(255,255,255,.08);border-radius:10px;padding:8px}
  .kv small{display:block;color:#9CA3AF}
  .chip{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid var(--line);background:var(--p2);font-size:12px;color:#E5E7EB}
  .attempt-row{padding:8px 10px;border:1px solid rgba(255,255,255,.06);border-radius:10px;margin-bottom:8px;background:rgba(255,255,255,.02)}
  .attempt-head{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .timeline{position:relative;margin-left:8px}
  .timeline:before{content:"";position:absolute;left:8px;top:0;bottom:0;width:2px;background:rgba(255,255,255,.06)}
  .t-item{position:relative;padding-left:22px;margin:10px 0}
  .t-item:before{content:"";position:absolute;left:2px;top:8px;width:12px;height:12px;border-radius:50%;background:linear-gradient(135deg,var(--cyan),var(--purple));box-shadow:0 0 0 3px rgba(34,211,238,.15)}
  .mono{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace}
  .loader{display:inline-flex;gap:8px;align-items:center;color:#9CA3AF}
  .dot{width:6px;height:6px;border-radius:50%;background:#9CA3AF;animation:b 1.4s infinite}
  .dot:nth-child(2){animation-delay:.2s}.dot:nth-child(3){animation-delay:.4s}
  @keyframes b{0%{opacity:0.2}50%{opacity:1}100%{opacity:0.2}}
</style>
</head>
<body>
<header class="header">
  <div class="wrap">
    <div class="title"><i class="fas fa-users"></i> รายชื่อนักศึกษา</div>
    <div style="display:flex;gap:8px">
      <a class="btn" href="admin_dashboard.php"><i class="fas fa-home"></i> หน้าหลัก</a>
      <?php $exportLink = '?' . http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>
    </div>
  </div>
</header>

<main class="container">
  <!-- Filters: เรียบขึ้น -->
  <form class="filters" method="get">
    <input class="input" type="text" name="q" placeholder="ค้นหา: รหัส/ชื่อ" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($enableGroupFilter): ?>
      <select class="select" name="group" title="กลุ่ม">
        <option value="">ทุกกลุ่ม</option>
        <?php foreach($groups as $gg): $sel = ((string)$gg['group_id']===$gFilter)?'selected':''; ?>
          <option value="<?php echo $gg['group_id']; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($gg['group_name'], ENT_QUOTES, 'UTF-8'); ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select class="select" name="academic_status" title="สถานะ">
      <option value="">สถานะทั้งหมด</option>
      <?php foreach($asts as $k=>$v){ $sel = ($ast===$k)?'selected':''; echo "<option value=\"$k\" $sel>$v</option>"; } ?>
    </select>
    <select class="select" name="quiz_state" title="ทดสอบ">
      <option value="">แบบทดสอบทั้งหมด</option>
      <option value="did" <?php echo $quiz==='did'?'selected':''; ?>>ทำแล้ว</option>
      <option value="not" <?php echo $quiz==='not'?'selected':''; ?>>ยังไม่ทำ</option>
    </select>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-primary" type="submit"><i class="fas fa-magnifying-glass"></i> ค้นหา</button>
      <a class="btn" href="students_list.php"><i class="fas fa-rotate-left"></i> ล้างตัวกรอง</a>
    </div>
    <div class="hint"><i class="fa-regular fa-lightbulb"></i> แนะนำ: คลิกชื่อเพื่อดู “ข้อมูลนักศึกษา”, ปุ่มนาฬิกาเพื่อดู “ประวัติคำแนะนำ/แบบทดสอบ”</div>
  </form>

  <!-- Table -->
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th style="width:66px">#</th>
          <th style="width:120px">รหัส</th>
          <th>ชื่อ-นามสกุล</th>
          <th style="width:200px">กลุ่ม</th>
          <th style="width:160px">สถานะ</th>
          <th style="width:130px">ทำแบบทดสอบ</th>
          <th style="width:260px">คำแนะนำล่าสุด</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($rows): $rowIdx=0; foreach($rows as $r):
          $cls=['active'=>'st-active','graduated'=>'st-graduated','leave'=>'st-leave','suspended'=>'st-suspended'][$r['academic_status']] ?? 'st-active';
          $sid=(string)$r['student_id']; $rid=htmlspecialchars($sid,ENT_QUOTES,'UTF-8'); $order=$offset+(++$rowIdx);
          $computedAttempts=(int)($r['computed_attempts'] ?? $r['quiz_attempts'] ?? 0);
          $gid = (int)($r['last_gid'] ?? 0);
          $gLabel = $gid>0 ? ($recomGroupLabel[$gid] ?? ("Group#".$gid)) : null;
          $subs = $gid>0 ? ($recomSubs[$gid] ?? []) : [];
        ?>
        <tr>
          <td class="idx-col"><?php echo $order; ?></td>
          <td class="mono"><?php echo htmlspecialchars($r['student_id'],ENT_QUOTES,'UTF-8'); ?></td>
          <td>
            <a class="student-link" data-sid="<?php echo $rid; ?>"><?php echo htmlspecialchars($r['full_name'],ENT_QUOTES,'UTF-8'); ?></a>
          </td>
          <td><?php echo htmlspecialchars($r['group_name'] ?? '-',ENT_QUOTES,'UTF-8'); ?></td>
          <td><span class="badge <?php echo $cls; ?>"><?php echo htmlspecialchars($asts[$r['academic_status']] ?? 'กำลังศึกษา',ENT_QUOTES,'UTF-8'); ?></span></td>
          <td><?php echo $computedAttempts; ?></td>
          <td>
            <?php if ($gLabel): ?>
              <div class="rec-cell">
                <span class="rec-pill"><?php echo htmlspecialchars($gLabel,ENT_QUOTES,'UTF-8'); ?></span>
                <?php foreach(array_slice($subs,0,3) as $s): ?>
                  <span class="badge"><?php echo htmlspecialchars($s,ENT_QUOTES,'UTF-8'); ?></span>
                <?php endforeach; ?>
                <?php if(count($subs) > 3): ?>
                  <span class="badge">+<?php echo count($subs)-3; ?></span>
                <?php endif; ?>
                <button class="rec-btn" onclick="openHistory('<?php echo $rid; ?>')"><i class="fas fa-clock"></i> ดู</button>
              </div>
            <?php else: ?>
              <span class="badge">ไม่มีคำแนะนำ</span>
              <button class="rec-btn" onclick="openHistory('<?php echo $rid; ?>')"><i class="fas fa-clock"></i> ดู</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="7" style="color:#9CA3AF;text-align:center">ไม่พบข้อมูล</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
    <div class="pagination">
      <?php $qs=$_GET; unset($qs['page']); for($i=1;$i<=$total_pages;$i++): $link='?'.http_build_query($qs+['page'=>$i]); $isActive=($i===$page)?'active':''; ?>
        <a class="page-link <?php echo $isActive; ?>" href="<?php echo htmlspecialchars($link,ENT_QUOTES,'UTF-8'); ?>"><?php echo $i; ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</main>

<a href="admin_dashboard.php" class="home-btn"><i class="fas fa-home"></i> หน้าหลัก</a>

<!-- Modal -->
<div id="detail-modal" class="modal" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-header">
      <div class="modal-title"><i class="fa-solid fa-rectangle-list"></i> <span id="detail-title">รายละเอียด</span></div>
      <div class="modal-actions">
        <button class="close" onclick="closeDetail()"><i class="fa-solid fa-xmark"></i> ปิด</button>
      </div>
    </div>

    <!-- Tabs -->
    <div class="modal-tabs" id="tabs" style="display:none">
      <button class="tab active" data-tab="tab-summary"><i class="fa-solid fa-circle-info"></i> สรุป</button>
      <button class="tab" data-tab="tab-reco"><i class="fa-solid fa-star"></i> คำแนะนำ</button>
      <button class="tab" data-tab="tab-quiz"><i class="fa-solid fa-clipboard-check"></i>ผลแบบทดสอบ</button>

    </div>

    <div class="modal-body" id="detail-body">
      <span class="loader"><span class="dot"></span><span class="dot"></span><span class="dot"></span> กำลังโหลด...</span>
    </div>
  </div>
</div>

<script>
/* ===== Utilities ===== */
const astMap = {'active':'กำลังศึกษา','graduated':'สำเร็จการศึกษา','leave':'ลาพักการศึกษา','suspended':'พ้นสภาพการศึกษา'};

function fmtDT(s){
  if(!s) return '—';
  const d = new Date(s);
  if (isNaN(d)) return s;
  const pad = n => String(n).padStart(2,'0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
function escapeHTML(str){ return (str??'').toString().replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

/* robust fetch JSON (กับ content-type guard & error) */
async function fetchJSON(url, opts = {}) {
  const res = await fetch(url, { 
    ...opts, 
    headers: { 'Accept':'application/json','Content-Type':'application/json', ...(opts.headers||{}) } 
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const ct = res.headers.get('content-type') || '';
  if (!ct.includes('application/json')) throw new Error(`Expected JSON, got ${ct || 'unknown'}`);
  const js = await res.json();
  if (js.ok === false) throw new Error(js.error || 'Unknown error');
  return js;
}

/* ===== Modal core ===== */
function openDetail(title){
  const m=document.getElementById('detail-modal');
  m.style.display='flex'; document.body.style.overflow='hidden';
  document.getElementById('detail-title').textContent=title||'รายละเอียด';
  document.getElementById('detail-body').innerHTML='<span class="loader"><span class="dot"></span><span class="dot"></span><span class="dot"></span> กำลังโหลด...</span>';
  document.getElementById('tabs').style.display='none';
}
function closeDetail(){ const m=document.getElementById('detail-modal'); m.style.display='none'; document.body.style.overflow='auto'; }
document.addEventListener('keydown', e=>{ if(e.key==='Escape'){ closeDetail(); }});
document.addEventListener('click', e=>{ if(e.target.classList.contains('modal')) closeDetail(); });

/* ===== Tab switching ===== */
document.querySelectorAll('.modal-tabs .tab').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.querySelectorAll('.modal-tabs .tab').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    const target = btn.dataset.tab;
    document.querySelectorAll('.tab-pane').forEach(p=>p.style.display='none');
    const el = document.getElementById(target);
    if (el) el.style.display='block';
  });
});


/* ===== Renderers ===== */
function renderSummary(student){
  return `
    <div class="section">
      <h4><i class="fa-solid fa-user"></i> ข้อมูลนักศึกษา</h4>
      <div class="grid">
        <div class="kv"><small>รหัส</small><div class="mono">${escapeHTML(student.student_id)}</div></div>
        <div class="kv"><small>ชื่อ-นามสกุล</small><div>${escapeHTML(student.full_name)}</div></div>
        <div class="kv"><small>กลุ่มปัจจุบัน</small><div>${escapeHTML(student.group_name||'—')}</div></div>
        <div class="kv"><small>สถานะ</small><div>${escapeHTML(astMap[student.academic_status]||'กำลังศึกษา')}</div></div>
        <div class="kv"><small>หลักสูตร</small><div>${escapeHTML(student.curriculum_name||'—')}</div></div>
      </div>
    </div>
  `;
}
function renderEiExtras(ei){
  if(!ei || !Object.keys(ei).length) return '';
  const labels = {program:'สาขาวิชา',major:'สาขา',faculty:'คณะ',admission_year:'ปีเข้าศึกษา',class_year:'ชั้นปี',curriculum_year:'ปีหลักสูตร',student_group:'กลุ่มเรียน',email:'อีเมล',phone:'โทรศัพท์'};
  const items = Object.entries(ei).map(([k,v])=>`
      <div class="kv"><small>${escapeHTML(labels[k]||k)}</small><div>${escapeHTML(v??'—')}</div></div>
  `).join('');
  return `
    <div class="section">
      <h4><i class="fa-solid fa-school"></i> ข้อมูลการศึกษา</h4>
      <div class="grid">${items}</div>
    </div>
  `;
}
function splitAttempts(attempts){
  const fromQR = [], fromTH = [];
  (attempts||[]).forEach(a => (a.source === 'quiz_results' ? fromQR : fromTH).push(a));
  return {fromQR, fromTH};
}
function renderLatestReco(latest){
  if(!latest || !(latest.recommend_group_label || latest.recommend_group_id)){
    return `<div class="attempt-row"><small>— ไม่มีคำแนะนำล่าสุด —</small></div>`;
  }
  const gLabel = latest.recommend_group_label || latest.recommend_group_id;
  const subs = Array.isArray(latest.subjects) ? latest.subjects : [];
  return `
    <div class="attempt-row">
      <div class="attempt-head"><span class="chip">ล่าสุด</span></div>
      ${subs.length?`<div style="margin-top:6px"><small>${subs.map(escapeHTML).join(', ')}</small></div>`:''}
      <div style="margin-top:6px"><small>อัปเดตเมื่อ: ${escapeHTML(fmtDT(latest.at))}</small></div>
    </div>
  `;
}
function renderRecoHistory(list){
  if(!list.length) return `<div class="attempt-row"><small>— ไม่มีประวัติคำแนะนำ —</small></div>`;
  const items = list.map(at=>{
    const gLabel = at.recommend_group_label || at.recommend_group_id || '—';
    const subs = (Array.isArray(at.subjects) && at.subjects.length) ? at.subjects.map(escapeHTML).join(', ') : '';
    return `
      <div class="t-item">
        <div class="attempt-head"><span class="chip">${at.source==='quiz_results'?'ระบบ':'บันทึกเดิม'}</span><strong>${escapeHTML(gLabel)}</strong></div>
        <div><small>เวลา: ${escapeHTML(fmtDT(at.at))}${at.score!=null?` | คะแนน: ${escapeHTML(at.score)}`:''}</small></div>
        ${subs?`<div><small>วิชาที่แนะนำ: ${subs}</small></div>`:''}
      </div>
    `;
  }).join('');
  return `<div class="timeline">${items}</div>`;
}
function renderQuizHistory(list){
  if(!list.length) return `<div class="attempt-row"><small>— ไม่มีประวัติการทำแบบทดสอบ —</small></div>`;
  const items = list.map(at=>{
    return `
      <div class="t-item">
        <div class="attempt-head"><span class="chip">${at.source==='quiz_results'?'ระบบ':'บันทึกเดิม'}</span><strong>${escapeHTML(fmtDT(at.at))}</strong></div>
        <div><small>คะแนน: ${escapeHTML(at.score??'—')} | กลุ่มที่แนะนำ: ${escapeHTML(at.recommend_group_label || at.recommend_group_id || '—')}</small></div>
        ${(Array.isArray(at.subjects)&&at.subjects.length)?`<div><small>--: ${at.subjects.map(escapeHTML).join(', ')}</small></div>`:''}
      </div>
    `;
  }).join('');
  return `<div class="timeline">${items}</div>`;
}
function renderEnrollments(list){
  if(!Array.isArray(list) || list.length===0){
    return `<div class="attempt-row"><small>— ไม่มีข้อมูลทะเบียน —</small></div>`;
  }
  const rows = list.map((e,i)=>`
    <tr>
      <td>${i+1}</td>
      <td>${escapeHTML(e.group_name||('Group#'+(e.group_id??'')))}</td>
      <td>${escapeHTML(e.status||'—')}</td>
      <td>${escapeHTML(e.enrollment_date?fmtDT(e.enrollment_date):'—')}</td>
      <td>${escapeHTML(e.end_date?fmtDT(e.end_date):'—')}</td>
    </tr>
  `).join('');
  return `
    <div class="section">
      <h4><i class="fa-solid fa-book-open-reader"></i> ประวัติทะเบียนกลุ่ม</h4>
      <div class="table-wrap" style="border:none">
        <table>
          <thead><tr><th style="width:56px">#</th><th>กลุ่ม</th><th style="width:140px">สถานะ</th><th style="width:160px">เริ่ม</th><th style="width:160px">สิ้นสุด</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    </div>
  `;
}

/* ===== Loaders (แท็บอัตโนมัติ) ===== */
async function loadRegDetail(sid){
  const js = await fetchJSON(`students_list.php?ajax=reg_detail&sid=${encodeURIComponent(sid)}`);
  const mBody = document.getElementById('detail-body');

  document.getElementById('tabs').style.display='flex';
  document.querySelectorAll('.modal-tabs .tab').forEach(b=>b.classList.remove('active'));
  document.querySelector('.modal-tabs .tab[data-tab="tab-summary"]').classList.add('active');

  const summaryHTML = renderSummary(js.student) + renderEiExtras(js.student.ei_extras);
  const enrollHTML  = renderEnrollments(js.enrollments);

  mBody.innerHTML = `
    <div id="tab-summary" class="tab-pane" style="display:block">
      ${summaryHTML}
    </div>
    <div id="tab-reco" class="tab-pane" style="display:none">
      <div class="attempt-row"><small>— เปิดแท็บ “คำแนะนำ” ผ่านปุ่มประวัติ เพื่อดูรายละเอียด —</small></div>
    </div>
    <div id="tab-quiz" class="tab-pane" style="display:none">
      <div class="attempt-row"><small>— เปิดแท็บ “แบบทดสอบ” ผ่านปุ่มประวัติ —</small></div>
    </div>
    <div id="tab-enroll" class="tab-pane" style="display:none">
      ${enrollHTML}
    </div>
  `;
}

async function loadQuizHistory(sid){
  const js = await fetchJSON(`students_list.php?ajax=quiz_detail&sid=${encodeURIComponent(sid)}`);
  const mBody = document.getElementById('detail-body');

  document.getElementById('tabs').style.display='flex';
  document.querySelectorAll('.modal-tabs .tab').forEach(b=>b.classList.remove('active'));
  document.querySelector('.modal-tabs .tab[data-tab="tab-reco"]').classList.add('active');

  const latest = renderLatestReco(js.latest_recommendation);
  const {fromQR, fromTH} = splitAttempts(js.attempts);

  mBody.innerHTML = `
    <div id="tab-summary" class="tab-pane" style="display:none">
      ${renderSummary(js.student)}
    </div>

    <div id="tab-reco" class="tab-pane" style="display:block">
      <div class="section">
        <h4><i class="fa-solid fa-star"></i> คำแนะนำล่าสุด</h4>
        ${latest}
      </div>
      <div class="section">
        <h4><i class="fa-solid fa-clock-rotate-left"></i> ประวัติคำแนะนำ (ทั้งหมด)</h4>
        ${renderRecoHistory(js.attempts)}
      </div>
    </div>

    <div id="tab-quiz" class="tab-pane" style="display:none">
      <div class="section">
        <h4><i class="fa-solid fa-list-check"></i> ประวัติการทำแบบทดสอบ</h4>
        ${renderQuizHistory(fromQR.concat(fromTH))}
      </div>
    </div>
  `;
}

/* ===== Wiring ===== */
document.addEventListener('DOMContentLoaded', function() {
  document.addEventListener('click', async (e) => {
    const a = e.target.closest('.student-link');
    if (a){ e.preventDefault(); const sid = a.getAttribute('data-sid'); openDetail('ข้อมูลนักศึกษา'); await loadRegDetail(sid); }
  });
});

async function openHistory(sid){
  openDetail('คำแนะนำ/แบบทดสอบ');
  await loadQuizHistory(sid);
}
async function openReg(sid){
  openDetail('ข้อมูลนักศึกษา/ทะเบียน');
  await loadRegDetail(sid);
}
</script>
</body>
</html>
