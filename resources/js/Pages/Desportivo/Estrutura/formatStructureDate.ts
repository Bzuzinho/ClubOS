/** Format calendar dates without shifting their day through the browser timezone. */
export function formatStructureDate(value?: string | null): string {
  if (!value) return '—';
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:$|T| )/.exec(value);
  if (!match) return '—';
  const [, year, month, day] = match;
  const date = new Date(Date.UTC(Number(year), Number(month) - 1, Number(day)));
  if (date.getUTCFullYear() !== Number(year)
    || date.getUTCMonth() !== Number(month) - 1
    || date.getUTCDate() !== Number(day)) return '—';
  return new Intl.DateTimeFormat('pt-PT', { timeZone: 'UTC' }).format(date);
}
