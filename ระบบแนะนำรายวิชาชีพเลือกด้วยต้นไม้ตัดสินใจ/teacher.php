<?php 
// teacher.php — Dashboard อาจารย์ + เปลี่ยนรหัสผ่าน + โปรไฟล์ + กลุ่ม/นักศึกษา + ผลกลุ่มจากแบบทดสอบ (แสดงทุกครั้งที่ทำ)
// + แสดงรายละเอียดรายวิชาในกลุ่ม (ปีที่ควรศึกษา/วิชาก่อน) แบบยืดหยุ่นคอลัมน์/สคีมา
session_start();

if (empty($_SESSION['loggedin']) || (($_SESSION['user_type'] ?? '') !== 'teacher')) {
    header('Location: index.php?error=unauthorized'); exit;
}
require_once 'db_connect.php'; // ต้องมี $conn (mysqli)

$DEBUG = !empty($_GET['debug']);

/* =========================================================
   Helpers
   ========================================================= */
function get_stmt_rows(mysqli_stmt $stmt): array {
    if (method_exists($stmt, 'get_result')) {
        $res = $stmt->get_result();
        if ($res instanceof mysqli_result) return $res->fetch_all(MYSQLI_ASSOC);
        return [];
    }
    $meta = $stmt->result_metadata(); if (!$meta) return [];
    $fields = []; $row = []; $bindArgs = [];
    while ($f = $meta->fetch_field()) { $fields[] = $f->name; $row[$f->name] = null; $bindArgs[] = &$row[$f->name]; }
    call_user_func_array([$stmt, 'bind_result'], $bindArgs);
    $out = []; while ($stmt->fetch()) { $copy=[]; foreach ($fields as $fn) $copy[$fn]=$row[$fn]; $out[]=$copy; }
    return $out;
}
function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function tableExists(mysqli $conn, string $name): bool {
    $sql="SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1";
    $st=$conn->prepare($sql); if(!$st) return false;
    $st->bind_param('s',$name); $st->execute(); $st->store_result(); $ok=$st->num_rows>0; $st->close(); return $ok;
}
function colExists(mysqli $conn, string $table, string $col): bool {
    if(!tableExists($conn,$table)) return false;
    $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
    $st=$conn->prepare($sql); if(!$st) return false;
    $st->bind_param('ss',$table,$col); $st->execute(); $st->store_result(); $ok=$st->num_rows>0; $st->close(); return $ok;
}
function pickCol(mysqli $conn, string $table, array $cands, ?string $fallback=null): ?string {
    foreach($cands as $c){ if(colExists($conn,$table,$c)) return $c; } return $fallback;
}
function norm_id(string $s): string { return str_replace([' ','-','_'],'',trim($s)); }
function mb_lc($s){ return function_exists('mb_strtolower')?mb_strtolower((string)$s,'UTF-8'):strtolower((string)$s); }

/* ===== CSRF ===== */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));

/* ===== หา teacher id (PK ของตาราง teacher คือคอลัมน์ id) ===== */
$teacherId = $_SESSION['teacher_id'] ?? null; // เราเก็บ id ไว้ใน session key นี้
if (!$teacherId) {
    $username = $_SESSION['username'] ?? null;
    if ($username) {
        $stmt = $conn->prepare("SELECT id FROM teacher WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s',$username); $stmt->execute();
            $rows = get_stmt_rows($stmt);
            if($rows){
                $teacherId=(int)$rows[0]['id'];
                $_SESSION['teacher_id']=$teacherId;
            }
            $stmt->close();
        } else { error_log("TID lookup failed: ".$conn->error); die('DB error (TID)'); }
    }
}
if (!$teacherId) { header('Location: index.php?error=teacher_id_missing'); exit; }

/* ===== โปรไฟล์อาจารย์ (อ่านค่าไว้โชว์/เติมในฟอร์ม) ===== */
$teacherProfile=['name'=>null,'username'=>null,'email'=>null,'phone'=>null];
$nameColsForSelect=[];
if (tableExists($conn,'teacher')) {
    $nameCols=[]; if(colExists($conn,'teacher','full_name'))$nameCols[]='full_name';
    if(colExists($conn,'teacher','teacher_name'))$nameCols[]='teacher_name';
    if(colExists($conn,'teacher','name'))$nameCols[]='name';
    $nameColsForSelect = $nameCols; // เก็บไว้ใช้ตอนอัปเดต
    $nameExpr = $nameCols?("COALESCE(".implode(', ',$nameCols).")"):"CAST(id AS CHAR)";
    $fields = "id, {$nameExpr} AS tname";
    if(colExists($conn,'teacher','username')) $fields.=", username";
    if(colExists($conn,'teacher','email'))    $fields.=", email";
    if(colExists($conn,'teacher','phone'))    $fields.=", phone";
    $st=$conn->prepare("SELECT {$fields} FROM teacher WHERE id=? LIMIT 1");
    if($st){
        $st->bind_param('i',$teacherId); $st->execute(); $rows=get_stmt_rows($st);
        if($rows){
            $r=$rows[0];
            $teacherProfile['name']=$r['tname']??$teacherId;
            $teacherProfile['username']=$r['username']??($_SESSION['username']??null);
            $teacherProfile['email']=$r['email']??null;
            $teacherProfile['phone']=$r['phone']??null;
        }
        $st->close();
    }
}

