<?php 
// db_connect.php - ไฟล์เชื่อมต่อฐานข้อมูล

// กำหนดค่าการเชื่อมต่อฐานข้อมูล
define('DB_HOST', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_DATABASE', 'studentregistration');

try {
    // --- FIX: ใช้ค่าจาก define() โดยตรง และปรับ charset เป็น utf8mb4 ---
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
        DB_USERNAME,
        DB_PASSWORD
    );
    
    // --- ADD THIS LINE (คงไว้ตามเดิม) ---
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // --- END ADD ---
    
    // ตั้งค่า collation ให้ชัดเจน (เสริมความเข้ากันได้กับ utf8mb4)
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

} catch (PDOException $e) {
    die("ไม่สามารถเชื่อมต่อฐานข้อมูลได้: " . $e->getMessage());
}

// สร้างการเชื่อมต่อกับฐานข้อมูล
$conn = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_DATABASE);

// ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    // Log error for debugging
    error_log("Database connection failed: " . $conn->connect_error);
    die("การเชื่อมต่อฐานข้อมูลล้มเหลว กรุณาลองใหม่อีกครั้ง");
}

// ตั้งค่าการเข้ารหัส UTF-8
$conn->set_charset('utf8mb4');
$conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");


// ตั้งค่า timezone
date_default_timezone_set('Asia/Bangkok');

/**
 * Function สำหรับการปิดการเชื่อมต่อฐานข้อมูล
 */
function closeConnection() {
    global $conn;
    if ($conn) {
        $conn->close();
    }
}

/**
 * Function สำหรับการดึงข้อมูลนักศึกษาจาก student_id
 * @param string $student_id รหัสนักศึกษา
 * @return array|false ข้อมูลนักศึกษาในรูปแบบ array หรือ false ถ้าไม่พบ
 */
function getStudentData($student_id) {
    global $conn;
    
    // SQL query to join personal_info, education_info, and user_login
    $sql = "SELECT s.*, p.full_name, p.birthdate, p.gender, p.citizen_id, p.address, p.phone, p.email,
                   e.faculty, e.major, e.education_level, e.curriculum_name, e.program_type, e.curriculum_year,
                   e.student_group, e.gpa, e.status, e.education_term, e.education_year,
                   u.last_login_time
            FROM students s
            LEFT JOIN personal_info p ON s.personal_info_id = p.id
            LEFT JOIN education_info e ON s.education_info_id = e.id
            LEFT JOIN user_login u ON s.student_id = u.student_id
            WHERE s.student_id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $data = $result->fetch_assoc();
        $stmt->close();
        return $data;
    }
    
    $stmt->close();
    return false;
}

/**
 * Function สำหรับการตรวจสอบการเข้าสู่ระบบ
 * @param string $student_id รหัสนักศึกษา
 * @param string $password รหัสผ่าน
 * @return array ผลลัพธ์การตรวจสอบ (success, message)
 */
function authenticateUser($student_id, $password) {
    global $conn;
    
    $sql = "SELECT u.password, u.login_attempts, u.locked_until, u.user_role 
            FROM user_login u 
            WHERE u.student_id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("AuthenticateUser prepare failed: " . $conn->error);
        return array('success' => false, 'message' => 'เกิดข้อผิดพลาดภายในระบบ');
    }
    
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        $stmt->close();
        
        // ตรวจสอบว่าบัญชีถูกล็อคหรือไม่
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            return array('success' => false, 'message' => 'บัญชีถูกล็อค กรุณาลองใหม่ภายหลัง');
        }
        
        // ตรวจสอบรหัสผ่าน
        if (password_verify($password, $user['password'])) {
            // รีเซ็ตจำนวนครั้งที่ลองเข้าสู่ระบบ
            resetLoginAttempts($student_id);
            // อัพเดทเวลาเข้าสู่ระบบล่าสุด
            updateLastLogin($student_id);
            // บันทึกประวัติการเข้าสู่ระบบ
            logActivity($student_id, 'login', 'เข้าสู่ระบบสำเร็จ');
            
            return array('success' => true, 'message' => 'เข้าสู่ระบบสำเร็จ', 'user_role' => $user['user_role']);
        } else {
            // เพิ่มจำนวนครั้งที่ลองเข้าสู่ระบบ
            incrementLoginAttempts($student_id);
            return array('success' => false, 'message' => 'รหัสผ่านไม่ถูกต้อง');
        }
    }
    
    $stmt->close();
    return array('success' => false, 'message' => 'ไม่พบรหัสนักศึกษานี้ในระบบ');
}

