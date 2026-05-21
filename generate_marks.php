<?php
/**
 * generate_marks.php — Fix subject mappings + Insert marks for ALL students
 * Run via browser: http://localhost/erms/generate_marks.php
 */
require_once 'config.php';
$db = getDB();

// Ensure attendance_status column exists
try { $db->exec("ALTER TABLE results ADD COLUMN attendance_status ENUM('PRESENT','ABSENT') DEFAULT 'PRESENT'"); } catch (Exception $e) {}

echo "<html><head><title>Generate Marks</title><style>
body{font-family:Arial;padding:20px;background:#f0f2f5;}
table{border-collapse:collapse;width:100%;margin:15px 0;}
th{background:#4361ee;color:#fff;padding:8px 12px;text-align:left;font-size:13px;}
td{padding:6px 12px;border-bottom:1px solid #e0e0e0;font-size:12px;}
.pass{background:#e8f5e9;} .fail{background:#ffebee;} .absent{background:#fff3e0;}
h2{color:#2b2d42;} .box{background:#fff;padding:20px;border-radius:12px;margin:15px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1);}
a.btn{display:inline-block;padding:12px 25px;background:#4361ee;color:#fff;text-decoration:none;border-radius:8px;margin:10px 5px;}
</style></head><body>";

// ── STEP 1: Fix subject→course mappings ──────────────────
echo "<div class='box'><h2>Step 1: Fixing Subject → Course Mappings</h2>";

$fixes = [
    // MT subjects → M-TECH (id=5)
    ["UPDATE subjects SET course_id = 5 WHERE code LIKE 'MT%'", "MT subjects → M-TECH"],
    // BT7xx, BT8xx → M-TECH (id=5) for sem 7-8 students
    ["UPDATE subjects SET course_id = 5 WHERE code LIKE 'BT7%' OR code LIKE 'BT8%'", "BT7/BT8 subjects → M-TECH"],
];

foreach ($fixes as $f) {
    $affected = $db->exec($f[0]);
    echo "<p>✅ {$f[1]}: {$affected} rows updated</p>";
}
echo "</div>";

// ── STEP 2: Get all students ────────────────────────────
$students = $db->query("
    SELECT s.id, s.roll_number, s.full_name, s.course_id, s.current_semester, c.code AS course_code
    FROM students s JOIN courses c ON s.course_id = c.id ORDER BY s.id
")->fetchAll();

if (empty($students)) {
    die("<div class='box'><h2 style='color:red;'>❌ No students found! Import students first.</h2></div></body></html>");
}

// ── STEP 3: Build subject map by course_id + semester ───
$allSubjects = $db->query("SELECT * FROM subjects ORDER BY code")->fetchAll();
$subjectMap = [];
foreach ($allSubjects as $sub) {
    $key = $sub['course_id'] . '_' . $sub['semester'];
    $subjectMap[$key][] = $sub;
}

// ── STEP 4: Define fail/absent students ─────────────────
// ~10 fail, ~8 absent by student index
$totalStudents = count($students);
$failIndices = [];
$absentIndices = [];

// Spread fails evenly across students
for ($i = 0; $i < 10 && $i * 7 < $totalStudents; $i++) {
    $failIndices[] = 3 + ($i * 7); // every 7th student starting from index 3
}
// Spread absents evenly
for ($i = 0; $i < 8 && $i * 9 < $totalStudents; $i++) {
    $absentIndices[] = 5 + ($i * 9); // every 9th student starting from index 5
}

// ── STEP 5: Generate and insert marks ───────────────────
echo "<div class='box'><h2>Step 2: Generating Marks for {$totalStudents} Students</h2>";

$insertStmt = $db->prepare("
    INSERT INTO results (student_id, subject_id, semester, exam_year, theory_marks, practical_marks, attendance_status, is_published, entered_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1)
    ON DUPLICATE KEY UPDATE theory_marks=VALUES(theory_marks), practical_marks=VALUES(practical_marks), attendance_status=VALUES(attendance_status), entered_at=NOW()
");

$inserted = 0; $failCount = 0; $absentCount = 0; $skipped = 0;
$examYear = 2026;
$studentMarksLog = [];

echo "<table><tr><th>#</th><th>Roll No</th><th>Name</th><th>Course</th><th>Sem</th><th>Subject</th><th>Theory</th><th>Prac</th><th>Total</th><th>Status</th></tr>";

foreach ($students as $idx => $stu) {
    $key = $stu['course_id'] . '_' . $stu['current_semester'];
    
    if (!isset($subjectMap[$key]) || empty($subjectMap[$key])) {
        $skipped++;
        echo "<tr class='absent'><td colspan='10'>⚠️ No subjects for {$stu['roll_number']} ({$stu['course_code']} Sem {$stu['current_semester']})</td></tr>";
        continue;
    }
    
    // Take up to 5 subjects per student
    $subjects = array_slice($subjectMap[$key], 0, 5);
    $isFail = in_array($idx, $failIndices);
    $isAbsent = in_array($idx, $absentIndices);
    
    foreach ($subjects as $subIdx => $sub) {
        $maxTh = (int)$sub['max_theory'];
        $maxPr = (int)$sub['max_practical'];
        
        // ABSENT: first subject only
        if ($isAbsent && $subIdx === 1) {
            $theory = 0; $practical = 0; $attendance = 'ABSENT';
            $css = 'absent'; $label = 'ABSENT';
            $absentCount++;
        }
        // FAIL: second subject only
        elseif ($isFail && $subIdx === 0) {
            $theory = $maxTh > 0 ? rand(5, 15) : 0;
            $practical = $maxPr > 0 ? rand(3, 10) : rand(5, 18);
            $attendance = 'PRESENT';
            $css = 'fail'; $label = 'FAIL';
            $failCount++;
        }
        // NORMAL PASS
        else {
            $level = rand(1, 10);
            if ($level <= 3) { // low pass
                $theory = $maxTh > 0 ? rand((int)($maxTh*0.45), (int)($maxTh*0.55)) : 0;
                $practical = $maxPr > 0 ? rand((int)($maxPr*0.5), (int)($maxPr*0.7)) : rand(42, 55);
            } elseif ($level <= 7) { // medium
                $theory = $maxTh > 0 ? rand((int)($maxTh*0.55), (int)($maxTh*0.75)) : 0;
                $practical = $maxPr > 0 ? rand((int)($maxPr*0.6), (int)($maxPr*0.85)) : rand(55, 75);
            } else { // high
                $theory = $maxTh > 0 ? rand((int)($maxTh*0.78), (int)($maxTh*0.95)) : 0;
                $practical = $maxPr > 0 ? rand((int)($maxPr*0.8), (int)($maxPr*0.96)) : rand(78, 95);
            }
            $attendance = 'PRESENT';
            $total = $theory + $practical;
            $css = 'pass'; $label = 'PASS';
        }
        
        $total = $theory + $practical;
        
        try {
            $insertStmt->execute([$stu['id'], $sub['id'], $stu['current_semester'], $examYear, $theory, $practical, $attendance]);
            $inserted++;
            echo "<tr class='{$css}'><td>{$inserted}</td><td>{$stu['roll_number']}</td><td>{$stu['full_name']}</td><td>{$stu['course_code']}</td><td>{$stu['current_semester']}</td><td>{$sub['code']}</td><td>{$theory}/{$maxTh}</td><td>{$practical}/{$maxPr}</td><td>{$total}</td><td><strong>{$label}</strong></td></tr>";
        } catch (Exception $e) {
            echo "<tr class='fail'><td colspan='10'>❌ Error for {$stu['roll_number']}: {$e->getMessage()}</td></tr>";
        }
    }
}

echo "</table></div>";

// ── STEP 6: Summary ─────────────────────────────────────
echo "<div class='box'>";
echo "<h2>✅ Marks Generation Complete!</h2>";
echo "<table style='width:auto;'>";
echo "<tr><td><strong>Students processed:</strong></td><td>" . ($totalStudents - $skipped) . " / {$totalStudents}</td></tr>";
echo "<tr><td><strong>Total marks inserted:</strong></td><td>{$inserted}</td></tr>";
echo "<tr class='fail'><td><strong>Fail entries:</strong></td><td>{$failCount}</td></tr>";
echo "<tr class='absent'><td><strong>Absent entries:</strong></td><td>{$absentCount}</td></tr>";
echo "<tr><td><strong>Skipped (no subjects):</strong></td><td>{$skipped}</td></tr>";
echo "</table>";
echo "<br><p>👉 Now click <strong>Publish Results</strong> in the admin dashboard (Year: 2026)</p>";
echo "<a class='btn' href='admindashboard.html'>Go to Admin Dashboard</a>";
echo "</div></body></html>";
?>
