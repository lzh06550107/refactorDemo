<?php

declare(strict_types=1);

namespace modules\openplatform\infrastructure;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\contract\ComponentTicketRepository;
use modules\openplatform\contract\OpenPlatformSecretCipher;
use modules\openplatform\domain\ComponentTicketWriteResult;
use modules\openplatform\domain\ComponentVerifyTicket;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;
use Throwable;

final readonly class ThinkPhpComponentTicketRepository implements ComponentTicketRepository
{
    public function __construct(private OpenPlatformSecretCipher $cipher)
    {
    }

    public function current(string $componentPlatformId): ?ComponentVerifyTicket
    {
        $row = Db::table('component_verify_tickets')
            ->where('component_platform_id', $componentPlatformId)
            ->find();
        if (!is_array($row)) {
            return null;
        }

        $ticket = $this->cipher->reveal(
            (string) $row['ticket_ciphertext'],
            (string) $row['ticket_key_version'],
        );

        return $this->mapTicket($row, $ticket);
    }

    public function accept(ComponentVerifyTicket $ticket, string $replayKey, string $payloadHash): ComponentTicketWriteResult
    {
        // Crypto is deliberately completed before the short authoritative DB transaction.
        $protected = $this->cipher->protect($ticket->ticket());

        return Db::transaction(function () use ($ticket, $replayKey, $payloadHash, $protected): ComponentTicketWriteResult {
            $platformId = $ticket->componentPlatformId();
            $platform = Db::table('component_platforms')->where('id', $platformId)->lock(true)->find();
            if (!is_array($platform)) {
                throw new AppException(ErrorCode::NOT_FOUND, 'OpenPlatform component platform not found.', 404);
            }

            // The unique inbox insert is the authoritative replay gate. Concurrent identical
            // deliveries converge here; a duplicate-key race is resolved by a locked reread.
            try {
                Db::table('component_ticket_inbox')->insert([
                    'id' => bin2hex(random_bytes(16)),
                    'component_platform_id' => $platformId,
                    'replay_key' => $replayKey,
                    'payload_hash' => $payloadHash,
                    'source_timestamp' => $this->sqlDate($ticket->sourceTimestamp()),
                    'received_at' => $this->sqlDate($ticket->receivedAt()),
                    'result' => 'pending',
                ]);
            } catch (Throwable $insertFailure) {
                $replay = Db::table('component_ticket_inbox')
                    ->where('component_platform_id', $platformId)
                    ->where('replay_key', $replayKey)
                    ->lock(true)
                    ->find();
                if (!is_array($replay)) {
                    throw $insertFailure;
                }
                if (!hash_equals((string) $replay['payload_hash'], $payloadHash)) {
                    throw new AppException(ErrorCode::CONFLICT, 'OpenPlatform callback replay payload mismatch.', 409);
                }

                $current = Db::table('component_verify_tickets')
                    ->where('component_platform_id', $platformId)
                    ->lock(true)
                    ->find();

                return new ComponentTicketWriteResult(
                    true,
                    ((string) ($replay['result'] ?? '')) === 'stale',
                    is_array($current) ? (int) $current['version'] : 0,
                );
            }

            $current = Db::table('component_verify_tickets')
                ->where('component_platform_id', $platformId)
                ->lock(true)
                ->find();

            $duplicate = false;
            $stale = false;
            $result = 'accepted';
            $version = 1;

            if (!is_array($current)) {
                Db::table('component_verify_tickets')->insert($this->ticketRow($ticket, $protected, $version));
            } else {
                $version = (int) $current['version'];
                $currentSource = $this->date((string) $current['source_timestamp']);

                if ($ticket->sourceTimestamp() > $currentSource) {
                    $version++;
                    Db::table('component_verify_tickets')
                        ->where('component_platform_id', $platformId)
                        ->update($this->ticketRow($ticket, $protected, $version, false));
                } elseif ($ticket->sourceTimestamp() == $currentSource) {
                    if (!hash_equals((string) $current['ticket_hash'], $ticket->ticketHash())) {
                        // The transaction rolls back the just-created inbox row as well.
                        throw new AppException(ErrorCode::CONFLICT, 'OpenPlatform verify ticket timestamp payload mismatch.', 409);
                    }
                    $duplicate = true;
                    $result = 'duplicate';
                } else {
                    $stale = true;
                    $result = 'stale';
                }
            }

            Db::table('component_ticket_inbox')
                ->where('component_platform_id', $platformId)
                ->where('replay_key', $replayKey)
                ->update(['result' => $result]);

            return new ComponentTicketWriteResult($duplicate, $stale, $version);
        });
    }

    /** @param array{ciphertext:string,keyVersion:string} $protected */
    private function ticketRow(ComponentVerifyTicket $ticket, array $protected, int $version, bool $includeId = true): array
    {
        $row = [
            'ticket_ciphertext' => $protected['ciphertext'],
            'ticket_key_version' => $protected['keyVersion'],
            'ticket_hash' => $ticket->ticketHash(),
            'source_timestamp' => $this->sqlDate($ticket->sourceTimestamp()),
            'received_at' => $this->sqlDate($ticket->receivedAt()),
            'version' => $version,
        ];
        if ($includeId) {
            $row = ['component_platform_id' => $ticket->componentPlatformId()] + $row;
        }
        return $row;
    }

    private function mapTicket(array $row, string $ticket): ComponentVerifyTicket
    {
        return new ComponentVerifyTicket(
            (string) $row['component_platform_id'],
            $ticket,
            (string) $row['ticket_hash'],
            $this->date((string) $row['source_timestamp']),
            $this->date((string) $row['received_at']),
            (int) $row['version'],
        );
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function sqlDate(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
