export const allowedExtensions = ['pdf', 'docx', 'xlsx', 'csv', 'mp3', 'mp4', 'eml', 'msg']
export const maxFileSize = 100 * 1024 * 1024
export function fileError(file: File): string {
  const extension = file.name.split('.').pop()?.toLowerCase() || ''
  if (!allowedExtensions.includes(extension)) return 'Escolha um arquivo PDF, DOCX, XLSX, CSV, MP3, MP4, EML ou MSG.'
  if (file.size > maxFileSize) return 'O arquivo deve ter no máximo 100 MB.'
  return ''
}
