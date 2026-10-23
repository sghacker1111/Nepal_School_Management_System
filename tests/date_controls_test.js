'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const makeInput=(name,paired=false,dataset={})=>({
  name,dataset,form:{querySelector:selector=>paired?{}:selector==='[name="'+name+'"]'?{}:null},
  after(element){this.calendar=element;}
});
const inputs=[makeInput('subscription_start'),makeInput('subscription_end'),makeInput('start_date'),makeInput('dob_ad',true),makeInput('date_ad',false,{dateAd:'paired'}),makeInput('unknown')];
const context={window:{},document:{
  addEventListener:(_,callback)=>callback(),
  querySelectorAll:selector=>selector==='input[type="date"]'?inputs:[],
  createElement:()=>({options:[],setAttribute(){},addEventListener(){},append(option){this.options.push(option);}})
}};
vm.runInNewContext(fs.readFileSync(require.resolve('../assets/js/school-dates.js'),'utf8'),context);
for(const input of inputs.slice(0,3)){
  assert.equal(input.calendar.name,'calendar_fields['+input.name+']');
  assert.deepEqual(Array.from(input.calendar.options,option=>option.value),['AD','BS']);
}
for(const input of inputs.slice(3))assert.equal(input.calendar,undefined);
console.log('Date calendar controls passed: standalone AD/BS selectors, paired controls and unrelated fields.');

const fields=Object.fromEntries(['modalSubscriptionMode','modalSubscriptionStart','modalSubscriptionEnd','modalSubscriptionEnabled','subscriptionStartControl','subscriptionEndControl'].map(id=>[id,{value:'',parentElement:{querySelector:()=>null}}]));
const subscriptionContext={document:{getElementById:id=>fields[id]},window:{},Date,Event:function(){}};
const schoolScript=fs.readFileSync(require.resolve('../frontend/pages/generated/super-admin-schools.js'),'utf8').split('let schoolModalObj;')[1].split('$(document).ready')[0].replace(/^const schoolToday=.*$/m,"const schoolToday='2026-10-10';");
vm.runInNewContext(schoolScript,subscriptionContext);
fields.modalSubscriptionMode.value='yearly';fields.modalSubscriptionStart.value='2026-10-10';
subscriptionContext.updateSubscriptionDates();assert.equal(fields.modalSubscriptionEnd.value,'2027-10-09');assert.equal(fields.modalSubscriptionEnd.readOnly,true);
subscriptionContext.renewSubscription();assert.equal(fields.modalSubscriptionStart.value,'2026-10-10');assert.equal(fields.modalSubscriptionEnd.value,'2028-10-09');assert.equal(fields.modalSubscriptionMode.value,'custom');assert.equal(fields.modalSubscriptionEnabled.checked,true);
fields.modalSubscriptionMode.value='yearly';fields.modalSubscriptionStart.value='2027-01-01';subscriptionContext.updateSubscriptionDates();subscriptionContext.renewSubscription();
assert.equal(fields.modalSubscriptionStart.value,'2027-01-01','A scheduled subscription must not become active early');assert.equal(fields.modalSubscriptionEnd.value,'2028-12-31');
fields.modalSubscriptionStart.value='2020-01-01';fields.modalSubscriptionEnd.value='2020-12-31';subscriptionContext.renewSubscription();
assert.equal(fields.modalSubscriptionStart.value,'2026-10-10');assert.equal(fields.modalSubscriptionEnd.value,'2027-10-09');assert.equal(fields.modalSubscriptionMode.value,'yearly');
assert.equal(subscriptionContext.subscriptionYearEnd('2024-02-29'),'2025-02-27');
fields.modalSubscriptionMode.value='no_expiry';subscriptionContext.updateSubscriptionDates();assert.equal(fields.subscriptionStartControl.hidden,true);assert.equal(fields.modalSubscriptionStart.required,false);
console.log('Subscription UI passed: yearly/leap dates, uninterrupted renewals, scheduled starts and expired-account renewal.');
