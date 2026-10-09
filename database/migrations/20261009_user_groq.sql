SET NAMES utf8mb4;

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

