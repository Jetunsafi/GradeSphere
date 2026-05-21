<?php
// ============================================================
//  view-result.php  –  Printable result page with live DB data
//  Accessed from student dashboard "View Result" button
// ============================================================

require_once 'config.php';
startSecureSession();
requireLogin('student');

$db        = getDB();
$userId    = $_SESSION['user_id'];
// Load student profile
$stmt = $db->prepare("
    SELECT s.*, c.name AS course_name, c.code AS course_code, c.total_semesters
    FROM students s
    JOIN courses c ON s.course_id = c.id
    WHERE s.user_id = ?
");
$stmt->execute([$userId]);
$student = $stmt->fetch();

if (!$student) { header('Location: student-dashboard.html'); exit; }

$semester  = (int)($_GET['semester'] ?? $_SESSION['semester'] ?? 1);
$year      = (int)($_GET['year'] ?? 0);

if (!$year) {
    // Auto-detect most recent exam year with published data for this student/semester
    $yrStmt = $db->prepare("SELECT exam_year FROM results WHERE student_id = ? AND semester = ? AND is_published = 1 ORDER BY exam_year DESC LIMIT 1");
    $yrStmt->execute([$student['id'], $semester]);
    $latestYear = $yrStmt->fetchColumn();
    $year = $latestYear ? (int)$latestYear : (int)date('Y');
}

// Load results
$rStmt = $db->prepare("
    SELECT r.*, sub.code AS subject_code, sub.name AS subject_name,
           sub.max_theory, sub.max_practical, sub.max_total
    FROM results r
    JOIN subjects sub ON r.subject_id = sub.id
    WHERE r.student_id = ? AND r.semester = ? AND r.exam_year = ? AND r.is_published = 1
    ORDER BY sub.code
");
$rStmt->execute([$student['id'], $semester, $year]);
$marks = $rStmt->fetchAll();

$totalObtained = array_sum(array_column($marks, 'total_marks'));
$totalMax      = array_sum(array_column($marks, 'max_total'));
$percentage    = $totalMax > 0 ? round($totalObtained / $totalMax * 100, 2) : 0;
$sgpa          = calculateSGPA($percentage);
$overallGrade  = calculateGrade($totalMax > 0 ? (int)round($totalObtained / max(count($marks), 1)) : 0);
$hasFail       = in_array('FAIL', array_column($marks, 'status'));
$overallStatus = $hasFail ? 'FAIL' : 'PASS';

logActivity($userId, $_SESSION['username'], 'student', "DOWNLOADED_RESULT: sem=$semester year=$year");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Result – <?= htmlspecialchars($student['roll_number']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background:#f4f7fc; font-family:'Times New Roman',serif; }
        .result-wrapper { max-width:1000px; margin:30px auto; background:white; box-shadow:0 10px 30px rgba(0,0,0,.1); border-radius:10px; padding:40px; }
        .university-header { text-align:center; margin-bottom:30px; }
        .university-header h1 { font-size:26px; font-weight:bold; text-transform:uppercase; margin:0 0 4px; }
        .university-header p  { font-size:13px; color:#333; margin:2px 0; }
        .university-header hr { width:200px; margin:14px auto; border:2px solid #000; }
        .info-box  { border:2px solid #000; padding:14px; margin:20px 0; }
        .info-table td { padding:7px; width:50%; }
        .marks-table { width:100%; border-collapse:collapse; border:2px solid #000; margin:18px 0; }
        .marks-table th { border:1px solid #000; padding:9px 5px; background:#e0e0e0; text-align:center; font-weight:bold; }
        .marks-table td { border:1px solid #000; padding:7px 5px; text-align:center; }
        .marks-table td:nth-child(3) { text-align:left; }
        .summary-box { display:flex; justify-content:space-between; border:2px solid #000; padding:18px; margin:24px 0; }
        .summary-item { text-align:center; flex:1; }
        .summary-label { font-size:12px; color:#666; margin-bottom:4px; }
        .summary-value { font-size:17px; font-weight:bold; }
        .pass-h { color:#28a745; font-weight:bold; }
        .fail-h { color:#dc3545; font-weight:bold; }
        .signatures { display:flex; justify-content:space-around; margin:45px 0 18px; }
        .sig-line { border-top:2px solid #000; width:140px; margin-bottom:4px; }
        .declaration { text-align:center; margin-top:24px; font-size:11px; color:#666; }
        .action-buttons { display:flex; justify-content:center; gap:14px; margin-top:28px; }
        .btn { padding:11px 24px; border:none; border-radius:8px; font-weight:500; cursor:pointer; display:inline-flex; align-items:center; gap:7px; transition:all .3s; }
        .btn-primary  { background:linear-gradient(135deg,#4361ee,#3a0ca3); color:white; }
        .btn-secondary{ background:linear-gradient(135deg,#f72585,#b5179e); color:white; }
        .btn-outline  { background:transparent; border:2px solid #4361ee; color:#4361ee; text-decoration:none; }
        .btn:hover { transform:translateY(-2px); box-shadow:0 5px 15px rgba(0,0,0,.2); }
        @media print {
            .action-buttons, nav { display:none !important; }
            body { background:white; }
            .result-wrapper { box-shadow:none; margin:0; }
        }
    </style>
</head>
<body>
<div class="result-wrapper">
    <div class="university-header">
        <h1>University Institute of Technology</h1>
        <p>Burdwan, West Bengal – 713104</p>
        <p>Established 2000 | NAAC Accredited 'A' Grade</p>
        <hr>
        <h2 style="font-size:19px;font-weight:bold;margin:13px 0 4px;">DETAILED STATEMENT OF MARKS</h2>
        <p><strong><?= htmlspecialchars($student['course_name']) ?> – Semester <?= $semester ?></strong></p>
        <p>Examination: <?= $year ?> | Roll No: <?= htmlspecialchars($student['roll_number']) ?></p>
    </div>

    <div class="info-box">
        <table class="info-table">
            <tr>
                <td><strong>Student Name:</strong> <?= htmlspecialchars($student['full_name']) ?></td>
                <td><strong>Roll Number:</strong> <?= htmlspecialchars($student['roll_number']) ?></td>
            </tr>
            <tr>
                <td><strong>Father's Name:</strong> <?= htmlspecialchars($student['father_name'] ?? '–') ?></td>
                <td><strong>Registration No.:</strong> <?= htmlspecialchars($student['registration_no'] ?? '–') ?></td>
            </tr>
            <tr>
                <td><strong>Mother's Name:</strong> <?= htmlspecialchars($student['mother_name'] ?? '–') ?></td>
                <td><strong>Course:</strong> <?= htmlspecialchars($student['course_name']) ?></td>
            </tr>
        </table>
    </div>

    <h3 style="font-size:17px;margin:18px 0 8px;">Subject-wise Marks</h3>

    <?php if (empty($marks)): ?>
        <p style="text-align:center;color:#888;padding:20px;">Results not yet published for this semester.</p>
    <?php else: ?>
    <table class="marks-table">
        <thead>
            <tr>
                <th>S.No</th><th>Subject Code</th><th>Subject Name</th>
                <th>Theory</th><th>Practical</th><th>Total</th>
                <th>Max</th><th>Grade</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($marks as $i => $m): ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td><?= htmlspecialchars($m['subject_code']) ?></td>
                <td style="text-align:left;"><?= htmlspecialchars($m['subject_name']) ?></td>
                <td><?= $m['theory_marks'] ?></td>
                <td><?= $m['practical_marks'] ?></td>
                <td><?= $m['total_marks'] ?></td>
                <td><?= $m['max_total'] ?></td>
                <td><?= $m['grade'] ?></td>
                <td class="<?= $m['status']==='PASS' ? 'pass-h' : 'fail-h' ?>"><?= $m['status'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" style="text-align:right;font-weight:bold;">Grand Total</td>
                <td style="font-weight:bold;"><?= $totalObtained ?></td>
                <td style="font-weight:bold;"><?= $totalMax ?></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>

    <div class="summary-box">
        <div class="summary-item"><div class="summary-label">Total Marks</div><div class="summary-value"><?= $totalObtained ?>/<?= $totalMax ?></div></div>
        <div class="summary-item"><div class="summary-label">Percentage</div><div class="summary-value"><?= $percentage ?>%</div></div>
        <div class="summary-item"><div class="summary-label">SGPA</div><div class="summary-value"><?= $sgpa ?></div></div>
        <div class="summary-item"><div class="summary-label">Grade</div><div class="summary-value" style="color:#f72585;"><?= $overallGrade ?></div></div>
        <div class="summary-item"><div class="summary-label">Result</div><div class="summary-value <?= $hasFail ? 'fail-h' : 'pass-h' ?>"><?= $overallStatus ?></div></div>
    </div>
    <?php endif; ?>

    <div class="signatures">
        <div class="signature" style="text-align:center;"><div class="sig-line"></div><p>Exam Controller</p></div>
        <div class="signature" style="text-align:center;"><div class="sig-line"></div><p>Principal</p></div>
        <div class="signature" style="text-align:center;"><div class="sig-line"></div><p>Registrar</p></div>
    </div>

    <div class="declaration">
        <p>This is a computer generated statement. No signature is required.</p>
        <p>Generated on: <?= date('d-m-Y') ?></p>
    </div>

    <div class="action-buttons">
        <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Print Result</button>
        <a href="student-dashboard.html" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>
</div>
</body>
</html>
