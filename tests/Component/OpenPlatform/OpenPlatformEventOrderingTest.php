<?php

declare(strict_types=1);

$eventServicePath = __DIR__ . '/../../../app/openplatform/application/AuthorizationEventService.php';
$ingressPath = __DIR__ . '/../../../app/openplatform/application/OpenPlatformEventService.php';

expectTrue(is_file($eventServicePath), 'Task 5 authorization lifecycle event service must exist before GREEN');
$eventSource = is_file($eventServicePath) ? (string) file_get_contents($eventServicePath) : '';
$ingressSource = (string) file_get_contents($ingressPath);

expectTrue(str_contains($eventSource, 'sourceTimestamp()'), 'lifecycle service must use authenticated source timestamp for ordering');
expectTrue(str_contains($eventSource, 'ErrorCode::CONFLICT'), 'same-timestamp conflicting lifecycle state must fail closed with CONFLICT');
expectTrue(str_contains($eventSource, 'markUnauthorized'), 'unauthorized lifecycle event must use repository atomic unauthorized transition');

$acceptPos = strpos($ingressSource, 'eventInbox->accept');
$dispatchPos = strpos($ingressSource, 'authorizationEvents->handle');
expectTrue($acceptPos !== false && $dispatchPos !== false && $acceptPos < $dispatchPos, 'authorizer lifecycle dispatch must occur only after authoritative replay inbox decision');
expectTrue(
    str_contains($ingressSource, 'if (!$accepted)') || str_contains($ingressSource, 'if ($accepted === false)'),
    'exact lifecycle replay must return before a second authorizer mutation',
);
