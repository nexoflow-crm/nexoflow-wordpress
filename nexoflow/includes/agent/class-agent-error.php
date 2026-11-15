<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Agent_Error
{
    const NOT_CONNECTED = 'NOT_CONNECTED';

    const MISSING_CREDENTIALS = 'MISSING_CREDENTIALS';

    const INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';

    const AGENT_DISABLED = 'AGENT_DISABLED';

    const SITE_MISMATCH = 'SITE_MISMATCH';

    const INVALID_REQUEST = 'INVALID_REQUEST';

    const UNKNOWN_ACTION = 'UNKNOWN_ACTION';

    const MODE_NOT_SUPPORTED = 'MODE_NOT_SUPPORTED';

    const INVALID_PARAMETERS = 'INVALID_PARAMETERS';

    const CAPABILITY_UNAVAILABLE = 'CAPABILITY_UNAVAILABLE';

    const NO_ACTING_USER = 'NO_ACTING_USER';

    const INSUFFICIENT_PERMISSION = 'INSUFFICIENT_PERMISSION';

    const NOT_FOUND = 'NOT_FOUND';

    const DRAFT_REQUIRED = 'DRAFT_REQUIRED';

    const CONTENT_CONFLICT = 'CONTENT_CONFLICT';

    const CONTENT_LOCKED = 'CONTENT_LOCKED';

    const RESTORE_POINT_UNAVAILABLE = 'RESTORE_POINT_UNAVAILABLE';

    const UNSUPPORTED_BUILDER_OPERATION = 'UNSUPPORTED_BUILDER_OPERATION';

    const REQUEST_ID_REUSED = 'REQUEST_ID_REUSED';

    const REQUEST_IN_PROGRESS = 'REQUEST_IN_PROGRESS';

    const REQUEST_INCOMPLETE = 'REQUEST_INCOMPLETE';

    const STORAGE_UNAVAILABLE = 'STORAGE_UNAVAILABLE';

    const PROVIDER_ERROR = 'PROVIDER_ERROR';

    const EXECUTION_FAILED = 'EXECUTION_FAILED';

    const STATUS = array(
        self::NOT_CONNECTED => 401,
        self::MISSING_CREDENTIALS => 401,
        self::INVALID_CREDENTIALS => 401,
        self::AGENT_DISABLED => 403,
        self::SITE_MISMATCH => 409,
        self::INVALID_REQUEST => 400,
        self::UNKNOWN_ACTION => 404,
        self::MODE_NOT_SUPPORTED => 422,
        self::INVALID_PARAMETERS => 422,
        self::CAPABILITY_UNAVAILABLE => 409,
        self::NO_ACTING_USER => 409,
        self::INSUFFICIENT_PERMISSION => 403,
        self::NOT_FOUND => 404,
        self::DRAFT_REQUIRED => 409,
        self::CONTENT_CONFLICT => 409,
        self::CONTENT_LOCKED => 409,
        self::RESTORE_POINT_UNAVAILABLE => 409,
        self::UNSUPPORTED_BUILDER_OPERATION => 409,
        self::REQUEST_ID_REUSED => 409,
        self::REQUEST_IN_PROGRESS => 409,
        self::REQUEST_INCOMPLETE => 409,
        self::STORAGE_UNAVAILABLE => 503,
        self::PROVIDER_ERROR => 502,
        self::EXECUTION_FAILED => 500,
    );

    private $code;

    private $message;

    private $details;

    public function __construct($code, $message, $details = array())
    {
        $this->code = is_string($code) && array_key_exists($code, self::STATUS) ? $code : self::EXECUTION_FAILED;
        $this->message = is_string($message) ? $message : '';
        $this->details = is_array($details) ? $details : array();
    }

    public function code()
    {
        return $this->code;
    }

    public function message()
    {
        return $this->message;
    }

    public function details()
    {
        return $this->details;
    }

    public function status()
    {
        return self::STATUS[$this->code];
    }

    public function to_array()
    {
        $error = array(
            'code' => $this->code,
            'message' => $this->message,
        );

        if (!empty($this->details)) {
            $error['details'] = $this->details;
        }

        return $error;
    }
}
