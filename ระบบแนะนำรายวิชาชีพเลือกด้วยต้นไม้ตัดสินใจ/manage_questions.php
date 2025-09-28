<?php
// manage_questions.php — กรองตามกลุ่ม + ลบ/แก้ไข + เพิ่มหลายข้อแบบกำหนดกลุ่ม/จำนวนได้ไม่จำกัด + Export/Import CSV + คงลำดับในกลุ่ม (order_in_group)

require __DIR__ . '/db_connect.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบไฟล์ db_connect.php');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------------- Schema guard: ensure order_in_group ---------------- */
function hasColumn(PDO $pdo, $table, $col){
    $st = $pdo->prepare("SELECT COUNT(*) c 
                         FROM information_schema.COLUMNS 
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table,$col]);
    return (int)$st->fetchColumn() > 0;
}
if (!hasColumn($pdo, 'questions', 'order_in_group')) {
    $pdo->exec("ALTER TABLE questions ADD COLUMN order_in_group INT NOT NULL DEFAULT 0");
}

/* ---------------- Helpers ---------------- */
function nextOrderForGroup(PDO $pdo, $group_id){
    $st = $pdo->prepare("SELECT COALESCE(MAX(order_in_group),0)+1 FROM questions WHERE group_id = ?");
    $st->execute([$group_id]);
    return (int)$st->fetchColumn();
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------------- EXPORT CSV ---------------- */
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="questions_'.date('Ymd_His').'.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM สำหรับ Excel

    $out = fopen('php://output', 'w');

    if ($_GET['export'] === 'questions') {
        // ส่งออกข้อมูลจริงทั้งหมด (เรียงตามกลุ่มแล้วตามลำดับในกลุ่ม)
        fputcsv($out, ['คำถาม','กลุ่มรายวิชา','ลำดับในกลุ่ม']);
        $stmt = $pdo->query("
            SELECT q.question_text, g.group_name, q.order_in_group
            FROM questions q
            LEFT JOIN subject_groups g ON q.group_id = g.group_id
            ORDER BY g.group_name ASC, q.order_in_group ASC, q.question_id ASC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [$row['question_text'], $row['group_name'], $row['order_in_group']]);
        }
    } else {
        // ส่งออกเฉพาะหัวตาราง (เป็นแม่แบบ)
        fputcsv($out, ['question_text','group_id (หรือ group_name)']);
    }
    fclose($out);
    exit;
}

/* ---------------- Messages ---------------- */
$message = '';
$message_type = 'success';
if (isset($_GET['message'])) {
    $message = (string)$_GET['message'];
    $message_type = (string)($_GET['type'] ?? 'success');
}

/* ---------------- Actions: add/edit/delete single ---------------- */
try {
    // เพิ่มทีละข้อ
    if (isset($_POST['add_one'])) {
        $qtext = trim($_POST['question_text'] ?? '');
        $gid = trim($_POST['group_id_for_question'] ?? '');
        if ($qtext === '' || $gid === '') {
            throw new Exception('กรุณากรอกคำถามและเลือกกลุ่ม');
        }
        $order = nextOrderForGroup($pdo, $gid);
        $ins = $pdo->prepare("INSERT INTO questions (question_text, group_id, order_in_group) VALUES (?,?,?)");
        $ins->execute([$qtext, $gid, $order]);
        header("Location: manage_questions.php?message=" . urlencode("เพิ่มคำถามสำเร็จ!") . "&type=success"); exit;
    }

    // แก้ไขคำถาม
    if (isset($_POST['update_question'])) {
        $qid = (int)($_POST['question_id'] ?? 0);
        $qtext = trim($_POST['question_text'] ?? '');
        $gid = trim($_POST['group_id_for_question'] ?? '');
        if ($qid<=0 || $qtext==='' || $gid==='') {
            throw new Exception('ข้อมูลแก้ไขไม่ครบถ้วน');
        }
        $stmt = $pdo->prepare("UPDATE questions SET question_text = ?, group_id = ? WHERE question_id = ?");
        $stmt->execute([$qtext, $gid, $qid]);
        header("Location: manage_questions.php?message=" . urlencode("แก้ไขคำถามสำเร็จ!") . "&type=success"); exit;
    }

    // ลบคำถามเดี่ยว
    if (isset($_POST['delete_question'])) {
        $qid = (int)($_POST['question_id'] ?? 0);
        if ($qid <= 0) throw new Exception('ไม่พบคำถามที่ต้องการลบ');
        $pdo->prepare("DELETE FROM questions WHERE question_id = ?")->execute([$qid]);
        header("Location: manage_questions.php?message=" . urlencode("ลบคำถามสำเร็จ!") . "&type=success"); exit;
    }

    // ลบทั้งหมดในกลุ่ม
    if (isset($_POST['delete_all_in_group'])) {
        $gid = trim($_POST['group_id_delete_all'] ?? '');
        if ($gid==='') throw new Exception('กรุณาเลือกกลุ่มที่จะลบทั้งหมด');
        $pdo->prepare("DELETE FROM questions WHERE group_id = ?")->execute([$gid]);
        header("Location: manage_questions.php?message=" . urlencode("ลบคำถามทั้งหมดของกลุ่มที่เลือกแล้ว") . "&type=success"); exit;
    }
} catch (Throwable $e) {
    $message = "เกิดข้อผิดพลาด: ".$e->getMessage();
    $message_type = 'error';
}

