-- ==============================================================================
-- WesTec Eswatini Business Management System (WesTec BMS)
-- Phase 3 Demonstration Seed Data
-- ==============================================================================

USE `westec_bms`;

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Roles
TRUNCATE TABLE `roles`;
INSERT INTO `roles` (`role_id`, `role_name`, `description`) VALUES
(1, 'Administrator', 'Full system access and security administration'),
(2, 'IT Manager', 'Oversees projects, service tickets, client accounts and reporting'),
(3, 'Senior Technician', 'Performs technical infrastructure assignments and ticket resolution'),
(4, 'Support Specialist', 'Helpdesk support, client communications and inventory tracking'),
(5, 'Client Representative', 'External client point of contact for service ticket logging');

-- 2. Users (Admin: Admin@12345, Others: Pass@12345)
TRUNCATE TABLE `users`;
INSERT INTO `users` (`user_id`, `username`, `password_hash`, `role_id`, `email`, `status`) VALUES
(1, 'admin', '$2y$10$hoiBAPOvj0VCsmG/xpQQ9uveGYcU1ppjPS8PfxxVIBzjuNMkuUv7e', 1, 'admin@westeceswatini.co.sz', 'Active'),
(2, 'manager_nkosi', '$2y$10$ryYdSfUhXtuL7tHcjR6hTOjhgQJ3ULmLHKR3JCYoJj33yw2EdIaJS', 2, 'm.nkosi@westeceswatini.co.sz', 'Active'),
(3, 'tech_sbu', '$2y$10$ryYdSfUhXtuL7tHcjR6hTOjhgQJ3ULmLHKR3JCYoJj33yw2EdIaJS', 3, 's.dlamini@westeceswatini.co.sz', 'Active'),
(4, 'support_thabo', '$2y$10$ryYdSfUhXtuL7tHcjR6hTOjhgQJ3ULmLHKR3JCYoJj33yw2EdIaJS', 4, 't.zwane@westeceswatini.co.sz', 'Active'),
(5, 'client_eec', '$2y$10$ryYdSfUhXtuL7tHcjR6hTOjhgQJ3ULmLHKR3JCYoJj33yw2EdIaJS', 5, 'itops@eec.co.sz', 'Active');

-- 3. Clients
TRUNCATE TABLE `clients`;
INSERT INTO `clients` (`client_id`, `client_name`, `contact_person`, `email`, `phone`, `address`) VALUES
(1, 'Eswatini Electricity Company (EEC)', 'Musa Simelane', 'itops@eec.co.sz', '+268 2409 4000', 'Eluvatseni House, Mhlambanyatsi Road, Mbabane'),
(2, 'Standard Bank Eswatini', 'Lindiwe Masuku', 'ictsupport@standardbank.co.sz', '+268 2405 6000', 'Corporate Place, Swazi Plaza, Mbabane'),
(3, 'Royal Eswatini Sugar Corporation', 'Vusi Shongwe', 'networks@res.co.sz', '+268 2313 4000', 'Simunye Sugar Estate, Lubombo District'),
(4, 'MTN Eswatini Telecoms', 'Thulani Khumalo', 'techops@mtn.co.sz', '+268 2406 0000', 'Mahlanya Road, Ezulwini Valley, Eswatini'),
(5, 'Eswatini Water Services Corporation', 'Zodwa Tsabedze', 'systems@ewsc.co.sz', '+268 2416 9000', 'Ezulwini Headquarters, MR103, Ezulwini');

-- 4. Staff
TRUNCATE TABLE `staff`;
INSERT INTO `staff` (`staff_id`, `full_name`, `email`, `phone`, `job_title`, `department`, `user_id`, `is_active`) VALUES
(1, 'Kgotso Dlamini', 'admin@westeceswatini.co.sz', '+268 7604 1234', 'Chief Technology Officer', 'Executive / IT Ops', 1, 1),
(2, 'Mandla Nkosi', 'm.nkosi@westeceswatini.co.sz', '+268 7611 2233', 'IT Infrastructure Manager', 'Operations', 2, 1),
(3, 'Sibusiso Dlamini', 's.dlamini@westeceswatini.co.sz', '+268 7633 4455', 'Senior Network & Systems Engineer', 'Engineering', 3, 1),
(4, 'Thabo Zwane', 't.zwane@westeceswatini.co.sz', '+268 7655 6677', 'Helpdesk Support Specialist', 'Client Support', 4, 1),
(5, 'Nokwanda Simelane', 'n.simelane@westeceswatini.co.sz', '+268 7688 9900', 'Cybersecurity & Cloud Specialist', 'Security', NULL, 1);

