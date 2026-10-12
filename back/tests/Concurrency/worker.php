<?php

/*
 * Worker dos testes de concorrência real (Tests\Feature\Voting\RealConcurrencyTest).
 *
 * Cada execução é um processo PHP separado, com a própria conexão ao PostgreSQL (hacklab_test),
 * que chama o mesmo Service usado pela API. Todos os workers esperam numa barreira (advisory lock
 * segurado pelo teste) e disparam juntos quando ela é liberada.
 *
 * Uso: php tests/Concurrency/worker.php <operação> '<json>'
 * Saída: uma linha JSON {"ok": bool, "status": int, ...}.
 */

use App\Domain\Evaluations\EvaluationService;
use App\Domain\Results\ResultPublicationService;
use App\Domain\Voting\PublicVoteService;
use App\Domain\Voting\VotingSessionService;
use App\Models\Evaluation;
use App\Models\Event;
use App\Models\User;
use App\Models\VotingSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $operation, $json] = $argv;
$args = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

if (! str_ends_with((string) DB::connection()->getDatabaseName(), '_test')) {
    fwrite(STDOUT, json_encode(['ok' => false, 'status' => 500, 'error' => 'worker fora do banco de testes'])."\n");
    exit(1);
}

$actor = isset($args['actor_id']) ? User::query()->findOrFail($args['actor_id']) : null;

if ($actor !== null) {
    Auth::guard('web')->setUser($actor);
}

// Barreira: espera o teste liberar o lock exclusivo (todos os workers partem juntos).
DB::select('select pg_advisory_lock_shared(?)', [$args['barrier']]);
DB::select('select pg_advisory_unlock_shared(?)', [$args['barrier']]);

try {
    $result = match ($operation) {
        'vote' => (function () use ($args) {
            [$vote, $created] = app(PublicVoteService::class)->cast(
                VotingSession::query()->findOrFail($args['session_id']),
                User::query()->findOrFail($args['user_id']),
                (int) $args['team_id'],
            );

            return ['vote_id' => $vote->id, 'team_id' => $vote->team_id, 'created' => $created];
        })(),
        'open' => ['status_after' => app(VotingSessionService::class)->open(VotingSession::query()->findOrFail($args['session_id']))->status->value],
        'close' => ['status_after' => app(VotingSessionService::class)->close(VotingSession::query()->findOrFail($args['session_id']))->status->value],
        'publish' => ['version' => app(ResultPublicationService::class)->publish(Event::query()->findOrFail($args['event_id']), (int) $args['session_id'], $actor)->version],
        'request_revision' => ['evaluation_status' => app(EvaluationService::class)->requestRevision(Evaluation::query()->findOrFail($args['evaluation_id']), $actor, 'Revisão concorrente')->status->value],
        default => throw new InvalidArgumentException("Operação desconhecida: {$operation}"),
    };

    fwrite(STDOUT, json_encode(['ok' => true, 'status' => 200] + $result)."\n");
} catch (ValidationException $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'status' => 422, 'errors' => $e->errors()])."\n");
} catch (AuthorizationException $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'status' => 403, 'error' => $e->getMessage()])."\n");
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'status' => 500, 'error' => $e::class.': '.$e->getMessage()])."\n");
}
