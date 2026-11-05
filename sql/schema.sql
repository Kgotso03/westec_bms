-- ==============================================================================
-- WesTec Eswatini Business Management System (WesTec BMS)
-- Complete Relational Database Schema, Triggers & Baseline Seed Data
-- Target Database: westec_bms (MySQL 8.0+ / MariaDB 10.4+)
-- ==============================================================================
-- Instructions for phpMyAdmin:
-- 1. Open phpMyAdmin (http://localhost/phpmyadmin)
-- 2. Click on the "Import" tab at the top
-- 3. Choose this file (schema.sql)
-- 4. Click "Import" / "Go" at the bottom
-- ==============================================================================

CREATE DATABASE IF NOT EXISTS `westec_bms` 
DEFAULT CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `westec_bms`;

-- Disable foreign key checks during initialization
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. ROLES TABLE
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
    `role_id` INT AUTO_INCREMENT PRIMARY KEY,
    `role_name` VARCHAR(50) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. USERS TABLE
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role_id` INT NOT NULL,
    `email` VARCHAR(100) NULL,
    `status` ENUM('Active', 'Inactive', 'Suspended') NOT NULL DEFAULT 'Active',
    `profile_photo` VARCHAR(255) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) 
        REFERENCES `roles` (`role_id`) 
        ON UPDATE CASCADE 
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2b. PASSWORD RESET TOKENS TABLE
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `password_reset_tokens`;
CREATE TABLE `password_reset_tokens` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token_hash` VARCHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_token_hash` (`token_hash`),
    INDEX `idx_user_id` (`user_id`),
    CONSTRAINT `fk_reset_tokens_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. CLIENTS TABLE
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `clients`;
CREATE TABLE `clients` (
    `client_id` INT AUTO_INCREMENT PRIMARY KEY,
    `client_name` VARCHAR(150) NOT NULL,
    `contact_person` VARCHAR(100) NULL,
    `email` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(30) NOT NULL,
    `address` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. STAFF TABLE
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `staff`;
CREATE TABLE `staff` (
    `staff_id` INT AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `phone` VARCHAR(30) NOT NULL,
    `job_title` VARCHAR(100) NOT NULL,
    `department` VARCHAR(80) DEFAULT 'IT Technical Services',
    `user_id` INT NULL UNIQUE,
    `manager_id` INT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_staff_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`user_id`) 
        ON UPDATE CASCADE 
        ON DELETE SET NULL,
    CONSTRAINT `fk_staff_manager` FOREIGN KEY (`manager_id`) 
        REFERENCES `users` (`user_id`) 
        ON UPDATE CASCADE 
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. TICKETS (SERVICE TICKETS) TABLE
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `tickets`;
CREATE TABLE `tickets` (
    `ticket_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ticket_number` VARCHAR(30) NOT NULL UNIQUE,
    `client_id` INT NOT NULL,
    `assigned_staff_id` INT NULL,
    `title` VARCHAR(150) NOT NULL,
    `description` TEXT NOT NULL,
    `priority` ENUM('Low', 'Medium', 'High', 'Critical') NOT NULL DEFAULT 'Medium',
    `status` ENUM('Open', 'In Progress', 'Pending', 'Resolved', 'Closed') NOT NULL DEFAULT 'Open',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `resolved_at` DATETIME NULL,
    CONSTRAINT `fk_tickets_client` FOREIGN KEY (`client_id`) 
        REFERENCES `clients` (`client_id`) 
        ON UPDATE CASCADE 
        ON DELETE RESTRICT,
    CONSTRAINT `fk_tickets_staff` FOREIGN KEY (`assigned_staff_id`) 
        REFERENCES `staff` (`staff_id`) 
        ON UPDATE CASCADE 
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 6. INVENTORY TABLE
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `inventory`;
CREATE TABLE `inventory` (
    `item_id` INT AUTO_INCREMENT PRIMARY KEY,
    `sku` VARCHAR(50) NOT NULL UNIQUE,
    `item_name` VARCHAR(150) NOT NULL,
    `category` VARCHAR(80) NOT NULL,
    `quantity_in_stock` INT NOT NULL DEFAULT 0,
    `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `reorder_level` INT NOT NULL DEFAULT 5,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 7. INVENTORY TRANSACTIONS TABLE
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `inventory_transactions`;
CREATE TABLE `inventory_transactions` (
    `transaction_id` INT AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT NOT NULL,
    `transaction_type` ENUM('IN', 'OUT', 'ADJUSTMENT') NOT NULL,
    `quantity` INT NOT NULL,
    `unit_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `reference_note` VARCHAR(255) NULL,
    `created_by` INT NULL,
    `transaction_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_inv_trans_item` FOREIGN KEY (`item_id`) 
        REFERENCES `inventory` (`item_id`) 
        ON UPDATE CASCADE 
        ON DELETE RESTRICT,
    CONSTRAINT `fk_inv_trans_user` FOREIGN KEY (`created_by`) 
        REFERENCES `users` (`user_id`) 
        ON UPDATE CASCADE 
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 8. PROJECTS TABLE
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `projects`;
CREATE TABLE `projects` (
    `project_id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_name` VARCHAR(150) NOT NULL,
    `client_id` INT NOT NULL,
    `description` TEXT NULL,
    `start_date` DATE NOT NULL,
    `due_date` DATE NOT NULL,
    `budget` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('Planning', 'In Progress', 'On Hold', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Planning',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_projects_client` FOREIGN KEY (`client_id`) 
        REFERENCES `clients` (`client_id`) 
        ON UPDATE CASCADE 
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 9. MILESTONES TABLE
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `milestones`;
CREATE TABLE `milestones` (
    `milestone_id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `milestone_name` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `due_date` DATE NOT NULL,
    `status` ENUM('Pending', 'In Progress', 'Achieved', 'Delayed') NOT NULL DEFAULT 'Pending',
    `completed_at` DATE NULL,
    CONSTRAINT `fk_milestones_project` FOREIGN KEY (`project_id`) 
        REFERENCES `projects` (`project_id`) 
        ON UPDATE CASCADE 
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 10. PROJECT ASSIGNMENTS TABLE
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `project_assignments`;
CREATE TABLE `project_assignments` (
    `assignment_id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `staff_id` INT NOT NULL,
    `role_in_project` VARCHAR(100) NOT NULL DEFAULT 'Lead Engineer',
    `assigned_date` DATE NOT NULL,
    `notes` VARCHAR(255) NULL,
    `assigned_by` INT NULL,
    CONSTRAINT `fk_proj_assign_project` FOREIGN KEY (`project_id`) 
        REFERENCES `projects` (`project_id`) 
        ON UPDATE CASCADE 
        ON DELETE CASCADE,
    CONSTRAINT `fk_proj_assign_staff` FOREIGN KEY (`staff_id`) 
        REFERENCES `staff` (`staff_id`) 
        ON UPDATE CASCADE 
        ON DELETE RESTRICT,
    CONSTRAINT `fk_proj_assign_manager` FOREIGN KEY (`assigned_by`) 
        REFERENCES `users` (`user_id`) 
        ON UPDATE CASCADE 
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 11. AUDIT LOGS TABLE
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
    `log_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `action` VARCHAR(50) NOT NULL,
    `table_affected` VARCHAR(50) NOT NULL,
    `record_id` INT NULL,
    `details` TEXT NULL,
    `ip_address` VARCHAR(45) DEFAULT '127.0.0.1',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`user_id`) 
        ON UPDATE CASCADE 
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 12. SERVICE REPORTS TABLE
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `service_reports`;
CREATE TABLE `service_reports` (
    `report_id` INT AUTO_INCREMENT PRIMARY KEY,
    `report_title` VARCHAR(150) NOT NULL,
    `report_type` VARCHAR(50) NOT NULL,
    `generated_by` INT NULL,
    `parameters` TEXT NULL,
    `summary_data` LONGTEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_reports_user` FOREIGN KEY (`generated_by`) 
        REFERENCES `users` (`user_id`) 
        ON UPDATE CASCADE 
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 13. INVENTORY PERMISSIONS TABLE
-- Manager-controlled, per-user access grants for the inventory module.
-- Staff under the IT Manager role have no access by default.
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `inventory_permissions`;
CREATE TABLE `inventory_permissions` (
    `permission_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `can_view` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'View inventory catalog and stock levels',
    `can_add_item` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Register new catalog items',
    `can_edit_item` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Edit existing catalog item details',
    `can_record_transaction` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Record stock IN / OUT / ADJUSTMENT transactions',
    `granted_by` INT NULL COMMENT 'user_id of the manager who configured this permission set',
    `granted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_inv_perm_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT `fk_inv_perm_granted_by` FOREIGN KEY (`granted_by`)
        REFERENCES `users` (`user_id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Manager-controlled inventory access grants per non-admin user';

-- ==============================================================================
-- DATABASE BUSINESS RULE TRIGGERS
-- ==============================================================================

DELIMITER $$

-- Trigger 1: Inventory Transaction Validation (BEFORE INSERT)
-- Rejects OUT transactions if requested quantity exceeds current stock.
DROP TRIGGER IF EXISTS `trg_check_inventory_stock_before_insert`$$
CREATE TRIGGER `trg_check_inventory_stock_before_insert`
BEFORE INSERT ON `inventory_transactions`
FOR EACH ROW
BEGIN
    DECLARE current_stock INT DEFAULT 0;
    
    IF NEW.quantity <= 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Transaction rejected: Quantity must be a positive integer greater than zero.';
    END IF;

    IF NEW.transaction_type = 'OUT' THEN
        SELECT `quantity_in_stock` INTO current_stock 
        FROM `inventory` 
        WHERE `item_id` = NEW.item_id;

        IF current_stock IS NULL OR NEW.quantity > current_stock THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Transaction rejected: Stock withdrawal exceeds current quantity in stock.';
        END IF;
    END IF;
END$$

-- Trigger 2: Inventory Stock Update (AFTER INSERT)
-- Automatically increments, decrements, or sets stock upon transaction recording.
DROP TRIGGER IF EXISTS `trg_update_inventory_stock_after_insert`$$
CREATE TRIGGER `trg_update_inventory_stock_after_insert`
AFTER INSERT ON `inventory_transactions`
FOR EACH ROW
BEGIN
    IF NEW.transaction_type = 'IN' THEN
        UPDATE `inventory`
        SET `quantity_in_stock` = `quantity_in_stock` + NEW.quantity
        WHERE `item_id` = NEW.item_id;
    ELSEIF NEW.transaction_type = 'OUT' THEN
        UPDATE `inventory`
        SET `quantity_in_stock` = `quantity_in_stock` - NEW.quantity
        WHERE `item_id` = NEW.item_id;
    ELSEIF NEW.transaction_type = 'ADJUSTMENT' THEN
        UPDATE `inventory`
        SET `quantity_in_stock` = NEW.quantity
        WHERE `item_id` = NEW.item_id;
    END IF;
END$$

-- Trigger 3: Project Date Validation (BEFORE INSERT)
-- Rejects projects where due_date is earlier than start_date.
DROP TRIGGER IF EXISTS `trg_validate_project_dates_before_insert`$$
CREATE TRIGGER `trg_validate_project_dates_before_insert`
BEFORE INSERT ON `projects`
FOR EACH ROW
BEGIN
    IF NEW.due_date < NEW.start_date THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Project validation failed: Due date cannot be earlier than start date.';
    END IF;
END$$

-- Trigger 4: Project Date Validation (BEFORE UPDATE)
DROP TRIGGER IF EXISTS `trg_validate_project_dates_before_update`$$
CREATE TRIGGER `trg_validate_project_dates_before_update`
BEFORE UPDATE ON `projects`
FOR EACH ROW
BEGIN
    IF NEW.due_date < NEW.start_date THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Project validation failed: Due date cannot be earlier than start date.';
    END IF;
END$$

-- Trigger 5: User Registration Audit (AFTER INSERT ON users)
-- Automatically logs registration in audit_logs without storing sensitive passwords.
DROP TRIGGER IF EXISTS `trg_after_user_register`$$
CREATE TRIGGER `trg_after_user_register`
AFTER INSERT ON `users`
FOR EACH ROW
BEGIN
    INSERT INTO `audit_logs` (
        `user_id`,
        `action`,
        `table_affected`,
        `record_id`,
        `details`,
        `ip_address`,
        `created_at`
    ) VALUES (
        NEW.user_id,
        'USER_REGISTERED',
        'users',
        NEW.user_id,
        CONCAT('New user registered successfully. Username: [', NEW.username, '], Role ID: [', NEW.role_id, '], Status: [', NEW.status, ']. Generated by database trigger trg_after_user_register.'),
        '127.0.0.1',
        NOW()
    );
END$$

-- Trigger 6: Ticket Status Change Audit (AFTER UPDATE ON tickets)
DROP TRIGGER IF EXISTS `trg_after_ticket_status_update`$$
CREATE TRIGGER `trg_after_ticket_status_update`
AFTER UPDATE ON `tickets`
FOR EACH ROW
BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO `audit_logs` (
            `user_id`,
            `action`,
            `table_affected`,
            `record_id`,
            `details`,
            `ip_address`,
            `created_at`
        ) VALUES (
            NULL,
            'TICKET_STATUS_CHANGED',
            'tickets',
            NEW.ticket_id,
            CONCAT('Ticket #', NEW.ticket_number, ' changed status from "', OLD.status, '" to "', NEW.status, '". Trigger trg_after_ticket_status_update fired.'),
            '127.0.0.1',
            NOW()
        );
    END IF;
END$$

DELIMITER ;

-- ==============================================================================
-- BASELINE ESSENTIAL SYSTEM DATA
-- (Zero demo data: only system roles and initial administrator account)
-- ==============================================================================

-- 1. System Roles
INSERT INTO `roles` (`role_id`, `role_name`, `description`) VALUES
(1, 'Administrator', 'Full system access and security administration'),
(2, 'IT Manager', 'Oversees projects, service tickets, client accounts and reporting'),
(3, 'Senior Technician', 'Performs technical infrastructure assignments and ticket resolution'),
(4, 'Support Specialist', 'Helpdesk support, client communications and inventory tracking'),
(5, 'Client Representative', 'External client point of contact for service ticket logging')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- 2. System Administrator Account (Username: admin / Password: Admin@12345)
INSERT INTO `users` (`user_id`, `username`, `password_hash`, `role_id`, `email`, `status`) VALUES
(1, 'admin', '$2y$10$hoiBAPOvj0VCsmG/xpQQ9uveGYcU1ppjPS8PfxxVIBzjuNMkuUv7e', 1, 'admin@westeceswatini.co.sz', 'Active')
ON DUPLICATE KEY UPDATE `status` = 'Active';

-- 3. Initial System Audit Entry
INSERT INTO `audit_logs` (`user_id`, `action`, `table_affected`, `record_id`, `details`, `ip_address`, `created_at`) VALUES
(1, 'SYSTEM_INIT', 'system', 1, 'WesTec BMS database schema and system administrator initialized successfully.', '127.0.0.1', NOW());

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;
