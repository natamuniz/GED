<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  watch,
} from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";
import {
  Search,
  SlidersHorizontal,
  ChevronDown,
  Download,
  ChevronLeft,
  ChevronRight,
  FolderOpen,
  AlertCircle,
  RefreshCw,
  X,
  FileText,
  ShieldCheck,
  LoaderCircle,
  ArrowUpRight,
} from "@lucide/vue";
import { api, errorMessage } from "../lib/http";
import { bytes, dateOnly, number } from "../lib/format";
import {
  documentQuery,
  emptyFilters,
  filename,
  readFilters,
} from "../lib/documents";
import { useAuth } from "../stores/auth";
import type {
  DocumentPage,
  DocumentRecord,
  DocumentType,
  DocumentVersion,
  FieldErrors,
  Unit,
} from "../types";

const route = useRoute(),
  router = useRouter(),
  auth = useAuth();
const filters = reactive(emptyFilters());
const page = ref<DocumentPage | null>(null),
  loading = ref(true),
  error = ref(""),
  fieldErrors = ref<FieldErrors>({});
const types = ref<DocumentType[]>([]),
  units = ref<Unit[]>([]),
  catalogError = ref(false);
const downloading = ref<number | null>(null),
  downloadError = ref("");
const selected = ref<DocumentRecord | null>(null),
  detailLoading = ref(false),
  detailError = ref(""),
  drawer = ref<HTMLDialogElement | null>(null),
  drawerTitle = ref<HTMLElement | null>(null);
let listController: AbortController | undefined,
  detailController: AbortController | undefined,
  detailTrigger: HTMLElement | null = null;
const activeFilterCount = computed(
  () =>
    Object.entries(readFilters(route.query)).filter(
      ([key, value]) => value && !(key === "archived" && value === "0"),
    ).length,
);
const selectedVersion = computed(
  () =>
    selected.value?.versions
      ?.slice()
      .sort((a, b) => b.version_number - a.version_number)[0],
);
const catalogReady = ref(false);
const filtersExpanded = ref(false);

