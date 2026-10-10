<?php
require_once __DIR__.'/../../includes/accounting_ui.php';
if(!$ac->can('invoice'))denySchoolRequest('Your role may view invoices but cannot create bills.');
$preview=null;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    if(($_POST['action']??'')==='preview')try{$preview=$ac->previewInvoice($_POST);}catch(InvalidArgumentException $e){$error=$e->getMessage();}
    else acHandle(fn()=>$ac->createInvoice($_POST,($_POST['action']??'')==='issue'),'invoice');
}
$catalog=$ac->rows('SELECT id,name,rate_minor,tax_basis_points,discount_eligible FROM ac_items WHERE school_id=? AND is_active=1 ORDER BY name',[$school]);$catalog=array_map(fn($r)=>$r+['rate'=>acAmount((int)$r['rate_minor']),'tax_percent'=>acAmount((int)$r['tax_basis_points'])],$catalog);
$years=$ac->rows('SELECT id,year_bs name FROM academic_years WHERE school_id=? ORDER BY is_active DESC,id DESC',[$school]);ob_start();
?>
<p class="text-muted">Create a custom bill, choose catalog fees/products, or save a draft. The server validates all totals before issuing.</p>
<?php if($preview): ?><div class="ac-preview mb-3"><h4>Validated bill preview</h4><?php foreach($preview['items'] as $r): ?><p><?= sanitize($r['particular']) ?>: <?= acMoney((int)$r['total_minor']) ?></p><?php endforeach; ?><strong>Total <?= acMoney($preview['total_minor']) ?></strong><p class="mb-0">Preview only — no invoice saved.</p></div><?php endif; ?>
<form method="post" data-manual-bill data-tax-registered="<?= (int)$ac->settings['tax_registered'] ?>" class="card card-body"><input type="hidden" name="idempotency_key" value="<?= sanitize($_POST['idempotency_key']??bin2hex(random_bytes(20))) ?>">
<div class="row"><div class="col-md-6"><?php acStudentPicker((int)($_GET['student']??0));acInput('customer_name','External customer name (leave blank for student)','', 'text',false);acSelect('customer_type','Customer type',acChoices(['external'=>'External customer','guardian'=>'Parent / guardian','student'=>'Student']),'external'); ?></div><div class="col-md-6"><?php acInput('bill_date','Bill date (AD)',date('Y-m-d'),'date');acInput('due_date','Due date (AD)',date('Y-m-d',strtotime('+'.$ac->settings['due_days'].' days')),'date');acSelect('academic_year_id','Academic year',$years,$years[0]['id']??'','id','name',false); ?></div></div>
<details class="mb-3"><summary>Contact and bill details</summary><?php acInput('guardian_name','Parent / guardian name','','text',false);acInput('phone','Phone','','text',false);acInput('address','Billing address','','text',false);acInput('description','Bill description','','text',false); ?></details>
<div class="d-flex gap-2 flex-wrap mb-3"><select class="form-select w-auto" data-catalog aria-label="Catalog fee or product"><option value="">Choose fee / product / service</option><?php foreach($catalog as $r): ?><option value="<?= $r['id'] ?>"><?= sanitize($r['name']) ?> · <?= acMoney((int)$r['rate_minor']) ?></option><?php endforeach; ?></select><button type="button" class="btn btn-outline-primary" data-add-catalog>Add catalog item</button><button type="button" class="btn btn-outline-secondary" data-add-item>Add custom item</button></div>
<div class="table-responsive"><table class="table ac-table"><thead><tr><th>Particular</th><th>Quantity</th><th>Rate (NPR)</th><th>Discount (NPR)</th><th>Tax %</th><th>Amount</th><th></th></tr></thead><tbody></tbody></table></div>
<?php acInput('bill_discount','Bill discount (NPR)','0.00','number'); ?>
<div class="ac-preview my-3"><h4>Manual Bill Preview</h4><div data-bill-preview aria-live="polite"></div><small>Final amounts are calculated and checked on the server.</small></div>
<div class="d-flex gap-2 flex-wrap"><button class="btn btn-secondary" name="action" value="draft">Save draft</button><button class="btn btn-primary" name="action" value="issue">Issue bill & open print preview</button></div></form>
<script id="acCatalog" type="application/json"><?= json_encode($catalog,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<?php acRender('Create manual bill',ob_get_clean());
