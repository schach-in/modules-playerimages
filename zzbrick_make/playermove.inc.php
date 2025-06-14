<?php

/**
 * playerimages module
 * import images with IPTC field participation_id to media database
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/playerimages
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2020-2023, 2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */

function mod_playerimages_make_playermove() {
	wrap_setting('cache', false);
	$locked = wrap_lock('playerimages_move', 'sequential', wrap_setting('playerimages_max_run_sec') + 20);
	if ($locked) return wrap_quit(403, wrap_text('Player images moving is running.'));
	
	wrap_package_activate('mediadb');
	$page['text'] = 'success';
	$source_folder = wrap_setting('playerimages_final_path');
	
	wrap_include_files('zzform/hooks', 'mediadb'); // different module!
	wrap_include_files('zzbrick_request/object', 'mediadb');

	wrap_setting('import_user', true); // allow addition also for public users
	
	// 1. read file per file in folder
	$folder = wrap_setting('cms_dir').$source_folder;
	$files = scandir($folder);
	$data = [];
	$participation_ids = [];
	foreach ($files as $file) {
		if (str_starts_with($file, '.')) continue;

		$my_file = [
			'base_path' => '/..'.$source_folder,
			'filename' => substr($file, 0, strrpos($file, '.')),
			'extension' => substr($file, strrpos($file, '.') + 1),
			'filename_full' => $folder.'/'.$file,
			'filename_short' => $file,
		];
		$iptc = media_get_iptc_metadata([$my_file]);
		if (empty($iptc['Keywords'])) continue;
		if (!str_starts_with($iptc['Keywords']['value'], 'participation_id=')) continue;
		$my_file['participation_id'] = str_replace( 'participation_id=', '', $iptc['Keywords']['value']);
		$exif = exif_read_data($my_file['filename_full']);
		$date = explode(' ', $exif['DateTime']);
		$my_file['date'] = str_replace(':', '-', $date[0]);

		$participation_ids[] = $my_file['participation_id'];
		$data[] = $my_file;
	}
	if (!$data) {
		wrap_unlock('playerimages_move');
		return $page;
	}

	// get data from remote server
	mf_playerimages_db_connect_participations_db();
	$sql = 'SELECT participation_id, person_id
			, IFNULL(main_events.event_id, events.event_id) AS event_id
			, CONCAT(UPPER(contact_abbr), "/", IFNULL(main_events.identifier, events.identifier)) AS event_identifier
		FROM participations
		LEFT JOIN persons USING (contact_id)
		LEFT JOIN events USING (event_id)
		LEFT JOIN categories series
			ON events.series_category_id = series.category_id
		LEFT JOIN events main_events
			ON series.main_category_id = main_events.series_category_id
			AND IFNULL(events.event_year, YEAR(events.date_begin)) = IFNULL(main_events.event_year, YEAR(main_events.date_begin))
		LEFT JOIN websites
			ON websites.website_id = IFNULL(main_events.website_id, events.website_id)
		LEFT JOIN contacts
			ON websites.contact_id = contacts.contact_id
		WHERE participation_id IN (%s)';
	$sql = sprintf($sql, implode(',', $participation_ids));
	$participations = wrap_db_fetch($sql, 'participation_id');
	wrap_db_connection(false);
	wrap_db_connect();
	
	foreach ($data as $file) {
		if (time() > $_SERVER['REQUEST_TIME'] + wrap_setting('playerimages_max_run_sec')) {
			wrap_unlock('playerimages_move');
			return $page;
		}
		$on_pic = $participations[$file['participation_id']];

		$dates = mod_playerimages_make_playermove_dates($on_pic['event_id']);
		if (array_key_exists($file['date'], $dates)) {
			$parent_object_id = $dates[$file['date']];
		} else {
			$key = key($dates);
			if ($file['date'] > $key) {
				end($dates);
				$key = key($dates);
			}
			$parent_object_id = $dates[$key];
		}
		
		$values = [];
		$values['action'] = 'insert';
		$values['ids'] = ['objectrelations[0][parent_object_id]', 'objectrelations[0][relation_type_property_id]'];
		$values['GET']['where']['class_id'] = wrap_id('classes', 'photo');
		$values['FILES']['field_uploaded_files_0_image']['name']['file'] = $file['filename_short'];
		$values['FILES']['field_uploaded_files_0_image']['tmp_name']['file'] = $file['filename_full'];
		$values['FILES']['field_uploaded_files_0_image']['do_not_delete']['file'] = true;
		$values['POST']['path'] = $file['filename'];
		$values['POST']['objects-Title'][0]['title'] = $file['filename'];
		$values['POST']['objectrelations'][0]['parent_object_id'] = $parent_object_id;
		$values['POST']['objectrelations'][0]['relation_type_property_id'] = wrap_id('properties', 'relation_type/part');
		$ops = zzform_multi('objects.sync', $values);
		if (empty($ops['id'])) {
			wrap_error(wrap_text('Image was not imported').': File '
				.$file['filename_short'].' – Values: '.json_encode($values).' – Error: '.json_encode($ops), E_USER_WARNING
			);
			wrap_unlock('playerimages_move');
			return false;
		}
		$object_id = $ops['id'];
		
		$line = [
			'child_object_id' => $object_id,
			'parent_object_id' => mod_playerimages_make_playermove_description($on_pic['person_id']),
			'relation_type_property_id' => wrap_id('properties', 'relation_type/description')
		];
		$id = zzform_insert('objectrelations', $line);
		if (!$id) {
			wrap_unlock('playerimages_move');
			return false;
		}
		
		$line = [
			'child_object_id' => $object_id,
			'parent_object_id' => mod_playerimages_make_playermove_element($on_pic['event_identifier']),
			'relation_type_property_id' => wrap_id('properties', 'relation_type/element-inside')
		];
		$id = zzform_insert('objectrelations', $line);
		if (!$id) {
			wrap_unlock('playerimages_move');
			return false;
		}
		
		// tags
		$line = [
			'object_id' => $object_id,
			'tag_id' => wrap_setting('playerimages_tag_id')
		];
		$id = zzform_insert('objects-tags', $line);
		if (!$id) {
			wrap_unlock('playerimages_move');
			return false;
		}
	}
	wrap_setting('import_user', false);
	wrap_unlock('playerimages_move');
	return $page;
}

