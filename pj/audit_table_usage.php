<?php
/**
 * audit_table_usage.php
 * สแกนทั้งฐานข้อมูล + โค้ดในโปรเจกต์ เพื่อหาตารางที่น่าจะ "ไม่ได้ใช้"
 * - พยายาม include('db_connect.php') อัตโนมัติ (รองรับทั้ง $pdo และ $conn ของ mysqli)
 * - ถ้า include ไม่ได้ ให้ใส่ค่าคอนฟิกมือด้านล่าง
 * - กวาดไฟล์ .php/.sql/.js/.ts/.py ทั้งโฟลเดอร์ (recursive) หา pattern การอ้างถึงตาราง
 * - สรุปผลเป็น HTML ตาราง + CSV ดิบ (ดาวน์โหลดได้)
 */

ini_set('memory_limit','1024M');
set_time_limit(0);

/* ---------------- User Config (สำรอง ถ้า include db_connect.php ไม่ได้) ---------------- */
$DB_HOST = '127.0.0.1';
$DB_NAME = 'studentregistration';
$DB_USER = 'root';
$DB_PASS = '';
$CHARSET = 'utf8mb4';

/* ---------------- Scan Config ---------------- */
$PROJECT_ROOT = __DIR__;                          // ตำแหน่งโปรเจกต์ (โฟลเดอร์ปัจจุบัน)
$SCAN_EXTS    = ['php','sql','js','ts','py'];     // นามสกุลไฟล์ที่จะกวาดหา
$EXCLUDE_DIRS = ['vendor','node_modules','.git','.idea','.vscode','storage','cache','dist','build'];
$CASE_SENSITIVE = false;                          // สแกนแยกตัวพิมพ์ใหญ่เล็กหรือไม่ (แนะนำ false)

/* ---------------- Helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function endsWith($s,$suffix){ return substr($s,-strlen($suffix)) === $suffix; }
function humanInt($n){ return number_format((int)$n); }

/* ---------------- Connect DB ---------------- */
$pdo = null;
$conn = null;
$dbConnected = false;
$connectionNote = '';

/* พยายาม include db_connect.php เพื่อ reuse การเชื่อมต่อเดิมในโปรเจกต์ */
$dbcandidates = [
  __DIR__ . '/db_connect.php',
  __DIR__ . '/includes/db_connect.php',
  __DIR__ . '/config/db_connect.php',
];
foreach ($dbcandidates as $cand) {
  if (is_file($cand)) {
    try {
      require_once $cand;
      $connectionNote = "Loaded connection from ".basename($cand);
      break;
    } catch (Throwable $e) {
      // ignore
    }
  }
}

/* ตรวจว่ามี $pdo หรือ $conn ไหม */
if (isset($pdo) && $pdo instanceof PDO) {
  try { $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } catch(Throwable $e){}
  $dbConnected = true;
} elseif (isset($conn) && $conn instanceof mysqli) {
  $dbConnected = true;
} else {
  // ต่อเองด้วย PDO
  try {
    $dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=$CHARSET";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $dbConnected = true;
    $connectionNote = "Connected via internal PDO config";
  } catch (Throwable $e) {
    $dbConnected = false;
    $connectionNote = "DB connect failed: ".$e->getMessage();
  }
}

