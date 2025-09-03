<?php
/**
 * audit_unused_tables.php
 * ------------------------------------------------------------
 * เครื่องมือสแกนฐานข้อมูล + โค้ดโปรเจ็ค เพื่อหาว่า "ตารางไหนน่าจะไม่ได้ใช้แล้ว"
 * - ตรวจ FKs จาก information_schema (ตารางไหนไม่มีใครอ้างถึง / ไม่อ้างถึงใครเลย)
 * - สแกนไฟล์โค้ด (.php/.js/.sql/ฯลฯ) หา FROM/JOIN/INSERT/UPDATE/DELETE/REFERENCES ที่อาจเรียกตาราง
 * - สรุปผลเป็น HTML (ถ้าเรียกผ่านเว็บ) หรือ TSV (ถ้าเรียกผ่าน CLI)
 *
 * ✅ ไม่แก้ไข DB / ไม่ลบอะไร เป็นเพียงเครื่องมือวิเคราะห์อย่างเดียว
 * ------------------------------------------------------------
 * วิธีใช้ (โหมดเว็บ):
 * 1) วางไฟล์นี้ไว้ที่ root ของโปรเจ็ค หรือที่ใดก็ได้ที่ PHP รันได้
 * 2) เปิดผ่านเบราว์เซอร์: http://localhost/audit_unused_tables.php
 *
 * วิธีใช้ (โหมด CLI):
 *    php audit_unused_tables.php
 *
 * หมายเหตุ: ตารางที่ชื่อทั่วไปเช่น `groups`, `user`, `order` อาจมี false positive ได้
 * ตรวจทานก่อนตัดสินใจลบทุกครั้ง
 */

// ==================== CONFIG ====================
$CONFIG = [
    // ----- DB -----
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'dbname'   => 'studentregistration',
        'user'     => 'root',
        'pass'     => '',
        'charset'  => 'utf8mb4',
    ],

    // ----- CODE SCAN -----
    // โฟลเดอร์ที่ต้องการสแกนโค้ด (แนะนำให้ตั้งเป็นโฟลเดอร์โปรเจ็คของคุณ)
    'scan_root' => __DIR__,

    // นามสกุลไฟล์ที่จะสแกนหา SQL/การเรียกใช้ตาราง
    'ext_whitelist' => ['php','phtml','inc','js','ts','sql','html','md','txt'],

    // โฟลเดอร์ที่ไม่อยากสแกน (ลดเวลาและลดสัญญาณรบกวน)
    'ignore_dirs' => ['vendor','node_modules','.git','.idea','.vscode','storage','cache','dist','build','public/uploads'],

    // ขนาดไฟล์สูงสุดที่จะอ่าน (bytes) ป้องกันไฟล์ใหญ่มากๆ
    'max_file_bytes' => 2 * 1024 * 1024, // 2MB

    // จำนวนไฟล์ตัวอย่างต่อ 1 ตารางที่จะแสดงในรายงาน
    'max_examples' => 5,

    // รายชื่อตารางที่อยากเมินเฉย (เช่น ตารางชั่วคราว/ระบบ)
    'table_ignore_list' => ['migrations','phinxlog','_tmp','sessions'],
];

// ==================== MAIN ====================
$pdo = db($CONFIG['db']);
$schema = $CONFIG['db']['dbname'];
$tables = fetchTables($pdo, $schema);

$fk = fetchFKMaps($pdo, $schema); // ['references' => [...], 'referenced_by' => [...]]

$scanResult = scanCodebase(
    $CONFIG['scan_root'],
    array_keys($tables),
    $CONFIG['ext_whitelist'],
    $CONFIG['ignore_dirs'],
    $CONFIG['max_file_bytes'],
    $CONFIG['max_examples']
);

$report = buildReport($tables, $fk, $scanResult, $CONFIG['table_ignore_list']);

if (PHP_SAPI === 'cli') {
    outputCLI($report);
} else {
    outputHTML($report, $CONFIG, $schema);
}

// ==================== FUNCTIONS ====================
function db(array $cfg): PDO {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'], $cfg['port'], $cfg['dbname'], $cfg['charset']);
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $pdo;
}

function fetchTables(PDO $pdo, string $schema): array {
    $sql = "SELECT TABLE_NAME, TABLE_ROWS, CREATE_TIME, UPDATE_TIME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :s AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME";
    $st = $pdo->prepare($sql);
    $st->execute([':s' => $schema]);
    $out = [];
    foreach ($st as $r) {
        $out[$r['TABLE_NAME']] = [
            'rows' => (int)($r['TABLE_ROWS'] ?? 0),
            'create_time' => $r['CREATE_TIME'] ?? null,
            'update_time' => $r['UPDATE_TIME'] ?? null,
        ];
    }
    return $out;
}

