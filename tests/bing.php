<?php

/**
 * Bing's verification tag (includes/class-bing.php): Bing's code from the job
 * poll is kept only when it is one, and printed as Bing's msvalidate.01 tag.
 */

require_once dirname(__DIR__) . '/nexoflow/includes/class-bing.php';

if (!function_exists('esc_attr')) {
    function esc_attr($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES);
    }
}

$nexoflow_bing_code = '8F2A0C3D9B7E41A6B5C4D3E2F1A0B9C8';

delete_option(NexoFlow_Bing::OPTION_CODE);
NexoFlow_Bing::keep_code(array('ok' => true, 'jobs' => array()));
nexoflow_assert(NexoFlow_Bing::tag() === '', 'No tag before the site is connected to Bing');

NexoFlow_Bing::keep_code(array('ok' => true, 'jobs' => array(), 'bingSiteVerification' => $nexoflow_bing_code));
nexoflow_assert(NexoFlow_Bing::tag() === '<meta name="msvalidate.01" content="' . $nexoflow_bing_code . '" />' . "\n", "Bing's code from the jobs is printed as Bing's tag");

foreach (array('"><script>alert(1)</script>', 'short', str_repeat('a', 65), 'has space in it', 12345678) as $nexoflow_bad_code) {
    NexoFlow_Bing::keep_code(array('bingSiteVerification' => $nexoflow_bad_code));
}
nexoflow_assert(NexoFlow_Bing::stored_code() === $nexoflow_bing_code, 'A value that is not a code never replaces the code');

NexoFlow_Bing::forget();
nexoflow_assert(NexoFlow_Bing::tag() === '', 'Disconnecting removes the tag');
