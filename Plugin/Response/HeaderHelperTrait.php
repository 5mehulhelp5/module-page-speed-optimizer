<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Plugin\Response;

use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Helper for response-output plugins. Magento's HttpResponse::getHeader()
 * returns a Laminas\Http\Header\HeaderInterface object (NOT a string) when
 * the header is present, OR `false` when missing. Casting that object
 * directly to (string) throws on PHP 8+, so we centralise the safe accessor.
 */
trait HeaderHelperTrait
{
    private function headerValue(HttpResponse $response, string $name): string
    {
        $header = $response->getHeader($name);
        if (!$header) {
            return '';
        }
        if (is_string($header)) {
            return $header;
        }
        if (is_object($header) && method_exists($header, 'getFieldValue')) {
            return (string) $header->getFieldValue();
        }
        return '';
    }
}