/**
 * Function สำหรับการรีเซ็ตจำนวนครั้งที่ลองเข้าสู่ระบบ
 * @param string $student_id รหัสนักศึกษา
 */
function resetLoginAttempts($student_id) {
    global $conn;
    
    $sql = "UPDATE user_login SET login_attempts = 0, locked_until = NULL WHERE student_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log("resetLoginAttempts prepare failed: " . $conn->error);
    }
}

/**
 * Function สำหรับการเพิ่มจำนวนครั้งที่ลองเข้าสู่ระบบ และล็อคบัญชีหากถึงเกณฑ์
 * @param string $student_id รหัสนักศึกษา
 */
function incrementLoginAttempts($student_id) {
    global $conn;
    
    $sql = "UPDATE user_login SET login_attempts = login_attempts + 1 WHERE student_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log("incrementLoginAttempts update failed: " . $conn->error);
        return;
    }
    
    // ตรวจสอบจำนวนครั้งที่ลองเข้าสู่ระบบ
    $sql = "SELECT login_attempts FROM user_login WHERE student_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            // กำหนดเกณฑ์การล็อคบัญชี เช่น 5 ครั้ง
            if ($user['login_attempts'] >= 5) {
                // ล็อคบัญชี 30 นาที
                $locked_until = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                $sql_lock = "UPDATE user_login SET locked_until = ? WHERE student_id = ?";
                $stmt_lock = $conn->prepare($sql_lock);
                if ($stmt_lock) {
                    $stmt_lock->bind_param("ss", $locked_until, $student_id);
                    $stmt_lock->execute();
                    $stmt_lock->close();
                    logActivity($student_id, 'account_locked', 'บัญชีถูกล็อคเนื่องจากพยายามเข้าสู่ระบบผิดพลาดหลายครั้ง');
                } else {
                    error_log("incrementLoginAttempts lock update failed: " . $conn->error);
                }
            }
        }
        $stmt->close();
    } else {
        error_log("incrementLoginAttempts select failed: " . $conn->error);
    }
}

/**
 * Function สำหรับการอัพเดทเวลาเข้าสู่ระบบล่าสุด
 * @param string $student_id รหัสนักศึกษา
 */
function updateLastLogin($student_id) {
    global $conn;
    
    $sql = "UPDATE user_login SET last_login_time = NOW() WHERE student_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log("updateLastLogin prepare failed: " . $conn->error);
    }
}

/**
 * Function สำหรับการบันทึกประวัติการใช้งาน (Activity Log)
 * @param string $student_id รหัสนักศึกษา
 * @param string $activity_type ประเภทกิจกรรม (เช่น login, logout, profile_update, password_change)
 * @param string $description รายละเอียดกิจกรรม
 * @param string|null $ip_address IP Address ของผู้ใช้งาน (ถ้าไม่ระบุจะดึงจาก $_SERVER)
 * @param string|null $user_agent User Agent ของผู้ใช้งาน (ถ้าไม่ระบุจะดึงจาก $_SERVER)
 */
function logActivity($student_id, $activity_type, $description = '', $ip_address = null, $user_agent = null) {
    global $conn;
    
    if (!$ip_address) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }
    
    if (!$user_agent) {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }
    
    $sql = "INSERT INTO activity_log (student_id, activity_type, activity_description, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("sssss", $student_id, $activity_type, $description, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log("logActivity prepare failed: " . $conn->error);
    }
}

/**
 * Function สำหรับการดึงประวัติการใช้งาน (Activity History) ของนักศึกษา
 * @param string $student_id รหัสนักศึกษา
 * @param int $limit จำนวนประวัติที่ต้องการดึง
 * @return array อาร์เรย์ของประวัติกิจกรรม
 */
function getActivityHistory($student_id, $limit = 20) {
    global $conn;
    
    $sql = "SELECT activity_type, activity_description, ip_address, created_at 
            FROM activity_log 
            WHERE student_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("getActivityHistory prepare failed: " . $conn->error);
        return [];
    }
    
    $stmt->bind_param("si", $student_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $activities = array();
    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }
    
    $stmt->close();
    return $activities;
}

/**
 * Function สำหรับการอัพเดทข้อมูลนักศึกษา (Profile)
 * ต้องมีการส่งค่า $data ที่มีคีย์ตรงกับชื่อคอลัมน์ในตาราง personal_info และ education_info
 * @param string $student_id รหัสนักศึกษา
 * @param array $data ข้อมูลที่ต้องการอัพเดท
 * @return array ผลลัพธ์การอัพเดท (success, message)
 */
