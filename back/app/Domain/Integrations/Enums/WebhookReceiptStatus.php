<?php

namespace App\Domain\Integrations\Enums;

enum WebhookReceiptStatus: string
{
    case Received = 'RECEIVED';
    case Processed = 'PROCESSED';
    case Ignored = 'IGNORED';
    case Failed = 'FAILED';
}
