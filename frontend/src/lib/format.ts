export const number = (value: number) =>
  new Intl.NumberFormat("pt-BR").format(value);
export function bytes(value: number): string {
  if (!value) return "0 B";
  const units = ["B", "KB", "MB", "GB", "TB"];
  const exponent = Math.min(
    Math.floor(Math.log(value) / Math.log(1024)),
    units.length - 1,
  );
  return `${new Intl.NumberFormat("pt-BR", { maximumFractionDigits: exponent ? 1 : 0 }).format(value / 1024 ** exponent)} ${units[exponent]}`;
}
export function dateOnly(value?: string | null): string {
  if (!value) return "—";
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
  return match ? `${match[3]}/${match[2]}/${match[1]}` : "—";
}
