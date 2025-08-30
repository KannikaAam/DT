<?php
/* =========================================================
   quiz.php — หน้าเดียวจบ: เริ่มทำข้อสอบ + ทำทีละข้อ + บันทึกผล + แสดงผล
   ใช้ตาราง: groups, subjects, questions, quiz_results, quiz_answers (ฐาน projact2)
   ========================================================= */
session_start();
require __DIR__ . '/db_connect.php'; // <-- ปรับชื่อให้ตรงไฟล์เชื่อม DB ของคุณ

// ===== ตรวจล็อกอินนักศึกษา =====
if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}
$STUDENT_ID = (int)$_SESSION['student_id'];

// ===== โหมดการแสดงผล =====
$SHOW_START   = false;
$SHOW_QUIZ    = false;
$SHOW_RESULT  = false;
$RESULT_GROUP = null;
$RESULT_GROUP_NAME = null;

// ===== Helper =====
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function goto_q($qid){ header('Location: quiz.php?qid='.(int)$qid); exit; }
function ans($qid, $bag){ return isset($bag[$qid]) ? (int)$bag[$qid] : -1; } // -1 ยังไม่ตอบ

// ===== Auto-migrate กันพัง (สร้างตารางผลลัพธ์/คำตอบถ้ายังไม่มี) =====
$pdo->exec("
CREATE TABLE IF NOT EXISTS quiz_results (
  result_id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  recommend_group_id INT DEFAULT NULL,
  completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS quiz_answers (
  answer_id INT AUTO_INCREMENT PRIMARY KEY,
  result_id INT NOT NULL,
  question_id INT NOT NULL,
  answer_value TINYINT(1) NOT NULL,
  KEY idx_result (result_id),
  CONSTRAINT qa_fk_result FOREIGN KEY (result_id) REFERENCES quiz_results(result_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ===== ฟังก์ชันบันทึกผล แล้ว “เตรียม” ให้โชว์ผลในหน้านี้เลย =====
function save_and_prepare_result(PDO $pdo, int $student_id, int $group_id): int {
    $_SESSION['final_result'] = ['recommend_group_id' => $group_id];

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO quiz_results (student_id, recommend_group_id) VALUES (?, ?)");
        $stmt->execute([$student_id, $group_id]);
        $result_id = (int)$pdo->lastInsertId();

        if (!empty($_SESSION['answers']) && is_array($_SESSION['answers'])) {
            $ins = $pdo->prepare("INSERT INTO quiz_answers (result_id, question_id, answer_value) VALUES (?,?,?)");
            foreach ($_SESSION['answers'] as $qid => $v) {
                $ins->execute([$result_id, (int)$qid, (int)$v]);
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        die('เกิดข้อผิดพลาดในการบันทึกข้อมูล: '.$e->getMessage());
    }

    // เคลียร์คำตอบระหว่างทำ แต่เก็บผลไว้ใน session
    unset($_SESSION['answers']);
    return $group_id;
}

/* =========================================================
   PART 1: PROCESSING (รับคำตอบ/ตัดสินใจทางเดิน)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qid = isset($_POST['qid']) ? (int)$_POST['qid'] : 0;
    $val = isset($_POST['answer']) ? (int)$_POST['answer'] : -1; // 1=ใช่ 0=ไม่ใช่

    if (!isset($_SESSION['answers'])) $_SESSION['answers'] = [];
    if ($qid > 0 && ($val === 0 || $val === 1)) $_SESSION['answers'][$qid] = $val;
    $a = $_SESSION['answers'];

    // ===== ตรรกะ decision tree (เหมือนของคุณ) =====

    // ทางแยกหลัก
    if ($qid == 1) { if (ans(1,$a)==1) goto_q(2); else goto_q(24); }
    if ($qid == 2) { if (ans(2,$a)==1) goto_q(3); else goto_q(14); }

    // กลุ่ม 1: 3–11
    if ($qid >= 3 && $qid <= 11) {
        switch ($qid) {
            case 3:  (ans(3,$a)==1) ? goto_q(4)  : goto_q(5);  break;
            case 4:
            case 5:  (ans($qid,$a)==1) ? goto_q(6)  : goto_q(7);  break;
            case 6:
            case 7:  (ans($qid,$a)==1) ? goto_q(8)  : goto_q(9);  break;
            case 8:
            case 9:  (ans($qid,$a)==1) ? goto_q(10) : goto_q(11); break;
            case 10:
            case 11: (ans($qid,$a)==1) ? goto_q(12) : goto_q(13); break;
        }
    }

    // ข้อ 12
    if ($qid == 12) {
        $E = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==0 && ans(7,$a)==0 && ans(9,$a)==0 && ans(11,$a)==1);
        $P = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==1 && ans(6,$a)==0 && ans(9,$a)==0 && ans(11,$a)==1);

        if (($E || $P) && ans(12,$a)==1) {
            goto_q(14);
        } else {
            $A = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==1 && ans(4,$a)==0 && ans(7,$a)==0 && ans(9,$a)==0 && ans(11,$a)==1);
            $B = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==1 && ans(6,$a)==0 && ans(9,$a)==0 && ans(11,$a)==1);
            $C = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==0 && ans(7,$a)==1 && ans(8,$a)==0 && ans(11,$a)==1);
            $D = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==0 && ans(7,$a)==0 && ans(9,$a)==1 && ans(10,$a)==1);

            if (($A||$B||$C||$D||$E) && ans(12,$a)==0) {
                goto_q(14);
            } else {
                $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 1);
                $SHOW_RESULT = true;
            }
        }
    }

    // ข้อ 13
    if (!$SHOW_RESULT && $qid == 13) {
        $Always1 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==1 && ans(4,$a)==0 && ans(7,$a)==0 && ans(9,$a)==0 && ans(11,$a)==0);
        $Always2 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==0 && ans(7,$a)==1 && ans(8,$a)==0 && ans(11,$a)==0);
        $Always3 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==0 && ans(7,$a)==0 && ans(9,$a)==1 && ans(10,$a)==0);
        $Always4 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==0 && ans(7,$a)==0 && ans(9,$a)==0 && ans(11,$a)==0);

        if ($Always1 || $Always2 || $Always3 || $Always4) {
            goto_q(14);
        } else {
            $OnNo1 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==1 && ans(4,$a)==1 && ans(6,$a)==0 && ans(9,$a)==0 && ans(11,$a)==0);
            $OnNo2 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==1 && ans(4,$a)==0 && ans(7,$a)==1 && ans(8,$a)==0 && ans(11,$a)==0);
            $OnNo3 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==1 && ans(6,$a)==1 && ans(8,$a)==0 && ans(11,$a)==0);
            $OnNo4 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==1 && ans(6,$a)==0 && ans(9,$a)==1 && ans(10,$a)==0);
            $OnNo5 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==1 && ans(6,$a)==0 && ans(9,$a)==0 && ans(11,$a)==0);
            $OnNo6 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==0 && ans(7,$a)==1 && ans(8,$a)==1 && ans(10,$a)==0);
            $OnNo7 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==0 && ans(7,$a)==0 && ans(9,$a)==1 && ans(10,$a)==0);

            if (($OnNo1||$OnNo2||$OnNo3||$OnNo4||$OnNo5||$OnNo6||$OnNo7) && ans(13,$a)==0) {
                goto_q(14);
            } else {
                $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 1);
                $SHOW_RESULT = true;
            }
        }
    }

    // กลุ่ม 2: 14–23
    if (!$SHOW_RESULT && $qid == 14) {
        $R1 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==1 && ans(4,$a)==1 && ans(6,$a)==0 && ans(9,$a)==0 && ans(11,$a)==0 && ans(13,$a)==0);
        $R2 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==1 && ans(4,$a)==0 && ans(7,$a)==1 && ans(8,$a)==0 && ans(11,$a)==0 && ans(13,$a)==0);
        if ($R1 || $R2) { (ans(14,$a)==0) ? goto_q(10) : goto_q(15); }
        else { (ans(14,$a)==1) ? goto_q(15) : goto_q(24); }
    }

    if (!$SHOW_RESULT && $qid >= 15 && $qid <= 21) {
        switch ($qid) {
            case 15: (ans(15,$a)==1) ? goto_q(16) : goto_q(17); break;
            case 16:
            case 17: (ans($qid,$a)==1) ? goto_q(18) : goto_q(19); break;
            case 18:
            case 19: (ans($qid,$a)==1) ? goto_q(20) : goto_q(21); break;
            case 20:
            case 21: (ans($qid,$a)==1) ? goto_q(22) : goto_q(23); break;
        }
    }

    if (!$SHOW_RESULT && $qid == 22) {
        $L = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==0 && ans(17,$a)==0 && ans(19,$a)==0 && ans(21,$a)==1);
        if ($L && ans(22,$a)==1) {
            goto_q(24);
        } else {
            $A = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==1 && ans(16,$a)==0 && ans(19,$a)==0 && ans(21,$a)==1);
            $B = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==0 && ans(17,$a)==1 && ans(18,$a)==0 && ans(21,$a)==1);
            $C = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==0 && ans(17,$a)==0 && ans(19,$a)==1 && ans(20,$a)==1);
            $D = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==0 && ans(17,$a)==0 && ans(19,$a)==0 && ans(21,$a)==1);
            if (($A||$B||$C||$D) && ans(22,$a)==0) {
                goto_q(24);
            } else {
                $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 2);
                $SHOW_RESULT = true;
            }
        }
    }

    if (!$SHOW_RESULT && $qid == 23) {
        $Y1 = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==1 && ans(16,$a)==0 && ans(19,$a)==0 && ans(21,$a)==0);
        $Y2 = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==0 && ans(17,$a)==1 && ans(18,$a)==0 && ans(21,$a)==0);
        $Y3 = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==0 && ans(17,$a)==0 && ans(19,$a)==1 && ans(20,$a)==0);
        $Y4 = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==0 && ans(17,$a)==0 && ans(19,$a)==0 && ans(21,$a)==0);

        if (($Y1||$Y2||$Y3||$Y4) && ans(23,$a)==1) {
            goto_q(24);
        } else {
            if (ans(23,$a)==1) { $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 2); $SHOW_RESULT = true; }
            else { goto_q(24); }
        }
    }

    // กลุ่ม 3: 24–33
    if (!$SHOW_RESULT && $qid == 24) { (ans(24,$a)==1) ? goto_q(25) : goto_q(14); }

    if (!$SHOW_RESULT && $qid >= 25 && $qid <= 31) {
        switch ($qid) {
            case 25: (ans(25,$a)==1) ? goto_q(26) : goto_q(27); break;
            case 26:
            case 27: (ans($qid,$a)==1) ? goto_q(28) : goto_q(29); break;
            case 28:
            case 29: (ans($qid,$a)==1) ? goto_q(30) : goto_q(31); break;
            case 30:
            case 31: (ans($qid,$a)==1) ? goto_q(32) : goto_q(33); break;
        }
    }

    if (!$SHOW_RESULT && $qid == 32) {
        $Always = (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==0 && ans(27,$a)==0 && ans(29,$a)==0 && ans(31,$a)==1);
        $NoA = (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==1 && ans(26,$a)==0 && ans(29,$a)==0 && ans(31,$a)==1);
        $NoB = (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==0 && ans(27,$a)==1 && ans(28,$a)==0 && ans(31,$a)==1);
        $NoC = (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==0 && ans(27,$a)==0 && ans(29,$a)==1 && ans(30,$a)==1);

        if ($Always) goto_q(14);
        elseif (($NoA||$NoB||$NoC) && ans(32,$a)==0) goto_q(14);
        else { $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 3); $SHOW_RESULT = true; }
    }

    if (!$SHOW_RESULT && $qid == 33) {
        $A =
          (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==0 && ans(27,$a)==0 && ans(29,$a)==0 && ans(31,$a)==0) ||
          (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==0 && ans(27,$a)==0 && ans(29,$a)==1 && ans(30,$a)==0) ||
          (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==0 && ans(27,$a)==1 && ans(28,$a)==0 && ans(31,$a)==0) ||
          (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==1 && ans(26,$a)==0 && ans(29,$a)==0 && ans(31,$a)==0);
        $Force = (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==1 && ans(26,$a)==1 && ans(28,$a)==1 && ans(30,$a)==0);
        $Back2 =
          (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==1 && ans(26,$a)==1 && ans(28,$a)==0 && ans(30,$a)==0) ||
          (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==1 && ans(26,$a)==1 && ans(28,$a)==0 && ans(31,$a)==0) ||
          (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==1 && ans(26,$a)==0 && ans(29,$a)==1 && ans(30,$a)==0) ||
          (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==1 && ans(26,$a)==0 && ans(29,$a)==0 && ans(31,$a)==0) ||
          (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==0 && ans(27,$a)==1 && ans(28,$a)==1 && ans(30,$a)==0) ||
          (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==0 && ans(27,$a)==1 && ans(28,$a)==0 && ans(31,$a)==0) ||
          (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==0 && ans(27,$a)==0 && ans(29,$a)==1 && ans(30,$a)==0) ||
          (ans(1,$a)==0 && ans(24,$a)==1 && ans(25,$a)==0 && ans(27,$a)==0 && ans(29,$a)==0 && ans(31,$a)==0);

        if ($Force) { $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 3); $SHOW_RESULT = true; }
        elseif ( ($A && ans(33,$a)==1) || ($Back2 && ans(33,$a)==0) ) { goto_q(14); }
        else { if (ans(33,$a)==1) { $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 3); $SHOW_RESULT = true; } else { goto_q(14); } }
    }

    // Fallback
    if (!$SHOW_RESULT) { $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 3); $SHOW_RESULT = true; }
}

/* =========================================================
   PART 2: DISPLAY (กำหนดโหมด)
   ========================================================= */
if ($SHOW_RESULT) {
    // ดึงชื่อกลุ่มจากตาราง groups (ไม่ใช่ subject_groups)
    try {
        $stmt = $pdo->prepare("SELECT group_name FROM `groups` WHERE group_id = ?");
        $stmt->execute([$RESULT_GROUP]);
        $RESULT_GROUP_NAME = $stmt->fetchColumn() ?: null;

        $stmt2 = $pdo->prepare("SELECT subject_name FROM subjects WHERE group_id = ? ORDER BY subject_name ASC");
        $stmt2->execute([$RESULT_GROUP]);
        $RESULT_SUBJECTS = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $RESULT_GROUP_NAME = null;
        $RESULT_SUBJECTS = [];
    }
}
elseif (isset($_GET['qid'])) {
    $current_qid = max(1, (int)$_GET['qid']);
    try {
        $stmt = $pdo->prepare("SELECT question_id, question_text FROM questions WHERE question_id = ?");
        $stmt->execute([$current_qid]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$question) { die("ไม่พบคำถาม ID: ".h($current_qid)); }
        $SHOW_QUIZ = true;
    } catch (PDOException $e) {
        die("เกิดข้อผิดพลาดในการดึงคำถาม: ".$e->getMessage());
    }
} else {
    unset($_SESSION['answers'], $_SESSION['final_result']);
    $SHOW_START = true;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>แบบทดสอบแนะนำรายวิชา</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
<style>
/* ===== Theme (ตามพาเลต) ===== */
:root{
  --blue-700:#3674B5;
  --blue-500:#578FCA;
  --cream:#F5F0CD;
  --yellow:#FADA7A;
  --ink:#1f2d3d;
  --muted:#6b7a90;
  --card:#ffffff;
  --ring: rgba(54,116,181,.35);
}

/* พื้นหลังเป็นแถบสีตามรูป */
*{box-sizing:border-box}
body{
  font-family:'Sarabun',sans-serif;
  color:var(--ink);
  margin:0; min-height:100vh;
  display:flex; align-items:center; justify-content:center;
  background:
    linear-gradient(180deg,
      var(--blue-700) 0 34%,
      var(--blue-500) 34% 58%,
      var(--cream)    58% 79%,
      var(--yellow)   79% 100%);
}

/* กล่องการ์ด */
.card{
  position:relative;
  background:var(--card);
  border-radius:16px;
  box-shadow:0 14px 40px rgba(0,0,0,.12), 0 2px 8px rgba(0,0,0,.06);
  padding:28px;
  max-width:920px; width:92%;
  border:1px solid rgba(87,143,202,.15);
}
.card::before{
  content:"";
  position:absolute; inset:0 0 auto 0; height:8px;
  border-radius:16px 16px 0 0;
  background:linear-gradient(90deg,var(--blue-700),var(--blue-500));
}

/* หัวเรื่อง/คำโปรย */
h1{margin:6px 0 12px; color:var(--blue-700); letter-spacing:.2px}
.lead{color:var(--muted); margin:0 0 22px}

/* meta/badges */
.meta{
  margin:6px 0 18px; color:var(--muted);
  display:inline-flex; gap:10px; flex-wrap:wrap;
}
.meta strong{
  color:var(--blue-700);
  background:linear-gradient(0deg, rgba(250,218,122,.26), rgba(245,240,205,.26));
  border:1px solid rgba(250,218,122,.5);
  padding:4px 10px; border-radius:999px; font-weight:700;
}

/* ปุ่ม */
.btn{
  appearance:none; border:0; cursor:pointer;
  display:inline-flex; align-items:center; justify-content:center;
  gap:8px; text-decoration:none; font-weight:700;
  padding:14px 22px; border-radius:12px; min-width:160px;
  background:linear-gradient(135deg,var(--blue-700),var(--blue-500));
  color:#fff; box-shadow:0 6px 16px rgba(54,116,181,.35);
  transition:transform .15s ease, box-shadow .15s ease, filter .15s ease;
}
.btn:hover{ transform:translateY(-2px); box-shadow:0 10px 22px rgba(54,116,181,.38) }
.btn:active{ transform:translateY(0); filter:saturate(.95) }
.btn:focus-visible{ outline:none; box-shadow:0 0 0 4px var(--ring) }

.btn--alt{
  background:linear-gradient(135deg,var(--yellow), #FFE59A);
  color:#7a5b00;
  box-shadow:0 6px 16px rgba(250,218,122,.35);
}
.btn--ghost{
  background:transparent; color:var(--blue-700);
  border:2px solid var(--blue-500);
}

/* กล่องคำถาม */
.qbox{
  border:1px solid rgba(87,143,202,.25);
  background:linear-gradient(0deg, rgba(245,240,205,.45), rgba(245,240,205,.15));
  border-radius:12px; padding:18px; margin:16px 0 22px;
}
.qtext{font-weight:700; font-size:1.15rem; margin:0 0 12px; color:#24466a}

/* ตัวเลือก “ใช่/ไม่ใช่” */
.answers{display:flex; gap:18px; flex-wrap:wrap}
.answers label{display:flex; align-items:center; gap:10px; cursor:pointer; font-size:1.08rem}
.answers input[type="radio"]{
  width:20px; height:20px; accent-color:var(--blue-700);
  border-radius:50%;
  box-shadow:0 0 0 2px rgba(87,143,202,.25);
}
.answers input[type="radio"]:focus-visible{
  outline:none; box-shadow:0 0 0 4px var(--ring);
}

/* รายการวิชา */
.list{margin-top:10px; padding-left:18px}
.list li{margin:.3rem 0}

/* ส่วนลิงก์/ปุ่มท้ายผลลัพธ์ */
.actions{display:flex; gap:12px; flex-wrap:wrap; margin-top:16px}

/* ปรับภาพรวมให้โปร่ง นุ่มนวล */
::selection{background:rgba(87,143,202,.35)}
</style>

</head>
<body>
<div class="card">
<?php if ($SHOW_START): ?>
    <h1>แบบทดสอบแนะนำรายวิชา</h1>
    <p class="lead">ระบบจะถามคำถามแบบ “ใช่ / ไม่ใช่” แล้วสรุปกลุ่มวิชาที่เหมาะกับคุณ</p>
    <div class="meta">รหัสนักศึกษา: <strong><?= h($STUDENT_ID) ?></strong></div>
    <a class="btn" href="quiz.php?qid=1">เริ่มทำแบบทดสอบ</a>

<?php elseif ($SHOW_QUIZ): ?>
    <h1>แบบทดสอบ</h1>
    <div class="meta">รหัสนักศึกษา: <strong><?= h($STUDENT_ID) ?></strong> | ข้อที่: <strong><?= (int)$question['question_id'] ?></strong></div>
    <form method="POST" action="quiz.php">
        <input type="hidden" name="qid" value="<?= (int)$question['question_id'] ?>">
        <div class="qbox">
            <p class="qtext"><?= h($question['question_text']) ?></p>
            <div class="answers">
                <label><input type="radio" name="answer" value="1" required> ใช่</label>
                <label><input type="radio" name="answer" value="0"> ไม่ใช่</label>
            </div>
        </div>
        <button type="submit" class="btn">ข้อต่อไป</button>
    </form>

<?php elseif ($SHOW_RESULT): ?>
    <h1>ผลลัพธ์ของคุณ</h1>
    <p class="lead">ระบบแนะนำกลุ่มวิชา:
        <strong><?= $RESULT_GROUP_NAME ? h($RESULT_GROUP_NAME) : ('Group #'.(int)$RESULT_GROUP) ?></strong>
    </p>
    <?php if (!empty($RESULT_SUBJECTS)): ?>
      <div>
        <div class="meta">รายวิชาแนะนำในกลุ่มนี้:</div>
        <ul class="list">
          <?php foreach ($RESULT_SUBJECTS as $row): ?>
            <li><?= h($row['subject_name']) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:16px">
        <a class="btn" href="quiz.php">เริ่มใหม่</a>
        <a class="btn btn--alt" href="student_dashboard.php">กลับหน้าแดชบอร์ด</a>
            
    </div>
<?php endif; ?>
</div>
</body>
</html>
