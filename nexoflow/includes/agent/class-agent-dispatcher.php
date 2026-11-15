<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Agent_Dispatcher
{
    const IN_PROGRESS_SECONDS = 300;

    private $actions;

    private $capabilities;

    private $validator;

    private $actor;

    private $site;

    private $store;

    public function __construct($actions, $capabilities, $validator, $actor, $site, $store)
    {
        $this->actions = $actions;
        $this->capabilities = $capabilities;
        $this->validator = $validator;
        $this->actor = $actor;
        $this->site = $site;
        $this->store = $store;
    }

    /**
     * The request has already been authenticated with the site connect key.
     *
     * @return array status and body
     */
    public function dispatch($body)
    {
        $envelope = $this->validator->envelope($body);

        if ($envelope instanceof NexoFlow_Agent_Error) {
            return self::failure($envelope, null);
        }

        $record = array(
            'request_id' => $envelope['request_id'],
            'authorization_id' => $envelope['authorization_id'],
            'action' => $envelope['action'],
            'fingerprint' => self::fingerprint($envelope),
            'user_id' => 0,
        );

        if ($envelope['site_id'] !== '' && !$this->site->matches($envelope['site_id'])) {
            return $this->reject(
                new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::SITE_MISMATCH,
                    __('This request was meant for a different site.', 'nexoflow')
                ),
                $envelope,
                $record
            );
        }

        // A retry must be answered before anything that depends on the current state
        // of the site: the first attempt may itself have changed that state.
        $previous = $this->previous($record);

        if ($previous !== null) {
            return $previous;
        }

        $action = $this->actions->get($envelope['action']);

        if ($action === null) {
            return $this->reject(
                new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::UNKNOWN_ACTION,
                    __('That action is not available.', 'nexoflow')
                ),
                $envelope,
                $record
            );
        }

        if (!in_array($envelope['mode'], $action['modes'], true)) {
            return $this->reject(
                new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::MODE_NOT_SUPPORTED,
                    $envelope['mode'] === NexoFlow_Agent_Validator::MODE_PUBLISH
                        ? __('Publishing is not available.', 'nexoflow')
                        : __('This action cannot run in that mode.', 'nexoflow'),
                    array('mode' => $envelope['mode'], 'modes' => $action['modes'])
                ),
                $envelope,
                $record
            );
        }

        $capability = $this->capabilities->get($action['capability']);

        if ($capability === null || !$capability['available']) {
            return $this->reject(
                new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::CAPABILITY_UNAVAILABLE,
                    $capability !== null && $capability['message'] !== ''
                        ? $capability['message']
                        : __('This site cannot do that right now.', 'nexoflow'),
                    array(
                        'capability' => $action['capability'],
                        'reason' => $capability !== null ? $capability['reason'] : NexoFlow_Agent_Capability_Registry::REASON_UNAVAILABLE,
                    )
                ),
                $envelope,
                $record
            );
        }

        $actor_id = $this->actor->id();

        if ($actor_id <= 0) {
            return $this->reject(
                new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::NO_ACTING_USER,
                    __('No WordPress account is set for NexoFlow to act as.', 'nexoflow')
                ),
                $envelope,
                $record
            );
        }

        $record['user_id'] = $actor_id;

        if (!$capability['permissioned']) {
            return $this->reject(
                new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::INSUFFICIENT_PERMISSION,
                    __('The acting WordPress account is not allowed to do this.', 'nexoflow'),
                    array('capability' => $action['capability'], 'requires' => $capability['requires'])
                ),
                $envelope,
                $record
            );
        }

        $params = $this->validator->parameters($action, $envelope['parameters']);

        if ($params instanceof NexoFlow_Agent_Error) {
            return $this->reject($params, $envelope, $record);
        }

        return $this->actor->run_as(
            $actor_id,
            function () use ($action, $params, $envelope, $record) {
                return $this->run($action, $params, $envelope, $record);
            }
        );
    }

    // Runs as the acting account.
    private function run($action, $params, $envelope, $record)
    {
        $provider = $action['provider'];
        $allowed = $provider->authorize($envelope['action'], $params);

        if ($allowed !== true) {
            return $this->reject(
                $allowed instanceof NexoFlow_Agent_Error ? $allowed : $this->unexpected(false),
                $envelope,
                $record
            );
        }

        if ($action['mutates']) {
            if (!$this->store->ready()) {
                return self::failure($this->storage_unavailable(), $envelope);
            }

            if (!$this->store->claim($record)) {
                $previous = $this->previous($record);

                return $previous !== null ? $previous : self::failure($this->storage_unavailable(), $envelope);
            }
        }

        try {
            $result = $provider->execute(
                $envelope['action'],
                $params,
                array(
                    'request_id' => $envelope['request_id'],
                    'authorization_id' => $envelope['authorization_id'],
                    'mode' => $envelope['mode'],
                )
            );
        } catch (Throwable $e) {
            $result = null;
        }

        if (!is_array($result) && !($result instanceof NexoFlow_Agent_Error)) {
            // The provider broke off part-way, so the site may or may not have changed.
            // The request_id stays claimed: a retry is told to check, never run again.
            if ($action['mutates']) {
                $this->store->abandon($envelope['request_id'], NexoFlow_Agent_Error::EXECUTION_FAILED);
            } else {
                $this->store->reject($record, NexoFlow_Agent_Error::EXECUTION_FAILED);
            }

            return self::failure($this->unexpected($action['mutates']), $envelope);
        }

        if ($result instanceof NexoFlow_Agent_Error) {
            if ($action['mutates']) {
                $this->store->fail($envelope['request_id'], $result->code());
            } else {
                $this->store->reject($record, $result->code());
            }

            $this->store->prune();

            return self::failure($result, $envelope);
        }

        $body = array(
            'success' => true,
            'request_id' => $envelope['request_id'],
            'action' => $envelope['action'],
            'mode' => $envelope['mode'],
            'replayed' => false,
            'result' => empty($result) ? new stdClass() : $result,
        );

        if ($envelope['authorization_id'] !== '') {
            $body['authorization_id'] = $envelope['authorization_id'];
        }

        if ($action['mutates']) {
            $this->store->complete(
                $envelope['request_id'],
                $body,
                isset($result['page_id']) ? (int) $result['page_id'] : 0
            );
            $this->store->prune();
        }

        return array(
            'status' => 200,
            'body' => $body,
        );
    }

    public static function failure($error, $envelope)
    {
        $body = array(
            'success' => false,
            'request_id' => is_array($envelope) ? $envelope['request_id'] : null,
            'action' => is_array($envelope) ? $envelope['action'] : null,
            'error' => $error->to_array(),
        );

        if (is_array($envelope) && $envelope['authorization_id'] !== '') {
            $body['authorization_id'] = $envelope['authorization_id'];
        }

        return array(
            'status' => $error->status(),
            'body' => $body,
        );
    }

    public static function fingerprint($envelope)
    {
        return hash(
            'sha256',
            (string) wp_json_encode(
                array(
                    $envelope['action'],
                    $envelope['mode'],
                    self::canonical($envelope['parameters']),
                )
            )
        );
    }

    private static function canonical($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = self::canonical($item);
        }

        return $value;
    }

    /**
     * The answer for a request_id that has already been claimed, or null when it has not.
     */
    private function previous($record)
    {
        if (!$this->store->ready()) {
            return null;
        }

        $row = $this->store->find_claim($record['request_id']);

        if ($row === null) {
            return null;
        }

        $envelope = array(
            'request_id' => $record['request_id'],
            'action' => $record['action'],
            'authorization_id' => $record['authorization_id'],
        );

        if (!hash_equals((string) $row['fingerprint'], $record['fingerprint'])) {
            return self::failure(
                new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::REQUEST_ID_REUSED,
                    __('This request_id was already used for a different request.', 'nexoflow')
                ),
                $envelope
            );
        }

        if ($row['status'] === NexoFlow_Agent_Request_Store::COMPLETED) {
            $body = json_decode((string) $row['response'], true);

            if (is_array($body)) {
                $body['replayed'] = true;

                return array(
                    'status' => 200,
                    'body' => $body,
                );
            }
        }

        $started = strtotime((string) $row['created_at'] . ' UTC');

        if ($row['status'] === NexoFlow_Agent_Request_Store::IN_PROGRESS
            && $started !== false
            && time() - $started < self::IN_PROGRESS_SECONDS
        ) {
            return self::failure(
                new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::REQUEST_IN_PROGRESS,
                    __('This request is still running. Try again shortly.', 'nexoflow')
                ),
                $envelope
            );
        }

        // The first attempt never reported back, so whether it changed the site is unknown.
        return self::failure(
            new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::REQUEST_INCOMPLETE,
                __('An earlier attempt at this request did not finish. Check the site, then send a new request.', 'nexoflow')
            ),
            $envelope
        );
    }

    private function reject($error, $envelope, $record)
    {
        $this->store->reject($record, $error->code());
        $this->store->prune();

        return self::failure($error, $envelope);
    }

    private function storage_unavailable()
    {
        return new NexoFlow_Agent_Error(
            NexoFlow_Agent_Error::STORAGE_UNAVAILABLE,
            __('This site cannot track requests right now, so nothing was changed.', 'nexoflow')
        );
    }

    private function unexpected($may_have_changed)
    {
        if ($may_have_changed) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::EXECUTION_FAILED,
                __('Something went wrong on this site while making the change. Check the site before trying again.', 'nexoflow'),
                array('changed' => 'unknown')
            );
        }

        return new NexoFlow_Agent_Error(
            NexoFlow_Agent_Error::EXECUTION_FAILED,
            __('Something went wrong on this site. Nothing was changed.', 'nexoflow'),
            array('changed' => 'no')
        );
    }
}
