CREATE TABLE IF NOT EXISTS visitors (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(40) NOT NULL,
    unit_number VARCHAR(2) NOT NULL DEFAULT '01',
    company VARCHAR(180) NOT NULL,
    person_to_meet VARCHAR(150) NOT NULL DEFAULT '',
    purpose VARCHAR(500) NOT NULL,
    visit_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_visitors_visit_time (visit_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
