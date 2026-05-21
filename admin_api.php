<?php
// ============================================================
//  admin_api.php  –  All admin CRUD actions via AJAX POST
//  Actions: add_student, edit_student, delete_student,
//           add_course, delete_course,
//           add_subject, delete_subject,
//           save_marks, publish_results,
//           get_students, get_courses, get_subjects,
//           get_marks, get_stats, get_activity_log
// ============================================================

require_once 'config.php';
header('Content-Type: application/json');
startSecureSession();
requireLogin('admin');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    $db = getDB();

    switch ($action) {

        // ── GET STATS (dashboard counters) ──────────────────
        case 'get_stats':
            $stats = [];
            $stats['total_students'] = $db->query("SELECT COUNT(*) FROM students")->fetchColumn();
            $stats['total_courses'] = $db->query("SELECT COUNT(*) FROM courses")->fetchColumn();
            $stats['total_subjects'] = $db->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
            $stats['total_results'] = $db->query("SELECT COUNT(*) FROM results WHERE is_published = 1")->fetchColumn();
            $stats['pass_count'] = $db->query("SELECT COUNT(DISTINCT student_id) FROM results WHERE status='PASS' AND is_published=1 AND attendance_status='PRESENT'")->fetchColumn();
            $stats['fail_count'] = $db->query("SELECT COUNT(DISTINCT student_id) FROM results WHERE status='FAIL' AND is_published=1 AND attendance_status='PRESENT'")->fetchColumn();
            $stats['absent_count'] = $db->query("SELECT COUNT(DISTINCT student_id) FROM results WHERE attendance_status='ABSENT' AND is_published=1")->fetchColumn();
            jsonResponse(true, 'Stats fetched', $stats);
            break;

        // ── GET ALL STUDENTS ────────────────────────────────
        case 'get_students':
            try {
                $db->exec("ALTER TABLE students ADD COLUMN plain_password VARCHAR(255) DEFAULT 'pass123'");
            } catch (Exception $e) {
            }
            $stmt = $db->query("
                SELECT s.*, c.name AS course_name, c.code AS course_code,
                       c.department, c.total_semesters,
                       u.username, u.last_login, u.is_active
                FROM students s
                JOIN courses c ON s.course_id = c.id
                JOIN users u ON s.user_id = u.id
                ORDER BY s.created_at DESC
            ");
            jsonResponse(true, 'Students fetched', ['students' => $stmt->fetchAll()]);
            break;

        // ── ADD STUDENT ─────────────────────────────────────
        case 'add_student':
            $name = trim($_POST['full_name'] ?? '');
            $courseId = (int) ($_POST['course_id'] ?? 0);
            $semester = (int) ($_POST['semester'] ?? 1);
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $dob = $_POST['dob'] ?? null;
            $address = trim($_POST['address'] ?? '');
            $fatherName = trim($_POST['father_name'] ?? '');
            $motherName = trim($_POST['mother_name'] ?? '');

            if (empty($name) || !$courseId) {
                jsonResponse(false, 'Name and course are required');
            }

            $courseRow = $db->prepare("SELECT code FROM courses WHERE id = ?");
            $courseRow->execute([$courseId]);
            $course = $courseRow->fetch();
            if (!$course)
                jsonResponse(false, 'Invalid course');

            $countStmt = $db->prepare("SELECT COUNT(*) FROM students WHERE course_id = ?");
            $countStmt->execute([$courseId]);
            $count = (int) $countStmt->fetchColumn() + 1;
            $rollNumber = strtoupper($course['code']) . date('Y') . str_pad($count, 3, '0', STR_PAD_LEFT);

            $checkRoll = $db->prepare("SELECT id FROM students WHERE roll_number = ?");
            $checkRoll->execute([$rollNumber]);
            if ($checkRoll->fetch()) {
                $rollNumber .= rand(1, 9);
            }

            $password = trim($_POST['password'] ?? '');
            if (empty($password)) {
                $password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890@#*'), 0, 8);
            }
           $hashedPw = $password;
            // $hashedPw = password_hash($password, PASSWORD_DEFAULT);
            $registrationNo = 'REG' . date('Y') . str_pad($count, 3, '0', STR_PAD_LEFT);

            try {
                $db->exec("ALTER TABLE students ADD COLUMN plain_password VARCHAR(255) DEFAULT 'pass123'");
            } catch (Exception $e) {
            }

            $db->beginTransaction();

            $stmtUser = $db->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'student')");
            $stmtUser->execute([$rollNumber, $hashedPw]);
            $userId = $db->lastInsertId();

            $stmtStu = $db->prepare("
                INSERT INTO students
                  (user_id, roll_number, full_name, father_name, mother_name, email, phone, dob, address, course_id, current_semester, enrollment_year, registration_no, plain_password)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtStu->execute([
                $userId,
                $rollNumber,
                $name,
                $fatherName,
                $motherName,
                $email,
                $phone,
                $dob ?: null,
                $address,
                $courseId,
                $semester,
                date('Y'),
                $registrationNo,
                $password
            ]);
            $studentId = $db->lastInsertId();

            $db->commit();

            logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "ADDED_STUDENT: $rollNumber");

            jsonResponse(true, 'Student added successfully', [
                'roll_number' => $rollNumber,
                'password' => $password,
                'student_id' => $studentId
            ]);
            break;

        // ── EDIT STUDENT ────────────────────────────────────
        case 'edit_student':
            $studentId = (int) ($_POST['student_id'] ?? 0);
            if (!$studentId)
                jsonResponse(false, 'Student ID required');

            $fields = [];
            $params = [];
            $allowed = ['full_name', 'father_name', 'mother_name', 'email', 'phone', 'dob', 'address', 'current_semester', 'course_id'];
            foreach ($allowed as $f) {
                if (isset($_POST[$f])) {
                    $fields[] = "$f = ?";
                    $params[] = $_POST[$f];
                }
            }
            if (empty($fields))
                jsonResponse(false, 'Nothing to update');
            $params[] = $studentId;

            $db->prepare("UPDATE students SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
            logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "EDITED_STUDENT: $studentId");
            jsonResponse(true, 'Student updated');
            break;

        // ── DELETE STUDENT ───────────────────────────────────
        case 'delete_student':
            $studentId = (int) ($_POST['student_id'] ?? 0);
            if (!$studentId)
                jsonResponse(false, 'Student ID required');

            $row = $db->prepare("SELECT user_id, roll_number FROM students WHERE id = ?");
            $row->execute([$studentId]);
            $stu = $row->fetch();
            if (!$stu)
                jsonResponse(false, 'Student not found');

            $db->beginTransaction();
            $db->prepare("DELETE FROM results WHERE student_id = ?")->execute([$studentId]);
            $db->prepare("DELETE FROM students WHERE id = ?")->execute([$studentId]);
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$stu['user_id']]);
            $db->commit();

            logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "DELETED_STUDENT: " . $stu['roll_number']);
            jsonResponse(true, 'Student deleted');
            break;

        // ── GET COURSES ──────────────────────────────────────
        case 'get_courses':
            $courses = $db->query("SELECT *, (SELECT COUNT(*) FROM students WHERE course_id = courses.id) AS student_count FROM courses ORDER BY code")->fetchAll();
            jsonResponse(true, 'Courses fetched', ['courses' => $courses]);
            break;

        // ── ADD COURSE ───────────────────────────────────────
        case 'add_course':
            $code = strtoupper(trim($_POST['code'] ?? ''));
            $name = trim($_POST['name'] ?? '');
            $dur = (int) ($_POST['duration'] ?? 3);
            $dept = trim($_POST['department'] ?? '');
            $sems = (int) ($_POST['semesters'] ?? 6);

            if (empty($code) || empty($name))
                jsonResponse(false, 'Code and name required');

            $check = $db->prepare("SELECT id FROM courses WHERE code = ?");
            $check->execute([$code]);
            if ($check->fetch())
                jsonResponse(false, "Course code '$code' already exists.");

            $db->prepare("INSERT INTO courses (code, name, duration_years, department, total_semesters) VALUES (?,?,?,?,?)")
                ->execute([$code, $name, $dur, $dept, $sems]);
            logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "ADDED_COURSE: $code");
            jsonResponse(true, 'Course added', ['course_id' => $db->lastInsertId()]);
            break;

        // ── DELETE COURSE ────────────────────────────────────
        case 'delete_course':
            $courseId = (int) ($_POST['course_id'] ?? 0);
            $check = $db->prepare("SELECT COUNT(*) FROM students WHERE course_id = ?");
            $check->execute([$courseId]);
            if ($check->fetchColumn() > 0) {
                jsonResponse(false, 'Cannot delete course: students are enrolled in it');
            }
            $db->prepare("DELETE FROM subjects WHERE course_id = ?")->execute([$courseId]);
            $db->prepare("DELETE FROM courses WHERE id = ?")->execute([$courseId]);
            logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "DELETED_COURSE: $courseId");
            jsonResponse(true, 'Course deleted');
            break;

        // ── GET SUBJECTS ─────────────────────────────────────
        case 'get_subjects':
            $courseId = (int) ($_GET['course_id'] ?? $_POST['course_id'] ?? 0);
            $semester = (int) ($_GET['semester'] ?? $_POST['semester'] ?? 0);

            $sql = "SELECT sub.*, c.code AS course_code, c.name AS course_name
                    FROM subjects sub JOIN courses c ON sub.course_id = c.id WHERE 1=1";
            $params = [];
            if ($courseId) {
                $sql .= " AND sub.course_id = ?";
                $params[] = $courseId;
            }
            if ($semester) {
                $sql .= " AND sub.semester = ?";
                $params[] = $semester;
            }
            $sql .= " ORDER BY sub.code";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            jsonResponse(true, 'Subjects fetched', ['subjects' => $stmt->fetchAll()]);
            break;

        // ── ADD SUBJECT ──────────────────────────────────────
        case 'add_subject':
            $code = strtoupper(trim($_POST['code'] ?? ''));
            $name = trim($_POST['name'] ?? '');
            $courseId = (int) ($_POST['course_id'] ?? 0);
            $semester = (int) ($_POST['semester'] ?? 1);
            $maxTh = (int) ($_POST['max_theory'] ?? 75);
            $maxPr = (int) ($_POST['max_practical'] ?? 25);
            $passing = (int) ($_POST['passing_marks'] ?? 40);

            if (empty($code) || empty($name) || !$courseId)
                jsonResponse(false, 'Code, name and course required');

            $check = $db->prepare("SELECT id FROM subjects WHERE code = ?");
            $check->execute([$code]);
            if ($check->fetch())
                jsonResponse(false, "Subject code '$code' already exists.");

            $db->prepare("INSERT INTO subjects (code, name, course_id, semester, max_theory, max_practical, max_total, passing_marks) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$code, $name, $courseId, $semester, $maxTh, $maxPr, $maxTh + $maxPr, $passing]);
            logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "ADDED_SUBJECT: $code");
            jsonResponse(true, 'Subject added', ['subject_id' => $db->lastInsertId()]);
            break;

        // ── DELETE SUBJECT ────────────────────────────────────
        case 'delete_subject':
            $subjectId = (int) ($_POST['subject_id'] ?? 0);
            $db->prepare("DELETE FROM results WHERE subject_id = ?")->execute([$subjectId]);
            $db->prepare("DELETE FROM subjects WHERE id = ?")->execute([$subjectId]);
            logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "DELETED_SUBJECT: $subjectId");
            jsonResponse(true, 'Subject deleted');
            break;

        // ── SAVE / UPDATE MARKS ──────────────────────────────
        case 'save_marks':
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $subjectId = (int) ($_POST['subject_id'] ?? 0);
            $semester = (int) ($_POST['semester'] ?? 0);
            $examYear = (int) ($_POST['exam_year'] ?? date('Y'));
            $theory = (int) ($_POST['theory_marks'] ?? 0);
            $practical = (int) ($_POST['practical_marks'] ?? 0);

            if (!$studentId || !$subjectId || !$semester)
                jsonResponse(false, 'Student, subject and semester required');

            $sub = $db->prepare("SELECT max_theory, max_practical FROM subjects WHERE id = ?");
            $sub->execute([$subjectId]);
            $subRow = $sub->fetch();
            if ($subRow) {
                if ($theory > $subRow['max_theory'])
                    jsonResponse(false, "Theory marks exceed max ({$subRow['max_theory']})");
                if ($practical > $subRow['max_practical'])
                    jsonResponse(false, "Practical marks exceed max ({$subRow['max_practical']})");
            }

            $db->prepare("
                INSERT INTO results (student_id, subject_id, semester, exam_year, theory_marks, practical_marks, entered_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  theory_marks = VALUES(theory_marks),
                  practical_marks = VALUES(practical_marks),
                  entered_by = VALUES(entered_by),
                  entered_at = NOW()
            ")->execute([$studentId, $subjectId, $semester, $examYear, $theory, $practical, $_SESSION['user_id']]);

            logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "SAVED_MARKS: student=$studentId subject=$subjectId");
            jsonResponse(true, 'Marks saved successfully');
            break;

        // ── PUBLISH RESULTS ──────────────────────────────────
        case 'publish_results':
            $semester = (int) ($_POST['semester'] ?? 0);
            $year = (int) ($_POST['exam_year'] ?? date('Y'));

            if ($semester) {
                $db->prepare("UPDATE results SET is_published = 1 WHERE semester = ? AND exam_year = ?")
                    ->execute([$semester, $year]);
            } else {
                $db->prepare("UPDATE results SET is_published = 1 WHERE exam_year = ?")->execute([$year]);
            }
            logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "PUBLISHED_RESULTS: sem=$semester year=$year");
            jsonResponse(true, 'Results published successfully');
            break;

        // ── GET MARKS (for a student/semester) ───────────────
        case 'get_marks':
            $studentId = (int) ($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
            $semester = (int) ($_GET['semester'] ?? $_POST['semester'] ?? 0);
            $year = (int) ($_GET['exam_year'] ?? date('Y'));

            $sql = "SELECT r.*, sub.code AS subject_code, sub.name AS subject_name,
                           sub.max_theory, sub.max_practical, sub.max_total
                    FROM results r JOIN subjects sub ON r.subject_id = sub.id
                    WHERE r.student_id = ? AND r.exam_year = ?";
            $params = [$studentId, $year];
            if ($semester) {
                $sql .= " AND r.semester = ?";
                $params[] = $semester;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $marks = $stmt->fetchAll();

            $totalObtained = array_sum(array_column($marks, 'total_marks'));
            $totalMax = array_sum(array_column($marks, 'max_total'));
            $percentage = $totalMax > 0 ? round($totalObtained / $totalMax * 100, 2) : 0;
            $sgpa = calculateSGPA($percentage);
            $overallGrade = calculateGrade($totalMax > 0 ? (int) round($totalObtained / count($marks)) : 0);
            $hasFail = in_array('FAIL', array_column($marks, 'status'));

            jsonResponse(true, 'Marks fetched', [
                'marks' => $marks,
                'total_obtained' => $totalObtained,
                'total_max' => $totalMax,
                'percentage' => $percentage,
                'sgpa' => $sgpa,
                'overall_grade' => $overallGrade,
                'overall_status' => $hasFail ? 'FAIL' : 'PASS',
            ]);
            break;

        // ── GET ACTIVITY LOG ─────────────────────────────────
        case 'get_activity_log':
            $limit = min((int) ($_GET['limit'] ?? 50), 200);
            $logs = $db->query("SELECT * FROM activity_log ORDER BY created_at DESC LIMIT $limit")->fetchAll();
            jsonResponse(true, 'Logs fetched', ['logs' => $logs]);
            break;

        // ── GET STUDENT DETAILS ──────────────────────────────
        case 'get_student':
            $studentId = (int) ($_GET['student_id'] ?? 0);
            $rollNo = trim($_GET['roll_number'] ?? '');

            $sql = "SELECT s.*, c.name AS course_name, c.code AS course_code,
                           c.department, c.total_semesters,
                           u.last_login, u.is_active
                    FROM students s JOIN courses c ON s.course_id = c.id JOIN users u ON s.user_id = u.id
                    WHERE ";
            $params = [];
            if ($studentId) {
                $sql .= "s.id = ?";
                $params[] = $studentId;
            } elseif ($rollNo) {
                $sql .= "s.roll_number = ?";
                $params[] = $rollNo;
            } else
                jsonResponse(false, 'Provide student_id or roll_number');

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $student = $stmt->fetch();
            if (!$student)
                jsonResponse(false, 'Student not found');
            jsonResponse(true, 'Student found', ['student' => $student]);
            break;

        // ── RESET STUDENT PASSWORD ───────────────────────────
        case 'reset_password':
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $newPassword = trim($_POST['new_password'] ?? 'pass123');

            $row = $db->prepare("SELECT user_id FROM students WHERE id = ?");
            $row->execute([$studentId]);
            $stu = $row->fetch();
            if (!$stu)
                jsonResponse(false, 'Student not found');

            $db->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $stu['user_id']]);
            logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "RESET_PASSWORD: student=$studentId");
            jsonResponse(true, 'Password reset successfully');
            break;

        // ── IMPORT STUDENTS (Bulk) ──────────────────────────
        case 'import_students':
            $studentsRaw = $_POST['students'] ?? '[]';
            $students = json_decode($studentsRaw, true) ?? [];
            if (empty($students))
                jsonResponse(false, 'No student data received or invalid format');

            $defaultCourseId = (int) ($_POST['course_id'] ?? 0);
            $allCourses = $db->query("SELECT id, code FROM courses")->fetchAll();
            $courseMap = [];
            foreach ($allCourses as $c) {
                $courseMap[strtoupper($c['code'])] = $c;
            }

            $success = 0;
            $failed = 0;
            $errors = [];
            $year = date('Y');
            $courseCounts = [];

            $db->beginTransaction();
            try {
                $stmtUser = $db->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'student')");
                $stmtStu = $db->prepare("
                    INSERT INTO students 
                      (user_id, roll_number, full_name, father_name, mother_name, email, phone, dob, address, course_id, current_semester, enrollment_year, registration_no, plain_password) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($students as $index => $s) {
                    $name = trim($s['full_name'] ?? '');
                    if (empty($name)) {
                        $failed++;
                        $errors[] = "Row " . ($index + 1) . ": Name is empty";
                        continue;
                    }

                    $rowCourseCode = strtoupper(trim($s['course_code'] ?? $s['course'] ?? ''));
                    $courseId = $defaultCourseId;
                    $courseCode = '';

                    if ($rowCourseCode && isset($courseMap[$rowCourseCode])) {
                        $courseId = (int) $courseMap[$rowCourseCode]['id'];
                        $courseCode = $courseMap[$rowCourseCode]['code'];
                    } elseif ($defaultCourseId) {
                        foreach ($courseMap as $code => $info) {
                            if ((int) $info['id'] === $defaultCourseId) {
                                $courseCode = $code;
                                break;
                            }
                        }
                    }

                    if (!$courseId) {
                        $failed++;
                        $errors[] = "Row " . ($index + 1) . ": No valid course (code: $rowCourseCode)";
                        continue;
                    }

                    if (!isset($courseCounts[$courseId])) {
                        $countStmt = $db->prepare("SELECT COUNT(*) FROM students WHERE course_id = ?");
                        $countStmt->execute([$courseId]);
                        $courseCounts[$courseId] = (int) $countStmt->fetchColumn();
                    }
                    $courseCounts[$courseId]++;
                    $rollNumber = strtoupper($courseCode) . $year . str_pad($courseCounts[$courseId], 3, '0', STR_PAD_LEFT);
                    $registrationNo = 'REG' . $year . str_pad($courseCounts[$courseId], 3, '0', STR_PAD_LEFT);

                    $email = trim($s['email'] ?? '');
                    $password = !empty($s['password']) ? trim($s['password']) : bin2hex(random_bytes(4));
                    $semester = (int) ($s['semester'] ?? $_POST['default_semester'] ?? 1);
$stmtUser->execute([$rollNumber, $password]);
                    //$stmtUser->execute([$rollNumber, password_hash($password, PASSWORD_DEFAULT)]);
                    $userId = $db->lastInsertId();

                    $stmtStu->execute([
                        $userId,
                        $rollNumber,
                        $name,
                        $s['father_name'] ?? null,
                        $s['mother_name'] ?? null,
                        $email ?: null,
                        $s['phone'] ?? null,
                        !empty($s['dob']) ? $s['dob'] : null,
                        $s['address'] ?? null,
                        $courseId,
                        $semester,
                        $year,
                        $registrationNo,
                        $password
                    ]);
                    $success++;
                }
                $db->commit();
                logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "BULK_IMPORT_STUDENTS: $success added, $failed failed");
                jsonResponse(true, "Import completed", ['success_count' => $success, 'failed_count' => $failed, 'errors' => $errors]);
            } catch (Exception $e) {
                $db->rollBack();
                jsonResponse(false, 'Transaction failed: ' . $e->getMessage());
            }
            break;

        // ── RESET ALL STUDENTS (Cleanup) ───────────────────
        case 'reset_all_students':
            $db->beginTransaction();
            try {
                $db->exec("SET FOREIGN_KEY_CHECKS=0;");
                $db->exec("DELETE FROM results;");
                $db->exec("DELETE FROM students;");
                $db->exec("DELETE FROM users WHERE role = 'student';");
                $db->exec("SET FOREIGN_KEY_CHECKS=1;");
                $db->commit();
                logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "RESET_STUDENT_DATABASE");
                jsonResponse(true, 'Student database has been completely reset.');
            } catch (Exception $e) {
                $db->rollBack();
                jsonResponse(false, 'Reset failed: ' . $e->getMessage());
            }
            break;

        // ── PUBLISH RESULTS ─────────────────────────────────
        case 'publish_results':
            $examYear = (int) ($_POST['exam_year'] ?? date('Y'));
            $semester = (int) ($_POST['semester'] ?? 0);

            $sql = "UPDATE results SET is_published = 1 WHERE exam_year = ?";
            $params = [$examYear];

            if ($semester > 0) {
                $sql .= " AND semester = ?";
                $params[] = $semester;
            }

            $db->beginTransaction();
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $affected = $stmt->rowCount();

                $db->commit();
                logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "PUBLISHED_RESULTS: year=$examYear, sem=$semester, count=$affected");
                jsonResponse(true, "Results published! $affected result(s) updated.", ['published_count' => $affected]);
            } catch (Exception $e) {
                $db->rollBack();
                jsonResponse(false, 'Publish failed: ' . $e->getMessage());
            }
            break;

        // ── IMPORT SUBJECTS (Bulk) ──────────────────────────────
        case 'import_subjects':
            $subjectsRaw = $_POST['subjects'] ?? '[]';
            $subjectsList = json_decode($subjectsRaw, true) ?? [];
            if (empty($subjectsList))
                jsonResponse(false, 'No subjects data received');

            $defaultCourseId = (int) ($_POST['course_id'] ?? 0);
            $success = 0;
            $skipped = 0;

            $db->beginTransaction();
            try {
                $stmtCheck = $db->prepare("SELECT id FROM subjects WHERE code = ?");
                $stmtCourse = $db->prepare("SELECT id FROM courses WHERE code = ?");
                $stmtInsert = $db->prepare("
                    INSERT INTO subjects (code, name, course_id, semester, max_theory, max_practical, max_total, passing_marks)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($subjectsList as $s) {
                    $code = strtoupper(trim($s['code'] ?? $s['subject_code'] ?? ''));
                    $name = trim($s['name'] ?? $s['subject_name'] ?? '');
                    if (empty($code) || empty($name)) {
                        $skipped++;
                        continue;
                    }

                    $stmtCheck->execute([$code]);
                    if ($stmtCheck->fetch()) {
                        $skipped++;
                        continue;
                    }

                    $courseId = $defaultCourseId;
                    $courseCode = strtoupper(trim($s['course_code'] ?? $s['course'] ?? ''));
                    if ($courseCode) {
                        $stmtCourse->execute([$courseCode]);
                        $courseRow = $stmtCourse->fetch();
                        if ($courseRow)
                            $courseId = (int) $courseRow['id'];
                    }
                    if (!$courseId) {
                        $skipped++;
                        continue;
                    }

                    $sem = (int) ($s['semester'] ?? 1);
                    $maxTh = (int) ($s['max_theory'] ?? 75);
                    $maxPr = (int) ($s['max_practical'] ?? 25);
                    $maxTotal = $maxTh + $maxPr;
                    $passing = (int) ($s['passing_marks'] ?? 40);

                    $stmtInsert->execute([$code, $name, $courseId, $sem, $maxTh, $maxPr, $maxTotal, $passing]);
                    $success++;
                }
                $db->commit();
                logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "IMPORTED_SUBJECTS: $success added, $skipped skipped");
                jsonResponse(true, "Subjects imported: $success added, $skipped skipped", ['success_count' => $success, 'skipped_count' => $skipped]);
            } catch (Exception $e) {
                $db->rollBack();
                jsonResponse(false, 'Subjects import failed: ' . $e->getMessage());
            }
            break;

        // ── IMPORT MARKS (Bulk) ─────────────────────────────
        case 'import_marks':
            $marksRaw = $_POST['marks'] ?? '[]';
            $marksList = json_decode($marksRaw, true) ?? [];
            if (empty($marksList))
                jsonResponse(false, 'No marks data received or invalid format');

            $examYear = (int) ($_POST['exam_year'] ?? date('Y'));
            $success = 0;
            $skipped = 0;
            $failed = 0;
            $errors = [];

            $db->beginTransaction();
            try {
                $checkStmt = $db->prepare("
                    SELECT id FROM results 
                    WHERE student_id = ? AND subject_id = ? AND exam_year = ? AND semester = ?
                ");

                $stmtMarks = $db->prepare("
                    INSERT INTO results (student_id, subject_id, semester, exam_year, theory_marks, practical_marks, attendance_status, entered_by)
                    VALUES ((SELECT id FROM students WHERE roll_number = ? LIMIT 1), (SELECT id FROM subjects WHERE code = ? LIMIT 1), ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE theory_marks = VALUES(theory_marks), practical_marks = VALUES(practical_marks), attendance_status = VALUES(attendance_status), entered_by = VALUES(entered_by), entered_at = NOW()
                ");

                foreach ($marksList as $m) {
                    $rollNo = trim($m['roll_number'] ?? '');
                    $subCode = trim($m['subject_code'] ?? '');
                    $semester = (int) ($m['semester'] ?? 1);

                    if (empty($rollNo) || empty($subCode)) {
                        $failed++;
                        $errors[] = "Missing roll_number or subject_code";
                        continue;
                    }

                    $rowYear = (int) ($m['exam_year'] ?? $m['year'] ?? $examYear);
                    $attendanceStatus = strtoupper(trim($m['attendance_status'] ?? 'PRESENT'));
                    if ($attendanceStatus !== 'ABSENT')
                        $attendanceStatus = 'PRESENT';

                    $studentStmt = $db->prepare("SELECT id FROM students WHERE roll_number = ? LIMIT 1");
                    $studentStmt->execute([$rollNo]);
                    $student = $studentStmt->fetch();

                    $subjectStmt = $db->prepare("SELECT id FROM subjects WHERE code = ? LIMIT 1");
                    $subjectStmt->execute([$subCode]);
                    $subject = $subjectStmt->fetch();

                    if (!$student) {
                        $failed++;
                        $errors[] = "Student not found: $rollNo";
                        continue;
                    }

                    if (!$subject) {
                        $failed++;
                        $errors[] = "Subject not found: $subCode";
                        continue;
                    }

                    $checkStmt->execute([$student['id'], $subject['id'], $rowYear, $semester]);
                    if ($checkStmt->fetch()) {
                        $skipped++;
                        continue;
                    }

                    $stmtMarks->execute([
                        $rollNo,
                        $subCode,
                        $semester,
                        $rowYear,
                        (int) ($m['theory'] ?? 0),
                        (int) ($m['practical'] ?? 0),
                        $attendanceStatus,
                        $_SESSION['user_id']
                    ]);
                    $success++;
                }
                $db->commit();

                $message = "Marks import completed: $success added";
                if ($skipped > 0)
                    $message .= ", $skipped skipped (already exist)";
                if ($failed > 0)
                    $message .= ", $failed failed";

                jsonResponse(true, $message, [
                    'success_count' => $success,
                    'skipped_count' => $skipped,
                    'failed_count' => $failed,
                    'errors' => $errors
                ]);
            } catch (Exception $e) {
                $db->rollBack();
                jsonResponse(false, 'Marks import failed: ' . $e->getMessage());
            }
            break;

        // ── SEARCH STUDENTS ─────────────────────────────────
        case 'search_students':
            $query = trim($_GET['q'] ?? $_POST['q'] ?? '');
            if (strlen($query) < 2)
                jsonResponse(false, 'Search query too short');

            $stmt = $db->prepare("
                SELECT s.*, c.name AS course_name, c.code AS course_code,
                       u.username, u.is_active
                FROM students s
                JOIN courses c ON s.course_id = c.id
                JOIN users u ON s.user_id = u.id
                WHERE s.full_name LIKE ? OR s.roll_number LIKE ? OR s.email LIKE ?
                ORDER BY s.full_name
                LIMIT 50
            ");
            $like = '%' . $query . '%';
            $stmt->execute([$like, $like, $like]);
            jsonResponse(true, 'Search results', ['students' => $stmt->fetchAll()]);
            break;

        // ── GET ABSENTEES ────────────────────────────────────
        case 'get_absentees':
            $stmt = $db->query("
                SELECT s.roll_number, s.full_name, c.code AS course_code,
                       r.semester, sub.code AS subject_code, sub.name AS subject_name,
                       r.exam_year
                FROM results r
                JOIN students s ON r.student_id = s.id
                JOIN courses c ON s.course_id = c.id
                JOIN subjects sub ON r.subject_id = sub.id
                WHERE r.attendance_status = 'ABSENT'
                ORDER BY s.full_name, sub.code
            ");
            jsonResponse(true, 'Absentees fetched', ['absentees' => $stmt->fetchAll()]);
            break;

        // ── GET STUDENTS BY COURSE + SEMESTER ───────────────
        case 'get_students_filtered':
            $courseId = (int)($_GET['course_id'] ?? $_POST['course_id'] ?? 0);
            $semester = (int)($_GET['semester']  ?? $_POST['semester']  ?? 0);
            $dept     = trim($_GET['department']  ?? $_POST['department']  ?? '');
            $search   = trim($_GET['q']  ?? $_POST['q']  ?? '');

            $sql = "SELECT s.*, c.name AS course_name, c.code AS course_code,
                           c.department, c.total_semesters,
                           u.username, u.last_login, u.is_active
                    FROM students s
                    JOIN courses c ON s.course_id = c.id
                    JOIN users u ON s.user_id = u.id
                    WHERE 1=1";
            $params = [];
            if ($courseId) { $sql .= " AND s.course_id = ?";        $params[] = $courseId; }
            if ($semester) { $sql .= " AND s.current_semester = ?"; $params[] = $semester; }
            if ($dept)     { $sql .= " AND c.department = ?";       $params[] = $dept; }
            if ($search) {
                $sql .= " AND (s.full_name LIKE ? OR s.roll_number LIKE ?)";
                $like = '%' . $search . '%';
                $params[] = $like; $params[] = $like;
            }
            $sql .= " ORDER BY s.roll_number ASC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            jsonResponse(true, 'Filtered students fetched', ['students' => $stmt->fetchAll()]);
            break;

        // ── GET MARKS FOR STUDENT (admin view, any semester) ─
        case 'get_student_marks':
            $studentId = (int)($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
            $semester  = (int)($_GET['semester']   ?? $_POST['semester']  ?? 0);
            if (!$studentId) jsonResponse(false, 'student_id required');

            $sql = "SELECT r.*, sub.code AS subject_code, sub.name AS subject_name,
                           sub.max_theory, sub.max_practical, sub.max_total
                    FROM results r
                    JOIN subjects sub ON r.subject_id = sub.id
                    WHERE r.student_id = ?";
            $params = [$studentId];
            if ($semester) { $sql .= " AND r.semester = ?"; $params[] = $semester; }
            $sql .= " ORDER BY r.semester ASC, sub.code ASC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $marks = $stmt->fetchAll();

            $totalObtained = array_sum(array_column($marks, 'total_marks'));
            $totalMax      = array_sum(array_column($marks, 'max_total'));
            $percentage    = $totalMax > 0 ? round($totalObtained / $totalMax * 100, 2) : 0;
            $sgpa          = calculateSGPA($percentage);
            $overallGrade  = calculateGrade($totalMax > 0 ? (int)round($totalObtained / max(count($marks),1)) : 0);
            $hasFail       = in_array('FAIL', array_column($marks, 'status'));

            jsonResponse(true, 'Marks fetched', [
                'marks'          => $marks,
                'total_obtained' => $totalObtained,
                'total_max'      => $totalMax,
                'percentage'     => $percentage,
                'sgpa'           => $sgpa,
                'overall_grade'  => $overallGrade,
                'overall_status' => $hasFail ? 'FAIL' : 'PASS',
            ]);
            break;

        // ── GET DEPARTMENTS ──────────────────────────────────
        case 'get_departments':
            $depts = $db->query("
                SELECT d.*, COUNT(c.id) AS course_count
                FROM departments d
                LEFT JOIN courses c ON c.department = d.name
                GROUP BY d.id
                ORDER BY d.name
            ")->fetchAll();
            // Fallback: if departments table missing, pull unique from courses
            if (empty($depts)) {
                $rows = $db->query("SELECT DISTINCT department FROM courses WHERE department IS NOT NULL AND department != '' ORDER BY department")->fetchAll();
                foreach ($rows as $r) {
                    $depts[] = ['id' => 0, 'name' => $r['department'], 'code' => '', 'course_count' => 1];
                }
            }
            jsonResponse(true, 'Departments fetched', ['departments' => $depts]);
            break;

        // ── ADD DEPARTMENT ────────────────────────────────────
        case 'add_department':
            $dName = trim($_POST['name'] ?? '');
            $dCode = strtoupper(trim($_POST['code'] ?? ''));
            $dHead = trim($_POST['head_name'] ?? '');
            if (empty($dName) || empty($dCode))
                jsonResponse(false, 'Department name and code required');
            try {
                $db->prepare("INSERT INTO departments (name, code, head_name) VALUES (?,?,?)")
                    ->execute([$dName, $dCode, $dHead]);
                logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "ADDED_DEPT: $dName");
                jsonResponse(true, 'Department added', ['dept_id' => $db->lastInsertId()]);
            } catch (Exception $e) {
                jsonResponse(false, 'Department already exists or error: ' . $e->getMessage());
            }
            break;

        // ── DELETE DEPARTMENT ─────────────────────────────────
        case 'delete_department':
            $deptId = (int)($_POST['dept_id'] ?? 0);
            $db->prepare("DELETE FROM departments WHERE id = ?")->execute([$deptId]);
            logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "DELETED_DEPT: $deptId");
            jsonResponse(true, 'Department deleted');
            break;

        // ── GET FULL STUDENT ACADEMIC HISTORY (semester-wise) ─
        case 'get_student_academic_history':
            $studentId = (int)($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
            if (!$studentId) jsonResponse(false, 'student_id required');

            // Student info
            $stuStmt = $db->prepare("
                SELECT s.*, c.name AS course_name, c.code AS course_code,
                       c.department, c.total_semesters, c.duration_years
                FROM students s JOIN courses c ON s.course_id = c.id
                WHERE s.id = ?
            ");
            $stuStmt->execute([$studentId]);
            $student = $stuStmt->fetch();
            if (!$student) jsonResponse(false, 'Student not found');

            // Semester-wise summary
            $semStmt = $db->prepare("
                SELECT r.semester, r.exam_year,
                       SUM(r.total_marks) AS obtained,
                       SUM(sub.max_total) AS max_marks,
                       ROUND(SUM(r.total_marks)/SUM(sub.max_total)*100, 2) AS percentage,
                       SUM(IF(r.status='FAIL',1,0)) AS fail_count,
                       SUM(IF(r.attendance_status='ABSENT',1,0)) AS absent_count,
                       COUNT(r.id) AS subject_count,
                       MAX(r.is_published) AS is_published
                FROM results r
                JOIN subjects sub ON r.subject_id = sub.id
                WHERE r.student_id = ?
                GROUP BY r.semester, r.exam_year
                ORDER BY r.semester ASC, r.exam_year ASC
            ");
            $semStmt->execute([$studentId]);
            $semesters = $semStmt->fetchAll();

            // Compute CGPA across published semesters
            $publishedSems = array_filter($semesters, fn($s) => $s['is_published']);
            $cgpa = 0;
            if (count($publishedSems) > 0) {
                $totalPerc = array_sum(array_column(array_values($publishedSems), 'percentage'));
                $cgpa = round($totalPerc / count($publishedSems) / 10, 2);
            }

            // Detailed marks per semester (all, not just published — admin view)
            $marksStmt = $db->prepare("
                SELECT r.*, sub.code AS subject_code, sub.name AS subject_name,
                       sub.max_theory, sub.max_practical, sub.max_total
                FROM results r JOIN subjects sub ON r.subject_id = sub.id
                WHERE r.student_id = ?
                ORDER BY r.semester ASC, sub.code ASC
            ");
            $marksStmt->execute([$studentId]);
            $allMarks = $marksStmt->fetchAll();

            // Group marks by semester
            $marksBySem = [];
            foreach ($allMarks as $m) {
                $key = $m['semester'] . '_' . $m['exam_year'];
                $marksBySem[$key][] = $m;
            }

            jsonResponse(true, 'Academic history fetched', [
                'student'   => $student,
                'semesters' => $semesters,
                'marks_by_semester' => $marksBySem,
                'cgpa'      => $cgpa,
            ]);
            break;

        // ── UNPUBLISH RESULTS ─────────────────────────────────
        case 'unpublish_results':
            $semester = (int)($_POST['semester'] ?? 0);
            $year     = (int)($_POST['exam_year'] ?? date('Y'));
            $studentId = (int)($_POST['student_id'] ?? 0);

            $sql = "UPDATE results SET is_published = 0 WHERE exam_year = ?";
            $params = [$year];
            if ($semester > 0) { $sql .= " AND semester = ?"; $params[] = $semester; }
            if ($studentId > 0) { $sql .= " AND student_id = ?"; $params[] = $studentId; }

            $db->beginTransaction();
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $affected = $stmt->rowCount();
                $db->commit();
                logActivity($_SESSION['user_id'], $_SESSION['username'], 'admin', "UNPUBLISHED_RESULTS: sem=$semester year=$year count=$affected");
                jsonResponse(true, "Results unpublished. $affected record(s) updated.", ['count' => $affected]);
            } catch (Exception $e) {
                $db->rollBack();
                jsonResponse(false, 'Unpublish failed: ' . $e->getMessage());
            }
            break;

        // ── GET SEMESTER STATS (admin dashboard charts) ───────
        case 'get_semester_stats':
            $courseId = (int)($_GET['course_id'] ?? 0);
            $year     = (int)($_GET['exam_year'] ?? date('Y'));

            $sql = "
                SELECT r.semester,
                       COUNT(DISTINCT r.student_id) AS total_students,
                       SUM(IF(r.status='PASS' AND r.attendance_status='PRESENT',1,0)) AS pass_count,
                       SUM(IF(r.status='FAIL' AND r.attendance_status='PRESENT',1,0)) AS fail_count,
                       SUM(IF(r.attendance_status='ABSENT',1,0)) AS absent_count,
                       ROUND(AVG(r.total_marks/sub.max_total*100),1) AS avg_percentage
                FROM results r
                JOIN subjects sub ON r.subject_id = sub.id
                JOIN students s ON r.student_id = s.id
                WHERE r.exam_year = ? AND r.is_published = 1
            ";
            $params = [$year];
            if ($courseId) { $sql .= " AND s.course_id = ?"; $params[] = $courseId; }
            $sql .= " GROUP BY r.semester ORDER BY r.semester ASC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            jsonResponse(true, 'Semester stats fetched', ['stats' => $stmt->fetchAll()]);
            break;

        // ── GET STUDENT CGPA ──────────────────────────────────
        case 'get_student_cgpa':
            $studentId = (int)($_GET['student_id'] ?? 0);
            if (!$studentId) jsonResponse(false, 'student_id required');

            $stmt = $db->prepare("
                SELECT ROUND(AVG(perc)/10,2) AS cgpa FROM (
                    SELECT r.semester, ROUND(SUM(r.total_marks)/SUM(sub.max_total)*100,2) AS perc
                    FROM results r JOIN subjects sub ON r.subject_id = sub.id
                    WHERE r.student_id = ? AND r.is_published = 1
                    GROUP BY r.semester
                ) sem_perc
            ");
            $stmt->execute([$studentId]);
            $row = $stmt->fetch();
            jsonResponse(true, 'CGPA fetched', ['cgpa' => $row['cgpa'] ?? 0]);
            break;

        default:
            jsonResponse(false, "Unknown action: $action");
    }

} catch (PDOException $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage());
} catch (Exception $e) {
    jsonResponse(false, 'Error: ' . $e->getMessage());
}
?>