// ============================================================
//  main.js  –  Frontend ↔ PHP Backend Bridge
//  Drop this file in your project root to replace the old one.
// ============================================================

// ── Utility: AJAX POST helper ────────────────────────────────
async function apiPost(url, data = {}) {
    const form = new FormData();
    for (const [k, v] of Object.entries(data)) {
        if (v !== undefined) {
            form.append(k, v);
        }
    }
    try {
        const res = await fetch(url, {
            method: 'POST',
            body: form,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!res.ok) throw new Error(`Server returned ${res.status}`);
        return await res.json();
    } catch (e) {
        console.error('API Error:', e);
        return { success: false, message: 'Network error: ' + e.message };
    }
}

async function apiGet(url, params = {}) {
    const qs = new URLSearchParams(params).toString();
    try {
        const res = await fetch(url + (qs ? '?' + qs : ''), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        return await res.json();
    } catch (e) {
        return { success: false, message: 'Network error.' };
    }
}

// ── Utility: show toast alert ────────────────────────────────
function showAlert(message, type = 'success') {
    const colors = { success: '#4caf50', error: '#f44336', info: '#2196f3', warning: '#ff9800' };
    const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', info: 'fa-info-circle', warning: 'fa-exclamation-triangle' };

    const el = document.createElement('div');
    el.innerHTML = `<i class="fas ${icons[type] || icons.info}"></i> <span>${message}</span>`;
    Object.assign(el.style, {
        position: 'fixed', top: '20px', right: '20px', zIndex: 9999,
        padding: '14px 22px', borderRadius: '8px', color: 'white',
        background: colors[type] || colors.info,
        boxShadow: '0 4px 14px rgba(0,0,0,0.2)',
        display: 'flex', alignItems: 'center', gap: '10px',
        fontSize: '0.95rem', maxWidth: '380px',
        animation: 'slideIn 0.3s ease'
    });
    document.body.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity 0.4s'; setTimeout(() => el.remove(), 400); }, 3500);
}

// ── Utility: toggle nav hamburger ───────────────────────────
function toggleMenu() {
    const nav = document.getElementById('navLinks');
    if (nav) nav.classList.toggle('show');
}

// ── Utility: toggle password visibility ─────────────────────
function togglePassword(inputId) {
    const el = document.getElementById(inputId);
    const icon = event.target;
    el.type = el.type === 'password' ? 'text' : 'password';
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
}

// ── Utility: FAQ accordion ───────────────────────────────────
function toggleFAQ(el) {
    const answer = el.nextElementSibling;
    const icon = el.querySelector('i');
    const isOpen = answer.style.maxHeight;
    document.querySelectorAll('.faq-answer').forEach(a => a.style.maxHeight = '');
    document.querySelectorAll('.faq-question i').forEach(i => i.style.transform = '');
    if (!isOpen) {
        answer.style.maxHeight = answer.scrollHeight + 'px';
        if (icon) icon.style.transform = 'rotate(180deg)';
    }
}

// ============================================================
//  LOGIN PAGE
// ============================================================
async function handleLogin(event) {
    event.preventDefault();

    const username = document.getElementById('username')?.value?.trim();
    const password = document.getElementById('password')?.value?.trim();
    const msgEl = document.getElementById('loginMsg');

    if (!username || !password) {
        if (msgEl) { msgEl.textContent = 'Please enter username and password.'; msgEl.style.color = '#f44336'; }
        return;
    }

    const btn = event.target.querySelector('button[type=submit]') || document.querySelector('.btn-login');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logging in...'; }

    const result = await apiPost('login.php', { username, password });

    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Login'; }

    if (result.success) {
        showAlert('Login successful! Redirecting...', 'success');
        setTimeout(() => window.location.href = result.redirect, 800);
    } else {
        if (msgEl) { msgEl.textContent = result.message; msgEl.style.color = '#f44336'; }
        showAlert(result.message, 'error');
    }
}

// ============================================================
//  LOGOUT
// ============================================================
function confirmLogout() {
    const modal = document.getElementById('logoutModal');
    if (modal) {
        modal.style.display = 'flex';
    } else {
        // Fallback if modal not found
        if (confirm('Are you sure you want to log out?')) {
            doLogout();
        }
    }
}

async function doLogout() {
    closeModal('logoutModal');
    const result = await apiGet('logout.php');
    showAlert('Logged out successfully!', 'success');
    setTimeout(() => window.location.href = 'login.html', 700);
}

async function logout() {
    confirmLogout();
}

// ============================================================
//  SESSION GUARD – call on protected pages
// ============================================================
async function guardPage(requiredRole) {
    const result = await apiGet('session_check.php');
    if (!result.success) {
        showAlert('Session expired. Please login again.', 'warning');
        setTimeout(() => window.location.href = 'login.html', 1200);
        return null;
    }
    if (requiredRole && result.role !== requiredRole) {
        window.location.href = 'login.html';
        return null;
    }
    return result;
}

// ============================================================
//  STUDENT DASHBOARD
// ============================================================
async function initStudentDashboard() {
    const session = await guardPage('student');
    if (!session) return;

    // Populate names / info
    setTextById('displayName', session.name);
    setTextById('displayRoll', session.roll_number);
    setTextById('studentName', session.name);
    setTextById('studentRoll', 'Roll: ' + session.roll_number);
    setTextById('studentCourse', session.course_code + ' - Semester ' + session.semester);
    setTextById('welcomeName', session.name.split(' ')[0]);
    setTextById('currentDate', new Date().toLocaleDateString('en-IN', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }));

    // Load semester results in table
    await loadStudentResultsTable(session);
}

async function loadStudentResultsTable(session) {
    const semester = session.semester;
    const result = await apiGet('student_api.php', { action: 'get_results', semester });
    const tbody = document.querySelector('.results-table tbody') || document.getElementById('resultsTableBody');
    if (!tbody) return;

    if (!result.success || !result.marks?.length) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:20px;color:#888;">No published results found for Semester ${semester}.</td></tr>`;
        return;
    }

    tbody.innerHTML = result.marks.map((m, i) => `
        <tr>
            <td>${i + 1}</td>
            <td>${m.subject_code}</td>
            <td>${m.subject_name}</td>
            <td>${m.semester}</td>
            <td>${m.total_marks}/${m.max_total}</td>
            <td>${m.grade}</td>
            <td><span class="badge ${m.status === 'PASS' ? 'pass' : 'fail'}">${m.status}</span></td>
            <td>
                <button class="btn-icon" onclick="showResultModal(${m.semester}, ${m.exam_year})" title="View"><i class="fas fa-eye"></i></button>
                <button class="btn-icon" onclick="downloadResult(${m.semester}, ${m.exam_year})" title="Download"><i class="fas fa-download"></i></button>
            </td>
        </tr>
    `).join('');
}

function showResultModal() {
    // Unhide dashboard sections if they were hidden by Profile view
    document.querySelectorAll('.dashboard-main > .welcome-banner, .dashboard-main > .stats-grid, .dashboard-main > .card, #semResultsSection, #semHistorySection').forEach(el => el.style.display = '');
    const profileSection = document.getElementById('profileSection');
    if (profileSection) profileSection.style.display = 'none';

    // Highlight sidebar
    document.querySelectorAll('.sidebar-menu li').forEach(li => li.classList.remove('active'));
    document.querySelectorAll('.sidebar-menu li')[1].classList.add('active'); // View Results

    // Scroll to Sem Results Section
    const el = document.getElementById('semResultsSection');
    if (el) el.scrollIntoView({ behavior: 'smooth' });
}