function updateStudentProfile($student_id, $data) {
    global $conn;
    
    try {
        $conn->begin_transaction();
        
        // ดึง personal_info_id และ education_info_id จากตาราง students
        $stmt_ids = $conn->prepare("SELECT personal_info_id, education_info_id FROM students WHERE student_id = ?");
        if (!$stmt_ids) {
            throw new Exception("Prepare failed for getting IDs: " . $conn->error);
        }
        $stmt_ids->bind_param("s", $student_id);
        $stmt_ids->execute();
        $result_ids = $stmt_ids->get_result();
        $student_info_ids = $result_ids->fetch_assoc();
        $stmt_ids->close();

        if (!$student_info_ids) {
            throw new Exception("Student ID not found.");
        }

        $personal_info_id = $student_info_ids['personal_info_id'];
        $education_info_id = $student_info_ids['education_info_id'];

        // อัพเดทข้อมูลส่วนตัวใน personal_info
        $sql_personal = "UPDATE personal_info SET full_name = ?, birthdate = ?, gender = ?, 
                                                 citizen_id = ?, address = ?, phone = ?, email = ?
                         WHERE id = ?";
        $stmt_personal = $conn->prepare($sql_personal);
        if (!$stmt_personal) {
            throw new Exception("Prepare failed for personal_info update: " . $conn->error);
        }
        $stmt_personal->bind_param("sssssssi", 
            $data['full_name'], $data['birthdate'], $data['gender'],
            $data['citizen_id'], $data['address'], $data['phone'], 
            $data['email'], $personal_info_id);
        $stmt_personal->execute();
        $stmt_personal->close();
        
        // อัพเดทข้อมูลการศึกษาใน education_info
        $sql_education = "UPDATE education_info SET faculty = ?, major = ?, education_level = ?, 
                                                 curriculum_name = ?, program_type = ?, curriculum_year = ?,
                                                 student_group = ?, gpa = ?, status = ?, 
                                                 education_term = ?, education_year = ?
                         WHERE id = ?";
        $stmt_education = $conn->prepare($sql_education);
        if (!$stmt_education) {
            throw new Exception("Prepare failed for education_info update: " . $conn->error);
        }
        // เนื่องจาก GPA เป็น float/double ให้ใช้ 'd' หรือ 'f' ถ้าใช้ bind_param
        // ถ้าเป็น PHP 8.1+ สามารถใช้ ...$data for named parameters ได้
        $stmt_education->bind_param("ssssssssdsii", // s: string, d: double, i: integer
            $data['faculty'], $data['major'], $data['education_level'],
            $data['curriculum_name'], $data['program_type'], $data['curriculum_year'],
            $data['student_group'], $data['gpa'], $data['status'],
            $data['education_term'], $data['education_year'], $education_info_id);
        $stmt_education->execute();
        $stmt_education->close();
        
        $conn->commit();
        
        // บันทึกประวัติการแก้ไข
        logActivity($student_id, 'profile_update', 'อัพเดทข้อมูลส่วนตัว');
        
        return array('success' => true, 'message' => 'อัพเดทข้อมูลสำเร็จ');
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Update profile error: " . $e->getMessage());
        return array('success' => false, 'message' => 'เกิดข้อผิดพลาดในการอัพเดทข้อมูล: ' . $e->getMessage());
    }
}


/**
 * Function สำหรับการเปลี่ยนรหัสผ่าน
 * @param string $student_id รหัสนักศึกษา
 * @param string $old_password รหัสผ่านเดิม
 * @param string $new_password รหัสผ่านใหม่
 * @return array ผลลัพธ์การเปลี่ยนรหัสผ่าน (success, message)
 */
