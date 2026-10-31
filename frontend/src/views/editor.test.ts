import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { reactive } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '../lib/http'
import { useAuth } from '../stores/auth'
import Editor from './DocumentEditorView.vue'
import FileUpload from '../components/FileUpload.vue'
import { fileError, maxFileSize } from '../lib/uploads'

const routing = vi.hoisted(() => ({ route: null as any, leave: null as any, update: null as any }))
vi.mock('vue-router', () => ({ useRoute: () => routing.route, onBeforeRouteLeave: (fn: any) => routing.leave = fn, onBeforeRouteUpdate: (fn: any) => routing.update = fn }))
let wrapper: VueWrapper | undefined
const record = { id: 7, title: 'Contrato', production_date: '2026-10-31', description: null, author: null, interested_party: null, reference_number: null, archived_at: null, type: { id: 1, name: 'Contrato' }, producing_unit: { id: 2, name: 'Financeiro' }, versions: [{ id: 9, version_number: 3, original_filename: 'contrato.pdf', size_bytes: 128, sha256: 'a'.repeat(64) }] }
beforeEach(() => {
  vi.restoreAllMocks(); setActivePinia(createPinia())
  const auth = useAuth(); auth.permissions = ['documents.view', 'documents.create', 'documents.update', 'documents.add-version']
  routing.route = reactive({ name: 'document-create', params: {}, fullPath: '/documentos/novo' })
  vi.spyOn(api, 'get').mockImplementation(async (url) => ({ data: url.includes('document-types') ? [{ id: 1, name: 'Contrato' }] : url.endsWith('/units') ? [{ id: 2, name: 'Financeiro' }] : url.includes('/documents/') ? { data: structuredClone(record) } : {} }) as any)
})
afterEach(() => wrapper?.unmount())
async function open(name = 'document-create') {
  routing.route.name = name; routing.route.params = { id: 7 }; routing.route.fullPath = '/' + name
  wrapper = mount(Editor, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } }); await flushPromises(); return wrapper
}
async function selectFile(w: VueWrapper, name = 'contrato.pdf') {
  w.getComponent(FileUpload).vm.$emit('update:modelValue', new File(['pdf'], name, { type: 'application/pdf' })); await flushPromises()
}
describe('cadastro e manutenção de documentos', () => {
  it('envia metadados e arquivo multipart para a unidade escolhida', async () => {
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: { title: 'Contrato', latest_version: { version_number: 1 } } } })
    const w = await open(); await w.get('#editor-title').setValue('Contrato'); await w.get('#editor-type').setValue('1'); await w.get('#editor-unit').setValue('2'); await w.get('#editor-date').setValue('2026-10-31'); await selectFile(w)
    await w.get('form').trigger('submit'); await flushPromises()
    const data = post.mock.calls[0]![1] as FormData
    expect(post.mock.calls[0]![0]).toBe('/api/v1/documents'); expect(data.get('producing_unit_id')).toBe('2'); expect((data.get('file') as File).name).toBe('contrato.pdf'); expect(w.text()).toContain('Documento cadastrado')
  })
  it('edita somente metadados, permitindo limpar campos opcionais', async () => {
    const patch = vi.spyOn(api, 'patch').mockResolvedValue({ data: { data: record } })
    const w = await open('document-edit'); expect(w.find('#editor-unit').exists()).toBe(false); expect(w.find('input[type=file]').exists()).toBe(false)
    await w.get('form').trigger('submit'); await flushPromises()
    expect(patch).toHaveBeenCalledWith('/api/v1/documents/7', expect.objectContaining({ author: null, title: 'Contrato' })); expect(patch.mock.calls[0]![1]).not.toHaveProperty('producing_unit_id')
  })
  it('envia nova versão sem reenviar metadados e usa a numeração do servidor', async () => {
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: { version_number: 5 } } })
    const w = await open('document-version'); await selectFile(w); await w.get('#change-note').setValue('Correção'); await w.get('form').trigger('submit'); await flushPromises()
    expect(post.mock.calls[0]![0]).toBe('/api/v1/documents/7/versions'); const data = post.mock.calls[0]![1] as FormData
    expect(data.get('change_note')).toBe('Correção'); expect(data.has('title')).toBe(false); expect(w.text()).toContain('Versão 5 adicionada')
  })
  it('exige arquivo e mantém o preenchimento quando o servidor rejeita o envio', async () => {
    const post = vi.spyOn(api, 'post').mockRejectedValue({ isAxiosError: true, response: { status: 422, data: { errors: { file: ['Conteúdo inválido.'] } } } })
    const w = await open(); await w.get('#editor-title').setValue('Contrato'); await w.get('form').trigger('submit'); expect(post).not.toHaveBeenCalled(); expect(w.text()).toContain('Selecione o arquivo')
    await selectFile(w); await w.get('form').trigger('submit'); await flushPromises()
    expect(w.text()).toContain('Conteúdo inválido.'); expect((w.get('#editor-title').element as HTMLInputElement).value).toBe('Contrato'); expect(w.text()).toContain('contrato.pdf')
  })
  it('bloqueia envio duplicado e navegação durante uma requisição em andamento', async () => {
    let resolve: any; const post = vi.spyOn(api, 'post').mockImplementation(() => new Promise(r => resolve = r))
    const w = await open('document-version'); await selectFile(w); await w.get('form').trigger('submit'); await flushPromises(); await w.get('form').trigger('submit')
    expect(post).toHaveBeenCalledTimes(1); expect(routing.leave({ name: 'documents' })).toBe(false)
    resolve({ data: { data: { version_number: 4 } } }); await flushPromises()
  })
  it('confirma saída com alterações e protege mudanças entre formulários', async () => {
    const confirm = vi.fn(() => false); window.confirm = confirm; const w = await open(); await w.get('#editor-title').setValue('Alterado')
    expect(routing.leave({ name: 'documents' })).toBe(false); expect(routing.update({ path: '/outro' }, { path: '/atual' })).toBe(false); expect(confirm).toHaveBeenCalledTimes(2)
  })
  it('bloqueia manutenção de documento arquivado e acesso geral sem permissão', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: { ...record, archived_at: '2026-10-31' } } })
    const w = await open('document-version'); expect(w.find('form').exists()).toBe(false); expect(w.text()).toContain('Documento arquivado')
    wrapper?.unmount(); vi.spyOn(api, 'get').mockResolvedValue({ data: { data: { ...record, producing_unit: null } } }); const next = await open('document-version'); expect(next.find('form').exists()).toBe(false)
  })
  it('oferece acesso geral somente com permissão explícita', async () => {
    const w = await open(); expect(w.find('option[value=general]').exists()).toBe(false); useAuth().permissions.push('documents.manage-general'); await flushPromises(); expect(w.find('option[value=general]').exists()).toBe(true)
  })
})
describe('validação do arquivo', () => {
  it('aceita extensões previstas sem diferenciar maiúsculas e rejeita tamanho excedido', () => {
    expect(fileError(new File(['x'], 'ARQUIVO.PDF'))).toBe(''); expect(fileError(new File(['x'], 'arquivo.exe'))).toContain('Escolha um arquivo')
    expect(fileError({ name: 'grande.mp4', size: maxFileSize + 1 } as File)).toContain('100 MB')
  })
  it('rejeita múltiplos arquivos e permite remover uma seleção', async () => {
    wrapper = mount(FileUpload, { props: { modelValue: null } }); await wrapper.get('.upload-dropzone').trigger('drop', { dataTransfer: { files: [new File(['x'], 'a.pdf'), new File(['x'], 'b.pdf')] } }); expect(wrapper.text()).toContain('único arquivo')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined(); await wrapper.setProps({ modelValue: new File(['x'], 'a.pdf') }); await wrapper.get('.icon-button').trigger('click'); expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([null])
  })
})
