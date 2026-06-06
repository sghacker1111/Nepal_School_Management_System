# INSTALLATION GUIDE

## Nepal Mathematics Learning & School Result Management System

### Quick Start Guide for Nepali Schools

---

## Prerequisites

Before installing, ensure you have:

1. **Web Server**: Apache or Nginx
2. **PHP**: Version 8.0 or higher
3. **Database**: MySQL 5.7+ or MariaDB 10.3+
4. **PHP Extensions**:
   - pdo_mysql
   - gd (for image processing)
   - mbstring
   - json
   - zip (for Excel export)

---

## Step 1: Database Setup

### Option A: Using phpMyAdmin

1. Open phpMyAdmin in your browser
   ```
   http://localhost/phpmyadmin
   ```

2. Create new database:
   - Click "New" in left sidebar
   - Database name: `nepal_school_erp`
   - Collation: `utf8mb4_unicode_ci`
   - Click "Create"

3. Import database schema:
   - Select `nepal_school_erp` database
   - Click "Import" tab
   - Choose file: `database.sql`
   - Click "Go"

### Option B: Using Command Line

```bash
# Login to MySQL
mysql -u root -p

# Create database
CREATE DATABASE nepal_school_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# Exit MySQL
EXIT;

# Import schema
mysql -u root -p nepal_school_erp < /path/to/database.sql
```

---

## Step 2: File Configuration

### 2.1 Copy Files to Web Directory

**For XAMPP (Windows/Mac):**
```
Copy nepal_school_erp folder to: C:\xampp\htdocs\
```

**For WAMP (Windows):**
```
Copy nepal_school_erp folder to: C:\wamp64\www\
```

**For Linux (Apache):**
```bash
sudo cp -r nepal_school_erp /var/www/html/
```

### 2.2 Configure Database Connection

Edit `config/config.php`:

```php
// Update these values according to your database
define('DB_HOST', 'localhost');
define('DB_NAME', 'nepal_school_erp');
define('DB_USER', 'root');
define('DB_PASS', '');  // Your MySQL password

// Update application URL
define('APP_URL', 'http://localhost/nepal_school_erp');
```

### 2.3 Set Folder Permissions

**Windows (XAMPP/WAMP):**
- Right-click `uploads` folder
- Properties → Security
- Give full control to Everyone

**Linux:**
```bash
cd /var/www/html/nepal_school_erp
chmod -R 777 uploads/
chown -R www-data:www-data uploads/
```

---

## Step 3: Verify PHP Configuration

### Check PHP Version

Create a file `test.php` with:
```php
<?php phpinfo(); ?>
```

Access it: `http://localhost/nepal_school_erp/test.php`

Ensure:
- PHP Version ≥ 8.0
- PDO MySQL extension enabled
- GD library enabled
- max_execution_time ≥ 30
- upload_max_filesize ≥ 5M
- post_max_size ≥ 10M

### Update php.ini if needed

**XAMPP:** `C:\xampp\php\php.ini`
**WAMP:** `C:\wamp64\bin\php\php[version]\php.ini`
**Linux:** `/etc/php/8.0/apache2/php.ini`

```ini
max_execution_time = 300
max_input_time = 300
memory_limit = 256M
upload_max_filesize = 10M
post_max_size = 20M
```

Restart Apache after changes.

---

## Step 4: First Login

1. Open browser and navigate to:
   ```
   http://localhost/nepal_school_erp
   ```

2. You will be redirected to login page

3. Use default credentials:
   ```
   Username: admin
   Password: admin123
   ```

4. **IMPORTANT**: Change password immediately after first login!

---

## Step 5: Initial Setup

### 5.1 Configure School Profile

1. Go to Settings → School Profile
2. Fill in school information:
   - School Name (English & Nepali)
   - EMIS Code
   - Address
   - Phone, Email
3. Upload:
   - School Logo (recommended: 200x200px)
   - Principal Signature (recommended: 150x50px)
   - Exam Coordinator Signature (recommended: 150x50px)
4. Save

### 5.2 Verify Academic Year

1. Go to Settings → Academic Years
2. Ensure current year is active (e.g., 2081/2024)
3. Add new years as needed

### 5.3 Setup Classes

Classes are pre-loaded, but you can:
1. Go to Classes module
2. Edit existing classes
3. Add sections (A, B, C...)
4. Assign class teachers

### 5.4 Configure Subjects

1. Go to Subjects module
2. Review pre-loaded subjects
3. Add/edit subjects per class
4. Set full marks, pass marks, credit hours

