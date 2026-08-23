<?php

namespace VCEShipping\Security;

use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Http\Request;

class RequestAuthenticator
{
    public const SECRET_HEADER = 'X-VCE-Shared-Secret';

    public function __construct(private ConfigRepository $config)
    {
    }

    public function authenticate(Request $request): bool
    {
        $expected = trim((string) $this->config->get('VCEShipping.security.sharedSecret', ''));
        $provided = trim((string) $request->header(self::SECRET_HEADER, ''));
        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }
}
