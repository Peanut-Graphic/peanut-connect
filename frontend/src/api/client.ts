import axios, { AxiosInstance, AxiosError } from 'axios';
import type { ApiResponse } from '@/types';

// WordPress passes these via wp_localize_script
declare global {
  interface Window {
    peanutConnect?: {
      apiUrl: string;
      nonce: string;
      version: string;
    };
  }
}

// Get config from WordPress or use defaults for development
const getConfig = () => {
  if (window.peanutConnect) {
    return {
      baseURL: window.peanutConnect.apiUrl,
      nonce: window.peanutConnect.nonce,
    };
  }

  // Development fallback
  return {
    baseURL: '/wp-json/peanut-connect/v1',
    nonce: '',
  };
};

const config = getConfig();

// Create axios instance
const api: AxiosInstance = axios.create({
  baseURL: config.baseURL,
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': config.nonce,
  },
});

/**
 * Normalize boolean query params to '1' / '0' before they hit the wire.
 *
 * Axios serializes a JS boolean to the strings "true"/"false", which Laravel's
 * `boolean` VALIDATION rule rejects (it only accepts 1/0/true/false as native,
 * not the strings "true"/"false") — the exact shape that 422'd the Dominion
 * funnel on `include_test`. '1'/'0' is the universally-safe form: it passes both
 * the strict `boolean` rule AND `$request->boolean()`. Doing it here kills the
 * whole class (archived, active, valid_only, include_test, any future flag) at
 * one point instead of per-call. Exported for unit testing.
 */
export function normalizeBooleanParams(
  params: Record<string, unknown> | undefined,
): Record<string, unknown> | undefined {
  if (!params || typeof params !== 'object') return params;
  const out: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(params)) {
    out[k] = typeof v === 'boolean' ? (v ? '1' : '0') : v;
  }
  return out;
}

api.interceptors.request.use((cfg) => {
  if (cfg.params) {
    cfg.params = normalizeBooleanParams(cfg.params as Record<string, unknown>);
  }
  return cfg;
});

/**
 * Flatten a Hub success envelope into a single object for callers.
 *
 * Hub's API is not uniform: list/setup endpoints nest the payload under
 * `data` ({ success, data: {...} }), while mutation endpoints return the
 * resource at the top level ({ success, campaign|utm|link: {...} }). The
 * old interceptor only forwarded `data.data`, silently dropping top-level
 * resource keys — so the campaign builder and every UTM/link mutation
 * came back empty. Spread the nested `data` (back-compat for lists) AND
 * the remaining top-level keys (mutation payloads), then re-attach
 * message/success.
 */
export const flattenApiResponse = (
  data: Record<string, unknown>
): Record<string, unknown> => {
  const { success, message, data: inner, ...rest } = data;
  const isPlainObject =
    inner != null && typeof inner === 'object' && !Array.isArray(inner);
  return {
    ...(isPlainObject ? (inner as Record<string, unknown>) : {}),
    ...rest,
    // A non-object `data` (array / primitive) can't be spread without
    // mangling it, so keep it addressable under `data`.
    ...(inner !== undefined && !isPlainObject ? { data: inner } : {}),
    message,
    success,
  };
};

// Response interceptor
api.interceptors.response.use(
  (response) => {
    // Handle API response format
    const data = response.data;
    if (data && typeof data === 'object' && 'success' in data) {
      if (!data.success) {
        return Promise.reject(new Error(data.message || 'Request failed'));
      }
      return {
        ...response,
        data: flattenApiResponse(data),
      };
    }
    return response;
  },
  (error: AxiosError<ApiResponse<unknown>>) => {
    const message = error.response?.data?.message || error.message || 'An error occurred';
    return Promise.reject(new Error(message));
  }
);

export default api;

// Helper to check if we're in WordPress admin
export const isWordPressAdmin = (): boolean => {
  return typeof window.peanutConnect !== 'undefined';
};

// Helper to get plugin version
export const getVersion = (): string => {
  return window.peanutConnect?.version || '1.0.0';
};
