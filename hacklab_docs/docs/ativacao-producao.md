# Ativação controlada em produção: evento, Even3 e e-mail

Roteiro para ligar, em etapas e com autorização do responsável em cada uma, o evento real, a integração Even3 e o envio de e-mails. Nenhuma etapa importa inscrições ou envia e-mail a participantes sem autorização explícita. Detalhes técnicos: [`integracao-even3.md`](integracao-even3.md) e [`infraestrutura.md`](infraestrutura.md) §3.2.7.

Estado de partida (26/10/2026): chave oficial da Even3 cadastrada no Render, `EVEN3_ENABLED=false`, `MAIL_MAILER=array`.

## 1. Evento real e setores (painel administrativo)

O evento e os setores pertencem ao HackLab: a Even3 não os cria. O evento interno precisa existir **antes** do vínculo com a Even3, porque o vínculo é feito dentro dele.

**1.1 Verificar se o evento já existe (só leitura).** Entre em produção como Administrador:
- o **Dashboard** mostra "Nenhum evento disponível" quando não há evento;
- com um evento, ele aparece no Dashboard; com mais de um, aparece o seletor de evento no topo.

Se o evento real já existir, use-o: não crie outro (o nome do evento não é único e um segundo cadastro gera duplicidade).

**1.2 Cadastrar o evento (se não existir).** Em **Configuração**, com os dados oficiais (nunca presumidos):
- nome, local, data de início e de término;
- fuso horário `America/Sao_Paulo` (sem ele, os check-ins não são ligados aos dias);
- status `PLANNED` (Planejado);
- clique **uma vez** em Salvar: um duplo clique pode criar dois eventos.

Depois, cadastre os **dias** do evento no mesmo painel: a presença da Even3 é ligada ao dia pela data local.

**1.3 Cadastrar os setores.** Em **Setores**, com o evento selecionado, um por vez. O nome é único dentro do evento, sem diferença de maiúsculas e minúsculas: repetir um nome é recusado. Setores não são apagados, só inativados.

**1.4 Conferir.** O Dashboard mostra o evento (nome, datas, dias); a tela Setores lista os setores; a tela Usuários oferece esses setores para Gestores e Editores.

## 2. Even3: validação somente leitura

**Pré-requisito:** evento interno cadastrado (etapa 1).

**2.1 Ligar a integração no Render (exige autorização).** `EVEN3_ENABLED=true`. O Render faz um novo deploy ao salvar.

Isso sozinho não sincroniza nada: sem um vínculo `ACTIVE`, nenhum caminho importa dados. O botão Sincronizar fica desabilitado, a API recusa ("Integração desativada"), a sincronização automática do front e o `even3:sync` só agem em vínculo `ACTIVE`, e o webhook recebido para vínculo inexistente ou desativado vira recibo `IGNORED`.

**2.2 Identificar o ID do evento oficial na Even3.** É o número do evento no painel do organizador da Even3. Não use o `793572`: é o evento de teste do PoC (12/10/2026). Um ID errado é recusado no passo seguinte (a Even3 responde "não encontrado" e nada é gravado).

**2.3 Vincular com status Desativada.** Em **Presença → Configuração Even3 → Editar vínculo**:
- **ID do evento na Even3:** o do passo 2.2;
- **Status: troque para "Desativada".** O formulário vem com "Ativa" por padrão. Salvo como Ativa, a sincronização automática do front importa todas as inscrições em até 10 minutos.

Ao salvar, o backend consulta `GET /events/{id}` na Even3 (somente leitura, com a chave do Render, que nunca aparece na tela, na resposta ou no log) e grava só o vínculo, o título e as datas externas. A auditoria registra `EVEN3_INTEGRATION_CONFIGURED`.

**2.4 Resultado esperado.** O painel mostra o título do evento na Even3 e o status **Desativada**. Divergência de nome ou datas com o evento interno aparece só como aviso (o HackLab não muda o evento). Para conferir de novo a qualquer momento: **Verificar conexão**, também somente leitura.

