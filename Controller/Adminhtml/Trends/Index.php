<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Controller\Adminhtml\Trends;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_PageSpeedOptimizer::trends';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('ETechFlow_PageSpeedOptimizer::trends');
        $page->getConfig()->getTitle()->prepend(__('Page Speed Trends'));
        return $page;
    }
}
