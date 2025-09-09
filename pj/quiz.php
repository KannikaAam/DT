<?php
/* =========================================================
   quiz.php — Enforced attempt limit + suspend gate
   - Base limit = 3 + admin_override_attempts from student_quiz_status
   - Block when academic_status = 'suspended'
   - Count attempts from quiz_results per student_id
   - Works with existing flow; adds permission checks on every request
   ========================================================= */
session_start();
require __DIR__ . '/db_connect.php'; // expects $pdo (PDO)

if (!isset($_SESSION['student_id'])) { header('Location: login.php'); exit; }
$STUDENT_ID = (int)$_SESSION['student_id'];

/* ---------- Helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function goto_q($qid){ header('Location: quiz.php?qid='.(int)$qid); exit; }
function ans($qid, $bag){ return isset($bag[$qid]) ? (int)$bag[$qid] : -1; } // -1 = not answered

/* ---------- Ensure policy table (idempotent) ---------- */
$pdo->exec("
CREATE TABLE IF NOT EXISTS student_quiz_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL UNIQUE,
    quiz_attempts INT NOT NULL DEFAULT 0,
    admin_override_attempts INT NOT NULL DEFAULT 0,
    academic_status ENUM('active','graduated','leave','suspended') NOT NULL DEFAULT 'active',
    updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ensure row for this student
$ins = $pdo->prepare("INSERT IGNORE INTO student_quiz_status (student_id) VALUES (?)");
$ins->execute([$STUDENT_ID]);

/* ---------- Compute policy (used/max/canStart) ---------- */
function compute_policy(PDO $pdo, int $student_id): array {
    // overrides + status
    $st = $pdo->prepare("SELECT admin_override_attempts, academic_status FROM student_quiz_status WHERE student_id=?");
    $st->execute([$student_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['admin_override_attempts'=>0,'academic_status'=>'active'];
    $override = (int)($row['admin_override_attempts'] ?? 0);
    $status   = (string)($row['academic_status'] ?? 'active');
    $base     = 3;
    $max      = max(0, $base + $override);

    // attempts used = submitted results count
    $used = 0;
    try {
        $c = $pdo->prepare("SELECT COUNT(*) FROM quiz_results WHERE student_id=?");
        $c->execute([$student_id]);
        $used = (int)$c->fetchColumn();
    } catch (Throwable $e) { $used = 0; }

    $can = ($status !== 'suspended') && ($used < $max);
    return ['base'=>$base,'override'=>$override,'max'=>$max,'used'=>$used,'status'=>$status,'can'=>$can];
}

$policy = compute_policy($pdo, $STUDENT_ID);
/* ===== ONE-TIME / REPEATABLE SEED & UPDATE QUESTIONS =====
   เรียกใช้โดยเปิด quiz.php?seed=1
   - เขียน question_text / order_in_group / group_id ลงตาราง questions โดยตรง
   - ถ้ามีแถวอยู่แล้วจะ UPDATE ให้ (ON DUPLICATE KEY)
   - group_id ปล่อย NULL ได้ ถ้าไม่อยากผูกกลุ่ม
=========================================================== */
if (isset($_GET['seed']) && (int)$_GET['seed'] === 1) {
    // 0) กันสคีมา: ให้ group_id เป็น NULL ได้ และ FK เป็น SET NULL
    try {
        // กำจัดค่า 0
        $pdo->exec("UPDATE `questions` SET group_id=NULL WHERE group_id=0");

        // ถ้า FK เก่าเป็น CASCADE ให้ถอดออกแล้วใส่ใหม่เป็น SET NULL (ข้ามได้ถ้าทำไปแล้ว)
        $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $fk = $pdo->prepare("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
                             WHERE CONSTRAINT_SCHEMA=? AND TABLE_NAME='questions'");
        $fk->execute([$db]);
        if ($name = $fk->fetchColumn()) {
            $pdo->exec("ALTER TABLE `questions` DROP FOREIGN KEY `{$name}`");
        }
        $pdo->exec("ALTER TABLE `questions` MODIFY COLUMN `group_id` INT NULL DEFAULT NULL");

        
        $gt = null;
        if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
                              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='subject_groups'")->fetchColumn() > 0) {
            $gt = 'subject_groups';
        } elseif ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
                                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='groups'")->fetchColumn() > 0) {
            $gt = '`groups`';
        }
        if ($gt) {
            $pdo->exec("ALTER TABLE `questions`
                        ADD CONSTRAINT `fk_questions_group`
                        FOREIGN KEY (`group_id`) REFERENCES {$gt}(`group_id`)
                        ON DELETE SET NULL ON UPDATE CASCADE");
        }
    } catch (Throwable $e) { /* เงียบได้ */ }

    
    // 2) อัปเสิร์ตเข้าตาราง `questions` โดยตรง
    $sql = "INSERT INTO `questions` (question_id, question_text, order_in_group, group_id)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              question_text = VALUES(question_text),
              order_in_group = VALUES(order_in_group),
              group_id = VALUES(group_id)";
    $st = $pdo->prepare($sql);

    $pdo->beginTransaction();
    foreach ($QUESTIONS as $qid => [$text, $order, $gid]) {
        
        $gid = ($gid === '' ? null : $gid);
        $st->execute([(int)$qid, (string)$text, (int)$order, $gid]);
    }
    $pdo->commit();

    // 3) เสร็จแล้วเด้งกลับหน้าเริ่ม
    header('Location: quiz.php'); exit;
}

/* ---------- Short-circuit guards on POST/GET ---------- */
function hard_block(string $reason, array $policy){
    http_response_code(403);
    ?>
    <!doctype html><html lang="th"><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ไม่อนุญาตให้ทำแบบทดสอบ</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Sarabun', sans-serif;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .error-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border-radius: 24px;
        padding: 40px;
        max-width: 600px;
        text-align: center;
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }
    .error-icon {
        font-size: 4rem;
        color: #ef4444;
        margin-bottom: 24px;
        opacity: 0.8;
    }
    h2 {
        color: #1f2937;
        margin-bottom: 16px;
        font-size: 1.8rem;
        font-weight: 600;
    }
    .error-message {
        color: #4b5563;
        margin-bottom: 24px;
        font-size: 1.1rem;
        line-height: 1.6;
    }
    .status-pills {
        display: flex;
        gap: 12px;
        justify-content: center;
        flex-wrap: wrap;
        margin-bottom: 32px;
    }
    .pill {
        background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
        padding: 8px 16px;
        border-radius: 25px;
        font-size: 0.9rem;
        font-weight: 500;
        color: #374151;
        border: 1px solid rgba(0, 0, 0, 0.1);
    }
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 14px 28px;
        background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        color: white;
        text-decoration: none;
        border-radius: 12px;
        font-weight: 600;
        font-size: 1rem;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
    }
    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
    }
    </style>
    <div class="error-card">
        <div class="error-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <h2>ไม่สามารถทำแบบทดสอบได้</h2>
        <p class="error-message"><?= h($reason) ?></p>
        <div class="status-pills">
            <span class="pill">สถานะ: <b><?= h($policy['status']) ?></b></span>
            <span class="pill">ทำไปแล้ว: <b><?= (int)$policy['used'] ?></b></span>
            <span class="pill">สิทธิ์สูงสุด: <b><?= (int)$policy['max'] ?></b></span>
        </div>
        <a class="btn" href="student_dashboard.php">
            <i class="fas fa-arrow-left"></i>
            กลับหน้าแดชบอร์ด
        </a>
    </div>
    </html>
    <?php
    exit;
}

