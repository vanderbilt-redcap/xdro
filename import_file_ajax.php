<?php
/*
	This script receives an .xlsx from the uploader. It checks the file, and if OK, imports each row as a record to REDCap
	
	validate uploaded file
		file exists
		no error uploading
		contains ok characters
		filename < 127 chars
		file size doesn't exceed max file upload size
	create worksheet object
		load uploaded workbook file
	
	todo make sure we accept xlsx and csv
*/
// connect to REDCap
require_once (APP_PATH_TEMP . "../redcap_connect.php");
$pid = $module->getProjectId();
// $module->nlog();

// make object that will hold our response
$json = new \stdClass();
$json->errors = [];
$json->ignored_cols = [];
$json->actions = [];

// $module->llog("post: " . print_r($_POST, true));
// $module->llog("files: " . print_r($_FILES, true));

/*
	function definitions
*/
function number_to_column($c) {	// thanks Derrick: https://icesquare.com/wordpress/example-code-to-convert-a-number-to-excel-column-letter/
	// converts 1 -> A, 26 -> Z, 27-> AA, 800 -> ADT, etc
    $c = intval($c);
    if ($c <= 0) return '';

    $letter = '';
             
    while($c != 0){
       $p = ($c - 1) % 26;
       $c = intval(($c - $p) / 26);
       $letter = chr(65 + $p) . $letter;
    }
    
    return $letter;
        
}

function file_upload_max_size() {					// from: https://stackoverflow.com/questions/13076480/php-get-actual-maximum-upload-size
  static $max_size = -1;

  if ($max_size < 0) {
    // Start with post_max_size.
    $post_max_size = parse_size(ini_get('post_max_size'));
    if ($post_max_size > 0) {
      $max_size = $post_max_size;
    }

    // If upload_max_size is less, then reduce. Except if upload_max_size is
    // zero, which indicates no limit.
    $upload_max = parse_size(ini_get('upload_max_filesize'));
    if ($upload_max > 0 && $upload_max < $max_size) {
      $max_size = $upload_max;
    }
  }
  return $max_size;
}

function parse_size($size) {
  $unit = preg_replace('/[^bkmgtpezy]/i', '', $size); // Remove the non-unit characters from the size.
  $size = preg_replace('/[^0-9\.]/', '', $size); // Remove the non-numeric characters from the size.
  if ($unit) {
    // Find the position of the unit in the ordered string which is the power of magnitude to multiply a kilobyte by.
    return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
  }
  else {
    return round($size);
  }
}

function humanFileSize($size,$unit="") {			// from: https://stackoverflow.com/questions/15188033/human-readable-file-size
  if( (!$unit && $size >= 1<<30) || $unit == "GB")
    return number_format($size/(1<<30),2)."GB";
  if( (!$unit && $size >= 1<<20) || $unit == "MB")
    return number_format($size/(1<<20),2)."MB";
  if( (!$unit && $size >= 1<<10) || $unit == "KB")
    return number_format($size/(1<<10),2)."KB";
  return number_format($size)." bytes";
}

function checkWorkbookFile($file_param_name) {
	global $json;

	$maxsize = file_upload_max_size();
	
	if (empty($_FILES[$file_param_name])) {
		$json->errors[] = "Please attach a workbook file before clicking 'Upload'.";
		exit(json_encode($json));
		return;
	}
	
	if ($_FILES[$file_param_name]["error"] !== 0) {
		$json->errors[] = "An error occured while uploading your workbook: " . $_FILES[$file_param_name]["error"] . ". Please try again.";
	}

	// have file, so check name, size
	if (preg_match("/[^A-Za-z0-9. ()-]/", $_FILES[$file_param_name]["name"])) {
		$json->errors[] = "File names can only contain alphabet, digit, period, space, hyphen, and parentheses characters.";
		$json->errors[] = "	Allowed characters: A-Z a-z 0-9 . ( ) -";
	}
	if (strlen($_FILES[$file_param_name]["name"]) > 127) {
		$json->errors[] = "Uploaded file has a name that exceeds the limit of 127 characters.";
	}
	if ($maxsize !== -1) {
		if ($_FILES[$file_param_name]["size"] > $maxsize) {
			$fileReadable = humanFileSize($_FILES[$file_param_name]["size"], "MB");
			$serverReadable = humanFileSize($maxsize, "MB");
			$json->errors[] = "Uploaded file size ($fileReadable) exceeds server maximum upload size of $serverReadable.";
		}
	}
	
	if (count($json->errors) > 0) {
		exit(json_encode($json));
	}
	
	return true;
}