/* ===== เปลี่ยนรหัสผ่าน ===== */
$pw_error=''; $pw_success='';
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='change_password'){
    if(!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')){
        $pw_error='CSRF ไม่ถูกต้อง กรุณาลองใหม่';
    }else{
        $current=trim((string)($_POST['current_password']??'')); 
        $new=trim((string)($_POST['new_password']??'')); 
        $confirm=trim((string)($_POST['confirm_password']??''));
        if($current===''||$new===''||$confirm===''){ $pw_error='กรุณากรอกข้อมูลให้ครบทุกช่อง';
        }elseif($new!==$confirm){ $pw_error='รหัสผ่านใหม่และยืนยันไม่ตรงกัน';
        }elseif(mb_strlen($new)<8){ $pw_error='รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร';
        }else{
            $tid=(int)$teacherId;
            $stmt=$conn->prepare("SELECT COALESCE(password_hash,'') AS password_hash, COALESCE(teacher_password,'') AS teacher_password, COALESCE(password,'') AS legacy_plain FROM teacher WHERE id=? LIMIT 1");
            if($stmt){
                $stmt->bind_param('i',$tid); $stmt->execute(); $rows=get_stmt_rows($stmt); $row=$rows?$rows[0]:null; $stmt->close();
                if(!$row){ $pw_error='ไม่พบข้อมูลอาจารย์';
                }else{
                    $match=false;
                    if(!$match && $row['password_hash']!=='' && password_get_info($row['password_hash'])['algo']) $match=password_verify($current,$row['password_hash']);
                    if(!$match && $row['teacher_password']!=='' && password_get_info($row['teacher_password'])['algo']) $match=password_verify($current,$row['teacher_password']);
                    if(!$match && $row['legacy_plain']!==''){ if($current===$row['legacy_plain'] || hash('sha256',$current)===$row['legacy_plain']) $match=true; }
                    if(!$match){ $pw_error='รหัสผ่านปัจจุบันไม่ถูกต้อง';
                    }else{
                        $newHash=password_hash($new,PASSWORD_DEFAULT);
                        $up=$conn->prepare("UPDATE teacher SET password_hash=?, password=NULL, teacher_password=NULL WHERE id=?");
                        if($up){
                            $up->bind_param('si',$newHash,$tid);
                            if($up->execute()) $pw_success='เปลี่ยนรหัสผ่านเรียบร้อย';
                            else { $pw_error='อัปเดตล้มเหลว: '.$up->error; error_log($up->error); }
                            $up->close();
                        }else{ $pw_error='เตรียมคำสั่งอัปเดตล้มเหลว: '.$conn->error; }
                    }
                }
            }else{ $pw_error='เตรียมคำสั่งค้นหารหัสผ่านล้มเหลว: '.$conn->error; }
        }
    }
}

/* ===== อัปเดตโปรไฟล์อาจารย์ ===== */
$pf_error=''; $pf_success='';
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='update_profile'){
    if(!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')){
        $pf_error='CSRF ไม่ถูกต้อง กรุณาลองใหม่';
    }else{
        $name  = trim((string)($_POST['pf_name']  ?? ''));
        $email = trim((string)($_POST['pf_email'] ?? ''));
        $phone = trim((string)($_POST['pf_phone'] ?? ''));
        // ตรวจสอบความยาวเบื้องต้น
        if(mb_strlen($name)>150 || mb_strlen($email)>150 || mb_strlen($phone)>50){
            $pf_error='ข้อมูลบางช่องยาวเกินกำหนด';
        }else{
            // เตรียมอัปเดตแบบยืดหยุ่น
            $sets=[]; $types=''; $vals=[];
            // เลือกคอลัมน์ชื่อแรกที่มีจริง
            $nameCol = pickCol($conn,'teacher', ['full_name','teacher_name','name'], null);
            if($nameCol!==null && $name!==''){ $sets[]="`$nameCol`=?"; $types.='s'; $vals[]=$name; }
            if(colExists($conn,'teacher','email')){ $sets[]="`email`=?"; $types.='s'; $vals[]=$email; }
            if(colExists($conn,'teacher','phone')){ $sets[]="`phone`=?"; $types.='s'; $vals[]=$phone; }
            if($sets){
                $sql="UPDATE teacher SET ".implode(', ',$sets)." WHERE id=?";
                if($st=$conn->prepare($sql)){
                    $types.='i'; $vals[]=$teacherId;
                    $st->bind_param($types, ...$vals);
                    if($st->execute()){
                        $pf_success='บันทึกโปรไฟล์เรียบร้อย';
                        // รีเฟรชค่าในตัวแปรแสดงผล
                        $teacherProfile['name']  = $name ?: $teacherProfile['name'];
                        $teacherProfile['email'] = $email;
                        $teacherProfile['phone'] = $phone;
                    }else{
                        $pf_error='อัปเดตโปรไฟล์ล้มเหลว: '.$st->error;
                    }
                    $st->close();
                }else{
                    $pf_error='เตรียมคำสั่งอัปเดตโปรไฟล์ล้มเหลว: '.$conn->error;
                }
            }else{
                $pf_error='ไม่พบคอลัมน์สำหรับอัปเดตในตาราง teacher';
            }
        }
    }
}

/* ===== กลุ่ม/นักศึกษา ===== */
$hasEducationInfo  = tableExists($conn,'education_info');

$groups=[];
$groupSelectFields="g.group_id, g.group_name, g.course_id";
$groupSelectFields .= colExists($conn,'course_groups','curriculum_value') ? ", g.curriculum_value" : ", NULL AS curriculum_value";
$sql="SELECT {$groupSelectFields}, COALESCE(c.course_name, CONCAT('Course#', g.course_id)) AS course_name
      FROM course_groups g LEFT JOIN courses c ON c.course_id=g.course_id
      WHERE g.teacher_id=? 
      ORDER BY ".(colExists($conn,'course_groups','created_at')?'g.created_at DESC':'g.group_id DESC');
if($st=$conn->prepare($sql)){ $st->bind_param('i',$teacherId); $st->execute(); $rows=get_stmt_rows($st); foreach($rows as $r)$groups[]=$r; $st->close(); }

$selectedGroupId = isset($_GET['group_id']) ? trim((string)$_GET['group_id']) : null;
$students=[]; $studentEdu=[]; $sourceNote=''; $groupInfo=null;

/* ====== โหลดแค็ตตาล็อก “วิชา” ต่อกลุ่ม จากตาราง courses (แทน subjects) ====== */
$subjectsByGroup = [];   // group_id => list of subjects-like items
$subjectIndexes  = [];   // group_id => ['by_name' => map, 'by_code' => map]

