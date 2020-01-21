#!/usr/bin/php
<?php
ini_set('memory_limit','4096M');

#$collection = 'mevi';
$collection = 'mhealthevidenceRight';

#$dbhost = '127.0.0.1';
$dbhost = 'localhost';
$dbuser = 'root';
$dbpass = 'root';
   
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
$warnings = array();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
	global $warnings;
	global $nid;
	$warnings[] =  "Badness on $nid ($errno) at $errline:\n " . trim($errstr) . "\n";
    });

$fields = array(
    'nid'=>'nid',
    'title'=>'title',
    'body_value'=>'body_value',
    'mterg.name' => 'mterg_terms',
    //'mesh.name' => 'mesh_terms',
    'journal.name'=> 'journal_title',
    'author.name'=> 'author',
    'pubmed_doi_value' => 'doi',
    'date_format(pubmed_publication_date_value,"%Y-%m-%d")'=>'publication_date'
    );
$qry = 'select ' ;

foreach ($fields as $d=>$n) {
    $qry .= ' ' . $d . ' as  ' . $n . ',';
}
$qry = rtrim($qry,",");

$qry .= ' FROM node
	LEFT JOIN field_data_body ON field_data_body.entity_id = node.nid
	LEFT  JOIN field_data_pubmed_author ON field_data_pubmed_author.entity_id = node.nid
	LEFT  JOIN field_data_field_mterg_terms ON field_data_field_mterg_terms.entity_id = node.nid
	LEFT JOIN field_data_pubmed_language ON field_data_pubmed_language.entity_id = node.nid
	LEFT  JOIN field_data_pubmed_journal_title ON field_data_pubmed_journal_title.entity_id = node.nid
	LEFT JOIN taxonomy_term_data as author ON pubmed_author_tid = author.tid
	LEFT JOIN taxonomy_term_data  as mterg ON field_mterg_terms_tid =  mterg.tid
	LEFT JOIN taxonomy_term_data as lang ON pubmed_language_tid = lang.tid
	LEFT JOIN taxonomy_term_data as journal ON pubmed_journal_title_tid = journal.tid
    LEFT JOIN field_data_pubmed_doi ON field_data_pubmed_doi.entity_id = node.nid
    LEFT JOIN field_data_pubmed_publication_date ON field_data_pubmed_publication_date.entity_id = node.nid
WHERE node.type=\'pubmed\'
order by nid';

//    LEFT JOIN taxonomy_term_data  as mesh ON pubmed_mesh_tid =  mesh.tid
//    LEFT  JOIN field_data_pubmed_mesh ON field_data_pubmed_mesh.entity_id = node.nid



//$mysqli = new mysqli($dbhost, $dbuser, $dbpass, $collection);

$pdo = new PDO("mysql:host=$dbhost;dbname=$collection", $dbuser, $dbpass, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8") );
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

//if ($mysqli->connect_errno){  die("Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);}
//$mysqli->set_charset('utf8');


/*$filenames = array(
    'resource_file'=>'file',
    'field_link' => 'link',
    'field_outside_link'=>'link',
    'field_link_website'=>'link',
    'image_field'=>'file',
    'image_file'=>'file',
    'cover_image'=>'auxfile'
     
    );*/
    
$furl = 'https://www.' . $collection . '.org/sites/default/files/';
$furl = 'https://www.mhealthevidence.org/sites/default/files/';



$headers = array();
//foreach (explode(",",$fields) as $f) {
foreach (array_values($fields) as $f) {
    $f = trim($f);
    if (strlen($f) == 0) {continue;   }
    foreach(explode(' as ',$f) as $p) {
	$p = trim($p);
	if (strlen($p) == 0) {  continue;}
	$f = $p;
    }
    $headers[] = $f;
}

$data =array();
$nid = false;
$r = array();
$result = $pdo->query($qry);
var_dump($result);
echo $qry;
//$count=0;
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
	/*$count++;
	if ($count >200)
		break;*/
    if ( $row['nid'] != $nid) {
	//if new entry new entry so save old one
	if ($nid) {
		if (array_key_exists('body_value',$r) && count($r['body_value'] >0 )) { $r['body_value'] = array($r['body_value'][0]);}
		$data[$nid] = $r;
	}
	//now reset for new one
	$nid  = $row['nid'];
	echo "Consolidating data for $nid\n";
	foreach ($headers as $c) {
	    if ($c == 'nid') {continue;}
	    $r[$c] = array();
	}
    } 
    foreach ($headers as $c) {
	if (($c == 'nid') ) {continue;}
	$d = $row[$c];
	$r[$c][] = $d;
	if (strlen($d) > 0) {
	    echo "\tAdding to $c: " . (str_replace(array("\n\r", "\n", "\r")," ", substr($d,0,60))) . (strlen($d) > 60 ? '...': '') . "\n";
	}
    }
}
$data[$nid] = $r;




