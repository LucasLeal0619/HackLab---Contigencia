<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Companies\CompanyService;
use App\Domain\Companies\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\ChangeCompanyStatusRequest;
use App\Http\Requests\Companies\CompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CompanyController extends Controller
{
    public function __construct(private readonly CompanyService $companies) {}

    /**
     * Filtros: status, type, search (nome, razão social ou documento).
     */
    public function index(Request $request, Event $event): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Company::class);

        $companies = Company::query()
            ->where('event_id', $event->id)
            ->withCount(['activeRepresentatives', 'challenges'])
            ->when($request->query('status'), fn ($q, string $status) => $q->where('status', $status))
            ->when($request->query('type'), fn ($q, string $type) => $q->where('type', $type))
            ->when($request->query('search'), function ($q, string $search) {
                $document = Company::normalizeDocument($search);

                $q->where(fn ($w) => $w
                    ->whereILike('name', "%{$search}%")
                    ->orWhereILike('legal_name', "%{$search}%")
                    ->when($document, fn ($d) => $d->orWhere('document', 'like', "%{$document}%")));
            })
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 50), 100));

        return CompanyResource::collection($companies);
    }

    public function store(CompanyRequest $request, Event $event): CompanyResource
    {
        return CompanyResource::make($this->companies->create($event, $request->validated()));
    }

    /**
     * Empresa com representantes e resumo dos desafios.
     */
    public function show(Company $company): CompanyResource
    {
        Gate::authorize('view', $company);

        return CompanyResource::make($company->load([
            'representatives' => fn ($q) => $q->with('person')->orderByDesc('active')->orderByDesc('is_primary')->orderBy('id'),
            'challenges' => fn ($q) => $q->with('team')->orderBy('title'),
        ])->loadCount(['activeRepresentatives', 'challenges']));
    }

    public function update(CompanyRequest $request, Company $company): CompanyResource
    {
        return CompanyResource::make($this->companies->update($company, $request->validated()));
    }

    public function updateStatus(ChangeCompanyStatusRequest $request, Company $company): CompanyResource
    {
        return CompanyResource::make($this->companies->changeStatus($company, CompanyStatus::from($request->validated('status'))));
    }
}
