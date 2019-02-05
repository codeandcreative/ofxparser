<?php
/**
 * @file
 * Captures the contents of an OFX file
 * 
 * Oct 8 2012			Giving up on a more efficient parse algorithm for now. Implementing more functionality around getting the data
 * Sept 26 2012:  This code is still in flux. I'm currently concentrating on a nifty pair of RegExp's to parse the thing along with recursion.
 *                The problem is currently in finding single line parameters between two aggregates
 */

define('OFXFILEREPORTLEVEL_ERROR', '1');
define('OFXFILEREPORTLEVEL_INFO', '3');
define('OFXFILEREPORTLEVEL_VERBOSE', '5');
define('OFXFILEREPORTLEVEL_DEBUG', '9');
define('OFXFILEREPORTLEVEL_DEV', '10');

/**
 * OFXFile
 * Parses and represents a standard OFX file
 *
 * TODO add DTD validation
 * TODO move reporting out of this class
 */
class OFXFile {
	protected $_data;
  
	/**
	 * Construct
	 */
	public function __construct($filepath, $reportlevel) {
		$this->_data = array();
		$this->_data['infile'] = $filepath;              	 // file path to the ofx file
		$this->_data['rawheader'] = false;                 // contents of the file's raw header (ofx 1.0)
		$this->_data['reportlevel'] = $reportlevel;        // sets verbosity of reporting
		$this->_data['isDescribed'] = false;               // used to flag discovery of ofx file description (header type)
		$this->_data['doc'] = array();                     // the parsed ofx file DOM

		// preg_match can be limited on the number of backtracks it can perform. For smaller source text,
		// this rarely becomes a problem. But when applying a regexp to an entire document, you can run into
		// this limit and cause unpredictable results.
		if(!ini_set('pcre.backtrack_limit', 1000000)) {
			$this->report("Couldn't change pcre backtrack limit. If file is large, this may not work...", OFXFILEREPORTLEVEL_ERROR);
			throw new Exception("Couldn't change pcre backtrack limit.");
		}
	}
  
	/**
	 * Magic method - get
	 */
	public function __get($property) {
		if(isset($this->_data[$property])) {
			return $this->_data[$property];
		}
	}
  
	/**
	 * Magic method - set
	 */
	public function __set($property, $value) {
		$this->_data[$property] = $value;
	}
  
	/**
	 * parse the ofx file
	 *
	 * If all goes right, the OFX file will be parsed and sitting in the doc variable
	 * @return void
	 */
	public function parse() {
		$linecount = 0;

		$file_as_string = file_get_contents($this->_data['infile']);

		if($file_as_string) {
			$file_as_string = trim($file_as_string);

			$matches = array();
			if(preg_match('/(?s)((?:[\w\d\:\s]*){9,9})?(<OFX>.*<\/OFX>)/',$file_as_string,$matches)) {
				if(count($matches > 2)) {
					// must be version < 200
					$this->rawheader = $matches[1];

					if(preg_match_all('/(.*):(.*)/',$this->rawheader,$linematches)) {
						for($i = 0; $i < count($linematches[1]); $i++) {
							$this->_data[$linematches[1][$i]] = $linematches[2][$i];
						}
					}
					$this->isDescribed = true;
				}

				if(!$this->isDescribed) {
					// must be version > 200

					// TODO get the version info out of the attributes
				}

				$this->_data['aggregateList'] = array();
				$this->getNode($matches[2], $this->_data['doc'], $this->_data['aggregateList'], 0);

				// TODO this works for MY bank for a Checking account. What happens if this is a Credit Card acct? Or some other type of acct?
				// Looking at the OFX DTD 1.03, I'm not so sure. I think it depends on the bank.
				$this->transactions = $this->doc['OFX'][0]['BANKMSGSRSV1'][0]['STMTTRNRS'][0]['STMTRS'][0]['BANKTRANLIST'][0]['STMTTRN'];


				$this->report("Here's the document:", OFXFILEREPORTLEVEL_DEV);
				$this->report(print_r($doc,1), OFXFILEREPORTLEVEL_DEV);
				$this->report("Here's the transactions:", OFXFILEREPORTLEVEL_DEBUG);
				$this->report(print_r($this->getTransactions(),1), OFXFILEREPORTLEVEL_DEBUG);

			} else {
				$this->report("It's not an OFX file...?", OFXFILEREPORTLEVEL_INFO);
			}
		} else {
			$this->report("Couldn't open file ". $this->infile, OFXFILEREPORTLEVEL_ERROR);
		}
	}
  