function changePassword($student_id, $old_password, $new_password) {
    global $conn;
    
    // ตรวจสอบรหัสผ่านเดิม
    $sql = "SELECT password FROM user_login WHERE student_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("changePassword select prepare failed: " . $conn->error);
        return array('success' => false, 'message' => 'เกิดข้อผิดพลาดภายในระบบ');
    }
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if (password_verify($old_password, $user['password'])) {
            // เปลี่ยนรหัสผ่าน
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $sql_update = "UPDATE user_login SET password = ? WHERE student_id = ?";
            $stmt_update = $conn->prepare($sql_update);
            if (!$stmt_update) {
                error_log("changePassword update prepare failed: " . $conn->error);
                return array('success' => false, 'message' => 'เกิดข้อผิดพลาดในการเปลี่ยนรหัสผ่าน');
            }
            $stmt_update->bind_param("ss", $hashed_password, $student_id);
            $stmt_update->execute();
            $stmt_update->close();
            
            // บันทึกประวัติ
            logActivity($student_id, 'password_change', 'เปลี่ยนรหัสผ่าน');
            
            return array('success' => true, 'message' => 'เปลี่ยนรหัสผ่านสำเร็จ');
        } else {
            return array('success' => false, 'message' => 'รหัสผ่านเดิมไม่ถูกต้อง');
        }
    }
    
    $stmt->close();
    return array('success' => false, 'message' => 'ไม่พบรหัสนักศึกษานี้ในระบบ');
}

/**
 * Function สำหรับการดึงข้อมูลแบบทดสอบ (Quizzes)
 * @param bool $active_only หากเป็น true จะดึงเฉพาะแบบทดสอบที่ active
 * @return array อาร์เรย์ของข้อมูลแบบทดสอบ
 */
function getQuizzes($active_only = true) {
    global $conn;
    
    $sql = "SELECT * FROM quizzes";
    if ($active_only) {
        $sql .= " WHERE status = 'active'"; // หรือ column ที่ใช้ระบุว่า active
    }
    $sql .= " ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("getQuizzes prepare failed: " . $conn->error);
        return [];
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $quizzes = [];
    while ($row = $result->fetch_assoc()) {
        $quizzes[] = $row;
    }
    
    $stmt->close();
    return $quizzes;
}

/**
 * Function สำหรับการลงทะเบียนนักศึกษาใหม่
 * จะเพิ่มข้อมูลลงใน personal_info, education_info, user_login และ students
 * @param array $data ข้อมูลนักศึกษาที่จะลงทะเบียน (ควรรวมข้อมูลครบทุกส่วน)
 * @return array ผลลัพธ์การลงทะเบียน (success, message, student_id)
 */
function registerStudent($data) {
    global $conn;

    try {
        $conn->begin_transaction();

        // 1. Insert into personal_info
        $sql_personal = "INSERT INTO personal_info (full_name, birthdate, gender, citizen_id, address, phone, email) 
                         VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt_personal = $conn->prepare($sql_personal);
        if (!$stmt_personal) {
            throw new Exception("Prepare failed for personal_info insert: " . $conn->error);
        }
        $stmt_personal->bind_param("sssssss",
            $data['full_name'], $data['birthdate'], $data['gender'], $data['citizen_id'],
            $data['address'], $data['phone'], $data['email']);
        $stmt_personal->execute();
        $personal_info_id = $stmt_personal->insert_id;
        $stmt_personal->close();

        // 2. Insert into education_info
        $sql_education = "INSERT INTO education_info (faculty, major, education_level, curriculum_name, program_type, curriculum_year, student_group, gpa, status, education_term, education_year) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_education = $conn->prepare($sql_education);
        if (!$stmt_education) {
            throw new Exception("Prepare failed for education_info insert: " . $conn->error);
        }
        $stmt_education->bind_param("ssssssssdis", // s:string, d:double, i:integer (for education_year)
            $data['faculty'], $data['major'], $data['education_level'], $data['curriculum_name'],
            $data['program_type'], $data['curriculum_year'], $data['student_group'], $data['gpa'],
            $data['status'], $data['education_term'], $data['education_year']);
        $stmt_education->execute();
        $education_info_id = $stmt_education->insert_id;
        $stmt_education->close();

        // 3. Insert into user_login
        $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
        $user_role = $data['user_role'] ?? 'student'; // กำหนด role เริ่มต้น
        $sql_user_login = "INSERT INTO user_login (student_id, password, user_role) 
                           VALUES (?, ?, ?)";
        $stmt_user_login = $conn->prepare($sql_user_login);
        if (!$stmt_user_login) {
            throw new Exception("Prepare failed for user_login insert: " . $conn->error);
        }
        $stmt_user_login->bind_param("sss", $data['student_id'], $hashed_password, $user_role);
        $stmt_user_login->execute();
        $stmt_user_login->close();

        // 4. Insert into students (linking table)
        $sql_students = "INSERT INTO students (student_id, personal_info_id, education_info_id) 
                         VALUES (?, ?, ?)";
        $stmt_students = $conn->prepare($sql_students);
        if (!$stmt_students) {
            throw new Exception("Prepare failed for students insert: " . $conn->error);
        }
        $stmt_students->bind_param("sii", $data['student_id'], $personal_info_id, $education_info_id);
        $stmt_students->execute();
        $stmt_students->close();

        $conn->commit();
        logActivity($data['student_id'], 'registration', 'ลงทะเบียนนักศึกษาใหม่สำเร็จ');
        return array('success' => true, 'message' => 'ลงทะเบียนนักศึกษาสำเร็จ', 'student_id' => $data['student_id']);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Student registration error: " . $e->getMessage());
        return array('success' => false, 'message' => 'เกิดข้อผิดพลาดในการลงทะเบียน: ' . $e->getMessage());
    }
}

