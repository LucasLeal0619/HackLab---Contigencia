<?php

namespace App\Models;

use App\Domain\Users\Enums\PermissionCode;
use App\Domain\Users\Enums\RoleCode;
use App\Domain\Users\Enums\UserStatus;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Conta de acesso ao HackLab. Sempre vinculada a uma Person.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'person_id',
        'role_id',
        'sector_id',
        'email',
        'password',
        'status',
        'email_verified_at',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'sector_id' => 'integer',
        ];
    }

    /**
     * Link de redefinição aponta para o frontend (FRONTEND_URL), não para uma rota do Laravel.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /**
     * Escopo setorial: quem tem setor (Gestor, Editor) só alcança o próprio setor;
     * quem não tem setor tem alcance global. O que pode fazer nesse alcance vem das permissões.
     */
    public function canReachSector(?int $sectorId): bool
    {
        return $this->sector_id === null || $this->sector_id === $sectorId;
    }

    public function reachesAllSectors(): bool
    {
        return $this->sector_id === null;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function hasRole(RoleCode $code): bool
    {
        return $this->role?->code === $code;
    }

    public function hasPermission(PermissionCode $permission): bool
    {
        return in_array($permission->value, $this->permissionCodes(), true);
    }

    /**
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        $this->loadMissing('role.permissions');

        return $this->role?->permissions->pluck('code')->sort()->values()->all() ?? [];
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    /**
     * Rótulo histórico na auditoria (snapshot): nome da pessoa e e-mail da conta.
     */
    public function auditLabel(): ?string
    {
        $name = $this->person?->full_name;

        return $name === null ? $this->email : "{$name} ({$this->email})";
    }
}
