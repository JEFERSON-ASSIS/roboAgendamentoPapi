ALTER TABLE message_logs
    ADD COLUMN provider VARCHAR(20) NOT NULL DEFAULT 'evolution' AFTER id;

CREATE INDEX idx_message_logs_provider_phone_created
    ON message_logs (provider, phone, created_at, id);