/**
 * Function สำหรับการดึงข้อมูลคอร์สเรียนทั้งหมด
 * @return array อาร์เรย์ของข้อมูลคอร์สเรียน
 */
function getAllCourses() {
    global $conn;
    
    $sql = "SELECT * FROM courses ORDER BY course_name ASC";
    $result = $conn->query($sql);
    
    $courses = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $courses[] = $row;
        }
    } else {
        error_log("getAllCourses query failed: " . $conn->error);
    }
    return $courses;
}

/**
 * Function สำหรับการดึงข้อมูลคอร์สเรียนที่นักศึกษาลงทะเบียนไว้
 * @param string $student_id รหัสนักศึกษา
 * @return array อาร์เรย์ของข้อมูลคอร์สเรียนที่ลงทะเบียน
 */
function getEnrolledCourses($student_id) {
    global $conn;
    
    $sql = "SELECT c.*, sc.enrollment_date 
            FROM student_courses sc
            JOIN courses c ON sc.course_id = c.course_id
            WHERE sc.student_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("getEnrolledCourses prepare failed: " . $conn->error);
        return [];
    }
    
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $enrolled_courses = [];
    while ($row = $result->fetch_assoc()) {
        $enrolled_courses[] = $row;
    }
    
    $stmt->close();
    return $enrolled_courses;
}

/**
 * Function สำหรับลงทะเบียนนักศึกษาในคอร์สเรียน
 * @param string $student_id รหัสนักศึกษา
 * @param int $course_id รหัสคอร์สเรียน
 * @return array ผลลัพธ์ (success, message)
 */
function enrollStudentInCourse($student_id, $course_id) {
    global $conn;

    // ตรวจสอบว่านักศึกษาได้ลงทะเบียนในคอร์สนี้แล้วหรือยัง
    $check_sql = "SELECT COUNT(*) FROM student_courses WHERE student_id = ? AND course_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    if (!$check_stmt) {
        error_log("enrollStudentInCourse check prepare failed: " . $conn->error);
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาดในการตรวจสอบการลงทะเบียน'];
    }
    $check_stmt->bind_param("si", $student_id, $course_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result()->fetch_row()[0];
    $check_stmt->close();

    if ($check_result > 0) {
        return ['success' => false, 'message' => 'นักศึกษาได้ลงทะเบียนในคอร์สนี้แล้ว'];
    }
    
    $sql = "INSERT INTO student_courses (student_id, course_id, enrollment_date) VALUES (?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("enrollStudentInCourse insert prepare failed: " . $conn->error);
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาดในการลงทะเบียนคอร์สเรียน'];
    }
    
    $stmt->bind_param("si", $student_id, $course_id);
    if ($stmt->execute()) {
        logActivity($student_id, 'course_enrollment', 'ลงทะเบียนคอร์สเรียน ID: ' . $course_id);
        $stmt->close();
        return ['success' => true, 'message' => 'ลงทะเบียนคอร์สเรียนสำเร็จ'];
    } else {
        error_log("enrollStudentInCourse execute failed: " . $stmt->error);
        $stmt->close();
        return ['success' => false, 'message' => 'ไม่สามารถลงทะเบียนคอร์สเรียนได้'];
    }
}

/**
 * Function สำหรับยกเลิกการลงทะเบียนนักศึกษาในคอร์สเรียน
 * @param string $student_id รหัสนักศึกษา
 * @param int $course_id รหัสคอร์สเรียน
 * @return array ผลลัพธ์ (success, message)
 */
