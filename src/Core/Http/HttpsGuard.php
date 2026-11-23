<?php
declare(strict_types=1);

namespace App\Core\Http;

/**
 * Enforce HTTPS at both front controllers, before handling credentials.
 * HTTP is permitted only for a loopback request addressed to localhost.
 * TLS-terminating reverse proxies must expose the original secure scheme to PHP;
 * untrusted X-Forwarded-* request headers are deliberately not trusted.
 */
final class HttpsGuard
{
    public static function isSecure(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if (in_array($https, ['on', '1', 'true'], true)
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        // Honour X-Forwarded-Proto only from an explicitly trusted TLS proxy.
        $trusted = array_filter(
            array_map('trim', explode(',', (string) (getenv('DUP_TRUSTED_HTTPS_PROXIES') ?: ''))),
            static fn (string $address): bool => $address !== ''
        );
        return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $trusted, true)
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function isLocalDevelopment(): bool
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
            return false;
        }
        $host = parse_url('http://'.(string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        return is_string($host) && in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }

    public static function enforce(): void
    {
        if (PHP_SAPI === 'cli' || self::isSecure() || self::isLocalDevelopment()) {
            return;
        }

        $host = parse_url('http://'.(string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        if (!is_string($host) || $host === '' ||
            (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
            http_response_code(400);
            exit('Invalid host.');
        }

        // The HTTPS port is 443 by default; generic hosts may configure another.
        $authority = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '['.$host.']' : $host;
        $portValue = (string) (getenv('DUP_HTTPS_PORT') ?: '443');
        $port = ctype_digit($portValue) ? (int) $portValue : 0;
        if ($port < 1 || $port > 65535) {
            http_response_code(500);
            exit('Invalid server HTTPS port configuration.');
        }
        if ($port !== 443) {
            $authority .= ':'.$port;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if (!str_starts_with($uri, '/') || str_starts_with($uri, '//') ||
            str_contains($uri, "\r") || str_contains($uri, "\n")) {
            $uri = '/';
        }
        header('Cache-Control: no-store');
        if (in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
            header('Location: https://'.$authority.$uri, true, 308);
        } else {
            // Never replay a plaintext login/setup POST via a 307/308 redirect.
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'HTTPS required. Reopen DUP using https:// before submitting forms.';
        }
        exit;
    }
}