function showSemesterResults() {
    // Unhide dashboard sections if they were hidden by Profile view
    document.querySelectorAll('.dashboard-main > .welcome-banner, .dashboard-main > .stats-grid, .dashboard-main > .card, #semResultsSection, #semHistorySection').forEach(el => el.style.display = '');
    const profileSection = document.getElementById('profileSection');
    if (profileSection) profileSection.style.display = 'none';

    // Highlight sidebar
    document.querySelectorAll('.sidebar-menu li').forEach(li => li.classList.remove('active'));
    document.querySelectorAll('.sidebar-menu li')[2].classList.add('active'); // Semester Results

    // Scroll to Sem History Section
    const el = document.getElementById('semHistorySection');
    if (el) el.scrollIntoView({ behavior: 'smooth' });
}

async function downloadMarksheet() {
    // Detect currently selected semester or student's default
    const sem = document.getElementById('resultSemester')?.value || 1;
    downloadResult(sem);
}

function printResult() { window.print(); }

async function downloadResult(semester, year) {
    let url = 'view-result.php';
    const params = [];
    if (semester) params.push(`semester=${semester}`);
    if (year) params.push(`year=${year}`);

    if (params.length > 0) {
        url += '?' + params.join('&');
    }

    window.open(url, '_blank');
}
async function viewProfile() { showAlert('Profile view coming soon!', 'info'); }

// ============================================================
//  ADMIN DASHBOARD
// ============================================================
async function initAdminDashboard() {
    const session = await guardPage('admin');
    if (!session) return;
    setTextById('adminName', session.name);

    await loadAdminStats();
    await loadStudentsTable();
    await loadCoursesTable();
    await loadSubjectsTable();
    if (typeof initCharts === 'function') {
        initCharts();
    }
}

async function loadAdminStats(isRetry = false) {
    try {
        const r = await apiGet('admin_api.php', { action: 'get_stats' });

        // If it failed and we haven't retried yet, wait a moment and try one last time
        if (!r.success && !isRetry) {
            setTimeout(() => loadAdminStats(true), 500);
            return;
        }

        if (!r.success) return;

        setTextById('totalStudents', r.total_students ?? '0');
        setTextById('totalCourses', r.total_courses ?? '0');
        setTextById('totalSubjects', r.total_subjects ?? '0');
        setTextById('totalResults', r.total_results ?? '0');
    } catch (err) {
        console.error("Failed to load stats:", err);
    }
}

async function loadStudentsTable() {
    const r = await apiGet('admin_api.php', { action: 'get_students' });
    if (!r.success) return;

    const tbodies = ['studentsTableBody', 'allStudentsTable'];
    tbodies.forEach(tbId => {
        const tb = document.getElementById(tbId);
        if (!tb) return;
        if (!r.students?.length) {
            tb.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:20px;color:#888;">No students added yet.</td></tr>`;
            return;
        }
        tb.innerHTML = r.students.map(s => `
            <tr>
                <td>${s.roll_number}</td>
                <td>${s.full_name}</td>
                <td>${s.course_code}</td>
                <td>Sem ${s.current_semester}</td>
                <td>${s.email || '–'}</td>
                <td>${s.phone || '–'}</td>
                <td><span class="badge ${s.is_active ? 'pass' : 'fail'}">${s.is_active ? 'Active' : 'Inactive'}</span></td>
                <td><span style="font-family:monospace; color:#4361ee; background:#f8f9fa; padding:2px 6px; border-radius:4px;">${s.plain_password || 'pass123'}</span></td>
                <td>
                    <button class="btn-icon edit"   onclick="editStudentPrompt(${s.id})" title="Edit"><i class="fas fa-edit"></i></button>
                    <button class="btn-icon"        onclick="viewStudentMarks(${s.id})"  title="View Marks"><i class="fas fa-eye"></i></button>
                    <button class="btn-icon delete" onclick="deleteStudentConfirm(${s.id}, '${s.roll_number}')" title="Delete"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `).join('');
    });

    // Also populate student dropdown in marks modal
    const sel = document.getElementById('marksStudentId') || document.getElementById('marksStudent');
    if (sel) {
        sel.innerHTML = '<option value="">Select Student</option>' +
            r.students.map(s => `<option value="${s.id}">${s.roll_number} – ${s.full_name}</option>`).join('');
    }
}

