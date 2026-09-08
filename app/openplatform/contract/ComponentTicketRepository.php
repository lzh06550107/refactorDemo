<?php

declare(strict_types=1);

namespace app\openplatform\contract;

use app\openplatform\domain\ComponentTicketWriteResult;
use app\openplatform\domain\ComponentVerifyTicket;

interface ComponentTicketRepository
{
    public function current(string $componentPlatformId): ?ComponentVerifyTicket;
    public function accept(ComponentVerifyTicket $ticket, string $replayKey, string $payloadHash): ComponentTicketWriteResult;
}
