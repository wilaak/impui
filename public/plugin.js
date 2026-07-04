import { action } from './datastar.js';

const cfg = window.__imp;
if (!cfg || !cfg.control) {
    console.error('imp: window.__imp.control is required');
}

let token = sessionStorage.getItem('imp_sid');
if (!token) {
    token = crypto.randomUUID ? crypto.randomUUID() :
        'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
            const r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    sessionStorage.setItem('imp_sid', token);
}

let version = '';
let plan = { mode: 'poll', interval_ms: 1000 };
let timer = null;
let channel = null;
let inflight = false;

function controlUrl(extra = '') {
    const sep = cfg.control.includes('?') ? '&' : '?';
    return cfg.control + sep + 'session=' + token + extra;
}

function apply(text) {
    for (const block of text.split('\n\n')) {
        let type = null;
        const args = {};
        const elements = [];
        const script = [];

        for (const line of block.split('\n')) {
            if (line.startsWith('event: ')) type = line.slice(7);
            else if (line.startsWith('data: elements ')) elements.push(line.slice(15));
            else if (line.startsWith('data: script ')) script.push(line.slice(13));
            else if (line.startsWith('data: selector ')) args.selector = line.slice(15);
            else if (line.startsWith('data: mode ')) args.mode = line.slice(11);
        }

        if (type === 'datastar-patch-elements' && elements.length) {
            args.elements = elements.join('\n');
            document.dispatchEvent(new CustomEvent('datastar-fetch', {
                detail: { type: 'datastar-patch-elements', argsRaw: args },
            }));
        } else if (type === 'datastar-execute-script' && script.length) {
            new Function(script.join('\n'))();
        }
    }
}

async function pull(actions = null) {
    if (inflight && !actions) return;   // never queue idle polls
    inflight = true;
    try {
        const extra = (actions ? '&actions=' + encodeURIComponent(JSON.stringify(actions)) : '')
            + (version ? '&v=' + encodeURIComponent(version) : '');
        const res = await fetch(controlUrl(extra), { headers: { 'imp-version': version } });

        version = res.headers.get('imp-version') ?? version;
        const rawPlan = res.headers.get('imp-plan');
        if (rawPlan) obey(JSON.parse(rawPlan));

        if (res.status === 200) apply(await res.text());
    } catch (e) {
        // transient network failure: the interval will try again
    } finally {
        inflight = false;
    }
}

function obey(next) {
    if (plan.mode === next.mode && plan.interval_ms === next.interval_ms) return;
    plan = next;

    if (timer) clearInterval(timer);
    if (channel) { channel.close(); channel = null; }

    timer = setInterval(() => pull(), plan.interval_ms);

    if (plan.hold) {
        channel = new EventSource(controlUrl('&hold=' + plan.hold));
        if (plan.hold === 'watch') {
            // Poke channel: any message means "pull now".
            channel.onmessage = () => pull();
            channel.addEventListener('datastar-execute-script', () => pull());
        } else {
            // Payload channel: messages are patches.
            channel.addEventListener('datastar-patch-elements', e => {
                apply('event: datastar-patch-elements\n' +
                    e.data.split('\n').map(l => 'data: ' + l).join('\n'));
            });
            channel.addEventListener('datastar-execute-script', e => {
                new Function(e.data.split('\n')
                    .filter(l => l.startsWith('script '))
                    .map(l => l.slice(7)).join('\n'))();
            });
        }
        // A 409 or dead channel closes the EventSource; the interval
        // keeps us alive and the next reply re-plans us.
        channel.onerror = () => {};
    }
}

// imp('increment', {...}) from anywhere; @imp('increment') in datastar.
export function imp(name, params) {
    if (name === undefined) {
        console.error('imp: missing action name');
        return;
    }
    return pull([{ name, params: params ?? true }]);
}

action({
    name: 'imp',
    apply(ctx, name, params) {
        return imp(name, params);
    },
});

// Boot: one pull fetches the initial render and the first plan.
pull();