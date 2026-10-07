SET NAMES utf8mb4;

ALTER TABLE users ADD COLUMN IF NOT EXISTS cpf VARCHAR(14) NULL AFTER email;
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL AFTER cpf;
ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('customer','admin') NOT NULL DEFAULT 'customer' AFTER password_hash;
ALTER TABLE users MODIFY COLUMN status ENUM('active','blocked','pending') NOT NULL DEFAULT 'active';

ALTER TABLE refresh_tokens ADD COLUMN IF NOT EXISTS device_uuid VARCHAR(128) NULL AFTER user_id;
UPDATE refresh_tokens SET device_uuid=CONCAT('legacy-',id) WHERE device_uuid IS NULL OR device_uuid='';
ALTER TABLE refresh_tokens MODIFY COLUMN device_uuid VARCHAR(128) NOT NULL;

CREATE TABLE IF NOT EXISTS checkout_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_id VARCHAR(64) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    status ENUM('creating','waiting','paid','declined','cancelled','expired','failed') NOT NULL DEFAULT 'creating',
    amount_cents INT UNSIGNED NOT NULL,
    pagbank_order_id VARCHAR(190) NULL UNIQUE,
    pagbank_charge_id VARCHAR(190) NULL,
    qr_code_text TEXT NULL,
    qr_code_image_url TEXT NULL,
    raw_response_json JSON NULL,
    raw_webhook_json JSON NULL,
    license_applied TINYINT(1) NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_checkout_user_created (user_id,created_at),
    INDEX idx_checkout_status (status,expires_at),
    CONSTRAINT fk_checkout_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_checkout_plan FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(50) NOT NULL,
    event_hash CHAR(64) NOT NULL,
    status ENUM('received','processed','failed') NOT NULL DEFAULT 'received',
    payload_json LONGTEXT NULL,
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    UNIQUE KEY uq_webhook_event (provider,event_hash),
    INDEX idx_webhooks_status_created (status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO plans (code,name,type,price_cents,billing_interval,max_devices,includes_updates,active)
VALUES
('monthly','Caption IA Mensal','subscription',3000,'month',2,1,1),
('lifetime','Caption IA Vitalício','lifetime',7000,'once',2,0,1)
ON DUPLICATE KEY UPDATE
name=VALUES(name),
price_cents=VALUES(price_cents),
max_devices=VALUES(max_devices),
includes_updates=VALUES(includes_updates),
active=VALUES(active);