/* ---------------- ADD BULK (ตามลำดับบรรทัด) ----------------
   โหมด:
   - one    : ใส่ทุกบรรทัดลงกลุ่มเดียว
   - multi  : แบ่งใส่ "หลายกลุ่มไม่จำกัดแถว" โดยกำหนดจำนวนข้อของแต่ละกลุ่มได้
---------------------------------------------------------------- */
try {
    if (isset($_POST['add_bulk_questions'])) {
        $mode = $_POST['bulk_mode'] ?? 'one';
        $raw  = trim($_POST['bulk_questions'] ?? '');
        if ($raw === '') {
            header("Location: manage_questions.php?message=" . urlencode("กรุณาวางคำถามอย่างน้อย 1 บรรทัด") . "&type=error"); exit;
        }
        $lines = array_values(array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", $raw)), fn($s)=>$s!==''));

        $perGroupNext = [];
        $ins = $pdo->prepare("INSERT INTO questions (question_text, group_id, order_in_group) VALUES (?,?,?)");

        $pdo->beginTransaction();
        try {
            $ok=0; $ignored=0;

            if ($mode === 'one') {
                $gid = $_POST['bulk_group_id'] ?? '';
                if ($gid===''){ throw new Exception("กรุณาเลือกกลุ่มสำหรับโหมดใส่ลงกลุ่มเดียว"); }
                if (!isset($perGroupNext[$gid])) $perGroupNext[$gid] = nextOrderForGroup($pdo, $gid);
                foreach ($lines as $qtext){
                    $ins->execute([$qtext, $gid, $perGroupNext[$gid]++]);
                    $ok++;
                }
            } else {
                // โหมดหลายกลุ่ม (ไม่จำกัดจำนวนแถว)
                $bulk_groups = $_POST['bulk_groups'] ?? [];
                $bulk_counts = $_POST['bulk_counts'] ?? [];
                if (!is_array($bulk_groups) || !is_array($bulk_counts)) {
                    throw new Exception("ข้อมูลกลุ่ม/จำนวน ไม่ถูกต้อง");
                }

                // สร้างโควตา [ [gid, count], ... ] เฉพาะแถวที่กรอกครบและ count > 0
                $quota = [];
                for ($i=0; $i<count($bulk_groups); $i++) {
                    $gid = trim((string)$bulk_groups[$i]);
                    $cnt = max(0, (int)($bulk_counts[$i] ?? 0));
                    if ($gid!=='' && $cnt>0) {
                        $quota[] = [$gid, $cnt];
                    }
                }
                if (empty($quota)) {
                    throw new Exception("กรุณาเพิ่มอย่างน้อย 1 แถว (เลือกกลุ่มและกำหนดจำนวนข้อมากกว่า 0)");
                }

                $quotaTotal = 0;
                foreach ($quota as $q) { $quotaTotal += $q[1]; }

                $idx = 0;
                foreach ($quota as [$gid,$cnt]) {
                    if (!isset($perGroupNext[$gid])) $perGroupNext[$gid] = nextOrderForGroup($pdo, $gid);
                    for ($i=0; $i<$cnt; $i++) {
                        if (!isset($lines[$idx])) break 2; // ออกทั้งสองลูปเมื่อหมดบรรทัด
                        $qtext = $lines[$idx++];
                        $ins->execute([$qtext, $gid, $perGroupNext[$gid]++]);
                        $ok++;
                    }
                }
                if (count($lines) > $quotaTotal) {
                    $ignored = count($lines) - $quotaTotal;
                }
            }

            $pdo->commit();

            // สร้างข้อความ dynamic
            if ($mode === 'one') {
                $msg = "เพิ่มคำถามสำเร็จ {$ok} ข้อ (ลงกลุ่มเดียว)";
                if ($ignored>0) $msg .= " (ตัดทิ้ง {$ignored} ข้อเกินจำนวนที่กรอก)";
            } else {
                $msg = "เพิ่มคำถามแบบหลายกลุ่มสำเร็จ {$ok} ข้อ";
                if (!empty($quota)) {
                    $parts = array_map(function($p){ return $p[1]; }, $quota);
                    $sum = array_sum($parts);
                    $msg .= " (รวม ".implode("+",$parts)." = {$sum})";
                }
                if ($ignored>0) $msg .= " — ตัดทิ้ง {$ignored} ข้อ (เกินจำนวนที่กำหนด)";
            }

            header("Location: manage_questions.php?message=" . urlencode($msg) . "&type=success"); exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            header("Location: manage_questions.php?message=" . urlencode("เพิ่มแบบหลายกลุ่มไม่สำเร็จ: ".$e->getMessage()) . "&type=error"); exit;
        }
    }
} catch (Throwable $e) {
    $message = "เกิดข้อผิดพลาด: ".$e->getMessage();
    $message_type = 'error';
}

/* ---------------- IMPORT CSV ----------------
   รองรับคอลัมน์:
   - question_text, group_id
   หรือ
   - question_text, group_name (ระบบจะ map หาค่า group_id ให้)
   ถ้ามีการเลือก group_id คงที่ในฟอร์ม จะบังคับลงกลุ่มนั้นทั้งหมด
------------------------------------------------ */
try {
    if (isset($_POST['import_csv'])) {
        if (!isset($_FILES['csv']) || $_FILES['csv']['error']!==UPLOAD_ERR_OK) {
            throw new Exception('กรุณาอัปโหลดไฟล์ CSV');
        }
        $fixedGid = $_POST['group_id_for_import'] ?? '';
        $mapNameToId = [];
        if ($fixedGid==='') {
            $rows = $pdo->query("SELECT group_id, group_name FROM subject_groups")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) { $mapNameToId[trim($r['group_name'])] = $r['group_id']; }
        }

        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) throw new Exception('ไม่สามารถอ่านไฟล์ CSV');

        $pdo->beginTransaction();
        $ok=0; $ignored=0;

        // ข้าม BOM, header
        $firstLine = fgetcsv($fh);
        if ($firstLine===false) throw new Exception('ไฟล์ว่าง');

        $ins = $pdo->prepare("INSERT INTO questions (question_text, group_id, order_in_group) VALUES (?,?,?)");

        $perGroupNext = []; // สำหรับ CSV
        while (($cols = fgetcsv($fh)) !== false) {
            if (count(array_filter($cols, fn($x)=>trim((string)$x) !== '')) === 0) continue;
            $qtext = trim((string)($cols[0] ?? ''));
            if ($qtext==='') { $ignored++; continue; }

            $gid = $fixedGid;
            if ($gid==='') {
                $second = trim((string)($cols[1] ?? ''));
                if ($second===''){ $ignored++; continue; }
                if (ctype_digit($second)) {
                    $gid = $second;
                } else {
                    if (!isset($mapNameToId[$second])) { $ignored++; continue; }
                    $gid = $mapNameToId[$second];
                }
            }

            $order = $perGroupNext[$gid] ?? nextOrderForGroup($pdo, $gid);
            $perGroupNext[$gid] = $order + 1;

            $ins->execute([$qtext, $gid, $order]);
            $ok++;
        }
        fclose($fh);
        $pdo->commit();

        $msg = "นำเข้า CSV สำเร็จ {$ok} ข้อ";
        if ($ignored>0) $msg .= " (ข้าม {$ignored} บรรทัดที่ไม่ครบถ้วน)";
        header("Location: manage_questions.php?message=" . urlencode($msg) . "&type=success"); exit;
    }
} catch (Throwable $e) {
    $message = "นำเข้าไม่สำเร็จ: ".$e->getMessage();
    $message_type = 'error';
}

