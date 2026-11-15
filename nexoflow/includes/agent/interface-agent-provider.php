<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One execution backend for the Website Agent.
 *
 * capabilities() returns name => array(supported, available, reason, message, requires),
 * where requires is the WordPress capability the acting account needs.
 *
 * actions() returns name => array(capability, mutates, modes, description, parameters, any_of).
 * Only the actions listed here can ever run.
 */
interface NexoFlow_Agent_Provider
{
    public function id();

    public function capabilities();

    public function actions();

    /**
     * Checks the acting account against the actual object. Runs as the acting account.
     *
     * @return true|NexoFlow_Agent_Error
     */
    public function authorize($action, $params);

    /**
     * An error result means the site was not changed.
     *
     * @return array|NexoFlow_Agent_Error
     */
    public function execute($action, $params, $context);
}