/* ---------------- Fetch schema info ---------------- */
$tables = [];          // table => [rows => ?, engine=>?, create_time=>?...]
$fksOut = [];          // table => list of fk to (ref_table)
$fksIn  = [];          // table => list of fk from (child_table)
if ($dbConnected) {
  // ตารางทั้งหมด
  $sqlTables = "SELECT TABLE_NAME, ENGINE, TABLE_ROWS, CREATE_TIME
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE='BASE TABLE'";
  if (isset($pdo) && $pdo instanceof PDO) {
    foreach ($pdo->query($sqlTables) as $r) {
      $tables[$r['TABLE_NAME']] = [
        'rows' => (int)$r['TABLE_ROWS'],
        'engine' => $r['ENGINE'],
        'create_time' => $r['CREATE_TIME'],
      ];
    }
  } else {
    $res = $conn->query($sqlTables);
    while ($r = $res->fetch_assoc()) {
      $tables[$r['TABLE_NAME']] = [
        'rows' => (int)$r['TABLE_ROWS'],
        'engine' => $r['ENGINE'],
        'create_time' => $r['CREATE_TIME'],
      ];
    }
  }

  // Foreign Keys
  $sqlFK = "SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL";
  if (isset($pdo) && $pdo instanceof PDO) {
    foreach ($pdo->query($sqlFK) as $r) {
      $t = $r['TABLE_NAME'];
      $ref = $r['REFERENCED_TABLE_NAME'];
      $fksOut[$t][] = $ref;              // t -> ref
      $fksIn[$ref][] = $t;               // ref <- t
    }
  } else {
    $res = $conn->query($sqlFK);
    while ($r = $res->fetch_assoc()) {
      $t = $r['TABLE_NAME'];
      $ref = $r['REFERENCED_TABLE_NAME'];
      $fksOut[$t][] = $ref;
      $fksIn[$ref][] = $t;
    }
  }

  // นับแถวจริงแบบแม่นยำ (อาจช้าในตารางใหญ่) — ทำเฉพาะตารางเล็ก/กลาง
  // ถ้าไม่อยากช้า ให้คอมเมนต์บล็อกนี้ออก
  foreach ($tables as $tname => &$meta) {
    try {
      $q = (isset($pdo) && $pdo instanceof PDO)
        ? $pdo->query("SELECT COUNT(*) c FROM `{$tname}`")
        : $conn->query("SELECT COUNT(*) c FROM `{$tname}`");
      $row = (isset($pdo) && $pdo instanceof PDO) ? $q->fetch(PDO::FETCH_ASSOC) : $q->fetch_assoc();
      if ($row && isset($row['c'])) $meta['rows'] = (int)$row['c'];
    } catch (Throwable $e) {
      // อ่านไม่ได้ก็ข้าม
    }
  }
  unset($meta);
}

/* ---------------- Scan codebase ---------------- */
$allFiles = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PROJECT_ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
  if (!$file->isFile()) continue;
  $path = $file->getPathname();

  // ข้ามโฟลเดอร์ที่ exclude
  $skip = false;
  foreach ($EXCLUDE_DIRS as $ex) {
    if (strpos($path, DIRECTORY_SEPARATOR.$ex.DIRECTORY_SEPARATOR) !== false) { $skip = true; break; }
  }
  if ($skip) continue;

  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  if (!in_array($ext, $SCAN_EXTS, true)) continue;
  $allFiles[] = $path;
}

/**
 * กลยุทธ์จับชื่อโต๊ะ:
 * - มองหาคีย์เวิร์ด SQL + ชื่อโต๊ะ:  FROM/JOIN/INTO/UPDATE/DELETE/CREATE TABLE
 * - รองรับ backtick และ quote
 * - รองรับการใช้ prefix db.`table` หรือ `table` เฉย ๆ
 */
$codeRefs = []; // table => set(files)
$patterns = [
  // ... FROM `table` / FROM table
  '/\bFROM\s+[`"\']?([a-zA-Z0-9_]+)[`"\']?/i',
  '/\bJOIN\s+[`"\']?([a-zA-Z0-9_]+)[`"\']?/i',
  '/\bINTO\s+[`"\']?([a-zA-Z0-9_]+)[`"\']?/i',         // INSERT INTO
  '/\bUPDATE\s+[`"\']?([a-zA-Z0-9_]+)[`"\']?/i',
  '/\bDELETE\s+FROM\s+[`"\']?([a-zA-Z0-9_]+)[`"\']?/i',
  '/\bREPLACE\s+INTO\s+[`"\']?([a-zA-Z0-9_]+)[`"\']?/i',
  '/\bTRUNCATE\s+TABLE\s+[`"\']?([a-zA-Z0-9_]+)[`"\']?/i',
  '/\bALTER\s+TABLE\s+[`"\']?([a-zA-Z0-9_]+)[`"\']?/i',
  '/\bCREATE\s+TABLE\s+[`"\']?([a-zA-Z0-9_]+)[`"\']?/i',
  // case: db.`table`
  '/\b(?:FROM|JOIN|INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO|TRUNCATE\s+TABLE|ALTER\s+TABLE|CREATE\s+TABLE)\s+[a-zA-Z0-9_]+\.\s*[`"\']?([a-zA-Z0-9_]+)[`"\']?/i',
];

