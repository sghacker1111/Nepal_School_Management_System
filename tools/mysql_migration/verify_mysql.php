<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/database_settings.php';

function fail(string $message, int $code = 1): never { fwrite(STDERR, $message . "\n"); exit($code); }
function qi(string $name): string { if (preg_match('/[\x00-\x1f\x7f]/', $name)) fail('Invalid schema identifier.'); return '`' . str_replace('`', '``', $name) . '`'; }
function mysqlPdo(): PDO {
    $env = ['ERP_DB_TYPE'=>getenv('ERP_DB_TYPE') ?: 'mysql','ERP_DB_HOST'=>getenv('ERP_DB_HOST') ?: '', 'ERP_DB_PORT'=>getenv('ERP_DB_PORT') ?: '3306','ERP_DB_NAME'=>getenv('ERP_DB_NAME') ?: '', 'ERP_DB_USER'=>getenv('ERP_DB_USER') ?: '', 'ERP_DB_PASS'=>getenv('ERP_DB_PASS') ?: ''];
    $settings = schoolDatabaseSettings($env);
    if ($settings['host'] !== '127.0.0.1' || !str_starts_with($settings['database'], 'erp_migration_test_')) fail('Verification is restricted to loopback and a disposable test database.');
    return new PDO('mysql:host=127.0.0.1;port='.$settings['port'].';dbname='.$settings['database'].';charset=utf8mb4', $settings['username'], $settings['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_NUM,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false]);
}
function readPackage(string $dir): array {
    $root=realpath($dir); if (!$root || !is_dir($root)) fail('Migration package directory is unavailable.');
    $manifest=json_decode((string)file_get_contents($root.'/migration-manifest.json'),true,512,JSON_THROW_ON_ERROR);
    foreach (['sql_file','statements_file','projected_manifest_file'] as $key) if (!isset($manifest[$key]) || basename($manifest[$key])!==$manifest[$key]) fail('Package manifest contains an unsafe file name.');
    foreach (['sql_file'=>'sql_sha256','statements_file'=>'statements_sha256'] as $file=>$hashKey) if (!hash_equals((string)($manifest[$hashKey]??''),hash_file('sha256',$root.'/'.$manifest[$file]))) fail('Migration package hash verification failed.');
    $projected=json_decode((string)file_get_contents($root.'/'.$manifest['projected_manifest_file']),true,512,JSON_THROW_ON_ERROR);
    $statements=[];$stream=fopen($root.'/'.$manifest['statements_file'],'rb');$sqlHash=hash_init('sha256');hash_update($sqlHash,"-- School ERP confidential export. Import into a NEW EMPTY database only.\n");
    while (($line=fgets($stream))!==false) {
        $statement=json_decode($line,true,512,JSON_THROW_ON_ERROR); if (!is_string($statement)) fail('Invalid SQL statement manifest.');
        if (!preg_match('/\A(?:SET @ERP_OLD_[A-Z_]+=@@SESSION\.[a-z_]+|SET SESSION (?:sql_mode|foreign_key_checks|time_zone)=|SET SESSION (?:character_set_client|character_set_results|character_set_connection|collation_connection)=@ERP_OLD_[A-Z_]+|SET NAMES utf8mb4|CREATE TABLE `|START TRANSACTION\z|INSERT INTO `|COMMIT\z)/i',$statement)) fail('SQL statement is outside the generated migration allowlist.');
        hash_update($sqlHash,$statement.";\n");$statements[]=$statement;
    }
    fclose($stream); if (!hash_equals($manifest['sql_sha256'],hash_final($sqlHash))) fail('SQL dump and verified statement list do not match.');
    return [$root,$manifest,$projected,$statements];
}
function normalizeNumber(string $s): string { $s=trim($s); if (str_contains($s,'e')||str_contains($s,'E')) { $s=rtrim(rtrim(sprintf('%.15F',(float)$s),'0'),'.'); } if (str_contains($s,'.')) $s=rtrim(rtrim($s,'0'),'.'); if ($s===''||$s==='-0'||$s==='+0') return '0'; return $s; }
function rowDigest(PDO $db,string $table,array $meta,array $types): array {
    $cols=array_column($meta['columns'],'name');$query='SELECT '.implode(',',array_map('qi',$cols)).' FROM '.qi($table);$stmt=$db->query($query);$hashes=[];$count=0;
    while (($row=$stmt->fetch())!==false) { $values=[]; foreach ($row as $i=>$value) { $decl=(string)$meta['columns'][$i]['type']; $type=$types[$cols[$i]]??''; if ($value===null) $values[]=['null']; elseif (str_contains(strtoupper($type),'BLOB')) $values[]=['bytes',bin2hex($value)]; elseif (preg_match('/INT|DECIMAL|NUMERIC|REAL|DOUBLE|FLOAT/i',$decl)) $values[]=['number',normalizeNumber((string)$value)]; else $values[]=['text',(string)$value]; }
        $json=json_encode($values,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$hashes[]=hash('sha256',$json);$count++; }
    $stmt->closeCursor();
    sort($hashes,SORT_STRING);return [hash('sha256',implode("\n",$hashes)),$count];
}
function inspect(PDO $db,array $manifest,array $projected): array {
    $results=[];$violations=0;$expected=$projected['tables']??[];$mapping=$manifest['schema_mappings']??[];
    foreach ($expected as $table=>$meta) { $types=$mapping[$table]['columns']??[];[$digest,$count]=rowDigest($db,$table,$meta,$types);$seq=null;
        if (isset($mapping[$table]['auto_increment'])&&$mapping[$table]['auto_increment']) { $q=$db->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);$seq=$q->fetchColumn();$q->closeCursor();$seq=$seq===false||$seq===null?null:(int)$seq; }
        $results[$table]=['rows_digest'=>$digest,'row_count'=>$count,'sequence'=>$seq];
    }
    foreach ($expected as $table=>$meta) { $groups=[]; foreach (($meta['foreign_keys']??[]) as $fk) $groups[$fk['id']][]=$fk;
        foreach ($groups as $group) { usort($group,fn($a,$b)=>$a['seq']<=>$b['seq']);$parent=$group[0]['parent'];$child=array_column($group,'from');$parents=array_column($group,'to');$where=[];$join=[];
            foreach ($child as $i=>$col) { $where[]='c.'.qi($col).' IS NOT NULL';$join[]='p.'.qi($parents[$i]).'=c.'.qi($col); }
            $sql='SELECT COUNT(*) FROM '.qi($table).' c WHERE '.implode(' AND ',$where).' AND NOT EXISTS (SELECT 1 FROM '.qi($parent).' p WHERE '.implode(' AND ',$join).')';$stmt=$db->query($sql);$violations+=(int)$stmt->fetchColumn();$stmt->closeCursor();
        }
    }
    $stmt=$db->query("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'");$version=$stmt->fetchColumn();$stmt->closeCursor();$stmt=$db->query('SELECT VERSION()');$engine=$stmt->fetchColumn();$stmt->closeCursor();
    return ['tables'=>$results,'foreign_key_violations'=>$violations,'schema_version'=>(string)$version,'engine'=>$engine];
}
$phase='package';
try {
    if (PHP_SAPI!=='cli') fail('This bridge is CLI-only.');
    $options=getopt('', ['action:','package:']);$action=$options['action']??'';$package=$options['package']??'';
    if (!in_array($action,['import','inspect'],true)||$package==='') fail('Use --action import|inspect --package <private-directory>.');
    [$root,$manifest,$projected,$statements]=readPackage($package);$phase='connect';$db=mysqlPdo();
    if ($action==='import') {
        $existing=(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")->fetchColumn();
        if ($existing!==0) fail('Target database is not empty; import was refused before any writes.');
        $phase='import';foreach ($statements as $index=>$statement) {
            try {
                $db->exec($statement);
                // SHOW WARNINGS itself cannot use the native prepare protocol.
                // This fixed diagnostic query contains no values or placeholders.
                $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,true);
                try{$warnings=$db->query('SHOW WARNINGS');$warning=$warnings->fetch();$warnings->closeCursor();}
                finally{$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);}
                if($warning)throw new RuntimeException('MySQL emitted storage warning '.(int)$warning[1].' at statement '.$index.'; verification stopped without exposing row values.');
            }
            catch (PDOException $error) {
                $details=['statement_index'=>$index,'statement_kind'=>strtok($statement," \n"),'table'=>preg_match('/^(?:CREATE TABLE|INSERT INTO) `([^`]+)/i',$statement,$match)?$match[1]:null,'sql_state'=>$error->errorInfo[0]??(string)$error->getCode(),'driver_code'=>$error->errorInfo[1]??null];
                @file_put_contents($root.'/verification-error.json',json_encode($details,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);@chmod($root.'/verification-error.json',0600);throw $error;
            }
        }
    }
    $phase='readback';$actual=inspect($db,$manifest,$projected);$mismatches=[];
    foreach ($projected['tables'] as $table=>$meta) { $found=$actual['tables'][$table]??null;if (!$found||$found['row_count']!==$meta['row_count']||$found['rows_digest']!==$meta['rows_digest'])$mismatches[]=$table;
        $next=$manifest['schema_mappings'][$table]['next_id']??null;if ($next!==null&&$found['sequence']!==null&&$found['sequence']<$next)$mismatches[]=$table.':sequence'; }
    $known=count($manifest['source_manifest']['foreign_key_violations']??[]);$new=$actual['foreign_key_violations']-$known;
    $report=['preserved'=>!$mismatches&&$actual['schema_version']==='18'&&$new===0,'known_orphans'=>$known,'new_violations'=>$new,'engine'=>$actual['engine'],'mismatches'=>$mismatches,'actual'=>$actual];
    if (!$report['preserved']) fail(json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
} catch (Throwable $error) {
    if (isset($root) && is_dir($root)) { $details=['phase'=>$phase,'exception'=>get_class($error),'sql_state'=>$error instanceof PDOException?($error->errorInfo[0]??(string)$error->getCode()):null,'driver_code'=>$error instanceof PDOException?($error->errorInfo[1]??null):null];@file_put_contents($root.'/verification-error.json',json_encode($details,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);@chmod($root.'/verification-error.json',0600); }
    fail($error instanceof PDOException?'MySQL migration verification failed; credentials and row contents are withheld.':'Migration verification failed: '.$error->getMessage());
}
