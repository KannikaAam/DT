
<?php
session_start();

// Database connection
class Database {
    private $host = 'localhost';
    private $db_name = 'quiz_system';
    private $username = 'root';
    private $password = '';
    private $conn;

    public function connect() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, 
                                $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            echo "Connection Error: " . $e->getMessage();
        }
        return $this->conn;
    }
}

// Quiz Management Class
class QuizManager {
    private $conn;
    private $table_quizzes = 'quizzes';
    private $table_questions = 'questions';
    private $table_quiz_subjects = 'quiz_subjects';

    public function __construct($db) {
        $this->conn = $db;
    }

    // Create tables if not exist
    public function createTables() {
        $sql_quizzes = "CREATE TABLE IF NOT EXISTS quizzes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";

        $sql_questions = "CREATE TABLE IF NOT EXISTS questions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT,
            question_text TEXT NOT NULL,
            question_type ENUM('scale', 'yesno', 'multiple') DEFAULT 'scale',
            options JSON,
            category VARCHAR(100),
            FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
        )";

        $sql_quiz_subjects = "CREATE TABLE IF NOT EXISTS quiz_subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT,
            subject_name VARCHAR(100),
            FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
        )";

        $this->conn->exec($sql_quizzes);
        $this->conn->exec($sql_questions);
        $this->conn->exec($sql_quiz_subjects);
    }

    // Get all quizzes
    public function getAllQuizzes() {
        $sql = "SELECT q.*, 
                COUNT(DISTINCT qs.id) as question_count,
                GROUP_CONCAT(DISTINCT qsub.subject_name) as subjects
                FROM quizzes q
                LEFT JOIN questions qs ON q.id = qs.quiz_id
                LEFT JOIN quiz_subjects qsub ON q.id = qsub.quiz_id
                GROUP BY q.id
                ORDER BY q.created_at DESC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get single quiz with questions
    public function getQuizById($id) {
        $sql = "SELECT * FROM quizzes WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $quiz = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($quiz) {
            // Get questions
            $sql_questions = "SELECT * FROM questions WHERE quiz_id = :quiz_id ORDER BY id";
            $stmt_q = $this->conn->prepare($sql_questions);
            $stmt_q->bindParam(':quiz_id', $id);
            $stmt_q->execute();
            $quiz['questions'] = $stmt_q->fetchAll(PDO::FETCH_ASSOC);

            // Get subjects
            $sql_subjects = "SELECT subject_name FROM quiz_subjects WHERE quiz_id = :quiz_id";
            $stmt_s = $this->conn->prepare($sql_subjects);
            $stmt_s->bindParam(':quiz_id', $id);
            $stmt_s->execute();
            $subjects = $stmt_s->fetchAll(PDO::FETCH_COLUMN);
            $quiz['subjects'] = $subjects;
        }

        return $quiz;
    }

    // Create new quiz
    public function createQuiz($title, $description, $subjects = []) {
        $sql = "INSERT INTO quizzes (title, description) VALUES (:title, :description)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':description', $description);
        
        if ($stmt->execute()) {
            $quiz_id = $this->conn->lastInsertId();
            
            // Add subjects
            foreach ($subjects as $subject) {
                $this->addSubjectToQuiz($quiz_id, $subject);
            }
            
            return $quiz_id;
        }
        return false;
    }

    // Add question to quiz
    public function addQuestion($quiz_id, $question_text, $question_type, $options, $category) {
        $sql = "INSERT INTO questions (quiz_id, question_text, question_type, options, category) 
                VALUES (:quiz_id, :question_text, :question_type, :options, :category)";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':quiz_id', $quiz_id);
        $stmt->bindParam(':question_text', $question_text);
        $stmt->bindParam(':question_type', $question_type);
        $stmt->bindParam(':options', json_encode($options));
        $stmt->bindParam(':category', $category);
        
        return $stmt->execute();
    }

    // Add subject to quiz
    public function addSubjectToQuiz($quiz_id, $subject_name) {
        $sql = "INSERT INTO quiz_subjects (quiz_id, subject_name) VALUES (:quiz_id, :subject_name)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':quiz_id', $quiz_id);
        $stmt->bindParam(':subject_name', $subject_name);
        return $stmt->execute();
    }

    // Update quiz status
    public function toggleQuizStatus($id) {
        $sql = "UPDATE quizzes SET active = NOT active WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    // Delete quiz
    public function deleteQuiz($id) {
        $sql = "DELETE FROM quizzes WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    // Delete question
    public function deleteQuestion($id) {
        $sql = "DELETE FROM questions WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    // Get statistics
    public function getStatistics() {
        $stats = [];
        
        // Total quizzes
        $sql = "SELECT COUNT(*) as total FROM quizzes";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        $stats['total_quizzes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Active quizzes
        $sql = "SELECT COUNT(*) as active FROM quizzes WHERE active = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        $stats['active_quizzes'] = $stmt->fetch(PDO::FETCH_ASSOC)['active'];
        
        // Total questions
        $sql = "SELECT COUNT(*) as total FROM questions";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        $stats['total_questions'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Total subjects
        $sql = "SELECT COUNT(DISTINCT subject_name) as total FROM quiz_subjects";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        $stats['total_subjects'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        return $stats;
    }
}

// Decision Tree Algorithm for Subject Recommendation
class DecisionTree {
    private $subjects = [
        'วิทยาการคอมพิวเตอร์', 'วิศวกรรมซอฟต์แวร์', 'แพทยศาสตร์', 'นิติศาสตร์',
        'บริหารธุรกิจ', 'ศิลปศาสตร์', 'วิทยาศาสตร์', 'เศรษฐศาสตร์',
        'สถาปัตยกรรม', 'ครุศาสตร์', 'จิตวิทยา', 'สังคมศาสตร์'
    ];

    public function recommend($answers) {
        $scores = [];
        foreach ($this->subjects as $subject) {
            $scores[$subject] = 0;
        }

        // Decision Tree Logic
        foreach ($answers as $category => $score) {
            switch ($category) {
                case 'technology_interest':
                    if ($score >= 4) {
                        $scores['วิทยาการคอมพิวเตอร์'] += 3;
                        $scores['วิศวกรรมซอฟต์แวร์'] += 3;
                    }
                    break;
                    
                case 'math_skill':
                    if ($score >= 4) {
                        $scores['วิทยาการคอมพิวเตอร์'] += 2;
                        $scores['วิศวกรรมซอฟต์แวร์'] += 2;
                        $scores['เศรษฐศาสตร์'] += 2;
                    }
                    break;
                    
                case 'science_interest':
                    if ($score >= 4) {
                        $scores['แพทยศาสตร์'] += 3;
                        $scores['วิทยาศาสตร์'] += 3;
                    }
                    break;
                    
                case 'art_creativity':
                    if ($score >= 4) {
                        $scores['ศิลปศาสตร์'] += 3;
                        $scores['สถาปัตยกรรม'] += 2;
                    }
                    break;
                    
                case 'social_interaction':
                    if ($score >= 4) {
                        $scores['จิตวิทยา'] += 2;
                        $scores['สังคมศาสตร์'] += 2;
                        $scores['ครุศาสตร์'] += 2;
                    }
                    break;
                    
                case 'business_interest':
                    if ($score >= 4) {
                        $scores['บริหารธุรกิจ'] += 3;
                        $scores['เศรษฐศาสตร์'] += 2;
                    }
                    break;
                    
                case 'law_interest':
                    if ($score >= 4) {
                        $scores['นิติศาสตร์'] += 3;
                    }
                    break;
            }
        }

        // Sort by score
        arsort($scores);
        return array_slice($scores, 0, 3, true);
    }
}

// Initialize
$database = new Database();
$db = $database->connect();
$quizManager = new QuizManager($db);
$decisionTree = new DecisionTree();

// Create tables
$quizManager->createTables();

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create_quiz':
            $title = $_POST['title'] ?? '';
            $description = $_POST['description'] ?? '';
            $subjects = $_POST['subjects'] ?? [];
            
            if (!empty($title)) {
                $quiz_id = $quizManager->createQuiz($title, $description, $subjects);
                if ($quiz_id) {
                    $_SESSION['success'] = 'สร้างแบบทดสอบเรียบร้อยแล้ว';
                    header("Location: ?page=edit_quiz&id=" . $quiz_id);
                    exit;
                }
            }
            break;
            
        case 'add_question':
            $quiz_id = $_POST['quiz_id'] ?? 0;
            $question_text = $_POST['question_text'] ?? '';
            $question_type = $_POST['question_type'] ?? 'scale';
            $category = $_POST['category'] ?? '';
            
            $options = [];
            if ($question_type === 'scale') {
                $options = ['ไม่เห็นด้วยอย่างยิ่ง', 'ไม่เห็นด้วย', 'เฉยๆ', 'เห็นด้วย', 'เห็นด้วยอย่างยิ่ง'];
            } elseif ($question_type === 'yesno') {
                $options = ['ใช่', 'ไม่ใช่'];
            } elseif ($question_type === 'multiple') {
                $options = explode(',', $_POST['custom_options'] ?? '');
                $options = array_map('trim', $options);
            }
            
            if (!empty($question_text) && !empty($category) && $quiz_id > 0) {
                $quizManager->addQuestion($quiz_id, $question_text, $question_type, $options, $category);
                $_SESSION['success'] = 'เพิ่มคำถามเรียบร้อยแล้ว';
            }
            break;
            
        case 'toggle_status':
            $id = $_POST['id'] ?? 0;
            if ($id > 0) {
                $quizManager->toggleQuizStatus($id);
            }
            break;
            
        case 'delete_quiz':
            $id = $_POST['id'] ?? 0;
            if ($id > 0) {
                $quizManager->deleteQuiz($id);
                $_SESSION['success'] = 'ลบแบบทดสอบเรียบร้อยแล้ว';
            }
            break;
            
        case 'delete_question':
            $id = $_POST['id'] ?? 0;
            if ($id > 0) {
                $quizManager->deleteQuestion($id);
                $_SESSION['success'] = 'ลบคำถามเรียบร้อยแล้ว';
            }
            break;
    }
    
    if (!headers_sent()) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?" . $_SERVER['QUERY_STRING']);
        exit;
    }
}

// Get current page
$page = $_GET['page'] ?? 'dashboard';
$quiz_id = $_GET['id'] ?? 0;

// Get data
$quizzes = $quizManager->getAllQuizzes();
$stats = $quizManager->getStatistics();
$current_quiz = null;

if ($quiz_id > 0) {
    $current_quiz = $quizManager->getQuizById($quiz_id);
}

$subject_categories = [
    'วิทยาการคอมพิวเตอร์', 'วิศวกรรมซอฟต์แวร์', 'แพทยศาสตร์', 'นิติศาสตร์',
    'บริหารธุรกิจ', 'ศิลปศาสตร์', 'วิทยาศาสตร์', 'เศรษฐศาสตร์',
    'สถาปัตยกรรม', 'ครุศาสตร์', 'จิตวิทยา', 'สังคมศาสตร์'
];

$question_types = [
    'scale' => 'แบบมาตราส่วน (1-5)',
    'yesno' => 'ใช่/ไม่ใช่',
    'multiple' => 'เลือกตอบ (หลายตัวเลือก)'
];
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบจัดการแบบทดสอบแนะนำรายวิชา</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">

<!-- Navigation -->
<nav class="bg-white shadow-lg mb-8">
    <div class="max-w-6xl mx-auto px-6 py-4">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">ระบบจัดการแบบทดสอบ</h1>
                <p class="text-gray-600">แนะนำรายวิชาโดยใช้ Decision Tree Algorithm</p>
            </div>
            <div class="flex space-x-4">
                <a href="?page=dashboard" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-home mr-2"></i>หน้าหลัก
                </a>
                <a href="?page=create_quiz" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-plus mr-2"></i>สร้างแบบทดสอบ
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- Alert Messages -->
<?php if (isset($_SESSION['success'])): ?>
<div class="max-w-6xl mx-auto px-6 mb-4">
    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
        <?= htmlspecialchars($_SESSION['success']) ?>
    </div>
</div>
<?php unset($_SESSION['success']); endif; ?>

<div class="max-w-6xl mx-auto px-6">

<?php if ($page === 'dashboard'): ?>
    <!-- Dashboard -->
    <div class="bg-white rounded-xl shadow-lg p-8">
        <!-- Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white p-6 rounded-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-blue-100">แบบทดสอบทั้งหมด</p>
                        <p class="text-3xl font-bold"><?= $stats['total_quizzes'] ?></p>
                    </div>
                    <i class="fas fa-chart-bar text-3xl text-blue-200"></i>
                </div>
            </div>
            <div class="bg-gradient-to-r from-green-500 to-green-600 text-white p-6 rounded-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-green-100">ใช้งานอยู่</p>
                        <p class="text-3xl font-bold"><?= $stats['active_quizzes'] ?></p>
                    </div>
                    <i class="fas fa-eye text-3xl text-green-200"></i>
                </div>
            </div>
            <div class="bg-gradient-to-r from-purple-500 to-purple-600 text-white p-6 rounded-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-purple-100">คำถามรวม</p>
                        <p class="text-3xl font-bold"><?= $stats['total_questions'] ?></p>
                    </div>
                    <i class="fas fa-question text-3xl text-purple-200"></i>
                </div>
            </div>
            <div class="bg-gradient-to-r from-orange-500 to-orange-600 text-white p-6 rounded-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-orange-100">รายวิชา</p>
                        <p class="text-3xl font-bold"><?= count($subject_categories) ?></p>
                    </div>
                    <i class="fas fa-book text-3xl text-orange-200"></i>
                </div>
            </div>
        </div>

        <!-- Quiz List -->
        <div class="space-y-4">
            <h2 class="text-2xl font-semibold text-gray-800">รายการแบบทดสอบ</h2>
            <?php if (empty($quizzes)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-chart-bar text-6xl text-gray-400 mb-4"></i>
                    <p class="text-gray-600 text-lg">ยังไม่มีแบบทดสอบ</p>
                    <p class="text-gray-500">เริ่มต้นสร้างแบบทดสอบแรกของคุณ</p>
                </div>
            <?php else: ?>
                <div class="grid gap-6">
                    <?php foreach ($quizzes as $quiz): ?>
                        <div class="bg-white border border-gray-200 rounded-lg p-6 hover:shadow-md transition-shadow">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3 mb-2">
                                        <h3 class="text-xl font-semibold text-gray-800"><?= htmlspecialchars($quiz['title']) ?></h3>
                                        <span class="px-3 py-1 rounded-full text-sm font-medium <?= $quiz['active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                            <?= $quiz['active'] ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?>
                                        </span>
                                    </div>
                                    <p class="text-gray-600 mb-3"><?= htmlspecialchars($quiz['description']) ?></p>
                                    <?php if (!empty($quiz['subjects'])): ?>
                                        <div class="flex flex-wrap gap-2 mb-3">
                                            <?php 
                                            $subjects = explode(',', $quiz['subjects']);
                                            foreach (array_slice($subjects, 0, 3) as $subject): 
                                            ?>
                                                <span class="bg-blue-100 text-blue-800 text-sm font-medium px-3 py-1 rounded-full">
                                                    <?= htmlspecialchars(trim($subject)) ?>
                                                </span>
                                            <?php endforeach; ?>
                                            <?php if (count($subjects) > 3): ?>
                                                <span class="bg-gray-100 text-gray-600 text-sm font-medium px-3 py-1 rounded-full">
                                                    +<?= count($subjects) - 3 ?> อื่นๆ
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex items-center space-x-4 text-sm text-gray-500">
                                        <span><?= $quiz['question_count'] ?> คำถาม</span>
                                        <span>สร้างเมื่อ <?= date('d/m/Y', strtotime($quiz['created_at'])) ?></span>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2 ml-4">
                                    <a href="?page=view_quiz&id=<?= $quiz['id'] ?>" class="p-2 text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-lg transition-colors" title="ดูแบบทดสอบ">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="?page=edit_quiz&id=<?= $quiz['id'] ?>" class="p-2 text-green-600 hover:text-green-800 hover:bg-green-50 rounded-lg transition-colors" title="แก้ไข">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= $quiz['id'] ?>">
                                        <button type="submit" class="px-3 py-1 rounded text-sm font-medium transition-colors <?= $quiz['active'] ? 'bg-red-100 text-red-700 hover:bg-red-200' : 'bg-green-100 text-green-700 hover:bg-green-200' ?>">
                                            <?= $quiz['active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?>
                                        </button>
                                    </form>
                                    <form method="POST" class="inline" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการลบแบบทดสอบนี้?')">
                                        <input type="hidden" name="action" value="delete_quiz">
                                        <input type="hidden" name="id" value="<?= $quiz['id'] ?>">
                                        <button type="submit" class="p-2 text-red-600 hover:text-red-800 hover:bg-red-50 rounded-lg transition-colors" title="ลบ">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($page === 'create_quiz'): ?>
    <!-- Create Quiz -->
    <div class="bg-white rounded-xl shadow-lg p-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-8">สร้างแบบทดสอบใหม่</h1>
        
        <form method="POST" class="space-y-6">
            <input type="hidden" name="action" value="create_quiz">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อแบบทดสอบ *</label>
                <input type="text" name="title" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="เช่น แบบทดสอบความสนใจด้านเทคโนโลยี">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">คำอธิบาย</label>
                <textarea name="description" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="อธิบายจุดประสงค์ของแบบทดสอบ"></textarea>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">รายวิชาที่เกี่ยวข้อง</label>
                <div class="grid grid-cols-3 gap-2">
                    <?php foreach ($subject_categories as $subject): ?>
                        <label class="flex items-center space-x-2">
                            <input type="checkbox" name="subjects[]" value="<?= htmlspecialchars($subject) ?>" class="rounded text-blue-600">
                            <span class="text-sm text-gray-700"><?= htmlspecialchars($subject) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="flex justify-end">
                <button type="submit" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <i class="fas fa-save mr-2"></i>สร้างแบบทดสอบ
                </button>
            </div>
        </form>
    </div>
    "เพิ่มคำถาม</h2>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="add_question">
                <input type="hidden" name="quiz_id" value="<?= $current_quiz['id'] ?>">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">คำถาม *</label>
                    <textarea name="question_text" required rows="2" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="ใส่คำถามที่ต้องการ"></textarea>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ประเภทคำถาม</label>
                        <select name="question_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" onchange="toggleCustomOptions(this)">
                            <?php foreach ($question_types as $type => $label): ?>
                                <option value="<?= $type ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">หมวดหมู่ *</label>
                        <select name="category" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="">เลือกหมวดหมู่</option>
                            <option value="technology_interest">ความสนใจด้านเทคโนโลยี</option>
                            <option value="math_skill">ทักษะด้านคณิตศาสตร์</option>
                            <option value="science_interest">ความสนใจด้านวิทยาศาสตร์</option>
                            <option value="art_creativity">ความคิดสร้างสรรค์ด้านศิลปะ</option>
                            <option value="social_interaction">การมีปฏิสัมพันธ์ทางสังคม</option>
                            <option value="business_interest">ความสนใจด้านธุรกิจ</option>
                            <option value="law_interest">ความสนใจด้านกฎหมาย</option>
                        </select>
                    </div>
                </div>
                
                <div id="custom_options_div" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-2">ตัวเลือกคำตอบ (คั่นด้วยเครื่องหมายจุลภาค)</label>
                    <input type="text" name="custom_options" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="เช่น ตัวเลือก 1, ตัวเลือก 2, ตัวเลือก 3">
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                        <i class="fas fa-plus mr-2"></i>เพิ่มคำถาม
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Questions List -->
        <div class="space-y-4">
            <h2 class="text-xl font-semibold text-gray-800">คำถามในแบบทดสอบ (<?= count($current_quiz['questions']) ?> คำถาม)</h2>
            
            <?php if (empty($current_quiz['questions'])): ?>
                <div class="text-center py-8">
                    <i class="fas fa-question text-4xl text-gray-400 mb-4"></i>
                    <p class="text-gray-600">ยังไม่มีคำถามในแบบทดสอบนี้</p>
                </div>
            <?php else: ?>
                <?php foreach ($current_quiz['questions'] as $index => $question): ?>
                    <div class="bg-gray-50 rounded-lg p-6">
                        <div class="flex justify-between items-start">
                            <div class="flex-1">
                                <div class="flex items-center space-x-3 mb-2">
                                    <span class="bg-blue-100 text-blue-800 text-sm font-medium px-3 py-1 rounded-full">
                                        คำถามที่ <?= $index + 1 ?>
                                    </span>
                                    <span class="bg-purple-100 text-purple-800 text-sm font-medium px-3 py-1 rounded-full">
                                        <?= htmlspecialchars($question['category']) ?>
                                    </span>
                                    <span class="bg-green-100 text-green-800 text-sm font-medium px-3 py-1 rounded-full">
                                        <?= htmlspecialchars($question_types[$question['question_type']] ?? $question['question_type']) ?>
                                    </span>
                                </div>
                                <h3 class="text-lg font-medium text-gray-800 mb-3"><?= htmlspecialchars($question['question_text']) ?></h3>
                                
                                <?php if (!empty($question['options'])): ?>
                                    <div class="mb-3">
                                        <p class="text-sm font-medium text-gray-600 mb-2">ตัวเลือกคำตอบ:</p>
                                        <div class="flex flex-wrap gap-2">
                                            <?php 
                                            $options = json_decode($question['options'], true);
                                            foreach ($options as $option): 
                                            ?>
                                                <span class="bg-white border border-gray-300 text-gray-700 text-sm px-3 py-1 rounded-full">
                                                    <?= htmlspecialchars($option) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="ml-4">
                                <form method="POST" class="inline" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการลบคำถามนี้?')">
                                    <input type="hidden" name="action" value="delete_question">
                                    <input type="hidden" name="id" value="<?= $question['id'] ?>">
                                    <button type="submit" class="p-2 text-red-600 hover:text-red-800 hover:bg-red-50 rounded-lg transition-colors" title="ลบคำถาม">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($page === 'view_quiz' && $current_quiz): ?>
    <!-- View Quiz -->
    <div class="bg-white rounded-xl shadow-lg p-8">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-4"><?= htmlspecialchars($current_quiz['title']) ?></h1>
            <p class="text-gray-600 text-lg"><?= htmlspecialchars($current_quiz['description']) ?></p>
            <?php if (!empty($current_quiz['subjects'])): ?>
                <div class="flex flex-wrap justify-center gap-2 mt-4">
                    <?php foreach ($current_quiz['subjects'] as $subject): ?>
                        <span class="bg-blue-100 text-blue-800 text-sm font-medium px-3 py-1 rounded-full">
                            <?= htmlspecialchars($subject) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($current_quiz['questions'])): ?>
            <form id="quizForm" class="space-y-8">
                <?php foreach ($current_quiz['questions'] as $index => $question): ?>
                    <div class="bg-gray-50 rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">
                            <?= $index + 1 ?>. <?= htmlspecialchars($question['question_text']) ?>
                        </h3>
                        
                        <?php 
                        $options = json_decode($question['options'], true);
                        $questionName = "question_" . $question['id'];
                        ?>
                        
                        <?php if ($question['question_type'] === 'scale'): ?>
                            <div class="space-y-2">
                                <?php foreach ($options as $value => $label): ?>
                                    <label class="flex items-center space-x-3 p-3 rounded-lg hover:bg-white transition-colors cursor-pointer">
                                        <input type="radio" name="<?= $questionName ?>" value="<?= $value + 1 ?>" data-category="<?= htmlspecialchars($question['category']) ?>" class="text-blue-600">
                                        <span class="text-gray-700"><?= htmlspecialchars($label) ?></span>
                                        <span class="text-sm text-gray-500 ml-auto">(<?= $value + 1 ?>)</span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($question['question_type'] === 'yesno'): ?>
                            <div class="space-y-2">
                                <label class="flex items-center space-x-3 p-3 rounded-lg hover:bg-white transition-colors cursor-pointer">
                                    <input type="radio" name="<?= $questionName ?>" value="5" data-category="<?= htmlspecialchars($question['category']) ?>" class="text-blue-600">
                                    <span class="text-gray-700">ใช่</span>
                                </label>
                                <label class="flex items-center space-x-3 p-3 rounded-lg hover:bg-white transition-colors cursor-pointer">
                                    <input type="radio" name="<?= $questionName ?>" value="1" data-category="<?= htmlspecialchars($question['category']) ?>" class="text-blue-600">
                                    <span class="text-gray-700">ไม่ใช่</span>
                                </label>
                            </div>
                        <?php else: ?>
                            <div class="space-y-2">
                                <?php foreach ($options as $index => $option): ?>
                                    <label class="flex items-center space-x-3 p-3 rounded-lg hover:bg-white transition-colors cursor-pointer">
                                        <input type="radio" name="<?= $questionName ?>" value="<?= $index + 1 ?>" data-category="<?= htmlspecialchars($question['category']) ?>" class="text-blue-600">
                                        <span class="text-gray-700"><?= htmlspecialchars($option) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <div class="text-center">
                    <button type="button" onclick="calculateResults()" class="px-8 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-lg font-semibold">
                        <i class="fas fa-calculator mr-2"></i>คำนวณผลลัพธ์
                    </button>
                </div>
            </form>
            
            <!-- Results Section -->
            <div id="results" class="hidden mt-8 p-6 bg-green-50 rounded-lg">
                <h2 class="text-2xl font-bold text-center text-gray-800 mb-6">ผลการแนะนำรายวิชา</h2>
                <div id="recommendedSubjects"></div>
            </div>
        <?php else: ?>
            <div class="text-center py-12">
                <i class="fas fa-question text-6xl text-gray-400 mb-4"></i>
                <p class="text-gray-600 text-lg">แบบทดสอบนี้ยังไม่มีคำถาม</p>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="bg-white rounded-xl shadow-lg p-8 text-center">
        <i class="fas fa-exclamation-circle text-6xl text-red-400 mb-4"></i>
        <h1 class="text-2xl font-bold text-gray-800 mb-4">ไม่พบหน้าที่ต้องการ</h1>
        <p class="text-gray-600 mb-6">หน้าที่คุณต้องการหาไม่มีอยู่ในระบบ</p>
        <a href="?page=dashboard" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
            <i class="fas fa-home mr-2"></i>กลับหน้าหลัก
        </a>
    </div>
<?php endif; ?>

</div>

<script>
function toggleCustomOptions(select) {
    const customDiv = document.getElementById('custom_options_div');
    if (select.value === 'multiple') {
        customDiv.style.display = 'block';
    } else {
        customDiv.style.display = 'none';
    }
}

function calculateResults() {
    const form = document.getElementById('quizForm');
    const formData = new FormData(form);
    const answers = {};
    
    // Collect answers by category
    const inputs = form.querySelectorAll('input[type="radio"]:checked');
    inputs.forEach(input => {
        const category = input.dataset.category;
        const value = parseInt(input.value);
        
        if (!answers[category]) {
            answers[category] = [];
        }
        answers[category].push(value);
    });
    
    // Calculate average score for each category
    const categoryScores = {};
    for (const category in answers) {
        const scores = answers[category];
        const average = scores.reduce((a, b) => a + b, 0) / scores.length;
        categoryScores[category] = average;
    }
    
    // Subject recommendations based on scores
    const subjects = {
        'วิทยาการคอมพิวเตอร์': 0,
        'วิศวกรรมซอฟต์แวร์': 0,
        'แพทยศาสตร์': 0,
        'นิติศาสตร์': 0,
        'บริหารธุรกิจ': 0,
        'ศิลปศาสตร์': 0,
        'วิทยาศาสตร์': 0,
        'เศรษฐศาสตร์': 0,
        'สถาปัตยกรรม': 0,
        'ครุศาสตร์': 0,
        'จิตวิทยา': 0,
        'สังคมศาสตร์': 0
    };
    
    // Apply decision tree logic
    for (const category in categoryScores) {
        const score = categoryScores[category];
        
        switch (category) {
            case 'technology_interest':
                if (score >= 4) {
                    subjects['วิทยาการคอมพิวเตอร์'] += 3;
                    subjects['วิศวกรรมซอฟต์แวร์'] += 3;
                }
                break;
                
            case 'math_skill':
                if (score >= 4) {
                    subjects['วิทยาการคอมพิวเตอร์'] += 2;
                    subjects['วิศวกรรมซอฟต์แวร์'] += 2;
                    subjects['เศรษฐศาสตร์'] += 2;
                }
                break;
                
            case 'science_interest':
                if (score >= 4) {
                    subjects['แพทยศาสตร์'] += 3;
                    subjects['วิทยาศาสตร์'] += 3;
                }
                break;
                
            case 'art_creativity':
                if (score >= 4) {
                    subjects['ศิลปศาสตร์'] += 3;
                    subjects['สถาปัตยกรรม'] += 2;
                }
                break;
                
            case 'social_interaction':
                if (score >= 4) {
                    subjects['จิตวิทยา'] += 2;
                    subjects['สังคมศาสตร์'] += 2;
                    subjects['ครุศาสตร์'] += 2;
                }
                break;
                
            case 'business_interest':
                if (score >= 4) {
                    subjects['บริหารธุรกิจ'] += 3;
                    subjects['เศรษฐศาสตร์'] += 2;
                }
                break;
                
            case 'law_interest':
                if (score >= 4) {
                    subjects['นิติศาสตร์'] += 3;
                }
                break;
        }
    }
    
    // Sort subjects by score
    const sortedSubjects = Object.entries(subjects)
        .sort(([,a], [,b]) => b - a)
        .slice(0, 3);
    
    // Display results
    displayResults(sortedSubjects, categoryScores);
}

function displayResults(recommendedSubjects, categoryScores) {
    const resultsDiv = document.getElementById('results');
    const subjectsDiv = document.getElementById('recommendedSubjects');
    
    let html = '<div class="space-y-6">';
    
    // Top 3 recommended subjects
    html += '<div><h3 class="text-xl font-semibold text-gray-800 mb-4">รายวิชาที่แนะนำ (อันดับ 1-3)</h3>';
    html += '<div class="space-y-3">';
    
    recommendedSubjects.forEach((subject, index) => {
        const [name, score] = subject;
        const percentage = Math.max(0, Math.round((score / 6) * 100)); // Normalize to percentage
        html += `
            <div class="flex items-center justify-between p-4 bg-white rounded-lg border-l-4 border-blue-500">
                <div>
                    <span class="text-lg font-medium text-gray-800">${index + 1}. ${name}</span>
                    <div class="text-sm text-gray-600">คะแนนความเหมาะสม: ${score.toFixed(1)}/6</div>
                </div>
                <div class="text-right">
                    <div class="text-2xl font-bold text-blue-600">${percentage}%</div>
                    <div class="w-24 bg-gray-200 rounded-full h-2">
                        <div class="bg-blue-600 h-2 rounded-full" style="width: ${percentage}%"></div>
                    </div>
                </div>
            </div>
        `;
    });
    
    html += '</div></div>';
    
    // Category breakdown
    html += '<div><h3 class="text-xl font-semibold text-gray-800 mb-4">คะแนนตามหมวดหมู่</h3>';
    html += '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">';
    
    const categoryNames = {
        'technology_interest': 'ความสนใจด้านเทคโนโลยี',
        'math_skill': 'ทักษะด้านคณิตศาสตร์',
        'science_interest': 'ความสนใจด้านวิทยาศาสตร์',
        'art_creativity': 'ความคิดสร้างสรรค์ด้านศิลปะ',
        'social_interaction': 'การมีปฏิสัมพันธ์ทางสังคม',
        'business_interest': 'ความสนใจด้านธุรกิจ',
        'law_interest': 'ความสนใจด้านกฎหมาย'
    };
    
    for (const category in categoryScores) {
        const score = categoryScores[category];
        const percentage = Math.round((score / 5) * 100);
        const categoryName = categoryNames[category] || category;
        
        html += `
            <div class="bg-white p-4 rounded-lg">
                <div class="flex justify-between items-center mb-2">
                    <span class="font-medium text-gray-800">${categoryName}</span>
                    <span class="text-lg font-bold text-gray-600">${score.toFixed(1)}/5</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-3">
                    <div class="bg-gradient-to-r from-blue-500 to-blue-600 h-3 rounded-full" style="width: ${percentage}%"></div>
                </div>
            </div>
        `;
    }
    
    html += '</div></div>';
    html += '</div>';
    
    subjectsDiv.innerHTML = html;
    resultsDiv.classList.remove('hidden');
    
    // Scroll to results
    resultsDiv.scrollIntoView({ behavior: 'smooth' });
}

// Auto-save form progress
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('quizForm');
    if (form) {
        const inputs = form.querySelectorAll('input[type="radio"]');
        inputs.forEach(input => {
            input.addEventListener('change', function() {
                // Optional: Save progress to sessionStorage
                // This can be implemented if needed
            });
        });
    }
});
</script>

</body>
</html>