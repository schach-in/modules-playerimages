<?php

/**
 * playerimages module
 * tag images from QR code
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/playerimages
 *
 * @author Erik Kothe <kontakt@erikkothe.de>
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2020-2022 Erik Kothe
 * @copyright Copyright © 2022-2023 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function mod_playerimages_make_playertag() {
	wrap_setting('cache', false);
	ini_set('max_execution_time', wrap_setting('playerimages_max_run_sec') + 20);

	$locked = wrap_lock('playerimages_tag', 'sequential', wrap_setting('playerimages_max_run_sec') + 20);
	if ($locked) return wrap_quit(403, wrap_text('Player tagging is running.'));

	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Common/customFunctions.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Common/DetectorResult.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Common/DecoderResult.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Common/Detector/MathUtils.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Common/PerspectiveTransform.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Common/GridSampler.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Common/DefaultGridSampler.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Common/BitSource.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Common/CharacterSetECI.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/ResultPoint.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Result.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Qrcode/Detector/FinderPatternInfo.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Qrcode/Detector/FinderPatternFinder.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Qrcode/Detector/FinderPattern.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Qrcode/Detector/AlignmentPatternFinder.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Qrcode/Detector/AlignmentPattern.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Qrcode/Decoder/BitMatrixParser.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Qrcode/Decoder/Decoder.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Qrcode/Decoder/Mode.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Qrcode/Decoder/DecodedBitStreamParser.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Qrcode/Decoder/DataBlock.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Qrcode/Decoder/DataMask.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Qrcode/Decoder/Version.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Qrcode/Decoder/ErrorCorrectionLevel.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Qrcode/Decoder/FormatInformation.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Qrcode/Detector/Detector.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/BinaryBitmap.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Binarizer.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Common/GlobalHistogramBinarizer.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Common/BitMatrix.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Common/HybridBinarizer.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/LuminanceSource.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/GDLuminanceSource.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/IMagickLuminanceSource.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Reader.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/ReaderException.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/ChecksumException.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/FormatException.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/NotFoundException.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Common/Reedsolomon/GenericGFPoly.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Common/Reedsolomon/GenericGF.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Common/Reedsolomon/ReedSolomonDecoder.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Common/Reedsolomon/ReedSolomonException.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/Qrcode/QRCodeReader.php';
	require_once wrap_setting('lib').'/php-qrcode-detector-decoder/lib/QrReader.php';

	$folders['input'] = wrap_setting('cms_dir').wrap_setting('playerimages_incoming_path');
	$folders['final'] = wrap_setting('cms_dir').wrap_setting('playerimages_final_path');
	$folders['error'] = wrap_setting('cms_dir').wrap_setting('playerimages_error_path');
	foreach ($folders as $index => $folder)
	if (!is_dir($folder)) {
		wrap_unlock('playerimages_tag');
		wrap_error(sprintf('%s folder %s does not exist', ucfirst($key), $folder), E_USER_ERROR);
	}

	$i = 1;
	foreach (scandir($folders['input']) as $image) {
		if($image == '.' || $image == '..') continue;

		$i++;
		// Prüfung ob es das Teilnehmerbild oder das QR-Code-Bild ist.
		if ($i%2 == 0) {
			// Bild wird für den nächsten Durchgang zwischengespeichert
			$face_image = $image;
			continue;
		}
		// Falls Dateigröße über n ist wird das Bild verkleinert
		if (filesize($folders['input'].'/'.$face_image) > wrap_setting('playerimages_shrink_above_bytes')){
			$imagick = new Imagick(realpath($folders['input'].'/'.$face_image));
			$imagick->scaleImage(1000, 1000, 1);
			$imagick->writeImage($folders['input'].'/'.$face_image);
		}

		try {
			$qrcode = new \Zxing\QrReader($folders['input'].'/'.$face_image);
			$text_array = explode("/", $qrcode->text());
			$participation_id = array_pop($text_array);

			if ($participation_id != '') {
				// Wenn ein QR-Code erkannt wurde, wird das Bild umbenannt und in final gespeichert
				wrap_log(sprintf('Match found: face %s, code %s = ID %d', $face_image, $image, $participation_id));
				rename($folders['input'].'/'.$image, $folders['final'].'/'.$image);
				chown($folders['final'].'/'.$image, wrap_setting('playerimages_server_user'));
				chgrp($folders['final'].'/'.$image, wrap_setting('playerimages_server_group'));
				chmod($folders['final'].'/'.$image, 0775);
				// Setzt die Teilnahme-Nummer in die Meta-Daten
				mf_playerimages_set_iptc($folders['final'].'/'.$image, "participation_id=".$participation_id);
				unlink($folders['input'].'/'.$face_image);
			} else {
				// Wenn kein QR-Code erkannt wurde, werden die Bilder in error/ verschoben
				wrap_log(sprintf('No QR-Code found: face %s, code %s', $face_image, $image));
				rename($folders['input'].'/'.$face_image, $folders['error'].'/'.$face_image);
				rename($folders['input'].'/'.$image, $folders['error'].'/'.$image);
			}
			unset($qrcode);
		} catch(Exception $e) {
			// falls eine Exception passiert, wird das Bild in error/ verschoben
			wrap_log(sprintf('An exception stopped from processing: face %s, code %s', $face_image, $image));
			rename($folders['input'].'/'.$face_image, $folders['error'].'/'.$face_image);
			rename($folders['input'].'/'.$image, $folders['error'].'/'.$image);
			unset($qrcode);
		}
	}

	$page['text'] = 'success<br><br>'.nl2br(file_get_contents(wrap_setting('log_dir').'/playerimages.log'));

	wrap_unlock('playerimages_tag');
	return $page;
}
