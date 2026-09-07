<?php

declare(strict_types=1);

namespace app;

use app\common\context\CorrelationIdFactory;
use app\common\context\RequestContext;
use app\common\error\AppException;
use app\common\error\ErrorEnvelope;
use think\exception\Handle;
use think\Response;
use Throwable;

final class ExceptionHandle extends Handle
{
    public function render($request, Throwable $e): Response
    {
        if ($e instanceof AppException) {
            $requestId = $this->resolveRequestId($request);

            return json(
                ErrorEnvelope::error(
                    requestId: $requestId,
                    code: $e->errorCode()->value,
                    message: $e->getMessage(),
                    data: $e->data(),
                ),
                $e->httpStatus(),
            );
        }

        return parent::render($request, $e);
    }

    private function resolveRequestId($request): string
    {
        try {
            $context = app(RequestContext::class);
            if ($context instanceof RequestContext) {
                return $context->requestId();
            }
        } catch (Throwable) {
            // RequestContext can be unavailable for failures before HTTP middleware executes.
        }

        return (new CorrelationIdFactory())->normalize((string) $request->header('x-request-id', ''));
    }
}
