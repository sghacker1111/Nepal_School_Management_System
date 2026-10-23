// Mechanically extracted from modules/learning/questions.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI,tinymce,MathJax,pdfjsLib,Tesseract,FileReader,Image,NodeFilter,localStorage,URLSearchParams,fixDevanagariInMathJax,renderPaperChapters}=scope;

const canManageCatalog=JSON.parse(config.values["learning-questions-value-0"]||'null');
const classesMap = JSON.parse(config.values["learning-questions-value-1"]||'null');
const subjectsMap = JSON.parse(config.values["learning-questions-value-2"]||'null');
const classSubjectsMap = JSON.parse(config.values["learning-questions-value-3"]||'null');
const cdcCurriculum = JSON.parse(config.values["learning-questions-value-4"]||'null');

window.SchoolQuestionEditor.init('#modalQuestionText, #modalQuestionTextNp, #modalAnswer, #modalAnswerNp, #modalOptA, #modalOptB, #modalOptC, #modalOptD, #modalOptANp, #modalOptBNp, #modalOptCNp, #modalOptDNp');

function updateImportContentMode(){
 const mode=document.querySelector('input[name="import_content_mode"]:checked')?.value||'normal';
 document.querySelectorAll('[data-import-template]').forEach(link=>{
  const url=new URL(link.href,window.location.href);url.searchParams.set('content_mode',mode);link.href=url.href;
 });
 const help=document.getElementById('importModeHelp');
 if(help)help.textContent=mode==='html'
  ?'HTML Source: supported HTML formatting and LaTeX in questions, options and model answers are saved. Scripts and unsafe attributes are removed. Use \\( ... \\), \\[ ... \\], $ ... $ or $$ ... $$ for maths. The templates below contain HTML examples.'
  :'Normal Text: questions, options and model answers are imported as plain text with line breaks. LaTeX is supported; HTML tags are displayed as text. The templates below contain normal-text examples.';
}
document.addEventListener('DOMContentLoaded',()=>{
 document.querySelectorAll('input[name="import_content_mode"]').forEach(radio=>radio.addEventListener('change',updateImportContentMode));
 updateImportContentMode();
});
function onModalClassChange(selectedSubjectId = '', selectedChapterValue = '', selectedTopicId = '') {
    const classId = document.getElementById('modalClass').value;
    const subjectSelect = document.getElementById('modalSubject');
    subjectSelect.innerHTML = '';

    if (!classId) {
        subjectSelect.innerHTML = '<option value="">-- First Select a Class --</option>';
        updateChaptersDropdown('');
        return;
    }

    // Filter active class-subject mappings
    const available = classSubjectsMap.filter(cs => cs.class_id == classId);
    if (available.length > 0) {
        subjectSelect.innerHTML = '<option value="">-- Choose Subject --</option>';
        available.forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.subject_id;
            opt.textContent = `[${s.code || 'SUB'}] ${s.name_en} (${s.name_np || ''})${s.is_optional == 1 ? ' [Optional]' : ''}`;
            subjectSelect.appendChild(opt);
        });
    } else {
        subjectSelect.innerHTML = '<option value="">No assigned subjects in this class</option>';
    }

    if (selectedSubjectId) {
        subjectSelect.value = selectedSubjectId;
    }

    updateChaptersDropdown(selectedChapterValue, selectedTopicId);
}

