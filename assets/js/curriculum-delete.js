(function () {
    'use strict';
    function element(tag, text, className) {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    }
    function showBlocked(response, baseUrl) {
        if (!response.blocked || !response.dependencies) {
            Swal.fire('Cannot delete', response.message || 'Please reload and try again.', 'error');
            return;
        }
        const body = element('div', undefined, 'text-start');
        body.append(element('p', response.message));
        body.append(element('p', 'Nothing was deleted. Open the listed sections, remove or reassign the active content, then retry. Private school content must be handled by its creator; it is not exposed here.', 'small text-muted'));
        for (const group of response.dependencies.groups) {
            body.append(element('h3', `${group.section} (${group.count})`, 'h6 mt-3'));
            const wrapper = element('div', undefined, 'table-responsive');
            const table = element('table', undefined, 'table table-sm small');
            const head = element('thead'), labels = element('tr');
            for (const label of ['Record', 'Location', 'Owner', '']) labels.append(element('th', label));
            head.append(labels); table.append(head);
            const rows = element('tbody');
            for (const item of group.items) {
                const row = element('tr');
                row.append(element('td', '#' + item.id), element('td', `${item.school} · ${item.class} · ${item.subject}`), element('td', item.owner));
                const action = element('td');
                if (typeof item.url === 'string' && /^\/(?:modules\/learning\/|learning(?:\/|\?)|exam-(?:questions|mcqs|paper-grids)\?)/.test(item.url) && !/[\\\x00-\x20]/.test(item.url) && !item.url.includes('://')) {
                    const link = element('a', 'Open section');
                    link.href = baseUrl + item.url; link.target = '_blank'; link.rel = 'noopener'; action.append(link);
                } else action.textContent = 'Ask the creator / school administrator';
                row.append(action); rows.append(row);
            }
            table.append(rows); wrapper.append(table); body.append(wrapper);
            if (group.count > group.items.length) body.append(element('p', `Showing the first ${group.items.length} of ${group.count} linked records. Open the section for the rest.`, 'small text-muted'));
        }
        Swal.fire({title: 'Deletion blocked: linked content', icon: 'warning', html: body, width: 'min(1100px, 95vw)', confirmButtonText: 'Close'});
    }
    function showDeleted(items, restore) {
        const body = element('div', undefined, 'text-start');
        body.append(element('p', 'Restore a chapter before its topics or linked questions/notes. Restoring a chapter also restores empty topics removed with that chapter.', 'small'));
        if (!items.length) body.append(element('p', 'No deleted chapters or topics in this class subject.'));
        for (const item of items) {
            const row = element('div', undefined, 'd-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom py-2');
            row.append(element('span', `${item.kind === 'chapters' ? 'Chapter' : 'Topic'}: ${item.name}${item.kind === 'topics' ? ' — ' + item.chapter : ''}`));
            const button = element('button', item.parent_deleted ? 'Restore chapter first' : 'Restore', 'btn btn-sm btn-outline-success');
            button.type = 'button'; button.disabled = Boolean(item.parent_deleted);
            button.addEventListener('click', () => { button.disabled = true; restore(item.kind, item.id); });
            row.append(button); body.append(row);
        }
        Swal.fire({title: 'Deleted curriculum', html: body, width: 'min(900px, 95vw)', confirmButtonText: 'Close'});
    }
    window.CurriculumDeleteUI = {showBlocked, showDeleted};
})();
