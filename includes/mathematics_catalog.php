<?php
/** Source-backed mathematics curriculum. Reads never seed or alter records. */
function mathematicsData():array {static $data;return $data??=json_decode(file_get_contents(__DIR__.'/../config/data/mathematics_grids.json'),true,64,JSON_THROW_ON_ERROR);}
function mathematicsChapterKey(string $name):string {return curriculumNameKey(trim(preg_replace('/\s*\([^)]*\)\s*$/u','',$name)));}
function mathematicsAreaKey(string $name):string {return strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',$name),'-'));}
function mathematicsGrade(PDO $db,int $class,int $subject):?int {
    $q=$db->prepare('SELECT c.name,s.name_en,s.type FROM classes c JOIN class_subjects cs ON cs.class_id=c.id AND cs.school_id=c.school_id JOIN subjects s ON s.id=cs.subject_id AND s.school_id=cs.school_id WHERE c.id=? AND s.id=?');$q->execute([$class,$subject]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||$row['type']!=='compulsory'||questionImportSubjectKey($row['name_en'])!=='mathematics')return null;
    return match(classNameKey($row['name'])){'grade:9'=>9,'grade:10'=>10,default=>null};
}
function mathematicsStoredAreas(PDO $db,int $class,int $subject):?array {
    $q=$db->prepare("SELECT payload FROM cdc_reference WHERE category='MATH_AREAS' AND item_key=?");$q->execute([$class.':'.$subject]);$json=$q->fetchColumn();return $json===false?null:json_decode($json,true,64,JSON_THROW_ON_ERROR);
}
function mathematicsDecorateChapters(PDO $db,array $actor,int $class,int $subject,array $chapters):array {
    $grade=mathematicsGrade($db,$class,$subject);if(!$grade)return $chapters;
    $scope=learningCatalogScope($db,$actor['school_id'],$class,$subject);$shared=$scope['shared'];$stored=$shared?mathematicsStoredAreas($db,$shared['class_id'],$shared['subject_id']):null;
    foreach($chapters as &$chapter){$chapter['area_key']='';$chapter['area_name']='';
        if($stored!==null){foreach($stored as $area)if(array_intersect($chapter['alias_ids'],$area['chapter_ids'])){$chapter['area_key']=$area['key'];$chapter['area_name']=$area['name'];break;}}
        else foreach(mathematicsData()['areas'][$grade] as $name=>$names)if(in_array(mathematicsChapterKey($chapter['name']),array_map('mathematicsChapterKey',$names),true)){$chapter['area_key']=mathematicsAreaKey($name);$chapter['area_name']=$name;break;}
    }unset($chapter);return $chapters;
}
function mathematicsAreas(PDO $db,array $actor,int $class,int $subject):array {
    $chapters=learningCatalogChapters($db,$actor,$class,$subject);$areas=[];
    foreach($chapters as $chapter)if($chapter['area_key']??''){$key=$chapter['area_key'];$areas[$key]??=['key'=>$key,'name'=>$chapter['area_name'],'chapter_ids'=>[],'chapter_names'=>[]];$areas[$key]['chapter_ids'][]=(int)$chapter['id'];$areas[$key]['chapter_names'][]=$chapter['name'];}
    return array_values($areas);
}
function mathematicsRequireAdmin(array $actor):void {if($actor['role']!=='super_admin'||$actor['school_id']!==0||$actor['read_only'])throw new InvalidArgumentException('Only system admin in the shared catalog may manage mathematics areas and defaults.');}
function mathematicsSaveAreas(PDO $db,array $actor,int $class,int $subject,mixed $input):void {
    mathematicsRequireAdmin($actor);if(!mathematicsGrade($db,$class,$subject))throw new InvalidArgumentException('Choose Class 9 or 10 compulsory mathematics.');
    if(!is_array($input)||!array_is_list($input)||!$input||count($input)>30)throw new InvalidArgumentException('Add 1–30 mathematics areas.');
    learningWrite($db,function()use($db,$actor,$class,$subject,$input){
        $available=[];foreach(curriculumChapters($db,0,$class,$subject) as $chapter)$available[(int)$chapter['id']]=true;
        $saved=[];$used=[];$keys=[];$names=[];
        foreach($input as $area){if(!is_array($area))throw new InvalidArgumentException('Choose valid area assignments.');$name=$area['name']??'';$key=$area['key']??'';$ids=$area['chapter_ids']??[];
            if(!is_string($name)||trim($name)===''||mb_strlen($name)>100||!is_string($key)||!preg_match('/^[a-z][a-z0-9-]{0,49}$/D',$key)||isset($keys[$key])||isset($names[curriculumNameKey($name)]))throw new InvalidArgumentException('Use unique area keys and names (maximum 100 characters).');
            if(!is_array($ids)||!array_is_list($ids)||!$ids||count($ids)>100)throw new InvalidArgumentException('Select chapters for every area.');
            $selected=[];foreach($ids as $id){$id=learningId($id);if(!isset($available[$id])||isset($used[$id]))throw new InvalidArgumentException('Every chapter must belong to this class subject and at most one area.');$used[$id]=true;$selected[]=$id;}
            $keys[$key]=true;$names[curriculumNameKey($name)]=true;$saved[]=['key'=>$key,'name'=>trim($name),'chapter_ids'=>$selected];
        }
        $id=$class.':'.$subject;$json=json_encode($saved,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$db->prepare("DELETE FROM cdc_reference WHERE category='MATH_AREAS' AND item_key=?")->execute([$id]);$db->prepare('INSERT INTO cdc_reference(category,item_key,payload) VALUES(?,?,?)')->execute(['MATH_AREAS',$id,$json]);learningAudit($db,$actor,'Save shared mathematics areas','chapters',$class);
    });
}
function mathematicsReferences(PDO $db,array $actor,int $class,int $subject):array {
    $grade=mathematicsGrade($db,$class,$subject);if(!$grade)return [];$chapters=learningCatalogChapters($db,$actor,$class,$subject);$references=[];
    foreach(mathematicsData()['profiles'] as $profile){if($profile['grade']!==$grade)continue;$rows=[];$missing=[];
        foreach($profile['areas'] as $area){$key=mathematicsAreaKey($area['area']);$ids=[];foreach($chapters as $chapter)if(($chapter['area_key']??'')===$key&&in_array(mathematicsChapterKey($chapter['name']),array_map('mathematicsChapterKey',$area['chapters']),true))$ids[]=(int)$chapter['id'];
            if(!$ids)$missing[]=$area['area'];
            $rows[]=['name'=>$area['area'],'area_key'=>$key,'type'=>'Written','chapter_ids'=>$ids,'count'=>$area['question_count'],'total_marks'=>$area['marks'],'cognitive_marks'=>$area['cognitive_marks']??[], 'cognitive_items'=>$profile['enforce_items']?$area['cognitive_items']:[],'reference_items'=>$area['cognitive_items']];
        }
        $notes=$profile['notes'];if($missing)$notes[]='Missing area chapters: '.implode(', ',$missing).'. System admin can install the default chapters and review area assignments.';
        $source=mathematicsData()['sources'][$profile['source']];$references[]=['key'=>$profile['key'],'name'=>$profile['name'],'url'=>'','source'=>$source['filename'],'source_sha256'=>$source['sha256'],'source_page'=>$profile['page'],'edition'=>$profile['source']==='cdc'?'2078':'2083','area_marks'=>array_column($profile['areas'],'marks','area'),'warning'=>implode(' ',$notes?:['Exact source area counts and marks. Review term subtopics before issuing the paper.']),'source_areas'=>$profile['areas'],'config'=>['reference_key'=>$profile['key'],'full_marks'=>75,'duration_minutes'=>180,'rows'=>$rows]];
    }return $references;
}
/** Explicit, additive admin setup. Existing chapters/grids and archived records survive. */
function mathematicsInstallDefaults(PDO $db,array $actor):array {
    mathematicsRequireAdmin($actor);
    return learningWrite($db,function()use($db,$actor){
        $counts=['chapters_added'=>0,'grids_added'=>0,'classes'=>0];$catalog=require __DIR__.'/../config/cdc_curriculum.php';
        $q=$db->query("SELECT c.id class_id,s.id subject_id FROM classes c JOIN class_subjects cs ON cs.class_id=c.id AND cs.school_id=0 JOIN subjects s ON s.id=cs.subject_id AND s.school_id=0 WHERE c.school_id=0 AND c.is_active=1 AND cs.is_active=1 AND COALESCE(s.is_hidden,0)=0");
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $scope){$class=(int)$scope['class_id'];$subject=(int)$scope['subject_id'];$grade=mathematicsGrade($db,$class,$subject);if(!$grade)continue;$counts['classes']++;
            $names=$catalog['Mathematics']['Class '.$grade];if($grade===10)array_push($names,'Pyramid (पिरामिड)','Cone (शङ्कु)','Combined Solids (संयुक्त ठोस वस्तुहरू)','Cost Estimation (लागत अनुमान)','Exponential Equations (घाताङ्कीय समीकरणहरू)');
            foreach($names as $order=>$name){$found=false;foreach(curriculumChapters($db,0,$class,$subject,true) as $chapter)if(mathematicsChapterKey($chapter['name'])===mathematicsChapterKey($name)){$found=true;break;}if(!$found){curriculumEnsureChapter($db,0,$class,$subject,$name,'',$order+1);$counts['chapters_added']++;}}
            foreach(mathematicsReferences($db,$actor,$class,$subject) as $reference){$key=$reference['key'];$check=$db->prepare("SELECT payload FROM cdc_reference WHERE category='MATH_DEFAULT_GRID' AND item_key=?");$check->execute([$key]);if($check->fetchColumn()!==false)continue;
                $grid=examSaveGrid($db,$actor,$class,$subject,$reference['name'],$reference['config']);$db->prepare('INSERT INTO cdc_reference(category,item_key,payload) VALUES(?,?,?)')->execute(['MATH_DEFAULT_GRID',$key,json_encode(['grid_id'=>$grid],JSON_THROW_ON_ERROR)]);$counts['grids_added']++;
            }
        }
        if(!$counts['classes'])throw new InvalidArgumentException('Make Class 9/10 compulsory mathematics active in the shared catalog first.');return $counts;
    });
}
