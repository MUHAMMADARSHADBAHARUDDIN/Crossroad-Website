-- cmis dummy data
-- database: crossroad_solution_inventory_management

use crossroad_solutions_inventory_management;

-- =================================
-- asset inventory dummy data
-- =================================

insert into asset_inventory
(part_number, serial_number, brand, description, interface, quantity, type, location, remark)
values
('pn-1001','sn-a1001','cisco','network switch 24 port','ethernet',5,'network','server room a','active'),
('pn-1002','sn-a1002','hp','rack mount server','ethernet',2,'server','data center 1','running'),
('pn-1003','sn-a1003','dell','storage device','sas',3,'storage','data center 1','backup storage'),
('pn-1004','sn-a1004','mikrotik','router board','ethernet',4,'network','network rack','stable'),
('pn-1005','sn-a1005','juniper','core router','fiber',1,'network','main rack','critical device');

-- =================================
-- project inventory dummy data
-- =================================

insert into project_inventory
(name, contract_name, contract_code, contract_start, contract_end, location, pic, support_coverage, preventive_management, partner, partner_pic, remark)
values
('hospital network upgrade','hospital it modernization','ctr-2024-001','2024-01-01','2026-01-01','kuala lumpur','ahmad faiz','24x7','quarterly','cisco partner','john tan','active project'),

('airport security system','airport surveillance system','ctr-2023-010','2023-06-01','2025-06-01','selangor','farid hakim','business hours','bi yearly','hikvision partner','michael lee','maintenance phase'),

('government cloud setup','gov private cloud','ctr-2025-003','2025-03-01','2027-03-01','putrajaya','nur azmi','24x7','monthly','dell partner','jason lim','deployment ongoing');

-- =================================
-- tender tracker dummy data
-- =================================

insert into tender_tracker
(tender_name, tender_code, company, submission_date, status, remark)
values
('smart city monitoring','tnd-001','majlis bandaraya','2025-05-10','submitted','awaiting result'),
('school network upgrade','tnd-002','ministry of education','2025-07-01','evaluation','technical review'),
('data center expansion','tnd-003','telekom malaysia','2025-09-15','preparation','proposal drafting'),
('government firewall project','tnd-004','mampu','2025-10-01','submitted','pending review');

-- =================================
-- administrator dummy data
-- =================================

insert into administrator
(username, password)
values
('admin1','admin123'),
('admin2','admin456');

-- =================================
-- user dummy data
-- =================================

insert into user
(username, password)
values
('user1','user123'),
('user2','user456'),
('staff1','staff123');

-- =================================
-- system admin dummy data
-- =================================

insert into system_admin
(username, password)
values
('sysadmin','sysadmin123'),
('superadmin','super123');
