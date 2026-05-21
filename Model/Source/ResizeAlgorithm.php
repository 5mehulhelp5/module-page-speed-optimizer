<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Source;

use ETechFlow\PageSpeedOptimizer\Model\Image\Resize\ImageResizer;
use Magento\Framework\Data\OptionSourceInterface;

class ResizeAlgorithm implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => ImageResizer::ALGORITHM_FIT,
             'label' => __('Fit — proportional scale (preserve aspect, no cropping)')],
            ['value' => ImageResizer::ALGORITHM_CROP,
             'label' => __('Crop — centered crop to target width')],
        ];
    }
}
