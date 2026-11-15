<?php

/**
 * The key file for change notices to search engines
 * (includes/class-indexnow.php): the key NexoFlow sends with the jobs is kept
 * only when it is one, and only /<key>.txt at the site's root is answered.
 */

require_once dirname(__DIR__) . '/nexoflow/includes/class-indexnow.php';

$nexoflow_indexnow_key = '3f2b9c0d4e5a6b7c8d9e0f1a2b3c4d5e';

delete_option(NexoFlow_IndexNow::OPTION_KEY);
NexoFlow_IndexNow::keep_key(array('ok' => true, 'jobs' => array()));
nexoflow_assert(NexoFlow_IndexNow::stored_key() === '', 'No key is kept before NexoFlow sends one');

NexoFlow_IndexNow::keep_key(array('ok' => true, 'jobs' => array(), 'indexNowKey' => $nexoflow_indexnow_key));
nexoflow_assert(NexoFlow_IndexNow::stored_key() === $nexoflow_indexnow_key, 'The key sent with the jobs is kept');

foreach (array('<script>alert(1)</script>', 'short', str_repeat('a', 129), 'has space in it', 12345678) as $nexoflow_bad_key) {
    NexoFlow_IndexNow::keep_key(array('indexNowKey' => $nexoflow_bad_key));
}
nexoflow_assert(NexoFlow_IndexNow::stored_key() === $nexoflow_indexnow_key, 'A value that is not a key never replaces the key');

nexoflow_assert(NexoFlow_IndexNow::is_key_request('/' . $nexoflow_indexnow_key . '.txt', '', $nexoflow_indexnow_key), 'The key file is answered at the site root');
nexoflow_assert(NexoFlow_IndexNow::is_key_request('/' . $nexoflow_indexnow_key . '.txt?x=1', '/', $nexoflow_indexnow_key), 'A query string does not change the address');
nexoflow_assert(NexoFlow_IndexNow::is_key_request('/blog/' . $nexoflow_indexnow_key . '.txt', '/blog', $nexoflow_indexnow_key), 'A site in a folder answers in its folder');
nexoflow_assert(!NexoFlow_IndexNow::is_key_request('/blog/' . $nexoflow_indexnow_key . '.txt', '', $nexoflow_indexnow_key), 'Not below the root');
nexoflow_assert(!NexoFlow_IndexNow::is_key_request('/other.txt', '', $nexoflow_indexnow_key), 'No other address');
nexoflow_assert(!NexoFlow_IndexNow::is_key_request('/.txt', '', ''), 'Nothing without a key');

NexoFlow_IndexNow::forget();
nexoflow_assert(NexoFlow_IndexNow::stored_key() === '', 'Disconnecting removes the key');
