<?php
/**
 * Plugin Name: Asset Optimizer
 * Description: Adds defer to render-blocking scripts and optimizes CSS delivery.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add defer attribute to front-end scripts that are render-blocking.
 *
 * Excludes scripts that must remain synchronous (e.g. jQuery when
 * inline scripts depend on it immediately, or third-party scripts
 * that break if deferred).
 */
add_filter('script_loader_tag', function (string $tag, string $handle, string $src): string {
    if (is_admin()) {
        return $tag;
    }

    if (strpos($tag, 'defer') !== false || strpos($tag, 'async') !== false) {
        return $tag;
    }

    $no_defer = [
        'jquery-core',
        'jquery',
    ];

    if (in_array($handle, $no_defer, true)) {
        return $tag;
    }

    return str_replace(' src=', ' defer src=', $tag);
}, 10, 3);

/**
 * Move non-critical stylesheets to load asynchronously using the
 * media="print" swap pattern, which is widely supported and doesn't
 * require JavaScript.
 *
 * Critical styles (theme, block editor, navigation) stay synchronous
 * so there's no flash of unstyled content for above-the-fold elements.
 */
add_filter('style_loader_tag', function (string $tag, string $handle, string $href, string $media): string {
    if (is_admin()) {
        return $tag;
    }

    $critical = [
        'your-theme-style',
        'wp-block-navigation',
        'wp-block-cover',
        'global-styles',
        'core-block-supports',
    ];

    if (in_array($handle, $critical, true)) {
        return $tag;
    }

    $tag = str_replace(
        "media='{$media}'",
        "media='print' onload=\"this.media='{$media}'\"",
        $tag
    );
    $tag = str_replace(
        "media=\"{$media}\"",
        "media=\"print\" onload=\"this.media='{$media}'\"",
        $tag
    );

    $noscript = "<noscript><link rel='stylesheet' href='{$href}' media='{$media}' /></noscript>";

    return $tag . $noscript;
}, 10, 4);

/**
 * Preload critical CSS and fonts needed for first paint.
 */
add_action('wp_head', function (): void {
    if (is_admin()) {
        return;
    }

    $critical_styles = [
        'your-theme-style',
        'wp-block-navigation',
        'wp-block-cover',
    ];
    foreach ($critical_styles as $handle) {
        $style = wp_styles()->registered[$handle] ?? null;
        if ($style && !empty($style->src)) {
            $ver = $style->ver ?: wp_styles()->default_version;
            $src = $style->src . '?ver=' . $ver;
            echo "<link rel='preload' as='style' href='" . esc_url($src) . "' />\n";
        }
    }

    $critical_fonts = [
        'assets/fonts/your-display-font/YourDisplayFont-Regular.woff2',
        'assets/fonts/your-body-font/YourBodyFont-Variable.woff2',
    ];
    foreach ($critical_fonts as $font_path) {
        $url = get_theme_file_uri($font_path);
        echo "<link rel='preload' as='font' type='font/woff2' href='" . esc_url($url) . "' crossorigin />\n";
    }
}, 1);

/**
 * Remove jQuery Migrate on the front end — it's only needed for
 * legacy jQuery 1.x/2.x code and adds ~12 KB of render-blocking weight.
 * If a plugin breaks, comment out this block.
 */
add_action('wp_default_scripts', function (\WP_Scripts $scripts): void {
    if (is_admin()) {
        return;
    }

    if (isset($scripts->registered['jquery']) && isset($scripts->registered['jquery-core'])) {
        $scripts->registered['jquery']->deps = array_diff(
            $scripts->registered['jquery']->deps,
            ['jquery-migrate']
        );
    }
});

/**
 * Defer Stripe.js loading — only needed on checkout/purchase pages,
 * not on every page load.
 */
add_action('wp_enqueue_scripts', function (): void {
    if (
        !is_singular('download')
        && !function_exists('edd_is_checkout')
    ) {
        wp_dequeue_script('sandhills-stripe-js-v3');
    } elseif (
        function_exists('edd_is_checkout')
        && !edd_is_checkout()
        && !is_singular('download')
    ) {
        wp_dequeue_script('sandhills-stripe-js-v3');
    }
}, 100);

/**
 * Strip unused @font-face declarations from inline global styles.
 *
 * Many FSE themes ship bundled font families but only a few are
 * actually referenced in the site's theme.json / FSE styles.
 * Everything else is dead weight in every HTML response.
 *
 * Customise the allowed slugs below to match the fonts your site uses.
 */
$_allowed_font_slugs = [
    'your-display-font',
    'your-body-font',
    'your-heading-font',
    'your-sans-font',
];

