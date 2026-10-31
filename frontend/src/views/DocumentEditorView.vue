<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRoute } from 'vue-router'
import axios from 'axios'
import { ArrowLeft, CheckCircle2, FileText, ShieldCheck, LoaderCircle, AlertCircle, Save, UploadCloud } from '@lucide/vue'
import FileUpload from '../components/FileUpload.vue'
import { api, errorMessage } from '../lib/http'
import { bytes } from '../lib/format'
import { fileError } from '../lib/uploads'
import { useAuth } from '../stores/auth'
import type { DocumentRecord, DocumentType, DocumentVersion, FieldErrors, Unit } from '../types'

const auth = useAuth(), route = useRoute()
const mode = computed(() => route.name === 'document-create' ? 'create' : route.name === 'document-version' ? 'version' : 'edit')
const title = computed(() => mode.value === 'create' ? 'Novo documento' : mode.value === 'version' ? 'Nova versão' : 'Editar metadados')
const blank = () => ({ title: '', description: '', document_type_id: '', producing_unit_id: '', production_date: '', author: '', interested_party: '', reference_number: '', change_note: '' })
const form = reactive(blank()), original = ref(''), file = ref<File | null>(null)
const document = ref<DocumentRecord | null>(null), types = ref<DocumentType[]>([]), units = ref<Unit[]>([])
const loading = ref(true), loadError = ref(''), error = ref(''), errors = ref<FieldErrors>({}), saving = ref(false), progress = ref(0)
const saved = ref<{ title: string; version?: number } | null>(null)
const formElement = ref<HTMLFormElement | null>(null), titleInput = ref<HTMLInputElement | null>(null), successHeading = ref<HTMLElement | null>(null)
let controller: AbortController | undefined
const latest = computed(() => document.value?.versions?.slice().sort((a, b) => b.version_number - a.version_number)[0])
const allowed = computed(() => {
  const permission = mode.value === 'create' ? 'documents.create' : mode.value === 'version' ? 'documents.add-version' : 'documents.update'
  if (!auth.can(permission)) return false
  if (mode.value !== 'create' && document.value) return !document.value.archived_at && (document.value.producing_unit !== null || auth.can('documents.manage-general'))
  return true
})
const hasDestination = computed(() => mode.value !== 'create' || units.value.length > 0 || auth.can('documents.manage-general'))
const dirty = computed(() => !saved.value && !loading.value && (JSON.stringify(form) !== original.value || file.value !== null))
const writableTypes = computed(() => {
  if (document.value && !types.value.some(type => type.id === document.value?.type.id)) return [...types.value, { ...document.value.type, name: `${document.value.type.name} (tipo atual)` }]
  return types.value
})
function confirmLeave(): boolean {
  if (saving.value) return false
  return !dirty.value || window.confirm('Existem alterações não salvas. Deseja sair desta tela?')
}
onBeforeRouteLeave(to => to.name === 'login' ? true : confirmLeave())
onBeforeRouteUpdate((to, from) => to.path === from.path || confirmLeave())
function beforeUnload(event: BeforeUnloadEvent) { if (dirty.value || saving.value) { event.preventDefault(); event.returnValue = '' } }
window.addEventListener('beforeunload', beforeUnload)
onBeforeUnmount(() => { controller?.abort(); window.removeEventListener('beforeunload', beforeUnload) })
async function load() {
  controller?.abort(); const request = new AbortController(); controller = request
  loading.value = true; loadError.value = ''; saved.value = null; errors.value = {}; error.value = ''; file.value = null
  Object.assign(form, blank()); document.value = null
  try {
    const [catalog, unitCatalog, record] = await Promise.all([
      mode.value !== 'version' ? api.get<DocumentType[]>('/api/v1/document-types', { signal: request.signal }) : Promise.resolve({ data: [] as DocumentType[] }),
      mode.value === 'create' ? api.get<Unit[]>('/api/v1/units', { signal: request.signal }) : Promise.resolve({ data: [] as Unit[] }),
      mode.value !== 'create' ? api.get<{ data: DocumentRecord }>(`/api/v1/documents/${route.params.id}`, { signal: request.signal }) : Promise.resolve(null),
    ])
    if (request.signal.aborted) return
    types.value = catalog.data; units.value = unitCatalog.data
    if (record) {
      document.value = record.data.data
      const item = document.value
      Object.assign(form, { title: item.title, description: item.description || '', document_type_id: String(item.type.id), production_date: item.production_date.slice(0, 10), author: item.author || '', interested_party: item.interested_party || '', reference_number: item.reference_number || '' })
    }
    original.value = JSON.stringify(form)
  } catch (cause) {
    if (!axios.isCancel(cause)) loadError.value = axios.isAxiosError(cause) && cause.response?.status === 404 ? 'Documento não encontrado.' : errorMessage(cause)
  } finally { if (!request.signal.aborted) loading.value = false }
}
async function submit() {
  if (saving.value || !allowed.value || !hasDestination.value) return
  errors.value = {}; error.value = ''
  if (mode.value !== 'edit') {
    if (!file.value) { errors.value = { file: ['Selecione o arquivo que deseja enviar.'] }; return }
    const invalid = fileError(file.value)
    if (invalid) { errors.value = { file: [invalid] }; return }
  }
  saving.value = true; progress.value = 0
  try {
    await api.get('/sanctum/csrf-cookie')
    if (mode.value === 'edit') {
      const payload = { title: form.title.trim(), document_type_id: Number(form.document_type_id), production_date: form.production_date, description: form.description || null, author: form.author || null, interested_party: form.interested_party || null, reference_number: form.reference_number || null }
      const response = await api.patch<{ data: DocumentRecord }>(`/api/v1/documents/${route.params.id}`, payload)
      saved.value = { title: response.data.data.title }
    } else {
      const payload = new FormData(); payload.append('file', file.value!, file.value!.name)
      if (mode.value === 'create') {
        for (const key of ['title', 'description', 'document_type_id', 'production_date', 'author', 'interested_party', 'reference_number'] as const) payload.append(key, form[key])
        if (form.producing_unit_id !== 'general') payload.append('producing_unit_id', form.producing_unit_id)
      } else payload.append('change_note', form.change_note)
      const path = mode.value === 'create' ? '/api/v1/documents' : `/api/v1/documents/${route.params.id}/versions`
      const response = await api.post<{ data: DocumentRecord & DocumentVersion }>(path, payload, { timeout: 0, onUploadProgress: event => { if (event.total) progress.value = Math.min(100, Math.round(event.loaded / event.total * 100)) } })
      saved.value = { title: mode.value === 'create' ? response.data.data.title : document.value!.title, version: mode.value === 'create' ? response.data.data.latest_version?.version_number : response.data.data.version_number }
    }
    file.value = null; original.value = JSON.stringify(form)
    await nextTick(); successHeading.value?.focus()
  } catch (cause) {
    if (axios.isAxiosError(cause) && cause.response?.status === 422) {
      errors.value = cause.response.data.errors || {}; error.value = 'Confira os campos indicados para continuar.'
      await nextTick(); formElement.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
    } else if (axios.isAxiosError(cause) && cause.response?.status === 413) error.value = 'O arquivo excede o limite de envio de 100 MB.'
    else if (axios.isAxiosError(cause) && !cause.response) error.value = 'Não recebemos a confirmação do servidor. Confira a listagem antes de reenviar para evitar duplicações.'
    else error.value = errorMessage(cause, 'Não foi possível salvar. Confira os dados e tente novamente.')
  } finally { saving.value = false }
}
async function createAnother() { saved.value = null; Object.assign(form, blank()); file.value = null; errors.value = {}; error.value = ''; original.value = JSON.stringify(form); await nextTick(); titleInput.value?.focus() }
watch(() => route.fullPath, load, { immediate: true })
</script>