async function loadCoursesTable() {
    const r = await apiGet('admin_api.php', { action: 'get_courses' });
    if (!r.success) {
        console.error('Failed to load courses:', r.message);
        return;
    }

    const tb = document.getElementById('coursesTable');
    if (tb) {
        tb.innerHTML = r.courses.map(c => `
            <tr>
                <td>${c.code}</td>
                <td>${c.name}</td>
                <td>${c.duration_years} yr</td>
                <td>${c.department || '–'}</td>
                <td>${c.total_semesters}</td>
                <td>${c.student_count}</td>
                <td>
                    <button class="btn-icon delete" onclick="deleteCourseConfirm(${c.id}, '${c.code}')"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="7" style="text-align:center">No courses yet.</td></tr>';
    }

    // Populate all course dropdowns in the system
    const dropdownIds = ['studentCourse', 'marksSubjectCourseId', 'subjectCourseId', 'subjectCourse', 'importCourseId', 'importSubjectCourseId'];
    dropdownIds.forEach(id => {
        const sel = document.getElementById(id);
        if (!sel) return;
        const currentValue = sel.value;
        sel.innerHTML = '<option value="">Choose Course...</option>' +
            r.courses.map(c => `<option value="${c.id}" data-code="${c.code}">${c.code} – ${c.name}</option>`).join('');
        if (currentValue) sel.value = currentValue;
    });
}

async function loadSubjectsTable() {
    const r = await apiGet('admin_api.php', { action: 'get_subjects' });
    if (!r.success) return;

    const tb = document.getElementById('subjectsTable');
    if (tb) {
        tb.innerHTML = r.subjects.map(s => `
            <tr>
                <td>${s.code}</td>
                <td>${s.name}</td>
                <td>${s.course_code}</td>
                <td>Sem ${s.semester}</td>
                <td>${s.max_theory}</td>
                <td>${s.max_practical}</td>
                <td>${s.max_total}</td>
                <td>
                    <button class="btn-icon delete" onclick="deleteSubjectConfirm(${s.id}, '${s.code}')"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="8" style="text-align:center">No subjects yet.</td></tr>';
    }
}

// ── Admin: Save Student ───────────────────────────────────────
async function saveStudent() {
    const data = {
        action: 'add_student',
        full_name: document.getElementById('studentName')?.value?.trim(),
        course_id: document.getElementById('studentCourse')?.value,
        semester: document.getElementById('studentSemester')?.value,
        email: document.getElementById('studentEmail')?.value?.trim(),
        phone: document.getElementById('studentPhone')?.value?.trim(),
        dob: document.getElementById('studentDob')?.value,
        father_name: document.getElementById('studentFatherName')?.value?.trim(),
        mother_name: document.getElementById('studentMotherName')?.value?.trim(),
        password: document.getElementById('studentPassword')?.value,
        address: document.getElementById('studentAddress')?.value?.trim(),
    };

    if (!data.full_name || !data.course_id) { showAlert('Name and course are required', 'error'); return; }

    const r = await apiPost('admin_api.php', data);
    if (r.success) {
        showAlert(`Student added! Roll: ${r.roll_number} | Password: ${r.password}`, 'success');
        const credBox = document.getElementById('credentialsBox');
        if (credBox) {
            credBox.style.display = 'block';
            setTextById('generatedRoll', r.roll_number);
            setTextById('generatedPass', r.password);
        }
        await loadStudentsTable();
        await loadAdminStats();
        setTimeout(() => closeModal('studentModal'), 3000);
    } else {
        showAlert(r.message, 'error');
    }
}

// ── Admin: Save Course ────────────────────────────────────────
async function saveCourse() {
    const data = {
        action: 'add_course',
        code: document.getElementById('courseCode')?.value?.trim(),
        name: document.getElementById('courseName')?.value?.trim(),
        duration: document.getElementById('courseDuration')?.value,
        department: document.getElementById('courseDept')?.value?.trim(),
        semesters: document.getElementById('courseSemesters')?.value,
    };
    if (!data.code || !data.name) { showAlert('Course code and name required', 'error'); return; }

    const r = await apiPost('admin_api.php', data);
    if (r.success) {
        showAlert('Course added!', 'success');
        await loadCoursesTable();
        await loadAdminStats();
        closeModal('courseModal');
    } else { showAlert(r.message, 'error'); }
}

// ── Admin: Save Subject ───────────────────────────────────────
async function saveSubject() {
    const data = {
        action: 'add_subject',
        code: document.getElementById('subjectCode')?.value?.trim(),
        name: document.getElementById('subjectName')?.value?.trim(),
        course_id: document.getElementById('subjectCourse')?.value || document.getElementById('subjectCourseId')?.value,
        semester: document.getElementById('subjectSemester')?.value,
        max_theory: document.getElementById('subjectMaxTheory')?.value || 75,
        max_practical: document.getElementById('subjectMaxPractical')?.value || 25,
        passing_marks: document.getElementById('subjectPassing')?.value || 40,
    };
    if (!data.code || !data.name || !data.course_id) { showAlert('Code, name and course are required', 'error'); return; }

    const r = await apiPost('admin_api.php', data);
    if (r.success) {
        showAlert('Subject added!', 'success');
        await loadSubjectsTable();
        await loadAdminStats();
        closeModal('subjectModal');
    } else { showAlert(r.message, 'error'); }
}

// ── Admin: Save Marks ─────────────────────────────────────────
async function saveMarks() {
    const data = {
        action: 'save_marks',
        student_id: document.getElementById('marksStudentId')?.value || document.getElementById('marksStudent')?.value,
        subject_id: document.getElementById('marksSubjectId')?.value || document.getElementById('marksSubject')?.value,
        semester: document.getElementById('marksSemester')?.value || 1,
        exam_year: document.getElementById('marksYear')?.value || document.getElementById('marksExamYear')?.value || new Date().getFullYear(),
        theory_marks: document.getElementById('theoryMarks')?.value || document.getElementById('marksTheory')?.value || 0,
        practical_marks: document.getElementById('practicalMarks')?.value || document.getElementById('marksPractical')?.value || 0,
    };
    if (!data.student_id || !data.subject_id) { showAlert('Student and subject are required', 'error'); return; }

    const r = await apiPost('admin_api.php', data);
    if (r.success) {
        showAlert('Marks saved!', 'success');
        closeModal('marksModal');
    } else { showAlert(r.message, 'error'); }
}

// ── Admin: Delete confirm helpers ────────────────────────────
async function deleteStudentConfirm(id, roll) {
    if (!confirm(`Delete student ${roll}? This will also remove all their results.`)) return;
    const r = await apiPost('admin_api.php', { action: 'delete_student', student_id: id });
    if (r.success) { showAlert('Student deleted', 'success'); await loadStudentsTable(); await loadAdminStats(); }
    else showAlert(r.message, 'error');
}

async function deleteCourseConfirm(id, code) {
    if (!confirm(`Delete course ${code}?`)) return;
    const r = await apiPost('admin_api.php', { action: 'delete_course', course_id: id });
    if (r.success) { showAlert('Course deleted', 'success'); await loadCoursesTable(); await loadAdminStats(); }
    else showAlert(r.message, 'error');
}

async function deleteSubjectConfirm(id, code) {
    if (!confirm(`Delete subject ${code}? All related marks will be removed.`)) return;
    const r = await apiPost('admin_api.php', { action: 'delete_subject', subject_id: id });
    if (r.success) { showAlert('Subject deleted', 'success'); await loadSubjectsTable(); }
    else showAlert(r.message, 'error');
}

async function publishResults() {
    const defaultYear = new Date().getFullYear();
    const yearInput = prompt("Enter Exam Year to publish:", defaultYear);
    if (!yearInput) return; // User cancelled

    const semInput = prompt("Enter Semester number to publish (or leave blank/0 to completely publish all semesters for that year):", "");
    if (semInput === null) return; // User cancelled

    const r = await apiPost('admin_api.php', { action: 'publish_results', exam_year: yearInput, semester: semInput || 0 });
    showAlert(r.success ? 'Results published successfully!' : r.message, r.success ? 'success' : 'error');
}

async function generateReports() {
    showAlert('Report generation coming soon!', 'info');
}

function editStudentPrompt(id) { showAlert('Edit form for student ID: ' + id + ' — extend with a full edit modal!', 'info'); }

let currentViewMarksStudentId = null;

async function viewStudentMarks(studentId) {
    currentViewMarksStudentId = studentId;

    // Fetch the student's basic details (reuse existing state or fetch again, here we fetch to ensure freshness)
    const r = await apiGet('admin_api.php', { action: 'get_students' });
    const student = r.students?.find(s => s.id == studentId);

    if (student) {
        const _set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
        _set('vmStudentName', student.full_name);
        _set('vmRollNo', student.roll_number);
        _set('vmCourse', student.course_code);
        _set('vmCurrentSem', 'Sem ' + student.current_semester);
        _set('vmEmail', student.email || '–');

        // Populate semester dropdown
        const semSel = document.getElementById('vmSemesterSelect');
        if (semSel) {
            semSel.innerHTML = '';
            const maxSem = parseInt(student.total_semesters || 8);
            for (let i = 1; i <= maxSem; i++) {
                const opt = document.createElement('option');
                opt.value = i; opt.textContent = 'Semester ' + i;
                if (i == student.current_semester) opt.selected = true;
                semSel.appendChild(opt);
            }
        }
    }

    const summaryEl = document.getElementById('vmSummary');
    if (summaryEl) summaryEl.style.display = 'none';
    const tbody = document.getElementById('vmMarksBody');
    if (tbody) tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;padding:20px;color:#888;"><i class="fas fa-spinner fa-spin"></i> Loading marks...</td></tr>`;

    openModal('viewMarksModal');
    await loadAdminStudentMarks();
}

async function loadAdminStudentMarks() {
    if (!currentViewMarksStudentId) return;
    const semSel = document.getElementById('vmSemesterSelect');
    const semester = semSel ? semSel.value : 1;

    const tb = document.getElementById('vmMarksBody');
    if (tb) tb.innerHTML = `<tr><td colspan="10" style="text-align:center;padding:20px;color:#888;"><i class="fas fa-spinner fa-spin"></i> Loading marks...</td></tr>`;
    const summaryEl = document.getElementById('vmSummary');
    if (summaryEl) summaryEl.style.display = 'none';

    const r = await apiGet('admin_api.php', { action: 'get_student_marks', student_id: currentViewMarksStudentId, semester: semester });

    if (!r.success || !r.marks?.length) {
        if (tb) tb.innerHTML = `<tr><td colspan="10" style="text-align:center;padding:20px;color:#888;">No marks recorded for this semester yet.</td></tr>`;
        return;
    }

    if (tb) {
        tb.innerHTML = r.marks.map((m, i) => `
            <tr style="${m.status === 'FAIL' ? 'background:#fff5f5;' : ''}">
                <td style="border:1px solid #dee2e6;padding:8px;text-align:center;">${i + 1}</td>
                <td style="border:1px solid #dee2e6;padding:8px;">${m.subject_code}</td>
                <td style="border:1px solid #dee2e6;padding:8px;">${m.subject_name}</td>
                <td style="border:1px solid #dee2e6;padding:8px;text-align:center;">${m.attendance_status === 'ABSENT' ? '<span style="color:#f57c00;font-weight:600">AB</span>' : m.theory_marks}</td>
                <td style="border:1px solid #dee2e6;padding:8px;text-align:center;">${m.attendance_status === 'ABSENT' ? '<span style="color:#f57c00;font-weight:600">AB</span>' : m.practical_marks}</td>
                <td style="border:1px solid #dee2e6;padding:8px;text-align:center;font-weight:600;">${m.attendance_status === 'ABSENT' ? 0 : m.total_marks}</td>
                <td style="border:1px solid #dee2e6;padding:8px;text-align:center;">${m.max_total}</td>
                <td style="border:1px solid #dee2e6;padding:8px;text-align:center;font-weight:700;color:#4361ee;">${m.grade || '–'}</td>
                <td style="border:1px solid #dee2e6;padding:8px;text-align:center;">
                    <span class="badge ${m.status === 'PASS' ? 'pass' : 'fail'}">${m.status}</span>
                </td>
                <td style="border:1px solid #dee2e6;padding:8px;text-align:center;">
                    ${m.is_published == 1 ? '<i class="fas fa-check-circle" style="color:#4caf50;" title="Published"></i>' : '<i class="fas fa-times-circle" style="color:#ccc;" title="Not Published"></i>'}
                </td>
            </tr>
        `).join('');
    }

    if (r.summary && summaryEl) {
        summaryEl.style.display = 'flex';
        document.getElementById('vmTotal').textContent = r.summary.total_obtained + '/' + r.summary.total_max;
        document.getElementById('vmPercent').textContent = r.summary.percentage + '%';
        document.getElementById('vmSGPA').textContent = r.summary.sgpa;
        document.getElementById('vmGrade').textContent = r.summary.grade;

        const statusEl = document.getElementById('vmResult');
        if (statusEl) {
            statusEl.textContent = r.summary.status;
            statusEl.style.color = r.summary.status === 'PASS' ? '#388e3c' : '#d32f2f';
        }
    }
}

async function loadStudentsByFilter() {
    const courseId = document.getElementById('filterCourseId')?.value || '';
    const semester = document.getElementById('filterSemester')?.value || '';
    const infoText = document.getElementById('filterInfoText');
    const infoDiv = document.getElementById('filterInfo');

    const params = { action: 'get_students_filtered' };
    if (courseId) params.course_id = courseId;
    if (semester) params.semester = semester;

    const btn = document.querySelector('button[onclick="loadStudentsByFilter()"]');
    const originalText = btn ? btn.innerHTML : '';
    if (btn) btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';

    try {
        const r = await apiGet('admin_api.php', params);
        if (!r.success) {
            showAlert(r.message || 'Error fetching filtered students', 'error');
            return;
        }

        const tb = document.getElementById('allStudentsTable');
        if (!tb) return;

        if (!r.students?.length) {
            tb.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:20px;color:#888;">No students match the selected filter.</td></tr>`;
            if (infoDiv) { infoDiv.style.display = 'block'; infoText.textContent = `Found 0 students matching filter.`; }
            return;
        }

        if (infoDiv) { infoDiv.style.display = 'block'; infoText.textContent = `Found ${r.students.length} student(s) matching the criteria.`; }

        tb.innerHTML = r.students.map(s => `
            <tr>
                <td>${s.roll_number}</td>
                <td>${s.full_name}</td>
                <td>${s.course_code}</td>
                <td>Sem ${s.current_semester}</td>
                <td>${s.email || '–'}</td>
                <td>${s.phone || '–'}</td>
                <td><span class="badge ${s.is_active ? 'pass' : 'fail'}">${s.is_active ? 'Active' : 'Inactive'}</span></td>
                <td><span style="font-family:monospace; color:#4361ee; background:#f8f9fa; padding:2px 6px; border-radius:4px;">${s.plain_password || 'pass123'}</span></td>
                <td>
                    <button class="btn-icon edit"   onclick="editStudentPrompt(${s.id})" title="Edit"><i class="fas fa-edit"></i></button>
                    <button class="btn-icon"        onclick="viewStudentMarks(${s.id})"  title="View Marks"><i class="fas fa-eye"></i></button>
                    <button class="btn-icon delete" onclick="deleteStudentConfirm(${s.id}, '${s.roll_number}')" title="Delete"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `).join('');
    } finally {
        if (btn) btn.innerHTML = originalText;
    }
}

// ── Admin: Student Results Directory ──────────────────────────

async function loadStudentResultsList() {
    const search = document.getElementById('srSearchInput')?.value || '';
    const dept = document.getElementById('srDeptFilter')?.value || '';
    const tb = document.getElementById('srTableBody');
    if (!tb) return;

    tb.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:20px;color:#888;"><i class="fas fa-spinner fa-spin"></i> Loading directory...</td></tr>`;

    const params = { action: 'get_students_filtered' };
    if (search) params.q = search;
    if (dept) params.department = dept;

    const r = await apiGet('admin_api.php', params);
    if (!r.success) {
        tb.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:20px;color:#d32f2f;">Error: ${r.message}</td></tr>`;
        return;
    }

    if (!r.students?.length) {
        tb.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:20px;color:#888;">No students found matching your search.</td></tr>`;
        return;
    }

    tb.innerHTML = '';
    // Let's render the list and parallelly fetch their CGPAs for display
    for (let s of r.students) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${s.roll_number}</td>
            <td style="font-weight:600;">${s.full_name}</td>
            <td>${s.course_code}</td>
            <td>${s.department || '--'}</td>
            <td>Sem ${s.current_semester}</td>
            <td id="cgpa_sr_${s.id}"><i class="fas fa-spinner fa-spin" style="font-size:0.8em;opacity:0.5;"></i></td>
            <td>
                <button onclick="viewStudentHistory(${s.id})" class="btn-icon" style="background:#e8eaf6;color:#3949ab;" title="View Academic History">
                    <i class="fas fa-history"></i> History
                </button>
            </td>
        `;
        tb.appendChild(tr);
        // Fire and forget CGPA fetch
        apiGet('admin_api.php', { action: 'get_student_cgpa', student_id: s.id }).then(cgpaRes => {
            const td = document.getElementById(`cgpa_sr_${s.id}`);
            if (td) {
                if (cgpaRes.success && cgpaRes.cgpa > 0) {
                    td.innerHTML = `<span style="background:linear-gradient(135deg,#4361ee,#7c3aed);color:white;padding:3px 10px;border-radius:12px;font-size:0.8rem;font-weight:700;">${cgpaRes.cgpa}</span>`;
                } else {
                    td.innerHTML = '<span style="color:#aaa;">--</span>';
                }
            }
        });
    }
}

let hmCurrentData = null; // Store fetched history data
let hmCurrentSem = null;

async function viewStudentHistory(studentId) {
    openModal('historyModal');
    const infoEl = document.getElementById('hmStudentInfo');
    const tabsEl = document.getElementById('hmTabsContainer');
    const tbody = document.getElementById('hmMarksBody');
    const sumBox = document.getElementById('hmSummaryBox');
    const actionBox = document.getElementById('hmActionBar');

    infoEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading academic history...';
    tabsEl.innerHTML = '';
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:30px;">Loading data...</td></tr>';
    sumBox.style.display = 'none';
    actionBox.style.display = 'none';

    const r = await apiGet('admin_api.php', { action: 'get_student_academic_history', student_id: studentId });
    if (!r.success) {
        infoEl.innerHTML = `<span style="color:#f44336;">Error: ${r.message}</span>`;
        return;
    }

    hmCurrentData = r; // save for tab clicks
    const stu = r.student;
    infoEl.innerHTML = `<strong>${stu.full_name}</strong> | Roll: ${stu.roll_number} | ${stu.course_name} (${stu.course_code}) | Dept: ${stu.department || '--'} | <strong>Overall CGPA: <span style="color:#ffd700;">${r.cgpa || '--'}</span></strong>`;

    if (!r.semesters || r.semesters.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:30px;">No exam results found for this student.</td></tr>';
        return;
    }

    // Build tabs
    r.semesters.forEach((sem, idx) => {
        const key = `${sem.semester}_${sem.exam_year}`;
        const tab = document.createElement('div');
        tab.className = 'hm-tab';
        tab.style.cssText = 'padding:12px 20px; cursor:pointer; font-size:0.9rem; font-weight:600; color:#555; border-bottom:3px solid transparent; white-space:nowrap; transition:all 0.2s;';
        tab.innerHTML = `Sem ${sem.semester} <span style="font-size:0.75rem; color:#888; margin-left:6px;">${sem.exam_year}</span>`;
        tab.onclick = () => renderHistoryTab(key, tab);
        tabsEl.appendChild(tab);
        if (idx === 0) tab.click(); // auto-click first tab
    });
}

function renderHistoryTab(semKey, tabEl) {
    // Tab styling
    document.querySelectorAll('.hm-tab').forEach(t => {
        t.style.color = '#555'; t.style.borderBottomColor = 'transparent'; t.style.background = 'transparent';
    });
    if (tabEl) {
        tabEl.style.color = '#4361ee'; tabEl.style.borderBottomColor = '#4361ee'; tabEl.style.background = '#eef2ff';
    }

    const marks = hmCurrentData.marks_by_semester[semKey] || [];
    // Find matching summary
    const [sNum, eYear] = semKey.split('_');
    const summary = hmCurrentData.semesters.find(s => s.semester == sNum && s.exam_year == eYear);
    hmCurrentSem = summary; // store for toggle publish

    const tbody = document.getElementById('hmMarksBody');
    const actionBox = document.getElementById('hmActionBar');
    const sumBox = document.getElementById('hmSummaryBox');

    if (!marks.length || !summary) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:30px;">Data missing.</td></tr>';
        actionBox.style.display = 'none'; sumBox.style.display = 'none';
        return;
    }

    // Render marks
    tbody.innerHTML = marks.map(m => `
        <tr style="${m.status === 'FAIL' ? 'background:#fff5f5;' : ''}">
            <td>${m.subject_code}</td>
            <td>${m.subject_name}</td>
            <td>${m.attendance_status === 'ABSENT' ? '<span style="color:#f57c00;">AB</span>' : m.theory_marks}</td>
            <td>${m.attendance_status === 'ABSENT' ? '<span style="color:#f57c00;">AB</span>' : m.practical_marks}</td>
            <td style="font-weight:600;">${m.attendance_status === 'ABSENT' ? 0 : m.total_marks}</td>
            <td>${m.max_total}</td>
            <td><span style="font-weight:700; color:${m.status === 'PASS' ? '#388e3c' : '#d32f2f'};">${m.status}</span></td>
        </tr>
    `).join('');

    // Render summary
    sumBox.style.display = 'flex';
    document.getElementById('hmSumMarks').textContent = `${summary.obtained} / ${summary.max_marks}`;
    document.getElementById('hmSumPerc').textContent = `${summary.percentage}%`;
    document.getElementById('hmSumSGPA').textContent = (parseFloat(summary.percentage) / 10).toFixed(2);

    const overallStatus = summary.fail_count == 0 ? 'PASS' : 'FAIL';
    const resEl = document.getElementById('hmSumResult');
    resEl.textContent = overallStatus;
    resEl.style.color = overallStatus === 'PASS' ? '#388e3c' : '#d32f2f';

    // Render action bar (Publish/Unpublish & Print)
    actionBox.style.display = 'flex';
    document.getElementById('hmExamYearTxt').textContent = `Exam Year: ${summary.exam_year}`;

    const pubBadge = document.getElementById('hmSemStatusBadge');
    const pubBtn = document.getElementById('hmTogglePublishBtn');

    if (summary.is_published == 1) {
        pubBadge.textContent = 'PUBLISHED';
        pubBadge.style.background = '#e8f5e9'; pubBadge.style.color = '#2e7d32';
        pubBtn.innerHTML = '<i class="fas fa-eye-slash"></i> Unpublish';
        pubBtn.className = 'btn btn-outline';
        pubBtn.style.borderColor = '#d32f2f'; pubBtn.style.color = '#d32f2f';
    } else {
        pubBadge.textContent = 'DRAFT / UNPUBLISHED';
        pubBadge.style.background = '#fff3e0'; pubBadge.style.color = '#ef6c00';
        pubBtn.innerHTML = '<i class="fas fa-check"></i> Publish Now';
        pubBtn.className = 'btn btn-primary';
        pubBtn.style.borderColor = ''; pubBtn.style.color = '';
    }

    const printBtn = document.getElementById('hmPrintBtn');
    printBtn.href = `marksheet.php?student_id=${hmCurrentData.student.id}&semester=${summary.semester}&exam_year=${summary.exam_year}`;
}

async function togglePublishCurrentSem() {
    if (!hmCurrentSem || !hmCurrentData) return;
    const stuId = hmCurrentData.student.id;
    const sem = hmCurrentSem.semester;
    const year = hmCurrentSem.exam_year;
    const isPubNow = hmCurrentSem.is_published == 1;

    const action = isPubNow ? 'unpublish_results' : 'publish_results';

    if (!confirm(`Are you sure you want to ${isPubNow ? 'UNPUBLISH' : 'PUBLISH'} Semester ${sem} results for this student?`)) return;

    const btn = document.getElementById('hmTogglePublishBtn');
    const origHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;

    const r = await apiPost('admin_api.php', { action: action, student_id: stuId, semester: sem, exam_year: year });

    btn.innerHTML = origHtml;
    btn.disabled = false;

    if (r.success) {
        showAlert(`Results ${isPubNow ? 'unpublished' : 'published'} successfully.`, 'success');
        // Refresh history modal silently
        viewStudentHistory(stuId);
    } else {
        showAlert(r.message, 'error');
    }
}


// ── Admin: Modal helpers ──────────────────────────────────────
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.style.display = 'flex';
    if (modalId === 'marksModal') { loadStudentsTable(); loadSubjectsForMarks(); }
}

function closeModal(modalId) {
    if (modalId) {
        const modal = document.getElementById(modalId);
        if (modal) modal.style.display = 'none';
    } else {
        document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
    }
}

async function loadSubjectsForMarks() {
    const r = await apiGet('admin_api.php', { action: 'get_subjects' });
    if (!r.success) return;
    const sel = document.getElementById('marksSubjectId') || document.getElementById('marksSubject');
    if (sel) {
        sel.innerHTML = '<option value="">Select Subject</option>' +
            r.subjects.map(s => `<option value="${s.id}">${s.code} – ${s.name} (Sem ${s.semester})</option>`).join('');
    }
}

// ── Admin: Generate roll number preview ──────────────────────
async function generateRollNumber() {
    const sel = document.getElementById('studentCourse');
    if (!sel?.value) return;
    const courseCode = sel.options[sel.selectedIndex]?.dataset?.code || sel.options[sel.selectedIndex]?.text?.split('–')[0]?.trim();
    const roll = (courseCode || 'XX') + new Date().getFullYear() + '###';
    const credBox = document.getElementById('credentialsBox');
    if (credBox) { credBox.style.display = 'block'; }
    setTextById('generatedRoll', 'Auto-generated on save');
    setTextById('generatedPass', '(As entered above)');
}

// ── Admin: Charts (unchanged from original) ───────────────────
function initCharts() {
    const enrollCtx = document.getElementById('enrollmentChart');
    const passCtx = document.getElementById('passChart');
    if (enrollCtx) {
        new Chart(enrollCtx, {
            type: 'bar',
            data: { labels: ['BCA', 'MCA', 'B.Tech', 'MBA'], datasets: [{ label: 'Students', data: [0, 0, 0, 0], backgroundColor: ['#4361ee', '#f72585', '#4cc9f0', '#7209b7'] }] },
            options: { responsive: true, maintainAspectRatio: false }
        });
        // Load real data
        apiGet('admin_api.php', { action: 'get_courses' }).then(r => {
            if (r.success) {
                const chart = Chart.getChart(enrollCtx);
                if (chart) {
                    chart.data.labels = r.courses.map(c => c.code);
                    chart.data.datasets[0].data = r.courses.map(c => c.student_count);
                    chart.update();
                }
            }
        });
    }
    if (passCtx) {
        new Chart(passCtx, {
            type: 'doughnut',
            data: { labels: ['Pass', 'Fail', 'Absent'], datasets: [{ data: [0, 0, 0], backgroundColor: ['#4caf50', '#f44336', '#ff9800'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });
        apiGet('admin_api.php', { action: 'get_stats' }).then(r => {
            if (r.success) {
                const chart = Chart.getChart(passCtx);
                const pass = parseInt(r.pass_count) || 0;
                const fail = parseInt(r.fail_count) || 0;
                const absent = parseInt(r.absent_count) || 0;
                if (chart) { chart.data.datasets[0].data = [pass, fail, absent]; chart.update(); }
            }
        });
    }
}

function updateEnrollmentChart() { }
function updatePassChart() { }

// ── Admin: Sidebar section switcher ──────────────────────────
function showSection(section) {
    const sections = ['dashboard', 'students', 'student_results', 'courses', 'subjects', 'marks', 'results', 'reports'];
    sections.forEach(s => {
        const el = document.getElementById(s + 'Section');
        if (el) el.style.display = (s === section) ? 'block' : 'none';
    });
    document.querySelectorAll('.admin-menu li').forEach(li => li.classList.remove('active'));
    const activeLink = document.querySelector(`.admin-menu li a[onclick*="'${section}'"]`);
    if (activeLink) activeLink.closest('li').classList.add('active');

    // Section specific inits
    if (section === 'student_results') {
        loadStudentResultsList();
        const deptSel = document.getElementById('srDeptFilter');
        if (deptSel && deptSel.options.length <= 1) {
            apiGet('admin_api.php', { action: 'get_departments' }).then(r => {
                if (r.success) {
                    deptSel.innerHTML = '<option value="">All Departments</option>' +
                        r.departments.map(d => `<option value="${d.name}">${d.name}</option>`).join('');
                }
            });
        }
    }
}

function toggleSidebar() {
    const sidebars = document.querySelectorAll('.dashboard-sidebar');
    sidebars.forEach(sidebar => sidebar.classList.toggle('active'));
}

function globalSearch(query) {
    if (query.length < 2) return;
    searchStudents(query);
}

// ── Admin: Search Students ─────────────────────────────
function searchStudents(query) {
    if (query.length < 2) {
        // If search is cleared, show all students again
        loadStudentsTable();
        return;
    }
    // Client-side filter on allStudentsTable
    const tables = ['studentsTableBody', 'allStudentsTable'];
    tables.forEach(tbId => {
        const tb = document.getElementById(tbId);
        if (!tb) return;
        const rows = tb.querySelectorAll('tr');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query.toLowerCase()) ? '' : 'none';
        });
    });
}

// ── Admin: Load Absentees ──────────────────────────────
async function loadAbsentees() {
    const r = await apiGet('admin_api.php', { action: 'get_absentees' });
    const tb = document.getElementById('absenteesTableBody');
    if (!tb) return;
    if (!r.success || !r.absentees?.length) {
        tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px;color:#888;">No absentees found.</td></tr>';
        return;
    }
    tb.innerHTML = r.absentees.map(a => `
        <tr>
            <td>${a.roll_number}</td>
            <td>${a.full_name}</td>
            <td>${a.course_code}</td>
            <td>Sem ${a.semester}</td>
            <td>${a.subject_code}</td>
            <td>${a.subject_name}</td>
            <td>${a.exam_year}</td>
        </tr>
    `).join('');
}

// ============================================================
//  CHECK RESULT PAGE (public)
// ============================================================
async function checkResult(event) {
    if (event) event.preventDefault();

    const rollNo = document.getElementById('checkRollNo')?.value?.trim();
    const course = document.getElementById('checkCourse')?.value;
    const semester = document.getElementById('checkSemester')?.value;
    const year = document.getElementById('checkYear')?.value;

    if (!rollNo || !course || !semester || !year) {
        showAlert('Please fill all fields', 'error');
        return;
    }

    const r = await apiPost('student_api.php', {
        action: 'check_result',
        roll_number: rollNo,
        course,
        semester,
        exam_year: year
    });

    if (!r.success) {
        showAlert(r.message, 'error');
        return;
    }

    const s = r.student;
    const sum = r.summary;

    // Update Student Identity Info
    setTextById('displayStudentName', s.name);
    setTextById('displayRollNo', s.roll_number);
    setTextById('displayCourse', s.course);
    setTextById('displaySemester', s.semester);
    setTextById('displaySemesterNo', s.semester);
    setTextById('displayExamDetails', `Examination: ${year} | Semester ${s.semester}`);

    // Update Marks Table
    const tbody = document.getElementById('displayMarksTable');
    if (tbody) {
        tbody.innerHTML = r.marks.map(m => `
            <tr${m.attendance_status === 'ABSENT' ? ' style="background:#fff3e0;"' : ''}>
                <td>${m.subject_code}</td>
                <td>${m.subject_name}</td>
                <td>${m.attendance_status === 'ABSENT' ? '<span style="color:#ff9800;font-weight:600;">AB</span>' : m.theory_marks}</td>
                <td>${m.attendance_status === 'ABSENT' ? '<span style="color:#ff9800;font-weight:600;">AB</span>' : m.practical_marks}</td>
                <td>${m.attendance_status === 'ABSENT' ? '0' : m.total_marks}</td>
                <td>${m.max_total}</td>
                <td>${m.attendance_status === 'ABSENT' ? '-' : m.grade}</td>
                <td><span class="badge ${m.attendance_status === 'ABSENT' ? 'absent' : (m.status === 'PASS' ? 'pass' : 'fail')}">${m.attendance_status === 'ABSENT' ? 'ABSENT' : m.status}</span></td>
            </tr>
        `).join('');
    }

    // Update Table Footer (Grand Total) - fix index to avoid overwriting "Grand Total" label
    const footerStrong = document.querySelectorAll('.marks-table tfoot td strong');
    if (footerStrong.length >= 3) {
        footerStrong[1].textContent = sum.total_obtained;
        footerStrong[2].textContent = sum.total_max;
    }

    // Update Summary Boxes
    const summaryItems = document.querySelectorAll('.result-summary .summary-item');
    summaryItems.forEach(item => {
        const label = item.querySelector('.summary-label')?.textContent?.toLowerCase();
        const valueEl = item.querySelector('.summary-value');
        if (!valueEl) return;

        if (label.includes('total')) valueEl.textContent = `${sum.total_obtained}/${sum.total_max}`;
        if (label.includes('percentage')) valueEl.textContent = sum.percentage + '%';
        if (label.includes('grade')) valueEl.textContent = sum.grade;
        if (label.includes('status')) {
            valueEl.textContent = sum.status;
            valueEl.className = 'summary-value ' + (sum.status === 'PASS' ? 'pass' : 'fail');
        }
    });

    // Toggle Display
    const formCard = document.getElementById('checkResultForm')?.closest('.check-result-card');
    if (formCard) formCard.style.display = 'none';
    const displayArea = document.getElementById('resultDisplay');
    if (displayArea) displayArea.style.display = 'block';
}

function checkAnother() {
    document.getElementById('checkResultForm').closest('.check-result-card').style.display = 'block';
    document.getElementById('resultDisplay').style.display = 'none';
    document.getElementById('checkResultForm')?.reset();
}

function downloadResultPDF() { window.print(); }

// ============================================================
//  VIEW-RESULT.PHP  – helper to load data
// ============================================================
async function loadViewResultPage() {
    const session = await guardPage('student');
    if (!session) return;

    const result = await apiGet('student_api.php', { action: 'get_results', semester: session.semester });
    const profile = await apiGet('student_api.php', { action: 'get_profile' });

    if (!result.success || !profile.success) return;

    const s = profile.profile;
    // Fill static elements on view-result.html
    document.querySelectorAll('[data-field="student_name"]').forEach(el => el.textContent = s.full_name);
    document.querySelectorAll('[data-field="roll_number"]').forEach(el => el.textContent = s.roll_number);
    document.querySelectorAll('[data-field="father_name"]').forEach(el => el.textContent = s.father_name || '–');
    document.querySelectorAll('[data-field="course_name"]').forEach(el => el.textContent = s.course_name);
    document.querySelectorAll('[data-field="semester"]').forEach(el => el.textContent = session.semester);
    document.querySelectorAll('[data-field="reg_no"]').forEach(el => el.textContent = s.registration_no || '–');

    const tbody = document.querySelector('.marks-table tbody');
    if (tbody && result.marks) {
        tbody.innerHTML = result.marks.map((m, i) => `
            <tr>
                <td>${i + 1}</td>
                <td>${m.subject_code}</td>
                <td>${m.subject_name}</td>
                <td>${m.theory_marks}</td>
                <td>${m.practical_marks}</td>
                <td>${m.total_marks}</td>
                <td>${m.max_total}</td>
                <td>${m.grade}</td>
                <td class="${m.status === 'PASS' ? 'pass-highlight' : 'fail-highlight'}">${m.status}</td>
            </tr>
        `).join('');

        const tfoot = document.querySelector('.marks-table tfoot tr');
        if (tfoot) {
            tfoot.innerHTML = `
                <td colspan="5" style="text-align:right;font-weight:bold;">Grand Total</td>
                <td style="font-weight:bold;">${result.summary.total_obtained}</td>
                <td style="font-weight:bold;">${result.summary.total_max}</td>
                <td colspan="2"></td>
            `;
        }

        // Summary box
        const boxes = document.querySelectorAll('.summary-item');
        boxes.forEach(box => {
            const label = box.querySelector('.summary-label')?.textContent?.toLowerCase();
            const val = box.querySelector('.summary-value');
            if (!val) return;
            if (label?.includes('total')) val.textContent = `${result.summary.total_obtained}/${result.summary.total_max}`;
            if (label?.includes('percentage')) val.textContent = result.summary.percentage + '%';
            if (label?.includes('sgpa')) val.textContent = result.summary.sgpa;
            if (label?.includes('grade')) val.textContent = result.summary.grade;
        });
    }
}

function downloadPDF() { window.print(); }

// ── Tiny helper ───────────────────────────────────────────────
function setTextById(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
}

// ============================================================
//  EXCEL IMPORT LOGIC
// ============================================================
let importedData = [];

// Drag and Drop Setup
function initImportModal() {
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('excelFileInput');

    if (!dropZone) return;

    dropZone.onclick = () => fileInput.click();

    dropZone.ondragover = (e) => { e.preventDefault(); dropZone.classList.add('dragover'); };
    dropZone.ondragleave = () => dropZone.classList.remove('dragover');
    dropZone.ondrop = (e) => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        if (e.dataTransfer.files.length) handleSelectedFile(e.dataTransfer.files[0]);
    };

    fileInput.onchange = (e) => {
        if (e.target.files.length) handleSelectedFile(e.target.files[0]);
    };
}

function handleSelectedFile(file) {
    const validTypes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'text/csv'];
    if (!validTypes.includes(file.type) && !file.name.endsWith('.csv')) {
        showAlert('Invalid file type. Please upload Excel or CSV.', 'error');
        return;
    }

    // Show file info
    document.getElementById('fileName').textContent = file.name;
    document.getElementById('fileSize').textContent = (file.size / 1024).toFixed(1) + ' KB';
    document.getElementById('fileInfo').classList.add('show');
    document.getElementById('dropZone').style.display = 'none';

    // Parse with SheetJS
    const reader = new FileReader();
    reader.onload = (e) => {
        const data = new Uint8Array(e.target.result);
        // Added cellDates: true to parse Excel dates correctly
        const workbook = XLSX.read(data, { type: 'array', cellDates: true });
        const firstSheetName = workbook.SheetNames[0];
        const worksheet = workbook.Sheets[firstSheetName];

        // Convert to JSON and FILTER OUT ALL EMPTY ROWS
        let rawData = XLSX.utils.sheet_to_json(worksheet, { defval: "" });
        importedData = rawData.filter(r => {
            if (currentImportMode === 'students') {
                const name = (r.full_name || r['Full Name'] || r['name'] || '').toString().trim();
                return name !== "";
            } else if (currentImportMode === 'subjects') {
                const code = (r.code || r['Code'] || r['subject_code'] || '').toString().trim();
                return code !== "";
            } else {
                const roll = (r.roll_number || r['Roll No'] || r['roll'] || '').toString().trim();
                return roll !== "";
            }
        });

        if (importedData.length === 0) {
            const fields = { students: 'full_name', subjects: 'code', marks: 'roll_number' };
            showAlert(`No valid records found. Please ensure the file has a "${fields[currentImportMode]}" column.`, 'error');
            removeImportFile();
            return;
        }

        renderPreviewTable(importedData);
        document.getElementById('importSubmitBtn').disabled = false;
        document.getElementById('previewSection').classList.add('show');
    };
    reader.readAsArrayBuffer(file);
}

function renderPreviewTable(data) {
    const head = document.getElementById('previewTableHead');
    const body = document.getElementById('previewTableBody');
    const count = document.getElementById('rowCount');

    if (!data.length) return;

    // Headers
    const headers = Object.keys(data[0]);
    head.innerHTML = `<tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr>`;

    // Rows (max 10 for preview)
    const previewRows = data.slice(0, 10);
    body.innerHTML = previewRows.map(row => `
        <tr>${headers.map(h => `<td>${row[h] || '–'}</td>`).join('')}</tr>
    `).join('');

    count.textContent = data.length + ' rows found';
}

function removeImportFile() {
    document.getElementById('fileInfo').classList.remove('show');
    document.getElementById('dropZone').style.display = 'block';
    document.getElementById('previewSection').classList.remove('show');
    document.getElementById('excelFileInput').value = '';
    document.getElementById('importSubmitBtn').disabled = true;
    importedData = [];
}

async function startImport() {
    if (!importedData || importedData.length === 0) return;

    const btn = document.getElementById('importSubmitBtn');
    const prog = document.getElementById('importProgress');
    if (btn) btn.disabled = true;
    if (prog) prog.classList.add('show');

    // Prepare API call based on mode
    let action = '';
    let payload = {};

    if (currentImportMode === 'students') {
        const courseId = document.getElementById('importCourseId').value;
        const defaultSem = document.getElementById('importDefaultSemester').value;
        // Course is optional if CSV has course_code column
        const hasCourseCode = importedData.some(r => r.course_code || r.course);
        if (!courseId && !hasCourseCode) { showAlert('Please select a course or include a course_code column in CSV', 'error'); btn.disabled = false; return; }

        action = 'import_students';
        payload = { action, course_id: courseId || '0', default_semester: defaultSem, students: JSON.stringify(importedData) };
    } else if (currentImportMode === 'subjects') {
        const courseId = document.getElementById('importSubjectCourseId')?.value || '';
        action = 'import_subjects';
        payload = { action, course_id: courseId, subjects: JSON.stringify(importedData) };
    } else {
        const examYear = document.getElementById('importExamYear').value;
        action = 'import_marks';
        payload = { action, exam_year: examYear, marks: JSON.stringify(importedData) };
    }

    const res = await apiPost('admin_api.php', payload);

    if (prog) prog.classList.remove('show');
    if (btn) btn.disabled = false;

    if (res.success) {
        // Show results
        const resultDiv = document.getElementById('importResults');
        resultDiv.innerHTML = `
            <div style="text-align:center; padding:10px;">
                <i class="fas fa-check-circle" style="font-size:2rem;color:#388e3c;"></i>
                <h4 style="margin:10px 0;">Import Successful!</h4>
                <p>Processed: <strong>${importedData.length}</strong> records</p>
                <button class="btn btn-primary" onclick="closeImportModal()" style="margin-top:15px;">Close & Refresh</button>
            </div>
        `;
        resultDiv.classList.add('show', 'success');

        if (currentImportMode === 'students') {
            await loadStudentsTable();
            await loadAdminStats();
        } else if (currentImportMode === 'subjects') {
            await loadSubjectsTable();
            await loadAdminStats();
        } else {
            await loadAdminStats();
        }
    } else {
        showAlert(res.message, 'error');
    }
}

// ── Import Mode & Tabs ───────────────────────────────────
let currentImportMode = 'students'; // 'students' or 'marks'

function switchImportMode(mode) {
    currentImportMode = mode;

    // UI update for mode buttons
    document.getElementById('modeBtnStudents')?.classList.toggle('active', mode === 'students');
    document.getElementById('modeBtnSubjects')?.classList.toggle('active', mode === 'subjects');
    document.getElementById('modeBtnMarks')?.classList.toggle('active', mode === 'marks');

    // Toggle content fields
    document.getElementById('importStudentFields').style.display = (mode === 'students') ? 'block' : 'none';
    document.getElementById('importSubjectFields').style.display = (mode === 'subjects') ? 'block' : 'none';
    document.getElementById('importMarksFields').style.display = (mode === 'marks') ? 'block' : 'none';

    // Toggle templates
    document.getElementById('studentTemplate').style.display = (mode === 'students') ? 'block' : 'none';
    document.getElementById('subjectsTemplate').style.display = (mode === 'subjects') ? 'block' : 'none';
    document.getElementById('marksTemplate').style.display = (mode === 'marks') ? 'block' : 'none';

    // Update import button text
    const btnLabels = { students: 'Import Students', subjects: 'Import Subjects', marks: 'Import Marks' };
    const btn = document.getElementById('importSubmitBtn');
    if (btn) btn.innerHTML = `<i class="fas fa-upload"></i> ${btnLabels[mode]}`;

    // Reset file selection
    removeImportFile();
}

function switchImportTab(tab) {
    // UI update for tab buttons
    document.getElementById('tabBtnUpload')?.classList.toggle('active', tab === 'upload');
    document.getElementById('tabBtnTemplate')?.classList.toggle('active', tab === 'template');

    // Toggle tab visibility
    document.querySelectorAll('.import-tab-content').forEach(c => c.classList.remove('active'));
    document.getElementById(tab + 'Tab').classList.add('active');

    // Toggle footer buttons
    document.getElementById('importModalFooter').style.display = (tab === 'upload') ? 'flex' : 'none';
}

function closeImportModal() {
    closeModal('importModal');
    // Reset modal state
    removeImportFile();
    document.getElementById('importProgress').classList.remove('show');
    document.getElementById('importResults').classList.remove('show');
}

function downloadExcelTemplate() {
    const headers = [
        ['full_name', 'email', 'phone', 'dob', 'father_name', 'mother_name', 'address', 'semester', 'password']
    ];
    const ws = XLSX.utils.aoa_to_sheet(headers);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Students");
    XLSX.writeFile(wb, "Student_Import_Template.xlsx");
}

function downloadMarksTemplate() {
    const headers = [
        ['roll_number', 'subject_code', 'theory', 'practical', 'semester']
    ];
    const ws = XLSX.utils.aoa_to_sheet(headers);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Marks");
    XLSX.writeFile(wb, "Marks_Import_Template.xlsx");
}

function downloadSubjectsTemplate() {
    const headers = [
        ['code', 'name', 'course_code', 'semester', 'max_theory', 'max_practical']
    ];
    const ws = XLSX.utils.aoa_to_sheet(headers);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Subjects");
    XLSX.writeFile(wb, "Subjects_Import_Template.xlsx");
}

// Update auto-init to include Import Modal
document.addEventListener('DOMContentLoaded', () => {
    const path = window.location.pathname;
    const page = path.split('/').pop().toLowerCase();

    if (page.includes('admindashboard.html')) {
        initAdminDashboard();
        initImportModal();
    } else if (page.includes('student-dashboard.html')) {
        initStudentDashboard();
    } else if (page.includes('view-result.php')) {
        loadViewResultPage();
    } else if (page === '' || page.includes('index.html') || page.includes('index.php')) {
        loadLandingPageStats();
    }
});

async function loadLandingPageStats() {
    const r = await apiGet('student_api.php', { action: 'get_public_stats' });
    if (r.success) {
        setTextById('total_students_v', r.total_students);
        setTextById('total_courses_v', r.total_courses);
        setTextById('total_subjects_v', r.total_subjects);
        setTextById('total_results_v', r.total_results);
    }
}

async function resetAllStudents() {
    if (!confirm('WARNING: This will DELETE ALL students and their results forever. Are you sure?')) return;
    if (!confirm('FINAL WARNING: This action cannot be undone. Clear all student data?')) return;

    const r = await apiPost('admin_api.php', { action: 'reset_all_students' });
    if (r.success) {
        alert('Student database cleared! Everything has been reset.');
        location.reload();
    } else {
        alert(r.message);
    }
}

// Smooth scroll for "Send Query" button
document.querySelectorAll('.query-btn').forEach(btn => {
    btn.addEventListener('click', function (e) {
        const targetId = this.getAttribute('href');
        if (targetId && targetId !== '#') {
            e.preventDefault();
            const targetElement = document.querySelector(targetId);
            if (targetElement) {
                targetElement.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }
    });
});

// ── Student Dashboard Initialization ─────────────────────
async function initStudentDashboard() {
    try {
        const r = await apiGet('session_check.php');
        if (!r.success) {
            window.location.href = 'login.html';
            return;
        }
        if (r.role !== 'student') {
            window.location.href = 'login.html';
            return;
        }

        // Update all name/roll placeholders
        const firstName = (r.name || 'Student').split(' ')[0];
        setTextById('displayName', r.name || 'Student');
        setTextById('displayRoll', r.roll_number || '');
        setTextById('studentName', r.name || 'Student');
        setTextById('studentRoll', 'Roll: ' + (r.roll_number || ''));
        setTextById('studentCourse', (r.course_name || 'Course') + ' - Semester ' + (r.semester || '1'));
        setTextById('welcomeName', firstName);

        // Update topbar user info
        const topUserName = document.querySelector('.user-name');
        const topUserRoll = document.querySelector('.user-roll');
        if (topUserName) topUserName.textContent = r.name || 'Student';
        if (topUserRoll) topUserRoll.textContent = r.roll_number || '';

        // Load student results/stats
        await loadStudentStats(r.student_id, r.semester);
    } catch (err) {
        console.error('Student dashboard init error:', err);
    }
}

async function loadStudentStats(studentId, semester) {
    try {
        const r = await apiGet('student_api.php', { action: 'get_semester_summary', student_id: studentId });
        if (r.success && r.semesters && r.semesters.length > 0) {
            const latest = r.semesters[r.semesters.length - 1];
            // Update SGPA card
            const sgpaEl = document.querySelector('.stat-value');
            if (sgpaEl) sgpaEl.textContent = (latest.percentage / 10).toFixed(1);

            // Update Subjects count
            const subCountEl = document.querySelectorAll('.stat-value');
            if (subCountEl[2]) subCountEl[2].textContent = latest.subject_count || r.semesters.length;
        }
    } catch (err) {
        console.error('Failed to load student stats:', err);
    }
}

// ── Publish Results (Admin) ──────────────────────────────
async function publishResults() {
    const examYear = prompt('Enter Exam Year (e.g., 2026):', new Date().getFullYear());
    if (!examYear) return;

    const semester = prompt('Enter Semester to publish (1-8), or leave blank for ALL semesters:', '');

    const payload = { action: 'publish_results', exam_year: examYear };
    if (semester) payload.semester = semester;

    const r = await apiPost('admin_api.php', payload);
    if (r.success) {
        showAlert(r.message, 'success');
        await loadAdminStats();
    } else {
        showAlert(r.message || 'Failed to publish results', 'error');
    }
}

// ── View Result Page Init ────────────────────────────────
async function loadViewResultPage() {
    // This page is server-rendered (PHP), no JS init needed
    console.log('View result page loaded');
}

