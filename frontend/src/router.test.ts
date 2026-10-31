import { beforeEach, describe, expect, it } from "vitest";
import { createPinia, setActivePinia } from "pinia";
import { router } from "./router";
import { useAuth } from "./stores/auth";

beforeEach(() => {
  setActivePinia(createPinia());
  useAuth().initialized = true;
});
describe("proteção de rotas", () => {
  it('permite abrir compartilhamentos recebidos sem documents.view e preserva o link após login', async () => {
    const path = '/compartilhado/' + 'a'.repeat(64);
    await router.push(path);
    expect(router.currentRoute.value.name).toBe('login');
    expect(router.currentRoute.value.query.redirect).toBe(path);
    useAuth().user = { id: 1, name: 'Destinatário', email: 'dest@example.test', profile: null, units: [] };
    useAuth().permissions = [];
    await router.push(path);
    expect(router.currentRoute.value.name).toBe('shared-token');
    await router.push('/compartilhados');
    expect(router.currentRoute.value.name).toBe('received-shares');
  });
  it('exige as permissões de manutenção e consulta nas rotas de edição e versão', async () => {
    useAuth().user = { id: 1, name: 'Editor', email: 'editor@example.test', profile: null, units: [] };
    useAuth().permissions = ['documents.update', 'documents.add-version'];
    await router.push('/documentos/7/editar');
    expect(router.currentRoute.value.name).toBe('dashboard');
    useAuth().permissions.push('documents.view');
    await router.push('/documentos/7/editar');
    expect(router.currentRoute.value.name).toBe('document-edit');
    await router.push('/documentos/7/nova-versao');
    expect(router.currentRoute.value.name).toBe('document-version');
    await router.push('/documentos/novo');
    expect(router.currentRoute.value.name).toBe('dashboard');
  });
  it("direciona uma sessão ausente para login e preserva o destino", async () => {
    await router.push("/documentos?search=Contrato");
    expect(router.currentRoute.value.name).toBe("login");
    expect(router.currentRoute.value.query.redirect).toBe(
      "/documentos?search=Contrato",
    );
  });
  it("bloqueia a listagem quando a sessão não tem documents.view", async () => {
    useAuth().user = {
      id: 1,
      name: "Leitor",
      email: "user@example.test",
      profile: null,
      units: [],
    };
    await router.push("/documentos");
    expect(router.currentRoute.value.name).toBe("dashboard");
    expect(router.currentRoute.value.query.access).toBe("denied");
  });
  it("abre a listagem para um usuário autorizado", async () => {
    useAuth().user = {
      id: 1,
      name: "Leitor",
      email: "user@example.test",
      profile: null,
      units: [],
    };
    useAuth().permissions = ["documents.view"];
    await router.push("/documentos");
    expect(router.currentRoute.value.name).toBe("documents");
  });
});
