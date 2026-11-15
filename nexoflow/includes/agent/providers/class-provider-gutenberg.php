<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Agent_Provider_Gutenberg implements NexoFlow_Agent_Builder
{
    const ID = 'gutenberg';

    const FREEFORM = 'core/freeform';

    const MAX_CONTENT_LENGTH = 1000000;

    const MAX_REPLACEMENTS = 20;

    const KIND_TEMPLATE = 'template';

    const KIND_PART = 'part';

    private $documents;

    private $templates;

    private $parts;

    private $probed = null;

    public function __construct($documents, $templates, $parts)
    {
        $this->documents = $documents;
        $this->templates = $templates;
        $this->parts = $parts;
    }

    public function id()
    {
        return self::ID;
    }

    public function detect()
    {
        $facts = $this->facts();
        $supported = !empty($facts['parser']);
        $active = $supported && !empty($facts['pages_use_blocks']);
        $reason = '';
        $message = '';

        if (!$supported) {
            $reason = 'BLOCK_API_UNAVAILABLE';
            $message = __('This WordPress version does not have the block editor.', 'nexoflow');
        } elseif (!$active) {
            $reason = 'BLOCK_EDITOR_DISABLED';
            $message = __('Pages on this site are edited with the classic editor.', 'nexoflow');
        }

        return array(
            'installed' => true,
            'active' => $active,
            'supported' => $supported,
            'available' => $active,
            'classic_editor_plugin' => !empty($facts['classic_editor_plugin']),
            'gutenberg_plugin' => isset($facts['gutenberg_plugin']) && $facts['gutenberg_plugin'] !== '' ? (string) $facts['gutenberg_plugin'] : null,
            'block_theme' => !empty($facts['block_theme']),
            'block_types' => isset($facts['block_types']) ? (int) $facts['block_types'] : 0,
            'reason' => $reason,
            'message' => $message,
        );
    }

    public function owns($post)
    {
        $facts = $this->facts();

        if (empty($facts['parser'])) {
            return false;
        }

        $content = (string) $post->post_content;

        if (has_blocks($content)) {
            return true;
        }

        return trim($content) === '' && !empty($facts['pages_use_blocks']);
    }

    public function allows_content_edit()
    {
        return true;
    }

    public function document_version($post)
    {
        return '';
    }

    public function capabilities()
    {
        $detected = $this->detect();

        $facts = $this->facts();
        $writes = $detected['supported'] && !empty($facts['serializer']);

        return array(
            'blocks.read' => array(
                'supported' => $detected['supported'],
                'available' => $detected['supported'],
                'reason' => 'BLOCK_API_UNAVAILABLE',
                'message' => __('This WordPress version does not have the block editor.', 'nexoflow'),
                'requires' => 'edit_pages',
            ),
            // Blocks are only added where pages are edited with blocks.
            'blocks.insert' => array(
                'supported' => $writes,
                'available' => $writes && $detected['available'],
                'reason' => $writes ? $detected['reason'] : 'BLOCK_API_UNAVAILABLE',
                'message' => $writes
                    ? $detected['message']
                    : __('This WordPress version does not have the block editor.', 'nexoflow'),
                'requires' => 'edit_pages',
                'writes' => true,
            ),
            'blocks.update' => array(
                'supported' => $writes,
                'available' => $writes && $detected['available'],
                'reason' => $writes ? $detected['reason'] : 'BLOCK_API_UNAVAILABLE',
                'message' => $writes
                    ? $detected['message']
                    : __('This WordPress version does not have the block editor.', 'nexoflow'),
                'requires' => 'edit_pages',
                'writes' => true,
            ),
        ) + $this->template_capabilities($writes);
    }

    private function template_capabilities($writes)
    {
        $themed = $this->templates->supported();
        $live = $this->templates->live_edits();
        $no_theme = array(
            'reason' => 'BLOCK_THEME_REQUIRED',
            'message' => __('This site does not use a block theme, so it has no templates to change.', 'nexoflow'),
        );

        return array(
            'templates.read' => array(
                'supported' => $themed,
                'available' => $themed,
                'requires' => 'edit_theme_options',
            ) + $no_theme,
            'templates.update' => array(
                'supported' => $themed && $writes,
                'available' => $themed && $writes && $live['available'],
                'requires' => 'edit_theme_options',
                'writes' => true,
                'reason' => $themed && $writes ? $live['reason'] : $no_theme['reason'],
                'message' => $themed && $writes ? $live['message'] : $no_theme['message'],
            ),
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
        $template = array(
            'type' => 'string',
            'required' => true,
            'max_length' => 200,
            'pattern' => NexoFlow_Agent_Templates::ID_PATTERN,
            'description' => 'Template ID, in the form theme//slug.',
        );
        $expected_revision = array(
            'type' => 'string',
            'required' => true,
            'pattern' => NexoFlow_Agent_Documents::REVISION_PATTERN,
            'description' => 'The revision returned when it was last read.',
        );
        $content = array(
            'type' => 'string',
            'required' => true,
            'min_length' => 1,
            'max_length' => self::MAX_CONTENT_LENGTH,
            'description' => 'The new blocks as block markup. HTML outside a block is refused. Scripts and unsafe markup are removed.',
        );
        $position = array(
            'type' => 'string',
            'enum' => array('start', 'end', 'before', 'after'),
            'default' => 'end',
            'description' => 'Where to add the blocks.',
        );
        $include_markup = array(
            'type' => 'boolean',
            'default' => false,
            'description' => 'Also return the full block markup of each top-level block, to see how the existing sections are built.',
        );
        $path = array(
            'type' => 'string',
            'pattern' => '/^[0-9]{1,4}$/D',
            'description' => 'Path of a top-level block from the matching read action. Needed for before and after.',
        );
        $kind = array(
            'type' => 'string',
            'enum' => array(self::KIND_TEMPLATE, self::KIND_PART),
            'default' => self::KIND_TEMPLATE,
            'description' => 'template for a page template, part for a template part such as the header or footer (theme.template_parts).',
        );
        $replacements = array(
            'type' => 'list',
            'required' => true,
            'min_items' => 1,
            'max_items' => self::MAX_REPLACEMENTS,
            'description' => 'The top-level blocks to replace or remove. Each one is swapped for the blocks given, or taken away; every other block is left as it is. At least one block must be left.',
            'items' => array(
                'path' => array(
                    'type' => 'string',
                    'required' => true,
                    'pattern' => '/^[0-9]{1,4}$/D',
                    'description' => 'Path of a top-level block from the matching read action.',
                ),
                'content' => array_merge($content, array(
                    'required' => false,
                    'description' => 'The blocks that take its place, as block markup. HTML outside a block is refused. Scripts and unsafe markup are removed. Leave out when remove is set.',
                )),
                'remove' => array(
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Take this block away instead of replacing it. Used with content left out, for example when a page is rebuilt with fewer sections than it had.',
                ),
            ),
        );

        return array(
            'blocks.read' => array(
                'capability' => 'blocks.read',
                'description' => 'Read the blocks of a page as a tree. Each block has a path that identifies it within the page.',
                'parameters' => array(
                    'page_id' => $page_id,
                    'include_markup' => $include_markup,
                ),
            ),
            'blocks.insert' => array(
                'capability' => 'blocks.insert',
                'mutates' => true,
                'modes' => array(NexoFlow_Agent_Validator::MODE_DRAFT, NexoFlow_Agent_Validator::MODE_LIVE),
                'description' => 'Add blocks to a page: at the start, at the end, or before or after a top-level block from blocks.read. The blocks already on the page are left as they are. A published page needs mode live; the previous version is kept and can be restored.',
                'parameters' => array(
                    'page_id' => $page_id,
                    'expected_revision' => $expected_revision,
                    'content' => $content,
                    'position' => $position,
                    'path' => $path,
                ),
            ),
            'blocks.replace' => array(
                'capability' => 'blocks.update',
                'mutates' => true,
                'modes' => array(NexoFlow_Agent_Validator::MODE_DRAFT, NexoFlow_Agent_Validator::MODE_LIVE),
                'description' => 'Replace top-level blocks of a page with new blocks, for example to change one section. The other blocks on the page are left as they are. A published page needs mode live; the previous version is kept and can be restored.',
                'parameters' => array(
                    'page_id' => $page_id,
                    'expected_revision' => $expected_revision,
                    'replacements' => $replacements,
                ),
            ),
            'templates.read' => array(
                'capability' => 'templates.read',
                'description' => 'Read the blocks of a block theme template, for example the one that draws the homepage (site.front_page.template), or of a template part such as the header.',
                'parameters' => array(
                    'template' => $template,
                    'kind' => $kind,
                    'include_markup' => $include_markup,
                ),
            ),
            'templates.insert' => array(
                'capability' => 'templates.update',
                'mutates' => true,
                'modes' => array(NexoFlow_Agent_Validator::MODE_LIVE),
                'description' => 'Add blocks to a block theme template: at the start, at the end, or before or after a top-level block from templates.read. Every visitor sees the change at once, so it needs mode live. The previous version is kept and can be restored. The theme files are not changed.',
                'parameters' => array(
                    'template' => $template,
                    'kind' => $kind,
                    'expected_revision' => $expected_revision,
                    'content' => $content,
                    'position' => $position,
                    'path' => $path,
                ),
            ),
            'templates.replace' => array(
                'capability' => 'templates.update',
                'mutates' => true,
                'modes' => array(NexoFlow_Agent_Validator::MODE_LIVE),
                'description' => 'Replace top-level blocks of a block theme template or template part with new blocks, for example to change one section of the homepage or the links in the header. Every visitor sees the change at once, so it needs mode live. The previous version is kept and can be restored. The theme files are not changed.',
                'parameters' => array(
                    'template' => $template,
                    'kind' => $kind,
                    'expected_revision' => $expected_revision,
                    'replacements' => $replacements,
                ),
            ),
            'templates.restore' => array(
                'capability' => 'templates.update',
                'mutates' => true,
                'modes' => array(NexoFlow_Agent_Validator::MODE_LIVE),
                'description' => 'Put a template or template part back to an earlier version, for example the restore_revision_id returned by a change.',
                'parameters' => array(
                    'template' => $template,
                    'kind' => $kind,
                    'expected_revision' => $expected_revision,
                    'revision_id' => array(
                        'type' => 'integer',
                        'required' => true,
                        'min' => 1,
                        'description' => 'ID of the WordPress revision to restore.',
                    ),
                ),
            ),
        );
    }

    public function authorize($action, $params)
    {
        if (strpos($action, 'blocks.') === 0) {
            return $this->documents->authorize_edit($params['page_id']);
        }

        if (strpos($action, 'templates.') !== 0) {
            return $this->refused();
        }

        if (!current_user_can('edit_theme_options')) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::INSUFFICIENT_PERMISSION,
                __('The acting WordPress account cannot change this site\'s templates.', 'nexoflow')
            );
        }

        $template = $this->store($params)->find($params['template']);

        return $template instanceof NexoFlow_Agent_Error ? $template : true;
    }

    // Templates and template parts are kept apart by WordPress; kind says which one is meant.
    private function store($params)
    {
        return isset($params['kind']) && $params['kind'] === self::KIND_PART ? $this->parts : $this->templates;
    }

    public function execute($action, $params, $context)
    {
        if ($action === 'blocks.insert' || $action === 'blocks.replace') {
            return $this->change_page($action, $params, $context);
        }

        if (strpos($action, 'templates.') === 0) {
            return $this->template_action($action, $params);
        }

        if ($action !== 'blocks.read') {
            return $this->refused();
        }

        $post = $this->documents->page($params['page_id']);

        if ($post instanceof NexoFlow_Agent_Error) {
            return $post;
        }

        $builder = $this->documents->builder_provider($post);

        if ($builder && !$builder->allows_content_edit()) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::UNSUPPORTED_BUILDER_OPERATION,
                __('This page is laid out by a page builder, not by blocks.', 'nexoflow'),
                array('page_id' => (int) $post->ID, 'builder' => $builder->id())
            );
        }

        $content = (string) $post->post_content;
        $count = 0;
        $blocks = $this->tree(parse_blocks($content), '', $count, !empty($params['include_markup']));

        return array(
            'page_id' => (int) $post->ID,
            'status' => (string) $post->post_status,
            'builder' => $this->documents->builder($post),
            'revision' => $this->documents->revision($post),
            'has_blocks' => (bool) has_blocks($content),
            'block_count' => $count,
            'blocks' => $blocks,
        );
    }

    private function change_page($action, $params, $context)
    {
        $post = $this->documents->page($params['page_id']);

        if ($post instanceof NexoFlow_Agent_Error) {
            return $post;
        }

        $allowed = $this->documents->check_write($post, $params['expected_revision'], $context['mode']);

        if ($allowed !== true) {
            return $allowed;
        }

        $builder = $this->documents->builder_provider($post);

        if ($builder && !$builder->allows_content_edit()) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::UNSUPPORTED_BUILDER_OPERATION,
                __('This page is laid out by a page builder, not by blocks.', 'nexoflow'),
                array('page_id' => (int) $post->ID, 'builder' => $builder->id())
            );
        }

        $changed = $this->changed((string) $post->post_content, $action, $params);

        if ($changed instanceof NexoFlow_Agent_Error) {
            return $changed;
        }

        $page = $this->documents->write($post, array('post_content' => $changed['content']));

        if ($page instanceof NexoFlow_Agent_Error) {
            return $page;
        }

        return $page + $changed['report'];
    }

    /**
     * The content after an insert or replace action, and what to report about it.
     *
     * @return array|NexoFlow_Agent_Error
     */
    private function changed($existing_content, $action, $params)
    {
        if (substr($action, -8) === '.replace') {
            $replaced = $this->replace($existing_content, $params['replacements']);

            return $replaced instanceof NexoFlow_Agent_Error
                ? $replaced
                : array('content' => $replaced['content'], 'report' => array('replaced' => $replaced['replaced']));
        }

        $spliced = $this->splice($existing_content, $params);

        return $spliced instanceof NexoFlow_Agent_Error
            ? $spliced
            : array('content' => $spliced['content'], 'report' => array('inserted' => $spliced['inserted']));
    }

    private function template_action($action, $params)
    {
        $templates = $this->store($params);
        $template = $templates->find($params['template']);

        if ($template instanceof NexoFlow_Agent_Error) {
            return $template;
        }

        if ($action === 'templates.read') {
            $content = (string) $template->content;
            $count = 0;
            $blocks = $this->tree(parse_blocks($content), '', $count, !empty($params['include_markup']));

            return $templates->summary($template) + array(
                'has_blocks' => (bool) has_blocks($content),
                'block_count' => $count,
                'blocks' => $blocks,
            );
        }

        $allowed = $templates->check_write($template, $params['expected_revision']);

        if ($allowed !== true) {
            return $allowed;
        }

        if ($action === 'templates.restore') {
            return $templates->restore($template, (int) $params['revision_id']);
        }

        if ($action !== 'templates.insert' && $action !== 'templates.replace') {
            return $this->refused();
        }

        $changed = $this->changed((string) $template->content, $action, $params);

        if ($changed instanceof NexoFlow_Agent_Error) {
            return $changed;
        }

        $saved = $templates->write($template, $changed['content']);

        if ($saved instanceof NexoFlow_Agent_Error) {
            return $saved;
        }

        return $saved + $changed['report'];
    }

    /**
     * Swaps top-level blocks of existing block markup for the new blocks of a
     * request. Only the new blocks are sanitized; every other block, and the
     * whitespace around them, is not rewritten.
     *
     * @return array|NexoFlow_Agent_Error content and what was replaced
     */
    private function replace($existing_content, $replacements)
    {
        $existing = parse_blocks($existing_content);
        $existing = is_array($existing) ? $existing : array();
        $offsets = array();

        foreach ($existing as $index => $block) {
            if (!$this->is_gap($block)) {
                $offsets[] = $index;
            }
        }

        $swaps = array();

        foreach ($replacements as $position => $replacement) {
            $path = (int) $replacement['path'];
            $problem = !isset($offsets[$path]) ? 'not_found' : (isset($swaps[$offsets[$path]]) ? 'repeated' : '');

            if ($problem !== '') {
                return new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::INVALID_PARAMETERS,
                    __('One or more parameters are missing or not valid.', 'nexoflow'),
                    array('fields' => array('replacements.' . $position . '.path' => $problem))
                );
            }

            $remove = !empty($replacement['remove']);
            $given = isset($replacement['content']) && $replacement['content'] !== '';

            // A block is either swapped for new blocks or taken away, never both and never neither.
            if ($remove === $given) {
                return new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::INVALID_PARAMETERS,
                    __('One or more parameters are missing or not valid.', 'nexoflow'),
                    array('fields' => array('replacements.' . $position . '.content' => $remove ? 'not_allowed' : 'required'))
                );
            }

            if ($remove) {
                $swaps[$offsets[$path]] = array();

                continue;
            }

            $added = $this->new_blocks(parse_blocks(wp_kses_post($replacement['content'])));

            if ($added instanceof NexoFlow_Agent_Error) {
                return $added;
            }

            $swaps[$offsets[$path]] = $added;
        }

        $merged = array();
        $blocks = 0;
        $kept = 0;
        $removed = 0;
        $skip_gap = false;

        foreach ($existing as $index => $block) {
            if (!isset($swaps[$index])) {
                // The blank line that followed a block taken away goes with it.
                if ($skip_gap && $this->is_gap($block)) {
                    $skip_gap = false;

                    continue;
                }

                $skip_gap = false;
                $merged[] = $block;
                $kept += $this->is_gap($block) ? 0 : 1;

                continue;
            }

            if (empty($swaps[$index])) {
                $removed++;
                $skip_gap = true;

                continue;
            }

            $skip_gap = false;

            foreach ($swaps[$index] as $number => $added) {
                if ($number > 0) {
                    $merged[] = $this->gap();
                }

                $merged[] = $added;
                $blocks++;
            }
        }

        // The last block was taken away: the blank line before it goes too.
        while ($removed > 0 && !empty($merged) && $this->is_gap($merged[count($merged) - 1])) {
            array_pop($merged);
        }

        if ($kept + $blocks === 0) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::INVALID_PARAMETERS,
                __('One or more parameters are missing or not valid.', 'nexoflow'),
                array('fields' => array('replacements' => 'removes_everything'))
            );
        }

        return array(
            'content' => serialize_blocks($merged),
            'replaced' => array(
                'sections' => count($swaps) - $removed,
                'blocks' => $blocks,
                'removed' => $removed,
            ),
        );
    }

    /**
     * Puts the new blocks of a request into existing block markup. Only the new
     * blocks are sanitized; what is already there is not rewritten.
     *
     * @return array|NexoFlow_Agent_Error content and where the blocks went
     */
    private function splice($existing_content, $params)
    {
        $added = $this->new_blocks(parse_blocks(wp_kses_post($params['content'])));

        if ($added instanceof NexoFlow_Agent_Error) {
            return $added;
        }

        $existing = parse_blocks($existing_content);
        $existing = is_array($existing) ? $existing : array();
        $position = $params['position'];
        $offsets = array();

        foreach ($existing as $index => $block) {
            if (!$this->is_gap($block)) {
                $offsets[] = $index;
            }
        }

        if ($position === 'start') {
            $at = 0;
            $first = 0;
        } elseif ($position === 'end') {
            $at = count($existing);
            $first = count($offsets);
        } else {
            $path = isset($params['path']) ? (int) $params['path'] : -1;

            if (!isset($params['path']) || !isset($offsets[$path])) {
                return new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::INVALID_PARAMETERS,
                    __('One or more parameters are missing or not valid.', 'nexoflow'),
                    array('fields' => array('path' => isset($params['path']) ? 'not_found' : 'required'))
                );
            }

            $at = $position === 'before' ? $offsets[$path] : $offsets[$path] + 1;
            $first = $position === 'before' ? $path : $path + 1;
        }

        $before = array_slice($existing, 0, $at);
        $after = array_slice($existing, $at);
        $merged = $before;

        if (!empty($before) && !$this->is_gap($before[count($before) - 1])) {
            $merged[] = $this->gap();
        }

        $count = 0;

        foreach ($added as $block) {
            if ($count > 0) {
                $merged[] = $this->gap();
            }

            $merged[] = $block;
            $count++;
        }

        if (!empty($after) && !$this->is_gap($after[0])) {
            $merged[] = $this->gap();
        }

        return array(
            'content' => serialize_blocks(array_merge($merged, $after)),
            'inserted' => array(
                'path' => (string) $first,
                'count' => $count,
            ),
        );
    }

    /**
     * The blocks of a request, without the whitespace between them.
     *
     * @return array|NexoFlow_Agent_Error
     */
    private function new_blocks($parsed)
    {
        $blocks = array();
        $problem = '';

        foreach (is_array($parsed) ? $parsed : array() as $block) {
            if (!is_array($block) || $this->is_gap($block)) {
                continue;
            }

            if (empty($block['blockName'])) {
                $problem = 'not_blocks';
                break;
            }

            $unknown = $this->unknown_core_block($block);

            if ($unknown !== '') {
                return new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::INVALID_PARAMETERS,
                    __('The content uses a WordPress block that does not exist on this site.', 'nexoflow'),
                    array('fields' => array('content' => 'unknown_block'), 'block' => $unknown)
                );
            }

            $blocks[] = $block;
        }

        if ($problem === '' && empty($blocks)) {
            $problem = 'no_blocks';
        }

        if ($problem !== '') {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::INVALID_PARAMETERS,
                __('The content must be block markup.', 'nexoflow'),
                array('fields' => array('content' => $problem))
            );
        }

        return $blocks;
    }

    // Blocks from other plugins may only exist in the editor, so only core names can be checked here.
    private function unknown_core_block($block)
    {
        $name = isset($block['blockName']) && is_string($block['blockName']) ? $block['blockName'] : '';

        if (strpos($name, 'core/') === 0
            && class_exists('WP_Block_Type_Registry')
            && !WP_Block_Type_Registry::get_instance()->is_registered($name)
        ) {
            return $name;
        }

        if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $inner) {
                $unknown = is_array($inner) ? $this->unknown_core_block($inner) : '';

                if ($unknown !== '') {
                    return $unknown;
                }
            }
        }

        return '';
    }

    private function is_gap($block)
    {
        return is_array($block)
            && empty($block['blockName'])
            && trim(isset($block['innerHTML']) ? (string) $block['innerHTML'] : '') === '';
    }

    private function gap()
    {
        return array(
            'blockName' => null,
            'attrs' => array(),
            'innerBlocks' => array(),
            'innerHTML' => "\n\n",
            'innerContent' => array("\n\n"),
        );
    }

    protected function probe()
    {
        return array(
            'parser' => function_exists('parse_blocks') && function_exists('has_blocks'),
            'serializer' => function_exists('serialize_blocks'),
            'pages_use_blocks' => $this->pages_use_blocks(),
            'classic_editor_plugin' => class_exists('Classic_Editor'),
            'gutenberg_plugin' => defined('GUTENBERG_VERSION') ? (string) GUTENBERG_VERSION : '',
            'block_theme' => function_exists('wp_is_block_theme') ? (bool) wp_is_block_theme() : false,
            'block_types' => class_exists('WP_Block_Type_Registry')
                ? count(WP_Block_Type_Registry::get_instance()->get_all_registered())
                : 0,
        );
    }

    private function facts()
    {
        if ($this->probed === null) {
            $this->probed = $this->probe();
        }

        return $this->probed;
    }

    private function pages_use_blocks()
    {
        $type = NexoFlow_Agent_Documents::POST_TYPE;

        if (function_exists('use_block_editor_for_post_type')) {
            return (bool) use_block_editor_for_post_type($type);
        }

        // WordPress 6.0 only loads that check in the admin.
        $object = get_post_type_object($type);

        return is_object($object)
            && !empty($object->show_in_rest)
            && post_type_supports($type, 'editor')
            && !class_exists('Classic_Editor');
    }

    // Whitespace between blocks is parsed as an empty block and is left out.
    private function tree($blocks, $prefix, &$count, $markup = false)
    {
        $nodes = array();
        $index = 0;

        if (!is_array($blocks)) {
            return $nodes;
        }

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $name = isset($block['blockName']) && is_string($block['blockName']) ? $block['blockName'] : '';
            $html = isset($block['innerHTML']) && is_string($block['innerHTML']) ? trim($block['innerHTML']) : '';

            if ($name === '' && $html === '') {
                continue;
            }

            $path = $prefix === '' ? (string) $index : $prefix . '.' . $index;
            $index++;
            $count++;

            $node = array(
                'path' => $path,
                'name' => $name === '' ? self::FREEFORM : $name,
                'attributes' => !empty($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : new stdClass(),
                'html' => $html,
                'inner_blocks' => $this->tree(
                    isset($block['innerBlocks']) ? $block['innerBlocks'] : array(),
                    $path,
                    $count
                ),
            );

            // Which header or footer a template shows, so it can be read and changed as its own document.
            if ($name === 'core/template-part' && !empty($block['attrs']['slug']) && is_string($block['attrs']['slug'])) {
                $theme = !empty($block['attrs']['theme']) && is_string($block['attrs']['theme'])
                    ? $block['attrs']['theme']
                    : (string) get_stylesheet();
                $node['template_part'] = $theme . '//' . $block['attrs']['slug'];
            }

            if ($markup && $prefix === '' && function_exists('serialize_block')) {
                $node['markup'] = serialize_block($block);

                // A pattern is only a reference here; its sections live in the registry.
                $pattern = $name === 'core/pattern' ? $this->pattern_markup($block) : '';

                if ($pattern !== '') {
                    $node['pattern_markup'] = $pattern;
                }
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    private function pattern_markup($block)
    {
        $slug = isset($block['attrs']['slug']) && is_string($block['attrs']['slug']) ? $block['attrs']['slug'] : '';

        if ($slug === '' || !class_exists('WP_Block_Patterns_Registry')) {
            return '';
        }

        $registry = WP_Block_Patterns_Registry::get_instance();

        if (!$registry->is_registered($slug)) {
            return '';
        }

        $pattern = $registry->get_registered($slug);

        return is_array($pattern) && isset($pattern['content']) && is_string($pattern['content']) ? $pattern['content'] : '';
    }

    private function refused()
    {
        return new NexoFlow_Agent_Error(
            NexoFlow_Agent_Error::UNKNOWN_ACTION,
            __('That action is not available.', 'nexoflow')
        );
    }
}
