// Mechanically extracted from modules/super_admin/schools.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;

let schoolModalObj;
const schoolToday=JSON.parse(config.values["super-admin-schools-value-0"]||'null');
function subscriptionDateAd(field){const selector=field.parentElement.querySelector('[name^="calendar_fields"]');return window.schoolConvertDate?schoolConvertDate(field.value,selector?.value||'AD','AD'):field.value;}
function subscriptionYearEnd(start){const [y,m,d]=start.split('-').map(Number);const last=new Date(Date.UTC(y+1,m,0)).getUTCDate();const expiry=new Date(Date.UTC(y+1,m-1,Math.min(d,last)));expiry.setUTCDate(expiry.getUTCDate()-1);return expiry.toISOString().slice(0,10);}
function updateSubscriptionDates() {
    const mode=document.getElementById('modalSubscriptionMode').value;
    const start=document.getElementById('modalSubscriptionStart');const end=document.getElementById('modalSubscriptionEnd');
    document.getElementById('subscriptionStartControl').hidden=mode==='no_expiry';document.getElementById('subscriptionEndControl').hidden=mode==='no_expiry';
    start.required=mode!=='no_expiry';end.required=mode==='custom';end.readOnly=mode==='yearly';
    if(mode==='yearly'&&start.value){try{const ad=subscriptionYearEnd(subscriptionDateAd(start));const selector=end.parentElement.querySelector('[name^="calendar_fields"]');end.value=window.schoolConvertDate?schoolConvertDate(ad,'AD',selector?.value||'AD'):ad;}catch(e){end.value='';}}
}
function resetSubscriptionCalendars(){['modalSubscriptionStart','modalSubscriptionEnd'].forEach(id=>{const field=document.getElementById(id);const select=field.parentElement.querySelector('[name^="calendar_fields"]');if(select){select.value='AD';select.dispatchEvent(new Event('change'));}});}
function renewSubscription(){
    resetSubscriptionCalendars();const start=document.getElementById('modalSubscriptionStart');const end=document.getElementById('modalSubscriptionEnd');
    if(end.value&&end.value>=schoolToday){const next=new Date(end.value+'T00:00:00Z');next.setUTCDate(next.getUTCDate()+1);document.getElementById('modalSubscriptionMode').value='custom';end.value=subscriptionYearEnd(next.toISOString().slice(0,10));if(!start.value)start.value=schoolToday;}
    else{document.getElementById('modalSubscriptionMode').value='yearly';start.value=schoolToday;}
    document.getElementById('modalSubscriptionEnabled').checked=true;updateSubscriptionDates();
}

$(document).ready(function() {
    schoolModalObj = new ErpUI.Modal(document.getElementById('schoolModal'), {
        focus: false
    });
    const recovery=JSON.parse(config.values["super-admin-schools-value-1"]||'null');
    if(recovery){
        showAddModal();
        const form=document.getElementById('schoolForm');
        Object.entries(recovery).forEach(([key,value])=>{const field=form.elements.namedItem(key);if(field&&typeof value==='string')field.value=value;});
        document.getElementById('modalSchoolId').value=recovery.school_id;
        document.querySelectorAll('.school-package').forEach(box=>box.checked=recovery.packages.includes(box.value));
        document.getElementById('modalSubscriptionEnabled').checked=recovery.subscription_enabled;
        if(recovery.action==='edit'){document.getElementById('modalTitle').innerText='Edit School Details';document.getElementById('modalAdminPassword').required=false;document.getElementById('adminPasswordLabel').innerText='Administrator Password (leave empty to keep current)';}
        updateSubscriptionDates();
    }

    $('#schoolForm').on('submit', function(e) {
        const form = this;
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            form.classList.add('was-validated');
            return;
        }

        const action = document.getElementById('modalAction').value;
        if (action === 'edit') {
            e.preventDefault();
            e.stopPropagation();

            verifyPasswordAndProceed(function() {
                form.submit();
            }, 'Confirm School Edit', 'Please enter your administrator password to save the school details:');
        }
    });
});

