<?php 
// Hacked together from all over.  
// rlb, 2020-05-12


// JUST SHUT UP, ALREADY
error_reporting (0);

$search_string = $_GET['s'];
    
function outputJson($hosts, $services, $program)
{
    // begin outputting XML
//    header("Content-type: application/json");
    $STATUS .=  "{" . "\n";

    // program status
    if ($program != "") {
        $STATUS .=  '  "programStatus": {' . "\n";
        foreach ($program as $key => $val) {
            $STATUS .=  '    "' . jsonString($key) . '": "' . jsonString($val) . '"' . (isLast($program, $key) ? '' : ',') . "\n";
        }
        unset($key, $val);
        $STATUS .=  '  },' . "\n";
    }

    // hosts
    $STATUS .=  '  "hosts": {' . "\n";
    foreach ($hosts as $hostName => $hostArray) {
        $STATUS .=  '   "' . jsonString($hostName) . '": {' . "\n";
        foreach ($hostArray as $key => $val) {
            $STATUS .=  '      "' . jsonString($key) . '": "' . jsonString($val) . '"' . (isLast($hostArray, $key) ? '' : ',') . "\n";
        }
        unset($key, $val);
        $STATUS .=  '   }' . (isLast($hosts, $hostName) ? '' : ',') . "\n";
    }
    unset($hostName, $hostArray);
    $STATUS .=  '  },' . "\n";

    // loop through the services
    $STATUS .=  '  "services": {' . "\n";
    foreach ($services as $hostName => $service) {
        $STATUS .=  '   "' . jsonString($hostName) . '": {' . "\n";
        foreach ($service as $serviceDesc => $serviceArray) {
            $STATUS .=  '   "' . jsonString($serviceDesc) . '": {' . "\n";
            foreach ($serviceArray as $key => $val) {
                $STATUS .=  '      "' . jsonString($key) . '": "' . jsonString($val) . '"' . (isLast($serviceArray, $key) ? '' : ',') . "\n";
            }
            unset($key, $val);
            $STATUS .=  '   }' . (isLast($service, $serviceDesc) ? '' : ',') . "\n";
        }
        $STATUS .=  '   }' . (isLast($services, $hostName) ? '' : ',') . "\n";
        unset($serviceDesc, $serviceArray);
    }
    unset($hostName, $service);
    $STATUS .=  '  }' . "\n";
    $STATUS .=  "}";

return "$STATUS";


}


// Determines if the given key is last in the given array
function isLast($array, $key)
{
    end($array);
    return ($key === key($array));
}


// replace reserved characters in json
function jsonString($s)
{
    $s = str_replace('\\', '\\\\', $s);
    $s = str_replace('"', '\"', $s);
    $s = str_replace("\t", '\t', $s);
    $s = str_replace("\n", " ", $s);
    $s = str_replace("\r", " ", $s);
    return $s;
}


// figure out what version the file is
function getFileVersion($statusFile)
{
    global $created_ts;
    $version = 2;

    $fh = fopen($statusFile, 'r');
    $inInfo = false;
    while ($line = fgets($fh)) {
        if (trim($line) == "info {") {
            $inInfo = true;
        } elseif (trim($line) == "}") {
            $inInfo = false;
            break;
        } elseif ($inInfo) {
            $vals = explode("=", $line);
            if (trim($vals[0]) == "created") {
                $created = $vals[1];
            } elseif (trim($vals[0]) == "version") {
                $version = substr($vals[1], 0, 1);
            }
        }
    }
    return $version;
}


