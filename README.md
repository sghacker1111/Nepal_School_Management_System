# Nepal Mathematics Learning & School Result Management System

## Complete Web-Based ERP for Nepali Schools

A comprehensive school management system built with PHP, MySQL/MariaDB, Bootstrap 5, and JavaScript. Designed specifically for Nepali schools following IEMIS/EMIS structure and CDC/NEB grading standards.

---

## 📋 Features

### Core Modules
1. **Authentication & User Management** - Multi-role login system with secure password hashing
2. **School Profile Management** - Complete school information with logo and signature uploads
3. **Academic Year System** - BS/AD date support with year switching
4. **Class & Section Management** - ECD to Class 10 support
5. **Student Management** - IEMIS-style student profiles with comprehensive fields
6. **Teacher/Staff Management** - Complete staff profiles with assignments
7. **Subject Management** - Flexible subject configuration per class
8. **Mathematics Learning Portal** - Class-wise lessons, quizzes, and practice
9. **Attendance System** - Daily attendance with BS/AD dates
10. **Exam Management** - Terminal exams, unit tests, practicals
11. **Result System** - Both percentage-based and GPA-based grading
12. **Progress Reports** - A4 printable report cards
13. **Public Result Check** - Online result verification portal
14. **Admit Card Generation** - 4 cards per A4 page with signatures

### User Roles
- Super Admin
- School Admin
- Principal
- Teacher
- Administrative Officer
- Exam Officer
- Accountant
- Student
- Parent/Guardian

---

## 🚀 Installation

### Prerequisites
- PHP 8.0 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Apache/Nginx web server
- Composer (optional)

### Step-by-Step Installation

1. **Clone/Copy Files**
   ```bash
   Copy all files to your web server directory
   Example: /var/www/html/nepal_school_erp
   ```

