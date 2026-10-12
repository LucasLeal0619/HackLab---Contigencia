<?php

namespace App\Models;

use App\Domain\Evaluations\Enums\EvaluationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Avaliação técnica de um jurado para uma equipe atribuída. Notas em evaluation_scores.
 * Resultado (percentual) é calculado na consulta, nunca persistido.
 */
class Evaluation extends Model
{
    protected $fillable = [
        'event_id',
        'juror_id',
        'team_id',
        'status',
        'comments',
    ];

    protected function casts(): array
    {
        return [
            'status' => EvaluationStatus::class,
            'submitted_at' => 'datetime',
            'revision_requested_at' => 'datetime',
            'juror_id' => 'integer',
            'team_id' => 'integer',
        ];
    }

    public function isSubmitted(): bool
    {
        return $this->status === EvaluationStatus::Submitted;
    }

    public function juror(): BelongsTo
    {
        return $this->belongsTo(Juror::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(EvaluationScore::class);
    }

    public function revisionRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revision_requested_by_user_id');
    }

    public function assignment(): ?JurorTeamAssignment
    {
        return JurorTeamAssignment::query()->where('juror_id', $this->juror_id)->where('team_id', $this->team_id)->first();
    }

    /**
     * Rótulo histórico na auditoria (snapshot): só a equipe avaliada (nada de notas).
     */
    public function auditLabel(): ?string
    {
        return $this->team === null ? null : "Avaliação · {$this->team->name}";
    }
}
