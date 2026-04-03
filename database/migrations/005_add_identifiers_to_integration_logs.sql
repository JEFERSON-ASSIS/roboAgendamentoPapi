ALTER TABLE integration_logs
    ADD COLUMN phone VARCHAR(30) NULL AFTER endpoint,
    ADD COLUMN cpf VARCHAR(20) NULL AFTER phone,
    ADD INDEX idx_integration_logs_phone (phone),
    ADD INDEX idx_integration_logs_cpf (cpf);
