<?php
declare(strict_types=1);

namespace CaptionIA\Services;

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;
use RuntimeException;

final class AuthService
{
    public function currentUser(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }

        $stmt = Database::connection()->prepare(
            'SELECT id,name,email,cpf,phone,role,status,created_at
             FROM users WHERE id=? LIMIT 1'
        );
        $stmt->execute([(int) $id]);
        $user = $stmt->fetch();

        if (!$user || $user['status'] !== 'active') {
            unset($_SESSION['user_id']);
            return null;
        }

        return $user;
    }

    public function register(string $name, string $email, string $cpf, string $phone, string $password): array
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        $cpf = preg_replace('/\D+/', '', $cpf) ?? '';
        $phone = preg_replace('/\D+/', '', $phone) ?? '';

        if (mb_strlen($name) < 3) {
            throw new RuntimeException('Informe seu nome completo.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um e-mail válido.');
        }
        if (!$this->validCpf($cpf)) {
            throw new RuntimeException('Informe um CPF válido.');
        }
        if (strlen($phone) < 10 || strlen($phone) > 11) {
            throw new RuntimeException('Informe um celular válido com DDD.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('A senha precisa ter pelo menos 8 caracteres.');
        }

        $pdo = Database::connection();
        $check = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetch()) {
            throw new RuntimeException('Já existe uma conta com este e-mail. Entre para continuar.');
        }

        $adminEmail = mb_strtolower(trim(Env::get('ADMIN_EMAIL', '') ?: ''));
        $role = $adminEmail !== '' && hash_equals($adminEmail, $email) ? 'admin' : 'customer';
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algo);

        $stmt = $pdo->prepare(
            'INSERT INTO users
             (name,email,cpf,phone,password_hash,role,status,created_at,updated_at)
             VALUES (?,?,?,?,?,?,\'active\',NOW(),NOW())'
        );
        $stmt->execute([$name, $email, $cpf, $phone, $hash, $role]);

        $this->loginById((int) $pdo->lastInsertId());
        return $this->currentUser() ?? throw new RuntimeException('Não foi possível criar a conta.');
    }

    public function login(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new RuntimeException('E-mail ou senha inválidos.');
        }
        if ($user['status'] !== 'active') {
            throw new RuntimeException('Esta conta está bloqueada.');
        }

        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        if (password_needs_rehash($user['password_hash'], $algo)) {
            $newHash = password_hash($password, $algo);
            $up = Database::connection()->prepare('UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?');
            $up->execute([$newHash, $user['id']]);
        }

        $this->loginById((int) $user['id']);
        return $this->currentUser() ?? throw new RuntimeException('Não foi possível iniciar a sessão.');
    }

    public function authenticateCredentials(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash']) || $user['status'] !== 'active') {
            throw new RuntimeException('E-mail ou senha inválidos.');
        }

        unset($user['password_hash']);
        return $user;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public function requireUser(): array
    {
        $user = $this->currentUser();
        if (!$user) {
            throw new RuntimeException('AUTH_REQUIRED');
        }
        return $user;
    }

    public function requireAdmin(): array
    {
        $user = $this->requireUser();
        if (($user['role'] ?? '') !== 'admin') {
            throw new RuntimeException('ADMIN_REQUIRED');
        }
        return $user;
    }

    private function loginById(int $id): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        $_SESSION['authenticated_at'] = time();
    }

    private function validCpf(string $cpf): bool
    {
        if (!preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $digit = (10 * $sum) % 11;
            if ($digit === 10) {
                $digit = 0;
            }
            if ((int) $cpf[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }
}
