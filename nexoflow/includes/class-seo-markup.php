<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Small changes to the HTML of a finished page, and the text helpers the SEO
 * tags need. Plain string work with no WordPress in it: when a page does not
 * look the way these expect, it is returned untouched.
 */
class NexoFlow_SEO_Markup
{
    const MAX_HTML_BYTES = 3145728;

    /** Parts of a page whose contents are not page structure. */
    const OPAQUE = '#<!--.*?-->|<(script|style|textarea|template|noscript|svg)\b[^>]*>.*?</\1\s*>#is';

    /**
     * Exactly one main heading on the page. With none, the first h2 of the page's
     * own content becomes it (or the first heading, when it has no h2). With
     * several, the first stays and the others become h2. Tags change, attributes
     * and words do not, and a small script right after each one keeps it looking
     * as it did: a tag can carry its own size and spacing.
     */
    public static function fix_headings($html)
    {
        if (!self::is_page($html)) {
            return $html;
        }

        $masked = self::mask($html);

        // Scripts, styles and comments could not be set aside, so nothing on the page is touched.
        if ($masked === null) {
            return $html;
        }

        $body = stripos($masked['html'], '<body');

        if ($body === false) {
            return $html;
        }

        $found = self::headings($masked['html'], $body);
        $main = array();

        foreach ($found as $heading) {
            if ($heading['level'] === 1) {
                $main[] = $heading;
            }
        }

        $changes = array();

        if (count($main) > 1) {
            foreach (array_slice($main, 1) as $heading) {
                $changes[] = array('heading' => $heading, 'level' => 2);
            }
        } elseif (empty($main)) {
            $chosen = self::choose_main($masked['html'], $body, $found);

            if ($chosen !== null) {
                $changes[] = array('heading' => $chosen, 'level' => 1);
            }
        }

        if (empty($changes)) {
            return $html;
        }

        // From the end, so the offsets of the earlier headings stay right.
        usort(
            $changes,
            function ($a, $b) {
                return $b['heading']['open'] - $a['heading']['open'];
            }
        );

        $out = $masked['html'];

        foreach ($changes as $change) {
            $heading = $change['heading'];
            $close = '</h' . $change['level'] . '>' . self::keep_look($heading['level']);
            $open = '<h' . $change['level'];
            $out = substr_replace($out, $close, $heading['close'], $heading['close_length']);
            $out = substr_replace($out, $open, $heading['open'], 3);
        }

        return self::unmask($out, $masked['parts']);
    }

    /**
     * Measures a hidden heading of the old level beside the changed one, and gives the
     * changed one whatever differs. Runs where the heading is, before anything is painted.
     */
    private static function keep_look($level)
    {
        return '<script>(function(s){var h=s.previousElementSibling;if(!h)return;var k=["fontSize","fontWeight","fontFamily","fontStyle","lineHeight","letterSpacing","textTransform","color","marginTop","marginBottom"];'
            . 'function a(){k.forEach(function(p){h.style[p]=""});var t=document.createElement("h' . (int) $level . '");t.className=h.className;t.style.cssText="position:absolute;visibility:hidden;margin-left:-9999px";h.parentNode.insertBefore(t,h);var c=getComputedStyle(t),o=getComputedStyle(h);k.forEach(function(p){if(c[p]!==o[p])h.style[p]=c[p]});t.parentNode.removeChild(t)}'
            . 'a();addEventListener("resize",a)})(document.currentScript)</script>';
    }

    /** The page says how to fit a phone screen. Most themes do; one that does not fails every phone check. */
    public static function ensure_viewport($html)
    {
        if (!self::is_page($html) || preg_match('#<meta\b[^>]*\bname\s*=\s*["\']?viewport#i', $html)) {
            return $html;
        }

        return self::after_head_tag($html, '<meta name="viewport" content="width=device-width, initial-scale=1">');
    }

    /** The page says what language it is in. */
    public static function ensure_language($html, $language)
    {
        $language = is_string($language) ? preg_replace('/[^A-Za-z0-9\-]/', '', $language) : '';

        if ($language === '' || !self::is_page($html) || !preg_match('#<html\b[^>]*>#i', $html, $match)) {
            return $html;
        }

        if (preg_match('#\blang\s*=#i', $match[0])) {
            return $html;
        }

        return preg_replace('#<html\b#i', '<html lang="' . $language . '"', $html, 1);
    }

    /** The page has a title. A theme that leaves it out gets the one WordPress would have used. */
    public static function ensure_title($html, $title)
    {
        if (!is_string($title) || $title === '' || !self::is_page($html)) {
            return $html;
        }

        $head_end = stripos($html, '</head');

        if ($head_end === false || preg_match('#<title\b#i', substr($html, 0, $head_end))) {
            return $html;
        }

        return self::after_head_tag($html, '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>');
    }