$lang_map = array(
    'English' => 'en'
    );

//want to output SAF https://wiki.duraspace.org/display/DSDOC5x/Importing+and+Exporting+Items+via+Simple+Archive+Format

function get_remote_contents($url) {
    $ch = curl_init( $url );
    $cookie = tempnam(sys_get_temp_dir(), 'Cookie');
    touch($cookie);
    $options = array(
	CURLOPT_CONNECTTIMEOUT => 10 ,
	CURLOPT_TIMEOUT => 30 , 
	CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.2 (KHTML, like Gecko) Chrome/22.0.1216.0 Safari/537.2" ,
	CURLOPT_AUTOREFERER => true,
	CURLOPT_FOLLOWLOCATION => true,
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_COOKIEFILE => $cookie,
	CURLOPT_COOKIEJAR => $cookie ,
	CURLOPT_SSL_VERIFYPEER => 0 ,
	CURLOPT_SSL_VERIFYHOST => 0
	);
    curl_setopt_array($ch, $options);
    $contents = curl_exec ($ch);
    curl_close($ch);
    unlink($cookie);
    return $contents;
}


$zip = new ZipArchive;
$zipfile = 'mhealthEvidence_dspace.zip';
//$zipfile = $collection . 'items6_dspace.zip';
if (file_exists($zipfile)) {unlink($zipfile);}
if (! $zip->open($zipfile, ZipArchive::CREATE))   { die("could not create zip file $zipfile\n");}
$got = array();
//$data = array_slice($data,0,5);
foreach ($data as $nid => $item) {
    $dir = 'item_' . $nid;
    echo "Adding $dir to zip file\n";
    $lang = 'en';
    $year = false;
    $titles = array();
    $mimes = array();
    $desc = false;
    $url =false;
    $main_title =  $item['title'][0];
    $aux_files = array();
    $sources =array();    
   
    $main_title = str_replace("&","And", $main_title);
    $main_title = str_replace("<","Under", $main_title);
    if (!$main_title && count($titles) == 0) {	echo "\tWARNING: no titles found for $nid <$main_title>\n";  continue; }
    //if (count($mimes) == 0) {	echo "\tWARNING: no mime types found for $nid\n"; continue; }

    $zip->addFromString($dir . '/collection', $collection . "\n");
    if ((count($item['lang']) > 0) && ($t_lang = $item['lang'][0]) && (array_key_exists($t_lang,$lang_map))){
	$lang =$lang_map[$t_lang];
    }
    /*if (count($item['publication_year']) > 0) {
	$year = $item['publication_year'][0];
    }*/
    if (count($item['publication_date']) > 0) {
	$year = $item['publication_date'][0];
    }
    if (count($item['body_value']) > 0) {
	$desc = trim($item['body_value'][0]);
	if (strlen($desc) > 0) {
	    $desc = str_replace("<p", "\n\n<p", $desc);
	    $desc = str_replace("&","And", $desc);
	    $desc = str_replace("<", "Under", $desc);
	    //$desc = strip_tags(html_entity_decode(htmlspecialchars_decode($desc)));
	    $desc = trim(preg_replace('/ +/', ' ', preg_replace('/[^A-Za-z0-9 ]/', ' ', urldecode(html_entity_decode(htmlspecialchars_decode(strip_tags($desc)))))));

	}
    }
    if (count($item['doi']) > 0) {
	$doi = $item['doi'][0];
	$prefix = 'http://dx.doi.org/';
    }
    if (count($item['journal_title']) > 0) {
	$journal = $item['journal_title'][0];
	$journal = str_replace("&","And", $journal);
	$journal = str_replace("<","Under", $journal);
    }
    //see https://wiki.duraspace.org/display/DSDOC5x/Metadata+and+Bitstream+Format+Registries for dublin core fields
    //example $item['resource_type'] = array('Tools & Guides')  dc.subject.type
    $dcfields = '  <dcvalue element="title" qualifier="none" language="' . $lang . '">' .$main_title  ."</dcvalue>\n";
    $dcterms = '  <dcvalue element="title"  language="' . $lang . '">' . $main_title ."</dcvalue>\n";
    $count =0;
    foreach ($titles as $filename => $title) {
	$count++;
	if ($count == 1) {
	    continue;
	}
	if (!is_string($title)) { print_r($item); print_r($titles); die("BADNESS = $nid\n");}
    $title = str_replace("&","And", $title);
    $title = str_replace("<","Under", $title);
	$dcfields .= '  <dcvalue element="title" qualifier="alternative" >' . $title ."</dcvalue>\n";
	$dcterms .= '  <dcvalue element="alternative" >' . $title ."</dcvalue>\n";
    }
    /*foreach ($item['resource_type'] as $type) {
    //$type = preg_replace('/ +/', ' ', preg_replace('/[^A-Za-z0-9 ]/', ' ', urldecode(html_entity_decode(htmlspecialchars_decode($type)))));
	$dcfields .= '  <dcvalue element="subject">' . $type . "</dcvalue>\n";
	$dcterms .= '  <dcvalue element="subject">' . $type . "</dcvalue>\n";
    }*/
    foreach (array_unique($item['mterg_terms']) as $term) {
    //$term = preg_replace('/ +/', ' ', preg_replace('/[^A-Za-z0-9 ]/', ' ', urldecode(html_entity_decode(htmlspecialchars_decode($term)))));
    	$term = str_replace("&","And", $term);
    	$term = str_replace("<","Under", $term);
	$dcfields .= '  <dcvalue element="subject">' . $term . "</dcvalue>\n";
	$dcterms .= '  <dcvalue element="subject">' . $term . "</dcvalue>\n";
    }

    /*foreach (array_unique($item['mesh_terms']) as $term) {
    //$term = preg_replace('/ +/', ' ', preg_replace('/[^A-Za-z0-9 ]/', ' ', urldecode(html_entity_decode(htmlspecialchars_decode($term)))));
    	$term = str_replace("&","And", $term);
    	$term = str_replace("<","Under", $term);
	$dcfields .= '  <dcvalue element="subject" qualifier="mesh">' . $term . "</dcvalue>\n";
	$dcterms .= '  <dcvalue element="subject">' . $term . "</dcvalue>\n";
    }*/

    foreach (array_unique($item['author']) as $i=>$ln) {
	if (!$ln) {continue;}
	/*if (array_key_exists($i,$item['author']) && strlen($fn = trim($item['author'][$i])) > 0) {
	    //$ln  .= ", " . $fn;
	}*/
	$dcfields .=  '  <dcvalue element="contributor" qualifier="author">' . $ln . "</dcvalue>\n";
	$dcterms .=  '  <dcvalue element="contributor">' . $ln . "</dcvalue>\n";
    }
    if ($year) {
	$dcfields .= '  <dcvalue element="date" qualifier="issued">' . $year . "</dcvalue>\n" ;
    }
    if ($desc) {
	$dcfields .=  '  <dcvalue element="description" qualifier="abstract">' . $desc . "</dcvalue>\n";
	$dcterms .=  '  <dcvalue element="abstract">' . $desc . "</dcvalue>\n";	
    }
    if ($doi) {
    	$dcfields .=  '  <dcvalue element="identifier" qualifier="uri">' . $prefix . $doi . "</dcvalue>\n";
		$dcterms .=  '  <dcvalue element="identifier">' . $prefix . $doi . "</dcvalue>\n";	
    }
    if ($journal) {
    	$dcfields .=  '  <dcvalue element="relation" qualifier="uri">' . $journal . "</dcvalue>\n";
		$dcterms .=  '  <dcvalue element="relation">' . $journal . "</dcvalue>\n";	
    }
    /*foreach ($aux_files as $filename => $mime) {
	$dcterms .=  '  <dcvalue element="relation">' . $filename . "</dcvalue>\n";	
    }
    foreach ($sources as $source) {
	$dcterms .=  '  <dcvalue element="source">' . $source . "</dcvalue>\n";	
    }*/

    
    $dcfields = "<dublin_core>\n" . $dcfields . "</dublin_core>\n";
    $dcterms = "<dublin_core schema='dcterms'>\n" . $dcterms ."</dublin_core>\n";
    $zip->addFromString($dir . '/dublin_core.xml', $dcfields);
    $zip->addFromString($dir . '/metadata_dcterms.xml', $dcterms);
    
      $zip->addFromString($dir . '/contents', "\n");


    /*$file_list = array_unique(array_merge(array_keys($titles),array_keys($aux_files)));
    $zip->addFromString($dir . '/contents',implode($file_list,"\n") . "\n");
    foreach ($file_list as $f_name ) {
	echo "\tAdding $f_name\n";
	if (!file_exists("files/" . $f_name)) { echo "WARNING: $f_name disappeared\n";}
	if (! $zip->addFile("files/" . $f_name, $dir . '/' . $f_name )) {
	    echo "\tWARNING: could not add $f_name\n";
	}
    }*/

}
$zip->close();


if (count($warnings) > 0) { echo "Warnings:\n" ; print_r($warnings);}

