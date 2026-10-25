<?php
require_once __DIR__.'/exam_paper_groups.php';
$isLearningPaper=true;$paperGroups=examPaperGroups($paper,$paperFormat['heading_style']);
include __DIR__.'/exam_paper_output.php';
