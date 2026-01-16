<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

/**
 * Trait InputSanitizer
 * 
 * Provides methods for sanitizing and validating user input to prevent
 * SQL injection, XSS attacks, and other security vulnerabilities.
 */
trait InputSanitizer
{
    /**
     * Sanitize a string input by removing dangerous characters
     *
     * @param string $input
     * @return string
     */
    protected function sanitizeString(string $input): string
    {
        // Remove HTML tags
        $sanitized = strip_tags($input);

        // Remove control characters
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/u', '', $sanitized);

        // Trim whitespace
        $sanitized = trim($sanitized);

        // Convert special characters to HTML entities (XSS prevention)
        $sanitized = htmlspecialchars($sanitized, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $sanitized;
    }

    /**
     * Sanitize input for database storage (less aggressive, allows some chars)
     *
     * @param string $input
     * @return string
     */
    protected function sanitizeForDatabase(string $input): string
    {
        // Remove HTML tags
        $sanitized = strip_tags($input);

        // Remove control characters
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/u', '', $sanitized);

        // Trim whitespace
        $sanitized = trim($sanitized);

        return $sanitized;
    }

    /**
     * Sanitize email address
     *
     * @param string $email
     * @return string|false
     */
    protected function sanitizeEmail(string $email): string|false
    {
        $email = trim(strtolower($email));
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }

    /**
     * Sanitize phone number (keep only digits and +)
     *
     * @param string $phone
     * @return string
     */
    protected function sanitizePhone(string $phone): string
    {
        return preg_replace('/[^0-9+\-\s()]/', '', $phone);
    }

    /**
     * Detect SQL injection patterns in input
     *
     * @param string $input
     * @return bool True if suspicious pattern detected
     */
    protected function detectSqlInjection(string $input): bool
    {
        $patterns = [
            '/[\'"]/i',                          // Quotes
            '/--/',                              // SQL comment
            '/;/',                               // Statement terminator
            '/\/\*.*\*\//s',                     // Block comment
            '/\bunion\s+(all\s+)?select\b/i',    // UNION SELECT
            '/\bselect\s+.*\bfrom\b/i',          // SELECT FROM
            '/\bdrop\s+(table|database)\b/i',   // DROP
            '/\binsert\s+into\b/i',              // INSERT INTO
            '/\bdelete\s+from\b/i',              // DELETE FROM
            '/\bupdate\s+.*\bset\b/i',           // UPDATE SET
            '/\bexec(ute)?\s*\(/i',              // EXEC/EXECUTE
            '/\bxp_/i',                          // xp_ stored procedures
            '/\bsp_/i',                          // sp_ stored procedures
            '/\b(or|and)\s+[\'"0-9]/i',          // OR/AND with value
            '/\b(or|and)\s+\d+\s*=\s*\d+/i',     // OR 1=1 pattern
            '/sleep\s*\(/i',                     // Time-based injection
            '/benchmark\s*\(/i',                 // Benchmark function
            '/load_file\s*\(/i',                 // File access
            '/into\s+(out|dump)file/i',          // File operations
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect XSS patterns in input
     *
     * @param string $input
     * @return bool True if suspicious pattern detected
     */
    protected function detectXss(string $input): bool
    {
        $patterns = [
            '/<script\b/i',                      // Script tag
            '/javascript\s*:/i',                 // Javascript protocol
            '/vbscript\s*:/i',                   // VBScript protocol
            '/on\w+\s*=/i',                      // Event handlers (onclick, onerror, etc.)
            '/<iframe\b/i',                      // Iframe tag
            '/<object\b/i',                      // Object tag
            '/<embed\b/i',                       // Embed tag
            '/<link\b.*\bhref/i',                // Link with href
            '/<style\b/i',                       // Style tag
            '/expression\s*\(/i',                // CSS expression
            '/url\s*\(/i',                       // CSS url()
            '/@import\b/i',                      // CSS import
            '/data\s*:/i',                       // Data URI
            '/<\s*img\b[^>]*\bsrc\s*=/i',        // Image with src in suspicious context
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect any suspicious/malicious input pattern
     *
     * @param string $input
     * @return bool True if suspicious pattern detected
     */
    protected function detectSuspiciousInput(string $input): bool
    {
        return $this->detectSqlInjection($input) || $this->detectXss($input);
    }

    /**
     * Log a security event
     *
     * @param string $type Type of security event
     * @param array $context Additional context
     * @return void
     */
    protected function logSecurityEvent(string $type, array $context = []): void
    {
        Log::channel('security')->warning("Security Event: {$type}", array_merge([
            'timestamp' => now()->toIso8601String(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'url' => request()->fullUrl(),
            'method' => request()->method(),
        ], $context));
    }

    /**
     * Validate and sanitize an array of inputs
     *
     * @param array $inputs
     * @param array $rules Array of field => 'type' (string, email, phone, etc.)
     * @return array Sanitized inputs
     */
    protected function sanitizeInputArray(array $inputs, array $rules = []): array
    {
        $sanitized = [];

        foreach ($inputs as $key => $value) {
            if (!is_string($value)) {
                $sanitized[$key] = $value;
                continue;
            }

            $type = $rules[$key] ?? 'string';

            switch ($type) {
                case 'email':
                    $sanitized[$key] = $this->sanitizeEmail($value);
                    break;
                case 'phone':
                    $sanitized[$key] = $this->sanitizePhone($value);
                    break;
                case 'database':
                    $sanitized[$key] = $this->sanitizeForDatabase($value);
                    break;
                case 'string':
                default:
                    $sanitized[$key] = $this->sanitizeString($value);
                    break;
            }
        }

        return $sanitized;
    }
}
