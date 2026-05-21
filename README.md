# GradeSphere – Setup Guide
## PHP + MySQL (XAMPP)

---

## 📁 Files You Received

| File | Purpose |
|------|---------|
| `erms_database.sql` | Creates all DB tables + demo data |
| `config.php` | DB connection + session helpers |
| `login.php` | Handles login (AJAX POST) |
| `logout.php` | Destroys session |
| `session_check.php` | Auth guard called by dashboards |
| `admin_api.php` | All admin CRUD actions |
| `student_api.php` | Student results + public check |
| `view-result.php` | Printable result page with live data |
| `main.js` | **REPLACE** your old main.js with this |

---

## 🚀 Quick Setup (5 Steps)

### Step 1 – Start XAMPP
Open XAMPP Control Panel → Start **Apache** and **MySQL**.

### Step 2 – Create the Database
1. Open browser → `http://localhost/phpmyadmin`
2. Click **Import** tab
3. Choose `erms_database.sql` → Click **Go**

This creates `erms_db` with all tables and demo data.

### Step 3 – Copy Files to htdocs
Copy **all your files** (HTML, CSS, images, PHP files) into:
```
`C:\xampp\htdocs\GradeSphere\`
```
Your folder should look like:
```
erms/
├── index.html
├── login.html
├── admindashboard.html
├── student-dashboard.html
├── check-result.html
├── view-result.html        ← keep for non-PHP fallback
├── view-result.php         ← NEW: live DB version
├── style.css
├── main.js                 ← REPLACE with the new one
├── config.php
├── login.php
├── logout.php
├── session_check.php
├── admin_api.php
├── student_api.php
├── pic1_jpg.jpeg ... (all images)
```

### Step 4 – Access the Site
Open: http://localhost/GradeSphere/index.html

### Step 5 – Login & Test

| Role | Username | Password |
|------|----------|----------|
| Admin | `admin` | `admin123` |
| Student | `STU001` | `pass123` |

---

## ✅ What Works After Setup

### Admin Can:
- ✅ Login / Logout (stored in `activity_log` table)
- ✅ Add students → auto-generates roll number + stores in DB
- ✅ Added students instantly visible in dashboard table
- ✅ Delete students (removes user + results)
- ✅ Add / delete courses
- ✅ Add / delete subjects
- ✅ Enter marks for any student/subject
- ✅ Publish results (makes them visible to students)
- ✅ View enrollment chart by course (live data)
- ✅ View pass/fail doughnut chart (live data)

### Student Can:
- ✅ Login / Logout (session tracked)
- ✅ View their results in dashboard
- ✅ Open result modal with marks table
- ✅ Open printable `view-result.php` page
- ✅ Print / Download (browser print dialog)

### Public (No Login):
- ✅ `check-result.html` → enter roll/course/semester → shows published results

---

## 🗄️ Database Tables

| Table | Description |
|-------|-------------|
| `users` | Login accounts for admin + all students |
| `activity_log` | Every login/logout/action logged with IP |
| `courses` | BCA, MCA, B.Tech, MBA, etc. |
| `students` | Student profiles linked to users |
| `subjects` | Subjects per course+semester |
| `results` | Marks per student per subject (auto-calculates grade/status) |

---

## 🔒 Security Notes
- Passwords are hashed with `password_hash()` (bcrypt)
- Sessions have 1-hour timeout
- All inputs use PDO prepared statements (SQL injection safe)
- Role-based access: admin endpoints reject student sessions

---

## ⚙️ Config Changes (if needed)

Open `config.php` and update if your XAMPP MySQL has a password:
```php
define('DB_USER', 'root');
define('DB_PASS', '');       // ← Add your MySQL password here if set
define('DB_NAME', 'erms_db');
```

---

## 🐛 Troubleshooting

| Problem | Fix |
|---------|-----|
| "Database connection failed" | Make sure MySQL is running in XAMPP |
| Login says "Invalid credentials" | Re-run the SQL file in phpMyAdmin |
| Blank page after login | Check Apache error log in XAMPP |
| Results not showing | Admin must click "Publish Results" first |
| Images not loading | Make sure image files are in the same folder |
