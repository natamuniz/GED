<script setup lang="ts">
import { ref } from 'vue'
import { UploadCloud, FileText, X } from '@lucide/vue'
import { bytes } from '../lib/format'
import { allowedExtensions, fileError } from '../lib/uploads'

const props = defineProps<{ modelValue: File | null; disabled?: boolean; error?: string }>()
const emit = defineEmits<{ 'update:modelValue': [file: File | null] }>()
const input = ref<HTMLInputElement | null>(null), localError = ref(''), dragging = ref(false)
function choose(files: FileList | null) {
  if (props.disabled || !files?.length) return
  if (files.length > 1) { localError.value = 'Selecione um único arquivo por versão.'; return }
  const file = files[0]!
  localError.value = fileError(file)
  if (!localError.value) emit('update:modelValue', file)
  if (input.value) input.value.value = ''
}
function drop(event: DragEvent) { dragging.value = false; choose(event.dataTransfer?.files || null) }
function remove() { if (!props.disabled) { localError.value = ''; emit('update:modelValue', null) } }
</script>
<template>
  <div class="upload-field">
    <input ref="input" class="sr-only" id="document-file" type="file" :accept="allowedExtensions.map(value => '.' + value).join(',')" :disabled="disabled" tabindex="-1" @change="choose(($event.target as HTMLInputElement).files)" />
    <button v-if="!modelValue" type="button" class="upload-dropzone" :class="{ dragging }" :disabled="disabled" :aria-invalid="Boolean(localError || error)" aria-describedby="upload-help upload-error" @click="input?.click()" @dragover.prevent="dragging = !disabled" @dragleave="dragging = false" @drop.prevent="drop">
      <span class="upload-symbol"><UploadCloud :size="25" /></span><strong>Selecione um arquivo</strong><span>ou arraste e solte aqui</span><small id="upload-help">PDF, Office, CSV, áudio, vídeo ou e-mail · Até 100 MB</small>
    </button>
    <div v-else class="upload-selected" tabindex="-1" :aria-invalid="Boolean(localError || error)" :aria-describedby="localError || error ? 'upload-error' : undefined" @dragover.prevent @drop.prevent="drop"><span class="file-badge"><FileText :size="22" /></span><div><strong>{{ modelValue.name }}</strong><small>{{ bytes(modelValue.size) }} · Pronto para enviar</small></div><button type="button" class="icon-button" :disabled="disabled" aria-label="Remover arquivo selecionado" @click="remove"><X :size="18" /></button></div>
    <p v-if="localError || error" id="upload-error" class="field-error" role="alert">{{ localError || error }}</p>
  </div>
</template>
