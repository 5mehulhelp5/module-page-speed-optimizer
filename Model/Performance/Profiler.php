<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Performance;

/**
 * Tideways / Blackfire span helper. Same shape as every other ETechFlow
 * module's Profiler. All PSO spans start with `ETechFlow_PSO_`.
 */
final class Profiler
{
    public static function start(string $name)
    {
        if (\function_exists('tideways_span_create')) {
            $id = \tideways_span_create('etechflow');
            \tideways_span_annotate($id, ['title' => $name]);
            return $id;
        }
        if (\function_exists('blackfire_span_open')) {
            return \blackfire_span_open($name);
        }
        return null;
    }

    public static function stop($handle): void
    {
        if ($handle === null) {
            return;
        }
        if (\function_exists('tideways_span_finish')) {
            \tideways_span_finish($handle);
            return;
        }
        if (\function_exists('blackfire_span_close')) {
            \blackfire_span_close($handle);
        }
    }
}
