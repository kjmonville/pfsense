<?php
/*
 * zfs_status.widget.php
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

require_once("guiconfig.inc");
require_once("pfsense-utils.inc");
require_once("functions.inc");
require_once("/usr/local/www/widgets/include/zfs_status.inc");

/*
 * Validate the "widgetkey" value.
 * When this widget is present on the Dashboard, $widgetkey is defined before
 * the Dashboard includes the widget. During other types of requests, such as
 * saving settings or AJAX, the value may be set via $_POST or similar.
 */
if ($_POST['widgetkey'] || $_GET['widgetkey']) {
	$rwidgetkey = isset($_POST['widgetkey']) ? $_POST['widgetkey'] : (isset($_GET['widgetkey']) ? $_GET['widgetkey'] : null);
	if (is_valid_widgetkey($rwidgetkey, $user_settings, __FILE__)) {
		$widgetkey = $rwidgetkey;
	} else {
		print gettext("Invalid Widget Key");
		exit;
	}
}

// Are we handling an ajax refresh?
if (isset($_POST['ajax'])) {
	print(zfs_status_create_table($widgetkey));
	exit();
}

?>

<div class="table-responsive">
	<?php print(zfs_status_create_table($widgetkey)); ?>
</div>

<script type="text/javascript">
//<![CDATA[

events.push(function(){
	var zfsCookie = <?=json_encode("treegrid-{$widgetkey}")?>;

	// Callback function called by refresh system when data is retrieved
	function zfs_status_callback(s) {
		var zfsTree = $(<?=json_encode("#{$widgetkey}-table")?>);

		zfsTree.removeData();

		zfsTree.html(s);

		initTreegrid(true);
	}

	// POST data to send via AJAX
	var postdata = {
		ajax: "ajax",
		widgetkey: <?=json_encode($widgetkey)?>
	};

	// Create an object defining the widget refresh AJAX call
	var zfsStatusObject = new Object();
	zfsStatusObject.name = "ZFS Pool Status";
	zfsStatusObject.url = "/widgets/widgets/zfs_status.widget.php";
	zfsStatusObject.callback = zfs_status_callback;
	zfsStatusObject.parms = postdata;
	zfsStatusObject.freq = 12; // Increments of 5 seconds

	// Register the AJAX object
	register_ajax(zfsStatusObject);

	function initTreegrid(isAjax) {
		var zfsTree = $(<?=json_encode("#{$widgetkey}-table")?>);

		if (!isAjax) {
			$.removeCookie(zfsCookie);

			zfsTree.removeData();
		}

		zfsTree.treegrid({
			expanderExpandedClass: 'fa-solid fa-chevron-down',
			expanderCollapsedClass: 'fa-solid fa-chevron-right',
			initialState: 'collapsed',
			saveStateName: zfsCookie,
			saveState: true
		});
	}

	initTreegrid(false);
});

//]]>
</script>
