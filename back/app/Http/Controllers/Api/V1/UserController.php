<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Users\Enums\RoleCode;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\UserService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\ChangeUserRoleRequest;
use App\Http\Requests\Users\ChangeUserSectorRequest;
use App\Http\Requests\Users\ChangeUserStatusRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()
            ->with('person', 'role', 'sector')
            ->when($request->query('search'), function ($query, string $search) {
                $query->where(fn ($q) => $q
                    ->whereILike('email', "%{$search}%")
                    ->orWhereHas('person', fn ($p) => $p->whereILike('full_name', "%{$search}%")));
            })
            ->when($request->query('role'), fn ($query, string $role) => $query->whereHas('role', fn ($r) => $r->where('code', $role)))
            ->when($request->query('status'), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->query('sector_id'), fn ($query, $sectorId) => $query->where('sector_id', $sectorId))
            ->orderBy('email')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): UserResource
    {
        return UserResource::make($this->users->create($request->validated()));
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return UserResource::make($user->load('person', 'role', 'sector'));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        return UserResource::make($this->users->update($user, $request->validated()));
    }

    public function updateRole(ChangeUserRoleRequest $request, User $user): UserResource
    {
        return UserResource::make($this->users->changeRole(
            $user,
            RoleCode::from($request->validated('role')),
            $request->validated('sector_id'),
        ));
    }

    public function updateSector(ChangeUserSectorRequest $request, User $user): UserResource
    {
        return UserResource::make($this->users->changeSector($user, (int) $request->validated('sector_id')));
    }

    public function updateStatus(ChangeUserStatusRequest $request, User $user): UserResource
    {
        return UserResource::make($this->users->changeStatus($user, UserStatus::from($request->validated('status'))));
    }
}
