CREATE TABLE integration_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service VARCHAR(100) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    request_payload LONGTEXT NULL,
    response_payload LONGTEXT NULL,
    status_code INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