async function catalogs() {
  catalogError.value = false;
  const results = await Promise.allSettled([
    api.get<DocumentType[]>("/api/v1/document-types"),
    api.get<Unit[]>("/api/v1/units"),
  ]);
  if (results[0].status === "fulfilled") types.value = results[0].value.data;
  if (results[1].status === "fulfilled") units.value = results[1].value.data;
  catalogError.value = results.some((result) => result.status === "rejected");
  catalogReady.value = true;
}
async function load() {
  listController?.abort();
  const controller = new AbortController();
  listController = controller;
  Object.assign(filters, readFilters(route.query));
  loading.value = true;
  error.value = "";
  fieldErrors.value = {};
  downloadError.value = "";
  try {
    const query = documentQuery(filters, Number(route.query.page) || 1);
    page.value = (
      await api.get<DocumentPage>("/api/v1/documents", {
        params: query,
        signal: controller.signal,
      })
    ).data;
  } catch (cause) {
    if (axios.isCancel(cause)) return;
    if (axios.isAxiosError(cause) && cause.response?.status === 422) {
      fieldErrors.value = cause.response.data.errors || {};
      error.value = "Confira os filtros informados e tente novamente.";
    } else error.value = errorMessage(cause);
  } finally {
    if (!controller.signal.aborted) loading.value = false;
  }
}
async function apply() {
  fieldErrors.value = {};
  if (
    filters.production_date_from &&
    filters.production_date_to &&
    filters.production_date_to < filters.production_date_from
  ) {
    fieldErrors.value = {
      production_date_to: [
        "A data final deve ser igual ou posterior à inicial.",
      ],
    };
    return;
  }
  const query = documentQuery(filters);
  if (
    JSON.stringify(query) ===
    JSON.stringify(
      documentQuery(readFilters(route.query), Number(route.query.page) || 1),
    )
  )
    await load();
  else await router.push({ name: "documents", query });
}
async function clear() {
  Object.assign(filters, emptyFilters());
  fieldErrors.value = {};
  if (!Object.keys(route.query).length) await load();
  else await router.push({ name: "documents" });
}
async function goTo(value: number) {
  if (loading.value || !page.value || value < 1 || value > page.value.last_page)
    return;
  await router.push({
    name: "documents",
    query: documentQuery(readFilters(route.query), value),
  });
}
async function download(version: DocumentVersion) {
  if (downloading.value !== null) return;
  downloading.value = version.id;
  downloadError.value = "";
  try {
    const response = await api.get<Blob>(
      `/api/v1/versions/${version.id}/download`,
      { responseType: "blob", timeout: 0 },
    );
    const url = URL.createObjectURL(response.data),
      link = document.createElement("a");
    link.href = url;
    link.download = filename(
      response.headers["content-disposition"],
      version.original_filename,
    );
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (cause) {
    downloadError.value = errorMessage(
      cause,
      "Não foi possível baixar o arquivo. Tente novamente.",
    );
  } finally {
    downloading.value = null;
  }
}
async function showDocument(record: DocumentRecord, event: Event) {
  detailController?.abort();
  const controller = new AbortController();
  detailController = controller;
  detailTrigger = event.currentTarget as HTMLElement;
  selected.value = record;
  detailError.value = "";
  detailLoading.value = true;
  drawer.value?.showModal();
  await nextTick();
  drawerTitle.value?.focus();
  try {
    selected.value = (
      await api.get<{ data: DocumentRecord }>(
        `/api/v1/documents/${record.id}`,
        { signal: controller.signal },
      )
    ).data.data;
  } catch (cause) {
    if (!axios.isCancel(cause)) detailError.value = errorMessage(cause);
  } finally {
    if (!controller.signal.aborted) detailLoading.value = false;
  }
}
function closeDrawer() {
  drawer.value?.close();
}
function closed() {
  detailController?.abort();
  selected.value = null;
  detailTrigger?.focus();
}
watch(() => route.fullPath, load, { immediate: true });
onMounted(catalogs);
onBeforeUnmount(() => {
  listController?.abort();
  detailController?.abort();
});
</script>

<template>
  <section class="page-heading">
    <div>
      <span class="eyebrow">ORGANIZAÇÃO QUE FAZ SENTIDO</span>
      <h1>
        Documentos
        <span v-if="page && !loading" class="heading-count">{{
          number(page.total)
        }}</span>
      </h1>
      <p>Encontre e consulte os documentos do seu acervo.</p>
    </div>
    <div class="heading-actions"><RouterLink v-if="auth.can('documents.create')" class="button primary" :to="{ name: 'document-create' }">Novo documento</RouterLink><button class="button secondary" :disabled="loading" @click="load">
      <RefreshCw :size="16" :class="{ spin: loading }" />Atualizar
    </button></div>
  </section>
  <section
    class="panel filter-panel"
    :class="{ 'filters-expanded': filtersExpanded }"
    aria-labelledby="filters-title"
  >
    <div class="filter-heading">
      <h2 id="filters-title">
        <SlidersHorizontal :size="16" />Filtrar documentos
      </h2>
      <button
        type="button"
        class="mobile-filter-toggle button ghost"
        :aria-expanded="filtersExpanded"
        aria-controls="document-type producing-unit document-status production-from production-to"
        @click="filtersExpanded = !filtersExpanded"
      >
        {{ filtersExpanded ? "Recolher" : "Mais filtros"
        }}<ChevronDown
          :size="15"
          :class="{ 'filter-chevron-up': filtersExpanded }"
        />
      </button>
      <span v-if="activeFilterCount" class="subtle-pill"
        >{{ activeFilterCount }}
        {{
          activeFilterCount === 1 ? "filtro aplicado" : "filtros aplicados"
        }}</span
      >
    </div>
    <form @submit.prevent="apply">
      <div class="filters-grid">
        <div class="filter-field search-field">
          <label for="document-search">Buscar no acervo</label>
          <div class="search-input">
            <Search :size="18" /><input
              id="document-search"
              v-model="filters.search"
              type="search"
              enterkeyhint="search"
              placeholder="Buscar pelo título do documento"
              maxlength="255"
            />
          </div>
        </div>
        <div class="filter-field">
          <label for="document-type">Tipo documental</label
          ><select
            id="document-type"
            v-model="filters.document_type_id"
            :disabled="!catalogReady"
          >
            <option value="">Todos os tipos</option>
            <option
              v-for="type in types"
              :key="type.id"
              :value="String(type.id)"
            >
              {{ type.name }}
            </option>
          </select>
        </div>
        <div class="filter-field">
          <label for="producing-unit">Unidade produtora</label
          ><select
            id="producing-unit"
            v-model="filters.producing_unit_id"
            :disabled="!catalogReady"
          >
            <option value="">Todas as unidades acessíveis</option>
            <option
              v-for="unit in units"
              :key="unit.id"
              :value="String(unit.id)"
            >
              {{ unit.name }}
            </option>
          </select>
        </div>
        <div class="filter-field">
          <label for="document-status">Situação</label
          ><select id="document-status" v-model="filters.archived">
            <option value="0">Ativos</option>
            <option value="1">Arquivados</option>
          </select>
        </div>
      </div>
      <div class="filter-bottom">
        <div class="date-filters">
          <span class="date-group-label">Período de produção</span>
          <div class="date-field">
            <label for="production-from">De</label
            ><input
              id="production-from"
              v-model="filters.production_date_from"
              type="date"
              :max="filters.production_date_to || undefined"
              :aria-invalid="Boolean(fieldErrors.production_date_from)"
            />
          </div>
          <span class="date-separator">—</span>
          <div class="date-field">
            <label for="production-to">Até</label
            ><input
              id="production-to"
              v-model="filters.production_date_to"
              type="date"
              :min="filters.production_date_from || undefined"
              :aria-invalid="Boolean(fieldErrors.production_date_to)"
            />
          </div>
        </div>
        <div class="filter-buttons">
          <button type="button" class="button ghost" @click="clear">
            Limpar</button
          ><button class="button primary" :disabled="loading">
            <Search :size="16" />Aplicar filtros
          </button>
        </div>
      </div>
      <p
        v-for="(messages, field) in fieldErrors"
        :key="field"
        class="field-error"
        role="alert"
      >
        {{ messages[0] }}
      </p>
    </form>
    <p v-if="catalogError" class="catalog-warning" role="alert">
      Alguns filtros não puderam ser carregados.
      <button @click="catalogs">Tentar novamente</button>
    </p>
  </section>
  <div v-if="downloadError" class="notice error" role="alert">
    {{ downloadError }}
  </div>
  <section
    class="panel documents-panel"
    :aria-busy="loading"
    aria-label="Listagem de documentos"
  >
    <div class="document-list-heading">
      <h2>
        {{
          readFilters(route.query).archived === "1"
            ? "Documentos arquivados"
            : "Documentos ativos"
        }}
      </h2>
      <span v-if="!loading && page && !error">{{
        page.total
          ? `${number(page.from || 0)}–${number(page.to || 0)} de ${number(page.total)}`
          : "Nenhum documento"
      }}</span
      ><span v-else-if="loading" role="status">Carregando…</span>
    </div>
    <div v-if="error" class="error-state" role="alert">
      <AlertCircle :size="32" />
      <h3>Não foi possível carregar os documentos</h3>
      <p>{{ error }}</p>
      <button class="button secondary" @click="load">Tentar novamente</button>
    </div>
    <div
      v-else-if="loading"
      class="table-skeleton"
      aria-label="Carregando documentos"
    >
      <div v-for="index in 6" :key="index">
        <span class="skeleton" /><span class="skeleton" /><span
          class="skeleton"
        />
      </div>
    </div>
    <div v-else-if="!page?.data.length" class="empty-state document-empty">
      <span class="empty-icon"><FolderOpen :size="32" /></span>
      <h3>
        {{
          activeFilterCount
            ? "Nenhum documento encontrado"
            : "Ainda não há documentos por aqui"
        }}
      </h3>
      <p>
        {{
          activeFilterCount
            ? "Ajuste os filtros para encontrar o que procura."
            : "Os documentos disponíveis para sua conta aparecerão neste espaço."
        }}
      </p>
      <button v-if="activeFilterCount" class="button secondary" @click="clear">
        Limpar filtros
      </button>
    </div>
    <template v-else
      ><div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th scope="col">Documento</th>
              <th scope="col">Tipo</th>
              <th scope="col">Unidade produtora</th>
              <th scope="col">Produção</th>
              <th scope="col">Versão</th>
              <th scope="col"><span class="sr-only">Ações</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="record in page.data" :key="record.id">
              <td>
                <div class="document-cell">
                  <span class="file-badge">{{
                    (
                      record.latest_version?.extension ||
                      record.latest_version?.original_filename
                        .split(".")
                        .pop() ||
                      "DOC"
                    )
                      .toUpperCase()
                      .slice(0, 5)
                  }}</span>
                  <div>
                    <button
                      class="document-title"
                      @click="showDocument(record, $event)"
                    >
                      {{ record.title }}</button
                    ><span class="document-subtitle">{{
                      record.reference_number ||
                      record.latest_version?.original_filename ||
                      "Sem referência"
                    }}</span>
                  </div>
                </div>
              </td>
              <td data-label="Tipo">
                <span class="type-pill">{{ record.type.name }}</span>
              </td>
              <td data-label="Unidade produtora">
                <span class="unit-name">{{
                  record.producing_unit?.name || "Acesso geral"
                }}</span>
              </td>
              <td class="date-cell" data-label="Produção">
                {{ dateOnly(record.production_date) }}
              </td>
              <td data-label="Versão">
                <span class="version-label">{{
                  record.latest_version
                    ? `v${record.latest_version.version_number}`
                    : "—"
                }}</span>
              </td>
              <td>
                <button
                  v-if="auth.can('documents.download') && record.latest_version"
                  class="icon-button table-action"
                  :aria-label="`Baixar ${record.title}`"
                  :disabled="downloading !== null"
                  @click="download(record.latest_version)"
                >
                  <LoaderCircle
                    v-if="downloading === record.latest_version.id"
                    :size="18"
                    class="spin"
                  /><Download v-else :size="18" /></button
                ><button
                  v-else
                  class="icon-button table-action"
                  :aria-label="`Consultar ${record.title}`"
                  @click="showDocument(record, $event)"
                >
                  <ArrowUpRight :size="18" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="pagination">
        <span
          >{{ number(page.total) }}
          {{
            page.total === 1 ? "documento disponível" : "documentos disponíveis"
          }}</span
        >
        <div>
          <button
            class="button pagination-button"
            :disabled="page.current_page <= 1 || loading"
            @click="goTo(page.current_page - 1)"
          >
            <ChevronLeft :size="16" /><span>Anterior</span></button
          ><span class="page-number"
            >{{ page.current_page }} <span>de {{ page.last_page }}</span></span
          ><button
            class="button pagination-button"
            :disabled="page.current_page >= page.last_page || loading"
            @click="goTo(page.current_page + 1)"
          >
            <span>Próxima</span><ChevronRight :size="16" />
          </button>
        </div></div
    ></template>
  </section>
  <p class="list-footnote">
    <ShieldCheck :size="14" />Esta listagem respeita suas unidades e permissões
    de acesso.
  </p>
  <dialog
    ref="drawer"
    class="document-dialog"
    aria-labelledby="detail-title"
    @close="closed"
    @click="
      (event) => {
        if (event.target === drawer) closeDrawer();
      }
    "
  >
    <template v-if="selected"
      ><div class="dialog-heading">
        <span class="eyebrow">DETALHES DO DOCUMENTO</span
        ><button
          class="icon-button"
          aria-label="Fechar detalhes"
          @click="closeDrawer"
        >
          <X :size="20" />
        </button>
      </div>
      <h2 id="detail-title" ref="drawerTitle" tabindex="-1">
        {{ selected.title }}
      </h2>
      <p class="dialog-description">
        {{ selected.description || "Nenhuma descrição informada." }}
      </p>
      <div v-if="detailError" class="notice error" role="alert">
        {{ detailError }}
      </div>
      <div v-else-if="detailLoading" class="dialog-loading" role="status">
        <LoaderCircle :size="22" class="spin" />Carregando detalhes…
      </div>
      <template v-else
        ><dl class="document-metadata">
          <div>
            <dt>Tipo documental</dt>
            <dd>{{ selected.type.name }}</dd>
          </div>
          <div>
            <dt>Unidade produtora</dt>
            <dd>{{ selected.producing_unit?.name || "Acesso geral" }}</dd>
          </div>
          <div>
            <dt>Data de produção</dt>
            <dd>{{ dateOnly(selected.production_date) }}</dd>
          </div>
          <div>
            <dt>Situação</dt>
            <dd>{{ selected.archived_at ? "Arquivado" : "Ativo" }}</dd>
          </div>
          <div>
            <dt>Autor</dt>
            <dd>{{ selected.author || "Não informado" }}</dd>
          </div>
          <div>
            <dt>Interessado</dt>
            <dd>{{ selected.interested_party || "Não informado" }}</dd>
          </div>
          <div>
            <dt>Número / referência</dt>
            <dd>{{ selected.reference_number || "Não informado" }}</dd>
          </div>
        </dl>
        <section v-if="selectedVersion" class="detail-file">
          <span class="file-badge"><FileText :size="20" /></span>
          <div>
            <strong>{{ selectedVersion.original_filename }}</strong
            ><small
              >Versão {{ selectedVersion.version_number }} ·
              {{ bytes(selectedVersion.size_bytes) }}</small
            >
          </div>
          <button
            v-if="auth.can('documents.download')"
            class="icon-button"
            :disabled="downloading !== null"
            aria-label="Baixar versão atual"
            @click="download(selectedVersion)"
          >
            <Download :size="19" />
          </button>
        </section>
        <div v-if="selectedVersion" class="hash-block">
          <span><ShieldCheck :size="15" />SHA-256 registrado</span
          ><code>{{ selectedVersion.sha256 }}</code>
        </div>
        <section v-if="selected.versions?.length && ['shares.create', 'shares.revoke-own', 'shares.revoke-any'].some(auth.can)" class="version-sharing"><h3>Compartilhamento por versão</h3><p class="field-help">Consulte os acessos concedidos ou gere um link temporário para a versão desejada.</p><div v-for="version in selected.versions.slice().sort((a, b) => b.version_number - a.version_number)" :key="version.id" class="share-version-row"><span>Versão {{ version.version_number }}<small>{{ version.original_filename }}</small></span><RouterLink class="button secondary" :to="{name:'version-shares',params:{id:selected.id,version:version.id}}" @click="closeDrawer">Gerenciar acessos</RouterLink></div></section>
        <section v-if="selected.versions && selected.versions.length > 1" class="version-history" aria-label="Histórico de versões"><h3>Versões anteriores</h3><div v-for="version in selected.versions.filter(item => item.id !== selectedVersion?.id).slice().sort((a, b) => b.version_number - a.version_number)" :key="version.id" class="detail-file"><FileText :size="18" /><div><strong>{{ version.original_filename }}</strong><small>Versão {{ version.version_number }} · {{ bytes(version.size_bytes) }}</small></div><button v-if="auth.can('documents.download')" class="icon-button" :disabled="downloading !== null" :aria-label="`Baixar versão ${version.version_number}`" @click="download(version)"><Download :size="18" /></button></div></section>
        <div v-if="!selected.archived_at && (selected.producing_unit || auth.can('documents.manage-general'))" class="detail-actions">
          <RouterLink v-if="auth.can('documents.update')" class="button secondary" :to="{ name: 'document-edit', params: { id: selected.id } }" @click="closeDrawer">Editar metadados</RouterLink>
          <RouterLink v-if="auth.can('documents.add-version')" class="button primary" :to="{ name: 'document-version', params: { id: selected.id } }" @click="closeDrawer">Nova versão</RouterLink>
        </div>
        <div v-if="downloadError" class="notice error" role="alert">
          {{ downloadError }}
        </div></template
      ><button class="button secondary dialog-close" @click="closeDrawer">
        Fechar detalhes
      </button></template
    >
  </dialog>
</template>