function unenrollStudentFromCourse($student_id, $course_id) {
    global $conn;

    $sql = "DELETE FROM student_courses WHERE student_id = ? AND course_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("unenrollStudentFromCourse prepare failed: " . $conn->error);
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาดในการยกเลิกการลงทะเบียน'];
    }
    
    $stmt->bind_param("si", $student_id, $course_id);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            logActivity($student_id, 'course_unenrollment', 'ยกเลิกการลงทะเบียนคอร์สเรียน ID: ' . $course_id);
            $stmt->close();
            return ['success' => true, 'message' => 'ยกเลิกการลงทะเบียนคอร์สเรียนสำเร็จ'];
        } else {
            $stmt->close();
            return ['success' => false, 'message' => 'ไม่พบข้อมูลการลงทะเบียนคอร์สนี้'];
        }
    } else {
        error_log("unenrollStudentFromCourse execute failed: " . $stmt->error);
        $stmt->close();
        return ['success' => false, 'message' => 'ไม่สามารถยกเลิกการลงทะเบียนคอร์สเรียนได้'];
    }
}

/**
 * Function สำหรับดึงข้อมูลนักศึกษาทั้งหมด (อาจมี filter สำหรับ Admin)
 * @param string $role_filter กรองตามบทบาท (เช่น 'student', 'admin', 'all')
 * @return array อาร์เรย์ของข้อมูลนักศึกษาทั้งหมด
 */
function getAllStudents($role_filter = 'student') {
    global $conn;
    
    $sql = "SELECT s.student_id, p.full_name, e.faculty, e.major, u.user_role, u.last_login_time, u.login_attempts, u.locked_until
            FROM students s
            LEFT JOIN personal_info p ON s.personal_info_id = p.id
            LEFT JOIN education_info e ON s.education_info_id = e.id
            LEFT JOIN user_login u ON s.student_id = u.student_id";
    
    $params = [];
    $types = '';

    if ($role_filter != 'all') {
        $sql .= " WHERE u.user_role = ?";
        $params[] = $role_filter;
        $types .= 's';
    }
    
    $sql .= " ORDER BY p.full_name ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("getAllStudents prepare failed: " . $conn->error);
        return [];
    }

    if (!empty($params)) {
        call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $params));
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    
    $stmt->close();
    return $students;
}

/**
 * Function สำหรับลบข้อมูลนักศึกษา (ต้องลบจากหลายตาราง)
 * @param string $student_id รหัสนักศึกษา
 * @return array ผลลัพธ์ (success, message)
 */
function deleteStudent($student_id) {
    global $conn;

    try {
        $conn->begin_transaction();

        // 1. ดึง personal_info_id และ education_info_id
        $stmt_ids = $conn->prepare("SELECT personal_info_id, education_info_id FROM students WHERE student_id = ?");
        if (!$stmt_ids) {
            throw new Exception("Prepare failed for getting IDs for delete: " . $conn->error);
        }
        $stmt_ids->bind_param("s", $student_id);
        $stmt_ids->execute();
        $result_ids = $stmt_ids->get_result();
        $student_info_ids = $result_ids->fetch_assoc();
        $stmt_ids->close();

        if (!$student_info_ids) {
            throw new Exception("Student ID not found for deletion.");
        }

        $personal_info_id = $student_info_ids['personal_info_id'];
        $education_info_id = $student_info_ids['education_info_id'];

        // 2. ลบจาก activity_log
        $sql_activity = "DELETE FROM activity_log WHERE student_id = ?";
        $stmt_activity = $conn->prepare($sql_activity);
        if ($stmt_activity) { $stmt_activity->bind_param("s", $student_id); $stmt_activity->execute(); $stmt_activity->close(); }

        // 3. ลบจาก student_courses
        $sql_courses = "DELETE FROM student_courses WHERE student_id = ?";
        $stmt_courses = $conn->prepare($sql_courses);
        if ($stmt_courses) { $stmt_courses->bind_param("s", $student_id); $stmt_courses->execute(); $stmt_courses->close(); }

        // 4. ลบจาก user_login
        $sql_login = "DELETE FROM user_login WHERE student_id = ?";
        $stmt_login = $conn->prepare($sql_login);
        if (!$stmt_login) { throw new Exception("Prepare failed for user_login delete: " . $conn->error); }
        $stmt_login->bind_param("s", $student_id);
        $stmt_login->execute();
        $stmt_login->close();

        // 5. ลบจาก students
        $sql_students = "DELETE FROM students WHERE student_id = ?";
        $stmt_students = $conn->prepare($sql_students);
        if (!$stmt_students) { throw new Exception("Prepare failed for students delete: " . $conn->error); }
        $stmt_students->bind_param("s", $student_id);
        $stmt_students->execute();
        $stmt_students->close();

        // 6. ลบจาก personal_info
        $sql_personal = "DELETE FROM personal_info WHERE id = ?";
        $stmt_personal = $conn->prepare($sql_personal);
        if (!$stmt_personal) { throw new Exception("Prepare failed for personal_info delete: " . $conn->error); }
        $stmt_personal->bind_param("i", $personal_info_id);
        $stmt_personal->execute();
        $stmt_personal->close();

        // 7. ลบจาก education_info
        $sql_education = "DELETE FROM education_info WHERE id = ?";
        $stmt_education = $conn->prepare($sql_education);
        if (!$stmt_education) { throw new Exception("Prepare failed for education_info delete: " . $conn->error); }
        $stmt_education->bind_param("i", $education_info_id);
        $stmt_education->execute();
        $stmt_education->close();

        $conn->commit();
        logActivity($student_id, 'student_deletion', 'ลบข้อมูลนักศึกษา ' . $student_id . ' สำเร็จ');
        return ['success' => true, 'message' => 'ลบข้อมูลนักศึกษาสำเร็จ'];

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Delete student error: " . $e->getMessage());
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาดในการลบข้อมูลนักศึกษา: ' . $e->getMessage()];
    }
}