/**
 * get possible date folders per event
 *
 * @param int $event_id
 * @return array
 */
function mod_playerimages_make_playermove_dates($event_id) {
	static $dates = [];
	if (!empty($dates[$event_id])) return $dates[$event_id];

	$sql = 'SELECT SUBSTRING_INDEX(foreign_key, "-", -3) AS date, object_id
		FROM objects
		WHERE foreign_key LIKE "%s-%%"
		AND foreign_source_id = %d';
	$sql = sprintf($sql
		, $event_id
		, wrap_setting('playerimages_foreign_source_id_days')
	);
	$dates[$event_id] = wrap_db_fetch($sql, 'object_id', 'key/value');
	return $dates[$event_id];
}

/**
 * get element object_id
 *
 * @param string $identifier
 * @return int
 */
function mod_playerimages_make_playermove_element($identifier) {
	static $object_id = [];
	if (!empty($object_id[$identifier])) return $object_id[$identifier];

	$sql = 'SELECT object_id
		FROM objects
		WHERE identifier = "%s/%s"';
	$sql = sprintf($sql, $identifier, wrap_setting('playerimages_path'));
	$object_id[$identifier] = wrap_db_fetch($sql, '', 'single value');
	return $object_id[$identifier];
}

/**
 * get element object_id
 *
 * @param string $identifier
 * @return int
 */
function mod_playerimages_make_playermove_description($person_id) {
	static $object_id = [];
	if (!empty($object_id[$person_id])) return $object_id[$person_id];

	$sql = 'SELECT object_id
		FROM objects
		WHERE foreign_key = %d
		AND foreign_source_id = %d';
	$sql = sprintf($sql
		, $person_id
		, wrap_setting('playerimages_foreign_source_id_persons')
	);
	$object_id[$person_id] = wrap_db_fetch($sql, '', 'single value');
	return $object_id[$person_id];
}