// parse nagios2 status.dat
function getData2($statusFile)
{
    // the keys to get from host status:
    $host_keys = array('host_name', 'has_been_checked', 'check_execution_time', 'check_latency', 'check_type', 'current_state', 'current_attempt', 'state_type', 'last_state_change', 'last_time_up', 'last_time_down', 'last_time_unreachable', 'last_notification', 'next_notification', 'no_more_notifications', 'current_notification_number', 'notifications_enabled', 'problem_has_been_acknowledged', 'acknowledgement_type', 'active_checks_enabled', 'passive_checks_enabled', 'last_update');

    // keys to get from service status:
    $service_keys = array('host_name', 'service_description', 'has_been_checked', 'check_execution_time', 'check_latency', 'current_state', 'state_type', 'last_state_change', 'last_time_ok', 'last_time_warning', 'last_time_unknown', 'last_time_critical', 'plugin_output', 'last_check', 'notifications_enabled', 'active_checks_enabled', 'passive_checks_enabled', 'problem_has_been_acknowledged', 'acknowledgement_type', 'last_update', 'is_flapping');

    # open the file
    $fh = fopen($statusFile, 'r');

    # variables to keep state
    $inSection = false;
    $sectionType = "";
    $lineNum = 0;
    $sectionData = array();

    $hostStatus = array();
    $serviceStatus = array();

    #variables for total hosts and services
    $typeTotals = array();

    # loop through the file
    while ($line = fgets($fh)) {
        $lineNum++; // increment counter of line number, mainly for debugging
        $line = trim($line); // strip whitespace
        if ($line == "") {
            continue;
        } // ignore blank line
        if (substr($line, 0, 1) == "#") {
            continue;
        } // ignore comment

        // ok, now we need to deal with the sections

        if (!$inSection) {
            // we're not currently in a section, but are looking to start one
            if (strstr($line, " ") && (substr($line, -1) == "{")) // space and ending with {, so it's a section header
            {
                $sectionType = substr($line, 0, strpos($line, " ")); // first word on line is type
                $inSection = true;
                // we're now in a section
                $sectionData = array();

                // increment the counter for this sectionType
                if (isset($typeTotals[$sectionType])) {
                    $typeTotals[$sectionType] = $typeTotals[$sectionType] + 1;
                } else {
                    $typeTotals[$sectionType] = 1;
                }

            }
        }

        if ($inSection && $line == "}") // closing a section
        {
            if ($sectionType == "service") {
                $serviceStatus[$sectionData['host_name']][$sectionData['service_description']] = $sectionData;
            }
            if ($sectionType == "host") {
                $hostStatus[$sectionData["host_name"]] = $sectionData;
            }
            $inSection = false;
            $sectionType = "";
            continue;
        } else {
            // we're currently in a section, and this line is part of it
            $lineKey = substr($line, 0, strpos($line, "="));
            $lineVal = substr($line, strpos($line, "=") + 1);

            // add to the array as appropriate
            if ($sectionType == "service") {
                if (in_array($lineKey, $service_keys)) {
                    $sectionData[$lineKey] = $lineVal;
                }
            } elseif ($sectionType == "host") {
                if (in_array($lineKey, $host_keys)) {
                    $sectionData[$lineKey] = $lineVal;
                }
            }
            // else continue on, ignore this section, don't save anything
        }

    }

    fclose($fh);

    $retArray = array("hosts" => $hostStatus, "services" => $serviceStatus);

    return $retArray;
}