/**
 * Function สำหรับรีเซ็ตรหัสผ่านของนักศึกษา (โดย Admin หรือผ่านระบบ Forgot Password)
 * @param string $student_id รหัสนักศึกษา
 * @param string $new_password รหัสผ่านใหม่
 * @return array ผลลัพธ์ (success, message)
 */
function resetUserPassword($student_id, $new_password) {
    global $conn;

    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    $sql = "UPDATE user_login SET password = ?, login_attempts = 0, locked_until = NULL WHERE student_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("resetUserPassword prepare failed: " . $conn->error);
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาดภายในระบบ'];
    }
    
    $stmt->bind_param("ss", $hashed_password, $student_id);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            logActivity($student_id, 'password_reset', 'รีเซ็ตรหัสผ่าน');
            $stmt->close();
            return ['success' => true, 'message' => 'รีเซ็ตรหัสผ่านสำเร็จ'];
        } else {
            $stmt->close();
            return ['success' => false, 'message' => 'ไม่พบรหัสนักศึกษานี้'];
        }
    } else {
        error_log("resetUserPassword execute failed: " . $stmt->error);
        $stmt->close();
        return ['success' => false, 'message' => 'ไม่สามารถรีเซ็ตรหัสผ่านได้'];
    }
}

/**
 * Function สำหรับดึงบทบาทผู้ใช้
 * @param string $student_id รหัสนักศึกษา
 * @return string|false บทบาทผู้ใช้ (เช่น 'student', 'admin') หรือ false หากไม่พบ
 */
function getUserRole($student_id) {
    global $conn;

    $sql = "SELECT user_role FROM user_login WHERE student_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("getUserRole prepare failed: " . $conn->error);
        return false;
    }
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        $stmt->close();
        return $user['user_role'];
    }
    $stmt->close();
    return false;
}

/**
 * Function สำหรับการดึงข้อมูลอาจารย์จาก teacher_id
 * @param string $teacher_id รหัสอาจารย์
 * @return array|false ข้อมูลอาจารย์ในรูปแบบ array หรือ false ถ้าไม่พบ
 */
function getTeacherData($teacher_id) {
    global $conn;

    // สมมติว่ามีตารางชื่อ 'teachers' ในฐานข้อมูล
    // และมีคอลัมน์ 'teacher_id' และ 'full_name'
    $sql = "SELECT * FROM teacher WHERE teacher_id = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Prepare failed for getTeacherData: " . $conn->error);
        return false;
    }

    $stmt->bind_param("s", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $data = $result->fetch_assoc();
        $stmt->close();
        return $data;
    }

    $stmt->close();
    return false;
}

/**
 * Function สำหรับดึงสถิตินักศึกษาทั้งหมด
 * @return int จำนวนนักศึกษาทั้งหมด
 */
function getTotalStudents() {
    global $conn;

    $sql = "SELECT COUNT(*) AS total FROM students";
    $result = $conn->query($sql);
    
    if ($result) {
        return $result->fetch_assoc()['total'] ?? 0;
    }
    
    error_log("getTotalStudents query failed: " . $conn->error);
    return 0;
}