if (tableExists($conn,'courses')) {
    $cNameCol = pickCol($conn,'courses',['course_name'],'course_name');
    $cCodeCol = pickCol($conn,'courses',['course_code','code'],'course_code');
    $cYearCol = pickCol($conn,'courses',['recommended_year','year','study_year'],'recommended_year');
    $cPreCol  = pickCol($conn,'courses',['prereq_text','prerequisite','pre_req'],'prereq_text');
    $cGrpCol  = pickCol($conn,'courses',['group_id'],null);
    $cCurCol  = pickCol($conn,'courses',['curriculum_name_value','curriculum_name'],'curriculum_name_value');

    foreach ($groups as $g) {
        $gid = (string)$g['group_id'];
        $gcur  = (string)($g['curriculum_value'] ?? '');

        $fields = "`$cNameCol` AS sname, `$cCodeCol` AS scode, `$cYearCol` AS syear, `$cPreCol` AS spre";
        $sqlS   = "SELECT {$fields} FROM courses";
        $where  = [];
        $types  = '';
        $binds  = [];

        if ($cGrpCol) { $where[] = "`$cGrpCol` = ?"; $types.='s'; $binds[]=$gid; }
        if (!$where && $gcur !== '' && $cCurCol) { $where[] = "TRIM(`$cCurCol`) = TRIM(?)"; $types.='s'; $binds[]=$gcur; }
        if ($where) $sqlS .= " WHERE ".implode(' AND ',$where);
        $sqlS .= " ORDER BY sname";

        $st = $conn->prepare($sqlS);
        if($st){
            if($types!=='') $st->bind_param($types, ...$binds);
            $st->execute();
            $rows = get_stmt_rows($st);
            $st->close();

            $list = [];
            $byName=[]; $byCode=[];
            foreach ($rows as $r) {
                $name = trim((string)($r['sname'] ?? ''));
                $code = trim((string)($r['scode'] ?? ''));
                $year = trim((string)($r['syear'] ?? ''));
                $pre  = trim((string)($r['spre']  ?? ''));
                if ($name === '' && $code === '') continue;
                $item = ['name'=>$name,'code'=>$code,'year'=>$year,'prereq'=>$pre];
                $list[] = $item;
                if ($name !== '') $byName[mb_lc($name)] = $item;
                if ($code !== '') $byCode[mb_lc($code)] = $item;
            }
            if (!$list) { // fallback: ดึงทั้งหมด
                $sqlAll = "SELECT {$fields} FROM courses ORDER BY sname";
                if($st=$conn->prepare($sqlAll)){
                    $st->execute(); $rows=get_stmt_rows($st); $st->close();
                    foreach ($rows as $r) {
                        $name = trim((string)($r['sname'] ?? ''));
                        $code = trim((string)($r['scode'] ?? ''));
                        $year = trim((string)($r['syear'] ?? ''));
                        $pre  = trim((string)($r['spre']  ?? ''));
                        if ($name === '' && $code === '') continue;
                        $item = ['name'=>$name,'code'=>$code,'year'=>$year,'prereq'=>$pre];
                        $list[] = $item;
                        if ($name !== '') $byName[mb_lc($name)] = $item;
                        if ($code !== '') $byCode[mb_lc($code)] = $item;
                    }
                }
            }
            $subjectsByGroup[$gid] = $list;
            $subjectIndexes[$gid]  = ['by_name'=>$byName,'by_code'=>$byCode];
        }
    }
}

