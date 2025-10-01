-- Faculty Performance Evaluation System Database Schema
-- Run this script in phpMyAdmin or MySQL command line

CREATE DATABASE IF NOT EXISTS faculty_evaluation_system;
USE faculty_evaluation_system;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('student', 'faculty', 'dean', 'admin') NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    department VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Faculty table (extends users for faculty-specific info)
CREATE TABLE faculty (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    employee_id VARCHAR(20) UNIQUE,
    position VARCHAR(50),
    hire_date DATE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Students table (extends users for student-specific info)
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    student_id VARCHAR(20) UNIQUE,
    year_level VARCHAR(20),
    program VARCHAR(100),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Evaluation criteria
CREATE TABLE evaluation_criteria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(100) NOT NULL,
    criterion VARCHAR(255) NOT NULL,
    description TEXT,
    weight DECIMAL(3,2) DEFAULT 1.00,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Evaluations table
CREATE TABLE evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NULL,
    faculty_id INT NOT NULL,
    semester VARCHAR(20) NOT NULL,
    academic_year VARCHAR(10) NOT NULL,
    subject VARCHAR(100),
    overall_rating DECIMAL(3,2),
    comments TEXT,
    is_anonymous BOOLEAN DEFAULT TRUE,
    evaluator_user_id INT NULL,
    evaluator_role ENUM('student', 'faculty', 'dean') NULL,
    is_self BOOLEAN DEFAULT FALSE,
    status ENUM('draft', 'submitted', 'reviewed') DEFAULT 'draft',
    submitted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
    FOREIGN KEY (evaluator_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Evaluation responses (detailed ratings for each criterion)
CREATE TABLE evaluation_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    evaluation_id INT NOT NULL,
    criterion_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    comment TEXT,
    FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE,
    FOREIGN KEY (criterion_id) REFERENCES evaluation_criteria(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default users
INSERT INTO users (username, password, role, full_name, email, department) VALUES
('admin01', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'System Administrator', 'admin@university.edu', 'IT Department'),
('dean01', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'dean', 'Dr. John Dean', 'dean@university.edu', 'Academic Affairs'),
('faculty01', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'Prof. Jane Smith', 'jsmith@university.edu', 'Computer Science'),
('student01', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'Alice Johnson', 'alice@student.university.edu', 'Computer Science'),
-- Department Admin Users
('tech_admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'Technology Department Admin', 'tech.admin@university.edu', 'Technology'),
('edu_admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'Education Department Admin', 'edu.admin@university.edu', 'Education'),
('bus_admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'Business Department Admin', 'bus.admin@university.edu', 'Business');

-- Insert faculty data
INSERT INTO faculty (user_id, employee_id, position, hire_date) VALUES
((SELECT id FROM users WHERE username = 'faculty01'), 'FAC001', 'Associate Professor', '2020-01-15');

-- Insert student data
INSERT INTO students (user_id, student_id, year_level, program) VALUES
((SELECT id FROM users WHERE username = 'student01'), 'STU001', '4th Year', 'Bachelor of Science in Computer Science');

-- Insert default evaluation criteria
INSERT INTO evaluation_criteria (category, criterion, description, weight) VALUES
('Teaching Effectiveness', 'Clarity of Instruction', 'How clearly the instructor explains concepts and materials', 1.0),
('Teaching Effectiveness', 'Course Organization', 'How well organized and structured the course is', 1.0),
('Teaching Effectiveness', 'Use of Teaching Methods', 'Effectiveness of teaching methods and techniques used', 1.0),
('Student Engagement', 'Encourages Participation', 'How well the instructor encourages student participation', 1.0),
('Student Engagement', 'Availability for Help', 'Instructor availability for questions and assistance', 1.0),
('Assessment', 'Fair Grading', 'Fairness and consistency in grading practices', 1.0),
('Assessment', 'Timely Feedback', 'Timeliness of feedback on assignments and exams', 1.0),
('Professional Conduct', 'Punctuality', 'Instructor punctuality and attendance', 1.0),
('Professional Conduct', 'Respect for Students', 'Shows respect and consideration for all students', 1.0),
('Course Content', 'Relevance of Material', 'Relevance and currency of course materials', 1.0);

-- Password reset requests
CREATE TABLE IF NOT EXISTS password_reset_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(50) NOT NULL,
    role ENUM('Student','Faculty','Dean') NOT NULL,
    status ENUM('Pending','Resolved') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Global Evaluation Schedule (applies to all departments and students)
CREATE TABLE IF NOT EXISTS evaluation_schedule (
    id INT PRIMARY KEY,
    start_at DATETIME NULL,
    end_at DATETIME NULL,
    override_mode ENUM('auto','open','closed') DEFAULT 'auto',
    notice VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Seed singleton row
INSERT INTO evaluation_schedule (id, start_at, end_at, override_mode, notice)
VALUES (1, NULL, NULL, 'auto', NULL)
ON DUPLICATE KEY UPDATE id = id;

-- Student one-time evaluation per faculty+subject+semester+academic_year
-- Note: UNIQUE allows multiple NULLs; dean entries (NULL student_id) will not conflict
CREATE UNIQUE INDEX uniq_student_eval
  ON evaluations (student_id, faculty_id, subject, semester, academic_year);

-- Dean one-time evaluation per faculty+subject+semester+academic_year
CREATE UNIQUE INDEX uniq_dean_eval
  ON evaluations (evaluator_user_id, evaluator_role, faculty_id, subject, semester, academic_year);
