<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Agent_Validator
{
    const MODE_DRAFT = 'draft';

    const MODE_LIVE = 'live';

    const MODE_PUBLISH = 'publish';

    const MODES = array(self::MODE_DRAFT, self::MODE_LIVE, self::MODE_PUBLISH);

    const FIELDS = array('request_id', 'authorization_id', 'site_id', 'action', 'parameters', 'mode');

    const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.:\-]{7,127}$/D';

    const ACTION_PATTERN = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){1,3}$/D';

    const MAX_ACTION_LENGTH = 64;

    const MAX_LIST_ITEMS = 50;

    const MAX_MAP_KEYS = 400;

    const MAX_MAP_KEY_LENGTH = 200;

    const MAX_MAP_DEPTH = 12;

    const MAX_MAP_BYTES = 400000;

    /**
     * Unknown fields are refused rather than ignored, so a field this version does not
     * understand (a credit amount, a future flag) can never be silently dropped.
     *
     * @return array|NexoFlow_Agent_Error
     */
    public function envelope($body)
    {
        if (!self::is_map($body)) {
            return $this->invalid(__('Send the request as a JSON object.', 'nexoflow'));
        }

        $unknown = array();

        foreach (array_keys($body) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                $unknown[] = substr((string) $field, 0, 64);
            }
        }

        if (!empty($unknown)) {
            return $this->invalid(
                __('The request has fields that are not part of the action contract.', 'nexoflow'),
                array('unknown_fields' => array_slice($unknown, 0, 10))
            );
        }

        $request_id = isset($body['request_id']) ? $body['request_id'] : null;

        if (!self::is_identifier($request_id)) {
            return $this->invalid(
                __('request_id is required: 8 to 128 letters, numbers, dots, colons, dashes or underscores.', 'nexoflow'),
                array('field' => 'request_id')
            );
        }

        $action = isset($body['action']) ? $body['action'] : null;

        if (!is_string($action) || strlen($action) > self::MAX_ACTION_LENGTH || !preg_match(self::ACTION_PATTERN, $action)) {
            return $this->invalid(__('action is required, for example pages.create.', 'nexoflow'), array('field' => 'action'));
        }

        $authorization_id = '';

        if (isset($body['authorization_id'])) {
            if (!self::is_identifier($body['authorization_id'])) {
                return $this->invalid(__('authorization_id is not valid.', 'nexoflow'), array('field' => 'authorization_id'));
            }

            $authorization_id = $body['authorization_id'];
        }

        $site_id = '';

        if (isset($body['site_id'])) {
            if (!is_string($body['site_id']) || $body['site_id'] === '' || strlen($body['site_id']) > 64) {
                return $this->invalid(__('site_id is not valid.', 'nexoflow'), array('field' => 'site_id'));
            }

            $site_id = $body['site_id'];
        }

        $parameters = array();

        if (isset($body['parameters'])) {
            if (!self::is_map($body['parameters'])) {
                return $this->invalid(__('parameters must be a JSON object.', 'nexoflow'), array('field' => 'parameters'));
            }

            $parameters = $body['parameters'];
        }

        $mode = self::MODE_DRAFT;

        if (isset($body['mode'])) {
            if (!is_string($body['mode']) || !in_array($body['mode'], self::MODES, true)) {
                return $this->invalid(__('mode is not valid.', 'nexoflow'), array('field' => 'mode'));
            }

            $mode = $body['mode'];
        }

        return array(
            'request_id' => $request_id,
            'authorization_id' => $authorization_id,
            'site_id' => $site_id,
            'action' => $action,
            'parameters' => $parameters,
            'mode' => $mode,
        );
    }

    /**
     * @return array|NexoFlow_Agent_Error The parameters with defaults applied.
     */
    public function parameters($definition, $input)
    {
        $schema = isset($definition['parameters']) && is_array($definition['parameters']) ? $definition['parameters'] : array();
        $input = is_array($input) ? $input : array();
        $fields = array();
        $clean = array();

        foreach (array_keys($input) as $name) {
            if (!is_string($name) || !isset($schema[$name])) {
                $fields[substr((string) $name, 0, 64)] = 'unknown';
            }
        }

        foreach ($schema as $name => $rule) {
            if (!array_key_exists($name, $input) || $input[$name] === null) {
                if (!empty($rule['required'])) {
                    $fields[$name] = 'required';
                } elseif (array_key_exists('default', $rule)) {
                    $clean[$name] = $rule['default'];
                }

                continue;
            }

            if (isset($rule['type']) && $rule['type'] === 'list') {
                $items = $this->items($name, $rule, $input[$name], $fields);

                if ($items !== null) {
                    $clean[$name] = $items;
                }

                continue;
            }

            $problem = $this->problem($rule, $input[$name]);

            if ($problem !== '') {
                $fields[$name] = $problem;
            } else {
                $clean[$name] = $input[$name];
            }
        }

        $details = array();

        if (!empty($fields)) {
            $details['fields'] = $fields;
        }

        if (empty($fields) && !empty($definition['any_of']) && is_array($definition['any_of'])) {
            $present = array_intersect($definition['any_of'], array_keys($clean));

            if (empty($present)) {
                $details['any_of'] = array_values($definition['any_of']);
            }
        }

        if (!empty($details)) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::INVALID_PARAMETERS,
                __('One or more parameters are missing or not valid.', 'nexoflow'),
                $details
            );
        }

        return $clean;
    }

    public static function is_identifier($value)
    {
        return is_string($value) && preg_match(self::ID_PATTERN, $value) === 1;
    }

    public static function is_map($value)
    {
        if (!is_array($value)) {
            return false;
        }

        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A list of objects that all follow the same rules, for example several
     * changes to one page. Problems are reported as name.index.field.
     *
     * @return array|null The items with defaults applied, or null when one is not valid.
     */
    private function items($name, $rule, $value, &$fields)
    {
        if (!is_array($value) || self::is_keyed($value)) {
            $fields[$name] = 'type';

            return null;
        }

        $count = count($value);

        if ($count < (isset($rule['min_items']) ? (int) $rule['min_items'] : 0)) {
            $fields[$name] = 'too_few';

            return null;
        }

        if ($count > (isset($rule['max_items']) ? (int) $rule['max_items'] : self::MAX_LIST_ITEMS)) {
            $fields[$name] = 'too_many';

            return null;
        }

        $schema = isset($rule['items']) && is_array($rule['items']) ? $rule['items'] : array();
        $clean = array();
        $valid = true;

        foreach ($value as $index => $item) {
            if (!self::is_map($item)) {
                $fields[$name . '.' . $index] = 'type';
                $valid = false;

                continue;
            }

            $entry = array();

            foreach (array_keys($item) as $field) {
                if (!isset($schema[$field])) {
                    $fields[$name . '.' . $index . '.' . substr((string) $field, 0, 64)] = 'unknown';
                    $valid = false;
                }
            }

            foreach ($schema as $field => $field_rule) {
                if (!array_key_exists($field, $item) || $item[$field] === null) {
                    if (!empty($field_rule['required'])) {
                        $fields[$name . '.' . $index . '.' . $field] = 'required';
                        $valid = false;
                    } elseif (array_key_exists('default', $field_rule)) {
                        $entry[$field] = $field_rule['default'];
                    }

                    continue;
                }

                $problem = $this->problem($field_rule, $item[$field]);

                if ($problem !== '') {
                    $fields[$name . '.' . $index . '.' . $field] = $problem;
                    $valid = false;
                } else {
                    $entry[$field] = $item[$field];
                }
            }

            $clean[] = $entry;
        }

        return $valid ? $clean : null;
    }

    // A JSON array arrives with the keys 0, 1, 2 and so on; anything else is an object.
    private static function is_keyed($value)
    {
        return array_keys($value) !== range(0, count($value) - 1) && !empty($value);
    }

    private function problem($rule, $value)
    {
        $type = isset($rule['type']) ? $rule['type'] : '';

        if ($type === 'string') {
            if (!is_string($value)) {
                return 'type';
            }

            $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

            if (isset($rule['min_length']) && $length < (int) $rule['min_length']) {
                return 'too_short';
            }

            if (isset($rule['max_length']) && $length > (int) $rule['max_length']) {
                return 'too_long';
            }

            if (isset($rule['enum']) && !in_array($value, $rule['enum'], true)) {
                return 'not_allowed';
            }

            if (isset($rule['pattern']) && !preg_match($rule['pattern'], $value)) {
                return 'format';
            }

            return '';
        }

        if ($type === 'integer') {
            if (!is_int($value)) {
                return 'type';
            }

            if (isset($rule['min']) && $value < (int) $rule['min']) {
                return 'too_small';
            }

            if (isset($rule['max']) && $value > (int) $rule['max']) {
                return 'too_large';
            }

            return '';
        }

        if ($type === 'boolean') {
            return is_bool($value) ? '' : 'type';
        }

        // A JSON object whose shape belongs to someone else, such as a page builder's settings.
        // It is bounded here and checked for meaning by whoever receives it.
        if ($type === 'map') {
            if (!is_array($value) || (!empty($value) && !self::is_keyed($value))) {
                return 'type';
            }

            if (count($value) > (isset($rule['max_keys']) ? (int) $rule['max_keys'] : self::MAX_MAP_KEYS)) {
                return 'too_many';
            }

            foreach ($value as $key => $entry) {
                if (!is_string($key) || $key === '' || strlen($key) > self::MAX_MAP_KEY_LENGTH) {
                    return 'format';
                }

                if (isset($rule['values']) && $rule['values'] === 'string' && !is_string($entry)) {
                    return 'type';
                }
            }

            if (self::depth($value) > self::MAX_MAP_DEPTH) {
                return 'too_deep';
            }

            $json = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);

            if (!is_string($json) || strlen($json) > (isset($rule['max_bytes']) ? (int) $rule['max_bytes'] : self::MAX_MAP_BYTES)) {
                return 'too_long';
            }

            return '';
        }

        return 'type';
    }

    private static function depth($value)
    {
        if (!is_array($value)) {
            return 0;
        }

        $deepest = 0;

        foreach ($value as $entry) {
            $deepest = max($deepest, self::depth($entry));

            if ($deepest > self::MAX_MAP_DEPTH) {
                break;
            }
        }

        return $deepest + 1;
    }

    private function invalid($message, $details = array())
    {
        return new NexoFlow_Agent_Error(NexoFlow_Agent_Error::INVALID_REQUEST, $message, $details);
    }
}
