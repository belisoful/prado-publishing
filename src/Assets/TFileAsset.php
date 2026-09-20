<?php

/**
 * TFileAsset class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets;

/**
 * TFileAsset class
 *
 * This is the base class for system file based assets.  It provides file validation,
 * file modification dates, and file and directory publishing.  The file can also
 * be virtualized with a second constructor parameter.
 *
 * Directories are handled by adding a DIRECTORY_SEPARATOR to the end, which is
 * stripped off in the final publish phase.  The trailing DIRECTORY_SEPARATOR internally
 * indicates to publish the directory rather than the file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TFileAsset extends TAsset
{
	/**
	 * This is a real physical asset in the host's file system.
	 * @return bool Real files are not virtual, returns false.
	 */
	public function getIsVirtual(): bool
	{
		return false;
	}

	/**
	 * A file asset copies its source unchanged, unless a subclass converts it in
	 * {@see putFile} or an enabled behavior takes over writing with `dyWriteAsset`.
	 * @return bool whether the asset publishes its source unchanged.
	 */
	public function getIsPassThrough(): bool
	{
		if ((new \ReflectionMethod($this, 'putFile'))->getDeclaringClass()->getName() !== self::class) {
			return false;
		}
		foreach ($this->getBehaviors() as $behavior) {
			if ($behavior->getEnabled() && method_exists($behavior, 'dyWriteAsset')) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Puts the file from the {@see getAssetOriginalFilePath AssetOriginalFilePath} to
	 * the $filepath.
	 * @param string $filepath The destination file path to write the file into.
	 * @return bool Was the file placed.
	 */
	public function writeAsset(string $filepath): bool
	{
		return $this->putFile($this->getAssetOriginalFilePath(), $filepath);
	}

	/**
	 * Copies the file to the destination.  Override this method to write your own,
	 * eg. GDF conversion, Database, stored file.
	 * @param string $src The source file path.
	 * @param string $dst The destination real file path
	 * @return bool Was the file placed.
	 */
	protected function putFile(string $src, string $dst): bool
	{
		return @copy($src, $dst);
	}

	/**
	 * Validates a path.  Override this method to write your validation method, eg
	 * for database storage, for Tar to validate both the MD5 and tar file.
	 * @param string $filepath The source file path.
	 * @return false|string The validated path or false if invalid.
	 */
	public function validatePath(string $filepath)
	{
		return realpath($filepath);
	}

	/**
	 * @param string $path The path to check.
	 * @return bool Whether or not the path is a directory.
	 */
	protected function isDirectory(string $path): bool
	{
		return is_dir($path);
	}

	/**
	 * Provides the modification time for the path.
	 * @param string $path The file to get the modification time.
	 * @return false|int The modification time, in unix time, of the specified path,
	 *   false if no file/directory.
	 */
	protected function modificationTime($path)
	{
		return @filemtime($path);
	}
}
