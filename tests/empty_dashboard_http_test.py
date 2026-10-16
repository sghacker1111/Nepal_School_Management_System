"""An empty accounting period must render, never call max() with one scalar."""
from http_support import ERPHttpFixture
with ERPHttpFixture() as app:
    app.login('school_admin')
    before={table:app.snapshot(table) for table in ('ac_payments','ac_invoices','ac_journals','ac_journal_lines')}
    for query in ('','?from=2030-01-01&to=2030-01-31'):
        body=app.request('/modules/accounting/index.php'+query)
        assert 'No posted collections in this range.' in body
        assert 'Accounting overview' in body and 'assets/dist/app.css' in body
        assert 'Request could not be completed' not in body
    for table,rows in before.items():assert app.snapshot(table)==rows,table
    print('Empty accounting dashboard passed: default/empty range and all financial records unchanged.')
