<?php
/**
 * Plugin Name: Khalaj Premiere Settings Inspector
 * Description: Temporary read-only inspector for Premiere naming settings in Khalaj Core.
 * Version: 1.4.0
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;

add_action('rest_api_init', static function () {
    register_rest_route('khj-premiere-settings/v1', '/inspect', [
        'methods' => 'GET',
        'permission_callback' => static function () { return current_user_can('manage_options'); },
        'callback' => static function () {
            $root = WP_PLUGIN_DIR . '/khalaj-core---2';
            $needles = [
                'premiere_broadcast_packages',
                'khalaj_core_product_naming_v1',
                'Title Affixes',
                'after_effects',
            ];
            $out = [
                'ok' => true,
                'root_exists' => is_dir($root),
                'matches' => [],
                'option_keys' => [],
            ];
            $settings = get_option('khalaj_core_product_naming_v1', []);
            if (is_array($settings)) {
                $out['option_keys'] = array_keys($settings);
            }
            if (!is_dir($root)) {
                $out['ok'] = false;
                return rest_ensure_response($out);
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
                $path = $file->getPathname();
                $src = @file_get_contents($path);
                if (!is_string($src) || $src === '') continue;
                $lines = preg_split('/\R/', $src);
                foreach ($lines as $i => $line) {
                    foreach ($needles as $needle) {
                        if (stripos($line, $needle) !== false) {
                            $start = max(0, $i - 8);
                            $end = min(count($lines) - 1, $i + 12);
                            $snippet = [];
                            for ($j = $start; $j <= $end; $j++) {
                                $snippet[] = ($j + 1) . "\t" . $lines[$j];
                            }
                            $out['matches'][] = [
                                'file' => ltrim(str_replace($root, '', $path), '/\\'),
                                'needle' => $needle,
                                'line' => $i + 1,
                                'snippet' => implode("\n", $snippet),
                            ];
                            if (count($out['matches']) >= 80) break 4;
                            break;
                        }
                    }
                }
            }
            return rest_ensure_response($out);
        },
    ]);
});