/* ===== Global course index (fallback สำหรับแมตช์วิชาที่อยู่นอกกลุ่ม) ===== */
$GLOBAL_BY_NAME = []; $GLOBAL_BY_CODE = [];
$GLOBAL_BY_NAME_N = []; $GLOBAL_BY_CODE_N = [];
if (tableExists($conn,'courses')) {
    $st = $conn->prepare("
        SELECT course_name AS sname, course_code AS scode, 
               recommended_year AS syear, prereq_text AS spre
        FROM courses
    ");
    if ($st) {
        $st->execute();
        $rows = get_stmt_rows($st);
        $st->close();
        $normGlobal = function($s){
            $s = function_exists('mb_strtolower') ? mb_strtolower((string)$s,'UTF-8') : strtolower((string)$s);
            return preg_replace('/[\s\-\(\)\[\]\.\/\\\\]+/u','',$s);
        };
        foreach ($rows as $r){
            $name = trim((string)($r['sname'] ?? ''));
            $code = trim((string)($r['scode'] ?? ''));
            $item = [
                'name'   => $name,
                'code'   => $code,
                'year'   => trim((string)($r['syear'] ?? '')),
                'prereq' => trim((string)($r['spre']  ?? '')),
            ];
            if ($name !== '') {
                $GLOBAL_BY_NAME[mb_lc($name)] = $item;
                $GLOBAL_BY_NAME_N[$normGlobal($name)] = $item;
            }
            if ($code !== '') {
                $GLOBAL_BY_CODE[mb_lc($code)] = $item;
                $GLOBAL_BY_CODE_N[$normGlobal($code)] = $item;
            }
        }
    }
}

if($selectedGroupId){
    // ข้อมูลกลุ่ม
    $groupInfoSelectFields="group_id, group_name, course_id";
    $groupInfoSelectFields .= colExists($conn,'course_groups','curriculum_value') ? ", curriculum_value" : ", NULL AS curriculum_value";
    if($st=$conn->prepare("SELECT {$groupInfoSelectFields} FROM course_groups WHERE group_id=? LIMIT 1")){
        $st->bind_param('s',$selectedGroupId); $st->execute(); $rows=get_stmt_rows($st); $groupInfo=$rows?$rows[0]:null; $st->close();
    }

    // ===== ดึงรายชื่อจาก education_info
    if ($groupInfo && $hasEducationInfo && colExists($conn,'education_info','student_group')) {
        $grp = trim((string)$groupInfo['group_name']);
        $cur = (string)($groupInfo['curriculum_value'] ?? '');

        if ($cur !== '' && colExists($conn,'education_info','curriculum_name')) {
            $sql = "
                SELECT 
                    ei.student_id,
                    CAST(ei.student_id AS CHAR) AS student_code,
                    COALESCE(NULLIF(TRIM(p.full_name),''), CAST(ei.student_id AS CHAR)) AS student_name,
                    COALESCE(p.email,'') AS email,
                    COALESCE(ei.student_group,'')   AS student_group,
                    COALESCE(ei.curriculum_name,'') AS curriculum_name
                FROM education_info ei
                LEFT JOIN personal_info p ON p.id = ei.personal_id
                LEFT JOIN form_options f
                       ON f.type='curriculum_name'
                      AND f.id = CASE 
                                  WHEN ei.curriculum_name REGEXP '^[0-9]+$' 
                                    THEN CAST(ei.curriculum_name AS UNSIGNED)
                                  ELSE NULL
                                 END
                WHERE TRIM(ei.student_group) = TRIM(?)
                  AND (ei.curriculum_name = ? OR f.label = ?)
                ORDER BY student_name
            ";
            $st = $conn->prepare($sql);
            $st->bind_param('sss', $grp, $cur, $cur);
        } else {
            $sql = "
                SELECT 
                    ei.student_id,
                    CAST(ei.student_id AS CHAR) AS student_code,
                    COALESCE(NULLIF(TRIM(p.full_name),''), CAST(ei.student_id AS CHAR)) AS student_name,
                    COALESCE(p.email,'') AS email,
                    COALESCE(ei.student_group,'')   AS student_group,
                    COALESCE(ei.curriculum_name,'') AS curriculum_name
                FROM education_info ei
                LEFT JOIN personal_info p ON p.id = ei.personal_id
                WHERE TRIM(ei.student_group) = TRIM(?)
                ORDER BY student_name
            ";
            $st = $conn->prepare($sql);
            $st->bind_param('s', $grp);
        }

        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) {
            $students[] = [
                'student_id'   => (string)$r['student_id'],
                'student_code' => (string)$r['student_code'],
                'student_name' => (string)$r['student_name'],
                'email'        => (string)$r['email'],
            ];
            $studentEdu[(string)$r['student_id']] = [
                'student_group'   => (string)$r['student_group'],
                'curriculum_name' => (string)$r['curriculum_name'],
            ];
        }
        $st->close();

        $sourceNote = 'education_info';
    }

    // ===== เติมข้อมูลการศึกษา “ล่าสุด” + คณะ/สาขาแบบยืดหยุ่น =====
    if($students && $hasEducationInfo){
        $sids=array_column($students,'student_id');
        $ph=implode(',',array_fill(0,count($sids),'?'));
        $types=str_repeat('s',count($sids));

        $latestCol = colExists($conn,'education_info','updated_at') ? 'updated_at'
                   : (colExists($conn,'education_info','id') ? 'id' : null);

        $eiFacultyCol = pickCol($conn,'education_info', ['faculty_name','faculty','คณะ','faculty_th']);
        $eiDeptCol    = pickCol($conn,'education_info', ['department','dept','ภาควิชา','หน่วยงาน','สาขา','major_department']);
        $eiMajorCol   = pickCol($conn,'education_info', ['program','program_name','field','สาขาวิชา','major']);

        $facSel = $eiFacultyCol ? "ei.`{$eiFacultyCol}` AS fac" : "NULL AS fac";
        $depSel = $eiDeptCol    ? "ei.`{$eiDeptCol}`    AS dep" : "NULL AS dep";
        $majSel = $eiMajorCol   ? "ei.`{$eiMajorCol}`   AS maj" : "NULL AS maj";

        if($latestCol){
            $sql = "
                SELECT ei.student_id, ei.student_group, ei.curriculum_name, {$facSel}, {$depSel}, {$majSel}
                FROM education_info ei
                INNER JOIN (
                    SELECT student_id, MAX({$latestCol}) m
                    FROM education_info
                    WHERE student_id IN ($ph)
                    GROUP BY student_id
                ) t ON t.student_id=ei.student_id AND t.m=ei.{$latestCol}
            ";
            if($st=$conn->prepare($sql)){
                $st->bind_param($types,...$sids);
                $st->execute(); 
                $rows=get_stmt_rows($st); 
                foreach($rows as $x){
                    $sid=(string)$x['student_id'];
                    if(!isset($studentEdu[$sid])) $studentEdu[$sid]=[];
                    $studentEdu[$sid]['student_group']   = $x['student_group'] ?? ($studentEdu[$sid]['student_group'] ?? '');
                    $studentEdu[$sid]['curriculum_name'] = $x['curriculum_name'] ?? ($studentEdu[$sid]['curriculum_name'] ?? '');
                    $studentEdu[$sid]['faculty']         = trim((string)($x['fac'] ?? ''));
                    $studentEdu[$sid]['department']      = trim((string)($x['dep'] ?? ''));
                    $studentEdu[$sid]['major']           = trim((string)($x['maj'] ?? ''));
                }
                $st->close();
            }
        }
    }

    /* =========== ผลจาก test_history (แสดงทุกครั้งที่ทำ) =========== 
         schema: username, timestamp, recommended_group, recommended_subjects
    */
    $studentAttempts = []; // sid => [ {group_name, time, courses[]}, ... ]
    $idsRaw=[]; $idsNorm=[];
    foreach($students as $row){
        foreach(['student_id','student_code','email'] as $k){
            if(!empty($row[$k])){ $v=(string)$row[$k]; $idsRaw[]=$v; $idsNorm[]=norm_id($v); }
        }
    }
    $idsRaw=array_values(array_unique(array_map('strval',$idsRaw)));
    $idsNorm=array_values(array_unique(array_map('strval',$idsNorm)));

    if(tableExists($conn,'test_history') && ($idsRaw || $idsNorm)){
        $phR=implode(',',array_fill(0,count($idsRaw),'?'));  $tR=str_repeat('s',count($idsRaw));
        $phN=implode(',',array_fill(0,count($idsNorm),'?')); $tN=str_repeat('s',count($idsNorm));

        $condR = $idsRaw? "CAST(h.username AS CHAR) IN ($phR)" : "0";
        $condN = $idsNorm? "REPLACE(REPLACE(REPLACE(CAST(h.username AS CHAR),' ','') ,'-',''),'_','') IN ($phN)" : "0";

        $sql="SELECT CAST(h.username AS CHAR) sid, h.recommended_group gname, h.recommended_subjects subjects, h.`timestamp` tstamp
              FROM test_history h
              WHERE ($condR) OR ($condN)
              ORDER BY h.`timestamp` DESC";

        if($st=$conn->prepare($sql)){
            $bindT=$tR.$tN; $bindV=array_merge($idsRaw,$idsNorm);
            if($bindT!=='') $st->bind_param($bindT,...$bindV);
            $st->execute(); 
            $rows=get_stmt_rows($st); 
            $st->close();

            $parseSubjects=function($val){
                if($val===null) return [];
                $s=trim((string)$val);
                if($s==='') return [];
                if($s[0]=='['||$s[0]=='{'){
                    $j=json_decode($s,true);
                    if(is_array($j)){
                        $o=[];
                        foreach($j as $it){
                            if(is_string($it)) $o[]=trim($it);
                            elseif(is_array($it)){
                                foreach(['name','title','subject','course_name','code'] as $k){
                                    if(!empty($it[$k])){ $o[]=trim((string)$it[$k]); break; }
                                }
                            }
                        }
                        return array_values(array_filter(array_unique($o),fn($x)=>$x!==''));
                    }
                }
                $parts=preg_split('/[,;\r\n]+/u',$s);
                $parts=array_map('trim',$parts);
                return array_values(array_filter(array_unique($parts),fn($x)=>$x!==''));
            };

            foreach($rows as $r){
                $sidRaw  = (string)$r['sid'];
                $sidNorm = norm_id($sidRaw);

                $attempt = [
                    'group_name' => (trim((string)$r['gname'])!=='')? trim((string)$r['gname']) : null,
                    'time'       => $r['tstamp'],
                    'courses'    => $parseSubjects($r['subjects']),
                ];

                if(!isset($studentAttempts[$sidRaw]))  $studentAttempts[$sidRaw]=[];
                if(!isset($studentAttempts[$sidNorm])) $studentAttempts[$sidNorm]=[];

                $studentAttempts[$sidRaw][]  = $attempt;
                if($sidNorm !== $sidRaw) $studentAttempts[$sidNorm][] = $attempt;
            }

            $sourceNote .= ($sourceNote? ' | ' : '').'outcome=test_history(all)';
        }
    }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard อาจารย์</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{
  --bg: #f5f7fa; --panel: #fff; --ink: #1f2937; --muted: #6b7280; --line: #e5e7eb; --brand: #3b82f6; --brand-dark: #2563eb;
  --ok: #22c55e; --warn: #f97316; --radius: 12px; --shadow: 0 4px 14px rgba(0,0,0,.05);
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); font-family: 'Sarabun', system-ui, Arial, sans-serif; color: var(--ink); line-height: 1.6; font-size: 15px; }
.container { max-width: 1400px; margin: 0 auto; padding: 20px 16px; }

