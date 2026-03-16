-- csim dummy data
-- database: crossroad_solutions_inventory_management

USE crossroad_solutions_inventory_management;

-- =================================
-- asset inventory dummy data
-- =================================

INSERT INTO asset_inventory
(part_number, serial_number, brand, description, interface, quantity, type, location, remark)
VALUES
    ('pn-1001','sn-a1001','Cisco','Network Switch 24 Port','Ethernet',5,'Network','Server Room A','Active'),
    ('pn-1002','sn-a1002','HP','Rack Mount Server','Ethernet',2,'Server','Data Center 1','Running'),
    ('pn-1003','sn-a1003','Dell','Storage Device','SAS',3,'Storage','Data Center 1','Backup Storage'),
    ('pn-1004','sn-a1004','Mikrotik','Router Board','Ethernet',4,'Network','Network Rack','Stable'),
    ('pn-1005','sn-a1005','Juniper','Core Router','Fiber',1,'Network','Main Rack','Critical Device');

-- =================================
-- project inventory dummy data
-- =================================

INSERT INTO project_inventory
(name, contract_name, contract_code, contract_start, contract_end, location, pic, support_coverage, preventive_management, partner, partner_pic, remark)
VALUES
    ('Hospital Network Upgrade','Hospital IT Modernization','CTR-2024-001','2024-01-01','2026-01-01','Kuala Lumpur','Ahmad Faiz','24x7','Quarterly','Cisco Partner','John Tan','Active Project'),

    ('Airport Security System','Airport Surveillance System','CTR-2023-010','2023-06-01','2025-06-01','Selangor','Farid Hakim','Business Hours','Bi Yearly','Hikvision Partner','Michael Lee','Maintenance Phase'),

    ('Government Cloud Setup','Gov Private Cloud','CTR-2025-003','2025-03-01','2027-03-01','Putrajaya','Nur Azmi','24x7','Monthly','Dell Partner','Jason Lim','Deployment Ongoing');

-- =================================
-- tender tracker dummy data
-- =================================

INSERT INTO tender_tracker
(tender_name, tender_code, company, submission_date, status, remark)
VALUES
    ('Smart City Monitoring','TND-001','Majlis Bandaraya','2025-05-10','Submitted','Awaiting Result'),
    ('School Network Upgrade','TND-002','Ministry of Education','2025-07-01','Evaluation','Technical Review'),
    ('Data Center Expansion','TND-003','Telekom Malaysia','2025-09-15','Preparation','Proposal Drafting'),
    ('Government Firewall Project','TND-004','MAMPU','2025-10-01','Submitted','Pending Review');

-- =================================
-- administrator dummy data
-- =================================

INSERT INTO administrator (username, password)
VALUES
    ('admin1','$2y$10$6Yp0a7gS0g6nWQ5R3p8bJ.NrHq7Yq0F0d1M8B7QXh5y8rLq6wE6nG'),
    ('admin2','$2y$10$6Yp0a7gS0g6nWQ5R3p8bJ.NrHq7Yq0F0d1M8B7QXh5y8rLq6wE6nG');

-- password for both = admin123


-- =================================
-- system admin dummy data
-- =================================

INSERT INTO system_admin (username, password)
VALUES
    ('sysadmin','$2y$10$6Yp0a7gS0g6nWQ5R3p8bJ.NrHq7Yq0F0d1M8B7QXh5y8rLq6wE6nG'),
    ('superadmin','$2y$10$6Yp0a7gS0g6nWQ5R3p8bJ.NrHq7Yq0F0d1M8B7QXh5y8rLq6wE6nG');

-- password = admin123


-- =================================
-- user dummy data (with roles)
-- =================================

INSERT INTO user (username, password, role)
VALUES
    ('coordinator1','$2y$10$6Yp0a7gS0g6nWQ5R3p8bJ.NrHq7Yq0F0d1M8B7QXh5y8rLq6wE6nG','user_project_coordinator'),

    ('technical1','$2y$10$6Yp0a7gS0g6nWQ5R3p8bJ.NrHq7Yq0F0d1M8B7QXh5y8rLq6wE6nG','user_technical'),

    ('manager1','$2y$10$6Yp0a7gS0g6nWQ5R3p8bJ.NrHq7Yq0F0d1M8B7QXh5y8rLq6wE6nG','user_project_manager');

-- password = admin123