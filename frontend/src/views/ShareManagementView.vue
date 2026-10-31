<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRoute } from 'vue-router'
import axios from 'axios'
import { ArrowLeft, Share2, Search, X, Copy, ShieldCheck, LoaderCircle, Link, Clock, Users, RefreshCw } from '@lucide/vue'
import { api, errorMessage } from '../lib/http'
import { bytes } from '../lib/format'
import { isoTimestamp, shareLabels, shareState, timestamp } from '../lib/shares'
import { useAuth } from '../stores/auth'
import type { DocumentRecord, DocumentShare, DocumentVersion, FieldErrors, SharePage, ShareRecipient } from '../types'

const auth = useAuth(), route = useRoute()
const record = ref<DocumentRecord | null>(null), version = ref<DocumentVersion | null>(null)
const loading = ref(true), loadError = ref(''), error = ref(''), errors = ref<FieldErrors>({})
const shares = ref<SharePage | null>(null), listLoading = ref(false), listError = ref('')
const people = ref<ShareRecipient[]>([]), selected = ref<ShareRecipient[]>([]), search = ref(''), peoplePage = ref(1), peopleLast = ref(1), peopleLoading = ref(false), peopleError = ref('')
const starts = ref(''), expires = ref(''), saving = ref(false), revoking = ref<number | null>(null)
const newLink = ref(''), createdId = ref<number | null>(null), copyMessage = ref(''), success = ref('')
const now = ref(Date.now()), interval = window.setInterval(() => now.value = Date.now(), 15000)
let controller: AbortController | undefined, peopleController: AbortController | undefined, listController: AbortController | undefined
const canCreate = computed(() => auth.can('shares.create') && auth.can('documents.download') && !record.value?.archived_at)
const canManage = computed(() => ['shares.create', 'shares.revoke-own', 'shares.revoke-any'].some(auth.can))
const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone
function state(share: DocumentShare) { return shareState(share, now.value) }
function canRevoke(share: DocumentShare) { return !share.revoked_at && (auth.can('shares.revoke-any') || (auth.can('shares.revoke-own') && share.created_by === auth.user?.id)) }
function leave() { return !saving.value && revoking.value === null && (!(selected.value.length || starts.value || expires.value) || window.confirm('Existem dados de compartilhamento não salvos. Deseja sair?')) }
onBeforeRouteLeave(to => to.name === 'login' || leave())
onBeforeRouteUpdate((to, from) => to.fullPath === from.fullPath || leave())
function beforeUnload(event: BeforeUnloadEvent) { if (saving.value || revoking.value !== null || selected.value.length || starts.value || expires.value) { event.preventDefault(); event.returnValue = '' } }
window.addEventListener('beforeunload', beforeUnload)
onBeforeUnmount(() => { controller?.abort(); peopleController?.abort(); listController?.abort(); clearInterval(interval); window.removeEventListener('beforeunload', beforeUnload) })
async function load() {
  controller?.abort(); peopleController?.abort(); listController?.abort(); const request = new AbortController(); controller = request
  loading.value = true; loadError.value = ''; record.value = null; version.value = null; newLink.value = ''; createdId.value = null; shares.value = null; selected.value = []; starts.value = ''; expires.value = ''; success.value = ''; error.value = ''; errors.value = {}
  try {
    const response = await api.get<{ data: DocumentRecord }>(`/api/v1/documents/${route.params.id}`, { signal: request.signal })
    if (request.signal.aborted) return
    record.value = response.data.data
    version.value = record.value.versions?.find(item => item.id === Number(route.params.version)) || null
    if (!version.value) { loadError.value = 'A versão solicitada não pertence a este documento.'; return }
    await Promise.all([canManage.value ? loadShares() : Promise.resolve(), canCreate.value ? loadPeople() : Promise.resolve()])
  } catch (cause) { if (!axios.isCancel(cause)) loadError.value = errorMessage(cause, 'Não foi possível consultar o documento.') }
  finally { if (!request.signal.aborted) loading.value = false }
}
async function loadShares(page = 1) {
  listController?.abort(); const request = new AbortController(); listController = request; listLoading.value = true; listError.value = ''
  try { const response = await api.get<SharePage>(`/api/v1/versions/${version.value!.id}/shares`, { params: { page }, signal: request.signal }); if (!request.signal.aborted) shares.value = response.data }
  catch (cause) { if (!axios.isCancel(cause)) listError.value = errorMessage(cause) }
  finally { if (!request.signal.aborted) listLoading.value = false }
}
async function loadPeople(page = 1) {
  peopleController?.abort(); const request = new AbortController(); peopleController = request; peopleLoading.value = true; peopleError.value = ''
  try { const response = await api.get<{ data: ShareRecipient[]; current_page: number; last_page: number }>('/api/v1/share-recipients', { params: { search: search.value.trim(), page }, signal: request.signal }); if (!request.signal.aborted) { people.value = response.data.data; peoplePage.value = response.data.current_page; peopleLast.value = response.data.last_page } }
  catch (cause) { if (!axios.isCancel(cause)) peopleError.value = errorMessage(cause) }
  finally { if (!request.signal.aborted) peopleLoading.value = false }
}
function toggle(person: ShareRecipient) {
  if (saving.value) return
  if (selected.value.some(item => item.id === person.id)) selected.value = selected.value.filter(item => item.id !== person.id)
  else if (selected.value.length < 100) selected.value.push(person)
  else errors.value = { recipient_ids: ['Selecione no máximo 100 usuários.'] }
}
async function create() {
  if (saving.value || !canCreate.value) return
  errors.value = {}; error.value = ''; success.value = ''; copyMessage.value = ''
  if (!selected.value.length) errors.value.recipient_ids = ['Selecione pelo menos um usuário.']
  const end = Date.parse(expires.value), start = starts.value ? Date.parse(starts.value) : Date.now()
  if (!Number.isFinite(end) || end <= Date.now() || end <= start) errors.value.expires_at = ['Informe uma validade futura e posterior ao início.']
  if (starts.value && !Number.isFinite(start)) errors.value.starts_at = ['Informe uma data de início válida.']
  if (Object.keys(errors.value).length) return
  saving.value = true
  try {
    await api.get('/sanctum/csrf-cookie')
    const response = await api.post<{ data: DocumentShare; token: string }>(`/api/v1/versions/${version.value!.id}/shares`, { recipient_ids: selected.value.map(item => item.id), expires_at: isoTimestamp(expires.value), ...(starts.value ? { starts_at: isoTimestamp(starts.value) } : {}) })
    newLink.value = `${window.location.origin}/compartilhado/${response.data.token}`; createdId.value = response.data.data.id
    selected.value = []; starts.value = ''; expires.value = ''; success.value = 'Compartilhamento criado. Copie o link abaixo e envie aos usuários selecionados.'
    await loadShares()
  } catch (cause) {
    if (axios.isAxiosError(cause) && cause.response?.status === 422) { errors.value = cause.response.data.errors || {}; error.value = 'Confira os campos indicados.' }
    else error.value = axios.isAxiosError(cause) && !cause.response ? 'Não recebemos a confirmação. Atualize o histórico antes de tentar novamente para evitar duplicações.' : errorMessage(cause, 'Não foi possível criar o compartilhamento.')
  } finally { saving.value = false }
}
async function copy() { try { await navigator.clipboard.writeText(newLink.value); copyMessage.value = 'Link copiado.' } catch { copyMessage.value = 'Selecione o link e copie manualmente.' } }
async function revoke(share: DocumentShare) {
  if (revoking.value !== null || saving.value || !canRevoke(share) || !window.confirm('Revogar este compartilhamento? Os destinatários perderão o acesso concedido por ele.')) return
  revoking.value = share.id; error.value = ''; success.value = ''
  try { await api.get('/sanctum/csrf-cookie'); const response = await api.post<{ data: { revoked_at: string } }>(`/api/v1/shares/${share.id}/revoke`); share.revoked_at = response.data.data.revoked_at; share.state = 'revoked'; if (createdId.value === share.id) newLink.value = ''; success.value = 'Compartilhamento revogado.' }
  catch (cause) { error.value = errorMessage(cause, 'Não foi possível confirmar a revogação. Atualize o histórico para consultar a situação atual.') }
  finally { revoking.value = null }
}
watch(() => route.fullPath, load, { immediate: true })
</script>

