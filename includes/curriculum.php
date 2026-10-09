<?php
/** Starter catalog retained from this installation; schools must review subject-specific CDC schemes. */
function seedUniversalCurriculum(PDO $db): void {
$subjects_catalog = [
    // Pre-Primary (Nursery, LKG, UKG, ECD) Compulsory
    'pre_nep'   => ['code' => 'NEP-00', 'en' => 'Compulsory Nepali', 'np' => 'अनिवार्य नेपाली', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'pre_eng'   => ['code' => 'ENG-00', 'en' => 'Compulsory English', 'np' => 'अनिवार्य अंग्रेजी', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'pre_mat'   => ['code' => 'MAT-00', 'en' => 'Mathematics', 'np' => 'गणित', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'pre_env'   => ['code' => 'ENV-00', 'en' => 'Our Environment & GK', 'np' => 'हाम्रो वातावरण तथा सामान्य ज्ञान', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 3],
    'pre_art'   => ['code' => 'ART-00', 'en' => 'Creative Arts', 'np' => 'सिर्जनात्मक कला', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 2],

    // Grades 1-3 (Classes 1, 2, 3) Compulsory & Optional/Local Component
    'g13_nep'   => ['code' => 'NEP-123', 'en' => 'Nepali', 'np' => 'नेपाली', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'g13_eng'   => ['code' => 'ENG-123', 'en' => 'English', 'np' => 'अंग्रेजी', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g13_mat'   => ['code' => 'MAT-123', 'en' => 'Mathematics', 'np' => 'गणित', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g13_ser'   => ['code' => 'SER-123', 'en' => 'Our Surroundings (हाम्रो सेरोफेरो)', 'np' => 'हाम्रो सेरोफेरो', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 8],
    'g13_loc'   => ['code' => 'LOC-123', 'en' => 'Local Curriculum / Mother Tongue', 'np' => 'स्थानीय पाठ्यक्रम / मातृभाषा', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],

    // Grades 4-5 (Classes 4, 5) Compulsory & Local Component
    'g45_nep'   => ['code' => 'NEP-45', 'en' => 'Nepali', 'np' => 'नेपाली', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'g45_eng'   => ['code' => 'ENG-45', 'en' => 'English', 'np' => 'अंग्रेजी', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g45_mat'   => ['code' => 'MAT-45', 'en' => 'Mathematics', 'np' => 'गणित', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g45_sci'   => ['code' => 'SCI-45', 'en' => 'Science and Technology', 'np' => 'विज्ञान तथा प्रविधि', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g45_soc'   => ['code' => 'SOC-45', 'en' => 'Social Studies and Human Values Education', 'np' => 'सामाजिक अध्ययन तथा मानव मूल्य शिक्षा', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g45_hpc'   => ['code' => 'HPC-45', 'en' => 'Health, Physical and Creative Arts', 'np' => 'स्वास्थ्य, शारीरिक तथा सिर्जनात्मक कला', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 3],
    'g45_loc'   => ['code' => 'LOC-45', 'en' => 'Local Curriculum / Mother Tongue', 'np' => 'स्थानीय पाठ्यक्रम / मातृभाषा', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],

    // Grades 6-8 (Classes 6, 7, 8) Main Subjects
    'g68_nep'   => ['code' => 'NEP-68', 'en' => 'Nepali', 'np' => 'नेपाली', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'g68_eng'   => ['code' => 'ENG-68', 'en' => 'English', 'np' => 'अंग्रेजी', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g68_mat'   => ['code' => 'MAT-68', 'en' => 'Mathematics', 'np' => 'गणित', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g68_sci'   => ['code' => 'SCI-68', 'en' => 'Science and Technology', 'np' => 'विज्ञान तथा प्रविधि', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g68_soc'   => ['code' => 'SOC-68', 'en' => 'Social Studies and Human Values Education', 'np' => 'सामाजिक अध्ययन तथा मानव मूल्य शिक्षा', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g68_hpe'   => ['code' => 'HPE-68', 'en' => 'Health and Physical Education', 'np' => 'स्वास्थ्य तथा शारीरिक शिक्षा', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 3],
    'g68_loc'   => ['code' => 'LOC-68', 'en' => 'Local/Mother-Tongue Subject', 'np' => 'स्थानीय विषय / मातृभाषा', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],

    // Grades 9-10 (Classes 9, 10) Compulsory (SEE)
    'g910_nep'  => ['code' => 'NEP-910', 'en' => 'Nepali', 'np' => 'नेपाली', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'g910_eng'  => ['code' => 'ENG-910', 'en' => 'English', 'np' => 'अंग्रेजी', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'g910_mat'  => ['code' => 'MAT-910', 'en' => 'Mathematics', 'np' => 'गणित', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'g910_sci'  => ['code' => 'SCI-910', 'en' => 'Science and Technology', 'np' => 'विज्ञान तथा प्रविधि', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'g910_soc'  => ['code' => 'SOC-910', 'en' => 'Social Studies', 'np' => 'सामाजिक अध्ययन', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],

    // Grades 9-10 Elective / Optional Subjects
    'opt_sub1'  => ['code' => 'OPT-1',   'en' => 'Optional Subject I', 'np' => 'ऐच्छिक विषय १', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'opt_sub2'  => ['code' => 'OPT-2',   'en' => 'Optional Subject II', 'np' => 'ऐच्छिक विषय २', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'opt_mat'   => ['code' => 'OPT-MAT', 'en' => 'Optional Mathematics', 'np' => 'ऐच्छिक गणित', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'opt_com'   => ['code' => 'OPT-COM', 'en' => 'Computer Science', 'np' => 'कम्प्युटर विज्ञान', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'opt_acc'   => ['code' => 'OPT-ACC', 'en' => 'Accountancy', 'np' => 'लेखा', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'opt_eco'   => ['code' => 'OPT-ECO', 'en' => 'Economics', 'np' => 'अर्थशास्त्र', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'opt_hpe'   => ['code' => 'OPT-HPE', 'en' => 'Health and Physical Education', 'np' => 'स्वास्थ्य तथा शारीरिक शिक्षा', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'opt_geo'   => ['code' => 'OPT-GEO', 'en' => 'Geography', 'np' => 'भूगोल', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'opt_agr'   => ['code' => 'OPT-AGR', 'en' => 'Agriculture', 'np' => 'कृषि विज्ञान', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'opt_edu'   => ['code' => 'OPT-EDU', 'en' => 'Education', 'np' => 'शिक्षा', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],

    // Grades 11-12 Compulsory (NEB)
    'g1112_eng' => ['code' => 'ENG-1112', 'en' => 'Compulsory English', 'np' => 'अनिवार्य अंग्रेजी', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g1112_nep' => ['code' => 'NEP-1112', 'en' => 'Compulsory Nepali', 'np' => 'अनिवार्य नेपाली', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 3],
    'g1112_sol' => ['code' => 'SOL-1112', 'en' => 'Social Studies and Life Skills', 'np' => 'सामाजिक अध्ययन तथा जीवनोपयोगी शिक्षा', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 5],

    // Grades 11-12 Optional Subjects (Science, Management, Humanities, Law Clusters)
    'opt11_phy' => ['code' => 'OPT-PHY', 'en' => 'Physics', 'np' => 'भौतिक विज्ञान', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_che' => ['code' => 'OPT-CHE', 'en' => 'Chemistry', 'np' => 'रसायन विज्ञान', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_bio' => ['code' => 'OPT-BIO', 'en' => 'Biology', 'np' => 'जीव विज्ञान', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_mat' => ['code' => 'OPT-MAT11', 'en' => 'Mathematics', 'np' => 'गणित', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_com' => ['code' => 'OPT-COM11', 'en' => 'Computer Science', 'np' => 'कम्प्युटर विज्ञान', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_acc' => ['code' => 'OPT-ACC11', 'en' => 'Accounting', 'np' => 'लेखाविधि', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_eco' => ['code' => 'OPT-ECO11', 'en' => 'Economics', 'np' => 'अर्थशास्त्र', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_bst' => ['code' => 'OPT-BST', 'en' => 'Business Studies', 'np' => 'व्यवसाय अध्ययन', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_bmt' => ['code' => 'OPT-BMT', 'en' => 'Business Mathematics', 'np' => 'व्यापारिक गणित', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_hmg' => ['code' => 'OPT-HMG', 'en' => 'Hotel Management', 'np' => 'होटल व्यवस्थापन', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_soc' => ['code' => 'OPT-SOC', 'en' => 'Sociology', 'np' => 'समाजशास्त्र', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_pol' => ['code' => 'OPT-POL', 'en' => 'Political Science', 'np' => 'राजनीति शास्त्र', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_law' => ['code' => 'OPT-LAW', 'en' => 'Jurisprudence and Legal Theories', 'np' => 'कानून', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5]
];

    // Local curriculum is part of the curriculum, not an individual student elective.
    foreach (['g13_loc','g45_loc'] as $key) $subjects_catalog[$key]['type']='compulsory';
    $groups = [
        '1-3'=>['g13_nep','g13_eng','g13_mat','g13_ser','g13_loc'],
        '4-5'=>['g45_nep','g45_eng','g45_mat','g45_sci','g45_soc','g45_hpc','g45_loc'],
        '6-8'=>['g68_nep','g68_eng','g68_mat','g68_sci','g68_soc','g68_hpe','g68_loc'],
        '9-10'=>['g910_nep','g910_eng','g910_mat','g910_sci','g910_soc','opt_mat','opt_com','opt_acc','opt_eco','opt_hpe','opt_geo','opt_agr','opt_edu'],
        '11-12'=>['g1112_eng','g1112_nep','g1112_sol','opt11_phy','opt11_che','opt11_bio','opt11_mat','opt11_com','opt11_acc','opt11_eco','opt11_bst','opt11_bmt','opt11_hmg','opt11_soc','opt11_pol','opt11_law']
    ];
    $classQuery=$db->prepare('SELECT id FROM classes WHERE name=? AND school_id=0');
    $subjectQuery=$db->prepare('SELECT id FROM subjects WHERE code=? AND school_id=0');
    $mapQuery=$db->prepare('SELECT id FROM class_subjects WHERE class_id=? AND subject_id=? AND school_id=0');
    foreach(range(1,12) as $grade) {
        $classQuery->execute(['Class '.$grade]);$classId=(int)$classQuery->fetchColumn();
        if(!$classId)continue;
        $group=$grade<=3?'1-3':($grade<=5?'4-5':($grade<=8?'6-8':($grade<=10?'9-10':'11-12')));
        foreach($groups[$group] as $key) {
            $s=$subjects_catalog[$key];$subjectQuery->execute([$s['code']]);$subjectId=$subjectQuery->fetchColumn();
            // Component values are editable templates, never retroactively applied to saved exams.
            $theory=$grade<=3?100:($grade<=8?50:75);
            $internal=100-$theory;
            if (!$subjectId) $subjectId=insertRecord($db,'subjects',['code'=>$s['code'],'name_en'=>$s['en'],'name_np'=>$s['np'],'type'=>$s['type'],'full_marks'=>100,'pass_marks'=>35,'credit_hours'=>$s['cr'],'school_id'=>0,'th_full_marks'=>$theory,'th_pass_marks'=>$theory*0.35,'in_full_marks'=>$internal,'in_pass_marks'=>$internal*0.4,'th_credit_hours'=>$s['cr']*$theory/100,'in_credit_hours'=>$s['cr']*$internal/100]);
            $mapQuery->execute([$classId,$subjectId]);if($mapQuery->fetchColumn())continue;
            insertRecord($db,'class_subjects',['class_id'=>$classId,'subject_id'=>$subjectId,'school_id'=>0,'credit_hours'=>$s['cr'],'th_full_marks'=>$theory,'th_pass_marks'=>$theory*0.35,'in_full_marks'=>$internal,'in_pass_marks'=>$internal*0.4,'th_credit_hours'=>$s['cr']*$theory/100,'in_credit_hours'=>$s['cr']*$internal/100,'is_optional'=>$s['type']==='optional'?1:0,'is_active'=>1]);
        }
    }
}
