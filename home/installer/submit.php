<?php
$data = json_decode(file_get_contents('php://input'), true);
//echo json_encode($data);
$dir = dirname(__DIR__);

$configSampleFile = $dir.'/app/etc/config.php.sample';
$redisSampleFile = $dir.'/app/etc/redis.php.sample'; 
$composerSampleFile = $dir.'/composer.json.sample'; 

$configSampleData = file_get_contents($configSampleFile);
$redisSampleData = file_get_contents($redisSampleFile);
$composerSampleData = file_get_contents($composerSampleFile);

$content = replaceContentThroughArray($data,$configSampleData);
if(!file_exists($dir.'/app/etc/config.php')){
	
	try{
		touch($dir.'/app/etc/config.php');
	}
	catch(\Exception $e){
		echo json_encode(['success'=>false,'message'=>'Create app/etc/config.php with writable permission.']);
		die;
	}
}
$handle = fopen($dir.'/app/etc/config.php','w');
fwrite($handle,$content);
fclose($handle);

$content = replaceContentThroughArray($data,$redisSampleData);

if(!file_exists($dir.'/app/etc/redis.php')){
	try{
		touch($dir.'/app/etc/redis.php');
	}
	catch(\Exception $e){
		echo json_encode(['success'=>false,'message'=>'Create app/etc/redis.php with writable permission.']);
		die;
	}
	
}

mkdir($dir.'/var/log',0777);
touch($dir.'/var/log/system.log');

$handle = fopen($dir.'/app/etc/redis.php','w');
fwrite($handle,$content);
fclose($handle);

list($modules,$repos) = getComposerModulesAndRepos($data);
$composerData = ['modules'=>$modules,'repositories'=>$repos,'githost'=>$data['githost']??'github.com'];

$content = replaceContentThroughArray($composerData,$composerSampleData);

if(!file_exists($dir.'/composer.json')){
	
	try{
		touch($dir.'/composer.json');
	}
	catch(\Exception $e){
		echo json_encode(['success'=>false,'message'=>'Create composer.json with writable permission.']);
		die;
	}
}
$handle = fopen($dir.'/composer.json','w');
fwrite($handle,$content);
fclose($handle);

function replaceContentThroughArray($array,$content){
	foreach($array as $key=>$value){
		if(is_string($value)){
			$content = str_replace('{{'.$key.'}}', $value, $content);
		}
	}
	return $content;
}

function getComposerModulesAndRepos($data){
	$modules = '';
	$repos = '';
	$gitHost = $data['githost'] ?? 'github.com';
	foreach ($data['modules'] as $module) {
		if(!$module['required'] && $module['selected'] ){
			$modules .= ',
		"cedcoss/'.$module['name'].'": "dev-master"';

			$repos .= ',
		{
            "type": "git",
            "url": "git@'.$data['githost'].':cedcoss/'.$module['name'].'.git"
        }';
		}
	}
	return [$modules,$repos];
}

echo json_encode(['success'=>true]);