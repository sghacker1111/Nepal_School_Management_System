<?php
require_once __DIR__.'/../../config/bootstrap.php';
require_once __DIR__.'/../../includes/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/functions.php';
Auth::requireRole('super_admin');
$db=getDB();$offeringAdmin=true;
$school_id=(int)($_GET['school_id']??0);
require __DIR__.'/../../includes/school_offerings_page.php';
