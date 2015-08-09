<?php

$nodes = array();
$links = array();
$group = 0;
$switches = array();

snmp_set_quick_print(TRUE);


/**
 * Return the CDP/EDP table of $switch
 *
 * @param 	string	$switch 	Switch name to be requested
 * @return 	table|bool 	SNMP response, FALSE if error
 */
function get_snmp_table($switch) {
	$OID_NeighborName = '1.3.6.1.4.1.1916.1.13.2.1.3';

	$result = snmp2_walk($switch, 'public', $OID_NeighborName);

	if ($result != FALSE) {
		$result = str_replace('\"', '', $result);
		$result = array_unique($result, SORT_STRING);
		$result = array_values(array_filter($result));
		return $result;
	} else {
		return FALSE;
	}
}


/**
 * Return the node ID (json data) of $switch
 *
 * @param 	string	$switch 	Switch name
 * @return 	int|bool 	Node ID of $switch, FALSE if not found
 */
function get_switch_node_id($switch) {
	global $nodes;

	foreach($nodes as $key => $node_switch) {
		if($node_switch['name'] == $switch) {
			return $key;
		}
	}
	return FALSE;
}


/**
 * Store informations from $base_switch in global variables
 *
 * @param 	string	$base_switch 	Switch name of the base switch
 * @return 	bool 	Returns TRUE if informations was written, FALSE otherwise
 */
function get_switch_links($base_switch) {
	global $switches;
	global $nodes;
	global $group;
	global $links;

	$base_switch_node_id = get_switch_node_id($base_switch);

	// Get switches connected to $base_switch
	$snmp_response = get_snmp_table($base_switch);

	if($snmp_response == FALSE) {
		return FALSE;
	}

	foreach($snmp_response as $key => $switch) {
		// If $base_switch not in $nodes (first run of get_switch_links())
		if(strstr(json_encode($nodes), $base_switch) == FALSE) {
			array_push($nodes, array('name'  => $base_switch,
						 'group' => $group));
		}

		// If switch is already present, delete it to avoid duplicate entry
		if(strstr(json_encode($nodes), $switch) != FALSE) {
			unset($snmp_response[$key]);

			$switch_node_id = get_switch_node_id($switch);

			if($switch_node_id != FALSE && $base_switch_node_id != FALSE) {
				array_push($links, array('source' => $switch_node_id,
							 'target' => $base_switch_node_id));
			}
		} else {
			// Add switches linked to $base_switch in $nodes
			array_push($nodes, array('name'   => $switch,
						 'group'  => $group));
			array_push($links, array('source' => get_switch_node_id($switch),
						 'target' => get_switch_node_id($base_switch)));
		}
	}

	// Add $base_switch + links + group to $switches
	array_push($switches, array('group'  => $group,
				    'parent' => $base_switch,
				    'links'  => $snmp_response));

	if(strstr(json_encode($nodes), $base_switch) != FALSE) $group++;

	return TRUE;
}


/**
 * Request all links found in CDP/EDP response
 *
 * @param 	string	$base_switch 	Switch name of the base switch
 * @param 	int 	$level 	Dig level of CDP/EDP requests
 * @return 	bool 	Returns TRUE if links of $base_switch has been returned, FALSE otherwise
 */
function recursive_search($base_switch, $level = 2) {
	global $switches;

	// Fill the $switches global variable
	if(get_switch_links($base_switch) == FALSE) {
		return FALSE;
	}

	$i = 0;
	do {
		foreach($switches[$i]['links'] as $sw) {
			get_switch_links($sw);
		}
		$i++;
	} while($i < $level);

	return TRUE;
}


/**
 * Request all links found in CDP/EDP response
 *
 * @param 	string	$type 	Type of alert (success, info, warning, danger)
 * @param 	string 	$content 	Content of the alert
 * @return 	bool 	Returns TRUE if alert is print, FALSE otherwise
 */
function display_alert($type, $content) {
	$types = array(
		'success'	=> 'glyphicon-ok-sign',
		'info'		=> 'glyphicon-info-sign',
		'warning'	=> 'glyphicon-warning-sign',
		'danger' 	=> 'glyphicon-exclamation-sign'
	);

	if(!array_key_exists($type, $types)) {
		return FALSE;
	}

	print '<div class="alert alert-' . $type . ' alert-dismissible" role="alert">
		<button type="button" class="close" data-dismiss="alert" aria-label="Close">
			<span aria-hidden="true">&times;</span>
		</button>
		<span class="glyphicon ' . $types[$type] . '" aria-hidden="true"></span> '
		. $content . '</div>';

	return TRUE;
}


recursive_search('eswctb08ma', 1);

file_put_contents('./data/snmp_data.json', json_encode(array('nodes' => $nodes,
							     'links' => $links)),
							     LOCK_EX);

display_alert('info', count($nodes) . ' nodes | ' . count($links) . ' links');
