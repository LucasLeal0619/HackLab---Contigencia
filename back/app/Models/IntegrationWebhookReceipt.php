<?php

namespace App\Models;

use App\Domain\Integrations\Enums\WebhookReceiptStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Recibo de um disparo de webhook (idempotência pelo id do disparo). Guarda só o hash do corpo, nunca o
 * payload completo nem o token de segurança.
 */
class IntegrationWebhookReceipt extends Model
{
    protected $fillable = [
        'event_integration_id',
        'external_delivery_id',
        'event_type_id',
        'event_type_name',
        'payload_hash',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WebhookReceiptStatus::class,
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'event_integration_id' => 'integer',
        ];
    }
}
