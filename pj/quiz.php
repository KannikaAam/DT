<?php
/* =========================================================
   quiz.php — หน้าเดียวจบ: เริ่มทำข้อสอบ + ทำทีละข้อ + บันทึกผล + แสดงผล
   ใช้ตาราง: groups, subjects, questions, quiz_results, quiz_answers (ฐาน projact2)
   ========================================================= */
session_start();
require __DIR__ . '/db_connect.php'; // ใช้ $pdo (PDO)

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
$RESULT_SUBJECTS = [];

// ===== Helper =====
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function goto_q($qid){ header('Location: quiz.php?qid='.(int)$qid); exit; }
function ans($qid, $bag){ return isset($bag[$qid]) ? (int)$bag[$qid] : -1; } // -1 = ยังไม่ตอบ

// ===== Auto-migrate: สร้างตาราง/คอลัมน์ที่ต้องใช้ =====
$pdo->exec("
CREATE TABLE IF NOT EXISTS quiz_results (
  result_id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  recommend_group_id INT DEFAULT NULL,
  completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS quiz_answers (
  answer_id INT AUTO_INCREMENT PRIMARY KEY,
  result_id INT NOT NULL,
  question_id INT NOT NULL,
  answer_value TINYINT(1) NOT NULL,
  KEY idx_result (result_id),
  CONSTRAINT qa_fk_result FOREIGN KEY (result_id) REFERENCES quiz_results(result_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ensure: result_id column exists
$col = $pdo->query("SHOW COLUMNS FROM quiz_answers LIKE 'result_id'")->fetch(PDO::FETCH_ASSOC);
if (!$col) {
    $pdo->exec("ALTER TABLE quiz_answers ADD COLUMN result_id INT NOT NULL AFTER answer_id");
}

// ensure: index on result_id
$idx = $pdo->query("SHOW INDEX FROM quiz_answers WHERE Key_name = 'idx_result'")->fetch(PDO::FETCH_ASSOC);
if (!$idx) {
    $pdo->exec("ALTER TABLE quiz_answers ADD KEY idx_result (result_id)");
}

// ensure: foreign key exists
$fkStmt = $pdo->prepare("
SELECT CONSTRAINT_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'quiz_answers'
  AND COLUMN_NAME = 'result_id'
  AND REFERENCED_TABLE_NAME = 'quiz_results'
");
$fkStmt->execute();
if (!$fkStmt->fetch(PDO::FETCH_ASSOC)) {
    try {
        $pdo->exec("ALTER TABLE quiz_answers ADD CONSTRAINT qa_fk_result FOREIGN KEY (result_id) REFERENCES quiz_results(result_id) ON DELETE CASCADE");
    } catch (PDOException $e) {
        // ถ้ามี FK อยู่แล้ว หรือ engine ไม่รองรับ ก็ข้ามได้
    }
}

// ===== บันทึกผล + เก็บ answers =====
function save_and_prepare_result(PDO $pdo, int $student_id, int $group_id): int {
    // นับจำนวน "ไม่ใช่"
    $no_count = 0;
    if (!empty($_SESSION['answers']) && is_array($_SESSION['answers'])) {
        foreach ($_SESSION['answers'] as $v) { if ((int)$v === 0) $no_count++; }
    }

    $_SESSION['final_result'] = [
        'recommend_group_id' => $group_id,
        'no_count' => $no_count,
    ];

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

// ===== บันทึกลง test_history (ให้ history.php อ่านได้) =====
function saveTestHistoryPDO(PDO $pdo, string $student_id, ?string $group, ?string $subjects, int $no_count): bool {
    try {
        // สร้างตารางถ้ายังไม่มี
        $pdo->exec("CREATE TABLE IF NOT EXISTS test_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL,
            recommended_group VARCHAR(255),
            recommended_subjects TEXT,
            no_count INT DEFAULT 0,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $sid = $_SESSION['student_id']; // ต้องเป็นรหัสนักศึกษา
        $stmt = $conn->prepare("
            INSERT INTO test_history (username, recommended_group, recommended_subjects, timestamp)VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("sss", $sid, $group, $subjects_json);
        return $st->execute([$student_id, $group, $subjects, $no_count]);
    } catch (PDOException $e) {
        error_log("saveTestHistoryPDO: ".$e->getMessage());
        return false;
    }
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
        if (ans(12,$a) == 1) { goto_q(14); }
        else { $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 1); $SHOW_RESULT = true; }
    }

    // ข้อ 13
    if (!$SHOW_RESULT && $qid == 13) {
        if (ans(13,$a) == 0) { goto_q(14); }
        else { $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 1); $SHOW_RESULT = true; }
    }

    // กลุ่ม 2: 14–23
    if (!$SHOW_RESULT && $qid == 14) {
        if (ans(14,$a) == 1) { goto_q(15); }
        else { goto_q(10); } // ย้อนกลับไปสรุปกลุ่ม 1 ตามเส้นทาง 10/12/13
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
        if ($L && ans(22,$a)==1) { goto_q(24); }
        else {
            $A = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==1 && ans(16,$a)==0 && ans(19,$a)==0 && ans(21,$a)==1);
            $B = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==0 && ans(17,$a)==1 && ans(18,$a)==0 && ans(21,$a)==1);
            $C = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==0 && ans(17,$a)==0 && ans(19,$a)==1 && ans(20,$a)==1);
            $D = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==0 && ans(17,$a)==0 && ans(19,$a)==0 && ans(21,$a)==1);
            if (($A||$B||$C||$D) && ans(22,$a)==0) { goto_q(24); }
            else { $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 2); $SHOW_RESULT = true; }
        }
    }

    if (!$SHOW_RESULT && $qid == 23) {
        $Y1 = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==1 && ans(16,$a)==0 && ans(19,$a)==0 && ans(21,$a)==0);
        $Y2 = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==0 && ans(17,$a)==1 && ans(18,$a)==0 && ans(21,$a)==0);
        $Y3 = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==0 && ans(17,$a)==0 && ans(19,$a)==1 && ans(20,$a)==0);
        $Y4 = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==0 && ans(17,$a)==0 && ans(19,$a)==0 && ans(21,$a)==0);

        if (($Y1||$Y2||$Y3||$Y4) && ans(23,$a)==1) { goto_q(24); }
        else { if (ans(23,$a)==1) { $RESULT_GROUP = save_and_prepare_result($pdo, $STUDENT_ID, 2); $SHOW_RESULT = true; } else { goto_q(24); } }
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

    // Fallback: ปลอดภัยกว่า → กลับไป 14
    if (!$SHOW_RESULT) { goto_q(14); }
}

