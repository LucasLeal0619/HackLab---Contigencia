<?php

namespace App\Domain\Audit;

/**
 * Ações auditadas. Módulos: auth, users, people, events, sectors, meetings, classes, participants, teams,
 * companies, challenges, tasks, occurrences, jurors, evaluations, voting, results.
 *
 * Avaliações: salvar rascunho não gera auditoria; o log registra transições (quem, qual avaliação,
 * jurado, equipe), não as notas e comentários.
 *
 * Pendências/ocorrências: comentário gera só interação (histórico), sem log de auditoria.
 *
 * Votação: só operações administrativas. O voto individual NUNCA gera log (não existe
 * PUBLIC_VOTE_CAST): registrar "usuário X votou na equipe Y" criaria outra tabela cruzando
 * identidade e escolha. public_votes já é a fonte de integridade.
 */
final class AuditAction
{
    public const LOGIN = 'LOGIN';

    public const LOGIN_FAILED = 'LOGIN_FAILED';

    public const LOGIN_BLOCKED_INACTIVE = 'LOGIN_BLOCKED_INACTIVE';

    public const LOGOUT = 'LOGOUT';

    /** Primeiro acesso concluído pela própria pessoa (Complemento 11.1). Sem senha nem token. */
    public const USER_SELF_ACTIVATED = 'USER_SELF_ACTIVATED';

    /** Senha redefinida pelo link enviado ao e-mail (Complemento 11.1). Sem senha nem token. */
    public const PASSWORD_RESET_COMPLETED = 'PASSWORD_RESET_COMPLETED';

    public const USER_CREATED = 'USER_CREATED';

    public const USER_UPDATED = 'USER_UPDATED';

    public const USER_ROLE_CHANGED = 'USER_ROLE_CHANGED';

    public const USER_ACTIVATED = 'USER_ACTIVATED';

    public const USER_INACTIVATED = 'USER_INACTIVATED';

    public const PERSON_CREATED = 'PERSON_CREATED';

    public const PERSON_UPDATED = 'PERSON_UPDATED';

    public const USER_SECTOR_CHANGED = 'USER_SECTOR_CHANGED';

    public const EVENT_CREATED = 'EVENT_CREATED';

    public const EVENT_UPDATED = 'EVENT_UPDATED';

    public const EVENT_DAY_CREATED = 'EVENT_DAY_CREATED';

    public const EVENT_DAY_UPDATED = 'EVENT_DAY_UPDATED';

    public const SECTOR_CREATED = 'SECTOR_CREATED';

    public const SECTOR_UPDATED = 'SECTOR_UPDATED';

    public const SECTOR_ACTIVATED = 'SECTOR_ACTIVATED';

    public const SECTOR_INACTIVATED = 'SECTOR_INACTIVATED';

    public const MEETING_CREATED = 'MEETING_CREATED';

    public const MEETING_UPDATED = 'MEETING_UPDATED';

    public const CLASS_CREATED = 'CLASS_CREATED';

    public const CLASS_UPDATED = 'CLASS_UPDATED';

    public const CLASS_ACTIVATED = 'CLASS_ACTIVATED';

    public const CLASS_INACTIVATED = 'CLASS_INACTIVATED';

    public const PARTICIPANT_CREATED = 'PARTICIPANT_CREATED';

    public const PARTICIPANT_UPDATED = 'PARTICIPANT_UPDATED';

    public const PARTICIPANT_STATUS_CHANGED = 'PARTICIPANT_STATUS_CHANGED';

    public const TEAM_CREATED = 'TEAM_CREATED';

    public const TEAM_UPDATED = 'TEAM_UPDATED';

    public const PARTICIPANT_ADDED_TO_TEAM = 'PARTICIPANT_ADDED_TO_TEAM';

    public const PARTICIPANT_REMOVED_FROM_TEAM = 'PARTICIPANT_REMOVED_FROM_TEAM';

    /** Movimentação entre equipes: um único log com before/after. */
    public const PARTICIPANT_TEAM_CHANGED = 'PARTICIPANT_TEAM_CHANGED';

    public const COMPANY_CREATED = 'COMPANY_CREATED';

    public const COMPANY_UPDATED = 'COMPANY_UPDATED';

    public const COMPANY_STATUS_CHANGED = 'COMPANY_STATUS_CHANGED';

    public const COMPANY_REPRESENTATIVE_ADDED = 'COMPANY_REPRESENTATIVE_ADDED';

    public const COMPANY_REPRESENTATIVE_UPDATED = 'COMPANY_REPRESENTATIVE_UPDATED';

    public const COMPANY_REPRESENTATIVE_STATUS_CHANGED = 'COMPANY_REPRESENTATIVE_STATUS_CHANGED';

    public const CHALLENGE_CREATED = 'CHALLENGE_CREATED';

    public const CHALLENGE_UPDATED = 'CHALLENGE_UPDATED';

    public const CHALLENGE_STATUS_CHANGED = 'CHALLENGE_STATUS_CHANGED';

    public const CHALLENGE_TEAM_ASSIGNED = 'CHALLENGE_TEAM_ASSIGNED';

    /** Desafio movido de uma equipe para outra: um único log com before/after. */
    public const CHALLENGE_TEAM_CHANGED = 'CHALLENGE_TEAM_CHANGED';

    public const CHALLENGE_TEAM_UNASSIGNED = 'CHALLENGE_TEAM_UNASSIGNED';

