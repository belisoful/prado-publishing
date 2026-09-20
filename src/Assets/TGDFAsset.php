<?php

/**
 * TGDFAsset class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets;

use Prado\Util\Helpers\TBitHelper;

/**
 * TGDFAsset class
 *
 * This is the class for GD Font (GDF) assets.  It provides for conversion of GD
 * font files to the current machine format.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TGDFAsset extends TFileAsset
{
	/**
	 * Copies the file to the destination then ensures that the endian byte order of
	 * the GDF is in current machine format.
	 * @param string $src The source file path
	 * @param string $dst The destination real file path
	 * @return bool was the file placed
	 */
	protected function putFile(string $src, string $dst): bool
	{
		if (@copy($src, $dst)) {
			if ($handle = fopen($dst, "rb+")) {
				if (($data = fread($handle, 16)) !== false && strlen($data) === 16) {
					$v = unpack('L4', $data); //Lchars/LstartChar/Lwidth/Lheight
					if ($v && ($v['3'] >> 1) > 0x7FFF) { // shift possible negative bit.
						fseek($handle, 0);
						fwrite($handle, pack('L4', TBitHelper::flipEndianLong($v[1]), TBitHelper::flipEndianLong($v[2]), TBitHelper::flipEndianLong($v[3]), TBitHelper::flipEndianLong($v[4])));
					}
				}
				fclose($handle);
			}
			return true;
		}
		return false;
	}
}
