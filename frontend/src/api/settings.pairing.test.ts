import { describe, it, expect, vi, beforeEach } from 'vitest';
import { settingsApi } from './endpoints';
import { isWellFormedPairingToken } from '@/utils/pairingToken';
import api from './client';

vi.mock('./client');

const TOKEN = 'hubpair_' + 'Ab1'.repeat(18) + 'xy';

describe('settingsApi.autoConnectToHub pairing token', () => {
  beforeEach(() => vi.resetAllMocks());

  it('sends pairing_token alongside hub_url when given', async () => {
    (api.post as any).mockResolvedValue({ data: { success: true, message: 'ok' } });
    await settingsApi.autoConnectToHub('https://hub.example.com', TOKEN);
    expect(api.post).toHaveBeenCalledWith('/settings/hub/connect', {
      hub_url: 'https://hub.example.com',
      pairing_token: TOKEN,
    });
  });

  it('trims the pasted token before sending', async () => {
    (api.post as any).mockResolvedValue({ data: { success: true, message: 'ok' } });
    await settingsApi.autoConnectToHub('https://hub.example.com', `  ${TOKEN}\n`);
    expect((api.post as any).mock.calls[0][1].pairing_token).toBe(TOKEN);
  });

  it('omits pairing_token when blank (older Hubs)', async () => {
    (api.post as any).mockResolvedValue({ data: { success: true, message: 'ok' } });
    await settingsApi.autoConnectToHub('https://hub.example.com', '   ');
    expect(api.post).toHaveBeenCalledWith('/settings/hub/connect', { hub_url: 'https://hub.example.com' });
  });
});

describe('isWellFormedPairingToken', () => {
  it('accepts hubpair_ plus 56 alphanumerics', () => {
    expect(TOKEN).toHaveLength(64);
    expect(isWellFormedPairingToken(TOKEN)).toBe(true);
    expect(isWellFormedPairingToken(`  ${TOKEN} `)).toBe(true);
  });

  it.each([
    ['wrong prefix', 'hubpaix_' + 'a'.repeat(56)],
    ['too short', 'hubpair_' + 'a'.repeat(55)],
    ['too long', 'hubpair_' + 'a'.repeat(57)],
    ['bad character', 'hubpair_' + 'a'.repeat(55) + '-'],
    ['an API key', 'a'.repeat(64)],
  ])('rejects %s', (_label, token) => {
    expect(isWellFormedPairingToken(token)).toBe(false);
  });
});
