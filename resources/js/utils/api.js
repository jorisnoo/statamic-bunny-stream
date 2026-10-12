function headers(extra = {}) {
    return {
        Accept: 'application/json',
        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
        ...extra,
    };
}

async function handle(response) {
    if (!response.ok) {
        // Surface the server's message (e.g. validation or "remote deletion is disabled").
        let message = `Request failed with status ${response.status}`;
        try {
            const body = await response.json();
            message = Object.values(body.errors ?? {})[0]?.[0] || body.message || message;
        } catch {}
        throw new Error(message);
    }

    return response.status === 204 ? null : response.json();
}

export function get(url) {
    return fetch(url, { headers: headers() }).then(handle);
}

export function post(url, data) {
    return fetch(url, {
        method: 'POST',
        headers: headers({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(data),
    }).then(handle);
}

export function patch(url, data) {
    return fetch(url, {
        method: 'PATCH',
        headers: headers({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(data),
    }).then(handle);
}

export function destroy(url) {
    return fetch(url, { method: 'DELETE', headers: headers() }).then(handle);
}

export function upload(url, files) {
    const body = new FormData();

    Object.entries(files).forEach(([key, file]) => body.append(key, file));

    return fetch(url, { method: 'POST', headers: headers(), body }).then(handle);
}
