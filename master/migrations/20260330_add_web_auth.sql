ALTER TABLE users
    ADD COLUMN password_hash VARCHAR(255) NULL AFTER api_token;

ALTER TABLE users
    ADD COLUMN role VARCHAR(32) NOT NULL DEFAULT 'tenant' AFTER password_hash;
