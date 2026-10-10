<?php
require_once __DIR__.'/accounting_money.php';
require_once __DIR__.'/nepali_dates.php';
require_once __DIR__.'/school_validation.php';

/** Internal posting unit. Only the authorized facade defines business workflows. */
final class AccountingLedger {
    private bool $ownsTransaction=false;
    public function __construct(private PDO $db,private int $school,private int $actor) {}
    private function one(string $sql,array $values):array {$q=$this->db->prepare($sql);$q->execute($values);return $q->fetch()?:[];}
    private function activeActor():void {
        $actor=$this->one('SELECT role,status,school_id FROM users WHERE id=?',[$this->actor]);
        if(!$actor||!(int)$actor['status']||!in_array($actor['role'],['super_admin','school_admin','accountant','cashier'],true)||($actor['role']!=='super_admin'&&(int)$actor['school_id']!==$this->school))throw new InvalidArgumentException('An active authorized school account is required to post transactions.');
    }
    /** First write locks the school on SQLite; InnoDB holds the row until commit. */
    public function transaction(callable $work):mixed {
        if($this->ownsTransaction||$this->db->inTransaction())throw new RuntimeException('Nested accounting transaction is not supported.');
        $this->db->beginTransaction();$this->ownsTransaction=true;
        try {
            $this->db->prepare('UPDATE ac_settings SET due_days=due_days WHERE school_id=?')->execute([$this->school]);
            if(!$this->one('SELECT school_id FROM ac_settings WHERE school_id=?'.($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$this->school]))throw new InvalidArgumentException('Accounting is not provisioned for this school.');
            $this->activeActor();$result=$work();$this->db->commit();return $result;
        } catch(Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        finally {$this->ownsTransaction=false;}
    }
    private function normalized(array $lines):array {
        $normalized=array_map(fn($l)=>[(int)$l[0],(int)$l[1],(int)$l[2],$l[3]===null?null:(int)$l[3]],$lines);
        usort($normalized,fn($a,$b)=>$a<=>$b);return $normalized;
    }
    private function storedLines(int $journal):array {
        $q=$this->db->prepare('SELECT account_id,debit_minor,credit_minor,student_id FROM ac_journal_lines WHERE journal_id=? ORDER BY id');$q->execute([$journal]);
        return $this->normalized($q->fetchAll(PDO::FETCH_NUM));
    }
    public function post(string $type,int $source,string $date,string $description,array $lines,?int $reversal=null):int {
        if(!$this->ownsTransaction||!$this->db->inTransaction())throw new InvalidArgumentException('Journal posting requires an active ledger transaction.');
        if(!preg_match('/^[a-z][a-z0-9_]{0,29}$/',$type)||$source<=0||schoolDateToAd($date)!==$date||!trim($description)||strlen($description)>2000||count($lines)>200)throw new InvalidArgumentException('Enter a valid journal source, date, description and lines.');
        $dr=0;$cr=0;$valid=[];
        foreach($lines as $line) {
            if(!is_array($line)||count($line)!==4||array_keys($line)!==[0,1,2,3])throw new InvalidArgumentException('Journal lines must contain account, debit, credit and student.');
            [$account,$debit,$credit,$student]=$line;
            if(!is_int($debit)||!is_int($credit)||$debit<0||$credit<0||($debit&&$credit))throw new InvalidArgumentException('Journal amounts must be exact non-negative integers on one side only.');
            $a=$this->one('SELECT id,is_active FROM ac_accounts WHERE id=? AND school_id=?',[(int)$account,$this->school]);
            if(!$a||!(int)$a['is_active'])throw new InvalidArgumentException('Journal accounts must be active and belong to this school.');
            if($student!==null)schoolRecord($this->db,'students',(int)$student,$this->school);
            $dr=acCheckedAdd($dr,$debit);$cr=acCheckedAdd($cr,$credit);
            if($debit||$credit)$valid[]=[(int)$account,$debit,$credit,$student===null?null:(int)$student];
        }
        if(!$valid||$dr!==$cr)throw new InvalidArgumentException('Journal debit and credit totals must balance exactly.');
        $old=$this->one('SELECT * FROM ac_journals WHERE school_id=? AND reference_type=? AND reference_id=?',[$this->school,$type,$source]);
        if($old) {
            if($old['posting_date']!==$date||$old['description']!==$description||($old['reversal_of']===null?null:(int)$old['reversal_of'])!==$reversal||$this->storedLines((int)$old['id'])!==$this->normalized($valid))throw new InvalidArgumentException('This journal source was already posted with different data.');
            return (int)$old['id'];
        }
        $q=$this->db->prepare('SELECT id,is_closed FROM ac_periods WHERE school_id=? AND start_date<=? AND end_date>=?');$q->execute([$this->school,$date,$date]);$periods=$q->fetchAll();
        if(count($periods)!==1)throw new InvalidArgumentException('Configure exactly one fiscal period covering this posting date.');
        if((int)$periods[0]['is_closed'])throw new InvalidArgumentException('This fiscal period is closed. An administrator must reopen it before posting.');
        if($reversal!==null) {
            $original=$this->one('SELECT id,posting_date FROM ac_journals WHERE id=? AND school_id=?',[$reversal,$this->school]);
            if(!$original||$date<$original['posting_date']||$this->one('SELECT id FROM ac_journals WHERE school_id=? AND reversal_of=?',[$this->school,$reversal]))throw new InvalidArgumentException('Choose an unreversed school journal and a date on or after its posting.');
            $opposite=array_map(fn($l)=>[$l[0],$l[2],$l[1],$l[3]],$this->storedLines($reversal));
            if($this->normalized($opposite)!==$this->normalized($valid))throw new InvalidArgumentException('Reversal lines must be the exact opposite of the original journal.');
        }
        $year=substr(schoolAdToBs($date)??$date,0,4);
        $sequence=$this->one("SELECT next_number FROM ac_sequences WHERE school_id=? AND kind='journal' AND year_key=?",[$this->school,$year]);$n=(int)($sequence['next_number']??1);
        if($n<=0||$n>=PHP_INT_MAX)throw new InvalidArgumentException('Journal numbering exceeds the supported limit.');
        if($sequence)$this->db->prepare("UPDATE ac_sequences SET next_number=? WHERE school_id=? AND kind='journal' AND year_key=?")->execute([$n+1,$this->school,$year]);
        else $this->db->prepare("INSERT INTO ac_sequences(school_id,kind,year_key,next_number) VALUES(?,'journal',?,?)")->execute([$this->school,$year,$n+1]);
        $number='JRN-'.$year.'-'.str_pad((string)$n,6,'0',STR_PAD_LEFT);
        $this->db->prepare('INSERT INTO ac_journals(school_id,number,posting_date,period_id,reference_type,reference_id,description,created_by,reversal_of) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$this->school,$number,$date,$periods[0]['id'],$type,$source,$description,$this->actor,$reversal]);$id=(int)$this->db->lastInsertId();
        $insert=$this->db->prepare('INSERT INTO ac_journal_lines(journal_id,account_id,debit_minor,credit_minor,student_id) VALUES(?,?,?,?,?)');
        foreach($valid as $line)$insert->execute([$id,...$line]);
        $this->db->prepare('INSERT INTO ac_audit(school_id,actor_id,action,entity,record_id,new_data) VALUES(?,?,?,?,?,?)')->execute([$this->school,$this->actor,'Post balanced journal','ac_journals',$id,json_encode(['type'=>$type,'reference'=>$source,'debit_minor'=>$dr,'credit_minor'=>$cr],JSON_UNESCAPED_UNICODE)]);
        return $id;
    }
}
