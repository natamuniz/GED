import axios from "axios";

export const api = axios.create({
  baseURL: (import.meta.env.VITE_API_URL || "http://localhost:8000").replace(
    /\/$/,
    "",
  ),
  withCredentials: true,
  withXSRFToken: true,
  timeout: 20000,
  headers: { Accept: "application/json" },
});

let sessionLost: (() => void) | undefined;
export function onSessionLost(callback: () => void) {
  sessionLost = callback;
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status;
    if (
      status === 401 ||
      (status === 403 &&
        error.response?.data?.message === "Usuário desativado.")
    ) {
      sessionLost?.();
    }
    return Promise.reject(error);
  },
);

export async function csrfPost(
  path: string,
  data?: unknown,
  initialize = false,
) {
  if (initialize) await api.get("/sanctum/csrf-cookie");
  try {
    return await api.post(path, data);
  } catch (error) {
    if (!axios.isAxiosError(error) || error.response?.status !== 419)
      throw error;
    await api.get("/sanctum/csrf-cookie");
    return api.post(path, data);
  }
}

export function errorMessage(
  error: unknown,
  fallback = "Não foi possível carregar os dados. Tente novamente.",
): string {
  if (!axios.isAxiosError(error)) return fallback;
  if (!error.response)
    return "Não foi possível conectar ao servidor. Verifique sua conexão e tente novamente.";
  if (error.response.status === 401)
    return "Sua sessão expirou. Entre novamente.";
  if (error.response.status === 403)
    return "Você não tem permissão para realizar esta ação.";
  if (error.response.status === 419)
    return "Sua sessão precisa ser atualizada. Tente novamente.";
  if (error.response.status === 429)
    return "Muitas tentativas em pouco tempo. Aguarde um momento e tente novamente.";
  return fallback;
}
