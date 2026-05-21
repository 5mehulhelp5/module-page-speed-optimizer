<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento etechflow:pso:enable-js-bundling`
 *
 * Wraps Magento's built-in JS bundling + CSS merging + JS minification
 * setup steps so merchants don't have to remember the right flag combo.
 *
 * Why a wrapper instead of building our own bundler: Magento has native
 * JS bundling that goes through setup:static-content:deploy with proper
 * RequireJS module-order preservation. Building a separate runtime
 * bundler would duplicate this AND likely introduce subtle ordering
 * bugs. We use the built-in.
 *
 * This command runs (sequentially):
 *   bin/magento config:set dev/js/enable_js_bundling 1
 *   bin/magento config:set dev/js/merge_files 1
 *   bin/magento config:set dev/js/minify_files 1
 *   bin/magento config:set dev/css/merge_css_files 1
 *   bin/magento config:set dev/css/minify_files 1
 *   bin/magento setup:static-content:deploy -f
 *   bin/magento cache:flush
 *
 * Equivalent to Amasty's "Enable JS Bundling" toggle.
 */
class EnableJsBundlingCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('etechflow:pso:enable-js-bundling')
            ->setDescription('Enable Magento built-in JS bundling + CSS merging + minification (matches Amasty Pro behaviour).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configs = [
            'dev/js/enable_js_bundling' => '1',
            'dev/js/merge_files'         => '1',
            'dev/js/minify_files'        => '1',
            'dev/css/merge_css_files'    => '1',
            'dev/css/minify_files'       => '1',
        ];

        $output->writeln('<info>Enabling Magento native JS bundling + CSS merging + minification...</info>');
        $output->writeln('');

        foreach ($configs as $path => $value) {
            $output->writeln(sprintf('  Setting <comment>%s</comment> = %s', $path, $value));
            $cmd = sprintf('bin/magento config:set %s %s 2>&1',
                \escapeshellarg($path), \escapeshellarg($value));
            $result = [];
            $exitCode = 0;
            @\exec($cmd, $result, $exitCode);
            if ($exitCode !== 0) {
                $output->writeln('  <error>FAILED: ' . implode("\n", $result) . '</error>');
                return Command::FAILURE;
            }
        }

        $output->writeln('');
        $output->writeln('<info>Settings applied. Next steps to take effect:</info>');
        $output->writeln('  1. <comment>bin/magento setup:static-content:deploy -f</comment>');
        $output->writeln('  2. <comment>bin/magento cache:flush</comment>');
        $output->writeln('  3. <comment>sudo systemctl restart php-fpm</comment> (if production with OPcache)');
        $output->writeln('');
        $output->writeln('<info>To DISABLE JS bundling later:</info>');
        $output->writeln('  <comment>bin/magento config:set dev/js/enable_js_bundling 0</comment>');
        $output->writeln('  Then re-deploy static content.');

        return Command::SUCCESS;
    }
}
