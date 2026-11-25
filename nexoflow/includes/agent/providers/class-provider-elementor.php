<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Elementor is only ever driven through the abilities it registers with the
 * WordPress Abilities API, the same ones its MCP server exposes. Elementor
 * registers them from version 4.3.0, on WordPress 6.9+, and only while
 * Elementor MCP is switched on for the site. Its stored page data is never
 * read or written here.
 */
class NexoFlow_Agent_Provider_Elementor implements NexoFlow_Agent_Builder
{
    const ID = 'elementor';

    const MIN_VERSION = '4.3.0';

    const PLUGIN_FILE = 'elementor/elementor.php';

    const PRO_PLUGIN_FILE = 'elementor-pro/elementor-pro.php';

    const ABILITY_PREFIX = 'elementor/';

    const ABILITY_STRUCTURE = 'elementor/get-page-structure';

    const ABILITY_WIDGETS = 'elementor/list-widget-schemas';

    // Elementor 4.3 says how one element type is set; 4.4 replaced it with one call for several.
    const ABILITY_WIDGET = 'elementor/get-widget-schema';

    const ABILITY_WIDGET_SCHEMAS = 'elementor/get-widget-schemas';

    const ABILITY_CREATE = 'elementor/create-page';

    const ABILITY_BUILD = 'elementor/build-composition';

    const ABILITY_SETTINGS = 'elementor/update-page-settings';

    const ABILITY_PUBLISH = 'elementor/publish-document';

    const ABILITY_ASSETS = 'elementor/list-assets';

    const MAX_GUIDE_IMAGES = 30;

    const ATOMIC_EXPERIMENT = 'e_atomic_elements';

    const MAX_XML_LENGTH = 200000;

    const MAX_GUIDE_TYPES = 12;

    /** How a new page is laid out by Elementor: inside the theme, edge to edge, or with nothing of the theme. */
    const LAYOUTS = array(
        'theme' => 'default',
        'full_width' => 'elementor_header_footer',
        'canvas' => 'elementor_canvas',
    );

    private $abilities;

    private $documents;

    private $probed = null;

    public function __construct($abilities, $documents)
    {
        $this->abilities = $abilities;
        $this->documents = $documents;
    }

    public function id()
    {
        return self::ID;
    }

    public function detect()
    {
        $facts = $this->facts();
        $installed = !empty($facts['installed']);
        $active = $installed && !empty($facts['active']);
        $version = isset($facts['version']) ? (string) $facts['version'] : '';
        $supported = $active && $version !== '' && version_compare($version, self::MIN_VERSION, '>=');
        $abilities_api = !empty($facts['abilities_api']);
        $adapter = !empty($facts['mcp_adapter']);
        $abilities = $supported && $abilities_api && isset($facts['abilities']) && is_array($facts['abilities'])
            ? array_values($facts['abilities'])
            : array();
        $mcp = !empty($abilities);
        $reason = '';
        $message = '';

        if (!$installed) {
            $reason = 'ELEMENTOR_NOT_INSTALLED';
            $message = __('Elementor is not installed on this site.', 'nexoflow');
        } elseif (!$active) {
            $reason = 'ELEMENTOR_INACTIVE';
            $message = __('Elementor is installed but not active.', 'nexoflow');
        } elseif (!$supported) {
            $reason = 'ELEMENTOR_VERSION_UNSUPPORTED';
            /* translators: %s: minimum Elementor version */
            $message = sprintf(__('Elementor %s or newer is needed.', 'nexoflow'), self::MIN_VERSION);
        } elseif (!$abilities_api) {
            $reason = 'ABILITIES_API_UNAVAILABLE';
            $message = __('WordPress 6.9 or newer is needed to work with Elementor.', 'nexoflow');
        } elseif (!$mcp && !$adapter) {
            $reason = 'ELEMENTOR_MCP_UNAVAILABLE';
            $message = __('Elementor MCP is not loaded on this site.', 'nexoflow');
        } elseif (!$mcp) {
            $reason = 'ELEMENTOR_MCP_DISABLED';
            $message = __('Elementor MCP is switched off. Turn it on under Elementor > Elementor MCP.', 'nexoflow');
        }

        return array(
            'installed' => $installed,
            'active' => $active,
            'version' => $version !== '' ? $version : null,
            'minimum_version' => self::MIN_VERSION,
            'supported' => $supported,
            'available' => $supported && $mcp,
            'pro' => array(
                'installed' => !empty($facts['pro_installed']),
                'active' => !empty($facts['pro_active']),
                'version' => isset($facts['pro_version']) && $facts['pro_version'] !== '' ? (string) $facts['pro_version'] : null,
            ),
            'atomic_editor' => isset($facts['atomic_editor']) ? (bool) $facts['atomic_editor'] : null,
            'abilities_api' => $abilities_api,
            'mcp' => array(
                'available' => $mcp,
                'adapter' => $adapter,
                'abilities' => $abilities,
            ),
            'reason' => $reason,
            'message' => $message,
        );
    }

