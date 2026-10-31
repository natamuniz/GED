# API de administração

Prefixo: `/api/v1/admin`. Autenticação por sessão Sanctum, usuário ativo e CSRF nas operações de escrita, como no restante da API. Respostas de item usam `data`; listagens usam paginação Laravel (`data`, `links`, `meta`).

## Rotas

| Recurso | Rotas | Permissão |
|---|---|---|
| Usuários | GET/POST `/users`, GET/PATCH `/users/{user}` | `users.manage` |
| Perfis | GET/POST `/profiles`, GET/PATCH/DELETE `/profiles/{profile}` | `profiles.manage` |
| Catálogo de permissões | GET `/permissions`, GET `/permissions/{permission}` | `profiles.manage` |
| Unidades | GET/POST `/units`, GET/PATCH `/units/{unit}` | `units.manage` |

A abrangência dos endpoints administrativos é global para o recurso gerenciado. Eles não alteram a abrangência de consulta dos documentos. O catálogo de permissões é definido pelo código; criar uma chave arbitrária não implementaria uma nova regra de negócio, por isso não há POST/PATCH/DELETE nesse catálogo.

## Usuários

Exemplo de POST:

```json
{
  "name": "Ana Silva",
  "email": "ana@example.test",
  "password": "uma-senha-local-forte",
  "password_confirmation": "uma-senha-local-forte",
  "profile_id": 3,
  "unit_ids": [2],
  "is_active": true,
  "permission_overrides": [
    {"permission_id": 7, "effect": "allow"},
    {"permission_id": 11, "effect": "deny"}
  ]
}
```

IDs são ilustrativos; consulte os catálogos. Nome, e-mail e senha confirmada (mínimo de 12 caracteres) são obrigatórios no cadastro. Perfil é opcional. Sem perfil ou permissões diretas, o usuário não recebe acesso aos documentos.

PATCH altera apenas os campos presentes. `unit_ids` e `permission_overrides`, quando enviados, substituem integralmente suas respectivas listas; `[]` remove todos os vínculos ou exceções. `profile_id: null` remove o perfil. Campos ausentes preservam os valores atuais. Para desativar, envie `{"is_active": false}`. Não há exclusão definitiva de usuário.

Além de `users.manage`, enviar `profile_id` ou `permission_overrides` exige `profiles.manage`. Isso impede que quem apenas cadastra usuários atribua privilégios administrativos. Senhas e hashes não são retornados nem registrados na auditoria. Troca de senha administrativa encerra as sessões existentes do usuário.

## Perfis

POST exige `name` e `permission_ids` (lista de IDs, inclusive vazia). `description` é opcional. PATCH permite alterar esses campos; uma lista de permissões enviada substitui a anterior. `is_system` é controlado pelo sistema.

GET de perfil inclui `users_count`, para avaliar quantos usuários herdam suas permissões. Exceções individuais continuam prevalecendo. Perfis de sistema não podem ser excluídos nem renomeados; suas permissões e descrição podem ser ajustadas. Perfis personalizados em uso não podem ser excluídos (HTTP 409).

## Unidades

POST exige `name` e `code` (único). `parent_id` é opcional; nulo representa unidade de primeiro nível. `is_active` é opcional. PATCH altera apenas os campos presentes. Não há exclusão definitiva; use `is_active: false`.

Uma unidade não pode ser subordinada a si mesma ou a uma descendente. Novos vínculos de usuários e novas unidades subordinadas exigem unidade ativa. Desativar uma unidade preserva documentos, vínculos e a consulta ao acervo já existente; não desativa automaticamente suas descendentes.

## Filtros e códigos HTTP

- Listagens: `search`, `page` e `per_page` (1 a 100, padrão 20).
- Usuários e unidades: `is_active` (0 ou 1).
- Permissões: `group`.
- 201: criado; 200: consultado/alterado; 204: perfil excluído.
- 401: sem autenticação; 403: sem permissão; 404: recurso inexistente.
- 409: tentativa de excluir perfil protegido ou em uso.
- 422: validação, ciclo de hierarquia ou remoção da última capacidade administrativa.

## Consistência e auditoria

Todas as escritas administrativas participam de uma transação com o mesmo advisory lock transacional PostgreSQL. A autorização do responsável é revalidada após obter o lock. Isso serializa alterações de perfis, vínculos, exceções, usuários e hierarquia feitas por esses endpoints.

Um administrador recuperável é um usuário ativo com permissões efetivas `users.manage` e `profiles.manage`. Se já existir um, a operação não pode deixar o sistema sem nenhum. A proteção cobre desativação, troca de perfil, exceções e alteração das permissões de um perfil. O nome do perfil não determina essa condição.

Auditoria registra responsável, entidade, ação e retratos anteriores/novos sem credenciais. Uma transação rejeitada não persiste alterações nem eventos de sucesso.

As proteções se aplicam aos endpoints; alterações manuais por SQL, seeders ou código que contorne o serviço administrativo exigem os mesmos cuidados.
