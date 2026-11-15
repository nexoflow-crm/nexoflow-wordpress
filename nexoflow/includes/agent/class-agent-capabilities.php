<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Agent_Capability_Registry
{
    const REASON_UNAVAILABLE = 'UNAVAILABLE';

    private $providers;

    private $actor;

    private $store;

    private $resolved = null;

    public function __construct($providers, $actor, $store)
    {
        $this->providers = $providers;
        $this->actor = $actor;
        $this->store = $store;
    }

    /**
     * supported: this site can do it. available: it can be used right now.
     * permissioned: the acting account is allowed to. usable: all of the above.
     */
    public function all()
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $actor_id = $this->actor->id();
        $writable = $this->store->ready();
        $resolved = array();

        foreach ($this->providers as $provider) {
            foreach ($provider->capabilities() as $name => $capability) {
                if (!is_string($name) || isset($resolved[$name]) || !is_array($capability)) {
                    continue;
                }

                $supported = !empty($capability['supported']);
                $available = $supported && !empty($capability['available']);

                // Changes are refused when retried requests cannot be told apart.
                if ($available && !empty($capability['writes']) && !$writable) {
                    $available = false;
                    $capability['reason'] = NexoFlow_Agent_Error::STORAGE_UNAVAILABLE;
                    $capability['message'] = __('This site could not create the table NexoFlow uses to track requests.', 'nexoflow');
                }

                $requires = isset($capability['requires']) && is_string($capability['requires']) ? $capability['requires'] : '';
                $permissioned = $actor_id > 0 && ($requires === '' || user_can($actor_id, $requires));
                $reason = '';
                $message = '';

                if (!$available) {
                    $reason = !empty($capability['reason']) ? (string) $capability['reason'] : self::REASON_UNAVAILABLE;
                    $message = isset($capability['message']) ? (string) $capability['message'] : '';
                } elseif ($actor_id <= 0) {
                    $reason = NexoFlow_Agent_Error::NO_ACTING_USER;
                    $message = __('No WordPress account is set for NexoFlow to act as.', 'nexoflow');
                } elseif (!$permissioned) {
                    $reason = NexoFlow_Agent_Error::INSUFFICIENT_PERMISSION;
                    $message = __('The acting WordPress account is not allowed to do this.', 'nexoflow');
                }

                $resolved[$name] = array(
                    'provider' => $provider->id(),
                    'supported' => $supported,
                    'available' => $available,
                    'permissioned' => $permissioned,
                    'usable' => $available && $permissioned,
                    'requires' => $requires,
                    'reason' => $reason,
                    'message' => $message,
                );
            }
        }

        ksort($resolved);
        $this->resolved = $resolved;

        return $resolved;
    }

    public function get($name)
    {
        $all = $this->all();

        return isset($all[$name]) ? $all[$name] : null;
    }

    public function usable()
    {
        $names = array();

        foreach ($this->all() as $name => $capability) {
            if ($capability['usable']) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function reset()
    {
        $this->resolved = null;
    }
}
