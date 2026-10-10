/**
 * Peanut Connect Popups
 *
 * Display and manage popups from Peanut Hub.
 *
 * @package Peanut_Connect
 * @since 2.3.0
 */

(function() {
    'use strict';

    if (typeof peanutConnectPopups === 'undefined') {
        return;
    }

    const config = peanutConnectPopups;
    const container = document.getElementById('peanut-connect-popups-container');
    const shownPopups = new Set();
    const dismissedPopups = JSON.parse(localStorage.getItem('peanut_dismissed_popups') || '[]');

    /**
     * This browser's visitor id (the tracker's cookie), or null. Never taken
     * from the page config: page caches serve one HTML to every visitor.
     * Without one, the server falls back to the request's own cookie.
     */
    function visitorId() {
        const name = config.cookieName || 'peanut_vid';
        const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([a-f0-9]{32})(?:;|$)', 'i'));
        return match ? match[1].toLowerCase() : null;
    }

    /**
     * Track popup interaction
     */
    function trackInteraction(popupId, action, data = {}) {
        fetch(`${config.restUrl}/popup-interaction`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce,
            },
            body: JSON.stringify({
                popup_id: popupId,
                visitor_id: visitorId(),
                action: action,
                page_url: window.location.href,
                data: data,
            }),
        }).catch(() => {});
    }

    /**
     * Dismiss popup
     */
    function dismissPopup(popupId, element) {
        dismissedPopups.push(popupId);
        localStorage.setItem('peanut_dismissed_popups', JSON.stringify(dismissedPopups));

        if (element) {
            element.classList.add('peanut-popup-closing');
            setTimeout(() => element.remove(), 300);
        }

        trackInteraction(popupId, 'dismiss');
    }

    /**
     * Create popup HTML
     */
    function createPopupElement(popup) {
        const content = popup.content || {};
        const design = popup.design || {};

        const wrapper = document.createElement('div');
        wrapper.className = `peanut-popup peanut-popup-${popup.type} peanut-popup-${popup.id}`;
        wrapper.setAttribute('data-popup-id', popup.id);

        // Apply custom styles
        if (design.backgroundColor) {
            wrapper.style.setProperty('--popup-bg', design.backgroundColor);
        }
        if (design.textColor) {
            wrapper.style.setProperty('--popup-text', design.textColor);
        }
        if (design.buttonColor) {
            wrapper.style.setProperty('--popup-button-bg', design.buttonColor);
        }
        if (design.buttonTextColor) {
            wrapper.style.setProperty('--popup-button-text', design.buttonTextColor);
        }

        let html = `
            <div class="peanut-popup-overlay"></div>
            <div class="peanut-popup-content">
                <button class="peanut-popup-close" aria-label="Close">&times;</button>
        `;

        if (content.image) {
            html += `<img class="peanut-popup-image" src="${escapeHtml(content.image)}" alt="">`;
        }

        if (content.title) {
            html += `<h2 class="peanut-popup-title">${escapeHtml(content.title)}</h2>`;
        }

        if (content.body) {
            html += `<div class="peanut-popup-body">${content.body}</div>`;
        }

        if (content.cta_text) {
            const ctaUrl = content.cta_url || '#';
            html += `<a class="peanut-popup-cta" href="${escapeHtml(ctaUrl)}">${escapeHtml(content.cta_text)}</a>`;
        }

        html += `</div>`;

        wrapper.innerHTML = html;

        // Event listeners
        wrapper.querySelector('.peanut-popup-close').addEventListener('click', () => {
            dismissPopup(popup.id, wrapper);
        });

        wrapper.querySelector('.peanut-popup-overlay').addEventListener('click', () => {
            dismissPopup(popup.id, wrapper);
        });

        const cta = wrapper.querySelector('.peanut-popup-cta');
        if (cta) {
            cta.addEventListener('click', (e) => {
                trackInteraction(popup.id, 'click', { url: cta.href });

                // If it's a form submission or conversion action
                if (content.cta_action === 'convert') {
                    trackInteraction(popup.id, 'convert');
                }
            });
        }

        return wrapper;
    }

    /**
     * Show popup
     */
    function showPopup(popup) {
        if (shownPopups.has(popup.id) || dismissedPopups.includes(popup.id)) {
            return;
        }

        shownPopups.add(popup.id);

        const element = createPopupElement(popup);
        container.appendChild(element);

        // Trigger animation
        requestAnimationFrame(() => {
            element.classList.add('peanut-popup-visible');
        });

        // Track view
        trackInteraction(popup.id, 'view');
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Check triggers for a popup
     */
    function checkTriggers(popup) {
        const triggers = popup.triggers || {};

        // Time delay trigger
        if (triggers.time_delay) {
            setTimeout(() => {
                showPopup(popup);
            }, triggers.time_delay * 1000);
        }

        // Scroll depth trigger
        if (triggers.scroll_percent) {
            let triggered = false;
            window.addEventListener('scroll', function checkScroll() {
                if (triggered) return;

                const h = document.documentElement;
                const b = document.body;
                const percent = Math.round((h.scrollTop || b.scrollTop) / ((h.scrollHeight || b.scrollHeight) - h.clientHeight) * 100);

                if (percent >= triggers.scroll_percent) {
                    triggered = true;
                    showPopup(popup);
                    window.removeEventListener('scroll', checkScroll);
                }
            }, { passive: true });
        }

        // Exit intent trigger
        if (triggers.exit_intent) {
            let triggered = false;
            document.addEventListener('mouseout', function checkExit(e) {
                if (triggered) return;

                if (e.clientY < 10 && e.relatedTarget === null) {
                    triggered = true;
                    showPopup(popup);
                    document.removeEventListener('mouseout', checkExit);
                }
            });
        }

        // Immediate show (no trigger)
        if (!triggers.time_delay && !triggers.scroll_percent && !triggers.exit_intent) {
            showPopup(popup);
        }
    }

    /**
     * Initialize
     */
    function init() {
        if (!container || !config.popups || !config.popups.length) {
            return;
        }

        // Sort by priority (higher first)
        const sortedPopups = [...config.popups].sort((a, b) => (b.priority || 0) - (a.priority || 0));

        // Set up triggers for each popup
        sortedPopups.forEach(popup => {
            checkTriggers(popup);
        });
    }

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
