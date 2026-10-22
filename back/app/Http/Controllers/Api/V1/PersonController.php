<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\People\PersonService;
use App\Http\Controllers\Controller;
use App\Http\Requests\People\StorePersonRequest;
use App\Http\Requests\People\UpdatePersonRequest;
use App\Http\Resources\PersonResource;
use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PersonController extends Controller
{
    public function __construct(private readonly PersonService $people) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Person::class);

        $people = Person::query()
            ->with('user')
            ->when($request->query('search'), fn ($query, string $search) => $query->where(fn ($q) => $q
                ->whereILike('full_name', "%{$search}%")
                ->orWhereILike('email', "%{$search}%")))
            ->orderBy('full_name')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return PersonResource::collection($people);
    }

    public function store(StorePersonRequest $request): PersonResource
    {
        return PersonResource::make($this->people->create($request->validated())->load('user'));
    }

    public function show(Person $person): PersonResource
    {
        Gate::authorize('view', $person);

        return PersonResource::make($person->load('user'));
    }

    public function update(UpdatePersonRequest $request, Person $person): PersonResource
    {
        return PersonResource::make($this->people->update($person, $request->validated())->load('user'));
    }
}
