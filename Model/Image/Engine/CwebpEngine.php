<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Engine;

/**
 * Shells out to the `cwebp` binary from Google's libwebp package.
 *
 * Why prefer this engine: cwebp produces the smallest WebP files of any
 * encoder (it's Google's reference implementation) and is fast enough
 * for production CLI batches (~50-200 images/second on modern hardware).
 *
 * Available on most Linux/macOS hosts via:
 *   apt install webp           # Debian/Ubuntu
 *   yum install libwebp-tools  # RHEL/CentOS
 *   brew install webp          # macOS
 *
 * Not available on some shared hosts that disable exec(). EngineChain
 * falls back to Imagick/GD silently in that case.
 */
class CwebpEngine implements ConversionEngineInterface
{
    public function getName(): string
    {
        return 'cwebp';
    }

    public function available(): bool
    {
        // exec() must be enabled AND cwebp must be on PATH.
        if (!\function_exists('exec')) {
            return false;
        }
        // `which cwebp` returns 0 if found. Suppress stderr.
        $output = [];
        $exitCode = 0;
        @exec('command -v cwebp 2>/dev/null', $output, $exitCode);
        return $exitCode === 0 && !empty($output);
    }

    public function convertToWebp(string $sourcePath, string $outputPath, int $quality): bool
    {
        if (!is_readable($sourcePath)) {
            throw new \RuntimeException(sprintf('cwebp: source not readable: %s', $sourcePath));
        }
        // Defensive: clamp quality.
        $quality = max(1, min(100, $quality));
        // cwebp args:
        //   -q N      quality (1-100; 75 default)
        //   -m 4      compression method (0-6; 4 is the speed/size sweet-spot)
        //   -mt       multi-threaded
        //   -quiet    suppress chatter on stdout
        // Escapeshellarg every path component — paths under pub/media can
        // contain spaces, plus signs, parens (Magento's cache hashing).
        $cmd = sprintf(
            'cwebp -q %d -m 4 -mt -quiet %s -o %s 2>&1',
            $quality,
            \escapeshellarg($sourcePath),
            \escapeshellarg($outputPath)
        );
        $output = [];
        $exitCode = 0;
        @\exec($cmd, $output, $exitCode);
        if ($exitCode !== 0 || !is_file($outputPath)) {
            $tail = implode("\n", array_slice($output, -5));
            throw new \RuntimeException(sprintf('cwebp failed (exit %d): %s', $exitCode, $tail));
        }
        return true;
    }
}
