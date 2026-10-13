<?php

namespace App\Domain\Users\Enums;

/**
 * Permissões por perfil. Novas permissões entram junto com os módulos que as usam.
 *
 * A Policy combina a permissão com o escopo do usuário (User::canReachSector):
 * quem tem setor (Gestor, Editor) fica limitado a ele; quem não tem setor tem
 * alcance global dentro do que as permissões permitem.
 */
enum PermissionCode: string
{
    case PeopleView = 'people.view';
    case PeopleManage = 'people.manage';
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case RolesView = 'roles.view';
    case AuditLogsView = 'audit_logs.view';
    case EventsView = 'events.view';
    case EventsManage = 'events.manage';
    case SectorsView = 'sectors.view';
    case SectorsManage = 'sectors.manage';
    case MeetingsView = 'meetings.view';
    case MeetingsManage = 'meetings.manage';
    case ClassesView = 'classes.view';
    case ClassesManage = 'classes.manage';
    case ParticipantsView = 'participants.view';
    case ParticipantsManage = 'participants.manage';
    case TeamsView = 'teams.view';
    case TeamsManage = 'teams.manage';
    case CompaniesView = 'companies.view';
    case CompaniesManage = 'companies.manage';
    case ChallengesView = 'challenges.view';
    case ChallengesManage = 'challenges.manage';
    case TasksView = 'tasks.view';
    case TasksComment = 'tasks.comment';
    case TasksCreate = 'tasks.create';
    case TasksOperate = 'tasks.operate';
    case TasksRoute = 'tasks.route';
    case OccurrencesView = 'occurrences.view';
    case OccurrencesComment = 'occurrences.comment';
    case OccurrencesCreate = 'occurrences.create';
    case OccurrencesOperate = 'occurrences.operate';
    case OccurrencesRoute = 'occurrences.route';
    case JurorsView = 'jurors.view';
    case JurorsManage = 'jurors.manage';
    case EvaluationCriteriaView = 'evaluation_criteria.view';
    case EvaluationCriteriaManage = 'evaluation_criteria.manage';
    case EvaluationsProgressView = 'evaluations.progress.view';
    case EvaluationsAllView = 'evaluations.all.view';
    case EvaluationsOwn = 'evaluations.own';
    case EvaluationsRequestRevision = 'evaluations.request_revision';
    case VotingSessionsView = 'voting_sessions.view';
    case VotingSessionsManage = 'voting_sessions.manage';
    case VotingEligibilityManage = 'voting_eligibility.manage';
    case VotesCast = 'votes.cast';
    case VotingAggregatesView = 'voting_aggregates.view';
    case ResultsPreview = 'results.preview';
    case ResultsPublish = 'results.publish';
    case ResultsViewPublished = 'results.view_published';
    case IntegrationsView = 'integrations.view';
    case IntegrationsManage = 'integrations.manage';
    case IntegrationsSync = 'integrations.sync';
    case EventRegistrationsView = 'event_registrations.view';
    case AttendanceView = 'attendance.view';
    case ReportsView = 'reports.view';

