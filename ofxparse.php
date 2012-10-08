<?php

require 'class.OFXFile.php';

if($argc == 3) {
  if(file_exists($argv[1])) {
    //$outfile = fopen($argv[2], 'w');
    //$fh = fopen($argv[1],'r');
  	$ofx = new OFXFile($argv[1], OFXFILEREPORTLEVEL_DEBUG);
    $ofx->parse();
    $csv = $ofx->getTransactionsCSV();
    
    if(!file_put_contents($argv[2], $csv)) {
	    print "Error writing csv to ". $argv[2];
    }
  } else {
    printUsage("Infile doesn't exist");
  }
} else {
  printUsage();
}

function printUsage($error = '') {
  if(!empty($error)) {
    print $error . "\n";
  }
  print "Usage: php ofx2csv.php <infile> <outfile>\n";
}

?>