SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    cpf VARCHAR(14) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('customer','admin') NOT NULL DEFAULT 'customer',
    status ENUM('active','blocked','pending') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- BYOK Groq: segregação por cliente; ciphertext AES-256-GCM derivado de APP_KEY.
CREATE TABLE IF NOT EXISTS user_groq_credentials (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    key_ciphertext TEXT NOT NULL,
    key_suffix CHAR(4) NOT NULL,
    verified_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_groq_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    type ENUM('subscription','lifetime') NOT NULL,
    price_cents INT UNSIGNED NOT NULL,
    billing_interval ENUM('month','year','once') NOT NULL,
    max_devices TINYINT UNSIGNED NOT NULL DEFAULT 2,
    includes_updates TINYINT(1) NOT NULL DEFAULT 0,
    featured TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO plans (code,name,type,price_cents,billing_interval,max_devices,includes_updates,active)
VALUES
('monthly','Caption IA Mensal','subscription',3000,'month',2,1,1),
('lifetime','Caption IA Vitalício','lifetime',7000,'once',2,0,1);

CREATE TABLE IF NOT EXISTS licenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    status ENUM('active','expired','revoked','pending') NOT NULL DEFAULT 'pending',
    purchased_version VARCHAR(30) NULL,
    max_major_version INT UNSIGNED NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_license_user_status (user_id,status,expires_at),
    CONSTRAINT fk_licenses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_licenses_plan FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS license_devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id BIGINT UNSIGNED NOT NULL,
    device_uuid VARCHAR(128) NOT NULL,
    device_name VARCHAR(160) NULL,
    os VARCHAR(80) NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_license_device (license_id,device_uuid),
    INDEX idx_devices_active (license_id,revoked_at),
    CONSTRAINT fk_devices_license FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    device_uuid VARCHAR(128) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_refresh_user_device (user_id,device_uuid,revoked_at,expires_at),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(50) NULL,
    provider_subscription_id VARCHAR(190) NULL,
    status ENUM('pending','active','past_due','cancelled','expired') NOT NULL DEFAULT 'pending',
    current_period_end DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES plans(id),
    UNIQUE KEY uq_provider_subscription (provider,provider_subscription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checkout_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_id VARCHAR(64) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    status ENUM('creating','waiting','paid','declined','cancelled','expired','failed') NOT NULL DEFAULT 'creating',
    amount_cents INT UNSIGNED NOT NULL,
    provider_mode VARCHAR(32) NOT NULL DEFAULT 'transparent_pix',
    pagbank_order_id VARCHAR(190) NULL UNIQUE,
    pagbank_checkout_id VARCHAR(190) NULL UNIQUE,
    pagbank_charge_id VARCHAR(190) NULL,
    hosted_checkout_url TEXT NULL,
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

CREATE TABLE IF NOT EXISTS payment_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    provider VARCHAR(50) NOT NULL,
    provider_payment_id VARCHAR(190) NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    status VARCHAR(50) NOT NULL,
    payload_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_provider_payment (provider,provider_payment_id),
    INDEX idx_payments_user_created (user_id,created_at),
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
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

CREATE TABLE IF NOT EXISTS app_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(80) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings (setting_key,setting_value,updated_at) VALUES
('payment_gateway','bacen_pix',NOW()),
('bacen_pix_name','CAPTION IA',NOW()),
('bacen_pix_city','FORTALEZA',NOW());

CREATE TABLE IF NOT EXISTS releases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(30) NOT NULL UNIQUE,
    major_version INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    sha256 CHAR(64) NULL,
    file_size BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user_created (user_id,created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Cloud Core: counters are server-owned and indexed by user, feature and minute.
CREATE TABLE IF NOT EXISTS cloud_rate_windows (
    user_id BIGINT UNSIGNED NOT NULL,
    feature VARCHAR(60) NOT NULL,
    window_start DATETIME NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id,feature,window_start),
    INDEX idx_cloud_window_cleanup (window_start),
    CONSTRAINT fk_cloud_rate_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS cloud_assets (
  id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  label VARCHAR(255) NOT NULL,
  category VARCHAR(40) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  byte_size BIGINT UNSIGNED NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cloud_assets_catalog (active,category,label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Affiliate/financial modules (non-destructive; feature OFF until admin enables).
INSERT IGNORE INTO app_settings (setting_key,setting_value,updated_at) VALUES
('affiliate_program_enabled','0',NOW()),
('affiliate_default_percent','15',NOW()),
('affiliate_minimum_withdrawal_cents','5000',NOW()),
('affiliate_hold_days','14',NOW()),
('affiliate_auto_enable_buyers','1',NOW()),
('analytics_enabled','1',NOW());

CREATE TABLE IF NOT EXISTS affiliate_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  code CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_affiliate_user (user_id),
  UNIQUE KEY uq_affiliate_code (code),
  KEY idx_affiliate_enabled (enabled,created_at),
  CONSTRAINT fk_affiliate_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS affiliate_attributions (
  order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  affiliate_id BIGINT UNSIGNED NOT NULL,
  rate_bps SMALLINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_attribution_affiliate (affiliate_id,created_at),
  CONSTRAINT fk_attr_order FOREIGN KEY (order_id) REFERENCES checkout_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_attr_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliate_profiles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS affiliate_commissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  affiliate_id BIGINT UNSIGNED NOT NULL,
  amount_cents INT UNSIGNED NOT NULL,
  status ENUM('earned','reversed') NOT NULL DEFAULT 'earned',
  available_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_commission_order (order_id),
  KEY idx_commission_balance (affiliate_id,status,available_at),
  CONSTRAINT fk_comm_order FOREIGN KEY (order_id) REFERENCES checkout_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_comm_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliate_profiles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS affiliate_withdrawals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  affiliate_id BIGINT UNSIGNED NOT NULL,
  amount_cents INT UNSIGNED NOT NULL,
  pix_key_ciphertext TEXT NOT NULL,
  status ENUM('requested','paid','rejected') NOT NULL DEFAULT 'requested',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  resolved_by BIGINT UNSIGNED NULL,
  admin_note VARCHAR(500) NULL,
  KEY idx_withdrawal_status (status,requested_at),
  KEY idx_withdrawal_profile (affiliate_id,status),
  CONSTRAINT fk_withdrawal_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliate_profiles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_withdrawal_admin FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Events contain only whitelisted funnel stage, anonymous visit id and optional
-- authenticated user id; never store IP, input content, Pix, CPF or full URL.
CREATE TABLE IF NOT EXISTS site_funnel_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(32) NOT NULL,
  page_key VARCHAR(30) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_funnel_type_date (event_type,created_at),
  KEY idx_funnel_visit_date (visit_id,created_at),
  KEY idx_funnel_page_date (page_key,created_at),
  CONSTRAINT fk_funnel_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Admin-only corrections to access after a verified payment.
-- Historical checkout_orders and payment_transactions are never overwritten.
CREATE TABLE IF NOT EXISTS license_access_adjustments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    license_id BIGINT UNSIGNED NOT NULL,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    old_plan_id BIGINT UNSIGNED NOT NULL,
    new_plan_id BIGINT UNSIGNED NOT NULL,
    old_expires_at DATETIME NULL,
    new_expires_at DATETIME NULL,
    reason VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_access_adjust_order (order_id,created_at),
    KEY idx_access_adjust_license (license_id,created_at),
    KEY idx_access_adjust_admin (admin_user_id,created_at),
    CONSTRAINT fk_access_adjust_order FOREIGN KEY (order_id) REFERENCES checkout_orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_access_adjust_license FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_access_adjust_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_access_adjust_old_plan FOREIGN KEY (old_plan_id) REFERENCES plans(id) ON DELETE RESTRICT,
    CONSTRAINT fk_access_adjust_new_plan FOREIGN KEY (new_plan_id) REFERENCES plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Administrative presentation and managerial corrections; never alter receipts.
CREATE TABLE IF NOT EXISTS order_admin_overrides (
  order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  display_amount_cents INT UNSIGNED NULL,
  archived_at DATETIME NULL,
  archived_reason VARCHAR(500) NULL,
  updated_by BIGINT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_order_override_archived (archived_at),
  CONSTRAINT fk_order_override_order FOREIGN KEY (order_id) REFERENCES checkout_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_override_actor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_admin_change_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  admin_user_id BIGINT UNSIGNED NOT NULL,
  action ENUM('adjust_value','reset_value','archive','restore') NOT NULL,
  before_amount_cents INT UNSIGNED NULL,
  after_amount_cents INT UNSIGNED NULL,
  reason VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order_change_log_order (order_id,created_at),
  CONSTRAINT fk_order_log_order FOREIGN KEY (order_id) REFERENCES checkout_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_log_actor FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
