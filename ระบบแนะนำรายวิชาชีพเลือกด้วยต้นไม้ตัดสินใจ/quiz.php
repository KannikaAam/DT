<?php
/* =========================================================
   quiz.php — fast & robust version
   - จำกัดสิทธิ์ทำ 3 ครั้ง + admin_override_attempts
   - ระงับสิทธิ์ถ้า academic_status = 'suspended'
   - บันทึกผลลง quiz_results / quiz_answers และ test_history
   - สคีมาถูกต้อง (AUTO_INCREMENT + INDEX ครบ) ตั้งแต่แรก
   - ไม่วิ่งตรวจ schema หนัก ๆ ทุกรีเควสต์ → โหลดเร็วขึ้น
   ========================================================= */
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

require __DIR__ . '/db_connect.php'; // ต้องมี $pdo (PDO)

if (!isset($_SESSION['student_id'])) { header('Location: login.php'); exit; }
$STUDENT_ID = (int)$_SESSION['student_id'];

/* ---------- Helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function goto_q($qid){ header('Location: quiz.php?qid='.(int)$qid); exit; }
function ans($qid,$bag){ return isset($bag[$qid]) ? (int)$bag[$qid] : -1; }

/* ---------- QMAP: แมปลำดับตรรกะ 1..33 -> question_id จริงใน DB ---------- */
/* อ้างอิงไฟล์ dump: ตาราง questions เริ่ม question_id ที่ 101.. (ไม่ใช่ 1..)  :contentReference[oaicite:1]{index=1} */
function build_qmap(PDO $pdo): array {
  // ปรับเกณฑ์การเรียงตามที่ฐานข้อมูลคุณวางไว้: ที่นี่ใช้ question_id ASC และเอา 33 แถวแรก
  $rows = $pdo->query("SELECT question_id FROM questions ORDER BY question_id ASC LIMIT 33")->fetchAll(PDO::FETCH_COLUMN, 0);
  $map = [];
  $i=1;
  foreach ($rows as $qid_real) { $map[$i++] = (int)$qid_real; }
  return $map;
}
if (empty($_SESSION['QMAP']) || !is_array($_SESSION['QMAP'])) {
  $_SESSION['QMAP'] = build_qmap($pdo);
}
/* ======================================================================== */

/* =========================================================
   SCHEMA (สร้างให้ถูกต้องตั้งแต่แรก + ดัชนีที่ใช้จริง)
   ========================================================= */
