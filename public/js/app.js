'use strict';

// Список камер с фильтрами. Весь вывод — через textContent (без innerHTML),
// поэтому данные из БД не могут внедрить HTML/JS.

const BODY_TYPES = { mirrorless: 'Беззеркальная', dslr: 'Зеркальная', compact: 'Компакт' };
const VIDEO = { fhd: 'Full HD', '4k': '4K', '6k': '6K', '8k': '8K' };

const form = document.getElementById('filters');
const list = document.getElementById('cameras');
const statusEl = document.getElementById('status');

let currentRequest = null;

async function loadCameras() {
    // Отменяем предыдущий запрос, если фильтр сменили быстрее, чем пришёл ответ
    currentRequest?.abort();
    currentRequest = new AbortController();

    const params = new URLSearchParams();
    for (const [key, value] of new FormData(form)) {
        if (value !== '') params.set(key, value);
    }

    setStatus('Загрузка…');
    try {
        const response = await fetch(`/api/cameras?${params}`, { signal: currentRequest.signal });
        const body = await response.json();
        if (!response.ok) {
            throw new Error(body.error?.message ?? `HTTP ${response.status}`);
        }
        render(body.data);
        setStatus(`Найдено: ${body.meta.total}`);
    } catch (error) {
        if (error.name === 'AbortError') return;
        list.replaceChildren();
        setStatus(`Ошибка: ${error.message}`, true);
    }
}

function render(cameras) {
    list.replaceChildren(...cameras.map(renderCard));
}

function renderCard(camera) {
    const li = el('li', 'card');
    li.append(
        el('h2', null, camera.model),
        el('p', 'brand', camera.brand.name),
    );

    const specs = [
        ['Тип', BODY_TYPES[camera.body_type] ?? camera.body_type],
        ['Байонет', camera.mount?.name ?? 'несъёмный объектив'],
        ['Матрица', camera.sensor_format.name],
        ['Мегапиксели', camera.megapixels],
        ['Стабилизация', camera.ibis === null ? null : (camera.ibis ? `да${camera.ibis_stops ? `, ${camera.ibis_stops} ст.` : ''}` : 'нет')],
        ['Видео', camera.video_max_resolution ? `${VIDEO[camera.video_max_resolution]} ${camera.video_max_fps ?? ''}p`.trim() : null],
        ['Вес', camera.weight_g ? `${camera.weight_g} г` : null],
        ['Цена', camera.price_usd ? `$${camera.price_usd.toLocaleString('en-US')}` : null],
        ['Год', camera.release_year],
    ];

    const dl = el('dl');
    for (const [label, value] of specs) {
        if (value === null || value === undefined) continue;
        dl.append(el('dt', null, label), el('dd', null, String(value)));
    }
    li.append(dl);
    return li;
}

function el(tag, className = null, text = null) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== null) node.textContent = text;
    return node;
}

function setStatus(text, isError = false) {
    statusEl.textContent = text;
    statusEl.classList.toggle('error', isError);
}

form.addEventListener('change', loadCameras);
loadCameras();
