<?php

return [
    // Comma-separated list of trusted immediate proxy IPs/CIDRs. Empty by
    // default: no proxy is trusted until explicitly configured in production.
    'proxies' => env('TRUSTED_PROXIES'),
];
