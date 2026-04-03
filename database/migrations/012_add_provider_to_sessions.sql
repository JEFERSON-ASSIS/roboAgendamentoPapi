ALTER TABLE sessions
    DROP PRIMARY KEY,
    ADD COLUMN provider VARCHAR(20) NOT NULL DEFAULT 'evolution' FIRST,
    ADD PRIMARY KEY (provider, phone);
