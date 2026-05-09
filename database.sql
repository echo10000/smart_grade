-- SQL schema for the SmartGrade online grading system.

-- Create the database if it doesn't already exist
CREATE DATABASE IF NOT EXISTS smartgrade_db;
USE smartgrade_db;

-- -----------------------------------------------------------------
-- Users table
-- Stores authentication details for administrators, teachers and students.
-- Passwords are hashed using PHP's password_hash() function when
-- inserted from the application layer. A sample admin hash is provided
-- here for demonstration. The admin password is "admin@123" and the
-- corresponding bcrypt hash (generated via password_hash) is included
-- below【869388051487751†L220-L232】.
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','teacher','student') NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  archived_at TIMESTAMP NULL DEFAULT NULL,
  archived_by INT NULL,
  FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
);

-- -----------------------------------------------------------------
-- Sections table
-- Represents class sections for organising students and subjects.
CREATE TABLE IF NOT EXISTS sections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  year_level VARCHAR(50) NOT NULL,
  school_year VARCHAR(20) NOT NULL,
  semester VARCHAR(20) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  archived_at TIMESTAMP NULL DEFAULT NULL,
  archived_by INT NULL,
  UNIQUE KEY uq_sections_term_name (name, year_level, school_year, semester),
  FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
);

-- -----------------------------------------------------------------
-- Students table
-- Links a user record to additional student-specific information.
CREATE TABLE IF NOT EXISTS students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  student_no VARCHAR(50) NOT NULL UNIQUE,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  year_level VARCHAR(50),
  section_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  archived_at TIMESTAMP NULL DEFAULT NULL,
  archived_by INT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL,
  FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
);

-- -----------------------------------------------------------------
-- Teachers table
-- Links a user record to teacher-specific data.
CREATE TABLE IF NOT EXISTS teachers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  employee_no VARCHAR(50) NOT NULL UNIQUE,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  department VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  archived_at TIMESTAMP NULL DEFAULT NULL,
  archived_by INT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
);

-- -----------------------------------------------------------------
-- Subjects table
-- Contains course information. Each subject can have one assigned teacher.
CREATE TABLE IF NOT EXISTS subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  units INT NOT NULL,
  teacher_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  archived_at TIMESTAMP NULL DEFAULT NULL,
  archived_by INT NULL,
  FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL,
  FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
);

-- -----------------------------------------------------------------
-- Enrollments table
-- Records which students are enrolled in which subjects and sections.
CREATE TABLE IF NOT EXISTS enrollments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  subject_id INT NOT NULL,
  section_id INT NOT NULL,
  school_year VARCHAR(20) NOT NULL,
  semester VARCHAR(20) NOT NULL,
  status ENUM('Enrolled','Dropped') DEFAULT 'Enrolled',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  archived_at TIMESTAMP NULL DEFAULT NULL,
  archived_by INT NULL,
  UNIQUE KEY uq_enrollments_student_subject_term (student_id, subject_id, school_year, semester),
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
  FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
);

-- -----------------------------------------------------------------
-- Grade components table
-- Describes the assessment components for a subject (e.g., quizzes, exams).
CREATE TABLE IF NOT EXISTS grade_components (
  id INT AUTO_INCREMENT PRIMARY KEY,
  subject_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  percentage_weight DECIMAL(5,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  archived_at TIMESTAMP NULL DEFAULT NULL,
  archived_by INT NULL,
  UNIQUE KEY uq_grade_components_subject_name (subject_id, name),
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
);

-- -----------------------------------------------------------------
-- Grades table
-- Stores individual student scores for each component in a subject.
CREATE TABLE IF NOT EXISTS grades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  subject_id INT NOT NULL,
  component_id INT NOT NULL,
  score DECIMAL(6,2) NOT NULL,
  total_score DECIMAL(6,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  archived_at TIMESTAMP NULL DEFAULT NULL,
  archived_by INT NULL,
  UNIQUE KEY uq_grades_student_subject_component (student_id, subject_id, component_id),
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  FOREIGN KEY (component_id) REFERENCES grade_components(id) ON DELETE CASCADE,
  FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
);

-- -----------------------------------------------------------------
-- Grade audit logs table
-- Records score changes for grade rows.
CREATE TABLE IF NOT EXISTS grade_audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grade_id INT NULL,
  student_id INT NOT NULL,
  subject_id INT NOT NULL,
  component_id INT NOT NULL,
  changed_by INT NOT NULL,
  old_score DECIMAL(6,2) NULL,
  old_total_score DECIMAL(6,2) NULL,
  new_score DECIMAL(6,2) NOT NULL,
  new_total_score DECIMAL(6,2) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE SET NULL,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  FOREIGN KEY (component_id) REFERENCES grade_components(id) ON DELETE CASCADE,
  FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE
);

