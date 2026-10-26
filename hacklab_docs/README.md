# HackLab

Sistema interno de organização e gestão de Hackathon do Senac.

## Stack

- Frontend: JavaScript + Vue
- Backend: PHP + Laravel
- Banco: MySQL 8 no desenvolvimento local; PostgreSQL 17 no CI e na produção (Supabase)
- Autenticação: Laravel Sanctum
- Desenvolvimento: PHP, Composer, Node e MySQL na máquina, sem Docker (Docker Compose com PostgreSQL é alternativa)
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
8. [Instalação detalhada no Senac](./docs/instalacao-senac.md)
9. [Instalação alternativa](./docs/instalacao-alternativa.md) (sem o MySQL do Senac)

## Instalação

- Computadores do Senac (MySQL Workbench, CMD): resumo no [README da raiz](../README.md) e passo a passo em [instalação detalhada no Senac](./docs/instalacao-senac.md).
- Sem o MySQL do Senac (script PowerShell, MySQL em ZIP ou Docker com PostgreSQL): [instalação alternativa](./docs/instalacao-alternativa.md).

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
