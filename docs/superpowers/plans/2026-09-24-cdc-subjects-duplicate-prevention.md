# CDC Nepal Subjects, Duplicate Subject Prevention & 502 Bad Gateway Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Configure CDC Nepal curriculum subjects for all classes (specifically Grades 1-10 with Compulsory and Optional/Local components), strictly block duplicate subjects per class, eliminate 502 Bad Gateway OpenResty timeouts, and ensure hidden optional subjects behave properly across modules.

**Architecture:** 
- Concurrency & Performance: Enable SQLite WAL mode (`PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL; PRAGMA busy_timeout = 5000;`) and guard silent schema migrations with `PRAGMA user_version` to eliminate locking bottlenecks and FastCGI timeouts.
- Curriculum: Seed standard classes and exact CDC Nepal subjects for Classes 1-10 with compulsory and optional/local subjects.
- Duplicate Prevention: Implement multi-layered protection (unique DB index on `(class_id, subject_id)` and server-side name/code validation across `modules/classes/subjects.php`, `modules/subjects/add.php`, and `modules/super_admin/subjects.php`).
- Visibility: Ensure optional subject toggle (`is_active = 0`) correctly hides subjects in question bank, paper generator, exams, and class routines.

**Tech Stack:** PHP 8.x, SQLite (PDO), Bootstrap 5, Git

---

## Global Constraints
- Exactly 16 standard classes: Nursery, LKG, UKG, ECD, Class 1 to 12.
- Class 1-3: Compulsory (Nepali, English, Mathematics, Our Surroundings), Optional/Local (Local Curriculum / Mother Tongue).
- Class 4-5: Compulsory (Nepali, English, Mathematics, Science and Technology, Social Studies and Human Values Education, Health, Physical and Creative Arts), Local Component (Local Curriculum / Mother Tongue).
- Class 6-8: Main Subjects (Nepali, English, Mathematics, Science and Technology, Social Studies and Human Values Education, Health and Physical Education, Local/Mother-Tongue subject).
- Class 9-10: Compulsory (Nepali, English, Mathematics, Science and Technology, Social Studies), Electives (Optional Subject I, Optional Subject II, plus standard electives).
- No class may have duplicate subjects (by ID, English name, or Nepali name).
- Must eliminate 502 Bad Gateway OpenResty errors.

---

### Task 1: Fix 502 Bad Gateway OpenResty & Database Concurrency

**Files:**
- Modify: `includes/database.php:33-75`
- Modify: `modules/classes/index.php:40-51`
- Test: `scratch/test_db_concurrency.php`

- [ ] **Step 1: Write test script to verify SQLite WAL mode and busy timeout**
- [ ] **Step 2: Update `includes/database.php` with WAL mode, busy_timeout = 5000, and user_version schema caching**
- [ ] **Step 3: Update `modules/classes/index.php` to handle universal section queries cleanly**
- [ ] **Step 4: Run test to verify SQLite connections execute without locks**

---

### Task 2: Seed Official CDC Subjects & Classes per Specification

**Files:**
- Modify: `config/seed_cdc_curriculum.php`
- Modify: `includes/database.php`
- Test: `scratch/verify_seeded_subjects.php`

- [ ] **Step 1: Update `config/seed_cdc_curriculum.php` with all requested subjects**:
  - Class 1-3: Nepali, English, Mathematics, Our Surroundings (Compulsory), Local Curriculum / Mother Tongue (Optional/Local).
  - Class 4-5: Nepali, English, Mathematics, Science and Technology, Social Studies and Human Values Education, Health, Physical and Creative Arts (Compulsory), Local Curriculum / Mother Tongue (Local/Optional).
  - Class 6-8: Nepali, English, Mathematics, Science and Technology, Social Studies and Human Values Education, Health and Physical Education, Local/Mother-Tongue subject (Compulsory).
  - Class 9-10: Compulsory (Nepali, English, Mathematics, Science and Technology, Social Studies), Electives (Optional Subject I, Optional Subject II, plus standard electives).
- [ ] **Step 2: Run seeder script `config/seed_cdc_curriculum.php`**
- [ ] **Step 3: Verify all 16 classes and their mapped subjects match exact user prompt requirements**

---

### Task 3: Implement Duplicate Subject Prevention

**Files:**
- Modify: `modules/classes/subjects.php:70-93`
- Modify: `modules/subjects/add.php:37-66`
- Modify: `modules/super_admin/subjects.php:130-153`
- Modify: `modules/subjects/edit.php`
- Test: `scratch/test_duplicate_prevention.php`

- [ ] **Step 1: Write automated test attempting duplicate subject mapping and duplicate subject creation**
- [ ] **Step 2: Add unique DB index `idx_class_subjects_unique ON class_subjects (class_id, subject_id)`**
- [ ] **Step 3: Add strict validation in `modules/classes/subjects.php` (block duplicate ID or matching name)**
- [ ] **Step 4: Add strict validation in `modules/subjects/add.php` (block creating a subject with existing name/code in target class)**
- [ ] **Step 5: Add strict validation in `modules/super_admin/subjects.php` and `modules/subjects/edit.php`**
- [ ] **Step 6: Run test to verify all duplicate attempts are blocked with clear error messages**

---

### Task 4: Ensure Unticked Optional Subjects are Hidden in All Modules

**Files:**
- Modify: `modules/classes/routine.php:155-165,620-630`
- Modify: `modules/exams/view.php:153-162,275-285`
- Test: `scratch/test_optional_toggle_visibility.php`

- [ ] **Step 1: Check and update `modules/classes/routine.php` to respect `(cs.is_optional = 0 OR cs.is_active = 1)` and `(school_id = ? OR school_id = 0)`**
- [ ] **Step 2: Check and update `modules/exams/view.php` to respect `(cs.is_optional = 0 OR cs.is_active = 1)`**
- [ ] **Step 3: Verify question bank, paper generator, routines, and exam subjects hide unticked optional subjects**

---

### Task 5: End-to-End Verification & Git Push

- [ ] **Step 1: Run comprehensive verification script checking classes, subjects, duplicate blocks, and WAL mode**
- [ ] **Step 2: Commit all changes to git**
- [ ] **Step 3: Push commits to remote git repository**
