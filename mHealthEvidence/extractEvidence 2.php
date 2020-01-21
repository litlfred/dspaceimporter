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

$fields = '	nid, title, 
 		   	body_value, 
		  	mterg.name as mterg_terms, 
			mesh.name as mesh_terms,
    		journal.name as journal_title,
    		author.name as author,
		    pubmed_doi_value,
		    pubmed_publication_date_value as publication_date';

$qry = 'select ' . $fields . '

FROM node
LEFT JOIN field_data_body ON field_data_body.entity_id = node.nid
	LEFT  JOIN field_data_field_mterg_terms ON field_data_field_mterg_terms.entity_id = node.nid
	LEFT  JOIN field_data_pubmed_mesh ON field_data_pubmed_mesh.entity_id = node.nid
	LEFT  JOIN field_data_pubmed_journal_title ON field_data_pubmed_journal_title.entity_id = node.nid
	LEFT  JOIN field_data_pubmed_author ON field_data_pubmed_author.entity_id = node.nid
	LEFT JOIN taxonomy_term_data  as mterg ON field_mterg_terms_tid =  mterg.tid
	LEFT JOIN taxonomy_term_data  as mesh ON pubmed_mesh_tid =  mesh.tid
    LEFT JOIN field_data_pubmed_doi ON field_data_pubmed_doi.entity_id = node.nid
    LEFT JOIN field_data_pubmed_publication_date ON field_data_pubmed_publication_date.entity_id = node.nid
	LEFT JOIN taxonomy_term_data as journal ON pubmed_journal_title_tid = journal.tid
	LEFT JOIN taxonomy_term_data as author ON pubmed_author_tid = author.tid
where node.type = \'pubmed\'
order by nid

';


//$mysqli = new mysqli($dbhost, $dbuser, $dbpass, $collection);

