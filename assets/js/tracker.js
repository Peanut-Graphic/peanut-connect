/**
 * Peanut Connect Tracker
 *
 * Advanced visitor and event tracking for Peanut Hub.
 * Captures rich, contextual user interactions.
 *
 * @package Peanut_Connect
 * @since 2.3.0
 * @version 3.2.0
 */

(function() {
    'use strict';

    // Exit if not configured
    if (typeof peanutConnectTracker === 'undefined') {
        return;
    }

    const config = peanutConnectTracker;
    let visitorId = config.visitorId;
    let clickId = config.clickId || null;

    // Engagement tracking state
    const engagement = {
        startTime: Date.now(),
        activeTime: 0,
        idleTime: 0,
        lastActivity: Date.now(),
        isActive: true,
        idleThreshold: 30000, // 30 seconds without activity = idle
        maxScrollDepth: 0,
        scrollMilestones: { 25: false, 50: false, 75: false, 100: false },
        interactions: 0,
        formInteractions: {},
        videosWatched: {},
    };

    // CTA detection patterns
    const ctaPatterns = {
        primary: /^(enroll|apply|register|sign.?up|get.?started|buy|purchase|order|subscribe|download|contact|call|request|book|schedule|reserve|join|start|try|demo|quote)/i,
        secondary: /^(learn.?more|read.?more|view|see|explore|discover|find.?out|details|info|more)/i,
        conversion: /^(submit|send|complete|finish|confirm|pay|checkout|donate)/i,
    };

    // ==========================================
    // COOKIE MANAGEMENT
    // ==========================================

    function setVisitorCookie() {
        const expires = new Date();
        expires.setTime(expires.getTime() + (config.cookieExpiry * 1000));
        document.cookie = `${config.cookieName}=${visitorId};expires=${expires.toUTCString()};path=/;SameSite=Lax`;
    }

    function getVisitorFromCookie() {
        const match = document.cookie.match(new RegExp('(^| )' + config.cookieName + '=([^;]+)'));
        return match ? match[2] : null;
    }

    function getClickId() {
        const urlParams = new URLSearchParams(window.location.search);
        const urlClickId = urlParams.get('click_id');
        if (urlClickId && /^[a-f0-9\-]{36}$/i.test(urlClickId)) {
            setClickIdCookie(urlClickId);
            return urlClickId;
        }
        return getClickIdFromCookie();
    }

    function setClickIdCookie(id) {
        if (!config.clickIdCookie || !config.clickIdExpiry) return;
        const expires = new Date();
        expires.setTime(expires.getTime() + (config.clickIdExpiry * 1000));
        document.cookie = `${config.clickIdCookie}=${id};expires=${expires.toUTCString()};path=/;SameSite=Lax`;
    }

    function getClickIdFromCookie() {
        if (!config.clickIdCookie) return null;
        const match = document.cookie.match(new RegExp('(^| )' + config.clickIdCookie + '=([^;]+)'));
        return match ? match[2] : null;
    }

    // Read click_id from any known cookie. The Hub tracker writes `_pnut_cid`;
    // this plugin writes the configured `clickIdCookie` (default `peanut_click_id`).
    // Both are checked so href-rewrite works regardless of which one populated first.
    function readPersistedClickId() {
        const cookieNames = ['_pnut_cid'];
        if (config.clickIdCookie && cookieNames.indexOf(config.clickIdCookie) === -1) {
            cookieNames.push(config.clickIdCookie);
        }
        for (const name of cookieNames) {
            const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[-.+*]/g, '\\$&') + '=([^;]*)'));
            if (match && match[1]) {
                try { return decodeURIComponent(match[1]); } catch (e) { return match[1]; }
            }
        }
        return null;
    }

    // Mutate a CTA link's href to include click_id before the browser navigates.
    // Runs inside the capture-phase click handler so the rewrite is visible to
    // the upcoming navigation. Skips tel:/mailto:/javascript:/anchor-only,
    // and skips links that already carry click_id.
    function appendClickIdToHref(link) {
        try {
            const cid = readPersistedClickId();
            if (!cid) return;
            const raw = link.getAttribute('href');
            if (!raw) return;
            if (/^(tel:|mailto:|javascript:|#)/i.test(raw)) return;
            // Split off any hash fragment FIRST. The query string belongs
            // before the hash — e.g. /foo/?click_id=X#validation, never
            // /foo/#validation?click_id=X (browsers treat the latter as a
            // fragment, so location.search ends up empty downstream and the
            // GTM beacon's URL parser can't recover the click_id).
            const hashIdx = raw.indexOf('#');
            const baseAndQuery = hashIdx === -1 ? raw : raw.substring(0, hashIdx);
            const hash = hashIdx === -1 ? '' : raw.substring(hashIdx);
            if (/[?&]click_id=/i.test(baseAndQuery)) return;
            const sep = baseAndQuery.indexOf('?') === -1 ? '?' : '&';
            link.setAttribute('href', baseAndQuery + sep + 'click_id=' + encodeURIComponent(cid) + hash);
        } catch (e) { /* noop */ }
    }

    // ==========================================
    // CORE TRACKING
    // ==========================================

    function trackEvent(eventType, eventName, eventData = {}) {
        const data = {
            visitor_id: visitorId,
            event_type: eventType,
            event_name: eventName,
            page_url: window.location.href,
            page_title: document.title,
            referrer: document.referrer || null,
            metadata: eventData,
            occurred_at: new Date().toISOString(),
        };

        if (clickId) {
            data.click_id = clickId;
        }

        // Add UTM params if present
        const urlParams = new URLSearchParams(window.location.search);
        ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach(param => {
            if (urlParams.has(param)) data[param] = urlParams.get(param);
        });

        // Use sendBeacon for reliability
        const url = `${config.restUrl}/track`;
        const payload = JSON.stringify(data);

        if (navigator.sendBeacon) {
            const blob = new Blob([payload], { type: 'application/json' });
            navigator.sendBeacon(url, blob);
        } else {
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
                body: payload,
                keepalive: true,
            }).catch(() => {});
        }
    }

    // ==========================================
    // ELEMENT CONTEXT HELPERS
    // ==========================================

    function getElementText(el) {
        if (!el) return null;
        // Try aria-label first
        if (el.getAttribute('aria-label')) return el.getAttribute('aria-label').trim().substring(0, 100);
        // Try title
        if (el.title) return el.title.trim().substring(0, 100);
        // Try innerText (visible text only)
        const text = el.innerText || el.textContent || '';
        return text.trim().replace(/\s+/g, ' ').substring(0, 100) || null;
    }

    function getElementIdentifier(el) {
        if (!el) return 'unknown';
        if (el.id) return `#${el.id}`;
        if (el.name) return `[name="${el.name}"]`;
        if (el.className && typeof el.className === 'string') {
            const mainClass = el.className.split(' ')[0];
            if (mainClass) return `.${mainClass}`;
        }
        return el.tagName.toLowerCase();
    }

    function getCtaType(text) {
        if (!text) return null;
        const cleanText = text.toLowerCase().trim();
        if (ctaPatterns.conversion.test(cleanText)) return 'conversion';
        if (ctaPatterns.primary.test(cleanText)) return 'primary';
        if (ctaPatterns.secondary.test(cleanText)) return 'secondary';
        return null;
    }

    function getFormName(form) {
        if (!form) return 'Unknown Form';
        // Try various form identifiers
        if (form.getAttribute('data-form-name')) return form.getAttribute('data-form-name');
        if (form.getAttribute('aria-label')) return form.getAttribute('aria-label');
        if (form.id) return form.id.replace(/[-_]/g, ' ').replace(/form/gi, '').trim() || form.id;
        if (form.name) return form.name.replace(/[-_]/g, ' ').replace(/form/gi, '').trim() || form.name;
        // Try to find a heading inside the form
        const heading = form.querySelector('h1, h2, h3, h4, legend');
        if (heading) return heading.textContent.trim().substring(0, 50);
        return 'Form';
    }

    function getFormStepInfo(form) {
        // Detect multi-step forms
        const stepIndicators = form.querySelectorAll('[data-step], .step, .form-step, [class*="step"]');
        const activeStep = form.querySelector('[data-step].active, .step.active, .form-step.active, [class*="step"][class*="active"]');
        const progressBar = form.querySelector('[role="progressbar"], progress, .progress');

        let currentStep = null;
        let totalSteps = null;

        if (stepIndicators.length > 0) {
            totalSteps = stepIndicators.length;
            stepIndicators.forEach((step, index) => {
                if (step.classList.contains('active') || step.classList.contains('current')) {
                    currentStep = index + 1;
                }
            });
        }

        if (progressBar) {
            const value = progressBar.getAttribute('aria-valuenow') || progressBar.value;
            const max = progressBar.getAttribute('aria-valuemax') || progressBar.max || 100;
            if (value && max) {
                return { progress: Math.round((value / max) * 100) };
            }
        }

        if (currentStep && totalSteps) {
            return { currentStep, totalSteps };
        }

        return null;
    }

    // ==========================================
    // SMART CTA TRACKING
    // ==========================================

    function trackSmartClicks() {
        document.addEventListener('click', function(e) {
            const target = e.target;

            // Track button clicks
            const button = target.closest('button, [role="button"], input[type="submit"], input[type="button"]');
            if (button) {
                const buttonText = getElementText(button) || button.value || 'Button';
                const ctaType = getCtaType(buttonText);

                trackEvent('click', `Clicked "${buttonText}"`, {
                    element: 'button',
                    text: buttonText,
                    identifier: getElementIdentifier(button),
                    cta_type: ctaType,
                    is_cta: !!ctaType,
                });

                // Funnel-stage event: Hub's "Clicked enroll" stage matches a
                // custom event named click_to_portal. Emit it for any primary
                // CTA (Enroll / Apply / Register / Sign-up / Get-started / …)
                // so the campaign funnel ticks without per-site wiring.
                if (ctaType === 'primary') {
                    trackEvent('custom', 'click_to_portal', {
                        element: 'button',
                        text: buttonText,
                        identifier: getElementIdentifier(button),
                        source: location.pathname,
                    });
                }

                engagement.interactions++;
                return;
            }

            // Track link clicks
            const link = target.closest('a');
            if (link) {
                const linkText = getElementText(link);
                const ctaType = getCtaType(linkText);

                // Forward click_id to primary CTA destinations so attribution
                // survives the next page-load (same-domain or external). Done
                // synchronously, before navigation, so the rewritten href is
                // what the browser follows.
                if (ctaType === 'primary') {
                    appendClickIdToHref(link);
                }

                const href = link.getAttribute('href');
                if (!href) return;

                // Check if external
                let isExternal = false;
                try {
                    const url = new URL(href, window.location.origin);
                    isExternal = url.hostname !== window.location.hostname;
                } catch (e) {}

                // Check for special links
                const isPhone = href.startsWith('tel:');
                const isEmail = href.startsWith('mailto:');
                const isDownload = link.hasAttribute('download') || /\.(pdf|doc|docx|xls|xlsx|zip|rar)$/i.test(href);

                let eventName = `Clicked "${linkText || 'Link'}"`;
                let eventType = 'click';

                if (isPhone) {
                    eventType = 'phone_click';
                    eventName = `Called ${href.replace('tel:', '')}`;
                } else if (isEmail) {
                    eventType = 'email_click';
                    eventName = `Emailed ${href.replace('mailto:', '').split('?')[0]}`;
                } else if (isDownload) {
                    eventType = 'download';
                    eventName = `Downloaded "${linkText || href.split('/').pop()}"`;
                } else if (isExternal) {
                    eventType = 'outbound_click';
                    eventName = `Clicked external: "${linkText || href}"`;
                }

                trackEvent(eventType, eventName, {
                    element: 'link',
                    text: linkText,
                    href: href.substring(0, 200),
                    identifier: getElementIdentifier(link),
                    cta_type: ctaType,
                    is_cta: !!ctaType,
                    is_external: isExternal,
                    is_phone: isPhone,
                    is_email: isEmail,
                    is_download: isDownload,
                });

                // Funnel-stage event: Hub's "Clicked enroll" stage matches a
                // custom event named click_to_portal. Emit it for any primary
                // CTA (Enroll / Apply / Register / Sign-up / Get-started / …)
                // so the campaign funnel ticks without per-site wiring.
                // Skip phone/email/download — those aren't conversion intent.
                if (ctaType === 'primary' && !isPhone && !isEmail && !isDownload) {
                    trackEvent('custom', 'click_to_portal', {
                        element: 'link',
                        text: linkText,
                        href: href.substring(0, 200),
                        identifier: getElementIdentifier(link),
                        source: location.pathname,
                    });
                }

                engagement.interactions++;
            }
        }, true);
    }

    // ==========================================
    // FORM TRACKING
    // ==========================================

    function trackForms() {
        // Track form focus (form start)
        document.addEventListener('focusin', function(e) {
            const field = e.target;
            if (!field.form) return;

            const form = field.form;
            const formId = getElementIdentifier(form);
            const formName = getFormName(form);

            // Track form start (first interaction)
            if (!engagement.formInteractions[formId]) {
                engagement.formInteractions[formId] = {
                    started: Date.now(),
                    fields: new Set(),
                    lastField: null,
                };

                const stepInfo = getFormStepInfo(form);
                trackEvent('form_start', `Started "${formName}"`, {
                    form_name: formName,
                    form_id: formId,
                    step_info: stepInfo,
                    field_count: form.querySelectorAll('input, select, textarea').length,
                });
            }

            // Track field focus
            const fieldName = field.name || field.id || field.placeholder || field.type;
            const fieldLabel = getFieldLabel(field) || fieldName;

            if (fieldName && !engagement.formInteractions[formId].fields.has(fieldName)) {
                engagement.formInteractions[formId].fields.add(fieldName);
                engagement.formInteractions[formId].lastField = fieldLabel;
            }
        });

        // Track form step changes (for multi-step forms)
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                    const el = mutation.target;
                    if (el.classList.contains('active') || el.classList.contains('current')) {
                        const form = el.closest('form');
                        if (form) {
                            const stepInfo = getFormStepInfo(form);
                            if (stepInfo && stepInfo.currentStep) {
                                trackEvent('form_step', `Completed Step ${stepInfo.currentStep - 1} of ${stepInfo.totalSteps}`, {
                                    form_name: getFormName(form),
                                    current_step: stepInfo.currentStep,
                                    total_steps: stepInfo.totalSteps,
                                });
                            }
                        }
                    }
                }
            });
        });

        // Observe step changes
        document.querySelectorAll('[data-step], .step, .form-step').forEach(el => {
            observer.observe(el, { attributes: true, attributeFilter: ['class'] });
        });

        // Track form submissions
        document.addEventListener('submit', function(e) {
            const form = e.target;
            if (!form || form.tagName !== 'FORM') return;

            const formId = getElementIdentifier(form);
            const formName = getFormName(form);
            const formData = engagement.formInteractions[formId] || {};

            const timeSpent = formData.started ? Math.round((Date.now() - formData.started) / 1000) : null;
            const fieldsCompleted = formData.fields ? formData.fields.size : 0;

            trackEvent('form_submit', `Submitted "${formName}"`, {
                form_name: formName,
                form_id: formId,
                form_action: form.action || window.location.href,
                time_spent_seconds: timeSpent,
                fields_completed: fieldsCompleted,
                step_info: getFormStepInfo(form),
            });
        });
    }

    function getFieldLabel(field) {
        // Try to find associated label
        if (field.id) {
            const label = document.querySelector(`label[for="${field.id}"]`);
            if (label) return label.textContent.trim().substring(0, 50);
        }
        // Try parent label
        const parentLabel = field.closest('label');
        if (parentLabel) return parentLabel.textContent.replace(field.value || '', '').trim().substring(0, 50);
        // Try placeholder
        if (field.placeholder) return field.placeholder.substring(0, 50);
        // Try aria-label
        if (field.getAttribute('aria-label')) return field.getAttribute('aria-label').substring(0, 50);
        return null;
    }

    // ==========================================
    // SCROLL DEPTH TRACKING
    // ==========================================

    function trackScrollDepth() {
        function getScrollPercent() {
            const h = document.documentElement;
            const b = document.body;
            const st = 'scrollTop';
            const sh = 'scrollHeight';
            const scrollTop = h[st] || b[st];
            const scrollHeight = (h[sh] || b[sh]) - h.clientHeight;
            if (scrollHeight === 0) return 100;
            return Math.round((scrollTop / scrollHeight) * 100);
        }

        let scrollTimeout;
        window.addEventListener('scroll', function() {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(function() {
                const percent = getScrollPercent();
                if (percent > engagement.maxScrollDepth) {
                    engagement.maxScrollDepth = percent;

                    // Track milestones
                    [25, 50, 75, 100].forEach(milestone => {
                        if (!engagement.scrollMilestones[milestone] && percent >= milestone) {
                            engagement.scrollMilestones[milestone] = true;
                            trackEvent('scroll', `Scrolled ${milestone}%`, {
                                depth: milestone,
                                page_height: document.body.scrollHeight,
                            });
                        }
                    });
                }
            }, 100);
        }, { passive: true });
    }

    // ==========================================
    // ENGAGEMENT & ACTIVITY TRACKING
    // ==========================================

    function trackEngagement() {
        const activityEvents = ['mousedown', 'keydown', 'touchstart', 'scroll'];

        // Track activity
        activityEvents.forEach(event => {
            document.addEventListener(event, function() {
                const now = Date.now();
                if (!engagement.isActive) {
                    engagement.isActive = true;
                }
                engagement.lastActivity = now;
            }, { passive: true });
        });

        // Check for idle state every 5 seconds
        setInterval(function() {
            const now = Date.now();
            const timeSinceActivity = now - engagement.lastActivity;

            if (engagement.isActive && timeSinceActivity > engagement.idleThreshold) {
                engagement.isActive = false;
            }

            // Update time counters
            if (engagement.isActive) {
                engagement.activeTime += 5000;
            } else {
                engagement.idleTime += 5000;
            }
        }, 5000);

        // Track engagement on page unload
        window.addEventListener('beforeunload', function() {
            const totalTime = Math.round((Date.now() - engagement.startTime) / 1000);
            const activeSeconds = Math.round(engagement.activeTime / 1000);
            const idleSeconds = Math.round(engagement.idleTime / 1000);

            trackEvent('page_exit', 'Left page', {
                total_time_seconds: totalTime,
                active_time_seconds: activeSeconds,
                idle_time_seconds: idleSeconds,
                max_scroll_depth: engagement.maxScrollDepth,
                interaction_count: engagement.interactions,
                engaged: activeSeconds > 10 && engagement.maxScrollDepth > 25,
            });
        });
    }

    // ==========================================
    // VIDEO TRACKING
    // ==========================================

    function trackVideos() {
        // Track HTML5 videos
        function setupVideoTracking(video) {
            if (video._peanutTracked) return;
            video._peanutTracked = true;

            const videoId = video.id || video.src || `video-${Math.random().toString(36).substr(2, 9)}`;
            const videoTitle = video.title || video.getAttribute('aria-label') || 'Video';

            engagement.videosWatched[videoId] = {
                started: false,
                percentWatched: 0,
                milestones: { 25: false, 50: false, 75: false, 100: false },
            };

            video.addEventListener('play', function() {
                if (!engagement.videosWatched[videoId].started) {
                    engagement.videosWatched[videoId].started = true;
                    trackEvent('video_play', `Started watching "${videoTitle}"`, {
                        video_id: videoId,
                        video_title: videoTitle,
                        duration: Math.round(video.duration || 0),
                    });
                }
            });

            video.addEventListener('pause', function() {
                const percent = Math.round((video.currentTime / video.duration) * 100) || 0;
                trackEvent('video_pause', `Paused "${videoTitle}" at ${percent}%`, {
                    video_id: videoId,
                    video_title: videoTitle,
                    percent_watched: percent,
                    current_time: Math.round(video.currentTime),
                });
            });

            video.addEventListener('ended', function() {
                trackEvent('video_complete', `Finished watching "${videoTitle}"`, {
                    video_id: videoId,
                    video_title: videoTitle,
                    duration: Math.round(video.duration || 0),
                });
            });

            // Track progress milestones
            video.addEventListener('timeupdate', function() {
                const percent = Math.round((video.currentTime / video.duration) * 100) || 0;
                const state = engagement.videosWatched[videoId];

                [25, 50, 75].forEach(milestone => {
                    if (!state.milestones[milestone] && percent >= milestone) {
                        state.milestones[milestone] = true;
                        trackEvent('video_progress', `Watched ${milestone}% of "${videoTitle}"`, {
                            video_id: videoId,
                            video_title: videoTitle,
                            milestone: milestone,
                        });
                    }
                });

                if (percent > state.percentWatched) {
                    state.percentWatched = percent;
                }
            });
        }

        // Track existing videos
        document.querySelectorAll('video').forEach(setupVideoTracking);

        // Track dynamically added videos
        const videoObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeName === 'VIDEO') {
                        setupVideoTracking(node);
                    } else if (node.querySelectorAll) {
                        node.querySelectorAll('video').forEach(setupVideoTracking);
                    }
                });
            });
        });
        videoObserver.observe(document.body, { childList: true, subtree: true });

        // YouTube iframe tracking (basic)
        function setupYouTubeTracking() {
            document.querySelectorAll('iframe[src*="youtube.com"], iframe[src*="youtu.be"]').forEach(iframe => {
                if (iframe._peanutTracked) return;
                iframe._peanutTracked = true;

                const videoId = iframe.src.match(/(?:embed\/|v=)([^?&]+)/)?.[1] || 'youtube';

                // Track when iframe becomes visible (rough proxy for engagement)
                const observer = new IntersectionObserver(function(entries) {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            trackEvent('video_view', `Viewed YouTube video`, {
                                video_id: videoId,
                                platform: 'youtube',
                            });
                            observer.disconnect();
                        }
                    });
                }, { threshold: 0.5 });
                observer.observe(iframe);
            });
        }
        setupYouTubeTracking();
    }

    // ==========================================
    // EXIT INTENT TRACKING
    // ==========================================

    function trackExitIntent() {
        let exitTracked = false;

        document.addEventListener('mouseout', function(e) {
            if (exitTracked) return;

            // Check if mouse left through top of viewport (exit intent)
            if (e.clientY < 10 && e.relatedTarget === null) {
                exitTracked = true;

                // Reset after 30 seconds
                setTimeout(() => { exitTracked = false; }, 30000);

                trackEvent('exit_intent', 'Showed exit intent', {
                    time_on_page: Math.round((Date.now() - engagement.startTime) / 1000),
                    scroll_depth: engagement.maxScrollDepth,
                    interactions: engagement.interactions,
                });
            }
        });

        // Also track back button (history navigation)
        window.addEventListener('popstate', function() {
            trackEvent('navigation', 'Used back/forward button', {
                direction: 'back',
                time_on_page: Math.round((Date.now() - engagement.startTime) / 1000),
            });
        });
    }

    // ==========================================
    // PUBLIC API
    // ==========================================

    window.peanutConnect = window.peanutConnect || {};

    window.peanutConnect.identify = function(email, name = null, properties = {}) {
        fetch(`${config.restUrl}/identify`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
            body: JSON.stringify({
                visitor_id: visitorId,
                email: email,
                name: name,
                properties: properties,
            }),
        }).catch(() => {});
    };

    window.peanutConnect.track = function(eventName, properties = {}) {
        trackEvent('custom', eventName, properties);
    };

    window.peanutConnect.conversion = function(type, value = null, properties = {}) {
        trackEvent('conversion', `Converted: ${type}`, {
            conversion_type: type,
            conversion_value: value,
            ...properties,
        });

        fetch(`${config.restUrl}/conversion`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
            body: JSON.stringify({
                visitor_id: visitorId,
                type: type,
                value: value,
                ...properties,
            }),
        }).catch(() => {});
    };

    // ==========================================
    // INITIALIZATION
    // ==========================================

    function init() {
        // Ensure visitor cookie is set
        if (!getVisitorFromCookie()) {
            setVisitorCookie();
        }

        // Check for Hub click_id
        const detectedClickId = getClickId();
        if (detectedClickId) {
            clickId = detectedClickId;
        }

        // Track page view with context. Use the canonical event_type
        // 'pageview' (server-side normalizes 'page_view' → 'pageview'
        // for older clients, but emit canonical from this version on).
        trackEvent('pageview', `Viewed: ${document.title}`, {
            entry_page: document.referrer ? false : true,
            referrer_domain: document.referrer ? new URL(document.referrer).hostname : null,
        });

        // Enable all tracking features
        trackSmartClicks();
        trackForms();
        trackScrollDepth();
        trackEngagement();
        trackVideos();
        trackExitIntent();
    }

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
