<?php
/*
 * zfs_status.inc
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2024 Ken Monville
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

//set variable for custom title
$zfs_status_title = gettext("ZFS Pools");

/*
 * get_zpool_list()
 *
 * Parses the output of 'zpool list -H' and returns an associative array
 * keyed by pool name, with keys: name, size, alloc, free, ckpoint,
 * expandsz, frag, cap, dedup, health, altroot.
 */
function get_zpool_list() {
	$returnArray = array();

	exec("/sbin/zpool list -H", $zpoolListOutput);

	if (empty($zpoolListOutput)) {
		return $returnArray;
	}

	foreach ($zpoolListOutput as $zpool) {
		$fields = array_pad(preg_split("/\s+/", $zpool), 11, '');
		[$poolName, $poolSize, $poolAlloc, $poolFree, $poolCkpoint,
		 $poolExpandsz, $poolFrag, $poolCap, $poolDedup,
		 $poolHealth, $poolAltroot] = $fields;

		$returnArray[$poolName]['name']     = $poolName;
		$returnArray[$poolName]['size']     = $poolSize;
		$returnArray[$poolName]['alloc']    = $poolAlloc;
		$returnArray[$poolName]['free']     = $poolFree;
		$returnArray[$poolName]['ckpoint']  = $poolCkpoint;
		$returnArray[$poolName]['expandsz'] = $poolExpandsz;
		$returnArray[$poolName]['frag']     = $poolFrag;
		$returnArray[$poolName]['cap']      = $poolCap;
		$returnArray[$poolName]['dedup']    = $poolDedup;
		$returnArray[$poolName]['health']   = $poolHealth;
		$returnArray[$poolName]['altroot']  = $poolAltroot;
	}

	return $returnArray;
}

/*
 * get_zpool_status($poolName)
 *
 * Parses the output of 'zpool status -v <poolName>' and returns an indexed
 * array of vdev rows from the config: section, each with keys:
 * name, state, read, write, cksum.
 */
function get_zpool_status($poolName) {
	$returnArray = array();
	$i = 0;
	$inConfig = false;

	// poolName is missing or contains invalid characters
	if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_\-.:]*$/', $poolName)) {
		return $returnArray;
	}

	exec("/sbin/zpool status -v " . escapeshellarg($poolName), $zpoolStatusLines);

	foreach ($zpoolStatusLines as $statusLine) {
		if ($statusLine == "config:") {
			$inConfig = true;
			continue;
		} elseif (preg_match("/^errors:.*$/", $statusLine)) {
			$inConfig = false;
			continue;
		} elseif ($inConfig) {
			if ($statusLine == "" || preg_match("/^\s+NAME\s+STATE.*?/", $statusLine)) {
				continue;
			}

			$fields = array_pad(preg_split("/\s+/", ltrim($statusLine)), 5, '');
			[$poolStatusName, $poolStatusState, $poolStatusRead,
			 $poolStatusWrite, $poolStatusCksum] = $fields;

			$returnArray[$i]['name']  = $poolStatusName;
			$returnArray[$i]['state'] = $poolStatusState;
			$returnArray[$i]['read']  = $poolStatusRead;
			$returnArray[$i]['write'] = $poolStatusWrite;
			$returnArray[$i]['cksum'] = $poolStatusCksum;
			$i++;
		}
	}

	return $returnArray;
}

/*
 * health_color($health)
 *
 * Returns a Bootstrap text class based on ZFS health status.
 */
function health_color($health) {
	switch ($health) {
		case 'ONLINE':
			return "text-success";
		case 'DEGRADED':
			return "text-warning";
		default:
			return "text-danger";
	}
}

function zfs_status_create_table($widgetkey) {
	$date = date('m/d/Y h:i:s a', time());
	$zpools = get_zpool_list();

	if (count($zpools) < 1) {
		ob_start();
		print_info_box(gettext("No pools found."), 'warning', false);
		return ob_get_clean();
	}

	$output  =	"<table id=\"" . htmlspecialchars($widgetkey, ENT_QUOTES, 'UTF-8') . "-table\" class=\"table table-striped table-hover table-condensed\">";
	$output .=		'<thead>';
	$output .=			'<tr>';
	$output .=				'<th>'.gettext("Name").'</th>';
	$output .=				'<th>'.gettext("Health").'</th>';
	$output .=				'<th>'.gettext("Size").'</th>';
	$output .=				'<th>'.gettext("Alloc").'</th>';
	$output .=				'<th>'.gettext("Free").'</th>';
	$output .=				'<th>'.gettext("Frag").'</th>';
	$output .=			'</tr>';
	$output .=		'</thead>';
	$output .=		'<tbody>';
	$output .=			zfs_status_create_pool_rows($zpools);
	$output .=			'<tr>';
	$output .=				"<td colspan=\"6\"><small>" . gettext("Last Update") . ": {$date}</small></td>";
	$output .=			'</tr>';
	$output .=		'</tbody>';
	$output .=	'</table>';

	return $output;
}

