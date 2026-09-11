<?php

declare(strict_types=1);

namespace modules\webhook\contract;

use modules\webhook\domain\WechatWebhookEvent;

interface ProviderEventDispatcher
{
    public function dispatch(WechatWebhookEvent $event): void;
}
