-- Item Receive and Part Request update - 2026-08-11
CREATE TABLE IF NOT EXISTS receiving_records (
    id int NOT NULL AUTO_INCREMENT,
    received_date date NOT NULL,
    received_by varchar(150) NOT NULL,
    item_type varchar(50) NOT NULL,
    item_name varchar(180) NOT NULL,
    part_number varchar(120) DEFAULT NULL,
    serial_number varchar(150) DEFAULT NULL,
    brand varchar(100) DEFAULT NULL,
    description text DEFAULT NULL,
    quantity int NOT NULL DEFAULT 1,
    rack_location varchar(150) NOT NULL,
    purpose_route varchar(30) NOT NULL DEFAULT '',
    purpose_detail text DEFAULT NULL,
    client_name varchar(180) DEFAULT NULL,
    project_name varchar(180) DEFAULT NULL,
    remark text DEFAULT NULL,
    routed_table varchar(80) DEFAULT NULL,
    routed_id int DEFAULT NULL,
    attachment_file_name varchar(255) DEFAULT NULL,
    attachment_original_name varchar(255) DEFAULT NULL,
    attachment_mime varchar(120) DEFAULT NULL,
    attachment_size bigint DEFAULT NULL,
    created_by varchar(150) NOT NULL,
    created_at datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    KEY idx_receiving_date (received_date),
    KEY idx_receiving_receiver (received_by),
    KEY idx_receiving_serial (serial_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE receiving_records ADD COLUMN IF NOT EXISTS attachment_file_name varchar(255) DEFAULT NULL;
ALTER TABLE receiving_records ADD COLUMN IF NOT EXISTS attachment_original_name varchar(255) DEFAULT NULL;
ALTER TABLE receiving_records ADD COLUMN IF NOT EXISTS attachment_mime varchar(120) DEFAULT NULL;
ALTER TABLE receiving_records ADD COLUMN IF NOT EXISTS attachment_size bigint DEFAULT NULL;
CREATE TABLE IF NOT EXISTS part_requests (id int NOT NULL AUTO_INCREMENT, request_id varchar(20) DEFAULT NULL, request_date date NOT NULL, purpose text NOT NULL, ticket_number varchar(100) NOT NULL, part_number varchar(120) NOT NULL, description text NOT NULL, recipient_email varchar(190) NOT NULL, requested_by varchar(150) NOT NULL, email_status varchar(30) NOT NULL DEFAULT 'Pending', email_response text DEFAULT NULL, created_at datetime NOT NULL DEFAULT current_timestamp(), PRIMARY KEY(id), UNIQUE KEY uniq_part_request_id(request_id), KEY idx_part_request_date(request_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS part_request_items (
    id int NOT NULL AUTO_INCREMENT,
    part_request_id int NOT NULL,
    item_number int NOT NULL,
    ticket_number varchar(100) NOT NULL,
    part_number varchar(120) NOT NULL,
    description text NOT NULL,
    created_at datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    UNIQUE KEY uniq_part_request_item_number (part_request_id, item_number),
    KEY idx_part_request_items_request (part_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
