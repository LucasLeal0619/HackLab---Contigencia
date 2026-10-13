<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\AccountActivationService;
use App\Domain\Auth\PasswordRecoveryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CompleteActivationRequest;
use App\Http\Requests\Auth\RecoveryEmailRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;

/**
 * Primeiro acesso e recuperação de senha (Complemento 11.1). Rotas públicas.
 *
 * Os pedidos respondem sempre 202 com a mesma mensagem: não revelam se o e-mail existe, se é elegível
 * ou se já tem conta. Nenhuma resposta contém token.
 */
class AccountRecoveryController extends Controller
{
    public const ACTIVATION_SENT = 'Se este e-mail puder acessar o HackLab, enviaremos as instruções.';

    public const RESET_SENT = 'Se houver uma conta com este e-mail, enviaremos um link para redefinir a senha.';

    public function __construct(
        private readonly AccountActivationService $activation,
        private readonly PasswordRecoveryService $recovery,
    ) {}

    public function requestActivation(RecoveryEmailRequest $request): JsonResponse
    {
        $this->activation->request($request->validated('email'));

        return response()->json(['message' => self::ACTIVATION_SENT], 202);
    }

    public function completeActivation(CompleteActivationRequest $request): JsonResponse
    {
        $this->activation->complete($request->validated('token'), $request->validated('password'));

        return response()->json(['message' => 'Acesso ativado. Agora você pode entrar no HackLab.']);
    }

    public function forgotPassword(RecoveryEmailRequest $request): JsonResponse
    {
        $this->recovery->forgot($request->validated('email'));

        return response()->json(['message' => self::RESET_SENT], 202);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->recovery->reset($request->validated('email'), $request->validated('token'), $request->validated('password'));

        return response()->json(['message' => 'Senha redefinida. Entre com a nova senha.']);
    }
}