	/**
	 * query transactions list
	 *
	 * TODO implement date filter
	 */
	public function getTransactions($startDate = false, $endDate = false) {
		return $this->transactions;
	}
  
	/**
	 * get transactions as a csv string
	 *
	 * NOTE: according to the DTD the field list in a transaction is variable, i.e., each bank may do it differently
	 */
	public function getTransactionsCSV($startDate = false, $endDate = false, $inclHeader = true) {
		if(empty($this->csv)) {
			$this->csv = "";
			$txns = $this->getTransactions($startDate,$endDate);

	  		if(!empty($txns)) {
				if($inclHeader) {
					$fieldnames = array_keys($txns[0]);
		  			$this->csv .= implode(',', $fieldnames) ."\n";
				}

				foreach($txns as $t) {
					$this->csv .= implode(',', $t) . "\n";
			  	}
		  	}
	  	}

	  	return $this->csv;
	}
  
	/**
	 * write to stdout
	 */
	protected function report($msg, $level) {
		if($level <= $this->reportlevel) {
			print $msg ."\n";
		}
	}
  
	/**
	 * recurse into aggregates to discover nested aggregates and elements
	 *
	 * This function doesn't use the dtd. It instead traverses the DOM tree discovering aggregates (i.e., tags
	 * that are also containers) and elements (i.e., tags that have a single value).
	 *
	 * TODO - trying to surf through an OFX SGML file without having to open and parse the OFX DTD
	 *      - also trying to use regexp over parsing line by line
	 *      - I've run into some problems when trying to discover single line elements.
	 *        When getNode receives some sgml, all of the tags are siblings, both aggregates and elements. I only want single line elements (i.e.,
	 *        that don't have a closing tag) and I only want single line elements that are at this tree level (i.e., not nested in an aggregate).
	 *        I think I have the winning rexexp pattern but php does not grok variable width look behinds, which is what I'm using to discover
	 *        opening tags for aggregates (line 171).
	 * @param string $sgml          a section of raw OFX SGML
	 * @param array $domNode       the current DOM node being parsed
	 * @param array $aggregateList  a list of all the aggregate tags (used for discovery)
	 * @param int $treeLevel        a counter used to track the current depth of traversal
	 * @param string $nodename      tag name of current DOM Node
	 * @return void
	 */
	protected function getNode($sgml, &$domNode, &$aggregateList = array(), $treelevel = 0, $nodename = '') {
		$this->report("Entering treelevel ".$treelevel .': '. $nodename, OFXFILEREPORTLEVEL_DEBUG);

		$pattern = ''; // pattern to find aggregates
		$elpattern = ''; // pattern to find elements (single line)

		switch($treelevel) {
			case 0:
				$pattern = '/^(?s)(?:\s)*?<([^>]+)>(?>(.*)<\/\1>)(?:\s)*?$/';
				break;
			default:
				$pattern = '/(?s)(?:\s)*?(?><([^>]+)>)(.*?)<\/\1>/';
				/* $elpattern = '/<([^>\/]+)>(.*?)(?:\s)(?>.*(?!(?:<\/[^>]+>)))/'; */
				/* $elpattern = '/(?s)(?<!<[^>]+>\n)<([^>\/]+)>(?!\s)(.*?)(?>(?:\n)(?!.*?<\/[^>]+>))/'; // The first look behind is not fixed-width and php doesn't support variable width look behinds*/
				/* $elpattern = '/(?s)<([^>]+)>\n<([^>\/]+)>(?!\s)(?>(.*?)(?:\n).*?)(?!<\/[^>]+>)/'; // */
				$elpattern = '/(?s)(?<!<STMTTRN>)<([^>]+)>(?!\s)(.*?)(?:\n)(?>.*?(?!<\/STMTTRN>))/'; // */
				break;
		}

		$this->report("Preg matching...", OFXFILEREPORTLEVEL_DEV);

		/**
		 * Discovering Aggregates
		 *
		 * Aggregates can either be singular or multiple. For example, there is only on <OFX> tag, but there are many <STMTTRN> (transaction) tags
		 * Up front (without the dtd), we can't distinguish which aggregate tags are multiples. So, as we discover a new tag name for a node, we give it
		 * an array to hold all of the instances of that aggregate tag in that node. Most tags only appear once. The result is a multi-dimensional array
		 * wherein most arrays only hold one item.  This seems awkward to me, but necessary.
		 *
		 */
		if(preg_match_all($pattern,$sgml,$matches)) {

			$this->checkPregErrors(); // in doing this, I discovered that php preg has limits on recursion and backtracking; without first setting the proper limits, results are unpredictable

			$currentDomNode = null;
			for($i = 0; $i < count($matches[1]); $i++) {
				$aggregateList[$matches[1][$i]] = true;

				if(!isset($domNode[$matches[1][$i]])) {
					$domNode[$matches[1][$i]] = array();
				}
				$currentDomNode = &$domNode[$matches[1][$i]];
				$index = count($currentDomNode);
				$currentDomNode[] = array();
				$this->report("Recursing into ". $matches[1][$i], OFXFILEREPORTLEVEL_DEBUG);
				$this->getNode($matches[2][$i],$currentDomNode[$index],$aggregateList,$treelevel + 1,$matches[1][$i]);
			}
		} else {
			$this->report("No aggregates found", OFXFILEREPORTLEVEL_DEBUG);
		}


		// Discovering Elements...
		/**
		 * This way seems so inefficient. It will work, but I'd like to apply one rexexp to the whole sgml
		 * that will find all the parts (see code commented out below)
		 *
		 * 20121002 - Checks against a list of all known aggregates. Inefficient, but effective.
		 */
		$sgml_lines = preg_split("/((\r?\n)|(\r\n?))/", $sgml);
		$this->report("Looking for elements...",OFXFILEREPORTLEVEL_DEBUG);

		$in_aggregate = false;
		foreach($sgml_lines as $line) {
	 		$line = trim($line);
			if(!empty($line)) {
		 		if(preg_match('/[\s]*<([^\/>]+)>(.*)/',$line,$elmatches)) {

					if(array_key_exists($elmatches[1], $aggregateList)) {
						$this->report('in aggregate: '.$elmatches[1],OFXFILEREPORTLEVEL_DEV);
						$in_aggregate = true;
					} elseif($in_aggregate == false) {
						$this->report("Got an element: ". $elmatches[1] .' = '. $elmatches[2], OFXFILEREPORTLEVEL_DEBUG);
						$domNode[$elmatches[1]] = $elmatches[2];
					}
				} elseif(preg_match('/<\/[^>]+>/',$line)) {
		 			$in_aggregate = false;
		 			$this->report('leaving aggregate',OFXFILEREPORTLEVEL_DEV);
		 		} else {
		   			$this->report("Unexpected pattern: ".$line, OFXFILEREPORTLEVEL_DEBUG);

				}
			}
		}


		/********************************************************************
		 * This method for discovering elements uses regexp on the whole
		 * sgml string. This is a puzzle I don't think I can solve with
		 * php regexp

		if($treelevel > 0 && preg_match_all($elpattern,$sgml,$elmatches)) {
			$this->checkPregErrors();
			$this->report('Got some elements', OFXFILEREPORTLEVEL_DEBUG);
			if($treelevel > 2) {
				$this->report(print_r($elmatches,1),OFXFILEREPORTLEVEL_DEBUG);
			}
		} else {
			$this->report("No elements found", OFXFILEREPORTLEVEL_INFO);
		}
		// *****************************************************************/

		$this->report("Exiting treelevel ".$treelevel .': '. $nodename, OFXFILEREPORTLEVEL_DEBUG);
	}
  