-- -----------------------------------------------------------------
-- Final grades table
-- Contains the computed final percentage, nullable GWA, and remarks for each student/subject.
CREATE TABLE IF NOT EXISTS final_grades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  subject_id INT NOT NULL,
  gwa DECIMAL(6,2) NULL,
  weighted_grade DECIMAL(6,2) NOT NULL,
  remarks ENUM('Passed','Failed','Incomplete','Dropped') NOT NULL,
  school_year VARCHAR(20) NOT NULL,
  semester VARCHAR(20) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  archived_at TIMESTAMP NULL DEFAULT NULL,
  archived_by INT NULL,
  UNIQUE KEY uq_final_grades_student_subject_term (student_id, subject_id, school_year, semester),
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
);

-- -----------------------------------------------------------------
-- Grade reports table
-- Holds references to generated report files per student.
CREATE TABLE IF NOT EXISTS grade_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  generated_by INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  archived_at TIMESTAMP NULL DEFAULT NULL,
  archived_by INT NULL,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
);

-- -----------------------------------------------------------------
-- Seed data

-- Insert a sample administrator account. The password hash corresponds to
-- "admin@123" using PHP's password_hash() function (bcrypt). This allows
-- immediate access to the system after import. Users should change
-- this password after logging in.
INSERT INTO users (name, email, password, role) VALUES
  ('System Administrator', 'admin@example.com',
   '$2y$10$/K.hjNr84lLNDt8fTXjoI.DBp6PpeyoJ.mGwrrLuCZfAwfSAGqhOW', 'admin');

-- Insert sample teachers
INSERT INTO users (name, email, password, role) VALUES
  ('Jane Instructor', 'jane.teacher@example.com',
   '$2y$10$/K.hjNr84lLNDt8fTXjoI.DBp6PpeyoJ.mGwrrLuCZfAwfSAGqhOW', 'teacher'),
  ('John Lecturer', 'john.lecturer@example.com',
   '$2y$10$/K.hjNr84lLNDt8fTXjoI.DBp6PpeyoJ.mGwrrLuCZfAwfSAGqhOW', 'teacher');

-- Map teacher records to their corresponding user IDs
INSERT INTO teachers (user_id, employee_no, first_name, last_name, department) VALUES
  (2, 'T-2026001', 'Jane', 'Instructor', 'Computer Science'),
  (3, 'T-2026002', 'John', 'Lecturer', 'Mathematics');

-- Insert sample subjects and assign to teachers
INSERT INTO subjects (code, name, units, teacher_id) VALUES
  ('CS101', 'Introduction to Programming', 3, 1),
  ('MATH201', 'Calculus II', 4, 2);

-- Insert sample section
INSERT INTO sections (name, year_level, school_year, semester) VALUES
  ('Section A', '1st Year', '2025-2026', '1st Semester');

-- Insert sample student user and record
INSERT INTO users (name, email, password, role) VALUES
  ('Alice Student', 'alice.student@example.com',
   '$2y$10$/K.hjNr84lLNDt8fTXjoI.DBp6PpeyoJ.mGwrrLuCZfAwfSAGqhOW', 'student');

INSERT INTO students (user_id, student_no, first_name, last_name, year_level, section_id) VALUES
  (4, 'S-2026001', 'Alice', 'Student', '1st Year', 1);

-- Enroll Alice in both subjects
INSERT INTO enrollments (student_id, subject_id, section_id, school_year, semester) VALUES
  (1, 1, 1, '2025-2026', '1st Semester'),
  (1, 2, 1, '2025-2026', '1st Semester');

-- Insert sample grade components for each subject
INSERT INTO grade_components (subject_id, name, percentage_weight) VALUES
  (1, 'Quiz', 20.00),
  (1, 'Activity', 20.00),
  (1, 'Midterm Exam', 30.00),
  (1, 'Final Exam', 30.00),
  (2, 'Quiz', 25.00),
  (2, 'Midterm Exam', 25.00),
  (2, 'Final Exam', 50.00);

-- Note: Grades and final_grades tables start empty. Data will be inserted
-- through the application when teachers input scores and computations run.

-- -----------------------------------------------------------------
-- Settings table
-- Stores key/value pairs for application-wide settings such as the current
-- school year and semester. The admin can manage these values through
-- school_year.php. If a setting does not exist it can be created on the fly.
CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(50) PRIMARY KEY,
  `value` VARCHAR(255) NOT NULL
);

-- Seed default settings
INSERT INTO settings (`key`, `value`) VALUES
  ('current_school_year', '2025-2026'),
  ('current_semester', '1st Semester'),
  ('passing_grade', '75')
ON DUPLICATE KEY UPDATE value=VALUES(value);
