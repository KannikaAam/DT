<?php
// forgot_password.php — เลือกประเภทผู้ใช้ก่อนรีเซ็ตรหัสผ่าน
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>ลืมรหัสผ่าน</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0f172a;--card:#111827;--line:rgba(255,255,255,.08);--text:#e5e7eb;--muted:#94a3b8;--btn:#2563eb}
*{box-sizing:border-box} body{margin:0;background:linear-gradient(135deg,#0b1220,#111827);color:var(--text);font-family:'Sarabun',system-ui}
.container{max-width:820px;margin:40px auto;padding:16px}
.card{background:rgba(255,255,255,.03);border:1px solid var(--line);border-radius:14px;padding:22px}
h1{margin:0 0 8px} .sub{color:var(--muted)}
.grid{display:grid;gap:16px;margin-top:16px} @media(min-width:720px){.grid{grid-template-columns:1fr 1fr}}
.opt{padding:18px;border:1px solid var(--line);border-radius:12px;background:rgba(17,24,39,.6)}
.opt h3{margin:0 0 6px} .btn{display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;padding:10px 14px;border-radius:10px;text-decoration:none;border:1px solid var(--line)}
.link{color:#e5e7eb;text-decoration:none;margin-left:10px}
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <h1>ลืมรหัสผ่าน</h1>
    <div class="sub">เลือกประเภทผู้ใช้งานของคุณ</div>
    <div class="grid">
      <div class="opt">
        <h3>นักศึกษา</h3>
        <div class="sub" style="margin-bottom:10px">ขอรีเซ็ตรหัสด้วยรหัสนักศึกษาหรืออีเมล</div>
        <a class="btn" href="student_forgot_password.php">ไปหน้าของนักศึกษา</a>
      </div>
      <div class="opt">
        <h3>อาจารย์</h3>
        <div class="sub" style="margin-bottom:10px">ขอรีเซ็ตรหัสด้วย username หรืออีเมล</div>
        <a class="btn" href="teacher_forgot_password.php">ไปหน้าของอาจารย์</a>
      </div>
    </div>
    <div style="margin-top:16px">
      <a class="link" href="index.php">กลับหน้าเข้าสู่ระบบ</a>
    </div>
  </div>
</div>
</body>
</html>