Nenhuma inscrição, entrada, pessoa, jurado ou conta é criada nesta etapa.

## 3. Even3: o que falta para sincronizar

A sincronização (status **Ativa**) é a importação real: exige autorização própria. Antes dela:

- **Cadastro interno completo:** participantes (a Even3 só valida quem já existe), empresas e representantes, jurados internos.
- **Webhook validado** (seção 4): é a única fonte de presença e de cancelamento.
- **Entradas e mapeamento:** as entradas da Even3 só chegam ao HackLab por uma sincronização. Hoje não existe "buscar só as entradas" com o vínculo desativado. Ao ativar, a primeira sincronização (manual ou a automática do front em até 10 min) traz entradas **e inscrições**. Inscrições de entradas ainda sem mapeamento entram sem efeito de categoria e aparecem em "Precisa de atenção" até o mapeamento; depois de mapear, sincronize de novo.

Ativação, quando autorizada: em **Configuração Even3**, status **Ativa**. Em seguida, clique em **Sincronizar** e mapeie as entradas (Participante, Jurado, Público, Empresa / Convidado, Organização). Por fim, sincronize de novo e confira "Precisa de atenção".

## 4. Webhook real: teste e monitoramento

**4.1 Configuração.**
- No Render: `EVEN3_WEBHOOK_HEADER` (o nome do header que a Even3 enviar) e `EVEN3_WEBHOOK_SECRET` (valor longo e aleatório, **diferente** da chave da API). Se o segredo for igual à chave, o webhook responde 503.
- Na Even3, URL do webhook: `https://<site do Netlify>/api/v1/webhooks/even3` (o mesmo caminho `/api/*` que o frontend usa, pelo proxy assinado do Netlify).

**4.2 Teste da autenticação sem importar dados.** Com o vínculo **Desativado**, faça uma inscrição de teste própria na Even3:
- autenticação correta: a Even3 recebe 200 e o HackLab grava um recibo `IGNORED` (vínculo não ativo). Nada é aplicado;
- header ou segredo errado: 401 e **nenhum recibo**. Só o log do Render mostra `POST /api/v1/webhooks/even3` com 401;
- `EVEN3_ENABLED=false` ou header/segredo ausentes: 503.

Com o vínculo **Ativo** (após autorização): inscrição confirmada, cancelamento e check-in de teste próprios geram recibos `PROCESSED`.

**4.3 Respostas do webhook.**

| Situação | HTTP | Recibo | Reenvio da Even3 |
|---|---|---|---|
| Processado | 200 | `PROCESSED` | não |
| Repetido (mesmo id) | 200 | o mesmo | não |
| Vínculo desativado, evento diferente ou gatilho desconhecido | 200 | `IGNORED` | não; **nunca é reprocessado** |
| Conflito de dados | 200 | `FAILED` | não; resolver pela sincronização da API |
| Erro inesperado | 500 | `FAILED` | sim; o reenvio reprocessa |
| Header/segredo errado | 401 | nenhum | sim, e falha de novo |
| Integração desligada no servidor | 503 | nenhum | sim |

A Even3 reenvia até 3 vezes (15, 30 e 150 min) e **desativa o webhook após 3 dias** sem entrega bem-sucedida: confira o status do webhook no painel da Even3. A presença não tem consulta histórica pela API: check-in ignorado ou perdido não volta sozinho.

**4.4 Como detectar falhas.** Os recibos não aparecem em nenhuma tela. Consultas somente leitura (`SELECT`) no SQL Editor do Supabase:

```sql
-- Resumo das últimas 24 h por status e gatilho
select status, error_code, event_type_name, count(*)
  from hacklab.integration_webhook_receipts
 where received_at > now() - interval '1 day'
 group by 1, 2, 3
 order by 4 desc;

-- Últimos recibos ignorados ou com falha
select received_at, external_delivery_id, event_type_id, event_type_name, status, error_code, error_message
  from hacklab.integration_webhook_receipts
 where status in ('IGNORED', 'FAILED')
 order by received_at desc
 limit 50;
```