/* =========================================================
   PART 2: DISPLAY (กำหนดโหมด)
   ========================================================= */
if ($SHOW_RESULT) {
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

    if (empty($_SESSION['final_result_saved']) && !empty($_SESSION['final_result'])) {
        $no_count = (int)($_SESSION['final_result']['no_count'] ?? 0);
        $subjects_text = '';
        if (!empty($RESULT_SUBJECTS)) {
            $names = array_map(fn($r)=>$r['subject_name'] ?? '', $RESULT_SUBJECTS);
            $subjects_text = implode("\n", array_filter($names));
        }
        $group_text = $RESULT_GROUP_NAME ?: ('กลุ่มที่ '.(int)$RESULT_GROUP);
        if (saveTestHistoryPDO($pdo, (string)$STUDENT_ID, $group_text, $subjects_text, $no_count)) {
            $_SESSION['final_result_saved'] = true;
        }
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
    unset($_SESSION['answers'], $_SESSION['final_result'], $_SESSION['final_result_saved']);
    $SHOW_START = true;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>แบบทดสอบแนะนำรายวิชาชีพเลือก</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
/* ===== Modern Color Palette ===== */
:root {
  --primary-600: #2563eb;
  --primary-500: #3b82f6;
  --primary-400: #60a5fa;
  --primary-100: #dbeafe;
  --primary-50: #eff6ff;
  
  --secondary-600: #dc2626;
  --secondary-500: #ef4444;
  --secondary-100: #fee2e2;
  
  --accent-600: #d97706;
  --accent-500: #f59e0b;
  --accent-100: #fef3c7;
  
  --success-600: #059669;
  --success-500: #10b981;
  --success-100: #d1fae5;
  
  --gray-900: #111827;
  --gray-800: #1f2937;
  --gray-700: #374151;
  --gray-600: #4b5563;
  --gray-500: #6b7280;
  --gray-400: #9ca3af;
  --gray-300: #d1d5db;
  --gray-200: #e5e7eb;
  --gray-100: #f3f4f6;
  --gray-50: #f9fafb;
  
  --white: #ffffff;
  --shadow: rgba(0, 0, 0, 0.1);
  --shadow-lg: rgba(0, 0, 0, 0.15);
  --shadow-xl: rgba(0, 0, 0, 0.25);
}

/* ===== Reset & Base ===== */
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: 'Sarabun', sans-serif;
  background: linear-gradient(135deg, var(--primary-50) 0%, var(--primary-100) 100%);
  color: var(--gray-800);
  min-height: 100vh;
  padding: 1rem;
  overflow-x: hidden;
}

/* ===== Animated Background ===== */
body::before {
  content: '';
  position: fixed;
  top: -50%;
  left: -50%;
  width: 200%;
  height: 200%;
  background: 
    radial-gradient(circle at 20% 80%, rgba(59, 130, 246, 0.15) 0%, transparent 50%),
    radial-gradient(circle at 80% 20%, rgba(239, 68, 68, 0.1) 0%, transparent 50%),
    radial-gradient(circle at 40% 40%, rgba(16, 185, 129, 0.1) 0%, transparent 50%);
  animation: float 20s ease-in-out infinite;
  z-index: -1;
}

@keyframes float {
  0%, 100% { transform: translateY(0px) rotate(0deg); }
  50% { transform: translateY(-20px) rotate(5deg); }
}

/* ===== Container ===== */
.container {
  max-width: 900px;
  margin: 2rem auto;
  position: relative;
}

/* ===== Card Design ===== */
.card {
  background: var(--white);
  border-radius: 24px;
  box-shadow: 
    0 20px 25px -5px var(--shadow),
    0 10px 10px -5px var(--shadow-lg);
  padding: 3rem;
  position: relative;
  backdrop-filter: blur(10px);
  border: 1px solid rgba(255, 255, 255, 0.2);
  overflow: hidden;
}

.card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 6px;
  background: linear-gradient(90deg, var(--primary-500), var(--accent-500), var(--success-500));
  border-radius: 24px 24px 0 0;
}

