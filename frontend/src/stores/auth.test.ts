import { beforeEach, describe, expect, it, vi } from "vitest";
import { createPinia, setActivePinia } from "pinia";
import { api, csrfPost } from "../lib/http";
import { useAuth } from "./auth";

const session = {
  user: {
    id: 1,
    name: "Marina",
    email: "marina@example.test",
    profile: { id: 1, name: "Gestor" },
    units: [],
  },
  permissions: ["documents.view"],
};
const failure = (status: number) => ({
  isAxiosError: true,
  response: { status, data: {} },
});
beforeEach(() => {
  setActivePinia(createPinia());
  vi.restoreAllMocks();
});
describe("sessão Sanctum", () => {
  it("inicializa CSRF, autentica e carrega as permissões da API", async () => {
    const get = vi
      .spyOn(api, "get")
      .mockImplementation(
        async (path) =>
          ({ data: path === "/api/v1/auth/me" ? session : null }) as never,
      );
    const post = vi
      .spyOn(api, "post")
      .mockResolvedValue({ data: { user: session.user } });
    const auth = useAuth();
    await auth.login(" MARINA@EXAMPLE.TEST ", "test-password");
    expect(get.mock.calls.map((call) => call[0])).toEqual([
      "/sanctum/csrf-cookie",
      "/api/v1/auth/me",
    ]);
    expect(post).toHaveBeenCalledWith("/api/v1/auth/login", {
      email: "marina@example.test",
      password: "test-password",
    });
    expect(auth.user?.id).toBe(1);
    expect(auth.can("documents.view")).toBe(true);
  });
  it("restaura uma sessão existente apenas uma vez, inclusive com chamadas simultâneas", async () => {
    const get = vi.spyOn(api, "get").mockResolvedValue({ data: session });
    const auth = useAuth();
    await Promise.all([auth.restore(), auth.restore()]);
    await auth.restore();
    expect(get).toHaveBeenCalledTimes(1);
    expect(auth.user?.name).toBe("Marina");
  });
  it("trata uma sessão ausente sem manter usuário ou permissões", async () => {
    vi.spyOn(api, "get").mockRejectedValue(failure(401));
    const auth = useAuth();
    await auth.restore();
    expect(auth.user).toBeNull();
    expect(auth.permissions).toEqual([]);
    expect(auth.initialized).toBe(true);
  });
  it("não simula logout quando o servidor está indisponível", async () => {
    vi.spyOn(api, "post").mockRejectedValue(new Error("network"));
    const auth = useAuth();
    auth.user = session.user;
    await expect(auth.logout()).rejects.toThrow("network");
    expect(auth.user).not.toBeNull();
  });
  it("renova CSRF e repete somente uma vez um POST de autenticação recusado por 419", async () => {
    const post = vi
      .spyOn(api, "post")
      .mockRejectedValueOnce(failure(419))
      .mockResolvedValue({ data: {} });
    const get = vi.spyOn(api, "get").mockResolvedValue({ data: null });
    await csrfPost("/api/v1/auth/logout");
    expect(post).toHaveBeenCalledTimes(2);
    expect(get).toHaveBeenCalledWith("/sanctum/csrf-cookie");
  });
});