    public const TASK_CREATED = 'TASK_CREATED';

    public const TASK_UPDATED = 'TASK_UPDATED';

    public const TASK_STATUS_CHANGED = 'TASK_STATUS_CHANGED';

    public const TASK_FORWARDED = 'TASK_FORWARDED';

    public const TASK_COMPLETED = 'TASK_COMPLETED';

    public const TASK_REOPENED = 'TASK_REOPENED';

    public const OCCURRENCE_CREATED = 'OCCURRENCE_CREATED';

    public const OCCURRENCE_UPDATED = 'OCCURRENCE_UPDATED';

    public const OCCURRENCE_STATUS_CHANGED = 'OCCURRENCE_STATUS_CHANGED';

    public const OCCURRENCE_FORWARDED = 'OCCURRENCE_FORWARDED';

    public const OCCURRENCE_RESOLVED = 'OCCURRENCE_RESOLVED';

    public const OCCURRENCE_REOPENED = 'OCCURRENCE_REOPENED';

    /** Ocorrência gerou pendência: um único log (sem TASK_CREATED separado). */
    public const OCCURRENCE_TASK_GENERATED = 'OCCURRENCE_TASK_GENERATED';

    public const JUROR_CREATED = 'JUROR_CREATED';

    public const JUROR_UPDATED = 'JUROR_UPDATED';

    public const JUROR_STATUS_CHANGED = 'JUROR_STATUS_CHANGED';

    /** Lista de equipes do jurado alterada numa operação: um único log com before/after. */
    public const JUROR_ASSIGNMENTS_CHANGED = 'JUROR_ASSIGNMENTS_CHANGED';

    public const EVALUATION_CRITERION_CREATED = 'EVALUATION_CRITERION_CREATED';

    public const EVALUATION_CRITERION_UPDATED = 'EVALUATION_CRITERION_UPDATED';

    public const EVALUATION_CRITERION_STATUS_CHANGED = 'EVALUATION_CRITERION_STATUS_CHANGED';

    public const EVALUATION_SUBMITTED = 'EVALUATION_SUBMITTED';

    public const EVALUATION_REVISION_REQUESTED = 'EVALUATION_REVISION_REQUESTED';

    public const EVALUATION_RESUBMITTED = 'EVALUATION_RESUBMITTED';

    public const VOTING_SESSION_CREATED = 'VOTING_SESSION_CREATED';

    public const VOTING_SESSION_UPDATED = 'VOTING_SESSION_UPDATED';

    /** Lista final de candidatas definida numa operação: um único log com before/after. */
    public const VOTING_CANDIDATES_CHANGED = 'VOTING_CANDIDATES_CHANGED';

    /** Lista final de elegíveis definida numa operação: um único log com before/after. */
    public const VOTING_ELIGIBILITY_CHANGED = 'VOTING_ELIGIBILITY_CHANGED';

    public const VOTING_SESSION_OPENED = 'VOTING_SESSION_OPENED';

    public const VOTING_SESSION_CLOSED = 'VOTING_SESSION_CLOSED';

    public const VOTING_SESSION_CANCELLED = 'VOTING_SESSION_CANCELLED';

    public const RESULTS_PUBLISHED = 'RESULTS_PUBLISHED';

    public const RESULTS_PUBLICATION_REVOKED = 'RESULTS_PUBLICATION_REVOKED';

    /**
     * Integração Even3: só ações administrativas. Pessoas, inscrições, check-ins e participantes criados pela
     * integração não geram um log cada (o histórico operacional fica nas tabelas da integração).
     */
    public const EVEN3_INTEGRATION_CONFIGURED = 'EVEN3_INTEGRATION_CONFIGURED';

    public const EVEN3_TICKET_MAPPING_CHANGED = 'EVEN3_TICKET_MAPPING_CHANGED';

    /** Um por sync manual; o resultado fica em integration_sync_runs. */
    public const EVEN3_SYNC_TRIGGERED = 'EVEN3_SYNC_TRIGGERED';

    /** Um por execução da materialização (não um por participante criado). */
    public const EVEN3_PARTICIPANTS_MATERIALIZED = 'EVEN3_PARTICIPANTS_MATERIALIZED';

    /**
     * Reconciliação (Complemento 11.2): referência ao resultado. A criação do Jurado/representante mantém o
     * próprio log de entidade (JUROR_CREATED, COMPANY_REPRESENTATIVE_ADDED).
     */
    public const EVEN3_RECONCILIATION_RESOLVED = 'EVEN3_RECONCILIATION_RESOLVED';

    public const EVEN3_RECONCILIATION_IGNORED = 'EVEN3_RECONCILIATION_IGNORED';

    public const EVEN3_RECONCILIATION_RESTORED = 'EVEN3_RECONCILIATION_RESTORED';

    /** Nome/datas do retrato da Even3 aplicados ao evento (além do EVENT_UPDATED da edição). */
    public const EVEN3_EVENT_METADATA_APPLIED = 'EVEN3_EVENT_METADATA_APPLIED';

    /** Liberação de acesso para ativar a própria conta (sem senha; a conta nasce no primeiro acesso). */
    public const ACCESS_GRANT_CREATED = 'ACCESS_GRANT_CREATED';

    public const ACCESS_GRANT_REVOKED = 'ACCESS_GRANT_REVOKED';
}