add_action('init', function () use ($_allowed_font_slugs): void {
    if (is_admin()) {
        return;
    }

    remove_action('wp_head', 'wp_print_font_faces', 50);

    add_action('wp_head', function () use ($_allowed_font_slugs): void {
        $settings = wp_get_global_settings();

        if (empty($settings['typography']['fontFamilies'])) {
            return;
        }

        $filtered = $settings;
        foreach ($filtered['typography']['fontFamilies'] as $origin => &$families) {
            if (!is_array($families)) {
                continue;
            }
            $families = array_values(array_filter($families, function ($font) use ($_allowed_font_slugs) {
                return in_array($font['slug'] ?? '', $_allowed_font_slugs, true);
            }));
        }
        unset($families);

        $fonts = [];
        foreach ($filtered['typography']['fontFamilies'] as $font_families) {
            foreach ($font_families as $definition) {
                if (empty($definition['fontFace']) || empty($definition['fontFamily'])) {
                    continue;
                }
                $family_name = $definition['fontFamily'];
                if (str_contains($family_name, ',')) {
                    $family_name = explode(',', $family_name)[0];
                }
                $family_name = trim($family_name, "\"'");
                if (empty($family_name)) {
                    continue;
                }

                $converted = [];
                foreach ($definition['fontFace'] as $face) {
                    $face['font-family'] = $family_name;
                    if (!empty($face['src'])) {
                        $face['src'] = array_map(function ($url) {
                            if (str_starts_with($url, 'file:./')) {
                                return get_theme_file_uri(str_replace('file:./', '', $url));
                            }
                            return $url;
                        }, (array) $face['src']);
                    }
                    $kebab = [];
                    foreach ($face as $k => $v) {
                        $kebab[strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $k))] = $v;
                    }
                    $converted[] = $kebab;
                }
                $fonts[] = $converted;
            }
        }

        if (!empty($fonts)) {
            wp_print_font_faces($fonts);
        }
    }, 50);
});

/**
 * Promote the first large content image to fetchpriority="high" and
 * loading="eager" so the browser fetches the LCP candidate immediately.
 *
 * Uses an output buffer because the LCP image may come from plugins
 * (e.g. Content Views) that don't go through wp_content_img_tag.
 */
add_action('template_redirect', function (): void {
    if (is_admin()) {
        return;
    }

    $remove_hint_domains = [
        'js.stripe.com',
        '0.gravatar.com',
        '1.gravatar.com',
        '2.gravatar.com',
        'stats.wp.com',
        's0.wp.com',
        'public-api.wordpress.com',
        'widgets.wp.com',
        'jetpack.wordpress.com',
    ];

    ob_start(function (string $html) use ($remove_hint_domains): string {
        foreach ($remove_hint_domains as $domain) {
            $html = preg_replace(
                '#<link\s[^>]*rel=[\'"](?:dns-prefetch|preconnect)[\'"][^>]*href=[\'"][^"\']*' . preg_quote($domain, '#') . '[^"\']*[\'"][^>]*/?\s*>\n?#i',
                '',
                $html
            );
        }

        $found_lcp = false;
        $html = preg_replace_callback('/<img\b[^>]*>/i', function (array $match) use (&$found_lcp): string {
            if ($found_lcp) {
                return $match[0];
            }

            $tag = $match[0];

            if (preg_match('/\bclass=["\'][^"\']*\b(site-logo|custom-logo|wp-image-\d+)["\'].*style=["\'][^"\']*width\s*:\s*(\d+)px/i', $tag, $sm)) {
                $rendered_width = (int) $sm[2];
                if ($rendered_width < 300) {
                    return $tag;
                }
            }

            $width = 0;
            if (preg_match('/\bwidth=["\'](\d+)["\']/', $tag, $m)) {
                $width = (int) $m[1];
            }

            if (preg_match('/style=["\'][^"\']*width\s*:\s*(\d+)px/', $tag, $sw)) {
                $width = (int) $sw[1];
            }

            if ($width > 0 && $width < 300) {
                return $tag;
            }

            $found_lcp = true;
            $tag = preg_replace('/\s*\bloading=["\'][^"\']*["\']/', '', $tag);
            $tag = preg_replace('/\s*\bfetchpriority=["\'][^"\']*["\']/', '', $tag);
            $tag = str_replace('<img ', '<img fetchpriority="high" loading="eager" ', $tag);

            return $tag;
        }, $html);

        return $html;
    });
});

/**
 * Remove dns-prefetch/preconnect hints for domains that aren't needed
 * on the current page. Stripe is dequeued on non-checkout pages, Gravatar
 * only matters on pages with comments, and several WordPress.com domains
 * are Jetpack overhead that rarely benefits first-party performance.
 */
add_filter('wp_resource_hints', function (array $urls, string $relation_type): array {
    if (is_admin()) {
        return $urls;
    }

    $remove_domains = [
        'js.stripe.com',
        '0.gravatar.com',
        '1.gravatar.com',
        '2.gravatar.com',
        'stats.wp.com',
        's0.wp.com',
        'public-api.wordpress.com',
        'widgets.wp.com',
        'jetpack.wordpress.com',
    ];

    if ($relation_type === 'dns-prefetch' || $relation_type === 'preconnect') {
        $urls = array_filter($urls, function ($url) use ($remove_domains) {
            $host = is_array($url) ? ($url['href'] ?? '') : $url;
            $host = str_replace('//', '', parse_url($host, PHP_URL_HOST) ?? $host);
            foreach ($remove_domains as $domain) {
                if ($host === $domain) {
                    return false;
                }
            }
            return true;
        });
    }

    return array_values($urls);
}, 10, 2);

/**
 * Disable WordPress emoji detection script and styles.
 * Modern browsers render emojis natively; the loader JS and
 * inline CSS add ~3 KB of unnecessary weight.
 */
add_action('init', function (): void {
    if (is_admin()) {
        return;
    }
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    add_filter('emoji_svg_url', '__return_false');
});

/**
 * Dequeue block styles not used on the current page template.
 * The wp-block-image inline CSS (6.9 KB) includes lightbox overlay
 * styles that are only needed when images have the lightbox enabled.
 * We keep the style registered but swap in a trimmed version.
 */
add_action('wp_enqueue_scripts', function (): void {
    if (is_front_page() || is_home()) {
        wp_dequeue_style('wp-block-post-comments-form');
        wp_dequeue_style('wp-block-latest-comments');
    }
}, 100);
