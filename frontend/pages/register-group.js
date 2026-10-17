import {registerPage} from '../app/page-lifecycle.js';
import {createPageScope} from './page-scope.js';
import modules from './generated-registry.js';
export function registerGroup(ids,initialize){
 for(const id of ids)registerPage(id,(root,context)=>{
  const config={values:{},modules:[],...context.config};const scope=createPageScope(root,context);
  scope.run(()=>{
   const list=[...config.modules].sort((a,b)=>Number(b.startsWith('shared-'))-Number(a.startsWith('shared-')));
   // A wrapper controller (system users) renders the shared settings editor.
   if(!list.length&&modules[id])list.push(id);
   for(const key of list){if(!Object.hasOwn(modules,key))throw new Error('Missing bundled page module: '+key);modules[key](scope,config);}
   if(id==='classes-routine')scope.window.initRoutineControls?.(root,context);
   initialize?.(root,context,scope);
  });
  return()=>scope.dispose();
 });
}
