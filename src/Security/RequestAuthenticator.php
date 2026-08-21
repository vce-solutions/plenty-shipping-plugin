<?php

namespace VCEShipping\Security;

use Plenty\Plugin\CachingRepository;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Http\Request;

class RequestAuthenticator
{
    public const TIMESTAMP_HEADER = 'X-VCE-Timestamp';
    public const NONCE_HEADER = 'X-VCE-Nonce';
    public const SIGNATURE_HEADER = 'X-VCE-Signature';

    private const MAX_CLOCK_SKEW_SECONDS = 300;
    private const NONCE_TTL_MINUTES = 6;

    public function __construct(
        private ConfigRepository $config,
        private CachingRepository $cache
    ) {
    }

    public function authenticate(Request $request): bool
    {
        $secret = trim((string) $this->config->get('VCEShipping.security.sharedSecret', ''));
        $timestamp = trim((string) $request->header(self::TIMESTAMP_HEADER, ''));
        $nonce = trim((string) $request->header(self::NONCE_HEADER, ''));
        $signature = trim((string) $request->header(self::SIGNATURE_HEADER, ''));
        if ($secret === '' || !ctype_digit($timestamp) || $nonce === '' || $signature === '') {
            return false;
        }
        if (abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)) {
            return false;
        }

        $canonical = "POST\n"
            . parse_url($request->getRequestUri(), PHP_URL_PATH) . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . hash('sha256', $request->getContent());
        $expected = 'sha256=' . hash_hmac('sha256', $canonical, $secret);
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        return $this->cache->add(
            'vce-shipping-nonce:' . hash('sha256', $nonce),
            true,
            self::NONCE_TTL_MINUTES
        );
    }
}
