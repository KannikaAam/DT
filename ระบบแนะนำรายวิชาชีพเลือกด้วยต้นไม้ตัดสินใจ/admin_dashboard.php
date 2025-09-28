<?php
session_start();
// Ensure user is logged in and is an admin
if (empty($_SESSION['loggedin']) || (($_SESSION['user_type'] ?? '') !== 'admin')) {
    header('Location: login.php?error=unauthorized');
    exit;
}
$admin_username = $_SESSION['admin_username'] ?? $_SESSION['username'] ?? 'Admin';

// Include database connection (expects $pdo: PDO)
require 'db_connect.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* -------------------------- AJAX: quiz_stats -------------------------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'quiz_stats') {
    header('Content-Type: application/json; charset=utf-8');

    // Get counts for each recommended_group, EXCLUDING 'กลุ่มที่ 1', 'กลุ่มที่ 2', 'กลุ่มที่ 3'
    // Also include a count for NULL/empty as "ไม่มีกลุ่มแนะนำ"
    $sql = "
        SELECT 
            NULLIF(TRIM(COALESCE(recommended_group, '')), '') AS rg,
            COUNT(*) AS cnt
        FROM test_history
        WHERE COALESCE(recommended_group, '') NOT IN ('กลุ่มที่ 1', 'กลุ่มที่ 2', 'กลุ่มที่ 3')
        GROUP BY rg
        ORDER BY cnt DESC
    ";
    $stmt = $pdo->query($sql);
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $labels = [];
    $data = [];
    $total = 0;

    foreach ($raw as $r) {
        $label = $r['rg'] ?? 'ไม่มีกลุ่มแนะนำ';
        $labels[] = $label;
        $data[] = (int)$r['cnt'];
        $total += (int)$r['cnt'];
    }

    echo json_encode([
        'ok' => true,
        'stats' => [
            'group_labels' => $labels,
            'group_data'   => $data,
            'total_recommendations' => $total
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="th" data-theme="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>แผงควบคุมผู้ดูแลระบบ</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    /* =================== DARK/GLASS THEME TOKENS =================== */
    :root {
      /* Surfaces & text (Dark) */
      --bg: #0B1220;                 /* main background */
      --panel: rgba(15,23,42,0.72);  /* card glass */
      --text: #E2E8F0;               /* slate-200 */
      --muted: #94A3B8;              /* slate-400 */
      --border: rgba(148,163,184,0.18);
      --ring: rgba(56,189,248,0.45);

      /* Brand (Academic Navy) */
      --primary: #1E3A8A;            /* indigo-800 */
      --primary-600: #3B82F6;        /* hover accent */
      --secondary: #38BDF8;          /* sky-400 */
      --accent: #22D3EE;             /* cyan-400 */

      /* States */
      --success: #10B981;            /* emerald-500 */
      --warning: #F59E0B;            /* amber-500 */
      --danger:  #EF4444;            /* red-500 */

      /* Layout sizes */
      --sidebar-width: 268px;
      --header-height: 68px;
      --radius: 14px;

      /* Shadows */
      --shadow-1: 0 8px 28px rgba(0,0,0,.35);
      --shadow-2: 0 16px 40px rgba(0,0,0,.45);
    }

    *{margin:0;padding:0;box-sizing:border-box}
    html,body{height:100%}
    body{
      font-family: 'Sarabun', sans-serif;
      background: radial-gradient(600px 300px at 8% 10%, rgba(56,189,248,.12), transparent 60%),
                  radial-gradient(700px 400px at 90% 0%, rgba(34,211,238,.10), transparent 60%),
                  var(--bg);
      color: var(--text);
      line-height: 1.65;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    /* =============== Sidebar (Glass) =============== */
    .sidebar{
      width: var(--sidebar-width);
      height: 100vh;
      position: fixed; inset: 0 auto 0 0;
      background:
        linear-gradient(180deg, rgba(30,58,138,.25), rgba(30,58,138,.12)) padding-box,
        linear-gradient(180deg, rgba(56,189,248,.25), rgba(34,211,238,.06)) border-box;
      border-right: 1px solid var(--border);
      color: var(--text);
      z-index: 1000;
      transform: translateX(0);
      transition: transform .3s ease;
      box-shadow: var(--shadow-2);
      backdrop-filter: blur(10px);
    }
    .sidebar-header{ padding: 1.25rem 1.25rem 1rem; border-bottom: 1px solid var(--border); }
    .sidebar-title{ font-size:1.15rem; font-weight:700; display:flex; gap:.75rem; align-items:center; color:#EAF4FF; }
    .sidebar-title i{ color: var(--secondary); }

    .sidebar-nav{ padding: .6rem 0 1rem; }
    .nav-item{ list-style:none; }
    .nav-link{
      display:flex; align-items:center; gap:.85rem;
      padding:.75rem 1.25rem; color: #D7E3F7; text-decoration:none; transition: all .22s ease;
      border-left: 3px solid transparent;
    }
    .nav-link:hover{
      background: rgba(56,189,248,.10);
      color:#fff; padding-left: 1.55rem;
    }
    .nav-link.active{
      background: rgba(59,130,246,.14);
      border-left-color: var(--secondary);
      color:#fff;
    }
    .nav-icon{ width:20px; text-align:center; font-size:1.05rem; color: var(--secondary); }

    .sidebar-footer{
      position:absolute; bottom:0; width:100%; padding:1.1rem 1.25rem;
      border-top:1px solid var(--border); background: rgba(2,6,23,.15);
      backdrop-filter: blur(8px);
    }
    .user-info{ margin-bottom: .8rem; font-size:.95rem; color:#CFE7FF; }
    .logout-btn{
      display:flex; align-items:center; gap:.6rem; width:100%;
      padding:.7rem .9rem; background: rgba(56,189,248,.12);
      border:1px solid var(--border); border-radius:10px; color:#EAF7FF;
      text-decoration:none; transition: all .22s ease;
    }
    .logout-btn:hover{ background: rgba(239,68,68,.18); border-color: rgba(239,68,68,.5); }

    /* =============== Overlay for mobile =============== */
    .overlay{
      display:none; position:fixed; inset:0; background: rgba(0,0,0,.55); z-index: 999; opacity:0; transition: opacity .3s ease;
    }
    .overlay.active{ display:block; opacity:1; }

    /* =============== Main Content =============== */
    .main-content{
      margin-left: var(--sidebar-width);
      min-height: 100vh;
      transition: margin-left .3s ease;
    }
    .header{
      height: var(--header-height);
      position: sticky; top:0; z-index: 500;
      background:
        linear-gradient(180deg, rgba(15,23,42,.65), rgba(15,23,42,.55)) padding-box,
        linear-gradient(180deg, rgba(56,189,248,.10), rgba(34,211,238,.04)) border-box;
      border-bottom: 1px solid var(--border);
      box-shadow: var(--shadow-1);
      display:flex; align-items:center; justify-content:space-between;
      padding: 0 1.25rem;
      backdrop-filter: blur(8px);
    }
    .header-title{ font-size:1.4rem; font-weight:800; letter-spacing:.2px; color:#EAF4FF; }
    .hamburger-menu{
      display:none; background:none; border:none; font-size:1.25rem; color:#EAF4FF; cursor:pointer;
      padding:.5rem; border-radius:8px; transition: background .2s ease;
    }
    .hamburger-menu:hover{ background: rgba(148,163,184,.12); }

    .container{ max-width:1200px; margin:0 auto; padding: 1.4rem; }

    /* =============== Cards (Glass) =============== */
    .card{
      background:
        linear-gradient(180deg, rgba(15,23,42,.7), rgba(15,23,42,.65)) padding-box,
        linear-gradient(180deg, rgba(56,189,248,.08), rgba(34,211,238,.04)) border-box;
      border:1px solid var(--border); border-radius: var(--radius);
      padding: 1.4rem; margin-bottom: 1.4rem;
      box-shadow: var(--shadow-1); backdrop-filter: blur(10px);
    }
    .section-title{
      font-size:1.2rem; font-weight:800; color:#EAF4FF; margin: .3rem 0 1rem;
    }

    /* =============== Chart area =============== */
    .chart-container{
      position: relative;
      width: 100%;
      max-width: 720px;        /* PC ใหญ่ขึ้นและยังไม่ล้น */
      margin: 0 auto 1rem;
      aspect-ratio: 1.8 / 1;   /* อัตราส่วนกว้าง:สูงอ่านสบาย */
      /* สำหรับจอแนวนอน จะได้โดนัทไม่เตี้ยเกินไป */
    }
    /* มือถือ: ทำให้สูงขึ้นเพื่อให้ label tooltip ไม่ซ้อน */
    @media (max-width: 768px){
      .chart-container{ max-width: 100%; aspect-ratio: 1 / 1; }
    }
    /* จอแคบมาก: โดนัทเกือบเต็มความสูง */
    @media (max-width: 420px){
      .chart-container{ aspect-ratio: .9 / 1; }
    }

    .chart-summary{
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: .65rem;
      margin-top: 1rem; padding-top: .9rem; border-top: 1px solid var(--border);
    }
    /* ถ้ารายการเยอะมาก ให้เลื่อนในแนวนอนบนมือถือ */
    @media (max-width: 520px){
      .chart-summary{
        display: grid;
        grid-auto-flow: column;
        grid-auto-columns: minmax(220px, 1fr);
        overflow-x: auto;
        padding-bottom: .5rem;
        scrollbar-width: thin;
      }
      .chart-summary::-webkit-scrollbar{ height:6px }
      .chart-summary::-webkit-scrollbar-thumb{ background: #334155; border-radius: 999px; }
    }

    .chart-summary-item{
      display:flex; align-items:center; gap:.6rem; font-size: .98rem; color: var(--muted);
      background: rgba(2,6,23,.22);
      border:1px solid var(--border);
      border-radius: 10px;
      padding: .55rem .7rem;
    }
    .chart-summary-item .color-indicator{
      width: 14px; height:14px; border-radius: 4px; flex-shrink:0; box-shadow: 0 0 0 2px rgba(255,255,255,.06);
    }
    .chart-summary-item .value{ font-weight:800; color:#F8FAFF; }

    /* Total line */
    .total-line{
      margin-top: .9rem; font-size: 1rem; color: #CFE7FF;
      display:flex; align-items:center; gap:.6rem;
    }

    /* =============== Action Buttons =============== */
    .action-buttons{
      display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:.9rem;
    }
    .action-btn{
      display:flex; align-items:center; gap:.75rem; padding: .9rem 1rem;
      background: rgba(2,6,23,.22);
      border:1px solid var(--border); border-radius: 12px;
      color:#EAF7FF; text-decoration:none; font-weight:700; cursor:pointer; transition: all .2s ease;
    }
    .action-btn:hover{
      border-color: var(--secondary);
      background: rgba(56,189,248,.08);
      transform: translateY(-2px);
      box-shadow: var(--shadow-1);
    }
    .action-btn i{ color: var(--secondary); font-size:1.05rem; }

    /* =============== Responsive Sidebar =============== */
    @media (max-width: 992px){
      .sidebar{ transform: translateX(-100%); }
      .sidebar.active{ transform: translateX(0); }
      .main-content{ margin-left: 0; }
      .hamburger-menu{ display:block; }
    }

    @media (max-width: 480px){
      .sidebar{ width: 86vw; }
      .container{ padding: 1rem; }
      .card{ padding: 1rem; }
    }
  </style>
</head>
<body>
  <div class="overlay" id="sidebarOverlay"></div>

  <!-- Sidebar -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-title">
        <i class="fas fa-shield-alt"></i>
        <span>แผงควบคุมระบบ</span>
      </div>
    </div>

    <nav class="sidebar-nav">
      <ul>
        <li class="nav-item">
          <a href="admin_dashboard.php" class="nav-link active">
            <i class="fas fa-home nav-icon"></i>
            <span>หน้าหลัก</span>
          </a>
        </li>
        <li class="nav-item">
          <a href="course_management.php" class="nav-link">
            <i class="fas fa-chalkboard nav-icon"></i>
            <span>จัดการข้อมูลหลักสูตร</span>
          </a>
        </li>
                <li class="nav-item">
          <a href="manage_recommended_groups.php" class="nav-link">
            <i class="fas fa-layer-group nav-icon"></i>
            <span>จัดการกลุ่ม & วิชา</span>
          </a>
        </li>
        <li class="nav-item">
          <a href="manage_questions.php" class="nav-link">
            <i class="fas fa-tasks nav-icon"></i>
            <span>จัดการคำถาม</span>
          </a>
        </li>
        <li class="nav-item">
          <a href="groups_manage.php" class="nav-link">
            <i class="fas fa-people-group nav-icon"></i>
            <span>จัดการกลุ่มเรียน</span>
          </a>
        </li>
        <li class="nav-item">
          <a href="teacher_registration.php" class="nav-link">
            <i class="fas fa-chalkboard-teacher nav-icon"></i>
            <span>จัดการอาจารย์</span>
          </a>
        </li>
        <li class="nav-item">
          <a href="manage_students.php" class="nav-link">
            <i class="fas fa-user-graduate nav-icon"></i>
            <span>จัดการสถานะ นศ.</span>
          </a>
        </li>
        <li class="nav-item">
          <a href="students_list.php" class="nav-link">
            <i class="fas fa-users nav-icon"></i>
            <span>รายชื่อนักศึกษา</span>
          </a>
        </li>

      </ul>
    </nav>

    <div class="sidebar-footer">
      <div class="user-info">
        <div>ยินดีต้อนรับ</div>
        <div><strong><?php echo htmlspecialchars($admin_username); ?></strong></div>
      </div>
      <a href="admin_logout.php" class="logout-btn">
        <i class="fas fa-sign-out-alt"></i>
        <span>ออกจากระบบ</span>
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <div class="main-content">
    <header class="header">
      <button class="hamburger-menu" id="hamburgerMenu">
        <i class="fas fa-bars"></i>
      </button>
      <h1 class="header-title">ภาพรวมระบบ</h1>
      <div></div>
    </header>

    <main class="container">
      <!-- Stats Section -->
      <section class="stats-section">
        <h2 class="section-title">สถิติจำนวนกลุ่มที่ถูกแนะนำ</h2>
        <div class="card">
          <div class="chart-container">
            <canvas id="quizStatsChart"></canvas>
          </div>
          <div class="chart-summary" id="groupSummary">
            <!-- Injected by JS -->
          </div>
          <div class="total-line">
            <i class="fas fa-chart-pie" style="color:var(--secondary)"></i>
            <span>รวมจำนวนครั้งที่แนะนำทั้งหมด: <span class="value" id="totalRecommendations">-</span> ครั้ง</span>
          </div>
        </div>
      </section>

      <!-- Actions Section -->
      <section class="actions-section">
        <h2 class="section-title">การดำเนินการด่วน</h2>
        <div class="card">
          <div class="action-buttons">
            <button class="action-btn" onclick="addNewQuestion()">
              <i class="fas fa-plus-circle"></i>
              <span>เพิ่มคำถามใหม่</span>
            </button>
            <button class="action-btn" onclick="addNewGroup()">
              <i class="fas fa-folder-plus"></i>
              <span>เพิ่มกลุ่มใหม่</span>
            </button>
            <a href="manage_students.php" class="action-btn">
              <i class="fas fa-users-cog"></i>
              <span>จัดการนักศึกษา</span>
            </a>
            <a href="students_list.php" class="action-btn">
              <i class="fas fa-list-ul"></i>
              <span>ดูรายชื่อนักศึกษา</span>
            </a>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script>
    // =============== Sidebar Toggle ===============
    document.addEventListener('DOMContentLoaded', () => {
      const sidebar = document.getElementById('sidebar');
      const hamburgerMenu = document.getElementById('hamburgerMenu');
      const overlay = document.getElementById('sidebarOverlay');

      function toggleSidebar() {
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
      }
      if (hamburgerMenu) hamburgerMenu.addEventListener('click', toggleSidebar);
      if (overlay) overlay.addEventListener('click', toggleSidebar);

      // Close sidebar when resizing to desktop
      window.addEventListener('resize', () => {
        if (window.innerWidth > 992) {
          sidebar.classList.remove('active');
          overlay.classList.remove('active');
        }
      });

      // Initialize chart
      loadQuizStats();
    });

    // =============== Chart Colors (High Contrast on Dark) ===============
    const CHART_COLORS = [
      '#38BDF8', // sky-400
      '#10B981', // emerald-500
      '#F59E0B', // amber-500
      '#EF4444', // red-500
      '#A78BFA', // violet-400
      '#22D3EE', // cyan-400
      '#60A5FA', '#FB7185', '#34D399', '#C084FC',
      '#F472B6', '#93C5FD', '#FBBF24', '#4ADE80', '#06B6D4'
    ];

    // =============== Fetch & Render ===============
    async function fetchQuizStats() {
      const resp = await fetch('?ajax=quiz_stats', { cache: 'no-store' });
      if (!resp.ok) throw new Error('Network error');
      const data = await resp.json();
      if (!data.ok) throw new Error('Server error');
      return data.stats;
    }

    let quizStatsChart;

    async function loadQuizStats() {
      try {
        const stats = await fetchQuizStats();
        renderGroupChart(stats);
        updateGroupSummary(stats);
      } catch (err) {
        console.error(err);
        const groupSummary = document.getElementById('groupSummary');
        groupSummary.innerHTML = '<div style="color:#FCA5A5">เกิดข้อผิดพลาดในการโหลดข้อมูลสถิติกลุ่ม</div>';
        document.getElementById('totalRecommendations').textContent = 'Error';
      }
    }

    function renderGroupChart(stats) {
      const canvas = document.getElementById('quizStatsChart');
      const ctx = canvas.getContext('2d');

      // ทำให้ชาร์ตคมชัดบนจอความหนาแน่นสูง
      const dpr = Math.min(window.devicePixelRatio || 1, 2);
      canvas.style.width = '100%';
      canvas.style.height = '100%';
      // Chart.js จะจัดการขนาดตาม container เมื่อ responsive:true อยู่แล้ว

      // destroy เก่า
      if (quizStatsChart) quizStatsChart.destroy();

      // ตั้งค่า default สีตัวหนังสือบนชาร์ต ให้เข้ากับธีมมืด
      Chart.defaults.color = getComputedStyle(document.documentElement).getPropertyValue('--text').trim() || '#E2E8F0';

      const bgColors = stats.group_labels.map((_, i) => CHART_COLORS[i % CHART_COLORS.length]);

      quizStatsChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: stats.group_labels,
          datasets: [{
            data: stats.group_data,
            backgroundColor: bgColors,
            borderColor: 'rgba(0,0,0,0)', // ไม่มีเส้นขาวล้อมชิ้น
            borderWidth: 0,
            hoverOffset: 8
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,  // สำคัญ: ให้ container (aspect-ratio) คุม
          cutout: '58%',               // โดนัทอ่านง่าย
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: 'rgba(15,23,42,0.92)',
              titleColor: '#E2E8F0',
              bodyColor: '#E2E8F0',
              borderColor: 'rgba(148,163,184,0.35)',
              borderWidth: 1,
              cornerRadius: 10,
              displayColors: true,
              callbacks: {
                label: function(ctx){
                  const value = ctx.parsed;
                  const total = ctx.dataset.data.reduce((a,b)=>a+b,0);
                  const pct = total ? ((value/total)*100).toFixed(1) : 0;
                  return `${ctx.label}: ${value} ครั้ง (${pct}%)`;
                }
              }
            }
          },
          animation: { animateRotate: true, duration: 900 }
        }
      });

      // Re-render on container resize (ให้คงความคมชัดและสัดส่วนที่ดี)
      if (typeof ResizeObserver !== 'undefined') {
        const ro = new ResizeObserver(()=> quizStatsChart.resize());
        ro.observe(canvas.parentElement);
      }
    }

    function updateGroupSummary(stats) {
      const groupSummary = document.getElementById('groupSummary');
      groupSummary.innerHTML = '';
      const total = stats.total_recommendations;

      if (!stats.group_labels.length) {
        groupSummary.innerHTML = '<div style="color:#94A3B8">ไม่มีข้อมูลกลุ่มที่แนะนำที่ตรงตามเงื่อนไข</div>';
      } else {
        stats.group_labels.forEach((label, i) => {
          const value = stats.group_data[i];
          const pct = total > 0 ? ((value/total)*100).toFixed(1) : 0;
          const color = CHART_COLORS[i % CHART_COLORS.length];

          const item = document.createElement('div');
          item.className = 'chart-summary-item';
          item.innerHTML = `
            <div class="color-indicator" style="background:${color}"></div>
            <span>${label}: <span class="value">${value.toLocaleString()} ครั้ง</span> (${pct}%)</span>
          `;
          groupSummary.appendChild(item);
        });
      }
      document.getElementById('totalRecommendations').textContent = total.toLocaleString();
    }

    // =============== Actions ===============
    function addNewQuestion(){ window.location.href = 'manage_questions.php?action=add'; }
    function addNewGroup(){ window.location.href = 'manage_recommended_groups.php?action=add'; }
  </script>
</body>
</html>