function showAddModal() {
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalSchoolId').value = '';
    document.getElementById('modalTitle').innerText = 'Register New School';
    document.getElementById('modalNameEn').value = '';
    document.getElementById('modalNameNp').value = '';
    document.getElementById('modalEmisCode').value = '';
    document.getElementById('modalAcademicYear').value = '2081/2025';
    document.getElementById('modalPhone').value = '';
    document.getElementById('modalEmail').value = '';
    document.getElementById('modalAddress').value = '';

    document.getElementById('modalPrincipalName').value = '';
    document.getElementById('modalPrincipalMobile').value = '';
    document.getElementById('modalTechnicalPerson').value = '';

    resetSubscriptionCalendars();document.querySelectorAll('.school-package').forEach(box=>box.checked=box.value==='full');
    document.getElementById('modalSubscriptionMode').value='yearly';document.getElementById('modalSubscriptionStart').value=schoolToday;document.getElementById('modalSubscriptionEnabled').checked=true;updateSubscriptionDates();
    document.getElementById('modalAdminFullName').value = '';
    document.getElementById('modalAdminUsername').value = '';
    document.getElementById('modalAdminPassword').value = '';
    document.getElementById('modalAdminPassword').setAttribute('required', 'required');
    document.getElementById('adminPasswordLabel').innerText = 'Administrator Password *';
    schoolModalObj.show();
}

function editSchool(s) {
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalSchoolId').value = s.id;
    document.getElementById('modalTitle').innerText = 'Edit School Details';
    document.getElementById('modalNameEn').value = s.name_en;
    document.getElementById('modalNameNp').value = s.name_np || '';
    document.getElementById('modalEmisCode').value = s.emis_code || '';
    document.getElementById('modalAcademicYear').value = s.academic_year_current || '2081/2025';
    document.getElementById('modalPhone').value = s.phone || '';
    document.getElementById('modalEmail').value = s.email || '';
    document.getElementById('modalAddress').value = s.address || '';

    document.getElementById('modalPrincipalName').value = s.principal_name || '';
    document.getElementById('modalPrincipalMobile').value = s.principal_mobile || '';
    document.getElementById('modalTechnicalPerson').value = s.technical_person || '';

    resetSubscriptionCalendars();let packages=[];try{packages=s.packages_json===null?[s.subscription_type]:JSON.parse(s.packages_json);if(!Array.isArray(packages))packages=[];}catch(e){}document.querySelectorAll('.school-package').forEach(box=>box.checked=packages.includes(box.value));
    document.getElementById('modalSubscriptionMode').value=s.subscription_mode||'no_expiry';document.getElementById('modalSubscriptionStart').value=s.subscription_start||schoolToday;document.getElementById('modalSubscriptionEnd').value=s.subscription_end||'';document.getElementById('modalSubscriptionEnabled').checked=Number(s.subscription_enabled)!==0;updateSubscriptionDates();
    document.getElementById('modalAdminFullName').value = s.admin_full_name || '';
    document.getElementById('modalAdminUsername').value = s.admin_username || '';
    document.getElementById('modalAdminPassword').value = '';
    document.getElementById('modalAdminPassword').removeAttribute('required');
    document.getElementById('adminPasswordLabel').innerText = 'Administrator Password (leave empty to keep current)';
    schoolModalObj.show();
}


scope.bindActions({"super-admin-schools-action-0":function(event,args){showAddModal()},
"super-admin-schools-action-1":function(event,args){editSchool(JSON.parse(args[0]||'null'))},
"super-admin-schools-action-2":function(event,args){updateSubscriptionDates()},
"super-admin-schools-action-3":function(event,args){updateSubscriptionDates()},
"super-admin-schools-action-4":function(event,args){renewSubscription()}});
}
