<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Images for the media library. NexoFlow sends the image itself; this site
 * never fetches an address it is given. Only real images are accepted, and
 * WordPress stores them the way it stores any upload.
 */
class NexoFlow_Agent_Provider_Media implements NexoFlow_Agent_Provider
{
    const ID = 'media';

    const REQUEST_META = '_nexoflow_agent_request';

    const MAX_BYTES = 8388608;

    // Base64 is four characters for every three bytes.
    const MAX_DATA_LENGTH = 11184812;

    const EXTENSIONS = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    );

    public function id()
    {
        return self::ID;
    }

    public function capabilities()
    {
        return array(
            'media.upload' => array(
                'supported' => true,
                'available' => true,
                'requires' => 'upload_files',
                'writes' => true,
            ),
        );
    }

    public function actions()
    {
        return array(
            'media.upload' => array(
                'capability' => 'media.upload',
                'mutates' => true,
                'modes' => array(NexoFlow_Agent_Validator::MODE_DRAFT, NexoFlow_Agent_Validator::MODE_LIVE),
                'description' => 'Add an image to the media library: JPEG, PNG, WebP or GIF, up to 8 MB. Nothing on the site shows it until a page uses it.',
                'parameters' => array(
                    'filename' => array(
                        'type' => 'string',
                        'required' => true,
                        'min_length' => 1,
                        'max_length' => 150,
                        'description' => 'File name to store the image under. The extension is set from the image itself.',
                    ),
                    'data' => array(
                        'type' => 'string',
                        'required' => true,
                        'min_length' => 16,
                        'max_length' => self::MAX_DATA_LENGTH,
                        'description' => 'The image, base64 encoded.',
                    ),
                    'alt' => array(
                        'type' => 'string',
                        'max_length' => 300,
                        'description' => 'What the image shows, for visitors who cannot see it.',
                    ),
                ),
            ),
        );
    }

    public function authorize($action, $params)
    {
        if ($action !== 'media.upload') {
            return $this->refused();
        }

        if (!current_user_can('upload_files')) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::INSUFFICIENT_PERMISSION,
                __('The acting WordPress account cannot add files to the media library.', 'nexoflow')
            );
        }

        return true;
    }

    public function execute($action, $params, $context)
    {
        if ($action !== 'media.upload') {
            return $this->refused();
        }

        $bytes = base64_decode($params['data'], true);

        if ($bytes === false || $bytes === '') {
            return $this->invalid('data', 'format');
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            return $this->invalid('data', 'too_large');
        }

        $this->load_media_functions();

        $tmp = wp_tempnam('nexoflow-image');

        if (!$tmp || file_put_contents($tmp, $bytes) === false) {
            $this->discard($tmp);

            return $this->failed();
        }

        // The image itself says what it is; the name it was sent under does not.
        $mime = wp_get_image_mime($tmp);

        if (!is_string($mime) || !isset(self::EXTENSIONS[$mime])) {
            $this->discard($tmp);

            return $this->invalid('data', 'not_an_image');
        }

        $attachment_id = media_handle_sideload(
            array(
                'name' => $this->filename($params['filename'], self::EXTENSIONS[$mime]),
                'tmp_name' => $tmp,
            ),
            0
        );

        if (is_wp_error($attachment_id) || !$attachment_id) {
            $this->discard($tmp);

            return $this->failed();
        }

        $attachment_id = (int) $attachment_id;

        if (isset($params['alt']) && $params['alt'] !== '') {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($params['alt']));
        }

        if (isset($context['request_id'])) {
            update_post_meta($attachment_id, self::REQUEST_META, $context['request_id']);
        }

        return $this->summary($attachment_id, $mime);
    }

    protected function load_media_functions()
    {
        if (!function_exists('media_handle_sideload') || !function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
    }

    private function summary($attachment_id, $mime)
    {
        $meta = wp_get_attachment_metadata($attachment_id);
        $file = get_attached_file($attachment_id);

        return array(
            'attachment_id' => $attachment_id,
            'url' => (string) wp_get_attachment_url($attachment_id),
            'mime_type' => $mime,
            'width' => is_array($meta) && isset($meta['width']) ? (int) $meta['width'] : null,
            'height' => is_array($meta) && isset($meta['height']) ? (int) $meta['height'] : null,
            'filesize' => is_string($file) && $file !== '' && file_exists($file) ? (int) filesize($file) : null,
            'alt' => (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
        );
    }

    private function filename($name, $extension)
    {
        $base = sanitize_file_name(pathinfo((string) $name, PATHINFO_FILENAME));

        return ($base === '' ? 'image' : $base) . '.' . $extension;
    }

    private function discard($tmp)
    {
        if (is_string($tmp) && $tmp !== '' && file_exists($tmp)) {
            wp_delete_file($tmp);
        }
    }

    private function invalid($field, $problem)
    {
        return new NexoFlow_Agent_Error(
            NexoFlow_Agent_Error::INVALID_PARAMETERS,
            __('One or more parameters are missing or not valid.', 'nexoflow'),
            array('fields' => array($field => $problem))
        );
    }

    private function failed()
    {
        return new NexoFlow_Agent_Error(
            NexoFlow_Agent_Error::EXECUTION_FAILED,
            __('WordPress could not store the image.', 'nexoflow')
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