/* ===== Typography ===== */
h1 {
  font-size: 2.5rem;
  font-weight: 700;
  color: var(--gray-900);
  margin-bottom: 0.5rem;
  background: linear-gradient(135deg, var(--primary-600), var(--primary-500));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

.lead {
  font-size: 1.25rem;
  color: var(--gray-600);
  margin-bottom: 2rem;
  line-height: 1.6;
}

.meta {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 2.5rem;
  align-items: center;
}

.badge {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  background: linear-gradient(135deg, var(--primary-100), var(--primary-50));
  color: var(--primary-600);
  padding: 0.5rem 1rem;
  border-radius: 50px;
  border: 1px solid var(--primary-200);
  font-weight: 500;
  font-size: 0.9rem;
}

.badge i {
  font-size: 1rem;
}

/* ===== Buttons ===== */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 0.875rem 2rem;
  border-radius: 16px;
  font-weight: 600;
  font-size: 1rem;
  text-decoration: none;
  border: none;
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  position: relative;
  overflow: hidden;
  min-width: 140px;
}

.btn::before {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
  transition: left 0.6s;
}

.btn:hover::before {
  left: 100%;
}

.btn-primary {
  background: linear-gradient(135deg, var(--primary-600), var(--primary-500));
  color: var(--white);
  box-shadow: 0 8px 25px rgba(37, 99, 235, 0.3);
}

.btn-primary:hover {
  transform: translateY(-2px);
  box-shadow: 0 12px 35px rgba(37, 99, 235, 0.4);
}

.btn-secondary {
  background: linear-gradient(135deg, var(--gray-600), var(--gray-500));
  color: var(--white);
  box-shadow: 0 8px 25px rgba(75, 85, 99, 0.3);
}

.btn-secondary:hover {
  transform: translateY(-2px);
  box-shadow: 0 12px 35px rgba(75, 85, 99, 0.4);
}

.btn-success {
  background: linear-gradient(135deg, var(--success-600), var(--success-500));
  color: var(--white);
  box-shadow: 0 8px 25px rgba(5, 150, 105, 0.3);
}

