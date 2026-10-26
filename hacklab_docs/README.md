# HackLab

Sistema interno de organização e gestão de Hackathon do Senac.

## Stack

- Frontend: JavaScript + Vue
- Backend: PHP + Laravel
- Banco: PostgreSQL (Docker em dev/testes; Supabase em produção)
- Autenticação: Laravel Sanctum
- Desenvolvimento: Docker / Docker Compose
- Produção: API no Render, frontend no Netlify, banco no Supabase
- Integração externa atual: Even3
- API: REST `/api/v1`

## Estrutura

```text
HackLab/
├── front/          (frontend Vue)
├── back/           (backend Laravel)
├── hacklab_docs/   (esta documentação)
├── CLAUDE.md
└── README.md
```

## Responsabilidade do HackLab

O HackLab cuida da operação interna:
- participantes;
- equipes;
- empresas;
- desafios;
- setores;
- reuniões;
- pendências;
- ocorrências;
- jurados;
- avaliações;
- votação;
- resultados;
- presença sincronizada;
- relatórios;
- auditoria;
- usuários e permissões.

A plataforma externa cuida da inscrição pública, QR e credenciamento.

## Documentação

Leia nesta ordem:

1. [CLAUDE.md](../CLAUDE.md)
2. [Arquitetura](./docs/arquitetura.md)
3. [Infraestrutura](./docs/infraestrutura.md)
4. [Regras de negócio](./docs/regras-de-negocio.md)
5. [Banco de dados](./docs/banco-de-dados.md)
6. [Integração Even3](./docs/integracao-even3.md)
7. [Plano de implementação](./docs/plano-implementacao.md)
8. [Ativação controlada em produção](./docs/ativacao-producao.md) (evento, Even3, webhook e e-mail)

## Backend

Veja o passo a passo completo no [README da raiz](../README.md).

Resumo (a partir da raiz do projeto):

```bash
cp back/.env.example back/.env
cp back/.env.testing.example back/.env.testing
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan key:generate --env=testing
docker compose exec app php artisan hacklab:db-check
docker compose exec app php artisan migrate --seed
```

## Frontend

```bash
cd front
npm install
npm run dev
```

Preservar o setup existente do frontend.

## Regra importante

O frontend nunca deve chamar Even3 diretamente.

```text
Vue
 ↓
Laravel API
 ↓
Even3 / PostgreSQL
```

Credenciais externas e do banco ficam apenas no backend.
