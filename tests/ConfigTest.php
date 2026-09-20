<?php

/**
 * ConfigTest class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * ConfigTest class.
 *
 * Checks the declarative files in `config/` against the code they describe, so the class map
 * cannot drift from `src/` as classes are added or renamed, so every message key the package
 * raises is defined somewhere, and so a message added to `errorMessages.txt` cannot be one the
 * framework already defines and would override.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class ConfigTest extends TestCase
{
	private const CONFIG_DIR = __DIR__ . '/../config';

	private const SRC_DIR = __DIR__ . '/../src';

	/**
	 * @return array<string, string> The class map, short name to fully qualified name.
	 */
	private static function classMap(): array
	{
		$json = file_get_contents(self::CONFIG_DIR . '/classMap.json');
		self::assertIsString($json, 'config/classMap.json is readable.');
		$map = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($map);
		return $map;
	}

	/**
	 * @return array<string, string> The source files in src/, keyed by path relative to src/.
	 *   Dot files are not package classes.
	 */
	private static function sourceFiles(): array
	{
		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC_DIR, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if ($file->getExtension() === 'php' && strncmp($file->getFilename(), '.', 1) !== 0) {
				$files[substr($file->getPathname(), strlen(self::SRC_DIR) + 1)] = $file->getPathname();
			}
		}
		ksort($files);
		return $files;
	}

	public function testTheClassMapCoversEveryClassInSrcAndNothingElse(): void
	{
		$names = [];
		foreach (array_keys(self::sourceFiles()) as $relative) {
			$names[] = basename($relative, '.php');
		}
		sort($names, SORT_STRING);
		self::assertSame($names, array_keys(self::classMap()), 'The class map lists exactly the classes in src/, in order.');
	}

	/**
	 * The PSR-4 root is `Prado\Web\` on `src/`, so a fully qualified name fixes the file path.
	 */
	public function testEveryMappedNameResolvesAtItsPsr4Path(): void
	{
		$files = self::sourceFiles();
		foreach (self::classMap() as $short => $fqn) {
			self::assertTrue(class_exists($fqn) || interface_exists($fqn) || trait_exists($fqn) || enum_exists($fqn), "{$fqn} is autoloadable.");
			self::assertSame($short, substr($fqn, strrpos($fqn, '\\') + 1), 'The short name is the class basename.');
			self::assertStringStartsWith('Prado\\Web\\', $fqn, 'The map holds fully qualified names, not escaped fragments.');
			$relative = str_replace('\\', '/', substr($fqn, strlen('Prado\\Web\\'))) . '.php';
			self::assertArrayHasKey($relative, $files, "{$fqn} lives at src/{$relative}.");
		}
	}

	/**
	 * The framework's own messages.txt is consulted last and wins, so a key restated here would
	 * never be read.  A message file that shadows a core key is dead weight and a trap.
	 */
	public function testNoMessageRestatesAKeyTheFrameworkAlreadyDefines(): void
	{
		$ours = $this->parseMessages(self::CONFIG_DIR . '/errorMessages.txt');
		$core = $this->coreMessages();

		self::assertNotEmpty($ours, 'The package message file defines keys.');
		self::assertNotEmpty($core, 'The framework message file was located.');
		self::assertSame([], array_values(array_intersect(array_keys($ours), array_keys($core))), 'No key here is one the framework already defines.');
	}

	/**
	 * Every key raised as `new T...Exception('key', ...)` in src/ is defined by this package or
	 * by the framework, and every key this package defines appears in the source (some are
	 * passed to a helper that raises them).
	 */
	public function testEveryRaisedKeyIsDefinedAndEveryDefinedKeyIsRaised(): void
	{
		$raised = [];
		foreach (self::sourceFiles() as $file) {
			preg_match_all('/new\s+\\\\?(?:[A-Za-z_\\\\]+\\\\)?T[A-Za-z]*Exception\(\s*\'([a-z0-9_]+)\'/', (string) file_get_contents($file), $matches);
			array_push($raised, ...$matches[1]);
		}
		$raised = array_values(array_unique($raised));
		sort($raised);
		$ours = $this->parseMessages(self::CONFIG_DIR . '/errorMessages.txt');
		$core = $this->coreMessages();

		$source = '';
		foreach (self::sourceFiles() as $file) {
			$source .= file_get_contents($file);
		}
		$unused = array_filter(array_keys($ours), fn ($key) => !str_contains($source, "'" . $key . "'"));

		self::assertNotEmpty($raised, 'The source raises message keys.');
		self::assertSame([], array_values(array_diff($raised, array_keys($ours), array_keys($core))), 'Every raised key has a message.');
		self::assertSame([], array_values($unused), 'Every defined key is used in the source.');
	}

	/**
	 * @return array<string, string> The framework's key to message map.
	 */
	private function coreMessages(): array
	{
		return $this->parseMessages(dirname((new \ReflectionClass(\Prado\Prado::class))->getFileName()) . '/Exceptions/messages/messages.txt');
	}

	/**
	 * Parses a message file the way `TException::translateErrorMessage()` does.
	 * @param string $file The message file path.
	 * @return array<string, string> The key to message map.
	 */
	private function parseMessages(string $file): array
	{
		$messages = [];
		foreach ((array) file($file) as $line) {
			$line = trim((string) $line);
			if ($line === '' || strncmp($line, '#', 1) === 0 || strncmp($line, ';', 1) === 0) {
				continue;
			}
			if (strpos($line, '=') !== false) {
				[$code, $message] = explode('=', $line, 2);
				$messages[trim($code)] = trim($message);
			}
		}
		return $messages;
	}
}
