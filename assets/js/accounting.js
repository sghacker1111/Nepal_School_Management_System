'use strict';
// The server remains authoritative; this mirrors its integer-paisa validation.
const AccountingMoney = (() => {
    const MAX = 1000000000000n;
    function add(a, b) {
        if (typeof a !== 'bigint' || typeof b !== 'bigint' || a > MAX || a < -MAX || b > MAX || b < -MAX || a + b > MAX || a + b < -MAX)
            throw new Error('Total exceeds the supported limit.');
        return a + b;
    }
    function units(value, scale = 2) {
        if (!(typeof value === 'string' || (typeof value === 'number' && Number.isSafeInteger(value))) || !Number.isInteger(scale) || scale < 0 || scale > 3)
            throw new Error('Enter amounts as decimal numbers, not scientific notation.');
        const raw = String(value).trim();
        if (raw.length > 80 || !new RegExp(scale ? '^\\d+(?:\\.\\d{1,' + scale + '})?$' : '^\\d+$').test(raw))
            throw new Error('Enter a non-negative number with at most ' + scale + ' decimal places.');
        const [whole, fraction = ''] = raw.split('.');
        return add(BigInt(whole + fraction.padEnd(scale, '0')), 0n);
    }
    function mulDiv(a, b, denominator) {
        if ([a, b, denominator].some(n => typeof n !== 'bigint' || n < 0n || n > MAX) || denominator === 0n)
            throw new Error('Invalid monetary calculation or unsupported amount.');
        // BigInt never converts the intermediate product to a binary float.
        return add((a * b + denominator / 2n) / denominator, 0n);
    }
    function show(minor) {
        add(minor, 0n);
        const n = minor < 0n ? -minor : minor;
        return 'Rs. ' + (minor < 0n ? '-' : '') + (n / 100n).toLocaleString('en-US') + '.' + String(n % 100n).padStart(2, '0');
    }
    function preview(input, billDiscount = '0', options = {}) {
        if (!Array.isArray(input) || input.length > 100) throw new Error('A bill may contain at most 100 lines.');
        let subtotal = 0n, discount = 0n, eligible = 0n;
        const items = input.filter(row => String(row.particular ?? '').trim() !== '').map(row => {
            const name = String(row.particular).trim();
            if (name.length > 200) throw new Error('Bill particulars must not exceed 200 characters.');
            const quantity = units(row.quantity ?? '1', 3), rate = units(row.rate ?? '0');
            if (quantity <= 0n || quantity > 1000000n) throw new Error('Quantity must be greater than zero and at most 1,000.');
            const gross = mulDiv(quantity, rate, 1000n), off = units(row.discount ?? '0'), taxRate = units(row.tax_percent ?? '0');
            if (off > gross) throw new Error('A line discount exceeds the line amount.');
            if (taxRate > 10000n) throw new Error('Tax percentage cannot exceed 100.');
            if (taxRate && !options.taxRegistered) throw new Error('Configure tax registration before applying tax.');
            const discountEligible = row.discount_eligible !== false && String(row.discount_eligible ?? '1') !== '0';
            if (off && !discountEligible) throw new Error('Line discounts are disabled for this item.');
            subtotal = add(subtotal, gross); discount = add(discount, off);
            if (discountEligible) eligible = add(eligible, gross - off);
            return { particular: name, quantity_milli: quantity, rate_minor: rate, gross_minor: gross, discount_minor: off, tax_basis_points: taxRate, discount_eligible: discountEligible };
        });
        if (!items.length) throw new Error('Add at least one bill item.');
        let remaining = units(billDiscount), weight = eligible, tax = 0n, total = 0n;
        if (remaining > eligible) throw new Error('Bill discount exceeds eligible amounts.');
        discount = add(discount, remaining);
        for (const item of items) {
            const base = item.gross_minor - item.discount_minor;
            const portion = item.discount_eligible && weight > 0n ? mulDiv(remaining, base, weight) : 0n;
            remaining -= portion;
            if (item.discount_eligible) weight -= base;
            item.discount_minor = add(item.discount_minor, portion);
            item.tax_minor = mulDiv(item.gross_minor - item.discount_minor, item.tax_basis_points, 10000n);
            item.total_minor = add(item.gross_minor - item.discount_minor, item.tax_minor);
            tax = add(tax, item.tax_minor); total = add(total, item.total_minor);
            delete item.discount_eligible;
        }
        if (remaining !== 0n) throw new Error('Discount allocation failed.');
        return { items, subtotal_minor: subtotal, discount_minor: discount, tax_minor: tax, total_minor: total };
    }
    return { MAX, add, units, mulDiv, show, preview };
})();
if (typeof module !== 'undefined' && module.exports) module.exports = AccountingMoney;

