<?php

namespace mini;

class CSRF
{
    private static ?string $secret = null;

    public static function init(): void
    {
        session();

        // Generate or retrieve session secret
        if (!isset($_SESSION['csrf_secret'])) {
            $_SESSION['csrf_secret'] = bin2hex(random_bytes(32));
        }

        self::$secret = $_SESSION['csrf_secret'];
    }

    public static function getToken(string $action = 'default'): string
    {
        if (self::$secret === null) {
            self::init();
        }

        $userId = $_SESSION['user_id'] ?? '';
        $sessionId = session_id();

        return hash_hmac('sha256', $action . $userId . $sessionId, self::$secret);
    }

    public static function verifyToken(string $token, string $action = 'default'): bool
    {
        if (self::$secret === null) {
            self::init();
        }

        $expectedToken = self::getToken($action);
        return hash_equals($expectedToken, $token);
    }

    public static function field(string $action = 'default'): string
    {
        $token = self::getToken($action);
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function check(array $data, string $action = 'default'): void
    {
        $token = $data['_csrf'] ?? '';

        if (!self::verifyToken($token, $action)) {
            http_response_code(403);
            die('CSRF token validation failed');
        }
    }
}