import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { JSDOM } from 'jsdom';
function fixture(html) {
    const dom = new JSDOM(html, { runScripts:'outside-only', url:'https://school.example.test/' });
    dom.window.eval(readFileSync('assets/js/ui-components.js','utf8'));
    return dom;
}
test('compiled public assets match the cache-busting manifest',()=>{
    const manifest=JSON.parse(readFileSync('assets/dist/manifest.json','utf8'));
    assert(Object.keys(manifest).length>=6);
    for(const [name,hash] of Object.entries(manifest)) {
        assert(/^[a-z][a-z0-9.-]*$/.test(name));
        assert.equal(createHash('sha256').update(readFileSync('assets/dist/'+name)).digest('hex').slice(0,12),hash,name);
    }
});
test('native modal opens, focuses, traps focus, emits editor events and restores focus', () => {
    const dom = fixture('<button id="opener" data-ui-toggle="modal" data-ui-target="#modal">Open</button><div id="modal" class="modal" aria-hidden="true"><input type="hidden"><input id="first"><button id="last" data-ui-dismiss="modal">Close</button></div>');
    const { document, KeyboardEvent } = dom.window; const modal = document.getElementById('modal');
    const events=[]; for (const name of ['show','shown','hide','hidden']) modal.addEventListener(`${name}.erp.modal`, () => events.push(name));
    document.getElementById('opener').focus(); document.getElementById('opener').click();
    assert.equal(document.activeElement.id,'first'); assert.equal(modal.getAttribute('aria-modal'),'true');
    document.getElementById('last').focus(); document.dispatchEvent(new KeyboardEvent('keydown',{key:'Tab',bubbles:true,cancelable:true}));
    assert.equal(document.activeElement.id,'first');
    document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
    assert.equal(document.activeElement.id,'opener'); assert(!modal.classList.contains('show'));
    assert(!document.querySelector('.modal-backdrop')); assert.deepEqual(events,['show','shown','hide','hidden']); dom.window.close();
});
test('collapse accordion loads topics through cancellable events and updates aria', () => {
    const dom=fixture('<div id="accordion"><button id="one" data-ui-toggle="collapse" data-ui-target="#a">A</button><div id="a" class="collapse" data-ui-parent="#accordion"></div><button id="two" data-ui-toggle="collapse" data-ui-target="#b">B</button><div id="b" class="collapse" data-ui-parent="#accordion"></div></div>');
    const d=dom.window.document; let loaded=0; d.getElementById('a').addEventListener('show.erp.collapse',()=>loaded++);
    d.getElementById('one').click(); assert.equal(loaded,1); assert.equal(d.getElementById('one').getAttribute('aria-expanded'),'true');
    d.getElementById('two').click(); assert(!d.getElementById('a').classList.contains('show')); assert(d.getElementById('b').classList.contains('show')); dom.window.close();
});
test('dropdowns, tabs and alert dismissal work without Bootstrap', () => {
    const dom=fixture('<div class="dropdown"><button id="menu" data-ui-toggle="dropdown">Menu</button><div class="dropdown-menu">Content</div></div><nav class="nav"><button id="tabA" class="active" data-ui-toggle="tab" data-ui-target="#a"></button><button id="tabB" data-ui-toggle="tab" data-ui-target="#b"></button></nav><div class="tab-content"><div id="a" class="tab-pane active"></div><div id="b" class="tab-pane"></div></div><div class="alert"><button id="close" data-ui-dismiss="alert"></button></div>');
    const d=dom.window.document; d.getElementById('menu').click(); assert(d.querySelector('.dropdown-menu').classList.contains('show'));
    d.getElementById('tabB').click(); assert(!d.querySelector('.dropdown-menu').classList.contains('show')); assert(d.getElementById('b').classList.contains('active')); assert(!d.getElementById('a').classList.contains('active'));
    d.getElementById('close').click(); assert(!d.querySelector('.alert')); assert.equal(dom.window.bootstrap,undefined); dom.window.close();
});
test('read-only status notices do not falsely announce a saved record',()=>{
    const dom=fixture('<div class="alert alert-success">Receivable reconciliation: balanced.</div>');
    dom.window.fetch=async()=>({ok:true,headers:{get:()=>null}});
    dom.window.eval(readFileSync('assets/js/notifications.js','utf8'));
    dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
    assert.equal(dom.window.document.querySelector('#school-notifications strong').textContent,'Status update');
    dom.window.schoolNotify('Question saved successfully.');
    assert.equal(dom.window.document.querySelector('#school-notifications>div:last-child strong').textContent,'Saved successfully');dom.window.close();
});

test('hidden archive confirmations and unopened dialogs do not emit warning toasts',()=>{
    const dom=fixture('<div hidden><p class="alert alert-warning">Enter your password to archive.</p></div><div class="modal"><p class="alert alert-danger">Dialog only</p></div>');
    dom.window.fetch=async()=>({ok:true,headers:{get:()=>null}});dom.window.eval(readFileSync('assets/js/notifications.js','utf8'));
    dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
    assert.equal(dom.window.document.querySelector('#school-notifications'),null);dom.window.close();
});
test('bundled table vendor initializes one matching version without a CDN',()=>{
    const dom=fixture('<table id="sample"><thead><tr><th>Name</th></tr></thead><tbody><tr><td>Nepal</td></tr></tbody></table>');
    dom.window.eval(readFileSync('assets/dist/vendor.js','utf8'));
    const $=dom.window.jQuery;assert.equal($.fn.dataTable.version,'1.13.11');
    $('#sample').DataTable({paging:false,searching:false});assert(dom.window.document.querySelector('.dataTables_wrapper'));assert(dom.window.Swal);dom.window.close();
});
