<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\security\WechatComponentEnvelopeParser;

$parser = new WechatComponentEnvelopeParser();
$outer = $parser->parseOuter('<xml><AppId>wx-diagnostic</AppId><Encrypt><![CDATA[cipher-value]]></Encrypt></xml>');
expectSame('cipher-value', $outer->encryptedPayload(), 'outer parser extracts only encrypted payload');
expectSame('wx-diagnostic', $outer->outerAppId(), 'outer AppId is diagnostic only');

$inner = $parser->parseInnerTicket('<xml><AppId><![CDATA[wx-component-1]]></AppId><InfoType><![CDATA[component_verify_ticket]]></InfoType><ComponentVerifyTicket><![CDATA[ticket-1]]></ComponentVerifyTicket></xml>');
expectSame('wx-component-1', $inner['appId'], 'inner parser extracts AppId');
expectSame('component_verify_ticket', $inner['infoType'], 'inner parser extracts InfoType');
expectSame('ticket-1', $inner['ticket'], 'inner parser extracts verify ticket');

foreach ([
    '<!DOCTYPE xml [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><xml><Encrypt>&xxe;</Encrypt></xml>',
    '<!ENTITY xxe "bad"><xml><Encrypt>cipher</Encrypt></xml>',
    '<xml><Encrypt></xml>',
    '<xml><AppId>wx</AppId></xml>',
    str_repeat('x', 131073),
] as $badXml) {
    try {
        $parser->parseOuter($badXml);
        throw new RuntimeException('unsafe/malformed outer XML must be rejected');
    } catch (AppException $e) {
        expectSame(ErrorCode::INVALID_ARGUMENT, $e->errorCode(), 'unsafe outer XML maps to INVALID_ARGUMENT');
        expectSame(400, $e->httpStatus(), 'unsafe outer XML maps to 400');
    }
}

foreach ([
    '<!DOCTYPE xml><xml><AppId>wx</AppId></xml>',
    '<xml><AppId>wx</AppId><InfoType>component_verify_ticket</InfoType></xml>',
] as $badInner) {
    try {
        $parser->parseInnerTicket($badInner);
        throw new RuntimeException('unsafe/incomplete inner XML must be rejected');
    } catch (AppException $e) {
        expectSame(ErrorCode::INVALID_ARGUMENT, $e->errorCode(), 'bad inner XML maps to INVALID_ARGUMENT');
        expectSame(400, $e->httpStatus(), 'bad inner XML maps to 400');
    }
}
