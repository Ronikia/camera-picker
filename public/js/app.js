import { getCameras } from './api.js';

//Работа с DOM.
//Формат данных и параметры API — docs/development.md.
//Готовые стили: .card, .brand, .status, .status.error (css/app.css).

const filters = document.getElementById('filters');
const status = document.getElementById('status');
const cameras = document.getElementById('cameras');
const nextCamersBtn = document.getElementById('nextCamersBtn');

const PAGE_SIZE = 10;

//Создание елемента

const createEl = (teg, className = '', text = '') => {
    const retTeg = document.createElement(teg);
    if(className !== ''){
        retTeg.classList.add(className);
    }
    retTeg.textContent = text;

    return retTeg;
};

//Рендер описания

const renderSpecs = (camera) => {
    const dl = createEl('dl');
    const rows = [
        ['Мегапиксели', camera.megapixels],
        ['Байонет', camera.mount?.name],
        ['Матрица', camera.sensor_format.name],
        ['Вес', camera.weight_g != null ? `${camera.weight_g} г` : null],
        ['Цена', camera.price_usd != null ? `$${camera.price_usd}` : null],
        ['Год', camera.release_year]
    ];
    for(const [key, value] of rows) {
        if (value == null) {
            continue;
        }
        const dt = createEl('dt','',key);
        const dd = createEl('dd','',value);
        dl.append(dt, dd);
    }

    return dl;
};

//Рендер карточки

const renderCard = (camera) => {
    const li = createEl('li','card');
    const h2 = createEl('h2','',camera.model);
    const p = createEl('p','brand',camera.brand.name);
    const dl = renderSpecs(camera);
    li.append(h2, p, dl);

    return li;
};

//Выгрузка камер

const loadCameras = async (append = false) => {
    const params = Object.fromEntries(new FormData(filters));
    params.limit = PAGE_SIZE;
    if (append === true) params.offset = cameras.children.length; 
    status.classList.remove('error');
    status.textContent = 'Загрузка, подождите';
    let responseCameras;
    try {
        responseCameras = await getCameras(params);
    } catch (error) {
        status.textContent = error.message;
        status.classList.add('error');
        return;
    }
    if (append !== true) cameras.replaceChildren();
    for (const dataCamera of responseCameras.data) {
        cameras.append(renderCard(dataCamera));
    }
    status.textContent = `Показанно: ${cameras.children.length} из ${responseCameras.meta.total}`;

    nextCamersBtn.hidden = (cameras.children.length >= responseCameras.meta.total);
};

//Обработчик смены фильтра

filters.addEventListener("change", () => {
    loadCameras();
});

//Обработчик нажатии на кнопку nextCamersBtn для загрузки новых камер

nextCamersBtn.addEventListener("click", () => {
    loadCameras(true);
});

loadCameras();

