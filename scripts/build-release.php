<?php
/**
 * Release Packaging Tool for Safe Elementor MCP.
 *
 * Generates a production-ready, deterministic release zip artifact:
 * - Validates PHP syntax of all distributable files
 * - Verifies version consistency across plugin header, constants, and readme.txt
 * - Performs pre-packaging secret and credential scanning
 * - Ensures single-root directory structure: safe-elementor-mcp/
 * - Excludes dev, test, script, and VCS files
 * - Computes SHA-256 checksums and generates release manifest.json
 * - Performs post-build archive verification
 *
 * Usage: php scripts/build-release.php
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

if ( php_sapi_name() !== 'cli' ) {
	fwrite( STDERR, "Error: This script must be run from the command line.\n" );
	exit( 1 );
}

$root_dir = dirname( __DIR__ );
$dist_dir = $root_dir . DIRECTORY_SEPARATOR . 'dist';

echo "=======================================================\n";
echo " Safe Elementor MCP — Release Packaging Tool\n";
echo "=======================================================\n\n";

// 1. Check required extensions
$required_exts = array( 'zip', 'json', 'hash' );
foreach ( $required_exts as $ext ) {
	if ( ! extension_loaded( $ext ) ) {
		fwrite( STDERR, "FATAL: Required PHP extension '{$ext}' is not loaded.\n" );
		exit( 1 );
	}
}

// 2. Version Consistency Verification
echo "[1/6] Verifying version consistency...\n";
$main_file = $root_dir . DIRECTORY_SEPARATOR . 'full-elementor-mcp.php';
if ( ! file_exists( $main_file ) ) {
	fwrite( STDERR, "FATAL: Main plugin file not found: {$main_file}\n" );
	exit( 1 );
}

$main_content = (string) file_get_contents( $main_file );

// Plugin header version
if ( ! preg_match( '/\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+[a-zA-Z0-9\.\-]*)/i', $main_content, $m_hdr ) ) {
	fwrite( STDERR, "FATAL: Could not extract Version from plugin header in full-elementor-mcp.php\n" );
	exit( 1 );
}
$header_version = trim( $m_hdr[1] );

// Constant version
if ( ! preg_match( '/define\(\s*[\'"]FULL_ELEMENTOR_MCP_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/', $main_content, $m_const ) ) {
	fwrite( STDERR, "FATAL: Could not extract FULL_ELEMENTOR_MCP_VERSION constant\n" );
	exit( 1 );
}
$const_version = trim( $m_const[1] );

if ( $header_version !== $const_version ) {
	fwrite( STDERR, "FATAL: Version mismatch: Header '{$header_version}' != Constant '{$const_version}'\n" );
	exit( 1 );
}

// Readme.txt stable tag
$readme_file = $root_dir . DIRECTORY_SEPARATOR . 'readme.txt';
if ( file_exists( $readme_file ) ) {
	$readme_content = (string) file_get_contents( $readme_file );
	if ( preg_match( '/Stable tag:\s*([0-9]+\.[0-9]+\.[0-9]+[a-zA-Z0-9\.\-]*)/i', $readme_content, $m_readme ) ) {
		$readme_version = trim( $m_readme[1] );
		if ( $readme_version !== $header_version ) {
			fwrite( STDERR, "FATAL: Version mismatch: readme.txt Stable tag '{$readme_version}' != Plugin '{$header_version}'\n" );
			exit( 1 );
		}
	}
}

$version = $header_version;
echo "      Authoritative release version: {$version}\n";

// 3. Collect distributable files and scan for secrets/banned artifacts
echo "[2/6] Collecting files and scanning for secrets/banned items...\n";

$banned_dir_prefixes = array(
	'.git',
	'.github',
	'.vscode',
	'tests',
	'scripts',
	'scratch',
	'tmp',
	'dist',
	'node_modules',
	'vendor',
);

$banned_file_patterns = array(
	'/\.git.*$/i',
	'/\.DS_Store$/i',
	'/Thumbs\.db$/i',
	'/desktop\.ini$/i',
	'/\.env.*$/i',
	'/\.log$/i',
	'/\.bak$/i',
	'/\.swp$/i',
	'/\.swo$/i',
	'/\.tmp$/i',
	'/composer\.lock$/i',
	'/package-lock\.json$/i',
);

$secret_patterns = array(
	'Private Key'      => '/-----BEGIN (?:[A-Z0-9_-]+ )?PRIVATE KEY-----/',
	'AWS Access Key'   => '/\bAKIA[0-9A-Z]{16}\b/',
	'GitHub Token'     => '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9_]{36}\b/',
	'Google API Key'   => '/\bAIza[0-9A-Za-z-_]{35}\b/',
	'Hardcoded Bearer' => '/\bbearer\s+[a-zA-Z0-9_\-\.]{30,}\b/i',
);

$files_to_package = array(); // relative_path => full_path
$secret_findings  = array();
$php_files_to_lint = array();

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

foreach ( $iterator as $item ) {
	if ( ! $item->isFile() ) {
		continue;
	}

	$full_path = $item->getPathname();
	$rel_path  = str_replace( $root_dir . DIRECTORY_SEPARATOR, '', $full_path );
	$norm_rel  = str_replace( '\\', '/', $rel_path );

	// Check directory exclusion
	$excluded = false;
	foreach ( $banned_dir_prefixes as $banned_dir ) {
		if ( $norm_rel === $banned_dir || str_starts_with( $norm_rel, $banned_dir . '/' ) ) {
			$excluded = true;
			break;
		}
	}
	if ( $excluded ) {
		continue;
	}

	// Check file pattern exclusion
	$filename = $item->getFilename();
	foreach ( $banned_file_patterns as $pat ) {
		if ( preg_match( $pat, $filename ) ) {
			$excluded = true;
			break;
		}
	}
	if ( $excluded ) {
		continue;
	}

	// Scan content for secrets and suspicious credentials
	$content = (string) file_get_contents( $full_path );
	foreach ( $secret_patterns as $desc => $pat ) {
		if ( preg_match( $pat, $content ) ) {
			$secret_findings[] = "Potential {$desc} in {$norm_rel}";
		}
	}

	if ( str_ends_with( strtolower( $filename ), '.php' ) ) {
		$php_files_to_lint[] = $full_path;
	}

	$files_to_package[ $norm_rel ] = $full_path;
}

if ( ! empty( $secret_findings ) ) {
	fwrite( STDERR, "FATAL: Secret scan found potential secrets:\n" );
	foreach ( $secret_findings as $finding ) {
		fwrite( STDERR, "  - {$finding}\n" );
	}
	exit( 1 );
}

echo "      Found " . count( $files_to_package ) . " distributable files. Secret scan clean.\n";

// 4. Lint all PHP files to be packaged
echo "[3/6] Linting all PHP files for syntax errors...\n";
foreach ( $php_files_to_lint as $php_file ) {
	$cmd = 'php -l ' . escapeshellarg( $php_file );
	exec( $cmd, $lint_output, $lint_code );
	if ( 0 !== $lint_code ) {
		fwrite( STDERR, "FATAL: Syntax error in {$php_file}:\n" . implode( "\n", $lint_output ) . "\n" );
		exit( 1 );
	}
}
echo "      All " . count( $php_files_to_lint ) . " PHP files passed syntax check.\n";

// 5. Create Dist Directory and Build ZIP
echo "[4/6] Packaging ZIP artifact with backward-compatible root directory 'full-elementor-mcp/'...\n";
if ( ! is_dir( $dist_dir ) ) {
	if ( ! mkdir( $dist_dir, 0755, true ) ) {
		fwrite( STDERR, "FATAL: Failed to create dist directory: {$dist_dir}\n" );
		exit( 1 );
	}
}

$zip_filename   = "safe-elementor-mcp-{$version}.zip";
$zip_path       = $dist_dir . DIRECTORY_SEPARATOR . $zip_filename;

foreach ( glob( $dist_dir . DIRECTORY_SEPARATOR . '*.zip' ) as $old_zip ) {
	unlink( $old_zip );
}
foreach ( glob( $dist_dir . DIRECTORY_SEPARATOR . '*.sha256' ) as $old_sha ) {
	unlink( $old_sha );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "FATAL: Could not open ZIP archive for writing: {$zip_path}\n" );
	exit( 1 );
}

$manifest_files = array();
$single_root    = 'full-elementor-mcp/';

// Sort files alphabetically for deterministic packaging
ksort( $files_to_package );

foreach ( $files_to_package as $norm_rel => $full_path ) {
	$zip_entry_name = $single_root . $norm_rel;
	$zip->addFile( $full_path, $zip_entry_name );

	$file_bytes = (int) filesize( $full_path );
	$file_sha   = hash_file( 'sha256', $full_path );
	$manifest_files[] = array(
		'path'   => $zip_entry_name,
		'size'   => $file_bytes,
		'sha256' => $file_sha,
	);
}

$zip->close();

echo "      Archive created: {$zip_filename} (" . round( filesize( $zip_path ) / 1024, 2 ) . " KB)\n";

// 6. Post-build Archive Verification
echo "[5/6] Verifying packaged archive integrity...\n";
$verify_zip = new ZipArchive();
if ( true !== $verify_zip->open( $zip_path, ZipArchive::RDONLY ) ) {
	fwrite( STDERR, "FATAL: Cannot read generated zip archive: {$zip_path}\n" );
	exit( 1 );
}

$zip_entry_count = $verify_zip->numFiles;
if ( $zip_entry_count !== count( $files_to_package ) ) {
	fwrite( STDERR, "FATAL: Entry count mismatch in zip: {$zip_entry_count} != " . count( $files_to_package ) . "\n" );
	exit( 1 );
}

$mandatory_entries = array(
	'full-elementor-mcp/full-elementor-mcp.php',
	'full-elementor-mcp/uninstall.php',
	'full-elementor-mcp/readme.txt',
	'full-elementor-mcp/LICENSE',
	'full-elementor-mcp/includes/class-compatibility-checker.php',
	'full-elementor-mcp/includes/safety/class-database-installer.php',
);

for ( $i = 0; $i < $zip_entry_count; $i++ ) {
	$stat = $verify_zip->statIndex( $i );
	$name = $stat['name'];

	// Invariant 1: Must start with full-elementor-mcp/
	if ( ! str_starts_with( $name, $single_root ) ) {
		fwrite( STDERR, "FATAL: File {$name} violates single-root requirement (must start with {$single_root})\n" );
		exit( 1 );
	}

	// Invariant 2: No forbidden patterns
	foreach ( $banned_dir_prefixes as $b_dir ) {
		if ( str_starts_with( $name, $single_root . $b_dir . '/' ) ) {
			fwrite( STDERR, "FATAL: Excluded folder '{$b_dir}' found inside zip: {$name}\n" );
			exit( 1 );
		}
	}
}

foreach ( $mandatory_entries as $mandatory ) {
	if ( false === $verify_zip->locateName( $mandatory ) ) {
		fwrite( STDERR, "FATAL: Mandatory entry missing from zip: {$mandatory}\n" );
		exit( 1 );
	}
}
$verify_zip->close();
echo "      Archive structure verified (single-root, mandatory files present, no forbidden paths).\n";

// 7. Checksums & Manifest Generation
echo "[6/6] Generating checksums and release manifest...\n";
$zip_sha256 = hash_file( 'sha256', $zip_path );
$sha256_file = $dist_dir . DIRECTORY_SEPARATOR . "{$zip_filename}.sha256";
file_put_contents( $sha256_file, "{$zip_sha256}  {$zip_filename}\n" );

$manifest = array(
	'product_name'        => 'Safe Elementor MCP',
	'slug'                => 'full-elementor-mcp',
	'plugin_basename'     => 'full-elementor-mcp/full-elementor-mcp.php',
	'version'             => $version,
	'zip_filename'        => $zip_filename,
	'zip_sha256'          => $zip_sha256,
	'zip_size_bytes'      => filesize( $zip_path ),
	'file_count'          => count( $manifest_files ),
	'build_timestamp_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
	'php_min_version'     => '8.0',
	'wp_min_version'      => '6.9',
	'files'               => $manifest_files,
);

$manifest_path = $dist_dir . DIRECTORY_SEPARATOR . 'manifest.json';
file_put_contents( $manifest_path, json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

echo "      SHA-256: {$zip_sha256}\n";
echo "      Manifest saved to: dist/manifest.json\n\n";

echo "=======================================================\n";
echo " BUILD SUCCESSFUL: {$zip_filename}\n";
echo "=======================================================\n";
exit( 0 );
