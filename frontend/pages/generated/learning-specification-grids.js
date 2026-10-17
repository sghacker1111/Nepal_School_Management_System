// Mechanically extracted from modules/learning/specification_grids.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI,tinymce,MathJax,pdfjsLib,Tesseract,FileReader,Image,NodeFilter,localStorage,URLSearchParams,fixDevanagariInMathJax,renderPaperChapters}=scope;

const gridSubjectsByClass=JSON.parse(config.values["learning-specification-grids-value-0"]||'null');
function updateGridSubjects(selected='') {
    const field=document.getElementById('modalSubject');
    const rows=gridSubjectsByClass[document.getElementById('modalClass').value]||[];
    field.replaceChildren(new Option(rows.length?'Choose Subject':'No assigned subjects for this class',''));
    rows.forEach(subject=>field.add(new Option(subject.name_en+(subject.code?' ('+subject.code+')':''),subject.id)));
    field.disabled=rows.length===0;
    field.value=String(selected);
}
function showAddForm() {
    document.getElementById('gridsListSection').style.display = 'none';
    document.getElementById('gridsFormSection').style.display = 'block';

    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalGridId').value = '';
    document.getElementById('formTitle').innerText = 'Create Specification Grid';
    document.getElementById('modalGridName').value = '';
    document.getElementById('modalClass').value = '';
    updateGridSubjects();

    // Reset inputs
    document.querySelectorAll('.count-input').forEach(i => i.value = 0);
    document.querySelectorAll('.marks-input').forEach(i => i.value = 1);
}

function editGrid(g) {
    document.getElementById('gridsListSection').style.display = 'none';
    document.getElementById('gridsFormSection').style.display = 'block';

    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalGridId').value = g.id;
    document.getElementById('formTitle').innerText = 'Edit Specification Grid Blueprint';
    document.getElementById('modalGridName').value = g.name;
    document.getElementById('modalClass').value = g.class_id;
    updateGridSubjects(g.subject_id);

    // Parse config JSON
    let config = {};
    try {
        config = JSON.parse(g.config_json);
    } catch(e) {}

    const types = {
        'MCQ': 'MCQ',
        'Very Short': 'Very_Short',
        'Short': 'Short',
        'Long': 'Long',
        'Reasoning': 'Reasoning',
        'Difference Between': 'Difference_Between'
    };

    for (let type in types) {
        let key = types[type];
        let val = config[type] || { count: 0, marks: 1 };
        document.getElementById('count_' + key).value = val.count || 0;
        document.getElementById('marks_' + key).value = val.marks || 1;
    }
}

function hideForm() {
    document.getElementById('gridsListSection').style.display = 'block';
    document.getElementById('gridsFormSection').style.display = 'none';
}


scope.bindActions({"learning-specification-grids-action-0":function(event,args){showAddForm()},
"learning-specification-grids-action-1":function(event,args){editGrid(JSON.parse(args[0]||'null'))},
"learning-specification-grids-action-2":function(event,args){return confirm('Are you sure you want to delete this specification grid layout?');},
"learning-specification-grids-action-3":function(event,args){return confirm('Are you sure you want to approve and make this grid layout universal for all schools?');},
"learning-specification-grids-action-4":function(event,args){hideForm()},
"learning-specification-grids-action-5":function(event,args){updateGridSubjects()}});
}