-- 5. Inventory
TRUNCATE TABLE `inventory`;
INSERT INTO `inventory` (`item_id`, `sku`, `item_name`, `category`, `quantity_in_stock`, `unit_price`, `reorder_level`) VALUES
(1, 'NET-CIS-2960X', 'Cisco Catalyst 2960-X 24-Port Gigabit Switch', 'Networking Hardware', 12, 14500.00, 3),
(2, 'NET-UBI-U6PRO', 'Ubiquiti UniFi 6 Pro Access Point Wi-Fi 6', 'Wireless Solutions', 25, 3450.00, 5),
(3, 'CAB-CAT6-305M', 'Cat6 UTP Solid Copper 305m Cable Drum', 'Cabling & Structured Cabling', 8, 2150.00, 4),
(4, 'SRV-MEM-D16G', 'Dell PowerEdge 16GB DDR4 ECC Registered RAM', 'Server Hardware', 4, 3200.00, 2),
(5, 'SEC-FG-60F', 'Fortinet FortiGate 60F Security Appliance', 'Network Security', 6, 18900.00, 2),
(6, 'RCK-PP-24P', 'Patch Panel 24-Port 1U Cat6 Unshielded', 'Rack Accessories', 15, 950.00, 5);

-- 6. Inventory Transactions
TRUNCATE TABLE `inventory_transactions`;
INSERT INTO `inventory_transactions` (`transaction_id`, `item_id`, `transaction_type`, `quantity`, `unit_cost`, `reference_note`, `created_by`, `transaction_date`) VALUES
(1, 1, 'IN', 12, 13800.00, 'Initial supplier batch from Westcon SA', 1, '2026-10-01 09:30:00'),
(2, 2, 'IN', 25, 3100.00, 'Direct shipment from Miro South Africa', 1, '2026-10-02 11:15:00'),
(3, 3, 'IN', 8, 1950.00, 'Warehouse restock for campus cabling', 2, '2026-10-05 14:00:00'),
(4, 4, 'IN', 4, 2900.00, 'Procured for EEC server memory expansion', 2, '2026-10-10 10:45:00'),
(5, 5, 'IN', 6, 17200.00, 'Security hardware import', 1, '2026-10-12 16:20:00'),
(6, 6, 'IN', 15, 820.00, 'Rack patch equipment batch', 2, '2026-10-15 08:30:00');

-- 7. Service Tickets
TRUNCATE TABLE `tickets`;
INSERT INTO `tickets` (`ticket_id`, `ticket_number`, `client_id`, `assigned_staff_id`, `title`, `description`, `priority`, `status`, `created_at`, `resolved_at`) VALUES
(1, 'TK-2026-001', 1, 3, 'Core Switch Failover Latency Spike', 'Intermittent failover delays observed between primary and redundant Cisco core distribution switches during peak load.', 'High', 'In Progress', '2026-10-18 08:45:00', NULL),
(2, 'TK-2026-002', 2, 3, 'ATM Branch VPN Tunnel Intermittent Drop', 'Mbabane and Manzini ATM terminal routers experience periodic IPsec tunnel drops during routine keepalive checks.', 'Critical', 'Open', '2026-10-20 10:15:00', NULL),
(3, 'TK-2026-003', 3, 5, 'SCADA Terminal Network Segmentation', 'Implement VLAN isolation and firewall ACLs for industrial automation control terminal at Simunye mill.', 'Medium', 'Resolved', '2026-10-14 13:00:00', '2026-10-17 17:30:00'),
(4, 'TK-2026-004', 4, 4, 'Guest Wi-Fi Portal Authentication Failure', 'Captive portal certificate expired on guest network, preventing visitors from authenticating into Ezulwini hub.', 'Low', 'Closed', '2026-10-11 09:00:00', '2026-10-11 11:45:00'),
(5, 'TK-2026-005', 5, 2, 'Remote Telemetry Sensor Connectivity Error', 'Remote reservoir telemetry transmitters failing to sync over GSM APN link to central SCADA server.', 'High', 'Pending', '2026-10-22 15:20:00', NULL);

