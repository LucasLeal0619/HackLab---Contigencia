<?php

namespace Database\Seeders;

use App\Domain\Challenges\Enums\ChallengeStatus;
use App\Domain\Companies\Enums\CompanyStatus;
use App\Domain\Companies\Enums\CompanyType;
use App\Domain\Demands\Enums\DemandPriority;
use App\Domain\Evaluations\EvaluationService;
use App\Domain\Events\Enums\EventStatus;
use App\Domain\Jurors\JurorService;
use App\Domain\Meetings\Enums\MeetingStatus;
use App\Domain\Occurrences\Enums\OccurrenceCategory;
use App\Domain\Occurrences\OccurrenceService;
use App\Domain\Participants\Enums\ParticipantStatus;
use App\Domain\Tasks\TaskService;
use App\Domain\Teams\Enums\TeamStatus;
use App\Domain\Users\Enums\RoleCode;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Voting\PublicVoteService;
use App\Domain\Voting\VotingSessionService;
use App\Models\Challenge;
use App\Models\Company;
use App\Models\CompanyRepresentative;
use App\Models\Evaluation;
use App\Models\EvaluationCriterion;
use App\Models\Event;
use App\Models\Juror;
use App\Models\JurorTeamAssignment;
use App\Models\Occurrence;
use App\Models\Participant;
use App\Models\Person;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Sector;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\VotingSession;
use Illuminate\Database\Seeder;

/**
 * Dados FICTÍCIOS de desenvolvimento: um evento de exemplo com 3 dias, 2 setores,
 * um Gestor e um Editor de exemplo, uma reunião geral, 3 turmas, 12 participantes
 * e 2 equipes com alguns membros, 3 empresas (com representantes) e 5 desafios em etapas
 * diferentes (um institucional, dois aprovados sem equipe, um distribuído). Só roda em local/testing.
 * Representantes são Persons sem conta de acesso.
 *
 * Pendências e ocorrências intersetoriais são criadas pelos Services (com histórico e referência
 * gerada pelo banco), usando os setores fictícios deste seeder.
 *
 * Jurados: tudo explícito, sem automação de domínio. O seeder cria a conta jurado@hacklab.local
 * por conta própria (o JurorService nunca cria User).
 *
 * Votação: 5 contas VOTER criadas aqui (votante01..05@hacklab.local); elegibilidade nunca cria conta.
 * Uma sessão CLOSED de teste (empate 2 x 2, participação 80%) e uma DRAFT configurada. Sem publicação.
 *
 * O código do sistema não depende desses dados (nomes, datas e número de dias vêm do banco).
 */
class DevelopmentDataSeeder extends Seeder
{
    public const EVENT_NAME = 'HackLab Exemplo (dev)';

    public const DEMO_PASSWORD = 'hacklab123';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->getOutput()->writeln('<comment>DevelopmentDataSeeder ignorado: só roda em local/testing.</comment>');

