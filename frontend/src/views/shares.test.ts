import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { reactive } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '../lib/http'
import { useAuth } from '../stores/auth'
import Manage from './ShareManagementView.vue'
import Received from './ReceivedSharesView.vue'
import { isoTimestamp, shareState } from '../lib/shares'
import type { DocumentShare } from '../types'

const routing = vi.hoisted(() => ({ route: null as any, push: vi.fn() }))
vi.mock('vue-router', () => ({ useRoute: () => routing.route, useRouter: () => ({push:routing.push}), onBeforeRouteLeave: vi.fn(), onBeforeRouteUpdate: vi.fn() }))
let wrapper: VueWrapper | undefined
const person = {id: 8, name:'João Almeida', email:'joao@example.test'}
const record = { id:7, title:'Contrato', archived_at:null, versions:[{id:12,version_number:2,original_filename:'contrato.pdf',size_bytes:120,sha256:'a'.repeat(64)}] }
const active: DocumentShare = {id:3,created_by:1,starts_at:'2098-01-01T12:00:00Z',expires_at:'2099-01-01T12:00:00Z',revoked_at:null,state:'active',recipients:[person]}
function share(overrides = {}) { return {...structuredClone(active), starts_at:'2020-01-01T12:00:00Z',...overrides} }
beforeEach(() => {
  vi.restoreAllMocks(); setActivePinia(createPinia()); window.confirm = vi.fn(() => true)
  useAuth().user = {id:1,name:'Gestor',email:'gestor@example.test',profile:null,units:[]}; useAuth().permissions = ['documents.view','documents.download','shares.create','shares.revoke-own']
  routing.route = reactive({name:'version-shares',params:{id:7,version:12},query:{},fullPath:'/documentos/7/versoes/12/compartilhamentos'})
  vi.spyOn(api,'get').mockImplementation(async (url) => ({data:url.includes('/documents/') ? {data:structuredClone(record)} : url.includes('share-recipients') ? {data:[person],current_page:1,last_page:2} : url.includes('/versions/') ? {data:[share()],meta:{current_page:1,last_page:1,total:1}} : {data:share()}}) as any)
})
afterEach(() => { wrapper?.unmount(); wrapper = undefined })
async function open(component = Manage) { wrapper = mount(component, {global:{stubs:{RouterLink:{template:'<a><slot /></a>'}}}}); await flushPromises(); return wrapper }
async function fill(w: VueWrapper) { await w.get('input[type=checkbox]').setValue(true); await w.get('#share-expiry').setValue('2099-01-01T12:00') }
describe('compartilhamento por versão', () => {
  it('envia destinatários e datas com fuso explícito e gera link para a interface', async () => {
    const post = vi.spyOn(api,'post').mockResolvedValue({data:{data:share(),token:'a'.repeat(64)}})
    const w = await open(); await fill(w); await w.get('#share-start').setValue('2098-01-01T12:00'); await w.get('form').trigger('submit'); await flushPromises()
    expect(post).toHaveBeenCalledWith('/api/v1/versions/12/shares',{recipient_ids:[8],starts_at:isoTimestamp('2098-01-01T12:00'),expires_at:isoTimestamp('2099-01-01T12:00')})
    expect((w.get('#share-link').element as HTMLInputElement).value).toContain('/compartilhado/'+'a'.repeat(64)); expect(w.text()).toContain('Compartilhamento criado')
  })
  it('mantém a seleção ao buscar e paginar os usuários', async () => {
    const w = await open(); await w.get('input[type=checkbox]').setValue(true); await w.get('#recipient-search').setValue('Ana'); await w.get('.recipient-search button').trigger('click'); await flushPromises(); await w.get('.share-form .share-pagination button:last-child').trigger('click'); await flushPromises()
    expect(api.get).toHaveBeenCalledWith('/api/v1/share-recipients',expect.objectContaining({params:{search:'Ana',page:2}})); expect(w.text()).toContain('1 de 100'); expect(w.get('.recipient-chip').text()).toContain('João Almeida')
  })
  it('rejeita ausência de destinatários e expiração anterior ao início sem enviar', async () => {
    const post = vi.spyOn(api,'post'); const w = await open(); await w.get('#share-start').setValue('2099-02-01T12:00'); await w.get('#share-expiry').setValue('2099-01-01T12:00'); await w.get('form').trigger('submit'); await flushPromises(); expect(post).not.toHaveBeenCalled(); expect(w.text()).toContain('Selecione pelo menos'); expect(w.text()).toContain('posterior ao início')
  })
  it('preserva dados com erro 422 e não repete automaticamente a criação', async () => {
    const post = vi.spyOn(api,'post').mockRejectedValue({isAxiosError:true,response:{status:422,data:{errors:{'recipient_ids.0':['Usuário desativado.']}}}})
    const w = await open(); await fill(w); await w.get('form').trigger('submit'); await flushPromises(); expect(w.text()).toContain('Usuário desativado.'); expect(w.text()).toContain('1 de 100'); expect(post).toHaveBeenCalledTimes(1)
  })
  it('revoga acesso próprio e atualiza a situação só após confirmação do servidor', async () => {
    const post = vi.spyOn(api,'post').mockResolvedValue({data:{data:{revoked_at:'2026-10-31T12:00:00Z'}}}); const w = await open(); await w.get('.revoke-button').trigger('click'); await flushPromises(); expect(post).toHaveBeenCalledWith('/api/v1/shares/3/revoke'); expect(w.text()).toContain('Revogado'); expect(w.find('.revoke-button').exists()).toBe(false)
  })
  it('não oferece revogação de outro criador sem revoke-any nem criação em arquivados', async () => {
    vi.spyOn(api,'get').mockImplementation(async url => ({data:url.includes('/documents/') ? {data:{...record,archived_at:'2026-10-31'}} : {data:[share({created_by:9})],meta:{current_page:1,last_page:1,total:1}}}) as any)
    const w = await open(); expect(w.find('form').exists()).toBe(false); expect(w.find('.revoke-button').exists()).toBe(false); useAuth().permissions.push('shares.revoke-any'); await flushPromises(); expect(w.find('.revoke-button').exists()).toBe(true)
  })
  it('bloqueia envios duplicados durante a criação', async () => {
    let finish: any; const post = vi.spyOn(api,'post').mockImplementation(() => new Promise(resolve => finish=resolve)); const w = await open(); await fill(w); await w.get('form').trigger('submit'); await flushPromises(); await w.get('form').trigger('submit'); expect(post).toHaveBeenCalledTimes(1); finish({data:{data:share(),token:'b'.repeat(64)}}); await flushPromises()
  })
})
describe('acesso recebido', () => {
  it('consulta link pelo token sem exigir permissão de consulta normal dos documentos', async () => {
    useAuth().permissions = []; routing.route.name='shared-token'; routing.route.params={token:'a'.repeat(64)}
    vi.spyOn(api,'get').mockResolvedValue({data:{data:share({document:{title:'Contrato restrito',production_date:'2026-10-31',type:{name:'Contrato'},producing_unit:{name:'Financeiro'}},version:record.versions[0]})}})
    const w = await open(Received as any); expect(api.get).toHaveBeenCalledWith('/api/v1/shared/'+'a'.repeat(64),expect.anything()); expect(w.text()).toContain('Contrato restrito'); expect(w.text()).toContain('Baixar esta versão')
  })
  it('mostra encerramento para link revogado sem exibir arquivo ou botão de download', async () => {
    routing.route.name='shared-token'; routing.route.params={token:'b'.repeat(64)}; vi.spyOn(api,'get').mockRejectedValue({isAxiosError:true,response:{status:410}})
    const w = await open(Received as any); expect(w.text()).toContain('expirou ou foi revogado'); expect(w.text()).not.toContain('Baixar esta versão')
  })
  it('lista compartilhamentos recebidos e preserva paginação na URL', async () => {
    routing.route.name='received-shares'; routing.route.query={page:'2'}; vi.spyOn(api,'get').mockResolvedValue({data:{data:[share({state:'revoked',revoked_at:'2026-10-31'})],meta:{current_page:2,last_page:2,total:21}}})
    const w = await open(Received as any); expect(api.get).toHaveBeenCalledWith('/api/v1/shares/received',expect.objectContaining({params:{page:2}})); expect(w.text()).toContain('Revogado'); expect(w.text()).not.toContain('Abrir documento')
  })
})
describe('estados e datas', () => {
  it('não mantém acesso visual após expiração e não presume liberação agendada', () => {
    expect(shareState(share(),Date.parse('2100-01-01'))).toBe('expired'); expect(shareState(share({revoked_at:'2026-10-31'}))).toBe('revoked'); expect(shareState(share({state:'scheduled'}))).toBe('scheduled')
    expect(isoTimestamp('2099-01-01T12:00')).toMatch(/T\d{2}:\d{2}:00Z$/)
  })
})