function zfs_status_create_pool_rows($zpoolListData) {
	$output = "";

	foreach ($zpoolListData as $zpool) {
		$safeName            = htmlspecialchars($zpool['name'],   ENT_QUOTES, 'UTF-8');
		$treegridRootClass   = "root-{$safeName}";
		$treegridDetailClass = "detail-{$safeName}";

		$output .=	"<tr class=\"treegrid-{$treegridRootClass}\">";
		$output .=		"<td>{$safeName}</td>";
		$output .=		'<td>';
		$output .=			"<span class=\"" . health_color($zpool['health']) . "\">" . htmlspecialchars($zpool['health'], ENT_QUOTES, 'UTF-8') . "</span>";
		$output .=		'</td>';
		$output .=		"<td>" . htmlspecialchars($zpool['size'],  ENT_QUOTES, 'UTF-8') . "</td>";
		$output .=		"<td>" . htmlspecialchars($zpool['alloc'], ENT_QUOTES, 'UTF-8') . "</td>";
		$output .=		"<td>" . htmlspecialchars($zpool['free'],  ENT_QUOTES, 'UTF-8') . "</td>";
		$output .=		"<td>" . htmlspecialchars($zpool['frag'],  ENT_QUOTES, 'UTF-8') . "</td>";
		$output .=	'</tr>';
		$output .=	"<tr class=\"treegrid-{$treegridDetailClass} treegrid-parent-{$treegridRootClass}\">";
		$output .=		'<td colspan="6">';
		$output .=			'<div class="table-responsive">';
		$output .=				'<table class="table table-striped table-hover table-condensed">';
		$output .=					'<thead>';
		$output .=						'<tr>';
		$output .=							'<th>'.gettext("Name").'</th>';
		$output .=							'<th>'.gettext("State").'</th>';
		$output .=							'<th>'.gettext("Read").'</th>';
		$output .=							'<th>'.gettext("Write").'</th>';
		$output .=							'<th>'.gettext("Cksum").'</th>';
		$output .=						'</tr>';
		$output .=					'</thead>';
		$output .=					'<tbody>';
		$output .=						zfs_status_create_detail_table($zpool['name']);
		$output .=					'</tbody>';
		$output .=				'</table>';
		$output .=			'</div>';
		$output .=		'</td>';
		$output .=	'</tr>';
	}

	return $output;
}

/*
 * zfs_status_create_detail_table($pool)
 *
 * Renders the vdev detail rows for a single pool. Indentation is conveyed
 * via Bootstrap text-muted prefix characters rather than inline styles.
 */
function zfs_status_create_detail_table($pool) {
	$output = "";
	$zpoolStatusData = get_zpool_status($pool);

	foreach ($zpoolStatusData as $i => $entry) {
		$indent = ($i > 1) ? str_repeat("\xc2\xa0\xc2\xa0", 2) : str_repeat("\xc2\xa0\xc2\xa0", $i);

		$output .=	'<tr>';
		$output .=		"<th>" . $indent . htmlspecialchars($entry['name'],  ENT_QUOTES, 'UTF-8') . "</th>";
		$output .=		'<td>';
		$output .=			"<span class=\"" . health_color($entry['state']) . "\">" . htmlspecialchars($entry['state'], ENT_QUOTES, 'UTF-8') . "</span>";
		$output .=		'</td>';
		$output .=		"<td>" . htmlspecialchars($entry['read'],  ENT_QUOTES, 'UTF-8') . "</td>";
		$output .=		"<td>" . htmlspecialchars($entry['write'], ENT_QUOTES, 'UTF-8') . "</td>";
		$output .=		"<td>" . htmlspecialchars($entry['cksum'], ENT_QUOTES, 'UTF-8') . "</td>";
		$output .=	'</tr>';
	}

	return $output;
}

?>