foreach ($allFiles as $fpath) {
  $content = @file_get_contents($fpath);
  if ($content === false) continue;
  if (!$CASE_SENSITIVE) $content = mb_strtolower($content);

  foreach ($patterns as $re) {
    if (preg_match_all($re, $content, $m)) {
      foreach ($m[1] as $tab) {
        $tab = trim($tab);
        if ($tab === '') continue;
        if (!isset($codeRefs[$tab])) $codeRefs[$tab] = [];
        $codeRefs[$tab][$fpath] = true;
      }
    }
  }
}

/* รวมผลลัพธ์ */
$rows = [];
$allTableNames = array_keys($tables);
sort($allTableNames, SORT_NATURAL);
foreach ($allTableNames as $tname) {
  $rowCount = $tables[$tname]['rows'] ?? null;
  $hasCode  = isset($codeRefs[strtolower($tname)]) || isset($codeRefs[$tname]);

  // รวมรายชื่อไฟล์ที่อ้างถึง
  $filesUsing = [];
  if (isset($codeRefs[$tname]))       $filesUsing = array_merge($filesUsing, array_keys($codeRefs[$tname]));
  if (isset($codeRefs[strtolower($tname)])) $filesUsing = array_merge($filesUsing, array_keys($codeRefs[strtolower($tname)]));

  // FK ความสัมพันธ์
  $parents = $fksOut[$tname] ?? []; // เราอ้างถึงใคร
  $children = $fksIn[$tname] ?? []; // ใครอ้างถึงเรา

  // ธงความเสี่ยงไม่ได้ใช้: ว่าง + ไม่พบในโค้ด + ไม่มี FK เข้า/ออก
  $flagUnused = ($rowCount === 0) && !$hasCode && empty($parents) && empty($children);

  $rows[] = [
    'table' => $tname,
    'rows' => $rowCount,
    'code_ref' => $hasCode ? count(array_unique($filesUsing)) : 0,
    'files' => array_values(array_unique($filesUsing)),
    'fk_parents' => array_values(array_unique($parents)),
    'fk_children'=> array_values(array_unique($children)),
    'engine' => $tables[$tname]['engine'] ?? '',
    'created' => $tables[$tname]['create_time'] ?? '',
    'flag_unused' => $flagUnused,
  ];
}

/* สร้าง CSV เพื่อดาวน์โหลด */
$csvPath = __DIR__.'/audit_table_usage.csv';
$fp = fopen($csvPath, 'w');
fputcsv($fp, ['table','rows','code_ref_count','fk_parents','fk_children','engine','created','flag_unused']);
foreach ($rows as $r) {
  fputcsv($fp, [
    $r['table'],
    $r['rows'],
    $r['code_ref'],
    implode('|', $r['fk_parents']),
    implode('|', $r['fk_children']),
    $r['engine'],
    $r['created'],
    $r['flag_unused'] ? 'YES' : 'NO',
  ]);
}
fclose($fp);

