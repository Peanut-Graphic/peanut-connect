import { describe, it, expect } from 'vitest';
import { normalizeBooleanParams } from './client';

describe('normalizeBooleanParams', () => {
  it("converts booleans to '1'/'0' (the Laravel-safe form) and leaves others alone", () => {
    expect(
      normalizeBooleanParams({
        include_test: false,
        archived: true,
        valid_only: false,
        campaign: 'DOME',
        page: 2,
        empty: undefined,
      }),
    ).toEqual({
      include_test: '0',
      archived: '1',
      valid_only: '0',
      campaign: 'DOME',
      page: 2,
      empty: undefined,
    });
  });

  it('passes undefined/null params through untouched', () => {
    expect(normalizeBooleanParams(undefined)).toBeUndefined();
  });
});
