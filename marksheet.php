<?php
// ============================================================
//  marksheet.php — Printable Semester Marksheet
//  Access: marksheet.php?semester=X&exam_year=Y
//  Works for both students (session) and public link
// ============================================================
require_once 'config.php';
startSecureSession();

$role     = $_SESSION['role'] ?? '';
$semester = (int)($_GET['semester'] ?? 1);
$examYear = (int)($_GET['exam_year'] ?? date('Y'));

// Determine student_id
$studentId = 0;
if ($role === 'student' && isset($_SESSION['student_id'])) {
    $studentId = (int)$_SESSION['student_id'];
} elseif ($role === 'admin' && isset($_GET['student_id'])) {
    $studentId = (int)$_GET['student_id'];
}

if (!$studentId) {
    header('Location: login.html'); exit;
}

$db = getDB();

// Fetch student + course info
$stuStmt = $db->prepare("
    SELECT s.*, c.name AS course_name, c.code AS course_code, c.department
    FROM students s JOIN courses c ON s.course_id = c.id
    WHERE s.id = ?
");
$stuStmt->execute([$studentId]);
$student = $stuStmt->fetch();
if (!$student) { echo "Student not found."; exit; }

// Fetch published results for given semester + year
$resStmt = $db->prepare("
    SELECT r.*, sub.code AS subject_code, sub.name AS subject_name,
           sub.max_theory, sub.max_practical, sub.max_total
    FROM results r JOIN subjects sub ON r.subject_id = sub.id
    WHERE r.student_id = ? AND r.semester = ? AND r.exam_year = ? AND r.is_published = 1
    ORDER BY sub.code
");
$resStmt->execute([$studentId, $semester, $examYear]);
$marks = $resStmt->fetchAll();

// Calculate summary
$totalObtained = array_sum(array_column($marks, 'total_marks'));
$totalMax      = array_sum(array_column($marks, 'max_total'));
$percentage    = $totalMax > 0 ? round($totalObtained / $totalMax * 100, 2) : 0;
$sgpa          = round($percentage / 10, 2);
$hasFail       = in_array('FAIL', array_column($marks, 'status'));
$overallStatus = $hasFail ? 'FAIL' : 'PASS';
$grade         = $percentage>=90?'A+':($percentage>=80?'A':($percentage>=70?'B+':($percentage>=60?'B':($percentage>=50?'C':($percentage>=40?'D':'F')))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marksheet — Semester <?= $semester ?> — <?= htmlspecialchars($student['full_name']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Times New Roman', serif; background: #f4f6fb; color: #1a1a2e; }

        .screen-bar {
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
            color: white;
            padding: 12px 30px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .screen-bar a { color: white; text-decoration: none; font-size: 0.9rem; opacity: 0.85; }
        .screen-bar a:hover { opacity: 1; }
        .screen-bar .bar-right { display: flex; gap: 12px; }
        .btn-print {
            background: white; color: #4361ee; border: none; padding: 8px 20px;
            border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 0.9rem;
            display: flex; align-items: center; gap: 6px;
        }

        .marksheet-wrapper { max-width: 860px; margin: 30px auto; padding: 0 20px 40px; }

        .marksheet {
            background: white;
            border: 3px double #1a1a2e;
            padding: 36px 40px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
        }

        /* Header */
        .ms-header { text-align: center; border-bottom: 3px double #1a1a2e; padding-bottom: 16px; margin-bottom: 18px; }
        .ms-header .institution { font-size: 1.5rem; font-weight: 800; letter-spacing: 1px; color: #1a1a2e; text-transform: uppercase; }
        .ms-header .dept-line { font-size: 0.95rem; color: #555; margin-top: 3px; }
        .ms-header .title-line {
            margin-top: 12px; font-size: 1.15rem; font-weight: 700; letter-spacing: 2px;
            border: 2px solid #1a1a2e; display: inline-block; padding: 4px 22px; text-transform: uppercase;
        }

        /* Student Info Grid */
        .ms-info-grid { display: grid; grid-template-columns: 1fr 1fr; border: 1.5px solid #333; margin-bottom: 18px; }
        .ms-info-cell { padding: 8px 14px; font-size: 0.92rem; }
        .ms-info-cell:nth-child(odd) { border-right: 1px solid #999; }
        .ms-info-cell:not(:last-child):not(:nth-last-child(2)):not(:nth-last-child(-n+1)) { border-bottom: 1px solid #ddd; }
        .ms-info-cell:not(:last-child) { border-bottom: 1px solid #ddd; }
        .ms-info-label { color: #666; font-size: 0.82rem; display: block; margin-bottom: 2px; }
        .ms-info-value { font-weight: 700; color: #1a1a2e; }

        /* Marks Table */
        .ms-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; margin-bottom: 18px; }
        .ms-table th { background: #1a1a2e; color: white; padding: 10px 8px; text-align: center; border: 1px solid #444; font-weight: 600; }
        .ms-table th.left { text-align: left; }
        .ms-table td { padding: 9px 8px; border: 1px solid #ccc; text-align: center; }
        .ms-table td.left { text-align: left; }
        .ms-table tbody tr:nth-child(even) { background: #f9f9f9; }
        .ms-table tbody tr:hover { background: #f0f4ff; }
        .ms-table tfoot td { font-weight: 700; background: #f0f0f0; border: 1px solid #888; }
        .fail-row { background: #fff5f5 !important; }

        .grade-span { font-weight: 800; color: #3949ab; }
        .pass-span { color: #2e7d32; font-weight: 700; }
        .fail-span { color: #c62828; font-weight: 700; }
        .ab-span { color: #e65100; font-weight: 700; }

        /* Summary Bar */
        .ms-summary {
            display: flex; justify-content: space-around; border: 2px solid #333;
            padding: 12px 10px; margin-bottom: 24px; text-align: center; flex-wrap: wrap; gap: 8px;
        }
        .ms-summary-item .label { font-size: 0.75rem; color: #666; margin-bottom: 4px; }
        .ms-summary-item .value { font-size: 1.25rem; font-weight: 800; color: #1a1a2e; }
        .ms-summary-item .value.pass { color: #2e7d32; }
        .ms-summary-item .value.fail { color: #c62828; }

        /* Signatures */
        .ms-signatures {
            display: flex; justify-content: space-around; margin-top: 36px; padding-top: 10px;
        }
        .sig-block { text-align: center; }
        .sig-line { border-top: 1.5px solid #333; width: 150px; margin: 0 auto 6px; }
        .sig-label { font-size: 0.85rem; color: #444; }

        .ms-footer { text-align: center; margin-top: 20px; font-size: 0.78rem; color: #888; border-top: 1px solid #eee; padding-top: 10px; }

        /* No results message */
        .no-results { text-align: center; padding: 40px; color: #888; }

        /* Print styles */
        @media print {
            .screen-bar { display: none !important; }
            body { background: white; }
            .marksheet-wrapper { margin: 0; padding: 0; }
            .marksheet { box-shadow: none; border: 3px double #000; }
            .ms-table th { background: #333 !important; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>

<!-- Screen navigation bar (hidden on print) -->
<div class="screen-bar">
    <a href="javascript:history.back()"><i class="fas fa-arrow-left"></i> Back</a>
    <span style="font-weight:700; font-size:1rem;"><i class="fas fa-file-alt"></i> Semester Marksheet</span>
    <div class="bar-right">
        <?php if ($role === 'admin'): ?>
        <a href="admindashboard.html"><i class="fas fa-tachometer-alt"></i> Admin</a>
        <?php else: ?>
        <a href="student-dashboard.html"><i class="fas fa-home"></i> Dashboard</a>
        <?php endif; ?>
        <button class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    </div>
</div>

<div class="marksheet-wrapper">
    <div class="marksheet">

        <!-- Header -->
        <div class="ms-header">
            <div class="institution">GradeSphere University</div>
            <div class="dept-line">Department of <?= htmlspecialchars($student['department'] ?: 'Academic Affairs') ?></div>
            <div class="title-line">Statement of Marks</div>
            <div style="margin-top:8px; font-size:0.88rem; color:#555;">Examination Year: <strong><?= $examYear ?></strong></div>
        </div>

        <!-- Student Info -->
        <div class="ms-info-grid">
            <div class="ms-info-cell">
                <span class="ms-info-label">Student Name</span>
                <span class="ms-info-value"><?= htmlspecialchars($student['full_name']) ?></span>
            </div>
            <div class="ms-info-cell">
                <span class="ms-info-label">Roll Number</span>
                <span class="ms-info-value"><?= htmlspecialchars($student['roll_number']) ?></span>
            </div>
            <div class="ms-info-cell">
                <span class="ms-info-label">Father's Name</span>
                <span class="ms-info-value"><?= htmlspecialchars($student['father_name'] ?: '—') ?></span>
            </div>
            <div class="ms-info-cell">
                <span class="ms-info-label">Registration No.</span>
                <span class="ms-info-value"><?= htmlspecialchars($student['registration_no'] ?: $student['roll_number']) ?></span>
            </div>
            <div class="ms-info-cell">
                <span class="ms-info-label">Course</span>
                <span class="ms-info-value"><?= htmlspecialchars($student['course_name']) ?> (<?= htmlspecialchars($student['course_code']) ?>)</span>
            </div>
            <div class="ms-info-cell">
                <span class="ms-info-label">Semester</span>
                <span class="ms-info-value">Semester <?= $semester ?></span>
            </div>
        </div>

        <?php if (empty($marks)): ?>
        <div class="no-results">
            <i class="fas fa-info-circle" style="font-size:2rem;margin-bottom:12px;color:#aaa;"></i>
            <p>No published results found for Semester <?= $semester ?>, Year <?= $examYear ?>.</p>
        </div>
        <?php else: ?>

        <!-- Marks Table -->
        <div style="overflow-x:auto; width:100%;">
            <table class="ms-table">
            <thead>
                <tr>
                    <th style="width:40px;">S.No</th>
                    <th style="width:90px;">Code</th>
                    <th class="left">Subject Name</th>
                    <th>Theory<br><small>(<?= $marks[0]['max_theory'] ?? 75 ?>)</small></th>
                    <th>Practical<br><small>(<?= $marks[0]['max_practical'] ?? 25 ?>)</small></th>
                    <th>Total<br><small>(100)</small></th>
                    <th>Grade</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($marks as $i => $m): ?>
                <tr class="<?= $m['status']==='FAIL' ? 'fail-row' : '' ?>">
                    <td><?= $i+1 ?></td>
                    <td><?= htmlspecialchars($m['subject_code']) ?></td>
                    <td class="left"><?= htmlspecialchars($m['subject_name']) ?></td>
                    <td><?= $m['attendance_status']==='ABSENT' ? '<span class="ab-span">AB</span>' : $m['theory_marks'] ?></td>
                    <td><?= $m['attendance_status']==='ABSENT' ? '<span class="ab-span">AB</span>' : $m['practical_marks'] ?></td>
                    <td><strong><?= $m['attendance_status']==='ABSENT' ? 0 : $m['total_marks'] ?></strong></td>
                    <td><span class="grade-span"><?= htmlspecialchars($m['grade'] ?: '—') ?></span></td>
                    <td><span class="<?= $m['status']==='PASS'?'pass-span':'fail-span' ?>"><?= $m['status'] ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" style="text-align:right;">Grand Total</td>
                    <td><strong><?= $totalObtained ?> / <?= $totalMax ?></strong></td>
                    <td><span class="grade-span"><?= $grade ?></span></td>
                    <td><span class="<?= $overallStatus==='PASS'?'pass-span':'fail-span' ?>"><?= $overallStatus ?></span></td>
                </tr>
            </tfoot>
        </table>
        </div>

        <!-- Summary Bar -->
        <div class="ms-summary">
            <div class="ms-summary-item">
                <div class="label">Total Marks</div>
                <div class="value"><?= $totalObtained ?> / <?= $totalMax ?></div>
            </div>
            <div class="ms-summary-item">
                <div class="label">Percentage</div>
                <div class="value"><?= $percentage ?>%</div>
            </div>
            <div class="ms-summary-item">
                <div class="label">SGPA</div>
                <div class="value"><?= $sgpa ?></div>
            </div>
            <div class="ms-summary-item">
                <div class="label">Grade</div>
                <div class="value"><?= $grade ?></div>
            </div>
            <div class="ms-summary-item">
                <div class="label">Result</div>
                <div class="value <?= strtolower($overallStatus) ?>"><?= $overallStatus ?></div>
            </div>
        </div>

        <?php endif; ?>

        <!-- Signatures -->
        <div class="ms-signatures">
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-label">Exam Controller</div>
            </div>
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-label">Head of Department</div>
            </div>
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-label">Principal / Registrar</div>
            </div>
        </div>

        <div class="ms-footer">
            This is a computer-generated document. No physical signature is required. &nbsp;|&nbsp;
            Generated on: <?= date('d M Y, h:i A') ?>
        </div>

    </div><!-- .marksheet -->
</div><!-- .marksheet-wrapper -->

</body>
</html>
