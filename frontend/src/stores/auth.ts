import { defineStore } from "pinia";
import axios from "axios";
import { api, csrfPost, errorMessage } from "../lib/http";
import type { Session, User } from "../types";

let restoration: Promise<void> | null = null;
export const useAuth = defineStore("auth", {
  state: () => ({
    user: null as User | null,
    permissions: [] as string[],
    initialized: false,
    issue: "",
  }),
  getters: {
    can: (state) => (permission: string) =>
      state.permissions.includes(permission),
  },
  actions: {
    clear() {
      this.user = null;
      this.permissions = [];
      this.initialized = true;
    },
    async restore(force = false) {
      if (this.initialized && !force) return;
      if (restoration) return restoration;
      restoration = (async () => {
        try {
          const { data } = await api.get<Session>("/api/v1/auth/me");
          this.user = data.user;
          this.permissions = data.permissions;
          this.issue = "";
        } catch (error) {
          this.clear();
          if (
            !axios.isAxiosError(error) ||
            ![401, 403].includes(error.response?.status ?? 0)
          )
            this.issue = errorMessage(error);
          if (force) throw error;
        } finally {
          this.initialized = true;
        }
      })();
      try {
        await restoration;
      } finally {
        restoration = null;
      }
    },
    async login(email: string, password: string) {
      await csrfPost(
        "/api/v1/auth/login",
        { email: email.trim().toLowerCase(), password },
        true,
      );
      await this.restore(true);
    },
    async logout() {
      try {
        await csrfPost("/api/v1/auth/logout");
      } catch (error) {
        if (
          !axios.isAxiosError(error) ||
          ![401, 419].includes(error.response?.status ?? 0)
        )
          throw error;
      }
      this.clear();
    },
  },
});
