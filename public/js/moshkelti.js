(() => {
    'use strict';

    const root = document.documentElement;
    const labels = {
        status: {
            received: ['تم الاستلام', 'Received'], processing: ['جارٍ الفرز', 'Processing'],
            needs_review: ['بانتظار المراجعة', 'Awaiting review'], pending: ['قيد الانتظار', 'Pending'],
            pending_review: ['بانتظار المراجعة', 'Awaiting review'], accepted: ['مقبول', 'Accepted'],
            rejected: ['مرفوض', 'Rejected'], needs_more_information: ['معلومات إضافية مطلوبة', 'More information needed'],
            routed: ['أُحيل للجهة', 'Routed to authority'], acknowledged: ['أكدت الجهة الاستلام', 'Acknowledged'],
            in_progress: ['قيد المعالجة', 'In progress'], resolved: ['تم الحل', 'Resolved'], closed: ['مغلق', 'Closed'],
            duplicate: ['بلاغ مكرر', 'Duplicate report'], dispatch_failed: ['تعذّر الإرسال', 'Dispatch failed'],
            emergency_guidance: ['إرشادات للطوارئ', 'Emergency guidance'], service_guidance: ['إرشادات للخدمة', 'Service guidance'],
            warning: ['تحذير', 'Warning'], temporary_suspend: ['تعليق مؤقت', 'Temporary suspension'],
            temporarily_suspended: ['تعليق مؤقت', 'Temporarily suspended'], banned: ['محظور', 'Banned'],
            reversal: ['رفع القيود', 'Restrictions reversed'], active: ['نشط', 'Active']
        },
        category: {
            roads: ['الطرق', 'Roads'], lighting: ['الإنارة', 'Lighting'], waste: ['النفايات', 'Waste'],
            drainage: ['الصرف', 'Drainage'], public_facilities: ['المرافق العامة', 'Public facilities'],
            other: ['أخرى', 'Other'], uncategorized: ['غير مصنّف', 'Uncategorized']
        },
        risk: { low: ['مخاطر منخفضة', 'Low risk'], medium: ['مخاطر متوسطة', 'Medium risk'], high: ['مخاطر مرتفعة', 'High risk'] },
        privacyType: { access: ['طلب الوصول', 'Access request'], correction: ['طلب التصحيح', 'Correction request'], erase: ['طلب المحو', 'Erasure request'], withdraw: ['سحب الموافقة الخارجية', 'Withdraw external consent'] },
        governorate: {
            Amman: ['عمّان', 'Amman'], Irbid: ['إربد', 'Irbid'], Zarqa: ['الزرقاء', 'Zarqa'],
            Balqa: ['البلقاء', 'Balqa'], Madaba: ['مادبا', 'Madaba'], Jerash: ['جرش', 'Jerash'],
            Ajloun: ['عجلون', 'Ajloun'], Mafraq: ['المفرق', 'Mafraq'], Karak: ['الكرك', 'Karak'],
            Tafilah: ['الطفيلة', 'Tafilah'], Maan: ['معان', 'Ma’an'], Aqaba: ['العقبة', 'Aqaba']
        },
        accessReason: {
            account_restricted: ['التقديم موقوف بسبب قيد على الحساب.', 'Submissions are paused because the account is restricted.'],
            admin_read_only: ['حساب الإدارة مخصص للمراجعة ولا يقدّم بلاغات.', 'Administrator accounts review reports and do not submit them.'],
            verification_required: ['تحقّق من بريدك الإلكتروني لتفعيل التقديم.', 'Verify your email address to enable submissions.']
        }
    };
    const choose = (arabic, english) => root.lang === 'en' ? english : arabic;
    const localized = (group, value) => {
        const pair = Object.hasOwn(labels[group], value) ? labels[group][value] : null;
        return pair ? choose(pair[0], pair[1]) : String(value || '—').replaceAll('_', ' ');
    };
    const refreshLabels = () => {
        Object.keys(labels).forEach((group) => {
            const attribute = group.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`);
            document.querySelectorAll(`[data-${attribute}]`).forEach((element) => {
                element.textContent = localized(group, element.dataset[group]);
            });
        });
        document.querySelectorAll('[data-option-ar][data-option-en]').forEach((option) => {
            option.textContent = choose(option.dataset.optionAr, option.dataset.optionEn);
        });
        document.querySelectorAll('option').forEach((option) => {
            if (!option.hasAttribute('data-option-ar') && labels.category[option.value]) {
                option.textContent = localized('category', option.value);
            }
            if (!option.hasAttribute('data-option-ar') && Object.hasOwn(labels.governorate, option.value)) {
                option.textContent = localized('governorate', option.value);
            }
        });
    };
    const setLanguage = (language) => {
        root.lang = language === 'en' ? 'en' : 'ar';
        root.dir = root.lang === 'ar' ? 'rtl' : 'ltr';
        refreshLabels();
        document.dispatchEvent(new CustomEvent('moshkelti:language'));
    };
    try {
        const explicitLanguage = new URL(window.location.href).searchParams.get('lang');
        const savedLanguage = localStorage.getItem('moshkelti.language');
        setLanguage(['ar', 'en'].includes(explicitLanguage) ? explicitLanguage : (savedLanguage || root.lang));
    } catch {
        setLanguage(root.lang);
    }
    root.classList.add('js-ready');
    document.querySelectorAll('[data-language-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            setLanguage(root.lang === 'ar' ? 'en' : 'ar');
            try { localStorage.setItem('moshkelti.language', root.lang); } catch { /* Storage is optional. */ }
        });
    });

    const menuButton = document.querySelector('[data-menu-toggle]');
    const navigation = document.getElementById('primary-nav');
    const closeMenu = () => {
        navigation?.classList.remove('is-open');
        menuButton?.setAttribute('aria-expanded', 'false');
    };
    menuButton?.addEventListener('click', () => {
        const open = menuButton.getAttribute('aria-expanded') !== 'true';
        navigation?.classList.toggle('is-open', open);
        menuButton.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menuButton?.getAttribute('aria-expanded') === 'true') {
            closeMenu();
            menuButton.focus();
        }
    });
    document.addEventListener('click', (event) => {
        if (menuButton && navigation && !navigation.contains(event.target) && !menuButton.contains(event.target)) {
            closeMenu();
        }
    });

    document.querySelectorAll('[data-countdown]').forEach((element) => {
        const serverNow = Date.parse(element.dataset.serverNow);
        const availableAt = Date.parse(element.dataset.availableAt);
        const fallback = Number(element.dataset.remainingSeconds);
        const remainingAtLoad = Number.isFinite(serverNow) && Number.isFinite(availableAt)
            ? Math.max(0, (availableAt - serverNow) / 1000)
            : (Number.isFinite(fallback) ? Math.max(0, fallback) : 0);
        const startedAt = performance.now();
        let timer;
        const render = () => {
            const remaining = Math.max(0, Math.ceil(remainingAtLoad - (performance.now() - startedAt) / 1000));
            if (remaining === 0) {
                element.textContent = choose('انتهت المدة؛ حدّث الصفحة للتحقق من صلاحية التقديم.', 'Time elapsed; refresh to verify submission access.');
                if (timer) { window.clearInterval(timer); }
                return;
            }
            const days = Math.floor(remaining / 86400);
            const hours = Math.floor((remaining % 86400) / 3600);
            const minutes = Math.floor((remaining % 3600) / 60);
            const seconds = remaining % 60;
            const time = [hours, minutes, seconds].map((value) => String(value).padStart(2, '0')).join(':');
            element.textContent = days ? `${days} ${choose('يوم', 'days')} ${time}` : time;
        };
        render();
        if (remainingAtLoad > 0) { timer = window.setInterval(render, 1000); }
        document.addEventListener('moshkelti:language', render);
        document.addEventListener('visibilitychange', render);
    });

    document.querySelectorAll('[data-character-input]').forEach((input) => {
        const count = input.closest('.field')?.querySelector('[data-character-count]');
        const render = () => {
            if (count) { count.textContent = `${input.value.length} / ${input.maxLength}`; }
        };
        input.addEventListener('input', render);
        render();
    });

    document.querySelectorAll('[data-attachments]').forEach((input) => {
        const feedback = input.closest('.field')?.querySelector('[data-upload-feedback]');
        const list = input.closest('.field')?.querySelector('[data-upload-list]');
        const acceptedTypes = new Set(['image/jpeg', 'image/png', 'image/webp']);
        let selectionVersion = 0;
        const validate = (showPreview) => {
            const files = Array.from(input.files || []);
            let error = '';
            if (files.length > 3) {
                error = choose('يمكن إرفاق 3 صور كحد أقصى. أعد اختيار الصور.', 'Choose no more than 3 images. Please reselect.');
            } else if (files.some((file) => !acceptedTypes.has(file.type) || !/\.(jpe?g|png|webp)$/i.test(file.name))) {
                error = choose('اختر صور JPEG أو PNG أو WebP فقط.', 'Choose JPEG, PNG or WebP images only.');
            } else if (files.some((file) => file.size > 5 * 1024 * 1024)) {
                error = choose('كل صورة يجب أن تكون 5 ميغابايت أو أقل.', 'Each image must be 5 MB or smaller.');
            }
            input.setCustomValidity(error);
            input.setAttribute('aria-invalid', String(Boolean(error)));
            if (feedback) { feedback.textContent = error; }
            if (!showPreview || !list) { return; }
            selectionVersion += 1;
            const currentVersion = selectionVersion;
            list.replaceChildren();
            if (error) { return; }
            files.forEach((file) => {
                const item = document.createElement('li');
                const name = document.createElement('span');
                item.className = 'file-item';
                name.dir = 'auto';
                name.textContent = `${file.name} · ${(file.size / (1024 * 1024)).toFixed(2)} MB`;
                item.append(name);
                list.append(item);
                const reader = new FileReader();
                reader.addEventListener('load', () => {
                    if (selectionVersion !== currentVersion || typeof reader.result !== 'string' || !/^data:image\/(jpeg|png|webp);base64,/i.test(reader.result)) { return; }
                    const image = document.createElement('img');
                    image.src = reader.result;
                    image.alt = '';
                    image.width = 56;
                    image.height = 56;
                    item.prepend(image);
                });
                reader.readAsDataURL(file);
            });
        };
        input.addEventListener('change', () => validate(true));
        document.addEventListener('moshkelti:language', () => validate(false));
    });

    document.querySelectorAll('[data-restriction-form]').forEach((form) => {
        const type = form.querySelector('[data-restriction-type]');
        const endsAt = form.querySelector('[data-restriction-end]');
        const update = () => {
            if (!type || !endsAt) { return; }
            const temporary = type.value === 'temporary_suspend';
            endsAt.required = temporary;
            endsAt.disabled = !temporary;
        };
        type?.addEventListener('change', update);
        update();
    });
    document.querySelectorAll('[data-decision-form]').forEach((form) => {
        const publication = form.querySelector('[name="publish"][type="checkbox"]');
        const title = form.querySelector('[name="public_title"]');
        const summary = form.querySelector('[name="public_summary"]');
        const update = () => {
            if (title && summary) { title.required = Boolean(publication?.checked); summary.required = Boolean(publication?.checked); }
        };
        publication?.addEventListener('change', update);
        update();
    });

    document.querySelectorAll('[data-report-form]').forEach((form) => {
        let submitting = false;
        form.addEventListener('submit', (event) => {
            if (submitting) { event.preventDefault(); return; }
            if (!form.checkValidity()) { return; }
            submitting = true;
            form.setAttribute('aria-busy', 'true');
            const button = form.querySelector('[data-submit-button]');
            if (button) { button.disabled = true; }
        });
        window.addEventListener('pageshow', () => {
            submitting = false;
            form.removeAttribute('aria-busy');
            const button = form.querySelector('[data-submit-button]');
            if (button) { button.disabled = false; }
        });
    });

    document.querySelectorAll('[data-report-status][data-poll-url]').forEach((element) => {
        let endpoint;
        try { endpoint = new URL(element.dataset.pollUrl, window.location.href); } catch { return; }
        if (endpoint.origin !== window.location.origin) { return; }
        const feedback = document.querySelector('[data-poll-feedback]');
        let feedbackKind = '';
        let failures = 0;
        let stopped = false;
        let timer;
        const renderFeedback = () => {
            if (!feedback) { return; }
            const messages = {
                changed: ['تغيّرت حالة البلاغ. حدّث الصفحة لعرض التفاصيل والإجراءات الحالية.', 'Report status changed. Refresh for current details and actions.'],
                unavailable: ['التحديث التلقائي غير متاح. يمكنك تحديث الصفحة يدوياً.', 'Automatic updates are unavailable. You can refresh the page manually.']
            };
            feedback.textContent = feedbackKind ? choose(...messages[feedbackKind]) : '';
        };
        const schedule = () => {
            if (!stopped) { timer = window.setTimeout(poll, 20000); }
        };
        const poll = async () => {
            if (stopped) { return; }
            if (document.hidden || navigator.onLine === false) { schedule(); return; }
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 8000);
            try {
                const response = await fetch(endpoint.href, {
                    headers: { Accept: 'application/json' }, credentials: 'same-origin',
                    cache: 'no-store', redirect: 'error', signal: controller.signal
                });
                if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) {
                    if ([401, 403, 404, 429].includes(response.status)) { stopped = true; }
                    throw new Error('Status unavailable');
                }
                const result = await response.json();
                const report = result.data || result;
                if (typeof report.status !== 'string' || !Object.hasOwn(labels.status, report.status)) { stopped = true; throw new Error('Unsupported response'); }
                failures = 0;
                if (report.status !== element.dataset.status) {
                    element.dataset.status = report.status;
                    element.textContent = localized('status', report.status);
                    feedbackKind = 'changed';
                    stopped = true;
                }
                renderFeedback();
            } catch {
                failures += 1;
                if (failures >= 3 || stopped) {
                    stopped = true;
                    feedbackKind = 'unavailable';
                    renderFeedback();
                }
            } finally {
                window.clearTimeout(timeout);
                schedule();
            }
        };
        document.addEventListener('moshkelti:language', renderFeedback);
        window.addEventListener('pagehide', () => { stopped = true; window.clearTimeout(timer); });
        window.addEventListener('pageshow', (event) => {
            if (event.persisted) { stopped = false; failures = 0; schedule(); }
        });
        schedule();
    });
    document.querySelector('[data-error-summary]')?.focus();
})();
