<?php
session_start();
include 'db_connect.php'; // เชื่อมต่อฐานข้อมูล

if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];
$full_name = 'ไม่พบชื่อ'; // Default value
$profile_picture_src = ''; // Default avatar URL
$gender = ''; // Default gender

// ดึงข้อมูลชื่อ, รูปโปรไฟล์, และเพศของนักศึกษา โดยใช้ Prepared Statement และ JOIN ตาราง
$sql_student_info = "SELECT p.full_name, p.profile_picture, p.gender 
                     FROM personal_info p 
                     INNER JOIN education_info e ON p.id = e.personal_id 
                     WHERE e.student_id = ?";
$stmt_student_info = $conn->prepare($sql_student_info);

if ($stmt_student_info) {
    $stmt_student_info->bind_param("s", $student_id);
    $stmt_student_info->execute();
    $result_student_info = $stmt_student_info->get_result();

    if ($result_student_info && $result_student_info->num_rows == 1) {
        $student_data = $result_student_info->fetch_assoc();
        $full_name = htmlspecialchars($student_data['full_name'] ?? 'ไม่ระบุชื่อ');
        $gender = strtolower($student_data['gender'] ?? '');

        // กำหนดแหล่งที่มาของรูปโปรไฟล์
        if (!empty($student_data['profile_picture']) && file_exists('uploads/profile_images/' . $student_data['profile_picture'])) {
            $profile_picture_src = 'uploads/profile_images/' . htmlspecialchars($student_data['profile_picture']);
        } else {
            // ใช้ UI-Avatars หากไม่มีรูปโปรไฟล์ หรือรูปไม่พบ
            $avatar_background = ($gender == 'ชาย' || $gender == 'male' || $gender == 'ม') ? '3498db' : (($gender == 'หญิง' || $gender == 'female' || $gender == 'ฟ') ? 'e91e63' : '9b59b6');
            $profile_picture_src = 'https://ui-avatars.com/api/?name=' . urlencode($full_name ?: 'Student') .
                '&background=' . $avatar_background .
                '&color=ffffff&size=150&font-size=0.6&rounded=true';
        }
    }
    $stmt_student_info->close();
}

// ดึงข้อมูลประวัติการทำแบบทดสอบจากตาราง test_history โดยใช้ Prepared Statement
$history_sql = "SELECT timestamp, recommended_group, recommended_subjects, no_count 
                FROM test_history 
                WHERE username = ? 
                ORDER BY timestamp DESC";
$stmt_history = $conn->prepare($history_sql);

