// Converte um relatório neutro (montado pelos templates a partir do payload do backend) na definição
// de documento do pdfmake. Função pura: não chama API, não lê DOM nem armazenamento do navegador.
import { NOT_INFORMED, text } from '@/js/services/reports/shared'

const BLUE = '#00498c'
const ORANGE = '#f29100'
const INK = '#1b2836'
const MUTED = '#5a6876'
const RULE = '#d5dde6'
const HEAD_FILL = '#eef2f6'
const ZEBRA = '#f8fafc'
const EMPTY = 'Nenhum registro para esta seção.'

// A4 retrato: 595 × 842 pt. Margens ≈ 17 mm laterais, 25 mm topo (cabeçalho), 20 mm base.
const PAGE_MARGINS = [48, 72, 48, 58]

const tableLayout = {
  hLineWidth: (i, node) => (i === 0 || i === node.table.body.length ? 0.6 : 0.4),
  vLineWidth: () => 0,
  hLineColor: (i) => (i === 1 ? '#9fb0c2' : RULE),
  fillColor: (row) => (row === 0 ? HEAD_FILL : row % 2 === 0 ? ZEBRA : null),
  paddingLeft: () => 5,
  paddingRight: () => 5,
  paddingTop: () => 3.5,
  paddingBottom: () => 3.5,
}

const kvLayout = {
  hLineWidth: (i, node) => (i === 0 ? 0 : i === node.table.body.length ? 0.6 : 0.4),
  vLineWidth: () => 0,
  hLineColor: () => RULE,
  paddingLeft: () => 0,
  paddingRight: () => 0,
  paddingTop: () => 4,
  paddingBottom: () => 4,
}

function kvBlock(rows) {
  if (!rows.length) return { text: EMPTY, italics: true, color: MUTED, margin: [0, 2, 0, 8] }
  return {
    table: {
      widths: ['*', 'auto'],
      dontBreakRows: true,
      body: rows.map(([label, value]) => [
        { text: label, color: MUTED },
        { text: text(value), bold: true, alignment: 'right', color: INK },
      ]),
    },
    layout: kvLayout,
    margin: [0, 2, 0, 8],
  }
}

// Célula simples (texto) ou com linha de detalhe abaixo: { text, sub }.
function tableCell(cell) {
  if (cell && typeof cell === 'object' && 'text' in cell) {
    return { stack: [{ text: text(cell.text, '—'), style: 'td' }, ...(cell.sub ? [{ text: cell.sub, style: 'tdSub' }] : [])] }
  }
  return { text: text(cell, '—'), style: 'td' }
}

function dataTable({ head, rows, widths, empty }) {
  if (!rows.length) return { text: empty || EMPTY, italics: true, color: MUTED, margin: [0, 2, 0, 8] }
  return {
    table: {
      headerRows: 1,
      // Cabeçalho nunca fica sozinho no fim da página: segue junto da primeira linha.
      keepWithHeaderRows: 1,
      dontBreakRows: true,
      widths: widths || head.map(() => '*'),
      body: [
        head.map((label) => ({ text: label.toUpperCase(), style: 'th' })),
        ...rows.map((row) => row.map((cell) => tableCell(cell))),
      ],
    },
    layout: tableLayout,
    margin: [0, 2, 0, 10],
  }
}

function blockNodes(blocks, prefix) {
  let subIndex = 0
  return blocks.flatMap((block) => {
    if (block.kind === 'kv') return [kvBlock(block.rows)]
    if (block.kind === 'table') return [dataTable(block)]
    if (block.kind === 'note') return [{ text: block.text, style: 'note' }]
    if (block.kind === 'sub') {
      subIndex += 1
      return [
        { text: `${prefix}.${subIndex}  ${block.title}`, style: 'h2', headlineLevel: 2 },
        ...blockNodes(block.blocks, `${prefix}.${subIndex}`),
      ]
    }
    return []
  })
}

function heading(number, title) {
  return { text: `${number}. ${title}`, style: 'h1', headlineLevel: 1 }
}

function coverPage(report, ctx) {
  const info = ctx.event
  const details = [
    ['Evento', info.name],
    ['Período', info.period],
    ['Dias', info.days],
    ['Local', info.location],
  ]
  return [
    ctx.logoWhite ? { image: 'logoWhite', width: 118, margin: [0, 0, 0, 10] } : { text: 'Senac', color: '#fff', bold: true, fontSize: 18 },
    { text: [{ text: 'Hack', color: '#fff' }, { text: 'Lab', color: ORANGE }], bold: true, fontSize: 20 },
    { text: 'RELATÓRIO', color: '#cfe0f1', fontSize: 9, bold: true, characterSpacing: 2, margin: [0, 74, 0, 6] },
    { text: report.title, color: '#fff', fontSize: 28, bold: true, lineHeight: 1.1 },
    { text: info.name, color: '#dbe7f3', fontSize: 13, margin: [0, 8, 0, 0] },
    {
      margin: [0, 120, 0, 0],
      table: {
        widths: [110, '*'],
        body: details.map(([label, value]) => [{ text: label, color: MUTED }, { text: text(value), color: INK, bold: true }]),
      },
      layout: { ...kvLayout, hLineWidth: (i) => (i === 0 ? 0 : 0.4) },
    },
    {
      margin: [0, 28, 0, 0],
      stack: [
        { text: [{ text: 'Documento gerado em: ', color: MUTED }, { text: ctx.generatedAt, bold: true }] },
        { text: [{ text: 'Gerado por: ', color: MUTED }, { text: `${ctx.author.name}${ctx.author.role ? ` · ${ctx.author.role}` : ''}`, bold: true }], margin: [0, 4, 0, 0] },
      ],
    },
  ]
}

