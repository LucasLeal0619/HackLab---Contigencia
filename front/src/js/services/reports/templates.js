// Templates dos quatro relatórios: payload do backend → relatório neutro { title, short, file, summary, sections, notes }.
// Funções puras (sem API, DOM ou armazenamento). Códigos de enum viram rótulos pt-BR só aqui, na apresentação.
import { count, dateTime, dayName, enumRows, label, name, percent, text, yesNo } from '@/js/services/reports/shared'
import { formatNumber } from '@/js/utils/format'

const PRESENCE = { PRESENT: 'Presente', NO_RECORD: 'Sem registro' }

function presenceText(code, compact) {
  if (compact) return code === 'PRESENT' ? 'Sim' : '—'
  return PRESENCE[code] || PRESENCE.NO_RECORD
}

function dayById(payload) {
  return Object.fromEntries((payload.event?.days || []).map((day) => [day.id, day]))
}

function presenceNote() {
  return { kind: 'note', text: 'Presença = check-in registrado na Even3 e sincronizado. Sem check-in aparece "Sem registro", que não significa ausência.' }
}

// ---------- Relatório Geral ----------
export function generalReport(payload) {
  const s = payload.summary
  const days = dayById(payload)
  const tz = payload.event?.timezone
  return {
    title: payload.title,
    short: 'Relatório Geral',
    file: 'Relatorio_Geral',
    summary: [
      ['Participantes cadastrados', count(s.participants.total)],
      ['Participantes sem equipe', count(s.participants.without_team)],
      ['Turmas', count(s.classes.total)],
      ['Equipes', count(s.teams.total)],
      ['Empresas', count(s.companies.total)],
      ['Desafios', count(s.challenges.total)],
      ['Setores ativos', count(s.sectors.active)],
      ['Reuniões', count(s.meetings.total)],
      ['Pendências abertas', count(s.tasks.open)],
      ['Pendências vencidas', count(s.tasks.overdue)],
      ['Ocorrências abertas', count(s.occurrences.open)],
      ['Inscrições externas', count(s.registrations.total)],
      ['Inscrições confirmadas', count(s.registrations.by_status?.CONFIRMED)],
      ['Pessoas com presença registrada', count(s.attendance.people_with_presence)],
      ['Avaliações esperadas', count(s.evaluations.expected)],
      ['Avaliações enviadas', count(s.evaluations.submitted)],
      ['Progresso das avaliações', percent(s.evaluations.completion_percent)],
      ['Sessões de votação', count(s.voting.sessions)],
      ['Resultado oficial publicado', s.official_result.published ? `Sim (versão ${s.official_result.version})` : 'Não'],
    ],
    sections: [
      {
        title: 'Participantes e equipes',
        blocks: [
          { kind: 'sub', title: 'Participantes por status', blocks: [{ kind: 'kv', rows: enumRows('participantStatus', s.participants.by_status) }] },
          { kind: 'sub', title: 'Composição', blocks: [{ kind: 'kv', rows: [
            ['Com equipe', count(s.participants.with_team)],
            ['Sem equipe', count(s.participants.without_team)],
            ['Turmas (ativas / total)', `${count(s.classes.active)} / ${count(s.classes.total)}`],
            ...enumRows('teamStatus', s.teams.by_status).map(([l, v]) => [`Equipes ${l.toLowerCase()}`, v]),
          ] }] },
        ],
      },
      {
        title: 'Empresas e desafios',
        blocks: [
          { kind: 'sub', title: 'Empresas por status', blocks: [{ kind: 'kv', rows: enumRows('companyStatus', s.companies.by_status) }] },
          { kind: 'sub', title: 'Desafios por status', blocks: [{ kind: 'kv', rows: [...enumRows('challengeStatus', s.challenges.by_status), ['Com equipe', count(s.challenges.with_team)]] }] },
        ],
      },
      {
        title: 'Gestão',
        blocks: [
          { kind: 'sub', title: 'Setores e reuniões', blocks: [{ kind: 'kv', rows: [
            ['Setores (ativos / total)', `${count(s.sectors.active)} / ${count(s.sectors.total)}`],
            ...enumRows('meetingStatus', s.meetings.by_status).map(([l, v]) => [`Reuniões: ${l.toLowerCase()}`, v]),
          ] }] },
          { kind: 'sub', title: 'Pendências', blocks: [{ kind: 'kv', rows: [...enumRows('taskStatus', s.tasks.by_status), ['Vencidas', count(s.tasks.overdue)]] }] },
          { kind: 'sub', title: 'Ocorrências', blocks: [{ kind: 'kv', rows: enumRows('occurrenceStatus', s.occurrences.by_status) }] },
        ],
      },
      {
        title: 'Inscrições e presença',
        blocks: [
          { kind: 'sub', title: 'Inscrições externas por status', blocks: [{ kind: 'kv', rows: enumRows('registrationStatus', s.registrations.by_status) }] },
          {
            kind: 'sub',
            title: 'Presença por dia',
            blocks: [
              { kind: 'table', head: ['Dia', 'Pessoas com presença', 'Check-ins'], widths: ['*', 110, 70], rows: s.attendance.by_day.map((row) => [dayName(days[row.event_day_id]), count(row.people_present), count(row.check_ins)]), empty: 'Nenhum dia cadastrado para o evento.' },
              presenceNote(),
            ],
          },
        ],
      },
      {
        title: 'Encerramento',
        blocks: [{ kind: 'kv', rows: [
          ['Avaliações esperadas / enviadas / pendentes', `${count(s.evaluations.expected)} / ${count(s.evaluations.submitted)} / ${count(s.evaluations.pending)}`],
          ['Progresso das avaliações', percent(s.evaluations.completion_percent)],
          ...enumRows('votingStatus', s.voting.by_status).map(([l, v]) => [`Sessões de votação: ${l.toLowerCase()}`, v]),
          ['Resultado oficial publicado', yesNo(s.official_result.published)],
          ...(s.official_result.published ? [['Versão / publicado em', `${s.official_result.version} · ${dateTime(s.official_result.published_at, tz)}`]] : []),
        ] }],
      },
    ],
    notes: [],
  }
}

