<?php
require __DIR__.'/../config/bootstrap.php';require __DIR__.'/../includes/database.php';require __DIR__.'/../includes/question_correction_service.php';
$db=getDB();echo questionCorrectionSubmit($db,learningActor($db,(int)$argv[1]),(int)$argv[2],'Concurrent report','Use corrected answer');