Sinais de problema:
- `IGNORED` com `event_type_name` desconhecido: o nome do gatilho real difere do esperado (ajuste no código antes do evento);
- nenhum recibo depois de uma inscrição de teste: 401 (veja o log do Render) ou URL errada na Even3;
- `FAILED` repetido: veja `error_message`.

## 5. E-mail com a SendPulse

Pré-requisito no código: o PR #13 (falha do SMTP sem vazar contas, timeout, STARTTLS obrigatório, `hacklab:mail-test`) está na `dev` e **precisa chegar à `main` antes** de trocar `MAIL_MAILER` para `smtp` em produção. Sem ele, uma falha do SMTP responde 500 e revela quais e-mails têm cadastro.

**5.1 Conta e credenciais.**
1. Crie a conta em sendpulse.com e confirme o e-mail.
2. Abra a seção **SMTP**. A conta nova começa no plano de teste: 50 e-mails no total, 10 por hora, com remetente da própria SendPulse.
3. **Autentique o domínio** do remetente em **SMTP → Settings → Domain settings**: adicione o domínio e gere os registros **SPF** e **DKIM**. Cadastre-os no DNS do domínio (quem administra o DNS: se for domínio do Senac, a TI).
   - Se o domínio já tiver um registro SPF, junte o valor da SendPulse no **mesmo** registro TXT: nunca crie um segundo SPF.
   - Recomendado: um registro DMARC (`_dmarc`, começando com `p=none`).
   - A verificação pode levar horas.
4. Cadastre o **endereço remetente** nesse domínio (ex.: `nao-responda@dominio`). Com o domínio autenticado, o plano gratuito é ativado: 12.000 e-mails/mês, até 50/hora.
5. Copie as credenciais em **SMTP → Dashboard → Connection using server settings**: servidor, login e **senha SMTP** (não é a senha do painel). Guarde num gerenciador de senhas; nunca em mensagem, documento ou Git.

**5.2 Teste local (fora do Render).** Num `back/.env` local, temporário:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp-pulse.com
MAIL_PORT=2525
MAIL_SCHEME=smtp
MAIL_REQUIRE_TLS=true
MAIL_USERNAME=<login SMTP>
MAIL_PASSWORD='<senha SMTP>'
MAIL_FROM_ADDRESS=<remetente autenticado>
MAIL_FROM_NAME=HackLab
```

Rode `php artisan config:clear` e `php artisan hacklab:mail-test seu@email`. Confira o recebimento, o remetente e se caiu no spam. Depois, volte `MAIL_MAILER=log` e apague a senha do `.env` local.

Verificado em 26/10/2026, fora do Render: `smtp-pulse.com:2525` aceita STARTTLS (TLS 1.3, certificado válido) com `MAIL_REQUIRE_TLS=true`.

**5.3 Porta 2525 a partir do Render Free.** O Render Free bloqueia 25, 465 e 587; a 2525 não consta no bloqueio, mas só um envio de dentro do Render confirma. Sem shell no Free, o teste é pelo próprio fluxo (após autorização, com o PR #13 na `main`):
1. no Render, as variáveis da seção 5.2 (com os valores reais) e `FRONTEND_URL`;
2. `MAIL_MAILER=smtp`;
3. na tela de login de produção, **Esqueci minha senha** com o e-mail do próprio Administrador;
4. chegou: conclua a redefinição. Não chegou: o log do Render mostra o motivo:
   - "Connection could not be established" ou timeout: porta 2525 bloqueada no Free;
   - "Failed to authenticate": login/senha SMTP;
   - erro de STARTTLS: TLS recusado.

A resposta da tela é a mesma em todos os casos (não revela a conta).

Para voltar atrás a qualquer momento: `MAIL_MAILER=array`.
