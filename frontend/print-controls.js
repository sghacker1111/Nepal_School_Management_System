// Standalone print documents remain native documents, not app navigation pages.
import {createPageScope} from './pages/page-scope.js';
import issueControls from './pages/generated/shared-report-issue-controls.js';
const start=()=>{
 const marker=document.querySelector('[data-erp-print-config]');if(!marker)return;
 const config=JSON.parse(marker.getAttribute('data-erp-print-config'));
 const controller=new AbortController();
 const scope=createPageScope(document.body,{signal:controller.signal,config});scope.run(()=>issueControls(scope,config));
};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
