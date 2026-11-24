ALTER TABLE stifin_voucher_queue
    ADD COLUMN IF NOT EXISTS transfer_branch VARCHAR(32) NULL,
    ADD COLUMN IF NOT EXISTS transfer_promoter VARCHAR(80) NULL,
    ADD COLUMN IF NOT EXISTS transfer_status ENUM('pending', 'review', 'sent') NULL,
    ADD COLUMN IF NOT EXISTS transfer_note VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS transfer_checked_at DATETIME NULL,
    ADD INDEX IF NOT EXISTS stifin_transfer_pending (transfer_status, id);
