<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A provider for one page builder. New builders implement this and are added
 * through the nexoflow_agent_providers filter.
 */
interface NexoFlow_Agent_Builder extends NexoFlow_Agent_Provider
{
    /**
     * @return array At least installed, active, supported, available.
     */
    public function detect();

    /**
     * Whether this builder owns the layout of the given page.
     *
     * @return bool
     */
    public function owns($post);

    /**
     * Whether the layout lives in the post content, so writing the content changes the page.
     * False for builders that keep their own data: their pages are only changed through the builder.
     *
     * @return bool
     */
    public function allows_content_edit();

    /**
     * Builder state that is not stored in the post itself, folded into the page revision.
     *
     * @return string
     */
    public function document_version($post);
}