function fetchFKMaps(PDO $pdo, string $schema): array {
    // ตารางนี้ดู mapping ของ FK ได้จาก information_schema.key_column_usage
    $sql = "SELECT TABLE_NAME, REFERENCED_TABLE_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = :s
              AND REFERENCED_TABLE_SCHEMA = :s
              AND REFERENCED_TABLE_NAME IS NOT NULL";
    $st = $pdo->prepare($sql);
    $st->execute([':s' => $schema]);

    $references = [];   // table -> [ referenced_table1, ... ]
    $referencedBy = []; // table -> [ child_table1, ... ]

    foreach ($st as $r) {
        $child = $r['TABLE_NAME'];
        $parent = $r['REFERENCED_TABLE_NAME'];
        if ($child && $parent) {
            $references[$child][$parent] = true;
            $referencedBy[$parent][$child] = true;
        }
    }

    // แปลงให้เป็น array ที่มีค่าจำนวนด้วย
    $refCount = [];
    foreach ($references as $t => $set) { $refCount[$t] = count($set); }
    $refByCount = [];
    foreach ($referencedBy as $t => $set) { $refByCount[$t] = count($set); }

    return [
        'references'   => $references,
        'ref_count'    => $refCount,
        'referenced_by'=> $referencedBy,
        'ref_by_count' => $refByCount,
    ];
}

function scanCodebase(string $root, array $tables, array $exts, array $ignoreDirs, int $maxBytes, int $maxExamples): array {
    $tables = array_values($tables);
    sort($tables);

    $result = [];
    foreach ($tables as $t) {
        $result[$t] = [
            'hits' => 0,
            'files' => [],
        ];
    }

    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $file */
    foreach ($rii as $file) {
        if (!$file->isFile()) continue;
        $path = $file->getPathname();

        // ignore dirs
        $parts = explode(DIRECTORY_SEPARATOR, str_replace($root, '', $path));
        foreach ($parts as $seg) {
            $seg = trim($seg, DIRECTORY_SEPARATOR);
            if ($seg === '') continue;
            foreach ($ignoreDirs as $ig) {
                if ($seg === $ig) continue 2; // ข้ามไฟล์นี้เลย
            }
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) continue;
        if ($file->getSize() > $maxBytes) continue;

        $content = @file_get_contents($path);
        if ($content === false) continue;

        // ทำให้ค้นหาง่ายขึ้น (ไม่เปลี่ยนต้นฉบับ)
        $hay = $content; // รวมทั้งอักษรเล็กใหญ่

        foreach ($tables as $t) {
            // หาแพทเทิร์นที่สื่อว่ามีการอ้างอิงตารางชัดเจน
            $patterns = buildSQLPatternsForTable($t);
            $hit = 0;
            foreach ($patterns as $p) {
                if (preg_match_all($p, $hay, $m)) {
                    $hit += count($m[0]);
                }
            }
            // ถ้าไม่พบรูปแบบ SQL เลย ลองค้นด้วยขอบเขตคำ (ลดหลั่นลงมา)
            if ($hit === 0) {
                $p2 = '/(?<![A-Za-z0-9_`])`?' . preg_quote($t, '/') . '`?(?![A-Za-z0-9_])/i';
                if (preg_match_all($p2, $hay, $m2)) {
                    $hit += min(1, count($m2[0])); // นับเป็น 1 เพื่อไม่ให้ noise มาก
                }
            }

            if ($hit > 0) {
                $result[$t]['hits'] += $hit;
                if (count($result[$t]['files']) < $maxExamples) {
                    $result[$t]['files'][] = $path;
                }
            }
        }
    }

    return $result;
}

function buildSQLPatternsForTable(string $t): array {
    $tq = preg_quote($t, '/');
    // เน้นคอนเท็กซ์ SQL ชัดเจน
    return [
        '/\\bFROM\\s+`?' . $tq . '`?\\b/i',
        '/\\bJOIN\\s+`?' . $tq . '`?\\b/i',
        '/\\bINSERT\\s+INTO\\s+`?' . $tq . '`?\\b/i',
        '/\\bUPDATE\\s+`?' . $tq . '`?\\b/i',
        '/\\bDELETE\\s+FROM\\s+`?' . $tq . '`?\\b/i',
        '/\\bREFERENCES\\s+`?' . $tq . '`?\\b/i',
        '/\\bALTER\\s+TABLE\\s+`?' . $tq . '`?\\b/i',
        '/\\bCREATE\\s+TABLE\\s+`?' . $tq . '`?\\b/i',
        '/\\bDROP\\s+TABLE\\s+IF\\s+EXISTS\\s+`?' . $tq . '`?\\b/i',
        '/\\bDESCRIBE\\s+`?' . $tq . '`?\\b/i',
    ];
}

