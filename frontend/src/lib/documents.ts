import type { LocationQuery } from "vue-router";
import type { DocumentFilters } from "../types";

export function emptyFilters(): DocumentFilters {
  return {
    search: "",
    document_type_id: "",
    producing_unit_id: "",
    archived: "0",
    production_date_from: "",
    production_date_to: "",
  };
}
export function readFilters(query: LocationQuery): DocumentFilters {
  const filters = emptyFilters();
  for (const key of Object.keys(filters) as (keyof DocumentFilters)[]) {
    if (typeof query[key] === "string") filters[key] = query[key] as string;
  }
  filters.archived = filters.archived === "1" ? "1" : "0";
  return filters;
}
export function documentQuery(
  filters: DocumentFilters,
  page = 1,
): Record<string, string> {
  const query: Record<string, string> = {};
  for (const [key, value] of Object.entries(filters)) {
    if (value.trim() && !(key === "archived" && value === "0"))
      query[key] = value.trim();
  }
  if (page > 1) query.page = String(page);
  return query;
}
export function filename(header: string | undefined, fallback: string): string {
  const encoded = header?.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
  if (encoded) {
    try {
      return decodeURIComponent(encoded);
    } catch {
      /* Use the original filename. */
    }
  }
  return header?.match(/filename="([^"]+)"/i)?.[1] || fallback;
}
