// Запросы к API. Здесь нет работы с DOM — только данные.

/**
 * Список камер.
 *
 * @param {Object} [filters] { brand, mount, type, sensor, limit, offset } — пустые значения пропускаются
 * @param {AbortSignal} [signal] чтобы отменить запрос (AbortController)
 * @returns {Promise<{data: Array<Object>, meta: {total: number, limit: number, offset: number}}>}
 * @throws {Error} с текстом ошибки от API (например, неверный параметр)
 */
export async function getCameras(filters = {}, signal) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters)) {
        if (value !== '' && value !== null && value !== undefined) {
            params.set(key, value);
        }
    }

    const response = await fetch(`/api/cameras?${params}`, { signal });
    const body = await response.json();

    if (!response.ok) {
        throw new Error(body.error?.message ?? `HTTP ${response.status}`);
    }

    return body;
}