-- 8. Projects
TRUNCATE TABLE `projects`;
INSERT INTO `projects` (`project_id`, `project_name`, `client_id`, `description`, `start_date`, `due_date`, `budget`, `status`) VALUES
(1, 'EEC Data Center Fiber Backbone Migration', 1, 'Upgrade 10Gbps redundant optical links between headquarters and standby disaster recovery node in Matsapha.', '2026-10-01', '2026-12-15', 185000.00, 'In Progress'),
(2, 'Standard Bank ATM SD-WAN Rollout', 2, 'Deployment of secure SD-WAN edge nodes across 24 retail branches and off-site ATM terminals in Eswatini.', '2026-10-15', '2027-01-30', 320000.00, 'Planning'),
(3, 'RES Sugar SCADA Hardening & Airgap Review', 3, 'Comprehensive security audit, VLAN segregation, and FortiGate deployment for agricultural processing network.', '2026-09-01', '2026-10-25', 95000.00, 'Completed');

-- 9. Milestones
TRUNCATE TABLE `milestones`;
INSERT INTO `milestones` (`milestone_id`, `project_id`, `milestone_name`, `description`, `due_date`, `status`, `completed_at`) VALUES
(1, 1, 'Site Survey and OTDR Optical Verification', 'Test optical loss and splice points along the 45km route between Mbabane and Matsapha.', '2026-10-15', 'Achieved', '2026-10-14'),
(2, 1, 'Core Switch Aggregation & Transceiver Setup', 'Configure 10G SFP+ uplinks and test failover spanning tree topology.', '2026-11-10', 'In Progress', NULL),
(3, 1, 'Failover Simulation & Disaster Recovery Sign-off', 'Live simulation of main fiber cut to verify failover SLA within 50ms.', '2026-12-10', 'Pending', NULL),
(4, 2, 'SD-WAN Edge Controller Staging', 'Configure orchestration template in WesTec staging lab.', '2026-11-05', 'Pending', NULL),
(5, 3, 'Firewall Policy Hardening & Acceptance', 'Deploy FortiGate 60F and lock down all administrative protocols.', '2026-10-20', 'Achieved', '2026-10-22');

-- 10. Project Assignments
TRUNCATE TABLE `project_assignments`;
INSERT INTO `project_assignments` (`assignment_id`, `project_id`, `staff_id`, `role_in_project`, `assigned_date`, `notes`) VALUES
(1, 1, 3, 'Lead Optical Engineer', '2026-10-01', 'Direct supervision of physical fiber terminations and OTDR testing.'),
(2, 1, 2, 'Project Manager', '2026-10-01', 'Client stakeholder liaison and milestone sign-offs.'),
(3, 2, 3, 'SD-WAN Solutions Architect', '2026-10-15', 'Branch edge configuration and IPsec tunnel integration.'),
(4, 3, 5, 'Security Consultant', '2026-09-01', 'Industrial SCADA firewall rules and penetration testing.');

-- 11. Initial Audit Logs
TRUNCATE TABLE `audit_logs`;
INSERT INTO `audit_logs` (`log_id`, `user_id`, `action`, `table_affected`, `record_id`, `details`, `ip_address`, `created_at`) VALUES
(1, 1, 'SYSTEM_INIT', 'system', 1, 'WesTec BMS Phase 3/4 database schema and baseline records initialized successfully.', '127.0.0.1', '2026-10-27 08:00:00'),
(2, 1, 'USER_REGISTERED', 'users', 1, 'Administrator account [admin] provisioned during initial setup.', '127.0.0.1', '2026-10-27 08:01:00');

-- 12. Service Reports
TRUNCATE TABLE `service_reports`;
INSERT INTO `service_reports` (`report_id`, `report_title`, `report_type`, `generated_by`, `parameters`, `summary_data`, `created_at`) VALUES
(1, 'Q4 IT Service Ticket Metrics & SLA Report', 'TICKET_SUMMARY', 1, '{"quarter":"Q4","year":2026}', 'Summary: 5 total tickets logged (1 Open, 1 In Progress, 1 Pending, 1 Resolved, 1 Closed). Average resolution time: 28.5 hours.', '2026-10-26 16:00:00'),
(2, 'WesTec Infrastructure Inventory Valuation', 'INVENTORY_VALUATION', 2, '{"category":"ALL"}', 'Summary: 6 SKUs in catalog. Total stock quantity: 70 units. Total valuation: E 413,000.00.', '2026-10-25 11:30:00');

SET FOREIGN_KEY_CHECKS = 1;
