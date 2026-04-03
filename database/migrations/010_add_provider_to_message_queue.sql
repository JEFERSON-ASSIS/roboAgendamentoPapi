ALTER TABLE message_queue
    ADD COLUMN provider VARCHAR(20) NOT NULL DEFAULT 'evolution' AFTER id;

DROP INDEX idx_message_queue_phone_processed_created ON message_queue;

CREATE INDEX idx_message_queue_provider_phone_processed_created
    ON message_queue (provider, phone, processed, created_at, id);