/**
 * Function สำหรับดึงจำนวนนักศึกษาที่ทำแบบทดสอบเสร็จสิ้น
 * @return int จำนวนนักศึกษาที่ทำแบบทดสอบเสร็จสิ้น
 */
function getCompletedTestStudents() {
    global $conn;

    // สมมติว่ามีตารางชื่อ 'student_quiz_results'
    $sql = "SELECT COUNT(DISTINCT student_id) AS completed FROM student_quiz_results WHERE status = 'completed'";
    $result = $conn->query($sql);

    if ($result) {
        return $result->fetch_assoc()['completed'] ?? 0;
    }

    error_log("getCompletedTestStudents query failed: " . $conn->error);
    return 0;
}

/**
 * Function สำหรับดึงผลการทดสอบล่าสุดของนักศึกษา
 * @param int $limit จำนวนรายการที่ต้องการ
 * @return array ข้อมูลผลการทดสอบ
 */
function getLatestQuizResults($limit = 5) {
    global $conn;
    
    $sql = "SELECT s.student_id, p.full_name, scr.score, scr.quiz_date
            FROM student_quiz_results scr
            JOIN students s ON scr.student_id = s.student_id
            JOIN personal_info p ON s.personal_info_id = p.id
            ORDER BY scr.quiz_date DESC
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("getLatestQuizResults prepare failed: " . $conn->error);
        return [];
    }

    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $results = [];
    while ($row = $result->fetch_assoc()) {
        $results[] = $row;
    }
    
    $stmt->close();
    return $results;
}

// -----------------------------------------------------------------------------
// สิ้นสุดโค้ดส่วนที่เพิ่มเข้ามา
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// การเชื่อมต่อฐานข้อมูลสำหรับฝั่งอาจารย์ (Teacher)
// ใช้สำหรับกรณีที่ข้อมูลอาจารย์เก็บคนละฐานกับนักศึกษา
// ค่าเริ่มต้นอ้างอิงจาก config.php ที่คุณส่งมา (DB_NAME = 'project1')
// ปรับแก้ได้ตามจริงของเครื่องคุณ
// -----------------------------------------------------------------------------
if (!defined('TEACHER_DB_HOST'))   define('TEACHER_DB_HOST', 'localhost');
if (!defined('TEACHER_DB_USER'))   define('TEACHER_DB_USER', 'root');
if (!defined('TEACHER_DB_PASS'))   define('TEACHER_DB_PASS', '');
if (!defined('TEACHER_DB_NAME'))   define('TEACHER_DB_NAME', 'studentregistration');

// สร้างการเชื่อมต่อสำหรับฐานข้อมูลของอาจารย์
$teacher_conn = @new mysqli(TEACHER_DB_HOST, TEACHER_DB_USER, TEACHER_DB_PASS, TEACHER_DB_NAME);
if ($teacher_conn && !$teacher_conn->connect_error) {
    $teacher_conn->set_charset('utf8mb4');
} else {
    // ไม่ให้ fatal error เพื่อไม่กระทบส่วนอื่น ๆ ของไซต์
    error_log('Teacher DB connection failed: ' . ($teacher_conn ? $teacher_conn->connect_error : 'unknown error'));
    $teacher_conn = null;
}

/**
 * คืนค่า mysqli connection ของฐานข้อมูลฝั่งอาจารย์
 * @return mysqli|null
 */
function getTeacherConnection() {
    global $teacher_conn;
    return $teacher_conn instanceof mysqli ? $teacher_conn : null;
}

/**
 * ดึงข้อมูลอาจารย์จากฐานข้อมูลของอาจารย์ (TEACHER_DB_NAME)
 * ถ้าคุณมีโครงสร้างตารางต่างจากตัวอย่างนี้ ให้แก้ชื่อ table/field ให้ตรง
 * @param string $teacher_id
 * @return array|false
 */
function getTeacherDataFromTeacherDB($teacher_id) {
    $tc = getTeacherConnection();
    if ($tc === null) {
        return false;
    }

    // ตัวอย่าง: ตารางชื่อ 'teacher' คอลัมน์ 'teacher_id'
    $sql = "SELECT * FROM teacher WHERE teacher_id = ? LIMIT 1";
    $stmt = $tc->prepare($sql);
    if (!$stmt) {
        error_log("getTeacherDataFromTeacherDB prepare failed: " . $tc->error);
        return false;
    }
    $stmt->bind_param("s", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result && $result->num_rows ? $result->fetch_assoc() : false;
    $stmt->close();
    return $data;
}

?>
