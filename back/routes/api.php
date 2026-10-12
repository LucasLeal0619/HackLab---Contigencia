<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BallotController;
use App\Http\Controllers\Api\V1\ChallengeController;
use App\Http\Controllers\Api\V1\ClassController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CompanyRepresentativeController;
use App\Http\Controllers\Api\V1\DemandRoutingController;
use App\Http\Controllers\Api\V1\EvaluationController;
use App\Http\Controllers\Api\V1\EvaluationCriterionController;
use App\Http\Controllers\Api\V1\Even3IntegrationController;
use App\Http\Controllers\Api\V1\Even3WebhookController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\EventDayController;
use App\Http\Controllers\Api\V1\EventRegistrationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\JurorController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\MyEvaluationController;
use App\Http\Controllers\Api\V1\OccurrenceController;
use App\Http\Controllers\Api\V1\ParticipantController;
use App\Http\Controllers\Api\V1\PersonController;
use App\Http\Controllers\Api\V1\ResultController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SectorController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VotingSessionController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/health', HealthController::class)->name('health');

    // Webhook da Even3: público (sem Sanctum), autenticado pelo token de segurança no header configurado.
    Route::post('/webhooks/even3', Even3WebhookController::class)
        ->middleware('throttle:webhooks')
        ->name('webhooks.even3');

    // Login da SPA: antes, GET /sanctum/csrf-cookie.
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');

        Route::apiResource('people', PersonController::class)->except('destroy');

        Route::apiResource('users', UserController::class)->except('destroy');
        Route::patch('/users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status');
        Route::patch('/users/{user}/sector', [UserController::class, 'updateSector'])->name('users.sector');

        Route::apiResource('events', EventController::class)->except('destroy');
        Route::scopeBindings()->group(function () {
            Route::get('/events/{event}/days', [EventDayController::class, 'index'])->name('events.days.index');
            Route::post('/events/{event}/days', [EventDayController::class, 'store'])->name('events.days.store');
            Route::patch('/events/{event}/days/{day}', [EventDayController::class, 'update'])->name('events.days.update');
        });

        Route::get('/events/{event}/sectors', [SectorController::class, 'index'])->name('events.sectors.index');
        Route::post('/events/{event}/sectors', [SectorController::class, 'store'])->name('events.sectors.store');
        Route::get('/sectors/{sector}', [SectorController::class, 'show'])->name('sectors.show');
        Route::patch('/sectors/{sector}', [SectorController::class, 'update'])->name('sectors.update');
        Route::patch('/sectors/{sector}/status', [SectorController::class, 'updateStatus'])->name('sectors.status');

        Route::get('/events/{event}/meetings', [MeetingController::class, 'index'])->name('events.meetings.index');
        Route::post('/events/{event}/meetings', [MeetingController::class, 'store'])->name('events.meetings.store');
        Route::get('/meetings/{meeting}', [MeetingController::class, 'show'])->name('meetings.show');
        Route::patch('/meetings/{meeting}', [MeetingController::class, 'update'])->name('meetings.update');

        Route::get('/events/{event}/classes', [ClassController::class, 'index'])->name('events.classes.index');
        Route::post('/events/{event}/classes', [ClassController::class, 'store'])->name('events.classes.store');
        Route::get('/classes/{class}', [ClassController::class, 'show'])->name('classes.show');
        Route::patch('/classes/{class}', [ClassController::class, 'update'])->name('classes.update');
        Route::patch('/classes/{class}/status', [ClassController::class, 'updateStatus'])->name('classes.status');

        Route::get('/events/{event}/participants', [ParticipantController::class, 'index'])->name('events.participants.index');
        Route::post('/events/{event}/participants', [ParticipantController::class, 'store'])->name('events.participants.store');
        Route::get('/participants/{participant}', [ParticipantController::class, 'show'])->name('participants.show');
        Route::patch('/participants/{participant}', [ParticipantController::class, 'update'])->name('participants.update');
        Route::patch('/participants/{participant}/team', [ParticipantController::class, 'updateTeam'])->name('participants.team');

        Route::get('/events/{event}/teams', [TeamController::class, 'index'])->name('events.teams.index');
        Route::post('/events/{event}/teams', [TeamController::class, 'store'])->name('events.teams.store');
        Route::get('/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
        Route::patch('/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::get('/teams/{team}/members', [TeamController::class, 'members'])->name('teams.members.index');
        Route::post('/teams/{team}/members', [TeamController::class, 'addMember'])->name('teams.members.store');
        Route::delete('/teams/{team}/members/{participant}', [TeamController::class, 'removeMember'])->name('teams.members.destroy');

        Route::get('/events/{event}/companies', [CompanyController::class, 'index'])->name('events.companies.index');
        Route::post('/events/{event}/companies', [CompanyController::class, 'store'])->name('events.companies.store');
        Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
        Route::patch('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
        Route::patch('/companies/{company}/status', [CompanyController::class, 'updateStatus'])->name('companies.status');

        Route::get('/companies/{company}/representatives', [CompanyRepresentativeController::class, 'index'])->name('companies.representatives.index');
        Route::post('/companies/{company}/representatives', [CompanyRepresentativeController::class, 'store'])->name('companies.representatives.store');
        Route::patch('/company-representatives/{representative}', [CompanyRepresentativeController::class, 'update'])->name('company-representatives.update');
        Route::patch('/company-representatives/{representative}/status', [CompanyRepresentativeController::class, 'updateStatus'])->name('company-representatives.status');

        Route::get('/events/{event}/challenges', [ChallengeController::class, 'index'])->name('events.challenges.index');
        Route::post('/events/{event}/challenges', [ChallengeController::class, 'store'])->name('events.challenges.store');
        Route::get('/challenges/{challenge}', [ChallengeController::class, 'show'])->name('challenges.show');
        Route::patch('/challenges/{challenge}', [ChallengeController::class, 'update'])->name('challenges.update');
        Route::patch('/challenges/{challenge}/status', [ChallengeController::class, 'updateStatus'])->name('challenges.status');
        Route::patch('/challenges/{challenge}/team', [ChallengeController::class, 'updateTeam'])->name('challenges.team');

        // Pendências: forward/complete/reopen são operações próprias (não passam pelo PATCH genérico).
        // Destinos válidos de encaminhamento/criação (setores ativos do evento), só para quem tem tasks.route.
        Route::get('/events/{event}/tasks/routing-sectors', [DemandRoutingController::class, 'taskSectors'])->name('events.tasks.routing-sectors');
        Route::get('/events/{event}/tasks', [TaskController::class, 'index'])->name('events.tasks.index');
        Route::post('/events/{event}/tasks', [TaskController::class, 'store'])->name('events.tasks.store');
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
        Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
        Route::post('/tasks/{task}/comments', [TaskController::class, 'comment'])->name('tasks.comments.store');
        Route::post('/tasks/{task}/forward', [TaskController::class, 'forward'])->name('tasks.forward');
        Route::post('/tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
        Route::post('/tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('tasks.reopen');

        // Ocorrências: forward/resolve/reopen/tasks são operações próprias.
        Route::get('/events/{event}/occurrences/routing-sectors', [DemandRoutingController::class, 'occurrenceSectors'])->name('events.occurrences.routing-sectors');
        Route::get('/events/{event}/occurrences', [OccurrenceController::class, 'index'])->name('events.occurrences.index');
        Route::post('/events/{event}/occurrences', [OccurrenceController::class, 'store'])->name('events.occurrences.store');
        Route::get('/occurrences/{occurrence}', [OccurrenceController::class, 'show'])->name('occurrences.show');
        Route::patch('/occurrences/{occurrence}', [OccurrenceController::class, 'update'])->name('occurrences.update');
        Route::post('/occurrences/{occurrence}/comments', [OccurrenceController::class, 'comment'])->name('occurrences.comments.store');
        Route::post('/occurrences/{occurrence}/forward', [OccurrenceController::class, 'forward'])->name('occurrences.forward');
        Route::post('/occurrences/{occurrence}/resolve', [OccurrenceController::class, 'resolve'])->name('occurrences.resolve');
        Route::post('/occurrences/{occurrence}/reopen', [OccurrenceController::class, 'reopen'])->name('occurrences.reopen');
        Route::post('/occurrences/{occurrence}/tasks', [OccurrenceController::class, 'generateTask'])->name('occurrences.tasks.store');

        // Jurados e atribuições explícitas.
        Route::get('/events/{event}/jurors', [JurorController::class, 'index'])->name('events.jurors.index');
        Route::post('/events/{event}/jurors', [JurorController::class, 'store'])->name('events.jurors.store');
        Route::get('/jurors/{juror}', [JurorController::class, 'show'])->name('jurors.show');
        Route::patch('/jurors/{juror}', [JurorController::class, 'update'])->name('jurors.update');
        Route::patch('/jurors/{juror}/status', [JurorController::class, 'updateStatus'])->name('jurors.status');
        Route::get('/jurors/{juror}/assignments', [JurorController::class, 'assignments'])->name('jurors.assignments.index');
        Route::put('/jurors/{juror}/assignments', [JurorController::class, 'syncAssignments'])->name('jurors.assignments.sync');

        // Critérios de avaliação.
        Route::get('/events/{event}/evaluation-criteria', [EvaluationCriterionController::class, 'index'])->name('events.evaluation-criteria.index');
        Route::post('/events/{event}/evaluation-criteria', [EvaluationCriterionController::class, 'store'])->name('events.evaluation-criteria.store');
        Route::patch('/evaluation-criteria/{criterion}', [EvaluationCriterionController::class, 'update'])->name('evaluation-criteria.update');
        Route::patch('/evaluation-criteria/{criterion}/status', [EvaluationCriterionController::class, 'updateStatus'])->name('evaluation-criteria.status');

        // Área do jurado (Person autenticada).
        Route::get('/events/{event}/my-evaluations', [MyEvaluationController::class, 'index'])->name('events.my-evaluations.index');
        Route::put('/juror-assignments/{assignment}/evaluation', [MyEvaluationController::class, 'save'])->name('juror-assignments.evaluation.save');
        Route::post('/juror-assignments/{assignment}/evaluation/submit', [MyEvaluationController::class, 'submit'])->name('juror-assignments.evaluation.submit');

        // Visão administrativa e agregada.
        Route::get('/events/{event}/evaluations', [EvaluationController::class, 'index'])->name('events.evaluations.index');
        Route::get('/evaluations/{evaluation}', [EvaluationController::class, 'show'])->name('evaluations.show');
        Route::post('/evaluations/{evaluation}/request-revision', [EvaluationController::class, 'requestRevision'])->name('evaluations.request-revision');
        Route::get('/events/{event}/evaluation-progress', [EvaluationController::class, 'progress'])->name('events.evaluation-progress');
        Route::get('/events/{event}/technical-results', [EvaluationController::class, 'technicalResults'])->name('events.technical-results');

        // Votação pública (administração). Nenhuma rota devolve eleitor → equipe.
        Route::get('/events/{event}/voting-sessions', [VotingSessionController::class, 'index'])->name('events.voting-sessions.index');
        Route::post('/events/{event}/voting-sessions', [VotingSessionController::class, 'store'])->name('events.voting-sessions.store');
        Route::get('/voting-sessions/{session}', [VotingSessionController::class, 'show'])->name('voting-sessions.show');
        Route::patch('/voting-sessions/{session}', [VotingSessionController::class, 'update'])->name('voting-sessions.update');
        Route::put('/voting-sessions/{session}/teams', [VotingSessionController::class, 'syncTeams'])->name('voting-sessions.teams.sync');
        Route::get('/voting-sessions/{session}/eligibilities', [VotingSessionController::class, 'eligibilities'])->name('voting-sessions.eligibilities.index');
        Route::put('/voting-sessions/{session}/eligibilities', [VotingSessionController::class, 'syncEligibilities'])->name('voting-sessions.eligibilities.sync');
        Route::post('/voting-sessions/{session}/open', [VotingSessionController::class, 'open'])->name('voting-sessions.open');
        Route::post('/voting-sessions/{session}/close', [VotingSessionController::class, 'close'])->name('voting-sessions.close');
        Route::post('/voting-sessions/{session}/cancel', [VotingSessionController::class, 'cancel'])->name('voting-sessions.cancel');
        Route::get('/voting-sessions/{session}/turnout', [VotingSessionController::class, 'turnout'])->name('voting-sessions.turnout');
        Route::get('/voting-sessions/{session}/results', [VotingSessionController::class, 'results'])->name('voting-sessions.results');

        // Área do eleitor (User VOTER elegível). Voto definitivo: sem PATCH/DELETE.
        Route::get('/events/{event}/my-voting-sessions', [BallotController::class, 'mySessions'])->name('events.my-voting-sessions');
        Route::get('/voting-sessions/{session}/ballot', [BallotController::class, 'ballot'])->name('voting-sessions.ballot');
        Route::post('/voting-sessions/{session}/vote', [BallotController::class, 'vote'])->name('voting-sessions.vote');
        Route::get('/voting-sessions/{session}/my-vote', [BallotController::class, 'myVote'])->name('voting-sessions.my-vote');

        // Resultados: prévia/publicação/revogação (Administrador) e resultado oficial (todos os perfis).
        Route::get('/events/{event}/results/preview', [ResultController::class, 'preview'])->name('events.results.preview');
        Route::get('/events/{event}/results/publications', [ResultController::class, 'publications'])->name('events.results.publications');
        Route::post('/events/{event}/results/publish', [ResultController::class, 'publish'])->name('events.results.publish');
        Route::post('/events/{event}/results/revoke', [ResultController::class, 'revoke'])->name('events.results.revoke');
        Route::get('/events/{event}/results/published', [ResultController::class, 'published'])->name('events.results.published');

        // Auditoria global (Administrador), somente leitura: não há POST/PATCH/DELETE.
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('/audit-logs/meta', [AuditLogController::class, 'meta'])->name('audit-logs.meta');
        Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->whereNumber('auditLog')->name('audit-logs.show');

        // Integração Even3 (Even3 → HackLab). O frontend nunca chama a Even3.
        Route::get('/events/{event}/integrations/even3/status', [Even3IntegrationController::class, 'status'])->name('events.integrations.even3.status');
        Route::put('/events/{event}/integrations/even3', [Even3IntegrationController::class, 'configure'])->name('events.integrations.even3.configure');
        Route::get('/events/{event}/integrations/even3/tickets', [Even3IntegrationController::class, 'tickets'])->name('events.integrations.even3.tickets');
        Route::patch('/external-ticket-types/{ticket}/mapping', [Even3IntegrationController::class, 'mapping'])->name('external-ticket-types.mapping');
        Route::post('/events/{event}/integrations/even3/sync', [Even3IntegrationController::class, 'sync'])->name('events.integrations.even3.sync');
        Route::get('/events/{event}/integrations/even3/sync-runs', [Even3IntegrationController::class, 'syncRuns'])->name('events.integrations.even3.sync-runs');
        Route::get('/integration-sync-runs/{run}', [Even3IntegrationController::class, 'showSyncRun'])->name('integration-sync-runs.show');

        Route::get('/events/{event}/event-registrations', [EventRegistrationController::class, 'index'])->name('events.event-registrations.index');
        Route::post('/events/{event}/event-registrations/materialize-participants', [EventRegistrationController::class, 'materialize'])->name('events.event-registrations.materialize');
        Route::get('/event-registrations/{registration}', [EventRegistrationController::class, 'show'])->name('event-registrations.show');
        Route::get('/events/{event}/attendance', [AttendanceController::class, 'index'])->name('events.attendance.index');
    });
});
