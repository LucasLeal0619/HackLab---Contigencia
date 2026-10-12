<?php

namespace App\Models;

use Database\Factories\EventDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventDay extends Model
{
    /** @use HasFactory<EventDayFactory> */
    use HasFactory;

    protected $fillable = [
        'event_id',
        'day_number',
        'date',
        'label',
        'start_time',
        'end_time',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'day_number' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Rótulo histórico na auditoria (snapshot).
     */
    public function auditLabel(): ?string
    {
        return trim(($this->label ?? "Dia {$this->day_number}").' · '.$this->date?->toDateString(), ' ·');
    }
}
