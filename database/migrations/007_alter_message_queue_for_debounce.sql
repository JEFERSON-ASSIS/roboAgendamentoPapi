ALTER TABLE message_queue
    ADD COLUMN media_url TEXT NULL AFTER message_type,
    ADD COLUMN payload_json LONGTEXT NULL AFTER media_url,
    ADD COLUMN processed_at DATETIME NULL AFTER processed;

CREATE INDEX idx_message_queue_phone_processed_created
    ON message_queue (phone, processed, created_at, id);
