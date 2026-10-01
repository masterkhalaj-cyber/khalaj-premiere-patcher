<?php
/**
 * Plugin Name: Khalaj Premiere Inspector
 * Description: Read-only diagnostic inspector for Premiere integration in Khalaj Core.
 * Version: 1.2.1
 * Author: Khalaj.Net
 */
defined('ABSPATH') || exit;

function khj_pi121_excerpt(string $src, array $patterns, int $radius = 18): array {
    $lines = preg_split('/\R/', $src);
    $hits = [];
    foreach ($patterns as $pattern) {
        foreach ($lines as $i => $line) {
            if (stripos($line, $pattern) === false) continue;
            $start = max(0, $i - $radius);
            $end = min(count($lines) - 1, $i + $radius);
            $buf = [];
            for ($j = $start; $j <= $end; $j++) {
                $buf[] = ($j + 1) . ': ' . $lines[$j];
            }
            $hits[] = [
                'pattern' => $pattern,
                'line' => $i + 1,
                'excerpt' => implode("\n", $buf),
            ];
            break;
        }
    }
    return $hits;
}

function khj_pi121_activate(): void {
    $targets = [
        WP_PLUGIN_DIR . '/khalaj-core---2/includes/class-khalaj-core-quality-gate.php' => [
            'missing_product_type',
            '_khalaj_product_type',
            '_khalaj_generation_entry_id',
            'premiere_project',
        ],
        WP_PLUGIN_DIR . '/khalaj-core---2/includes/class-khalaj-core-gravity-contract.php' => [
            'enforce_pipeline_product_type',
            'khalaj_ai_v40_80_process_pipeline',
            'khalaj_ai_target_product_type',
        ],
        WP_PLUGIN_DIR . '/khalaj-core---2/includes/core-modules/runtime-guards.php' => [
            '_khalaj_product_type',
            '_khalaj_generation_entry_id',
            'entry_type',
            'canonicalize_product',
        ],
        WP_PLUGIN_DIR . '/khalaj-gravity-openai/khalaj-gravity-openai.php' => [
            '_khalaj_product_type',
            '_khalaj_generation_entry_id',
            'wp_insert_post',
            'wp_update_post',
            'Quality Gate',
        ],
    ];

    $report = [
        'ok' => true,
        'version' => '1.2.1',
        'created_at' => gmdate('c'),
        'files' => [],
    ];

    foreach ($targets as $path => $patterns) {
        $key = str_replace(WP_PLUGIN_DIR . '/', '', $path);
        if (!is_file($path) || !is_readable($path)) {
            $report['files'][$key] = ['exists' => false];
            continue;
        }
        $src = (string) file_get_contents($path);
        $report['files'][$key] = [
            'exists' => true,
            'sha256' => hash('sha256', $src),
            'bytes' => strlen($src),
            'hits' => khj_pi121_excerpt($src, $patterns),
        ];
    }

    update_option('khj_premiere_inspector_v121', $report, false);
}
register_activation_hook(__FILE__, 'khj_pi121_activate');
