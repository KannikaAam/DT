<?php
require 'db_connect.php';
if (!isset($pdo)) { die('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบไฟล์ db_connect.php'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------- STATE ----------
$group_to_edit = null;
$subject_to_edit = null;
$message = '';
$message_type = 'success';

// ---------- DELETE ----------
try {
    if (isset($_GET['delete_group'])) {
        // ถ้าต้องการลบ cascade แนะนำให้ตั้ง FK ON DELETE CASCADE ใน DB
        $stmt = $pdo->prepare("DELETE FROM subject_groups WHERE group_id = ?");
        $stmt->execute([$_GET['delete_group']]);
        header("Location: manage_groups_subjects.php?message=" . urlencode("ลบกลุ่มวิชาสำเร็จ!") . "&type=success");
        exit;
    }
    if (isset($_GET['delete_subject'])) {
        $stmt = $pdo->prepare("DELETE FROM subjects WHERE subject_id = ?");
        $stmt->execute([$_GET['delete_subject']]);
        header("Location: manage_groups_subjects.php?message=" . urlencode("ลบรายวิชาสำเร็จ!") . "&type=success");
        exit;
    }
} catch (PDOException $e) {
    header("Location: manage_groups_subjects.php?message=" . urlencode("เกิดข้อผิดพลาดในการลบ: " . $e->getMessage()) . "&type=error");
    exit;
}

// ---------- ADD / UPDATE ----------
try {
    // กลุ่มวิชา
    if (isset($_POST['add_group'])) {
        $stmt = $pdo->prepare("INSERT INTO subject_groups (group_name) VALUES (?)");
        $stmt->execute([$_POST['group_name']]);
        header("Location: manage_groups_subjects.php?message=" . urlencode("เพิ่มกลุ่มวิชาสำเร็จ!") . "&type=success");
        exit;
    }
    if (isset($_POST['update_group'])) {
        $stmt = $pdo->prepare("UPDATE subject_groups SET group_name = ? WHERE group_id = ?");
        $stmt->execute([$_POST['group_name'], $_POST['group_id']]);
        header("Location: manage_groups_subjects.php?message=" . urlencode("แก้ไขกลุ่มวิชาสำเร็จ!") . "&type=success");
        exit;
    }

    // รายวิชา
    if (isset($_POST['add_subject'])) {
        $stmt = $pdo->prepare("INSERT INTO subjects (subject_name, group_id) VALUES (?, ?)");
        $stmt->execute([$_POST['subject_name'], $_POST['group_id_for_subject']]);
        header("Location: manage_groups_subjects.php?message=" . urlencode("เพิ่มรายวิชาสำเร็จ!") . "&type=success");
        exit;
    }
    if (isset($_POST['update_subject'])) {
        $stmt = $pdo->prepare("UPDATE subjects SET subject_name = ?, group_id = ? WHERE subject_id = ?");
        $stmt->execute([$_POST['subject_name'], $_POST['group_id_for_subject'], $_POST['subject_id']]);
        header("Location: manage_groups_subjects.php?message=" . urlencode("แก้ไขรายวิชาสำเร็จ!") . "&type=success");
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
    if (isset($_GET['edit_group'])) {
        $stmt = $pdo->prepare("SELECT * FROM subject_groups WHERE group_id = ?");
        $stmt->execute([$_GET['edit_group']]);
        $group_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (isset($_GET['edit_subject'])) {
        $stmt = $pdo->prepare("SELECT * FROM subjects WHERE subject_id = ?");
        $stmt->execute([$_GET['edit_subject']]);
        $subject_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $message = "ไม่สามารถดึงข้อมูลเพื่อแก้ไขได้: " . $e->getMessage();
    $message_type = 'error';
}

// ---------- LIST FETCH ----------
try {
    $groups = $pdo->query("SELECT * FROM subject_groups ORDER BY group_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $subjects_sql = "
        SELECT s.subject_id, s.subject_name, g.group_name, g.group_id
        FROM subjects s
        JOIN subject_groups g ON s.group_id = g.group_id
        ORDER BY g.group_name, s.subject_name
    ";
    $subjects = $pdo->query($subjects_sql)->fetchAll(PDO::FETCH_ASSOC);
    $stats = ['groups' => count($groups), 'subjects' => count($subjects)];
} catch (PDOException $e) {
    $message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $message_type = 'error';
    $groups = $subjects = [];
    $stats = ['groups' => 0, 'subjects' => 0];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>จัดการกลุ่มวิชาและรายวิชา</title>
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
    .container{display:grid;grid-template-columns:repeat(auto-fit,minmax(350px,1fr));gap:1.5rem;padding:0 1.5rem 1.5rem;max-width:1400px;margin:0 auto}
    .column{background:var(--card-bg-color);border-radius:var(--card-radius);box-shadow:var(--card-shadow);padding:1.5rem;transition:.3s}
    .column:hover{transform:translateY(-5px);box-shadow:0 8px 12px rgba(0,0,0,.12)}
    .column-header{display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:2px solid var(--border-color)}
    .column-header i{font-size:1.5rem;color:var(--primary-color)}
    .form-section{background:#f8f9fa;padding:1.5rem;border-radius:10px;margin-bottom:1.5rem;border:1px solid var(--border-color)}
    .form-section.editing{background:#fff9e6;border-color:var(--warning-color)}
    .form-section h3{font-size:1.1rem;font-weight:600;margin-bottom:1rem;display:flex;gap:.5rem;align-items:center}
    .form-group{margin-bottom:1rem}
    label{display:block;margin-bottom:.5rem;font-weight:500;color:var(--text-light-color)}
    input,select{width:100%;padding:.75rem 1rem;border:1px solid var(--border-color);border-radius:8px;font-size:1rem;font-family:var(--font-family)}
    .btn{background:linear-gradient(135deg,var(--primary-color) 0%,var(--secondary-color) 100%);color:#fff;border:none;padding:.75rem 1.5rem;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;width:100%}
    .cancel-link{display:block;text-align:center;margin-top:.75rem;color:var(--text-light-color);text-decoration:none;font-weight:500}
    .data-list{max-height:400px;overflow-y:auto;padding-right:.5rem}
    .data-item{display:flex;justify-content:space-between;align-items:center;background:#f8f9fa;border-radius:8px;padding:1rem;margin-bottom:.75rem;border-left:4px solid var(--secondary-color)}
    .data-item-content strong{display:block;font-weight:600}
    .data-item-content small{color:var(--text-light-color)}
    .action-btn{width:32px;height:32px;border-radius:50%;color:#fff;display:inline-flex;justify-content:center;align-items:center;text-decoration:none}
    .edit-btn{background:var(--warning-color)}.delete-btn{background:var(--danger-color)}
</style>
</head>
<body>
<header class="header">
    <h1><i class="fas fa-tools"></i> จัดการกลุ่มวิชา & รายวิชา</h1>
    <div class="stats-bar">
        <div class="stat-item"><i class="fas fa-layer-group"></i><span><?= $stats['groups'] ?> กลุ่มวิชา</span></div>
        <div class="stat-item"><i class="fas fa-book"></i><span><?= $stats['subjects'] ?> รายวิชา</span></div>
        <div class="stat-item"><i class="fas fa-question-circle"></i><a href="manage_questions.php" style="color:#fff;text-decoration:underline;">ไปหน้าจัดการคำถาม</a></div>
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
    <!-- กลุ่มวิชา -->
    <section class="column" id="group-form">
        <header class="column-header"><i class="fas fa-layer-group"></i><h2>จัดการกลุ่มวิชา</h2></header>
        <div class="form-section <?= $group_to_edit ? 'editing' : '' ?>">
            <?php if ($group_to_edit): ?>
            <h3><i class="fas fa-edit"></i> แก้ไขกลุ่มวิชา</h3>
            <form method="POST" action="manage_groups_subjects.php">
                <input type="hidden" name="group_id" value="<?= $group_to_edit['group_id'] ?>">
                <div class="form-group">
                    <label for="group_name">ชื่อกลุ่มวิชา</label>
                    <input type="text" name="group_name" value="<?= htmlspecialchars($group_to_edit['group_name']) ?>" required>
                </div>
                <button type="submit" name="update_group" class="btn"><i class="fas fa-save"></i> บันทึก</button>
                <a class="cancel-link" href="manage_groups_subjects.php">ยกเลิก</a>
            </form>
            <?php else: ?>
            <h3><i class="fas fa-plus-circle"></i> เพิ่มกลุ่มวิชาใหม่</h3>
            <form method="POST" action="manage_groups_subjects.php">
                <div class="form-group">
                    <label for="group_name">ชื่อกลุ่มวิชา</label>
                    <input type="text" name="group_name" placeholder="เช่น เทคโนโลยีสารสนเทศ" required>
                </div>
                <button type="submit" name="add_group" class="btn"><i class="fas fa-plus-circle"></i> เพิ่ม</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="data-list">
            <h3>กลุ่มวิชาที่มีอยู่ (<?= count($groups) ?>)</h3>
            <ul style="list-style:none;padding-left:0">
                <?php foreach ($groups as $group): ?>
                <li class="data-item">
                    <div class="data-item-content">
                        <strong><?= htmlspecialchars($group['group_name']) ?></strong>
                    </div>
                    <div class="data-item-actions" style="display:flex;gap:.5rem">
                        <a class="action-btn edit-btn" title="แก้ไข" href="manage_groups_subjects.php?edit_group=<?= $group['group_id'] ?>#group-form"><i class="fas fa-pen"></i></a>
                        <a class="action-btn delete-btn" title="ลบ" href="manage_groups_subjects.php?delete_group=<?= $group['group_id'] ?>" onclick="return confirm('ยืนยันลบกลุ่มวิชานี้? อาจมีผลกับรายวิชา/คำถามที่เกี่ยวข้อง')"><i class="fas fa-trash"></i></a>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>

    <!-- รายวิชา -->
    <!-- รายวิชา -->
    <section class="column" id="subject-form">
        <header class="column-header"><i class="fas fa-book"></i><h2>จัดการรายวิชา</h2></header>
        <div class="form-section <?= $subject_to_edit ? 'editing' : '' ?>">
            <?php if ($subject_to_edit): ?>
            <!-- โหมดแก้ไข: (คงรูปแบบเดิมไว้ให้แก้ชื่อได้ทันที) -->
            <h3><i class="fas fa-edit"></i> แก้ไขรายวิชา</h3>
            <form method="POST" action="manage_groups_subjects.php">
                <input type="hidden" name="subject_id" value="<?= $subject_to_edit['subject_id'] ?>">
                <div class="form-group">
                    <label for="subject_name">ชื่อรายวิชา</label>
                    <input type="text" name="subject_name" value="<?= htmlspecialchars($subject_to_edit['subject_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="group_id_for_subject">กลุ่มวิชา</label>
                    <select name="group_id_for_subject" required>
                        <?php foreach ($groups as $group): ?>
                        <option value="<?= $group['group_id'] ?>" <?= ($subject_to_edit['group_id']==$group['group_id'])?'selected':'' ?>>
                            <?= htmlspecialchars($group['group_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="update_subject" class="btn"><i class="fas fa-save"></i> บันทึก</button>
                <a class="cancel-link" href="manage_groups_subjects.php">ยกเลิก</a>
            </form>

            <?php else: ?>
            <!-- โหมดเพิ่ม: เลือกหลักสูตร -> เลือกรายวิชา (ดึงจาก course_management.php) -->
            <h3><i class="fas fa-plus-circle"></i> เพิ่มรายวิชาใหม่</h3>
            <form method="POST" action="manage_groups_subjects.php" id="add-subject-form">
                <div class="form-group">
                    <label for="curriculum_select">เลือกหลักสูตร</label>
                    <select id="curriculum_select">
                        <option value="">-- เลือกหลักสูตร --</option>
                        <!-- จะเติมด้วย JS จาก API -->
                    </select>
                </div>

                <div class="form-group">
                    <label for="course_select">เลือกรายวิชาในหลักสูตร</label>
                    <select id="course_select" disabled>
                        <option value="">-- เลือกรายวิชา --</option>
                        <!-- จะเติมด้วย JS จาก API -->
                    </select>
                </div>

                <div class="form-group">
                    <label for="group_id_for_subject">สังกัดกลุ่มวิชา</label>
                    <select name="group_id_for_subject" required>
                        <option value="">-- เลือกกลุ่มวิชา --</option>
                        <?php foreach ($groups as $group): ?>
                        <option value="<?= $group['group_id'] ?>"><?= htmlspecialchars($group['group_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- เก็บชื่อรายวิชาจริงจาก option ที่เลือก ส่งให้ PHP ใช้ insert -->
                <input type="hidden" name="subject_name" id="subject_name">

                <button type="submit" name="add_subject" class="btn" <?= empty($groups)?'disabled':'' ?>>
                    <i class="fas fa-plus-circle"></i> เพิ่ม
                </button>
            </form>
            <?php endif; ?>
        </div>

        <div class="data-list">
            <h3>รายวิชาทั้งหมด (<?= count($subjects) ?>)</h3>
            <ul style="list-style:none;padding-left:0">
                <?php foreach ($subjects as $subject): ?>
                <li class="data-item">
                    <div class="data-item-content">
                        <strong><?= htmlspecialchars($subject['subject_name']) ?></strong>
                        <small>กลุ่ม: <?= htmlspecialchars($subject['group_name']) ?></small>
                    </div>
                    <div class="data-item-actions" style="display:flex;gap:.5rem">
                        <a class="action-btn edit-btn" title="แก้ไข" href="manage_groups_subjects.php?edit_subject=<?= $subject['subject_id'] ?>#subject-form"><i class="fas fa-pen"></i></a>
                        <a class="action-btn delete-btn" title="ลบ" href="manage_groups_subjects.php?delete_subject=<?= $subject['subject_id'] ?>" onclick="return confirm('ยืนยันลบรายวิชานี้?')"><i class="fas fa-trash"></i></a>
                    </div>
                </li>
                <?php endforeach; ?>
                <?php if (empty($subjects)): ?>
                <li style="padding:1rem;color:#666">ยังไม่มีรายวิชา</li>
                <?php endif; ?>
            </ul>
        </div>
    </section>

</main>

<script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  // ===== ของเดิม: auto-hide message =====
  const msg = document.querySelector('.message');
  if (msg) {
    setTimeout(()=>{
      msg.style.transition='opacity .5s, transform .5s';
      msg.style.opacity='0';
      msg.style.transform='translateY(-20px)';
      setTimeout(()=>msg.remove(),500);
    }, 5000);
  }

  // ===== ใหม่: โหลดหลักสูตร/รายวิชาจาก API =====
  const curriculumSelect = document.getElementById('curriculum_select');
  const courseSelect     = document.getElementById('course_select');
  const subjectNameInput = document.getElementById('subject_name');
  const formAdd          = document.getElementById('add-subject-form');

  // ไม่มีโหมดเพิ่ม → ไม่ต้องทำอะไรต่อ
  if (!formAdd || !curriculumSelect || !courseSelect || !subjectNameInput) return;

  // 1) โหลด "หลักสูตร"
  (async function loadCurricula(){
    try {
      const res  = await fetch('course_management.php?ajax=curricula', {cache:'no-store'});
      const json = await res.json();
      if (!json.ok) throw new Error(json.error || 'โหลดหลักสูตรไม่สำเร็จ');
      (json.curricula || []).forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.curriculum_value;
        opt.textContent = item.curriculum_label;
        curriculumSelect.appendChild(opt);
      });
    } catch (err) {
      alert('เกิดข้อผิดพลาดในการโหลดหลักสูตร: ' + err.message);
    }
  })();

  // 2) เมื่อเลือกหลักสูตร → โหลด "รายวิชา"
  curriculumSelect.addEventListener('change', async () => {
    courseSelect.innerHTML = '<option value="">-- เลือกรายวิชา --</option>';
    courseSelect.disabled = true;
    subjectNameInput.value = '';

    const curr = curriculumSelect.value.trim();
    if (!curr) return;

    try {
      const res  = await fetch('course_management.php?ajax=courses_by_curriculum&curriculum_name=' + encodeURIComponent(curr), {cache:'no-store'});
      const json = await res.json();
      if (!json.ok) throw new Error(json.error || 'โหลดรายวิชาไม่สำเร็จ');

      (json.courses || []).forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.course_id; // เก็บ id ไว้ใน value (อนาคตจะขยายเก็บ course_id ใน DB ได้)
        opt.textContent = (c.course_code ? c.course_code + ' - ' : '') + c.course_name;
        opt.dataset.courseName = c.course_name; // ใช้ set ลง hidden ตอน submit
        courseSelect.appendChild(opt);
      });
      courseSelect.disabled = false;
    } catch (err) {
      alert('เกิดข้อผิดพลาดในการโหลดรายวิชา: ' + err.message);
    }
  });

  // 3) เมื่อเลือกรายวิชา → เซ็ตชื่อจริงลง hidden ให้ PHP
  courseSelect.addEventListener('change', () => {
    const sel = courseSelect.options[courseSelect.selectedIndex];
    subjectNameInput.value = sel?.dataset?.courseName || '';
  });

  // 4) ก่อน submit: validate ว่ามีชื่อวิชาแล้ว
  formAdd.addEventListener('submit', (e) => {
    if (!subjectNameInput.value.trim()) {
      e.preventDefault();
      alert('กรุณาเลือกหลักสูตรและรายวิชา');
    }
  });
});
</script>

</body>
</html>
