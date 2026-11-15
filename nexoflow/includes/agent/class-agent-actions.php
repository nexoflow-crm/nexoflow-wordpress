<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Agent_Action_Registry
{
    private $providers;

    private $actions = null;

    public function __construct($providers)
    {
        $this->providers = $providers;
    }

    /**
     * The allowlist. The first provider to declare a name keeps it, so a provider
     * added later cannot take over an existing action.
     */
    public function all()
    {
        if ($this->actions !== null) {
            return $this->actions;
        }

        $actions = array();

        foreach ($this->providers as $provider) {
            foreach ($provider->actions() as $name => $definition) {
                if (!is_string($name) || isset($actions[$name]) || !is_array($definition)) {
                    continue;
                }

                if (!preg_match(NexoFlow_Agent_Validator::ACTION_PATTERN, $name) || empty($definition['capability'])) {
                    continue;
                }

                $actions[$name] = array(
                    'provider' => $provider,
                    'capability' => (string) $definition['capability'],
                    'mutates' => !empty($definition['mutates']),
                    'modes' => isset($definition['modes']) && is_array($definition['modes'])
                        ? array_values($definition['modes'])
                        : array(NexoFlow_Agent_Validator::MODE_DRAFT),
                    'description' => isset($definition['description']) ? (string) $definition['description'] : '',
                    'parameters' => isset($definition['parameters']) && is_array($definition['parameters'])
                        ? $definition['parameters']
                        : array(),
                    'any_of' => isset($definition['any_of']) && is_array($definition['any_of'])
                        ? array_values($definition['any_of'])
                        : array(),
                );
            }
        }

        ksort($actions);
        $this->actions = $actions;

        return $actions;
    }

    public function get($name)
    {
        $actions = $this->all();

        return is_string($name) && isset($actions[$name]) ? $actions[$name] : null;
    }

    public function describe()
    {
        $described = array();

        foreach ($this->all() as $name => $action) {
            $entry = array(
                'name' => $name,
                'provider' => $action['provider']->id(),
                'capability' => $action['capability'],
                'mutates' => $action['mutates'],
                'modes' => $action['modes'],
                'description' => $action['description'],
                'parameters' => $this->describe_parameters($action['parameters']),
            );

            if (!empty($action['any_of'])) {
                $entry['any_of'] = $action['any_of'];
            }

            $described[] = $entry;
        }

        return $described;
    }

    private function describe_parameters($parameters)
    {
        $described = array();

        foreach ($parameters as $name => $rule) {
            // Patterns are a server-side detail; the description says what is expected.
            unset($rule['pattern']);
            $rule['required'] = !empty($rule['required']);

            if (isset($rule['items']) && is_array($rule['items'])) {
                $rule['items'] = $this->describe_parameters($rule['items']);
            }

            $described[$name] = $rule;
        }

        return empty($described) ? new stdClass() : $described;
    }
}