function get_assoc_form($column_name) {
	// given "patient_ssn" would return "aries_registry" -- return form name that a field belongs to
	$forms = [
		"patientID" => "aries_registry",
		"PATIENT_LOCAL_ID" => "aries_registry",
		"PATIENT_DOB" => "aries_registry",
		"PATIENT_SSN" => "aries_registry",
		"PATIENT_RACE_CALCULATED" => "aries_registry",
		"PATIENT_ETHNICITY" => "aries_registry",
		"reporterName" => "aries_registry",
		"reporterPhone" => "aries_registry",
		"JURISDICTION_NM" => "aries_registry",
		"PATIENT_FIRST_NAME" => "demographics",
		"PATIENT_LAST_NAME" => "demographics",
		"PATIENT_CURRENT_SEX" => "demographics",
		"PATIENT_STREET_ADDRESS_1" => "demographics",
		"PATIENT_STREET_ADDRESS_2" => "demographics",
		"PATIENT_CITY" => "demographics",
		"PATIENT_STATE" => "demographics",
		"PATIENT_ZIP" => "demographics",
		"PATIENT_COUNTY" => "demographics",
		"PATIENT_LAST_CHANGE_TIME" => "demographics",
		"PATIENT_FIRST_NAME" => "demographics",
		"PATIENT_LAST_NAME" => "demographics",
		"PATIENT_CURRENT_SEX" => "demographics",
		"PATIENT_PHONE_HOME" => "demographics",
		"PATIENT_STREET_ADDRESS_1" => "demographics",
		"PATIENT_STREET_ADDRESS_2" => "demographics",
		"PATIENT_CITY" => "demographics",
		"PATIENT_STATE" => "demographics",
		"PATIENT_ZIP" => "demographics",
		"PATIENT_COUNTY" => "demographics",
		"ordering_facility" => "antimicrobial_susceptibilities_and_resistance_mech",
		"ORDERING_PROVIDER_NM" => "antimicrobial_susceptibilities_and_resistance_mech",
		"PROVIDER_ADDRESS" => "antimicrobial_susceptibilities_and_resistance_mech",
		"PROVIDER_PHONE" => "antimicrobial_susceptibilities_and_resistance_mech",
		"reporting_facility" => "antimicrobial_susceptibilities_and_resistance_mech",
		"ordered_test_nm" => "antimicrobial_susceptibilities_and_resistance_mech",
		"SPECIMEN_DESC" => "antimicrobial_susceptibilities_and_resistance_mech",
		"lab_test_nm" => "antimicrobial_susceptibilities_and_resistance_mech",
		"SPECIMEN_COLLECTION_DT" => "antimicrobial_susceptibilities_and_resistance_mech",
		"LAB_TEST_STATUS" => "antimicrobial_susceptibilities_and_resistance_mech",
		"resulted_dt" => "antimicrobial_susceptibilities_and_resistance_mech",
		"DISEASE" => "antimicrobial_susceptibilities_and_resistance_mech",
		"DISEASE_CD" => "antimicrobial_susceptibilities_and_resistance_mech",
		"lab_test_nm_2" => "antimicrobial_susceptibilities_and_resistance_mech",
		"coded_result_val_desc" => "antimicrobial_susceptibilities_and_resistance_mech",
		"interpretation_flg" => "antimicrobial_susceptibilities_and_resistance_mech",
		"numeric_result_val" => "antimicrobial_susceptibilities_and_resistance_mech",
		"result_units" => "antimicrobial_susceptibilities_and_resistance_mech",
		"test_method_cd" => "antimicrobial_susceptibilities_and_resistance_mech"
	];
	$forms = array_change_key_case($forms);
	
	if (!empty($forms[strtolower($column_name)])) {
		return [1, $forms[strtolower($column_name)]];
	} else {
		return [0, "no associated form for column $column_name"];
	}
}

