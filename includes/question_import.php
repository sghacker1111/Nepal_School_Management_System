<?php
require_once __DIR__.'/curriculum_catalog.php';

function questionImportLabel(string $value):string {
    return curriculumNameKey(str_replace('&',' and ',curriculumName($value)));
}

/** Known curriculum labels only; optional and compulsory subjects remain distinct. */
function questionImportSubjectKey(string $value):string {
    $key=questionImportLabel($value);
    $groups=[
        'mathematics'=>['mathematics','math','maths','compulsory mathematics','compulsory math','compulsory maths','गणित','अनिवार्य गणित'],
        'optional mathematics'=>['optional mathematics','optional math','optional maths','ऐच्छिक गणित'],
        'science'=>['science','science and technology','compulsory science','विज्ञान','विज्ञान तथा प्रविधि','विज्ञान र प्रविधि'],
        'english'=>['english','compulsory english','अंग्रेजी','अङ्ग्रेजी','अनिवार्य अंग्रेजी','अनिवार्य अङ्ग्रेजी'],
        'nepali'=>['nepali','compulsory nepali','नेपाली','अनिवार्य नेपाली'],
        'social studies'=>['social studies','social','सामाजिक अध्ययन','सामाजिक शिक्षा'],
    ];
    foreach($groups as $canonical=>$aliases)if(in_array($key,$aliases,true))return $canonical;
    return $key;
}

/** Match only subjects already permitted for this actor and this exact class. */
function questionImportSubjectId(array $subjects,string $value):int {
    $label=questionImportLabel($value);$matches=[];
    foreach($subjects as $subject){
        foreach(['name_en','name_np','code'] as $field){
            $candidate=questionImportLabel((string)($subject[$field]??''));
            if($candidate!==''&&$candidate===$label){$matches[(int)$subject['id']]=true;break;}
        }
    }
    if(!$matches){
        $key=questionImportSubjectKey($value);
        foreach($subjects as $subject)foreach(['name_en','name_np'] as $field){
            $candidate=(string)($subject[$field]??'');
            if($candidate!==''&&questionImportSubjectKey($candidate)===$key){$matches[(int)$subject['id']]=true;break;}
        }
    }
    if(count($matches)===1)return (int)array_key_first($matches);
    $label=mb_substr(curriculumName($value),0,100,'UTF-8');
    if($matches)throw new InvalidArgumentException('Subject "'.$label.'" matches multiple assigned subjects. Use its subject code.');
    throw new InvalidArgumentException('Subject "'.$label.'" is not assigned or available for this class. Use a listed subject name or code.');
}
