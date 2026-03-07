<?php
/**
 * Plugin Name: Imgproxy Rewrite
 * Description: Rewrites WordPress media URLs to signed imgproxy URLs at render time.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Imgproxy_Rewrite {
    private static string $endpoint = '';
    private static string $site_host = '';
    private static string $key_bin = '';
    private static string $salt_bin = '';
    private static int $quality = 82;

    /** @var int[] */
    private static array $width_presets = array(320, 480, 640, 768, 960, 1200, 1536, 1920, 2560);

    public static function boot(): void {
        $bypass = defined('IMGPROXY_BYPASS')
            ? (bool) IMGPROXY_BYPASS
            : filter_var((string) getenv('IMGPROXY_BYPASS'), FILTER_VALIDATE_BOOLEAN);
        if ($bypass) {
            return;
        }

        $endpoint = defined('IMGPROXY_ENDPOINT') ? (string) IMGPROXY_ENDPOINT : (string) getenv('IMGPROXY_ENDPOINT');
        $key_hex = defined('IMGPROXY_KEY') ? (string) IMGPROXY_KEY : (string) getenv('IMGPROXY_KEY');
        $salt_hex = defined('IMGPROXY_SALT') ? (string) IMGPROXY_SALT : (string) getenv('IMGPROXY_SALT');

        self::$endpoint = rtrim($endpoint, '/');
        self::$site_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        self::$key_bin = self::hex_to_bin_safe($key_hex);
        self::$salt_bin = self::hex_to_bin_safe($salt_hex);
        self::$quality = max(50, min(95, (int) getenv('IMGPROXY_QUALITY')));
        if (self::$quality === 0) {
            self::$quality = 82;
        }

        if (self::$endpoint === '' || self::$site_host === '' || self::$key_bin === '' || self::$salt_bin === '') {
            return;
        }

        add_filter('wp_get_attachment_url', array(__CLASS__, 'filter_attachment_url'), 20, 2);
        add_filter('wp_get_attachment_image_src', array(__CLASS__, 'filter_attachment_image_src'), 20, 4);
        add_filter('wp_calculate_image_srcset', array(__CLASS__, 'filter_srcset_sources'), 20, 5);
        add_filter('wp_get_attachment_image_attributes', array(__CLASS__, 'filter_img_attributes'), 20, 3);
        add_filter('the_content', array(__CLASS__, 'filter_content_markup'), 20);
        add_action('template_redirect', array(__CLASS__, 'start_output_buffer'), 0);
    }

    public static function filter_attachment_url(string $url, int $post_id): string {
        if (self::should_bypass_request_context()) {
            return $url;
        }

        if (self::is_site_icon_attachment($post_id)) {
            return $url;
        }

        $meta = wp_get_attachment_metadata($post_id);
        $width = is_array($meta) && isset($meta['width']) ? (int) $meta['width'] : 0;
        return self::build_imgproxy_url($url, $width, 0);
    }

    public static function filter_attachment_image_src($image, int $attachment_id, $size, bool $icon) {
        if (self::should_bypass_request_context()) {
            return $image;
        }

        if (!is_array($image) || empty($image[0])) {
            return $image;
        }

        if (self::is_site_icon_attachment($attachment_id)) {
            return $image;
        }

        $width = isset($image[1]) ? (int) $image[1] : 0;
        $height = isset($image[2]) ? (int) $image[2] : 0;
        $image[0] = self::build_imgproxy_url((string) $image[0], $width, $height);
        return $image;
    }

    public static function filter_srcset_sources($sources, array $size_array, string $image_src, array $image_meta, int $attachment_id) {
        if (self::should_bypass_request_context()) {
            return $sources;
        }

        if (!is_array($sources)) {
            return $sources;
        }

        if (self::is_site_icon_attachment($attachment_id)) {
            return $sources;
        }

        foreach ($sources as $descriptor => $source) {
            if (!is_array($source) || empty($source['url'])) {
                continue;
            }
            $width = isset($source['value']) ? (int) $source['value'] : 0;
            $height = isset($source['height']) ? (int) $source['height'] : 0;
            $sources[$descriptor]['url'] = self::build_imgproxy_url((string) $source['url'], $width, $height);
        }

        return $sources;
    }

    public static function filter_img_attributes(array $attr, WP_Post $attachment, $size): array {
        if (self::should_bypass_request_context()) {
            return $attr;
        }

        if (self::is_site_icon_attachment((int) $attachment->ID)) {
            return $attr;
        }

        foreach (array('src', 'data-src') as $single_attr) {
            if (!empty($attr[$single_attr])) {
                $attr[$single_attr] = self::build_imgproxy_url((string) $attr[$single_attr], 0, 0);
            }
        }

        foreach (array('srcset', 'data-srcset') as $srcset_attr) {
            if (!empty($attr[$srcset_attr])) {
                $attr[$srcset_attr] = self::rewrite_srcset_string((string) $attr[$srcset_attr]);
            }
        }

        return $attr;
    }

    public static function filter_content_markup(string $content): string {
        if (self::should_bypass_request_context()) {
            return $content;
        }

        return self::rewrite_html_attributes($content);
    }

    public static function start_output_buffer(): void {
        if (
            self::should_bypass_request_context()
            || is_feed()
            || is_embed()
            || is_robots()
            || is_trackback()
        ) {
            return;
        }

        ob_start(array(__CLASS__, 'rewrite_html_attributes'));
    }

    private static function should_bypass_request_context(): bool {
        return is_admin() || wp_doing_ajax() || wp_is_json_request();
    }

    public static function rewrite_html_attributes(string $html): string {
        if (strpos($html, 'wp-content/uploads/') === false) {
            return $html;
        }

        $html = preg_replace_callback(
            '/\b(src|data-src)\s*=\s*([\"\'])([^\"\']+)\\2/i',
            static function (array $matches): string {
                $url = self::build_imgproxy_url($matches[3], 0, 0);
                return $matches[1] . '=' . $matches[2] . esc_url($url) . $matches[2];
            },
            $html
        );

        $html = preg_replace_callback(
            '/\b(srcset|data-srcset)\s*=\s*([\"\'])([^\"\']+)\\2/i',
            static function (array $matches): string {
                $rewritten = self::rewrite_srcset_string($matches[3]);
                return $matches[1] . '=' . $matches[2] . esc_attr($rewritten) . $matches[2];
            },
            $html
        );

        return (string) $html;
    }

    private static function rewrite_srcset_string(string $srcset): string {
        $items = preg_split('/\s*,\s*/', trim($srcset));
        if (!is_array($items)) {
            return $srcset;
        }

        $rewritten = array();
        foreach ($items as $item) {
            if ($item === '') {
                continue;
            }

            if (!preg_match('/^(\S+)(?:\s+(.+))?$/', $item, $parts)) {
                $rewritten[] = $item;
                continue;
            }

            $url = $parts[1];
            $descriptor = isset($parts[2]) ? trim($parts[2]) : '';
            $width = 0;
            if (preg_match('/\b(\d+)w\b/', $descriptor, $width_match)) {
                $width = (int) $width_match[1];
            }

            $proxy_url = self::build_imgproxy_url($url, $width, 0);
            $rewritten[] = trim($proxy_url . ' ' . $descriptor);
        }

        return implode(', ', $rewritten);
    }

    private static function build_imgproxy_url(string $source_url, int $width, int $height): string {
        if (!self::is_rewritable_source($source_url) || self::is_already_imgproxy($source_url)) {
            return $source_url;
        }

        $width = self::select_width_preset($width);
        $height = max(0, min(4000, $height));
        $opts = sprintf('rs:fit:%d:%d:0/g:sm/q:%d', $width, $height, self::$quality);

        $local_source = self::to_local_source($source_url);
        $encoded_source = rtrim(strtr(base64_encode($local_source), '+/', '-_'), '=');
        $extension = self::output_extension(pathinfo((string) wp_parse_url($source_url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $path = '/' . $opts . '/' . $encoded_source . '.' . $extension;
        $signature = self::base64url(hash_hmac('sha256', self::$salt_bin . $path, self::$key_bin, true));

        return self::$endpoint . '/' . $signature . $path;
    }

    /**
     * Convert an https:// upload URL to a local:// path so imgproxy
     * reads from the shared volume instead of downloading over the network.
     */
    private static function to_local_source(string $url): string {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if (str_starts_with($path, '/wp-content/uploads/')) {
            return 'local://' . $path;
        }
        return $url;
    }

    private static function is_rewritable_source(string $url): bool {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if ($host === '' || $path === '') {
            return false;
        }

        $site_host = strtolower(self::$site_host);
        $host = strtolower($host);
        $is_site_host = ($host === $site_host) || ($host === 'www.' . $site_host) || ('www.' . $host === $site_host);

        if (!$is_site_host || !str_starts_with($path, '/wp-content/uploads/')) {
            return false;
        }

        $basename = strtolower((string) basename($path));
        if (str_starts_with($basename, 'site-icon-') || str_starts_with($basename, 'favicon-')) {
            return false;
        }

        return self::is_image_path($path);
    }

    private static function is_site_icon_attachment(int $attachment_id): bool {
        if ($attachment_id <= 0) {
            return false;
        }

        return ((int) get_option('site_icon')) === $attachment_id;
    }

    private static function is_already_imgproxy(string $url): bool {
        $endpoint_host = (string) wp_parse_url(self::$endpoint, PHP_URL_HOST);
        $url_host = (string) wp_parse_url($url, PHP_URL_HOST);
        return $endpoint_host !== '' && $url_host !== '' && strtolower($endpoint_host) === strtolower($url_host);
    }

    private static function select_width_preset(int $width): int {
        if ($width <= 0) {
            return 1536;
        }

        foreach (self::$width_presets as $preset) {
            if ($width <= $preset) {
                return $preset;
            }
        }

        return 2560;
    }

    private static function normalize_extension(string $extension): string {
        $extension = strtolower(trim($extension));
        if ($extension === 'jpeg') {
            return 'jpg';
        }

        if (!in_array($extension, array('jpg', 'png', 'gif', 'webp', 'avif'), true)) {
            return 'jpg';
        }

        return $extension;
    }

    /**
     * Choose the imgproxy output format. Legacy formats (jpg, png, gif)
     * are converted to webp so every URL is a modern format regardless
     * of the Accept header — Cloudflare ignores Vary: Accept and caches
     * a single variant per URL, so content negotiation doesn't work.
     */
    private static function output_extension(string $extension): string {
        $ext = self::normalize_extension($extension);
        if (in_array($ext, array('jpg', 'png', 'gif'), true)) {
            return 'webp';
        }
        return $ext;
    }

    private static function is_image_path(string $path): bool {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        return in_array($extension, array('jpg', 'png', 'gif', 'webp', 'avif'), true);
    }

    private static function hex_to_bin_safe(string $hex): string {
        $hex = trim($hex);
        if ($hex === '' || !ctype_xdigit($hex) || (strlen($hex) % 2 !== 0)) {
            return '';
        }

        $binary = hex2bin($hex);
        return $binary === false ? '' : $binary;
    }

    private static function base64url(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

Imgproxy_Rewrite::boot();