(() => {
    if (typeof document === 'undefined') return;
    document.querySelectorAll('[data-student-picker]').forEach(root => {
        const search = root.querySelector('input[type=search]'), id = root.querySelector('input[type=hidden]'), results = root.querySelector('[data-student-results]');
        let timer, revision = 0;
        search.addEventListener('input', () => {
            id.value = ''; results.replaceChildren(); clearTimeout(timer);
            const current = ++revision;
            if (search.value.trim().length < 2) return;
            timer = setTimeout(async () => {
                try {
                    const response = await fetch(window.APP_URL + '/modules/accounting/search.php?q=' + encodeURIComponent(search.value.trim()), { headers: { Accept: 'application/json' } });
                    if (!response.ok) throw new Error('Student search failed.');
                    const rows = await response.json();
                    if (current !== revision) return;
                    rows.forEach(r => {
                        const b = document.createElement('button'); b.type = 'button'; b.className = 'list-group-item list-group-item-action'; b.textContent = r.label;
                        b.onclick = () => { id.value = r.id; search.value = r.label; results.replaceChildren(); id.dispatchEvent(new Event('change', { bubbles: true })); }; results.append(b);
                    });
                    if (!rows.length) results.textContent = 'No matching students.';
                } catch (e) { if (current === revision) results.textContent = e.message; }
            }, 250);
        });
    });
    const bill = document.querySelector('[data-manual-bill]');
    if (!bill) return;
    const body = bill.querySelector('tbody'), catalog = JSON.parse(document.getElementById('acCatalog').textContent);
    let rowIndex = 0;
    function update() {
        const preview = bill.querySelector('[data-bill-preview]'), rows = Array.from(body.querySelectorAll('tr'));
        try {
            const input = rows.map(row => {
                const item = { discount_eligible: row.dataset.eligible };
                row.querySelectorAll('[data-field]').forEach(field => { item[field.dataset.field] = field.value; });
                return item;
            });
            const result = AccountingMoney.preview(input, bill.querySelector('[name=bill_discount]').value || '0', { taxRegistered: bill.dataset.taxRegistered === '1' });
            preview.replaceChildren();
            let i = 0;
            rows.forEach((row, index) => {
                row.querySelector('[data-line-total]').textContent = '';
                if (!String(input[index].particular).trim()) return;
                const item = result.items[i++], p = document.createElement('p');
                p.textContent = item.particular + ' | ' + input[index].quantity + ' × ' + AccountingMoney.show(item.rate_minor) + ' = ' + AccountingMoney.show(item.total_minor);
                preview.append(p); row.querySelector('[data-line-total]').textContent = AccountingMoney.show(item.total_minor);
            });
            [['Subtotal', result.subtotal_minor], ['Discount', result.discount_minor], ['Tax', result.tax_minor], ['Total', result.total_minor]].forEach(([label, amount]) => {
                const p = document.createElement('p'); p.textContent = label + ': ' + AccountingMoney.show(amount); preview.append(p);
            });
        } catch (e) {
            preview.textContent = e.message;
            rows.forEach(row => { row.querySelector('[data-line-total]').textContent = ''; });
        }
    }
    function add(item = null) {
        const i = rowIndex++, row = document.createElement('tr'); row.dataset.eligible = item ? String(item.discount_eligible) : '1';
        const fields = [['particular', 'text', item?.name || ''], ['quantity', 'number', '1'], ['rate', 'number', item?.rate || '0.00'], ['discount', 'number', '0.00'], ['tax_percent', 'number', item?.tax_percent || '0.00']];
        fields.forEach(([key, type, value]) => {
            const td = document.createElement('td'), input = document.createElement('input'); input.type = type; input.name = 'items[' + i + '][' + key + ']'; input.dataset.field = key; input.value = value; input.className = 'form-control'; input.required = true;
            input.setAttribute('aria-label', key.replaceAll('_', ' '));
            if (type === 'number') { input.min = '0'; input.step = key === 'quantity' ? '0.001' : '0.01'; if (key === 'quantity') input.max = '1000'; if (key === 'tax_percent') input.max = '100'; }
            input.addEventListener('input', update); td.append(input); row.append(td);
        });
        const hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'items[' + i + '][item_id]'; hidden.value = item?.id || ''; row.firstChild.append(hidden);
        const total = document.createElement('td'); total.dataset.lineTotal = ''; row.append(total);
        const td = document.createElement('td'), remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-outline-danger'; remove.textContent = 'Remove';
        remove.onclick = () => { row.remove(); update(); }; td.append(remove); row.append(td); body.append(row); update();
    }
    bill.querySelector('[data-add-item]').onclick = () => add();
    bill.querySelector('[data-add-catalog]').onclick = () => { const id = bill.querySelector('[data-catalog]').value, item = catalog.find(r => String(r.id) === id); if (item) add(item); };
    bill.querySelector('[name=bill_discount]').addEventListener('input', update); add();
})();