function showAddForm(category = 'MCQ') {
    document.getElementById('modalVisibility').value=canManageCatalog?'public':'private';
    document.getElementById('modalCatalogTopicId').value='';document.getElementById('modalLocalTopicId').value='';
    document.getElementById('questionsListSection').style.display = 'none';
    document.getElementById('questionsFormSection').style.display = 'block';

    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalQuestionId').value = '';

    const typeSelect = document.getElementById('modalType');
    const typeBadge = document.getElementById('typeBadgeContainer');

    if (category === 'MCQ') {
        document.getElementById('formTitle').innerHTML = '<i class="fas fa-list-ol text-primary me-2"></i> Add Multiple Choice Question (MCQ)';
        typeSelect.value = 'MCQ';
        typeSelect.style.display = 'none';
        if (typeBadge) typeBadge.style.display = 'block';
    } else {
        document.getElementById('formTitle').innerHTML = '<i class="fas fa-pen-nib text-success me-2"></i> Add Question (Subjective / Theory)';
        typeSelect.style.display = 'block';
        if (typeBadge) typeBadge.style.display = 'none';

        // Default to Short answer and hide MCQ option
        if (typeSelect.value === 'MCQ') {
            typeSelect.value = 'Short';
        }
        for (let i = 0; i < typeSelect.options.length; i++) {
            if (typeSelect.options[i].value === 'MCQ') {
                typeSelect.options[i].style.display = 'none';
            } else {
                typeSelect.options[i].style.display = '';
            }
        }
    }

    document.getElementById('modalClass').value = '';
    onModalClassChange();
    document.getElementById('modalChapter').value = '';
    document.getElementById('modalTopicSelect').innerHTML = '<option value="">Choose Topic</option>';
    document.getElementById('modalMarks').value = (category === 'MCQ') ? '1' : '5';
    if (document.getElementById('modalCognitiveDomain')) {
        document.getElementById('modalCognitiveDomain').value = 'Knowledge';
    }

    // Clear editors safely
    if (window.tinymce && tinymce.get('modalQuestionText')) {
        tinymce.get('modalQuestionText').setContent('');
    } else {
        document.getElementById('modalQuestionText').value = '';
    }
    if (window.tinymce && tinymce.get('modalQuestionTextNp')) {
        tinymce.get('modalQuestionTextNp').setContent('');
    } else {
        document.getElementById('modalQuestionTextNp').value = '';
    }
    if (window.tinymce && tinymce.get('modalAnswer')) {
        tinymce.get('modalAnswer').setContent('');
    } else {
        document.getElementById('modalAnswer').value = '';
    }
    if (window.tinymce && tinymce.get('modalAnswerNp')) {
        tinymce.get('modalAnswerNp').setContent('');
    } else {
        document.getElementById('modalAnswerNp').value = '';
    }
    // Clear options safely using helper
    setOptionVal('modalOptA', '');
    setOptionVal('modalOptANp', '');
    setOptionVal('modalOptB', '');
    setOptionVal('modalOptBNp', '');
    setOptionVal('modalOptC', '');
    setOptionVal('modalOptCNp', '');
    setOptionVal('modalOptD', '');
    setOptionVal('modalOptDNp', '');
    selectCorrectOption('A');

    toggleMCQFields();

    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function selectCorrectOption(opt) {
    opt = (opt || 'A').toUpperCase();
    const radio = document.getElementById('correctOpt_' + opt);
    if (radio) {
        radio.checked = true;
    }
    onCorrectOptionChange(opt);
}

function onCorrectOptionChange(opt) {
    opt = (opt || 'A').toUpperCase();
    ['A', 'B', 'C', 'D'].forEach(o => {
        const container = document.getElementById('optContainer_' + o);
        const radio = document.getElementById('correctOpt_' + o);
        const badge = container ? container.querySelector('.correct-tick-badge') : null;

        if (container && radio) {
            if (o === opt) {
                radio.checked = true;
                container.style.borderColor = '#198754';
                container.style.backgroundColor = 'rgba(25, 135, 84, 0.05)';
                container.classList.add('shadow');
                if (badge) {
                    badge.classList.remove('bg-white');
                    badge.classList.add('bg-success', 'border-success');
                    const label = badge.querySelector('label');
                    if (label) label.className = 'form-check-label fw-bold text-white m-0';
                }
            } else {
                radio.checked = false;
                container.style.borderColor = '#dee2e6';
                container.style.backgroundColor = '#f8f9fa';
                container.classList.remove('shadow');
                if (badge) {
                    badge.classList.remove('bg-success', 'border-success');
                    badge.classList.add('bg-white');
                    const label = badge.querySelector('label');
                    if (label) label.className = 'form-check-label fw-bold text-success m-0';
                }
            }
        }
    });

    const hidden = document.getElementById('modalCorrect');
    if (hidden) hidden.value = opt;
}

function setOptionVal(id, val) {
    if (window.tinymce && tinymce.get(id)) {
        tinymce.get(id).setContent(val || '');
    } else {
        const el = document.getElementById(id);
        if (el) el.value = val || '';
    }
}

function editQuestion(q) {
    document.getElementById('modalVisibility').value=q.visibility;
    document.getElementById('modalCatalogTopicId').value=q.catalog_topic_id||'';
    document.getElementById('questionsListSection').style.display = 'none';
    document.getElementById('questionsFormSection').style.display = 'block';

    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalQuestionId').value = q.id;

    const typeSelect = document.getElementById('modalType');
    const typeBadge = document.getElementById('typeBadgeContainer');

    // Ensure all options are visible during edit
    for (let i = 0; i < typeSelect.options.length; i++) {
        typeSelect.options[i].style.display = '';
    }

    if (q.type === 'MCQ') {
        document.getElementById('formTitle').innerHTML = '<i class="fas fa-list-ol text-primary me-2"></i> Edit Multiple Choice Question (MCQ)';
        typeSelect.style.display = 'none';
        if (typeBadge) typeBadge.style.display = 'block';
    } else {
        document.getElementById('formTitle').innerHTML = '<i class="fas fa-edit text-warning me-2"></i> Edit Question Details';
        typeSelect.style.display = 'block';
        if (typeBadge) typeBadge.style.display = 'none';
    }

    document.getElementById('modalClass').value = q.class_id;
    onModalClassChange(q.subject_id, q.chapter, q.topic_id||q.catalog_topic_id);
    document.getElementById('modalType').value = q.type;
    document.getElementById('modalMarks').value = q.marks;
    if (document.getElementById('modalCognitiveDomain')) {
        document.getElementById('modalCognitiveDomain').value = q.cognitive_domain || 'Knowledge';
    }

    // Populate editors safely
    setOptionVal('modalQuestionText', q.question_text);
    setOptionVal('modalQuestionTextNp', q.question_text_np);
    setOptionVal('modalAnswer', q.answer);
    setOptionVal('modalAnswerNp', q.answer_np);

    if (q.type === 'MCQ') {
        const opts = JSON.parse(q.options || '{}');
        setOptionVal('modalOptA', opts.A || '');
        setOptionVal('modalOptB', opts.B || '');
        setOptionVal('modalOptC', opts.C || '');
        setOptionVal('modalOptD', opts.D || '');

        const optsNp = JSON.parse(q.options_np || '{}');
        setOptionVal('modalOptANp', optsNp.A || '');
        setOptionVal('modalOptBNp', optsNp.B || '');
        setOptionVal('modalOptCNp', optsNp.C || '');
        setOptionVal('modalOptDNp', optsNp.D || '');

        selectCorrectOption(q.correct_option || 'A');
    } else {
        setOptionVal('modalOptA', '');
        setOptionVal('modalOptANp', '');
        setOptionVal('modalOptB', '');
        setOptionVal('modalOptBNp', '');
        setOptionVal('modalOptC', '');
        setOptionVal('modalOptCNp', '');
        setOptionVal('modalOptD', '');
        setOptionVal('modalOptDNp', '');
    }

    toggleMCQFields();
    // Type-change defaults apply to new questions, not an existing saved weight.
    document.getElementById('modalMarks').value = q.marks;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function hideForm() {
    document.getElementById('questionsListSection').style.display = 'block';
    document.getElementById('questionsFormSection').style.display = 'none';
}

let chapterDropdownRequestVersion = 0;
let topicDropdownRequestVersion = 0;

function updateChaptersDropdown(selectedChapterValue = '', selectedTopicId = '') {
    const requestVersion=++chapterDropdownRequestVersion;
    ++topicDropdownRequestVersion;
    document.getElementById('modalTopicSelect').innerHTML='<option value="">Choose Topic</option>';
    const classId=document.getElementById('modalClass').value;
    const subjectId=document.getElementById('modalSubject').value;
    const select=document.getElementById('modalChapterSelect');
    const customInput=document.getElementById('modalChapterCustom');
    const hiddenInput=document.getElementById('modalChapter');
    select.innerHTML='';customInput.style.display='none';hiddenInput.value='';
    const addCustom=()=>{if(!canManageCatalog)return;const opt=document.createElement('option');opt.value='__custom__';opt.textContent='Other / Custom Chapter';select.appendChild(opt);};
    if(!classId||!subjectId){updateTopicsDropdown(0,'');return;}
    $.ajax({url:window.location.pathname,data:{action_get_chapters:1,class_id:classId,subject_id:subjectId},dataType:'json',success:function(chapters){
        if(requestVersion!==chapterDropdownRequestVersion)return;
        select.innerHTML='';
        for(const ch of chapters||[]){const opt=document.createElement('option');opt.value=ch.name;opt.textContent=ch.name;opt.setAttribute('data-id',ch.id);select.appendChild(opt);}
        addCustom();
        const matched=(chapters||[]).find(c=>c.name===selectedChapterValue);
        if(selectedChapterValue&&matched){select.value=matched.name;}
        else if(selectedChapterValue&&canManageCatalog){select.value='__custom__';customInput.style.display='block';customInput.value=selectedChapterValue;}
        else if(chapters?.length){select.value=chapters[0].name;}
        else if(canManageCatalog){select.value='__custom__';customInput.style.display='block';customInput.value=selectedChapterValue;}
        hiddenInput.value=select.value==='__custom__'?customInput.value:select.value;
        const chosen=(chapters||[]).find(c=>c.name===select.value);
        updateTopicsDropdown(chosen?.id||0,hiddenInput.value,selectedTopicId);
    },error:function(){if(requestVersion!==chapterDropdownRequestVersion)return;select.innerHTML='';addCustom();hiddenInput.value='';}});
}

function updateTopicsDropdown(chapterId, chapterName, selectedTopicId = '') {
    const requestVersion = ++topicDropdownRequestVersion;
    const classId = document.getElementById('modalClass').value;
    const subjectId = document.getElementById('modalSubject').value;
    const select = document.getElementById('modalTopicSelect');

    select.innerHTML = '<option value="">Choose Topic</option>';
    if (!classId || !subjectId || (!chapterId && !chapterName)) return;

    $.ajax({
        url: window.location.pathname,
        data: {
            action_get_topics: 1,
            chapter_id: chapterId,
            chapter_name: chapterName,
            class_id: classId,
            subject_id: subjectId
        },
        dataType: 'json',
        success: function(topics) {
            if (requestVersion !== topicDropdownRequestVersion) return;
            select.innerHTML = '<option value="">Choose Topic</option>';
            if (Array.isArray(topics) && topics.length > 0) {
                const choices = new Map();
                topics.forEach(t => {
                    const key = String(t.name).trim().replace(/\s+/g, ' ').toLowerCase();
                    if (!key) return;
                    const aliases = [t.id, ...(Array.isArray(t.alias_ids) ? t.alias_ids : [])].map(String);
                    const isSelected = selectedTopicId !== '' && aliases.includes(String(selectedTopicId));
                    if (!choices.has(key)) choices.set(key, {topic: t, value: t.id});
                    if (isSelected) choices.get(key).value = selectedTopicId;
                });
                choices.forEach(({topic, value}) => {
                    const opt = document.createElement('option');
                    opt.value = value;
                    opt.textContent = topic.name;
                    const alias=(topic.aliases||[]).find(a=>String(a.id)===String(value));
                    opt.setAttribute('data-scope',alias?.scope||topic.scope||'local');
                    select.appendChild(opt);
                });
                if (selectedTopicId) {
                    select.value = selectedTopicId;
                }
            }
            syncCatalogTopic();
        }
    });
}

function syncCatalogTopic(){
    const select=document.getElementById('modalTopicSelect');
    const global=document.getElementById('modalCatalogTopicId');
    const local=document.getElementById('modalLocalTopicId');
    if(!global||!local)return;
    const scope=select.options[select.selectedIndex]?.getAttribute('data-scope');
    global.value=scope==='shared'?select.value:'';local.value=scope==='shared'?'':select.value;
}

function onChapterSelectChange() {
    const select = document.getElementById('modalChapterSelect');
    const customInput = document.getElementById('modalChapterCustom');
    const hiddenInput = document.getElementById('modalChapter');

    if (select.value === '__custom__') {
        customInput.style.display = 'block';
        hiddenInput.value = customInput.value;
        updateTopicsDropdown(0, '', '');
    } else {
        customInput.style.display = 'none';
        hiddenInput.value = select.value;
        const selectedOpt = select.options[select.selectedIndex];
        const dbChapterId = selectedOpt ? parseInt(selectedOpt.getAttribute('data-id')) || 0 : 0;
        updateTopicsDropdown(dbChapterId, select.value, '');
    }
}

function updateChapterHidden() {
    const customInput = document.getElementById('modalChapterCustom');
    const hiddenInput = document.getElementById('modalChapter');
    hiddenInput.value = customInput.value;
}

function toggleMCQFields() {
    const type = document.getElementById('modalType').value;
    const mcqDiv = document.getElementById('mcqDetails');
    const ansDiv = document.getElementById('answerDetails');
    const marksField = document.getElementById('modalMarks');
    const mcqInputs = mcqDiv.querySelectorAll('.mcq-field');

    if (type === 'MCQ') {
        mcqDiv.style.display = 'block';
        ansDiv.style.display = 'block';
        mcqInputs.forEach(i => i.setAttribute('required', 'required'));
        marksField.value = '1';
    } else {
        mcqDiv.style.display = 'none';
        ansDiv.style.display = 'block';
        mcqInputs.forEach(i => i.removeAttribute('required'));

        if (type === 'Very Short') {
            marksField.value = '1';
        } else if (type === 'Short') {
            marksField.value = '5';
        } else if (type === 'Long') {
            marksField.value = '8';
        } else if (type === 'Reasoning') {
            marksField.value = '4';
        } else if (type === 'Difference Between') {
            marksField.value = '4';
        }
    }
}



scope.bindActions({"learning-questions-action-0":function(event,args){showAddForm('MCQ')},
"learning-questions-action-1":function(event,args){showAddForm('other')},
"learning-questions-action-2":function(event,args){this.form.elements.subject.value='';this.form.submit()},
"learning-questions-action-3":function(event,args){this.form.submit()},
"learning-questions-action-4":function(event,args){this.form.submit()},
"learning-questions-action-5":function(event,args){editQuestion(JSON.parse(args[0]||'null'))},
"learning-questions-action-6":function(event,args){return confirm(''+(args[0]??'')+'')},
"learning-questions-action-7":function(event,args){return confirm(''+(args[0]??'')+'')},
"learning-questions-action-8":function(event,args){hideForm()},
"learning-questions-action-9":function(event,args){tinymce.triggerSave()},
"learning-questions-action-10":function(event,args){onModalClassChange()},
"learning-questions-action-11":function(event,args){updateChaptersDropdown('')},
"learning-questions-action-12":function(event,args){onChapterSelectChange()},
"learning-questions-action-13":function(event,args){updateChapterHidden()},
"learning-questions-action-14":function(event,args){syncCatalogTopic()},
"learning-questions-action-15":function(event,args){toggleMCQFields()},
"learning-questions-action-16":function(event,args){selectCorrectOption('A')},
"learning-questions-action-17":function(event,args){onCorrectOptionChange('A')},
"learning-questions-action-18":function(event,args){selectCorrectOption('B')},
"learning-questions-action-19":function(event,args){onCorrectOptionChange('B')},
"learning-questions-action-20":function(event,args){selectCorrectOption('C')},
"learning-questions-action-21":function(event,args){onCorrectOptionChange('C')},
"learning-questions-action-22":function(event,args){selectCorrectOption('D')},
"learning-questions-action-23":function(event,args){onCorrectOptionChange('D')},
"learning-questions-action-24":function(event,args){hideForm()}});
}
