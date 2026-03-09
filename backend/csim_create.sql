-- use database
use crossroad_solutions_inventory_management;

-- =========================
-- 1. asset inventory table
-- =========================
drop table if exists asset_inventory;

create table asset_inventory (
    no int auto_increment primary key,
    part_number varchar(100),
    serial_number varchar(100),
    brand varchar(100),
    description text,
    interface varchar(100),
    quantity int,
    type varchar(100),
    location varchar(150),
    remark text
);

-- =========================
-- 2. project inventory table
-- =========================
drop table if exists project_inventory;

create table project_inventory (
    no int auto_increment primary key,
    name varchar(150),
    contract_name varchar(150),
    contract_code varchar(100),
    contract_start date,
    contract_end date,
    location varchar(150),
    pic varchar(150),
    support_coverage varchar(150),
    preventive_management varchar(150),
    partner varchar(150),
    partner_pic varchar(150),
    remark text
);

-- =========================
-- 3. tender tracker table
-- =========================
drop table if exists tender_tracker;

create table tender_tracker (
    no int auto_increment primary key,
    tender_name varchar(150),
    tender_code varchar(100),
    company varchar(150),
    submission_date date,
    status varchar(100),
    remark text
);

-- =========================
-- 4. administrator table
-- =========================
drop table if exists administrator;

create table administrator (
    username varchar(100) primary key,
    password varchar(255)
);

-- =========================
-- 5. user table
-- =========================
drop table if exists user;

create table user (
    username varchar(100) primary key,
    password varchar(255)
);

-- =========================
-- 6. system admin table
-- =========================
drop table if exists system_admin;

create table system_admin (
    username varchar(100) primary key,
    password varchar(255)

);
