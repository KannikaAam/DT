<?php
$mysqli = new mysqli("localhost", "root", "", "studentregistration");
$mysqli->set_charset("utf8");

// ดึงคำขอทั้งหมด
$sql = "SELECT r.id, t.name AS teacher_name, t.username, r.status, r.requested_at
        FROM password_reset_requests r
        JOIN teacher t ON r.teacher_id = t.teacher_id
        ORDER BY r.requested_at DESC";
$result = $mysqli->query($sql);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>Admin - คำขอรีเซ็ตรหัสผ่าน</title>
  <style>
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #ccc; padding: 8px; text-align: center; }
    th { background-color: #f2f2f2; }
    button { padding: 5px 10px; margin: 2px; }
  </style>
</head>
<body>
  <h2>📋 รายการคำขอรีเซ็ตรหัสผ่าน</h2>

  <table>
    <thead>
      <tr>
        <th>ลำดับ</th>
        <th>ชื่ออาจารย์</th>
        <th>Username</th>
        <th>สถานะ</th>
        <th>เวลาที่ขอ</th>
        <th>การจัดการ</th>
      </tr>
    </thead>
    <tbody>
      <?php while($row = $result->fetch_assoc()): ?>
      <tr>
        <td><?= $row['id'] ?></td>
        <td><?= $row['teacher_name'] ?></td>
        <td><?= $row['username'] ?></td>
        <td><?= $row['status'] ?></td>
        <td><?= $row['requested_at'] ?></td>
        <td>
          <?php if ($row['status'] == 'pending'): ?>
            <form action="process_request.php" method="post" style="display:inline;">
              <input type="hidden" name="id" value="<?= $row['id'] ?>">
              <button type="submit" name="action" value="approve">✅ อนุมัติ</button>
              <button type="submit" name="action" value="reject">❌ ปฏิเสธ</button>
            </form>
          <?php else: ?>
            <em>คำขอถูกจัดการแล้ว</em>
          <?php endif; ?>
        </td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</body>
</html>
