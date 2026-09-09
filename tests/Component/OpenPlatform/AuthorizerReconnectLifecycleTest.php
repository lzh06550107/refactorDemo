<?php

declare(strict_types=1);

use app\account\domain\AccountStatus;
use app\account\domain\AccountType;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\application\AuthorizerConnectionService;
use app\openplatform\contract\AuthorizerAccountStateReader;
use app\openplatform\contract\AuthorizerConnectionStore;
use app\openplatform\domain\AuthorizerAccountOwnership;

$now = new DateTimeImmutable('2026-09-09T10:00:00Z');
$ownership = new AuthorizerAccountOwnership(
    'platform-1',
    'wx-authorizer-1',
    'tenant-1',
    'account-1',
    AccountType::WECHAT_MINI_PROGRAM,
    $now->modify('-2 days'),
    $now->modify('-1 day'),
);

$connections = new class implements AuthorizerConnectionStore {
    public array $enabled = [];
    public array $disabled = [];
    public function enableExisting(AuthorizerAccountOwnership $ownership, DateTimeImmutable $now): void
    {
        $this->enabled[] = [$ownership->accountId(), $ownership->accountType(), $now];
    }
    public function disable(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $now): void
    {
        $this->disabled[] = [$componentPlatformId, $authorizerAppId, $now];
    }
};
$accounts = new class implements AuthorizerAccountStateReader {
    public AccountStatus $status = AccountStatus::ACTIVE;
    public int $reads = 0;
    public function status(AuthorizerAccountOwnership $ownership): ?AccountStatus
    {
        $this->reads++;
        return $this->status;
    }
};
$service = new AuthorizerConnectionService($connections, $accounts);

$service->reconnect($ownership, $now);
expectSame(1, count($connections->enabled), 'ACTIVE owned Account re-enables provider connection');
expectSame(AccountStatus::ACTIVE, $accounts->status, 'reconnect never mutates ACTIVE Account business state');

$accounts->status = AccountStatus::SUSPENDED;
$service->reconnect($ownership, $now->modify('+1 second'));
expectSame(2, count($connections->enabled), 'SUSPENDED Account may restore provider connection projection');
expectSame(AccountStatus::SUSPENDED, $accounts->status, 'SUSPENDED Account remains suspended after provider reconnect');

$accounts->status = AccountStatus::DELETED;
$enabledBeforeDeleted = count($connections->enabled);
try {
    $service->reconnect($ownership, $now->modify('+2 seconds'));
    throw new RuntimeException('DELETED Account must not be automatically reconnected');
} catch (AppException $e) {
    expectSame(ErrorCode::CONFLICT, $e->errorCode(), 'DELETED Account reconnect requires manual conflict resolution');
    expectSame(409, $e->httpStatus(), 'DELETED Account reconnect maps to HTTP 409');
}
expectSame($enabledBeforeDeleted, count($connections->enabled), 'DELETED Account provider binding is not re-enabled');

$service->disconnect('platform-1', 'wx-authorizer-1', $now->modify('+3 seconds'));
expectSame(1, count($connections->disabled), 'remote unauthorized disables only the connection projection');
expectSame(['platform-1', 'wx-authorizer-1'], array_slice($connections->disabled[0], 0, 2), 'disconnect preserves canonical authorizer identity');

$root = dirname(__DIR__, 3);
$readerPath = $root . '/app/openplatform/infrastructure/ThinkPhpAuthorizerAccountStateReader.php';
expectTrue(is_file($readerPath), 'ThinkPHP Account-state reader must exist for production reconnect policy');
$readerSource = (string) file_get_contents($readerPath);
expectTrue(str_contains($readerSource, "Db::table('accounts')"), 'Account-state reader uses canonical accounts table');
expectTrue(str_contains($readerSource, "'tenant_id'"), 'Account-state read is Tenant-scoped');
expectTrue(!str_contains($readerSource, '->update(') && !str_contains($readerSource, '->insert('), 'Account-state reader is strictly read-only');
