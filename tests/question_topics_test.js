const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../modules/learning/questions.php'), 'utf8');
const declarations = source.indexOf('let chapterDropdownRequestVersion');
const start = declarations >= 0 ? declarations : source.indexOf('function updateChaptersDropdown(');
const code = source.slice(start, source.indexOf('function onChapterSelectChange()', start));
const classChangeCode = source.slice(source.indexOf('function onModalClassChange('), source.indexOf('function showAddForm('));

function fixture() {
    class Element {
        constructor(value = '') { this._value = value; this.children = []; this.style = {}; this.attributes = {}; }
        set innerHTML(html) { this.children = []; this._value = ''; if (html.includes('Choose Topic')) this.appendChild(Object.assign(new Element(), {textContent: 'Choose Topic'})); }
        get value() { return this._value || (this.children[0]?.value ?? ''); }
        set value(value) { this._value = String(value); }
        appendChild(child) { this.children.push(child); }
        setAttribute(name, value) { this.attributes[name] = String(value); }
    }
    const elements = Object.fromEntries(['modalClass', 'modalSubject', 'modalChapterSelect', 'modalChapterCustom', 'modalChapter', 'modalTopicSelect'].map(id => [id, new Element()]));
    elements.modalClass.value = '9'; elements.modalSubject.value = '20';
    const requests = [];
    const context = vm.createContext({document: {getElementById: id => elements[id], createElement: () => new Element()}, window: {location: {pathname: '/modules/learning/questions.php'}}, classSubjectsMap: [{class_id: 9, subject_id: 20, name_en: 'Mathematics'}], $: {ajax: options => requests.push(options)}});
    vm.runInContext(code, context);
    vm.runInContext(classChangeCode, context);
    return {context, elements, requests, run: text => vm.runInContext(text, context), labels: () => elements.modalTopicSelect.children.map(option => option.textContent)};
}
const topics = [{id: 101, name: 'Cardinality of two sets'}, {id: 102, name: 'Cardinality of three sets'}];

// Reproduce the screenshot: both overlapping responses used to append the same two topics.
let app = fixture();
app.run('updateTopicsDropdown(30, "Sets"); updateTopicsDropdown(30, "Sets");');
for (const request of app.requests) request.success(topics);
assert.deepEqual(app.labels(), ['Choose Topic', ...topics.map(t => t.name)], 'Overlapping topic loads must render each choice once');

// A slower response for the previous chapter must never replace the selected chapter's topics.
app = fixture();
app.run('updateTopicsDropdown(30, "Sets"); updateTopicsDropdown(40, "Algebra");');
app.requests[1].success([{id: 201, name: 'Algebra topic'}]);
app.requests[0].success(topics);
assert.deepEqual(app.labels(), ['Choose Topic', 'Algebra topic']);

// Duplicate legacy labels are one choice, while an existing question keeps its old topic ID.
app = fixture();
app.run('updateTopicsDropdown(30, "Sets", 103);');
app.requests[0].success([{id: 101, name: 'Cardinality of two sets', alias_ids: [101, 103]}, {id: 104, name: '  CARDINALITY   OF TWO SETS  '}, topics[1]]);
assert.deepEqual(app.labels(), ['Choose Topic', ...topics.map(t => t.name)]);
assert.equal(app.elements.modalTopicSelect.value, '103', 'Editing must preserve a selected historical topic ID');
assert.equal(app.elements.modalTopicSelect.children[1].value, '103');

// Clearing the class invalidates an outstanding load and leaves only the placeholder.
app = fixture();
app.run('updateTopicsDropdown(30, "Sets");');
app.elements.modalClass.value = '';
app.run('updateTopicsDropdown(0, "");');
app.requests[0].success(topics);
assert.deepEqual(app.labels(), ['Choose Topic']);
assert.equal(app.requests.length, 1, 'Do not request topics without a class/chapter');

// Two chapter loads must produce only one current topic load, even when both complete.
app = fixture();
app.run('updateChaptersDropdown(); updateChaptersDropdown();');
const chapterLoads = app.requests.slice();
for (const request of chapterLoads) request.success([{id: 30, name: 'Sets'}]);
const topicLoads = app.requests.filter(request => request.data.action_get_topics);
for (const request of topicLoads) request.success(topics);
assert.equal(topicLoads.length, 1);
assert.deepEqual(app.labels(), ['Choose Topic', ...topics.map(t => t.name)]);

// Changing the subject invalidates topic responses before the new chapters have loaded.
app = fixture();
app.run('updateTopicsDropdown(30, "Sets");');
app.elements.modalSubject.value = '21';
app.run('updateChaptersDropdown();');
app.requests[0].success(topics);
assert.deepEqual(app.labels(), ['Choose Topic']);

// Editing loads the saved subject/chapter/topic together in one dependent request chain.
app = fixture();
app.run('onModalClassChange(20, "Sets", 103);');
assert.equal(app.requests.length, 1);
app.requests[0].success([{id: 40, name: 'Algebra'}, {id: 30, name: 'Sets'}]);
assert.equal(app.elements.modalChapterSelect.value, 'Sets');
app.requests[1].success([{id: 101, name: 'Cardinality of two sets', alias_ids: [101, 103]}]);
assert.equal(app.elements.modalTopicSelect.value, '103');
console.log('Question topic dropdown passed: overlapping loads, stale responses, duplicate labels, legacy selection and cleared filters.');