.btn-success:hover {
  transform: translateY(-2px);
  box-shadow: 0 12px 35px rgba(5, 150, 105, 0.4);
}

.btn-outline {
  background: transparent;
  color: var(--primary-600);
  border: 2px solid var(--primary-200);
  box-shadow: none;
}

.btn-outline:hover {
  background: var(--primary-50);
  border-color: var(--primary-300);
  transform: translateY(-1px);
}

.btn-danger {
  background: linear-gradient(135deg, var(--secondary-600), var(--secondary-500));
  color: var(--white);
  box-shadow: 0 8px 25px rgba(220, 38, 38, 0.3);
}

.btn-danger:hover {
  transform: translateY(-2px);
  box-shadow: 0 12px 35px rgba(220, 38, 38, 0.4);
}

.btn:active {
  transform: translateY(0);
}

.btn:focus {
  outline: none;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
}

/* ===== Question Box ===== */
.question-container {
  background: linear-gradient(135deg, var(--primary-50), var(--white));
  border-radius: 20px;
  padding: 2rem;
  margin: 2rem 0;
  border: 1px solid var(--primary-100);
  position: relative;
  overflow: hidden;
}

.question-container::before {
  content: '';
  position: absolute;
  top: -50%;
  right: -50%;
  width: 100%;
  height: 100%;
  background: radial-gradient(circle, rgba(59, 130, 246, 0.1) 0%, transparent 70%);
  animation: pulse 4s ease-in-out infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 0.5; transform: scale(1); }
  50% { opacity: 0.8; transform: scale(1.1); }
}

.question-header {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.question-icon {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--primary-500), var(--primary-400));
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--white);
  font-size: 1.25rem;
  flex-shrink: 0;
}

.question-text {
  font-size: 1.35rem;
  font-weight: 600;
  color: var(--gray-800);
  line-height: 1.5;
  position: relative;
  z-index: 1;
}

/* ===== Answer Options ===== */
.answers {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1.5rem;
  margin: 2rem 0;
}

.answer-option {
  position: relative;
}

.answer-option input[type="radio"] {
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
}

.answer-label {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.25rem 1.5rem;
  border-radius: 16px;
  border: 2px solid var(--gray-200);
  background: var(--white);
  cursor: pointer;
  transition: all 0.3s ease;
  font-size: 1.1rem;
  font-weight: 500;
  position: relative;
  overflow: hidden;
}

.answer-label::before {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.1), transparent);
  transition: left 0.5s;
}

.answer-label:hover::before {
  left: 100%;
}

.answer-option input[type="radio"]:checked + .answer-label {
  border-color: var(--primary-400);
  background: linear-gradient(135deg, var(--primary-50), var(--white));
  box-shadow: 0 8px 25px rgba(37, 99, 235, 0.15);
  transform: translateY(-2px);
}

.radio-custom {
  width: 24px;
  height: 24px;
  border: 2px solid var(--gray-300);
  border-radius: 50%;
  position: relative;
  transition: all 0.3s ease;
  flex-shrink: 0;
}

.answer-option input[type="radio"]:checked + .answer-label .radio-custom {
  border-color: var(--primary-500);
  background: var(--primary-500);
}

.radio-custom::after {
  content: '';
  position: absolute;
  top: 50%;
  left: 50%;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--white);
  transform: translate(-50%, -50%) scale(0);
  transition: transform 0.3s ease;
}

.answer-option input[type="radio"]:checked + .answer-label .radio-custom::after {
  transform: translate(-50%, -50%) scale(1);
}

.answer-text {
  color: var(--gray-700);
  font-weight: 500;
}

.answer-option input[type="radio"]:checked + .answer-label .answer-text {
  color: var(--primary-600);
  font-weight: 600;
}

/* ===== Result Section ===== */
.result-container {
  text-align: center;
  padding: 2rem 0;
}

.result-icon {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--success-500), var(--success-400));
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--white);
  font-size: 2rem;
  margin: 0 auto 2rem;
  animation: celebration 0.6s ease-out;
}

@keyframes celebration {
  0% { transform: scale(0) rotate(0deg); }
  50% { transform: scale(1.2) rotate(180deg); }
  100% { transform: scale(1) rotate(360deg); }
}

