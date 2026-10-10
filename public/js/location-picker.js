(() => {
    'use strict';
    const picker = document.querySelector('[data-location-picker]');
    if (!picker) { return; }
    const map = picker.querySelector('[data-map]');
    const layer = picker.querySelector('[data-map-layer]');
    const latitude = picker.querySelector('[name="latitude"]');
    const longitude = picker.querySelector('[name="longitude"]');
    const manual = picker.querySelector('[data-map-manual]');
    const status = picker.querySelector('[data-map-status]');
    const choose = (ar, en) => document.documentElement.lang === 'en' ? en : ar;
    const valid = (lat, lng) => Number.isFinite(lat) && Number.isFinite(lng) && lat >= 29 && lat <= 33.5 && lng >= 34.5 && lng <= 39.5;
    const read = () => latitude.value !== '' && longitude.value !== '' && valid(Number(latitude.value), Number(longitude.value))
        ? [Number(latitude.value), Number(longitude.value)] : null;
    let selected = read();
    let centre = selected || [31.95, 35.91];
    let zoom = selected ? 15 : 8;
    let loaded = false;
    let drag = null;
    let generation = 0;
    const project = (lat, lng) => {
        const scale = 256 * 2 ** zoom;
        const sine = Math.sin(lat * Math.PI / 180);
        return [(lng + 180) / 360 * scale, (0.5 - Math.log((1 + sine) / (1 - sine)) / (4 * Math.PI)) * scale];
    };
    const unproject = (x, y) => {
        const scale = 256 * 2 ** zoom;
        return [Math.atan(Math.sinh(Math.PI * (1 - 2 * y / scale))) * 180 / Math.PI, x / scale * 360 - 180];
    };
    const message = () => {
        status.textContent = selected
            ? choose('الموقع المحدد: ', 'Selected location: ') + selected.map(value => value.toFixed(6)).join(', ')
            : choose('لم يُحدَّد موقع بعد. اختر مكان المشكلة على الخريطة.', 'No location selected yet. Select the issue on the map.');
    };
    const svg = (tag, attributes) => {
        const element = document.createElementNS('http://www.w3.org/2000/svg', tag);
        Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, String(value)));
        return element;
    };
    const render = () => {
        if (!loaded) { return; }
        generation += 1;
        const current = generation;
        const width = map.clientWidth;
        const height = map.clientHeight;
        const [cx, cy] = project(...centre);
        const left = cx - width / 2;
        const top = cy - height / 2;
        layer.setAttribute('viewBox', `0 0 ${width} ${height}`);
        layer.replaceChildren();
        for (let x = Math.floor(left / 256); x <= Math.floor((left + width) / 256); x += 1) {
            for (let y = Math.floor(top / 256); y <= Math.floor((top + height) / 256); y += 1) {
                if (x < 0 || y < 0 || x >= 2 ** zoom || y >= 2 ** zoom) { continue; }
                const tile = svg('image', {x: x * 256 - left, y: y * 256 - top, width: 256, height: 256});
                tile.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
                tile.addEventListener('error', () => {
                    if (current !== generation) { return; }
                    status.textContent = choose('تعذّر تحميل بعض أجزاء الخريطة. استخدم الإدخال اليدوي إن لم يظهر الموقع بوضوح.', 'Some map tiles failed to load. Use manual coordinates if the location is not clear.');
                    manual.open = true;
                });
                tile.setAttribute('href', `https://tile.openstreetmap.org/${zoom}/${x}/${y}.png`);
                layer.append(tile);
            }
        }
        if (selected) {
            const [px, py] = project(...selected);
            layer.append(svg('circle', {cx: px - left, cy: py - top, r: 9, fill: '#cb542a', stroke: '#fff', 'stroke-width': 3}));
        }
        picker.querySelectorAll('[data-map-zoom]').forEach(button => {
            button.disabled = Number(button.dataset.mapZoom) > 0 ? zoom >= 19 : zoom <= 7;
        });
    };
    const select = (point) => {
        if (!valid(...point)) {
            status.textContent = choose('اختر موقعاً ضمن نطاق الأردن المدعوم.', 'Choose a location within the supported Jordan bounds.');
            return;
        }
        selected = point.map(value => Number(value.toFixed(6)));
        latitude.value = selected[0].toFixed(6);
        longitude.value = selected[1].toFixed(6);
        [latitude, longitude].forEach(input => { input.removeAttribute('aria-invalid'); input.dispatchEvent(new Event('change', {bubbles: true})); });
        message();
        render();
    };
    const pan = (dx, dy) => {
        const [x, y] = project(...centre);
        const point = unproject(x + dx, y + dy);
        centre = [Math.max(29, Math.min(33.5, point[0])), Math.max(34.5, Math.min(39.5, point[1]))];
        render();
    };
    picker.querySelector('[data-map-controls]').hidden = false;
    manual.open = Boolean(latitude.getAttribute('aria-invalid') || longitude.getAttribute('aria-invalid'));
    [latitude, longitude].forEach(input => {
        input.addEventListener('invalid', () => { manual.open = true; });
        input.addEventListener('input', () => { selected = read(); if (selected) { centre = selected; } message(); render(); });
    });
    picker.querySelector('[data-map-load]').addEventListener('click', event => {
        if (loaded) { return; }
        loaded = true;
        map.hidden = false;
        picker.querySelector('[data-map-attribution]').hidden = false;
        picker.querySelector('[data-map-select]').disabled = false;
        event.currentTarget.hidden = true;
        message();
        render();
    });
    picker.querySelectorAll('[data-map-zoom]').forEach(button => button.addEventListener('click', () => {
        zoom = Math.max(7, Math.min(19, zoom + Number(button.dataset.mapZoom)));
        render();
    }));
    picker.querySelector('[data-map-select]').addEventListener('click', () => select(centre));
    const locationButton = picker.querySelector('[data-map-location]');
    if (locationButton && window.isSecureContext && navigator.geolocation) {
        locationButton.hidden = false;
        locationButton.addEventListener('click', () => {
            locationButton.disabled = true;
            status.textContent = choose('جارٍ تحديد الموقع بإذنك. استخدمه فقط إذا كنت عند مكان المشكلة.', 'Finding your location with permission. Use it only if you are at the issue.');
            navigator.geolocation.getCurrentPosition(position => {
                locationButton.disabled = false;
                const point = [position.coords.latitude, position.coords.longitude];
                if (valid(...point)) { centre = point; zoom = 15; }
                select(point);
            }, () => {
                locationButton.disabled = false;
                status.textContent = choose('تعذر الحصول على الموقع أو لم تسمح بمشاركته. اختر المكان على الخريطة أو أدخل الإحداثيات يدوياً.', 'Location is unavailable or permission was denied. Select the map location or enter coordinates manually.');
                if (!loaded) { manual.open = true; }
            }, {enableHighAccuracy: false, timeout: 15000, maximumAge: 0});
        });
    }
    map.addEventListener('pointerdown', event => {
        if (!event.isPrimary || event.button !== 0) { return; }
        drag = {id: event.pointerId, x: event.clientX, y: event.clientY};
        map.setPointerCapture(event.pointerId);
    });
    map.addEventListener('pointerup', event => {
        if (!drag || drag.id !== event.pointerId) { return; }
        const dx = event.clientX - drag.x;
        const dy = event.clientY - drag.y;
        drag = null;
        if (Math.hypot(dx, dy) > 6) { pan(-dx, -dy); return; }
        const bounds = map.getBoundingClientRect();
        const [cx, cy] = project(...centre);
        select(unproject(cx + event.clientX - bounds.left - map.clientWidth / 2, cy + event.clientY - bounds.top - map.clientHeight / 2));
    });
    map.addEventListener('pointercancel', () => { drag = null; });
    map.addEventListener('keydown', event => {
        const moves = {ArrowLeft: [-128, 0], ArrowRight: [128, 0], ArrowUp: [0, -128], ArrowDown: [0, 128]};
        if (Object.hasOwn(moves, event.key)) { event.preventDefault(); pan(...moves[event.key]); }
        if (event.key === 'Enter') { event.preventDefault(); select(centre); }
    });
    window.addEventListener('resize', render);
    document.addEventListener('moshkelti:language', message);
    message();
})();
