<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Agent_Rest
{
    const ROUTE_CAPABILITIES = '/site/capabilities';

    const ROUTE_EXECUTE = '/actions/execute';

    private $agent;

    public function __construct($agent)
    {
        $this->agent = $agent;
    }

    public function register()
    {
        add_action('rest_api_init', array($this, 'routes'));
        add_filter('rest_request_after_callbacks', array($this, 'envelope'), 10, 3);
    }

    public function routes()
    {
        register_rest_route(
            NexoFlow_Rest::NS,
            self::ROUTE_CAPABILITIES,
            array(
                'methods' => 'GET',
                'callback' => array($this, 'capabilities'),
                'permission_callback' => array($this, 'authorize'),
            )
        );

        register_rest_route(
            NexoFlow_Rest::NS,
            self::ROUTE_EXECUTE,
            array(
                'methods' => 'POST',
                'callback' => array($this, 'execute'),
                'permission_callback' => array($this, 'authorize'),
            )
        );
    }

    /**
     * @return NexoFlow_Agent_Error|null Null when the request carries this site's connect key.
     */
    public static function authenticate()
    {
        $auth = NexoFlow_Auth::check();

        if ($auth === NexoFlow_Auth::NOT_CONNECTED) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::NOT_CONNECTED,
                __('Site is not connected.', 'nexoflow')
            );
        }

        if ($auth === NexoFlow_Auth::MISSING) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::MISSING_CREDENTIALS,
                __('Send the site connect key as a Bearer token.', 'nexoflow')
            );
        }

        if ($auth !== NexoFlow_Auth::OK) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::INVALID_CREDENTIALS,
                __('Invalid site connect key.', 'nexoflow')
            );
        }

        if (!NexoFlow_Agent::enabled()) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::AGENT_DISABLED,
                __('The Website Agent is switched off on this site.', 'nexoflow')
            );
        }

        return null;
    }

    public function authorize()
    {
        $error = self::authenticate();

        if ($error === null) {
            return true;
        }

        return new WP_Error(
            'nexoflow_agent',
            $error->message(),
            array(
                'status' => $error->status(),
                'nexoflow_code' => $error->code(),
            )
        );
    }

    public function capabilities()
    {
        return $this->respond(200, $this->agent->discovery());
    }

    public function execute($request)
    {
        $result = $this->agent->dispatcher()->dispatch($request->get_json_params());

        return $this->respond($result['status'], $result['body']);
    }

    /**
     * Gives refusals on these routes the same shape as every other answer.
     */
    public function envelope($response, $handler, $request)
    {
        if (!is_wp_error($response) || !is_object($request) || !self::is_agent_route($request->get_route())) {
            return $response;
        }

        $data = $response->get_error_data();

        if (is_array($data) && isset($data['nexoflow_code'])) {
            $error = new NexoFlow_Agent_Error($data['nexoflow_code'], $response->get_error_message());
        } else {
            $error = new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::INVALID_REQUEST,
                __('Send the request as a JSON object.', 'nexoflow')
            );
        }

        $failure = NexoFlow_Agent_Dispatcher::failure($error, null);

        return $this->respond($failure['status'], $failure['body']);
    }

    public static function is_agent_route($route)
    {
        $base = '/' . NexoFlow_Rest::NS;

        return $route === $base . self::ROUTE_CAPABILITIES || $route === $base . self::ROUTE_EXECUTE;
    }

    private function respond($status, $body)
    {
        $response = new WP_REST_Response($body, $status);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