	/**
	* use php's preg error handling to determine success or failure of a preg_match.
	* Reports success and failure back to the user.
	*
	* @return bool
	*/
	public function checkPregErrors() {
		$result = true;
		switch(preg_last_error()) {
			case PREG_INTERNAL_ERROR:
				$this->report("\tPreg internal error", OFXFILEREPORTLEVEL_ERROR);
				$result = false;
				break;
			case PREG_BACKTRACK_LIMIT_ERROR:
				$this->report("\tPreg backtrack limit error", OFXFILEREPORTLEVEL_ERROR);
				$result = false;
				break;
			case PREG_RECURSION_LIMIT_ERROR:
				$this->report("\tPreg recursion limit error", OFXFILEREPORTLEVEL_ERROR);
				$result = false;
				break;
			case PREG_BAD_UTF8_ERROR:
				$this->report("\tPreg bad utf8 error", OFXFILEREPORTLEVEL_ERROR);
				$result = false;
				break;
			case PREG_BAD_UTF8_OFFSET_ERROR:
				$this->report("\tPreg bad utf8 offset error", OFXFILEREPORTLEVEL_ERROR);
				$result = false;
				break;
			default:
				$this->report("\tNo preg errors", OFXFILEREPORTLEVEL_DEV);
		}

		return $result;
	}
}
?>
