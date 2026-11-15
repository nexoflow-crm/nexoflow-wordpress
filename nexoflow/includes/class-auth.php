<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Auth
{
    const OK = 'ok';

    const NOT_CONNECTED = 'not_connected';

    const MISSING = 'missing';

    const INVALID = 'invalid';

    public static function check()
    {
        if (!NexoFlow_Client::has_site_key()) {
            return self::NOT_CONNECTED;
        }

        $token = NexoFlow_Client::request_bearer_token();

        if ($token === '') {
            return self::MISSING;
        }

        if (!hash_equals(NexoFlow_Client::site_key(), $token)) {
            return self::INVALID;
        }

        return self::OK;
    }
}
