import { describe, it, expect, vi } from 'vitest';
import api, { isWordPressAdmin, getVersion, flattenApiResponse, extractApiErrorMessage } from './client';

describe('flattenApiResponse', () => {
  it('preserves top-level resource keys from mutation responses (campaign/utm/link)', () => {
    // Hub returns mutation payloads at the top level, not nested under `data`.
    const result = flattenApiResponse({
      success: true,
      campaign: { name: 'DOME2620RS1_learn', short_url: '/DOME2620WS1_learn' },
    });
    expect(result.campaign).toEqual({
      name: 'DOME2620RS1_learn',
      short_url: '/DOME2620WS1_learn',
    });
    expect(result.success).toBe(true);
  });

  it('still spreads nested `data` for list/setup responses (back-compat)', () => {
    const result = flattenApiResponse({
      success: true,
      data: { data: [{ id: 1 }], current_page: 1, total: 1 },
    });
    expect(result.data).toEqual([{ id: 1 }]);
    expect(result.current_page).toBe(1);
    expect(result.total).toBe(1);
  });

  it('keeps a non-object `data` value addressable under data', () => {
    const result = flattenApiResponse({
      success: true,
      data: [{ id: 1 }, { id: 2 }],
    });
    expect(result.data).toEqual([{ id: 1 }, { id: 2 }]);
  });

  it('carries the message through alongside the payload', () => {
    const result = flattenApiResponse({
      success: true,
      message: 'Campaign created',
      utm: { id: 7 },
    });
    expect(result.utm).toEqual({ id: 7 });
    expect(result.message).toBe('Campaign created');
  });
});

describe('extractApiErrorMessage', () => {
  it('surfaces Hub validation errors, which carry `errors` but no `message`', () => {
    // Hub's CampaignController 422 shape for a slug collision.
    const message = extractApiErrorMessage(
      { success: false, errors: { custom_slug: ['The custom slug has already been taken.'] } },
      'Request failed with status code 422'
    );
    expect(message).toBe('The custom slug has already been taken.');
  });

  it('joins messages across multiple invalid fields', () => {
    const message = extractApiErrorMessage(
      {
        success: false,
        errors: {
          utm_source: ['The utm source field is required.'],
          base_url: ['The base url must be a valid URL.'],
        },
      },
      'fallback'
    );
    expect(message).toBe('The utm source field is required. The base url must be a valid URL.');
  });

  it('prefers an explicit message over field errors', () => {
    expect(
      extractApiErrorMessage({ message: 'Site is not associated with an agency.', errors: { x: ['y'] } }, 'fallback')
    ).toBe('Site is not associated with an agency.');
  });

  it('falls back when the body has neither message nor errors', () => {
    expect(extractApiErrorMessage({ raw: '<html>' }, 'Request failed with status code 502')).toBe(
      'Request failed with status code 502'
    );
    expect(extractApiErrorMessage(undefined, 'fallback')).toBe('fallback');
  });
});

describe('API Client', () => {
  describe('isWordPressAdmin', () => {
    it('returns true when peanutConnect is defined', () => {
      expect(isWordPressAdmin()).toBe(true);
    });

    it('returns false when peanutConnect is undefined', () => {
      const original = window.peanutConnect;
      // @ts-expect-error - Temporarily unset for testing
      window.peanutConnect = undefined;

      expect(isWordPressAdmin()).toBe(false);

      // Restore
      window.peanutConnect = original;
    });
  });

  describe('getVersion', () => {
    it('returns version from peanutConnect', () => {
      expect(getVersion()).toBe('2.1.3');
    });

    it('returns default version when peanutConnect is undefined', () => {
      const original = window.peanutConnect;
      // @ts-expect-error - Temporarily unset for testing
      window.peanutConnect = undefined;

      expect(getVersion()).toBe('1.0.0');

      // Restore
      window.peanutConnect = original;
    });
  });

  describe('axios instance', () => {
    it('has correct baseURL configured', () => {
      expect(api.defaults.baseURL).toBe('http://localhost/wp-json/peanut-connect/v1');
    });

    it('has X-WP-Nonce header configured', () => {
      expect(api.defaults.headers['X-WP-Nonce']).toBe('test-nonce-12345');
    });

    it('has Content-Type header configured', () => {
      expect(api.defaults.headers['Content-Type']).toBe('application/json');
    });
  });
});
