<?php
session_start();
if (empty($_SESSION['loggedin']) || (($_SESSION['user_type'] ?? '') !== 'admin')) {
    header('Location: login.php?error=unauthorized'); exit;
}
$admin_username = $_SESSION['admin_username'] ?? $_SESSION['username'] ?? 'Admin';

require 'db_connect.php'; // ให้ตัวแปร $pdo พร้อมใช้งาน (PDO)
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* -------------------------- AJAX: quiz_stats -------------------------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'quiz_stats') {
    header('Content-Type: application/json; charset=utf-8');

    // นับนักศึกษาทั้งหมดจาก education_info (ผูกกับ personal_info)
    $total_students = (int)$pdo->query("
        SELECT COUNT(*) FROM education_info
    ")->fetchColumn();

    // ตารางสถานะแบบทดสอบ (student_quiz_status) อาจยังไม่มีทุกคน ให้ LEFT JOIN เพื่อคำนวณ
    $sql = "
        SELECT
          SUM(CASE WHEN COALESCE(sqs.quiz_attempts,0) > 0 THEN 1 ELSE 0 END) AS did_quiz,
          SUM(CASE WHEN COALESCE(sqs.quiz_attempts,0) = 0 THEN 1 ELSE 0 END) AS not_quiz,
          SUM(CASE WHEN COALESCE(sqs.recommended_count,0) > 0 THEN 1 ELSE 0 END) AS recommended_ok,
          SUM(CASE WHEN COALESCE(sqs.admin_override_attempts,0) > 0 THEN 1 ELSE 0 END) AS admin_override
        FROM education_info ei
        LEFT JOIN student_quiz_status sqs ON ei.student_id = sqs.student_id
    ";
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: ['did_quiz'=>0,'not_quiz'=>0,'recommended_ok'=>0,'admin_override'=>0];

    echo json_encode([
        'ok' => true,
        'stats' => [
            'total'          => $total_students,
            'did_quiz'       => (int)$row['did_quiz'],
            'not_quiz'       => (int)$row['not_quiz'],
            'recommended_ok' => (int)$row['recommended_ok'],
            'admin_override' => (int)$row['admin_override'],
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
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
:root{
  --background-dark:#111827; --primary-dark:#1F2937; --secondary-dark:#374151; --border-color:#374151;
  --text-primary:#F9FAFB; --text-secondary:#9CA3AF; --accent-cyan:#22d3ee; --accent-purple:#a78bfa;
  --danger-color:#f43f5e; --success-color:#10b981;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Sarabun',sans-serif;background-color:var(--background-dark);color:var(--text-primary);line-height:1.65}

/* Header */
.header{background:linear-gradient(135deg,rgba(34,211,238,.08),rgba(167,139,250,.08)),var(--primary-dark);
  padding:1rem 2rem;border-bottom:1px solid var(--border-color);position:sticky;top:0;z-index:1000;backdrop-filter:blur(6px)}
.header-content{max-width:1400px;margin:0 auto;display:flex;justify-content:space-between;align-items:center}
.header-title{display:flex;align-items:center;gap:10px;font-size:1.5rem;font-weight:600;color:var(--accent-cyan)}
.user-info{display:flex;align-items:center;gap:1rem}
.welcome-text{font-size:.95rem;color:var(--text-secondary)}
.header-actions{display:flex;gap:.5rem}
.btn-small{
  display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:8px;border:1px solid var(--border-color);
  background-color:var(--primary-dark);color:var(--text-primary);text-decoration:none;cursor:pointer;transition:.2s
}
.btn-small:hover{border-color:var(--accent-cyan);color:var(--accent-cyan)}
.logout-btn{color:var(--danger-color)}
.logout-btn:hover{background-color:var(--danger-color);border-color:var(--danger-color);color:#fff}

/* Main */
.container{max-width:1400px;margin:2rem auto;padding:0 2rem}
.section-title{font-size:1.6rem;font-weight:700;margin-bottom:1.2rem;padding-bottom:.5rem;border-bottom:2px solid var(--accent-cyan);display:inline-block}

/* Stats small cards */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:2rem}
.stat-card{background:var(--primary-dark);padding:1rem;border-radius:12px;border:1px solid var(--border-color);display:flex;gap:1rem;align-items:center;transition:.2s}
.stat-card:hover{transform:translateY(-3px);border-color:var(--accent-cyan);box-shadow:0 0 20px rgba(34,211,238,.12)}
.stat-icon{font-size:1.4rem;width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,var(--accent-cyan),var(--accent-purple));color:#0b1220}
.stat-info .stat-number{font-size:1.8rem;font-weight:800}
.stat-info .stat-label{color:var(--text-secondary);font-size:.95rem}

/* Nav cards */
.nav-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1.2rem;margin-bottom:2rem}
.nav-card{background:var(--primary-dark);padding:1.2rem;border-radius:12px;text-decoration:none;color:var(--text-primary);
  border:1px solid var(--border-color);display:flex;gap:1rem;align-items:flex-start;transition:.2s}
.nav-card:hover{transform:translateY(-4px);box-shadow:0 0 18px rgba(34,211,238,.12);border-color:var(--accent-cyan)}
.nav-icon{font-size:1.2rem;color:var(--accent-cyan);margin-top:4px}
.nav-card h3{font-size:1.1rem;margin-bottom:.35rem}
.nav-card p{color:var(--text-secondary);font-size:.9rem}

/* Bottom grid */
.bottom-grid{display:grid;grid-template-columns:2fr 1fr;gap:1.2rem;align-items:flex-start}
.card{background:var(--primary-dark);padding:1.2rem;border-radius:12px;border:1px solid var(--border-color)}
.chart-controls{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1rem}
.chart-btn{background-color:var(--secondary-dark);color:var(--text-secondary);border:1px solid var(--border-color);
  padding:8px 14px;border-radius:8px;cursor:pointer;font-size:.9rem;font-weight:600;transition:.2s}
.chart-btn.active,.chart-btn:hover{color:#0b1220;background:linear-gradient(135deg,var(--accent-cyan),var(--accent-purple));border-color:transparent}
.chart-container{position:relative;height:380px}
.action-buttons{display:flex;flex-direction:column;gap:.8rem}
.action-btn{background-color:var(--primary-dark);color:var(--accent-cyan);border:1px solid var(--border-color);
  padding:12px 16px;border-radius:10px;cursor:pointer;font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:10px;transition:.2s;text-align:left}
.action-btn:hover{background-color:var(--secondary-dark);border-color:var(--accent-cyan)}

/* Floating Home */
.home-btn{position:fixed;bottom:18px;right:18px;background:linear-gradient(135deg,var(--accent-cyan),var(--accent-purple));
  color:#0b1220;font-weight:800;padding:12px 16px;border-radius:12px;box-shadow:0 8px 22px rgba(0,0,0,.35);text-decoration:none;display:flex;align-items:center;gap:8px;z-index:50}
.home-btn:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(34,211,238,.25)}

@media (max-width: 992px){.bottom-grid{grid-template-columns:1fr}}
@media (max-width: 768px){
  .header-content{flex-direction:column;gap:1rem}
  .container{padding:0 1rem;margin-top:1rem}
  .chart-container{height:320px}
}
</style>
</head>
<body>
<header class="header">
  <div class="header-content">
    <div class="header-title"><i class="fas fa-shield-alt"></i><span>แผงควบคุมผู้ดูแลระบบ</span></div>
    <div class="user-info">
      <span class="welcome-text">ยินดีต้อนรับ, <strong><?php echo htmlspecialchars($admin_username); ?></strong></span>
      <div class="header-actions">
        <a href="admin_dashboard.php" class="btn-small"><i class="fas fa-home"></i> หน้าหลัก</a>
        <a href="admin_logout.php" class="btn-small logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
      </div>
    </div>
  </div>
</header>

<main class="container">
  <!-- Stats -->
  <section class="stats-section">
    <div class="stats-grid" id="topStats">
      <!-- จะถูกเติมด้วย JS ตามข้อมูลจริง -->
      <div class="stat-card"><div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
        <div class="stat-info"><div class="stat-number" id="stTotal">-</div><div class="stat-label">นักศึกษาทั้งหมด</div></div></div>
      <div class="stat-card"><div class="stat-icon"><i class="fas fa-check-circle"></i></div>
        <div class="stat-info"><div class="stat-number" id="stDid">-</div><div class="stat-label">เคยทำแบบทดสอบ</div></div></div>
      <div class="stat-card"><div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
        <div class="stat-info"><div class="stat-number" id="stNot">-</div><div class="stat-label">ยังไม่ทำแบบทดสอบ</div></div></div>
      <div class="stat-card"><div class="stat-icon"><i class="fas fa-thumbs-up"></i></div>
        <div class="stat-info"><div class="stat-number" id="stRecOk">-</div><div class="stat-label">แนะนำสำเร็จ ≥ 1</div></div></div>
    </div>
  </section>

  <!-- Navigation -->
  <section class="nav-section">
    <h2 class="section-title">เมนูการจัดการ</h2>
    <div class="nav-grid">
      <a href="manage_questions.php" class="nav-card"><i class="fas fa-tasks nav-icon"></i><div><h3>จัดการคำถามและคำตอบ</h3><p>เพิ่ม/แก้ไข/ลบคำถามสำหรับระบบแนะนำ</p></div></a>
      <a href="manage_recommended_groups.php" class="nav-card"><i class="fas fa-layer-group nav-icon"></i><div><h3>จัดการกลุ่มและวิชาที่แนะนำ</h3><p>เพิ่ม/แก้ไข/ลบ กลุ่มวิชาและรายวิชา</p></div></a>
      <a href="manage_students.php" class="nav-card"><i class="fas fa-user-graduate nav-icon"></i><div><h3>จัดการสถานะแบบทดสอบนักศึกษา</h3><p>ตรวจสอบและปรับสถานะแบบทดสอบ</p></div></a>
      <a href="course_management.php" class="nav-card"><i class="fas fa-chalkboard nav-icon"></i><div><h3>จัดการหลักสูตร</h3><p>เพิ่ม/แก้ไข/ลบ หลักสูตรทั้งหมด</p></div></a>
      <a href="teacher_registration.php" class="nav-card"><i class="fas fa-chalkboard-teacher nav-icon"></i><div><h3>จัดการข้อมูลอาจารย์</h3><p>เพิ่ม/แก้ไข/รีเซ็ตรหัสผ่าน</p></div></a>
      <a href="groups_manage.php" class="nav-card"><i class="fas fa-people-group nav-icon"></i><div><h3>จัดการกลุ่มเรียน</h3><p>เพิ่ม/แก้ไข กลุ่มการเรียน</p></div></a>
      <a href="students_list.php" class="nav-card">
  <i class="fas fa-users nav-icon"></i>
  <div>
    <h3>รายชื่อนักศึกษาทั้งระบบ</h3>
    <p>ดูภาพรวม ค้นหา กรอง และส่งออกรายชื่อนักศึกษา</p>
  </div>
</a>

    </div>
  </section>

  <div class="bottom-grid">
    <!-- Doughnut Chart -->
    <section class="chart-section card">
      <h2 class="section-title">สถิติการทำแบบทดสอบ (ชาร์ตวงกลม)</h2>
      <div class="chart-controls">
        <button class="chart-btn active" id="btnQuiz">สถานะการทำแบบทดสอบ</button>
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

<a href="admin_dashboard.php" class="home-btn"><i class="fas fa-home"></i> หน้าหลัก</a>

<script>
let statsChart;

/* วาด Doughnut chart จากข้อมูลจริง */
async function fetchQuizStats(){
  const res = await fetch('?ajax=quiz_stats', {cache:'no-store'});
  const json = await res.json();
  if(!json.ok) throw new Error('โหลดสถิติล้มเหลว');
  return json.stats;
}

function renderTopStats(s){
  document.getElementById('stTotal').textContent = s.total;
  document.getElementById('stDid').textContent   = s.did_quiz;
  document.getElementById('stNot').textContent   = s.not_quiz;
  document.getElementById('stRecOk').textContent = s.recommended_ok;
}

function drawDoughnut(stats){
  const ctx = document.getElementById('statsChart').getContext('2d');
  if (statsChart) statsChart.destroy();

  const labels = ['เคยทำแบบทดสอบ','ยังไม่ทำ','แนะนำสำเร็จ ≥1','ให้สิทธิ์เพิ่ม (แอดมิน)'];
  const data   = [stats.did_quiz, stats.not_quiz, stats.recommended_ok, stats.admin_override];

  statsChart = new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        label: 'สถานะการทำแบบทดสอบ',
        data,
        // ใช้สีที่เข้ากับธีม — ไม่ต้องกำหนดสไตล์เพิ่มก็สวย (Chart.js 3)
        backgroundColor: ['#22d3ee','#9CA3AF','#10b981','#a78bfa'],
        borderColor: '#111827',
        borderWidth: 2
      }]
    },
    options: {
      responsive:true, maintainAspectRatio:false,
      plugins:{
        legend:{position:'bottom', labels:{color:'var(--text-secondary)'}},
        tooltip:{enabled:true}
      },
      cutout: '60%'
    }
  });
}

function addNewQuestion(){ window.location.href = 'manage_questions.php?action=add'; }
function addNewGroup(){ window.location.href = 'manage_recommended_groups.php?action=add'; }
function viewReports(){ alert('ฟีเจอร์รายงานกำลังพัฒนา'); }
function backupData(){ if (confirm('คุณต้องการสำรองข้อมูลระบบหรือไม่?')) alert('กำลังสำรองข้อมูล...'); }

document.addEventListener('DOMContentLoaded', async () => {
  try{
    const s = await fetchQuizStats();
    renderTopStats(s);
    drawDoughnut(s);
  }catch(e){
    console.error(e);
  }
});
</script>
</body>
</html>