// ---------- Participantes e Presença ----------
export function participantsReport(payload) {
  const s = payload.summary
  const days = payload.event?.days || []
  const byId = dayById(payload)
  // Até 3 dias cabe o texto completo; mais que isso, colunas compactas para não estourar a página.
  const compact = days.length > 3
  return {
    title: payload.title,
    short: 'Participantes e Presença',
    file: 'Participantes_Presenca',
    summary: [
      ['Participantes', count(s.total)],
      ...enumRows('participantStatus', s.by_status),
      ['Com equipe', count(s.with_team)],
      ['Sem equipe', count(s.without_team)],
      ['Com presença em ao menos um dia', count(s.with_any_presence)],
    ],
    sections: [
      {
        title: 'Resumo por turma e por dia',
        blocks: [
          { kind: 'sub', title: 'Participantes por turma', blocks: [{ kind: 'table', head: ['Turma', 'Participantes'], widths: ['*', 90], rows: s.by_class.map((row) => [row.class ? row.class.name : 'Sem turma', count(row.total)]) }] },
          { kind: 'sub', title: 'Presença registrada por dia', blocks: [{ kind: 'table', head: ['Dia', 'Participantes presentes'], widths: ['*', 130], rows: s.presence_by_day.map((row) => [dayName(byId[row.event_day_id]), count(row.participants_present)]), empty: 'Nenhum dia cadastrado para o evento.' }, presenceNote()] },
        ],
      },
      {
        title: 'Lista de participantes',
        blocks: [{
          kind: 'table',
          head: ['Participante', 'Turma', 'Status', 'Equipe', ...days.map((day) => day.label || `Dia ${day.day_number}`)],
          widths: ['*', 70, 60, 70, ...days.map(() => (compact ? 34 : 52))],
          rows: payload.participants.map((row) => [
            row.name,
            name(row.class),
            label('participantStatus', row.status),
            name(row.team),
            ...days.map((day) => presenceText(row.presence?.[String(day.id)], compact)),
          ]),
          empty: 'Nenhum participante cadastrado.',
        }, ...(compact ? [{ kind: 'note', text: 'Sim = presença registrada no dia; — = sem registro.' }] : [])],
      },
    ],
    notes: ['Participante sem inscrição externa aparece como "Sem registro" em todos os dias; isso não é erro.'],
  }
}

// Dia, horário, equipe e solução (quando resolvida) numa linha de detalhe da ocorrência.
function occurrenceDetails(item, tz) {
  return [
    item.event_day ? text(item.event_day.label, `Dia ${item.event_day.day_number}`) : null,
    item.occurred_at ? dateTime(item.occurred_at, tz) : null,
    item.team ? `Equipe: ${item.team.name}` : null,
    item.status === 'RESOLVED' && item.resolution ? `Solução: ${item.resolution}` : null,
  ].filter(Boolean).join(' · ')
}

