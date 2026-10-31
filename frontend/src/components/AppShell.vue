<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from "vue";
import { useRoute, useRouter } from "vue-router";
import {
  Files,
  LayoutDashboard,
  FolderOpen,
  LogOut,
  Menu,
  X,
  ShieldCheck,
  ChevronRight,
  Share2,
} from "@lucide/vue";
import { useAuth } from "../stores/auth";
import { errorMessage } from "../lib/http";

const auth = useAuth(),
  route = useRoute(),
  router = useRouter();
const open = ref(false),
  leaving = ref(false),
  error = ref("");
const navigation = ref<HTMLElement | null>(null);
const menuButton = ref<HTMLButtonElement | null>(null);
let previousOverflow = "";
const mobileLayout = window.matchMedia("(max-width: 680px)");
async function closeNavigation() {
  if (!open.value) return;
  open.value = false;
  await nextTick();
  menuButton.value?.focus();
}
function containFocus(event: KeyboardEvent) {
  if (!open.value || event.key !== "Tab") return;
  const elements = Array.from(
    navigation.value?.querySelectorAll<HTMLElement>("a[href], button") || [],
  );
  const first = elements[0],
    last = elements[elements.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last?.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first?.focus();
  }
}
function layoutChanged() {
  if (!mobileLayout.matches) open.value = false;
}
watch(open, async (value) => {
  if (value) {
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    await nextTick();
    navigation.value?.querySelector<HTMLButtonElement>("button")?.focus();
  } else document.body.style.overflow = previousOverflow;
});
onMounted(() => mobileLayout.addEventListener("change", layoutChanged));
onBeforeUnmount(() => {
  mobileLayout.removeEventListener("change", layoutChanged);
  if (open.value) document.body.style.overflow = previousOverflow;
});
const initials = computed(() =>
  (auth.user?.name || "")
    .split(" ")
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0])
    .join("")
    .toUpperCase(),
);
const scope = computed(() =>
  auth.can("access.global")
    ? "Todas as unidades"
    : auth.user?.units.length === 1
      ? auth.user.units[0]!.name
      : `${auth.user?.units.length || 0} unidades vinculadas`,
);
watch(
  () => route.fullPath,
  async () => {
    const wasOpen = open.value;
    open.value = false;
    if (wasOpen) {
      await nextTick();
      document.getElementById("main-content")?.focus();
    }
  },
);
async function logout() {
  leaving.value = true;
  error.value = "";
  try {
    await auth.logout();
    await router.replace("/login");
  } catch (cause) {
    error.value = errorMessage(
      cause,
      "Não foi possível encerrar sua sessão. Tente novamente.",
    );
  } finally {
    leaving.value = false;
  }
}
</script>

<template>
  <div
    class="app-layout"
    @keydown.esc="closeNavigation"
    @keydown="containFocus"
  >
    <a class="skip-link" href="#main-content">Pular para o conteúdo</a>
    <button
      v-if="open"
      class="sidebar-backdrop"
      aria-label="Fechar navegação"
      @click="closeNavigation"
    />
    <aside
      ref="navigation"
      id="main-nav"
      class="sidebar"
      :class="{ 'is-open': open }"
    >
      <RouterLink to="/" class="brand"
        ><span class="brand-mark"><Files :size="24" /></span
        ><span>GED<small>ACERVO DIGITAL</small></span></RouterLink
      >
      <button
        class="icon-button mobile-close"
        aria-label="Fechar navegação"
        @click="closeNavigation"
      >
        <X :size="20" />
      </button>
      <p class="nav-label">PRINCIPAL</p>
      <nav aria-label="Navegação principal">
        <RouterLink to="/" class="nav-item" exact-active-class="active"
          ><LayoutDashboard :size="19" />Visão geral</RouterLink
        >
        <RouterLink
          v-if="auth.can('documents.view')"
          to="/documentos"
          class="nav-item"
          active-class="active"
          ><FolderOpen :size="19" />Documentos</RouterLink
        >
        <RouterLink to="/compartilhados" class="nav-item" active-class="active"><Share2 :size="19" />Compartilhados comigo</RouterLink>
      </nav>
      <div class="sidebar-note">
        <ShieldCheck :size="22" /><strong>Seu acervo, protegido.</strong>
        <p>Documentos organizados e acesso conforme suas permissões.</p>
      </div>
      <div class="sidebar-user">
        <span class="avatar">{{ initials }}</span
        ><span class="user-info"
          ><strong>{{ auth.user?.name }}</strong
          ><small>{{
            auth.user?.profile?.name || "Acesso personalizado"
          }}</small></span
        >
      </div>
    </aside>
    <div class="app-body" :inert="open">
      <header class="topbar">
        <div class="breadcrumb">
          <button
            ref="menuButton"
            class="icon-button mobile-menu"
            :aria-expanded="open"
            aria-controls="main-nav"
            aria-label="Abrir navegação"
            @click="open = !open"
          >
            <Menu :size="22" /></button
          ><span class="breadcrumb-root">Meu acervo</span
          ><ChevronRight :size="14" /><strong>{{ route.meta.title }}</strong>
        </div>
        <div class="topbar-actions">
          <span class="scope-label"><span class="status-dot" />{{ scope }}</span
          ><span class="topbar-divider" /><button
            class="logout-button"
            :disabled="leaving"
            @click="logout"
          >
            <LogOut :size="17" />{{ leaving ? "Saindo…" : "Sair" }}
          </button>
        </div>
      </header>
      <main id="main-content" class="main-content" tabindex="-1">
        <div v-if="error" class="notice error" role="alert">{{ error }}</div>
        <slot />
      </main>
      <footer class="app-footer">
        <span>GED · Gestão eletrônica de documentos</span
        ><span>Organização que preserva informação.</span>
      </footer>
    </div>
  </div>
</template>
