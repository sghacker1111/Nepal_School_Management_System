import {preparePageSubmit,readPageMetadata} from './page-lifecycle.js';
import {setMutationPending,markSaved,setMutationUncertain,mutationState,formSnapshot} from './dirty-forms.js';
import {navigate,isEnhancedURL,applyPageResponse,cancelNavigation} from './navigation.js';
let installed=null;
const submissions=new WeakSet();

export function canHandleForm(form,submitter) {
    if(!installed||!form?.matches?.('form')||!form.closest('#main-content')||form.closest('[data-untrusted-content],.exam-bank-rich'))return false;
    const targetName=submitter?.getAttribute('formtarget')||form.getAttribute('target');
    if(form.dataset.erpNative!==undefined||targetName&&targetName!=='_self')return false;
    if(form.getAttribute('hx-target')==='#questionBankPanel')return false;
    let url=new URL(submitter?.getAttribute('formaction')||form.getAttribute('action')||form.ownerDocument.defaultView.location.href,form.ownerDocument.defaultView.location.href).href;
    if((submitter?.getAttribute('formmethod')||form.getAttribute('method')||'get').toUpperCase()==='GET'){
        const target=new URL(url,form.ownerDocument.defaultView.location.href);target.search='';
        for(const[key,value]of new form.ownerDocument.defaultView.FormData(form))if(typeof value==='string')target.searchParams.append(key,value);
        if(submitter?.name&&!submitter.disabled)target.searchParams.append(submitter.name,submitter.value);url=target.href;
    }
    return (installed.deps.isEnhancedURL||isEnhancedURL)(url);
}

function errorNotice(form,message) {
    form.querySelector('[data-form-error]')?.remove();
    const notice=form.ownerDocument.createElement('div');notice.className='alert alert-danger';notice.dataset.formError='';notice.setAttribute('role','alert');notice.tabIndex=-1;notice.textContent=message;form.prepend(notice);notice.focus();
}
function responseInfo(response,doc) {
    if(response.header('X-ERP-Fragment')!=='app-page'||!/^text\/html(?:;|$)/i.test(response.header('Content-Type')||''))throw new Error('Unmarked save response.');
    const template=doc.createElement('template');template.innerHTML=response.text;
    const config=readPageMetadata(template.content);
    const error=template.content.querySelector('.alert-danger,[role="alert"] p');
    return{config,message:error?.textContent?.trim()||'The change could not be saved. Review the values and try again.'};
}