// ---------- Gestão e Operação ----------
export function operationsReport(payload) {
  const tz = payload.event?.timezone
  const { sectors, meetings, companies, challenges, teams, tasks, occurrences } = payload
  return {
    title: payload.title,
    short: 'Gestão e Operação',
    file: 'Gestao_Operacao',
    summary: [
      ['Setores (ativos / inativos)', `${count(sectors.summary.active)} / ${count(sectors.summary.inactive)}`],
      ['Reuniões', count(meetings.summary.total)],
      ['Empresas', count(companies.summary.total)],
      ['Desafios', count(challenges.summary.total)],
      ['Equipes', count(teams.summary.total)],
      ['Pendências (abertas / vencidas)', `${count(tasks.summary.open)} / ${count(tasks.summary.overdue)}`],
      ['Ocorrências', count(occurrences.summary.total)],
    ],
    sections: [
      { title: 'Setores', blocks: [{ kind: 'table', head: ['Setor', 'Situação'], widths: ['*', 90], rows: sectors.items.map((item) => [item.name, item.active ? 'Ativo' : 'Inativo']) }] },
      {
        title: 'Reuniões',
        blocks: [
          { kind: 'kv', rows: [['Gerais', count(meetings.summary.general)], ['Setoriais', count(meetings.summary.sector)], ...enumRows('meetingStatus', meetings.summary.by_status)] },
          { kind: 'table', head: ['Reunião', 'Tipo', 'Setor', 'Data/hora', 'Local', 'Status'], widths: ['*', 50, 70, 75, 65, 55], rows: meetings.items.map((item) => [item.title, label('meetingScope', item.scope), name(item.sector), dateTime(item.scheduled_at, tz), text(item.location, '—'), label('meetingStatus', item.status)]) },
        ],
      },
      {
        title: 'Empresas, desafios e equipes',
        blocks: [
          { kind: 'sub', title: 'Empresas', blocks: [{ kind: 'table', head: ['Empresa', 'Tipo', 'Status', 'Desafios'], widths: ['*', 80, 70, 50], rows: companies.items.map((item) => [item.name, label('companyType', item.type), label('companyStatus', item.status), count(item.challenges_count)]) }] },
          { kind: 'sub', title: 'Desafios', blocks: [{ kind: 'table', head: ['Desafio', 'Empresa', 'Status', 'Equipe'], widths: ['*', 90, 80, 80], rows: challenges.items.map((item) => [item.title, name(item.company), label('challengeStatus', item.status), name(item.team)]) }] },
          { kind: 'sub', title: 'Equipes', blocks: [{ kind: 'table', head: ['Equipe', 'Código', 'Status', 'Membros', 'Desafio'], widths: ['*', 50, 50, 45, 110], rows: teams.items.map((item) => [item.name, text(item.code, '—'), label('teamStatus', item.status), count(item.members_count), item.challenge ? item.challenge.title : '—']) }] },
        ],
      },
      {
        title: 'Pendências',
        blocks: [
          { kind: 'sub', title: 'Resumo', blocks: [{ kind: 'kv', rows: [...enumRows('taskStatus', tasks.summary.by_status), ['Vencidas', count(tasks.summary.overdue)], ...enumRows('priority', tasks.summary.by_priority).map(([l, v]) => [`Prioridade ${l.toLowerCase()}`, v])] }] },
          { kind: 'sub', title: 'Por setor responsável', blocks: [{ kind: 'table', head: ['Setor', 'Total', 'Abertas'], widths: ['*', 60, 60], rows: tasks.summary.by_responsible_sector.map((row) => [name(row.sector), count(row.total), count(row.open)]) }] },
          { kind: 'sub', title: 'Lista', blocks: [{ kind: 'table', head: ['Ref.', 'Pendência', 'Origem', 'Responsável', 'Prioridade', 'Status', 'Prazo', 'Vencida'], widths: [45, '*', 55, 60, 45, 50, 60, 35], rows: tasks.items.map((item) => [item.reference, item.title, name(item.origin_sector), name(item.responsible_sector), label('priority', item.priority), label('taskStatus', item.status), item.due_at ? dateTime(item.due_at, tz) : '—', yesNo(item.overdue)]) }] },
        ],
      },
      {
        title: 'Ocorrências',
        blocks: [
          { kind: 'sub', title: 'Resumo', blocks: [{ kind: 'kv', rows: [...enumRows('occurrenceStatus', occurrences.summary.by_status), ...enumRows('occurrenceCategory', occurrences.summary.by_category).map(([l, v]) => [`Categoria: ${l}`, v]), ...enumRows('priority', occurrences.summary.by_priority).map(([l, v]) => [`Prioridade ${l.toLowerCase()}`, v])] }] },
          { kind: 'sub', title: 'Lista', blocks: [{ kind: 'table', head: ['Ref.', 'Ocorrência', 'Categoria', 'Setor', 'Prioridade', 'Status'], widths: [48, '*', 70, 70, 52, 58], rows: occurrences.items.map((item) => [item.reference, { text: item.title, sub: occurrenceDetails(item, tz) }, label('occurrenceCategory', item.category), name(item.responsible_sector), label('priority', item.priority), label('occurrenceStatus', item.status)]) }] },
        ],
      },
    ],
    notes: ['Atas, decisões, documentos e equipamentos não existem no HackLab e não fazem parte deste relatório.'],
  }
}

