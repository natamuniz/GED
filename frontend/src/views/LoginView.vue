<script setup lang="ts">
import { ref, onBeforeUnmount } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";
import {
  Files,
  ArrowRight,
  Eye,
  EyeOff,
  ShieldCheck,
  Layers3,
  Search,
  LoaderCircle,
} from "@lucide/vue";
import { useAuth } from "../stores/auth";
import { errorMessage } from "../lib/http";
import type { FieldErrors } from "../types";

const auth = useAuth(),
  route = useRoute(),
  router = useRouter();
const email = ref(""),
  password = ref(""),
  visible = ref(false),
  busy = ref(false),
  error = ref(""),
  fields = ref<FieldErrors>({});
onBeforeUnmount(() => {
  password.value = "";
});
async function submit() {
  if (busy.value) return;
  busy.value = true;
  error.value = "";
  fields.value = {};
  try {
    await auth.login(email.value.trim(), password.value);
    password.value = "";
    const target = route.query.redirect;
    await router.replace(
      typeof target === "string" &&
        target.startsWith("/") &&
        !target.startsWith("//") &&
        !target.includes("\\")
        ? target
        : "/",
    );
  } catch (cause) {
    if (axios.isAxiosError(cause) && cause.response?.status === 422) {
      fields.value = cause.response.data.errors || {};
      error.value = "Confira seu e-mail e sua senha para continuar.";
    } else
      error.value = errorMessage(
        cause,
        "Não foi possível entrar. Tente novamente.",
      );
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="login-layout">
    <section class="login-story" aria-label="Sobre o GED">
      <div class="brand">
        <span class="brand-mark"><Files :size="25" /></span
        ><span>GED<small>ACERVO DIGITAL</small></span>
      </div>
      <div class="story-content">
        <span class="story-eyebrow"><span />INFORMAÇÃO NO LUGAR CERTO</span>
        <h1>
          Um lugar para<br />cada documento.<br /><em
            >Mais clareza<br />para seu dia.</em
          >
        </h1>
        <p>
          Organize seu acervo, preserve o histórico e encontre o que importa.
        </p>
        <div class="document-art" aria-hidden="true">
          <div class="art-orbit" />
          <div class="art-sheet sheet-back"><span /><span /><span /></div>
          <div class="art-sheet sheet-front">
            <div class="art-file-icon"><Files :size="26" /></div>
            <i /><span /><span /><span class="short-line" />
            <div class="art-verified">
              <ShieldCheck :size="15" />Integridade preservada
            </div>
          </div>
          <span class="art-badge"
            ><Layers3 :size="17" />Cada versão, uma história.</span
          >
        </div>
        <div class="story-features">
          <span><Search :size="16" />Encontre</span
          ><span><Layers3 :size="16" />Organize</span
          ><span><ShieldCheck :size="16" />Preserve</span>
        </div>
      </div>
      <span class="story-footer">Gestão eletrônica de documentos</span>
    </section>
    <main class="login-main">
      <div class="login-card">
        <div class="login-mobile-brand">
          <Files :size="25" />GED <span>Acervo digital</span>
        </div>
        <span class="eyebrow">BEM-VINDO AO SEU ACERVO</span>
        <h2>Entre para continuar.</h2>
        <p class="login-intro">Acesse seus documentos com segurança.</p>
        <div
          v-if="route.query.reason === 'expired'"
          class="notice"
          role="status"
        >
          Sua sessão foi encerrada ou expirou. Entre novamente.
        </div>
        <div v-if="auth.issue && !error" class="notice error" role="alert">
          {{ auth.issue }}
        </div>
        <div v-if="error" class="notice error" role="alert">{{ error }}</div>
        <form class="login-form" @submit.prevent="submit">
          <label for="email">E-mail</label
          ><input
            id="email"
            v-model="email"
            name="email"
            type="email"
            autocapitalize="none"
            autocorrect="off"
            enterkeyhint="next"
            autocomplete="username"
            placeholder="voce@empresa.com.br"
            required
            maxlength="255"
            :aria-invalid="Boolean(fields.email)"
            :aria-describedby="fields.email ? 'email-error' : undefined"
            :disabled="busy"
          />
          <span v-if="fields.email" id="email-error" class="field-error">{{
            fields.email[0]
          }}</span>
          <label for="password">Senha</label>
          <div class="password-input">
            <input
              id="password"
              v-model="password"
              name="password"
              :type="visible ? 'text' : 'password'"
              autocomplete="current-password"
              placeholder="Sua senha de acesso"
              required
              :disabled="busy"
              :aria-invalid="Boolean(fields.password)"
              :aria-describedby="fields.password ? 'password-error' : undefined"
            /><button
              type="button"
              class="icon-button"
              :aria-label="visible ? 'Ocultar senha' : 'Mostrar senha'"
              :aria-pressed="visible"
              @click="visible = !visible"
            >
              <EyeOff v-if="visible" :size="19" /><Eye v-else :size="19" />
            </button>
          </div>
          <span
            v-if="fields.password"
            id="password-error"
            class="field-error"
            >{{ fields.password[0] }}</span
          >
          <button class="button primary login-submit" :disabled="busy">
            <LoaderCircle v-if="busy" class="spin" :size="18" />{{
              busy ? "Entrando…" : "Entrar no acervo"
            }}<ArrowRight v-if="!busy" :size="18" />
          </button>
        </form>
        <p class="access-help">
          Ainda não tem acesso?<br /><strong
            >Solicite seu cadastro ao administrador.</strong
          >
        </p>
        <div class="login-security">
          <ShieldCheck :size="16" />Acesso restrito a usuários autorizados
        </div>
      </div>
      <footer class="login-footer">GED · Seus documentos, bem cuidados.</footer>
    </main>
  </div>
</template>
