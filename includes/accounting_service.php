<?php
require_once __DIR__.'/school_validation.php';
require_once __DIR__.'/nepali_dates.php';

require_once __DIR__.'/accounting_money.php';

/** Shared action matrix: direct HTTP and service methods enforce the same capabilities. */
function accountingActionAllowed(string $role,string $action,bool $preview=false):bool {
    $known=['view','catalog','fees','invoice','payment','advance','expense','subscription','settings','approve','credit','refund','reverse','period','opening'];
    if(!in_array($action,$known,true))return false;
    if(!in_array($role,['super_admin','school_admin','accountant','cashier','auditor'],true))return false;
    if($action==='view')return true;
    if($preview||$role==='auditor')return false;
    if(in_array($role,['school_admin','super_admin'],true))return true;
    if($role==='cashier')return in_array($action,['payment','advance'],true);
    return in_array($action,['catalog','fees','invoice','payment','advance','expense','subscription'],true);
}
function accountingRouteAction(string $file,array $input):?string {
    $action=(string)($input['action']??'');
    return match($file){
        'create_bill.php'=>'invoice','generate.php'=>'invoice','payments.php'=>'payment',
        'invoice.php'=>['issue'=>'invoice','advance'=>'advance','credit'=>'credit','update'=>'invoice'][$action]??null,
        'fees.php'=>['category'=>'catalog','item'=>'catalog','rule'=>'fees','scholarship'=>'approve','copy_preview'=>'fees','copy'=>'fees','fine_policy'=>'approve'][$action]??null,
        'settings.php'=>['settings'=>'settings','method'=>'settings','period'=>'period','period_state'=>'period','close_preview'=>'period'][$action]??null,
        'journals.php'=>'settings',
        'student.php'=>['opening'=>'opening','refund'=>'refund','fines_preview'=>'approve','fines'=>'approve','waive_fine'=>'credit'][$action]??null,
        'expenses.php'=>['create'=>'expense','update'=>'expense','upload'=>'expense','approve'=>'approve','pay'=>'expense','reverse'=>'reverse'][$action]??null,
        'subscriptions.php'=>'subscription','credit.php'=>$action==='refund'?'refund':null,
        default=>null
    };
}
final class SchoolAccounting {
    public string $role;private bool $preview;private bool $approvedGeneration=false;public array $settings;
    public function __construct(public PDO $db,public int $school,public int $user,bool $preview=false){
        $q=$db->prepare('SELECT role,school_id,status FROM users WHERE id=?');$q->execute([$user]);$actor=$q->fetch();
        if(!$actor||!$actor['status']||($actor['role']!=='super_admin'&&(int)$actor['school_id']!==$school))throw new InvalidArgumentException('Accounting access requires an active account in this school.');
        $this->role=$actor['role'];$this->preview=$preview;
        if(!in_array($this->role,['school_admin','super_admin','accountant','cashier','auditor'],true))throw new InvalidArgumentException('Your role cannot access accounting.');
        $q=$db->prepare('SELECT id FROM schools WHERE id=?');$q->execute([$school]);if(!$q->fetchColumn())throw new InvalidArgumentException('Choose a school for accounting.');
        $this->settings=$this->one('SELECT * FROM ac_settings WHERE school_id=?',[$school]);
        if(!$this->settings)throw new InvalidArgumentException('Accounting is not provisioned. Ask an administrator to complete the school upgrade.');
    }
    public function can(string $action):bool {
        return accountingActionAllowed($this->role,$action,$this->preview);
    }
    private function authorize(string $action):void{
        $actor=$this->one('SELECT role,school_id,status FROM users WHERE id=?',[$this->user]);
        if(!$actor||!(int)$actor['status']||$actor['role']!==$this->role||($this->role!=='super_admin'&&(int)$actor['school_id']!==$this->school)||!$this->can($action))throw new InvalidArgumentException('Your active account is not authorized for this financial action.');
    }
    public function rows(string $sql,array $values=[]):array{$q=$this->db->prepare($sql);$q->execute($values);return $q->fetchAll();}
    public function one(string $sql,array $values=[]):array{return $this->rows($sql,$values)[0]??[];}
    private function scalar(string $sql,array $values=[]):int{$q=$this->db->prepare($sql);$q->execute($values);return (int)$q->fetchColumn();}
    public function record(string $table,int $id,bool $lock=false):array {
        if(!in_array($table,['ac_accounts','ac_categories','ac_items','ac_fee_rules','ac_scholarships','ac_subscriptions','ac_invoices','ac_payments','ac_credits','ac_expenses','ac_periods','ac_methods','ac_journals'],true))throw new InvalidArgumentException('Invalid accounting record.');
        $row=$this->one("SELECT * FROM $table WHERE id=? AND school_id=?".($lock&&DB_TYPE==='mysql'?' FOR UPDATE':''),[$id,$this->school]);if(!$row)throw new InvalidArgumentException('The financial record does not belong to this school.');return $row;
    }
    public function control(string $key):int{return $this->scalar('SELECT id FROM ac_accounts WHERE school_id=? AND control_key=?',[$this->school,$key]);}
    /** One per-school database lock serializes cashiers, sequences and corrections. */
    private function atomic(callable $fn):mixed {
        if($this->db->inTransaction())throw new RuntimeException('Nested accounting transaction is not supported.');
        $this->db->beginTransaction();
        try{
            $this->db->prepare('UPDATE ac_settings SET due_days=due_days WHERE school_id=?')->execute([$this->school]);
            if(DB_TYPE==='mysql')$this->one('SELECT school_id FROM ac_settings WHERE school_id=? FOR UPDATE',[$this->school]);
            $value=$fn();$this->db->commit();return $value;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function audit(string $action,string $entity,int $id,array $old=[],array $new=[],string $reason=''):void{
        $this->db->prepare('INSERT INTO ac_audit(school_id,actor_id,action,entity,record_id,old_data,new_data,reason) VALUES(?,?,?,?,?,?,?,?)')->execute([$this->school,$this->user,$action,$entity,$id,json_encode($old,JSON_UNESCAPED_UNICODE),json_encode($new,JSON_UNESCAPED_UNICODE),$reason]);
    }
    private function date($date):string{return schoolDateToAd($date)??throw new InvalidArgumentException('A valid date is required.');}
    private function key(array $input):string{$key=trim((string)($input['idempotency_key']??''));if(!preg_match('/^[A-Za-z0-9:_.-]{12,150}$/',$key))throw new InvalidArgumentException('Reload this form to obtain a valid submission key.');return $key;}
    private function number(string $kind,string $date):string {
        $year=substr(schoolAdToBs($date)??$date,0,4);$r=$this->one('SELECT next_number FROM ac_sequences WHERE school_id=? AND kind=? AND year_key=?',[$this->school,$kind,$year]);$n=(int)($r['next_number']??1);
        if($r)$this->db->prepare('UPDATE ac_sequences SET next_number=? WHERE school_id=? AND kind=? AND year_key=?')->execute([$n+1,$this->school,$kind,$year]);else $this->db->prepare('INSERT INTO ac_sequences VALUES(?,?,?,?)')->execute([$this->school,$kind,$year,$n+1]);
        $prefix=match($kind){'invoice'=>$this->settings['invoice_prefix'],'receipt'=>$this->settings['receipt_prefix'],'credit'=>'CRN','expense'=>'EXP',default=>'JRN'};return $prefix.'-'.$year.'-'.str_pad((string)$n,6,'0',STR_PAD_LEFT);
    }
    private function period(string $date):array {
        $periods=$this->rows('SELECT * FROM ac_periods WHERE school_id=? AND start_date<=? AND end_date>=? ORDER BY start_date DESC',[$this->school,$date,$date]);
        if(count($periods)!==1)throw new InvalidArgumentException('Configure exactly one fiscal period covering this posting date.');
        if($periods[0]['is_closed'])throw new InvalidArgumentException('This fiscal period is closed. An administrator must reopen it before posting.');return $periods[0];
    }
    private function journal(string $type,int $id,string $date,string $description,array $lines,?int $reversal=null):int {
        $period=$this->period($date);$dr=0;$cr=0;$valid=[];
        foreach($lines as $line){[$account,$debit,$credit,$student]=$line;$a=$this->record('ac_accounts',(int)$account);if(!$a['is_active']||!is_int($debit)||!is_int($credit)||$debit<0||$credit<0||($debit&&$credit))throw new InvalidArgumentException('Journal lines require active accounts and non-negative exact amounts on one side only.');if($student)schoolRecord($this->db,'students',(int)$student,$this->school);$dr=acCheckedAdd($dr,$debit);$cr=acCheckedAdd($cr,$credit);if($debit||$credit)$valid[]=$line;}
        if(!$valid||$dr!==$cr)throw new InvalidArgumentException('Journal debit and credit totals must balance exactly.');
        $this->db->prepare('INSERT INTO ac_journals(school_id,number,posting_date,period_id,reference_type,reference_id,description,created_by,reversal_of) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$this->school,$this->number('journal',$date),$date,$period['id'],$type,$id,$description,$this->user,$reversal]);$journal=(int)$this->db->lastInsertId();
        foreach($valid as [$account,$debit,$credit,$student])$this->db->prepare('INSERT INTO ac_journal_lines(journal_id,account_id,debit_minor,credit_minor,student_id) VALUES(?,?,?,?,?)')->execute([$journal,$account,$debit,$credit,$student]);
        $this->audit('Post balanced journal','ac_journals',$journal,[],['type'=>$type,'reference'=>$id,'debit_minor'=>$dr,'credit_minor'=>$cr]);return $journal;
    }
    public function saveCategory(array $in):int {
        $this->authorize('catalog');$name=trim($in['name']??'');$kind=$in['kind']??'fee';if(!$name||strlen($name)>120||!in_array($kind,['fee','expense'],true))throw new InvalidArgumentException('Enter a category name and valid category type.');
        return $this->atomic(function()use($in,$name,$kind){$id=(int)($in['id']??0);$old=$id?$this->record('ac_categories',$id):[];if($id)$this->db->prepare('UPDATE ac_categories SET name=?,is_active=? WHERE id=? AND school_id=?')->execute([$name,empty($in['is_active'])?0:1,$id,$this->school]);else{$this->db->prepare('INSERT INTO ac_categories(school_id,name,kind) VALUES(?,?,?)')->execute([$this->school,$name,$kind]);$id=(int)$this->db->lastInsertId();}$this->audit('Save category','ac_categories',$id,$old,$in);return $id;});
    }
    public function saveAccount(array $in):int {
        $this->authorize('settings');$code=trim($in['code']??'');$name=trim($in['name']??'');$type=$in['type']??'';
        if(!preg_match('/^[A-Za-z0-9_.-]{1,30}$/',$code)||!$name||strlen($name)>120||!in_array($type,['asset','liability','equity','income','expense','contra_income'],true))throw new InvalidArgumentException('Enter a valid account code, name and type.');
        return $this->atomic(function()use($code,$name,$type){$this->db->prepare('INSERT INTO ac_accounts(school_id,code,name,type) VALUES(?,?,?,?)')->execute([$this->school,$code,$name,$type]);$id=(int)$this->db->lastInsertId();$this->audit('Create account','ac_accounts',$id,[],compact('code','name','type'));return $id;});
    }
    public function saveItem(array $in):int {
        $this->authorize('catalog');$name=trim($in['name']??'');$code=trim($in['code']??'');$kind=$in['kind']??'fee';$frequency=$in['frequency']??'manual';$rate=acDecimalUnits($in['rate']??'0');$tax=acDecimalUnits($in['tax_percent']??'0');
        if(!$name||strlen($name)>200||!preg_match('/^[A-Za-z0-9_.-]{1,50}$/',$code)||!in_array($kind,['fee','product','service'],true)||!in_array($frequency,['monthly','quarterly','annual','one_time','exam','manual'],true)||$tax>10000)throw new InvalidArgumentException('Enter valid item information, frequency and tax percentage.');
        $account=$this->record('ac_accounts',(int)($in['income_account_id']??$this->control('income')));if($account['type']!=='income')throw new InvalidArgumentException('Items must map to an income account.');
        $category=(int)($in['category_id']??0);if($category)$this->record('ac_categories',$category);
        if($tax&&!$this->settings['tax_registered'])throw new InvalidArgumentException('Configure applicable tax registration before adding a taxable item.');
        return $this->atomic(function()use($in,$code,$name,$kind,$frequency,$rate,$tax,$account,$category){$id=(int)($in['id']??0);$old=$id?$this->record('ac_items',$id):[];
            $values=[$code,$name,trim($in['name_np']??''),$category?:null,trim($in['description']??''),$kind,trim($in['unit']??'item'),$rate,$frequency,$account['id'],$tax,empty($in['discount_eligible'])?0:1,empty($in['fine_eligible'])?0:1,empty($in['is_active'])?0:1];
            $fields=['code','name','name_np','category_id','description','kind','unit','rate_minor','frequency','income_account_id','tax_basis_points','discount_eligible','fine_eligible','is_active'];
            if($id)$this->db->prepare('UPDATE ac_items SET '.implode(',',array_map(fn($k)=>$k.'=?',$fields)).' WHERE id=? AND school_id=?')->execute([...$values,$id,$this->school]);
            else{$this->db->prepare('INSERT INTO ac_items('.implode(',',$fields).',school_id,created_by) VALUES('.implode(',',array_fill(0,count($fields)+2,'?')).')')->execute([...$values,$this->school,$this->user]);$id=(int)$this->db->lastInsertId();}
            $this->audit('Save fee/product/service','ac_items',$id,$old,$in);return $id;
        });
    }
    public function saveFeeRule(array $in):int {
        $this->authorize('fees');$item=$this->record('ac_items',(int)($in['item_id']??0));$year=schoolRecord($this->db,'academic_years',(int)($in['academic_year_id']??0),$this->school);
        $refs=[];foreach(['class_id'=>'classes','section_id'=>'sections','student_id'=>'students','exam_id'=>'exams','subject_id'=>'subjects'] as $k=>$table){$refs[$k]=!empty($in[$k])?schoolRecord($this->db,$table,(int)$in[$k],$this->school):null;}
        if(!$refs['class_id']&&!$refs['student_id'])throw new InvalidArgumentException('Choose a class or individual student for this fee assignment.');
        if($refs['section_id']&&$refs['section_id']['class_id']!=($refs['class_id']['id']??0))throw new InvalidArgumentException('Choose a section in the selected class.');
        if($refs['student_id']&&($refs['student_id']['academic_year_id']!=$year['id']||($refs['class_id']&&$refs['student_id']['class_id']!=$refs['class_id']['id'])))throw new InvalidArgumentException('Student, class and academic year must match for a new fee assignment.');
        $start=$this->date($in['effective_from']??'');$end=!empty($in['effective_to'])?$this->date($in['effective_to']):null;if($end&&$end<$start)throw new InvalidArgumentException('The effective end date precedes the start date.');
        $amount=acDecimalUnits($in['amount']??'0');$discount=acDecimalUnits($in['discount']??'0');$pct=acDecimalUnits($in['discount_percent']??'0');if($discount>$amount||$pct>10000)throw new InvalidArgumentException('Discount cannot exceed the fee.');if(($discount||$pct||!empty($in['is_exempt']))&&!$this->can('approve'))throw new InvalidArgumentException('Scholarships, waivers and fee discounts require administrator approval.');
        return $this->atomic(function()use($in,$item,$year,$refs,$start,$end,$amount,$discount,$pct){$values=[$this->school,$item['id'],$year['id'],...array_map(fn($r)=>$r['id']??null,$refs),trim($in['stream']??''),$amount,$discount,$pct,empty($in['is_exempt'])?0:1,empty($in['is_optional'])?0:1,$start,$end,$this->user];
            $this->db->prepare('INSERT INTO ac_fee_rules(school_id,item_id,academic_year_id,class_id,section_id,student_id,exam_id,subject_id,stream,amount_minor,discount_minor,discount_basis_points,is_exempt,is_optional,effective_from,effective_to,created_by) VALUES('.implode(',',array_fill(0,17,'?')).')')->execute($values);$id=(int)$this->db->lastInsertId();$this->audit('Assign historical fee rate','ac_fee_rules',$id,[],$in);return $id;
        });
    }
    public function saveScholarship(array $in):int {
        $this->authorize('approve');$student=schoolRecord($this->db,'students',(int)$in['student_id'],$this->school);$year=schoolRecord($this->db,'academic_years',(int)$in['academic_year_id'],$this->school);$item=(int)($in['item_id']??0);if($item)$this->record('ac_items',$item);
        $name=trim($in['name']??'');$amount=acDecimalUnits($in['amount']??'0');$pct=acDecimalUnits($in['percent']??'0');$start=$this->date($in['effective_from']??'');$end=!empty($in['effective_to'])?$this->date($in['effective_to']):null;
        if(!$name||$pct>10000||($end&&$end<$start))throw new InvalidArgumentException('Enter a scholarship name, valid percentage and dates.');
        return $this->atomic(function()use($in,$student,$year,$item,$name,$amount,$pct,$start,$end){$this->db->prepare('INSERT INTO ac_scholarships(school_id,student_id,item_id,academic_year_id,name,amount_minor,basis_points,effective_from,effective_to,approved_by,remarks) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$this->school,$student['id'],$item?:null,$year['id'],$name,$amount,$pct,$start,$end,$this->user,trim($in['remarks']??'')]);$id=(int)$this->db->lastInsertId();$this->audit('Approve scholarship','ac_scholarships',$id,[],$in);return $id;});
    }
    /** Identical request keys are replay-safe; changed content under one key is rejected. */
    private function replay(string $table,string $key,string $hash):?int {$row=$this->one("SELECT id,request_hash FROM $table WHERE school_id=? AND idempotency_key=?",[$this->school,$key]);if(!$row)return null;if(!hash_equals($row['request_hash'],$hash))throw new InvalidArgumentException('This submission key was already used for different data. Reload the form.');return (int)$row['id'];}
    public function previewInvoice(array $in):array {
        $student=!empty($in['student_id'])?schoolRecord($this->db,'students',(int)$in['student_id'],$this->school):null;
        $customer=trim($in['customer_name']??'');if($student)$customer=trim($student['first_name_en'].' '.$student['last_name_en']);
        if(!$customer||strlen($customer)>200)throw new InvalidArgumentException('Choose a student or enter a customer name.');
        $year=!empty($in['academic_year_id'])?schoolRecord($this->db,'academic_years',(int)$in['academic_year_id'],$this->school):null;
        $date=$this->date($in['bill_date']??'');$due=$this->date($in['due_date']??$date);if($due<$date)throw new InvalidArgumentException('Due date cannot precede bill date.');
        $items=[];$subtotal=0;$lineDiscount=0;
        foreach((array)($in['items']??[]) as $r){if(empty($r['particular'])&&empty($r['item_id']))continue;
            $item=!empty($r['item_id'])?$this->record('ac_items',(int)$r['item_id']):null;if($item&&!$item['is_active'])throw new InvalidArgumentException('This fee or product is inactive.');
            $name=trim($r['particular']??($item['name']??''));if(!$name||strlen($name)>200)throw new InvalidArgumentException('Every bill item needs a name up to 200 characters.');
            $quantity=acDecimalUnits($r['quantity']??'1',3);if(!$quantity||$quantity>1000000)throw new InvalidArgumentException('Quantity must be greater than zero and at most 1000.');
            $rate=acDecimalUnits($r['rate']??($item?acAmount((int)$item['rate_minor']):'0'));$gross=acMulDiv($rate,$quantity,1000);$discount=acDecimalUnits($r['discount']??'0');
            if($discount>$gross||($discount&&$item&&!$item['discount_eligible']))throw new InvalidArgumentException('Line discount is invalid or disabled for this item.');
            $tax=$item?(int)$item['tax_basis_points']:acDecimalUnits($r['tax_percent']??'0');if($tax>10000||($tax&&!$this->settings['tax_registered']))throw new InvalidArgumentException('Tax needs valid school tax registration and a configured rate.');
            $account=$this->record('ac_accounts',(int)($r['income_account_id']??$item['income_account_id']??$this->control('income')));if($account['type']!=='income'||!$account['is_active'])throw new InvalidArgumentException('Select an active income account.');
            $items[]=['item_id'=>$item['id']??null,'particular'=>$name,'quantity_milli'=>$quantity,'rate_minor'=>$rate,'gross_minor'=>$gross,'discount_minor'=>$discount,'tax_basis_points'=>$tax,'income_account_id'=>$account['id'],'category_id'=>$item['category_id']??null,'fine_eligible'=>$item['fine_eligible']??0,'discount_eligible'=>$item['discount_eligible']??1];$subtotal=acCheckedAdd($subtotal,$gross);$lineDiscount=acCheckedAdd($lineDiscount,$discount);
        }
        if(!$items||count($items)>100)throw new InvalidArgumentException('Add between 1 and 100 invoice items.');
        $billDiscount=acDecimalUnits($in['bill_discount']??'0');$net=$subtotal-$lineDiscount;$eligible=array_sum(array_map(fn($r)=>$r['discount_eligible']?$r['gross_minor']-$r['discount_minor']:0,$items));
        if($billDiscount>$eligible)throw new InvalidArgumentException('Bill discount exceeds discount-eligible items.');
        $discountTotal=acCheckedAdd($billDiscount,$lineDiscount);
        if($discountTotal>(int)$this->settings['discount_approval_minor']&&!$this->can('approve')&&!$this->approvedGeneration)throw new InvalidArgumentException('This discount requires school administrator approval.');
        $remaining=$billDiscount;$weight=$eligible;$taxTotal=0;$total=0;
        foreach($items as &$r){$base=$r['gross_minor']-$r['discount_minor'];$portion=$r['discount_eligible']&&$weight>0?min($remaining,acMulDiv($remaining,$base,$weight)):0;$r['discount_minor']=acCheckedAdd($r['discount_minor'],$portion);$remaining-=$portion;if($r['discount_eligible'])$weight-=$base;$r['tax_minor']=acMulDiv($r['gross_minor']-$r['discount_minor'],(int)$r['tax_basis_points'],10000);$r['total_minor']=acCheckedAdd($r['gross_minor']-$r['discount_minor'],$r['tax_minor']);$taxTotal=acCheckedAdd($taxTotal,$r['tax_minor']);$total=acCheckedAdd($total,$r['total_minor']);unset($r['discount_eligible']);}unset($r);
        if($remaining!==0||$total<0)throw new InvalidArgumentException('Discount allocation failed.');
        return ['student'=>$student,'customer_name'=>$customer,'customer_type'=>$student?'student':($in['customer_type']??'external'),'academic_year_id'=>$year['id']??($student['academic_year_id']??null),'bill_date'=>$date,'due_date'=>$due,'items'=>$items,'subtotal_minor'=>$subtotal,'discount_minor'=>$discountTotal,'tax_minor'=>$taxTotal,'total_minor'=>$total];
    }
    public function createInvoice(array $in,bool $issue=false):int {
        $this->authorize('invoice');$key=$this->key($in);$hash=hash('sha256',json_encode(['issue'=>$issue,'input'=>$in],JSON_UNESCAPED_UNICODE));
        return $this->atomic(function()use($in,$issue,$key,$hash){if(($id=$this->replay('ac_invoices',$key,$hash))!==null)return $id;$p=$this->previewInvoice($in);$s=$p['student'];
            $this->db->prepare('INSERT INTO ac_invoices(school_id,academic_year_id,class_id,section_id,student_id,customer_type,customer_name,guardian_name,phone,address,description,bill_date,due_date,billing_period,subtotal_minor,discount_minor,tax_minor,total_minor,idempotency_key,request_hash,created_by) VALUES('.implode(',',array_fill(0,21,'?')).')')->execute([$this->school,$p['academic_year_id'],$s['class_id']??null,$s['section_id']??null,$s['id']??null,$p['customer_type'],$p['customer_name'],trim($in['guardian_name']??$s['guardian_name']??$s['father_name']??''),trim($in['phone']??$s['guardian_phone']??''),trim($in['address']??''),trim($in['description']??''),$p['bill_date'],$p['due_date'],trim($in['billing_period']??''),$p['subtotal_minor'],$p['discount_minor'],$p['tax_minor'],$p['total_minor'],$key,$hash,$this->user]);$id=(int)$this->db->lastInsertId();
            foreach($p['items'] as $r){$fields=array_keys($r);$this->db->prepare('INSERT INTO ac_invoice_items(invoice_id,'.implode(',',$fields).') VALUES('.implode(',',array_fill(0,count($fields)+1,'?')).')')->execute([$id,...array_values($r)]);}
            $this->audit('Save invoice draft','ac_invoices',$id,[],['total_minor'=>$p['total_minor']]);if($issue)$this->issueInside($id);return $id;
        });
    }
    private function issueInside(int $id):void {
        $i=$this->record('ac_invoices',$id,true);if($i['status']!=='draft')return;$this->period($i['bill_date']);$lines=[[$this->control('receivable'),(int)$i['total_minor'],0,$i['student_id']]];
        $items=$this->rows('SELECT * FROM ac_invoice_items WHERE invoice_id=?',[$id]);foreach($items as $r)$lines[]=[(int)$r['income_account_id'],0,(int)$r['gross_minor'],$i['student_id']];
        if($i['discount_minor'])$lines[]=[$this->control('discount'),(int)$i['discount_minor'],0,$i['student_id']];if($i['tax_minor'])$lines[]=[$this->control('tax'),0,(int)$i['tax_minor'],$i['student_id']];
        if((int)$i['total_minor']>0||$i['subtotal_minor']>0)$this->journal('invoice',$id,$i['bill_date'],'Issue invoice '.$id,$lines);
        $number=$this->number('invoice',$i['bill_date']);$this->db->prepare("UPDATE ac_invoices SET number=?,status='issued',issued_at=CURRENT_TIMESTAMP WHERE id=? AND school_id=?")->execute([$number,$id,$this->school]);$this->audit('Issue invoice','ac_invoices',$id,$i,['number'=>$number]);
    }
    public function issueInvoice(int $id):void{$this->authorize('invoice');$this->atomic(fn()=>$this->issueInside($id));}
    public function invoiceBalance(int $id):int {
        $i=$this->record('ac_invoices',$id);if(in_array($i['status'],['draft','void'],true))return 0;
        $paid=$this->scalar("SELECT COALESCE(SUM(a.amount_minor),0) FROM ac_allocations a JOIN ac_payments p ON p.id=a.payment_id WHERE a.invoice_id=? AND p.school_id=? AND p.status='posted'",[$id,$this->school]);
        $credits=$this->scalar('SELECT COALESCE(SUM(amount_minor),0) FROM ac_credits WHERE school_id=? AND invoice_id=?',[$this->school,$id]);
        $advance=$this->scalar("SELECT COALESCE(-SUM(amount_minor),0) FROM ac_advance_entries WHERE school_id=? AND invoice_id=? AND kind='apply'",[$this->school,$id]);
        return (int)$i['total_minor']-$paid-$credits-$advance;
    }
    public function invoiceStatus(array $i):string {if(in_array($i['status'],['draft','void'],true))return $i['status'];$balance=$this->invoiceBalance((int)$i['id']);if($balance===0)return 'paid';if($i['due_date']<date('Y-m-d'))return 'overdue';return $balance<(int)$i['total_minor']?'partially_paid':'unpaid';}
    public function outstanding(?int $student=null):int{$ids=$this->rows("SELECT id FROM ac_invoices WHERE school_id=? AND status='issued'".($student!==null?' AND student_id=?':''),$student!==null?[$this->school,$student]:[$this->school]);$sum=0;foreach($ids as $r)$sum=acCheckedAdd($sum,$this->invoiceBalance((int)$r['id']));return $sum;}
    public function advanceBalance(int $student):int {schoolRecord($this->db,'students',$student,$this->school);return $this->scalar('SELECT COALESCE(SUM(amount_minor),0) FROM ac_advance_entries WHERE school_id=? AND student_id=?',[$this->school,$student]);}
    public function recordPayment(array $in):int {
        $this->authorize('payment');$key=$this->key($in);$hash=hash('sha256',json_encode($in));$amount=acDecimalUnits($in['amount']??'0');if(!$amount)throw new InvalidArgumentException('Payment amount must be positive.');$date=$this->date($in['payment_date']??'');
        return $this->atomic(function()use($in,$key,$hash,$amount,$date){if(($id=$this->replay('ac_payments',$key,$hash))!==null)return $id;
            $student=!empty($in['student_id'])?schoolRecord($this->db,'students',(int)$in['student_id'],$this->school):null;$method=$this->record('ac_methods',(int)($in['method_id']??0));$account=$this->record('ac_accounts',(int)$method['account_id']);if(!$method['is_active']||!$account['is_active']||$account['type']!=='asset')throw new InvalidArgumentException('Choose an active cash/bank payment method.');
            $reference=trim($in['reference']??'');if($method['requires_reference']&&($reference===''||empty($in['externally_confirmed'])))throw new InvalidArgumentException('Confirm the external bank/digital payment and enter its transaction reference; this form does not process an online gateway.');
            $allocations=[];$allocated=0;$customer=trim($in['customer_name']??'');
            foreach((array)($in['allocations']??[]) as $invoice=>$v){if($v==='')continue;$value=acDecimalUnits($v);if(!$value)continue;$i=$this->record('ac_invoices',(int)$invoice,true);if($i['status']!=='issued'||($student&&(int)$i['student_id']!==(int)$student['id'])||(!$student&&!empty($i['student_id'])))throw new InvalidArgumentException('Select issued invoices for the chosen student/customer.');if(!$student&&$customer!==$i['customer_name'])throw new InvalidArgumentException('External invoice customer must match the payment customer.');if($value>$this->invoiceBalance((int)$invoice))throw new InvalidArgumentException('Payment allocation exceeds the current invoice balance. Reload balances.');$allocations[(int)$invoice]=$value;$allocated=acCheckedAdd($allocated,$value);}
            if($allocated>$amount)throw new InvalidArgumentException('Invoice allocations exceed the payment amount.');$excess=$amount-$allocated;if($excess&&(!$student||empty($in['allow_advance'])))throw new InvalidArgumentException('Explicitly allow the unallocated student amount as an advance, or allocate the entire payment.');
            if($student)$customer=trim($student['first_name_en'].' '.$student['last_name_en']);if(!$customer)throw new InvalidArgumentException('Choose a student or enter the invoice customer name.');
            $number=$this->number('receipt',$date);$this->db->prepare('INSERT INTO ac_payments(school_id,number,student_id,customer_name,payment_date,amount_minor,method_id,account_id,reference,remarks,idempotency_key,request_hash,received_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$this->school,$number,$student['id']??null,$customer,$date,$amount,$method['id'],$account['id'],$reference,trim($in['remarks']??''),$key,$hash,$this->user]);$id=(int)$this->db->lastInsertId();
            foreach($allocations as $invoice=>$value)$this->db->prepare('INSERT INTO ac_allocations(payment_id,invoice_id,amount_minor) VALUES(?,?,?)')->execute([$id,$invoice,$value]);
            $lines=[[(int)$account['id'],$amount,0,$student['id']??null]];if($allocated)$lines[]=[$this->control('receivable'),0,$allocated,$student['id']??null];
            if($excess){$this->db->prepare("INSERT INTO ac_advance_entries(school_id,student_id,payment_id,amount_minor,entry_date,kind,reference_key,created_by) VALUES(?,?,?,?,?,'receive',?,?)")->execute([$this->school,$student['id'],$id,$excess,$date,'receive:'.$id,$this->user]);$lines[]=[$this->control('advance'),0,$excess,$student['id']];}
            $this->journal('payment',$id,$date,'Receive payment '.$number,$lines);$this->audit('Receive confirmed payment','ac_payments',$id,[],['amount_minor'=>$amount,'allocated_minor'=>$allocated,'advance_minor'=>$excess]);return $id;
        });
    }
    public function applyAdvance(array $in):int {
        $this->authorize('advance');$key=$this->key($in);$amount=acDecimalUnits($in['amount']??'0');$date=$this->date($in['date']??'');if(!$amount)throw new InvalidArgumentException('Enter a positive advance allocation.');
        return $this->atomic(function()use($in,$key,$amount,$date){$r=$this->one('SELECT * FROM ac_advance_entries WHERE school_id=? AND reference_key=?',[$this->school,'apply:'.$key]);if($r){if((int)$r['invoice_id']!==(int)$in['invoice_id']||-(int)$r['amount_minor']!==$amount||$r['entry_date']!==$date)throw new InvalidArgumentException('This key was already used for different advance data.');return (int)$r['id'];}
            $i=$this->record('ac_invoices',(int)$in['invoice_id'],true);if(!$i['student_id']||$i['status']!=='issued'||$amount>$this->invoiceBalance((int)$i['id'])||$amount>$this->advanceBalance((int)$i['student_id']))throw new InvalidArgumentException('Advance or invoice balance is insufficient.');
            $this->db->prepare("INSERT INTO ac_advance_entries(school_id,student_id,invoice_id,amount_minor,entry_date,kind,reference_key,created_by) VALUES(?,?,?,?,?,'apply',?,?)")->execute([$this->school,$i['student_id'],$i['id'],-$amount,$date,'apply:'.$key,$this->user]);$id=(int)$this->db->lastInsertId();$this->journal('advance_apply',$id,$date,'Apply student advance',[[$this->control('advance'),$amount,0,$i['student_id']],[$this->control('receivable'),0,$amount,$i['student_id']]]);$this->audit('Apply advance','ac_advance_entries',$id,[],['invoice'=>$i['id'],'amount_minor'=>$amount]);return $id;
        });
    }
    public function creditInvoice(array $in):int {
        $this->authorize('credit');if(empty($in['confirm']))throw new InvalidArgumentException('Confirm the credit/cancellation warning.');$key=$this->key($in);$amount=acDecimalUnits($in['amount']??'0');$date=$this->date($in['date']??'');$reason=trim($in['reason']??'');if(!$reason||!$amount)throw new InvalidArgumentException('A positive amount and correction reason are required.');
        return $this->atomic(function()use($in,$key,$amount,$date,$reason){$existing=$this->one('SELECT * FROM ac_credits WHERE school_id=? AND idempotency_key=?',[$this->school,$key]);if($existing){if((int)$existing['invoice_id']!==(int)$in['invoice_id']||(int)$existing['amount_minor']!==$amount||$existing['credit_date']!==$date||$existing['reason']!==$reason)throw new InvalidArgumentException('This credit key already has different data.');return (int)$existing['id'];}
            $i=$this->record('ac_invoices',(int)$in['invoice_id'],true);if($i['status']!=='issued'||$amount>$this->invoiceBalance((int)$i['id']))throw new InvalidArgumentException('Credit cannot exceed the unpaid invoice balance. Reverse/refund payments explicitly when required.');
            $void=!empty($in['void']);if($void&&($amount!==(int)$i['total_minor']||$this->invoiceBalance((int)$i['id'])!==(int)$i['total_minor']))throw new InvalidArgumentException('Only a completely unpaid, unadjusted invoice can be voided. Use a credit note otherwise.');
            $this->db->prepare('INSERT INTO ac_credits(school_id,number,invoice_id,credit_date,amount_minor,reason,kind,created_by,idempotency_key) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$this->school,$this->number('credit',$date),$i['id'],$date,$amount,$reason,$void?'void':'credit',$this->user,$key]);$id=(int)$this->db->lastInsertId();
            $remaining=$amount;$base=(int)$i['total_minor'];$tax=(int)$i['tax_minor'];$taxCredit=$base>0?acMulDiv($amount,$tax,$base):0;$lines=[[$this->control('discount'),$amount-$taxCredit,0,$i['student_id']],[$this->control('receivable'),0,$amount,$i['student_id']]];if($taxCredit)$lines[]=[$this->control('tax'),$taxCredit,0,$i['student_id']];$this->journal('credit',$id,$date,$reason,$lines);
            if($void)$this->db->prepare("UPDATE ac_invoices SET status='void' WHERE id=? AND school_id=?")->execute([$i['id'],$this->school]);$this->audit($void?'Void invoice':'Approve credit note','ac_credits',$id,$i,['amount_minor'=>$amount],$reason);return $id;
        });
    }
    public function reversePayment(int $id,string $date,string $reason,bool $confirmed):void {
        $this->authorize('reverse');$date=$this->date($date);if(!$confirmed||trim($reason)==='')throw new InvalidArgumentException('Confirm reversal and provide a reason.');
        $this->atomic(function()use($id,$date,$reason){$p=$this->record('ac_payments',$id,true);if($p['status']==='reversed')return;$advance=$this->scalar("SELECT COALESCE(SUM(amount_minor),0) FROM ac_advance_entries WHERE school_id=? AND payment_id=? AND kind='receive'",[$this->school,$id]);if($advance&&$advance>$this->advanceBalance((int)$p['student_id']))throw new InvalidArgumentException('This payment advance has been used or refunded. Restore it before reversal.');
            $j=$this->one("SELECT * FROM ac_journals WHERE school_id=? AND reference_type='payment' AND reference_id=?",[$this->school,$id]);if(!$j)throw new InvalidArgumentException('The original payment journal is missing.');$lines=[];foreach($this->rows('SELECT * FROM ac_journal_lines WHERE journal_id=?',[$j['id']]) as $l)$lines[]=[(int)$l['account_id'],(int)$l['credit_minor'],(int)$l['debit_minor'],$l['student_id']];$this->journal('payment_reverse',$id,$date,$reason,$lines,(int)$j['id']);
            if($advance)$this->db->prepare("INSERT INTO ac_advance_entries(school_id,student_id,payment_id,amount_minor,entry_date,kind,reference_key,created_by) VALUES(?,?,?,?,?,'reverse',?,?)")->execute([$this->school,$p['student_id'],$id,-$advance,$date,'reverse:'.$id,$this->user]);$this->db->prepare("UPDATE ac_payments SET status='reversed' WHERE id=? AND school_id=?")->execute([$id,$this->school]);$this->audit('Reverse payment','ac_payments',$id,$p,['status'=>'reversed'],$reason);
        });
    }
    /** Refund only available advance; paid invoice refunds first require a reviewed credit/reversal. */
    public function refundAdvance(array $in):int {
        $this->authorize('refund');if(empty($in['confirm']))throw new InvalidArgumentException('Confirm the refund warning.');$key=$this->key($in);$amount=acDecimalUnits($in['amount']??'0');$date=$this->date($in['date']??'');$reason=trim($in['reason']??'');if(!$amount||!$reason)throw new InvalidArgumentException('A positive refund and reason are required.');
        return $this->atomic(function()use($in,$key,$amount,$date,$reason){$old=$this->one('SELECT * FROM ac_refunds WHERE school_id=? AND idempotency_key=?',[$this->school,$key]);if($old){if((int)$old['student_id']!==(int)$in['student_id']||(int)$old['amount_minor']!==$amount||$old['refund_date']!==$date||$old['reason']!==$reason||(int)$old['account_id']!==(int)$in['account_id'])throw new InvalidArgumentException('This refund key already has different data.');return (int)$old['id'];}
            $student=schoolRecord($this->db,'students',(int)$in['student_id'],$this->school);$account=$this->record('ac_accounts',(int)$in['account_id']);if($account['type']!=='asset'||!$account['is_active']||$amount>$this->advanceBalance((int)$student['id']))throw new InvalidArgumentException('Choose an active cash/bank account and an amount within available advance.');
            $this->db->prepare('INSERT INTO ac_refunds(school_id,student_id,amount_minor,refund_date,account_id,reason,approved_by,idempotency_key) VALUES(?,?,?,?,?,?,?,?)')->execute([$this->school,$student['id'],$amount,$date,$account['id'],$reason,$this->user,$key]);$id=(int)$this->db->lastInsertId();$this->db->prepare("INSERT INTO ac_advance_entries(school_id,student_id,amount_minor,entry_date,kind,reference_key,created_by) VALUES(?,?,?,?,'refund',?,?)")->execute([$this->school,$student['id'],-$amount,$date,'refund:'.$id,$this->user]);$this->journal('refund',$id,$date,$reason,[[$this->control('advance'),$amount,0,$student['id']],[(int)$account['id'],0,$amount,$student['id']]]);$this->audit('Approve advance refund','ac_refunds',$id,[],['amount_minor'=>$amount],$reason);return $id;
        });
    }
    public function savePeriod(array $in):int {
        $this->authorize('period');$start=$this->date($in['start_date']??'');$end=$this->date($in['end_date']??'');$name=trim($in['name']??'');if(!$name||strlen($name)>100||$end<$start)throw new InvalidArgumentException('Enter a fiscal period name and ordered dates.');
        return $this->atomic(function()use($name,$start,$end){if($this->scalar('SELECT COUNT(*) FROM ac_periods WHERE school_id=? AND start_date<=? AND end_date>=?',[$this->school,$end,$start]))throw new InvalidArgumentException('Fiscal periods may not overlap.');$this->db->prepare('INSERT INTO ac_periods(school_id,name,start_date,end_date) VALUES(?,?,?,?)')->execute([$this->school,$name,$start,$end]);$id=(int)$this->db->lastInsertId();$this->audit('Create fiscal period','ac_periods',$id,[],compact('name','start','end'));return $id;});
    }
    public function closePeriod(int $id,bool $close,string $reason,bool $confirm):void {
        $this->authorize('period');if(!$confirm||!trim($reason))throw new InvalidArgumentException('Confirm the period change and enter a reason.');$this->atomic(function()use($id,$close,$reason){$p=$this->record('ac_periods',$id);$this->db->prepare('UPDATE ac_periods SET is_closed=?,closed_by=? WHERE id=? AND school_id=?')->execute([$close?1:0,$this->user,$id,$this->school]);$this->audit($close?'Close fiscal period':'Reopen fiscal period','ac_periods',$id,$p,['is_closed'=>$close],$reason);});
    }
    public function saveSettings(array $in):void {
        $this->authorize('settings');$pan=trim($in['pan_number']??'');$registered=empty($in['tax_registered'])?0:1;$invoice=trim($in['invoice_prefix']??'INV');$receipt=trim($in['receipt_prefix']??'RCP');$days=filter_var($in['due_days']??30,FILTER_VALIDATE_INT);$threshold=acDecimalUnits($in['discount_approval']??'0');
        if(($registered&&!$pan)||!preg_match('/^[A-Za-z0-9-]{1,30}$/',$invoice)||!preg_match('/^[A-Za-z0-9-]{1,30}$/',$receipt)||$days===false||$days<0||$days>365)throw new InvalidArgumentException('Enter valid numbering prefixes, due days and tax registration where applicable.');
        $this->atomic(function()use($in,$pan,$registered,$invoice,$receipt,$days,$threshold){$this->db->prepare('UPDATE ac_settings SET pan_number=?,tax_registered=?,invoice_prefix=?,receipt_prefix=?,due_days=?,discount_approval_minor=?,prorate=? WHERE school_id=?')->execute([$pan,$registered,$invoice,$receipt,$days,$threshold,empty($in['prorate'])?0:1,$this->school]);$this->audit('Change accounting settings','ac_settings',$this->school,$this->settings,$in);});$this->settings=$this->one('SELECT * FROM ac_settings WHERE school_id=?',[$this->school]);
    }
    public function saveMethod(array $in):int {
        $this->authorize('settings');$account=$this->record('ac_accounts',(int)($in['account_id']??0));$name=trim($in['name']??'');if($account['type']!=='asset'||!$account['is_active']||!$name||strlen($name)>100)throw new InvalidArgumentException('Enter a payment method name and an active cash/bank asset account.');
        return $this->atomic(function()use($in,$account,$name){$this->db->prepare('INSERT INTO ac_methods(school_id,name,account_id,requires_reference) VALUES(?,?,?,?)')->execute([$this->school,$name,$account['id'],empty($in['requires_reference'])?0:1]);$id=(int)$this->db->lastInsertId();$this->audit('Create payment method','ac_methods',$id,[],$in);return $id;});
    }
    public function createExpense(array $in):int {
        $this->authorize('expense');$key=$this->key($in);$date=$this->date($in['expense_date']??'');$amount=acDecimalUnits($in['amount']??'0');$title=trim($in['title']??'');$expense=$this->record('ac_accounts',(int)($in['expense_account_id']??0));$cash=$this->record('ac_accounts',(int)($in['payment_account_id']??0));$category=(int)($in['category_id']??0);if($category)$this->record('ac_categories',$category);
        if(!$amount||!$title||strlen($title)>200||$expense['type']!=='expense'||$cash['type']!=='asset'||!$expense['is_active']||!$cash['is_active'])throw new InvalidArgumentException('Enter a positive expense, title and valid expense/cash accounts.');
        return $this->atomic(function()use($in,$key,$date,$amount,$title,$expense,$cash,$category){$old=$this->one('SELECT * FROM ac_expenses WHERE school_id=? AND idempotency_key=?',[$this->school,$key]);if($old){if($old['title']!==$title||(int)$old['amount_minor']!==$amount||$old['expense_date']!==$date||(int)$old['expense_account_id']!==(int)$expense['id']||(int)$old['payment_account_id']!==(int)$cash['id'])throw new InvalidArgumentException('This expense submission key already contains different data.');return (int)$old['id'];}
            $this->db->prepare('INSERT INTO ac_expenses(school_id,number,title,payee,expense_date,amount_minor,expense_account_id,payment_account_id,category_id,reference,remarks,idempotency_key,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$this->school,$this->number('expense',$date),$title,trim($in['payee']??''),$date,$amount,$expense['id'],$cash['id'],$category?:null,trim($in['reference']??''),trim($in['remarks']??''),$key,$this->user]);$id=(int)$this->db->lastInsertId();$this->audit('Save expense draft','ac_expenses',$id,[],$in);return $id;
        });
    }
    public function approveExpense(int $id):void {$this->authorize('approve');$this->atomic(function()use($id){$e=$this->record('ac_expenses',$id,true);if($e['status']!=='draft')return;$this->journal('expense_approve',$id,$e['expense_date'],$e['title'],[[(int)$e['expense_account_id'],(int)$e['amount_minor'],0,null],[$this->control('payable'),0,(int)$e['amount_minor'],null]]);$this->db->prepare("UPDATE ac_expenses SET status='approved',approved_by=? WHERE id=? AND school_id=?")->execute([$this->user,$id,$this->school]);$this->audit('Approve expense','ac_expenses',$id,$e,['status'=>'approved']);});}
    public function payExpense(int $id,string $date):void {$this->authorize('expense');$date=$this->date($date);$this->atomic(function()use($id,$date){$e=$this->record('ac_expenses',$id,true);if($e['status']==='paid')return;if($e['status']!=='approved')throw new InvalidArgumentException('Administrator approval is required before expense payment.');$this->journal('expense_pay',$id,$date,'Pay '.$e['title'],[[$this->control('payable'),(int)$e['amount_minor'],0,null],[(int)$e['payment_account_id'],0,(int)$e['amount_minor'],null]]);$this->db->prepare("UPDATE ac_expenses SET status='paid' WHERE id=? AND school_id=?")->execute([$id,$this->school]);$this->audit('Pay approved expense','ac_expenses',$id,$e,['status'=>'paid']);});}
    public function reverseExpense(int $id,string $date,string $reason,bool $confirm):void {
        $this->authorize('reverse');$date=$this->date($date);if(!$confirm||!trim($reason))throw new InvalidArgumentException('Confirm expense reversal and provide a reason.');$this->atomic(function()use($id,$date,$reason){$e=$this->record('ac_expenses',$id,true);if($e['status']==='reversed')return;if($e['status']==='draft')throw new InvalidArgumentException('A draft has no posted financial entry to reverse.');
            foreach($this->rows("SELECT * FROM ac_journals WHERE school_id=? AND reference_id=? AND reference_type IN ('expense_approve','expense_pay') ORDER BY id",[$this->school,$id]) as $j){$lines=[];foreach($this->rows('SELECT * FROM ac_journal_lines WHERE journal_id=?',[$j['id']]) as $l)$lines[]=[(int)$l['account_id'],(int)$l['credit_minor'],(int)$l['debit_minor'],$l['student_id']];$this->journal($j['reference_type'].'_reverse',$id,$date,$reason,$lines,(int)$j['id']);}$this->db->prepare("UPDATE ac_expenses SET status='reversed' WHERE id=? AND school_id=?")->execute([$id,$this->school]);$this->audit('Reverse expense','ac_expenses',$id,$e,['status'=>'reversed'],$reason);
        });
    }
    public function postManualJournal(array $in):int {
        $this->authorize('opening');if(empty($in['confirm']))throw new InvalidArgumentException('Confirm this journal after reviewing the accounts.');$key=$this->key($in);$date=$this->date($in['date']??'');$description=trim($in['description']??'');if(!$description)throw new InvalidArgumentException('Journal description is required.');$lines=[];
        foreach((array)($in['lines']??[]) as $r){if(empty($r['account_id']))continue;$a=$this->record('ac_accounts',(int)$r['account_id']);if(in_array($a['control_key'],['receivable','advance'],true))throw new InvalidArgumentException('Use verified student opening dues or advance workflows for control accounts, to keep subledgers reconciled.');$lines[]=[(int)$a['id'],acDecimalUnits($r['debit']?:'0'),acDecimalUnits($r['credit']?:'0'),null];}
        return $this->atomic(function()use($in,$key,$date,$description,$lines){$old=$this->one("SELECT * FROM ac_audit WHERE school_id=? AND action='Manual journal request' AND reason=?",[$this->school,$key]);if($old){if($old['new_data']!==json_encode($in,JSON_UNESCAPED_UNICODE))throw new InvalidArgumentException('This journal key already contains different data.');return (int)$old['record_id'];}$id=$this->journal('manual',random_int(1,2000000000),$date,$description,$lines);$this->audit('Manual journal request','ac_journals',$id,[],$in,$key);return $id;});
    }
    public function openingDue(array $in):int {
        $this->authorize('opening');if(empty($in['confirm']))throw new InvalidArgumentException('Confirm that this opening balance was independently verified.');$amount=acDecimalUnits($in['amount']??'0');if(!$amount)throw new InvalidArgumentException('Opening due must be positive.');$this->key($in);
        $student=schoolRecord($this->db,'students',(int)($in['student_id']??0),$this->school);$date=$this->date($in['date']??'');
        return $this->atomic(function()use($in,$amount,$student,$date){$key='opening:'.$in['idempotency_key'];$hash=hash('sha256',json_encode($in));if(($id=$this->replay('ac_invoices',$key,$hash))!==null)return $id;$this->period($date);$number=$this->number('invoice',$date);$name=trim($student['first_name_en'].' '.$student['last_name_en']);
            $this->db->prepare("INSERT INTO ac_invoices(school_id,number,academic_year_id,class_id,section_id,student_id,customer_type,customer_name,description,bill_date,due_date,status,subtotal_minor,total_minor,idempotency_key,request_hash,created_by,issued_at) VALUES(?,?,?,?,?,?,'student',?,?,?,?,'issued',?,?,?,?,?,CURRENT_TIMESTAMP)")->execute([$this->school,$number,$student['academic_year_id'],$student['class_id'],$student['section_id'],$student['id'],$name,'Verified opening due: '.trim($in['reason']??''),$date,$date,$amount,$amount,$key,$hash,$this->user]);$id=(int)$this->db->lastInsertId();$this->journal('opening_due',$id,$date,'Verified opening student due',[[$this->control('receivable'),$amount,0,$student['id']],[$this->control('equity'),0,$amount,$student['id']]]);$this->audit('Import verified opening due','ac_invoices',$id,[],$in);return $id;
        });
    }
    public function billingWindow(string $period,string $calendar):array {
        if(!preg_match('/^\d{4}-\d{2}$/',$period)||!in_array($calendar,['BS','AD'],true))throw new InvalidArgumentException('Choose a billing month as YYYY-MM in AD or BS.');
        $start=$this->date(schoolDateToAd($period.'-01',$calendar));if($calendar==='BS'){[$year,$month]=array_map('intval',explode('-',$period));$day=schoolCalendar()['years'][$year][$month-1];$end=$this->date(schoolDateToAd($period.'-'.sprintf('%02d',$day),'BS'));}else $end=(new DateTimeImmutable($start))->format('Y-m-t');return [$start,$end];
    }
    /** Rate precedence: student exception, then section, then class; latest effective revision wins. */
    public function studentFeeItems(array $s,int $year,string $start,string $end,?int $exam=null,string $frequency='monthly'):array {
        $rules=$this->rows('SELECT r.*,i.frequency,i.name,i.fine_eligible FROM ac_fee_rules r JOIN ac_items i ON i.id=r.item_id AND i.school_id=r.school_id WHERE r.school_id=? AND r.academic_year_id=? AND i.is_active=1 AND r.effective_from<=? AND (r.effective_to IS NULL OR r.effective_to>=?) AND (r.student_id=? OR (r.student_id IS NULL AND r.class_id=?)) AND (r.section_id IS NULL OR r.section_id=?) ORDER BY r.effective_from DESC,r.id DESC',[$this->school,$year,$end,$start,$s['id'],$s['class_id'],$s['section_id']]);
        usort($rules,fn($a,$b)=>[(int)!empty($b['student_id']),(int)!empty($b['section_id']),$b['effective_from'],(int)$b['id']]<=>[(int)!empty($a['student_id']),(int)!empty($a['section_id']),$a['effective_from'],(int)$a['id']]);$chosen=[];$items=[];
        foreach($rules as $r){if(isset($chosen[$r['item_id']]))continue;if($r['frequency']!==$frequency||($r['exam_id']&&$r['exam_id']!==$exam))continue;
            if($r['stream']){ $c=schoolRecord($this->db,'classes',(int)$s['class_id'],$this->school);if(strcasecmp(trim((string)($c['stream']??$c['faculty']??'')),trim($r['stream']))!==0)continue;}
            if($r['subject_id']&&!in_array((int)$r['subject_id'],array_map('intval',[$s['optional_subject_i'],$s['optional_subject_ii'],$s['optional_subject_iii']??0]),true)&&!$this->scalar('SELECT COUNT(*) FROM class_subjects WHERE school_id=? AND class_id=? AND subject_id=? AND is_optional=0',[$this->school,$s['class_id'],$r['subject_id']]))continue;
            if($r['is_optional']&&!$r['student_id'])continue;$chosen[$r['item_id']]=true;if($r['is_exempt'])continue;
            $amount=(int)$r['amount_minor'];$effectiveStart=max($start,$r['effective_from']);$effectiveEnd=min($end,$r['effective_to']?:$end);
            // Student creation is the existing admission timestamp; effective fee dates can restrict it further.
            $admission=substr($s['created_at']??$start,0,10);if($admission>$end)continue;$effectiveStart=max($effectiveStart,$admission);
            if($this->settings['prorate']&&$frequency==='monthly'){$all=(int)(new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days+1;$days=(int)(new DateTimeImmutable($effectiveStart))->diff(new DateTimeImmutable($effectiveEnd))->days+1;$amount=acMulDiv($amount,$days,$all);}
            $discount=min($amount,acCheckedAdd((int)$r['discount_minor'],acMulDiv($amount,(int)$r['discount_basis_points'],10000)));
            foreach($this->rows('SELECT * FROM ac_scholarships WHERE school_id=? AND student_id=? AND academic_year_id=? AND (item_id IS NULL OR item_id=?) AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?)',[$this->school,$s['id'],$year,$r['item_id'],$effectiveEnd,$effectiveStart]) as $sch)$discount=min($amount,acCheckedAdd(acCheckedAdd($discount,(int)$sch['amount_minor']),acMulDiv($amount,(int)$sch['basis_points'],10000)));
            $items[]=['item_id'=>(int)$r['item_id'],'particular'=>$r['name'],'quantity'=>'1','rate'=>acAmount($amount),'discount'=>acAmount($discount)];
        }return $items;
    }
    public function recurringPreview(array $in):array {
        $year=schoolRecord($this->db,'academic_years',(int)($in['academic_year_id']??0),$this->school);[$start,$end]=$this->billingWindow($in['billing_period']??'',$in['billing_calendar']??'BS');$params=[$this->school,$year['id']];$sql="SELECT * FROM students WHERE school_id=? AND academic_year_id=? AND status='active'";
        foreach(['class_id','section_id','student_id'] as $field)if(!empty($in[$field])){schoolRecord($this->db,$field==='class_id'?'classes':($field==='section_id'?'sections':'students'),(int)$in[$field],$this->school);$sql.=' AND '.($field==='student_id'?'id':$field).'=?';$params[]=(int)$in[$field];}
        $frequency=$in['frequency']??'monthly';if(!in_array($frequency,['monthly','annual','one_time','exam'],true))throw new InvalidArgumentException('Choose a supported billing frequency.');$exam=!empty($in['exam_id'])?(int)$in['exam_id']:null;if($exam)schoolRecord($this->db,'exams',$exam,$this->school);if($frequency==='exam'&&!$exam)throw new InvalidArgumentException('Select the examination to bill.');
        $result=[];foreach($this->rows($sql.' ORDER BY first_name_en,last_name_en,id',$params) as $s){$items=$this->studentFeeItems($s,(int)$year['id'],$start,$end,$exam,$frequency);if(!$items)continue;$key='fees:'.$frequency.':'.$year['id'].':'.$s['id'].':'.($frequency==='exam'?$exam:($frequency==='monthly'?($in['billing_calendar']??'BS').':'.$in['billing_period']:$year['id']));$draft=['student_id'=>$s['id'],'academic_year_id'=>$year['id'],'bill_date'=>$start,'due_date'=>(new DateTimeImmutable($start))->modify('+'.(int)$this->settings['due_days'].' days')->format('Y-m-d'),'billing_period'=>($in['billing_calendar']??'BS').' '.$in['billing_period'],'items'=>$items,'idempotency_key'=>$key];
            $this->approvedGeneration=true;try{$preview=$this->previewInvoice($draft);}finally{$this->approvedGeneration=false;}$existing=$this->one('SELECT id,status FROM ac_invoices WHERE school_id=? AND idempotency_key=?',[$this->school,$key]);$result[]=['student'=>$s,'input'=>$draft,'total_minor'=>$preview['total_minor'],'existing'=>$existing];
        }return $result;
    }
    public function generateRecurring(array $in):array {
        $this->authorize('invoice');if(empty($in['confirm']))throw new InvalidArgumentException('Preview and confirm the affected students before generating bills.');$preview=$this->recurringPreview($in);$result=['issued'=>[],'skipped'=>[],'errors'=>[]];
        foreach($preview as $p){try{if($p['existing']){$result['skipped'][]=$p['existing']['id'];continue;}$this->approvedGeneration=true;$result['issued'][]=$this->createInvoice($p['input'],true);}catch(Throwable $e){$result['errors'][]=['student_id'=>$p['student']['id'],'error'=>$e instanceof InvalidArgumentException?$e->getMessage():'Transaction failed; retry this student.'];error_log($e->getMessage());}finally{$this->approvedGeneration=false;}}
        $this->atomic(function()use($in,$result){$this->db->prepare("INSERT INTO ac_runs(school_id,kind,period_key,details,created_by) VALUES(?,'fees',?,?,?)")->execute([$this->school,$in['billing_period'],json_encode($result),$this->user]);});return $result;
    }
    public function saveSubscription(array $in):int {
        $this->authorize('invoice');$item=$this->record('ac_items',(int)($in['item_id']??0));$student=!empty($in['student_id'])?schoolRecord($this->db,'students',(int)$in['student_id'],$this->school):null;$name=$student?trim($student['first_name_en'].' '.$student['last_name_en']):trim($in['customer_name']??'');$frequency=$in['frequency']??'';$amount=acDecimalUnits($in['amount']??'0');$start=$this->date($in['start_date']??'');$end=!empty($in['end_date'])?$this->date($in['end_date']):null;
        if(!$name||!$amount||!in_array($frequency,['monthly','quarterly','annual','one_time'],true)||($end&&$end<$start))throw new InvalidArgumentException('Enter valid subscription details, amount and dates.');
        return $this->atomic(function()use($in,$item,$student,$name,$frequency,$amount,$start,$end){$this->db->prepare('INSERT INTO ac_subscriptions(school_id,item_id,student_id,customer_name,frequency,amount_minor,start_date,end_date,next_date,renewal,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$this->school,$item['id'],$student['id']??null,$name,$frequency,$amount,$start,$end,$start,empty($in['renewal'])?0:1,$this->user]);$id=(int)$this->db->lastInsertId();$this->audit('Create subscription','ac_subscriptions',$id,[],$in);return $id;});
    }
    public function changeSubscription(int $id,string $status,string $date):void {
        $this->authorize('invoice');if(!in_array($status,['active','paused','cancelled'],true))throw new InvalidArgumentException('Choose an active, paused or cancelled state.');$date=$this->date($date);$this->atomic(function()use($id,$status,$date){$s=$this->record('ac_subscriptions',$id,true);if(in_array($s['status'],['cancelled','expired'],true))throw new InvalidArgumentException('Cancelled/expired subscriptions cannot be resumed. Create a reviewed renewal instead.');if($status==='active'&&$s['end_date']&&$s['end_date']<$date)throw new InvalidArgumentException('Subscription end date has passed.');$next=$status==='active'?max($s['next_date'],$date):$s['next_date'];$this->db->prepare('UPDATE ac_subscriptions SET status=?,next_date=? WHERE id=? AND school_id=?')->execute([$status,$next,$id,$this->school]);$this->audit('Change subscription state','ac_subscriptions',$id,$s,['status'=>$status,'next_date'=>$next]);});
    }
    private function subscriptionNext(string $date,string $frequency):?string {
        if($frequency==='one_time')return null;$months=['monthly'=>1,'quarterly'=>3,'annual'=>12][$frequency];$d=new DateTimeImmutable($date);$first=$d->modify('first day of this month')->modify('+'.$months.' months');$day=min((int)$d->format('d'),(int)$first->format('t'));return $first->format('Y-m-').sprintf('%02d',$day);
    }
    public function generateSubscriptions(string $through):array {
        $this->authorize('invoice');$through=$this->date($through);$out=['issued'=>[],'errors'=>[]];
        foreach($this->rows("SELECT id FROM ac_subscriptions WHERE school_id=? AND status='active' AND next_date<=?",[$this->school,$through]) as $record){
            for($limit=0;$limit<120;$limit++){ $s=$this->record('ac_subscriptions',(int)$record['id']);if($s['status']!=='active'||$s['next_date']>$through)break;if($s['end_date']&&$s['next_date']>$s['end_date']){$this->atomic(fn()=>$this->db->prepare("UPDATE ac_subscriptions SET status='expired' WHERE id=? AND school_id=?")->execute([$s['id'],$this->school]));break;}
                if($s['student_id']&&schoolRecord($this->db,'students',(int)$s['student_id'],$this->school)['status']!=='active')break;
                $item=$this->record('ac_items',(int)$s['item_id']);$due=(new DateTimeImmutable($s['next_date']))->modify('+'.(int)$this->settings['due_days'].' days')->format('Y-m-d');$key='subscription:'.$s['id'].':'.$s['next_date'];$draft=['student_id'=>$s['student_id'],'customer_name'=>$s['customer_name'],'customer_type'=>'external','bill_date'=>$s['next_date'],'due_date'=>$due,'billing_period'=>$s['next_date'],'description'=>'Subscription '.$s['id'],'items'=>[['item_id'=>$item['id'],'particular'=>$item['name'],'quantity'=>'1','rate'=>acAmount((int)$s['amount_minor'])]],'idempotency_key'=>$key];
                try{$out['issued'][]=$this->createInvoice($draft,true);$this->atomic(function()use($s){$current=$this->record('ac_subscriptions',(int)$s['id'],true);if($current['next_date']!==$s['next_date'])return;$next=$this->subscriptionNext($s['next_date'],$s['frequency']);$status=$next&&(!$s['end_date']||$next<=$s['end_date'])?'active':'expired';$this->db->prepare('UPDATE ac_subscriptions SET next_date=?,status=? WHERE id=? AND school_id=?')->execute([$next??$s['next_date'],$status,$s['id'],$this->school]);$this->audit('Subscription billing cycle','ac_subscriptions',(int)$s['id'],$s,['next_date'=>$next,'status'=>$status]);});}
                catch(Throwable $e){$out['errors'][]=['subscription'=>$s['id'],'error'=>$e instanceof InvalidArgumentException?$e->getMessage():'Posting failed; retry.'];break;}
            }
        }return $out;
    }
    public function balances(string $from,string $to):array {
        $from=$this->date($from);$to=$this->date($to);if($to<$from)throw new InvalidArgumentException('Report date range is reversed.');
        return $this->rows('SELECT a.id,a.code,a.name,a.type,a.control_key,COALESCE(SUM(l.debit_minor),0) debit_minor,COALESCE(SUM(l.credit_minor),0) credit_minor FROM ac_accounts a LEFT JOIN (ac_journal_lines l JOIN ac_journals j ON j.id=l.journal_id AND j.school_id=? AND j.posting_date>=? AND j.posting_date<=?) ON l.account_id=a.id WHERE a.school_id=? GROUP BY a.id,a.code,a.name,a.type,a.control_key ORDER BY a.code',[$this->school,$from,$to,$this->school]);
    }
    public function reconcile():array {
        $ar=$this->scalar('SELECT COALESCE(SUM(l.debit_minor-l.credit_minor),0) FROM ac_journal_lines l JOIN ac_journals j ON j.id=l.journal_id WHERE j.school_id=? AND l.account_id=?',[$this->school,$this->control('receivable')]);$adv=$this->scalar('SELECT COALESCE(SUM(l.credit_minor-l.debit_minor),0) FROM ac_journal_lines l JOIN ac_journals j ON j.id=l.journal_id WHERE j.school_id=? AND l.account_id=?',[$this->school,$this->control('advance')]);$subAdv=$this->scalar('SELECT COALESCE(SUM(amount_minor),0) FROM ac_advance_entries WHERE school_id=?',[$this->school]);$outstanding=$this->outstanding();$unbalanced=$this->scalar('SELECT COUNT(*) FROM (SELECT j.id FROM ac_journals j JOIN ac_journal_lines l ON l.journal_id=j.id WHERE j.school_id=? GROUP BY j.id HAVING SUM(l.debit_minor)<>SUM(l.credit_minor)) bad',[$this->school]);return ['receivable_gl'=>$ar,'receivable_subledger'=>$outstanding,'advance_gl'=>$adv,'advance_subledger'=>$subAdv,'unbalanced_journals'=>$unbalanced,'ok'=>$ar===$outstanding&&$adv===$subAdv&&$unbalanced===0];
    }
    public function studentStatement(int $student):array {
        schoolRecord($this->db,'students',$student,$this->school);
        return $this->rows('SELECT j.posting_date,j.number,j.description,a.name,l.debit_minor,l.credit_minor,a.control_key FROM ac_journal_lines l JOIN ac_journals j ON j.id=l.journal_id JOIN ac_accounts a ON a.id=l.account_id WHERE j.school_id=? AND l.student_id=? AND a.control_key IN (\'receivable\',\'advance\') ORDER BY j.posting_date,j.id,l.id',[$this->school,$student]);
    }
    public function dashboard(string $from,string $to):array {
        $balances=$this->balances($from,$to);$income=0;$expense=0;foreach($balances as $a){if(in_array($a['type'],['income','contra_income'],true))$income=acCheckedAdd($income,(int)$a['credit_minor']-(int)$a['debit_minor']);if($a['type']==='expense')$expense=acCheckedAdd($expense,(int)$a['debit_minor']-(int)$a['credit_minor']);}
        $collected=$this->scalar("SELECT COALESCE(SUM(amount_minor),0) FROM ac_payments WHERE school_id=? AND status='posted' AND payment_date>=? AND payment_date<=?",[$this->school,$from,$to]);$refunds=$this->scalar('SELECT COALESCE(SUM(amount_minor),0) FROM ac_refunds WHERE school_id=? AND refund_date>=? AND refund_date<=?',[$this->school,$from,$to]);
        $billed=$this->scalar("SELECT COALESCE(SUM(total_minor),0) FROM ac_invoices WHERE school_id=? AND status='issued' AND bill_date>=? AND bill_date<=? AND idempotency_key NOT LIKE 'opening:%'",[$this->school,$from,$to]);return ['billed'=>$billed,'collected'=>$collected,'refunds'=>$refunds,'outstanding'=>$this->outstanding(),'advances'=>$this->scalar('SELECT COALESCE(SUM(amount_minor),0) FROM ac_advance_entries WHERE school_id=?',[$this->school]),'income'=>$income,'expense'=>$expense,'net'=>acCheckedAdd($income,-$expense)];
    }
}