2. **Create Database**
   ```sql
   CREATE DATABASE nepal_school_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

3. **Import Database Schema**
   ```bash
   mysql -u root -p nepal_school_erp < database.sql
   ```

4. **Configure Database Connection**
   Edit `config/config.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'nepal_school_erp');
   define('DB_USER', 'root');
   define('DB_PASS', 'your_password');
   ```

5. **Set Permissions**
   ```bash
   chmod 777 uploads/
   chmod 777 uploads/students/
   chmod 777 uploads/staff/
   chmod 777 uploads/school/
   ```

6. **Access Application**
   ```
   http://localhost/nepal_school_erp
   ```

7. **Default Login Credentials**
   ```
   Username: admin
   Password: admin123
   ```
   **⚠️ Change this immediately after first login!**

---

## 📁 Project Structure

```
nepal_school_erp/
├── config/
│   └── config.php              # Configuration settings
├── includes/
│   ├── database.php            # Database connection class
│   ├── auth.php                # Authentication functions
│   ├── functions.php           # Helper functions
│   └── header.php              # Common header/layout
├── modules/
│   ├── auth/
│   │   ├── login.php           # Login page
│   │   ├── logout.php          # Logout handler
│   │   └── profile.php         # User profile
│   ├── students/
│   │   ├── index.php           # Student list
│   │   ├── add.php             # Add student
│   │   ├── edit.php            # Edit student
│   │   ├── view.php            # View student profile
│   │   └── admit_card.php      # Generate admit card
│   ├── teachers/
│   ├── classes/
│   ├── subjects/
│   ├── exams/
│   ├── results/
│   ├── attendance/
│   ├── learning/
│   └── reports/
├── assets/
│   ├── css/
│   │   └── style.css           # Custom styles
│   ├── js/
│   │   └── main.js             # JavaScript functions
│   └── images/
├── uploads/                     # File upload directory
├── database.sql                 # Database schema
└── index.php                    # Dashboard
```

---

## 🔒 Security Features

- **Password Hashing**: bcrypt using `password_hash()`
- **SQL Injection Prevention**: PDO prepared statements
- **XSS Protection**: Input sanitization with `htmlspecialchars()`
- **CSRF Protection**: Token-based validation
- **Session Management**: Timeout and secure session handling
- **Role-Based Access Control**: Permission-based page access
- **Audit Logging**: Track all critical actions
- **File Upload Validation**: Type and size restrictions

---

## 📊 Database Tables

### Core Tables
- `schools` - School information
- `users` - User accounts
- `roles` - User roles
- `activity_logs` - Audit trail

### Academic Structure
- `academic_years` - BS/AD academic years
- `classes` - Class definitions
- `sections` - Class sections
- `subjects` - Subject catalog

### Student Data
- `students` - Student profiles (IEMIS-style)
- `student_documents` - Document uploads
- `attendances` - Daily attendance

### Exam & Results
- `exams` - Exam definitions
- `exam_subjects` - Exam-subject configuration
- `marks` - Student marks
- `grading_scales` - Grade boundaries

---

## 🎯 Key Features Explained

### 1. Student Management
- Comprehensive IEMIS-inspired fields
- Photo upload support
- Parent/guardian information
- Address details (Province, District, Municipality, Ward)
- Caste/ethnicity, religion, scholarship tracking
- Disability status
- EMIS ID support

### 2. Exam Admit Card
- **4 cards per A4 page** layout
- Student photo inclusion
- Automatic school logo and signatures
- Exam details display
- Terms and conditions
- Signature lines for student, coordinator, and principal

### 3. Result System
- **Two modes**: Percentage-based and GPA-based
- Configurable grading scales
- Credit hour support
- Theory/practical separation
- Auto-calculation of GPA
- Nepal CDC/NEB compliant (editable)

### 4. Mathematics Learning
- Class-wise lessons (Nursery to Class 10)
- Chapter and topic organization
- Quiz system with auto-grading
- Practice questions
- Progress tracking

### 5. Attendance
- Daily marking (Present, Absent, Late, Leave)
- BS/AD date support
- Monthly summaries
- Percentage calculations

---

## 🛠️ Customization

### Adding New Classes
1. Go to Classes module
2. Click "Add Class"
3. Enter name (English and Nepali)
4. Select level (ECD/Basic/Secondary)
5. Choose grading type

### Configuring Grading Scale
1. Navigate to Settings > Grading Scale
2. Edit percentage ranges
3. Set grade letters and grade points
4. Configure pass/fail rules

### School Profile Setup
1. Go to Settings > School Profile
2. Upload school logo
3. Upload principal signature
4. Upload exam coordinator signature
5. Set school information

---

## 📱 Responsive Design

- Desktop optimized dashboard
- Tablet-friendly forms
- Mobile-responsive tables
- Print-friendly layouts

---

## 🔧 Technology Stack

| Component | Technology |
|-----------|-----------|
| Backend | PHP 8+ |
| Database | MySQL/MariaDB |
| Frontend | HTML5, CSS3, Bootstrap 5 |
| JavaScript | Vanilla JS, jQuery |
| Charts | Chart.js (optional) |
| Tables | DataTables |
| Alerts | SweetAlert2 |
| Icons | Font Awesome |

---

## 📝 Default Data

### Pre-loaded Classes
- Nursery, LKG, UKG
- Class 1 to Class 10

### Sample Subjects
- Nepali, English, Mathematics
- Science, Social Studies
- Optional subjects for Class 9-10

### Grading Scale (Secondary)
- A+ (80-100%) = 4.0 GPA
- A (70-79%) = 3.6 GPA
- B+ (60-69%) = 3.2 GPA
- B (50-59%) = 2.8 GPA
- C (40-49%) = 2.4 GPA
- D (30-39%) = 2.0 GPA
- F (0-29%) = 0.0 GPA

---

## ⚠️ Important Notes

1. **Backup Regularly**: Export database frequently
2. **Secure Admin Account**: Change default password immediately
3. **File Uploads**: Ensure `uploads/` directory is writable
4. **Production Settings**: Disable error display in production
5. **HTTPS**: Use SSL certificate for production deployment
6. **PHP Version**: Minimum PHP 8.0 required

---

## 🆘 Troubleshooting

### Database Connection Error
- Check `config/config.php` credentials
- Verify MySQL service is running
- Ensure database exists

### Permission Denied
```bash
chmod -R 777 uploads/
chown -R www-data:www-data uploads/
```

### Session Issues
- Check PHP session configuration
- Ensure `/tmp` or session path is writable

### File Upload Fails
- Check `MAX_FILE_SIZE` in config
- Verify upload_max_filesize in php.ini
- Ensure destination folder exists and is writable

---

## 📞 Support & Credits

Developed for Nepali schools following CDC/NEB guidelines and IEMIS structure.

### Future Enhancements
- SMS integration
- Email notifications
- Online fee management
- Library management
- Transport management
- Hostel management
- Mobile app
- API for third-party integration

---

## 📄 License

This project is proprietary software developed for educational institutions.

---

## 🎓 Version Information

**Version**: 1.0.0  
**Release Date**: 2024  
**Last Updated**: Current

---

*Built with ❤️ for Nepali Education System*
