# Validação da modelagem do GED — MVP

Este documento registra a validação conceitual que orientou as migrations. O backend Laravel e o ambiente Docker foram implementados posteriormente; consulte o README da raiz para o estado atual e os endpoints disponíveis.

## Regras de autorização

- Permissões efetivas: permissões do perfil, acrescidas das concessões individuais e removidas as negações individuais.
- A permissão de executar uma ação não concede abrangência organizacional.
- Abrangência normal: unidades vinculadas ao usuário e suas descendentes. Acesso global exige permissão explícita.
- Documento sem unidade produtora: consulta por todos os usuários ativos e autenticados; manutenção exige permissão específica.
- Compartilhamento concede consulta e download da versão indicada aos destinatários selecionados, sem acesso às demais versões ou operações de escrita.

## Cenário de referência

Financeiro possui Contas a pagar, Contas a receber e Folha como subordinadas. Ana é colaboradora de Contas a pagar; Bruno é colaborador de Contas a receber; Carla é gestora do Financeiro; Daniel é leitor de Folha.

| Caso | Resultado esperado |
|---|---|
| Bruno cadastra um relatório em Contas a receber | Documento e versão 1 criados; hash calculado pelo backend |
| Ana consulta esse relatório sem compartilhamento | Acesso negado |
| Carla consulta esse relatório | Permitido por abrangência sobre as subordinadas |
| Bruno consulta documento diretamente vinculado ao Financeiro | Negado; acesso não sobe na hierarquia |
| Daniel tenta criar documento em Folha | Negado pelo perfil Leitor |
| Administrador cadastra orientação sem unidade | Consulta permitida a todos os usuários ativos e autenticados |
| Ana tenta retirar a unidade de um documento | Negado sem permissão para gerir documentos gerais |
| Bruno compartilha a versão 1 com Ana | Ana consulta e baixa somente essa versão durante a validade |
| Daniel recebe uma cópia desse link | Negado; não é destinatário |
| Bruno adiciona versão 2 | Link anterior permanece associado à versão 1 |
| Link expira após Ana abrir a página | Nova requisição de download pelo link é negada |
| Criador do link é desativado ou perde autorização para compartilhar | Link deixa de conceder acesso |
| Documento é arquivado | Consulta preservada; escrita e novos compartilhamentos bloqueados; links existentes mantêm as regras de validade |
| Arquivo armazenado é alterado | Verificação registra divergência; não substitui o hash original |
| Arquivo não pode ser lido | Verificação registra erro, e não divergência |
| Perfil permite compartilhar, mas usuário tem negação individual | Compartilhamento negado |

Negação via compartilhamento não elimina eventual acesso normal que o usuário já possua.

## Consistência de cadastro e versionamento

- Documento lógico e primeira versão são cadastrados no mesmo fluxo; não há documento disponível sem arquivo inicial.
- Banco e armazenamento não compartilham transação. Falhas exigem compensação e limpeza de arquivos órfãos.
- A criação de versões deve serializar a atribuição do número por documento, além da restrição única (document_id, version_number).
- Conteúdo e atributos técnicos de versões existentes são imutáveis.
- SHA-256 não é chave única e não substitui assinatura digital.
- Data de produção descreve o documento lógico no MVP. Versões registram a data do envio, que não equivale à data de produção do conteúdo.

## Consistência da organização e administração

- Impedir ciclos na hierarquia de unidades.
- Alterar a hierarquia ou mover documentos modifica a abrangência de acesso e deve ser auditado.
- Gestor só move documento entre unidades sobre as quais possui abrangência.
- Desativar uma unidade não torna seus documentos gerais nem os exclui. Impede novos vínculos e novos cadastros nela; preserva consulta ao acervo e administração autorizada.
- Não permitir remover ou desativar o último administrador ativo. A proteção também se aplica a alterações de perfil e exceções que retirem sua capacidade administrativa.
- Desativar tipos documentais impede sua seleção para novos documentos, sem alterar o acervo existente.

## Dashboard: exemplo de contagem

Documento A em Contas a receber: duas versões de 2 MB e 3 MB. Documento B geral: uma versão de 1 MB. Não há outros documentos.

| Usuário | Total de documentos | Espaço ocupado |
|---|---:|---:|
| Bruno e Carla | 2 | 6 MB |
| Ana, mesmo recebendo A por link | 1 | 1 MB |

Documentos arquivados permanecem nos totais, com separação por situação. Compartilhamentos não ampliam indicadores. Cada documento deve ser contado uma só vez mesmo quando múltiplos vínculos concedem acesso.

## Estrutura prevista

14 tabelas de domínio: organizational_units, users, organizational_unit_user, profiles, permissions, permission_profile, user_permission_overrides, document_types, documents, document_versions, integrity_checks, document_shares, document_share_recipients e audit_events. Tabelas operacionais de autenticação, filas e sessões são adicionais.

Relacionamentos obrigatórios usam chaves estrangeiras. Não aplicar exclusão em cascata ao acervo e à auditoria. Pares nas tabelas associativas são únicos. Datas com horário são armazenadas em UTC; data de produção é DATE.

## Implementação seguinte

Base implementada com Laravel 13, PHP 8.4 e PostgreSQL 17 em Docker. Migrations e seeders disponíveis em backend/database. A suíte inicial valida autenticação, autorização, hierarquia, upload, versões, integridade e dashboard; cenários de compartilhamento aguardam a implementação dos endpoints correspondentes.
