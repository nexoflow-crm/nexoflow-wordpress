<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Agent_Site
{
    const SECRET_OPTION = 'nexoflow_agent_site_secret';

    const PREFIX = 'nfsite_';

    /**
     * Stable for this install at this address. A copy of the database on another
     * address gets a different identifier, so a request meant for one never matches the other.
     */
    public function id()
    {
        return self::PREFIX . substr(hash_hmac('sha256', $this->address(), $this->secret()), 0, 32);
    }

    public function matches($candidate)
    {
        return is_string($candidate) && $candidate !== '' && hash_equals($this->id(), $candidate);
    }

    private function address()
    {
        $parts = wp_parse_url(home_url('/'));

        if (!is_array($parts)) {
            return '';
        }

        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) ? untrailingslashit($parts['path']) : '';

        return $host . $port . $path;
    }

    private function secret()
    {
        $secret = get_option(self::SECRET_OPTION, '');

        if (is_string($secret) && strlen($secret) >= 32) {
            return $secret;
        }

        add_option(self::SECRET_OPTION, wp_generate_password(64, false), '', false);

        $secret = get_option(self::SECRET_OPTION, '');

        return is_string($secret) ? $secret : '';
    }
}