function buildReport(array $tables, array $fk, array $scan, array $ignoreList): array {
    $rows = [];
    $ignoreSet = array_flip($ignoreList);

    foreach ($tables as $t => $meta) {
        $refers = $fk['ref_count'][$t] ?? 0;        // ตารางนี้อ้างถึงคนอื่นกี่ตัว
        $refBy  = $fk['ref_by_count'][$t] ?? 0;     // ตารางนี้ถูกอ้างถึงโดยกี่ตาราง
        $hits   = $scan[$t]['hits'] ?? 0;           // จำนวนครั้งที่เจอในโค้ด
        $files  = $scan[$t]['files'] ?? [];

        // heuristic จัดกลุ่ม
        $category = 'ใช้งานอยู่';
        $confidence = 0.5;

        if ($hits === 0 && $refBy === 0 && $refers === 0) {
            $category = 'น่าสงสัย: อาจไม่ใช้แล้ว';
            $confidence = 0.9;
        } elseif ($hits === 0 && $refBy === 0 && $refers > 0) {
            $category = 'เสี่ยงเลิกใช้ (ไม่มีใครอ้างถึง + ไม่มีร่องรอยในโค้ด)';
            $confidence = 0.7;
        } elseif ($hits === 0 && $refBy > 0) {
            $category = 'โครงสร้างผูกอยู่ แต่ไม่พบในโค้ด (ตรวจซ้ำ)';
            $confidence = 0.6;
        } else {
            $category = 'อาจใช้งาน (พบในโค้ด/มี FK)';
            $confidence = 0.6 + min(0.3, ($hits > 0 ? 0.2 : 0) + ($refBy > 0 ? 0.1 : 0) + ($refers > 0 ? 0.1 : 0));
        }

        $ignored = isset($ignoreSet[$t]);
        if ($ignored) {
            $category = 'ข้ามตามรายการเมิน (ignore)';
            $confidence = 1.0;
        }

        $rows[] = [
            'table' => $t,
            'rows'  => $meta['rows'],
            'create_time' => $meta['create_time'],
            'update_time' => $meta['update_time'],
            'references' => $refers,
            'referenced_by' => $refBy,
            'code_hits' => $hits,
            'example_files' => $files,
            'category' => $category,
            'confidence' => $confidence,
            'ignored' => $ignored,
        ];
    }

    // เรียงผล: น่าสงสัยมากไปน้อย, แล้วตามชื่อ
    usort($rows, function($a,$b){
        $rankA = categoryRank($a['category']);
        $rankB = categoryRank($b['category']);
        if ($rankA === $rankB) return strcmp($a['table'], $b['table']);
        return $rankA <=> $rankB; // น้อยก่อน
    });

    return $rows;
}

function categoryRank(string $cat): int {
    $order = [
        'น่าสงสัย: อาจไม่ใช้แล้ว' => 0,
        'เสี่ยงเลิกใช้ (ไม่มีใครอ้างถึง + ไม่มีร่องรอยในโค้ด)' => 1,
        'โครงสร้างผูกอยู่ แต่ไม่พบในโค้ด (ตรวจซ้ำ)' => 2,
        'อาจใช้งาน (พบในโค้ด/มี FK)' => 3,
        'ใช้งานอยู่' => 4,
        'ข้ามตามรายการเมิน (ignore)' => 5,
    ];
    return $order[$cat] ?? 9;
}

function outputCLI(array $rows): void {
    // หัวตาราง TSV
    $headers = ['table','rows','create_time','update_time','references','referenced_by','code_hits','category','confidence'];
    echo implode("\t", $headers) . "\n";
    foreach ($rows as $r) {
        echo implode("\t", [
            $r['table'],
            (string)$r['rows'],
            (string)$r['create_time'],
            (string)$r['update_time'],
            (string)$r['references'],
            (string)$r['referenced_by'],
            (string)$r['code_hits'],
            $r['category'],
            number_format($r['confidence'],2),
        ]) . "\n";
    }
}

