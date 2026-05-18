<?php
/*
 * ZfsStatusTest.php
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

// Stub pfSense functions unavailable outside the web framework
function print_info_box(string $msg, string $type = 'info', bool $closeable = true): void {}

set_include_path(
	get_include_path() . PATH_SEPARATOR .
	realpath(__DIR__ . '/../src/usr/local/www/widgets/include')
);
require_once('zfs_status.inc');

use PHPUnit\Framework\TestCase;

class ZfsStatusTest extends TestCase {

	// -------------------------------------------------------------------------
	// Fixture data
	// -------------------------------------------------------------------------

	/** pfSense-style mirror pool — root vdev → mirror-0 → da0p4, da1p4 */
	private array $mirrorPoolVdevs;

	/** Single-disk pool — root vdev → da2 */
	private array $singleDiskPoolVdevs;

	/** Degraded pool — root vdev → mirror-0 → da0p4 (ONLINE), da1p4 (FAULTED) */
	private array $degradedPoolVdevs;

	/** zpool list properties for the mirror pool */
	private array $mirrorListProps;

	protected function setUp(): void {
		$this->mirrorPoolVdevs = [
			'pfSense' => [
				'name'            => 'pfSense',
				'vdev_type'       => 'root',
				'state'           => 'ONLINE',
				'read_errors'     => 0,
				'write_errors'    => 0,
				'checksum_errors' => 0,
				'vdevs'           => [
					'mirror-0' => [
						'name'            => 'mirror-0',
						'vdev_type'       => 'mirror',
						'state'           => 'ONLINE',
						'read_errors'     => 0,
						'write_errors'    => 0,
						'checksum_errors' => 0,
						'vdevs'           => [
							'da0p4' => [
								'name'            => 'da0p4',
								'vdev_type'       => 'disk',
								'state'           => 'ONLINE',
								'read_errors'     => 0,
								'write_errors'    => 0,
								'checksum_errors' => 0,
							],
							'da1p4' => [
								'name'            => 'da1p4',
								'vdev_type'       => 'disk',
								'state'           => 'ONLINE',
								'read_errors'     => 0,
								'write_errors'    => 0,
								'checksum_errors' => 0,
							],
						],
					],
				],
			],
		];

		$this->singleDiskPoolVdevs = [
			'test' => [
				'name'            => 'test',
				'vdev_type'       => 'root',
				'state'           => 'ONLINE',
				'read_errors'     => 0,
				'write_errors'    => 0,
				'checksum_errors' => 0,
				'vdevs'           => [
					'da2' => [
						'name'            => 'da2',
						'vdev_type'       => 'disk',
						'state'           => 'ONLINE',
						'read_errors'     => 0,
						'write_errors'    => 0,
						'checksum_errors' => 0,
					],
				],
			],
		];

		$this->degradedPoolVdevs = [
			'tank' => [
				'name'            => 'tank',
				'vdev_type'       => 'root',
				'state'           => 'DEGRADED',
				'read_errors'     => 0,
				'write_errors'    => 0,
				'checksum_errors' => 0,
				'vdevs'           => [
					'mirror-0' => [
						'name'            => 'mirror-0',
						'vdev_type'       => 'mirror',
						'state'           => 'DEGRADED',
						'read_errors'     => 0,
						'write_errors'    => 0,
						'checksum_errors' => 3,
						'vdevs'           => [
							'da0p4' => [
								'name'            => 'da0p4',
								'vdev_type'       => 'disk',
								'state'           => 'ONLINE',
								'read_errors'     => 0,
								'write_errors'    => 0,
								'checksum_errors' => 0,
							],
							'da1p4' => [
								'name'            => 'da1p4',
								'vdev_type'       => 'disk',
								'state'           => 'FAULTED',
								'read_errors'     => 0,
								'write_errors'    => 3,
								'checksum_errors' => 0,
							],
						],
					],
				],
			],
		];

		$this->mirrorListProps = [
			'size'          => ['value' => '6.50G'],
			'allocated'     => ['value' => '1.29G'],
			'free'          => ['value' => '5.21G'],
			'fragmentation' => ['value' => '2%'],
			'capacity'      => ['value' => '19%'],
			'dedupratio'    => ['value' => '1.00x'],
		];
	}

	// -------------------------------------------------------------------------
	// Runner helper
	// -------------------------------------------------------------------------

	/**
	 * Returns a callable that simulates exec() for get_zpool_json().
	 * statusPools and listPools are the 'pools' sub-arrays of each command's JSON.
	 */
	private function makeRunner(
		array $statusPools,
		array $listPools,
		int   $statusRet = 0,
		int   $listRet   = 0
	): callable {
		return function(string $cmd, array &$out, int &$ret)
		         use ($statusPools, $listPools, $statusRet, $listRet): void {
			if (str_contains($cmd, 'status')) {
				$out = [json_encode(['output_version' => [], 'pools' => $statusPools])];
				$ret = $statusRet;
			} else {
				$out = [json_encode(['output_version' => [], 'pools' => $listPools])];
				$ret = $listRet;
			}
		};
	}

	// -------------------------------------------------------------------------
	// health_color()
	// -------------------------------------------------------------------------

	public function test_health_color_online(): void {
		$this->assertEquals('text-success', health_color('ONLINE'));
	}

	public function test_health_color_degraded(): void {
		$this->assertEquals('text-warning', health_color('DEGRADED'));
	}

	public function test_health_color_faulted(): void {
		$this->assertEquals('text-danger', health_color('FAULTED'));
	}

	public function test_health_color_offline(): void {
		$this->assertEquals('text-danger', health_color('OFFLINE'));
	}

	public function test_health_color_empty_string(): void {
		$this->assertEquals('text-danger', health_color(''));
	}

	public function test_health_color_unknown_state(): void {
		$this->assertEquals('text-danger', health_color('REMOVED'));
	}

	public function test_health_color_avail_is_success(): void {
		$this->assertEquals('text-success', health_color('AVAIL'));
	}

	public function test_health_color_inuse_is_warning(): void {
		$this->assertEquals('text-warning', health_color('INUSE'));
	}

	// -------------------------------------------------------------------------
	// parse_vdev_tree()
	// -------------------------------------------------------------------------

	public function test_parse_vdev_tree_leaf_disk_returns_one_row(): void {
		$leaf = [
			'name'            => 'da0',
			'vdev_type'       => 'disk',
			'state'           => 'ONLINE',
			'read_errors'     => 0,
			'write_errors'    => 0,
			'checksum_errors' => 0,
		];
		$rows = parse_vdev_tree($leaf);
		$this->assertCount(1, $rows);
	}

	public function test_parse_vdev_tree_leaf_disk_maps_vdev_type_to_type(): void {
		$leaf = [
			'name'            => 'da0',
			'vdev_type'       => 'disk',
			'state'           => 'ONLINE',
			'read_errors'     => 0,
			'write_errors'    => 0,
			'checksum_errors' => 0,
		];
		$rows = parse_vdev_tree($leaf);
		$this->assertEquals('disk', $rows[0]['type']);
	}

	public function test_parse_vdev_tree_leaf_disk_has_depth_zero(): void {
		$leaf = [
			'name'            => 'da0',
			'vdev_type'       => 'disk',
			'state'           => 'ONLINE',
			'read_errors'     => 0,
			'write_errors'    => 0,
			'checksum_errors' => 0,
		];
		$rows = parse_vdev_tree($leaf);
		$this->assertEquals(0, $rows[0]['depth']);
	}

	public function test_parse_vdev_tree_leaf_carries_error_counts(): void {
		$leaf = [
			'name'            => 'da0',
			'vdev_type'       => 'disk',
			'state'           => 'FAULTED',
			'read_errors'     => 1,
			'write_errors'    => 2,
			'checksum_errors' => 3,
		];
		$rows = parse_vdev_tree($leaf);
		$this->assertEquals(1, $rows[0]['read']);
		$this->assertEquals(2, $rows[0]['write']);
		$this->assertEquals(3, $rows[0]['cksum']);
	}

	public function test_parse_vdev_tree_mirror_returns_three_rows(): void {
		$mirror = [
			'name'            => 'mirror-0',
			'vdev_type'       => 'mirror',
			'state'           => 'ONLINE',
			'read_errors'     => 0,
			'write_errors'    => 0,
			'checksum_errors' => 0,
			'vdevs'           => [
				'da0' => ['name' => 'da0', 'vdev_type' => 'disk', 'state' => 'ONLINE',
				          'read_errors' => 0, 'write_errors' => 0, 'checksum_errors' => 0],
				'da1' => ['name' => 'da1', 'vdev_type' => 'disk', 'state' => 'ONLINE',
				          'read_errors' => 0, 'write_errors' => 0, 'checksum_errors' => 0],
			],
		];
		$rows = parse_vdev_tree($mirror);
		$this->assertCount(3, $rows);
	}

	public function test_parse_vdev_tree_mirror_assigns_correct_depths(): void {
		$mirror = [
			'name'            => 'mirror-0',
			'vdev_type'       => 'mirror',
			'state'           => 'ONLINE',
			'read_errors'     => 0,
			'write_errors'    => 0,
			'checksum_errors' => 0,
			'vdevs'           => [
				'da0' => ['name' => 'da0', 'vdev_type' => 'disk', 'state' => 'ONLINE',
				          'read_errors' => 0, 'write_errors' => 0, 'checksum_errors' => 0],
				'da1' => ['name' => 'da1', 'vdev_type' => 'disk', 'state' => 'ONLINE',
				          'read_errors' => 0, 'write_errors' => 0, 'checksum_errors' => 0],
			],
		];
		$rows = parse_vdev_tree($mirror);
		$this->assertEquals(0, $rows[0]['depth']); // mirror-0
		$this->assertEquals(1, $rows[1]['depth']); // da0
		$this->assertEquals(1, $rows[2]['depth']); // da1
	}

	public function test_parse_vdev_tree_three_level_nesting(): void {
		// root (0) → mirror-0 (1) → da0p4 (2), da1p4 (2)
		$root = $this->mirrorPoolVdevs['pfSense'];
		$rows = parse_vdev_tree($root);
		$this->assertCount(4, $rows);
		$this->assertEquals(0, $rows[0]['depth']); // pfSense (root)
		$this->assertEquals(1, $rows[1]['depth']); // mirror-0
		$this->assertEquals(2, $rows[2]['depth']); // da0p4
		$this->assertEquals(2, $rows[3]['depth']); // da1p4
	}

	public function test_parse_vdev_tree_row_order_is_depth_first(): void {
		$root = $this->mirrorPoolVdevs['pfSense'];
		$rows = parse_vdev_tree($root);
		$names = array_column($rows, 'name');
		$this->assertEquals(['pfSense', 'mirror-0', 'da0p4', 'da1p4'], $names);
	}

	public function test_parse_vdev_tree_raidz_with_three_disks(): void {
		$raidz = [
			'name'            => 'raidz1-0',
			'vdev_type'       => 'raidz',
			'state'           => 'ONLINE',
			'read_errors'     => 0,
			'write_errors'    => 0,
			'checksum_errors' => 0,
			'vdevs'           => [
				'da0' => ['name' => 'da0', 'vdev_type' => 'disk', 'state' => 'ONLINE',
				          'read_errors' => 0, 'write_errors' => 0, 'checksum_errors' => 0],
				'da1' => ['name' => 'da1', 'vdev_type' => 'disk', 'state' => 'ONLINE',
				          'read_errors' => 0, 'write_errors' => 0, 'checksum_errors' => 0],
				'da2' => ['name' => 'da2', 'vdev_type' => 'disk', 'state' => 'ONLINE',
				          'read_errors' => 0, 'write_errors' => 0, 'checksum_errors' => 0],
			],
		];
		$rows = parse_vdev_tree($raidz);
		$this->assertCount(4, $rows); // raidz1-0 + 3 disks
	}

	public function test_parse_vdev_tree_cant_open_state_displays_as_unavail(): void {
		$leaf = [
			'name'            => 'da0',
			'vdev_type'       => 'disk',
			'state'           => 'CANT_OPEN',
			'read_errors'     => 0,
			'write_errors'    => 0,
			'checksum_errors' => 0,
		];
		$rows = parse_vdev_tree($leaf);
		$this->assertEquals('UNAVAIL', $rows[0]['state']);
	}

	public function test_parse_vdev_tree_missing_vdevs_key_does_not_recurse(): void {
		$leaf = [
			'name'            => 'da0',
			'vdev_type'       => 'disk',
			'state'           => 'ONLINE',
			'read_errors'     => 0,
			'write_errors'    => 0,
			'checksum_errors' => 0,
			// no 'vdevs' key
		];
		$rows = parse_vdev_tree($leaf);
		$this->assertCount(1, $rows);
	}

	// -------------------------------------------------------------------------
	// get_zpool_json()
	// -------------------------------------------------------------------------

	public function test_get_zpool_json_returns_empty_when_status_fails(): void {
		$runner = $this->makeRunner([], [], statusRet: 1, listRet: 1);
		$result = get_zpool_json($runner);
		$this->assertEmpty($result);
	}

	public function test_get_zpool_json_returns_empty_on_malformed_json(): void {
		$runner = function(string $cmd, array &$out, int &$ret): void {
			$out = ['not valid json {{'];
			$ret = 0;
		};
		$result = get_zpool_json($runner);
		$this->assertEmpty($result);
	}

	public function test_get_zpool_json_single_pool_name_and_state(): void {
		$statusPools = [
			'pfSense' => [
				'name'        => 'pfSense',
				'state'       => 'ONLINE',
				'error_count' => 0,
				'vdevs'       => $this->mirrorPoolVdevs,
			],
		];
		$runner = $this->makeRunner($statusPools, []);
		$result = get_zpool_json($runner);

		$this->assertArrayHasKey('pfSense', $result);
		$this->assertEquals('pfSense', $result['pfSense']['name']);
		$this->assertEquals('ONLINE',  $result['pfSense']['state']);
	}

	public function test_get_zpool_json_merges_capacity_from_list(): void {
		$statusPools = [
			'pfSense' => ['name' => 'pfSense', 'state' => 'ONLINE', 'error_count' => 0, 'vdevs' => []],
		];
		$listPools = [
			'pfSense' => ['properties' => $this->mirrorListProps],
		];
		$runner = $this->makeRunner($statusPools, $listPools);
		$result = get_zpool_json($runner);

		$this->assertEquals('6.50G', $result['pfSense']['size']);
		$this->assertEquals('1.29G', $result['pfSense']['alloc']);
		$this->assertEquals('5.21G', $result['pfSense']['free']);
		$this->assertEquals('2%',    $result['pfSense']['frag']);
		$this->assertEquals('19%',   $result['pfSense']['cap']);
		$this->assertEquals('1.00x', $result['pfSense']['dedup']);
	}

	public function test_get_zpool_json_capacity_defaults_when_list_fails(): void {
		$statusPools = [
			'pfSense' => ['name' => 'pfSense', 'state' => 'ONLINE', 'error_count' => 0, 'vdevs' => []],
		];
		$runner = $this->makeRunner($statusPools, [], listRet: 1);
		$result = get_zpool_json($runner);

		$this->assertEquals('-', $result['pfSense']['size']);
		$this->assertEquals('-', $result['pfSense']['alloc']);
		$this->assertEquals('-', $result['pfSense']['free']);
		$this->assertEquals('-', $result['pfSense']['frag']);
	}

	public function test_get_zpool_json_multiple_pools(): void {
		$statusPools = [
			'pfSense' => ['name' => 'pfSense', 'state' => 'ONLINE',   'error_count' => 0, 'vdevs' => []],
			'test'    => ['name' => 'test',    'state' => 'DEGRADED',  'error_count' => 3, 'vdevs' => []],
		];
		$runner = $this->makeRunner($statusPools, []);
		$result = get_zpool_json($runner);

		$this->assertCount(2, $result);
		$this->assertArrayHasKey('pfSense', $result);
		$this->assertArrayHasKey('test', $result);
		$this->assertEquals('DEGRADED', $result['test']['state']);
	}

	public function test_get_zpool_json_error_count_is_integer(): void {
		$statusPools = [
			'pfSense' => ['name' => 'pfSense', 'state' => 'ONLINE', 'error_count' => 0, 'vdevs' => []],
		];
		$runner = $this->makeRunner($statusPools, []);
		$result = get_zpool_json($runner);

		$this->assertIsInt($result['pfSense']['error_count']);
	}

	public function test_get_zpool_json_preserves_status_and_action_strings(): void {
		$statusPools = [
			'pfSense' => [
				'name'        => 'pfSense',
				'state'       => 'ONLINE',
				'status'      => "Some features are not enabled.\n\t",
				'action'      => "Enable all features using 'zpool upgrade'.\n\t",
				'error_count' => 0,
				'vdevs'       => [],
			],
		];
		$runner = $this->makeRunner($statusPools, []);
		$result = get_zpool_json($runner);

		$this->assertStringContainsString('features', $result['pfSense']['status']);
		$this->assertStringContainsString('upgrade',  $result['pfSense']['action']);
	}

	public function test_get_zpool_json_vdevs_tree_is_preserved(): void {
		$statusPools = [
			'pfSense' => [
				'name'        => 'pfSense',
				'state'       => 'ONLINE',
				'error_count' => 0,
				'vdevs'       => $this->mirrorPoolVdevs,
			],
		];
		$runner = $this->makeRunner($statusPools, []);
		$result = get_zpool_json($runner);

		$this->assertArrayHasKey('pfSense', $result['pfSense']['vdevs']);
		$this->assertArrayHasKey(
			'mirror-0',
			$result['pfSense']['vdevs']['pfSense']['vdevs']
		);
	}

	public function test_get_zpool_json_captures_spares(): void {
		$statusPools = [
			'tank' => [
				'name'        => 'tank',
				'state'       => 'ONLINE',
				'error_count' => 0,
				'vdevs'       => [],
				'spares'      => [
					'da2' => ['name' => 'da2', 'vdev_type' => 'disk', 'class' => 'spare', 'state' => 'AVAIL'],
				],
			],
		];
		$runner = $this->makeRunner($statusPools, []);
		$result = get_zpool_json($runner);

		$this->assertArrayHasKey('spares', $result['tank']);
		$this->assertArrayHasKey('da2', $result['tank']['spares']);
	}

	// -------------------------------------------------------------------------
	// zfs_status_create_detail_table()
	// -------------------------------------------------------------------------

	public function test_detail_table_empty_vdevs_returns_empty_string(): void {
		$pool = ['vdevs' => [], 'error_count' => 0];
		$html = zfs_status_create_detail_table($pool);
		$this->assertSame('', $html);
	}

	public function test_detail_table_mirror_pool_contains_expected_names(): void {
		$pool = ['vdevs' => $this->mirrorPoolVdevs];
		$html = zfs_status_create_detail_table($pool);

		$this->assertStringContainsString('pfSense',  $html);
		$this->assertStringContainsString('mirror-0', $html);
		$this->assertStringContainsString('da0p4',    $html);
		$this->assertStringContainsString('da1p4',    $html);
	}

	public function test_detail_table_degraded_state_uses_danger_class(): void {
		$pool = ['vdevs' => $this->degradedPoolVdevs];
		$html = zfs_status_create_detail_table($pool);

		$this->assertStringContainsString('text-danger', $html);
	}

	public function test_detail_table_online_state_uses_success_class(): void {
		$pool = ['vdevs' => $this->mirrorPoolVdevs];
		$html = zfs_status_create_detail_table($pool);

		$this->assertStringContainsString('text-success', $html);
	}

	public function test_detail_table_escapes_xss_in_device_name(): void {
		$pool = [
			'vdevs' => [
				'evil' => [
					'name'            => '<script>alert(1)</script>',
					'vdev_type'       => 'disk',
					'state'           => 'ONLINE',
					'read_errors'     => 0,
					'write_errors'    => 0,
					'checksum_errors' => 0,
				],
			],
		];
		$html = zfs_status_create_detail_table($pool);

		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);
	}

	public function test_detail_table_error_counts_rendered_as_integers(): void {
		$pool = ['vdevs' => $this->degradedPoolVdevs];
		$html = zfs_status_create_detail_table($pool);

		// Ensure numeric 3 appears as-is, not "3.0" or in scientific notation
		$this->assertMatchesRegularExpression('/<td>3<\/td>/', $html);
	}

	public function test_detail_table_rows_in_depth_first_order(): void {
		$pool = ['vdevs' => $this->mirrorPoolVdevs];
		$html = zfs_status_create_detail_table($pool);

		$posPfSense  = strpos($html, 'pfSense');
		$posMirror   = strpos($html, 'mirror-0');
		$posDa0p4    = strpos($html, 'da0p4');
		$posDa1p4    = strpos($html, 'da1p4');

		$this->assertLessThan($posMirror, $posPfSense);
		$this->assertLessThan($posDa0p4,  $posMirror);
		$this->assertLessThan($posDa1p4,  $posDa0p4);
	}

	public function test_detail_table_spares_renders_group_header(): void {
		$pool = [
			'vdevs'  => [],
			'spares' => [
				'da2' => ['name' => 'da2', 'state' => 'AVAIL'],
			],
		];
		$html = zfs_status_create_detail_table($pool);
		$this->assertStringContainsString('spares', $html);
	}

	public function test_detail_table_spares_renders_device_name_and_state(): void {
		$pool = [
			'vdevs'  => [],
			'spares' => [
				'da2' => ['name' => 'da2', 'state' => 'AVAIL'],
			],
		];
		$html = zfs_status_create_detail_table($pool);
		$this->assertStringContainsString('da2', $html);
		$this->assertStringContainsString('AVAIL', $html);
		$this->assertStringContainsString('text-success', $html);
	}

	public function test_detail_table_spares_appear_after_vdev_tree(): void {
		$pool = [
			'vdevs'  => $this->singleDiskPoolVdevs,
			'spares' => [
				'spare0' => ['name' => 'spare0', 'state' => 'AVAIL'],
			],
		];
		$html = zfs_status_create_detail_table($pool);
		$this->assertGreaterThan(strpos($html, 'da2'), strpos($html, 'spare0'));
	}

	public function test_detail_table_no_spares_key_renders_no_spare_section(): void {
		$pool = ['vdevs' => []];
		$html = zfs_status_create_detail_table($pool);
		$this->assertStringNotContainsString('spares', $html);
	}

	public function test_detail_table_inuse_spare_uses_warning_color(): void {
		$pool = [
			'vdevs'  => [],
			'spares' => [
				'da2' => ['name' => 'da2', 'state' => 'INUSE'],
			],
		];
		$html = zfs_status_create_detail_table($pool);
		$this->assertStringContainsString('text-warning', $html);
	}

	// -------------------------------------------------------------------------
	// zfs_status_create_pool_rows()
	// -------------------------------------------------------------------------

	public function test_pool_rows_contains_treegrid_class_with_pool_name(): void {
		$pools = [
			'pfSense' => [
				'name'  => 'pfSense',
				'state' => 'ONLINE',
				'size'  => '6.50G',
				'alloc' => '1.29G',
				'free'  => '5.21G',
				'frag'  => '2%',
				'vdevs' => [],
			],
		];
		$html = zfs_status_create_pool_rows($pools);
		$this->assertStringContainsString('treegrid-root-pfSense', $html);
	}

	public function test_pool_rows_state_has_health_color_span(): void {
		$pools = [
			'pfSense' => [
				'name'  => 'pfSense',
				'state' => 'ONLINE',
				'size'  => '6.50G',
				'alloc' => '1.29G',
				'free'  => '5.21G',
				'frag'  => '2%',
				'vdevs' => [],
			],
		];
		$html = zfs_status_create_pool_rows($pools);
		$this->assertStringContainsString('text-success', $html);
		$this->assertStringContainsString('ONLINE', $html);
	}

	public function test_pool_rows_capacity_values_appear_in_output(): void {
		$pools = [
			'pfSense' => [
				'name'  => 'pfSense',
				'state' => 'ONLINE',
				'size'  => '6.50G',
				'alloc' => '1.29G',
				'free'  => '5.21G',
				'frag'  => '2%',
				'vdevs' => [],
			],
		];
		$html = zfs_status_create_pool_rows($pools);
		$this->assertStringContainsString('6.50G', $html);
		$this->assertStringContainsString('1.29G', $html);
		$this->assertStringContainsString('5.21G', $html);
		$this->assertStringContainsString('2%',    $html);
	}

	public function test_pool_rows_escapes_xss_in_pool_name(): void {
		$pools = [
			'<img src=x>' => [
				'name'  => '<img src=x>',
				'state' => 'ONLINE',
				'size'  => '1G',
				'alloc' => '100M',
				'free'  => '900M',
				'frag'  => '0%',
				'vdevs' => [],
			],
		];
		$html = zfs_status_create_pool_rows($pools);
		$this->assertStringNotContainsString('<img src=x>', $html);
		$this->assertStringContainsString('&lt;img', $html);
	}

	public function test_pool_rows_degraded_pool_uses_warning_color(): void {
		$pools = [
			'tank' => [
				'name'  => 'tank',
				'state' => 'DEGRADED',
				'size'  => '7.50G',
				'alloc' => '100M',
				'free'  => '7.40G',
				'frag'  => '0%',
				'vdevs' => $this->degradedPoolVdevs,
			],
		];
		$html = zfs_status_create_pool_rows($pools);
		$this->assertStringContainsString('text-warning', $html);
	}

	public function test_pool_rows_embeds_detail_table(): void {
		$pools = [
			'pfSense' => [
				'name'  => 'pfSense',
				'state' => 'ONLINE',
				'size'  => '6.50G',
				'alloc' => '1.29G',
				'free'  => '5.21G',
				'frag'  => '2%',
				'vdevs' => $this->mirrorPoolVdevs,
			],
		];
		$html = zfs_status_create_pool_rows($pools);
		// Detail sub-table must appear inside the expansion row
		$this->assertStringContainsString('mirror-0', $html);
		$this->assertStringContainsString('da0p4',    $html);
	}

	// -------------------------------------------------------------------------
	// zfs_status_create_meta_rows()
	// -------------------------------------------------------------------------

	public function test_meta_rows_does_not_use_code_element(): void {
		$pool = ['status' => 'Something', 'action' => 'Do something'];
		$html = zfs_status_create_meta_rows($pool);
		$this->assertStringNotContainsString('<code', $html);
	}

	public function test_meta_rows_no_warning_icon_when_no_status_or_action(): void {
		$pool = [];
		$html = zfs_status_create_meta_rows($pool);
		$this->assertStringNotContainsString('fa-triangle-exclamation', $html);
	}

	public function test_meta_rows_status_message_shown_when_non_empty(): void {
		$pool = ['error_count' => 0, 'status' => 'Pool has unsupported features'];
		$html = zfs_status_create_meta_rows($pool);
		$this->assertStringContainsString('Pool has unsupported features', $html);
		$this->assertStringContainsString('fa-triangle-exclamation', $html);
	}

	public function test_meta_rows_blank_status_not_rendered(): void {
		$pool = ['error_count' => 0, 'status' => ''];
		$html = zfs_status_create_meta_rows($pool);
		$this->assertStringNotContainsString('fa-triangle-exclamation', $html);
	}

	public function test_meta_rows_action_message_shown_when_non_empty(): void {
		$pool = ['error_count' => 0, 'action' => 'Run zpool upgrade'];
		$html = zfs_status_create_meta_rows($pool);
		$this->assertStringContainsString('Run zpool upgrade', $html);
		$this->assertStringContainsString('text-muted', $html);
	}

	public function test_meta_rows_blank_action_not_rendered(): void {
		$pool = ['error_count' => 0, 'action' => ''];
		$html = zfs_status_create_meta_rows($pool);
		$this->assertStringNotContainsString('text-muted', $html);
	}

	// -------------------------------------------------------------------------
	// zfs_status_errors_row()
	// -------------------------------------------------------------------------

	public function test_errors_row_no_errors_shows_checkmark(): void {
		$html = zfs_status_errors_row(['error_count' => 0]);
		$this->assertStringContainsString('fa-circle-check text-success', $html);
	}

	public function test_errors_row_no_errors_shows_no_known_data_errors(): void {
		$html = zfs_status_errors_row(['error_count' => 0]);
		$this->assertStringContainsString('No known data errors', $html);
	}

	public function test_errors_row_no_errors_omits_warning_icon(): void {
		$html = zfs_status_errors_row(['error_count' => 0]);
		$this->assertStringNotContainsString('fa-triangle-exclamation', $html);
	}

	public function test_errors_row_with_errors_shows_warning_icon(): void {
		$html = zfs_status_errors_row(['error_count' => 3]);
		$this->assertStringContainsString('fa-triangle-exclamation', $html);
	}

	public function test_errors_row_with_errors_uses_danger_class(): void {
		$html = zfs_status_errors_row(['error_count' => 3]);
		$this->assertStringContainsString('text-danger', $html);
	}

	public function test_errors_row_does_not_use_code_element(): void {
		$html = zfs_status_errors_row(['error_count' => 3]);
		$this->assertStringNotContainsString('<code', $html);
	}
}