/* ---------------- Render HTML ---------------- */
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>Audit Table Usage</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial,'Noto Sans','Liberation Sans',sans-serif;
         background:#0b1220;color:#e6ecff;margin:24px}
    a{color:#93c5fd}
    .wrap{max-width:1200px;margin:0 auto}
    h1{font-size:22px;margin:0 0 8px}
    .meta{opacity:.8;margin-bottom:16px}
    table{width:100%;border-collapse:collapse;background:rgba(255,255,255,0.05);border-radius:12px;overflow:hidden}
    th,td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,0.08);vertical-align:top}
    th{position:sticky;top:0;background:rgba(255,255,255,0.06);backdrop-filter:saturate(180%) blur(8px);text-align:left}
    tr:hover{background:rgba(255,255,255,0.04)}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px}
    .ok{background:#113d2a;color:#8af0b7}
    .warn{background:#43230f;color:#ffcc99}
    .bad{background:#3d1111;color:#ff9e9e}
    .muted{opacity:.7}
    details{margin:0}
    summary{cursor:pointer;color:#a3bffa}
    .footer{margin-top:16px;font-size:12px;opacity:.7}
    .tag{display:inline-block;background:rgba(255,255,255,0.1);padding:2px 6px;border-radius:6px;margin:2px 4px 0 0;font-size:12px}
    .sticky{position:sticky;top:42px;background:rgba(11,18,32,.9);padding:8px 0;margin:8px 0 16px;backdrop-filter:blur(8px)}
    input[type="text"]{background:#0f172a;border:1px solid #243b55;color:#e6ecff;border-radius:8px;padding:8px 10px;width:320px}
  </style>
</head>
<body>
<div class="wrap">
  <h1>Audit Table Usage</h1>
  <div class="meta">
    <div>Connection: <?=h($connectionNote)?></div>
    <div>Total tables: <?=count($rows)?> | Code files scanned: <?=count($allFiles)?> | 
      <a href="<?=h(basename($csvPath))?>" download>ดาวน์โหลด CSV</a></div>
  </div>

  <div class="sticky">
    <input id="q" type="text" placeholder="พิมพ์เพื่อกรองชื่อตาราง/ไฟล์..." oninput="filterRows()">
  </div>

  <table id="tbl">
    <thead>
      <tr>
        <th>Table</th>
        <th>Rows</th>
        <th>Code refs</th>
        <th>FK Parents (เราอ้างถึง)</th>
        <th>FK Children (ใครอ้างถึงเรา)</th>
        <th>Flags</th>
        <th>Files</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): 
      $flags = [];
      if ($r['rows'] === 0) $flags[] = '<span class="pill bad">EMPTY</span>';
      if ($r['code_ref'] === 0) $flags[] = '<span class="pill warn">NO_CODE_REF</span>';
      if (empty($r['fk_parents']) && empty($r['fk_children'])) $flags[] = '<span class="pill warn">NO_FK_LINKS</span>';
      if ($r['flag_unused']) $flags[] = '<span class="pill bad">LIKELY_UNUSED</span>';
      if (!$flags) $flags[] = '<span class="pill ok">OK</span>';
    ?>
      <tr>
        <td><strong><?=h($r['table'])?></strong><div class="muted"><?=h($r['engine'])?></div></td>
        <td><?=humanInt($r['rows'])?></td>
        <td><?=humanInt($r['code_ref'])?></td>
        <td>
          <?php if ($r['fk_parents']): foreach ($r['fk_parents'] as $p): ?>
            <span class="tag"><?=h($p)?></span>
          <?php endforeach; else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($r['fk_children']): foreach ($r['fk_children'] as $c): ?>
            <span class="tag"><?=h($c)?></span>
          <?php endforeach; else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td><?=implode(' ',$flags)?></td>
        <td>
          <?php if ($r['files']): ?>
            <details>
              <summary><?=count($r['files'])?> file(s)</summary>
              <div class="muted">
                <?php foreach ($r['files'] as $f): ?>
                  <div><?=h(str_replace($PROJECT_ROOT.DIRECTORY_SEPARATOR,'',$f))?></div>
                <?php endforeach; ?>
              </div>
            </details>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="footer">
    เคล็ดลับ: ตารางที่ถูก mark เป็น <b>LIKELY_UNUSED</b> คือเงื่อนไขครบ “ว่าง + ไม่พบในโค้ด + ไม่มี FK เข้า/ออก”.
    โปรดตรวจด้วยสายตาอีกครั้งก่อนลบ (เผื่อมีการอ้างถึงแบบไดนามิก เช่น สร้างชื่อโต๊ะจากตัวแปร)
  </div>
</div>

<script>
function filterRows(){
  const q = document.getElementById('q').value.toLowerCase();
  const rows = document.querySelectorAll('#tbl tbody tr');
  rows.forEach(tr=>{
    tr.style.display = tr.innerText.toLowerCase().includes(q) ? '' : 'none';
  });
}
</script>
</body>
</html>
