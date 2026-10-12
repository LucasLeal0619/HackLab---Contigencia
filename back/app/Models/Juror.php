<?php

namespace App\Models;

use App\Domain\Jurors\Enums\AssignmentStatus;
use App\Domain\Jurors\Enums\JurorStatus;
use App\Domain\Users\Enums\PermissionCode;
use App\Domain\Users\Enums\UserStatus;
use Database\Factories\JurorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Papel de jurado de uma Person no evento. Não é conta de acesso nem representante de empresa.
 * A conta (se existir) é o User da mesma Person: users.person_id = jurors.person_id.
 */
class Juror extends Model
{
    /** @use HasFactory<JurorFactory> */
    use HasFactory;

    protected $fillable = [
        'event_id',
        'person_id',
        'company_id',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => JurorStatus::class,
            'person_id' => 'integer',
            'company_id' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === JurorStatus::Active;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Conta de acesso derivada da mesma Person (pode não existir).
     */
    public function account(): HasOne
    {
        return $this->hasOne(User::class, 'person_id', 'person_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(JurorTeamAssignment::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->hasMany(JurorTeamAssignment::class)->where('status', AssignmentStatus::Active->value);
    }

    /**
     * Pronto para avaliar pelo sistema: jurado ativo + conta ativa da mesma Person com permissão de avaliar.
     */
    public function accessReady(): bool
    {
        $account = $this->account;

        return $this->isActive()
            && $account !== null
            && $account->status === UserStatus::Active
            && $account->hasPermission(PermissionCode::EvaluationsOwn);
    }

    /**
     * Rótulo histórico na auditoria (snapshot): o nome da pessoa.
     */
    public function auditLabel(): ?string
    {
        return $this->person?->full_name;
    }
}