export async function submitForm(form,submitter) {
    if(!canHandleForm(form,submitter)||submissions.has(form))return false;
    const state=installed,{win,deps}=state,method=(submitter?.getAttribute('formmethod')||form.getAttribute('method')||'get').toUpperCase();
    if(method!=='GET'&&method!=='POST')return false;
    if(['pending','uncertain'].includes(mutationState(form)))return false;
    submissions.add(form);let pending=false,saved=false;const buttons=[];
    try{
        if(method==='POST'){(deps.cancelNavigation||cancelNavigation)();setMutationPending(form,true);pending=true;}
        await(deps.preparePageSubmit||preparePageSubmit)(form);
        if(!form.isConnected)return false;
        if(!form.noValidate&&!submitter?.formNoValidate&&!form.reportValidity())return false;
        const body=new win.FormData(form);
        if(submitter?.name&&!submitter.disabled)body.append(submitter.name,submitter.value);
        const url=new URL(submitter?.getAttribute('formaction')||form.getAttribute('action')||win.location.href,win.location.href);
        if(url.origin!==win.location.origin||!/^https?:$/.test(url.protocol))return false;
        if(method==='GET'){
            url.search='';for(const [key,value]of body)if(typeof value==='string')url.searchParams.append(key,value);
            return await(deps.navigate||navigate)(url.href,{focus:'retain'});
        }
        body.set('csrf_token',win.CSRF_TOKEN||'');
        const submitted=formSnapshot(form);
        const savedDraft=()=>{
            const changed=formSnapshot(form)!==submitted;markSaved(form,submitted);saved=true;pending=false;
            if(changed){errorNotice(form,'The submitted values were saved. Your newer edits are still here and remain unsaved; review them before saving again.');return false;}return true;
        };
        for(const button of form.querySelectorAll('button[type="submit"],button:not([type]),input[type="submit"]')){buttons.push([button,button.disabled]);button.disabled=true;}
        form.setAttribute('aria-busy','true');form.querySelector('[data-form-error]')?.remove();
        const request=deps.request||async function(path,init){const response=await win.fetch(path,init);return{status:response.status,text:await response.text(),header:name=>response.headers.get(name)};};
        // Deliberately no AbortSignal/timeout: an uncertain POST must never be retried.
        const response=await request(url.href,{method:'POST',body,headers:{'HX-Request':'true','HX-Target':'main-content','X-CSRF-Token':win.CSRF_TOKEN||'',Accept:'text/html'},credentials:'same-origin'});
        if(response.status===204&&response.header('HX-Location')){
            const location=JSON.parse(response.header('HX-Location')),destination=new URL(location.path,url);
            if(destination.origin!==win.location.origin||location.target!=='#main-content'||location.swap!=='innerHTML')throw new Error('Invalid save redirect.');
            if(!savedDraft())return true;win.schoolNotify?.('Saved successfully.','success');
            await(deps.navigate||navigate)(destination.href,{history:'replace'});return true;
        }
        if(response.status===401){
            setMutationPending(form,false);pending=false;
            errorNotice(form,'Your session has ended. Your draft is still here. Sign in again in another tab, then reload only after preserving your work.');
            const login=new URL(response.header('HX-Redirect')||((win.APP_URL||win.location.origin)+'/login'),win.location.href);
            if(login.origin===win.location.origin&&login.pathname.endsWith('/login')){const link=form.ownerDocument.createElement('a');link.href=login.href;link.target='_blank';link.rel='noopener';link.textContent=' Sign in in a new tab';form.querySelector('[data-form-error]').append(link);}return false;
        }
        const parsed=responseInfo(response,form.ownerDocument);
        let current;
        try{current=readPageMetadata(form.closest('#main-content'));}catch{}
        if(current?.contextVersion&&parsed.config.contextVersion&&current.contextVersion!==parsed.config.contextVersion){
            setMutationPending(form,false);pending=false;
            await(deps.applyPageResponse||applyPageResponse)(response,url.href,{history:'replace',focus:'retain'});return false;
        }
        if([403,404,419,422].includes(response.status)||parsed.config.mutation?.outcome==='invalid'){
            setMutationPending(form,false);pending=false;errorNotice(form,parsed.message);return false;
        }
        if(response.status!==200||!['saved','preview'].includes(parsed.config.mutation?.outcome))throw new Error('Save result is not confirmed.');
        if(parsed.config.mutation.outcome==='saved'){if(!savedDraft())return true;}
        else {
            setMutationPending(form,false);pending=false;
            if(formSnapshot(form)!==submitted){errorNotice(form,'The preview used the submitted values. Your newer edits remain here; preview again when ready.');return true;}
        }
        const updated=await(deps.applyPageResponse||applyPageResponse)(response,url.href,{history:'replace',focus:'retain'});
        // A preview is not a saved invoice/paper draft. Remounting must not
        // silently establish it as a clean baseline for navigation.
        if(updated&&parsed.config.mutation.outcome==='preview'&&!parsed.config.pageId?.startsWith('learning-'))form.ownerDocument.getElementById('main-content')?.dispatchEvent(new win.Event('erp:dirty',{bubbles:true}));
        return true;
    }catch(error){
        if(pending&&!saved){setMutationUncertain(form,form.dataset.statusUrl||form.action);pending=false;}
        else errorNotice(form,'The page could not be updated. No request was automatically resubmitted.');
        return false;
    }finally{
        if(pending)setMutationPending(form,false);
        form.removeAttribute('aria-busy');for(const [button,disabled]of buttons)button.disabled=mutationState(form)==='uncertain'||disabled;
        submissions.delete(form);
    }
}

export function installForms(htmx,dependencies={}) {
    const win=dependencies.window||window,doc=win.document,state={win,deps:dependencies,htmx};installed=state;
    const submit=event=>{if(!event.defaultPrevented&&canHandleForm(event.target,event.submitter)){event.preventDefault();event.stopImmediatePropagation();void submitForm(event.target,event.submitter);}};
    // Bubble phase leaves page-specific password/action confirmations in control.
    doc.addEventListener('submit',submit);
    return()=>{doc.removeEventListener('submit',submit);if(installed===state)installed=null;};
}
