<?php
session_start();

// ตรวจสอบสิทธิ์ admin
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php?error=unauthorized');
    exit();
}
$admin_name = $_SESSION['admin_username'] ?? 'ผู้ดูแลระบบ';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Dashboard ผู้ดูแลระบบ</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    /* === ชุดสีเดียวกับหน้าธีม Dark/Glass (ให้เหมือนกันทุกหน้า) === */
    :root{
      --navy:#0f1419;
      --steel:#1e293b;
      --slate:#334155;
      --sky:#0ea5e9;       /* cyan โทนหลัก */
      --cyan:#06b6d4;      /* cyan ไล่เฉด */
      --purple:#a78bfa;    /* accent secondary */
      --text:#f1f5f9;
      --muted:#94a3b8;
      --subtle:#64748b;
      --border:#374151;

      --bg-grad:
        radial-gradient(1200px 800px at 20% 0%, rgba(14,165,233,.08), transparent 65%),
        radial-gradient(1000px 600px at 80% 100%, rgba(167,139,250,.06), transparent 65%),
        conic-gradient(from 230deg at 0% 50%, #0f1419, #1e293b, #0f1419);

      --glass:rgba(15,20,25,.85);
      --overlay:rgba(0,0,0,.6);
      --shadow:0 10px 30px rgba(0,0,0,.25);
      --radius:16px;
    }

    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      font-family:'Sarabun',system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
      background:var(--bg-grad);
      color:var(--text);
      -webkit-font-smoothing:antialiased;
      -moz-osx-font-smoothing:grayscale;
      overflow-x:hidden;
      line-height:1.6;
    }

    /* โครงสร้างหลัก */
    .content{min-height:100dvh;display:flex;flex-direction:column}

    /* Navbar ให้เหมือนทุกหน้า */
    .navbar{
      position:sticky; top:0; z-index:20;
      display:flex; justify-content:space-between; align-items:center;
      padding:14px 18px;
      background:linear-gradient(180deg, rgba(15,20,25,.85), rgba(15,20,25,.65));
      backdrop-filter:saturate(140%) blur(14px);
      border-bottom:1px solid var(--border);
    }
    .navbar-left h1{font-size:18px;font-weight:800;margin:0;color:var(--text)}
    .navbar-right{display:flex;align-items:center;gap:12px}
    .user-info{display:flex;align-items:center;gap:10px}
    .user-avatar{
      width:40px;height:40px;border-radius:12px;
      background:linear-gradient(135deg,var(--sky),var(--cyan));
      display:grid;place-items:center;
      color:#0b1220;font-weight:900
    }
    .user-details span{font-weight:800;line-height:1}
    .user-role{font-size:12px;color:var(--muted)}
    .logout-btn{
      display:inline-flex;align-items:center;gap:8px;
      background:rgba(255,255,255,.03);color:#f43f5e;
      padding:10px 14px;border-radius:12px;
      text-decoration:none;border:1px solid var(--border);font-weight:800
    }
    .logout-btn:hover{
      background:#f43f5e; color:#fff; border-color:#f43f5e;
      box-shadow:0 0 20px rgba(244,63,94,.35)
    }

    /* พื้นที่เนื้อหา */
    .main-content{
      width:100%;
      max-width:1100px;
      margin:24px auto 48px;
      padding:0 16px;
    }

    .section-header{
      font-size:20px;font-weight:800;margin:6px 0 16px;
      padding-bottom:10px;border-bottom:1px solid var(--border);
      color:var(--text);
      background:linear-gradient(135deg,var(--sky),var(--cyan));
      -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent;
    }

    /* การ์ดลิงก์ – เหมือนหน้าอื่น */
    .card-container{
      display:grid; gap:16px;
      grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
    }
    .action-card{
      display:block; text-decoration:none; color:var(--text);
      background:linear-gradient(180deg, var(--glass), rgba(15,20,25,.72));
      border:1px solid var(--border);
      border-radius:var(--radius);
      padding:18px;
      box-shadow:var(--shadow);
      transition:transform .2s ease, box-shadow .2s ease, border-color .2s ease;
      position:relative; overflow:hidden;
    }
    .action-card:hover{
      transform:translateY(-4px);
      border-color:rgba(14,165,233,.45);
      box-shadow:0 16px 34px rgba(14,165,233,.12);
    }
    .action-card::after{
      content:"→";
      position:absolute; right:16px; top:14px; font-size:22px; color:var(--muted);
      opacity:0; transform:translateX(6px); transition:transform .2s ease, opacity .2s ease;
    }
    .action-card:hover::after{opacity:1; transform:translateX(0)}
    .action-card-content{display:flex; align-items:center; gap:14px}
    .action-icon{
      width:56px;height:56px;border-radius:12px;flex-shrink:0;
      background:linear-gradient(135deg,var(--sky),var(--cyan));
      display:grid;place-items:center;font-size:22px;color:#091227;font-weight:900
    }
    .action-text h3{margin:0 0 6px;font-size:18px;font-weight:800}
    .action-text p{margin:0;color:var(--muted);font-size:14px;line-height:1.55}

    /* สถิติ (สไตล์เดียวกัน เผื่อใช้ต่อ) */
    .stats-grid{
      display:grid; gap:14px; margin:0 0 18px;
      grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
    }
    .stat-card{
      background:linear-gradient(180deg, var(--glass), rgba(15,20,25,.72));
      border:1px solid var(--border); border-radius:var(--radius);
      padding:16px; display:flex; gap:12px; align-items:center;
      transition:border-color .2s ease, transform .2s ease;
    }
    .stat-card:hover{border-color:rgba(14,165,233,.45); transform:translateY(-3px)}
    .stat-icon{
      width:46px;height:46px;border-radius:12px;
      background:linear-gradient(135deg,var(--sky),var(--cyan));
      display:grid;place-items:center;color:#091227;font-weight:900
    }
    .stat-number{font-size:24px;font-weight:900}
    .stat-label{color:var(--muted);font-size:13px}

    /* Responsive */
    @media (max-width: 768px){
      .navbar{padding:12px 14px}
      .navbar-left h1{font-size:17px}
      .logout-btn{padding:8px 12px}
      .main-content{margin:16px auto 32px}
      .action-card{padding:16px}
      .action-icon{width:52px;height:52px}
    }
    @media (max-width: 420px){
      .action-card-content{gap:12px}
      .action-text h3{font-size:16px}
      .action-text p{font-size:13px}
      .user-details span{max-width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    }

    /* Motion reduce & focus */
    @media (prefers-reduced-motion: reduce){ *{animation:none !important; transition:none !important} }
    :focus-visible{outline:2px solid rgba(14,165,233,.6); outline-offset:2px}
  </style>
</head>
<body>
  <div class="content">
    <nav class="navbar">
      <div class="navbar-left">
        <h1>การจัดการหลัก</h1>
      </div>
      <div class="navbar-right">
        <div class="user-info">
          <div class="user-avatar"><?php echo strtoupper(mb_substr($admin_name, 0, 1, 'UTF-8')); ?></div>
          <div class="user-details">
            <span><?php echo htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8'); ?></span>
            <div class="user-role">ผู้ดูแลระบบ</div>
          </div>
        </div>
        <a class="logout-btn" href="admin_dashboard.php">
          <i class="fas fa-sign-out-alt"></i><span>กลับสู่หน้าหลัก</span>
        </a>
        <a class="logout-btn" href="logout.php">
          <i class="fas fa-sign-out-alt"></i><span>ออกจากระบบ</span>
        </a>
        
      </div>
    </nav>

    <main class="main-content">
      <h2 class="section-header">เมนูด่วน</h2>

      <div class="card-container">
        <a class="action-card" href="admin_add_teacher.php">
          <div class="action-card-content">
            <div class="action-icon"><i class="fas fa-user-plus"></i></div>
            <div class="action-text">
              <h3>ลงทะเบียนอาจารย์</h3>
              <p>เพิ่มข้อมูลอาจารย์ใหม่ พร้อมตั้งค่าสิทธิ์การใช้งานเบื้องต้น</p>
            </div>
          </div>
        </a>

        <a class="action-card" href="view_teacher.php">
          <div class="action-card-content">
            <div class="action-icon"><i class="fas fa-users-cog"></i></div>
            <div class="action-text">
              <h3>จัดการข้อมูลอาจารย์</h3>
              <p>ดู แก้ไข และจัดการข้อมูลของอาจารย์ทั้งหมดในระบบ</p>
            </div>
          </div>
        </a>
      </div>
    </main>
  </div>

  <script>
  </script>
</body>
</html>
