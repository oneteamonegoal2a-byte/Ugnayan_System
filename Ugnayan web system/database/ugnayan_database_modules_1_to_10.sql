CREATE DATABASE IF NOT EXISTS ugnayan_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ugnayan_db;

CREATE TABLE IF NOT EXISTS users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','staff','bhw','resident') NOT NULL DEFAULT 'resident',
  status ENUM('pending','approved','rejected','disabled') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS residents (
  resident_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100),
  last_name VARCHAR(100) NOT NULL,
  suffix VARCHAR(20),
  birth_date DATE NOT NULL,
  gender ENUM('Male','Female') NOT NULL,
  civil_status ENUM('Single','Married','Widowed','Separated') NOT NULL,
  purok VARCHAR(50) NOT NULL,
  contact_number VARCHAR(20) NOT NULL,
  address TEXT NOT NULL,
  occupation VARCHAR(100),
  profile_photo VARCHAR(255) NULL,
  application_status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  rejection_reason TEXT,
  approved_by INT NULL,
  approved_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS resident_documents (
  document_id INT AUTO_INCREMENT PRIMARY KEY,
  resident_id INT NOT NULL,
  document_type ENUM('Valid ID','Proof of Residency','2x2 Photo') NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notifications (
  notification_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  type ENUM('announcement','request_update','account_status') NOT NULL DEFAULT 'announcement',
  is_read BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS audit_logs (
  log_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(150) NOT NULL,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS activity_history (
  activity_id INT AUTO_INCREMENT PRIMARY KEY,
  resident_id INT NOT NULL,
  activity_type VARCHAR(100) NOT NULL,
  details TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE CASCADE
);

INSERT IGNORE INTO users (email, password_hash, role, status)
VALUES ('admin@ugnayan.com', '$2y$12$W/9qPU85raw8epbECwxL9uyDLCUpBviHuk2Bjal/5mUTPd9GYu.ka', 'admin', 'approved');

-- =========================================================
-- MODULE 2: BARANGAY COMPLAINT SYSTEM
-- =========================================================
CREATE TABLE IF NOT EXISTS complaints (
  complaint_id INT AUTO_INCREMENT PRIMARY KEY,
  resident_id INT NULL,
  category ENUM('noise','dispute','sanitation','peace_and_order','health','environment','others') NOT NULL DEFAULT 'others',
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  is_anonymous BOOLEAN DEFAULT FALSE,
  priority ENUM('Low','Medium','High') NOT NULL DEFAULT 'Medium',
  status ENUM('Pending','Ongoing','Resolved','Rejected') NOT NULL DEFAULT 'Pending',
  assigned_to INT NULL,
  admin_response TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  resolved_at DATETIME NULL,
  FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_to) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS complaint_evidence (
  evidence_id INT AUTO_INCREMENT PRIMARY KEY,
  complaint_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_type ENUM('photo','video','document') NOT NULL DEFAULT 'photo',
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS complaint_updates (
  update_id INT AUTO_INCREMENT PRIMARY KEY,
  complaint_id INT NOT NULL,
  updated_by INT NULL,
  status ENUM('Pending','Ongoing','Resolved','Rejected') NOT NULL,
  update_message TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);


-- =========================================================
-- MODULE 3: CALAMITY ANNOUNCEMENTS MODULE
-- =========================================================
CREATE TABLE IF NOT EXISTS calamity_announcements (
  announcement_id INT AUTO_INCREMENT PRIMARY KEY,
  created_by INT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  calamity_type ENUM('typhoon','flood','fire','earthquake','landslide','health_emergency','others') NOT NULL DEFAULT 'others',
  priority ENUM('Urgent','Warning','Info') NOT NULL DEFAULT 'Info',
  target_purok VARCHAR(50) NULL,
  safety_instructions TEXT,
  evacuation_plan TEXT,
  scheduled_at DATETIME NULL,
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS calamity_attachments (
  attachment_id INT AUTO_INCREMENT PRIMARY KEY,
  announcement_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_name VARCHAR(255),
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (announcement_id) REFERENCES calamity_announcements(announcement_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS calamity_reads (
  read_id INT AUTO_INCREMENT PRIMARY KEY,
  announcement_id INT NOT NULL,
  user_id INT NOT NULL,
  is_read BOOLEAN DEFAULT FALSE,
  read_at DATETIME NULL,
  FOREIGN KEY (announcement_id) REFERENCES calamity_announcements(announcement_id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);


-- =========================================================
-- MODULE 4: SCHEDULING MODULE
-- =========================================================
CREATE TABLE IF NOT EXISTS schedules (
  schedule_id INT AUTO_INCREMENT PRIMARY KEY,
  created_by INT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  event_type ENUM('event','meeting','program','appointment','others') NOT NULL DEFAULT 'event',
  target_group ENUM('All','Residents','Staff','BHW','Specific Purok') NOT NULL DEFAULT 'All',
  target_purok VARCHAR(50) NULL,
  start_datetime DATETIME NOT NULL,
  end_datetime DATETIME NOT NULL,
  location VARCHAR(255),
  status ENUM('Scheduled','Cancelled','Completed') NOT NULL DEFAULT 'Scheduled',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS schedule_attendance (
  attendance_id INT AUTO_INCREMENT PRIMARY KEY,
  schedule_id INT NOT NULL,
  resident_id INT NULL,
  user_id INT NULL,
  rsvp_status ENUM('Pending','Going','Not Going') DEFAULT 'Pending',
  attended BOOLEAN DEFAULT FALSE,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (schedule_id) REFERENCES schedules(schedule_id) ON DELETE CASCADE,
  FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS schedule_reminders (
  reminder_id INT AUTO_INCREMENT PRIMARY KEY,
  schedule_id INT NOT NULL,
  reminder_message TEXT NOT NULL,
  remind_at DATETIME NOT NULL,
  is_sent BOOLEAN DEFAULT FALSE,
  FOREIGN KEY (schedule_id) REFERENCES schedules(schedule_id) ON DELETE CASCADE
);


-- =========================================================
-- MODULE 5: ITEM MANAGEMENT MODULE
-- =========================================================
CREATE TABLE IF NOT EXISTS inventory_items (
  item_id INT AUTO_INCREMENT PRIMARY KEY,
  item_name VARCHAR(255) NOT NULL,
  description TEXT,
  total_quantity INT NOT NULL DEFAULT 0,
  available_quantity INT NOT NULL DEFAULT 0,
  borrowed_quantity INT NOT NULL DEFAULT 0,
  condition_status ENUM('Good','Needs Repair','Damaged') NOT NULL DEFAULT 'Good',
  borrowing_limit INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS item_borrow_requests (
  borrow_id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  resident_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  purpose TEXT,
  borrow_date DATE NOT NULL,
  return_date DATE NOT NULL,
  status ENUM('Pending','Approved','Rejected','Borrowed','Returned','Overdue') NOT NULL DEFAULT 'Pending',
  approved_by INT NULL,
  remarks TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id) REFERENCES inventory_items(item_id) ON DELETE CASCADE,
  FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE CASCADE,
  FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS item_maintenance (
  maintenance_id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  issue_description TEXT NOT NULL,
  maintenance_status ENUM('Pending','In Progress','Completed') NOT NULL DEFAULT 'Pending',
  maintenance_date DATE,
  cost DECIMAL(10,2) DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id) REFERENCES inventory_items(item_id) ON DELETE CASCADE
);


-- =========================================================
-- MODULE 6: DOCUMENT REQUEST MODULE
-- =========================================================
CREATE TABLE IF NOT EXISTS document_types (
  document_type_id INT AUTO_INCREMENT PRIMARY KEY,
  document_name VARCHAR(150) NOT NULL UNIQUE,
  description TEXT,
  fee DECIMAL(10,2) DEFAULT 0.00,
  template_path VARCHAR(255),
  is_active BOOLEAN DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS document_requests (
  request_id INT AUTO_INCREMENT PRIMARY KEY,
  document_type_id INT NOT NULL,
  resident_id INT NOT NULL,
  purpose TEXT,
  status ENUM('Pending','Processing','Approved','Rejected','Ready','Released') NOT NULL DEFAULT 'Pending',
  pickup_schedule DATETIME NULL,
  payment_status ENUM('Not Required','Unpaid','Paid') NOT NULL DEFAULT 'Not Required',
  remarks TEXT,
  reviewed_by INT NULL,
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (document_type_id) REFERENCES document_types(document_type_id) ON DELETE CASCADE,
  FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE CASCADE,
  FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS document_request_files (
  file_id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_name VARCHAR(255),
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES document_requests(request_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS generated_documents (
  generated_id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  generated_file_path VARCHAR(255) NOT NULL,
  generated_by INT NULL,
  generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES document_requests(request_id) ON DELETE CASCADE,
  FOREIGN KEY (generated_by) REFERENCES users(user_id) ON DELETE SET NULL
);


-- =========================================================
-- MODULE 7: BARANGAY INCIDENT LOG MODULE
-- =========================================================
CREATE TABLE IF NOT EXISTS incident_logs (
  incident_id INT AUTO_INCREMENT PRIMARY KEY,
  recorded_by INT NULL,
  resident_id INT NULL,
  incident_title VARCHAR(255) NOT NULL,
  incident_type VARCHAR(150) NOT NULL,
  description TEXT NOT NULL,
  incident_date DATETIME NOT NULL,
  location VARCHAR(255),
  status ENUM('Open','Under Review','Closed') NOT NULL DEFAULT 'Open',
  visibility ENUM('Admin Only','Resident Visible') NOT NULL DEFAULT 'Admin Only',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE SET NULL,
  FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS incident_files (
  file_id INT AUTO_INCREMENT PRIMARY KEY,
  incident_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_name VARCHAR(255),
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (incident_id) REFERENCES incident_logs(incident_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS incident_updates (
  update_id INT AUTO_INCREMENT PRIMARY KEY,
  incident_id INT NOT NULL,
  updated_by INT NULL,
  update_text TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (incident_id) REFERENCES incident_logs(incident_id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);


-- =========================================================
-- MODULE 8: HEALTHCARE MODULE
-- =========================================================
CREATE TABLE IF NOT EXISTS health_programs (
  program_id INT AUTO_INCREMENT PRIMARY KEY,
  created_by INT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  program_type ENUM('vaccination','checkup','medical_mission','health_tips','others') NOT NULL DEFAULT 'others',
  schedule_date DATETIME NOT NULL,
  location VARCHAR(255),
  target_purok VARCHAR(50) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS health_appointments (
  appointment_id INT AUTO_INCREMENT PRIMARY KEY,
  program_id INT NULL,
  resident_id INT NOT NULL,
  appointment_date DATETIME NOT NULL,
  status ENUM('Pending','Confirmed','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (program_id) REFERENCES health_programs(program_id) ON DELETE SET NULL,
  FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS health_records (
  health_record_id INT AUTO_INCREMENT PRIMARY KEY,
  resident_id INT NOT NULL,
  blood_type VARCHAR(5),
  allergies TEXT,
  medical_conditions TEXT,
  emergency_contact_name VARCHAR(150),
  emergency_contact_number VARCHAR(20),
  updated_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);


-- =========================================================
-- MODULE 9: BHW MODULE
-- =========================================================
CREATE TABLE IF NOT EXISTS bhw_profiles (
  bhw_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  assigned_purok VARCHAR(50) NOT NULL,
  contact_number VARCHAR(20),
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS household_profiles (
  household_id INT AUTO_INCREMENT PRIMARY KEY,
  household_head_resident_id INT NULL,
  purok VARCHAR(50) NOT NULL,
  address TEXT NOT NULL,
  household_number VARCHAR(50),
  assigned_bhw_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (household_head_resident_id) REFERENCES residents(resident_id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_bhw_id) REFERENCES bhw_profiles(bhw_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS household_members (
  member_id INT AUTO_INCREMENT PRIMARY KEY,
  household_id INT NOT NULL,
  resident_id INT NOT NULL,
  relationship_to_head VARCHAR(100),
  FOREIGN KEY (household_id) REFERENCES household_profiles(household_id) ON DELETE CASCADE,
  FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS bhw_home_visits (
  visit_id INT AUTO_INCREMENT PRIMARY KEY,
  household_id INT NOT NULL,
  bhw_id INT NULL,
  visit_date DATETIME NOT NULL,
  observations TEXT,
  recommendations TEXT,
  next_visit_date DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (household_id) REFERENCES household_profiles(household_id) ON DELETE CASCADE,
  FOREIGN KEY (bhw_id) REFERENCES bhw_profiles(bhw_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS priority_groups (
  priority_id INT AUTO_INCREMENT PRIMARY KEY,
  resident_id INT NOT NULL,
  group_type ENUM('Pregnant Woman','Infant/Child','Senior Citizen','PWD','High Risk') NOT NULL,
  notes TEXT,
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS immunization_records (
  immunization_id INT AUTO_INCREMENT PRIMARY KEY,
  resident_id INT NOT NULL,
  vaccine_name VARCHAR(150) NOT NULL,
  dose_number VARCHAR(50),
  date_given DATE,
  next_due_date DATE,
  administered_by INT NULL,
  remarks TEXT,
  FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE CASCADE,
  FOREIGN KEY (administered_by) REFERENCES users(user_id) ON DELETE SET NULL
);


-- =========================================================
-- MODULE 10: COMMUNICATION MODULE
-- =========================================================
CREATE TABLE IF NOT EXISTS messages (
  message_id INT AUTO_INCREMENT PRIMARY KEY,
  sender_id INT NOT NULL,
  receiver_id INT NULL,
  category ENUM('complaint','request','inquiry','feedback','others') NOT NULL DEFAULT 'inquiry',
  subject VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('Open','Replied','Closed') NOT NULL DEFAULT 'Open',
  is_read BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (receiver_id) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS message_replies (
  reply_id INT AUTO_INCREMENT PRIMARY KEY,
  message_id INT NOT NULL,
  sender_id INT NOT NULL,
  reply_message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (message_id) REFERENCES messages(message_id) ON DELETE CASCADE,
  FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS broadcast_messages (
  broadcast_id INT AUTO_INCREMENT PRIMARY KEY,
  created_by INT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  target_group ENUM('All','Residents','Staff','BHW','Specific Purok') NOT NULL DEFAULT 'All',
  target_purok VARCHAR(50) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS auto_replies (
  auto_reply_id INT AUTO_INCREMENT PRIMARY KEY,
  keyword VARCHAR(100) NOT NULL,
  response TEXT NOT NULL,
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS feedback (
  feedback_id INT AUTO_INCREMENT PRIMARY KEY,
  resident_id INT NULL,
  rating INT CHECK (rating BETWEEN 1 AND 5),
  comments TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE SET NULL
);


-- =========================================================
-- DEFAULT DOCUMENT TYPES ONLY
-- No residents, complaints, alerts, schedules, items, or other records inserted.
-- Only the Module 1 admin account remains inserted.
-- =========================================================
INSERT IGNORE INTO document_types (document_name, description, fee) VALUES
('Barangay Clearance', 'Certificate issued for general barangay clearance purposes.', 0.00),
('Certificate of Residency', 'Certificate proving residency in the barangay.', 0.00),
('Certificate of Indigency', 'Certificate for residents requesting indigency certification.', 0.00),
('Business Clearance', 'Barangay clearance for business-related purposes.', 0.00);


-- =========================================================
-- ADVANCED SQL IMPLEMENTATION
-- SQL Joins, Views, Stored Procedures, Triggers,
-- Aggregate Functions, and MySQL Indexes
-- =========================================================

-- MySQL Indexes for faster dashboard filters, joins, and status lookups.
-- The helper keeps this section safe when the schema is imported more than once.
DELIMITER $$

DROP PROCEDURE IF EXISTS sp_add_index_if_missing$$
CREATE PROCEDURE sp_add_index_if_missing(
  IN p_table_name VARCHAR(64),
  IN p_index_name VARCHAR(64),
  IN p_index_sql TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = p_table_name
      AND index_name = p_index_name
  ) THEN
    SET @index_sql = p_index_sql;
    PREPARE index_stmt FROM @index_sql;
    EXECUTE index_stmt;
    DEALLOCATE PREPARE index_stmt;
  END IF;
END$$

DELIMITER ;

CALL sp_add_index_if_missing('users', 'idx_users_role_status', 'CREATE INDEX idx_users_role_status ON users (role, status)');
CALL sp_add_index_if_missing('residents', 'idx_residents_status_purok', 'CREATE INDEX idx_residents_status_purok ON residents (application_status, purok)');
CALL sp_add_index_if_missing('residents', 'idx_residents_purok_name', 'CREATE INDEX idx_residents_purok_name ON residents (purok, last_name, first_name)');
CALL sp_add_index_if_missing('resident_documents', 'idx_resident_documents_type', 'CREATE INDEX idx_resident_documents_type ON resident_documents (resident_id, document_type)');
CALL sp_add_index_if_missing('notifications', 'idx_notifications_user_read_created', 'CREATE INDEX idx_notifications_user_read_created ON notifications (user_id, is_read, created_at)');
CALL sp_add_index_if_missing('complaints', 'idx_complaints_status_priority_created', 'CREATE INDEX idx_complaints_status_priority_created ON complaints (status, priority, created_at)');
CALL sp_add_index_if_missing('complaints', 'idx_complaints_resident_status', 'CREATE INDEX idx_complaints_resident_status ON complaints (resident_id, status)');
CALL sp_add_index_if_missing('document_requests', 'idx_document_requests_status_resident', 'CREATE INDEX idx_document_requests_status_resident ON document_requests (status, resident_id)');
CALL sp_add_index_if_missing('document_requests', 'idx_document_requests_type_status', 'CREATE INDEX idx_document_requests_type_status ON document_requests (document_type_id, status)');
CALL sp_add_index_if_missing('item_borrow_requests', 'idx_item_borrow_status_return', 'CREATE INDEX idx_item_borrow_status_return ON item_borrow_requests (status, return_date)');
CALL sp_add_index_if_missing('item_borrow_requests', 'idx_item_borrow_resident_status', 'CREATE INDEX idx_item_borrow_resident_status ON item_borrow_requests (resident_id, status)');
CALL sp_add_index_if_missing('health_appointments', 'idx_health_appointments_status_date', 'CREATE INDEX idx_health_appointments_status_date ON health_appointments (status, appointment_date)');
CALL sp_add_index_if_missing('messages', 'idx_messages_receiver_status_read', 'CREATE INDEX idx_messages_receiver_status_read ON messages (receiver_id, status, is_read)');
CALL sp_add_index_if_missing('feedback', 'idx_feedback_resident_rating', 'CREATE INDEX idx_feedback_resident_rating ON feedback (resident_id, rating)');

DROP PROCEDURE IF EXISTS sp_add_index_if_missing;

-- View with JOINs and aggregate document counts for resident verification.
CREATE OR REPLACE VIEW vw_resident_application_review AS
SELECT
  r.resident_id,
  r.user_id,
  CONCAT_WS(' ', r.first_name, NULLIF(r.middle_name, ''), r.last_name, NULLIF(r.suffix, '')) AS resident_name,
  u.email,
  u.status AS account_status,
  r.application_status,
  r.rejection_reason,
  r.purok,
  r.contact_number,
  r.address,
  COUNT(DISTINCT rd.document_id) AS submitted_document_count,
  GROUP_CONCAT(DISTINCT rd.document_type ORDER BY rd.document_type SEPARATOR ', ') AS submitted_documents,
  MAX(rd.uploaded_at) AS latest_document_upload,
  approver.email AS approved_by_email,
  r.approved_at
FROM residents r
JOIN users u ON u.user_id = r.user_id
LEFT JOIN resident_documents rd ON rd.resident_id = r.resident_id
LEFT JOIN users approver ON approver.user_id = r.approved_by
GROUP BY
  r.resident_id,
  r.user_id,
  r.first_name,
  r.middle_name,
  r.last_name,
  r.suffix,
  u.email,
  u.status,
  r.application_status,
  r.rejection_reason,
  r.purok,
  r.contact_number,
  r.address,
  approver.email,
  r.approved_at;

-- View with JOINs and aggregate functions for purok-level reports.
CREATE OR REPLACE VIEW vw_purok_service_summary AS
SELECT
  p.purok,
  COUNT(r.resident_id) AS total_residents,
  SUM(CASE WHEN r.application_status = 'Pending' THEN 1 ELSE 0 END) AS pending_residents,
  SUM(CASE WHEN r.application_status = 'Approved' THEN 1 ELSE 0 END) AS approved_residents,
  SUM(CASE WHEN r.application_status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_residents,
  COALESCE(c.total_complaints, 0) AS total_complaints,
  COALESCE(c.pending_complaints, 0) AS pending_complaints,
  COALESCE(c.resolved_complaints, 0) AS resolved_complaints,
  COALESCE(dr.total_document_requests, 0) AS total_document_requests,
  COALESCE(dr.pending_document_requests, 0) AS pending_document_requests,
  COALESCE(dr.released_document_requests, 0) AS released_document_requests,
  COALESCE(ha.total_health_appointments, 0) AS total_health_appointments,
  COALESCE(ha.pending_health_appointments, 0) AS pending_health_appointments,
  COALESCE(f.total_feedback, 0) AS total_feedback,
  COALESCE(f.average_feedback_rating, 0) AS average_feedback_rating
FROM (
  SELECT DISTINCT purok
  FROM residents
  WHERE purok IS NOT NULL AND purok <> ''
) p
LEFT JOIN residents r ON r.purok = p.purok
LEFT JOIN (
  SELECT
    r2.purok,
    COUNT(c.complaint_id) AS total_complaints,
    SUM(CASE WHEN c.status = 'Pending' THEN 1 ELSE 0 END) AS pending_complaints,
    SUM(CASE WHEN c.status = 'Resolved' THEN 1 ELSE 0 END) AS resolved_complaints
  FROM residents r2
  LEFT JOIN complaints c ON c.resident_id = r2.resident_id
  GROUP BY r2.purok
) c ON c.purok = p.purok
LEFT JOIN (
  SELECT
    r3.purok,
    COUNT(dr.request_id) AS total_document_requests,
    SUM(CASE WHEN dr.status = 'Pending' THEN 1 ELSE 0 END) AS pending_document_requests,
    SUM(CASE WHEN dr.status = 'Released' THEN 1 ELSE 0 END) AS released_document_requests
  FROM residents r3
  LEFT JOIN document_requests dr ON dr.resident_id = r3.resident_id
  GROUP BY r3.purok
) dr ON dr.purok = p.purok
LEFT JOIN (
  SELECT
    r4.purok,
    COUNT(ha.appointment_id) AS total_health_appointments,
    SUM(CASE WHEN ha.status = 'Pending' THEN 1 ELSE 0 END) AS pending_health_appointments
  FROM residents r4
  LEFT JOIN health_appointments ha ON ha.resident_id = r4.resident_id
  GROUP BY r4.purok
) ha ON ha.purok = p.purok
LEFT JOIN (
  SELECT
    r5.purok,
    COUNT(f.feedback_id) AS total_feedback,
    ROUND(AVG(f.rating), 2) AS average_feedback_rating
  FROM residents r5
  LEFT JOIN feedback f ON f.resident_id = r5.resident_id
  GROUP BY r5.purok
) f ON f.purok = p.purok
GROUP BY
  p.purok,
  c.total_complaints,
  c.pending_complaints,
  c.resolved_complaints,
  dr.total_document_requests,
  dr.pending_document_requests,
  dr.released_document_requests,
  ha.total_health_appointments,
  ha.pending_health_appointments,
  f.total_feedback,
  f.average_feedback_rating;

-- Unified admin queue using JOINs and UNION ALL across modules.
CREATE OR REPLACE VIEW vw_admin_work_queue AS
SELECT
  'Resident Application' AS queue_type,
  r.resident_id AS reference_id,
  CONCAT_WS(' ', r.first_name, r.last_name) AS subject,
  r.purok,
  r.application_status AS status,
  u.created_at AS created_at
FROM residents r
JOIN users u ON u.user_id = r.user_id
WHERE r.application_status = 'Pending'
UNION ALL
SELECT
  'Complaint' AS queue_type,
  c.complaint_id AS reference_id,
  c.title AS subject,
  COALESCE(r.purok, 'Unassigned') AS purok,
  c.status,
  c.created_at
FROM complaints c
LEFT JOIN residents r ON r.resident_id = c.resident_id
WHERE c.status IN ('Pending', 'Ongoing')
UNION ALL
SELECT
  'Document Request' AS queue_type,
  dr.request_id AS reference_id,
  dt.document_name AS subject,
  r.purok,
  dr.status,
  dr.requested_at AS created_at
FROM document_requests dr
JOIN document_types dt ON dt.document_type_id = dr.document_type_id
JOIN residents r ON r.resident_id = dr.resident_id
WHERE dr.status IN ('Pending', 'Processing', 'Approved', 'Ready')
UNION ALL
SELECT
  'Borrow Request' AS queue_type,
  b.borrow_id AS reference_id,
  i.item_name AS subject,
  r.purok,
  b.status,
  b.created_at
FROM item_borrow_requests b
JOIN inventory_items i ON i.item_id = b.item_id
JOIN residents r ON r.resident_id = b.resident_id
WHERE b.status IN ('Pending', 'Approved', 'Borrowed', 'Overdue');

-- Inventory report with JOINs and aggregate functions.
CREATE OR REPLACE VIEW vw_inventory_borrowing_report AS
SELECT
  i.item_id,
  i.item_name,
  i.total_quantity,
  i.available_quantity,
  i.borrowed_quantity,
  i.condition_status,
  COUNT(b.borrow_id) AS total_borrow_requests,
  COALESCE(SUM(CASE WHEN b.status = 'Pending' THEN 1 ELSE 0 END), 0) AS pending_requests,
  COALESCE(SUM(CASE WHEN b.status IN ('Approved', 'Borrowed', 'Overdue') THEN 1 ELSE 0 END), 0) AS active_requests,
  COALESCE(SUM(CASE WHEN b.status IN ('Approved', 'Borrowed', 'Overdue') THEN b.quantity ELSE 0 END), 0) AS active_borrowed_quantity,
  MIN(CASE WHEN b.status IN ('Approved', 'Borrowed', 'Overdue') THEN b.return_date ELSE NULL END) AS nearest_return_date,
  COALESCE(m.total_maintenance_cost, 0.00) AS total_maintenance_cost
FROM inventory_items i
LEFT JOIN item_borrow_requests b ON b.item_id = i.item_id
LEFT JOIN (
  SELECT item_id, SUM(cost) AS total_maintenance_cost
  FROM item_maintenance
  GROUP BY item_id
) m ON m.item_id = i.item_id
GROUP BY
  i.item_id,
  i.item_name,
  i.total_quantity,
  i.available_quantity,
  i.borrowed_quantity,
  i.condition_status,
  m.total_maintenance_cost;

DELIMITER $$

-- Stored Procedure: one-row admin dashboard summary with aggregate functions.
DROP PROCEDURE IF EXISTS sp_get_admin_dashboard_summary$$
CREATE PROCEDURE sp_get_admin_dashboard_summary()
BEGIN
  SELECT
    (SELECT COUNT(*) FROM residents) AS total_residents,
    (SELECT COUNT(*) FROM residents WHERE application_status = 'Pending') AS pending_resident_applications,
    (SELECT COUNT(*) FROM residents WHERE application_status = 'Approved') AS approved_residents,
    (SELECT COUNT(*) FROM complaints WHERE status = 'Pending') AS pending_complaints,
    (SELECT COUNT(*) FROM document_requests WHERE status IN ('Pending', 'Processing')) AS active_document_requests,
    (SELECT COUNT(*) FROM item_borrow_requests WHERE status IN ('Pending', 'Approved', 'Borrowed', 'Overdue')) AS active_borrow_requests,
    (SELECT COUNT(*) FROM health_appointments WHERE status = 'Pending') AS pending_health_appointments,
    (SELECT COALESCE(ROUND(AVG(rating), 2), 0) FROM feedback) AS average_feedback_rating;
END$$

-- Stored Procedure: resident service history using JOINs and grouped aggregates.
DROP PROCEDURE IF EXISTS sp_get_resident_service_summary$$
CREATE PROCEDURE sp_get_resident_service_summary(IN p_resident_id INT)
BEGIN
  SELECT
    r.resident_id,
    CONCAT_WS(' ', r.first_name, NULLIF(r.middle_name, ''), r.last_name, NULLIF(r.suffix, '')) AS resident_name,
    u.email,
    r.purok,
    r.application_status,
    COALESCE(c.total_complaints, 0) AS total_complaints,
    COALESCE(c.pending_complaints, 0) AS pending_complaints,
    COALESCE(d.total_document_requests, 0) AS total_document_requests,
    COALESCE(d.released_document_requests, 0) AS released_document_requests,
    COALESCE(b.total_borrow_requests, 0) AS total_borrow_requests,
    COALESCE(b.active_borrow_requests, 0) AS active_borrow_requests,
    COALESCE(h.total_health_appointments, 0) AS total_health_appointments
  FROM residents r
  JOIN users u ON u.user_id = r.user_id
  LEFT JOIN (
    SELECT
      resident_id,
      COUNT(*) AS total_complaints,
      SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_complaints
    FROM complaints
    GROUP BY resident_id
  ) c ON c.resident_id = r.resident_id
  LEFT JOIN (
    SELECT
      resident_id,
      COUNT(*) AS total_document_requests,
      SUM(CASE WHEN status = 'Released' THEN 1 ELSE 0 END) AS released_document_requests
    FROM document_requests
    GROUP BY resident_id
  ) d ON d.resident_id = r.resident_id
  LEFT JOIN (
    SELECT
      resident_id,
      COUNT(*) AS total_borrow_requests,
      SUM(CASE WHEN status IN ('Approved', 'Borrowed', 'Overdue') THEN 1 ELSE 0 END) AS active_borrow_requests
    FROM item_borrow_requests
    GROUP BY resident_id
  ) b ON b.resident_id = r.resident_id
  LEFT JOIN (
    SELECT
      resident_id,
      COUNT(*) AS total_health_appointments
    FROM health_appointments
    GROUP BY resident_id
  ) h ON h.resident_id = r.resident_id
  WHERE r.resident_id = p_resident_id;
END$$

-- Stored Procedure: mark borrow requests as overdue when return date has passed.
DROP PROCEDURE IF EXISTS sp_mark_overdue_borrow_requests$$
CREATE PROCEDURE sp_mark_overdue_borrow_requests()
BEGIN
  UPDATE item_borrow_requests
  SET status = 'Overdue'
  WHERE status IN ('Approved', 'Borrowed')
    AND return_date < CURDATE();

  SELECT ROW_COUNT() AS overdue_requests_marked;
END$$

-- Stored Procedure: send a broadcast to residents in one purok using INSERT ... SELECT JOIN.
DROP PROCEDURE IF EXISTS sp_send_purok_broadcast$$
CREATE PROCEDURE sp_send_purok_broadcast(
  IN p_created_by INT,
  IN p_title VARCHAR(255),
  IN p_message TEXT,
  IN p_target_purok VARCHAR(50)
)
BEGIN
  INSERT INTO broadcast_messages (created_by, title, message, target_group, target_purok)
  VALUES (p_created_by, p_title, p_message, 'Specific Purok', p_target_purok);

  INSERT INTO notifications (user_id, title, message, type)
  SELECT u.user_id, p_title, p_message, 'announcement'
  FROM users u
  JOIN residents r ON r.user_id = u.user_id
  WHERE r.purok = p_target_purok
    AND r.application_status = 'Approved'
    AND u.status = 'approved';

  SELECT ROW_COUNT() AS residents_notified;
END$$

-- Trigger: validate inventory quantities before insert.
DROP TRIGGER IF EXISTS trg_inventory_items_before_insert_validate$$
CREATE TRIGGER trg_inventory_items_before_insert_validate
BEFORE INSERT ON inventory_items
FOR EACH ROW
BEGIN
  IF NEW.total_quantity < 0 OR NEW.available_quantity < 0 OR NEW.borrowed_quantity < 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Inventory quantities cannot be negative.';
  END IF;

  IF NEW.available_quantity + NEW.borrowed_quantity > NEW.total_quantity THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Available and borrowed quantities cannot exceed total quantity.';
  END IF;
END$$

-- Trigger: validate borrow request quantity and date range.
DROP TRIGGER IF EXISTS trg_item_borrow_requests_before_insert_validate$$
CREATE TRIGGER trg_item_borrow_requests_before_insert_validate
BEFORE INSERT ON item_borrow_requests
FOR EACH ROW
BEGIN
  IF NEW.quantity <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Borrow quantity must be greater than zero.';
  END IF;

  IF NEW.return_date < NEW.borrow_date THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Return date cannot be earlier than borrow date.';
  END IF;
END$$

-- Trigger: keep complaint update and resolved timestamps consistent.
DROP TRIGGER IF EXISTS trg_complaints_before_update_status_dates$$
CREATE TRIGGER trg_complaints_before_update_status_dates
BEFORE UPDATE ON complaints
FOR EACH ROW
BEGIN
  IF NEW.status <> OLD.status OR NOT (NEW.admin_response <=> OLD.admin_response) THEN
    SET NEW.updated_at = NOW();
  END IF;

  IF NEW.status = 'Resolved' AND OLD.status <> 'Resolved' THEN
    SET NEW.resolved_at = COALESCE(NEW.resolved_at, NOW());
  END IF;

  IF NEW.status <> 'Resolved' THEN
    SET NEW.resolved_at = NULL;
  END IF;
END$$

-- Trigger: prevent releasing paid documents before payment is complete.
DROP TRIGGER IF EXISTS trg_document_requests_before_update_release_rules$$
CREATE TRIGGER trg_document_requests_before_update_release_rules
BEFORE UPDATE ON document_requests
FOR EACH ROW
BEGIN
  DECLARE v_fee DECIMAL(10,2) DEFAULT 0.00;

  SELECT COALESCE(fee, 0.00)
  INTO v_fee
  FROM document_types
  WHERE document_type_id = NEW.document_type_id;

  IF NEW.status = 'Released' AND v_fee > 0 AND NEW.payment_status <> 'Paid' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid documents must be marked as Paid before release.';
  END IF;
END$$

-- Trigger: database-level audit trail for resident status changes.
DROP TRIGGER IF EXISTS trg_residents_after_update_status_audit$$
CREATE TRIGGER trg_residents_after_update_status_audit
AFTER UPDATE ON residents
FOR EACH ROW
BEGIN
  IF NEW.application_status <> OLD.application_status THEN
    INSERT INTO audit_logs (user_id, action, description)
    VALUES (
      NEW.approved_by,
      'Resident Application Status Changed',
      CONCAT(
        'Resident #',
        NEW.resident_id,
        ' changed from ',
        OLD.application_status,
        ' to ',
        NEW.application_status
      )
    );
  END IF;
END$$

DELIMITER ;