/* General Styles */
.card { background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; margin-bottom: 16px; }
.card-header { padding: 16px 20px; border-bottom: 1px solid var(--line); background: #fafbfc; }
.card-header h3 { margin: 0; font-size: 1.2rem; display: flex; align-items: center; gap: 12px; color: #2d3b4d; }
.card-body { padding: 20px; }
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border-radius: 8px; padding: 10px 16px; font-size: 14px; border: 1px solid var(--line); background: #fff; cursor: pointer; transition: all .2s; text-decoration: none; color: var(--ink); }
.btn:hover { background: #fee2e2; border-color: #fca5a5; }
.btn1 { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border-radius: 8px; padding: 10px 16px; font-size: 14px; border: 1px solid var(--line); background: #fff; cursor: pointer; transition: all .2s; text-decoration: none; color: var(--ink); }
.btn1:hover { background: #e0f2fe; border-color: #bfdbfe; }
.btn-link { background: transparent; border: none; color: var(--brand); cursor: pointer; text-decoration: underline; padding: 0; font-size: 14px; }
.btn-primary { background: linear-gradient(180deg, var(--brand), var(--brand-dark)); color: #fff; border: 1px solid var(--brand-dark); }
.btn-primary:hover { background: linear-gradient(180deg, var(--brand-dark), #1d4ed8); border-color: #1d4ed8; }

.code { font-family: ui-monospace, Menlo, Consolas, monospace; background: #f1f5f9; padding: 2px 6px; border-radius: 6px; font-size: 13px; color: #334155; }
.alert { padding: 12px 20px; margin-bottom: 16px; border-radius: 8px; border: 1px solid; display: flex; align-items: center; gap: 12px; }
.alert-success { background: #ecfdf5; border-color: #d1fae5; color: #065f46; }
.alert-error { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
.small { font-size: 0.9em; color: var(--muted); }
ul.clean { list-style-type: none; margin: 0; padding-left: 1rem; }
ul.clean li::before { content: "\2022"; color: #3b82f6; font-weight: bold; display: inline-block; width: 1em; margin-left: -1em; }
.tag { display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--line); border-radius: 999px; font-size: 12px; padding: 4px 10px; background: #fff; }
.tag.blue { background: #eff6ff; border-color: #dbeafe; color: #1e40af; }

/* Header */
.header { background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); padding: 20px; margin-bottom: 20px; display: flex; flex-direction: column; gap: 16px; }
@media (min-width: 768px) {
  .header { flex-direction: row; justify-content: space-between; align-items: center; }
}
.header-title { display: flex; gap: 12px; align-items: center; }
.header-title i { font-size: 32px; color: #3b82f6; background: #e0f2fe; border-radius: 12px; padding: 12px; }
.profile { display: flex; gap: 12px; align-items: center; border: 1px solid var(--line); border-radius: 999px; padding: 8px 16px; background: #fbfcff; font-size: 14px; }
.profile i { color: var(--brand); }
.profile-info { display: flex; flex-direction: column; }
.profile-sub { font-size: 13px; color: var(--muted); }

/* Main Grid */
.main-grid { display: grid; gap: 20px; grid-template-columns: 1fr; }
@media (min-width: 1024px) { .main-grid { grid-template-columns: 380px 1fr; } }

/* Group List */
.group-list { list-style: none; margin: 0; padding: 0; }
.group-item { border: 1px solid var(--line); border-radius: 10px; padding: 16px; margin-bottom: 12px; background: #fff; display: flex; flex-direction: column; gap: 10px; }
.group-item h4 { margin: 0; font-size: 1.1rem; color: #2d3b4d; }
.group-meta { display: flex; flex-direction: column; gap: 6px; font-size: 14px; color: #2d3b4d; }
.group-meta i { width: 18px; text-align: center; color: var(--ink); }
.group-courses { display:none; padding:10px; border:1px dashed #e2e8f0; border-radius:8px; background:#fafcff; }

/* Student List */
.student-toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.search-box { position: relative; flex: 1; min-width: 220px; }
.search-box input { width: 100%; padding: 12px 40px; border: 1px solid var(--line); border-radius: 8px; outline: none; background: #fff; transition: border-color .2s; font-size: 15px; }
.search-box input:focus { border-color: var(--brand); }
.search-box i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #6b7280; }
.counter { font-size: 14px; color: var(--muted); }

.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border: 1px solid var(--line); border-radius: 10px; }
table { width: 100%; border-collapse: collapse; }
thead th { position: sticky; top: 0; background: #f9fafb; border-bottom: 1px solid var(--line); font-weight: 600; font-size: 14px; text-align: left; padding: 12px 16px; color: #6b7280; white-space: nowrap; }
tbody td { padding: 12px 16px; vertical-align: top; font-size: 14.5px; border-top: 1px solid #f3f4f6; }
.master-row { cursor: pointer; transition: background-color .2s; }
.master-row:hover { background-color: #f9fafb; }
.master-row.open { background-color: #f2f5f8; }
.row-link { display: flex; align-items: center; gap: 10px; text-decoration: none; color: var(--ink); }
.chev { transition: transform .2s; }
.master-row.open .chev { transform: rotate(90deg); }
.detail-row { background: #f9fafb; border-bottom: 1px solid var(--line); }
.detail-box { padding: 16px; border: 1px dashed #e2e8f0; border-radius: 8px; background: #fff; }

@media (max-width: 680px){
  thead th.col-email, td.col-email { display:none; }
  thead th.col-fac,   td.col-fac   { min-width:120px; }
  thead th.col-major, td.col-major { min-width:150px; }
}

/* Modal */
.modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,.4); display: none; justify-content: center; align-items: center; z-index: 1000; opacity: 0; transition: opacity .2s ease-in-out; }
.modal.show { display: flex; opacity: 1; }
.modal-content { background: var(--panel); border-radius: var(--radius); max-width: 500px; width: 90%; transform: translateY(-20px); transition: transform .2s ease-in-out; }
.modal.show .modal-content { transform: translateY(0); }
.modal-header { padding: 16px 20px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; }
.modal-header h3 { margin: 0; font-size: 1.1rem; }
.modal-close { background: none; border: none; font-size: 1.5rem; color: #6b7280; cursor: pointer; }
.modal-body { padding: 20px; }
.modal-footer { padding: 16px 20px; border-top: 1px solid var(--line); display: flex; justify-content: flex-end; gap: 10px; }
.form-input { width: 100%; padding: 10px; border: 1px solid var(--line); border-radius: 6px; font-size: 15px; margin-top: 4px; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <i class="fas fa-chalkboard-teacher"></i>
            <div>
                <h2 style="margin:0; font-size:1.6rem; font-weight:600">ระบบแนะนำรายวิชาชีพเลือก</h2>
                <div class="small">หน้าหลักอาจารย์</div>
            </div>
        </div>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <div class="profile">
                <i class="fas fa-user-tie"></i>
                <div class="profile-info">
                    <div style="font-weight:600"><?=h($teacherProfile['name'] ?: 'อาจารย์')?></div>
                    <div class="profile-sub">
                        <?php if ($teacherProfile['username']): ?>@<?=h($teacherProfile['username'])?><?php endif; ?>
                        <?php if ($teacherProfile['email']): ?> · <?=h($teacherProfile['email'])?><?php endif; ?>
                        <?php if ($teacherProfile['phone']): ?> · <?=h($teacherProfile['phone'])?><?php endif; ?>
                    </div>
                </div>
            </div>
            <button class="btn1" onclick="openModal('pfModal')"><i class="fas fa-id-card"></i> แก้ไขโปรไฟล์</button>
            <button class="btn1" onclick="openModal('pwModal')"><i class="fas fa-key"></i> เปลี่ยนรหัสผ่าน</button>
            <a class="btn" href="logout.php"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
        </div>
    </div>

    <?php if ($pw_success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?=h($pw_success)?></div>
    <?php elseif ($pw_error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?=h($pw_error)?></div>
    <?php endif; ?>

    <?php if ($pf_success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?=h($pf_success)?></div>
    <?php elseif ($pf_error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?=h($pf_error)?></div>
    <?php endif; ?>

    <div class="main-grid">
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-users"></i> กลุ่มเรียนที่รับผิดชอบ</h3></div>
            <div class="card-body">
                <?php if (!$groups): ?>
                    <div class="small">ยังไม่มีกลุ่มเรียน</div>
                <?php else: ?>
                    <ul class="group-list">
                        <?php foreach($groups as $g): 
                            $gid=(string)$g['group_id'];
                            $subList = $subjectsByGroup[$gid] ?? [];
                        ?>
                            <li class="group-item" id="g-<?=h($gid)?>">
                                <h4><?=h($g['group_name'])?></h4>
                                <div class="group-meta">
                                    <?php if(!empty($g['curriculum_value'])): ?>
                                      <div><i class="fas fa-clipboard-list"></i> หลักสูตร: <span class="code"><?=h($g['curriculum_value'])?></span></div>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
                                    <a class="btn btn-primary" href="?group_id=<?=urlencode($g['group_id'])?><?php if($DEBUG) echo '&debug=1'; ?>"><i class="fas fa-eye"></i> เปิดรายชื่อ</a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3><i class="fas fa-user-graduate"></i>รายชื่อนักศึกษา</h3></div>
            <div class="card-body">
                <?php if (!$selectedGroupId): ?>
                    <div class="small">เลือกกลุ่มจากด้านซ้ายก่อน</div>
                <?php else: 
                    $subIdx = $subjectIndexes[(string)$selectedGroupId] ?? ['by_name'=>[],'by_code'=>[]];
                    $byName = $subIdx['by_name']; $byCode = $subIdx['by_code'];
                ?>
                    <div class="student-toolbar">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input id="q" type="text" placeholder="ค้นหา">
                        </div>
                        <div class="counter" id="cnt"></div>
                    </div>

                    <?php if (!$students): ?>
                        <div class="small">ไม่พบนักศึกษาในกลุ่มนี้</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table id="list">
                                <thead>
                                    <tr>
                                        <th style="width:12%">รหัส นศ.</th>
                                        <th style="width:22%">ชื่อ-นามสกุล</th>
                                        <th class="col-fac"   style="width:18%">คณะ</th>
                                        <th class="col-major" style="width:20%">สาขาวิชา</th>
                                        <th style="width:12%">กลุ่ม</th>
                                        <th class="col-email" style="width:16%">อีเมล</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach($students as $st):
                                        $sid=(string)$st['student_id'];
                                        $edu = $studentEdu[$sid] ?? [];
                                        $fac = $edu['faculty'] ?? '';
                                        $maj = $edu['major'] ?? '';
                                        $dep = $edu['department'] ?? '';
                                        $maj_or_dep = $maj !== '' ? $maj : $dep;
                                ?>
                                    <tr class="master-row" data-sid="<?=h($sid)?>">
                                        <td><span class="code"><?=h($sid)?></span></td>
                                        <td>
                                            <a class="row-link" href="javascript:void(0)">
                                                <span class="chev"><i class="fas fa-caret-right"></i></span>
                                                <span class="name" style="font-weight:600"><?=h($st['student_name'] ?? $sid)?></span>
                                            </a>
                                        </td>
                                        <td class="col-fac"><?= $fac!=='' ? h($fac) : '<span class="small">-</span>' ?></td>
                                        <td class="col-major"><?= $maj_or_dep!=='' ? h($maj_or_dep) : '<span class="small">-</span>' ?></td>
                                        <td>
                                            <?php if(!empty($edu['student_group'])): ?>
                                                <span class="code"><?=h($edu['student_group'])?></span>
                                                <?php if(!empty($edu['curriculum_name'])): ?>
                                                    <div class="small" style="margin-top:2px"><i class="fas fa-clipboard-list"></i> <?=h($edu['curriculum_name'])?></div>
                                                <?php endif; ?>
                                            <?php else: ?><span class="small">-</span><?php endif; ?>
                                        </td>
                                        <td class="col-email small"><?=h($st['email'] ?? '')?></td>
                                    </tr>

                                    <tr class="detail-row" style="display:none">
                                        <td colspan="6">
                                            <div class="detail-box">
                                                <?php
                                                    $attempts = $studentAttempts[$sid] ?? $studentAttempts[norm_id($sid)] ?? [];
                                                ?>
                                                <?php if(!empty($attempts)): ?>
                                                    <div class="small" style="margin-bottom:8px">
                                                        <i class="fas fa-history"></i> ประวัติการทำแบบทดสอบทั้งหมด: <b><?=count($attempts)?></b> ครั้ง
                                                    </div>

                                                    <ul class="clean" style="margin-left:.25rem">
                                                        <?php 
                                                        // --- Helpers สำหรับการแมตช์ ---
                                                        $norm = function($s){
                                                            $s = mb_lc($s);
                                                            return preg_replace('/[\s\-\(\)\[\]\.\/\\\\]+/u','',$s);
                                                        };
                                                        // ดัชนี normalized ภายในกลุ่ม (สร้างหนึ่งครั้ง)
                                                        static $byNameN=null,$byCodeN=null;
                                                        if($byNameN===null){
                                                            $byNameN = [];
                                                            foreach($byName as $kk=>$val){ $byNameN[$norm($kk)] = $val; }
                                                            $byCodeN = [];
                                                            foreach($byCode as $kk=>$val){ $byCodeN[$norm($kk)] = $val; }
                                                        }
                                                        // ฟังก์ชันเดา course_code จากสตริง เช่น "20-406-035-210 - ชื่อวิชา"
                                                        $guessCode = function($s){
                                                            if (preg_match('/\b([0-9]{2}-[0-9]{3}-[0-9]{3}-[0-9]{3})\b/u', $s, $m)) return $m[1];
                                                            if (preg_match('/\b([0-9]{11,12})\b/u', $s, $m)) return $m[1]; // กรณีตัวเลขยาวไม่มีขีด
                                                            return null;
                                                        };
                                                        ?>
                                                        <?php foreach($attempts as $idx => $att): ?>
                                                            <li style="margin-bottom:10px">
                                                                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:6px">
                                                                    <?php if(!empty($att['group_name'])): ?>
                                                                        <span class="tag blue"><i class="fas fa-layer-group"></i> กลุ่มที่ได้: <?=h($att['group_name'])?></span>
                                                                    <?php else: ?>
                                                                        <span class="tag"><i class="fas fa-layer-group"></i> กลุ่มที่ได้: -</span>
                                                                    <?php endif; ?>
                                                                    <?php if(!empty($att['time'])): ?>
                                                                        <span class="small"><i class="fas fa-calendar-alt"></i> เวลา: <?=h($att['time'])?></span>
                                                                    <?php endif; ?>
                                                                    <span class="small" style="opacity:.8">#<?=($idx+1)?></span>
                                                                </div>
                                                                <?php if(!empty($att['courses'])): ?>
                                                                    <div class="small" style="margin-bottom:4px; font-weight:600"><i class="fas fa-book"></i> รายวิชาที่แนะนำ:</div>
                                                                    <ul class="clean" style="margin-left:1rem">
                                                                        <?php foreach($att['courses'] as $cn): 
                                                                            $cn_lc = mb_lc($cn);
                                                                            $keyN  = $norm($cn);
                                                                            $code  = $guessCode($cn);

                                                                            // 1) หาในกลุ่มก่อน (by code → by name → normalized)
                                                                            $info = null;
                                                                            if ($code) {
                                                                                $code_lc = mb_lc($code);
                                                                                $code_n  = $norm($code);
                                                                                $info = $byCode[$code_lc] 
                                                                                     ?? $byCodeN[$code_n] 
                                                                                     ?? null;
                                                                            }
                                                                            if (!$info) {
                                                                                $info = $byName[$cn_lc] 
                                                                                     ?? $byNameN[$keyN] 
                                                                                     ?? $byCode[$cn_lc] 
                                                                                     ?? $byCodeN[$keyN] 
                                                                                     ?? null;
                                                                            }
                                                                            // 2) ถ้ายังไม่เจอ ใช้ global index
                                                                            if (!$info && isset($GLOBAL_BY_CODE,$GLOBAL_BY_CODE_N,$GLOBAL_BY_NAME,$GLOBAL_BY_NAME_N)) {
                                                                                if ($code) {
                                                                                    $code_lc = mb_lc($code);
                                                                                    $code_n  = $norm($code);
                                                                                    $info = $GLOBAL_BY_CODE[$code_lc] 
                                                                                         ?? $GLOBAL_BY_CODE_N[$code_n] 
                                                                                         ?? null;
                                                                                }
                                                                                if (!$info) {
                                                                                    $info = $GLOBAL_BY_NAME[$cn_lc] 
                                                                                         ?? $GLOBAL_BY_NAME_N[$keyN] 
                                                                                         ?? null;
                                                                                }
                                                                            }
                                                                        ?>
                                                                            <li>
                                                                                <?=h($cn)?>
                                                                                <?php if($info): ?>
                                                                                  <?php if(($info['year']??'')!==''): ?> · <span class="small">ปีที่ควรศึกษา: <b><?=h($info['year'])?></b></span><?php endif; ?>
                                                                                  <?php if(($info['prereq']??'')!==''): ?> · <span class="small">วิชาก่อน: <b><?=h($info['prereq'])?></b></span><?php endif; ?>
                                                                                <?php endif; ?>
                                                                            </li>
                                                                        <?php endforeach; ?>
                                                                    </ul>
                                                                <?php else: ?>
                                                                    <div class="small"><i class="fas fa-info-circle"></i> ไม่มีรายการวิชาที่แนะนำ</div>
                                                                <?php endif; ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php else: ?>
                                                    <div class="small"><i class="fas fa-minus-circle"></i> ยังไม่มีผลจากแบบทดสอบใน test_history</div>
                                                <?php endif; ?>                                               
                                            </div>
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

    <?php if (!empty($DEBUG)): ?>
    <pre style="margin-top:20px;padding:16px;border:1px dashed #cbd5e1;background:#fff;border-radius:10px;white-space:pre-wrap;font-size:12px;color:#6b7280">
DEBUG
- test_history: <?= tableExists($conn,'test_history') ? 'YES' : 'NO' ?>

- students: <?= isset($students)?count($students):0 ?> records
- selectedGroupId: <?= h((string)$selectedGroupId) ?>

- subjectsByGroup[sel]: <?php 
$__sg=$subjectsByGroup[(string)$selectedGroupId]??[]; echo count($__sg); ?> items
    </pre>
    <?php endif; ?>
</div>

<!-- Modal: เปลี่ยนรหัสผ่าน -->
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
                <div style="margin-bottom:12px">
                    <label class="small" style="font-weight:600">รหัสผ่านปัจจุบัน</label>
                    <input class="form-input" type="password" name="current_password" required>
                </div>
                <div style="margin-bottom:12px">
                    <label class="small" style="font-weight:600">รหัสผ่านใหม่</label>
                    <input class="form-input" type="password" name="new_password" minlength="8" required>
                </div>
                <div>
                    <label class="small" style="font-weight:600">ยืนยันรหัสผ่านใหม่</label>
                    <input class="form-input" type="password" name="confirm_password" minlength="8" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('pwModal')"><i class="fas fa-times"></i> ยกเลิก</button>
                <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> บันทึก</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: แก้ไขโปรไฟล์ -->
<div id="pfModal" class="modal" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-id-card"></i> แก้ไขโปรไฟล์อาจารย์</h3>
            <button class="modal-close" onclick="closeModal('pfModal')" type="button"><i class="fas fa-times"></i></button>
        </div>
        <form method="post" autocomplete="off">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="csrf_token" value="<?=h($_SESSION['csrf_token'])?>">
                <div style="margin-bottom:12px">
                    <label class="small" style="font-weight:600">ชื่อ-นามสกุล</label>
                    <input class="form-input" type="text" name="pf_name" value="<?=h($teacherProfile['name'] ?? '')?>" maxlength="150">
                </div>
                <div style="margin-bottom:12px">
                    <label class="small" style="font-weight:600">อีเมล</label>
                    <input class="form-input" type="email" name="pf_email" value="<?=h($teacherProfile['email'] ?? '')?>" maxlength="150">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('pfModal')"><i class="fas fa-times"></i> ยกเลิก</button>
                <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script>
// Modal
function openModal(id){const m=document.getElementById(id);if(!m)return;m.style.display='flex';setTimeout(()=>{m.classList.add('show');m.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';const f=m.querySelector('input,select,textarea');if(f){setTimeout(()=>f.focus(),100);}},10);}
function closeModal(id){const m=document.getElementById(id);if(!m)return;m.classList.remove('show');m.setAttribute('aria-hidden','true');document.body.style.overflow='';setTimeout(()=>m.style.display='none',200);}
window.addEventListener('click',e=>{['pwModal','pfModal'].forEach(mid=>{const m=document.getElementById(mid);if(m && e.target===m){closeModal(mid);}});});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){document.querySelectorAll('.modal.show').forEach(m=>closeModal(m.id));}});
document.addEventListener('DOMContentLoaded',function(){
    // validate pw confirm
    const f=document.querySelector('#pwModal form'); if(f){
        const np=f.querySelector('input[name="new_password"]'); const cp=f.querySelector('input[name="confirm_password"]');
        function v(){ if(np.value && cp.value){ cp.setCustomValidity(np.value!==cp.value?'รหัสผ่านไม่ตรงกัน':''); } }
        np.addEventListener('input',v); cp.addEventListener('input',v);
    }
});

// Live search + accordion
(function(){
    const q=document.getElementById('q');
    const tbody=document.querySelector('#list tbody');
    if(!q||!tbody) return;
    const masters=[...tbody.querySelectorAll('tr.master-row')];
    const details=[...tbody.querySelectorAll('tr.detail-row')];
    function updateCounter(){
        const total=masters.length;
        const visible=masters.filter(tr=>tr.style.display!=='none').length;
        const el=document.getElementById('cnt'); if(el) el.textContent=`แสดง ${visible} / ${total} รายการ`;
    }
    function filter(){
        const term=q.value.trim().toLowerCase();
        masters.forEach((m,i)=>{
            const text=m.innerText.toLowerCase();
            const ok=!term||text.includes(term);
            m.style.display= ok? '' : 'none';
            if(details[i]) details[i].style.display= ok && m.classList.contains('open')? '' : 'none';
        });
        updateCounter();
    }
    q.addEventListener('input',filter);
    updateCounter();

    masters.forEach((m,i)=>{
        const a=m.querySelector('.row-link');
        const d=details[i];
        if(!a||!d) return;
        a.addEventListener('click', e=>{
            const wasOpen = m.classList.contains('open');
            // Close others
            masters.forEach((mm,j)=>{
                if (m !== mm) { mm.classList.remove('open'); if(details[j]) details[j].style.display = 'none'; }
            });
            // Toggle current
            if(!wasOpen){ m.classList.add('open'); d.style.display='table-row'; }
            else{ m.classList.remove('open'); d.style.display='none'; }
        });
    });
})();
</script>
</body>
</html>