// parse nagios3 status.dat
function getData3($statusFile)
{
    global $debug;

    # open the file
    $fh = fopen($statusFile, 'r');

    # variables to keep state
    $inSection = false;
    $sectionType = "";
    $lineNum = 0;
    $sectionData = array();

    $hostStatus = array();
    $serviceStatus = array();
    $programStatus = array();

    #variables for total hosts and services
    $typeTotals = array();

    # loop through the file
    while ($line = fgets($fh)) {
        $lineNum++; // increment counter of line number, mainly for debugging
        $line = trim($line); // strip whitespace
        if ($line == "") {
            continue;
        } // ignore blank line
        if (substr($line, 0, 1) == "#") {
            continue;
        } // ignore comment

        // ok, now we need to deal with the sections
        if (!$inSection) {
            // we're not currently in a section, but are looking to start one
            if (substr($line, strlen($line) - 1, 1) == "{") // space and ending with {, so it's a section header
            {
                $sectionType = substr($line, 0, strpos($line, " ")); // first word on line is type
                $inSection = true;
                // we're now in a section
                $sectionData = array();

                // increment the counter for this sectionType
                if (isset($typeTotals[$sectionType])) {
                    $typeTotals[$sectionType] = $typeTotals[$sectionType] + 1;
                } else {
                    $typeTotals[$sectionType] = 1;
                }

            }
        } elseif ($inSection && trim($line) == "}") // closing a section
        {
            if ($sectionType == "servicestatus") {
                $serviceStatus[$sectionData['host_name']][$sectionData['service_description']] = $sectionData;
            } elseif ($sectionType == "hoststatus") {
                $hostStatus[$sectionData["host_name"]] = $sectionData;
            } elseif ($sectionType == "programstatus") {
                $programStatus = $sectionData;
            }
            $inSection = false;
            $sectionType = "";
            continue;
        } else {
            // we're currently in a section, and this line is part of it
            $lineKey = substr($line, 0, strpos($line, "="));
            $lineVal = substr($line, strpos($line, "=") + 1);

            // add to the array as appropriate
            if ($sectionType == "servicestatus" || $sectionType == "hoststatus" || $sectionType == "programstatus") {
                if ($debug) {
                    $STATUS .=  "LINE " . $lineNum . ": lineKey=" . $lineKey . "= lineVal=" . $lineVal . "=\n";
                }
                $sectionData[$lineKey] = $lineVal;
            }
            // else continue on, ignore this section, don't save anything
        }

    }

    fclose($fh);

    $retArray = array("hosts" => $hostStatus, "services" => $serviceStatus, "program" => $programStatus);
    return $retArray;
}


// parse nagios4 status.dat
function getData4($statusFile)
{
    // For now just re-use the nagios3 parsing
    return getData3($statusFile);
}

// this formats the age of a check in seconds into a nice textual description
function ageString($seconds)
{
    $age = "";
    if ($seconds > 86400) {
        $days = (int)($seconds / 86400);
        $seconds = $seconds - ($days * 86400);
        $age .= $days . " days ";
    }
    if ($seconds > 3600) {
        $hours = (int)($seconds / 3600);
        $seconds = $seconds - ($hours * 3600);
        $age .= $hours . " hours ";
    }
    if ($seconds > 60) {
        $minutes = (int)($seconds / 60);
        $seconds = $seconds - ($minutes * 60);
        $age .= $minutes . " minutes ";
    }
    $age .= $seconds . " seconds ";
    return $age;
}


// PHP program to carry out multidimensional 
// array search by key=>value 
   
// Function to iteratively search for a 
// given key=>value   
function search($array, $key, $value) { 
   
    // RecursiveArrayIterator to traverse an 
    // unknown amount of sub arrays within 
    // the outer array. 
    $arrIt = new RecursiveArrayIterator($array); 
   
    // RecursiveIteratorIterator used to iterate 
    // through recursive iterators 
    $it = new RecursiveIteratorIterator($arrIt); 
   
    foreach ($it as $sub) { 
   
        // Current active sub iterator 
        $subArray = $it->getSubIterator(); 
   
        $pattern = '/'.$value.'/i';
//        $pattern= '/MySQL/';
        if (preg_match($pattern,$subArray[$key])) {
        // if ($subArray[$key] === $value) { 
            $result[] = iterator_to_array($subArray); 
         } 
    } 
    return $result; 
} 


function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}





// End Functions

// First, get JSON

// Change this accordingly
$statusFile = "/usr/local/nagios/var/status.dat";

$nag_version = getFileVersion($statusFile);
$created_ts = 0;

$debug = false;

if ($nag_version == 4) {
    $data = getData4($statusFile);
} else if ($nag_version == 3) {
    $data = getData3($statusFile);
} else {
    $data = getData2($statusFile);
}

$hosts = $data['hosts'];
$services = $data['services'];
$program = "";
if (array_key_exists("program", $data)) {
    $program = $data['program'];
}

$jsonStr=outputJson($hosts, $services, $program);







// $jsonStr = file_get_contents('jsonstatus.json');

$arr = json_decode($jsonStr,true);
$res = search($arr, 'service_description', $search_string); 

$sort_col = $_GET['srt'];
$sort_order = $_GET['srto'];

if ( "$sort_order" == "" ) {
    $sord='SORT_ASC';
} elseif ("$sort_order" == "a") {
    $sord='SORT_ASC';
} elseif ("$sort_order" == "d") {
    $sord='SORT_DESC';
} else {
    $sord='SORT_ASC';
}

