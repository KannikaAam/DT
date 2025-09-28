<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$config = [
  'host' => 'localhost',
  'username' => 'aprdt',
  'password' => 'aprdt1234',
  'database' => 'studentregistration',
  'charset' => 'utf8mb4'
];

function db($cfg){ mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
  $c = new mysqli($cfg['host'],$cfg['username'],$cfg['password'],$cfg['database']);
  $c->set_charset($cfg['charset']); return $c;
}
function json_out($ok,$msg='',$redirect=null,$extra=[]){
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array_merge([
    'success'=>(bool)$ok,'message'=>$msg,'redirect_url'=>$redirect
  ],$extra), JSON_UNESCAPED_UNICODE); exit;
}
function verify_any($input,$stored){
  $info = password_get_info((string)$stored);
  return !empty($info['algo']) ? password_verify($input,$stored)
                               : hash_equals((string)$stored,(string)$input);
}
function table_exists($conn,$name){
  $q=$conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=?");
  $q->bind_param('s',$name); $q->execute(); $q->store_result();
  $ok = $q->num_rows>0; $q->close(); return $ok;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
  // เฮดเดอร์สำหรับ AJAX เฉพาะตอน POST
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: POST');
  header('Access-Control-Allow-Headers: Content-Type');

  $username  = trim($_POST['username'] ?? $_POST['student_id'] ?? '');
  $password  = $_POST['password'] ?? '';
  $user_type = strtolower(trim($_POST['user_type'] ?? ''));

  if ($username==='' || $password==='' || !in_array($user_type,['student','teacher','admin'],true)) {
    json_out(false,'กรอกข้อมูลไม่ครบหรือประเภทผู้ใช้ไม่ถูกต้อง',null,['debug_user_type'=>$user_type]);
  }

  try {
    $conn = db($config);

// ---------- STUDENT ----------
if ($user_type === 'student') {
  if (!table_exists($conn,'user_login')) json_out(false,'ไม่พบตาราง user_login');

  // ค้นหาทั้งสองคอลัมน์ เผื่อข้อมูลบางแถวเก็บใน username หรือ student_id
  $st = $conn->prepare("
      SELECT username, student_id, password
      FROM user_login
      WHERE username = ? OR student_id = ?
      LIMIT 1
  ");
  $st->bind_param('ss', $username, $username);
  $st->execute();
  $rs = $st->get_result();

  if ($row = $rs->fetch_assoc()) {
    if (verify_any($password, $row['password'])) {
      // ตั้ง session แล้วเข้าแดชบอร์ดนักศึกษา
      $_SESSION['loggedin']   = true;
      $_SESSION['user_type']  = 'student';
      $_SESSION['student_id'] = $row['student_id'] ?: $row['username'];
      $_SESSION['username']   = $row['username']   ?: $row['student_id'];
      json_out(true,'เข้าสู่ระบบสำเร็จ','student_dashboard.php',['role'=>'student']);
    }
    json_out(false,'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
  }
  json_out(false,'ไม่พบชื่อผู้ใช้');
}


// ---------- TEACHER ----------
if ($user_type === 'teacher') {
  if (!table_exists($conn,'teacher')) json_out(false,'ไม่พบตาราง teacher');

  $as_id = ctype_digit($username) ? (int)$username : 0;

  $st = $conn->prepare(
    "SELECT
        id, username, teacher_code, name,
        COALESCE(role,'teacher')      AS role,
        COALESCE(password_hash,'')    AS password_hash,
        COALESCE(teacher_password,'') AS teacher_password,
        COALESCE(password,'')         AS legacy_plain,
        COALESCE(status,'active')     AS status
     FROM teacher
     WHERE (teacher_code = ? OR username = ? OR id = ?)
     LIMIT 1"
  );
  $st->bind_param('ssi', $username, $username, $as_id);
  $st->execute();
  $rs = $st->get_result();

  if ($row = $rs->fetch_assoc()) {
    if ($row['status'] !== 'active') json_out(false,'บัญชีถูกระงับการใช้งาน');

    $ok = false;

    // ตรวจ password_hash
    if ($row['password_hash'] !== '' && password_get_info($row['password_hash'])['algo']) {
      $ok = password_verify($password, $row['password_hash']);
    }
    // เผื่อค้างที่ teacher_password
    if (!$ok && $row['teacher_password'] !== '' && password_get_info($row['teacher_password'])['algo']) {
      $ok = password_verify($password, $row['teacher_password']);
      if ($ok && $row['password_hash']==='') {
        $newHash = $row['teacher_password'];
        $upd = $conn->prepare("UPDATE teacher SET password_hash=?, teacher_password=NULL WHERE id=?");
        $upd->bind_param('si',$newHash,$row['id']);
        $upd->execute(); $upd->close();
      }
    }
    // เผื่อเคยเก็บ plaintext ใน password
    if (!$ok && $row['legacy_plain'] !== '') {
      if (hash_equals($row['legacy_plain'], $password)) {
        $ok = true;
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE teacher SET password_hash=?, password=NULL, teacher_password=NULL WHERE id=?");
        $upd->bind_param('si',$newHash,$row['id']);
        $upd->execute(); $upd->close();
      }
    }

    if ($ok) {
      if ($row['role'] === 'admin') {
        $_SESSION['loggedin']=true; $_SESSION['user_type']='admin';
        $_SESSION['username']=$row['username']; $_SESSION['name']=$row['name'] ?: $row['username'];
        $_SESSION['admin_id']=$row['username']; $_SESSION['admin_username']=$row['username'];
        $_SESSION['teacher_id']=$row['id']; // เก็บ id ไว้
        json_out(true,'เข้าสู่ระบบสำเร็จ','admin_dashboard.php',['role'=>'admin']);
      } else {
        $_SESSION['loggedin']=true; $_SESSION['user_type']='teacher';
        $_SESSION['teacher_id']=$row['id']; // ใช้ id ตามสคีมา
        $_SESSION['username']=$row['username']; $_SESSION['name']=$row['name'];
        json_out(true,'เข้าสู่ระบบสำเร็จ','teacher.php',['role'=>'teacher']);
      }
    }

    json_out(false,'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
  }

  json_out(false,'ไม่พบชื่อผู้ใช้');
}



    // ---------- ADMIN ----------
    if ($user_type === 'admin') {
      $hit = false;

      if (table_exists($conn,'admins')) {
        $st = $conn->prepare("SELECT username, password FROM admins WHERE username=? LIMIT 1");
        $st->bind_param('s',$username); $st->execute(); $rs=$st->get_result();
        if ($row = $rs->fetch_assoc()) {
          if (verify_any($password,$row['password'])) {
            $_SESSION['loggedin']=true; $_SESSION['user_type']='admin';
            $_SESSION['admin_id']=$row['username']; $_SESSION['admin_username']=$row['username'];
            $_SESSION['username']=$row['username']; $_SESSION['name']=$row['username'];
            json_out(true,'เข้าสู่ระบบสำเร็จ','admin_dashboard.php',['role'=>'admin']);
          }
          $hit = true;
        }
      }

      if (table_exists($conn,'admin_users')) {
        $st = $conn->prepare("SELECT username, password_hash, COALESCE(full_name,'') AS full_name FROM admin_users WHERE username=? LIMIT 1");
        $st->bind_param('s',$username); $st->execute(); $rs=$st->get_result();
        if ($row = $rs->fetch_assoc()) {
          if (password_verify($password,$row['password_hash'])) {
            $_SESSION['loggedin']=true; $_SESSION['user_type']='admin';
            $_SESSION['admin_id']=$row['username']; $_SESSION['admin_username']=$row['username'];
            $_SESSION['username']=$row['username']; $_SESSION['name']=$row['full_name'] ?: $row['username'];
            json_out(true,'เข้าสู่ระบบสำเร็จ','admin_dashboard.php',['role'=>'admin']);
          }
          $hit = true;
        }
      }

      json_out(false, $hit ? 'รหัสผ่านไม่ถูกต้อง' : 'ไม่พบชื่อผู้ใช้');
    }

    // เผื่อหลุดมา
    json_out(false,'ประเภทผู้ใช้ไม่รองรับ');

  } catch (Throwable $e) {
    error_log('LOGIN_FATAL: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    json_out(false,'เซิร์ฟเวอร์ผิดพลาด กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ');
  }
}

// ---------- GET ----------
if (!empty($_SESSION['loggedin'])) {
  $t = $_SESSION['user_type'] ?? '';
  $to = ($t==='admin') ? 'admin_dashboard.php'
       : (($t==='teacher') ? 'teacher.php' : 'student_dashboard.php');
  header("Location: $to"); exit;
}
header("Location: index.php"); exit;
