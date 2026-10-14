<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/mcq_practice.php';
Auth::requireRole(['super_admin','school_admin','principal','teacher','exam_officer','student']);
Auth::requireAccess('learning');
$db=getDB();$school=(int)Auth::getSchoolId();$role=(string)Auth::getRole();$actor=(int)Auth::getUserId();
$contentActor=learningActor($db,$actor,$school,Auth::isReadOnly());$classes=learningReadClasses($db,$contentActor);
$action=$_SERVER['REQUEST_METHOD']==='POST'?($_POST['action']??''):($_GET['action']??'');
if($action!==''){
    header('Content-Type: application/json');header('Cache-Control: no-store');
    try{
        if($_SERVER['REQUEST_METHOD']==='GET'&&$action==='subjects'){
            $class=(int)($_GET['class_id']??0);
            if(!in_array($class,array_map('intval',array_column($classes,'id')),true))throw new InvalidArgumentException('Choose an available class.');
            $result=['subjects'=>learningReadSubjects($db,$contentActor,$class)];
        }elseif($_SERVER['REQUEST_METHOD']==='GET'&&$action==='catalog'){
            $result=['questions'=>practiceCatalog(practicePool($db,$school,$role,$actor,(int)($_GET['class_id']??0),(int)($_GET['subject_id']??0)))];
        }elseif($_SERVER['REQUEST_METHOD']==='GET'&&$action==='state'){
            $result=['practice'=>null];
            if(isset($_SESSION['mcq_practice'])){practiceValidateSession($db,$school,$role,$actor,$_SESSION['mcq_practice']);$result['practice']=practicePublicState($_SESSION['mcq_practice']);}
        }elseif($_SERVER['REQUEST_METHOD']==='POST'&&$action==='start'){
            $_SESSION['mcq_practice']=practiceStart($db,$school,$role,$actor,$_POST);$result=['practice'=>practicePublicState($_SESSION['mcq_practice'])];
        }elseif($_SERVER['REQUEST_METHOD']==='POST'&&in_array($action,['answer','next'],true)){
            if(!isset($_SESSION['mcq_practice']))throw new InvalidArgumentException('Start a practice first.');
            practiceValidateSession($db,$school,$role,$actor,$_SESSION['mcq_practice']);
            $result=['practice'=>practiceTransition($_SESSION['mcq_practice'],$action,$_POST)];
        }elseif($_SERVER['REQUEST_METHOD']==='POST'&&$action==='reset'){
            unset($_SESSION['mcq_practice']);$result=['practice'=>null];
        }else throw new InvalidArgumentException('Choose a valid practice action.');
        echo json_encode(['success'=>true,...$result],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
    }catch(InvalidArgumentException $e){if(str_contains($e->getMessage(),'restart'))unset($_SESSION['mcq_practice']);http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
    catch(Throwable $e){error_log($e->getMessage());http_response_code(500);echo json_encode(['success'=>false,'message'=>'Practice could not be loaded. Please retry.']);}
    exit;
}
$current=null;
if(isset($_SESSION['mcq_practice'])){
    try{practiceValidateSession($db,$school,$role,$actor,$_SESSION['mcq_practice']);$current=practicePublicState($_SESSION['mcq_practice']);}
    catch(InvalidArgumentException $e){unset($_SESSION['mcq_practice']);}
}
$pageTitle='Practice MCQs';$activePage='practice_mcqs';$pageStyles=['/assets/css/mcq-practice.css?v='.(int)filemtime(__DIR__.'/../../assets/css/mcq-practice.css')];ob_start();
?>
<script>window.MathJax={tex:{inlineMath:[['\\(','\\)'],['$','$']],displayMath:[['\\[','\\]'],['$$','$$']],processEscapes:true}};</script>
<script async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>
<section id="practiceApp" class="mcq-practice" aria-label="MCQ practice">
 <header class="practice-heading"><div><span class="practice-eyebrow">LEARNING · ONE QUESTION AT A TIME</span><h1>Practice MCQs</h1><p>Choose your questions and get instant feedback.</p></div><div class="practice-tools"><button type="button" id="practiceSound" class="btn btn-outline-secondary" aria-pressed="true">Sound on</button><button type="button" id="practiceFullscreen" class="btn btn-outline-primary">Full screen</button></div></header>
 <div id="practiceError" class="practice-error" role="alert" hidden></div>
 <div id="practiceSetup" class="practice-panel">
  <form id="practiceSetupForm" method="GET">
   <div class="practice-filters">
    <div><label for="practiceClass">Class</label><select id="practiceClass" class="form-select" required><option value="">Choose class</option><?php foreach($classes as $c):?><option value="<?= (int)$c['id'] ?>" <?= $role==='student'?'selected':'' ?>><?= sanitize($c['name']) ?></option><?php endforeach;?></select></div>
    <div><label for="practiceSubject">Subject</label><select id="practiceSubject" class="form-select" required disabled><option value="">Choose class first</option></select></div>
    <div><label for="practiceChapter">Chapter</label><select id="practiceChapter" class="form-select" disabled><option value="">All chapters</option></select></div>
    <div><label for="practiceTopic">Topic</label><select id="practiceTopic" class="form-select" disabled><option value="">All topics</option></select></div>
   </div>
   <div class="practice-search"><label for="practiceSearch">Find MCQs</label><input id="practiceSearch" class="form-control" type="search" maxlength="100" placeholder="Search question text" disabled></div>
   <div class="practice-config"><div><label for="practiceSelection">Choose MCQs</label><select id="practiceSelection" class="form-select"><option value="random">Random MCQs from these filters</option><option value="chosen">Choose individual MCQs</option></select></div><div><label for="practiceCount">Number of MCQs</label><input id="practiceCount" type="number" class="form-control" value="10" min="1" max="100" required><small id="practiceCountHint">Up to 100 per practice.</small></div></div>
   <div id="practiceChoices" hidden><div class="practice-choice-toolbar"><strong id="practiceSelectedCount">0 selected</strong><div><button id="practiceSelectAll" type="button" class="btn btn-sm btn-outline-primary">Select shown</button><button id="practiceClear" type="button" class="btn btn-sm btn-outline-secondary">Clear</button></div></div><div id="practiceQuestionList" class="practice-question-list"></div></div>
   <div class="practice-start"><p id="practiceAvailable" role="status">Choose a class and subject to load MCQs.</p><button id="practiceStart" class="btn btn-primary btn-lg" type="submit" disabled>Start practice <span aria-hidden="true">→</span></button></div>
  </form>
 </div>
 <div id="practiceStage" class="practice-stage" hidden>
 <div id="practiceQuiz" class="practice-panel practice-quiz" hidden>
  <div class="practice-progress-meta"><strong id="practicePosition"></strong><span id="practiceLiveScore"></span></div><div class="practice-progress" role="progressbar" id="practiceProgress" aria-label="Practice progress" aria-valuemin="0" aria-valuemax="100"><span id="practiceProgressFill"></span></div>
  <p id="practiceQuizTitle" class="practice-subject"></p><h2 id="practiceQuestion" class="practice-question" tabindex="-1"></h2><div id="practiceQuestionNp" class="practice-nepali font-nepali"></div>
  <div id="practiceOptions" class="practice-options" role="group" aria-label="Choose one answer"></div>
  <div id="practiceFeedback" class="practice-feedback" role="status" aria-live="polite" hidden></div>
  <div class="practice-next"><button id="practiceLeave" class="btn btn-outline-secondary" type="button">End practice</button><button id="practiceNext" class="btn btn-primary" type="button" hidden>Next question →</button></div>
 </div>
 <div id="practiceSummary" class="practice-panel practice-summary" hidden><span class="practice-eyebrow">PRACTICE COMPLETE</span><h2>Keep learning, one question at a time.</h2><div id="practiceScore" class="practice-score"></div><p id="practiceSummaryText"></p><button id="practiceAgain" class="btn btn-primary" type="button">Back to class &amp; subject selection</button><details class="practice-review"><summary>Review your answers</summary><div id="practiceReview"></div><button id="practiceReviewBack" class="btn btn-outline-primary mt-3" type="button">Back to class &amp; subject selection</button></details></div>
 </div>
 <noscript><p class="alert alert-info">Enable JavaScript to use interactive MCQ practice.</p></noscript>
</section>
<script id="practiceBootstrap" type="application/json"><?= json_encode(['current'=>$current,'limit'=>MCQ_PRACTICE_LIMIT],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script defer src="<?= APP_URL ?>/assets/js/mcq-practice.js?v=<?= (int)filemtime(__DIR__.'/../../assets/js/mcq-practice.js') ?>"></script>
<?php $content=ob_get_clean();include __DIR__.'/../../includes/header.php';