.result-title {
  font-size: 2rem;
  font-weight: 700;
  color: var(--gray-900);
  margin-bottom: 1rem;
}

.result-group {
  display: inline-block;
  background: linear-gradient(135deg, var(--success-100), var(--success-50));
  color: var(--success-600);
  padding: 0.75rem 2rem;
  border-radius: 50px;
  border: 2px solid var(--success-200);
  font-size: 1.25rem;
  font-weight: 600;
  margin: 1rem 0 2rem;
}

.subjects-container {
  background: var(--gray-50);
  border-radius: 16px;
  padding: 2rem;
  margin: 2rem 0;
  border: 1px solid var(--gray-200);
}

.subjects-title {
  font-size: 1.25rem;
  font-weight: 600;
  color: var(--gray-800);
  margin-bottom: 1.5rem;
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.subjects-list {
  list-style: none;
  display: grid;
  gap: 0.75rem;
}

.subjects-list li {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
  background: var(--white);
  border-radius: 12px;
  border: 1px solid var(--gray-200);
  color: var(--gray-700);
  transition: all 0.3s ease;
}

.subjects-list li:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  transform: translateX(4px);
}

.subjects-list li::before {
  content: '📚';
  font-size: 1.25rem;
}

/* ===== Actions (Button Groups) ===== */
.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  justify-content: center;
  margin-top: 3rem;
}

/* ===== Progress Indicator ===== */
.progress-container {
  margin-bottom: 2rem;
}

.progress-text {
  font-size: 0.9rem;
  color: var(--gray-600);
  margin-bottom: 0.5rem;
  text-align: center;
}

.progress-bar {
  width: 100%;
  height: 8px;
  background: var(--gray-200);
  border-radius: 50px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--primary-500), var(--primary-400));
  border-radius: 50px;
  transition: width 0.5s ease;
}

/* ===== Responsive Design ===== */
@media (max-width: 768px) {
  body { padding: 0.5rem; }
  .container { margin: 1rem auto; }
  .card { padding: 2rem 1.5rem; border-radius: 16px; }
  h1 { font-size: 2rem; }
  .lead { font-size: 1.1rem; }
  .question-text { font-size: 1.2rem; }
  .answers { grid-template-columns: 1fr; gap: 1rem; }
  .answer-label { padding: 1rem 1.25rem; }
  .actions { flex-direction: column; align-items: center; }
  .btn { width: 100%; max-width: 300px; }
  .meta { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
}

@media (max-width: 480px) {
  .card { padding: 1.5rem 1rem; }
  h1 { font-size: 1.75rem; }
  .question-container { padding: 1.5rem; }
  .question-header { flex-direction: column; text-align: center; gap: 1rem; }
  .question-icon { width: 60px; height: 60px; font-size: 1.5rem; }
}

/* ===== Loading Animation ===== */
.loading {
  display: inline-block;
  width: 20px;
  height: 20px;
  border: 2px solid var(--white);
  border-radius: 50%;
  border-top-color: transparent;
  animation: spin 1s ease-in-out infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* ===== Fade In Animation ===== */
.fade-in { animation: fadeIn 0.6s ease-out; }

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(20px); }
  to   { opacity: 1; transform: translateY(0); }
}
</style>
</head>
<body>

