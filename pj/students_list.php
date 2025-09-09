<?php
// students_list.php — FULL drop-in (guards missing enrollments.status/enrollment_date/end_date)
// AJAX returns JSON with strict content-type check + full schema guard.
// Added: map option-id from education_info to labels via form_options in reg_detail.

session_start();
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] !== '';

// Harden AJAX error to JSON
ini_set('display_errors', 0);
ini_set('log_errors', 1);
if (!ini_get('error_log')) { ini_set('error_log', __DIR__.'/php-error.log'); }

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
$coalesceList = "en.group_name";
if ($hasStudentGroupCol) $coalesceList .= ", ei.student_group";
$selectGroupName="COALESCE($coalesceList) AS group_name,";
$selectGroupId="NULL AS group_id,";
$selectComputed="$attemptExpr AS computed_attempts,";

$sqlList="
  SELECT
    ei.student_id,
    $selectGroupId
    pi.full_name,
    $selectGroupName
    COALESCE(sqs.academic_status,'active') AS academic_status,
    COALESCE(sqs.quiz_attempts,0) AS quiz_attempts,
    $selectComputed
    COALESCE(qr.last_gid, NULL) AS last_gid
  FROM education_info ei
  INNER JOIN personal_info pi ON pi.id=ei.personal_id
  LEFT JOIN student_quiz_status sqs ON ei.student_id=sqs.student_id
  $joinLegacyGroup
  $joinCurrentGroup
  $joinTH
  $joinQR
  $wh
  ORDER BY ei.student_id
  LIMIT :lim OFFSET :off
";
$ps=$pdo->prepare($sqlList);
foreach($params as $k=>$v){ $ps->bindValue($k,$v); }
$ps->bindValue(':lim',$per_page,PDO::PARAM_INT);
$ps->bindValue(':off',$offset,PDO::PARAM_INT);
$ps->execute();
$rows=$ps->fetchAll(PDO::FETCH_ASSOC);

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
  $sid_raw=trim($sid); $sid_norm=ltrim($sid_raw,'0'); if($sid_norm==='') $sid_norm='0';

  $coalesceHdr = "en.group_name";
  if ($hasStudentGroupCol) $coalesceHdr .= ", ei.student_group";

  // header
  $sql="
    SELECT ei.student_id, pi.full_name,
           COALESCE($coalesceHdr) AS group_name,
           COALESCE(sqs.academic_status,'active') AS academic_status
    FROM education_info ei
    INNER JOIN personal_info pi ON pi.id=ei.personal_id
    LEFT JOIN student_quiz_status sqs ON ei.student_id=sqs.student_id
    $joinCurrentGroup
    WHERE TRIM(CAST(ei.student_id AS CHAR)) = :sid_raw
       OR TRIM(LEADING '0' FROM TRIM(CAST(ei.student_id AS CHAR))) = :sid_norm
    LIMIT 1
  ";
  $st=$pdo->prepare($sql); $st->execute([':sid_raw'=>$sid_raw, ':sid_norm'=>$sid_norm]);
  $head=$st->fetch(PDO::FETCH_ASSOC);
  if(!$head){ send_json(['ok'=>false,'error'=>'not found'],404); }

  // extras from education_info
  $eiExtras=[]; 
  $eiCols=['program','major','faculty','admission_year','class_year','curriculum_year','email','phone'];
  if ($hasStudentGroupCol) $eiCols[]='student_group';
  $sel=[]; foreach($eiCols as $c){ if(hasColumn($pdo,'education_info',$c)) $sel[]=$c; }
  if($sel){
    $q=$pdo->prepare("SELECT ".implode(',', $sel)." FROM education_info WHERE TRIM(CAST(student_id AS CHAR))=:sid_raw OR TRIM(LEADING '0' FROM TRIM(CAST(student_id AS CHAR)))=:sid_norm LIMIT 1");
    $q->execute([':sid_raw'=>$sid_raw, ':sid_norm'=>$sid_norm]);
    $eiExtras=$q->fetch(PDO::FETCH_ASSOC) ?: [];
    // map numeric option id -> label for known option-backed fields
    $optionBacked = ['program','major','faculty','admission_year','class_year','curriculum_year'];
    foreach($optionBacked as $k){
      if (array_key_exists($k,$eiExtras)) {
        $eiExtras[$k] = mapOptionLabel($eiExtras[$k], $optLabel);
      }
    }
  }

  // enrollments list (guard columns)
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
}