---

## Troubleshooting

### Error: Database Connection Failed

**Solution:**
1. Verify MySQL is running
2. Check database credentials in `config/config.php`
3. Ensure database exists
4. Check user permissions

```sql
-- Grant privileges
GRANT ALL PRIVILEGES ON nepal_school_erp.* TO 'root'@'localhost';
FLUSH PRIVILEGES;
```

### Error: Session Not Working

**Solution:**
1. Check PHP session configuration
2. Ensure temp directory is writable
3. Clear browser cookies/cache

### Error: File Upload Fails

**Solution:**
```bash
# Linux
chmod 777 uploads/
chmod 777 uploads/students/
chmod 777 uploads/staff/

# Check php.ini settings
upload_max_filesize = 10M
post_max_size = 20M
```

### Error: Blank White Page

**Solution:**
1. Enable error display temporarily
2. In `config/config.php`:
   ```php
   ini_set('display_errors', 1);
   error_reporting(E_ALL);
   ```
3. Check error logs
4. Fix reported errors

### Error: CSRF Token Mismatch

**Solution:**
1. Clear browser cache
2. Delete session cookies
3. Try incognito/private mode
4. Check session configuration

---

## Security Recommendations

### For Production Deployment:

1. **Change Default Password**
   - Immediately after installation
   - Use strong password (min 12 characters)

2. **Enable HTTPS**
   ```bash
   # Install SSL certificate
   # Update APP_URL to https://
   ```

3. **Disable Error Display**
   ```php
   // In config/config.php
   ini_set('display_errors', 0);
   error_reporting(0);
   ```

4. **Secure Database**
   ```sql
   -- Create dedicated user
   CREATE USER 'school_user'@'localhost' IDENTIFIED BY 'strong_password';
   GRANT ALL PRIVILEGES ON nepal_school_erp.* TO 'school_user'@'localhost';
   FLUSH PRIVILEGES;
   ```

5. **Regular Backups**
   ```bash
   # Daily backup script
   mysqldump -u root -p nepal_school_erp > backup_$(date +%Y%m%d).sql
   ```

6. **Update Regularly**
   - Monitor for security patches
   - Update PHP version
   - Keep MySQL updated

---

## Data Migration from Existing System

If migrating from another system:

1. **Export Data** from old system to CSV/Excel
2. **Map Fields** to new database structure
3. **Use Import Tools** (available in Student/Teacher modules)
4. **Verify Data** after import
5. **Test All Modules** before going live

---

## Support & Training

### For School Administrators:

1. **Watch Tutorial Videos** (if available)
2. **Read User Manual** (separate document)
3. **Practice in Demo Mode** first
4. **Contact Support** for issues

### Common Tasks:

- Adding Students: See Student Module Guide
- Creating Exams: See Exam Module Guide
- Entering Marks: See Result Module Guide
- Printing Reports: See Report Module Guide

---

## Backup & Restore

### Create Backup

**Database:**
```bash
mysqldump -u root -p nepal_school_erp > backup.sql
```

**Files:**
```bash
tar -czf backup_files.tar.gz nepal_school_erp/
```

### Restore from Backup

**Database:**
```bash
mysql -u root -p nepal_school_erp < backup.sql
```

**Files:**
```bash
tar -xzf backup_files.tar.gz
```

---

## Performance Optimization

### For Large Schools (1000+ students):

1. **Database Indexing**
   ```sql
   ALTER TABLE students ADD INDEX idx_class (class_id);
   ALTER TABLE marks ADD INDEX idx_exam (exam_id);
   ```

2. **PHP OPcache**
   Enable in php.ini:
   ```ini
   opcache.enable=1
   opcache.memory_consumption=128
   ```

3. **Query Caching**
   ```sql
   SET GLOBAL query_cache_size = 67108864;
   ```

---

## Checklist Before Going Live

- [ ] Database installed and configured
- [ ] School profile completed
- [ ] Academic year set
- [ ] Classes and sections created
- [ ] Subjects configured
- [ ] Teachers added
- [ ] Students enrolled
- [ ] Admin password changed
- [ ] HTTPS enabled (production)
- [ ] Backup system in place
- [ ] Staff trained on system
- [ ] Test all major functions
- [ ] Print test admit cards
- [ ] Test result publishing

---

## Contact & Support

For technical support and queries, refer to the main README.md file.

---

**Installation Completed! 🎉**

You're now ready to use the Nepal School ERP system.

*Built with ❤️ for Nepali Education System*
