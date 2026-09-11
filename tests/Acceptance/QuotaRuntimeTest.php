<?php

declare(strict_types=1);

use modules\account\domain\AccountType;
use modules\quota\application\QuotaService;
use modules\quota\domain\QuotaGrant;
use modules\quota\domain\QuotaGrantSource;
use modules\quota\domain\QuotaResource;
use think\App;

function acceptanceQuotaRuntimeTest(AcceptanceRuntime $runtime): void
{
    $db = $runtime->db();
    $tenantId = 'accept-quota-tenant';
    $db->exec("INSERT INTO tenants (id, name, status) VALUES ('accept-quota-tenant', 'V1 Quota Acceptance Tenant', 'active')");

    require_once $runtime->config->root . '/vendor/autoload.php';
    $app = (new App($runtime->config->root))->initialize();
    $quota = $app->make(QuotaService::class);
    acceptanceAssert($quota instanceof QuotaService, 'ThinkPHP container must resolve the production QuotaService.');

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $official = QuotaResource::accountCreate(AccountType::OFFICIAL_ACCOUNT);
    $miniProgram = QuotaResource::accountCreate(AccountType::WECHAT_MINI_PROGRAM);

    $officialGrant = new QuotaGrant(
        'accept-quota-grant-official',
        $tenantId,
        $official,
        QuotaGrantSource::PURCHASE,
        2,
    );
    $grant = $quota->grant($officialGrant, 'acceptance:quota:grant:official', $now);
    $grantReplay = $quota->grant($officialGrant, 'acceptance:quota:grant:official', $now);
    acceptanceAssert($grant->id() === $grantReplay->id(), 'Quota grant replay must return the original ledger entry.');

    $miniGrant = new QuotaGrant(
        'accept-quota-grant-mini-program',
        $tenantId,
        $miniProgram,
        QuotaGrantSource::PURCHASE,
        2,
    );
    $quota->grant($miniGrant, 'acceptance:quota:grant:mini-program', $now);

    $beforeConsume = $quota->availability($tenantId, $official, $now);
    acceptanceAssert($beforeConsume->available() === 2, 'Official-account quota must expose two available units after grant.');
    acceptanceAssert($beforeConsume->purchaseRemaining() === 2, 'Purchase quota projection must expose two remaining units.');

    $consume = $quota->consume($tenantId, $official, 1, 'acceptance:quota:consume:official', $now);
    $consumeReplay = $quota->consume($tenantId, $official, 1, 'acceptance:quota:consume:official', $now);
    acceptanceAssert($consume->id() === $consumeReplay->id(), 'Quota consume replay must return the original ledger entry.');
    acceptanceAssert($consume->purchaseCharge() === 1, 'Purchase quota must be charged first for account creation.');

    $afterConsume = $quota->availability($tenantId, $official, $now);
    acceptanceAssert($afterConsume->available() === 1, 'Quota availability must decrease after consume.');
    acceptanceAssert($afterConsume->purchaseRemaining() === 1, 'Purchase quota projection must decrease after consume.');

    $release = $quota->release(
        $tenantId,
        $official,
        $consume->id(),
        1,
        'acceptance:quota:release:official',
        $now,
    );
    $releaseReplay = $quota->release(
        $tenantId,
        $official,
        $consume->id(),
        1,
        'acceptance:quota:release:official',
        $now,
    );
    acceptanceAssert($release->id() === $releaseReplay->id(), 'Quota release replay must return the original ledger entry.');
    acceptanceAssert($release->referenceEntryId() === $consume->id(), 'Quota release must reference the original consume entry.');

    $afterRelease = $quota->availability($tenantId, $official, $now);
    acceptanceAssert($afterRelease->available() === 2, 'Quota release must restore account-creation availability.');
    acceptanceAssert($afterRelease->purchaseRemaining() === 2, 'Quota release must restore purchase quota projection.');

    $balance = $db->prepare(
        'SELECT granted_amount, consumed_amount, purchase_granted_amount, purchase_consumed_amount FROM quota_balances WHERE tenant_id = ? AND resource_key = ?',
    );
    $balance->execute([$tenantId, $official->key()]);
    $officialBalance = $balance->fetch(PDO::FETCH_ASSOC);
    acceptanceAssert(is_array($officialBalance), 'Official-account quota balance projection must exist.');
    acceptanceAssert((int) $officialBalance['granted_amount'] === 2, 'Official-account granted projection must equal two.');
    acceptanceAssert((int) $officialBalance['consumed_amount'] === 0, 'Released official-account quota must have zero net consumed amount.');
    acceptanceAssert((int) $officialBalance['purchase_granted_amount'] === 2, 'Purchase-granted projection must equal two.');
    acceptanceAssert((int) $officialBalance['purchase_consumed_amount'] === 0, 'Released purchase quota must have zero net consumed amount.');

    $ledgerCount = $db->prepare(
        'SELECT COUNT(*) FROM quota_ledger_entries WHERE tenant_id = ? AND resource_key = ?',
    );
    $ledgerCount->execute([$tenantId, $official->key()]);
    acceptanceAssert((int) $ledgerCount->fetchColumn() === 3, 'Official-account ledger must contain one grant, one consume and one release only.');

    $ledgerCount->execute([$tenantId, $miniProgram->key()]);
    acceptanceAssert((int) $ledgerCount->fetchColumn() === 1, 'Mini-program ledger must contain one grant entry.');
}