function get_assoc_field($column_name) {
	// use this to convert data file column names to REDCap project field names (e.g., given "ORDERING_PROVIDER_NM", returns "providername"
	$fields = [
		"patientID" => "patientid",
		"PATIENT_LOCAL_ID" => "patientid",
		"ordering_facility" => "ordering_facility",
		"ORDERING_PROVIDER_NM" => "providername",
		"PROVIDER_ADDRESS" => "provider_address",
		"PROVIDER_PHONE" => "providerphone",
		"reporting_facility" => "reporting_facility",
		"reporterName" => "reportername",
		"reporterPhone" => "reporterphone",
		"ordered_test_nm" => "ordered_test_nm",
		"SPECIMEN_DESC" => "specimen_desc",
		"SPECIMEN_COLLECTION_DT" => "specimen_collection_dt",
		"LAB_TEST_STATUS" => "lab_test_status",
		"resulted_dt" => "resulted_dt",
		"DISEASE" => "disease",
		"PATIENT_LAST_CHANGE_TIME" => "patient_last_change_time",
		"PATIENT_SSN" => "patient_ssn",
		"PATIENT_RACE_CALCULATED" => "patient_race_calculated",
		"PATIENT_ETHNICITY" => "patient_ethnicity",
		"PATIENT_FIRST_NAME" => "patient_first_name",
		"PATIENT_LAST_NAME" => "patient_last_name",
		"PATIENT_DOB" => "patient_dob",
		"PATIENT_CURRENT_SEX" => "patient_current_sex",
		"PATIENT_PHONE_HOME" => "patient_phone_home",
		"PATIENT_STREET_ADDRESS_1" => "patient_street_address_1",
		"PATIENT_STREET_ADDRESS_2" => "patient_street_address_2",
		"PATIENT_CITY" => "patient_city",
		"PATIENT_STATE" => "patient_state",
		"PATIENT_ZIP" => "patient_zip",
		"PATIENT_COUNTY" => "patient_county",
		"JURISDICTION_NM" => "jurisdiction_nm",
		"lab_test_nm" => "lab_test_nm_2",
		"coded_result_val_desc" => "coded_result_val_desc",
		"interpretation_flg" => "interpretation_flg",
		"numeric_result_val" => "numeric_result_val",
		"result_units" => "result_units",
		"test_method_cd" => "test_method_cd"
	];
	$fields = array_change_key_case($fields);
	
	if (!empty($fields[strtolower($column_name)])) {
		return [1, $fields[strtolower($column_name)]];
	} else {
		return [0, "no associated field for column $column_name"];
	}
}

function get_next_instance($record, $form_name) {
	global $module;
	$pid = $module->framework->getProjectId();
	$eid = $module->getFirstEventId($pid);
	
	if (empty($record["repeat_instances"][$eid][$form_name])) {
		return 1;
	} else {
		return max(array_keys($record["repeat_instances"][$eid][$form_name])) + 1;
	}
}


