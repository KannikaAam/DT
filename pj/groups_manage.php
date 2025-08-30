<?php
// groups_manage.php — จัดการกลุ่มเรียน (เชื่อม education_info.student_group & curriculum_name)
session_start();
if (empty($_SESSION['loggedin'])) { header('Location: login.php?error=unauthorized'); exit; }

/* ===== Role ===== */
$user_role = $_SESSION['user_type']
  ?? (isset($_SESSION['admin_id']) ? 'admin'
  : (isset($_SESSION['teacher_id']) ? 'teacher' : null));
if (!in_array($user_role, ['admin','teacher'], true)) { header('Location: login.php?error=unauthorized'); exit; }
$is_admin   = $user_role === 'admin';
$is_teacher = $user_role === 'teacher';
$user_id    = $is_admin ? ($_SESSION['admin_id'] ?? null) : ($_SESSION['teacher_id'] ?? null);

/* ===== DB connect ===== */
$DB_HOST='127.0.0.1'; $DB_NAME='studentregistration'; $DB_USER='root'; $DB_PASS='';
try {
  $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch(PDOException $e){ die('DB Connection failed: '.htmlspecialchars($e->getMessage())); }

/* ===== Auto-migrate: course_groups fields we need ===== */
try{
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='course_groups' AND COLUMN_NAME='curriculum_value' LIMIT 1");
  $q->execute(); if(!$q->fetchColumn()){
    $pdo->exec("ALTER TABLE course_groups ADD COLUMN curriculum_value VARCHAR(100) NULL AFTER course_id");
  }
  $q=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='course_groups' AND INDEX_NAME='idx_cg_cur_group'");
  $q->execute(); if(!(int)$q->fetchColumn()){
    @ $pdo->exec("CREATE INDEX idx_cg_cur_group ON course_groups(curriculum_value, group_name)");
  }
}catch(Throwable $e){}

/* ===== Utils ===== */
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
function flash($t,$m){ $_SESSION['flash'][]=['t'=>$t,'m'=>$m]; }
$flashes = $_SESSION['flash'] ?? []; unset($_SESSION['flash']);

/* ===== Student column helpers (for flexible schema) ===== */
function colExists(PDO $pdo, $table, $col){
  $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $st->execute([$table,$col]); return (bool)$st->fetchColumn();
}
function studentNameExpr(PDO $pdo){
  if (colExists($pdo,'students','student_name')) return 's.student_name';
  if (colExists($pdo,'students','full_name'))   return 's.full_name';
  if (colExists($pdo,'students','name'))        return 's.name';
  $hasF=colExists($pdo,'students','first_name'); $hasL=colExists($pdo,'students','last_name');
  if($hasF||$hasL){ $p=[]; if($hasF)$p[]='s.first_name'; if($hasL)$p[]='s.last_name'; return "TRIM(CONCAT_WS(' ',".implode(',',$p)."))"; }
  return "CAST(s.student_id AS CHAR)";
}
function studentCodeExpr(PDO $pdo){
  if (colExists($pdo,'students','student_code')) return 's.student_code';
  if (colExists($pdo,'students','code'))        return 's.code';
  return "CAST(s.student_id AS CHAR)";
}

/* ===== Data helpers ===== */
function curriculumOptions(PDO $pdo){
  return $pdo->query("SELECT value,label FROM form_options
                      WHERE type='curriculum_name' AND is_active=1
                      ORDER BY sort_order, label")->fetchAll(PDO::FETCH_ASSOC);
}
function groupNameOptions(PDO $pdo){
  return $pdo->query("SELECT value,label FROM form_options
                      WHERE type='student_group' AND is_active=1
                      ORDER BY sort_order, label")->fetchAll(PDO::FETCH_ASSOC);
}
function teachersList(PDO $pdo){
  return $pdo->query("SELECT teacher_id, name AS teacher_name FROM teacher ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}
function groupsByCurriculum(PDO $pdo, $cur, $is_admin, $is_teacher, $uid){
  // นับ นศ. จาก education_info (ผูกด้วย curriculum_name + student_group)
  $base="SELECT cg.*,
               t.name AS teacher_name,
               (SELECT COUNT(*) FROM education_info ei
                 WHERE ei.curriculum_name=cg.curriculum_value
                   AND ei.student_group=cg.group_name) AS student_count
        FROM course_groups cg
        LEFT JOIN teacher t ON cg.teacher_id=t.teacher_id
        WHERE cg.curriculum_value=?";
  if ($is_admin){ $sql="$base ORDER BY cg.group_name"; $st=$pdo->prepare($sql); $st->execute([$cur]); }
  else { $sql="$base AND cg.teacher_id=? ORDER BY cg.group_name"; $st=$pdo->prepare($sql); $st->execute([$cur,$uid]); }
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
function groupSignature(PDO $pdo, $group_id){
  $st=$pdo->prepare("SELECT group_id, curriculum_value, group_name, teacher_id, max_students FROM course_groups WHERE group_id=?");
  $st->execute([$group_id]); return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function roster(PDO $pdo, $group_id, $is_admin, $is_teacher, $uid){
  // ถ้าเป็นครู ต้องเป็นเจ้าของกลุ่ม
  if ($is_teacher){
    $chk=$pdo->prepare("SELECT 1 FROM course_groups WHERE group_id=? AND teacher_id=?");
    $chk->execute([$group_id,$uid]); if(!$chk->fetchColumn()) return [];
  }
  $sig = groupSignature($pdo,$group_id); if(!$sig) return [];
  $nameExpr=studentNameExpr($pdo); $codeExpr=studentCodeExpr($pdo);
  $sql="SELECT s.student_id, {$codeExpr} AS student_code, {$nameExpr} AS student_name,
               ei.status, ei.created_at AS enrollment_date
        FROM education_info ei
        INNER JOIN students s ON s.student_id=ei.student_id
        WHERE ei.curriculum_name=? AND ei.student_group=?
        ORDER BY student_name";
  $st=$pdo->prepare($sql); $st->execute([$sig['curriculum_value'],$sig['group_name']]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function groupInfo(PDO $pdo, $group_id, $uid, $is_admin, $is_teacher){
  $base="SELECT cg.*, t.name AS teacher_name
         FROM course_groups cg
         LEFT JOIN teacher t ON cg.teacher_id=t.teacher_id
         WHERE cg.group_id=?";
  if ($is_admin){ $st=$pdo->prepare($base); $st->execute([$group_id]); }
  else { $st=$pdo->prepare("$base AND cg.teacher_id=?"); $st->execute([$group_id,$uid]); }
  return $st->fetch(PDO::FETCH_ASSOC);
}

/* ===== AJAX ===== */
if (isset($_GET['ajax'])){
  $ajax=$_GET['ajax'];

  if ($ajax==='groups_table'){
    header('Content-Type: text/html; charset=utf-8');
    $cur = trim($_GET['curriculum_value'] ?? '');
    if ($cur===''){ echo '<div>โปรดเลือกหลักสูตร</div>'; exit; }
    $groups = groupsByCurriculum($pdo,$cur,$is_admin,$is_teacher,$user_id); ?>
    <table class="table">
      <thead><tr><th>กลุ่ม</th><th>อาจารย์</th><th>นศ.</th><th>รับสูงสุด</th><th>จัดการ</th></tr></thead>
      <tbody>
      <?php foreach($groups as $g): ?>
        <tr>
          <td><?php echo e($g['group_name']); ?></td>
          <td><?php echo e($g['teacher_name'] ?? '-'); ?></td>
          <td><?php echo (int)$g['student_count']; ?></td>
          <td><?php echo (int)$g['max_students']; ?></td>
          <td class="actions">
            <button class="btn secondary btn-sm" onclick="loadRoster(<?php echo (int)$g['group_id']; ?>)">รายชื่อ</button>
            <?php if ($is_admin): ?>
              <button class="btn btn-sm" style="background:#0ea5e9;color:#fff"
                onclick="openEditGroupFromBtn(this)"
                data-id="<?php echo (int)$g['group_id']; ?>"
                data-cur="<?php echo e($g['curriculum_value']); ?>"
                data-name="<?php echo e($g['group_name']); ?>"
                data-teacher-id="<?php echo e($g['teacher_id']); ?>"
                data-max="<?php echo (int)$g['max_students']; ?>">แก้ไข</button>
              <button class="btn danger btn-sm"
                onclick="confirmDeleteGroupFromBtn(this)"
                data-id="<?php echo (int)$g['group_id']; ?>"
                data-name="<?php echo e($g['group_name']); ?>">ลบ</button>
              <button class="btn primary btn-sm" onclick="openEnroll(<?php echo (int)$g['group_id']; ?>)">+ เพิ่ม นศ.</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; if(empty($groups)): ?>
        <tr><td colspan="5">ยังไม่มีกลุ่มในหลักสูตรนี้</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <?php exit;
  }

  if ($ajax==='roster'){
    header('Content-Type: text/html; charset=utf-8');
    $gid=(int)($_GET['group_id'] ?? 0);
    if ($gid<=0){ echo '<div>ไม่พบกลุ่ม</div>'; exit; }
    $info = groupInfo($pdo,$gid,$user_id,$is_admin,$is_teacher);
    if (!$info){ echo '<div>ไม่พบกลุ่มหรือสิทธิ์ไม่พอ</div>'; exit; }
    $rows = roster($pdo,$gid,$is_admin,$is_teacher,$user_id);
    ?>
    <div class="roster-head">
      <div><b>กลุ่ม: <?php echo e($info['group_name']); ?></b> | หลักสูตร: <?php echo e($info['curriculum_value'] ?: '-'); ?></div>
      <div>อาจารย์: <?php echo e($info['teacher_name'] ?? '-'); ?> • นศ.: <?php echo is_countable($rows)?count($rows):0; ?>/<?php echo (int)$info['max_students']; ?></div>
    </div>
    <table class="table">
      <thead><tr><th>รหัส</th><th>ชื่อ</th><th>สถานะ</th><th>วันที่ลงทะเบียน</th><?php if($is_admin):?><th>จัดการ</th><?php endif;?></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?php echo e($r['student_code'] ?? $r['student_id']); ?></td>
          <td><?php echo e($r['student_name'] ?? ''); ?></td>
          <td><?php echo e($r['status'] ?? 'enrolled'); ?></td>
          <td><?php echo e($r['enrollment_date']); ?></td>
          <?php if($is_admin): ?>
          <td>
            <form method="post" onsubmit="return confirm('นำออก <?php echo e($r['student_name'] ?? ''); ?> ?')">
              <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="action" value="unenroll">
              <input type="hidden" name="group_id" value="<?php echo (int)$gid; ?>">
              <input type="hidden" name="student_id" value="<?php echo (int)$r['student_id']; ?>">
              <button class="btn danger btn-sm" type="submit">นำออก</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; if(empty($rows)): ?>
        <tr><td colspan="<?php echo $is_admin?5:4; ?>">ยังไม่มีนักศึกษา</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <?php exit;
  }

  if ($ajax==='student_options'){
    // แสดงรายชื่อนศ.ในหลักสูตรนั้นที่ยังไม่ได้อยู่ในกลุ่มนี้ (education_info.student_group != group_name)
    header('Content-Type: text/html; charset=utf-8');
    if(!$is_admin){ echo 'permission denied'; exit; }
    $gid=(int)($_GET['group_id'] ?? 0);
    $q  =trim($_GET['q'] ?? '');
    $sig=groupSignature($pdo,$gid);
    if(!$sig){ echo '<div class="muted">ไม่พบกลุ่ม</div>'; exit; }

    $nameExpr=studentNameExpr($pdo); $codeExpr=studentCodeExpr($pdo);

    $sql="SELECT s.student_id, {$codeExpr} AS student_code, {$nameExpr} AS student_name
          FROM education_info ei
          INNER JOIN students s ON s.student_id=ei.student_id
          WHERE ei.curriculum_name=:cur
            AND (ei.student_group IS NULL OR ei.student_group<>:grp)";
    if ($q !== '') $sql.=" AND ({$nameExpr} LIKE :kw OR {$codeExpr} LIKE :kw)";
    $sql.=" ORDER BY student_name LIMIT 300";
    $st=$pdo->prepare($sql);
    $st->bindValue(':cur',$sig['curriculum_value']);
    $st->bindValue(':grp',$sig['group_name']);
    if ($q!=='') $st->bindValue(':kw',"%{$q}%",PDO::PARAM_STR);
    $st->execute();
    $list=$st->fetchAll(PDO::FETCH_ASSOC);

    foreach($list as $s){
      echo '<label class="pick">
        <input type="checkbox" name="student_ids[]" value="'.(int)$s['student_id'].'">
        <span>'.e(($s['student_code'] ?? '').' - '.($s['student_name'] ?? '')).'</span>
      </label>';
    }
    if(empty($list)) echo '<div class="muted">ไม่พบนักศึกษาที่เพิ่มได้</div>';
    exit;
  }

  echo 'bad request'; exit;
}

/* ===== POST actions ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])){
  $csrf=$_POST['csrf_token'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'],$csrf)) { flash('danger','CSRF ไม่ถูกต้อง'); header("Location: ".$_SERVER['PHP_SELF']); exit; }

  $act=$_POST['action'];
  if (in_array($act, ['add_group','edit_group','delete_group','enroll','unenroll'], true) && !$is_admin){
    flash('danger','คุณไม่มีสิทธิ์ดำเนินการนี้'); header("Location: ".$_SERVER['PHP_SELF']); exit;
  }

  if ($act==='add_group' || $act==='edit_group'){
    $group_id     =(int)($_POST['group_id'] ?? 0);
    $cur          =trim($_POST['curriculum_value'] ?? '');
    $group_name   =trim($_POST['group_name'] ?? '');
    $teacher_id   =trim($_POST['teacher_id'] ?? ''); // string ได้
    $max_students =(int)($_POST['max_students'] ?? 40);

    $errs=[];
    if($cur==='')        $errs[]='โปรดเลือกหลักสูตร';
    if($group_name==='') $errs[]='โปรดเลือกกลุ่มเรียน';
    if($teacher_id==='') $errs[]='โปรดเลือกอาจารย์ผู้สอน';
    if($max_students<1 || $max_students>500) $errs[]='จำนวนนักศึกษาสูงสุด 1–500';
    $ckT=$pdo->prepare("SELECT 1 FROM teacher WHERE teacher_id=? LIMIT 1"); $ckT->execute([$teacher_id]);
    if(!$ckT->fetchColumn()) $errs[]='ไม่พบบัญชีอาจารย์ที่เลือก';
    if($errs){ foreach($errs as $e) flash('danger',$e); header("Location: ".$_SERVER['PHP_SELF']); exit; }

    if ($act==='add_group'){
      $ck=$pdo->prepare("SELECT 1 FROM course_groups WHERE curriculum_value=? AND group_name=? LIMIT 1");
      $ck->execute([$cur,$group_name]); if($ck->fetchColumn()){ flash('danger','กลุ่มนี้มีอยู่แล้ว'); header("Location: ".$_SERVER['PHP_SELF']); exit; }
      $ins=$pdo->prepare("INSERT INTO course_groups (curriculum_value, group_name, teacher_id, max_students) VALUES (?,?,?,?)");
      $ins->execute([$cur,$group_name,$teacher_id,$max_students]);
      flash('ok','เพิ่มกลุ่มเรียนแล้ว');
    }else{
      // ห้ามลดต่ำกว่าจำนวนนศ.ปัจจุบันใน education_info
      $cnt=$pdo->prepare("SELECT COUNT(*) FROM education_info WHERE curriculum_name=? AND student_group=?");
      $cnt->execute([$cur,$group_name]); $curCount=(int)$cnt->fetchColumn();
      if ($max_students < $curCount){
        flash('danger','จำนวนนักศึกษาปัจจุบันมากกว่าค่าสูงสุดใหม่');
        header("Location: ".$_SERVER['PHP_SELF']); exit;
      }
      $up=$pdo->prepare("UPDATE course_groups
                         SET curriculum_value=?, group_name=?, teacher_id=?, max_students=?
                         WHERE group_id=?");
      $up->execute([$cur,$group_name,$teacher_id,$max_students,$group_id]);
      flash('ok','แก้ไขกลุ่มเรียนเรียบร้อย');
    }
    header("Location: ".$_SERVER['PHP_SELF']); exit;
  }

  if ($act==='delete_group'){
    $gid=(int)($_POST['group_id'] ?? 0);
    $pdo->prepare("DELETE FROM course_groups WHERE group_id=?")->execute([$gid]);
    flash('ok','ลบกลุ่มเรียนเรียบร้อย'); header("Location: ".$_SERVER['PHP_SELF']); exit;
  }

  if ($act==='enroll'){
    $gid=(int)($_POST['group_id'] ?? 0);
    $ids=$_POST['student_ids'] ?? [];
    if(!$ids){ flash('danger','โปรดเลือกนักศึกษา'); header("Location: ".$_SERVER['PHP_SELF']); exit; }
    $sig=groupSignature($pdo,$gid); if(!$sig){ flash('danger','ไม่พบกลุ่ม'); header("Location: ".$_SERVER['PHP_SELF']); exit; }

    // ตรวจ capacity จาก education_info
    $cnt=$pdo->prepare("SELECT COUNT(*) FROM education_info WHERE curriculum_name=? AND student_group=?");
    $cnt->execute([$sig['curriculum_value'],$sig['group_name']]); $curCount=(int)$cnt->fetchColumn();
    $avail=max(0, (int)$sig['max_students'] - $curCount);

    $added=0;
    foreach($ids as $sid){
      if($avail<=0) break;
      $sid=(int)$sid; if($sid<=0) continue;
      // ข้ามถ้าอยู่กลุ่มนี้อยู่แล้ว
      $ck=$pdo->prepare("SELECT 1 FROM education_info WHERE student_id=? AND curriculum_name=? AND student_group=?");
      $ck->execute([$sid,$sig['curriculum_value'],$sig['group_name']]); if($ck->fetchColumn()) continue;
      // ย้ายเข้ากลุ่ม (อัปเดตแถวของหลักสูตรนั้น)
      $up=$pdo->prepare("UPDATE education_info SET student_group=? WHERE student_id=? AND curriculum_name=?");
      $up->execute([$sig['group_name'],$sid,$sig['curriculum_value']]);
      if($up->rowCount()>0){ $added++; $avail--; }
    }
    if($added>0) flash('ok',"เพิ่มนักศึกษาแล้ว {$added} คน"); else flash('danger','ไม่สามารถเพิ่มนักศึกษา');
    header("Location: ".$_SERVER['PHP_SELF']); exit;
  }

  if ($act==='unenroll'){
    $gid=(int)($_POST['group_id'] ?? 0);
    $sid=(int)($_POST['student_id'] ?? 0);
    $sig=groupSignature($pdo,$gid);
    if($sig){
      // เอาออก = เซ็ตกลุ่มเป็น NULL ใน education_info สำหรับหลักสูตรนั้น
      $pdo->prepare("UPDATE education_info SET student_group=NULL WHERE student_id=? AND curriculum_name=?")
          ->execute([$sid,$sig['curriculum_value']]);
      flash('ok','นำออกจากกลุ่มแล้ว');
    } else {
      flash('danger','ไม่พบกลุ่ม');
    }
    header("Location: ".$_SERVER['PHP_SELF']); exit;
  }
}

/* ===== Initial for rendering ===== */
$curricula   = curriculumOptions($pdo);
$teachers    = $is_admin ? teachersList($pdo) : [];
$groupNames  = groupNameOptions($pdo);
$selected_cur = $_GET['cur'] ?? ($curricula[0]['value'] ?? '');
$groups_top   = $selected_cur ? groupsByCurriculum($pdo,$selected_cur,$is_admin,$is_teacher,$user_id) : [];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>จัดการกลุ่มเรียน</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
:root{--navy:#113F67;--steel:#34699A;--sky:#58A0C8;--sun:#FDF5AA;--text:#eef2f7;--muted:rgba(255,255,255,.78);--line:rgba(255,255,255,.14)}
*{box-sizing:border-box} body{margin:0;font-family:'Sarabun',system-ui,Segoe UI,Roboto,sans-serif;color:var(--text);
background:radial-gradient(900px 600px at 15% 10%, rgba(88,160,200,.25), transparent 60%),radial-gradient(700px 500px at 85% 0%, rgba(253,245,170,.18), transparent 60%),linear-gradient(135deg,var(--navy),var(--steel));min-height:100vh}
.container{max-width:1200px;margin:28px auto;padding:16px}
.header{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:12px}
.title{margin:0;font-size:22px;font-weight:800}.subtitle{color:var(--muted)}
.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.select, .input{padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:rgba(17,63,103,.35);color:var(--text);min-width:220px}
.btn{padding:10px 14px;border-radius:10px;border:1px solid var(--line);cursor:pointer;text-decoration:none;font-weight:800}
.primary{color:#0b243d;background:linear-gradient(135deg,var(--sun),#fff6c8)} .secondary{color:#fff;background:linear-gradient(135deg,var(--steel),var(--sky))}
.danger{background:#dc3545;color:#fff;border-color:rgba(255,255,255,.15)}
.card{background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:16px;padding:16px;backdrop-filter:blur(10px)}
.columns{display:grid;grid-template-columns:1.2fr .8fr;gap:16px}
.table{width:100%;border-collapse:collapse;margin-top:10px}
.table th,.table td{padding:12px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left}
.table th{background:rgba(255,255,255,.08)}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.alert{padding:12px 14px;border-radius:12px;margin:8px 0;border:1px solid}.ok{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.35)}.dangerA{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.35)}
.muted{color:var(--muted)} .btn-sm{padding:6px 10px;font-weight:700}
.roster-head{display:flex;justify-content:space-between;gap:8px;margin-bottom:8px}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000}
.modal-content{background:#fff;color:#111;margin:4% auto;padding:18px;border-radius:14px;width:92%;max-width:840px;max-height:88vh;overflow:auto}
.modal-header{display:flex;justify-content:space-between;align-items:center;padding-bottom:10px;border-bottom:1px solid #eee;margin-bottom:12px}
.close{font-size:1.6rem;cursor:pointer;color:#666}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.form-field{display:flex;flex-direction:column;gap:6px}
.form-field input,.form-field select{padding:10px 12px;border:1px solid #ddd;border-radius:10px}
.pick{display:flex;gap:8px;align-items:center;margin:6px 0}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div>
      <h1 class="title"><i class="fas fa-users"></i> จัดการกลุ่มเรียน</h1>
      <div class="subtitle">กลุ่มเรียนผูกกับ <b>education_info.student_group</b> • หลักสูตรผูกกับ <b>education_info.curriculum_name</b></div>
    </div>
    <div class="toolbar">
      <select id="curriculumSelect" class="select" onchange="reloadGroups()">
        <option value="">— เลือกหลักสูตร —</option>
        <?php foreach($curricula as $cur): ?>
          <option value="<?php echo e($cur['value']); ?>" <?php echo ($cur['value']===$selected_cur)?'selected':''; ?>>
            <?php echo e($cur['label']); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select id="groupSelect" class="select" onchange="if(this.value){ loadRoster(this.value); }">
        <option value="">— เลือกกลุ่ม —</option>
        <?php foreach($groups_top as $g): ?>
          <option value="<?php echo (int)$g['group_id']; ?>">
            <?php echo e($g['group_name']); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <?php if ($is_admin): ?>
      <button class="btn primary" onclick="openAddGroup()"><i class="fas fa-plus"></i> เพิ่มกลุ่มเรียน</button>
      <?php endif; ?>
    </div>
  </div>

  <?php foreach ($flashes as $f): ?>
    <div class="alert <?php echo $f['t']==='ok'?'ok':'dangerA'; ?>">• <?php echo e($f['m']); ?></div>
  <?php endforeach; ?>

  <div class="columns">
    <div class="card">
      <h3 style="margin:0 0 8px">กลุ่มเรียนในหลักสูตร</h3>
      <div id="groupsArea"><div class="muted">โปรดเลือกหลักสูตร</div></div>
    </div>

    <div class="card">
      <h3 style="margin:0 0 8px">รายชื่อนักศึกษาในกลุ่ม</h3>
      <div id="rosterArea"><div class="muted">เลือกกลุ่มจากดรอปดาวด้านบน หรือกด “รายชื่อ” จากตาราง</div></div>
    </div>
  </div>
</div>

<?php if ($is_admin): ?>
<!-- Modal: Add/Edit Group -->
<div id="groupModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3 id="gmTitle">เพิ่มกลุ่มเรียน</h3>
      <span class="close" onclick="closeModal('groupModal')">&times;</span>
    </div>
    <form method="post" id="groupForm">
      <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
      <input type="hidden" name="action" value="add_group" id="gmAction">
      <input type="hidden" name="group_id" id="gm_group_id">
      <div class="form-grid">
        <div class="form-field">
          <label>หลักสูตร (curriculum_name)</label>
          <select name="curriculum_value" id="gm_curriculum" required>
            <option value="">— เลือกหลักสูตร —</option>
            <?php foreach($curricula as $cur): ?>
              <option value="<?php echo e($cur['value']); ?>"><?php echo e($cur['label']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-field">
          <label>กลุ่มเรียน (student_group)</label>
          <select name="group_name" id="gm_group_name" required>
            <option value="">— เลือกกลุ่ม —</option>
            <?php foreach($groupNames as $gn): ?>
              <option value="<?php echo e($gn['label']); ?>"><?php echo e($gn['label']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-field">
          <label>อาจารย์ผู้สอน</label>
          <select name="teacher_id" id="gm_teacher_id" required>
            <option value="">— เลือกอาจารย์ —</option>
            <?php foreach($teachers as $t): ?>
              <option value="<?php echo e($t['teacher_id']); ?>"><?php echo e($t['teacher_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-field">
          <label>จำนวนนักศึกษาสูงสุด</label>
          <input type="number" min="1" max="500" name="max_students" id="gm_max" value="40" required>
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:10px">
        <button type="button" class="btn" onclick="closeModal('groupModal')">ยกเลิก</button>
        <button type="submit" class="btn primary">บันทึก</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Delete Group -->
<div id="delGroupModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>ยืนยันการลบกลุ่ม</h3>
      <span class="close" onclick="closeModal('delGroupModal')">&times;</span>
    </div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
      <input type="hidden" name="action" value="delete_group">
      <input type="hidden" name="group_id" id="dg_group_id">
      <p>ต้องการลบกลุ่ม <b id="dg_group_name"></b> ใช่หรือไม่?</p>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:10px">
        <button type="button" class="btn" onclick="closeModal('delGroupModal')">ยกเลิก</button>
        <button type="submit" class="btn danger">ลบ</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Enroll Students -->
<div id="enrollModal" class="modal">
  <div class="modal-content">
    <div class="modal-header"><h3>เพิ่มนักศึกษาเข้ากลุ่ม</h3><span class="close" onclick="closeModal('enrollModal')">&times;</span></div>
    <form method="post" id="enrollForm">
      <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
      <input type="hidden" name="action" value="enroll">
      <input type="hidden" name="group_id" id="en_group_id">
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px">
        <input type="text" id="en_search" class="input" placeholder="ค้นหา รหัส/ชื่อ นักศึกษา…">
        <button class="btn secondary" type="button" onclick="loadStudentOptions()">ค้นหา</button>
      </div>
      <div id="studentOptions" style="max-height:50vh;overflow:auto;border:1px solid #eee;padding:10px;background:#fff;border-radius:10px;color:#111">
        <div class="muted">กรอกคำค้นแล้วกดค้นหา</div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:10px">
        <button type="button" class="btn" onclick="closeModal('enrollModal')">ยกเลิก</button>
        <button type="submit" class="btn primary">เพิ่มที่เลือก</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function $(id){ return document.getElementById(id); }

function reloadGroups(){
  const cur = $('curriculumSelect').value || '';
  const url = new URL(location.href);
  if (cur) url.searchParams.set('cur', cur); else url.searchParams.delete('cur');
  location.href = url.toString();
}
function loadRoster(groupId){
  fetch(`?ajax=roster&group_id=${groupId}`).then(r=>r.text()).then(html=>{ $('rosterArea').innerHTML=html; });
}
function loadGroupsTableForCurrentCur(){
  const cur = $('curriculumSelect').value || '';
  if (!cur){ $('groupsArea').innerHTML='<div class="muted">โปรดเลือกหลักสูตร</div>'; return; }
  fetch(`?ajax=groups_table&curriculum_value=${encodeURIComponent(cur)}`)
    .then(r=>r.text()).then(html=>{ $('groupsArea').innerHTML=html; });
}

<?php if ($is_admin): ?>
function openAddGroup(){
  $('gmTitle').innerText='เพิ่มกลุ่มเรียน';
  $('gmAction').value='add_group';
  $('gm_group_id').value='';
  $('gm_curriculum').value = $('curriculumSelect').value || '';
  $('gm_group_name').value='';
  $('gm_teacher_id').value='';
  $('gm_max').value='40';
  $('groupModal').style.display='block';
}
function openEditGroupFromBtn(btn){
  const d = btn.dataset; // id, cur, name, teacherId, max
  openEditGroup(d.id, d.cur, d.name, d.teacherId, d.max);
}
function openEditGroup(id, curVal, name, teacher_id, max_stu){
  $('gmTitle').innerText='แก้ไขกลุ่มเรียน';
  $('gmAction').value='edit_group';
  $('gm_group_id').value=id;
  $('gm_curriculum').value=curVal || '';
  $('gm_teacher_id').value=teacher_id || '';
  $('gm_max').value=max_stu || 40;
  const sel=$('gm_group_name');
  if (![...sel.options].some(o=>o.value===name)) sel.add(new Option(name,name));
  sel.value=name;
  $('groupModal').style.display='block';
}
function confirmDeleteGroupFromBtn(btn){
  const d=btn.dataset; $('dg_group_id').value=d.id; $('dg_group_name').innerText=d.name; $('delGroupModal').style.display='block';
}
function openEnroll(group_id){
  $('en_group_id').value=group_id; $('en_search').value='';
  $('studentOptions').innerHTML='<div class="muted">กรอกคำค้นแล้วกดค้นหา</div>'; $('enrollModal').style.display='block';
}
function loadStudentOptions(){
  const gid=$('en_group_id').value||0, q=encodeURIComponent($('en_search').value||'');
  fetch(`?ajax=student_options&group_id=${gid}&q=${q}`).then(r=>r.text()).then(html=>{ $('studentOptions').innerHTML=html; });
}
<?php endif; ?>

function closeModal(id){ const el=$(id); if(el) el.style.display='none'; }
window.onclick=function(ev){ ['groupModal','delGroupModal','enrollModal'].forEach(mid=>{ const el=$(mid); if(el && ev.target===el) el.style.display='none'; }); };

document.addEventListener('DOMContentLoaded', function () {
  loadGroupsTableForCurrentCur();
});
</script>
</body>
</html>