            return;
        }

        $start = now()->addMonth()->startOfWeek()->addDays(2);

        $event = Event::query()->firstOrCreate(['name' => self::EVENT_NAME], [
            'description' => 'Evento fictício para desenvolvimento.',
            'location' => 'Local de exemplo',
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(2)->toDateString(),
            'status' => EventStatus::Planned,
        ]);

        foreach (range(1, 3) as $number) {
            $event->days()->firstOrCreate(['day_number' => $number], [
                'date' => $event->start_date->copy()->addDays($number - 1)->toDateString(),
                'label' => "Dia {$number}",
            ]);
        }

        $sectorA = $event->sectors()->firstOrCreate(['name' => 'Setor Exemplo A'], ['active' => true]);
        $sectorB = $event->sectors()->firstOrCreate(['name' => 'Setor Exemplo B'], ['active' => true]);
        $sectorC = $event->sectors()->firstOrCreate(['name' => 'Setor Exemplo C'], ['active' => true]);

        $this->demoUser('gestor@hacklab.local', 'Gestor de Exemplo', RoleCode::Manager, $sectorA);
        $this->demoUser('editor@hacklab.local', 'Editor de Exemplo', RoleCode::Editor, $sectorB);

        $admin = User::query()->where('email', DevelopmentAdminSeeder::DEFAULT_EMAIL)->first();

        if ($admin !== null) {
            $event->meetings()->firstOrCreate(['title' => 'Reunião geral de alinhamento'], [
                'sector_id' => null,
                'scheduled_at' => $event->start_date->copy()->subWeek()->setTime(14, 0),
                'status' => MeetingStatus::Scheduled,
                'created_by_user_id' => $admin->id,
            ]);
        }

        $this->participantsAndTeams($event);
        $this->companiesAndChallenges($event);

        if ($admin !== null) {
            $this->demands($event, $admin, $sectorA, $sectorB, $sectorC);
            $this->jurorsAndEvaluations($event, $admin);
            $this->voting($event, $admin);
        }
    }

    /**
     * Votação pública: 5 contas VOTER criadas explicitamente aqui (elegibilidade nunca cria conta),
     * uma sessão CLOSED de teste com empate popular (2 x 2) e um elegível que não votou (participação 80%),
     * e uma sessão DRAFT "oficial" já configurada. Tudo pelos Services (com auditoria administrativa).
     */
    private function voting(Event $event, User $admin): void
    {
        $voters = collect(range(1, 5))->map(fn (int $n) => $this->voterUser(sprintf('votante%02d@hacklab.local', $n), "Votante Exemplo {$n}"));
        $teams = Team::query()->where('event_id', $event->id)->orderBy('name')->get()->keyBy('name');
        $team1 = $teams->get('Equipe Exemplo 1');
        $team2 = $teams->get('Equipe Exemplo 2');

        if ($team1 === null || $team2 === null) {
            return;
        }

        $sessions = app(VotingSessionService::class);
        $votes = app(PublicVoteService::class);
        $voterIds = $voters->pluck('id')->all();

        if (! VotingSession::query()->where('event_id', $event->id)->where('name', 'Votação de teste (exemplo)')->exists()) {
            $test = $sessions->create($event, 'Votação de teste (exemplo)', $admin);
            $sessions->syncTeams($test, [$team1->id, $team2->id]);
            $sessions->syncEligibilities($test, $voterIds, $admin);
            $sessions->open($test);

            // Empate popular: 2 votos para cada equipe; o 5º elegível não vota.
            foreach ([$team1, $team1, $team2, $team2] as $i => $team) {
                $votes->cast($test, $voters[$i], $team->id);
            }

            $sessions->close($test);
        }

        if (! VotingSession::query()->where('event_id', $event->id)->where('name', 'Escolha do Público (exemplo)')->exists()) {
            $official = $sessions->create($event, 'Escolha do Público (exemplo)', $admin);
            $sessions->syncTeams($official, [$team1->id, $team2->id]);
            $sessions->syncEligibilities($official, $voterIds, $admin);
        }
    }

    private function voterUser(string $email, string $name): User
    {
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $existing;
        }

        $person = Person::query()->firstOrCreate(['email' => $email], ['full_name' => $name]);

        return User::query()->create([
            'person_id' => $person->id,
            'role_id' => Role::forCode(RoleCode::Voter)->id,
            'email' => $email,
            'password' => self::DEMO_PASSWORD,
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Jurados (com/sem empresa, com/sem conta), atribuições explícitas, critérios e avaliações.
     */
    private function jurorsAndEvaluations(Event $event, User $admin): void
    {
        $jurors = app(JurorService::class);

        // Critérios com faixas e pesos diferentes (o cálculo normaliza; pesos não somam 100).
        foreach ([
            ['Inovação', 0, 10, 3, 1],
            ['Viabilidade', 1, 5, 2, 2],
            ['Apresentação', 0, 10, 1, 3],
        ] as [$name, $min, $max, $weight, $order]) {
            EvaluationCriterion::query()->firstOrCreate(
                ['event_id' => $event->id, 'name' => $name],
                ['min_score' => $min, 'max_score' => $max, 'weight' => $weight, 'sort_order' => $order, 'active' => true],
            );
        }

        $alfa = Company::query()->where('event_id', $event->id)->where('name', 'Alfa Tecnologia (exemplo)')->first();
        $teams = Team::query()->where('event_id', $event->id)->orderBy('name')->get()->keyBy('name');
        $team1 = $teams->get('Equipe Exemplo 1');
        $team2 = $teams->get('Equipe Exemplo 2');

        // 1) Representante da Alfa que também é jurado (mesma Person, sem conta).
        $representative = Person::query()->where('email', 'representante01@hacklab.local')->first();
        $repJuror = $this->juror($jurors, $event, $representative, $alfa?->id);

        // 2) Jurado independente, com conta JUROR criada explicitamente aqui.
        $independentPerson = Person::query()->firstOrCreate(['email' => 'jurado@hacklab.local'], ['full_name' => 'Jurado Exemplo']);
        $independent = $this->juror($jurors, $event, $independentPerson, null);
        $jurorUser = User::query()->where('email', 'jurado@hacklab.local')->first() ?? User::query()->create([
            'person_id' => $independentPerson->id,
            'role_id' => Role::forCode(RoleCode::Juror)->id,
            'email' => 'jurado@hacklab.local',
            'password' => self::DEMO_PASSWORD,
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
        ]);

        // 3) Jurado sem conta (Juror não implica User).
        $noAccountPerson = Person::query()->firstOrCreate(['email' => 'jurado.semconta@hacklab.local'], ['full_name' => 'Jurado Sem Conta (exemplo)']);
        $noAccount = $this->juror($jurors, $event, $noAccountPerson, null);

        if ($team1 === null || $team2 === null) {
            return;
        }

        // Atribuições explícitas: equipe 1 com dois jurados; jurado independente com duas equipes.
        $jurors->syncAssignments($independent, [$team1->id, $team2->id], $admin);
        $jurors->syncAssignments($repJuror, [$team1->id], $admin);
        $jurors->syncAssignments($noAccount, [$team2->id], $admin);

        $criteria = EvaluationCriterion::query()->where('event_id', $event->id)->orderBy('sort_order')->get();
        $evaluations = app(EvaluationService::class);
        $assignment = fn (Juror $juror, Team $team) => JurorTeamAssignment::query()->where('juror_id', $juror->id)->where('team_id', $team->id)->first();

        // Avaliação enviada (equipe 1) e rascunho parcial (equipe 2), pelo próprio jurado.
        if (! Evaluation::query()->where('juror_id', $independent->id)->where('team_id', $team1->id)->exists()) {
            $evaluations->submit($assignment($independent, $team1), $jurorUser, [
                'comments' => 'Boa proposta, apresentação clara.',
                'scores' => $criteria->map(fn ($c) => ['criterion_id' => $c->id, 'score' => (float) $c->max_score - 1])->all(),
            ]);
        }

        if (! Evaluation::query()->where('juror_id', $independent->id)->where('team_id', $team2->id)->exists()) {
            $evaluations->saveDraft($assignment($independent, $team2), $jurorUser, [
                'scores' => [['criterion_id' => $criteria->first()->id, 'score' => 7]],
            ]);
        }
    }

    private function juror(JurorService $jurors, Event $event, ?Person $person, ?int $companyId): ?Juror
    {
        if ($person === null) {
            return null;
        }

        return Juror::query()->where('event_id', $event->id)->where('person_id', $person->id)->first()
            ?? $jurors->create($event, ['person_id' => $person->id, 'company_id' => $companyId]);
    }

    /**
     * Pendências e ocorrências intersetoriais (idempotente pelo título).
     */
    private function demands(Event $event, User $admin, Sector $sectorA, Sector $sectorB, Sector $sectorC): void
    {
        $tasks = app(TaskService::class);
        $occurrences = app(OccurrenceService::class);
        $manager = User::query()->where('email', 'gestor@hacklab.local')->first();
        $editor = User::query()->where('email', 'editor@hacklab.local')->first();
        $exists = fn (string $model, string $title) => $model::query()->where('event_id', $event->id)->where('title', $title)->exists();

        // Pendência do próprio setor, atribuída ao Gestor do setor A.
        if (! $exists(Task::class, 'Pendência Exemplo: conferir crachás')) {
            $task = $tasks->create($event, $manager ?? $admin, [
                'title' => 'Pendência Exemplo: conferir crachás',
                'description' => 'Conferir a lista de crachás impressos.',
                'origin_sector_id' => $sectorA->id,
                'responsible_sector_id' => $sectorA->id,
                'assigned_user_id' => $manager?->id,
                'priority' => DemandPriority::High->value,
            ]);
            $tasks->comment($task, $admin, 'Lembrar de separar os crachás de jurados.');
        }

        // Pendência encaminhada: A → B (A continua envolvido).
        if (! $exists(Task::class, 'Pendência Exemplo: instalação elétrica')) {
            $task = $tasks->create($event, $admin, [
                'title' => 'Pendência Exemplo: instalação elétrica',
                'description' => 'Pontos de energia para as bancadas.',
                'origin_sector_id' => $sectorC->id,
                'responsible_sector_id' => $sectorA->id,
            ]);
            $tasks->forward($task, $sectorB->id, 'A instalação depende da equipe do Setor B.', $admin);
        }

        // Pendência com vários envolvidos.
        if (! $exists(Task::class, 'Pendência Exemplo: logística do coffee break')) {
            $tasks->create($event, $admin, [
                'title' => 'Pendência Exemplo: logística do coffee break',
                'description' => 'Horários e reposição do coffee break.',
                'origin_sector_id' => $sectorB->id,
                'responsible_sector_id' => $sectorB->id,
                'assigned_user_id' => $editor?->id,
                'involved_sector_ids' => [$sectorA->id, $sectorC->id],
                'due_at' => $event->start_date->copy()->setTime(9, 0)->toIso8601String(),
            ]);
        }

        // Pendência concluída.
        if (! $exists(Task::class, 'Pendência Exemplo: lista de presença impressa')) {
            $task = $tasks->create($event, $admin, [
                'title' => 'Pendência Exemplo: lista de presença impressa',
                'description' => 'Imprimir listas de apoio.',
                'origin_sector_id' => $sectorA->id,
                'responsible_sector_id' => $sectorA->id,
            ]);
            $tasks->complete($task, $admin, 'Listas impressas e entregues.');
        }

        $day1 = $event->days()->where('day_number', 1)->first();
        $team = Team::query()->where('event_id', $event->id)->where('name', 'Equipe Exemplo 1')->first();

        // Ocorrência aberta.
        if (! $exists(Occurrence::class, 'Ocorrência Exemplo: projetor com defeito')) {
            $occurrences->create($event, $admin, [
                'title' => 'Ocorrência Exemplo: projetor com defeito',
                'description' => 'Projetor da sala 2 não liga.',
                'category' => OccurrenceCategory::Technology->value,
                'origin_sector_id' => $sectorA->id,
                'responsible_sector_id' => $sectorA->id,
                'event_day_id' => $day1?->id,
                'location' => 'Sala 2',
            ]);
        }

        // Ocorrência em atendimento, envolvendo mais de um setor.
        if (! $exists(Occurrence::class, 'Ocorrência Exemplo: rede instável')) {
            $occurrence = $occurrences->create($event, $admin, [
                'title' => 'Ocorrência Exemplo: rede instável',
                'description' => 'Wi-Fi caindo no salão principal.',
                'category' => OccurrenceCategory::Infrastructure->value,
                'priority' => DemandPriority::Urgent->value,
                'origin_sector_id' => $sectorA->id,
                'responsible_sector_id' => $sectorB->id,
                'involved_sector_ids' => [$sectorC->id],
                'event_day_id' => $day1?->id,
            ]);
            $occurrences->update($occurrence, ['status' => 'IN_PROGRESS'], $admin);
            $occurrences->comment($occurrence, $admin, 'Técnico a caminho.');
        }

        // Ocorrência resolvida com solução.
        if (! $exists(Occurrence::class, 'Ocorrência Exemplo: equipe sem mesa')) {
            $occurrence = $occurrences->create($event, $admin, [
                'title' => 'Ocorrência Exemplo: equipe sem mesa',
                'description' => 'Uma equipe chegou e não havia mesa.',
                'category' => OccurrenceCategory::Team->value,
                'origin_sector_id' => $sectorB->id,
                'responsible_sector_id' => $sectorB->id,
                'team_id' => $team?->id,
            ]);
            $occurrences->resolve($occurrence, $admin, 'Mesa extra trazida do depósito.');
        }

        // Ocorrência que gerou pendência.
        if (! $exists(Occurrence::class, 'Ocorrência Exemplo: falta de cadeiras')) {
            $occurrence = $occurrences->create($event, $admin, [
                'title' => 'Ocorrência Exemplo: falta de cadeiras',
                'description' => 'Faltam cadeiras na área das equipes.',
                'category' => OccurrenceCategory::Production->value,
                'origin_sector_id' => $sectorC->id,
                'responsible_sector_id' => $sectorC->id,
            ]);
            $occurrences->generateTask($occurrence, $admin, [
                'title' => 'Pendência Exemplo: repor cadeiras',
                'description' => 'Trazer 20 cadeiras do depósito.',
                'origin_sector_id' => $sectorC->id,
                'responsible_sector_id' => $sectorA->id,
            ]);
        }
    }

    private function participantsAndTeams(Event $event): void
    {
        $classes = collect(range(1, 3))->map(fn (int $n) => SchoolClass::query()->firstOrCreate(
            ['event_id' => $event->id, 'name' => "Turma Exemplo {$n}"],
            ['active' => true],
        ));

        $teams = collect(range(1, 2))->map(fn (int $n) => Team::query()->firstOrCreate(
            ['event_id' => $event->id, 'name' => "Equipe Exemplo {$n}"],
            ['code' => "EX{$n}", 'status' => TeamStatus::Active],
        ));

        foreach (range(1, 12) as $n) {
            $email = sprintf('participante%02d@hacklab.local', $n);
            $person = Person::query()->firstOrCreate(['email' => $email], ['full_name' => sprintf('Participante Exemplo %02d', $n)]);

            $participant = Participant::query()->firstOrCreate(
                ['event_id' => $event->id, 'person_id' => $person->id],
                ['class_id' => $classes[($n - 1) % 3]->id, 'status' => ParticipantStatus::Available],
            );

            // Os 8 primeiros em equipes (alternando turmas); os demais ficam sem equipe.
            if ($n <= 8 && ! TeamMember::query()->where('participant_id', $participant->id)->exists()) {
                TeamMember::query()->create([
                    'event_id' => $event->id,
                    'team_id' => $teams[($n - 1) % 2]->id,
                    'participant_id' => $participant->id,
                    'joined_at' => now(),
                    'active' => true,
                ]);
            }
        }
    }

    private function companiesAndChallenges(Event $event): void
    {
        $alfa = $this->company($event, 'Alfa Tecnologia (exemplo)', CompanyType::Participant, CompanyStatus::Confirmed, '00.ABC.000/0001-00');
        $beta = $this->company($event, 'Beta Parcerias (exemplo)', CompanyType::Partner, CompanyStatus::Confirmed, null);
        $gama = $this->company($event, 'Gama Patrocínios (exemplo)', CompanyType::Sponsor, CompanyStatus::Draft, null);

        $this->representative($alfa, 'representante01@hacklab.local', 'Representante Exemplo 01', 'Gerente de Inovação', true);
        $this->representative($alfa, 'representante02@hacklab.local', 'Representante Exemplo 02', 'Analista', false);
        $this->representative($beta, 'representante03@hacklab.local', 'Representante Exemplo 03', 'Coordenadora', true);
        // Gama em DRAFT, ainda sem representante.

        $distributed = $this->challenge($event, $alfa, 'Desafio Exemplo: Logística', ChallengeStatus::Distributed);
        $this->challenge($event, $alfa, 'Desafio Exemplo: Atendimento', ChallengeStatus::Approved);
        $this->challenge($event, $beta, 'Desafio Exemplo: Sustentabilidade', ChallengeStatus::UnderReview);
        $this->challenge($event, null, 'Desafio Institucional Exemplo', ChallengeStatus::Approved);
        $this->challenge($event, $gama, 'Desafio Exemplo: Rascunho', ChallengeStatus::Draft);

        // Desafio distribuído para a primeira equipe de exemplo (sem sobrescrever vínculo existente).
        $team = Team::query()->where('event_id', $event->id)->where('name', 'Equipe Exemplo 1')->first();

        if ($team !== null && $team->challenge_id === null && ! Team::query()->where('challenge_id', $distributed->id)->exists()) {
            $team->forceFill(['challenge_id' => $distributed->id])->save();
        }
    }

    private function company(Event $event, string $name, CompanyType $type, CompanyStatus $status, ?string $document): Company
    {
        return Company::query()->firstOrCreate(['event_id' => $event->id, 'name' => $name], [
            'type' => $type,
            'status' => $status,
            'document' => $document,
            'segment' => 'Exemplo',
        ]);
    }

    private function representative(Company $company, string $email, string $name, string $title, bool $primary): void
    {
        $person = Person::query()->firstOrCreate(['email' => $email], ['full_name' => $name]);

        CompanyRepresentative::query()->firstOrCreate(
            ['company_id' => $company->id, 'person_id' => $person->id],
            ['title' => $title, 'is_primary' => $primary, 'active' => true],
        );
    }

    private function challenge(Event $event, ?Company $company, string $title, ChallengeStatus $status): Challenge
    {
        return Challenge::query()->firstOrCreate(['event_id' => $event->id, 'title' => $title], [
            'company_id' => $company?->id,
            'problem' => 'Problema fictício para desenvolvimento.',
            'objective' => 'Objetivo fictício.',
            'status' => $status,
        ]);
    }

    private function demoUser(string $email, string $name, RoleCode $role, Sector $sector): void
    {
        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        $person = Person::query()->create(['full_name' => $name, 'email' => $email]);

        User::query()->create([
            'person_id' => $person->id,
            'role_id' => Role::forCode($role)->id,
            'sector_id' => $sector->id,
            'email' => $email,
            'password' => self::DEMO_PASSWORD,
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
        ]);
    }
}