<div class="container">
  <div class="card fade-in">

    <?php if ($SHOW_START): ?>
      <div class="result-container">
        <div class="result-icon">
          <i class="fas fa-graduation-cap"></i>
        </div>
        <h1>แบบทดสอบแนะนำรายวิชาชีพเลือก</h1>
        <p class="lead">ระบบจะถามคำถามแบบ "ใช่ / ไม่ใช่" เพื่อวิเคราะห์และแนะนำกลุ่มวิชาที่เหมาะสมกับคุณมากที่สุด</p>
        
        <div class="meta">
          <div class="badge">
            <i class="fas fa-user"></i>
            รหัสนักศึกษา: <?= h($STUDENT_ID) ?>
          </div>
          <div class="badge">
            <i class="fas fa-clock"></i>
            ประมาณ 5-10 นาที
          </div>
        </div>

        <div class="actions">
          <a href="quiz.php?qid=1" class="btn btn-primary">
            <i class="fas fa-play"></i>
            เริ่มทำแบบทดสอบ
          </a>
          <a href="student_dashboard.php" class="btn btn-outline">
            <i class="fas fa-home"></i>
            กลับหน้าหลัก
          </a>
        </div>
      </div>

    <?php elseif ($SHOW_QUIZ): ?>
      <h1>แบบทดสอบแนะนำรายวิชาชีพเลือก</h1>
      
      <div class="meta">
        <div class="badge">
          <i class="fas fa-user"></i>
          <?= h($STUDENT_ID) ?>
        </div>
        <div class="badge">
          <i class="fas fa-question-circle"></i>
          ข้อที่ <?= (int)$question['question_id'] ?>
        </div>
      </div>

      <form method="POST" action="quiz.php">
        <input type="hidden" name="qid" value="<?= (int)$question['question_id'] ?>">
        
        <div class="question-container">
          <div class="question-header">
            <div class="question-icon">
              <i class="fas fa-lightbulb"></i>
            </div>
            <div class="question-text"><?= h($question['question_text']) ?></div>
          </div>

          <div class="answers">
            <div class="answer-option">
              <input type="radio" name="answer" value="1" id="answer_yes" required>
              <label for="answer_yes" class="answer-label">
                <div class="radio-custom"></div>
                <div class="answer-text">
                  <i class="fas fa-check text-success"></i> ใช่
                </div>
              </label>
            </div>
            
            <div class="answer-option">
              <input type="radio" name="answer" value="0" id="answer_no">
              <label for="answer_no" class="answer-label">
                <div class="radio-custom"></div>
                <div class="answer-text">
                  <i class="fas fa-times text-danger"></i> ไม่ใช่
                </div>
              </label>
            </div>
          </div>
        </div>

        <div class="actions">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-arrow-right"></i>
            ข้อต่อไป
          </button>
          <a href="student_dashboard.php" class="btn btn-danger" onclick="return confirm('คุณแน่ใจหรือไม่ที่จะยกเลิกการทำแบบทดสอบ?')">
            <i class="fas fa-times"></i>
            ยกเลิก
          </a>
        </div>
      </form>

    <?php elseif ($SHOW_RESULT): ?>
      <div class="result-container">
        <div class="result-icon">
          <i class="fas fa-trophy"></i>
        </div>
        
        <h1 class="result-title">ผลลัพธ์ของคุณ</h1>
        <p class="lead">ระบบแนะนำกลุ่มวิชาที่เหมาะกับคุณ</p>
        
        <div class="result-group">
          <i class="fas fa-star"></i>
          <?= $RESULT_GROUP_NAME ? h($RESULT_GROUP_NAME) : ('กลุ่มที่ '.(int)$RESULT_GROUP) ?>
        </div>

        <?php if (!empty($RESULT_SUBJECTS)): ?>
          <div class="subjects-container">
            <div class="subjects-title">
              <i class="fas fa-book-open"></i>
              รายวิชาที่แนะนำในกลุ่มนี้
            </div>
            <ul class="subjects-list">
              <?php foreach ($RESULT_SUBJECTS as $row): ?>
                <li><?= h($row['subject_name']) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <div class="actions">
          <a href="quiz.php" class="btn btn-primary">
            <i class="fas fa-redo"></i>
            ทำแบบทดสอบใหม่
          </a>
          <a href="student_dashboard.php" class="btn btn-success">
            <i class="fas fa-home"></i>
            กลับหน้าแดชบอร์ด
          </a>
        </div>
      </div>

    <?php endif; ?>

  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const card = document.querySelector('.card');
    if (card) card.classList.add('fade-in');

    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function() {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<span class="loading"></span> กำลังประมวลผล...';
                submitBtn.disabled = true;
            }
        });
    }

    const answerLabels = document.querySelectorAll('.answer-label');
    answerLabels.forEach(label => {
        label.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
            this.style.boxShadow = '0 8px 25px rgba(37, 99, 235, 0.15)';
        });
        label.addEventListener('mouseleave', function() {
            if (!this.previousElementSibling.checked) {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = 'none';
            }
        });
    });
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && e.target.type === 'radio') {
        e.target.closest('form').submit();
    }
});
</script>

</body>
</html>
