-- Run once in phpMyAdmin before deploying token-aware PHP.
-- Nullable during rollout: cables without tokens have no public access.
ALTER TABLE pwg_cables
    ADD COLUMN public_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD UNIQUE KEY uq_pwg_cables_public_token (public_token);

-- Backfill existing rows using PHP random_bytes(16):
-- php tools/backfill-cable-tokens.php /absolute/path/to/pwg-db-config.php
-- Do not use RAND(), UUID(), serial hashes, or shared batch tokens.
