import type { DocumentShare } from '../types'

export function shareState(share: DocumentShare, now = Date.now()): DocumentShare['state'] {
  if (share.revoked_at || share.state === 'revoked') return 'revoked'
  if (now >= Date.parse(share.expires_at)) return 'expired'
  if (now < Date.parse(share.starts_at)) return 'scheduled'
  return share.state
}
export const shareLabels = { active: 'Ativo', scheduled: 'Agendado', expired: 'Expirado', revoked: 'Revogado', unavailable: 'Indisponível' }
export function timestamp(value: string): string {
  return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
}
export function isoTimestamp(value: string): string { return new Date(value).toISOString().replace(/\.\d{3}Z$/, 'Z') }
