<?php
// ============================================================
//  student_api.php  –  Student-facing API
//  Actions: get_profile, get_results, get_semester_results,
//           check_result (public, no auth)
// ============================================================

require_once 'config.php';
header('Content-Type: application/json');
startSecureSession();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Public action – no auth needed
if ($action === 'check_result') {
    handleCheckResult();
    exit;
}

// Skip session check for landing page stats
$isPublicAction = (($_POST['action'] ?? $_GET['action'] ?? '') === 'get_public_stats');

if (!$isPublicAction) {
    startSecureSession();
    requireLogin('student');
}

try {
    $db = getDB();

    switch ($action) {

        // ── GET STUDENT PROFILE ──────────────────────────────
        case 'get_profile':
            $stmt = $db->prepare("
                SELECT s.*, c.name AS course_name, c.code AS course_code,
                       c.total_semesters, c.department, u.last_login
                FROM students s
                JOIN courses c ON s.course_id = c.id
                JOIN users u ON s.user_id = u.id
                WHERE s.user_id = ?
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $profile = $stmt->fetch();
            if (!$profile) jsonResponse(false, 'Profile not found');
            jsonResponse(true, 'Profile fetched', ['profile' => $profile]);
            break;

        // ── GET RESULTS FOR A SEMESTER ───────────────────────
        case 'get_results':
            $semester  = (int)($_GET['semester'] ?? $_SESSION['semester'] ?? 1);
            $studentId = $_SESSION['student_id'];

            // Auto-detect most recent exam year with published data
            $requestedYear = (int)($_GET['exam_year'] ?? 0);
            if ($requestedYear) {
                $year = $requestedYear;
            } else {
                $yrStmt = $db->prepare("SELECT exam_year FROM results WHERE student_id = ? AND semester = ? AND is_published = 1 ORDER BY exam_year DESC LIMIT 1");
                $yrStmt->execute([$studentId, $semester]);
                $latestYear = $yrStmt->fetchColumn();
                $year = $latestYear ? (int)$latestYear : (int)date('Y');
            }

            $stmt = $db->prepare("
                SELECT r.*, sub.code AS subject_code, sub.name AS subject_name,
                       sub.max_theory, sub.max_practical, sub.max_total,
                       r.attendance_status
                FROM results r
                JOIN subjects sub ON r.subject_id = sub.id
                WHERE r.student_id = ?
                  AND r.semester = ?
                  AND r.exam_year = ?
                  AND r.is_published = 1
                ORDER BY sub.code
            ");
            $stmt->execute([$studentId, $semester, $year]);
            $marks = $stmt->fetchAll();

            if (empty($marks)) {
                jsonResponse(false, 'No published results found for this semester and year');
            }

            // Summary calculations
            $totalObtained = array_sum(array_column($marks, 'total_marks'));
            $totalMax      = array_sum(array_column($marks, 'max_total'));
            $percentage    = $totalMax > 0 ? round($totalObtained / $totalMax * 100, 2) : 0;
            $sgpa          = calculateSGPA($percentage);
            $overallGrade  = calculateGrade((int)round($totalObtained / max(count($marks), 1)));
            $hasFail       = in_array('FAIL', array_column($marks, 'status'));

            logActivity($_SESSION['user_id'], $_SESSION['username'], 'student', "VIEW_RESULT: sem=$semester year=$year");

            jsonResponse(true, 'Results fetched', [
                'marks'          => $marks,
                'summary'        => [
                    'total_obtained' => $totalObtained,
                    'total_max'      => $totalMax,
                    'percentage'     => $percentage,
                    'sgpa'           => $sgpa,
                    'grade'          => $overallGrade,
                    'status'         => $hasFail ? 'FAIL' : 'PASS',
                ]
            ]);
            break;

        // ── GET ALL SEMESTERS SUMMARY (also aliased as get_semester_summary) ───
        case 'get_semester_summary':
        case 'get_all_semesters':
            $studentId = $_SESSION['student_id'];

            $stmt = $db->prepare("
                SELECT r.semester, r.exam_year,
                       SUM(r.total_marks) AS obtained,
                       SUM(sub.max_total) AS max_marks,
                       ROUND(SUM(r.total_marks) / SUM(sub.max_total) * 100, 2) AS percentage,
                       SUM(IF(r.status = 'FAIL', 1, 0)) AS fail_count,
                       COUNT(r.id) AS subject_count
                FROM results r
                JOIN subjects sub ON r.subject_id = sub.id
                WHERE r.student_id = ? AND r.is_published = 1
                GROUP BY r.semester, r.exam_year
                ORDER BY r.semester ASC
            ");
            $stmt->execute([$studentId]);
            $semesters = $stmt->fetchAll();

            // Add SGPA to each semester
            foreach ($semesters as &$sem) {
                $sem['sgpa'] = round(floatval($sem['percentage']) / 10, 2);
                $sem['status'] = ($sem['fail_count'] == 0) ? 'PASS' : 'FAIL';
                $grade = floatval($sem['percentage']);
                $sem['grade'] = $grade >= 90 ? 'A+' : ($grade >= 80 ? 'A' : ($grade >= 70 ? 'B+' : ($grade >= 60 ? 'B' : ($grade >= 50 ? 'C' : ($grade >= 40 ? 'D' : 'F')))));
            }
            unset($sem);

            // Compute CGPA
            $cgpa = 0;
            if (count($semesters) > 0) {
                $cgpa = round(array_sum(array_column($semesters, 'percentage')) / count($semesters) / 10, 2);
            }

            jsonResponse(true, 'Semester summary fetched', [
                'semesters' => $semesters,
                'cgpa'      => $cgpa,
            ]);
            break;

        case 'get_public_stats':
            $stats = [];
            $stats['total_students'] = $db->query("SELECT COUNT(*) FROM students")->fetchColumn();
            $stats['total_courses']  = $db->query("SELECT COUNT(*) FROM courses")->fetchColumn();
            $stats['total_subjects'] = $db->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
            $stats['total_results']  = $db->query("SELECT COUNT(*) FROM results WHERE is_published = 1")->fetchColumn();
            jsonResponse(true, 'Public stats fetched', $stats);
            break;

        // ── GET STUDENT CGPA ──────────────────────────────────
        case 'get_cgpa':
            $studentId = $_SESSION['student_id'];
            $stmt = $db->prepare("
                SELECT ROUND(AVG(perc)/10,2) AS cgpa,
                       ROUND(MAX(perc)/10,2) AS best_sgpa,
                       COUNT(*) AS sem_count
                FROM (
                    SELECT r.semester,
                           ROUND(SUM(r.total_marks)/SUM(sub.max_total)*100,2) AS perc
                    FROM results r JOIN subjects sub ON r.subject_id = sub.id
                    WHERE r.student_id = ? AND r.is_published = 1
                    GROUP BY r.semester
                ) sem_perc
            ");
            $stmt->execute([$studentId]);
            $row = $stmt->fetch();
            jsonResponse(true, 'CGPA fetched', [
                'cgpa'      => $row['cgpa'] ?? 0,
                'best_sgpa' => $row['best_sgpa'] ?? 0,
                'sem_count' => $row['sem_count'] ?? 0,
            ]);
            break;

        default:
            jsonResponse(false, "Unknown action: $action");
    }

} catch (PDOException $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage());
}

// ── PUBLIC: check_result (used by check-result.html) ────────
function handleCheckResult(): void {
    $rollNo   = trim($_POST['roll_number'] ?? $_GET['roll_number'] ?? '');
    $course   = trim($_POST['course'] ?? $_GET['course'] ?? '');
    $semester = (int)($_POST['semester'] ?? $_GET['semester'] ?? 0);
    $requestedYear = (int)($_POST['exam_year'] ?? $_GET['exam_year'] ?? 0);

    if (empty($rollNo) || empty($course) || !$semester) {
        jsonResponse(false, 'Roll number, course and semester are required');
    }

    try {
        $db = getDB();

        // Find student
        $stmt = $db->prepare("
            SELECT s.*, c.name AS course_name, c.code AS course_code
            FROM students s
            JOIN courses c ON s.course_id = c.id
            WHERE s.roll_number = ? AND c.code = ?
        ");
        $stmt->execute([$rollNo, $course]);
        $student = $stmt->fetch();

        if (!$student) {
            jsonResponse(false, 'No student found with this roll number and course');
        }

        // Auto-detect year if not specified
        if ($requestedYear) {
            $year = $requestedYear;
        } else {
            $yrStmt = $db->prepare("SELECT exam_year FROM results WHERE student_id = ? AND semester = ? AND is_published = 1 ORDER BY exam_year DESC LIMIT 1");
            $yrStmt->execute([$student['id'], $semester]);
            $latestYear = $yrStmt->fetchColumn();
            $year = $latestYear ? (int)$latestYear : (int)date('Y');
        }

        // Fetch published results
        $rStmt = $db->prepare("
            SELECT r.*, sub.code AS subject_code, sub.name AS subject_name,
                   sub.max_theory, sub.max_practical, sub.max_total,
                   r.attendance_status
            FROM results r
            JOIN subjects sub ON r.subject_id = sub.id
            WHERE r.student_id = ? AND r.semester = ? AND r.exam_year = ? AND r.is_published = 1
            ORDER BY sub.code
        ");
        $rStmt->execute([$student['id'], $semester, $year]);
        $marks = $rStmt->fetchAll();

        if (empty($marks)) {
            jsonResponse(false, 'Results not yet published for this semester/year');
        }

        $totalObtained = array_sum(array_column($marks, 'total_marks'));
        $totalMax      = array_sum(array_column($marks, 'max_total'));
        $percentage    = $totalMax > 0 ? round($totalObtained / $totalMax * 100, 2) : 0;
        $sgpa          = calculateSGPA($percentage);
        $overallGrade  = calculateGrade((int)round($totalObtained / max(count($marks), 1)));
        $hasFail       = in_array('FAIL', array_column($marks, 'status'));

        jsonResponse(true, 'Results found', [
            'student' => [
                'name'        => $student['full_name'],
                'roll_number' => $student['roll_number'],
                'course'      => $student['course_name'],
                'semester'    => $semester,
                'father_name' => $student['father_name'],
                'reg_no'      => $student['registration_no'],
            ],
            'marks'  => $marks,
            'summary' => [
                'total_obtained' => $totalObtained,
                'total_max'      => $totalMax,
                'percentage'     => $percentage,
                'sgpa'           => $sgpa,
                'grade'          => $overallGrade,
                'status'         => $hasFail ? 'FAIL' : 'PASS',
            ]
        ]);

    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    }
}
?>
