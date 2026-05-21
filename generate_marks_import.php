<?php
/**
 * generate_import_marks.php
 * Generates marks_import.csv for all 60 students (10 per dept × 6 depts)
 * Each student has 6 sems × 6 subjects = 36 marks rows
 * Roll numbers follow pattern: {COURSE}{YEAR}{NNN} e.g. BCA2026001
 */

$depts = ['BCA','BBA','MCA','MSCit','BSc','BCom'];
$year = 2026;
$semCount = 6;
$subjPerSem = 6;
$studentsPerDept = 10;

// Seed for reproducibility
srand(42);

$rows = [];
$rows[] = 'roll_number,subject_code,theory,practical,semester,attendance_status';

foreach ($depts as $dept) {
    for ($s = 1; $s <= $studentsPerDept; $s++) {
        $rollNo = strtoupper($dept) . $year . str_pad($s, 3, '0', STR_PAD_LEFT);
        
        // Decide if this student occasionally fails or is absent (adds realism)
        // ~20% chance of at least 1 fail, ~10% chance of 1 absent across all subs
        $failSem = (rand(1,5) === 1) ? rand(1, $semCount) : 0;
        $failSubj = $failSem ? rand(1, $subjPerSem) : 0;
        $absentSem = (rand(1,10) === 1) ? rand(1, $semCount) : 0;
        $absentSubj = $absentSem ? rand(1, $subjPerSem) : 0;
        
        for ($sem = 1; $sem <= $semCount; $sem++) {
            for ($sub = 1; $sub <= $subjPerSem; $sub++) {
                // Build subject code: e.g. BCA101, BCA106, BCA201...
                $subCode = strtoupper($dept) . ($sem * 100 + $sub);
                
                $isAbsent = ($sem === $absentSem && $sub === $absentSubj);
                $isFail   = ($sem === $failSem && $sub === $failSubj);
                
                if ($isAbsent) {
                    $rows[] = "$rollNo,$subCode,0,0,$sem,ABSENT";
                } elseif ($isFail) {
                    // Fail: theory < 28 (below 40% of 75 = 30), practical OK
                    $theory = rand(5, 29);
                    $practical = rand(10, 25);
                    $rows[] = "$rollNo,$subCode,$theory,$practical,$sem,PRESENT";
                } else {
                    // Normal: pass with decent marks
                    $theory = rand(38, 75);
                    $practical = rand(15, 25);
                    $rows[] = "$rollNo,$subCode,$theory,$practical,$sem,PRESENT";
                }
            }
        }
    }
}

$csv = implode("\n", $rows);
file_put_contents(__DIR__ . '/marks_import.csv', $csv);

echo "Generated " . (count($rows)-1) . " mark rows\n";
echo "File saved to marks_import.csv\n";
?>
