<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

use modules\openplatform\domain\ComponentTicketWriteResult;
use modules\openplatform\domain\ComponentVerifyTicket;

interface ComponentTicketRepository
{
    public function current(string $componentPlatformId): ?ComponentVerifyTicket;
    public function accept(ComponentVerifyTicket $ticket, string $replayKey, string $payloadHash): ComponentTicketWriteResult;
}
