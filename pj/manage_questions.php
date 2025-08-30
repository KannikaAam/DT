<?php
// manage_questions.php
require 'db_connect.php';
if (!isset($pdo)) { die('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบไฟล์ db_connect.php'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------- STATE ----------
$question_to_edit = null;
$message = '';
$message_type = 'success';

// ---------- DELETE ----------
try {
    if (isset($_GET['delete_question'])) {
        $stmt = $pdo->prepare("DELETE FROM questions WHERE question_id = ?");
        $stmt->execute([$_GET['delete_question']]);
        header("Location: manage_questions.php?message=" . urlencode("ลบคำถามสำเร็จ!") . "&type=success");
        exit;
    }
} catch (PDOException $e) {
    header("Location: manage_questions.php?message=" . urlencode("เกิดข้อผิดพลาดในการลบ: " . $e->getMessage()) . "&type=error");
    exit;
}

// ---------- ADD / UPDATE ----------
try {
    if (isset($_POST['add_question'])) {
        $stmt = $pdo->prepare("INSERT INTO questions (question_text, group_id) VALUES (?, ?)");
        $stmt->execute([$_POST['question_text'], $_POST['group_id_for_question']]);
        header("Location: manage_questions.php?message=" . urlencode("เพิ่มคำถามสำเร็จ!") . "&type=success");
        exit;
    }
    if (isset($_POST['update_question'])) {
        $stmt = $pdo->prepare("UPDATE questions SET question_text = ?, group_id = ? WHERE question_id = ?");
        $stmt->execute([$_POST['question_text'], $_POST['group_id_for_question'], $_POST['question_id']]);
        header("Location: manage_questions.php?message=" . urlencode("แก้ไขคำถามสำเร็จ!") . "&type=success");
        exit;
    }
} catch (PDOException $e) {
    $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    $message_type = 'error';
}

// ---------- MESSAGE ----------
if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $message_type = $_GET['type'] ?? 'success';
}

// ---------- EDIT FETCH ----------
try {
    if (isset($_GET['edit_question'])) {
        $stmt = $pdo->prepare("SELECT * FROM questions WHERE question_id = ?");
        $stmt->execute([$_GET['edit_question']]);
        $question_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $message = "ไม่สามารถดึงข้อมูลเพื่อแก้ไขได้: " . $e->getMessage();
    $message_type = 'error';
}

// ---------- LIST FETCH ----------
try {
    $groups = $pdo->query("SELECT * FROM subject_groups ORDER BY group_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $questions_sql = "
        SELECT q.question_id, q.question_text, g.group_name, g.group_id
        FROM questions q
        LEFT JOIN subject_groups g ON q.group_id = g.group_id
        ORDER BY q.question_id DESC
    ";
    $questions = $pdo->query($questions_sql)->fetchAll(PDO::FETCH_ASSOC);
    $stats = ['questions' => count($questions)];
} catch (PDOException $e) {
    $message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $message_type = 'error';
    $groups = [];
    $questions = [];
    $stats = ['questions' => 0];
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>จัดการคำถาม</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
<style>
    :root{
        --primary-color:#6a11cb;--secondary-color:#2575fc;--background-color:#f0f2f5;--card-bg-color:#fff;
        --text-color:#333;--text-light-color:#666;--border-color:#e0e0e0;--success-color:#28a745;
        --warning-color:#ffc107;--danger-color:#dc3545;--font-family:'Sarabun',sans-serif;--card-shadow:0 4px 6px rgba(0,0,0,.1);--card-radius:12px;
    }
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:var(--font-family);background:var(--background-color);color:var(--text-color);line-height:1.7}
    .header{background:linear-gradient(135deg,var(--primary-color) 0%,var(--secondary-color) 100%);color:#fff;padding:2rem 1.5rem;text-align:center;border-bottom-left-radius:20px;border-bottom-right-radius:20px;margin-bottom:2rem}
    .header h1{font-size:2rem;font-weight:700}
    .stats-bar{display:flex;justify-content:center;gap:1rem;margin-top:.8rem;flex-wrap:wrap}
    .stat-item{display:flex;align-items:center;gap:.5rem;background:rgba(255,255,255,.15);padding:.5rem 1rem;border-radius:50px;font-weight:500}
    .message{text-align:center;padding:1rem;margin:0 1.5rem 1.5rem;border-radius:var(--card-radius);font-weight:500;display:flex;align-items:center;justify-content:center;gap:.75rem}
    .message.success{background:var(--success-color);color:#fff}.message.error{background:var(--danger-color);color:#fff}
    .container{display:grid;grid-template-columns:repeat(auto-fit,minmax(350px,1fr));gap:1.5rem;padding:0 1.5rem 1.5rem;max-width:1200px;margin:0 auto}
    .column{background:var(--card-bg-color);border-radius:var(--card-radius);box-shadow:var(--card-shadow);padding:1.5rem;transition:.3s}
    .column:hover{transform:translateY(-5px);box-shadow:0 8px 12px rgba(0,0,0,.12)}
    .column-header{display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:2px solid var(--border-color)}
    .column-header i{font-size:1.5rem;color:var(--primary-color)}
    .form-section{background:#f8f9fa;padding:1.5rem;border-radius:10px;margin-bottom:1.5rem;border:1px solid var(--border-color)}
    .form-section.editing{background:#fff9e6;border-color:var(--warning-color)}
    .form-section h3{font-size:1.1rem;font-weight:600;margin-bottom:1rem;display:flex;gap:.5rem;align-items:center}
    .form-group{margin-bottom:1rem}
    label{display:block;margin-bottom:.5rem;font-weight:500;color:var(--text-light-color)}
    textarea,select{width:100%;padding:.75rem 1rem;border:1px solid var(--border-color);border-radius:8px;font-size:1rem;font-family:var(--font-family)}
    .btn{background:linear-gradient(135deg,var(--primary-color) 0%,var(--secondary-color) 100%);color:#fff;border:none;padding:.75rem 1.5rem;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;width:100%}
    .cancel-link{display:block;text-align:center;margin-top:.75rem;color:var(--text-light-color);text-decoration:none;font-weight:500}
    .data-list{max-height:560px;overflow-y:auto;padding-right:.5rem}
    .data-item{display:flex;justify-content:space-between;align-items:center;background:#f8f9fa;border-radius:8px;padding:1rem;margin-bottom:.75rem;border-left:4px solid var(--secondary-color)}
    .data-item-content strong{display:block;font-weight:600}
    .data-item-content small{color:var(--text-light-color)}
    .action-btn{width:32px;height:32px;border-radius:50%;color:#fff;display:inline-flex;justify-content:center;align-items:center;text-decoration:none}
    .edit-btn{background:var(--warning-color)}.delete-btn{background:var(--danger-color)}
</style>
</head>
<body>
<header class="header">
    <h1><i class="fas fa-question-circle"></i> จัดการคำถาม</h1>
    <div class="stats-bar">
        <div class="stat-item"><i class="fas fa-database"></i><span><?= $stats['questions'] ?> คำถาม</span></div>
        <div class="stat-item"><i class="fas fa-layer-group"></i><a href="manage_recommended_groups.php" style="color:#fff;text-decoration:underline;">ไปหน้ากลุ่ม/รายวิชา</a></div>
        <div class="stat-item"><i class="fas fa-home"></i><a href="admin_dashboard.php" style="color:#fff;text-decoration:underline;">กลับหน้าหลัก</a></div>
    </div>
</header>

<?php if ($message): ?>
<div class="message <?= $message_type ?>">
    <i class="fas <?= $message_type == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i>
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<main class="container">
    <!-- ฟอร์มคำถาม -->
    <section class="column" id="question-form">
        <header class="column-header"><i class="fas fa-pen-to-square"></i><h2>จัดการคำถาม</h2></header>
        <div class="form-section <?= $question_to_edit ? 'editing' : '' ?>">
            <?php if ($question_to_edit): ?>
            <h3><i class="fas fa-edit"></i> แก้ไขคำถาม</h3>
            <form method="POST" action="manage_questions.php">
                <input type="hidden" name="question_id" value="<?= $question_to_edit['question_id'] ?>">
                <div class="form-group">
                    <label for="question_text">ข้อความคำถาม</label>
                    <textarea name="question_text" rows="4" required><?= htmlspecialchars($question_to_edit['question_text']) ?></textarea>
                </div>
                <div class="form-group">
                    <label for="group_id_for_question">ให้คะแนนกลุ่ม</label>
                    <select name="group_id_for_question" required>
                        <?php foreach ($groups as $group): ?>
                        <option value="<?= $group['group_id'] ?>" <?= ($question_to_edit['group_id']==$group['group_id'])?'selected':'' ?>>
                            <?= htmlspecialchars($group['group_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="update_question" class="btn"><i class="fas fa-save"></i> บันทึก</button>
                <a class="cancel-link" href="manage_questions.php">ยกเลิก</a>
            </form>
            <?php else: ?>
            <h3><i class="fas fa-plus-circle"></i> เพิ่มคำถามใหม่</h3>
            <form method="POST" action="manage_questions.php">
                <div class="form-group">
                    <label for="question_text">ข้อความคำถาม</label>
                    <textarea name="question_text" placeholder="เช่น คุณชอบแก้ไขปัญหาที่ซับซ้อนด้วยตรรกะหรือไม่?" rows="4" required></textarea>
                </div>
                <div class="form-group">
                    <label for="group_id_for_question">ให้คะแนนกลุ่ม</label>
                    <select name="group_id_for_question" required>
                        <option value="">-- เลือกกลุ่มวิชา --</option>
                        <?php foreach ($groups as $group): ?>
                        <option value="<?= $group['group_id'] ?>"><?= htmlspecialchars($group['group_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="add_question" class="btn" <?= empty($groups)?'disabled':'' ?>><i class="fas fa-plus-circle"></i> เพิ่มคำถาม</button>
            </form>
            <?php endif; ?>
        </div>
    </section>

    <!-- รายการคำถาม -->
    <section class="column">
        <header class="column-header"><i class="fas fa-list"></i><h2>คลังคำถามทั้งหมด</h2></header>
        <div class="data-list">
            <ul style="list-style:none;padding-left:0">
                <?php foreach ($questions as $question): ?>
                <li class="data-item">
                    <div class="data-item-content">
                        <strong>"<?= htmlspecialchars($question['question_text']) ?>"</strong>
                        <small>ให้คะแนนกลุ่ม: <?= htmlspecialchars($question['group_name'] ?? 'N/A') ?></small>
                    </div>
                    <div class="data-item-actions" style="display:flex;gap:.5rem">
                        <a class="action-btn edit-btn" title="แก้ไข" href="manage_questions.php?edit_question=<?= $question['question_id'] ?>#question-form"><i class="fas fa-pen"></i></a>
                        <a class="action-btn delete-btn" title="ลบ" href="manage_questions.php?delete_question=<?= $question['question_id'] ?>" onclick="return confirm('ยืนยันลบคำถามนี้?')"><i class="fas fa-trash"></i></a>
                    </div>
                </li>
                <?php endforeach; ?>
                <?php if (empty($questions)): ?>
                <li style="padding:1rem;color:#666">ยังไม่มีคำถาม</li>
                <?php endif; ?>
            </ul>
        </div>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const msg = document.querySelector('.message');
    if (msg) {
        setTimeout(()=>{ msg.style.transition='opacity .5s, transform .5s'; msg.style.opacity='0'; msg.style.transform='translateY(-20px)'; setTimeout(()=>msg.remove(),500); }, 5000);
    }
});
</script>
</body>
</html>
