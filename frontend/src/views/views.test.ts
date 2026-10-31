import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { config, flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { reactive } from "vue";
import { api } from "../lib/http";
import { useAuth } from "../stores/auth";
import LoginView from "./LoginView.vue";
import DashboardView from "./DashboardView.vue";
import DocumentsView from "./DocumentsView.vue";

const routing = vi.hoisted(() => ({
  route: null as any,
  replace: vi.fn(),
  push: vi.fn(),
}));
vi.mock("vue-router", () => ({
  useRoute: () => routing.route,
  useRouter: () => ({ replace: routing.replace, push: routing.push }),
}));
let wrapper: VueWrapper | undefined;
config.global.stubs.RouterLink = { template: '<a><slot /></a>' };
const record = {
  id: 7,
  title: "Contrato de serviços",
  description: null,
  production_date: "2026-10-31",
  reference_number: "CT-7",
  author: null,
  interested_party: null,
  archived_at: null,
  type: { id: 1, name: "Contrato" },
  producing_unit: { id: 1, name: "Financeiro" },
  latest_version: {
    id: 2,
    version_number: 1,
    original_filename: "contrato.pdf",
    extension: "pdf",
    size_bytes: 2048,
    sha256: "a".repeat(64),
  },
};
beforeEach(() => {
  setActivePinia(createPinia());
  vi.restoreAllMocks();
  routing.replace.mockReset();
  routing.push.mockReset();
  routing.route = reactive({
    fullPath: "/documentos",
    query: {},
    meta: { title: "Documentos" },
  });
  const auth = useAuth();
  auth.initialized = true;
  auth.user = {
    id: 1,
    name: "Marina Oliveira",
    email: "marina@example.test",
    profile: { id: 1, name: "Gestor" },
    units: [{ id: 1, name: "Financeiro" }],
  };
  auth.permissions = ["documents.view", "documents.download"];
});
afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
});
describe("telas conectadas à API", () => {
  it("mostra o erro de credenciais e permite corrigir o formulário", async () => {
    useAuth().clear();
    vi.spyOn(useAuth(), "login").mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: { errors: { email: ["Credenciais inválidas."] } },
      },
    });
    wrapper = mount(LoginView);
    await wrapper.get("#email").setValue("marina@example.test");
    await wrapper.get("#password").setValue("wrong-password");
    await wrapper.get("form").trigger("submit");
    await flushPromises();
    expect(wrapper.text()).toContain("Credenciais inválidas.");
    expect(wrapper.get("#email").attributes("aria-invalid")).toBe("true");
    expect(wrapper.get('button[type="button"]').attributes("aria-label")).toBe(
      "Mostrar senha",
    );
    expect(routing.replace).not.toHaveBeenCalled();
  });
  it("não redireciona para um endereço externo após login", async () => {
    routing.route.query = { redirect: "//other.example" };
    vi.spyOn(useAuth(), "login").mockResolvedValue();
    wrapper = mount(LoginView);
    await wrapper.get("#email").setValue("marina@example.test");
    await wrapper.get("#password").setValue("test-password");
    await wrapper.get("form").trigger("submit");
    await flushPromises();
    expect(routing.replace).toHaveBeenCalledWith("/");
  });
  it("exibe os indicadores reais e os tipos retornados no dashboard", async () => {
    vi.spyOn(api, "get").mockResolvedValue({
      data: {
        data: {
          total_documents: 12,
          active_documents: 10,
          archived_documents: 2,
          storage_bytes: 2048,
          by_type: [{ id: 1, name: "Contrato", total: 12 }],
        },
      },
    });
    wrapper = mount(DashboardView, {
      global: { stubs: { RouterLink: { template: "<a><slot /></a>" } } },
    });
    await flushPromises();
    expect(wrapper.text()).toContain("12");
    expect(wrapper.text()).toContain("2 KB");
    expect(wrapper.text()).toContain("Contrato");
    expect(wrapper.text()).toContain("Financeiro");
  });
  it("mostra uma falha de rede no dashboard e oferece nova tentativa", async () => {
    vi.spyOn(api, "get").mockRejectedValue({ isAxiosError: true });
    wrapper = mount(DashboardView, {
      global: { stubs: { RouterLink: { template: "<a><slot /></a>" } } },
    });
    await flushPromises();
    expect(wrapper.text()).toContain("Não foi possível conectar ao servidor");
    expect(
      wrapper
        .findAll("button")
        .some((button) => button.text() === "Tentar novamente"),
    ).toBe(true);
  });
  it("carrega documentos com os filtros da URL e paginação da API", async () => {
    routing.route.query = { production_date_from: "2026-10-01", page: "2" };
    const get = vi.spyOn(api, "get").mockImplementation(async (path) => {
      if (path === "/api/v1/document-types")
        return { data: [{ id: 1, name: "Contrato" }] } as never;
      if (path === "/api/v1/units")
        return { data: [{ id: 1, name: "Financeiro" }] } as never;
      return {
        data: {
          data: [record],
          total: 21,
          current_page: 2,
          last_page: 2,
          from: 21,
          to: 21,
        },
      } as never;
    });
    wrapper = mount(DocumentsView);
    await flushPromises();
    expect(wrapper.text()).toContain("Contrato de serviços");
    expect(wrapper.text()).toContain("31/10/2026");
    expect(get).toHaveBeenCalledWith(
      "/api/v1/documents",
      expect.objectContaining({
        params: { production_date_from: "2026-10-01", page: "2" },
      }),
    );
    expect(
      wrapper.find('button[aria-label="Baixar Contrato de serviços"]').exists(),
    ).toBe(true);
  });
  it("distingue uma lista vazia de uma falha de carregamento", async () => {
    vi.spyOn(api, "get").mockImplementation(
      async (path) =>
        ({
          data:
            path === "/api/v1/documents"
              ? { data: [], total: 0, current_page: 1, last_page: 1 }
              : [],
        }) as never,
    );
    wrapper = mount(DocumentsView);
    await flushPromises();
    expect(wrapper.text()).toContain("Ainda não há documentos por aqui");
    expect(wrapper.text()).not.toContain(
      "Não foi possível carregar os documentos",
    );
  });
});