<template>
  <RouterLink :to="{ name: 'documents' }" class="editor-back"><ArrowLeft :size="16" />Voltar aos documentos</RouterLink>
  <section class="page-heading"><div><span class="eyebrow">CUIDE DO SEU ACERVO</span><h1>{{ title }}</h1><p>{{ mode === 'create' ? 'Dê contexto ao documento e envie o arquivo original.' : mode === 'edit' ? 'Mantenha as informações do documento atualizadas.' : 'Preserve o histórico ao enviar um arquivo atualizado.' }}</p></div></section>
  <div v-if="loading" class="panel editor-loading" role="status"><LoaderCircle class="spin" :size="24" />Preparando formulário…</div>
  <div v-else-if="loadError" class="panel error-state" role="alert"><AlertCircle :size="32" /><h2>Não foi possível abrir o formulário</h2><p>{{ loadError }}</p><button class="button secondary" @click="load">Tentar novamente</button></div>
  <section v-else-if="saved" class="panel editor-success" role="status"><span class="success-symbol"><CheckCircle2 :size="32" /></span><h2 ref="successHeading" tabindex="-1">{{ mode === 'create' ? 'Documento cadastrado' : mode === 'edit' ? 'Metadados atualizados' : `Versão ${saved.version} adicionada` }}</h2><p>{{ saved.title }}</p><span v-if="mode !== 'edit'" class="success-note">Arquivo armazenado e hash de integridade registrado.</span><div class="editor-success-actions"><RouterLink class="button primary" :to="{ name: 'documents', query: { search: saved.title } }">Ver na listagem</RouterLink><button v-if="mode === 'create'" class="button secondary" @click="createAnother">Cadastrar outro</button><RouterLink v-else class="button secondary" :to="{ name: 'documents' }">Voltar ao acervo</RouterLink></div></section>
  <div v-else-if="!allowed || !hasDestination" class="panel error-state" role="alert"><ShieldCheck :size="32" /><h2>{{ document?.archived_at ? 'Documento arquivado' : 'Ação indisponível para sua conta' }}</h2><p>{{ document?.archived_at ? 'A restauração do documento é necessária para editar os metadados ou adicionar versões.' : !hasDestination ? 'Sua conta precisa de uma unidade autorizada para cadastrar documentos.' : 'Você precisa de permissão e acesso normal ao documento para realizar esta ação.' }}</p><RouterLink class="button secondary" :to="{ name: 'documents' }">Voltar aos documentos</RouterLink></div>
  <form v-else ref="formElement" class="editor-grid" @submit.prevent="submit">
    <div class="editor-main">
      <div v-if="error" class="notice error" role="alert">{{ error }}</div>
      <section v-if="mode !== 'version'" class="panel editor-panel"><div class="panel-heading"><div><h2>Informações do documento</h2><p>Os campos com asterisco são obrigatórios.</p></div><FileText :size="21" /></div>
        <fieldset :disabled="saving" class="metadata-form">
          <div class="form-field full"><label for="editor-title">Título <span aria-hidden="true">*</span></label><input ref="titleInput" id="editor-title" v-model="form.title" required maxlength="255" :aria-invalid="Boolean(errors.title)" :aria-describedby="errors.title ? 'editor-title-error' : undefined" /><p v-if="errors.title" id="editor-title-error" class="field-error">{{ errors.title[0] }}</p></div>
          <div class="form-field"><label for="editor-type">Tipo documental *</label><select id="editor-type" v-model="form.document_type_id" required :aria-invalid="Boolean(errors.document_type_id)"><option disabled value="">Selecione um tipo</option><option v-for="type in writableTypes" :key="type.id" :value="String(type.id)">{{ type.name }}</option></select><p v-if="errors.document_type_id" class="field-error">{{ errors.document_type_id[0] }}</p></div>
          <div class="form-field"><label for="editor-date">Data de produção *</label><input id="editor-date" v-model="form.production_date" type="date" required :aria-invalid="Boolean(errors.production_date)" /><p v-if="errors.production_date" class="field-error">{{ errors.production_date[0] }}</p></div>
          <div v-if="mode === 'create'" class="form-field full"><label for="editor-unit">Unidade produtora *</label><select id="editor-unit" v-model="form.producing_unit_id" required :aria-invalid="Boolean(errors.producing_unit_id)"><option disabled value="">Selecione a unidade responsável</option><option v-for="unit in units" :key="unit.id" :value="String(unit.id)">{{ unit.name }}</option><option v-if="auth.can('documents.manage-general')" value="general">Sem unidade · acesso geral</option></select><p v-if="form.producing_unit_id === 'general'" class="field-help general-warning">Este documento ficará acessível aos usuários do GED com permissão de consulta.</p><p v-if="errors.producing_unit_id" class="field-error">{{ errors.producing_unit_id[0] }}</p></div>
          <div class="form-field"><label for="editor-author">Autor</label><input id="editor-author" v-model="form.author" maxlength="255" :aria-invalid="Boolean(errors.author)" /><p v-if="errors.author" class="field-error">{{ errors.author[0] }}</p></div>
          <div class="form-field"><label for="editor-party">Interessado</label><input id="editor-party" v-model="form.interested_party" maxlength="255" :aria-invalid="Boolean(errors.interested_party)" /><p v-if="errors.interested_party" class="field-error">{{ errors.interested_party[0] }}</p></div>
          <div class="form-field full"><label for="editor-reference">Número / referência</label><input id="editor-reference" v-model="form.reference_number" maxlength="255" placeholder="Ex.: Ofício 015/2026" :aria-invalid="Boolean(errors.reference_number)" /><p v-if="errors.reference_number" class="field-error">{{ errors.reference_number[0] }}</p></div>
          <div class="form-field full"><label for="editor-description">Descrição</label><textarea id="editor-description" v-model="form.description" rows="4" maxlength="10000" placeholder="Contextualize o conteúdo do documento." :aria-invalid="Boolean(errors.description)" /><p v-if="errors.description" class="field-error">{{ errors.description[0] }}</p></div>
        </fieldset>
      </section>
      <section v-if="mode !== 'edit'" class="panel editor-panel"><div class="panel-heading"><div><h2>{{ mode === 'create' ? 'Arquivo original' : 'Arquivo da nova versão' }}</h2><p>Um arquivo por versão, com integridade registrada.</p></div><UploadCloud :size="21" /></div><FileUpload v-model="file" :disabled="saving" :error="errors.file?.[0]" /><div v-if="mode === 'version'" class="form-field version-note"><label for="change-note">O que mudou nesta versão?</label><textarea id="change-note" v-model="form.change_note" rows="3" maxlength="5000" :disabled="saving" placeholder="Descreva a atualização para facilitar a consulta ao histórico." :aria-invalid="Boolean(errors.change_note)" /><p v-if="errors.change_note" class="field-error">{{ errors.change_note[0] }}</p></div></section>
      <div v-if="saving && mode !== 'edit'" class="panel upload-progress" role="status"><div><span>{{ progress < 100 ? 'Enviando arquivo…' : 'Processando e registrando o documento…' }}</span><strong>{{ progress }}%</strong></div><progress :value="progress" max="100" aria-label="Progresso do envio" /><p>Aguarde a confirmação antes de fechar esta tela.</p></div>
      <div class="editor-actions"><RouterLink v-if="!saving" class="button secondary" :to="{ name: 'documents' }">Cancelar</RouterLink><button class="button primary" :disabled="saving"><LoaderCircle v-if="saving" :size="17" class="spin" /><Save v-else :size="17" />{{ saving ? 'Salvando…' : mode === 'create' ? 'Cadastrar documento' : mode === 'edit' ? 'Salvar metadados' : 'Enviar nova versão' }}</button></div>
    </div>
    <aside class="editor-aside panel"><span class="access-icon"><ShieldCheck :size="24" /></span><span class="eyebrow">HISTÓRICO PRESERVADO</span><h2>{{ mode === 'create' ? 'Um cadastro completo começa pelo contexto.' : mode === 'edit' ? 'Informações claras, acervo organizado.' : 'Cada atualização tem seu lugar.' }}</h2><p>{{ mode === 'create' ? 'Tipo, data e unidade ajudam a encontrar o documento e definir seu acesso.' : mode === 'edit' ? 'As alterações dos metadados ficam registradas na auditoria. Os arquivos e suas versões são preservados.' : 'O arquivo atualizado será uma nova versão. As anteriores continuam disponíveis no histórico.' }}</p><template v-if="document"><span class="small-label">DOCUMENTO</span><strong class="editor-document-title">{{ document.title }}</strong><span class="small-label">UNIDADE PRODUTORA</span><p>{{ document.producing_unit?.name || 'Acesso geral' }}</p><div v-if="latest" class="editor-current-file"><FileText :size="18" /><span>{{ latest.original_filename }}<small>Versão {{ latest.version_number }} · {{ bytes(latest.size_bytes) }}</small></span></div></template><p class="editor-aside-foot">{{ mode === 'edit' ? 'A unidade produtora é mantida nesta edição.' : 'PDF, DOCX, XLSX, CSV, MP3, MP4, EML e MSG. Até 100 MB por arquivo.' }}</p></aside>
  </form>
</template>
