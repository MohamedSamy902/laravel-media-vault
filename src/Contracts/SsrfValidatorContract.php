<?php

namespace MohamedSamy902\LaravelMediaVault\Contracts;

use MohamedSamy902\LaravelMediaVault\Exceptions\SsrfException;

interface SsrfValidatorContract
{
    /**
     * Validate a URL against SSRF attack vectors.
     *
     * Checks scheme, hostname resolution, private/reserved IP ranges,
     * and optional domain allowlist.
     *
     * @param  string  $url
     * @return string         The resolved IP address to be used with CURLOPT_RESOLVE
     * @throws SsrfException  When the URL is blocked
     */
    public function validate(string $url): string;
}
