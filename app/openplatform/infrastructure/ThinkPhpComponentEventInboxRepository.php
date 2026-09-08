<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\ComponentEventInboxRepository;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;
use Throwable;

final class ThinkPhpComponentEventInboxRepository implements ComponentEventInboxRepository
{
    public function accept(
        string $componentPlatformId,
        string $replayKey,
        string $payloadHash,
        string $infoType,
        DateTimeImmutable $sourceTimestamp,
        DateTimeImmutable $receivedAt,
    ): bool {
        return Db::transaction(function () use ($componentPlatformId, $replayKey, $payloadHash, $infoType, $sourceTimestamp, $receivedAt): bool {
            // The unique insert is the authoritative replay gate. Platform existence/enablement
            // was already authenticated before this repository boundary; the FK remains a final guard.
            try {
                Db::table('component_ticket_inbox')->insert([
                    'id' => bin2hex(random_bytes(16)),
                    'component_platform_id' => $componentPlatformId,
                    'replay_key' => $replayKey,
                    'payload_hash' => $payloadHash,
                    'source_timestamp' => $this->sqlDate($sourceTimestamp),
                    'received_at' => $this->sqlDate($receivedAt),
                    'result' => 'event:' . $infoType,
                ]);
                return true;
            } catch (Throwable $insertFailure) {
                $row = Db::table('component_ticket_inbox')
                    ->where('component_platform_id', $componentPlatformId)
                    ->where('replay_key', $replayKey)
                    ->lock(true)
                    ->find();
                if (!is_array($row)) {
                    throw $insertFailure;
                }
                if (!hash_equals((string) $row['payload_hash'], $payloadHash)) {
                    throw new AppException(ErrorCode::CONFLICT, 'OpenPlatform callback replay payload mismatch.', 409);
                }
                return false;
            }
        });
    }

    private function sqlDate(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