function import_data_row($row, $row_index) {
	global $json;
	global $module;
	global $headers;
	global $headers_flipped;
	global $mode;
	global $lab_obj;
	
	$lab_multi = [
		"lab_test_nm",
		"jurisdiction_nm",
		"resulted_dt",
		"lab_test_status",
		"specimen_desc",
		"disease",
		"disease_cd",
		"reporting_facility",
		"ordering_facility",
		"patient_local_id"
	];
	
	// handle if this is a non "r_result" row of a lab file:
	if ($mode == 'lab') {
		$lab_row_type = strtolower($row[$headers_flipped["lab_test_type"]]);
		
		if ($lab_row_type != "r_result") {
			// delete lab_obj info if we're in a different row group now
			if (!empty($lab_obj->patient_local_id) and $row[$headers_flipped["patient_local_id"]] != $lab_obj->patient_local_id) {
				$lab_obj = new stdClass();
			}
			
			foreach($lab_multi as $field) {
				$col_index = $headers_flipped[$field];
				$value = $row[$col_index];
				if (!empty($value) and strtolower($value) != "null" and strtolower($value) != "no information given")
					$lab_obj->$field = $value;
			}
			return [];
		}
		
	}
	
	$pid = $module->framework->getProjectId();
	$eid = $module->getFirstEventId($pid);
	
	// goal is to create an array for each form that needs importing, then $module->saveData or $module->saveInstanceData
	// $module->llog("row: " . print_r($row, true));
	
	/*
		build $data array that we will fill with imported field values (from row of workbook)
		then we will call `$result = saveData(... $data)`
		collect/collate $result["errors"] for response to client
	*/
	$imported = [];
	$imported["aries_registry"] = [];
	$imported["demographics"] = [];
	$imported["antimicrobial_susceptibilities_and_resistance_mech"] = [];
	
	// fill 'imported' array with data so we can save to REDCap
	foreach ($row as $i => $value) {
		// skip null values
		if (strcasecmp($value, "NULL") == 0)
			continue;
		
		// figure out what kind of value we're importing
		$column_name = $headers[$i];
		
		$date_fields = [
			"lab_rpt_dt",
			"specimen_collection_dt",
			"resulted_dt",
			"patient_dob",
			"patient_last_change_time"
		];
		
		// convert to Y-m-d value if applicable
		if (array_search(strtolower($column_name), $date_fields, true) != false) {
			$value = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format("Y-m-d");
		}
		
		// either add error to return array or set assoc_form to be form string value
		$assoc_form = get_assoc_form($column_name);
		if ($assoc_form[0] == 0) {
			// $module->llog("error getting associated form for row / column / value : $i / $column_name / $value");
			$json->ignored_cols[$column_name] = true;
			unset($assoc_form);
		} else {
			$assoc_form = $assoc_form[1];
		}
		
		$assoc_field = get_assoc_field($column_name);
		if ($assoc_field[0] == 0) {
			// $module->llog("error getting associated field for row / column / value : $i / $column_name / $value");
			$json->ignored_cols[$column_name] = true;
			unset($assoc_field);
		} else {
			$assoc_field = $assoc_field[1];
		}
		
		if (!empty($assoc_form) && !empty($assoc_field)) {
			$imported[$assoc_form][$assoc_field] = $value;
			
			if ($mode == 'lab') {
				if (!empty($lab_obj->$assoc_field) and $assoc_field != "patient_local_id")
					$imported[$assoc_form][$assoc_field] = $lab_obj->$assoc_field;
			}
		}
		unset($column_name, $assoc_form, $assoc_field);
	}
	
	// $module->llog("printing 'imported' array before saving:" . print_r($imported, true));
	
	// save to redcap
	$rows = [];
	$pati_id = $imported["aries_registry"]["patientid"];
	if (empty($pati_id)) {
		$rows[] = [$row, "[NOT FOUND]", "N/A", "No patient ID found -- make sure patient_ID or PATIENT_LOCAL_ID column isn't empty!"];
	} else {
		$data = \REDCap::getData($pid, 'array', $pati_id);
		$next_demographics_instance = get_next_instance(reset($data), "demographics");
		$next_antimicrobial_instance = get_next_instance(reset($data), "antimicrobial_susceptibilities_and_resistance_mech");
		
		// overwrite aries_registry form values (if imported values)
		if (!empty($imported["aries_registry"])) {
			$data[$pati_id][$eid] = $imported["aries_registry"];
			
			// add to \$rows for reporting purposes
			$fields = array_keys($imported["aries_registry"]);
			$rows[] = [$row_index, $pati_id, 'aries_registry', "Updating fields: " . implode(', ', $fields)];
		}
		
		// add instances to repeatable forms (if imported values)
		if (!empty($imported["demographics"])) {
			$data[$pati_id]["repeat_instances"][$eid]["demographics"][$next_demographics_instance] = $imported["demographics"];
			
			// reporting
			$rows[] = [$row_index, $pati_id, 'demographics', "Creating instance $next_demographics_instance"];
		}
		
		if (!empty($imported["antimicrobial_susceptibilities_and_resistance_mech"])) {
			$data[$pati_id]["repeat_instances"][$eid]["antimicrobial_susceptibilities_and_resistance_mech"][$next_antimicrobial_instance] = $imported["antimicrobial_susceptibilities_and_resistance_mech"];
			
			// reporting
			$rows[] = [$row_index, $pati_id, 'antimicrobial_susceptibilities_and_resistance_mech', "Creating instance $next_antimicrobial_instance"];
		}
		
		// $module->llog('printing data:' . print_r($data, true));
		
		// try to save
		// $module->llog("PATIENTID: $pati_id - saveData data: " . print_r($data, true));
		$result = \REDCap::saveData($pid, 'array', $data);
		// $module->llog("PATIENTID: $pati_id - saveData result: " . print_r($result, true));
		
		// set \$rows to empty array since above $rows entries are now inaccurate (save errors prevent update/create)
		if (!empty($result["errors"]))
			$rows = [];
		
		foreach($result["errors"] as $err) {
			// $module->llog("saveData err: $err");
			$rows[] = [$row_index, $pati_id, "", $err];
		}
		if (gettype($result["errors"]) == "string") {
			$rows[] = [$row_index, $pati_id, "", $result["errors"]];
		}
	}
	return $rows;
}