/* ---------- AJAX: quiz_detail (history) ---------- */
if ($isAjax && $_GET['ajax']==='quiz_detail'){
  $sid=trim($_GET['sid'] ?? '');
  if($sid===''){ send_json(['ok'=>false,'error'=>'missing sid'],400); }
  $sid_raw=trim($sid); $sid_norm=ltrim($sid_raw,'0'); if($sid_norm==='') $sid_norm='0';

  $coalesceHdr = "en.group_name";
  if ($hasStudentGroupCol) $coalesceHdr .= ", ei.student_group";

  $hdr=$pdo->prepare("
    SELECT ei.student_id, pi.full_name, COALESCE($coalesceHdr) AS group_name
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
}

/* ---------- Export CSV ---------- */
if(isset($_GET['export']) && $_GET['export']==='csv'){
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=students_'.date('Ymd_His').'.csv');
  echo "\xEF\xBB\xBF";
  $out=fopen('php://output','w');
  fputcsv($out,['student_id','full_name','group','academic_status','quiz_attempts(computed)','latest_recommendation(group)','subjects(sample up to 5)']);
  foreach($rows as $r){
    $latestLabel=''; $subjectsOut='';
    $gid = (int)($r['last_gid'] ?? 0);
    if($gid>0){
      $latestLabel = $recomGroupLabel[$gid] ?? ("Group#".$gid);
      $subs = $recomSubs[$gid] ?? [];
      $subjectsOut = implode('|', array_slice($subs,0,5));
    }
    $computed=(int)($r['computed_attempts'] ?? $r['quiz_attempts'] ?? 0);
    fputcsv($out,[
      $r['student_id'],$r['full_name'],$r['group_name'] ?? '',
      $r['academic_status'],$computed,$latestLabel,$subjectsOut
    ]);
  }
  fclose($out); exit;
}

/* ---------- Labels ---------- */
$asts=['active'=>'กำลังศึกษา','graduated'=>'สำเร็จการศึกษา','leave'=>'ลาพัก','suspended'=>'พักการเรียน'];
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
  :root{--bg:#111827;--p1:#1F2937;--p2:#374151;--line:#374151;--text:#F9FAFB;--muted:#9CA3AF;--cyan:#22d3ee;--purple:#a78bfa;--danger:#f43f5e;--green:#10b981}
  *{box-sizing:border-box} body{margin:0;font-family:'Sarabun',sans-serif;background:var(--bg);color:var(--text);line-height:1.6}
  .header{position:sticky;top:0;z-index:10;background:var(--p1);border-bottom:1px solid var(--line);padding:12px 16px}
  .header .wrap{max-width:1200px;margin:0 auto;display:flex;gap:12px;align-items:center;justify-content:space-between}
  .title{font-weight:700;color:var(--cyan)}
  .container{max-width:1200px;margin:24px auto;padding:0 16px}
  .filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;background:var(--p1);border:1px solid var(--line);border-radius:12px;padding:12px;margin-bottom:16px}
  .input,.select{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--p2);color:var(--text);outline:none}
  .btn{display:inline-flex;gap:8px;align-items:center;border:1px solid var(--line);background:var(--p2);color:var(--text);padding:10px 14px;border-radius:10px;text-decoration:none;cursor:pointer}
  .btn:hover{border-color:var(--cyan);color:var(--cyan)}
  .btn-primary{background:linear-gradient(135deg,var(--cyan),var(--purple));border-color:transparent;color:#0b1220;font-weight:800}
  .table-wrap{overflow:auto;border-radius:12px;border:1px solid var(--line);background:var(--p1)}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{padding:12px;border-bottom:1px solid rgba(255,255,255,.06)}
  th{position:sticky;top:0;background:var(--p1);text-align:left;color:#E5E7EB}
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
  .modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center;padding:16px}
  .modal-card{background:var(--p1);border:1px solid var(--line);border-radius:12px;max-width:900px;width:100%;box-shadow:0 12px 30px rgba(0,0,0,.3)}
  .modal-header{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid var(--line)}
  .modal-body{padding:12px 16px;max-height:70vh;overflow:auto}
  .close{background:transparent;border:1px solid var(--line);color:#fff;padding:6px 10px;border-radius:8px;cursor:pointer}
  .close:hover{border-color:#f43f5e;background:#f43f5e}
  .course-item{border-bottom:1px dashed rgba(255,255,255,.08);padding:8px 0}
  .course-item small{color:#9CA3AF}
  .student-link{color:#9ee7f7;cursor:pointer;text-decoration:underline}
  .student-link:hover{color:#22d3ee}
  .idx-col{color:#9CA3AF}
  .attempt-row{padding:8px 0;border-bottom:1px dashed rgba(255,255,255,.08)}
  .attempt-head{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .chip{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid var(--line);background:var(--p2);font-size:12px;color:#E5E7EB}
</style>
</head>
<body>
<header class="header">
  <div class="wrap">
    <div class="title"><i class="fas fa-users"></i> รายชื่อนักศึกษา</div>
    <div style="display:flex;gap:8px">
      <a class="btn" href="admin_dashboard.php"><i class="fas fa-home"></i> หน้าหลัก</a>
      <?php $exportLink = '?' . http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>
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
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> ค้นหา</button>
      <a class="btn" href="students_list.php"><i class="fas fa-rotate-left"></i> ยกเลิก</a>
    </div>
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
          <th style="width:240px">กลุ่มแนะนำ & วิชา</th>
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
          <td><?php echo htmlspecialchars($r['student_id'],ENT_QUOTES,'UTF-8'); ?></td>
          <td><a class="student-link" data-sid="<?php echo $rid; ?>"><?php echo htmlspecialchars($r['full_name'],ENT_QUOTES,'UTF-8'); ?></a></td>
          <td><?php echo htmlspecialchars($r['group_name'] ?? '-',ENT_QUOTES,'UTF-8'); ?></td>
          <td><span class="badge <?php echo $cls; ?>"><?php echo htmlspecialchars($asts[$r['academic_status']] ?? 'กำลังศึกษา',ENT_QUOTES,'UTF-8'); ?></span></td>
          <td><?php echo $computedAttempts; ?></td>
          <td>
            <?php if ($gLabel): ?>
              <div class="rec-cell">
                <span class="rec-pill"><?php echo htmlspecialchars($gLabel,ENT_QUOTES,'UTF-8'); ?> • <?php echo count($subs); ?> วิชา</span>
                <?php foreach(array_slice($subs,0,4) as $s): ?>
                  <span class="badge"><?php echo htmlspecialchars($s,ENT_QUOTES,'UTF-8'); ?></span>
                <?php endforeach; ?>
                <?php if(count($subs) > 4): ?>
                  <span class="badge">+<?php echo count($subs)-4; ?></span>
                <?php endif; ?>
                <button class="rec-btn" onclick="openHistory('<?php echo $rid; ?>')"><i class="fas fa-clock"></i> ดูประวัติ</button>
              </div>
            <?php else: ?>
              <span class="badge">— ไม่มีคำแนะนำ —</span>
              <button class="rec-btn" onclick="openHistory('<?php echo $rid; ?>')"><i class="fas fa-clock"></i> ดูประวัติ</button>
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
      <strong id="detail-title">รายละเอียด</strong>
      <button class="close" onclick="closeDetail()">ปิด</button>
    </div>
    <div class="modal-body" id="detail-body">กำลังโหลด...</div>
  </div>
</div>

<script>
async function fetchJSON(url, opts = {}) {
  const res = await fetch(url, { ...opts, headers: { 'Accept': 'application/json', ...(opts.headers||{}) } });
  const ct = res.headers.get('content-type') || '';
  const text = await res.text();
  if (!ct.includes('application/json')) {
    const preview = text.slice(0, 300).replace(/\s+/g, ' ').trim();
    throw new Error(`Expected JSON, got ${ct || 'unknown'}: ${preview}`);
  }
  let js;
  try { js = JSON.parse(text); } catch(e){ throw new Error(`Invalid JSON: ${e.message}`); }
  if (!res.ok || js.ok === false) {
    throw new Error((js && js.error) ? js.error : `HTTP ${res.status}`);
  }
  return js;
}

// click name -> registration detail
document.addEventListener('click', async (e) => {
  const a = e.target.closest('.student-link');
  if (!a) return;
  e.preventDefault();
  const sid = a.getAttribute('data-sid');
  openDetail('ข้อมูลการลงทะเบียน');
  await loadRegDetail(sid);
});

async function openHistory(sid){
  openDetail('ประวัติคำแนะนำ/แบบทดสอบ');
  await loadQuizHistory(sid);
}

function openDetail(title){
  const m=document.getElementById('detail-modal');
  m.style.display='flex'; document.body.style.overflow='hidden';
  document.getElementById('detail-title').textContent=title||'รายละเอียด';
  document.getElementById('detail-body').textContent='กำลังโหลด...';
}
function closeDetail(){ const m=document.getElementById('detail-modal'); m.style.display='none'; document.body.style.overflow='auto'; }
document.addEventListener('keydown', e=>{ if(e.key==='Escape'){ document.querySelectorAll('.modal').forEach(m=>ม.style.display='none'); document.body.style.overflow='auto'; }});
document.addEventListener('click', e=>{ if(e.target.classList.contains('modal')){ e.target.style.display='none'; document.body.style.overflow='auto'; }});

async function loadRegDetail(sid){
  try{
    const js  = await fetchJSON(`?ajax=reg_detail&sid=${encodeURIComponent(sid)}`);
    const mBody = document.getElementById('detail-body');
    const astMap = {'active':'กำลังศึกษา','graduated':'สำเร็จการศึกษา','leave':'ลาพัก','suspended':'พักการเรียน'};

    let html = `
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:12px">
        <div class="badge">รหัส: ${js.student.student_id}</div>
        <div class="badge">ชื่อ: ${js.student.full_name}</div>
        <div class="badge">กลุ่มปัจจุบัน: ${js.student.group_name || '—'}</div>
        <div class="badge">สถานะ: ${astMap[js.student.academic_status] || 'กำลังศึกษา'}</div>
      </div>
    `;

    if (js.student.ei_extras && Object.keys(js.student.ei_extras).length){
      html += `<h4 style="margin:8px 0;color:#9ee7f7">ข้อมูลการศึกษา</h4><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px">`;
      for (const [k,v] of Object.entries(js.student.ei_extras)){
        const label = ({
          program:'สาขาวิชา',major:'สาขา',faculty:'คณะ',
          curriculum_year:'ปีหลักสูตร',student_group:'กลุ่มเรียน'
        }[k] || k);
        html += `<div><small style="color:#9CA3AF">${label}</small><div>${(v ?? '') || '—'}</div></div>`;
      }
      html += `</div>`;
    }

    html += `<h4 style="margin:12px 0 6px;color:#9ee7f7">ประวัติการลงทะเบียนตามกลุ่ม</h4>`;
    if (Array.isArray(js.enrollments) && js.enrollments.length){
      js.enrollments.forEach(en => {
        html += `
          <div class="attempt-row">
            <div class="attempt-head"><strong>${en.group_name || ('Group#'+(en.group_id||''))}</strong></div>
            <div style="margin-top:6px"><small>สถานะ: ${en.status || '—'} | เริ่ม: ${en.enrollment_date || '—'} | สิ้นสุด: ${en.end_date || '—'}</small></div>
          </div>
        `;
      });
    }else{
      html += `<div class="attempt-row"><small>— ไม่พบบันทึกการลงทะเบียน —</small></div>`;
    }

    html += `<div style="margin-top:10px"><button class="rec-btn" onclick="openHistory('${js.student.student_id}')"><i class="fas fa-clock"></i> ดูประวัติคำแนะนำ/แบบทดสอบ</button></div>`;

    mBody.innerHTML = html;

  }catch(err){
    document.getElementById('detail-body').innerHTML =
      `<div class="course-item"><small style="color:#fca5a5">เกิดข้อผิดพลาด: ${err.message}</small></div>`;
  }
}

async function loadQuizHistory(sid){
  try{
    const js  = await fetchJSON(`?ajax=quiz_detail&sid=${encodeURIComponent(sid)}`);
    const mBody = document.getElementById('detail-body');

    let html = `
      <div style="margin-bottom:10px;color:#9CA3AF">
        กลุ่มเรียนปัจจุบัน: ${js.student.group_name ? js.student.group_name : '—'}
      </div>
      <h4 style="margin:12px 0 6px;color:#9ee7f7">กลุ่มแนะนำล่าสุด & วิชา</h4>
    `;

    if (js.latest_recommendation && (js.latest_recommendation.recommend_group_label || js.latest_recommendation.recommend_group_id)) {
      const gLabel = js.latest_recommendation.recommend_group_label || js.latest_recommendation.recommend_group_id;
      const subs = Array.isArray(js.latest_recommendation.subjects) ? js.latest_recommendation.subjects : [];
      html += `
        <div class="attempt-row">
          <div class="attempt-head"><span class="chip">ล่าสุด</span><strong>${gLabel}</strong><span class="chip">${subs.length} วิชา</span></div>
          ${subs.length ? `<div style="margin-top:6px"><small>${subs.join(', ')}</small></div>` : ``}
        </div>
      `;
    } else {
      html += `<div class="course-item"><small>— ไม่มีคำแนะนำล่าสุด —</small></div>`;
    }

    html += `<h4 style="margin:12px 0 8px;color:#9ee7f7">ประวัติการทำแบบทดสอบทั้งหมด</h4>`;
    if (Array.isArray(js.attempts) && js.attempts.length){
      js.attempts.forEach(at => {
        const src = at.source === 'quiz_results' ? 'ระบบ' : 'บันทึกเดิม';
        const score = (at.score ?? '—');
        const gLabel = at.recommend_group_label || at.recommend_group_id || '—';
        const subs = (Array.isArray(at.subjects) && at.subjects.length) ? at.subjects.join(', ') : null;

        html += `
          <div class="attempt-row">
            <div class="attempt-head"><span class="chip">${src}</span><strong>${at.at || '—'}</strong></div>
            <div style="margin-top:6px">
              <small>คะแนน: ${score} | กลุ่มที่แนะนำ: ${gLabel}</small>
              ${subs ? `<div><small>วิชาที่แนะนำ: ${subs}</small></div>` : ``}
            </div>
          </div>
        `;
      });
    }else{
      html += `<div class="course-item"><small>— ไม่มีประวัติการทำแบบทดสอบ —</small></div>`;
    }

    mBody.innerHTML = html;

  }catch(err){
    document.getElementById('detail-body').innerHTML =
      `<div class="course-item"><small style="color:#fca5a5">เกิดข้อผิดพลาด: ${err.message}</small></div>`;
  }
}
</script>
</body>
</html>