    public function owns($post)
    {
        $facts = $this->facts();

        if (empty($facts['active'])) {
            return false;
        }

        return $this->built_with_elementor($post);
    }

    public function allows_content_edit()
    {
        return false;
    }

    public function document_version($post)
    {
        return '';
    }

    public function capabilities()
    {
        $detected = $this->detect();
        $offered = $detected['mcp']['abilities'];
        $widget_schema = in_array(self::ABILITY_WIDGET_SCHEMAS, $offered, true) ? self::ABILITY_WIDGET_SCHEMAS : self::ABILITY_WIDGET;
        $capability = function ($needs, $requires, $writes) use ($detected, $offered) {
            $missing = array_diff($needs, $offered);
            $reason = $detected['reason'];
            $message = $detected['message'];

            if ($reason === '' && !empty($missing)) {
                $reason = 'ELEMENTOR_ABILITY_MISSING';
                $message = __('This Elementor version does not offer this.', 'nexoflow');
            }

            // Building needs Elementor's own newer editor; Elementor refuses without it.
            if ($reason === '' && $writes && $detected['atomic_editor'] === false) {
                $reason = 'ELEMENTOR_ATOMIC_EDITOR_OFF';
                $message = __('The Atomic Editor is switched off in Elementor. Turn it on under Elementor > Settings.', 'nexoflow');
            }

            return array(
                'supported' => $detected['supported'],
                'available' => $detected['available'] && $reason === '',
                'reason' => $reason,
                'message' => $message,
                'requires' => $requires,
            ) + ($writes ? array('writes' => true) : array());
        };

        return array(
            'elementor.inspect' => $capability(array(self::ABILITY_STRUCTURE), 'edit_pages', false),
            'elementor.build' => $capability(
                array(self::ABILITY_WIDGETS, $widget_schema, self::ABILITY_CREATE, self::ABILITY_BUILD),
                'edit_pages',
                true
            ),
            'elementor.publish' => $capability(array(self::ABILITY_PUBLISH), 'publish_pages', true),
        );
    }

