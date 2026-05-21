<?php
$conn = new mysqli('localhost', 'root', '', 'erms_db');
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

echo "Cleaning existing data...\n";
$conn->query("SET FOREIGN_KEY_CHECKS=0");
$conn->query("TRUNCATE TABLE results");
$conn->query("TRUNCATE TABLE students");
$conn->query("DELETE FROM users WHERE role = 'student'");
$conn->query("SET FOREIGN_KEY_CHECKS=1");

$firstNames = ["Rahul", "Priya", "Amit", "Sneha", "Vikram", "Anjali", "Rohan", "Pooja", "Karan", "Neha", "Arjun", "Kavya", "Aditya", "Riya", "Siddharth", "Aisha", "Varun", "Ishita", "Abhinav", "Megha", "Sameer", "Tanvi", "Nikhil", "Shruti", "Rishabh"];
$lastNames = ["Sharma", "Verma", "Patel", "Singh", "Kumar", "Gupta", "Jain", "Deshmukh", "Joshi", "Chopra", "Reddy", "Nair", "Iyer", "Das", "Bose", "Menon", "Malhotra", "Mehta", "Yadav", "Rajput"];

$result = $conn->query("SELECT * FROM courses");
$courses = [];
while ($row = $result->fetch_assoc()) $courses[] = $row;

$studentCount = 0;
$resultsCount = 0;
$stmtUser = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'student')");
$stmtStudent = $conn->prepare("INSERT INTO students (user_id, roll_number, full_name, father_name, mother_name, email, phone, dob, address, course_id, current_semester, registration_no, enrollment_year, plain_password, gender) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmtResult = $conn->prepare("INSERT INTO results (student_id, subject_id, semester, exam_year, theory_marks, practical_marks, is_published) VALUES (?, ?, ?, ?, ?, ?, 1)");

$enrollmentBaseYear = 2026;

echo "Generating students and marks with unique passwords...\n";
$conn->begin_transaction();

foreach ($courses as $course) {
    $courseId = $course['id'];
    $courseCode = $course['code'];
    $maxSems = $course['total_semesters'];
    
    $subRes = $conn->query("SELECT * FROM subjects WHERE course_id = $courseId");
    $subjects = [];
    while ($sub = $subRes->fetch_assoc()) $subjects[$sub['semester']][] = $sub;

    for ($sem = 1; $sem <= $maxSems; $sem++) {
        for ($i = 1; $i <= 5; $i++) {
            $studentCount++;
            $gender = (rand(0, 1) == 0) ? 'Male' : 'Female';
            $fname = $firstNames[array_rand($firstNames)];
            $lname = $lastNames[array_rand($lastNames)];
            $fullName = "$fname $lname";
            $username = strtolower($courseCode) . "_" . sprintf("%04d", $studentCount);
            $email = $username . "@example.com";
            
            // Unique password generation
            $dob = date('Y-m-d', strtotime('-' . rand(18, 23) . ' years'));
            $dobYear = date('Y', strtotime($dob));
            $plainPassword = strtolower($fname) . "@" . $dobYear;
            $password = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 4]); // FAST HASH
            
            $stmtUser->bind_param("ss", $username, $password);
            if (!$stmtUser->execute()) die("User Insert Failed: " . $stmtUser->error . "\n");
            $userId = $conn->insert_id;
            
            $rollNo = $courseCode . "2026" . sprintf("%03d", $studentCount);
            $regNo = "REG" . date('Y') . rand(10000, 99999);
            $father = "Mr. " . $lastNames[array_rand($lastNames)];
            $mother = "Mrs. " . $lastNames[array_rand($lastNames)];
            $phone = "9" . rand(100000000, 999999999);
            $address = rand(10, 999) . ", Sample Street, City";
            $yearDiff = floor(($sem - 1) / 2);
            $enrollYear = $enrollmentBaseYear - $yearDiff;
            
            $stmtStudent->bind_param("issssssssisisss", $userId, $rollNo, $fullName, $father, $mother, $email, $phone, $dob, $address, $courseId, $sem, $regNo, $enrollYear, $plainPassword, $gender);
            if (!$stmtStudent->execute()) die("Student Insert Failed: " . $stmtStudent->error . "\n");
            $studentId = $conn->insert_id;
            
            for ($s = 1; $s <= $sem; $s++) {
                if (!isset($subjects[$s])) continue;
                foreach ($subjects[$s] as $subject) {
                    $subId = $subject['id'];
                    $maxT = $subject['max_theory'];
                    $maxP = $subject['max_practical'];
                    
                    if (rand(1, 100) > 5) {
                        $marksT = rand(ceil(0.4 * $maxT), $maxT);
                        $marksP = rand(ceil(0.4 * $maxP), $maxP);
                    } else {
                        $marksT = rand(0, ceil(0.39 * $maxT));
                        $marksP = rand(0, ceil(0.39 * $maxP));
                    }
                    $examYear = $enrollYear + floor(($s - 1) / 2);
                    $stmtResult->bind_param("iiiiii", $studentId, $subId, $s, $examYear, $marksT, $marksP);
                    if (!$stmtResult->execute()) die("Result Insert Failed: " . $stmtResult->error . "\n");
                    $resultsCount++;
                }
            }
        }
    }
}
$conn->commit();
echo "Successfully generated $studentCount students and $resultsCount result records.\n";
$conn->close();
?>