$pdo->exec("
CREATE TABLE IF NOT EXISTS student_quiz_status (
  id INT NOT NULL AUTO_INCREMENT,
  student_id INT NOT NULL UNIQUE,
  quiz_attempts INT NOT NULL DEFAULT 0,
  admin_override_attempts INT NOT NULL DEFAULT 0,
  academic_status ENUM('active','graduated','leave','suspended') NOT NULL DEFAULT 'active',
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS quiz_results (
  result_id INT NOT NULL AUTO_INCREMENT,
  student_id INT NOT NULL,
  recommend_group_id INT DEFAULT NULL,
  completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (result_id),
  KEY idx_qr_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS quiz_answers (
  answer_id INT NOT NULL AUTO_INCREMENT,
  result_id INT NOT NULL,
  question_id INT NOT NULL,
  answer_value TINYINT(1) NOT NULL,
  PRIMARY KEY (answer_id),
  KEY idx_result (result_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS test_history (
  id INT NOT NULL AUTO_INCREMENT,
  username VARCHAR(255) NOT NULL,
  recommended_group VARCHAR(255),
  recommended_subjects TEXT,
  no_count INT DEFAULT 0,
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_time (username, `timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ===== ensure row for this student ===== */
$ins = $pdo->prepare("INSERT IGNORE INTO student_quiz_status (student_id) VALUES (?)");
$ins->execute([$STUDENT_ID]);

/* =========================================================
   POLICY
   ========================================================= */
function compute_policy(PDO $pdo, int $student_id): array {
  $st = $pdo->prepare("SELECT admin_override_attempts, academic_status FROM student_quiz_status WHERE student_id=?");
  $st->execute([$student_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['admin_override_attempts'=>0,'academic_status'=>'active'];
  $override = (int)($row['admin_override_attempts'] ?? 0);
  $status   = (string)($row['academic_status'] ?? 'active');
  $base     = 3;
  $max      = max(0, $base + $override);

  $c = $pdo->prepare("SELECT COUNT(*) FROM quiz_results WHERE student_id=?");
  $c->execute([$student_id]);
  $used = (int)$c->fetchColumn();

  return ['base'=>$base,'override'=>$override,'max'=>$max,'used'=>$used,'status'=>$status,'can'=>($status!=='suspended' && $used<$max)];
}
$policy = compute_policy($pdo, $STUDENT_ID);

/* =========================================================
   OPTIONAL: seed คำถามแบบเบา ๆ (เรียกแค่เมื่ออยาก seed)
   ใช้: quiz.php?seed=1 และเตรียม $QUESTIONS = [qid => [text, order, group_id|null], ...]
   ========================================================= */
if (isset($_GET['seed']) && (int)$_GET['seed']===1 && isset($QUESTIONS) && is_array($QUESTIONS)) {
  $pdo->exec("ALTER TABLE `questions` ADD COLUMN question_id INT NOT NULL UNIQUE");
  try {} catch (Throwable $e) {}

  $sql = "INSERT INTO `questions` (question_id, question_text, order_in_group, group_id)
          VALUES (?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE question_text=VALUES(question_text), order_in_group=VALUES(order_in_group), group_id=VALUES(group_id)";
  $st = $pdo->prepare($sql);

  $pdo->beginTransaction();
  foreach ($QUESTIONS as $qid => [$text,$ord,$gid]) {
    $gid = ($gid===''?null:$gid);
    $st->execute([(int)$qid, (string)$text, (int)$ord, $gid]);
  }
  $pdo->commit();
  // รีบิลด์ QMAP หลัง seed
  $_SESSION['QMAP'] = build_qmap($pdo);
  header('Location: quiz.php'); exit;
}

/* =========================================================
   GUARDS
   ========================================================= */
function hard_block(string $reason, array $policy){
  http_response_code(403); ?>
  <!doctype html><html lang="th"><meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1"><title>ไม่อนุญาตให้ทำแบบทดสอบ</title>
  <div style="font-family:Sarabun,system-ui;background:#f3f4f6;min-height:100vh;display:flex;align-items:center;justify-content:center">
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:28px;max-width:640px;text-align:center;box-shadow:0 20px 40px rgba(0,0,0,.08)">
      <h2 style="margin:0 0 8px">ไม่สามารถทำแบบทดสอบได้</h2>
      <p style="color:#555;margin:0 0 10px"><?= h($reason) ?></p>
      <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
        <span style="background:#eef2ff;padding:6px 10px;border-radius:999px">สถานะ: <b><?= h($policy['status']) ?></b></span>
        <span style="background:#eef2ff;padding:6px 10px;border-radius:999px">ทำไปแล้ว: <b><?= (int)$policy['used'] ?></b></span>
        <span style="background:#eef2ff;padding:6px 10px;border-radius:999px">สิทธิ์สูงสุด: <b><?= (int)$policy['max'] ?></b></span>
      </div>
      <div style="margin-top:14px"><a href="student_dashboard.php">⬅ กลับหน้าแดชบอร์ด</a></div>
    </div>
  </div></html><?php
  exit;
}

if ($policy['status']==='suspended') hard_block('สถานะของคุณถูกระงับสิทธิ์ โปรดติดต่อผู้ดูแลระบบ', $policy);
if ($policy['used'] >= $policy['max']) hard_block('คุณทำครบจำนวนครั้งที่กำหนดแล้ว', $policy);

/* =========================================================
   SAVE RESULT (ไวขึ้น: ใช้ statement เดียว, transaction)
   ========================================================= */
function save_and_prepare_result(PDO $pdo, int $student_id, int $group_id): int {
  $no_count = 0;
  if (!empty($_SESSION['answers']) && is_array($_SESSION['answers'])) {
    foreach ($_SESSION['answers'] as $v) if ((int)$v === 0) $no_count++;
  }
  $_SESSION['final_result'] = ['recommend_group_id'=>$group_id,'no_count'=>$no_count];

  try {
    $pdo->beginTransaction();

    $stRes = $pdo->prepare("INSERT INTO quiz_results (student_id, recommend_group_id) VALUES (?,?)");
    $stRes->execute([$student_id, $group_id]);
    $result_id = (int)$pdo->lastInsertId();

    if (!empty($_SESSION['answers'])) {
      $stAns = $pdo->prepare("INSERT INTO quiz_answers (result_id, question_id, answer_value) VALUES (?,?,?)");
      // --- ใช้ QMAP แมปจากหมายเลขตรรกะ -> question_id จริงก่อนบันทึก ---
      $QMAP = $_SESSION['QMAP'] ?? [];
      foreach ($_SESSION['answers'] as $logical_qid => $v) {
        $qid_real = (int)($QMAP[$logical_qid] ?? $logical_qid);
        $stAns->execute([$result_id, $qid_real, (int)$v]);
      }
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die('เกิดข้อผิดพลาดในการบันทึกข้อมูล: '.$e->getMessage());
  }

  unset($_SESSION['answers']);
  return $group_id;
}

/* =========================================================
   MAIN BRANCHING - IMPLEMENTED NEW USER RULES (2025-09-28)
   ========================================================= */
$SHOW_START=false; $SHOW_QUIZ=false; $SHOW_RESULT=false;
$RESULT_GROUP=null; $RESULT_GROUP_NAME=null; $RESULT_SUBJECTS=[];

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $qid = isset($_POST['qid']) ? (int)$_POST['qid'] : 0;
  $val = isset($_POST['answer']) ? (int)$_POST['answer'] : -1;

  if (!isset($_SESSION['answers'])) $_SESSION['answers']=[];
  if ($qid>0 && ($val===0 || $val===1)) $_SESSION['answers'][$qid]=$val;
  $a = $_SESSION['answers'];

  /* Helper function for answer check (ans(qid, answers_array) == 1 (Yes) or 0 (No)) */
  function a($q, $val) { global $a; return ans($q, $a) == $val; }

  // Q1 & Q2: การแยกกลุ่มหลัก
  if ($qid == 1) { if (a(1,1)) goto_q(2); else goto_q(24); }
  if ($qid == 2) { if (a(2,1)) goto_q(3); else goto_q(14); }

  // Q3-Q11: การแยกทางของกลุ่ม 1 (ใช้ตรรกะเดิมที่ถูกต้อง)
  if ($qid>=3 && $qid<=11) {
    switch ($qid) {
      case 3:  (a(3,1)) ? goto_q(4)  : goto_q(5);  break;
      case 4:
      case 5:  (a($qid,1)) ? goto_q(6)  : goto_q(7);  break;
      case 6:
      case 7:  (a($qid,1)) ? goto_q(8)  : goto_q(9);  break;
      case 8:
      case 9:  (a($qid,1)) ? goto_q(10) : goto_q(11); break;
      case 10:
      case 11: (a($qid,1)) ? goto_q(12) : goto_q(13); break;
    }
  }

  // Q12: ตรรกะการแนะนำกลุ่ม 1 หรือถามต่อ Q14 (กลุ่ม 2)
  if ($qid==12) {
    // เงื่อนไข Ask Q14 (พบ 2 เส้นทางที่ Q12=N -> ถามต่อ)
    $ASK_Q14_Q12 = (
      // Path Q3=N, Q5=Y, Q6=N, Q9=N, Q11=Y, Q12=N
      (a(3,0) && a(5,1) && a(6,0) && a(9,0) && a(11,1) && a(12,0)) ||
      // Path Q3=N, Q5=N, Q7=N, Q9=N, Q11=Y, Q12=N
      (a(3,0) && a(5,0) && a(7,0) && a(9,0) && a(11,1) && a(12,0))
    );

    if ($ASK_Q14_Q12) {
        goto_q(14); // ถามต่อ กลุ่ม 2 ข้อ 14
    } else {
        // เงื่อนไขอื่นๆ ทั้งหมด (รวมถึงเส้นทาง G1 ที่ Q12=Y/N) -> แนะนำกลุ่ม 1
        $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 1);
        $SHOW_RESULT=true;
    }
  }

  // Q13: ตรรกะการแนะนำกลุ่ม 1 หรือถามต่อ Q14 (กลุ่ม 2)
  if (!$SHOW_RESULT && $qid==13) {
    // เงื่อนไข Ask Q14 (พบ 10 เส้นทางที่ Q13=N -> ถามต่อ)
    $ASK_Q14_Q13 = a(13,0) && (
        // E1: Q3=Y, Q4=Y, Q6=N, Q9=N, Q11=N
        (a(3,1) && a(4,1) && a(6,0) && a(9,0) && a(11,0)) ||
        // E2: Q3=Y, Q4=N, Q7=Y, Q8=N, Q11=N
        (a(3,1) && a(4,0) && a(7,1) && a(8,0) && a(11,0)) ||
        // E3: Q3=Y, Q4=N, Q7=N, Q9=Y, Q10=N
        (a(3,1) && a(4,0) && a(7,0) && a(9,1) && a(10,0)) ||
        // E4: Q3=Y, Q4=N, Q7=N, Q9=N, Q11=N
        (a(3,1) && a(4,0) && a(7,0) && a(9,0) && a(11,0)) ||
        // E5: Q3=N, Q5=Y, Q6=Y, Q8=N, Q11=N
        (a(3,0) && a(5,1) && a(6,1) && a(8,0) && a(11,0)) ||
        // E6: Q3=N, Q5=Y, Q6=N, Q9=Y, Q10=N
        (a(3,0) && a(5,1) && a(6,0) && a(9,1) && a(10,0)) ||
        // E7: Q3=N, Q5=N, Q7=Y, Q8=N, Q11=N
        (a(3,0) && a(5,0) && a(7,1) && a(8,0) && a(11,0)) ||
        // E8: Q3=N, Q5=N, Q7=N, Q9=Y, Q10=N
        (a(3,0) && a(5,0) && a(7,0) && a(9,1) && a(10,0)) ||
        // E9: Q3=N, Q5=N, Q7=N, Q9=N, Q11=N
        (a(3,0) && a(5,0) && a(7,0) && a(9,0) && a(11,0)) ||
        // E10: Q3=Y, Q4=Y, Q6=Y, Q8=N, Q11=N
        (a(3,1) && a(4,1) && a(6,1) && a(8,0) && a(11,0)) // ตรวจสอบจาก Q3=Y, 4=Y, 6=Y, 8=N (ถึง Q11=N)
    );

    if ($ASK_Q14_Q13) {
      goto_q(14); // ถามต่อ กลุ่ม 2 ข้อ 14
    } else {
      // เงื่อนไขอื่นๆ ทั้งหมด -> แนะนำกลุ่ม 1
      $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 1);
      $SHOW_RESULT=true;
    }
  }

  // Q14: ตัวแยกทางสุดท้ายของกลุ่ม 1 ก่อนเข้ากลุ่ม 2 หรือ 3
  if (!$SHOW_RESULT && $qid==14) {
    // Q14=N จะวนกลับไป Q10 หรือ Q24, Q14=Y ไป Q15 (ตรรกะนี้ซับซ้อนตามที่ผู้ใช้ระบุ)
    if (a(14,0)) {
        // 14=N: เช็คเงื่อนไขวนกลับไป Q10/Q12/Q13 หรือไป Q24 (กลุ่ม 3)
        // เนื่องจากผู้ใช้ระบุเงื่อนไขซับซ้อนที่ Q14=N -> ไป Q10/Q12/Q13 ฉันจะยึดตรรกะที่วนกลับไป Q10/Q12/Q13 ที่ผู้ใช้กำหนดมา
        
        // (ตัวอย่างเงื่อนไขที่ผู้ใช้ระบุให้วนกลับไป Q10, Q12, Q13 เมื่อ 14=N)
        $ASK_Q10_Q12_Q13 = (
          (a(3,1) && a(4,1) && a(6,0) && a(9,0) && a(11,0) && a(13,0)) || // 14=N -> 10, 12, 13
          (a(3,1) && a(4,0) && a(7,1) && a(8,0) && a(11,0) && a(13,0))    // 14=N -> 10, 12, 13
        );

        if ($ASK_Q10_Q12_Q13) {
             // เนื่องจากไม่มีการระบุชัดเจนว่ากลับไปที่ไหนระหว่าง 10, 12, 13 ฉันจะส่งกลับไป Q12 (จุดตัดสินใจถัดไป)
             goto_q(12);
        } else {
             goto_q(24); // เงื่อนไขอื่น ๆ (เช่น 14=N จากกลุ่ม 3) ไป Q24 (กลุ่ม 3)
        }
    } else {
      goto_q(15); // Q14=Y ไป Q15 (กลุ่ม 2)
    }
  }

  // Q15-Q21: การแยกทางของกลุ่ม 2 (ใช้ตรรกะเดิมที่ถูกต้อง)
  if (!$SHOW_RESULT && $qid>=15 && $qid<=21) {
    switch ($qid) {
      case 15: (a(15,1)) ? goto_q(16) : goto_q(17); break;
      case 16:
      case 17: (a($qid,1)) ? goto_q(18) : goto_q(19); break;
      case 18:
      case 19: (a($qid,1)) ? goto_q(20) : goto_q(21); break;
      case 20:
      case 21: (a($qid,1)) ? goto_q(22) : goto_q(23); break;
    }
  }

  // Q22: ตรรกะการแนะนำกลุ่ม 2 หรือถามต่อ Q24 (กลุ่ม 3)
  if (!$SHOW_RESULT && $qid==22) {
    // เงื่อนไข Ask Q24 (พบ 4 เส้นทางที่ Q22=N -> ถามต่อ Q24)
    $ASK_Q24_Q22 = a(22,0) && (
      // Q15=Y, Q16=N, Q19=N, Q21=Y, Q22=N
      (a(15,1) && a(16,0) && a(19,0) && a(21,1)) ||
      // Q15=N, Q17=Y, Q18=N, Q21=Y, Q22=N
      (a(15,0) && a(17,1) && a(18,0) && a(21,1)) ||
      // Q15=N, Q17=N, Q19=Y, Q20=Y, Q22=N
      (a(15,0) && a(17,0) && a(19,1) && a(20,1)) ||
      // Q15=N, Q17=N, Q19=N, Q21=Y, Q22=N
      (a(15,0) && a(17,0) && a(19,0) && a(21,1))
    );

    if ($ASK_Q24_Q22) {
      goto_q(24); // ถามต่อ กลุ่ม 3 ข้อ 24
    } else {
      // เงื่อนไขอื่นๆ ทั้งหมด -> แนะนำกลุ่ม 2
      $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 2);
      $SHOW_RESULT=true;
    }
  }

  // Q23: ตรรกะการแนะนำกลุ่ม 2 หรือถามต่อ Q24 (กลุ่ม 3)
  if (!$SHOW_RESULT && $qid==23) {
    // เงื่อนไข Ask Q24 (พบ 8 เส้นทางที่ Q23=N/Y -> ถามต่อ Q24)
    $ASK_Q24_Q23_Y = a(23,1) && (
      // Q15=N, Q17=N, Q19=Y, Q20=N, Q23=Y
      (a(15,0) && a(17,0) && a(19,1) && a(20,0)) ||
      // Q15=N, Q17=N, Q19=N, Q21=N, Q23=Y
      (a(15,0) && a(17,0) && a(19,0) && a(21,0))
    );

    $ASK_Q24_Q23_N = a(23,0) && (
      // Q15=Y, Q16=N, Q19=N, Q21=N, Q23=N
      (a(15,1) && a(16,0) && a(19,0) && a(21,0)) ||
      // Q15=N, Q17=Y, Q18=N, Q21=N, Q23=N
      (a(15,0) && a(17,1) && a(18,0) && a(21,0)) ||
      // Q15=N, Q17=N, Q19=Y, Q20=N, Q23=N
      (a(15,0) && a(17,0) && a(19,1) && a(20,0)) ||
      // Q15=N, Q17=N, Q19=N, Q21=Y, Q22=Y (เส้นทาง Q22 ที่ไป Q23 ไม่ได้)
      (a(15,0) && a(17,0) && a(19,0) && a(21,1)) ||
      // Q15=N, Q17=N, Q19=N, Q21=N, Q23=N
      (a(15,0) && a(17,0) && a(19,0) && a(21,0))
    );

    if ($ASK_Q24_Q23_Y || $ASK_Q24_Q23_N) {
      goto_q(24); // ถามต่อ กลุ่ม 3 ข้อ 24
    } else {
      // เงื่อนไขอื่นๆ ทั้งหมด -> แนะนำกลุ่ม 2
      $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 2);
      $SHOW_RESULT=true;
    }
  }

  // Q24: เป็นจุดเข้าสู่กลุ่ม 3 เสมอ
  if (!$SHOW_RESULT && $qid==24) { goto_q(25); }

  // Q25-Q31: การแยกทางของกลุ่ม 3 (ใช้ตรรกะเดิมที่ถูกต้อง)
  if (!$SHOW_RESULT && $qid>=25 && $qid<=31) {
    switch ($qid) {
      case 25: (a(25,1)) ? goto_q(26) : goto_q(27); break;
      case 26:
      case 27: (a($qid,1)) ? goto_q(28) : goto_q(29); break;
      case 28:
      case 29: (a($qid,1)) ? goto_q(30) : goto_q(31); break;
      case 30:
      case 31: (a($qid,1)) ? goto_q(32) : goto_q(33); break;
    }
  }

  // Q32: ตรรกะการแนะนำกลุ่ม 3 หรือถามต่อ Q2 (กลุ่ม 1)
  if (!$SHOW_RESULT && $qid==32) {
    // เงื่อนไข Ask Q14 (พบ 4 เส้นทางที่ Q32=N -> ถามต่อ Q14)
    $ASK_Q14_Q32 = a(32,0) && (
      // Q25=Y, Q26=Y, Q28=N, Q31=Y, Q32=N
      (a(25,1) && a(26,1) && a(28,0) && a(31,1)) ||
      // Q25=Y, Q26=N, Q29=N, Q31=Y, Q32=N
      (a(25,1) && a(26,0) && a(29,0) && a(31,1)) ||
      // Q25=N, Q27=Y, Q28=N, Q31=Y, Q32=N
      (a(25,0) && a(27,1) && a(28,0) && a(31,1)) ||
      // Q25=N, Q27=N, Q29=Y, Q30=Y, Q32=N
      (a(25,0) && a(27,0) && a(29,1) && a(30,1))
    );

    if ($ASK_Q14_Q32) {
        goto_q(14); // ถามต่อ กลุ่ม 2 ข้อ 14 (จุดเริ่มต้นของ Group 2)
    } else {
        // เงื่อนไขอื่นๆ ทั้งหมด -> แนะนำกลุ่ม 3
        $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 3);
        $SHOW_RESULT=true;
    }
  }

// Q32: ตรรกะการแนะนำกลุ่ม 3 หรือถามต่อ Q2 (กลุ่ม 1)
  if (!$SHOW_RESULT && $qid==32) {
    // เงื่อนไข Ask Q2 (พบ 4 เส้นทางที่ Q32=N -> ถามต่อ Q2)
    $ASK_Q2_Q32 = a(32,0) && (
      // Q25=Y, Q26=Y, Q28=N, Q31=Y, Q32=N
      (a(25,1) && a(26,1) && a(28,0) && a(31,1)) ||
      // Q25=Y, Q26=N, Q29=N, Q31=Y, Q32=N
      (a(25,1) && a(26,0) && a(29,0) && a(31,1)) ||
      // Q25=N, Q27=Y, Q28=N, Q31=Y, Q32=N
      (a(25,0) && a(27,1) && a(28,0) && a(31,1)) ||
      // Q25=N, Q27=N, Q29=Y, Q30=Y, Q32=N
      (a(25,0) && a(27,0) && a(29,1) && a(30,1))
    );

    if ($ASK_Q2_Q32) {
        goto_q(2); // ถามต่อ กลุ่ม 1 ข้อ 2 (แก้ไขจาก Q14)
    } else {
        // เงื่อนไขอื่นๆ ทั้งหมด -> แนะนำกลุ่ม 3
        $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 3);
        $SHOW_RESULT=true;
    }
  }

  // Q33: ตรรกะการแนะนำกลุ่ม 3 หรือถามต่อ Q2 (กลุ่ม 1)
  if (!$SHOW_RESULT && $qid==33) {
    // เงื่อนไข Ask Q2 (พบ 8 เส้นทางที่ Q33=N/Y -> ถามต่อ Q2)
    $ASK_Q2_Q33 = (
      // Q33=Y -> ถามต่อ (2 เส้นทาง)
      a(33,1) && (
        (a(25,1) && a(26,0) && a(29,0) && a(31,0)) || // Q25=Y, Q26=N, Q29=N, Q31=N, Q33=Y
        (a(25,0) && a(27,0) && a(29,0) && a(31,0))    // Q25=N, Q27=N, Q29=N, Q31=N, Q33=Y (รวมถึงกรณี N ทั้งหมดตามที่คุณระบุ)
      )
    ) || (
      // Q33=N -> ถามต่อ (6 เส้นทาง)
      a(33,0) && (
        (a(25,1) && a(26,1) && a(28,0) && a(31,0)) ||
        (a(25,1) && a(26,0) && a(29,1) && a(30,0)) ||
        (a(25,1) && a(26,0) && a(29,0) && a(31,0)) ||
        (a(25,0) && a(27,1) && a(28,0) && a(31,0)) ||
        (a(25,0) && a(27,0) && a(29,1) && a(30,0)) ||
        (a(25,0) && a(27,0) && a(29,0) && a(31,0))
      )
    );

    if ($ASK_Q2_Q33) {
      goto_q(2); // ถามต่อ กลุ่ม 1 ข้อ 2 (แก้ไขจาก Q14)
    } else {
      // เงื่อนไขอื่นๆ ทั้งหมด -> แนะนำกลุ่ม 3
      $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 3);
      $SHOW_RESULT=true;
    }
  }

  if (!$SHOW_RESULT) goto_q(2);
}
// ... ส่วนแสดงผลลัพธ์และ HTML อื่น ๆ
/* =========================================================
   DISPLAY (ดึงชื่อกลุ่ม + รายวิชาที่แนะนำ)
   ========================================================= */
$question = null;

if ($SHOW_RESULT) {
  // group name
  $RESULT_GROUP_NAME = null;
  if ($RESULT_GROUP>0) {
    try {
      $hasSG = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='subject_groups'")->fetchColumn() > 0;
      if ($hasSG) {
        $nameCol = 'group_name';
        $idCol   = 'group_id';
        $cols = $pdo->query("SHOW COLUMNS FROM subject_groups")->fetchAll(PDO::FETCH_COLUMN,0);
        if ($cols) {
          if (!in_array('group_name',$cols,true)) { foreach(['name','title','label'] as $c){ if(in_array($c,$cols,true)){ $nameCol=$c; break; } } }
          if (!in_array('group_id',$cols,true))   { foreach(['id'] as $c){ if(in_array($c,$cols,true)){ $idCol=$c; break; } } }
        }
        // พยายามแมพตรง id ก่อน ไม่เจอใช้ลำดับ
        $st = $pdo->prepare("SELECT `$nameCol` FROM subject_groups WHERE `$idCol`=? LIMIT 1");
        $st->execute([$RESULT_GROUP]);
        $RESULT_GROUP_NAME = $st->fetchColumn();
        if (!$RESULT_GROUP_NAME) {
          $st = $pdo->query("SELECT `$nameCol` FROM subject_groups ORDER BY `$idCol` ASC");
          $rows = $st->fetchAll(PDO::FETCH_COLUMN,0);
          if ($rows) { $idx = max(0,min(count($rows)-1,$RESULT_GROUP-1)); $RESULT_GROUP_NAME = $rows[$idx]; }
        }
      }
    } catch (Throwable $e) {}
  }
  if (!$RESULT_GROUP_NAME) $RESULT_GROUP_NAME = 'กลุ่มที่ '.(int)$RESULT_GROUP;

  // subjects
  $RESULT_SUBJECTS = [];
  try {
    $hasSub = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='subjects'")->fetchColumn() > 0;
    if ($hasSub) {
      $cols = $pdo->query("SHOW COLUMNS FROM subjects")->fetchAll(PDO::FETCH_COLUMN,0);
      $map = [
        'group_id' => ['group_id','subject_group_id','grp_id'],
        'code'     => ['subject_code','course_code','code'],
        'name'     => ['subject_name','course_name','name','title'],
        'credits'  => ['credits','credit','unit','units'],
        'year'     => ['recommended_year','year','year_recommended'],
        'prereq'   => ['prereq_text','prerequisite','prereq'],
      ];
      $use = [];
      foreach ($map as $k=>$cands) { foreach ($cands as $c) if (in_array($c,$cols,true)) { $use[$k]=$c; break; } }
      $select = [];
      if (isset($use['code']))    $select[] = "`{$use['code']}` AS course_code";
      if (isset($use['name']))    $select[] = "`{$use['name']}` AS course_name";
      if (isset($use['credits'])) $select[] = "`{$use['credits']}` AS credits";
      if (isset($use['year']))    $select[] = "`{$use['year']}` AS recommended_year";
      if (isset($use['prereq']))  $select[] = "`{$use['prereq']}` AS prereq_text";
      if ($select) {
        $order = [];
        if (isset($use['year'])) $order[]="`{$use['year']}` IS NULL, `{$use['year']}`";
        if (isset($use['name'])) $order[]="`{$use['name']}`";
        $orderSql = $order ? " ORDER BY ".implode(',', $order) : "";

        if (isset($use['group_id'])) {
          $st = $pdo->prepare("SELECT ".implode(',',$select)." FROM subjects WHERE `{$use['group_id']}`=?".$orderSql);
          $st->execute([$RESULT_GROUP]);
          $RESULT_SUBJECTS = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        if (!$RESULT_SUBJECTS) {
          $st = $pdo->query("SELECT ".implode(',',$select)." FROM subjects".$orderSql." LIMIT 12");
          $RESULT_SUBJECTS = $st->fetchAll(PDO::FETCH_ASSOC);
        }
      }
    }
  } catch (Throwable $e) {}
}
elseif (isset($_GET['qid'])) {
  $qid = max(1,(int)$_GET['qid']);                 // <- หมายเลข "ตรรกะ" 1..33
  $QMAP = $_SESSION['QMAP'] ?? [];
  $qid_real = (int)($QMAP[$qid] ?? 0);              // แมปไป question_id จริงใน DB

  if ($qid_real <= 0) {
    die("ไม่พบคำถาม ID: ".h($qid));
  }

  // ดึงด้วย question_id จริง แต่ตั้งชื่อเป็น question_id เพื่อให้ส่วน template เดิมใช้ได้
  $st = $pdo->prepare("SELECT question_id, question_text FROM questions WHERE question_id=? LIMIT 1");
  $st->execute([$qid_real]);
  $question = $st->fetch(PDO::FETCH_ASSOC);

  if (!$question) die("ไม่พบคำถาม ID: ".h($qid));
  $CURRENT_LOGICAL_QID = $qid; // ส่งต่อไป hidden input
  $SHOW_QUIZ=true;
} else {
  unset($_SESSION['answers'], $_SESSION['final_result'], $_SESSION['final_result_saved']);
  $SHOW_START=true;
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>แบบทดสอบแนะนำรายวิชาชีพเลือก</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--g1:linear-gradient(135deg,#667eea 0%,#764ba2 100%);--g2:linear-gradient(135deg,#4facfe 0%,#00f2fe 100%);--g3:linear-gradient(135deg,#fa709a 0%,#fee140 100%);--r:20px;--r2:12px}
body{font-family:'Sarabun',sans-serif;background:var(--g1);min-height:100vh;padding:20px;line-height:1.6}
.container{max-width:900px;margin:0 auto}
.card{background:rgba(255,255,255,.95);backdrop-filter:blur(14px);border-radius:var(--r);padding:36px;border:1px solid rgba(255,255,255,.2);box-shadow:0 16px 40px rgba(0,0,0,.1)}
.header{text-align:center;margin-bottom:28px}
.title{font-size:2rem;font-weight:700;background:var(--g1);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.pills{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:10px}
.pill{background:#eef2ff;border:1px solid #e5e7eb;border-radius:999px;padding:8px 14px;font-weight:600}
.pill.warn{background:#fef3c7}
.btns{text-align:center;margin-top:18px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:14px 26px;border-radius:12px;border:0;cursor:pointer;text-decoration:none;margin:0 6px}
.btn-primary{background:var(--g1);color:#fff}
.btn-outline{background:#fff;border:2px solid #e5e7eb}
.btn-success{background:var(--g2);color:#fff}
.qtext{font-size:1.2rem;background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-left:5px solid #3b82f6;border-radius:12px;padding:18px;margin-bottom:20px}
.opts{display:flex;gap:16px;justify-content:center;flex-wrap:wrap;margin-bottom:20px}
.opt input{position:absolute;opacity:0}
.opt label{display:flex;align-items:center;gap:10px;padding:14px 24px;border:2px solid #e5e7eb;border-radius:12px;background:#fff;cursor:pointer}
.opt input:checked+label{background:var(--g1);color:#fff;border-color:#3b82f6}
.result-title{font-size:1.8rem;font-weight:700;margin-bottom:10px;text-align:center}
.result-group{display:inline-block;background:var(--g2);color:#fff;border-radius:24px;padding:12px 20px;margin:10px 0}
.subjects{background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-radius:12px;padding:20px;margin:18px 0}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;list-style:none}
.item{background:#fff;border-left:4px solid #3b82f6;border-radius:8px;padding:10px}
@media (max-width:768px){.card{padding:24px}}
</style>
</head>
<body>
<div class="container"><div class="card">

<?php if ($SHOW_START): ?>
  <div class="header">
    <div class="title"><i class="fas fa-clipboard-list"></i> แบบทดสอบแนะนำรายวิชาชีพเลือก</div>
    <div class="pills">
      <span class="pill">รหัส: <b><?= h($STUDENT_ID) ?></b></span>
      <span class="pill <?= ($policy['used']>0?'warn':'') ?>">ทำไปแล้ว <b><?= (int)$policy['used'] ?></b> ครั้ง</span>
      <span class="pill">สิทธิ์สูงสุด <b><?= (int)$policy['max'] ?></b> ครั้ง</span>
      <span class="pill">สถานะ <b><?= h($policy['status']) ?></b></span>
    </div>
  </div>
  <div class="btns">
    <?php if ($policy['can']): ?>
      <a class="btn btn-primary" href="quiz.php?qid=1"><i class="fas fa-play"></i> เริ่มทำแบบทดสอบ</a>
      <a class="btn btn-outline" href="student_dashboard.php">ยกเลิก</a>
    <?php else: ?>
      <div style="color:#b91c1c;background:#fee2e2;border:1px solid #fecaca;border-radius:12px;padding:14px;margin-bottom:10px">
        <b>ไม่สามารถเริ่มทำได้</b> — <?= ($policy['status']==='suspended')?'สถานะถูกระงับสิทธิ์ โปรดติดต่อผู้ดูแลระบบ':'คุณทำครบจำนวนครั้งที่กำหนดแล้ว' ?>
      </div>
      <a class="btn btn-outline" href="student_dashboard.php"><i class="fas fa-arrow-left"></i> กลับแดชบอร์ด</a>
    <?php endif; ?>
  </div>

<?php elseif ($SHOW_QUIZ): ?>
  <div class="qtext"><?= h($question['question_text']) ?></div>
  <form method="post" id="f">
    <!-- ส่งหมายเลข "ตรรกะ" กลับไปให้ลอจิกแตกแขนงเดิมทำงานได้ -->
    <input type="hidden" name="qid" value="<?= (int)($CURRENT_LOGICAL_QID ?? $question['question_id']) ?>">
    <div class="opts">
      <div class="opt"><input id="y" type="radio" name="answer" value="1" required><label for="y"><i class="fas fa-check" style="color:#10b981"></i> ใช่</label></div>
      <div class="opt"><input id="n" type="radio" name="answer" value="0"><label for="n"><i class="fas fa-times" style="color:#f59e0b"></i> ไม่ใช่</label></div>
    </div>
    <div class="btns">
      <button class="btn btn-primary" type="submit"><i class="fas fa-arrow-right"></i> ข้อต่อไป</button>
      <a class="btn btn-outline" href="student_dashboard.php" onclick="return confirm('ยกเลิกการทำแบบทดสอบ?')">ยกเลิก</a>
    </div>
  </form>

<?php elseif ($SHOW_RESULT): ?>
  <div class="result-title"><i class="fas fa-trophy"></i> ผลลัพธ์ของคุณ</div>
  <div style="text-align:center"><span class="result-group">กลุ่มที่แนะนำ: <b><?= h($RESULT_GROUP_NAME) ?></b></span></div>

  <?php if (!empty($RESULT_SUBJECTS)): ?>
    <div class="subjects">
      <div style="text-align:center;margin-bottom:10px"><i class="fas fa-book"></i> รายวิชาที่แนะนำ</div>
      <ul class="grid">
        <?php foreach ($RESULT_SUBJECTS as $r): ?>
          <li class="item">
            <div style="font-weight:600"><?= h($r['course_name'] ?? '-') ?></div>
            <div style="font-size:13px;color:#555">
              รหัส: <?= h($r['course_code'] ?? '-') ?> |
              หน่วยกิต: <?= h($r['credits'] ?? '-') ?> |
              ปีที่แนะนำ: <?= h($r['recommended_year'] ?? '-') ?><br>
              <?php if (!empty($r['prereq_text'])): ?>วิชาบังคับก่อน: <?= h($r['prereq_text']) ?><?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php
  // บันทึกลง test_history (หนึ่งครั้งต่อผลลัพธ์)
  if (empty($_SESSION['final_result_saved']) && !empty($_SESSION['final_result'])) {
    $no_count = (int)$_SESSION['final_result']['no_count'] ?? 0;
    $names = array_map(fn($x)=>$x['course_name']??'', $RESULT_SUBJECTS);
    $subjects_text = implode("\n", array_filter($names));
    $grp = $RESULT_GROUP_NAME ?: ('กลุ่มที่ '.(int)$RESULT_GROUP);
    try {
      $stmt = $pdo->prepare("INSERT INTO test_history (username, recommended_group, recommended_subjects, no_count) VALUES (?,?,?,?)");
      $stmt->execute([(string)$STUDENT_ID, $grp, $subjects_text, $no_count]);
      $_SESSION['final_result_saved']=true;
    } catch (Throwable $e) {}
  }
  ?>

  <div class="btns">
    <a class="btn btn-success" href="quiz.php"><i class="fas fa-redo"></i> ทำแบบทดสอบใหม่</a>
    <a class="btn btn-outline" href="student_dashboard.php"><i class="fas fa-home"></i> กลับแดชบอร์ด</a>
  </div>

<?php endif; ?>

</div></div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const f = document.getElementById('f');
  if (!f) return;
  f.addEventListener('submit', () => {
    const btn = f.querySelector('button[type="submit"]');
    if (btn){ btn.textContent = 'กำลังประมวลผล...'; btn.disabled = true; }
  }, {once:true});
});
</script>
</body>
</html>
