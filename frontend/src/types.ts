export interface Unit {
  id: number;
  name: string;
  code?: string;
  parent_id?: number | null;
}
export interface DocumentType {
  id: number;
  name: string;
}
export interface User {
  id: number;
  name: string;
  email: string;
  profile: { id: number; name: string } | null;
  units: Unit[];
}
export interface Session {
  user: User;
  permissions: string[];
}
export interface DocumentVersion {
  id: number;
  version_number: number;
  original_filename: string;
  mime_type: string;
  size_bytes: number;
  sha256: string;
  created_at: string;
  extension?: string;
}
export interface DocumentRecord {
  id: number;
  title: string;
  description: string | null;
  production_date: string;
  reference_number: string | null;
  author: string | null;
  interested_party: string | null;
  archived_at: string | null;
  type: DocumentType;
  producing_unit: Unit | null;
  latest_version?: DocumentVersion | null;
  versions?: DocumentVersion[];
}
export interface DocumentPage {
  data: DocumentRecord[];
  current_page: number;
  last_page: number;
  total: number;
  from: number | null;
  to: number | null;
  per_page: number;
}
export interface Dashboard {
  total_documents: number;
  active_documents: number;
  archived_documents: number;
  storage_bytes: number;
  by_type: { id: number; name: string; total: number }[];
}
export interface DocumentFilters {
  search: string;
  document_type_id: string;
  producing_unit_id: string;
  archived: string;
  production_date_from: string;
  production_date_to: string;
}
export type FieldErrors = Record<string, string[]>;
export interface ShareRecipient { id: number; name: string; email: string }
export interface DocumentShare {
  id: number;
  created_by: number;
  starts_at: string;
  expires_at: string;
  revoked_at: string | null;
  state: 'active' | 'scheduled' | 'expired' | 'revoked' | 'unavailable';
  recipients?: ShareRecipient[];
  document?: Pick<DocumentRecord, 'id' | 'title' | 'description' | 'production_date' | 'type' | 'producing_unit' | 'author' | 'interested_party' | 'reference_number'>;
  version?: DocumentVersion;
}
export interface SharePage { data: DocumentShare[]; meta: { current_page: number; last_page: number; total: number } }
