(() => {
    'use strict';

    function keyFor(user, targetId = null, kind = 'reply') {
        if (!Number.isSafeInteger(user?.id) || user.id < 1) return null;
        if (kind !== 'reply' && kind !== 'edit') return null;
        if ((targetId !== null || kind === 'edit') && (!Number.isSafeInteger(targetId) || targetId < 1)) return null;
        const account = `${user.id}.${encodeURIComponent(user.created_at || '')}`;
        return `wojtex.draft.v1.${account}.${targetId === null ? 'topic' : `${kind}.${targetId}`}`;
    }

    function revisionFor(body) {
        let hash = 2166136261;
        for (let index = 0; index < body.length; index++) {
            hash = Math.imul(hash ^ body.charCodeAt(index), 16777619);
        }
        return `${body.length}.${hash >>> 0}`;
    }

    function read(key) {
        let raw;
        try {
            raw = localStorage.getItem(key);
        } catch (_) {
            return {raw: null, draft: null};
        }

        try {
            const draft = JSON.parse(raw);
            if (!draft || typeof draft.body !== 'string') return {raw, draft: null};
            return {raw, draft: {
                body: draft.body.length > 50000 ? Array.from(draft.body).slice(0, 50000).join('') : draft.body,
                title: typeof draft.title === 'string' ? draft.title.slice(0, 180) : '',
                categoryId: Number.isSafeInteger(draft.categoryId) ? draft.categoryId : null,
                ...(typeof draft.baseline === 'string' ? {baseline: draft.baseline.slice(0, 32)} : {}),
            }};
        } catch (_) {
            return {raw, draft: null};
        }
    }

    function write(key, draft, expected, overwrite = false) {
        try {
            const previous = localStorage.getItem(key);
            if (!overwrite && previous !== expected) return {status: 'conflict'};
            const raw = draft ? JSON.stringify(draft) : null;
            if (raw !== previous) {
                if (raw === null) localStorage.removeItem(key);
                else localStorage.setItem(key, raw);
            }
            return {status: 'saved', raw};
        } catch (_) {
            return {status: 'unavailable'};
        }
    }

    window.WojtexDrafts = {keyFor, revisionFor, read, write};
})();