    /** The address of the first image in a piece of HTML, or an empty string. */
    public static function first_image($html)
    {
        if (!is_string($html) || !preg_match('#<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']#i', $html, $match)) {
            return '';
        }

        return $match[1];
    }

    /**
     * A description from a page's own text: whole sentences when they fit, else
     * whole words. Empty when the page says too little to describe it.
     */
    public static function describe($text, $max = 155, $min = 50)
    {
        if (!is_string($text)) {
            return '';
        }

        $text = preg_replace('#<!--.*?-->|<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $text);
        // A heading runs straight into the sentence after it unless something ends it.
        $text = preg_replace('#</h[1-6]\s*>#i', '. ', (string) $text);
        $text = preg_replace('#</(p|div|li|section|br)\s*>|<br\s*/?>#i', ' ', (string) $text);
        $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $text = (string) preg_replace('/([.!?:;,])\s*\.(\s|$)/u', '$1$2', $text);

        if (self::length($text) < $min) {
            return '';
        }

        if (self::length($text) <= $max) {
            return $text;
        }

        $cut = self::slice($text, $max);
        $end = max(strrpos($cut, '. '), strrpos($cut, '! '), strrpos($cut, '? '));

        if ($end !== false && $end >= $min) {
            return trim(substr($cut, 0, $end + 1));
        }

        $space = strrpos($cut, ' ');

        return rtrim($space !== false ? substr($cut, 0, $space) : $cut, " ,;:-") . '…';
    }

    public static function length($text)
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private static function slice($text, $length)
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
    }

    private static function is_page($html)
    {
        return is_string($html) && $html !== '' && strlen($html) <= self::MAX_HTML_BYTES && stripos($html, '<html') !== false;
    }

    private static function after_head_tag($html, $tag)
    {
        if (!preg_match('#<head\b[^>]*>#i', $html, $match, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $at = $match[0][1] + strlen($match[0][0]);

        return substr_replace($html, "\n" . $tag, $at, 0);
    }

    private static function mask($html)
    {
        $parts = array();
        $masked = preg_replace_callback(
            self::OPAQUE,
            function ($match) use (&$parts) {
                $parts[] = $match[0];

                return '<!--nf' . (count($parts) - 1) . '-->';
            },
            $html
        );

        return is_string($masked) ? array('html' => $masked, 'parts' => $parts) : null;
    }

    private static function unmask($html, $parts)
    {
        $out = preg_replace_callback(
            '#<!--nf(\d+)-->#',
            function ($match) use ($parts) {
                return isset($parts[(int) $match[1]]) ? $parts[(int) $match[1]] : $match[0];
            },
            $html
        );

        return is_string($out) ? $out : $html;
    }

    private static function headings($html, $from)
    {
        $found = array();

        if (!preg_match_all('#<h([1-6])\b[^>]*>#i', $html, $matches, PREG_OFFSET_CAPTURE, $from)) {
            return $found;
        }

        foreach ($matches[0] as $index => $match) {
            $level = (int) $matches[1][$index][0];
            $open = (int) $match[1];

            if (!preg_match('#</h' . $level . '\s*>#i', $html, $closing, PREG_OFFSET_CAPTURE, $open)) {
                continue;
            }

            $found[] = array(
                'level' => $level,
                'open' => $open,
                'close' => (int) $closing[0][1],
                'close_length' => strlen($closing[0][0]),
                'text' => trim(wp_strip_all_tags(substr($html, $open, (int) $closing[0][1] - $open))),
            );
        }

        return $found;
    }

    /**
     * The heading that says what the page is about: past the site's header when
     * the page marks one, never in its footer, with words in it. The first h2
     * wins over a small label above it.
     */
    private static function choose_main($html, $body, $found)
    {
        $start = $body;
        $main = stripos($html, '<main', $body);
        $header_end = stripos($html, '</header', $body);

        if ($main !== false) {
            $start = $main;
        } elseif ($header_end !== false) {
            $start = $header_end;
        }

        $footer = stripos($html, '<footer', $start);
        $candidates = array();

        foreach ($found as $heading) {
            if ($heading['open'] < $start || ($footer !== false && $heading['open'] > $footer) || $heading['text'] === '') {
                continue;
            }

            $candidates[] = $heading;
        }

        foreach ($candidates as $heading) {
            if ($heading['level'] === 2) {
                return $heading;
            }
        }

        return empty($candidates) ? null : $candidates[0];
    }
}