    public function actions()
    {
        $page_id = array(
            'type' => 'integer',
            'required' => true,
            'min' => 1,
            'description' => 'WordPress page ID.',
        );
        $expected_revision = array(
            'type' => 'string',
            'required' => true,
            'pattern' => NexoFlow_Agent_Documents::REVISION_PATTERN,
            'description' => 'The revision the page had when it was read.',
        );

        return array(
            'elementor.inspect' => array(
                'capability' => 'elementor.inspect',
                'description' => 'Read the element tree of a page built with Elementor.',
                'parameters' => array(
                    'page_id' => $page_id,
                    'element_id' => array(
                        'type' => 'string',
                        'min_length' => 1,
                        'max_length' => 64,
                        'pattern' => '/^[A-Za-z0-9_\-]+$/D',
                        'description' => 'Only return the part of the tree under this element.',
                    ),
                    'include_content' => array(
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'Include settings and styles. Needs element_id.',
                    ),
                ),
            ),
            'elementor.guide' => array(
                'capability' => 'elementor.build',
                'description' => 'What can be built with Elementor on this site: the element types, and the settings of the types asked for.',
                'parameters' => array(
                    'types' => array(
                        'type' => 'list',
                        'max_items' => self::MAX_GUIDE_TYPES,
                        'description' => 'Element types to return the settings of, for example e-heading.',
                        'items' => array(
                            'type' => array(
                                'type' => 'string',
                                'required' => true,
                                'pattern' => '/^[a-z][a-z0-9\-]{1,60}$/D',
                                'description' => 'An element type from the list this action returns.',
                            ),
                        ),
                    ),
                ),
            ),
            'elementor.create_page' => array(
                'capability' => 'elementor.build',
                'mutates' => true,
                'modes' => array(NexoFlow_Agent_Validator::MODE_DRAFT),
                'description' => 'Create a new page that is built with Elementor. It is always a draft with nothing on it yet.',
                'parameters' => array(
                    'title' => array(
                        'type' => 'string',
                        'required' => true,
                        'min_length' => 1,
                        'max_length' => 200,
                        'description' => 'Page title, plain text.',
                    ),
                    'layout' => array(
                        'type' => 'string',
                        'enum' => array_keys(self::LAYOUTS),
                        'default' => 'full_width',
                        'description' => 'How the page sits in the theme: theme (inside the theme\'s own page layout), full_width (the theme\'s header and footer with the page edge to edge between them), or canvas (nothing of the theme).',
                    ),
                ),
            ),
            'elementor.build' => array(
                'capability' => 'elementor.build',
                'mutates' => true,
                'modes' => array(NexoFlow_Agent_Validator::MODE_DRAFT),
                'description' => 'Build sections on a page made with Elementor. On a draft the page is saved as built. On a published page Elementor holds the change back: visitors keep seeing the page as it was until elementor.publish.',
                'parameters' => array(
                    'page_id' => $page_id,
                    'expected_revision' => $expected_revision,
                    'structure' => array(
                        'type' => 'string',
                        'required' => true,
                        'min_length' => 1,
                        'max_length' => self::MAX_XML_LENGTH,
                        'description' => 'The elements as Elementor\'s own XML: one tag per element, each with a unique configuration-id attribute and nothing else.',
                    ),
                    'settings' => array(
                        'type' => 'map',
                        'description' => 'configuration-id to that element\'s settings, as its type describes them.',
                    ),
                    'styles' => array(
                        'type' => 'map',
                        'values' => 'string',
                        'description' => 'configuration-id to a plain CSS string for that element.',
                    ),
                    'replace' => array(
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'Take away what is there first: the whole page, or with parent_id the elements inside that one. Without it the new elements are added after what is there.',
                    ),
                    'parent_id' => array(
                        'type' => 'string',
                        'min_length' => 1,
                        'max_length' => 64,
                        'pattern' => '/^[A-Za-z0-9_\-]+$/D',
                        'description' => 'Build inside this element instead of at the top level of the page.',
                    ),
                ),
            ),
            'elementor.publish' => array(
                'capability' => 'elementor.publish',
                'mutates' => true,
                'modes' => array(NexoFlow_Agent_Validator::MODE_LIVE),
                'description' => 'Make a page built with Elementor live: publish a draft, or put the change Elementor was holding back on a published page in front of visitors. The version from before is kept.',
                'parameters' => array(
                    'page_id' => $page_id,
                    'expected_revision' => $expected_revision,
                ),
            ),
        );
    }

    public function authorize($action, $params)
    {
        switch ($action) {
            case 'elementor.guide':
            case 'elementor.create_page':
                return true;

            case 'elementor.inspect':
            case 'elementor.build':
            case 'elementor.publish':
                return $this->documents->authorize_edit($params['page_id']);
        }

        return $this->refused();
    }

    public function execute($action, $params, $context)
    {
        switch ($action) {
            case 'elementor.inspect':
                return $this->inspect($params);

            case 'elementor.guide':
                return $this->guide($params);

            case 'elementor.create_page':
                return $this->create_page($params);

            case 'elementor.build':
                return $this->build($params);

            case 'elementor.publish':
                return $this->publish($params, $context);
        }

        return $this->refused();
    }

    /** What has to survive putting a page back to an earlier version: how Elementor lays it out. */
    public function before_restore($post)
    {
        return array('layout' => $this->layout_of($post->ID));
    }