if ( "$sort_col" == "" ) {
    array_multisort( array_column( $res, 'host_name' ), SORT_ASC,  $res );
} elseif ( "$sort_col" == "h" ) {
  if ( "$sort_order"=="d") {
    array_multisort( array_column( $res, 'host_name' ), SORT_DESC,  $res );
  } else {
    array_multisort( array_column( $res, 'host_name' ), SORT_ASC,  $res );
  }
} elseif ( "$sort_col" == "s" ) {
  if ( "$sort_order"=="d") {
    array_multisort( array_column( $res, 'service_description' ), SORT_DESC, $res );
  } else {
    array_multisort( array_column( $res, 'service_description' ), SORT_ASC, $res );
  }
} elseif ( "$sort_col" == "c" ) {
  if ( "$sort_order"=="a") {
    array_multisort( array_column( $res, 'current_state' ), SORT_ASC, SORT_NUMERIC, array_column( $res, 'host_name' ), SORT_ASC, $res );
  } else {
    array_multisort( array_column( $res, 'current_state' ), SORT_DESC, SORT_NUMERIC, array_column( $res, 'host_name' ), SORT_ASC,$res );
  }
}

// exit;

// array_multisort('current_state',SORT_DESC, 'host_name', SORT_ASC, $res);
// array_multisort( array_column( $res, 'current_state' ), SORT_DESC, SORT_NUMERIC, $res );

$rightnow=time();

echo "<html>\n";
echo "<head><title>Search Results</title>\n";
echo "<style type=\"text/css\">  /*this is what we want the div to look like    when it is not showing*/  div.loading-invisible{    /*make invisible*/    display:none;  }  /*this is what we want the div to look like    when it IS showing*/  div.loading-visible{    /*make visible*/    display:block;    /*position it 200px down the screen*/    position:absolute;    top:5px;    left:0;    width:100%;    text-align:center;    /*in supporting browsers, make it      a little transparent*/    background:#fff;    filter: alpha(opacity=75); /* internet explorer */    -khtml-opacity: 0.75;      /* khtml, old safari */    -moz-opacity: 0.75;       /* mozilla, netscape */    opacity: 0.75;           /* fx, safari, opera */    border-top:1px solid #fff;    border-bottom:1px solid #fff;  }</style>";



echo "<link rel='stylesheet' type='text/css' href='/nagios/stylesheets/common.css'>\n";
echo "<link rel='stylesheet' type='text/css' href='/nagios/stylesheets/status.css'>\n";
echo "<link rel='stylesheet' type='text/css' href='/nagios/stylesheets/local.css'>\n";
echo "<LINK REL='stylesheet' TYPE='text/css' HREF='/nagios/stylesheets/nag_funcs.css'>";
echo "<script type='text/javascript' src='/nagios/js/jquery-1.12.4.min.js'></script>";
echo "<script type='text/javascript' src='/nagios/js/nag_funcs.js'></script>";
echo "</head><body class='status'>\n";

echo "<div id=\"loading\" class=\"loading-invisible\">  <p><img src=loading_trans.gif><br></p></div>";
echo "<script type=\"text/javascript\">  document.getElementById(\"loading\").className = \"loading-visible\";  var hideDiv = function(){document.getElementById(\"loading\").className = \"loading-invisible\";};  var oldLoad = window.onload;  var newLoad = oldLoad ? function(){hideDiv.call(this);oldLoad.call(this);} : hideDiv;  window.onload = newLoad;</script>";


echo "<table class='linkBox'>\n";
echo "<tr><td class='linkBox'>\n";
echo "<a href='status.cgi?hostgroup=all&style=detail'>View Service Status Detail For All Host Groups</a><br>\n";
echo "<a href='status.cgi?hostgroup=all&style=overview'>View Status Overview For All Host Groups</a><br>\n";
echo "<a href='status.cgi?hostgroup=all&style=summary'>View Status Summary For All Host Groups</a><br>\n";
echo "<a href='status.cgi?hostgroup=all&style=grid'>View Status Grid For All Host Groups</a><br>\n";
echo "</td></tr></table>\n";
echo "<div align='center' class='statusTitle'>Nagios Search Results for [$search_string]:</b> $h</div>";
echo "<table cellpadding=2 border=0 width=100% class='status'>\n";


