ALTER TABLE message_queue
    ADD COLUMN external_message_id VARCHAR(191) NULL AFTER phone;

CREATE INDEX idx_message_queue_provider_phone_external_message_id
    ON message_queue (provider, phone, external_message_id);
