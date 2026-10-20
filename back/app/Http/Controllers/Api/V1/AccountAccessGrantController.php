<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\AccountAccessGrantService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccountAccessGrantResource;
use App\Models\AccountAccessGrant;
use App\Models\EventIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Liberações de acesso (Complemento 11.2). Criadas pela fila Organização do Centro Even3.
 */
class AccountAccessGrantController extends Controller
{
    public function revoke(Request $request, AccountAccessGrant $grant, AccountAccessGrantService $grants): AccountAccessGrantResource
    {
        Gate::authorize('grantAccess', EventIntegration::class);

        return AccountAccessGrantResource::make($grants->revoke($grant, $request->user())->load('role', 'person'));
    }
}
