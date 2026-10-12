<?php
require_once __DIR__.'/functions.php';
require_once __DIR__.'/curriculum_catalog.php';

const MCQ_PRACTICE_LIMIT=100;
function practiceScope(PDO $db,int $school,string $role,int $actor,int $class,int $subject):array {
    $chosenClass=null;$chosenSubject=null;
    foreach(learningClasses($db,$school,$role,$actor) as $row)if((int)$row['id']===$class)$chosenClass=$row;
    if(!$chosenClass)throw new InvalidArgumentException('Choose an available class.');
    foreach(learningSubjects($db,$school,$class,$role,$actor) as $row)if((int)$row['id']===$subject)$chosenSubject=$row;
    if(!$chosenSubject)throw new InvalidArgumentException('Choose an available subject for this class.');
    return [$chosenClass,$chosenSubject];
}
function practicePool(PDO $db,int $school,string $role,int $actor,int $class,int $subject):array {
    [$c,$s]=practiceScope($db,$school,$role,$actor,$class,$subject);
    $q=$db->prepare("SELECT q.*,t.name topic_name FROM questions q JOIN classes c ON c.id=q.class_id AND c.school_id=q.school_id JOIN subjects s ON s.id=q.subject_id AND s.school_id=q.school_id LEFT JOIN topics t ON t.id=q.topic_id AND t.school_id=q.school_id AND t.class_id=q.class_id AND t.subject_id=q.subject_id WHERE q.type='MCQ' AND c.is_active=1 AND COALESCE(s.is_hidden,0)=0 AND EXISTS(SELECT 1 FROM class_subjects cs WHERE cs.school_id=q.school_id AND cs.class_id=q.class_id AND cs.subject_id=q.subject_id AND (cs.is_active=1 OR cs.is_optional=1)) AND ((q.school_id=? AND q.class_id=? AND q.subject_id=?) OR (q.school_id=0 AND c.name=? AND ((s.code<>'' AND s.code=?) OR s.name_en=?))) ORDER BY q.id DESC");
    $q->execute([$school,$class,$subject,$c['name'],$s['code']??'',$s['name_en']]);$pool=[];
    while($row=$q->fetch()){
        try{$en=json_decode($row['options']??'',true,512,JSON_THROW_ON_ERROR);$np=json_decode($row['options_np']?:'{}',true,512,JSON_THROW_ON_ERROR);}catch(Throwable $e){continue;}
        if(!is_array($en)||!is_array($np)||!in_array($row['correct_option'],['A','B','C','D'],true))continue;
        $options=[];$valid=true;
        foreach(['A','B','C','D'] as $key){
            if(!is_scalar($en[$key]??'')||!is_scalar($np[$key]??'')){$valid=false;break;}
            $text=safeRichHtml((string)($en[$key]??''));$nepali=safeRichHtml((string)($np[$key]??''));
            if(trim(strip_tags($text.$nepali))===''&&!str_contains($text.$nepali,'<img')){$valid=false;break;}
            $options[$key]=['text'=>$text,'nepali'=>$nepali];
        }
        if(!$valid)continue;
        $text=safeRichHtml((string)$row['question_text']);$nepali=safeRichHtml((string)($row['question_text_np']??''));
        if(trim(strip_tags($text.$nepali))===''&&!str_contains($text.$nepali,'<img'))continue;
        $pool[]=['id'=>(int)$row['id'],'text'=>$text,'nepali'=>$nepali,'options'=>$options,'key'=>$row['correct_option'],'chapter'=>curriculumName((string)$row['chapter']),'topic'=>curriculumName((string)($row['topic_name']??'')),'explanation'=>safeRichHtml((string)($row['answer']??'')),'explanation_np'=>safeRichHtml((string)($row['answer_np']??''))];
    }
    return $pool;
}
function practiceCatalog(array $pool):array {
    return array_map(static fn($q)=>['id'=>$q['id'],'chapter'=>$q['chapter'],'topic'=>$q['topic'],'search_text'=>curriculumNameKey(html_entity_decode(strip_tags($q['text'].' '.$q['nepali']),ENT_QUOTES|ENT_HTML5,'UTF-8')),'preview'=>mb_substr(trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($q['text']?:$q['nepali']),ENT_QUOTES|ENT_HTML5,'UTF-8'))),0,180,'UTF-8')],$pool);
}
function practiceStart(PDO $db,int $school,string $role,int $actor,array $input):array {
    foreach(['class_id','subject_id'] as $field)if(!is_scalar($input[$field]??null)||!ctype_digit((string)$input[$field])||(int)$input[$field]<1)throw new InvalidArgumentException('Choose a valid class and subject.');
    $class=(int)($input['class_id']??0);$subject=(int)($input['subject_id']??0);
    $count=$input['count']??'';$mode=$input['selection_mode']??'random';
    if(!is_scalar($count)||!ctype_digit((string)$count)||(int)$count<1||(int)$count>MCQ_PRACTICE_LIMIT)throw new InvalidArgumentException('Choose 1 to 100 MCQs.');
    if(!in_array($mode,['random','chosen'],true))throw new InvalidArgumentException('Choose random MCQs or choose your questions.');
    $filters=[];foreach(['chapter','topic','search'] as $key){$value=$input[$key]??'';if(!is_string($value)||mb_strlen($value)>($key==='search'?100:255))throw new InvalidArgumentException('Use a valid practice filter.');$filters[$key]=curriculumNameKey($value);}
    $pool=array_values(array_filter(practicePool($db,$school,$role,$actor,$class,$subject),static function($q)use($filters){
        return (!$filters['chapter']||curriculumNameKey($q['chapter'])===$filters['chapter'])&&(!$filters['topic']||curriculumNameKey($q['topic'])===$filters['topic'])&&(!$filters['search']||str_contains(curriculumNameKey(html_entity_decode(strip_tags($q['text'].' '.$q['nepali']),ENT_QUOTES|ENT_HTML5,'UTF-8')),$filters['search']));
    }));
    if($mode==='chosen'){
        $ids=$input['question_ids']??[];
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>MCQ_PRACTICE_LIMIT)throw new InvalidArgumentException('Choose up to 100 MCQs from this class and subject.');
        foreach($ids as $id)if(!is_scalar($id)||!ctype_digit((string)$id)||(int)$id<1)throw new InvalidArgumentException('Choose valid MCQs.');
        $ids=array_map('intval',$ids);
        if(count($ids)!==count(array_unique($ids)))throw new InvalidArgumentException('Choose each MCQ once.');
        $pool=array_values(array_filter($pool,static fn($q)=>in_array($q['id'],$ids,true)));
        if(count($pool)!==count($ids))throw new InvalidArgumentException('Some selected MCQs are no longer available in this class and subject.');
    }
    if(count($pool)<(int)$count)throw new InvalidArgumentException('Only '.count($pool).' matching MCQs are available. Reduce the question count or adjust your filters.');
    shuffle($pool);
    [$c,$s]=practiceScope($db,$school,$role,$actor,$class,$subject);
    return ['token'=>bin2hex(random_bytes(24)),'school'=>$school,'actor'=>$actor,'class'=>$class,'subject'=>$subject,'title'=>$c['name'].' · '.$s['name_en'],'expires'=>time()+3600,'questions'=>array_slice($pool,0,(int)$count),'index'=>0,'answers'=>[]];
}
function practiceValidateSession(PDO $db,int $school,string $role,int $actor,array $state):void {
    if(($state['school']??null)!==$school||($state['actor']??null)!==$actor||($state['expires']??0)<time())throw new InvalidArgumentException('This practice session has expired. Start a new practice.');
    practiceScope($db,$school,$role,$actor,(int)$state['class'],(int)$state['subject']);
}
function practiceFeedback(array $q,array $answer):array {
    return ['correct'=>$answer['correct'],'selected'=>$answer['choice'],'correct_option'=>$q['key'],'correct_text'=>$q['options'][$q['key']]['text'],'correct_nepali'=>$q['options'][$q['key']]['nepali'],'explanation'=>$q['explanation'],'explanation_np'=>$q['explanation_np']];
}
function practicePublicState(array $state):array {
    $index=$state['index'];$total=count($state['questions']);$score=count(array_filter($state['answers'],static fn($a)=>$a['correct']));
    $public=['token'=>$state['token'],'index'=>$index,'total'=>$total,'score'=>$score,'answered'=>count($state['answers']),'title'=>$state['title'],'complete'=>$index>=$total];
    if($public['complete']){
        $public['review']=[];foreach($state['questions'] as $i=>$q)$public['review'][]=['id'=>$q['id'],'text'=>$q['text'],'nepali'=>$q['nepali'],...practiceFeedback($q,$state['answers'][$i])];
    }else{
        $q=$state['questions'][$index];$public['question']=['id'=>$q['id'],'text'=>$q['text'],'nepali'=>$q['nepali'],'options'=>$q['options']];
        if(isset($state['answers'][$index]))$public['feedback']=practiceFeedback($q,$state['answers'][$index]);
    }
    return $public;
}
function practiceTransition(array &$state,string $action,array $input):array {
    if(!is_string($input['token']??null)||!hash_equals($state['token'],$input['token']))throw new InvalidArgumentException('This practice was replaced. Reload to continue.');
    $index=$input['index']??'';
    if(!is_scalar($index)||!ctype_digit((string)$index)||(int)$index!==$state['index']||!isset($state['questions'][$state['index']]))throw new InvalidArgumentException('This question has already changed. Reload to continue.');
    $q=$state['questions'][$state['index']];
    if(!is_scalar($input['question_id']??null)||(string)$input['question_id']!==(string)$q['id'])throw new InvalidArgumentException('Answer the current practice question.');
    if($action==='answer'){
        $choice=$input['choice']??'';if(!in_array($choice,['A','B','C','D'],true))throw new InvalidArgumentException('Choose A, B, C or D.');
        if(isset($state['answers'][$state['index']])&&$state['answers'][$state['index']]['choice']!==$choice)throw new InvalidArgumentException('This answer is already submitted. Continue to the next question.');
        $state['answers'][$state['index']]=['choice'=>$choice,'correct'=>$choice===$q['key']];
    }elseif($action==='next'){
        if(!isset($state['answers'][$state['index']]))throw new InvalidArgumentException('Choose an answer before continuing.');
        $state['index']++;
    }else throw new InvalidArgumentException('Choose a valid practice action.');
    return practicePublicState($state);
}