$history_result = null; // Initialize to null
if ($stmt_history) {
    $stmt_history->bind_param("s", $student_id);
    $stmt_history->execute();
    $history_result = $stmt_history->get_result();
} else {
    // กรณีเกิดข้อผิดพลาดในการเตรียม Statement สำหรับประวัติ
    error_log("Error preparing history statement: " . $conn->error);
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ประวัติการใช้งาน</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2980b9;
            --accent-color: #f39c12;
            --success-color: #27ae60;
            --warning-color: #e74c3c;
            --text-color: #333;
            --light-bg: #f9f9f9;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Prompt', sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: var(--text-color);
            line-height: 1.6;
        }
        
        .navbar {
            background-color: var(--primary-color);
            padding: 15px 30px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .navbar-brand {
            font-size: 20px;
            font-weight: bold;
        }
        
        .navbar-user {
            display: flex;
            align-items: center;
        }
        
        .user-info {
            margin-right: 20px;
            text-align: right;
        }
        
        .user-name {
            font-weight: bold;
            font-size: 14px;
        }
        
        .user-id {
            font-size: 12px;
            opacity: 0.9;
        }
        
        .logout-btn {
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
            text-decoration: none;
        }
        
        .logout-btn:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }
        
        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .dashboard-header {
            margin-bottom: 30px;
        }
        
        .dashboard-header h1 {
            font-size: 28px;
            color: var(--secondary-color);
            margin-bottom: 10px;
        }
        
        .dashboard-header p {
            color: #7f8c8d;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 20px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #374151;
            background: white;
            position: relative;
            overflow: hidden;
        }
        
        .btn-primary {
            border-left: 3px solid #3b82f6;
        }
        .btn-primary:hover {
            background: #f8faff;
            border-left-color: #2563eb;
        }
        
        .btn-success {
            border-left: 3px solid #10b981;
        }
        
        .btn-success:hover {
            background: #f0fdf4;
            border-left-color: #059669;
        }
        
        .btn-warning {
            border-left: 3px solid #f59e0b;
        }

        .btn-warning:hover {
            background: #fffbeb;
            border-left-color: #d97706;
        }

        .btn-info {
            border-left: 3px solid #8b5cf6;
        }

        .btn-info:hover {
            background: #faf5ff;
            border-left-color: #7c3aed;
        }
        
        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            border-left: 4px solid;
        }
        
        .alert-warning {
            background-color: #fff3cd;
            border-color: var(--accent-color);
            color: #856404;
        }
        
        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 20px;
        }
        
        .card-header {
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
            margin-bottom: 15px;
        }
        
        .card-title {
            font-size: 18px;
            color: var(--secondary-color);
        }
        
        .info-item {
            display: flex;
            margin-bottom: 12px;
            padding: 8px 0;
            border-bottom: 1px solid #f8f9fa;
        }
        
        .info-label {
            font-weight: bold;
            width: 180px;
            color: var(--text-color);
            font-size: 14px;
        }
        
        .info-value {
            flex: 1;
            color: #555;
            font-size: 14px;
            min-height: 20px;
        }
        
        .info-value.empty {
            color: #999;
            font-style: italic;
        }
        
        .student-profile {
            display: grid;
            grid-template-columns: 1fr 2fr; /* ปรับให้เหมาะสม */
            gap: 20px;
        }
        
        .profile-image {
            text-align: center;
            padding: 20px;
        }
        
        .profile-image img {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid #f5f7fa;
            box-shadow: var(--box-shadow);
            transition: transform 0.3s ease;
        }
        
        .profile-image img:hover {
            transform: scale(1.05);
        }
        
        .profile-status {
            margin-top: 10px;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            display: inline-block;
        }
        
        .profile-status.default {
            background-color: #e3f2fd;
            color: #1976d2;
        }
        
        .profile-status.uploaded {
            background-color: #e8f5e8;
            color: #2e7d32;
        }
        
        .profile-details {
            padding: 20px 0;
        }
        
        .section-title {
            font-size: 18px;
            color: var(--secondary-color);
            margin: 20px 0 15px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--primary-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title:first-child {
            margin-top: 0;
        }
        
        .icon {
            width: 20px;
            height: 20px;
            display: inline-block;
        }
        
        /* Table Styles for history */
        .card-body table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 14px;
        }
        .card-body th, .card-body td {
            border: 1px solid #e0e0e0;
            padding: 12px;
            text-align: left;
            vertical-align: top;
        }
        .card-body th {
            background-color: #f2f2f2;
            font-weight: 600;
            color: #555;
        }
        .card-body tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .card-body tbody tr:hover {
            background-color: #eaf6ff;
        }
        .card-body td:last-child {
            text-align: center; /* For 'no_count' column */
        }
        .card-body p { /* For "no history" message */
            text-align: center;
            padding: 20px;
            color: #7f8c8d;
            font-style: italic;
        }

        @media (max-width: 768px) {
            .student-profile {
                grid-template-columns: 1fr;
            }
            
            .profile-image img {
                width: 120px;
                height: 120px;
            }
            
            .navbar {
                flex-direction: column;
                text-align: center;
                padding: 15px;
            }
            
            .navbar-user {
                margin-top: 10px;
                flex-direction: column;
            }
            
            .user-info {
                margin-right: 0;
                margin-bottom: 10px;
                text-align: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                text-align: center;
                justify-content: center;
            }
            
            .info-item {
                flex-direction: column;
            }
            
            .info-label {
                width: 100%;
                margin-bottom: 5px;
            }

            /* Responsive table */
            .card-body table, .card-body thead, .card-body tbody, .card-body th, .card-body td, .card-body tr {
                display: block;
            }
            .card-body thead tr {
                position: absolute;
                top: -9999px;
                left: -9999px;
            }
            .card-body tr { border: 1px solid #e0e0e0; margin-bottom: 10px; }
            .card-body td {
                border: none;
                border-bottom: 1px solid #eee;
                position: relative;
                padding-left: 50%;
                text-align: right;
            }
            .card-body td:before {
                position: absolute;
                top: 0;
                left: 6px;
                width: 45%;
                padding-right: 10px;
                white-space: nowrap;
                text-align: left;
                font-weight: bold;
                color: #555;
            }
            /* Label the data */
            .card-body td:nth-of-type(1):before { content: "วันที่:"; }
            .card-body td:nth-of-type(2):before { content: "กลุ่มที่แนะนำ:"; }
            .card-body td:nth-of-type(3):before { content: "รายวิชาที่แนะนำ:"; }
            .card-body td:nth-of-type(4):before { content: "จำนวน \"ไม่ใช่\":"; }
        }
    </style>
</head>
<body>
<div class="navbar">
  <div class="navbar-brand">ระบบทะเบียนนักศึกษา</div>
  <div class="navbar-user">
    <div class="user-info">
      <div class="user-name"><?php echo $full_name; ?></div>
      <div class="user-id">รหัสนักศึกษา: <?php echo htmlspecialchars($student_id); ?></div>
    </div>
    <a href="index.php" class="logout-btn">ออกจากระบบ</a>
  </div>
</div>

<div class="container">
  <div class="dashboard-header">
    <h1>ประวัติการใช้งาน</h1>
    <p>หน้าสำหรับแสดงประวัติการทำแบบทดสอบและกิจกรรมอื่นๆ</p>
  </div>

  <div class="action-buttons">
    <a href="student_dashboard.php" class="btn btn-primary"><span class="icon">🏠</span> กลับหน้าหลัก</a>
    <a href="edit_profile.php" class="btn btn-success"><span class="icon">✏️</span> แก้ไขข้อมูลส่วนตัว</a>
    <a href="history.php" class="btn btn-warning"><span class="icon">📋</span> ประวัติการใช้งาน</a>
    <a href="quiz.php" class="btn btn-info"><span class="icon">📝</span> ทำแบบทดสอบ</a>
  </div>

  <div class="card">
    <div class="card-header">
      <h2 class="card-title">ประวัติการทำแบบทดสอบ</h2>
    </div>
    <div class="card-body">
      <?php if ($history_result && $history_result->num_rows > 0): ?>
      <table>
        <thead>
          <tr>
            <th>วันที่/เวลา</th>
            <th>กลุ่มที่แนะนำ</th>
            <th>รายวิชาที่แนะนำ</th>
            <th>จำนวน "ไม่ใช่"</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($row = $history_result->fetch_assoc()): ?>
          <tr>
            <td><?= htmlspecialchars($row['timestamp']) ?></td>
            <td><?= htmlspecialchars($row['recommended_group']) ?></td>
            <td><?= nl2br(htmlspecialchars($row['recommended_subjects'])) ?></td>
            <td><?= htmlspecialchars($row['no_count']) ?></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
      <?php else: ?>
      <p>ยังไม่มีประวัติการทำแบบทดสอบในขณะนี้ ข้อมูลจะปรากฏขึ้นที่นี่เมื่อคุณทำแบบทดสอบเสร็จสิ้น</p>
      <?php endif; ?>
    </div>
  </div>
</div>

</body>
</html>