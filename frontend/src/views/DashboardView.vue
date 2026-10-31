<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import {
  Files,
  FileCheck2,
  Archive,
  HardDrive,
  ArrowUpRight,
  RefreshCw,
  ShieldCheck,
  FolderOpen,
  AlertCircle,
  CalendarDays,
} from "@lucide/vue";
import { api, errorMessage } from "../lib/http";
import { bytes, number } from "../lib/format";
import { useAuth } from "../stores/auth";
import type { Dashboard } from "../types";

const auth = useAuth(),
  route = useRoute();
const data = ref<Dashboard | null>(null),
  loading = ref(true),
  error = ref("");
const palette = [
  "#27755c",
  "#83af8b",
  "#b4c9ac",
  "#d3b786",
  "#8c9eaf",
  "#a5afa6",
];
const types = computed(() =>
  [...(data.value?.by_type || [])].sort((a, b) => b.total - a.total),
);
const today = new Intl.DateTimeFormat("pt-BR", {
  day: "numeric",
  month: "long",
  year: "numeric",
}).format(new Date());
const cards = computed(() =>
  data.value
    ? [
        {
          label: "Total de documentos",
          value: number(data.value.total_documents),
          note: "Ativos e arquivados",
          icon: Files,
          style: "green",
        },
        {
          label: "Documentos ativos",
          value: number(data.value.active_documents),
          note: "Disponíveis para consulta",
          icon: FileCheck2,
          style: "blue",
        },
        {
          label: "Arquivados",
          value: number(data.value.archived_documents),
          note: "Histórico preservado",
          icon: Archive,
          style: "ochre",
        },
        {
          label: "Armazenamento",
          value: bytes(data.value.storage_bytes),
          note: "Todas as versões dos arquivos",
          icon: HardDrive,
          style: "slate",
        },
      ]
    : [],
);
async function load() {
  loading.value = true;
  error.value = "";
  try {
    data.value = (
      await api.get<{ data: Dashboard }>("/api/v1/dashboard")
    ).data.data;
  } catch (cause) {
    error.value = errorMessage(cause);
  } finally {
    loading.value = false;
  }
}
onMounted(load);
</script>

<template>
  <section class="page-heading">
    <div>
      <span class="eyebrow">SEU ACERVO, EM PERSPECTIVA</span>
      <h1>Visão geral</h1>
      <p>Acompanhe os documentos disponíveis para você.</p>
    </div>
    <button class="button secondary" :disabled="loading" @click="load">
      <RefreshCw :size="16" :class="{ spin: loading }" />Atualizar
    </button>
  </section>
  <div v-if="route.query.access === 'denied'" class="notice" role="alert">
    Sua conta não tem permissão para consultar a listagem de documentos.
  </div>
  <div v-if="error" class="error-state panel" role="alert">
    <AlertCircle :size="32" />
    <h2>Não foi possível carregar o acervo</h2>
    <p>{{ error }}</p>
    <button class="button primary" @click="load">Tentar novamente</button>
  </div>
  <div v-else :aria-busy="loading">
    <div class="section-context">
      <span
        ><span class="status-dot" />Informações da sua abrangência de
        acesso</span
      ><span><CalendarDays :size="14" />{{ today }}</span>
    </div>
    <div
      v-if="loading"
      class="metrics-grid"
      role="status"
      aria-label="Carregando indicadores"
    >
      <div v-for="index in 4" :key="index" class="metric-card skeleton-card">
        <span class="skeleton w60" /><span
          class="skeleton metric-skeleton"
        /><span class="skeleton w80" />
      </div>
    </div>
    <div v-else class="metrics-grid">
      <article v-for="card in cards" :key="card.label" class="metric-card">
        <div class="metric-top">
          <span>{{ card.label }}</span
          ><span class="metric-icon" :class="card.style"
            ><component :is="card.icon" :size="20"
          /></span>
        </div>
        <strong class="metric-value">{{ card.value }}</strong>
        <p>{{ card.note }}</p>
      </article>
    </div>
    <div class="dashboard-grid">
      <section class="panel distribution">
        <div class="panel-heading">
          <div>
            <h2>Documentos por tipo</h2>
            <p>Uma visão da composição do seu acervo.</p>
          </div>
          <span class="subtle-pill"
            >{{
              loading ? "…" : number(data?.total_documents || 0)
            }}
            documentos</span
          >
        </div>
        <div v-if="loading" class="chart-skeleton">
          <span v-for="index in 4" :key="index" class="skeleton" />
        </div>
        <div v-else-if="!types.length" class="empty-state">
          <FolderOpen :size="36" />
          <h3>Seu acervo começa aqui</h3>
          <p>
            Os tipos de documento aparecerão conforme o acervo for cadastrado.
          </p>
        </div>
        <template v-else
          ><div class="stacked-bar" aria-hidden="true">
            <span
              v-for="(type, index) in types"
              :key="type.id"
              :style="{
                width: `${(type.total / (data?.total_documents || 1)) * 100}%`,
                background: palette[index % palette.length],
              }"
            />
          </div>
          <div class="type-list">
            <div v-for="(type, index) in types" :key="type.id" class="type-row">
              <span class="type-label"
                ><i
                  :style="{ background: palette[index % palette.length] }"
                />{{ type.name }}</span
              >
              <div class="type-track" aria-hidden="true">
                <span
                  :style="{
                    width: `${(type.total / (types[0]?.total || 1)) * 100}%`,
                    background: palette[index % palette.length],
                  }"
                />
              </div>
              <strong>{{ number(type.total) }}</strong
              ><span class="type-percentage"
                >{{
                  Math.round((type.total / (data?.total_documents || 1)) * 100)
                }}%</span
              >
            </div>
          </div>
          <div class="chart-footnote">
            Inclui documentos ativos e arquivados.
          </div></template
        >
      </section>
      <section class="panel access-panel">
        <div class="access-icon"><ShieldCheck :size="24" /></div>
        <span class="eyebrow">SEU ESPAÇO DE TRABALHO</span>
        <h2>Um acervo que<br />respeita seu acesso.</h2>
        <p>
          Você visualiza os documentos das unidades autorizadas e os de acesso
          geral.
        </p>
        <span class="small-label">ABRANGÊNCIA</span>
        <div class="unit-chips">
          <span v-if="auth.can('access.global')" class="unit-chip global"
            >Todas as unidades</span
          ><template v-else-if="auth.user?.units.length"
            ><span
              v-for="unit in auth.user.units"
              :key="unit.id"
              class="unit-chip"
              >{{ unit.name }}</span
            ></template
          ><span v-else class="unit-chip">Documentos de acesso geral</span>
        </div>
        <RouterLink
          v-if="auth.can('documents.view')"
          to="/documentos"
          class="access-link"
          >Explorar documentos ativos<ArrowUpRight :size="18"
        /></RouterLink>
      </section>
    </div>
  </div>
</template>
