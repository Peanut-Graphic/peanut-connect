/* Hub-synchronized forms. Identity is derived only by the WordPress proxy. */
(function () {
    'use strict';
    const config = window.PeanutFormsConfig || {};
    const element = (tag, text) => {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = String(text);
        return node;
    };
    function render(container) {
        const status = element('p');
        status.setAttribute('role', 'status');
        const unavailable = () => {
            container.replaceChildren(element('p', 'This form requires features unavailable in this embed. Please contact the site owner.'));
        };
        let schema;
        try { schema = JSON.parse(container.querySelector('.peanut-form-schema').textContent); }
        catch (_) { unavailable(); return; }
        const fields = schema.fields;
        const controls = ['text', 'email', 'phone', 'number', 'url', 'textarea', 'select', 'dropdown', 'checkbox', 'checkboxGroup', 'radio', 'date', 'time', 'datetime', 'hidden'];
        const displays = ['heading', 'section', 'paragraph', 'html', 'divider'];
        // File uploads, signature, conditional fields, enrollment and scheduler
        // need their dedicated Hub flows; never silently submit partial data.
        if (!Array.isArray(fields) || !fields.length || ['enrollment', 'scheduler'].includes(schema.type) || fields.some(f => !f || f.conditionalLogic || !controls.concat(displays).includes(f.type) || (controls.includes(f.type) && (typeof f.name !== 'string' || !f.name)))) {
            unavailable(); return;
        }
        const form = element('form');
        const loadTime = new Date().toISOString();
        const session = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
        const readers = [];
        const steps = Array.isArray(schema.steps) ? schema.steps : [];
        const panels = (steps.length ? steps : [{title: '', fields: []}]).map(step => {
            const panel = element('div');
            if (step.title) panel.append(element('h3', step.title));
            if (step.description) panel.append(element('p', step.description));
            form.append(panel);
            return panel;
        });
        const panelFor = field => {
            const index = steps.findIndex(step => Array.isArray(step.fields) && step.fields.includes(field.id));
            return panels[Math.max(index, 0)];
        };
        fields.forEach((field, index) => {
            if (displays.includes(field.type)) {
                panelFor(field).append(element(field.type === 'divider' ? 'hr' : ['heading', 'section'].includes(field.type) ? 'h3' : 'p', field.description || field.label || ''));
                return;
            }
            const wrap = element('div');
            wrap.className = 'peanut-form-field';
            const id = 'peanut-field-' + session + '-' + index;
            const label = element('label', field.label || field.name);
            label.htmlFor = id;
            let input;
            let read;
            if (['checkboxGroup', 'radio'].includes(field.type) || (field.type === 'checkbox' && field.options && field.options.length)) {
                const group = element('fieldset');
                group.append(element('legend', field.label || field.name));
                const choices = (field.options || []).map((option, n) => {
                    const choice = element('input');
                    choice.type = field.type === 'radio' ? 'radio' : 'checkbox';
                    choice.name = field.name;
                    choice.value = String(option.value);
                    choice.id = id + '-' + n;
                    if (field.type === 'radio') choice.required = !!field.required;
                    const choiceLabel = element('label', option.label);
                    choiceLabel.htmlFor = choice.id;
                    group.append(choice, choiceLabel);
                    return choice;
                });
                if (field.required && field.type !== 'radio') {
                    const validate = () => choices[0] && choices[0].setCustomValidity(choices.some(c => c.checked) ? '' : (config.i18n.required || 'This field is required'));
                    choices.forEach(c => c.addEventListener('change', validate));
                    validate();
                }
                wrap.append(group);
                read = () => field.type === 'radio' ? (choices.find(c => c.checked) || {}).value || '' : choices.filter(c => c.checked).map(c => c.value);
            } else {
                input = element(field.type === 'textarea' ? 'textarea' : ['select', 'dropdown'].includes(field.type) ? 'select' : 'input');
                if (input.tagName === 'INPUT') input.type = field.type === 'phone' ? 'tel' : field.type === 'datetime' ? 'datetime-local' : field.type;
                if (input.tagName === 'SELECT') {
                    const placeholder = element('option', field.placeholder || 'Select an option');
                    placeholder.value = '';
                    input.append(placeholder);
                    (field.options || []).forEach(o => { const option = element('option', o.label); option.value = String(o.value); input.append(option); });
                }
                input.id = id;
                input.name = field.name;
                input.required = !!field.required;
                input.placeholder = field.placeholder || '';
                const validation = Object.assign({}, field.validation || {}, field);
                ['min', 'max', 'minLength', 'maxLength', 'pattern'].forEach(key => { if (validation[key] !== undefined) input.setAttribute(key, validation[key]); });
                if (input.type === 'checkbox') input.checked = field.defaultValue === true;
                else if (field.defaultValue !== undefined) input.value = String(field.defaultValue);
                if (field.type !== 'hidden') wrap.append(label);
                wrap.append(input);
                read = () => input.type === 'checkbox' ? input.checked : input.value;
            }
            if (field.helpText) wrap.append(element('small', field.helpText));
            readers.push([field.name, read]);
            panelFor(field).append(wrap);
        });
        const honeypot = element('input');
        honeypot.name = '_hp_field';
        honeypot.type = 'text';
        honeypot.tabIndex = -1;
        honeypot.autocomplete = 'off';
        const trap = element('label', 'Leave this field empty');
        trap.className = 'peanut-form-honeypot';
        trap.append(honeypot);
        form.append(trap);
        const button = element('button', schema.button || 'Submit');
        button.type = 'submit';
        form.append(button, status);
        let currentStep = 0;
        const back = element('button', 'Back');
        const next = element('button', 'Next');
        back.type = next.type = 'button';
        function showStep(index) {
            currentStep = index;
            panels.forEach((panel, n) => {
                panel.hidden = n !== index;
                panel.querySelectorAll('input, select, textarea').forEach(control => { control.disabled = n !== index; });
            });
            back.hidden = index === 0;
            next.hidden = index === panels.length - 1;
            button.hidden = index !== panels.length - 1;
        }
        if (panels.length > 1) {
            form.insertBefore(back, button);
            form.insertBefore(next, button);
            back.addEventListener('click', () => showStep(currentStep - 1));
            next.addEventListener('click', () => { if (form.reportValidity()) showStep(currentStep + 1); });
            showStep(0);
        }
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (button.disabled || !form.reportValidity()) return;
            if (currentStep < panels.length - 1) { showStep(currentStep + 1); return; }
            button.disabled = true;
            status.textContent = config.i18n.submitting;
            try {
                // admin-ajax respects logged-in cookies and always bypasses the
                // cached page's token. No visitor or session value is in HTML.
                const tokenResponse = await fetch(config.nonceUrl, { credentials: 'same-origin', cache: 'no-store' });
                const token = await tokenResponse.json();
                if (!tokenResponse.ok || !token.success || !token.data.nonce) throw new Error(config.i18n.error);
                const data = Object.fromEntries(readers.map(([name, read]) => [name, read()]));
                data._hp_field = honeypot.value;
                const response = await fetch(config.submitUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': token.data.nonce },
                    body: JSON.stringify({ form_slug: container.dataset.formSlug, data, session_id: session,
                        metadata: { form_load_time: loadTime, form_submit_time: new Date().toISOString() } })
                });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || config.i18n.error);
                status.textContent = result.message || 'Thank you for your submission!';
                back.disabled = next.disabled = true;
                form.querySelectorAll('input, select, textarea').forEach(control => { control.disabled = true; });
                if (result.redirect_url) {
                    const target = new URL(result.redirect_url, window.location.href);
                    if (['https:', 'http:'].includes(target.protocol)) window.location.assign(target.href);
                }
            } catch (error) {
                status.textContent = error.message || config.i18n.error;
                button.disabled = false;
            }
        });
        container.replaceChildren(form);
    }
    const start = () => document.querySelectorAll('.peanut-form-container').forEach(render);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