    /**
     * Elementor keeps a rendered copy of each page. After a page is put back to an
     * earlier version that copy is out of date, so Elementor is asked to drop it.
     */
    public function after_restore($post, $before = array())
    {
        if (is_array($before) && isset($before['layout'])) {
            $this->keep_layout($post->ID, $before['layout']);
        }

        $this->forget_rendered_pages();
    }

    /**
     * Whether Elementor draws this whole page itself instead of filling the theme's
     * page template. Such a page shows as the homepage even on a theme that has a
     * homepage template of its own.
     */
    public function draws_whole_page($post)
    {
        return $this->owns($post) && in_array($this->layout_of($post->ID), array(self::LAYOUTS['full_width'], self::LAYOUTS['canvas']), true);
    }

    // The page template WordPress has on record for the page: Elementor's layouts are page templates.
    private function layout_of($post_id)
    {
        return (string) get_page_template_slug((int) $post_id);
    }

    /**
     * Elementor 4.3 puts a published page back on the theme's own layout while it
     * holds a change to it back, and a restore can carry that over. On a theme
     * with its own homepage template visitors would then see the theme's page
     * instead of this one, so the layout the page had is put back.
     */
    private function keep_layout($post_id, $before)
    {
        if (!in_array($before, array(self::LAYOUTS['full_width'], self::LAYOUTS['canvas']), true)) {
            return;
        }

        if ($this->layout_of($post_id) !== $before) {
            update_post_meta((int) $post_id, '_wp_page_template', $before);
        }
    }