/* ---------------- FETCH: edit target ---------------- */
$question_to_edit = null;
try {
    if (isset($_GET['edit_question'])) {
        $stmt = $pdo->prepare("SELECT * FROM questions WHERE question_id = ?");
        $stmt->execute([$_GET['edit_question']]);
        $question_to_edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (PDOException $e) {
    $message = "ไม่สามารถดึงข้อมูลเพื่อแก้ไขได้: " . $e->getMessage();
    $message_type = 'error';
}

/* ---------------- GROUP Filter + List ---------------- */
$groupParam = isset($_GET['group']) && $_GET['group']!=='' ? trim($_GET['group']) : '';
try {
    $groups = $pdo->query("SELECT * FROM subject_groups ORDER BY group_name ASC")->fetchAll(PDO::FETCH_ASSOC);

    $sql = "SELECT q.question_id, q.question_text, g.group_name, g.group_id, q.order_in_group
            FROM questions q
            LEFT JOIN subject_groups g ON q.group_id = g.group_id";
    $params = [];
    if ($groupParam!=='') {
        $sql .= " WHERE q.group_id = :gid ";
        $params[':gid'] = $groupParam;
    }
    $sql .= " ORDER BY g.group_name ASC, q.order_in_group ASC, q.question_id DESC";

    $stmtQ = $pdo->prepare($sql);
    foreach ($params as $k=>$v) { $stmtQ->bindValue($k,$v); }
    $stmtQ->execute();
    $questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $questions = [];
    $message = "ไม่สามารถดึงรายการคำถามได้: ".$e->getMessage();
    $message_type = 'error';
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>จัดการคำถาม</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="container">

  <!-- Topbar -->
  <div class="topbar">
    <div class="brand">
      <div class="logo"></div>
      <h1 class="title">จัดการคำถาม</h1>
    </div>
    <nav class="nav">
      <div class="chips" style="margin-top:10px">
        <span class="badge">คำถาม: <strong><?php echo number_format(count($questions)); ?></strong></span>
        <span class="badge">กลุ่ม: <strong><?php echo count($groups); ?></strong></span>
      </div>
      <a href="admin_dashboard.php" class="btn secondary">หน้าหลัก</a>
      <a href="manage_recommended_groups.php" class="btn primary">จัดการกลุ่มวิชา</a>
    </nav>
  </div>

  <!-- Flash/message -->
  <?php if ($message): ?>
    <div class="alert <?php echo $message_type==='success'?'ok':'dangerBox'; ?>">
      <?php echo htmlspecialchars($message); ?>
    </div>
  <?php endif; ?>

  <!--ส่งออก/นำเข้า -->
  <div class="card" style="margin-bottom:16px">
    <div class="section-header">
      <span style="font-size:18px">🔍</span>
      <h4>เลือกเพื่อดู</h4>
    </div>
    
    <form method="get" class="filter-row">
      <div>
        <label>แสดงตามกลุ่ม</label>
        <select class="select" name="group">
          <option value="">— ทั้งหมด —</option>
          <?php foreach ($groups as $g): ?>
            <option value="<?= h($g['group_id']) ?>" <?= $groupParam===$g['group_id']?'selected':'' ?>>
              <?= h($g['group_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn primary">🔍ค้นหา</button>
      <a class="btn accent" href="manage_questions.php?export=questions">📥 ส่งออก CSV</a>
      <a class="btn secondary" href="manage_questions.php?export=questions_header">📝 เทมเพลต CSV</a>
    </form>
  </div>

  <div class="grid">

    <?php if ($question_to_edit): ?>
      <!-- โหมดแก้ไข -->
      <section class="card" id="edit-form">
        <div class="section-header">
          <span style="font-size:18px">✏️</span>
          <h3>แก้ไขคำถาม</h3>
        </div>
        
        <form method="post" style="display:grid;gap:12px">
          <input type="hidden" name="question_id" value="<?= (int)$question_to_edit['question_id'] ?>">
          <div>
            <label>ข้อความคำถาม</label>
            <textarea name="question_text" class="input textarea" required><?= h($question_to_edit['question_text']) ?></textarea>
          </div>
          <div>
            <label>กลุ่มรายวิชา</label>
            <select name="group_id_for_question" class="select" required>
              <?php foreach ($groups as $g): ?>
                <option value="<?= h($g['group_id']) ?>" <?= ($g['group_id']==$question_to_edit['group_id'])?'selected':'' ?>>
                  <?= h($g['group_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="display:flex;gap:8px;justify-content:flex-end">
            <a href="manage_questions.php" class="btn secondary">❌ ยกเลิก</a>
            <button type="submit" name="update_question" class="btn primary">💾 บันทึกการแก้ไข</button>
            <button type="submit" name="delete_question" class="btn danger" onclick="return confirm('ลบคำถามนี้ใช่หรือไม่?')">🗑️ ลบคำถามนี้</button>
          </div>
        </form>
      </section>

    <?php else: ?>
      <!-- โหมดเพิ่ม -->
      <section class="card" id="add-form">
        <div class="section-header">
          <span style="font-size:18px">➕</span>
          <h3>เพิ่มคำถาม</h3>
        </div>
        
        <form method="post" style="display:grid;gap:16px">
          <div>
            <label>คำถาม (วางทีละบรรทัด)</label>
            <textarea name="bulk_questions" class="input textarea" placeholder="พิมพ์/วางคำถามทีละบรรทัดที่นี่..." required></textarea>
          </div>

          <div class="mode-card">
            <div class="mode-title">
              <label><input type="radio" name="bulk_mode" value="one" checked> โหมด: ใส่ลงกลุ่มเดียว</label>
            </div>
            <div style="margin-top:8px">
              <select name="bulk_group_id" class="select">
                <option value="">— เลือกกลุ่ม —</option>
                <?php foreach ($groups as $g): ?>
                  <option value="<?= h($g['group_id']) ?>"><?= h($g['group_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="mode-card">
            <div class="mode-title">
              <label><input type="radio" name="bulk_mode" value="multi"> โหมด: หลายกลุ่ม (กำหนดจำนวนข้อแต่ละกลุ่ม)</label>
            </div>
            
            <div id="slots" style="display:grid;gap:8px;margin-top:8px"></div>

            <div style="display:flex;gap:8px;margin-top:8px;align-items:center">
              <button type="button" class="btn secondary" id="addSlot">➕ เพิ่มแถวกลุ่ม</button>
              <span style="color:var(--muted);font-size:12px" id="quota-note">รวม: 0</span>
            </div>
          </div>

          <div style="display:flex;justify-content:flex-end">
            <button type="submit" name="add_bulk_questions" class="btn primary">➕ เพิ่มคำถาม</button>
          </div>
        </form>
      </section>
    <?php endif; ?>

    <!-- นำเข้า/ส่งออก + ลบทั้งกลุ่ม -->
    <section class="card">
      <div class="section-header">
        <span style="font-size:18px">📁</span>
        <h3>นำเข้า CSV & จัดการขั้นสูง</h3>
      </div>

      <div class="mode-card">
        <div class="mode-title">📥 นำเข้าไฟล์ CSV</div>
        <form action="manage_questions.php" method="post" enctype="multipart/form-data" style="display:grid;gap:12px">
          <div>
            <label>เลือกไฟล์ CSV</label>
            <input type="file" name="csv" accept=".csv" class="input" required>
          </div>
          <div>
            <label>เลือกกลุ่มคงที่ (ไม่จำเป็น)</label>
            <select name="group_id_for_import" class="select">
              <option value="">— อ่านจากไฟล์ —</option>
              <?php foreach ($groups as $g): ?>
                <option value="<?= h($g['group_id']) ?>"><?= h($g['group_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" name="import_csv" class="btn success">📥 นำเข้า CSV</button>
        </form>
      </div>

      <div class="hr"></div>

      <div class="mode-card">
        <div class="mode-title">🗑️ ลบทั้งหมดในกลุ่ม</div>
        <form method="post" style="display:grid;gap:12px" onsubmit="return confirm('ยืนยันลบคำถามทั้งหมดของกลุ่มที่เลือก?')">
          <select class="select" name="group_id_delete_all">
            <option value="">— เลือกกลุ่ม —</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= h($g['group_id']) ?>"><?= h($g['group_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn danger" name="delete_all_in_group">🗑️ ลบทั้งหมด</button>
        </form>
      </div>
    </section>

  </div>

  <!-- รายการคำถาม -->
  <div class="card" style="margin-top:16px">
    <div class="section-header">
      <span style="font-size:18px">📋</span>
      <h3>รายการคำถาม</h3>
      <span class="badge" style="margin-left:auto">ทั้งหมด: <?= number_format(count($questions)) ?> ข้อ</span>
    </div>
    
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:60px">#</th>
            <th>คำถาม</th>
            <th style="width:200px">กลุ่มรายวิชา</th>
            <th style="width:120px">ลำดับในกลุ่ม</th>
            <th style="width:100px">การจัดการ</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($questions)): ?>
          <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:40px">— ไม่มีข้อมูล —</td></tr>
        <?php else: ?>
          <?php foreach ($questions as $i=>$row): ?>
            <tr>
              <td style="text-align:center;color:var(--muted);font-weight:700"><?= $i+1 ?>.</td>
              <td style="font-weight:600"><?= h($row['question_text']) ?></td>
              <td><span class="badge"><?= h($row['group_name']) ?></span></td>
              <td style="text-align:center"><span class="badge"><?= (int)$row['order_in_group'] ?></span></td>
              <td>
                <a class="btn secondary btn-icon" href="manage_questions.php?edit_question=<?= (int)$row['question_id'] ?>" title="แก้ไข">✏️</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // Auto-hide alert
  document.querySelectorAll('.alert').forEach(a=>{
    setTimeout(()=>{
      a.style.transition='opacity .5s, transform .5s';
      a.style.opacity='0'; 
      a.style.transform='translateY(-10px)';
      setTimeout(()=>a.remove(),500);
    }, 5000);
  });

  const slots = document.getElementById('slots');
  const addBtn = document.getElementById('addSlot');
  const quotaNote = document.getElementById('quota-note');

  // เตรียม options ของ select groups จาก server
  const GROUPS = <?=
    json_encode(array_map(function($g){ return ['id'=>$g['group_id'],'name'=>$g['group_name']]; }, $groups), JSON_UNESCAPED_UNICODE)
  ?>;

  function calcSum(){
    let sum = 0;
    document.querySelectorAll('input[name="bulk_counts[]"]').forEach(inp=>{
      const n = Math.max(0, parseInt(inp.value || '0', 10));
      sum += n;
    });
    quotaNote.textContent = 'รวม: ' + sum;
  }

  function makeSlot(){
    const wrap = document.createElement('div');
    wrap.className = 'slot';

    const sel = document.createElement('select');
    sel.className = 'select';
    sel.name = 'bulk_groups[]';
    GROUPS.forEach(g=>{
      const op = document.createElement('option');
      op.value = g.id;
      op.textContent = g.name;
      sel.appendChild(op);
    });

    const num = document.createElement('input');
    num.className = 'input';
    num.type = 'number';
    num.min = '0';
    num.name = 'bulk_counts[]';
    num.value = '10';
    num.placeholder = 'จำนวนข้อ';
    num.addEventListener('input', calcSum);

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'del';
    del.innerHTML = '❌';
    del.addEventListener('click', ()=>{
      wrap.remove();
      calcSum();
    });

    wrap.appendChild(sel);
    wrap.appendChild(num);
    wrap.appendChild(del);
    return wrap;
  }

  function ensureAtLeastOne(){
    if (!slots.querySelector('.slot')) {
      slots.appendChild(makeSlot());
    }
  }

  addBtn?.addEventListener('click', ()=>{
    slots.appendChild(makeSlot());
    calcSum();
  });

  // เริ่มต้น 1 แถว
  ensureAtLeastOne();
  calcSum();
});
</script>
</body>
</html>