import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const script = readFileSync(new URL('../public/assets/wojtex-drafts.js', import.meta.url), 'utf8');
const items = new Map();
let blocked = false;
const localStorage = {
    getItem: key => items.get(key) ?? null,
    setItem: (key, value) => {
        if (blocked) throw new Error('Storage quota exceeded');
        items.set(key, value);
    },
    removeItem: key => items.delete(key)
};
function browser(storage) {
    const window = {};
    vm.runInNewContext(script, {window, localStorage: storage});
    return window.WojtexDrafts;
}

const firstTab = browser(localStorage);
const secondTab = browser(localStorage);
const account = {id: 4, created_at: '2026-10-24 14:30:00'};
const key = firstTab.keyFor(account);
assert.equal(firstTab.keyFor(null), null);
assert.equal(firstTab.keyFor(account, 0), null);
assert.notEqual(firstTab.keyFor({...account, id: 5}), key);
assert.notEqual(firstTab.keyFor({...account, created_at: '2026-11-25 12:00:00'}), key);
assert.notEqual(firstTab.keyFor(account, 1), firstTab.keyFor(account, 2));
assert.notEqual(firstTab.keyFor(account, 1), key);

const original = {title: 'A draft about the forum', body: 'Some Markdown **text**.', categoryId: 2};
const originalSave = firstTab.write(key, original, null);
assert.equal(originalSave.status, 'saved');
assert.equal(JSON.stringify(secondTab.read(key).draft), JSON.stringify({body: original.body, title: original.title, categoryId: 2}));
assert.equal(secondTab.write(key, {...original, body: 'An edit from the second tab'}, originalSave.raw).status, 'saved');
assert.equal(firstTab.write(key, original, originalSave.raw).status, 'conflict');
assert.equal(firstTab.write(key, null, originalSave.raw).status, 'conflict');
assert.equal(secondTab.read(key).draft.body, 'An edit from the second tab');
const overwrite = firstTab.write(key, original, originalSave.raw, true);
assert.equal(overwrite.status, 'saved');
assert.equal(firstTab.write(key, null, overwrite.raw).status, 'saved');
assert.equal(secondTab.read(key).draft, null);

items.set(key, '{truncated');
assert.equal(firstTab.read(key).draft, null);
items.set(key, JSON.stringify({body: 'x'.repeat(51000), title: 'y'.repeat(190), categoryId: '3'}));
assert.equal(firstTab.read(key).draft.body.length, 50000);
assert.equal(firstTab.read(key).draft.title.length, 180);
assert.equal(firstTab.read(key).draft.categoryId, null);
blocked = true;
assert.equal(firstTab.write(key, original, firstTab.read(key).raw).status, 'unavailable');
const withoutStorage = browser({getItem() { throw new Error('Access denied'); }});
assert.equal(withoutStorage.read(key).draft, null);
assert.equal(withoutStorage.write(key, original, null).status, 'unavailable');

console.log('PASS: draft restoration, account and reply isolation, cross-tab conflict, conditional removal, explicit overwrite, malformed data and unavailable storage');