    private function inspect($params)
    {
        $post = $this->elementor_page($params['page_id']);

        if ($post instanceof NexoFlow_Agent_Error) {
            return $post;
        }

        if (!empty($params['include_content']) && !isset($params['element_id'])) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::INVALID_PARAMETERS,
                __('One or more parameters are missing or not valid.', 'nexoflow'),
                array('fields' => array('element_id' => 'required'))
            );
        }

        $input = array('post_id' => (int) $post->ID);

        if (isset($params['element_id'])) {
            $input['element_id'] = $params['element_id'];
        }

        if (!empty($params['include_content'])) {
            $input['include_content'] = true;
        }

        $result = $this->abilities->execute(self::ABILITY_STRUCTURE, $input);

        if (is_wp_error($result)) {
            return $this->ability_error($result);
        }

        return array(
            'page_id' => (int) $post->ID,
            'builder' => self::ID,
            'revision' => $this->documents->revision($post),
            'status' => (string) $post->post_status,
            'title' => (string) $post->post_title,
            'url' => (string) get_permalink($post->ID),
            'elements' => is_array($result) && isset($result['elements']) && is_array($result['elements'])
                ? $result['elements']
                : array(),
        );
    }

    private function guide($params)
    {
        $listed = $this->abilities->execute(self::ABILITY_WIDGETS, array('summary' => true));

        if (is_wp_error($listed)) {
            return $this->ability_error($listed);
        }

        $types = array();

        foreach (is_array($listed) && isset($listed['widgets']) && is_array($listed['widgets']) ? $listed['widgets'] : array() as $widget) {
            if (is_array($widget) && isset($widget['type']) && is_string($widget['type'])) {
                $types[$widget['type']] = isset($widget['description']) && is_string($widget['description']) ? $widget['description'] : '';
            }
        }

        $asked_types = array();

        foreach (isset($params['types']) ? $params['types'] : array() as $asked) {
            $type = $asked['type'];

            if (!isset($types[$type])) {
                return new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::INVALID_PARAMETERS,
                    __('Elementor does not have that element type on this site.', 'nexoflow'),
                    array('fields' => array('types' => 'not_allowed'), 'type' => $type)
                );
            }

            $asked_types[$type] = $type;
        }

        $schemas = $this->widget_schemas(array_values($asked_types));

        if (is_wp_error($schemas)) {
            return $this->ability_error($schemas);
        }

        $elements = array();

        foreach ($types as $type => $description) {
            $elements[] = array('type' => $type, 'description' => $description);
        }

        return array(
            'builder' => self::ID,
            'elements' => $elements,
            'settings' => $schemas,
            'images' => $this->library_images(),
        );
    }

    /**
     * How each element type is set, keyed by type. Elementor 4.4 answers for every type in one call and
     * keys its answer by type; 4.3 answers for one type a call.
     *
     * @return array|WP_Error
     */
    private function widget_schemas($types)
    {
        $schemas = array();

        if (empty($types)) {
            return $schemas;
        }

        if (!$this->abilities->has(self::ABILITY_WIDGET_SCHEMAS)) {
            foreach ($types as $type) {
                $schema = $this->abilities->execute(self::ABILITY_WIDGET, array('widget_type' => $type));

                if (is_wp_error($schema)) {
                    return $schema;
                }

                $schemas[$type] = $schema;
            }

            return $schemas;
        }

        $answer = $this->abilities->execute(self::ABILITY_WIDGET_SCHEMAS, array('widget_type' => $types));

        if (is_wp_error($answer)) {
            return $answer;
        }

        foreach ($types as $type) {
            if (!is_array($answer) || !isset($answer['schemas'][$type]) || !is_array($answer['schemas'][$type])) {
                return new WP_Error('nexoflow_widget_schema_missing', __('Elementor did not say how this element type is set.', 'nexoflow'));
            }

            $schemas[$type] = $answer['schemas'][$type];
        }

        return $schemas;
    }

    // The images already in the media library, which are the only ones a page may use.
    private function library_images()
    {
        if (!$this->abilities->has(self::ABILITY_ASSETS)) {
            return array();
        }

        $listed = $this->abilities->execute(self::ABILITY_ASSETS, array('type' => 'image', 'per_page' => self::MAX_GUIDE_IMAGES));
        $images = array();

        foreach (!is_wp_error($listed) && is_array($listed) && isset($listed['assets']) && is_array($listed['assets']) ? $listed['assets'] : array() as $asset) {
            if (!is_array($asset) || empty($asset['id'])) {
                continue;
            }

            $images[] = array(
                'id' => (int) $asset['id'],
                'title' => isset($asset['title']) ? sanitize_text_field((string) $asset['title']) : '',
                'alt' => isset($asset['alt']) ? sanitize_text_field((string) $asset['alt']) : '',
                'width' => isset($asset['width']) ? (int) $asset['width'] : 0,
                'height' => isset($asset['height']) ? (int) $asset['height'] : 0,
            );
        }

        return $images;
    }

    private function create_page($params)
    {
        // The same cleaning a title gets on every other page path.
        $title = sanitize_text_field($params['title']);

        if ($title === '') {
            return self::invalid('title', 'empty');
        }

        $created = $this->abilities->execute(
            self::ABILITY_CREATE,
            array('title' => $title, 'post_type' => NexoFlow_Agent_Documents::POST_TYPE)
        );

        if (is_wp_error($created)) {
            return $this->ability_error($created);
        }

        $post = is_array($created) && isset($created['id']) ? get_post((int) $created['id']) : null;

        if (!$post || $post->post_type !== NexoFlow_Agent_Documents::POST_TYPE) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::EXECUTION_FAILED,
                __('Elementor did not create the page.', 'nexoflow')
            );
        }

        // A new page is always a draft, whatever Elementor did with it.
        if (!$this->documents->is_draft($post)) {
            wp_update_post(array('ID' => (int) $post->ID, 'post_status' => 'draft'));
            $post = get_post((int) $post->ID);
        }

        $layout = isset($params['layout']) ? $params['layout'] : 'full_width';

        if ($layout !== 'theme' && $this->abilities->has(self::ABILITY_SETTINGS)) {
            // The page is there either way; a layout Elementor would not take is not a reason to lose it.
            $this->abilities->execute(
                self::ABILITY_SETTINGS,
                array('post_id' => (int) $post->ID, 'settings' => array('template' => self::LAYOUTS[$layout]))
            );
            $post = get_post((int) $post->ID);
        }

        return $this->documents->summary($post) + array(
            'layout' => $layout,
            'builder_edit_url' => is_array($created) && isset($created['edit_url']) ? esc_url_raw((string) $created['edit_url']) : '',
        );
    }

    private function build($params)
    {
        $post = $this->elementor_page($params['page_id']);

        if ($post instanceof NexoFlow_Agent_Error) {
            return $post;
        }

        // Elementor holds a change to a published page back, so this is never what visitors see.
        $allowed = $this->documents->check_staged($post, $params['expected_revision']);

        if ($allowed !== true) {
            return $allowed;
        }

        $unsafe = self::unsafe_build_input($params);

        if ($unsafe !== '') {
            return self::invalid($unsafe, 'unsafe');
        }

        if (!empty($params['settings'])) {
            $params['settings'] = self::clean_settings($params['settings']);
        }

        $input = array(
            'post_id' => (int) $post->ID,
            'xml_structure' => $params['structure'],
            'mode' => !empty($params['replace']) ? 'replace_children' : 'append',
        );

        if (!empty($params['settings'])) {
            $input['element_config'] = $params['settings'];
        }

        if (!empty($params['styles'])) {
            $input['style'] = $params['styles'];
        }

        if (isset($params['parent_id'])) {
            $input['parent_id'] = $params['parent_id'];
        }

        $layout = $this->layout_of($post->ID);
        $built = $this->abilities->execute(self::ABILITY_BUILD, $input);
        $this->keep_layout($post->ID, $layout);

        if (is_wp_error($built)) {
            return $this->ability_error($built, 1200);
        }

        $updated = get_post((int) $post->ID);

        if (!$updated) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::EXECUTION_FAILED,
                __('Elementor could not complete this.', 'nexoflow')
            );
        }

        $built = is_array($built) ? $built : array();
        $warnings = array();

        foreach (isset($built['warnings']) && is_array($built['warnings']) ? array_slice($built['warnings'], 0, 20) : array() as $warning) {
            $warnings[] = substr(sanitize_text_field(is_string($warning) ? $warning : (string) wp_json_encode($warning)), 0, 300);
        }

        return $this->documents->summary($updated) + array(
            // Visitors only see it once the page is published.
            'staged' => !$this->documents->is_draft($updated),
            'built' => array(
                'elements' => isset($built['root_element_ids']) && is_array($built['root_element_ids']) ? count($built['root_element_ids']) : 0,
                'removed' => isset($built['removed_element_ids']) && is_array($built['removed_element_ids']) ? count($built['removed_element_ids']) : 0,
            ),
            'root_element_ids' => isset($built['root_element_ids']) && is_array($built['root_element_ids'])
                ? array_values(array_filter($built['root_element_ids'], 'is_string'))
                : array(),
            'warnings' => $warnings,
            'builder_edit_url' => isset($built['edit_url']) ? esc_url_raw((string) $built['edit_url']) : '',
        );
    }

    private function publish($params, $context)
    {
        $post = $this->elementor_page($params['page_id']);

        if ($post instanceof NexoFlow_Agent_Error) {
            return $post;
        }

        $allowed = $this->documents->check_write($post, $params['expected_revision'], $context['mode']);

        if ($allowed !== true) {
            return $allowed;
        }

        $was_draft = $this->documents->is_draft($post);
        $restore = $was_draft ? 0 : $this->documents->restore_point($post);

        if (!$was_draft && $restore <= 0) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::RESTORE_POINT_UNAVAILABLE,
                __('WordPress could not keep the current version of this page, so it was not changed.', 'nexoflow'),
                array('page_id' => (int) $post->ID)
            );
        }

        $layout = $this->layout_of($post->ID);
        $published = $this->abilities->execute(self::ABILITY_PUBLISH, array('post_id' => (int) $post->ID));
        $this->keep_layout($post->ID, $layout);

        if (is_wp_error($published)) {
            return $this->ability_error($published);
        }

        $this->forget_rendered_pages();
        $updated = get_post((int) $post->ID);

        if (!$updated || $updated->post_status !== 'publish') {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::EXECUTION_FAILED,
                __('Elementor did not publish the page.', 'nexoflow')
            );
        }

        $page = $this->documents->summary($updated);
        $page['previous_status'] = (string) $post->post_status;

        if ($restore > 0) {
            $page['restore_revision_id'] = $restore;
        }

        return $page;
    }

    /**
     * @return WP_Post|NexoFlow_Agent_Error The page, when Elementor built it.
     */
    private function elementor_page($page_id)
    {
        $post = $this->documents->page($page_id);

        if ($post instanceof NexoFlow_Agent_Error) {
            return $post;
        }

        if (!$this->owns($post)) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::UNSUPPORTED_BUILDER_OPERATION,
                __('This page is not built with Elementor.', 'nexoflow'),
                array('page_id' => (int) $post->ID, 'builder' => $this->documents->builder($post))
            );
        }

        return $post;
    }

    /**
     * Elementor's own "regenerate files and data". Without it Elementor keeps serving the
     * rendered copy it made of the page before the change.
     */
    protected function forget_rendered_pages()
    {
        try {
            $plugin = \Elementor\Plugin::$instance;

            if (is_object($plugin)
                && isset($plugin->files_manager)
                && is_object($plugin->files_manager)
                && method_exists($plugin->files_manager, 'clear_cache')
            ) {
                $plugin->files_manager->clear_cache();
            }
        } catch (Throwable $e) {
            return;
        }
    }

    protected function probe()
    {
        $active = defined('ELEMENTOR_VERSION') && class_exists('Elementor\\Plugin');
        $pro_active = defined('ELEMENTOR_PRO_VERSION');
        $file = $this->plugin_version(self::PLUGIN_FILE);
        $pro_file = $this->plugin_version(self::PRO_PLUGIN_FILE);

        return array(
            'installed' => $active || $file !== null,
            'active' => $active,
            'version' => $active ? (string) ELEMENTOR_VERSION : (string) $file,
            'pro_installed' => $pro_active || $pro_file !== null,
            'pro_active' => $pro_active,
            'pro_version' => $pro_active ? (string) ELEMENTOR_PRO_VERSION : (string) $pro_file,
            'atomic_editor' => $active ? $this->atomic_editor() : null,
            'abilities_api' => $this->abilities->supported(),
            'mcp_adapter' => class_exists('WP\\MCP\\Core\\McpAdapter'),
            'abilities' => $active ? $this->abilities->names(self::ABILITY_PREFIX) : array(),
        );
    }

    protected function built_with_elementor($post)
    {
        try {
            $plugin = \Elementor\Plugin::$instance;

            if (!is_object($plugin) || !isset($plugin->documents) || !is_object($plugin->documents)) {
                return false;
            }

            $document = $plugin->documents->get((int) $post->ID);

            return is_object($document)
                && method_exists($document, 'is_built_with_elementor')
                && (bool) $document->is_built_with_elementor();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function facts()
    {
        if ($this->probed === null) {
            $this->probed = $this->probe();
        }

        return $this->probed;
    }

    // The version of an installed plugin that is not active, or null when its file is not there.
    private function plugin_version($plugin_file)
    {
        if (!defined('WP_PLUGIN_DIR')) {
            return null;
        }

        $path = WP_PLUGIN_DIR . '/' . $plugin_file;

        if (!file_exists($path)) {
            return null;
        }

        $data = get_file_data($path, array('version' => 'Version'));

        return is_array($data) && isset($data['version']) && is_string($data['version']) ? $data['version'] : '';
    }

    private function atomic_editor()
    {
        try {
            $plugin = \Elementor\Plugin::$instance;

            if (is_object($plugin)
                && isset($plugin->experiments)
                && is_object($plugin->experiments)
                && method_exists($plugin->experiments, 'is_feature_active')
            ) {
                return (bool) $plugin->experiments->is_feature_active(self::ATOMIC_EXPERIMENT);
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }

    private static function invalid($field, $problem)
    {
        return new NexoFlow_Agent_Error(
            NexoFlow_Agent_Error::INVALID_PARAMETERS,
            __('One or more parameters are missing or not valid.', 'nexoflow'),
            array('fields' => array($field => $problem))
        );
    }

    /**
     * What a build may not carry, whatever Elementor does with it: script, event handlers or script addresses in the
     * structure or settings, and markup in a style. Returns the parameter that carries it, or ''.
     */
    public static function unsafe_build_input($params)
    {
        $markup = '/<\s*\/?\s*(script|style|iframe|object|embed|link|meta|base)\b|\bon[a-z]+\s*=|(javascript|vbscript|data)\s*:/i';

        if (isset($params['structure']) && is_string($params['structure'])) {
            $decoded = (string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]+/', '', html_entity_decode($params['structure'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if (preg_match($markup, $params['structure']) || preg_match($markup, $decoded) || preg_match('/(java|vb)\s*script\s*:/i', $decoded)) {
                return 'structure';
            }
        }

        if (isset($params['styles']) && is_array($params['styles'])) {
            foreach ($params['styles'] as $css) {
                if (!is_string($css) || preg_match('/[<>]|expression\s*\(|@import|(javascript|vbscript)\s*:|behavior\s*:|-moz-binding/i', $css)) {
                    return 'styles';
                }
            }
        }

        if (isset($params['settings']) && is_array($params['settings'])) {
            $unsafe = false;

            array_walk_recursive(
                $params['settings'],
                function ($value) use (&$unsafe) {
                    if (is_string($value) && self::is_script_address($value)) {
                        $unsafe = true;
                    }
                }
            );

            if ($unsafe) {
                return 'settings';
            }
        }

        return '';
    }

    /**
     * Whether a value is an address that runs script, the way a browser reads it: entities decoded (HTML5 ones
     * too), and the tabs, line breaks and other control characters a browser skips taken out.
     */
    public static function is_script_address($value)
    {
        $decoded = html_entity_decode(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = (string) preg_replace('/[\x00-\x20\x7f]+/', '', $decoded);

        return (bool) preg_match('/^(javascript|vbscript|data):/i', $plain);
    }

    /** Every text in the settings with a tag in it, cleaned the way post content is. */
    public static function clean_settings($settings)
    {
        array_walk_recursive(
            $settings,
            function (&$value) {
                // Only text with a tag in it: "5 < 10 minutes", a CSS "a > b" or a link with "&" stays as it is.
                if (is_string($value) && preg_match('#<\s*/?\s*[a-z!]#i', $value)) {
                    $value = wp_kses_post($value);
                }
            }
        );

        return $settings;
    }

    private function ability_error($error, $max_length = 300)
    {
        $provider_code = (string) $error->get_error_code();
        $data = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 0;
        // Elementor names its elements as tags, "<e-form> has no <e-form-submit-button>". Cleaning the
        // text would strip them and leave "has no .", so its element names are kept, quoted. Any other
        // markup is still removed.
        $message = preg_replace('/<\/?(e-[a-z0-9\-]+)[^<>]*>/i', '"$1"', (string) $error->get_error_message());
        $message = sanitize_text_field((string) $message);

        if (strlen($message) > $max_length) {
            $message = substr($message, 0, $max_length);
        }

        $code = NexoFlow_Agent_Error::PROVIDER_ERROR;

        if ($provider_code === 'nexoflow_ability_missing' || $provider_code === 'elementor_v4_required') {
            $code = NexoFlow_Agent_Error::CAPABILITY_UNAVAILABLE;
        } elseif ($provider_code === 'ability_invalid_permissions' || $status === 401 || $status === 403) {
            $code = NexoFlow_Agent_Error::INSUFFICIENT_PERMISSION;
        } elseif ($status === 404) {
            $code = NexoFlow_Agent_Error::NOT_FOUND;
        } elseif ($provider_code === 'ability_invalid_input' || $status === 400) {
            $code = NexoFlow_Agent_Error::INVALID_PARAMETERS;
        }

        if ($message === '') {
            $message = __('Elementor could not complete this.', 'nexoflow');
        }

        return new NexoFlow_Agent_Error(
            $code,
            $message,
            array('provider' => self::ID, 'provider_code' => sanitize_key($provider_code))
        );
    }

    private function refused()
    {
        return new NexoFlow_Agent_Error(
            NexoFlow_Agent_Error::UNKNOWN_ACTION,
            __('That action is not available.', 'nexoflow')
        );
    }
}
