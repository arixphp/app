// Development only. No subscription exists without server-provided metadata.
const metadata = document.querySelector('meta[name="frankenphp-hot-reload:url"]');
const subscriptionKey = Symbol.for('arix.dev.reload');

if (metadata?.content && !globalThis[subscriptionKey]) {
    let hub;
    try {
        hub = new URL(metadata.content, location.href);
    } catch {
        hub = null;
    }

    if (hub?.origin === location.origin && typeof EventSource === 'function') {
        const subscription = new EventSource(hub.href);
        globalThis[subscriptionKey] = subscription;
        let timer = null;

        subscription.onmessage = ({ data }) => {
            let changes;
            try {
                changes = JSON.parse(data);
            } catch {
                return;
            }

            if (!Array.isArray(changes) || !changes.some(change => change?.path_name || change?.associated_path_name)) {
                return;
            }

            clearTimeout(timer);
            timer = setTimeout(() => location.reload(), 100);
        };
    }
}
