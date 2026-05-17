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
 * get_zpool_json()
 *
 * Runs 'zpool status -j --json-int' and 'zpool list -j --json-int' and
 * merges results into an associative array keyed by pool name. Each entry
 * contains all health, vdev-tree, scan, and capacity data in one place.
 *
 * Pool array keys:
 *   name, state, status, action, see, scan, errors, config (vdev tree),
 *   size, alloc, free, frag, cap, dedup
 */
function get_zpool_json(?callable $runner = null) {
	$result = [];

	if ($runner === null) {
		$runner = static function(string $cmd, array &$out, int &$ret): void {
			exec($cmd, $out, $ret);
		};
	}

	$statusOut = [];
	$statusRet = 0;
	$runner('/sbin/zpool status -j --json-int 2>/dev/null', $statusOut, $statusRet);

	$listOut = [];
	$listRet = 0;
	$runner('/sbin/zpool list   -j          2>/dev/null', $listOut, $listRet);

	$statusPools = ($statusRet === 0)
		? (json_decode(implode('', $statusOut), true)['pools'] ?? [])
		: [];
	$listPools = ($listRet === 0)
		? (json_decode(implode('', $listOut), true)['pools'] ?? [])
		: [];

	foreach ($statusPools as $name => $pool) {
		$props = $listPools[$name]['properties'] ?? [];
		$result[$name] = [
			'name'        => $name,
			'state'       => $pool['state']       ?? '',
			'status'      => $pool['status']      ?? '',
			'action'      => $pool['action']      ?? '',
			'see'         => $pool['see']         ?? '',
			'scan'        => $pool['scan']        ?? [],
			'error_count' => $pool['error_count'] ?? 0,
			'vdevs'       => $pool['vdevs']       ?? [],
			'size'        => $props['size']['value']          ?? '-',
			'alloc'       => $props['allocated']['value']     ?? '-',
			'free'        => $props['free']['value']          ?? '-',
			'frag'        => $props['fragmentation']['value'] ?? '-',
			'cap'         => $props['capacity']['value']      ?? '-',
			'dedup'       => $props['dedupratio']['value']    ?? '-',
		];
	}

	return $result;
}

/*
 * parse_vdev_tree($vdev, $depth)
 *
 * Recursively walks a vdev subtree from 'zpool status -j' and returns a
 * flat array of rows, each with: name, type, state, read, write, cksum,
 * depth. depth=0 is a top-level vdev group (mirror-0, raidz1-0, sda, etc.).
 */
function parse_vdev_tree($vdev, $depth = 0) {
	$rows = [[
		'name'  => $vdev['name']            ?? '',
		'type'  => $vdev['vdev_type']       ?? '',
		'state' => $vdev['state']           ?? '',
		'read'  => $vdev['read_errors']     ?? 0,
		'write' => $vdev['write_errors']    ?? 0,
		'cksum' => $vdev['checksum_errors'] ?? 0,
		'depth' => $depth,
	]];

	foreach ($vdev['vdevs'] ?? [] as $child) {
		$rows = array_merge($rows, parse_vdev_tree($child, $depth + 1));
	}

	return $rows;
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
	$zpools = get_zpool_json();

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
		$output .=			"<span class=\"" . health_color($zpool['state']) . "\">" . htmlspecialchars($zpool['state'], ENT_QUOTES, 'UTF-8') . "</span>";
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
		$output .=						zfs_status_create_detail_table($zpool);
		$output .=					'</tbody>';
		$output .=				'</table>';
		$output .=			'</div>';
		$output .=		'</td>';
		$output .=	'</tr>';
	}

	return $output;
}

/*
 * zfs_status_create_detail_table($poolData)
 *
 * Renders the vdev detail rows for a single pool by recursively walking the
 * nested vdev tree from get_zpool_json(). Indentation depth reflects the
 * actual vdev hierarchy (pool → mirror/raidz → leaf disk).
 */
function zfs_status_create_detail_table($poolData) {
	$output = '';

	foreach ($poolData['vdevs'] ?? [] as $vdev) {
		foreach (parse_vdev_tree($vdev) as $row) {
			$indent = str_repeat("\xc2\xa0\xc2\xa0", $row['depth'] * 2);

			$output .=	'<tr>';
			$output .=		"<th>{$indent}" . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . "</th>";
			$output .=		'<td>';
			$output .=			"<span class=\"" . health_color($row['state']) . "\">" . htmlspecialchars($row['state'], ENT_QUOTES, 'UTF-8') . "</span>";
			$output .=		'</td>';
			$output .=		'<td>' . (int)$row['read']  . '</td>';
			$output .=		'<td>' . (int)$row['write'] . '</td>';
			$output .=		'<td>' . (int)$row['cksum'] . '</td>';
			$output .=	'</tr>';
		}
	}

	return $output;
}

?>
