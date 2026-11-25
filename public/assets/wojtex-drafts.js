(() => {
    'use strict';

    function keyFor(user, threadId = null) {
        if (!Number.isSafeInteger(user?.id) || user.id < 1) return null;
        if (threadId !== null && (!Number.isSafeInteger(threadId) || threadId < 1)) return null;
        const account = `${user.id}.${encodeURIComponent(user.created_at || '')}`;
        return `wojtex.draft.v1.${account}.${threadId === null ? 'topic' : `reply.${threadId}`}`;
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
                body: draft.body.slice(0, 50000),
                title: typeof draft.title === 'string' ? draft.title.slice(0, 180) : '',
                categoryId: Number.isSafeInteger(draft.categoryId) ? draft.categoryId : null
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

    window.WojtexDrafts = {keyFor, read, write};
})();
