<?php

declare(strict_types=1);

namespace app\webhook\contract;

use app\webhook\domain\WechatWebhookEvent;

interface ProviderEventDispatcher
{
    public function dispatch(WechatWebhookEvent $event): void;
}