$pdo = new PDO("mysql:host=$dbhost;dbname=$collection", $dbuser, $dbpass, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8") );
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

//if ($mysqli->connect_errno){  die("Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);}
//$mysqli->set_charset('utf8');


$filenames = array(
    'resource_file'=>'file',
    'field_link' => 'link',
    'field_outside_link'=>'link',
    'field_link_website'=>'link',
    'image_field'=>'file',
    'image_file'=>'file',
    'cover_image'=>'auxfile'
     
    );
    
$furl = 'https://www.' . $collection . '.org/sites/default/files/';
$furl = 'https://www.mhealthevidence.org/sites/default/files/';



$headers = array();
foreach (explode(",",$fields) as $f) {
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
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
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
$zipfile = $collection . '_dspace.zip';
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
    foreach ($filenames as $prefix=>$filetype) {
	//get files that are referenced locally on the website but not saved in the db so need to retrieve
	if ($filetype == 'file') {
	    foreach ($item[$prefix . '_filename'] as $i=>$filename) {
		if (!$filename) {continue; }//just in case empty values we put int
		$rurl =$furl . $filename;
		if (array_key_exists($rurl,$got) && $got[$rurl]) { continue;}
		echo "\tRetrieving for $prefix: $rurl\n";
		$rfile = get_remote_contents($rurl);
		if (!$rfile) {echo "\tWARNING: Could not retrieve $rurl\n";	continue;}
		$got[$rurl] = true;
		file_put_contents('files/' . $filename, $rfile);
		$mime = false;
		if (array_key_existS($i,$item[$prefix . '_mime'])) {   $mime = $item[$prefix . '_mime'][$i]; }
		if (!$mime) { $mime = mime_content_type('files/' .  $filename);}
		if ($item['title']) {
		    $tmp_title = $item['title'];
		} else {
		    $tmp_title  = $filename;
		}
		$titles[$filename] = $tmp_title ;
		$mimes[$filename] = $mime;
	    }
	} else if ($filetype == 'auxfile') {
	    foreach ($item[$prefix . '_filename'] as $i=>$filename) {
		if (!$filename) {continue; }//just in case empty values we put int
		$rurl =$furl . $filename;
		if (array_key_exists($rurl,$got) && $got[$rurl]) { continue;}
		echo "\tRetrieving for $prefix: $rurl\n";
		$rfile = get_remote_contents($rurl);
		if (!$rfile) {echo "\tWARNING: Could not retrieve $rurl\n";	continue;}
		$got[$rurl] = true;
		file_put_contents('files/' . $filename, $rfile);
		$mime = false;
		if (array_key_existS($i,$item[$prefix . '_mime'])) {   $mime = $item[$prefix . '_mime'][$i]; }
		if (!$mime) { $mime = mime_content_type('files/' .  $filename);}
		$aux_files[$filename] = $mime;
	    }
	} else if ($filetype == 'link') {
	    foreach ($item[$prefix . '_url'] as $i => $rurl) {
		if (!$rurl) {continue; }//just in case empty values we put int
		if (array_key_exists($rurl,$got) && $got[$rurl]) { continue;}
		//strip almost everything from url so we can use as filename
		$rfilename = rtrim(ltrim( preg_replace('/[^\da-z\\.]+/i', '-', explode('#',$rurl,2)[0] ), "-"),"-"); 
		$tmp_title = $item[$prefix . '_title'][$i];
		if (!$tmp_title   ) {
		    $tmp_title = $main_title;
		    if (!$tmp_title) {
			$tmp_title = $rfilename;
		    }
		}
		echo "\tRetrieving for $prefix: $rurl\n";
		$rfile = get_remote_contents($rurl);
		if (!$rfile) {echo "\tWARNING: Could not retrieve $rurl\n"; continue;}
		if (!$tmp_title || !$rurl) {   echo "\tWARNING: bad title or URL ($tmp_title/$rurl)\n";continue;}

		$got[$rurl] = true;
		file_put_contents('files/' .  $rfilename,$rfile);
		$mime = mime_content_type('files/' .  $rfilename);
		echo "\tRetrieved $mime from $rurl\n";
		if ($mime == 'text/html') {
		    echo "\tConverting to PDF: $rurl \n";
		    //let's also try and get the PDF version
		    $out = array();
		    $ret = false;
		    $pdffilename = $rfilename . ".pdf";
		    $pdfout = "files/$pdffilename";
		    exec("wkhtmltopdf " . escapeshellarg($rurl) . " " . escapeshellarg($pdfout) . " 2> /dev/null");
		    if (is_file($pdfout) && filesize($pdfout) >0) { //success
			$mimes[$pdffilename] = 'application/pdf';
			$titles[$pdffilename] = $tmp_title;
		    } else {
			echo "\tWARNING: Could not convert to PDF ($ret): $rurl \n"; print_r($out);
		    }
		}
		$sources[] = $rurl;
		$mimes[$rfilename] = $mime;
		$titles[$rfilename] = $tmp_title;
	    }
	}
    }
    if (!$main_title && count($titles) == 0) {	echo "\tWARNING: no titles found for $nid <$main_title>\n";  continue; }
    if (count($mimes) == 0) {	echo "\tWARNING: no mime types found for $nid\n"; continue; }

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
	    //$desc = strip_tags(html_entity_decode(htmlspecialchars_decode($desc)));
	    $desc = trim(preg_replace('/ +/', ' ', preg_replace('/[^A-Za-z0-9 ]/', ' ', urldecode(html_entity_decode(htmlspecialchars_decode(strip_tags($desc)))))));

	}
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
	$dcfields .= '  <dcvalue element="title" qualifier="alternative" >' . $title ."</dcvalue>\n";
	$dcterms .= '  <dcvalue element="alternative" >' . $title ."</dcvalue>\n";
    }
    foreach ($item['resource_type'] as $type) {
    //$type = preg_replace('/ +/', ' ', preg_replace('/[^A-Za-z0-9 ]/', ' ', urldecode(html_entity_decode(htmlspecialchars_decode($type)))));
	$dcfields .= '  <dcvalue element="subject">' . $type . "</dcvalue>\n";
	$dcterms .= '  <dcvalue element="subject">' . $type . "</dcvalue>\n";
    }
    foreach ($item['mterg_terms'] as $term) {
    //$term = preg_replace('/ +/', ' ', preg_replace('/[^A-Za-z0-9 ]/', ' ', urldecode(html_entity_decode(htmlspecialchars_decode($term)))));
	$dcfields .= '  <dcvalue element="subject">' . $term . "</dcvalue>\n";
	$dcterms .= '  <dcvalue element="subject">' . $term . "</dcvalue>\n";
    }

    foreach ($item['mesh_terms'] as $term) {
    //$term = preg_replace('/ +/', ' ', preg_replace('/[^A-Za-z0-9 ]/', ' ', urldecode(html_entity_decode(htmlspecialchars_decode($term)))));
	$dcfields .= '  <dcvalue element="subject">' . $term . "</dcvalue>\n";
	$dcterms .= '  <dcvalue element="subject">' . $term . "</dcvalue>\n";
    }

    foreach ($item['author'] as $i=>$ln) {
	if (!$ln) {continue;}
	if (array_key_exists($i,$item['author']) && strlen($fn = trim($item['author'][$i])) > 0) {
	    $ln  .= ", " . $fn;
	}
	$dcfields .=  '  <dcvalue element="contributor" qualifier="author">' . $ln . "</dcvalue>\n";
	$dcterms .=  '  <dcvalue element="contributor">' . $ln . "</dcvalue>\n";
    }
    if ($desc) {
	$dcfields .=  '  <dcvalue element="description" qualifier="abstract">' . $desc . "</dcvalue>\n";
	$dcterms .=  '  <dcvalue element="abstract">' . $desc . "</dcvalue>\n";	
    }
    if ($year) {
	$dcfields .= '  <dcvalue element="date" qualifier="issued">' . $year . "</dcvalue>\n" ;
    }
    foreach ($aux_files as $filename => $mime) {
	$dcterms .=  '  <dcvalue element="relation">' . $filename . "</dcvalue>\n";	
    }
    foreach ($sources as $source) {
	$dcterms .=  '  <dcvalue element="source">' . $source . "</dcvalue>\n";	
    }

    
    $dcfields = "<dublin_core>\n" . $dcfields . "</dublin_core>\n";
    $dcterms = "<dublin_core schema='dcterms'>\n" . $dcterms ."</dublin_core>\n";
    $zip->addFromString($dir . '/dublin_core.xml', $dcfields);
    $zip->addFromString($dir . '/metadata_dcterms.xml', $dcterms);
    

    $file_list = array_unique(array_merge(array_keys($titles),array_keys($aux_files)));
    $zip->addFromString($dir . '/contents',implode($file_list,"\n") . "\n");
    foreach ($file_list as $f_name ) {
	echo "\tAdding $f_name\n";
	if (!file_exists("files/" . $f_name)) { echo "WARNING: $f_name disappeared\n";}
	if (! $zip->addFile("files/" . $f_name, $dir . '/' . $f_name )) {
	    echo "\tWARNING: could not add $f_name\n";
	}
    }

}
$zip->close();


if (count($warnings) > 0) { echo "Warnings:\n" ; print_r($warnings);}

