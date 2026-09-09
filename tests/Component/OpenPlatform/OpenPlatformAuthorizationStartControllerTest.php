<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$file = $root . '/app/api/controller/V1/OpenPlatformAuthorizationStartController.php';
expectTrue(is_file($file), 'authorization start controller exists');

$source = (string) file_get_contents($file);
expectTrue(str_contains($source, 'AuthorizationIntentMode'), 'controller parses an explicit authorization intent mode');
expectTrue(str_contains($source, 'OpenPlatformAdminGuard'), 'controller uses the trusted admin permission guard');
expectTrue(str_contains($source, '$this->context->tenantId()'), 'controller sources Tenant from trusted RequestContext');
expectTrue(!str_contains($source, "(string) $request->param('tenant_id', '')"), 'controller never sources Tenant ownership from request body');
expectTrue(str_contains($source, "request->param('tenantId'"), 'camelCase Tenant assertion remains compatible');
expectTrue(str_contains($source, "request->param('tenant_id'"), 'snake_case Tenant assertion remains compatible');
expectTrue(str_contains($source, 'OpenPlatformPermission::START'), 'start permission is required');
expectTrue(str_contains($source, 'OpenPlatformPermission::BIND'), 'existing Account mode requires bind permission');
expectTrue(str_contains($source, 'OpenPlatformPermission::PROVISION'), 'auto provisioning mode requires provision permission');
expectTrue(str_contains($source, 'ErrorCode::FORBIDDEN'), 'mismatched compatibility Tenant assertion is rejected');