// ---------- Encerramento e Resultados ----------
export function closingReport(payload) {
  const tz = payload.event?.timezone
  const { evaluations, jurors, voting } = payload
  const official = payload.official_result
  const summary = evaluations.summary
  const officialBlocks = official.published
    ? [
      { kind: 'kv', rows: [['Versão da publicação', count(official.version)], ['Publicado em', dateTime(official.published_at, tz)], ['Sessão de votação', official.voting_session ? official.voting_session.name : '—']] },
      { kind: 'sub', title: 'Ranking técnico (jurados)', blocks: [{ kind: 'table', head: ['Posição', 'Equipe', 'Nota técnica', 'Avaliações válidas'], widths: [50, '*', 70, 80], rows: (official.technical?.teams || []).map((row) => [row.rank ? `${row.rank}º${row.tied ? ' (empate)' : ''}` : '—', row.team.name, row.technical_score === null ? '—' : formatNumber(row.technical_score, 2), count(row.valid_evaluations)]) }] },
      { kind: 'sub', title: 'Escolha do Público', blocks: [
        { kind: 'table', head: ['Posição', 'Equipe', 'Votos', '%'], widths: [50, '*', 60, 60], rows: (official.popular?.teams || []).map((row) => [row.rank ? `${row.rank}º${row.tied ? ' (empate)' : ''}` : '—', row.team.name, count(row.vote_count), percent(row.percentage)]) },
        { kind: 'kv', rows: [['Total de votos', count(official.popular?.total_votes)], ['Participação', official.turnout ? `${count(official.turnout.votes_cast)} de ${count(official.turnout.eligible_voters)} (${percent(official.turnout.turnout_percent)})` : '—']] },
      ] },
      { kind: 'note', text: 'Resultado técnico e Escolha do Público são classificações independentes: não existe nota combinada.' },
    ]
    : [{ kind: 'note', text: official.message || 'Resultado oficial ainda não publicado.' }]

  return {
    title: payload.title,
    short: 'Encerramento e Resultados',
    file: 'Encerramento_Resultados',
    summary: [
      ['Avaliações esperadas', count(summary.expected)],
      ['Avaliações enviadas', count(summary.submitted)],
      ['Avaliações pendentes', count(summary.pending)],
      ['Progresso', percent(summary.completion_percent)],
      ['Jurados (ativos / total)', `${count(jurors.by_status?.ACTIVE)} / ${count(jurors.total)}`],
      ['Sessões de votação', count(voting.length)],
      ['Resultado oficial publicado', official.published ? `Sim (versão ${official.version})` : 'Não'],
    ],
    sections: [
      {
        title: 'Avaliações técnicas (progresso)',
        blocks: [
          { kind: 'kv', rows: [['Rascunhos', count(summary.draft)], ['Revisão solicitada', count(summary.revision_requested)], ['Não iniciadas', count(summary.not_started)]] },
          { kind: 'table', head: ['Equipe', 'Jurados', 'Enviadas', 'Rascunho', 'Revisão', 'Não iniciadas', 'Progresso'], widths: ['*', 45, 45, 45, 45, 55, 55], rows: evaluations.teams.map((row) => [row.team.name, count(row.assigned_jurors), count(row.submitted), count(row.draft), count(row.revision_requested), count(row.not_started), percent(row.completion_percent)]) },
          { kind: 'note', text: 'Apenas o progresso: notas e comentários individuais dos jurados não fazem parte deste relatório.' },
        ],
      },
      {
        title: 'Jurados',
        blocks: [{ kind: 'kv', rows: [['Total', count(jurors.total)], ...enumRows('jurorStatus', jurors.by_status), ['Atribuições ativas', count(jurors.active_assignments)]] }],
      },
      {
        title: 'Votação do público',
        blocks: [
          { kind: 'table', head: ['Sessão', 'Status', 'Abertura', 'Encerramento', 'Equipes', 'Elegíveis', 'Votos', 'Particip.'], widths: ['*', 52, 62, 64, 40, 46, 32, 48], rows: voting.map((row) => [row.name, label('votingStatus', row.status), row.opened_at ? dateTime(row.opened_at, tz) : '—', row.closed_at ? dateTime(row.closed_at, tz) : '—', count(row.candidates), count(row.eligible_voters), count(row.votes_cast), percent(row.turnout_percent)]), empty: 'Nenhuma sessão de votação.' },
          { kind: 'note', text: 'Só a participação de cada sessão. Votos por equipe aparecem apenas no resultado oficial publicado; o voto de cada pessoa nunca é exibido.' },
        ],
      },
      { title: 'Resultado oficial', blocks: officialBlocks },
    ],
    notes: [],
  }
}