/* =========================================================
   PART 1: PROCESSING
   ========================================================= */
$SHOW_START=false; $SHOW_QUIZ=false; $SHOW_RESULT=false;
$RESULT_GROUP=null; $RESULT_GROUP_NAME=null; $RESULT_SUBJECTS=[];

/* Guards */
if ($policy['status'] === 'suspended') {
    hard_block('สถานะของคุณถูกระงับสิทธิ์ (suspended) โปรดติดต่อผู้ดูแลระบบเพื่อปลดระงับ', $policy);
}
if ($policy['used'] >= $policy['max']) {
    hard_block('คุณใช้สิทธิ์ครบแล้ว ไม่สามารถทำแบบทดสอบเพิ่มเติมได้', $policy);
}

/* ---------- questions table introspection + seed (no default group) ---------- */
function table_exists(PDO $pdo, string $name): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$name]);
    return (bool)$stmt->fetchColumn();
}
function get_table_columns(PDO $pdo, string $name): array {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$name}`");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
// --- schema repair for questions.group_id to allow NULL + FK SET NULL ---
try {
    // มีคอลัมน์ group_id ไหม
    $cols = $pdo->query("SHOW COLUMNS FROM `questions`")->fetchAll(PDO::FETCH_ASSOC);
    $hasGroup = false; $groupIsNullable = true; $groupDefault = null;
    foreach ($cols as $c) {
        if (strcasecmp($c['Field'], 'group_id') === 0) {
            $hasGroup = true;
            $groupIsNullable = (strtoupper($c['Null']) === 'YES');
            $groupDefault = $c['Default'];
            break;
        }
    }
    if ($hasGroup) {
        // ถ้ามีค่า 0 อยู่ให้เซ็ตเป็น NULL ก่อน
        $pdo->exec("UPDATE `questions` SET group_id = NULL WHERE group_id = 0");

        // หา FK name (ถ้ามี) เพื่อถอดออก
        $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $sqlFk = "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
                  WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = 'questions'";
        $stFk = $pdo->prepare($sqlFk);
        $stFk->execute([$db]);
        $fkName = $stFk->fetchColumn();

        if ($fkName) {
            $pdo->exec("ALTER TABLE `questions` DROP FOREIGN KEY `{$fkName}`");
        }

        // ปรับคอลัมน์ให้ NULL ได้ (กัน default 0)
        $pdo->exec("ALTER TABLE `questions` MODIFY COLUMN `group_id` INT NULL");

        // เลือกตารางกลุ่มที่มีจริง
        $groupTable = null;
        $chk = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='subject_groups'")->fetchColumn();
        if ($chk > 0) $groupTable = 'subject_groups';
        else {
            $chk2 = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='groups'")->fetchColumn();
            if ($chk2 > 0) $groupTable = '`groups`';
        }
        if ($groupTable) {
            $pdo->exec("ALTER TABLE `questions`
                        ADD CONSTRAINT `fk_questions_group`
                        FOREIGN KEY (`group_id`) REFERENCES {$groupTable}(`group_id`)
                        ON DELETE SET NULL ON UPDATE CASCADE");
        }
    }
} catch (Throwable $e) {
    // ไม่ต้องหยุดระบบ แค่ log ก็พอ
    error_log('questions schema repair failed: '.$e->getMessage());
}

$QCOL=null;
try{
    if (!table_exists($pdo, 'questions')) { die('ตาราง "questions" ไม่มีอยู่ในฐานข้อมูล'); }
    $qcols = array_column(get_table_columns($pdo, 'questions'), 'Field');
    if (in_array('question_id', $qcols, true)) $QCOL='question_id';
    elseif (in_array('id', $qcols, true)) $QCOL='id';
    else { $pdo->exec("ALTER TABLE `questions` ADD COLUMN `question_id` INT NOT NULL UNIQUE"); $QCOL='question_id'; $qcols[]='question_id'; }

    $has_group_col = in_array('group_id', $qcols, true);
    $has_order_col = in_array('order_in_group', $qcols, true);
    if ($has_order_col) {
        $pdo->exec("ALTER TABLE `questions` MODIFY COLUMN `order_in_group` INT NOT NULL DEFAULT 0");
    }

    // seed 33 rows if missing — do NOT touch group_id
    $fields = ['`'.$QCOL.'`','question_text'];
    $vals   = ['?','?'];
    if ($has_order_col) { $fields[]='order_in_group'; $vals[]='?'; }

    $sqlIns = "INSERT INTO `questions` (".implode(',', $fields).") VALUES (".implode(',', $vals).")";
    $ins = $pdo->prepare($sqlIns);
    $check = $pdo->prepare("SELECT 1 FROM `questions` WHERE `{$QCOL}`=? LIMIT 1");

    $pdo->beginTransaction();
    $order = 1;
    for($i=1;$i<=33;$i++){
        $check->execute([$i]);
        if(!$check->fetch()){
            if ($has_order_col) $ins->execute([$i, " {$i} (แก้ข้อความภายหลัง)", $order]);
            else                $ins->execute([$i, " {$i} (แก้ข้อความภายหลัง)"]);
        }
        $order++;
    }
    $pdo->commit();
}catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    die('ตาราง "questions" ไม่มีหรืออ่านไม่ได้: '.$e->getMessage());
}

/* ---------- result tables ---------- */
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
  KEY idx_result (result_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ---------- save history ---------- */
function save_and_prepare_result(PDO $pdo, int $student_id, int $group_id): int {
    $no_count = 0;
    if (!empty($_SESSION['answers']) && is_array($_SESSION['answers'])) {
        foreach ($_SESSION['answers'] as $v) { if ((int)$v === 0) $no_count++; }
    }
    $_SESSION['final_result'] = ['recommend_group_id'=>$group_id,'no_count'=>$no_count];

    try{
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO quiz_results (student_id, recommend_group_id) VALUES (?, ?)");
        $stmt->execute([$student_id, $group_id]);
        $result_id = (int)$pdo->lastInsertId();

        if (!empty($_SESSION['answers']) && is_array($_SESSION['answers'])) {
            $ins = $pdo->prepare("INSERT INTO quiz_answers (result_id, question_id, answer_value) VALUES (?,?,?)");
            foreach ($_SESSION['answers'] as $qid => $v) { $ins->execute([$result_id, (int)$qid, (int)$v]); }
        }
        $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            die('เกิดข้อผิดพลาดในการบันทึกข้อมูล: '.$e->getMessage());
        }

        unset($_SESSION['answers']); // clear bag
        return $group_id;
}

/* ---------- history for dashboard ---------- */
function saveTestHistoryPDO(PDO $pdo, string $student_id, ?string $group, ?string $subjects, int $no_count): bool {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS test_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255) NOT NULL,
                recommended_group VARCHAR(255),
                recommended_subjects TEXT,
                no_count INT DEFAULT 0,
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $stmt = $pdo->prepare("INSERT INTO test_history (username, recommended_group, recommended_subjects, no_count) VALUES (?,?,?,?)");
        return $stmt->execute([$student_id, $group, $subjects, $no_count]);
    } catch (PDOException $e) { error_log("saveTestHistoryPDO: ".$e->getMessage()); return false; }
}

/* ---------- Main branching (unchanged core logic) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qid = isset($_POST['qid']) ? (int)$_POST['qid'] : 0;
    $val = isset($_POST['answer']) ? (int)$_POST['answer'] : -1; // 1=yes 0=no

    if (!isset($_SESSION['answers'])) $_SESSION['answers'] = [];
    if ($qid > 0 && ($val === 0 || $val === 1)) $_SESSION['answers'][$qid] = $val;
    $a = $_SESSION['answers'];

    if ($qid == 1) { if (ans(1,$a)==1) goto_q(2); else goto_q(24); }
    if ($qid == 2) { if (ans(2,$a)==1) goto_q(3); else goto_q(14); }

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

    if ($qid == 12) {
        $is_new_unique_path_E = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==0 && ans(7,$a)==0 && ans(9,$a)==0 && ans(11,$a)==1);
        $is_the_problem_path  = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==1 && ans(6,$a)==0 && ans(9,$a)==0 && ans(11,$a)==1);
        if ( ($is_new_unique_path_E || $is_the_problem_path) && ans(12,$a)==1 ) { goto_q(14); }
        else {
            $is_special_path_A = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==1 && ans(4,$a)==0 && ans(7,$a)==0 && ans(9,$a)==0 && ans(11,$a)==1);
            $is_special_path_B = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==1 && ans(6,$a)==0 && ans(9,$a)==0 && ans(11,$a)==1);
            $is_special_path_C = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==0 && ans(7,$a)==1 && ans(8,$a)==0 && ans(11,$a)==1);
            $is_special_path_D = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==0 && ans(7,$a)==0 && ans(9,$a)==1 && ans(10,$a)==1);
            if ( ($is_special_path_A || $is_special_path_B || $is_special_path_C || $is_special_path_D || $is_new_unique_path_E) && ans(12,$a)==0 ) { goto_q(14); }
            else { $RESULT_GROUP = save_and_prepare_result($pdo, $student_id=$STUDENT_ID, $group_id=1); $SHOW_RESULT = true; }
        }
    }

    if (!$SHOW_RESULT && $qid == 13) {
        $path_GoTo14_Always_1 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==1 && ans(4,$a)==0 && ans(7,$a)==0 && ans(9,$a)==0 && ans(11,$a)==0);
        $path_GoTo14_Always_2 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==0 && ans(7,$a)==1 && ans(8,$a)==0 && ans(11,$a)==0);
        $path_GoTo14_Always_3 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==0 && ans(7,$a)==0 && ans(9,$a)==1 && ans(10,$a)==0);
        $path_GoTo14_Always_4 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==0 && ans(7,$a)==0 && ans(9,$a)==0 && ans(11,$a)==0);
        if ($path_GoTo14_Always_1 || $path_GoTo14_Always_2 || $path_GoTo14_Always_3 || $path_GoTo14_Always_4) { goto_q(14); }
        else {
            $path_GoTo14_OnNo_1 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==1 && ans(4,$a)==1 && ans(6,$a)==0 && ans(9,$a)==0 && ans(11,$a)==0);
            $path_GoTo14_OnNo_2 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==1 && ans(4,$a)==0 && ans(7,$a)==1 && ans(8,$a)==0 && ans(11,$a)==0);
            $path_GoTo14_OnNo_3 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==1 && ans(6,$a)==1 && ans(8,$a)==0 && ans(11,$a)==0);
            $path_GoTo14_OnNo_4 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==1 && ans(6,$a)==0 && ans(9,$a)==1 && ans(10,$a)==0);
            $path_GoTo14_OnNo_5 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==1 && ans(6,$a)==0 && ans(9,$a)==0 && ans(11,$a)==0);
            $path_GoTo14_OnNo_6 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==0 && ans(7,$a)==1 && ans(8,$a)==1 && ans(10,$a)==0);
            $path_GoTo14_OnNo_7 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==0 && ans(5,$a)==0 && ans(7,$a)==0 && ans(9,$a)==1 && ans(10,$a)==0);
            if (($path_GoTo14_OnNo_1 || $path_GoTo14_OnNo_2 || $path_GoTo14_OnNo_3 || $path_GoTo14_OnNo_4 || $path_GoTo14_OnNo_5 || $path_GoTo14_OnNo_6 || $path_GoTo14_OnNo_7) && ans(13,$a)==0) { goto_q(14); }
            else { $RESULT_GROUP = save_and_prepare_result($pdo, $student_id=$STUDENT_ID, $group_id=1); $SHOW_RESULT = true; }
        }
    }

    if (!$SHOW_RESULT && $qid == 14) {
        $is_rejection_path_1 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==1 && ans(4,$a)==1 && ans(6,$a)==0 && ans(9,$a)==0 && ans(11,$a)==0 && ans(13,$a)==0);
        $is_rejection_path_2 = (ans(1,$a)==1 && ans(2,$a)==1 && ans(3,$a)==1 && ans(4,$a)==0 && ans(7,$a)==1 && ans(8,$a)==0 && ans(11,$a)==0 && ans(13,$a)==0);
        if ($is_rejection_path_1 || $is_rejection_path_2) { if (ans(14,$a)==0) goto_q(10); else goto_q(15); }
        else { if (ans(14,$a)==1) goto_q(15); else goto_q(24); }
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
            else { $RESULT_GROUP = save_and_prepare_result($pdo, $student_id=$STUDENT_ID, $group_id=2); $SHOW_RESULT = true; }
        }
    }

    if (!$SHOW_RESULT && $qid == 23) {
        $Y1 = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==1 && ans(16,$a)==0 && ans(19,$a)==0 && ans(21,$a)==0);
        $Y2 = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==0 && ans(17,$a)==1 && ans(18,$a)==0 && ans(21,$a)==0);
        $Y3 = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==0 && ans(17,$a)==0 && ans(19,$a)==1 && ans(20,$a)==0);
        $Y4 = (ans(1,$a)==1 && ans(2,$a)==0 && ans(14,$a)==1 && ans(15,$a)==0 && ans(17,$a)==0 && ans(19,$a)==0 && ans(21,$a)==0);
        if (($Y1||$Y2||$Y3||$Y4) && ans(23,$a)==1) { goto_q(24); }
        else { if (ans(23,$a)==1) { $RESULT_GROUP = save_and_prepare_result($pdo, $student_id=$STUDENT_ID, $group_id=2); $SHOW_RESULT = true; } else { goto_q(24); } }
    }

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
        if ($Always) goto_q(2);
        elseif (($NoA||$NoB||$NoC) && ans(32,$a)==0) goto_q(2);
        else { $RESULT_GROUP = save_and_prepare_result($pdo, $student_id=$STUDENT_ID, $group_id=3); $SHOW_RESULT = true; }
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
        if ($Force) { $RESULT_GROUP = save_and_prepare_result($pdo, $student_id=$STUDENT_ID, $group_id=3); $SHOW_RESULT = true; }
        elseif ( ($A && ans(33,$a)==1) || ($Back2 && ans(33,$a)==0) ) { goto_q(2); }
        else { if (ans(33,$a)==1) { $RESULT_GROUP = save_and_prepare_result($pdo, $student_id=$STUDENT_ID, $group_id=3); $SHOW_RESULT = true; } else { goto_q(2); } }
    }

    if (!$SHOW_RESULT) { goto_q(14); }
}

/* =========================================================
   PART 2: DISPLAY (FIX: handle start & question pages that were missing)
   ========================================================= */
$question = null;

if ($SHOW_RESULT) {
    try{
        // === ดึงชื่อกลุ่มให้ได้ชัวร์ ไม่ว่าโครงสร้างตารางจะต่างกันยังไง ===
        function pick_group_table(PDO $pdo): ?array {
            $cands = [
                ['table' => 'subject_groups', 'id' => ['group_id','id'], 'name' => ['group_name','name','title']],
                ['table' => '`groups`',       'id' => ['group_id','id'], 'name' => ['group_name','name','title']],
                ['table' => 'courses',        'id' => ['group_id'],      'name' => ['group_name','name','title']],
            ];
            foreach ($cands as $c) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
                $tbl = str_replace('`','',$c['table']);
                $st->execute([$tbl]);
                if (!(int)$st->fetchColumn()) continue;

                $cols = $pdo->query("SHOW COLUMNS FROM {$c['table']}")->fetchAll(PDO::FETCH_COLUMN, 0);
                $colsLower = array_map('strtolower',$cols);
                $idCol = null; $nameCol = null;
                foreach ($c['id'] as $x)   if (in_array(strtolower($x), $colsLower, true))   { $idCol   = $x; break; }
                foreach ($c['name'] as $x) if (in_array(strtolower($x), $colsLower, true))   { $nameCol = $x; break; }
                if ($idCol && $nameCol) return ['table'=>$c['table'],'id'=>$idCol,'name'=>$nameCol];
            }
            return null;
        }

        $RESULT_GROUP_NAME = null;
        if ($RESULT_GROUP) {
            if ($meta = pick_group_table($pdo)) {
                $sql = "SELECT `{$meta['name']}` FROM {$meta['table']} WHERE `{$meta['id']}` = ? LIMIT 1";
                $st  = $pdo->prepare($sql);
                $st->execute([$RESULT_GROUP]);
                $RESULT_GROUP_NAME = $st->fetchColumn() ?: null;
            }
        }

        
        if (!$RESULT_GROUP_NAME && $RESULT_GROUP > 0) {
            try {
                $st = $pdo->prepare("SELECT DISTINCT COALESCE(group_name, '') FROM courses WHERE group_id = ? LIMIT 1");
                $st->execute([$RESULT_GROUP]);
                $name = $st->fetchColumn();
                if ($name) $RESULT_GROUP_NAME = $name;
            } catch (Throwable $e) { /*  */ }
        }

        // 3.2 ดึงรายวิชาที่แนะนำ
        $RESULT_SUBJECTS = [];
        if ($RESULT_GROUP > 0 && table_exists($pdo,'courses')) {
            $sql = "SELECT course_code, course_name, credits, recommended_year, prereq_text
                    FROM courses
                    WHERE group_id = ?
                    ORDER BY recommended_year IS NULL, recommended_year, course_name";
            $st = $pdo->prepare($sql);
            $st->execute([$RESULT_GROUP]);
            $RESULT_SUBJECTS = $st->fetchAll(PDO::FETCH_ASSOC);
        }

        // 3.3 Fallback #1: group_id = 0
        if (empty($RESULT_SUBJECTS) && table_exists($pdo,'courses')) {
            $sql = "SELECT course_code, course_name, credits, recommended_year, prereq_text
                    FROM courses
                    WHERE group_id = 0
                    ORDER BY recommended_year IS NULL, recommended_year, course_name";
            $st = $pdo->prepare($sql);
            $st->execute();
            $RESULT_SUBJECTS = $st->fetchAll(PDO::FETCH_ASSOC);
        }

        // 3.4 Fallback #2: subjects
        if (empty($RESULT_SUBJECTS) && table_exists($pdo,'subjects')) {
            $sql = "SELECT course_code, course_name, credits, recommended_year, prereq_text
                    FROM subjects
                    WHERE (group_id = ? OR group_id IS NULL)
                    ORDER BY recommended_year IS NULL, recommended_year, course_name";
            $st = $pdo->prepare($sql);
            $st->execute([$RESULT_GROUP]);
            $RESULT_SUBJECTS = $st->fetchAll(PDO::FETCH_ASSOC);
        }

        // 3.5 Fallback #3: all courses
        if (empty($RESULT_SUBJECTS) && table_exists($pdo,'courses')) {
            $sql = "SELECT course_code, course_name, credits, recommended_year, prereq_text
                    FROM courses
                    ORDER BY recommended_year IS NULL, recommended_year, course_name
                    LIMIT 12";
            $st = $pdo->prepare($sql);
            $st->execute();
            $RESULT_SUBJECTS = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }catch(PDOException $e){
        $RESULT_GROUP_NAME=null; $RESULT_SUBJECTS=[];
    }

    // save history
    if (empty($_SESSION['final_result_saved']) && !empty($_SESSION['final_result'])) {
        $no_count = (int)($_SESSION['final_result']['no_count'] ?? 0);
        $subjects_text = '';
        if (!empty($RESULT_SUBJECTS)) {
            $names = array_map(fn($r)=>$r['course_name'] ?? '', $RESULT_SUBJECTS);
            $subjects_text = implode("\n", array_filter($names));
        }
        $group_text = $RESULT_GROUP_NAME ?: ('กลุ่มที่ '.(int)$RESULT_GROUP);
        if (saveTestHistoryPDO($pdo, (string)$STUDENT_ID, $group_text, $subjects_text, $no_count)) {
            $_SESSION['final_result_saved'] = true;
        }
    }

} elseif (isset($_GET['qid'])) {
    $current_qid = max(1, (int)$_GET['qid']);

    // ฟังก์ชันดึงคำถามแบบ fallback: question_id -> id
    $question = null;
    $stmt = $pdo->prepare("SELECT question_id, question_text FROM questions WHERE question_id = ? LIMIT 1");
    $stmt->execute([$current_qid]);
    $question = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$question) {
        // fallback ถ้าข้อมูลแถวนี้ถูกบันทึกเป็น id (จากหน้า manage_questions.php)
        $stmt = $pdo->prepare("SELECT id AS question_id, question_text FROM questions WHERE id = ? LIMIT 1");
        $stmt->execute([$current_qid]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$question) { die("ไม่พบคำถาม ID: ".h($current_qid)); }
    $SHOW_QUIZ = true;


} else {
    // ⬅️ FIX: รองรับหน้าเริ่มต้น
    unset($_SESSION['answers'], $_SESSION['final_result'], $_SESSION['final_result_saved']);
    $SHOW_START = true;
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>แบบทดสอบแนะนำรายวิชาชีพเลือก</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    :root {
        --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --success-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        --warning-gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        --card-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        --card-shadow-hover: 0 30px 60px rgba(0, 0, 0, 0.15);
        --border-radius: 20px;
        --border-radius-small: 12px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    body {
        font-family: 'Sarabun', sans-serif;
        background: var(--primary-gradient);
        min-height: 100vh;
        padding: 20px;
        line-height: 1.6;
    }

    .container {
        max-width: 900px;
        margin: 0 auto;
        position: relative;
    }

    .quiz-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border-radius: var(--border-radius);
        padding: 40px;
        box-shadow: var(--card-shadow);
        border: 1px solid rgba(255, 255, 255, 0.2);
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .quiz-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 6px;
        background: var(--primary-gradient);
    }

    .quiz-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--card-shadow-hover);
    }

    /* Header Styles */
    .quiz-header {
        text-align: center;
        margin-bottom: 40px;
    }

    .quiz-title {
        font-size: 2.2rem;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 16px;
        background: var(--primary-gradient);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .student-info {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
        padding: 12px 20px;
        border-radius: 25px;
        font-weight: 600;
        color: #374151;
        margin-bottom: 20px;
    }

    .student-info i {
        color: #3b82f6;
    }

    /* Status Pills */
    .status-container {
        display: flex;
        gap: 12px;
        justify-content: center;
        flex-wrap: wrap;
        margin-bottom: 32px;
    }

    .status-pill {
        background: linear-gradient(135deg, #f8fafc, #e2e8f0);
        padding: 10px 18px;
        border-radius: 25px;
        font-size: 0.9rem;
        font-weight: 500;
        color: #475569;
        border: 1px solid rgba(0, 0, 0, 0.08);
        transition: var(--transition);
        position: relative;
    }

    .status-pill:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
    }

    .status-pill.active {
        background: linear-gradient(135deg, #dbeafe, #bfdbfe);
        color: #1d4ed8;
        border-color: #3b82f6;
    }

    .status-pill.warning {
        background: linear-gradient(135deg, #fef3c7, #fde68a);
        color: #92400e;
        border-color: #f59e0b;
    }

    /* Button Styles */
    .btn-container {
        text-align: center;
        margin-top: 32px;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 16px 32px;
        border: none;
        border-radius: var(--border-radius-small);
        font-size: 1.1rem;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
        margin: 0 8px;
    }

    .btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: var(--transition);
    }

    .btn:hover::before {
        left: 100%;
    }

    .btn-primary {
        background: var(--primary-gradient);
        color: white;
        box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
    }

    .btn-primary:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 30px rgba(102, 126, 234, 0.4);
    }

    .btn-success {
        background: var(--success-gradient);
        color: white;
        box-shadow: 0 8px 20px rgba(79, 172, 254, 0.3);
    }

    .btn-success:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 30px rgba(79, 172, 254, 0.4);
    }

    .btn-outline {
        background: rgba(255, 255, 255, 0.9);
        border: 2px solid #e2e8f0;
        color: #475569;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    }

    .btn-outline:hover {
        background: white;
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        border-color: #cbd5e1;
    }

    .btn-warning {
        background: var(--warning-gradient);
        color: white;
        box-shadow: 0 8px 20px rgba(250, 112, 154, 0.3);
    }

    .btn-warning:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 30px rgba(250, 112, 154, 0.4);
    }

    /* Question Styles */
    .question-container {
        margin-bottom: 40px;
    }

    .question-number {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: var(--primary-gradient);
        color: white;
        padding: 8px 16px;
        border-radius: 25px;
        font-weight: 600;
        margin-bottom: 20px;
        font-size: 1rem;
    }

    .question-text {
        font-size: 1.3rem;
        font-weight: 500;
        color: #374151;
        line-height: 1.7;
        margin-bottom: 32px;
        padding: 24px;
        background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        border-radius: var(--border-radius-small);
        border-left: 5px solid #3b82f6;
    }

    /* Radio Button Styles */
    .answer-options {
        display: flex;
        gap: 20px;
        justify-content: center;
        flex-wrap: wrap;
        margin-bottom: 40px;
    }

    .radio-option {
        position: relative;
    }

    .radio-option input[type="radio"] {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
    }

    .radio-label {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 28px;
        background: rgba(255, 255, 255, 0.9);
        border: 2px solid #e2e8f0;
        border-radius: var(--border-radius-small);
        cursor: pointer;
        transition: var(--transition);
        font-size: 1.1rem;
        font-weight: 500;
        color: #475569;
        min-width: 140px;
        justify-content: center;
    }

    .radio-label:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        border-color: #3b82f6;
    }

    .radio-option input[type="radio"]:checked + .radio-label {
        background: var(--primary-gradient);
        color: white;
        border-color: #3b82f6;
        transform: translateY(-3px);
        box-shadow: 0 12px 25px rgba(59, 130, 246, 0.3);
    }

    .radio-icon {
        width: 20px;
        height: 20px;
        border: 2px solid currentColor;
        border-radius: 50%;
        position: relative;
        flex-shrink: 0;
    }

    .radio-option input[type="radio"]:checked + .radio-label .radio-icon::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 8px;
        height: 8px;
        background: white;
        border-radius: 50%;
        transform: translate(-50%, -50%);
    }

    /* Result Styles */
    .result-container {
        text-align: center;
    }

    .result-header {
        margin-bottom: 32px;
    }

    .result-title {
        font-size: 2rem;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 20px;
    }

    .result-group {
        background: var(--success-gradient);
        color: white;
        padding: 16px 32px;
        border-radius: 25px;
        font-size: 1.3rem;
        font-weight: 700;
        display: inline-block;
        margin-bottom: 32px;
        box-shadow: 0 8px 20px rgba(79, 172, 254, 0.3);
    }

    .subjects-container {
        background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        padding: 30px;
        border-radius: var(--border-radius-small);
        margin-bottom: 32px;
        text-align: left;
    }

    .subjects-title {
        font-size: 1.4rem;
        font-weight: 600;
        color: #374151;
        margin-bottom: 20px;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .subjects-list {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 12px;
        list-style: none;
    }

    .subject-item {
        background: white;
        padding: 12px 16px;
        border-radius: 8px;
        border-left: 4px solid #3b82f6;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        transition: var(--transition);
    }

    .subject-item:hover {
        transform: translateX(5px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .container {
            padding: 10px;
        }
        
        .quiz-card {
            padding: 24px;
            margin: 10px 0;
        }
        
        .quiz-title {
            font-size: 1.8rem;
        }
        
        .status-container {
            flex-direction: column;
            align-items: center;
        }
        
        .answer-options {
            flex-direction: column;
            align-items: center;
        }
        
        .radio-label {
            min-width: 200px;
        }
        
        .btn {
            width: 100%;
            max-width: 300px;
            margin: 8px 0;
        }
        
        .subjects-list {
            grid-template-columns: 1fr;
        }
    }

    /* Animation */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .quiz-card {
        animation: fadeInUp 0.6s ease-out;
    }

    /* Loading Animation */
    .loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(255, 255, 255, 0.3);
        border-radius: 50%;
        border-top-color: #fff;
        animation: spin 1s ease-in-out infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    /* Success Checkmark */
    .success-icon {
        color: #10b981;
        font-size: 1.2em;
    }

    .warning-icon {
        color: #f59e0b;
        font-size: 1.2em;
    }

    .info-icon {
        color: #3b82f6;
        font-size: 1.2em;
    }
</style>
</head>
<body>
<div class="container">
  <div class="quiz-card">
  
  <?php if ($SHOW_START): ?>
    <!-- START SCREEN -->
    <div class="quiz-header">
      <h1 class="quiz-title">
        <i class="fas fa-clipboard-list"></i>
        แบบทดสอบแนะนำรายวิชาชีพเลือก
      </h1>
      <div class="student-info">
        <i class="fas fa-user"></i>
        <span>รหัสนักศึกษา: <strong><?= h($STUDENT_ID) ?></strong></span>
      </div>
      <div class="status-container">
        <div class="status-pill <?= ($policy['used'] > 0) ? 'warning' : 'active' ?>">
          <i class="fas fa-chart-line"></i>
          ทำไปแล้ว: <strong><?= (int)$policy['used'] ?></strong> ครั้ง
        </div>
        <div class="status-pill">
          <i class="fas fa-trophy"></i>
          สิทธิ์สูงสุด: <strong><?= (int)$policy['max'] ?></strong> ครั้ง
        </div>
        <div class="status-pill <?= ($policy['status'] === 'active') ? 'active' : 'warning' ?>">
          <i class="fas fa-user-check"></i>
          สถานะ: <strong><?= h($policy['status']) ?></strong>
        </div>
      </div>
    </div>
    <div class="btn-container">
      <?php if ($policy['can']): ?>
        <a class="btn btn-primary" href="quiz.php?qid=1">
          <i class="fas fa-play"></i>
          เริ่มทำแบบทดสอบ
        </a>
        <a class="btn btn-outline" href="student_dashboard.php">
          ยกเลิก
        </a>
      <?php else: ?>
        <div style="margin-bottom: 24px; padding: 20px; background: linear-gradient(135deg, #fee2e2, #fecaca); border-radius: 12px; color: #dc2626;">
          <i class="fas fa-exclamation-triangle" style="font-size: 1.5rem; margin-bottom: 8px;"></i>
          <p style="font-weight: 600; margin: 0;">
            <strong>ไม่สามารถเริ่มทำได้:</strong><br>
            <?= ($policy['status']==='suspended')
               ? 'สถานะถูกระงับสิทธิ์ โปรดติดต่อผู้ดูแลระบบ'
               : 'คุณทำครบจำนวนครั้งที่กำหนดแล้ว' ?>
          </p>
        </div>
        <a class="btn btn-outline" href="student_dashboard.php">
          <i class="fas fa-arrow-left"></i>
          กลับหน้าแดชบอร์ด
        </a>
      <?php endif; ?>
    </div>

  <?php elseif ($SHOW_QUIZ): ?>
    <!-- QUESTION SCREEN -->
    <div class="question-text">
      <?= h($question['question_text']) ?>
    </div>
    <form method="post">
      <input type="hidden" name="qid" value="<?= (int)$question['question_id'] ?>">
      <div class="answer-options">
        <label class="radio-option">
          <input type="radio" name="answer" value="1" id="answer_yes" required>
          <span class="radio-label"><div class="radio-icon"></div><i class="fas fa-check success-icon"></i> <span>ใช่</span></span>
        </label>
        <label class="radio-option">
          <input type="radio" name="answer" value="0" id="answer_no">
          <span class="radio-label"><div class="radio-icon"></div><i class="fas fa-times warning-icon"></i> <span>ไม่ใช่</span></span>
        </label>
      </div>
      <div class="btn-container">
        <button class="btn btn-primary" type="submit">
          <i class="fas fa-arrow-right"></i>
          ข้อต่อไป
        </button>
        <a class="btn btn-outline" href="student_dashboard.php"
           onclick="return confirm('ยกเลิกการทำแบบทดสอบ? ข้อมูลที่ทำไปจะหายไป')">
          <i class="fas fa-times"></i>
          ยกเลิก
        </a>
      </div>
    </form>

  <?php elseif ($SHOW_RESULT): ?>
    <!-- RESULT SCREEN -->
    <div class="result-container">
      <div class="result-header">
        <h2 class="result-title"><i class="fas fa-trophy"></i> ผลลัพธ์ของคุณ</h2>
        <div class="result-group">
          <i class="fas fa-star"></i>
          กลุ่มที่แนะนำ: 
          <strong><?= $RESULT_GROUP_NAME ? h($RESULT_GROUP_NAME) : ('กลุ่มที่ '.(int)$RESULT_GROUP) ?></strong>
        </div>
      </div>

      <?php if (!empty($RESULT_SUBJECTS)): ?>
        <div class="subjects-container">
          <h3 class="subjects-title"><i class="fas fa-book"></i> รายวิชาที่แนะนำ</h3>
          <ul class="subjects-list">
            <?php foreach ($RESULT_SUBJECTS as $row): ?>
              <li class="subject-item">
                <div style="font-weight:600;margin-bottom:4px;"><?= h($row['course_name'] ?? '-') ?></div>
                <div style="font-size:13px;color:#555;">
                  <div>รหัส: <?= h($row['course_code'] ?? '-') ?></div>
                  <div>หน่วยกิต: <?= h($row['credits'] ?? '-') ?></div>
                  <div>ปีที่แนะนำ: <?= h($row['recommended_year'] ?? '-') ?></div>
                  <?php if (!empty($row['prereq_text'])): ?>
                    <div>วิชาบังคับก่อน: <?= h($row['prereq_text']) ?></div>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="btn-container">
        <a class="btn btn-success" href="quiz.php">
          <i class="fas fa-redo"></i>
          ทำแบบทดสอบใหม่
        </a>
        <a class="btn btn-outline" href="student_dashboard.php">
          <i class="fas fa-home"></i>
          กลับแดชบอร์ด
        </a>
      </div>
    </div>
  <?php endif; ?>

  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const form = document.querySelector('form');
  if (form) {
    form.addEventListener('submit', function() {
      const submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn) { submitBtn.innerHTML = '<div class="loading"></div> กำลังประมวลผล...'; submitBtn.disabled = true; }
    });
  }
});
</script>
</body>
</html>