function identification(report, ctx) {
  const info = ctx.event
  return [
    { text: 'Identificação do relatório', style: 'h1' },
    kvBlock([
      ['Relatório', report.title],
      ['Evento', info.name],
      ['Descrição', info.description],
      ['Período', info.period],
      ['Dias do evento', info.days],
      ['Local', info.location],
      ['Fuso horário', info.timezone],
      ['Situação do evento', info.status],
      ['Data da geração', ctx.generatedAt],
      ['Responsável pela geração', ctx.author.name],
      ['Perfil', ctx.author.role || NOT_INFORMED],
      ['Origem dos dados', 'HackLab — dados registrados no backend'],
    ]),
  ]
}

function contents(titles) {
  return [
    { text: 'Sumário', style: 'h1', margin: [0, 18, 0, 6] },
    {
      table: {
        widths: [22, '*'],
        body: titles.map((title, index) => [{ text: `${index + 1}.`, color: BLUE, bold: true }, { text: title, color: INK }]),
      },
      layout: kvLayout,
    },
  ]
}

// report: { title, short, summary: [[rótulo, valor]], sections: [{ title, blocks }], notes: [] }
// ctx: { event: {...textos}, generatedAt, author: { name, role }, logoWhite?, logoNavy? }
export function buildDocument(report, ctx) {
  const notes = [
    ...(report.notes || []),
    'O relatório representa o estado do banco de dados do HackLab no momento da geração.',
    'Informações não cadastradas no sistema são indicadas como "Não informado".',
  ]
  const sections = [
    { title: 'Resumo executivo', blocks: [{ kind: 'kv', rows: report.summary }] },
    ...report.sections,
    { title: 'Observações', blocks: [] },
  ]
  const body = sections.flatMap((item, index) => {
    const number = index + 1
    if (item.title === 'Observações') {
      return [heading(number, item.title), { ul: notes, style: 'body', margin: [0, 2, 0, 0] }]
    }
    return [heading(number, item.title), ...blockNodes(item.blocks, number)]
  })

  return {
    // Imagens registradas uma única vez no arquivo, mesmo aparecendo em todas as páginas.
    images: Object.fromEntries([['logoWhite', ctx.logoWhite], ['logoNavy', ctx.logoNavy]].filter(([, value]) => value)),
    pageSize: 'A4',
    pageOrientation: 'portrait',
    pageMargins: PAGE_MARGINS,
    info: {
      title: `${report.title} — ${ctx.event.name}`,
      author: 'HackLab',
      subject: 'Hackathon',
      creator: 'HackLab',
      producer: 'HackLab',
      keywords: `HackLab, relatório, ${report.short}`,
    },
    background: (page) => (page === 1
      ? { canvas: [{ type: 'rect', x: 0, y: 0, w: 595.28, h: 400, color: BLUE }, { type: 'rect', x: 0, y: 400, w: 595.28, h: 5, color: ORANGE }] }
      : null),
    header: (page) => (page === 1 ? null : {
      margin: [48, 26, 48, 0],
      stack: [
        {
          columns: [
            ctx.logoNavy ? { image: 'logoNavy', width: 42, margin: [0, 0, 8, 0] } : { text: '', width: 0 },
            { text: [{ text: 'Hack', color: BLUE }, { text: 'Lab', color: ORANGE }], bold: true, fontSize: 10, width: 'auto', margin: [0, 2, 0, 0] },
            { text: report.short, alignment: 'right', fontSize: 8.5, color: MUTED, margin: [0, 3, 0, 0] },
          ],
          columnGap: 0,
        },
        { canvas: [{ type: 'line', x1: 0, y1: 6, x2: 499.28, y2: 6, lineWidth: 0.6, lineColor: RULE }] },
      ],
    }),
    footer: (page, pages) => ({
      margin: [48, 18, 48, 0],
      stack: [
        { canvas: [{ type: 'line', x1: 0, y1: 0, x2: 499.28, y2: 0, lineWidth: 0.6, lineColor: RULE }] },
        {
          margin: [0, 6, 0, 0],
          columns: [
            { text: `HackLab • Relatório gerado em ${ctx.generatedAt}`, fontSize: 7.5, color: MUTED },
            { text: `Página ${page} de ${pages}`, alignment: 'right', fontSize: 7.5, color: MUTED },
          ],
        },
      ],
    }),
    // Evita título sozinho no fim da página: empurra para a próxima.
    pageBreakBefore: (node, following) => Boolean(node.headlineLevel) && (following.length === 0 || node.startPosition.verticalRatio > (node.headlineLevel === 1 ? 0.8 : 0.86)),
    content: [
      ...coverPage(report, ctx),
      { text: '', pageBreak: 'after' },
      ...identification(report, ctx),
      ...contents(sections.map((item) => item.title)),
      { text: '', pageBreak: 'after' },
      ...body,
    ],
    defaultStyle: { font: 'Roboto', fontSize: 9.5, color: INK, lineHeight: 1.2 },
    styles: {
      h1: { fontSize: 16, bold: true, color: BLUE, margin: [0, 16, 0, 6] },
      h2: { fontSize: 11.5, bold: true, color: INK, margin: [0, 8, 0, 4] },
      th: { fontSize: 7.5, bold: true, color: INK, characterSpacing: 0.3 },
      td: { fontSize: 8.5, color: INK },
      tdSub: { fontSize: 7.5, color: MUTED, margin: [0, 2, 0, 0] },
      note: { fontSize: 8, italics: true, color: MUTED, margin: [0, 0, 0, 10] },
      body: { fontSize: 9.5, color: INK },
    },
  }
}
