<?php
session_start();
if (empty($_SESSION['loggedin']) || (($_SESSION['user_type'] ?? '') !== 'admin')) {
    header('Location: login.php?error=unauthorized');
    exit;
}

// ดึงข้อมูล admin จาก session
$admin_username = $_SESSION['admin_username'] ?? $_SESSION['username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แผงควบคุมผู้ดูแลระบบ - Dark Theme</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
<style>
        :root {
            --background-dark: #111827;
            --primary-dark: #1F2937;
            --secondary-dark: #374151;
            --border-color: #374151;
            --text-primary: #F9FAFB;
            --text-secondary: #9CA3AF;
            --accent-cyan: #22d3ee;
            --accent-purple: #a78bfa;
            --danger-color: #f43f5e;
            --success-color: #10b981;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Sarabun', sans-serif;
            background-color: var(--background-dark);
            color: var(--text-primary);
            line-height: 1.6;
        }

        /* Header */
        .header {
            background-color: var(--primary-dark);
            padding: 1rem 2rem;
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--accent-cyan);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .welcome-text {
            font-size: 0.95rem;
            color: var(--text-secondary);
        }

        .logout-btn {
            background-color: var(--primary-dark);
            color: var(--danger-color);
            padding: 8px 16px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn:hover {
            background-color: var(--danger-color);
            color: var(--text-primary);
            border-color: var(--danger-color);
        }

        /* Main Container */
        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .section-title {
            font-size: 1.6rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--accent-cyan);
            display: inline-block;
        }

        /* Stats Section */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: var(--primary-dark);
            padding: 1.5rem;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-cyan);
            box-shadow: 0 0 20px rgba(34, 211, 238, 0.15);
        }

        .stat-icon {
            font-size: 2rem;
            width: 60px;
            height: 60px;
            flex-shrink: 0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--background-dark);
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple));
        }

        .stat-info .stat-number {
            font-size: 2.25rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .stat-info .stat-label {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        /* Navigation Cards */
        .nav-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .nav-card {
            background: var(--primary-dark);
            padding: 1.5rem;
            border-radius: 12px;
            text-decoration: none;
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .nav-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0 20px rgba(34, 211, 238, 0.1);
            border-color: var(--accent-cyan);
        }
        
        .nav-icon {
            font-size: 1.5rem;
            color: var(--accent-cyan);
            margin-top: 5px;
        }

        .nav-card h3 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .nav-card p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        /* Chart & Quick Actions Section */
        .bottom-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            align-items: flex-start;
        }
        
        .card {
            background: var(--primary-dark);
            padding: 1.5rem;
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }

        .chart-controls {
            display: flex;
            justify-content: flex-start;
            gap: 10px;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .chart-btn {
            background-color: var(--secondary-dark);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .chart-btn:hover {
            color: var(--text-primary);
            border-color: var(--text-secondary);
        }

        .chart-btn.active {
            background-color: var(--accent-cyan);
            color: var(--background-dark);
            border-color: var(--accent-cyan);
            font-weight: 600;
        }

        .chart-container {
            position: relative;
            height: 400px;
        }
        
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .action-btn {
            background-color: var(--primary-dark);
            color: var(--accent-cyan);
            border: 1px solid var(--border-color);
            padding: 12px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            transition: all 0.3s ease;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .action-btn:hover {
            background-color: var(--secondary-dark);
            border-color: var(--accent-cyan);
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .bottom-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
            .stats-grid, .nav-grid {
                grid-template-columns: 1fr;
            }
            .container { padding: 0 1rem; margin-top: 1rem; }
            .chart-container { height: 300px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="header-title">
                <i class="fas fa-shield-alt"></i>
                <span>แผงควบคุมผู้ดูแลระบบ</span>
            </div>
            <div class="user-info">
                <span class="welcome-text">ยินดีต้อนรับ, <strong><?php echo htmlspecialchars($admin_username); ?></strong></span>
                <a href="admin_logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
                </a>
            </div>
        </div>
    </header>

    <main class="container">
        <!-- Statistics Section -->
        <section class="stats-section">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-question-circle"></i></div>
                    <div class="stat-info">
                        <div class="stat-number" id="questionCount">50</div>
                        <div class="stat-label">คำถามทั้งหมด</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-info">
                        <div class="stat-number" id="userCount">100</div>
                        <div class="stat-label">ผู้ใช้งาน</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-book-open"></i></div>
                    <div class="stat-info">
                        <div class="stat-number" id="courseCount">20</div>
                        <div class="stat-label">หลักสูตร</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                    <div class="stat-info">
                        <div class="stat-number" id="testCount">15</div>
                        <div class="stat-label">การทดสอบ</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Navigation Section -->
        <section class="nav-section">
            <h2 class="section-title">เมนูการจัดการ</h2>
            <div class="nav-grid">
                <a href="manage_questions.php" class="nav-card">
                    <i class="fas fa-tasks nav-icon"></i>
                    <div>
                        <h3>จัดการคำถามและคำตอบ</h3>
                        <p>เพิ่ม แก้ไข หรือลบคำถามและตัวเลือกสำหรับระบบแนะนำวิชา</p>
                    </div>
                </a>
                <a href="manage_recommended_groups.php" class="nav-card">
                    <i class="fas fa-layer-group nav-icon"></i>
                    <div>
                        <h3>จัดการกลุ่มและวิชาที่แนะนำ</h3>
                        <p>เพิ่ม แก้ไข หรือลบกลุ่มวิชาและรายวิชาที่ระบบจะแนะนำ</p>
                    </div>
                </a>
                <a href="manage_students.php" class="nav-card">
                    <i class="fas fa-user-graduate nav-icon"></i>
                    <div>
                        <h3>จัดการสถานะแบบทดสอบนักศึกษา</h3>
                        <p>ตรวจสอบและปรับสถานะการทำแบบทดสอบของนักศึกษา</p>
                    </div>
                </a>
                <a href="course_management.php" class="nav-card">
                    <i class="fas fa-chalkboard nav-icon"></i>
                    <div>
                        <h3>จัดการหลักสูตร</h3>
                        <p>เพิ่ม แก้ไข หรือลบหลักสูตรทั้งหมดในระบบ</p>
                    </div>
                </a>
                <a href="teacher_registration.php" class="nav-card">
                    <i class="fas fa-chalkboard-teacher nav-icon"></i>
                    <div>
                        <h3>จัดการข้อมูลอาจารย์</h3>
                        <p>เพิ่ม แก้ไข สร้างรหัสผ่าน และจัดการข้อมูลอาจารย์</p>
                    </div>
                </a>
                <a href="groups_manage.php" class="nav-card">
                    <i class="fas fa-chalkboard-teacher nav-icon"></i>
                    <div>
                        <h3>จัดการกลุ่มเรียน</h3>
                        <p>เพิ่ม แก้ไข และจัดการกลุ่ม</p>
                    </div>
                </a>
            </div>
        </section>

        <div class="bottom-grid">
            <!-- Chart Section -->
            <section class="chart-section card">
                <h2 class="section-title">กราฟสถิติ</h2>
                <div class="chart-controls">
                    <button class="chart-btn active" onclick="showChart(event, 'overview')">ภาพรวม</button>
                    <button class="chart-btn" onclick="showChart(event, 'monthly')">ผู้ใช้รายเดือน</button>
                    <button class="chart-btn" onclick="showChart(event, 'category')">หมวดหมู่คำถาม</button>
                </div>
                <div class="chart-container">
                    <canvas id="statsChart"></canvas>
                </div>
            </section>
    
            <!-- Quick Actions -->
            <section class="quick-actions card">
                <h2 class="section-title">การดำเนินการด่วน</h2>
                <div class="action-buttons">
                    <button class="action-btn" onclick="addNewQuestion()"><i class="fas fa-plus-circle"></i> เพิ่มคำถามใหม่</button>
                    <button class="action-btn" onclick="addNewGroup()"><i class="fas fa-folder-plus"></i> เพิ่มกลุ่มใหม่</button>
                    <button class="action-btn" onclick="viewReports()"><i class="fas fa-chart-pie"></i> ดูรายงาน</button>
                    <button class="action-btn" onclick="backupData()"><i class="fas fa-database"></i> สำรองข้อมูล</button>
                </div>
            </section>
        </div>
    </main>

    <script>
        let statsChart;

        // ข้อมูลตัวอย่างสำหรับกราฟ (ปรับสีให้เข้ากับธีม)
        const chartData = {
            overview: {
                labels: ['คำถาม', 'ผู้ใช้งาน', 'หลักสูตร', 'การทดสอบ'],
                data: [50, 100, 20, 15],
                backgroundColor: ['#22d3ee', '#10b981', '#a78bfa', '#f43f5e']
            },
            monthly: {
                labels: ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.'],
                data: [12, 19, 8, 15, 23, 18],
                backgroundColor: '#22d3ee'
            },
            category: {
                labels: ['คณิตศาสตร์', 'วิทยาศาสตร์', 'ภาษาไทย', 'ภาษาอังกฤษ', 'สังคม'],
                data: [25, 18, 12, 20, 15],
                backgroundColor: ['#f43f5e', '#22d3ee', '#ffc107', '#10b981', '#a78bfa']
            }
        };

        function createChart(type = 'overview') {
            const ctx = document.getElementById('statsChart').getContext('2d');
            
            if (statsChart) {
                statsChart.destroy();
            }

            const data = chartData[type];
            
            statsChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: getChartTitle(type),
                        data: data.data,
                        backgroundColor: data.backgroundColor,
                        borderRadius: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(255, 255, 255, 0.1)' },
                            ticks: { color: 'var(--text-secondary)' }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: 'var(--text-secondary)' }
                        }
                    }
                }
            });
        }

        function getChartTitle(type) {
            const titles = {
                overview: 'ภาพรวมระบบ',
                monthly: 'ผู้ใช้งานใหม่รายเดือน',
                category: 'จำนวนคำถามตามหมวดหมู่'
            };
            return titles[type] || 'สถิติ';
        }

        function showChart(event, type) {
            document.querySelectorAll('.chart-btn').forEach(btn => btn.classList.remove('active'));
            event.currentTarget.classList.add('active');
            createChart(type);
        }

        function addNewQuestion() { window.location.href = 'manage_questions.php?action=add'; }
        function addNewGroup() { window.location.href = 'manage_recommended_groups.php?action=add'; }
        function viewReports() { alert('ฟีเจอร์รายงานกำลังพัฒนา'); }
        function backupData() {
            if (confirm('คุณต้องการสำรองข้อมูลระบบหรือไม่?')) {
                alert('กำลังสำรองข้อมูล...');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            createChart('overview');
        });
    </script>
</body>
</html>