function outputHTML(array $rows, array $cfg, string $schema): void {
    header('Content-Type: text/html; charset=utf-8');
    $now = date('Y-m-d H:i:s');
    ?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DB Audit — ตารางที่อาจไม่ได้ใช้</title>
<style>
  :root{
    --bg:#0b1220; --card:#0f172a; --muted:#1f2937; --text:#e5e7eb; --sub:#9ca3af;
    --ok:#10b981; --warn:#f59e0b; --bad:#ef4444; --accent:#6366f1; --chip:#111827;
  }
  *{box-sizing:border-box}
  body{margin:0;background:radial-gradient(1200px 600px at 20% -10%,#1e293b22,transparent), linear-gradient(180deg,#0b1220,#0b1324);color:var(--text);font:14px/1.7 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Sarabun",sans-serif}
  header{padding:24px 20px 8px;border-bottom:1px solid #ffffff14;position:sticky;top:0;background:linear-gradient(180deg,#0b1220ee,#0b1220cc)}
  h1{margin:0;font-size:20px}
  .sub{color:var(--sub);font-size:12px}
  .wrap{padding:20px;max-width:1200px;margin:0 auto}
  table{width:100%;border-collapse:separate;border-spacing:0 10px}
  thead th{font-size:12px;color:#cbd5e1;text-align:left;padding:8px 10px}
  tbody tr{background:var(--card);box-shadow:0 2px 0 #00000033}
  td{padding:12px 10px;vertical-align:top;border-top:1px solid #ffffff12;border-bottom:1px solid #00000055}
  .tbl{overflow:auto}
  .chip{display:inline-block;padding:2px 8px;border-radius:999px;background:var(--chip);color:#d1d5db;border:1px solid #ffffff12}
  .cat{font-weight:600}
  .cat.bad{color:var(--bad)}
  .cat.warn{color:var(--warn)}
  .cat.ok{color:var(--ok)}
  .mono{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size:12px}
  .files{opacity:.9}
  .hint{margin-top:12px;color:#cbd5e1}
  .badge{padding:2px 6px;border:1px solid #ffffff26;border-radius:6px;margin-right:6px}
  .footer{color:#94a3b8;font-size:12px;margin-top:12px}
</style>
</head>
<body>
<header>
  <h1>DB Audit — ตารางที่อาจไม่ได้ใช้ (สคีมา: <span class="mono"><?php echo h($schema) ?></span>)</h1>
  <div class="sub">สแกนเมื่อ <?php echo h($now) ?> · โฟลเดอร์ที่สแกน: <span class="mono"><?php echo h($cfg['scan_root']) ?></span></div>
</header>
<div class="wrap">
  <div class="hint">
    <span class="badge">เกณฑ์</span> ถ้า <b>Code Hits = 0</b> และ <b>Referenced By = 0</b> มีแนวโน้มว่าไม่ได้ใช้แล้ว แต่ควรตรวจทานอีกครั้งก่อนลบจริง
  </div>
  <div class="tbl">
  <table>
    <thead>
      <tr>
        <th>Table</th>
        <th>Rows</th>
        <th>FK: Refers</th>
        <th>FK: Referenced By</th>
        <th>Code Hits</th>
        <th>ตัวอย่างไฟล์ที่พบ</th>
        <th>จัดกลุ่ม</th>
        <th>Conf.</th>
        <th>Created / Updated</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $catClass = 'ok';
        if (strpos($r['category'], 'น่าสงสัย') === 0) $catClass = 'bad';
        elseif (strpos($r['category'], 'เสี่ยง') === 0 || strpos($r['category'], 'โครงสร้าง') === 0) $catClass = 'warn';
      ?>
      <tr>
        <td class="mono"><?php echo h($r['table']) ?></td>
        <td><?php echo number_format((int)$r['rows']) ?></td>
        <td><?php echo (int)$r['references'] ?></td>
        <td><?php echo (int)$r['referenced_by'] ?></td>
        <td><span class="chip mono"><?php echo (int)$r['code_hits'] ?></span></td>
        <td class="files">
          <?php if (empty($r['example_files'])): ?>
            <span class="sub">—</span>
          <?php else: ?>
            <div class="mono">
            <?php foreach ($r['example_files'] as $f): ?>
              <div><?php echo h(shortenPath($f, 90)) ?></div>
            <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </td>
        <td class="cat <?php echo $catClass ?>"><?php echo h($r['category']) ?></td>
        <td class="mono"><?php echo number_format($r['confidence'], 2) ?></td>
        <td class="mono">
          <div><?php echo h((string)$r['create_time']) ?></div>
          <div class="sub"><?php echo h((string)$r['update_time']) ?></div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div class="footer">หมายเหตุ: เกณฑ์นี้เป็น heuristic เพื่อช่วยไล่ดูเท่านั้น — ควรสำรองข้อมูลและทดสอบก่อนดำเนินการลบ</div>
</div>
</body>
</html>
<?php }

function shortenPath(string $p, int $limit=100): string {
    if (mb_strlen($p) <= $limit) return $p;
    $h = floor(($limit-3)/2);
    return mb_substr($p,0,$h).'...'.mb_substr($p,-$h);
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
