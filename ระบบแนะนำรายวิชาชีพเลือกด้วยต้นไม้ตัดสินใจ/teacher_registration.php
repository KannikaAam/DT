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
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    /* =========================
       Theme: Dark / Glass UI
       ========================= */
    :root{
      --navy:#0f1419;
      --steel:#1e293b;
      --slate:#334155;

      --sky:#0ea5e9;     /* primary (ฟ้า) */
      --cyan:#38bdf8;    /* ไล่เฉด */
      --violet:#8b5cf6;  /* accent รอง */
      --red:#ef4444;     /* แดง */

      --text:#f1f5f9;
      --muted:#94a3b8;
      --subtle:#64748b;
      --border:rgba(148,163,184,.25);

      --glass:rgba(15,20,25,.78);
      --glass-2:rgba(15,20,25,.64);
      --shadow:0 12px 34px rgba(0,0,0,.28);
      --radius:16px;

      --bg-grad:
        radial-gradient(1200px 800px at 12% 0%, rgba(14,165,233,.10), transparent 65%),
        radial-gradient(1000px 600px at 88% 100%, rgba(139,92,246,.07), transparent 65%),
        linear-gradient(135deg,#0b1220,#111827 55%, #0b1220);
    }

    /* Base */
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      font-family:'Sarabun',system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
      background:var(--bg-grad);
      color:var(--text);
      -webkit-font-smoothing:antialiased;
      -moz-osx-font-smoothing:grayscale;
      line-height:1.6;
      overflow-x:hidden;
    }

    .content{min-height:100dvh;display:flex;flex-direction:column}

    /* =========================
       Navbar
       ========================= */
    .navbar{
      position:sticky; top:0; z-index:20;
      display:flex; justify-content:space-between; align-items:center;
      padding:14px 18px;
      background:linear-gradient(180deg, rgba(15,20,25,.85), rgba(15,20,25,.65));
      backdrop-filter:saturate(140%) blur(14px);
      border-bottom:1px solid var(--border);
    }
    .nav-left{display:flex;align-items:center;gap:12px;min-width:0}
    .brand{
      width:40px;height:40px;border-radius:12px;flex-shrink:0;
      background:linear-gradient(135deg,var(--sky),var(--cyan));
      color:#0b1220; display:grid; place-items:center; font-weight:900;
      box-shadow:0 8px 22px rgba(14,165,233,.35);
    }
    .title-area{min-width:0}
    .title{margin:0;font-size:18px;font-weight:800;letter-spacing:.2px}
    .subtitle{color:var(--muted);font-size:12px;line-height:1.2;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

    .nav-right{display:flex;align-items:center;gap:12px}
    .user{
      display:flex;align-items:center;gap:10px;padding:6px 10px;border-radius:12px;
      background:linear-gradient(180deg, var(--glass), var(--glass-2));
      border:1px solid var(--border);
    }
    .avatar{
      width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--sky),var(--cyan));
      color:#0b1220; display:grid; place-items:center; font-weight:900
    }
    .details span{font-weight:800;line-height:1;display:block}
    .role{font-size:12px;color:var(--muted)}

    .home-btn{
      display:inline-flex;align-items:center;gap:8px;
      padding:10px 14px;border-radius:12px;text-decoration:none;font-weight:800;
      background:transparent; color:#cbd5e1; border:1px solid var(--border);
    }
    .home-btn:hover{background:rgba(148,163,184,.1); border-color:rgba(148,163,184,.45)}
    .home-btn:focus-visible{outline:2px solid rgba(56,189,248,.6); outline-offset:2px}

    /* =========================
       Main
       ========================= */
    .main{width:100%; max-width:1100px; margin:24px auto 48px; padding:0 16px}
    .section-header{
      display:flex; align-items:center; justify-content:space-between; gap:12px;
      margin:6px 0 14px;
    }
    .section-title{
      font-size:20px; font-weight:800; margin:0;
      background:linear-gradient(135deg,var(--sky),var(--cyan));
      -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent;
    }

    /* Quick Stats (optional info look) */
    .stats{
      display:grid; gap:12px; margin:0 0 18px;
      grid-template-columns:repeat(auto-fit,minmax(210px,1fr));
    }
    .stat{
      display:flex; align-items:center; gap:12px;
      background:linear-gradient(180deg, var(--glass), var(--glass-2));
      border:1px solid var(--border); border-radius:var(--radius); padding:14px;
      transition:border-color .2s ease, transform .2s ease;
    }
    .stat:hover{border-color:rgba(14,165,233,.45); transform:translateY(-2px)}
    .stat-icon{
      width:46px;height:46px;border-radius:12px;flex-shrink:0;
      background:linear-gradient(135deg,var(--sky),var(--cyan));
      color:#0b1220; display:grid; place-items:center; font-weight:900
    }
    .stat h4{margin:0;font-size:18px;font-weight:900}
    .stat p{margin:0;color:var(--muted);font-size:13px}

    /* Cards (เมนูด่วน) */
    .grid{display:grid; gap:16px; grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
    .card-link{
      display:block; text-decoration:none; color:var(--text);
      background:linear-gradient(180deg, var(--glass), var(--glass-2));
      border:1px solid var(--border); border-radius:var(--radius);
      padding:18px; box-shadow:var(--shadow);
      position:relative; overflow:hidden;
      transition:transform .2s ease, box-shadow .2s ease, border-color .2s ease, background .2s ease;
    }
    .card-link:hover{
      transform:translateY(-4px);
      border-color:rgba(14,165,233,.45);
      box-shadow:0 18px 36px rgba(14,165,233,.14);
      background:linear-gradient(180deg, rgba(15,20,25,.86), rgba(15,20,25,.72));
    }
    .card-link::after{
      content:""; position:absolute; inset:auto -20% 0 auto; width:120%; height:1px;
      background:linear-gradient(90deg, transparent, rgba(56,189,248,.55), transparent);
      opacity:0; transform:translateY(-6px); transition:.25s ease;
    }
    .card-link:hover::after{opacity:1; transform:translateY(0)}

    .card-body{display:flex; align-items:center; gap:14px}
    .card-icon{
      width:56px;height:56px;border-radius:12px;flex-shrink:0;
      background:linear-gradient(135deg,var(--sky),var(--cyan));
      color:#0b1220; display:grid; place-items:center; font-size:22px; font-weight:900
    }
    .card-text h3{margin:0 0 6px;font-size:18px;font-weight:900}
    .card-text p{margin:0;color:var(--muted);font-size:14px;line-height:1.55}

    /* Footer mini */
    .foot-note{
      margin-top:24px; color:var(--subtle); font-size:12px; text-align:center
    }

    /* =========================
       Responsive
       ========================= */
    @media (max-width: 768px){
      .navbar{padding:12px 14px}
      .title{font-size:17px}
      .home-btn{padding:8px 12px}
      .main{margin:16px auto 32px}
      .card-link{padding:16px}
      .card-icon{width:52px;height:52px}
      .subtitle{display:none}
    }
    @media (max-width: 420px){
      .details span{max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .card-text h3{font-size:16px}
      .card-text p{font-size:13px}
    }

    @media (prefers-reduced-motion: reduce){ *{animation:none !important; transition:none !important} }
    :focus-visible{outline:2px solid rgba(56,189,248,.6); outline-offset:2px}
  </style>
</head>
<body>
  <div class="content">
    <!-- Navbar -->
    <nav class="navbar" aria-label="ส่วนหัว">
      <div class="nav-left">
        <div class="brand" aria-hidden="true"><i class="fa-solid fa-sliders"></i></div>
        <div class="title-area">
          <h1 class="title">การจัดการหลัก</h1>
          <div class="subtitle">แดชบอร์ดผู้ดูแลระบบ • จัดการผู้ใช้งานและข้อมูลหลัก</div>
        </div>
      </div>

      <div class="nav-right">
        <a class="home-btn" href="admin_dashboard.php" aria-label="ไปยังหน้าหลัก">
          <i class="fas fa-home"></i><span>หน้าหลัก</span>
        </a>
      </div>
    </nav>

    <!-- Main -->
    <main class="main" id="main" tabindex="-1">
      <div class="section-header">
        <h2 class="section-title">เมนู</h2>
      </div>

      <!-- เมนู -->
      <section class="grid" aria-label="เมนูด่วน">
        <a class="card-link" href="admin_add_teacher.php">
          <div class="card-body">
            <div class="card-icon"><i class="fas fa-user-plus"></i></div>
            <div class="card-text">
              <h3>ลงทะเบียนอาจารย์</h3>
              <p>เพิ่มข้อมูลอาจารย์ใหม่ พร้อมตั้งค่าสิทธิ์การใช้งานเบื้องต้น</p>
            </div>
          </div>
        </a>

        <a class="card-link" href="view_teacher.php">
          <div class="card-body">
            <div class="card-icon"><i class="fas fa-users-cog"></i></div>
            <div class="card-text">
              <h3>จัดการข้อมูลอาจารย์</h3>
              <p>ดู แก้ไข และจัดการข้อมูลของอาจารย์ทั้งหมดในระบบ</p>
            </div>
          </div>
        </a>
      </section>
    </main>
  </div>

  <script>
    // โฟกัส main เมื่อโหลดเพื่อช่วยการเข้าถึงด้วยคีย์บอร์ด
    window.addEventListener('DOMContentLoaded', ()=> {
      const main = document.getElementById('main');
      if (main) main.focus();
    });
  </script>
</body>
</html>
