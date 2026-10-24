import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';
const source=await readFile(new URL('../public/assets/wojtex-appearance.js',import.meta.url),'utf8');
function browser(saved={},blocked=false){const data=new Map(Object.entries(saved)),listeners={},media={matches:false,addEventListener:(_,fn)=>media.change=fn};const root={dataset:{}},meta={};const context={window:{matchMedia:()=>media,addEventListener:(name,fn)=>listeners[name]=fn},document:{documentElement:root,querySelector:s=>s.startsWith('meta')?meta:null},localStorage:{getItem:k=>{if(blocked)throw Error('blocked');return data.get(k)??null;},setItem:(k,v)=>{if(blocked)throw Error('blocked');data.set(k,v);},removeItem:k=>data.delete(k)},FormData:class{constructor(values){return Object.entries(values);}}};vm.runInNewContext(source,context);return{api:context.window.WojtexAppearance,data,root,media,listeners,meta};}
let b=browser();b.api.apply({theme:'midnight',accent:'cyan'});assert.equal(b.root.dataset.theme,'midnight');assert.equal(b.root.dataset.accent,'cyan');
b.api.update({theme:'light',accent:'rose',density:'compact'});b=browser(Object.fromEntries(b.data));b.api.apply({theme:'dark',accent:'blue'});assert.equal(b.root.dataset.theme,'light');assert.equal(b.root.dataset.accent,'rose');assert.equal(b.root.dataset.density,'compact');
b.api.update({theme:'system',accent:'default'});assert.equal(b.root.dataset.theme,'light');b.media.matches=true;b.media.change();assert.equal(b.root.dataset.theme,'dark');assert.equal(b.root.dataset.accent,'blue');
b.api.reset();assert.equal(b.root.dataset.accent,'blue');assert.equal(b.root.dataset.density,'comfortable');
b=browser({'wojtex.appearance.v1':'{"theme":"bad","accent":"bad","density":"bad"}'});b.api.apply({theme:'light',accent:'green'});assert.equal(b.root.dataset.theme,'light');assert.equal(b.root.dataset.accent,'green');
b=browser({'wojtex.appearance.v1':'broken'});assert.equal(b.root.dataset.theme,'dark');
b=browser({},true);assert.equal(b.api.update({theme:'midnight',accent:'blue'}),false);assert.equal(b.root.dataset.theme,'midnight');
b=browser({'veyra.theme':'light'});assert.equal(b.root.dataset.theme,'light');b.api.reset();assert.equal(browser(Object.fromEntries(b.data)).root.dataset.theme,'dark');
b=browser();b.data.set('wojtex.appearance.v1',JSON.stringify({theme:'midnight',accent:'amber',density:'compact'}));b.listeners.storage({key:'wojtex.appearance.v1'});assert.equal(b.root.dataset.theme,'midnight');assert.equal(b.root.dataset.accent,'amber');
console.log('PASS: defaults, reload persistence, system change, reset, invalid data, blocked storage, legacy migration, cross-tab changes');
