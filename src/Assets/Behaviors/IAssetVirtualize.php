<?php

/**
 * IAssetVirtualize class
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors;

/**
 * IAssetVirtualize
 *
 * These are the methods required to do Asset Virtualization.
 *
 * {@see getVirtualFiles VirtualFiles} and {@see getOriginalFiles
 * OriginalFiles} are regular expression, and {@see getMapFromVirtual
 * MapFromVirtual} and {@see getMapToVirtual MapToVirtual} are regular
 * expression replacement values.  The replacement values may include regular
 * expression captured groups from the matching.
 *
 * VirtualFiles matches the file path to identify virtual files and the
 * regex replacement value is MapFromVirtual. The file path identified
 * with the VirtualFiles are replaced with MapFromVirtual and should
 * result in the "real" file path for validation.  See note 1 below.
 * Replacement is always performed.
 *
 * The OriginalFiles matches the "real" file path to identify
 * original assets and the regex replacement value is MapToVirtual.
 * The file path identified with the OriginalFiles are replaced with
 * MapToVirtual and should result in the virtual file path.  There must
 * be a map to re-virtualize the file to not publish under the original
 * asset file path.  Replacement is always performed.
 *
 * For example, VirtualFiles = "/\.thumb(.jpg)$/i" would use MapFromVirtual
 * = "${1}" to map virtual files ending in ".thumb.jpg" to their real files
 * ending in ".jpg".  Typically, if that thumb file doesn't exist, it would fail.
 * When virtualized, it will seem like the file is there without actually
 * being there.
 * More complex configurations could, eg., virtualize a virtual non-file asset
 * and would succeed.
 *
 * When a VirtualFile matches, the OriginalFiles regular expression is matched
 * on the validated file path and replaced with MapToVirtual.  This process must
 * revirtualize the "non-virtual" file path.
 *
 * In the example, OriginalFiles = "/\.jpg$/i" would use MapToVirtual
 * = ".thumb.jpg" to map matching file paths ending in ".jpg" to ".thumb.jpg".
 *
 * The MapToVirtual must change the file path. MapToVirtual does not need
 * to preserve the VirtualFiles matching pattern.
 *
 * Folders can be virtualized as well.  eg. VirtualFiles = "/\/virtualFolder\//i"
 * MapFromVirtual = "/realFolder/" and OriginalFiles = "/\/realFolder\//i"
 * MapToVirtual = "/virtualFolder/" (or MapToVirtual="/altFolder/")
 *
 * eg. VirtualFiles = "/\/fictitious\/drive\//i" MapFromVirtual = "./protected/Pages/driveFolder/"
 * OriginalFiles = "/^.*\/protected\/Pages\/driveFolder\//i" MapToVirtual = "/fictitious/drive/"
 *
 * Note 1: Normally the virtual file will map to a real file, but it
 * it could map to another virtual file that then maps to a generated asset.
 * Multiple IAssetVirtualize can be layered and chained.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see https://www.php.net/manual/en/function.preg-replace.php
 * @see https://www.regular-expressions.info
 */
interface IAssetVirtualize
{
	/**
	 * @return ?string Regex matching virtual files before validation.
	 */
	public function getVirtualFiles();

	/**
	 * @param string $value Regex matching virtual files before validation.
	 */
	public function setVirtualFiles($value);

	/**
	 * @return string Replacement value for the VirtualFiles Regex match.
	 */
	public function getMapFromVirtual();

	/**
	 * @param string $value Replacement value for the VirtualFiles Regex match.
	 */
	public function setMapFromVirtual($value);

	/**
	 * @return string Regex matching "real" files after validation.  This is
	 * always matched and replaced when VirtualFiles is a match.
	 */
	public function getOriginalFiles();

	/**
	 * @param string $value Regex matching "real" files after validation.
	 */
	public function setOriginalFiles($value);

	/**
	 * @return ?string Replacement value for the OriginalFiles Regex match.
	 */
	public function getMapToVirtual();

	/**
	 * @param string $value Replacement value for the OriginalFiles Regex match.
	 */
	public function setMapToVirtual($value);
}
