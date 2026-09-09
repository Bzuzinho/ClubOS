import { describe, expect, it } from 'vitest';
import { formatStructureDate } from './formatStructureDate';

describe('structure calendar dates', () => {
  it.each([
    ['2026-09-01', '01/09/2026'],
    ['2026-09-01T00:00:00.000000Z', '01/09/2026'],
    ['2026-09-01T00:00:00+01:00', '01/09/2026'],
    ['2026-09-01 00:00:00', '01/09/2026'],
    ['2024-02-29', '29/02/2024'],
    ['2026-02-30', '—'],
    ['invalid', '—'],
    [null, '—'],
    [undefined, '—'],
    ['', '—'],
  ])('formats %s without crashing the workspace', (value, expected) => {
    expect(formatStructureDate(value)).toBe(expected);
  });
});
