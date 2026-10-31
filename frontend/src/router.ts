import { createRouter, createWebHistory } from "vue-router";
import { useAuth } from "./stores/auth";

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: "/login",
      name: "login",
      component: () => import("./views/LoginView.vue"),
      meta: { title: "Entrar", public: true },
    },
    {
      path: "/",
      name: "dashboard",
      component: () => import("./views/DashboardView.vue"),
      meta: { title: "Visão geral" },
    },
    {
      path: "/documentos",
      name: "documents",
      component: () => import("./views/DocumentsView.vue"),
      meta: { title: "Documentos", permission: "documents.view" },
    },
    { path: "/documentos/novo", name: "document-create", component: () => import("./views/DocumentEditorView.vue"), meta: { title: "Novo documento", permission: "documents.create" } },
    { path: "/documentos/:id(\\d+)/editar", name: "document-edit", component: () => import("./views/DocumentEditorView.vue"), meta: { title: "Editar documento", permission: "documents.update", requiresView: true } },
    { path: "/documentos/:id(\\d+)/nova-versao", name: "document-version", component: () => import("./views/DocumentEditorView.vue"), meta: { title: "Nova versão", permission: "documents.add-version", requiresView: true } },
    { path: '/documentos/:id(\\d+)/versoes/:version(\\d+)/compartilhamentos', name: 'version-shares', component: () => import('./views/ShareManagementView.vue'), meta: { title: 'Compartilhar versão', permission: 'documents.view' } },
    { path: '/compartilhados', name: 'received-shares', component: () => import('./views/ReceivedSharesView.vue'), meta: { title: 'Compartilhados comigo' } },
    { path: '/compartilhamentos/:share(\\d+)', name: 'shared-access', component: () => import('./views/ReceivedSharesView.vue'), meta: { title: 'Documento compartilhado' } },
    { path: '/compartilhado/:token([a-f0-9]{64})', name: 'shared-token', component: () => import('./views/ReceivedSharesView.vue'), meta: { title: 'Documento compartilhado' } },
    { path: "/:pathMatch(.*)*", redirect: "/" },
  ],
  scrollBehavior: () => ({ top: 0 }),
});
router.beforeEach(async (to) => {
  const auth = useAuth();
  await auth.restore();
  if (!to.meta.public && !auth.user)
    return { name: "login", query: { redirect: to.fullPath } };
  if (to.name === "login" && auth.user) return { name: "dashboard" };
  if ((typeof to.meta.permission === "string" && !auth.can(to.meta.permission)) || (to.meta.requiresView && !auth.can('documents.view')))
    return { name: "dashboard", query: { access: "denied" } };
});
router.afterEach((to) => {
  document.title = `${String(to.meta.title || "Acervo digital")} · GED`;
});
