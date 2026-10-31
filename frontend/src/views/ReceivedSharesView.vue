<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import { Download, Share2, ShieldCheck, RefreshCw, ArrowLeft, LoaderCircle } from '@lucide/vue'
import { api, errorMessage } from '../lib/http'
import { bytes, dateOnly } from '../lib/format'
import { filename } from '../lib/documents'
import { shareLabels, shareState, timestamp } from '../lib/shares'
import type { DocumentShare, SharePage } from '../types'

const route = useRoute(), router = useRouter()
const list = ref<SharePage | null>(null), share = ref<DocumentShare | null>(null), loading = ref(true), error = ref(''), downloading = ref(false), downloadError = ref('')
const now = ref(Date.now()), interval = window.setInterval(() => now.value = Date.now(), 15000)
let controller: AbortController | undefined
const state = (item: DocumentShare) => shareState(item, now.value)
function accessPath() { return route.name === 'shared-token' ? `/api/v1/shared/${route.params.token}` : `/api/v1/shares/${route.params.share}` }
function accessError(cause: unknown) {
  if (axios.isAxiosError(cause)) {
    if (cause.response?.status === 410) return 'Este compartilhamento expirou ou foi revogado.'
    if (cause.response?.status === 403) return 'Acesso indisponível. Verifique se você é um dos destinatários, se a validade já começou e se o responsável mantém permissão sobre o documento.'
    if (cause.response?.status === 404) return 'Compartilhamento não encontrado.'
  }
  return errorMessage(cause)
}
async function load() {
  controller?.abort(); const request = new AbortController(); controller = request; loading.value = true; error.value = ''; downloadError.value = ''; share.value = null
  try {
    if (route.name === 'received-shares') {
      const page = Math.max(1, Number(route.query.page) || 1)
      const response = await api.get<SharePage>('/api/v1/shares/received', { params: { page }, signal: request.signal }); if (!request.signal.aborted) list.value = response.data
    } else { const response = await api.get<{ data: DocumentShare }>(accessPath(), { signal: request.signal }); if (!request.signal.aborted) share.value = response.data.data }
  } catch (cause) { if (!axios.isCancel(cause)) error.value = accessError(cause) }
  finally { if (!request.signal.aborted) loading.value = false }
}
async function download() {
  if (downloading.value || !share.value || state(share.value) !== 'active') return
  downloading.value = true; downloadError.value = ''
  try {
    const response = await api.get<Blob>(`${accessPath()}/download`, { responseType: 'blob', timeout: 0 })
    const url = URL.createObjectURL(response.data), link = document.createElement('a'); link.href = url; link.download = filename(response.headers['content-disposition'], share.value.version?.original_filename || 'documento'); document.body.appendChild(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (cause) { downloadError.value = accessError(cause); if (axios.isAxiosError(cause) && [403, 404, 410].includes(cause.response?.status || 0)) await load() }
  finally { downloading.value = false }
}
watch(() => route.fullPath, load, { immediate: true })
onBeforeUnmount(() => { controller?.abort(); clearInterval(interval) })
</script>

<template>
  <RouterLink v-if="route.name !== 'received-shares'" class="editor-back" :to="{name:'received-shares'}"><ArrowLeft :size="16" />Compartilhados comigo</RouterLink>
  <section class="page-heading"><div><span class="eyebrow">INFORMAÇÃO QUE CONECTA</span><h1>{{ route.name === 'received-shares' ? 'Compartilhados comigo' : 'Documento compartilhado' }}</h1><p>{{ route.name === 'received-shares' ? 'Consulte os arquivos que foram disponibilizados para você.' : 'Um acesso temporário a uma versão específica.' }}</p></div><button class="button secondary" :disabled="loading || downloading" @click="load"><RefreshCw :size="16" :class="{spin:loading}" />Atualizar</button></section>
  <div v-if="loading" class="panel editor-loading" role="status"><LoaderCircle :size="24" class="spin" />Consultando compartilhamentos…</div>
  <section v-else-if="error" class="panel error-state" role="alert"><ShieldCheck :size="30" /><h2>Acesso indisponível</h2><p>{{ error }}</p><button class="button secondary" @click="load">Tentar novamente</button></section>
  <section v-else-if="route.name === 'received-shares'" class="panel editor-panel"><div class="panel-heading"><div><h2>Seus compartilhamentos</h2><p>Ativos, agendados e encerrados.</p></div><Share2 :size="21" /></div><p v-if="!list?.data.length" class="share-empty">Nenhum documento foi compartilhado com você.</p><template v-else><article v-for="item in list.data" :key="item.id" class="share-record"><div class="share-record-heading"><strong>{{ state(item) === 'active' ? item.document?.title : `Compartilhamento #${item.id}` }}</strong><span class="share-state" :class="state(item)">{{ shareLabels[state(item)] }}</span></div><p v-if="state(item) === 'active' && item.version" class="share-period">{{ item.version.original_filename }} · Versão {{ item.version.version_number }}</p><p class="share-period">{{ timestamp(item.starts_at) }} até {{ timestamp(item.expires_at) }}</p><RouterLink v-if="state(item) === 'active'" class="button secondary" :to="{ name: 'shared-access', params: { share: item.id } }">Abrir documento</RouterLink><p v-else class="field-help">{{ state(item) === 'scheduled' ? 'O arquivo ficará disponível a partir do início da validade. Atualize a lista nesse horário.' : 'O acesso concedido por este compartilhamento está encerrado ou indisponível.' }}</p></article><div class="share-pagination"><button class="button ghost" :disabled="list.meta.current_page <= 1" @click="router.push({query:{page:list.meta.current_page - 1}})">Anterior</button><span>{{ list.meta.current_page }} de {{ list.meta.last_page }}</span><button class="button ghost" :disabled="list.meta.current_page >= list.meta.last_page" @click="router.push({query:{page:list.meta.current_page + 1}})">Próxima</button></div></template></section>
  <section v-else-if="share && state(share) === 'active' && share.document && share.version" class="panel editor-panel shared-document"><span class="eyebrow">VERSÃO {{ share.version.version_number }} · ACESSO TEMPORÁRIO</span><h2>{{ share.document.title }}</h2><p class="dialog-description">{{ share.document.description || 'Nenhuma descrição informada.' }}</p><dl class="document-metadata"><div><dt>Tipo documental</dt><dd>{{ share.document.type.name }}</dd></div><div><dt>Unidade produtora</dt><dd>{{ share.document.producing_unit?.name || 'Acesso geral' }}</dd></div><div><dt>Data de produção</dt><dd>{{ dateOnly(share.document.production_date) }}</dd></div><div><dt>Disponível até</dt><dd>{{ timestamp(share.expires_at) }}</dd></div><div><dt>Autor</dt><dd>{{ share.document.author || 'Não informado' }}</dd></div><div><dt>Interessado</dt><dd>{{ share.document.interested_party || 'Não informado' }}</dd></div></dl><div class="detail-file"><Share2 :size="22" /><div><strong>{{ share.version.original_filename }}</strong><small>{{ bytes(share.version.size_bytes) }} · Versão {{ share.version.version_number }}</small></div></div><div class="hash-block"><span><ShieldCheck :size="15" />SHA-256 registrado</span><code>{{ share.version.sha256 }}</code></div><p v-if="downloadError" class="notice error" role="alert">{{ downloadError }}</p><div class="detail-actions"><button class="button primary" :disabled="downloading" @click="download"><Download :size="17" />{{ downloading ? 'Baixando…' : 'Baixar esta versão' }}</button></div><p class="list-footnote">O acesso é revalidado pelo servidor a cada consulta e download.</p></section>
  <section v-else class="panel error-state"><ShieldCheck :size="30" /><h2>Compartilhamento encerrado ou indisponível</h2><p>Atualize para consultar a situação atual ou solicite um novo compartilhamento ao responsável.</p></section>
</template>
