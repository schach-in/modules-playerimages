<?php

/**
 * playerimages module
 * module functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/playerimages
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2020-2022 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * connect to event database
 *
 */
function mf_playerimages_db_connect_participations_db() {
	global $zz_conf;
	global $zz_setting;

	mysqli_close($zz_conf['db_connection']);
	$filename = $zz_setting['inc'].'/custom/zzwrap_sql/pwd-extern.json';
	$db = json_decode(file_get_contents($filename), true);
	$zz_conf['db_connection'] = mysqli_connect($db['db_host'], $db['db_user'], $db['db_pwd'], $db['db_name']);

	if (!$zz_conf['db_connection']) {
		wrap_error('Unable to establish database connection to main server.', E_USER_ERROR);
	}
}

function mf_playerimages_set_iptc($image, $keywords){
	$iptc = [
		'2#025' => $keywords
	];
	$data = '';
	foreach($iptc as $tag => $string) {
		$tag = substr($tag, 2);
		$data .= mf_playerimages_iptc_make_tag(2, $tag, $string);
	}
	$content = iptcembed($data, $image);
	$fp = fopen($image, "wb");
	fwrite($fp, $content);
	fclose($fp);
}

function mf_playerimages_iptc_make_tag($rec, $data, $value){
	$length = strlen($value);
	$retval = chr(0x1C) . chr($rec) . chr($data);

	if ($length < 0x8000) {
		$retval .= chr($length >> 8) . chr($length & 0xFF);
	} else {
		$retval .= chr(0x80) .
			chr(0x04) .
			chr(($length >> 24) & 0xFF) .
			chr(($length >> 16) & 0xFF) .
			chr(($length >> 8) & 0xFF) .
			chr($length & 0xFF);
	}
	return $retval . $value;
}
