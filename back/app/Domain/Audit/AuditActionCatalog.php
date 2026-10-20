<?php

namespace App\Domain\Audit;

use ReflectionClass;

/**
 * Metadado de apresentação da auditoria: módulo e rótulo em português de cada código de AuditAction,
 * e rótulo de cada módulo. Não substitui as constantes (códigos de domínio) nem muda o que é persistido.
 *
 * Toda constante de AuditAction precisa estar aqui (garantido por teste), com o mesmo módulo usado na gravação.
 */
final class AuditActionCatalog
{
    public const MODULES = [
        'auth' => 'Autenticação',
        'users' => 'Usuários',
        'people' => 'Pessoas',
        'events' => 'Eventos',
        'sectors' => 'Setores',
        'meetings' => 'Reuniões',
        'classes' => 'Turmas',
        'participants' => 'Participantes',
        'teams' => 'Equipes',
        'companies' => 'Empresas',
        'challenges' => 'Desafios',
        'tasks' => 'Pendências',
        'occurrences' => 'Ocorrências',
        'jurors' => 'Jurados',
        'evaluations' => 'Avaliações',
        'voting' => 'Votação',
        'results' => 'Resultados',
        'integrations' => 'Integrações',
    ];

    /** @var array<string, array{0: string, 1: string}> código => [módulo, rótulo] */
    public const ACTIONS = [
        AuditAction::LOGIN => ['auth', 'Login realizado'],
        AuditAction::LOGIN_FAILED => ['auth', 'Tentativa de login recusada'],
        AuditAction::LOGIN_BLOCKED_INACTIVE => ['auth', 'Login bloqueado (conta inativa)'],
        AuditAction::LOGOUT => ['auth', 'Logout realizado'],
        AuditAction::USER_SELF_ACTIVATED => ['auth', 'Primeiro acesso concluído'],
        AuditAction::PASSWORD_RESET_COMPLETED => ['auth', 'Senha redefinida por e-mail'],

        AuditAction::USER_CREATED => ['users', 'Usuário criado'],
        AuditAction::USER_UPDATED => ['users', 'Usuário alterado'],
        AuditAction::USER_ROLE_CHANGED => ['users', 'Perfil do usuário alterado'],
        AuditAction::USER_SECTOR_CHANGED => ['users', 'Setor do usuário alterado'],
        AuditAction::USER_ACTIVATED => ['users', 'Usuário ativado'],
        AuditAction::USER_INACTIVATED => ['users', 'Usuário inativado'],

        AuditAction::PERSON_CREATED => ['people', 'Pessoa cadastrada'],
        AuditAction::PERSON_UPDATED => ['people', 'Pessoa alterada'],

        AuditAction::EVENT_CREATED => ['events', 'Evento criado'],
        AuditAction::EVENT_UPDATED => ['events', 'Evento alterado'],
        AuditAction::EVENT_DAY_CREATED => ['events', 'Dia do evento criado'],
        AuditAction::EVENT_DAY_UPDATED => ['events', 'Dia do evento alterado'],

        AuditAction::SECTOR_CREATED => ['sectors', 'Setor criado'],
        AuditAction::SECTOR_UPDATED => ['sectors', 'Setor alterado'],
        AuditAction::SECTOR_ACTIVATED => ['sectors', 'Setor ativado'],
        AuditAction::SECTOR_INACTIVATED => ['sectors', 'Setor inativado'],

        AuditAction::MEETING_CREATED => ['meetings', 'Reunião criada'],
        AuditAction::MEETING_UPDATED => ['meetings', 'Reunião alterada'],

        AuditAction::CLASS_CREATED => ['classes', 'Turma criada'],
        AuditAction::CLASS_UPDATED => ['classes', 'Turma alterada'],
        AuditAction::CLASS_ACTIVATED => ['classes', 'Turma ativada'],
        AuditAction::CLASS_INACTIVATED => ['classes', 'Turma inativada'],

        AuditAction::PARTICIPANT_CREATED => ['participants', 'Participante cadastrado'],
        AuditAction::PARTICIPANT_UPDATED => ['participants', 'Participante alterado'],
        AuditAction::PARTICIPANT_STATUS_CHANGED => ['participants', 'Status do participante alterado'],

        AuditAction::TEAM_CREATED => ['teams', 'Equipe criada'],
        AuditAction::TEAM_UPDATED => ['teams', 'Equipe alterada'],
        AuditAction::PARTICIPANT_ADDED_TO_TEAM => ['teams', 'Participante incluído na equipe'],
        AuditAction::PARTICIPANT_REMOVED_FROM_TEAM => ['teams', 'Participante retirado da equipe'],
        AuditAction::PARTICIPANT_TEAM_CHANGED => ['teams', 'Participante movido de equipe'],

        AuditAction::COMPANY_CREATED => ['companies', 'Empresa cadastrada'],
        AuditAction::COMPANY_UPDATED => ['companies', 'Empresa alterada'],
        AuditAction::COMPANY_STATUS_CHANGED => ['companies', 'Status da empresa alterado'],
        AuditAction::COMPANY_REPRESENTATIVE_ADDED => ['companies', 'Representante incluído'],
        AuditAction::COMPANY_REPRESENTATIVE_UPDATED => ['companies', 'Representante alterado'],
        AuditAction::COMPANY_REPRESENTATIVE_STATUS_CHANGED => ['companies', 'Representante ativado/desativado'],

        AuditAction::CHALLENGE_CREATED => ['challenges', 'Desafio cadastrado'],
        AuditAction::CHALLENGE_UPDATED => ['challenges', 'Desafio alterado'],
        AuditAction::CHALLENGE_STATUS_CHANGED => ['challenges', 'Status do desafio alterado'],
        AuditAction::CHALLENGE_TEAM_ASSIGNED => ['challenges', 'Desafio distribuído à equipe'],
        AuditAction::CHALLENGE_TEAM_CHANGED => ['challenges', 'Desafio movido para outra equipe'],
        AuditAction::CHALLENGE_TEAM_UNASSIGNED => ['challenges', 'Desafio retirado da equipe'],

        AuditAction::TASK_CREATED => ['tasks', 'Pendência criada'],
        AuditAction::TASK_UPDATED => ['tasks', 'Pendência alterada'],
        AuditAction::TASK_STATUS_CHANGED => ['tasks', 'Status da pendência alterado'],
        AuditAction::TASK_FORWARDED => ['tasks', 'Pendência encaminhada'],
        AuditAction::TASK_COMPLETED => ['tasks', 'Pendência concluída'],
        AuditAction::TASK_REOPENED => ['tasks', 'Pendência reaberta'],

        AuditAction::OCCURRENCE_CREATED => ['occurrences', 'Ocorrência registrada'],
        AuditAction::OCCURRENCE_UPDATED => ['occurrences', 'Ocorrência alterada'],
        AuditAction::OCCURRENCE_STATUS_CHANGED => ['occurrences', 'Status da ocorrência alterado'],
        AuditAction::OCCURRENCE_FORWARDED => ['occurrences', 'Ocorrência encaminhada'],
        AuditAction::OCCURRENCE_RESOLVED => ['occurrences', 'Ocorrência resolvida'],
        AuditAction::OCCURRENCE_REOPENED => ['occurrences', 'Ocorrência reaberta'],
        AuditAction::OCCURRENCE_TASK_GENERATED => ['occurrences', 'Pendência gerada a partir da ocorrência'],

        AuditAction::JUROR_CREATED => ['jurors', 'Jurado cadastrado'],
        AuditAction::JUROR_UPDATED => ['jurors', 'Jurado alterado'],
        AuditAction::JUROR_STATUS_CHANGED => ['jurors', 'Status do jurado alterado'],
        AuditAction::JUROR_ASSIGNMENTS_CHANGED => ['jurors', 'Equipes do jurado alteradas'],

        AuditAction::EVALUATION_CRITERION_CREATED => ['evaluations', 'Critério de avaliação criado'],
        AuditAction::EVALUATION_CRITERION_UPDATED => ['evaluations', 'Critério de avaliação alterado'],
        AuditAction::EVALUATION_CRITERION_STATUS_CHANGED => ['evaluations', 'Critério de avaliação ativado/inativado'],
        AuditAction::EVALUATION_SUBMITTED => ['evaluations', 'Avaliação enviada'],
        AuditAction::EVALUATION_REVISION_REQUESTED => ['evaluations', 'Revisão de avaliação solicitada'],
        AuditAction::EVALUATION_RESUBMITTED => ['evaluations', 'Avaliação reenviada'],

        AuditAction::VOTING_SESSION_CREATED => ['voting', 'Sessão de votação criada'],
        AuditAction::VOTING_SESSION_UPDATED => ['voting', 'Sessão de votação alterada'],
        AuditAction::VOTING_CANDIDATES_CHANGED => ['voting', 'Equipes candidatas alteradas'],
        AuditAction::VOTING_ELIGIBILITY_CHANGED => ['voting', 'Eleitores elegíveis alterados'],
        AuditAction::VOTING_SESSION_OPENED => ['voting', 'Votação aberta'],
        AuditAction::VOTING_SESSION_CLOSED => ['voting', 'Votação encerrada'],
        AuditAction::VOTING_SESSION_CANCELLED => ['voting', 'Sessão de votação cancelada'],

        AuditAction::RESULTS_PUBLISHED => ['results', 'Resultados publicados'],
        AuditAction::RESULTS_PUBLICATION_REVOKED => ['results', 'Publicação de resultados revogada'],

        AuditAction::EVEN3_INTEGRATION_CONFIGURED => ['integrations', 'Integração Even3 configurada'],
        AuditAction::EVEN3_TICKET_MAPPING_CHANGED => ['integrations', 'Mapeamento de entrada Even3 alterado'],
        AuditAction::EVEN3_SYNC_TRIGGERED => ['integrations', 'Sincronização Even3 disparada'],
        AuditAction::EVEN3_PARTICIPANTS_MATERIALIZED => ['integrations', 'Participantes criados a partir das inscrições Even3'],
        AuditAction::EVEN3_RECONCILIATION_RESOLVED => ['integrations', 'Candidato da Even3 reconciliado'],
        AuditAction::EVEN3_RECONCILIATION_IGNORED => ['integrations', 'Candidato da Even3 ignorado'],
        AuditAction::EVEN3_RECONCILIATION_RESTORED => ['integrations', 'Candidato da Even3 restaurado para revisão'],
        AuditAction::EVEN3_EVENT_METADATA_APPLIED => ['integrations', 'Dados do evento na Even3 aplicados'],
        AuditAction::ACCESS_GRANT_CREATED => ['users', 'Acesso liberado para primeiro acesso'],
        AuditAction::ACCESS_GRANT_REVOKED => ['users', 'Liberação de acesso revogada'],
    ];

    /**
     * Rótulo humano; códigos fora do catálogo (ex.: de testes) aparecem como o próprio código.
     */
    public static function actionLabel(string $action): string
    {
        return self::ACTIONS[$action][1] ?? $action;
    }

    public static function moduleOf(string $action): ?string
    {
        return self::ACTIONS[$action][0] ?? null;
    }

    public static function moduleLabel(string $module): string
    {
        return self::MODULES[$module] ?? $module;
    }

    /**
     * Todos os códigos declarados em AuditAction (fonte de verdade).
     *
     * @return list<string>
     */
    public static function declaredActions(): array
    {
        return array_values((new ReflectionClass(AuditAction::class))->getConstants());
    }

    /**
     * @return list<array{code: string, label: string, module: string}>
     */
    public static function actions(): array
    {
        return collect(self::ACTIONS)
            ->map(fn (array $definition, string $code) => ['code' => $code, 'label' => $definition[1], 'module' => $definition[0]])
            ->values()
            ->all();
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    public static function modules(): array
    {
        return collect(self::MODULES)->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])->values()->all();
    }
}
