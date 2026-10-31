import { describe, expect, it } from "vitest";
import {
  documentQuery,
  emptyFilters,
  filename,
  readFilters,
} from "./documents";
import { bytes, dateOnly } from "./format";

describe("contrato dos filtros e arquivos", () => {
  it("preserva período e situação ao trocar a página e omite filtros vazios", () => {
    const filters = readFilters({
      search: "Contrato",
      archived: "1",
      production_date_from: "2026-10-01",
      production_date_to: "2026-10-31",
    });
    expect(documentQuery(filters, 2)).toEqual({
      search: "Contrato",
      archived: "1",
      production_date_from: "2026-10-01",
      production_date_to: "2026-10-31",
      page: "2",
    });
    expect(documentQuery(emptyFilters())).toEqual({});
  });
  it("não converte datas de produção por fuso horário", () => {
    expect(dateOnly("2026-10-31")).toBe("31/10/2026");
  });
  it("exibe zero e tamanhos binários sem valores inválidos", () => {
    expect(bytes(0)).toBe("0 B");
    expect(bytes(1024)).toBe("1 KB");
  });
  it("respeita nomes UTF-8 enviados no download", () => {
    expect(
      filename("attachment; filename*=UTF-8''Relat%C3%B3rio.pdf", "file.pdf"),
    ).toBe("Relatório.pdf");
    expect(filename(undefined, "original.pdf")).toBe("original.pdf");
  });
});
