-- =================================
-- DUMMY DATA
-- =================================

-- Asset Inventory
INSERT INTO asset_inventory
(part_number, serial_number, brand, description, interface, quantity, type, location, remark)
VALUES
    ('pn-1001','sn-a1001','Cisco','Network Switch 24 Port','Ethernet',5,'Network','Server Room A','Active'),
    ('pn-1002','sn-a1002','HP','Rack Mount Server','Ethernet',2,'Server','Data Center 1','Running'),
    ('pn-1003','sn-a1003','Dell','Storage Device','SAS',3,'Storage','Data Center 1','Backup Storage'),
    ('pn-1004','sn-a1004','Mikrotik','Router Board','Ethernet',4,'Network','Network Rack','Stable'),
    ('pn-1005','sn-a1005','Juniper','Core Router','Fiber',1,'Network','Main Rack','Critical Device');

-- Project Inventory
INSERT INTO project_inventory
(name, contract_name, contract_code, contract_start, contract_end, location, pic, support_coverage, preventive_management, partner, partner_pic, remark, created_by)
VALUES
    ('Hospital Network Upgrade','Hospital IT Modernization','CTR-2024-001','2024-01-01','2026-01-01','Kuala Lumpur','Ahmad Faiz','24x7','Quarterly','Cisco Partner','John Tan','Active Project','admin1'),
    ('Airport Security System','Airport Surveillance System','CTR-2023-010','2023-06-01','2025-06-01','Selangor','Farid Hakim','Business Hours','Bi Yearly','Hikvision Partner','Michael Lee','Maintenance Phase','admin2'),
    ('Government Cloud Setup','Gov Private Cloud','CTR-2025-003','2025-03-01','2027-03-01','Putrajaya','Nur Azmi','24x7','Monthly','Dell Partner','Jason Lim','Deployment Ongoing','sysadmin');

-- Tender Tracker
INSERT INTO tender_tracker
(tender_name, tender_code, company, submission_date, status, remark)
VALUES
    ('Smart City Monitoring','TND-001','Majlis Bandaraya','2025-05-10','Submitted','Awaiting Result'),
    ('School Network Upgrade','TND-002','Ministry of Education','2025-07-01','Evaluation','Technical Review'),
    ('Data Center Expansion','TND-003','Telekom Malaysia','2025-09-15','Preparation','Proposal Drafting'),
    ('Government Firewall Project','TND-004','MAMPU','2025-10-01','Submitted','Pending Review');

-- Administrator (hashed password: 123456)
INSERT INTO administrator (username, email, password)
VALUES
    ('admin1','admin1@gmail.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9c2cZ8dQ0W5Q5Q5Q5Q5Q5G'),
    ('admin2','admin2@gmail.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9c2cZ8dQ0W5Q5Q5Q5Q5Q5G');

-- System Admin (hashed password: 123456)
INSERT INTO system_admin (username, email, password)
VALUES
    ('sysadmin','sysadmin@gmail.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9c2cZ8dQ0W5Q5Q5Q5Q5Q5G'),
    ('superadmin','superadmin@gmail.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9c2cZ8dQ0W5Q5Q5Q5Q5Q5G');

-- Users (hashed password: 123456)
INSERT INTO user (username, email, password, role)
VALUES
    ('coordinator1','coordinator@gmail.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9c2cZ8dQ0W5Q5Q5Q5Q5Q5G','User (Project Coordinator)'),
    ('technical1','technical@gmail.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9c2cZ8dQ0W5Q5Q5Q5Q5Q5G','User (Technical)'),
    ('manager1','manager@gmail.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9c2cZ8dQ0W5Q5Q5Q5Q5Q5G','User (Project Manager)');