<template>
  <RouterLink class="editor-back" :to="{ name: 'documents' }"><ArrowLeft :size="16" />Voltar aos documentos</RouterLink>
  <section class="page-heading"><div><span class="eyebrow">ACESSO COM PRAZO DEFINIDO</span><h1>Compartilhar versão</h1><p>Disponibilize o arquivo certo, para as pessoas certas.</p></div></section>
  <div v-if="loading" class="panel editor-loading" role="status"><LoaderCircle class="spin" :size="24" />Preparando compartilhamentos…</div>
  <div v-else-if="loadError" class="panel error-state" role="alert"><h2>Não foi possível abrir esta versão</h2><p>{{ loadError }}</p><button class="button secondary" @click="load">Tentar novamente</button></div>
  <div v-else-if="!canManage" class="panel error-state"><ShieldCheck :size="30" /><h2>Ação indisponível para sua conta</h2><p>Você precisa de permissão para consultar os compartilhamentos desta versão.</p></div>
  <div v-else class="editor-grid share-layout">
    <div class="editor-main">
      <p v-if="error" class="notice error" role="alert">{{ error }}</p><p v-if="success" class="notice share-notice" role="status">{{ success }}</p>
      <section v-if="newLink" class="panel editor-panel generated-link"><h2><Link :size="18" />Link do compartilhamento</h2><p>Guarde este link agora: ele só é exibido após a criação. Apenas os usuários selecionados poderão acessá-lo, após entrar no GED.</p><label class="sr-only" for="share-link">Link gerado</label><div class="share-link-row"><input id="share-link" :value="newLink" readonly autocomplete="off" /><button type="button" class="button secondary" @click="copy"><Copy :size="16" />Copiar</button></div><p v-if="copyMessage" role="status">{{ copyMessage }}</p></section>
      <form v-if="canCreate" class="panel editor-panel" @submit.prevent="create">
        <div class="panel-heading"><div><h2>Novo compartilhamento</h2><p>O acesso será exclusivo para esta versão.</p></div><Share2 :size="21" /></div>
        <fieldset class="share-form" :disabled="saving"><legend class="sr-only">Destinatários e validade</legend>
          <label for="recipient-search" class="share-field-label">Selecionar usuários <span aria-hidden="true">*</span></label>
          <div class="recipient-search"><input id="recipient-search" v-model="search" type="search" maxlength="255" placeholder="Buscar por nome ou e-mail" @keydown.enter.prevent="loadPeople()" /><button type="button" class="button secondary" :disabled="peopleLoading" @click="loadPeople()"><Search :size="16" />Buscar</button></div>
          <div v-if="selected.length" class="selected-recipients" aria-label="Usuários selecionados"><button v-for="person in selected" :key="person.id" type="button" class="recipient-chip" :aria-label="`Remover ${person.name}`" @click="toggle(person)">{{ person.name }}<X :size="14" /></button></div>
          <p class="field-help">{{ selected.length }} de 100 usuários selecionados. A seleção é mantida ao buscar e trocar de página.</p>
          <p v-if="peopleError" class="notice error" role="alert">{{ peopleError }} <button type="button" class="button ghost" @click="loadPeople(peoplePage)">Tentar novamente</button></p>
          <div v-else class="recipient-results" :aria-busy="peopleLoading"><p v-if="peopleLoading" role="status">Buscando usuários…</p><p v-else-if="!people.length">Nenhum usuário encontrado.</p><template v-else><label v-for="person in people" :key="person.id" class="recipient-option"><input type="checkbox" :checked="selected.some(item => item.id === person.id)" :disabled="saving || (selected.length >= 100 && !selected.some(item => item.id === person.id))" @change="toggle(person)" /><span><strong>{{ person.name }}</strong><small>{{ person.email }}</small></span></label></template></div>
          <div class="share-pagination"><button type="button" class="button ghost" :disabled="peopleLoading || peoplePage <= 1" @click="loadPeople(peoplePage - 1)">Anterior</button><span>Página {{ peoplePage }} de {{ peopleLast }}</span><button type="button" class="button ghost" :disabled="peopleLoading || peoplePage >= peopleLast" @click="loadPeople(peoplePage + 1)">Próxima</button></div>
          <p v-for="(messages, key) in errors" v-show="String(key).startsWith('recipient_ids')" :key="key" class="field-error" role="alert">{{ messages[0] }}</p>
          <div class="metadata-form share-dates"><div class="form-field"><label for="share-start">Disponível a partir de</label><input id="share-start" v-model="starts" type="datetime-local" :aria-invalid="Boolean(errors.starts_at)" /><p class="field-help">Deixe vazio para liberar imediatamente.</p><p v-if="errors.starts_at" class="field-error">{{ errors.starts_at[0] }}</p></div><div class="form-field"><label for="share-expiry">Disponível até *</label><input id="share-expiry" v-model="expires" type="datetime-local" required :aria-invalid="Boolean(errors.expires_at)" /><p v-if="errors.expires_at" class="field-error">{{ errors.expires_at[0] }}</p></div></div>
          <p class="field-help">Horários no fuso do seu dispositivo: {{ timezone }}.</p>
          <div class="share-submit"><button class="button primary" :disabled="saving || revoking !== null"><LoaderCircle v-if="saving" class="spin" :size="17" /><Share2 v-else :size="17" />{{ saving ? 'Criando…' : 'Gerar compartilhamento' }}</button></div>
        </fieldset>
      </form>
      <p v-else class="notice share-notice">{{ record?.archived_at ? 'Documento arquivado: novos compartilhamentos estão bloqueados. Os existentes podem ser consultados e revogados conforme suas permissões.' : 'Sua conta pode consultar os compartilhamentos. A criação exige permissão de compartilhar e baixar o documento.' }}</p>
      <section class="panel editor-panel" aria-labelledby="share-history-title"><div class="panel-heading"><div><h2 id="share-history-title">Compartilhamentos desta versão</h2><p>{{ auth.can('shares.revoke-any') ? 'Todos os compartilhamentos desta versão.' : 'Compartilhamentos criados por você.' }}</p></div><button class="icon-button" aria-label="Atualizar compartilhamentos" :disabled="listLoading || saving || revoking !== null" @click="loadShares(shares?.meta.current_page || 1)"><RefreshCw :size="18" :class="{spin:listLoading}" /></button></div>
        <p v-if="listLoading" role="status">Carregando compartilhamentos…</p><p v-else-if="listError" class="notice error" role="alert">{{ listError }}<button class="button ghost" @click="loadShares()">Tentar novamente</button></p><p v-else-if="!shares?.data.length" class="share-empty">Nenhum compartilhamento registrado nesta versão.</p>
        <template v-else><article v-for="share in shares.data" :key="share.id" class="share-record"><div class="share-record-heading"><strong>Compartilhamento #{{ share.id }}</strong><span class="share-state" :class="state(share)">{{ shareLabels[state(share)] }}</span></div><p class="share-period"><Clock :size="14" />{{ timestamp(share.starts_at) }} até {{ timestamp(share.expires_at) }}</p><p class="share-recipients"><Users :size="14" />{{ share.recipients?.map(person => person.name).join(', ') || 'Sem destinatários disponíveis' }}</p><button v-if="canRevoke(share)" class="button secondary revoke-button" :disabled="revoking !== null || saving" @click="revoke(share)">{{ revoking === share.id ? 'Revogando…' : 'Revogar acesso' }}</button></article><div class="share-pagination"><button class="button ghost" :disabled="shares.meta.current_page <= 1 || saving || revoking !== null" @click="loadShares(shares.meta.current_page - 1)">Anterior</button><span>{{ shares.meta.current_page }} de {{ shares.meta.last_page }}</span><button class="button ghost" :disabled="shares.meta.current_page >= shares.meta.last_page || saving || revoking !== null" @click="loadShares(shares.meta.current_page + 1)">Próxima</button></div></template>
      </section>
    </div>
    <aside class="editor-aside panel"><span class="access-icon"><ShieldCheck :size="24" /></span><span class="eyebrow">COMPARTILHE COM CONTEXTO</span><h2>Acesso pontual, histórico preservado.</h2><p>O link libera somente este arquivo. As outras versões e os documentos da unidade continuam com suas permissões originais.</p><span class="small-label">DOCUMENTO</span><strong class="editor-document-title">{{ record?.title }}</strong><span class="small-label">VERSÃO COMPARTILHADA</span><div v-if="version" class="editor-current-file"><Share2 :size="18" /><span>{{ version.original_filename }}<small>Versão {{ version.version_number }} · {{ bytes(version.size_bytes) }}</small></span></div><p class="editor-aside-foot">O acesso termina no prazo escolhido ou com a revogação. Nenhuma mensagem é enviada automaticamente aos destinatários.</p></aside>
  </div>
</template>
