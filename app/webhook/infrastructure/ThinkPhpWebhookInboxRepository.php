<?php

declare(strict_types=1);

namespace app\webhook\infrastructure;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\webhook\contract\WebhookInboxRepository;
use app\webhook\domain\WechatWebhookEvent;
use app\webhook\domain\WebhookInboxResult;
use DateTimeImmutable;
use Throwable;
use think\facade\Db;

final class ThinkPhpWebhookInboxRepository implements WebhookInboxRepository
{
    public function receive(WechatWebhookEvent $event, DateTimeImmutable $receivedAt): WebhookInboxResult
    {
        return Db::transaction(function () use ($event, $receivedAt): WebhookInboxResult {
            $where = [
                'provider_type' => $event->providerType(),
                'provider_account_id' => $event->providerAccountId(),
                'provider_event_key' => $event->providerEventKey(),
            ];

            $existing = Db::table('webhook_inbox')->where($where)->lock(true)->find();
            if ($existing !== null) {
                return $this->existingResult((array) $existing, $event->rawBodyHash());
            }

            $id = bin2hex(random_bytes(16));
            try {
                Db::table('webhook_inbox')->insert([
                    'id' => $id,
                    'tenant_id' => $event->tenantId(),
                    'provider_type' => $event->providerType(),
                    'provider_account_id' => $event->providerAccountId(),
                    'provider_event_key' => $event->providerEventKey(),
                    'raw_body_hash' => $event->rawBodyHash(),
                    'received_at' => $this->sqlDate($receivedAt),
                ]);
            } catch (Throwable $e) {
                $winner = Db::table('webhook_inbox')->where($where)->lock(true)->find();
                if ($winner !== null) {
                    return $this->existingResult((array) $winner, $event->rawBodyHash());
                }
                throw $e;
            }

            return WebhookInboxResult::accepted($id);
        });
    }

    public function markDispatched(string $inboxId, DateTimeImmutable $dispatchedAt): void
    {
        Db::table('webhook_inbox')->where('id', $inboxId)->update([
            'dispatched_at' => $this->sqlDate($dispatchedAt),
        ]);
    }

    private function existingResult(array $row, string $rawBodyHash): WebhookInboxResult
    {
        $stored = (string) ($row['raw_body_hash'] ?? '');
        if (!hash_equals($stored, $rawBodyHash)) {
            throw new AppException(ErrorCode::CONFLICT, 'Webhook replay payload conflicts with prior event.', 409);
        }
        return WebhookInboxResult::replayed((string) $row['id']);
    }

    private function sqlDate(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
