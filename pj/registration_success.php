<?php
// กำหนดค่าการเชื่อมต่อฐานข้อมูล
$host = 'localhost';      // โฮสต์ของฐานข้อมูล
$username = 'root';       // ชื่อผู้ใช้ฐานข้อมูล
$password = '';           // รหัสผ่านฐานข้อมูล (เปลี่ยนตามที่คุณตั้งไว้)
$database = 'studentregistration'; // ชื่อฐานข้อมูล

// สร้างการเชื่อมต่อกับฐานข้อมูล
$conn = new mysqli($host, $username, $password, $database);

// ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    die("การเชื่อมต่อล้มเหลว: " . $conn->connect_error);
}

// ตั้งค่าการเข้ารหัส UTF-8 เพื่อรองรับภาษาไทย
$conn->set_charset("utf8");

// ตรวจสอบว่ามีการส่งค่า id หรือไม่
if (!isset($_GET['id'])) {
    header("Location: index.html");
    exit();
}

$student_id = $_GET['id'];

// ดึงข้อมูลนักศึกษาจากฐานข้อมูล
$sql = "SELECT p.full_name, e.student_id, e.faculty, e.major, e.curriculum_name
        FROM personal_info p
        JOIN education_info e ON p.id = e.personal_id
        WHERE e.student_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $student = $result->fetch_assoc();
} else {
    header("Location: student_dashboard.php");
    exit();
}

// ปิดการเชื่อมต่อฐานข้อมูล
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ลงทะเบียนสำเร็จ</title>
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2980b9;
            --accent-color: #f39c12;
            --text-color: #333;
            --light-bg: #f9f9f9;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Prompt', 'Kanit', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .container {
            width: 100%;
            max-width: 600px;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }
        
        .header {
            background-color: var(--primary-color);
            color: white;
            text-align: center;
            padding: 25px 20px;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .content {
            padding: 30px;
        }
        
        .success-icon {
            text-align: center;
            margin-bottom: 20px;
            font-size: 64px;
            color: #2ecc71;
        }
        
        .success-message {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .success-message h2 {
            color: #2ecc71;
            margin-bottom: 10px;
            font-size: 24px;
        }
        
        .success-message p {
            color: #7f8c8d;
            font-size: 16px;
        }
        
        .student-info {
            background-color: var(--light-bg);
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
        }
        
        .info-item {
            display: flex;
            margin-bottom: 15px;
        }
        
        .info-label {
            font-weight: bold;
            width: 140px;
            color: var(--text-color);
        }
        
        .info-value {
            flex: 1;
            color: #555;
        }
        
        .buttons {
            text-align: center;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background-color: var(--primary-color);
            color: white;
            text-decoration: none;
            border-radius: var(--border-radius);
            font-weight: bold;
            transition: background-color 0.3s;
            margin: 0 10px;
        }
        
        .btn:hover {
            background-color: var(--secondary-color);
        }
        
        .btn-secondary {
            background-color: #95a5a6;
        }
        
        .btn-secondary:hover {
            background-color: #7f8c8d;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>ลงทะเบียนนักศึกษา</h1>
        </div>
        
        <div class="content">
            <div class="success-icon">
                ✓
            </div>
            
            <div class="success-message">
                <h2>ลงทะเบียนสำเร็จ</h2>
                <p>ระบบได้บันทึกข้อมูลของท่านเรียบร้อยแล้ว</p>
            </div>
            
            <div class="student-info">
                <div class="info-item">
                    <div class="info-label">ชื่อ-นามสกุล:</div>
                    <div class="info-value"><?php echo $student['full_name']; ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">รหัสนักศึกษา:</div>
                    <div class="info-value"><?php echo $student['student_id']; ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">คณะ:</div>
                    <div class="info-value"><?php echo $student['faculty']; ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">สาขาวิชา:</div>
                    <div class="info-value"><?php echo $student['major']; ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">หลักสูตร:</div>
                    <div class="info-value"><?php echo $student['curriculum_name']; ?></div>
                </div>
            </div>
            
            <div class="buttons">
                <a href="login.php" class="btn">เข้าสู่ระบบ</a>
                <a href="index.html" class="btn btn-secondary">กลับสู่หน้าหลัก</a>
            </div>
        </div>
    </div>
</body>
</html>