function send_lots_of_errors() {
	global $json;
	
	// this function should be used to help test/build the client side error reporting functionality
	// $json->errors[] = "Please attach a workbook file before clicking 'Upload'.";
	// $json->errors[] = "File names can only contain alphabet, digit, period, space, hyphen, and parentheses characters.";
	// $json->errors[] = "Uploaded file has a name that exceeds the limit of 127 characters.";
	$json->row_error_arrays = [];
	$json->row_error_arrays[1] = [[1, "No patient ID found -- make sure patient_ID or PATIENT_LOCAL_ID column isn't empty"]];
	$json->row_error_arrays[2] = [[1, "No patient ID found -- make sure patient_ID or PATIENT_LOCAL_ID column isn't empty"]];
	$json->row_error_arrays[3] = [];
	$json->row_error_arrays[4] = [
		[0, "no associated field for column PATIENT_COUNTRY"],
		[0, "no associated field for column BAD_COL_NAME"],
		[0, "no associated field for column BAD_COL_NAME2"]
	];
	$json->row_error_arrays[5] = [
		[0, "no associated field for column PATIENT_COUNTRY"],
		[0, "no associated field for column BAD_COL_NAME"],
		[0, "no associated field for column BAD_COL_NAME2"]
	];
	$json->row_error_arrays[6] = [];
	$json->row_error_arrays[7] = [[1, "No patient ID found -- make sure patient_ID or PATIENT_LOCAL_ID column isn't empty"]];
	$json->row_error_arrays[8] = [];
	$json->row_error_arrays[9] = [];
	$json->row_error_arrays[10] = [];
	$json->row_error_arrays[11] = [];
	exit(json_encode($json));
}

// send_lots_of_errors();

// use PHPSpreadsheet (php 5.6 | 7.x version)
require "libs/PhpSpreadsheet/vendor/autoload.php";
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

// check workbook file, load if ok
try {
	checkWorkbookFile("client_file");
	$upload_path = $_FILES["client_file"]["tmp_name"];
	
	// at this point, we know checkWorkbookFile didn't exit with errors
	$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($upload_path);
	$reader->setReadDataOnly(TRUE);
	$workbook = $reader->load($upload_path);
	$sheet = $workbook->getActiveSheet();
	
	// cleanup
	unlink($upload_path);
	
} catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
	$json->errors[] = "There was an error reading the file uploaded: $e";
	exit(json_encode($json));
}

// $module->llog("No error reading file.");

// this obj will lab order values that span across multiple rows (per PATIENT_LOCAL_ID)
$lab_obj = new \stdClass();

$headers = [];
$json->actions = [];
foreach ($sheet->getRowIterator() as $i => $row) {
	if ($i == 1) {
		// build headers array for future referencing
		$cell_iter = $row->getCellIterator();
		foreach($cell_iter as $cell) {
			$headers[] = $cell->getValue();
		}
		$headers_flipped = array_flip(array_map('strtolower', $headers));
		
		// found out if this file is a lab import or patient file
		$mode = "patient";
		foreach ($headers as $header) {
			if (strtolower($header) == "lab_test_type")
				$mode = "lab";
		}
	} else {
		$range = "A$i:" . number_to_column(count($headers)) . "$i";
		$json->actions = array_merge($json->actions, import_data_row(reset($sheet->rangeToArray($range)), $i));
	}
}

$json->success = true;
exit(json_encode($json));



