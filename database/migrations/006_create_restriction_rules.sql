CREATE TABLE IF NOT EXISTS restriction_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    match_type VARCHAR(20) NOT NULL DEFAULT 'contains',
    trigger_value TEXT NOT NULL,
    response_message TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    priority INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE INDEX idx_restriction_rules_active_priority
    ON restriction_rules (is_active, priority, id);
