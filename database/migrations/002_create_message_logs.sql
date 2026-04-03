CREATE TABLE message_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(30) NOT NULL,
    direction VARCHAR(10) NOT NULL,
    message_type VARCHAR(30) NOT NULL,
    raw_payload LONGTEXT NULL,
    normalized_text LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
