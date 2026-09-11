<?php

declare(strict_types=1);

namespace app\api\controller\V1;

use app\common\context\RequestContext;
use modules\openplatform\application\OpenPlatformEventService;
use DateTimeImmutable;
use DateTimeZone;
use think\Request;
use think\Response;

final readonly class OpenPlatformEventController
{
    public function __construct(
        private OpenPlatformEventService $service,
        private RequestContext $context,
    ) {
    }

    public function receive(string $componentPlatformId, Request $request): Response
    {
        $this->service->ingest(
            $componentPlatformId,
            $request->getInput(),
            (string) $request->get('timestamp', ''),
            (string) $request->get('nonce', ''),
            (string) $request->get('msg_signature', ''),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->context->requestId(),
            $this->context->traceId(),
        );

        return Response::create('success', 'html', 200)->contentType('text/plain');
    }
}
