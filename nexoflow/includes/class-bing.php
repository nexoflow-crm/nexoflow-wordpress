<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bing's verification tag. When the business connects Bing in NexoFlow,
 * NexoFlow adds the site to the business's Bing account and sends Bing's code
 * for it with the site's jobs; the plugin prints it on the homepage as Bing's
 * msvalidate.01 meta tag, so Bing confirms the site is the business's with
 * nothing for anyone to do here. Disconnecting the site removes it.
 */
class NexoFlow_Bing
{
    const OPTION_CODE = 'nexoflow_bing_site_verification';

    public function register()
    {
        add_action('wp_head', array($this, 'print_tag'), 1);
        add_action('nexoflow_disconnected', array(__CLASS__, 'forget'));
    }

    /** A code Bing gives: letters and digits only. */
    public static function is_code($value)
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9]{8,64}$/', $value) === 1;
    }

    /** Bing's code for this site, or empty. */
    public static function stored_code()
    {
        $code = get_option(self::OPTION_CODE, '');

        return self::is_code($code) ? $code : '';
    }

    /** Keep Bing's code NexoFlow sends with the site's jobs. A missing or malformed one changes nothing. */
    public static function keep_code($response)
    {
        if (!is_array($response) || !isset($response['bingSiteVerification']) || !self::is_code($response['bingSiteVerification'])) {
            return;
        }

        if ($response['bingSiteVerification'] !== self::stored_code()) {
            update_option(self::OPTION_CODE, $response['bingSiteVerification'], false);
        }
    }

    public static function forget()
    {
        delete_option(self::OPTION_CODE);
    }

    /** The tag, or empty when there is no code. */
    public static function tag()
    {
        $code = self::stored_code();

        return $code === '' ? '' : '<meta name="msvalidate.01" content="' . esc_attr($code) . '" />' . "\n";
    }

    public function print_tag()
    {
        if (!is_front_page()) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tag() escapes the code, which is letters and digits only.
        echo self::tag();
    }
}
