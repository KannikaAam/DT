<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบแนะนำรายวิชาชีพเลือก | หลักสูตรวิทยาศาสตรบัณฑิต</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --accent: #e74c3c;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --success: #2ecc71;
            --teacher: #8e44ad;
            --admin: #e67e22;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Sarabun', sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            width: 100%;
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 15px;
        }
        
        /* Header Styles */
        header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            display: flex;
            align-items: center;
            flex: 1;
        }
        
        .logo img {
            height: 120px;
            margin-right: 10px;
            flex-shrink: 0;
        }
        
        .logo-text {
            flex: 1;
            min-width: 0;
        }
        
        .logo-text h1 {
            font-size: clamp(1rem, 2.5vw, 1.5rem);
            font-weight: 700;
            margin-bottom: 0.2rem;
            line-height: 1.2;
            word-wrap: break-word;
        }
        
        .logo-text p {
            font-size: clamp(0.7rem, 1.5vw, 0.9rem);
            opacity: 0.9;
            line-height: 1.2;
        }
        
        nav {
            flex-shrink: 0;
            margin-left: 10px;
        }
        
        nav ul {
            display: flex;
            list-style: none;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        nav ul li a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            white-space: nowrap;
        }
        
        nav ul li a:hover {
            color: var(--light);
            text-decoration: underline;
        }
        
        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, rgba(44, 62, 80, 0.8), rgba(52, 152, 219, 0.8)), 
                        url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 400"><rect fill="%23f0f0f0" width="1200" height="400"/><circle fill="%23e0e0e0" cx="200" cy="100" r="50"/><circle fill="%23d0d0d0" cx="400" cy="200" r="30"/><circle fill="%23e0e0e0" cx="600" cy="150" r="40"/><circle fill="%23d0d0d0" cx="800" cy="250" r="35"/><circle fill="%23e0e0e0" cx="1000" cy="180" r="45"/></svg>');
            background-size: cover;
            background-position: center;
            height: 450px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: white;
            margin-bottom: 3rem;
        }
        
        .hero-content {
            position: relative;
            z-index: 1;
            max-width: 900px;
            padding: 0 20px;
        }
        
        .hero h2 {
            font-size: clamp(1.8rem, 4vw, 2.5rem);
            margin-bottom: 1rem;
            font-weight: 700;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
            line-height: 1.2;
        }
        
        .hero p {
            font-size: clamp(1rem, 2vw, 1.2rem);
            margin-bottom: 2rem;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.3);
            line-height: 1.4;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .btn {
            display: inline-block;
            padding: 0.8rem 1.8rem;
            background-color: var(--secondary);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }
        
        .btn:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .btn-accent {
            background-color: var(--accent);
        }
        
        .btn-accent:hover {
            background-color: #c0392b;
        }
        
        .btn-full {
            width: 100%;
            margin-bottom: 1rem;
        }
        
        /* Features Section */
        .features {
            padding: 3rem 0;
            background-color: white;
            margin-bottom: 3rem;
        }
        
        .section-title {
            text-align: center;
            margin-bottom: 3rem;
        }
        
        .section-title h2 {
            font-size: clamp(1.5rem, 3vw, 2rem);
            color: var(--dark);
            position: relative;
            display: inline-block;
            padding-bottom: 10px;
        }
        
        .section-title h2::after {
            content: '';
            position: absolute;
            width: 60%;
            height: 3px;
            background-color: var(--secondary);
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        
        .feature-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
        }
        
        .feature-icon {
            font-size: 2.5rem;
            color: var(--secondary);
            margin-bottom: 1.5rem;
        }
        
        .feature-card h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: var(--dark);
        }
        
        /* How It Works Section */
        .how-it-works {
            padding: 3rem 0;
            background-color: #f8f9fa;
            margin-bottom: 3rem;
        }
        
        .steps {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin-top: 2rem;
        }
        
        .steps::before {
            content: '';
            position: absolute;
            width: 80%;
            height: 3px;
            background-color: #ddd;
            top: 35px;
            left: 10%;
            z-index: 1;
        }
        
        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 25%;
            position: relative;
            z-index: 2;
        }
        
        .step-number {
            width: 70px;
            height: 70px;
            background-color: var(--secondary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .step-content {
            text-align: center;
        }
        
        .step-content h4 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }
        
        /* Login Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background-color: white;
            padding: 2rem;
            border-radius: 10px;
            width: 100%;
            max-width: 500px;
            position: relative;
            animation: modalFadeIn 0.3s ease;
        }
        
        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .close-modal {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 1.5rem;
            cursor: pointer;
            color: #888;
            transition: all 0.2s ease;
        }
        
        .close-modal:hover {
            color: var(--accent);
        }
        
        .modal-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        
        .modal-header h3 {
            font-size: 1.8rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }
        
        .modal-subtitle {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 1.5rem;
        }
        
        /* User Type Selection */
        .user-type-selection {
            margin-bottom: 1.5rem;
        }
        
        .user-type-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .user-type-card {
            background-color: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 1.5rem 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .user-type-card:hover {
            border-color: var(--secondary);
            transform: translateY(-2px);
        }
        
        .user-type-card.active {
            border-color: var(--secondary);
            background-color: rgba(52, 152, 219, 0.1);
        }
        
        .user-type-card.student.active {
            border-color: var(--secondary);
            background-color: rgba(52, 152, 219, 0.1);
        }
        
        .user-type-card.teacher.active {
            border-color: var(--teacher);
            background-color: rgba(142, 68, 173, 0.1);
        }
        
        .user-type-card.admin.active {
            border-color: var(--admin);
            background-color: rgba(230, 126, 34, 0.1);
        }
        
        .user-type-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .user-type-card.student .user-type-icon {
            color: var(--secondary);
        }
        
        .user-type-card.teacher .user-type-icon {
            color: var(--teacher);
        }
        
        .user-type-card.admin .user-type-icon {
            color: var(--admin);
        }
        
        .user-type-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.3rem;
        }
        
        .user-type-desc {
            font-size: 0.8rem;
            color: #666;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        .form-group input {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }
        
        .form-footer {
            text-align: center;
            margin-top: 1rem;
        }
        
        .form-footer p {
            margin-top: 1rem;
            font-size: 0.9rem;
            color: #777;
        }
        
        .form-footer a {
            color: var(--secondary);
            text-decoration: none;
            font-weight: 600;
        }
        
        .form-footer a:hover {
            text-decoration: underline;
        }
        
        /* About Section */
        .about {
            padding: 3rem 0;
            background-color: white;
        }
        
        .about-content {
            display: flex;
            align-items: center;
            gap: 2rem;
        }
        
        .about-image {
            flex: 1;
        }
        
        .about-image img {
            width: 100%;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .about-text {
            flex: 1;
        }
        
        .about-text h3 {
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            color: var(--dark);
        }
        
        /* Footer */
        footer {
            background-color: var(--dark);
            color: white;
            padding: 3rem 0 1.5rem;
        }
        
        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .footer-section h4 {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            position: relative;
            padding-bottom: 10px;
        }
        
        .footer-section h4::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 40px;
            height: 2px;
            background-color: var(--secondary);
        }
        
        .contact-info {
            margin-bottom: 1rem;
        }
        
        .contact-info p {
            display: flex;
            align-items: center;
            margin-bottom: 0.8rem;
        }
        
        .contact-info p i {
            margin-right: 10px;
            color: var(--secondary);
        }
        
        .footer-links ul {
            list-style: none;
        }
        
        .footer-links ul li {
            margin-bottom: 0.8rem;
        }
        
        .footer-links ul li a {
            color: #ddd;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .footer-links ul li a:hover {
            color: var(--secondary);
            margin-left: 5px;
        }
        
        .social-links {
            display: flex;
        }
        
        .social-links a {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background-color: rgba(255, 255, 255, 0.1);
            margin-right: 10px;
            border-radius: 50%;
            color: white;
            font-size: 1.2rem;
            transition: all 0.3s ease;
        }
        
        .social-links a:hover {
            background-color: var(--secondary);
            transform: translateY(-5px);
        }
        
        .copyright {
            text-align: center;
            padding-top: 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 0.9rem;
            color: #bbb;
        }
        
        /* Responsive */
        @media screen and (max-width: 768px) {
            .user-type-grid {
                grid-template-columns: 1fr;
            }
            
            .user-type-card {
                display: flex;
                align-items: center;
                text-align: left;
                padding: 1rem;
            }
            
            .user-type-icon {
                margin-right: 1rem;
                margin-bottom: 0;
            }
            
            .user-type-content {
                flex: 1;
            }
        }
        
        @media screen and (max-width: 1200px) {
            .logo-text h1 {
                font-size: 1.2rem;
            }
            
            .logo-text p {
                font-size: 0.8rem;
            }
        }
        
        @media screen and (max-width: 991px) {
            .header-content {
                flex-direction: column;
                text-align: center;
                gap: 3rem;
            }
            
            .logo {
                justify-content: center;
            }
            
            .logo-text h1 {
                font-size: 1.3rem;
            }
            
            nav {
                margin-left: 0;
            }
            
            nav ul {
                justify-content: center;
                gap: 15px;
            }
            
            .hero {
                height: 400px;
            }
            
            .hero h2 {
                font-size: 2rem;
            }
            
            .steps::before {
                display: none;
            }
            
            .steps {
                flex-direction: column;
            }
            
            .step {
                width: 100%;
                margin-bottom: 2rem;
            }
            
            .about-content {
                flex-direction: column;
            }
        }
        
        @media screen and (max-width: 768px) {
            .hero {
                height: 350px;
                padding: 0 15px;
            }
            
            .hero h2 {
                font-size: 1.8rem;
            }
            
            .hero p {
                font-size: 1rem;
            }
            
            .feature-card {
                padding: 1.5rem;
            }
            
            .logo-text h1 {
                font-size: 1.1rem;
            }
            
            .logo-text p {
                font-size: 0.75rem;
            }
            
            nav ul {
                flex-direction: column;
                align-items: center;
                gap: 10px;
            }
            
            nav ul li a {
                font-size: 0.85rem;
            }
        }
        
        @media screen and (max-width: 480px) {
            .hero {
                height: 300px;
            }
            
            .hero h2 {
                font-size: 1.5rem;
            }
            
            .hero p {
                font-size: 0.9rem;
            }
            
            .logo img {
                height: 70px;
            }
            
            .logo-text h1 {
                font-size: 1rem;
            }
            
            .logo-text p {
                font-size: 0.7rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo">
                    <img src="images/logo2.png" alt="โลโก้มหาวิทยาลัย">
                    <div class="logo-text">
                        <h1>ระบบแนะนำรายวิชาชีพเลือกด้วยต้นไม้ตัดสินใจ</h1>
                        <p>Academic Path Recommender using Decision Trees</p>
                    </div>
                </div>

                <nav>
                    <ul>
                        <li><a href="#home">หน้าหลัก</a></li>
                        <li><a href="#features">คุณสมบัติ</a></li>
                        <li><a href="#how-it-works">วิธีการใช้งาน</a></li>
                        <li><a href="#about">เกี่ยวกับเรา</a></li>
                        <li><a href="#" id="login-btn" class="btn btn-accent">เข้าสู่ระบบ</a></li>
                        <li><a href="register.php" id="Register-btn" class="btn btn-accent">ลงทะเบียน</a></li>
                    </ul>
                </nav>
            </div>
        </div>
    </header>

    <section class="hero" id="home">
        <div class="hero-content">
            <h2>ค้นพบรายวิชาที่เหมาะกับคุณที่สุด</h2>
            <p>ระบบแนะนำรายวิชาชีพเลือกด้วยต้นไม้ตัดสินใจ เพื่อช่วยคุณเลือกวิชาที่ตรงกับความสนใจ</p>
            <a href="#" class="btn" id="recommendation-btn">รับคำแนะนำทันที</a>
        </div>
    </section>

    <section class="features" id="features">
        <div class="container">
            <div class="section-title">
                <h2>คุณสมบัติระบบ</h2>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-brain"></i>
                    </div>
                    <h3>ต้นไม้ตัดสินใจอัจฉริยะ</h3>
                    <p>ระบบใช้อัลกอริทึมต้นไม้ตัดสินใจที่ทันสมัยเพื่อวิเคราะห์ข้อมูลและให้คำแนะนำที่แม่นยำ</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <h3>เชื่อมโยงกับหลักสูตร</h3>
                    <p>วิเคราะห์รายวิชาชีพเลือกในหลักสูตรปรับปรุง พ.ศ. 2565 เพื่อแนะนำวิชาที่เหมาะสมที่สุด</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h3>ติดตามผลการเรียน</h3>
                    <p>ติดตามความก้าวหน้าและผลการเรียนของคุณเพื่อปรับปรุงคำแนะนำให้ตรงกับความต้องการ</p>
                </div>
            </div>
        </div>
    </section>

    <section class="how-it-works" id="how-it-works">
        <div class="container">
            <div class="section-title">
                <h2>วิธีการใช้งาน</h2>
            </div>
            <div class="steps">
                <div class="step">
                    <div class="step-number">1</div>
                    <div class="step-content">
                        <h4>ลงทะเบียน</h4>
                        <p>สร้างบัญชีผู้ใช้ด้วยรหัสนักศึกษาและข้อมูลส่วนตัว</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <div class="step-content">
                        <h4>เข้าสู่ระบบ</h4>
                        <p>กรุณาเข้าสู่ระบบเพื่อเริ่มต้นใช้งานระบบแนะนำแผนการเรียน โดยใช้ <strong>รหัสนักศึกษา</strong> และ <strong>รหัสผ่าน</strong> ของคุณ</p>
                        <p>หากยังไม่มีบัญชี หรือไม่สามารถเข้าสู่ระบบได้ กรุณาติดต่อเจ้าหน้าที่ผู้ดูแลระบบ</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <div class="step-content">
                        <h4>รับคำแนะนำ</h4>
                        <p>ระบบจะวิเคราะห์และแนะนำรายวิชาที่เหมาะสม</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-number">4</div>
                    <div class="step-content">
                        <h4>วางแผนลงทะเบียน</h4>
                        <p>ใช้คำแนะนำจากระบบเพื่อช่วยในการวางแผนการลงทะเบียนเรียนให้สอดคล้องกับความสนใจและเป้าหมายอาชีพของคุณ</p>
                        <p>อย่าลืมนำแผนที่ได้ไป <strong>ปรึกษาอาจารย์ที่ปรึกษา</strong> เพื่อให้ได้รับคำแนะนำเพิ่มเติมและลงทะเบียนได้อย่างเหมาะสม</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="about" id="about">
        <div class="container">
            <div class="section-title">
                <h2>เกี่ยวกับระบบ</h2>
            </div>
            <div class="about-content">
                <div class="about-image">
                    <img src="images/image.png" alt="ภาพเกี่ยวกับเรา">
                </div>
                <div class="about-text">
                    <h3>ความเป็นมาและวัตถุประสงค์</h3>
                    <p>ระบบแนะนำรายวิชาชีพเลือกด้วยต้นไม้ตัดสินใจ (Academic Path Recommender using Decision Trees) นี้ถูกพัฒนาขึ้นเพื่อเป็นเครื่องมือสนับสนุนการตัดสินใจสำหรับนักศึกษาหลักสูตรวิทยาศาสตรบัณฑิต ให้สามารถเลือกรายวิชาชีพเลือกได้อย่างเหมาะสมและสอดคล้องกับความสนใจ ศักยภาพ และเป้าหมายทางอาชีพในอนาคต</p>
                    <p>เราเล็งเห็นถึงความสำคัญของการเลือกวิชาที่ถูกต้อง ซึ่งมีผลอย่างยิ่งต่อเส้นทางอาชีพของนักศึกษา ด้วยการใช้อัลกอริทึมต้นไม้ตัดสินใจ ระบบจะวิเคราะห์ข้อมูลที่เกี่ยวข้อง เพื่อให้คำแนะนำที่แม่นยำและเป็นประโยชน์สูงสุดแก่นักศึกษา</p>
                </div>
            </div>
        </div>
    </section>

    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-section about-us">
                    <h4>เกี่ยวกับเรา</h4>
                    <p>ระบบแนะนำรายวิชาชีพเลือก ที่ช่วยให้นักศึกษาสามารถค้นพบเส้นทางการเรียนรู้ที่เหมาะสมกับตนเอง</p>
                </div>
                <div class="footer-section quick-links">
                    <h4>ลิงก์ด่วน</h4>
                    <ul>
                        <li><a href="#home">หน้าหลัก</a></li>
                        <li><a href="#features">คุณสมบัติ</a></li>
                        <li><a href="#how-it-works">วิธีการใช้งาน</a></li>
                        <li><a href="#about">เกี่ยวกับเรา</a></li>
                    </ul>
                </div>
                <div class="footer-section contact-info">
                    <h4>ติดต่อเรา</h4>
                    <p><i class="fas fa-map-marker-alt"></i> มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน วิทยาเขตสุรินทร์</p>
                    <p><i class="fas fa-envelope"></i> contact@rmuti.ac.th</p>
                    <p><i class="fas fa-phone"></i> +66 44 123 456</p>
                </div>
                <div class="footer-section social">
                    <h4>ติดตามเรา</h4>
                    <div class="social-links">
                        <a href="#"><i class="fab fa-facebook-f"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>
            </div>
            <div class="copyright">
                <p>&copy; 2025 ระบบแนะนำรายวิชาชีพเลือก. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <div id="login-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <div class="modal-header">
                <h3>เข้าสู่ระบบ</h3>
                <p class="modal-subtitle">กรุณาเลือกประเภทผู้ใช้งานของคุณ</p>
            </div>
            <div class="user-type-selection">
                <div class="user-type-grid">
                    <div class="user-type-card student" data-user-type="student">
                        <div class="user-type-icon"><i class="fas fa-user-graduate"></i></div>
                        <div class="user-type-content">
                            <div class="user-type-title">นักศึกษา</div>
                            <div class="user-type-desc">เข้าถึงระบบแนะนำวิชาชีพ</div>
                        </div>
                    </div>
                    <div class="user-type-card teacher" data-user-type="teacher">
                        <div class="user-type-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                        <div class="user-type-content">
                            <div class="user-type-title">อาจารย์</div>
                            <div class="user-type-desc">ดูข้อมูลนักศึกษา, จัดการรายวิชา</div>
                        </div>
                    </div>
                    <div class="user-type-card admin" data-user-type="admin">
                        <div class="user-type-icon"><i class="fas fa-user-shield"></i></div>
                        <div class="user-type-content">
                            <div class="user-type-title">ผู้ดูแลระบบ</div>
                            <div class="user-type-desc">จัดการผู้ใช้, กำหนดค่าระบบ</div>
                        </div>
                    </div>
                </div>
            </div>
            <form id="login-form">
                <div class="form-group">
                    <label for="username" id="username-label">ชื่อผู้ใช้</label>
                    <input type="text" id="username" name="username" placeholder="กรุณาเลือกประเภทผู้ใช้" required>
                </div>
                <div class="form-group">
                    <label for="password">รหัสผ่าน</label>
                    <input type="password" id="password" name="password" placeholder="รหัสผ่าน" required>
                </div>
                <button type="submit" class="btn btn-full">เข้าสู่ระบบ</button>
                <div class="form-footer">
                    <p>ยังไม่มีบัญชี? <a href="register.php">ลงทะเบียนที่นี่</a></p>
                    <p><a href="forgot_password.php">ลืมรหัสผ่าน?</a></p>
                </div>
            </form>
        </div>
    </div>
    <script>
        $(document).ready(function() {
            // Smooth scrolling for navigation links
            $('a[href^="#"]').on('click', function(event) {
                var target = this.hash;
                if (target) {
                    event.preventDefault();
                    $('html, body').animate({
                        scrollTop: $(target).offset().top
                    }, 800);
                }
            });

            // Login Modal functionality
            const loginBtn = $('#login-btn');
            const recommendationBtn = $('#recommendation-btn');
            const loginModal = $('#login-modal');
            const closeModal = $('.close-modal');
            const userTypeCards = $('.user-type-card');
            const loginForm = $('#login-form');
            const usernameLabel = $('#username-label');
            const usernameInput = $('#username');
            

            function openLoginModal() {
                loginModal.css('display', 'flex');
            }

            function closeLoginModal() {
                loginModal.css('display', 'none');
                userTypeCards.removeClass('active');
                loginForm[0].reset();
                // รีเซ็ตป้ายชื่อกลับเป็นค่าเริ่มต้น
                usernameLabel.text('ชื่อผู้ใช้');
                usernameInput.attr('placeholder', 'รหัสนักศึกษา หรือ ชื่อผู้ใช้');
                selectedUserType = '';
            }

            // ฟังก์ชันสำหรับเปลี่ยนป้ายชื่อตามประเภทผู้ใช้
function updateUsernameLabel(userType) {
    switch(userType) {
        case 'student':
            usernameLabel.text('รหัสนักศึกษา');
            usernameInput.attr('placeholder', 'กรอกรหัสนักศึกษา');
            break;
        case 'teacher':
            // ✅ รองรับทั้งรหัสอาจารย์และ Username ให้ตรงกับ login.php
            usernameLabel.text('รหัสอาจารย์หรือ Username');
            usernameInput.attr('placeholder', 'เช่น T001 หรือ kan123');
            break;
        case 'admin':
            usernameLabel.text('ชื่อผู้ใช้');
            usernameInput.attr('placeholder', 'กรอกชื่อผู้ใช้');
            break;
        default:
            usernameLabel.text('ชื่อผู้ใช้');
            usernameInput.attr('placeholder', 'รหัสนักศึกษา หรือ ชื่อผู้ใช้');
            break;
    }
}


            loginBtn.on('click', function(e) {
                e.preventDefault();
                openLoginModal();
            });

            recommendationBtn.on('click', function(e) {
                e.preventDefault();
                openLoginModal();
            });

            closeModal.on('click', closeLoginModal);

            $(window).on('click', function(event) {
                if ($(event.target).is(loginModal)) {
                    closeLoginModal();
                }
            });

            let selectedUserType = '';

            // เมื่อคลิกเลือกประเภทผู้ใช้
            userTypeCards.on('click', function() {
                userTypeCards.removeClass('active');
                $(this).addClass('active');
                selectedUserType = $(this).data('user-type');
                
                // เปลี่ยนป้ายชื่อและ placeholder
                updateUsernameLabel(selectedUserType);
                
                console.log('Selected user type:', selectedUserType);
            });

            loginForm.on('submit', function(e) {
                e.preventDefault();

                const username = $('#username').val();
                const password = $('#password').val();

                if (!selectedUserType) {
                    alert('กรุณาเลือกประเภทผู้ใช้งานก่อนเข้าสู่ระบบ');
                    return;
                }

                $.ajax({
                    url: 'login.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        username: username,
                        password: password,
                        user_type: selectedUserType,
                        ajax: '1'
                    },
                    success: function (resp) {
                        if (resp.success) {
                        window.location.href = resp.redirect_url || 'index.php';
                        } else {
                        alert('เข้าสู่ระบบไม่สำเร็จ: ' + (resp.message || 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'));
                        }
                    },
                    error: function (jqXHR) {
                        console.error('AJAX Error:', jqXHR.responseText);
                        alert('เกิดข้อผิดพลาดในการเชื่อมต่อ กรุณาลองใหม่อีกครั้ง');
                    }
                });
            });
        });
    </script>
</body>
</html>