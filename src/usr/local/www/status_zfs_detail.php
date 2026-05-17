<?php
/*
 * status_zfs_detail.php
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

##|+PRIV
##|*IDENT=page-status-zfspools
##|*NAME=Status: ZFS Pools
##|*DESCR=Allow access to the 'Status: ZFS Pools' page.
##|*MATCH=status_zfs_detail.php*
##|-PRIV

require_once('guiconfig.inc');
require_once('/usr/local/www/widgets/include/zfs_status.inc');

$pool       = $_GET['pool'] ?? '';
$validPools = array_keys(get_zpool_json());
$poolError  = false;

if ($pool !== '' && !in_array($pool, $validPools, true)) {
	$poolError = true;
}

if (!$poolError) {
	if ($pool !== '') {
		$cmd = '/sbin/zpool status -v ' . escapeshellarg($pool);
	} else {
		$cmd = '/sbin/zpool status -v';
	}
	exec($cmd . ' 2>&1', $cmdOutput);
	$outputText = implode("\n", $cmdOutput);
}

$pgtitle = [gettext('Status'), gettext('ZFS Pools')];
$pglinks = ['', '@self'];
if ($pool !== '' && !$poolError) {
	$pgtitle[] = htmlspecialchars($pool, ENT_QUOTES, 'UTF-8');
	$pglinks[]  = '@self';
}

include('head.inc');
?>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">
<?php if ($pool !== '' && !$poolError): ?>
			<?=htmlspecialchars($pool, ENT_QUOTES, 'UTF-8')?>
<?php else: ?>
			<?=gettext('All Pools')?>
<?php endif; ?>
		</h2>
	</div>
	<div class="panel-body">
<?php if ($poolError): ?>
		<?php print_info_box(gettext('Invalid pool name.'), 'danger', false); ?>
<?php elseif (empty($validPools)): ?>
		<?php print_info_box(gettext('No ZFS pools found.'), 'warning', false); ?>
<?php else: ?>
		<pre><?=htmlspecialchars($outputText, ENT_QUOTES, 'UTF-8')?></pre>
<?php endif; ?>
	</div>
</div>

<?php include('foot.inc'); ?>
