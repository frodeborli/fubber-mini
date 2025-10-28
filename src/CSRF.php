<?php

namespace mini;

/**
 * WordPress-inspired nonce (CSRF) token system
 *
 * Tokens are self-contained with action, timestamp, and IP address,
 * signed with HMAC using session ID, user agent, and application salt.
 *
 * Usage:
 *   $nonce = new CSRF('delete-post');
 *   render('form.php', ['nonce' => $nonce]);
 *
 *   // In template:
 *   <form method="post">
 *     <?= $nonce ?>
 *     ...
 *   </form>
 *
 *   // Verify:
 *   $nonce = new CSRF('delete-post');
 *   if ($nonce->verify($_POST['__nonce__'])) {
 *     // Process form
 *   }
 */
class CSRF
{
    private string $action;
    private string $fieldName;
    private ?string $token = null;

    /**
     * Create a CSRF token for a specific action
     *
     * @param string $action Action name (e.g., 'delete-post', 'update-settings')
     * @param string $fieldName HTML field name (default: '__nonce__')
     */
    public function __construct(string $action, string $fieldName = '__nonce__')
    {
        $this->action = $action;
        $this->fieldName = $fieldName;
    }

    /**
     * Build signature key from hard-to-guess components
     *
     * Includes application salt, session ID, and user agent to make
     * tokens difficult to forge even if attacker knows the action.
     */
    private function buildSignatureKey(): string
    {
        $hardToGuess = Mini::$mini->salt;

        // Include session ID if available
        $sessionName = session_name() ?: '';
        if ($sessionName && isset($_COOKIE[$sessionName])) {
            $hardToGuess .= $_COOKIE[$sessionName];
        }

        // Include user agent for browser fingerprinting
        $hardToGuess .= $_SERVER['HTTP_USER_AGENT'] ?? '';

        return $hardToGuess;
    }

    /**
     * Generate a new token with current timestamp and IP
     */
    private function generateToken(): string
    {
        $data = implode('|', [
            $this->action,
            (string) microtime(true),
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        $signature = hash_hmac('sha256', $data, $this->buildSignatureKey());
        $token = $data . '|' . $signature;

        return base64_encode($token);
    }

    /**
     * Get the token string (lazy generation)
     */
    public function getToken(): string
    {
        if ($this->token === null) {
            $this->token = $this->generateToken();
        }
        return $this->token;
    }

    /**
     * Verify a token
     *
     * @param string|null $token Token to verify (typically from $_POST)
     * @param float $maxAge Maximum age in seconds (default: 86400 = 24 hours)
     * @return bool True if valid and not expired
     */
    public function verify(?string $token, float $maxAge = 86400): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        // Decode token
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return false;
        }

        // Split into parts: action|time|ip|signature
        $parts = explode('|', $decoded);
        if (count($parts) !== 4) {
            return false;
        }

        [$action, $time, $ip, $signature] = $parts;

        // Verify action matches
        if ($action !== $this->action) {
            return false;
        }

        // Verify not expired
        $age = microtime(true) - (float) $time;
        if ($age > $maxAge || $age < 0) {
            return false;
        }

        // Verify IP matches (if IP was recorded)
        $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip !== '' && $ip !== $currentIp) {
            return false;
        }

        // Verify signature using same key derivation
        $data = implode('|', [$action, $time, $ip]);
        $expectedSignature = hash_hmac('sha256', $data, $this->buildSignatureKey());

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Output hidden input field
     */
    public function __toString(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars($this->fieldName, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->getToken(), ENT_QUOTES, 'UTF-8')
        );
    }
}