echo "<tr><th align=left class='status'>Host&nbsp; <a href='?s=$search_string&srt=h&srto=a'><IMG SRC='/nagios/images/up.gif' border=0 ALT='Sort by host name (ascending)' TITLE='Sort by host name (ascending)'></a>&nbsp;<a href='?s=$search_string&srt=h&srto=d'><IMG SRC='/nagios/images/down.gif' border=0 ALT='Sort by host name (descending)' TITLE='Sort by host name (descending)'></a></th>";
echo "<th align=left class='status'>Service&nbsp; <a href='?s=$search_string&srt=s&srto=a'><IMG SRC='/nagios/images/up.gif' border=0 ALT='Sort by service (ascending)' TITLE='Sort by service (ascending)'></a>&nbsp;<a href='?s=$search_string&srt=s&srto=d'><IMG SRC='/nagios/images/down.gif' border=0 ALT='Sort by service (descending)' TITLE='Sort by service (descending)'></th>";
echo "<th align=left class='status'>Status&nbsp; <a href='?s=$search_string&srt=c&srto=a'><IMG SRC='/nagios/images/up.gif' border=0 ALT='Sort by status (ascending)' TITLE='Sort by status (ascending)'></a>&nbsp;<a href='?s=$search_string&srt=c&srto=d'><IMG SRC='/nagios/images/down.gif' border=0 ALT='Sort by status (descending)' TITLE='Sort by status (descending)'></th>";
echo "<th align=left class='status' style='white-space:nowrap'>Last Check</th>";
echo "<th align=left class='status'>Duration</th>";
echo "<th align=left class='status'>Next Check</th>";
echo "<th align=left class='status'>Status Information</th></tr>\n";


   
foreach ($res as $var) { 

    if ($class_row == "statusEven") {
        $class_row = "statusOdd";
    } else {
        $class_row = "statusEven";
    }



    if ($var["current_state"] == "0") {
       	$class_status = "OK";
       	$status = "UP";
       	$statusBG="statusOK";
    } elseif ($var["current_state"] == "1") {
	$class_status = "WARNING";
	$status = "WARNING";
	$class_row="statusBGWARNING";
	$statusBG="statusWARNING";
    } elseif ($var["current_state"] == "2") {
	$class_status = "CRITICAL";
	$status = "CRITICAL";
	$class_row="statusBGCRITICAL";
	$statusBG="statusCRITICAL";
    } else {
	$class_status = "UNKNOWN";
	$status = "UNKNOWN";
	$class_row="statusBGUNKNOWN";
	$statusBG="statusUNKNOWN";
    }




    $lastcheck=date("D M j G:i:s T Y", $var['last_check']);
    $nextcheck=date("D M j G:i:s T Y", $var['next_check']);
    $last_hard_state_change=$var['last_hard_state_change'];
    $elapsed_time=$rightnow - $last_hard_state_change;
    
//    $duration=time_elapsed_string($last_hard_stage_change);
    $duration=ageString($elapsed_time);

    $sd=str_replace(" ","+",$var['service_description']);
    
    echo "<tr class=$class_row><td style='white-space:nowrap'><a href=/nagios/cgi-bin/status.cgi?host=" . $var["host_name"] . ">" . $var["host_name"]. "</a></td>";
    echo "<td style='white-space:nowrap'><a href=/nagios/cgi-bin/extinfo.cgi?type=2&host=" . $var["host_name"] . "&service=" . $sd .">" . $var['service_description'] . "</a></td><td style='white-space:nowrap' class=$statusBG>" . $class_status . "</td><td style='white-space:nowrap'>".$lastcheck."</td>";
    echo "<td style='white-space:nowrap'>" . $duration ."</td><td>" . $nextcheck . "</td><td>" . $var['plugin_output'] . "</td></tr>\n"; 
    // var_dump($res);

} 

echo "</table>";
