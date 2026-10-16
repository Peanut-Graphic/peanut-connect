import { readFileSync } from 'node:fs';
import { beforeEach, expect, it, vi } from 'vitest';
const source = readFileSync('../assets/js/forms.js', 'utf8');
const fields = [{ id: 'email', name: 'email', type: 'email', label: 'Email', required: true }];
function mount(definitions = fields, extra = {}) {
    const container = document.createElement('div');
    container.className = 'peanut-form-container';
    container.dataset.formSlug = 'contact';
    const schema = document.createElement('script');
    schema.className = 'peanut-form-schema';
    schema.type = 'application/json';
    schema.textContent = JSON.stringify({ fields: definitions, button: 'Send', ...extra });
    container.append(schema);
    document.body.append(container);
    new Function(source)();
    if (document.readyState === 'loading') document.dispatchEvent(new Event('DOMContentLoaded'));
    return container;
}
function submit(container: HTMLElement) {
    container.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
}
beforeEach(() => {
    document.body.replaceChildren();
    vi.restoreAllMocks();
    (window as any).PeanutFormsConfig = {
        submitUrl: '/submit',
        nonceUrl: '/fresh-token',
        i18n: { submitting: 'Submitting...', error: 'Try again', required: 'Required' },
    };
});
it('refreshes tokens, sends no page identity, and gives cached HTML a new browser session', async () => {
    const fetcher = vi
        .fn()
        .mockImplementation(async (url: string) => ({
            ok: true,
            json: async () =>
                url === '/fresh-token'
                    ? { success: true, data: { nonce: 'fresh' } }
                    : { success: true, message: 'Received' },
        }));
    vi.stubGlobal('fetch', fetcher);
    const sessions = [];
    for (let i = 0; i < 2; i++) {
        document.body.replaceChildren();
        const container = mount();
        (container.querySelector('[name=email]') as HTMLInputElement).value = 'visitor@example.test';
        submit(container);
        await vi.waitFor(() => expect(container.querySelector('[role=status]')!.textContent).toBe('Received'));
        const request = fetcher.mock.calls[i * 2 + 1][1];
        expect(request.headers['X-WP-Nonce']).toBe('fresh');
        const payload = JSON.parse(request.body);
        expect(payload).not.toHaveProperty('visitor_id');
        expect(payload.data.email).toBe('visitor@example.test');
        sessions.push(payload.session_id);
    }
    expect(sessions[0]).not.toBe(sessions[1]);
    expect(fetcher.mock.calls[0][1]).toMatchObject({ cache: 'no-store', credentials: 'same-origin' });
});
it('shows Hub errors and permits retry', async () => {
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValueOnce({ ok: true, json: async () => ({ success: true, data: { nonce: 'fresh' } }) })
            .mockResolvedValueOnce({
                ok: false,
                json: async () => ({ success: false, message: 'Hub validation error' }),
            }),
    );
    const container = mount();
    (container.querySelector('[name=email]') as HTMLInputElement).value = 'visitor@example.test';
    submit(container);
    await vi.waitFor(() => expect(container.querySelector('[role=status]')!.textContent).toBe('Hub validation error'));
    expect((container.querySelector('button') as HTMLButtonElement).disabled).toBe(false);
});
it('keeps labels inert and refuses unsupported or conditional fields', () => {
    const container = mount([{ ...fields[0], label: '<img src=x onerror=alert(1)>' }]);
    expect(container.querySelector('img')).toBeNull();
    document.body.replaceChildren();
    expect(mount([{ ...fields[0], type: 'file' }]).querySelector('form')).toBeNull();
    document.body.replaceChildren();
    expect(mount([{ ...fields[0], conditionalLogic: {} }] as any).querySelector('form')).toBeNull();
});
it('validates steps and retains previous-step values in the final payload', async () => {
    const fetcher = vi
        .fn()
        .mockImplementation(async (url: string) => ({
            ok: true,
            json: async () =>
                url === '/fresh-token'
                    ? { success: true, data: { nonce: 'fresh' } }
                    : { success: true, message: 'Received' },
        }));
    vi.stubGlobal('fetch', fetcher);
    const container = mount([...fields, { id: 'name', type: 'text', name: 'name', label: 'Name', required: true }], {
        steps: [
            { title: 'Email step', fields: ['email'] },
            { title: 'Name step', fields: ['name'] },
        ],
    });
    const next = Array.from(container.querySelectorAll('button')).find((b) => b.textContent === 'Next')!;
    next.click();
    expect((container.querySelector('[name=name]') as HTMLInputElement).disabled).toBe(true);
    (container.querySelector('[name=email]') as HTMLInputElement).value = 'visitor@example.test';
    next.click();
    (container.querySelector('[name=name]') as HTMLInputElement).value = 'Visitor';
    submit(container);
    await vi.waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
    expect(JSON.parse(fetcher.mock.calls[1][1].body).data).toMatchObject({
        name: 'Visitor',
        email: 'visitor@example.test',
    });
});