    public function label(): string
    {
        return match ($this) {
            self::PeopleView => 'Consultar pessoas',
            self::PeopleManage => 'Cadastrar e editar pessoas',
            self::UsersView => 'Consultar usuários',
            self::UsersManage => 'Cadastrar, editar, ativar/inativar usuários e alterar perfil',
            self::RolesView => 'Consultar perfis',
            self::AuditLogsView => 'Consultar a auditoria global (somente leitura)',
            self::EventsView => 'Consultar eventos e dias',
            self::EventsManage => 'Cadastrar e editar eventos e dias',
            self::SectorsView => 'Consultar setores (no escopo do usuário)',
            self::SectorsManage => 'Gerenciar setores (no escopo do usuário)',
            self::MeetingsView => 'Consultar reuniões (no escopo do usuário)',
            self::MeetingsManage => 'Gerenciar reuniões (no escopo do usuário)',
            self::ClassesView => 'Consultar turmas',
            self::ClassesManage => 'Cadastrar, editar, ativar/inativar turmas',
            self::ParticipantsView => 'Consultar participantes',
            self::ParticipantsManage => 'Cadastrar e editar participantes',
            self::TeamsView => 'Consultar equipes e composição',
            self::TeamsManage => 'Cadastrar equipes e gerenciar membros',
            self::CompaniesView => 'Consultar empresas e representantes',
            self::CompaniesManage => 'Cadastrar e editar empresas e representantes',
            self::ChallengesView => 'Consultar desafios',
            self::ChallengesManage => 'Cadastrar e editar desafios e distribuí-los às equipes',
            self::TasksView => 'Consultar pendências (no escopo do usuário)',
            self::TasksComment => 'Comentar pendências (no escopo do usuário)',
            self::TasksCreate => 'Criar pendências',
            self::TasksOperate => 'Operar pendências do setor responsável (status, concluir, reabrir)',
            self::TasksRoute => 'Gerir pendências do setor responsável (encaminhar, prioridade, prazo, atribuição, envolvidos)',
            self::OccurrencesView => 'Consultar ocorrências (no escopo do usuário)',
            self::OccurrencesComment => 'Comentar ocorrências (no escopo do usuário)',
            self::OccurrencesCreate => 'Registrar ocorrências',
            self::OccurrencesOperate => 'Operar ocorrências do setor responsável (status, resolver, reabrir)',
            self::OccurrencesRoute => 'Gerir ocorrências do setor responsável (encaminhar, atribuição, envolvidos, gerar pendência)',
            self::JurorsView => 'Consultar jurados e atribuições',
            self::JurorsManage => 'Cadastrar jurados e gerenciar atribuições jurado ↔ equipe',
            self::EvaluationCriteriaView => 'Consultar critérios de avaliação',
            self::EvaluationCriteriaManage => 'Cadastrar e editar critérios de avaliação',
            self::EvaluationsProgressView => 'Consultar progresso agregado das avaliações (sem notas)',
            self::EvaluationsAllView => 'Consultar todas as avaliações e notas',
            self::EvaluationsOwn => 'Avaliar as equipes atribuídas (como jurado da própria Person)',
            self::EvaluationsRequestRevision => 'Solicitar revisão de avaliação enviada',
            self::VotingSessionsView => 'Consultar sessões de votação, candidatas e elegíveis (sem a escolha de ninguém)',
            self::VotingSessionsManage => 'Criar, configurar, abrir, fechar e cancelar sessões de votação',
            self::VotingEligibilityManage => 'Definir os eleitores elegíveis de uma sessão',
            self::VotesCast => 'Votar nas sessões em que é elegível',
            self::VotingAggregatesView => 'Consultar participação e, com a sessão fechada, a contagem por equipe',
            self::ResultsPreview => 'Consultar a prévia e o histórico das publicações de resultado',
            self::ResultsPublish => 'Publicar e revogar o resultado oficial',
            self::ResultsViewPublished => 'Consultar o resultado oficial publicado',
            self::IntegrationsView => 'Consultar status, entradas e histórico de sincronização da integração Even3',
            self::IntegrationsManage => 'Configurar a integração Even3 e mapear entradas',
            self::IntegrationsSync => 'Disparar sincronização com a Even3',
            self::EventRegistrationsView => 'Consultar inscrições externas',
            self::AttendanceView => 'Consultar presença (check-ins)',
            self::ReportsView => 'Consultar e exportar os relatórios consolidados do evento',
        };
    }

    /**
     * Matriz perfil → permissões.
     *
     * @return array<string, list<self>>
     */
    public static function matrix(): array
    {
        return [
            // Administrador não vota por padrão: votes.cast é só do Votante elegível.
            RoleCode::Administrator->value => array_values(array_filter(self::cases(), fn (self $p) => $p !== self::VotesCast)),
            RoleCode::Manager->value => [
                self::PeopleView, self::EventsView,
                self::SectorsView, self::SectorsManage,
                self::MeetingsView, self::MeetingsManage,
                self::ClassesView, self::ParticipantsView, self::TeamsView,
                self::CompaniesView, self::ChallengesView,
                self::TasksView, self::TasksComment, self::TasksCreate, self::TasksOperate, self::TasksRoute,
                self::OccurrencesView, self::OccurrencesComment, self::OccurrencesCreate, self::OccurrencesOperate, self::OccurrencesRoute,
                self::EvaluationsProgressView,
                self::ResultsViewPublished,
            ],
            RoleCode::Editor->value => [
                self::PeopleView, self::EventsView, self::SectorsView, self::MeetingsView,
                self::ClassesView, self::ParticipantsView, self::TeamsView,
                self::CompaniesView, self::ChallengesView,
                self::TasksView, self::TasksComment, self::TasksCreate, self::TasksOperate,
                self::OccurrencesView, self::OccurrencesComment, self::OccurrencesCreate, self::OccurrencesOperate,
                self::ResultsViewPublished,
            ],
            RoleCode::Consultant->value => [
                self::PeopleView, self::EventsView, self::SectorsView, self::MeetingsView,
                self::ClassesView, self::ParticipantsView, self::TeamsView,
                self::CompaniesView, self::ChallengesView,
                self::TasksView, self::TasksComment,
                self::OccurrencesView, self::OccurrencesComment,
                self::EvaluationsProgressView,
                self::ResultsViewPublished,
                self::IntegrationsView, self::EventRegistrationsView, self::AttendanceView,
                self::ReportsView,
            ],
            RoleCode::Juror->value => [self::EventsView, self::EvaluationCriteriaView, self::EvaluationsOwn, self::ResultsViewPublished],
            RoleCode::Voter->value => [self::EventsView, self::VotesCast, self::ResultsViewPublished],
        ];
    }
}
