<?php

namespace App\Models;

use App\Domain\Participants\Enums\ParticipantStatus;
use Database\Factories\ParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Aluno que participa do Hackathon desenvolvendo solução. Não é sinônimo de inscrito.
 * Dados de identidade ficam em Person.
 */
class Participant extends Model
{
    /** @use HasFactory<ParticipantFactory> */
    use HasFactory;

    protected $fillable = [
        'event_id',
        'person_id',
        'class_id',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ParticipantStatus::class,
            'class_id' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TeamMember::class)->latest('id');
    }

    public function activeMembership(): HasOne
    {
        return $this->hasOne(TeamMember::class)->where('active', true);
    }

    /**
     * Rótulo histórico na auditoria (snapshot): o nome da pessoa.
     */
    public function auditLabel(): ?string
    {
        return $this->person?->full_name;
    }
}
