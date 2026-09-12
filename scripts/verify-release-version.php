<?php
/**
 * Release Version Consistency Verifier.
 *
 * Enforces tag and source version consistency:
 * - Compares target tag / input version against FULL_ELEMENTOR_MCP_VERSION in full-elementor-mcp.php
 * - Compares against Version: in full-elementor-mcp.php header
 * - Compares against Stable tag: in readme.txt
 * - Supports -rc* pre-release tags (sets is_prerelease=true, checks base version in readme.txt)
 *
 * Usage: php scripts/verify-release-version.php <tag-or-version>
 *
 * @package Safe_Elementor_MCP
 */

declare(strict_types=1);

$target_input = $argv[1] ?? getenv( 'RELEASE_VERSION' ) ?: getenv( 'GITHUB_REF_NAME' ) ?: '';
$target_input = trim( (string) $target_input );

if ( '' === $target_input ) {
	fwrite( STDERR, "Error: No release version or tag specified.\n" );
	fwrite( STDERR, "Usage: php scripts/verify-release-version.php <tag-or-version>\n" );
	exit( 1 );
}

// Strip leading 'v' if present (e.g. v1.8.0 -> 1.8.0, v1.8.0-rc1 -> 1.8.0-rc1)
$version_string = ltrim( $target_input, 'v' );

if ( ! preg_match( '/^(\d+\.\d+\.\d+)(-(?:rc|beta|alpha)\.?\d*)?$/i', $version_string, $matches ) ) {
	fwrite( STDERR, "Error: Invalid version format: '{$target_input}'. Expected SemVer (e.g. 1.8.0, v1.8.0, or v1.8.0-rc1).\n" );
	exit( 1 );
}

$base_version  = $matches[1];
$suffix        = $matches[2] ?? '';
$is_prerelease = ! empty( $suffix );

echo "Verifying release version consistency for '{$target_input}' (Base: {$base_version}, Prerelease: " . ( $is_prerelease ? 'yes' : 'no' ) . ")...\n";

$repo_root = dirname( __DIR__ );
$main_file = $repo_root . DIRECTORY_SEPARATOR . 'full-elementor-mcp.php';
$readme    = $repo_root . DIRECTORY_SEPARATOR . 'readme.txt';

if ( ! file_exists( $main_file ) ) {
	fwrite( STDERR, "Error: Main plugin file missing at {$main_file}\n" );
	exit( 1 );
}
if ( ! file_exists( $readme ) ) {
	fwrite( STDERR, "Error: readme.txt missing at {$readme}\n" );
	exit( 1 );
}

$main_content = file_get_contents( $main_file );
$readme_content = file_get_contents( $readme );

// 1. Check FULL_ELEMENTOR_MCP_VERSION constant
if ( ! preg_match( "/define\(\s*'FULL_ELEMENTOR_MCP_VERSION',\s*'([^']+)'\s*\);/", $main_content, $m_const ) ) {
	fwrite( STDERR, "Error: FULL_ELEMENTOR_MCP_VERSION constant not found in full-elementor-mcp.php\n" );
	exit( 1 );
}
$const_version = $m_const[1];

// 2. Check Version: in plugin header
if ( ! preg_match( '/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $main_content, $m_hdr ) ) {
	fwrite( STDERR, "Error: Version: header not found in full-elementor-mcp.php\n" );
	exit( 1 );
}
$header_version = trim( $m_hdr[1] );

// 3. Check Stable tag: in readme.txt
if ( ! preg_match( '/^Stable tag:\s*([^\r\n]+)/mi', $readme_content, $m_stable ) ) {
	fwrite( STDERR, "Error: Stable tag: not found in readme.txt\n" );
	exit( 1 );
}
$stable_tag = trim( $m_stable[1] );

$mismatches = array();

if ( $const_version !== $base_version ) {
	$mismatches[] = "FULL_ELEMENTOR_MCP_VERSION is '{$const_version}', expected '{$base_version}'";
}
if ( $header_version !== $base_version ) {
	$mismatches[] = "Plugin header Version is '{$header_version}', expected '{$base_version}'";
}
if ( $stable_tag !== $base_version ) {
	$mismatches[] = "readme.txt Stable tag is '{$stable_tag}', expected '{$base_version}'";
}

// 4. Check CHANGELOG.md entry
$changelog_file = $repo_root . DIRECTORY_SEPARATOR . 'CHANGELOG.md';
if ( file_exists( $changelog_file ) ) {
	$changelog_content = (string) file_get_contents( $changelog_file );
	if ( ! preg_match( '/##\s*\[?' . preg_quote( $base_version, '/' ) . '\]?/i', $changelog_content ) ) {
		$mismatches[] = "CHANGELOG.md does not contain an entry for version '{$base_version}'";
	} else {
		echo " [PASS] CHANGELOG.md entry exists for: {$base_version}\n";
	}
}

// 5. Check dist/manifest.json if present
$manifest_file = $repo_root . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'manifest.json';
if ( file_exists( $manifest_file ) ) {
	$manifest_data = json_decode( (string) file_get_contents( $manifest_file ), true );
	if ( isset( $manifest_data['version'] ) && $manifest_data['version'] !== $base_version ) {
		$mismatches[] = "dist/manifest.json version '{$manifest_data['version']}' != target '{$base_version}'";
	} else {
		echo " [PASS] dist/manifest.json version matches: {$base_version}\n";
	}
}

// 6. Check dist ZIP filename if present
$zip_artifact = $repo_root . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . "safe-elementor-mcp-{$base_version}.zip";
if ( file_exists( $zip_artifact ) ) {
	if ( filesize( $zip_artifact ) < 1000 ) {
		$mismatches[] = "dist/safe-elementor-mcp-{$base_version}.zip is corrupt or too small";
	} else {
		echo " [PASS] dist/safe-elementor-mcp-{$base_version}.zip exists and is valid\n";
	}
}

if ( ! empty( $mismatches ) ) {
	fwrite( STDERR, "\nFAILED: Version consistency check failed:\n" );
	foreach ( $mismatches as $err ) {
		fwrite( STDERR, " - {$err}\n" );
	}
	fwrite( STDERR, "\nRelease aborted. Codebase versions must strictly match the release target.\n" );
	exit( 1 );
}

echo " [PASS] FULL_ELEMENTOR_MCP_VERSION matches: {$const_version}\n";
echo " [PASS] Plugin header Version matches: {$header_version}\n";
echo " [PASS] readme.txt Stable tag matches: {$stable_tag}\n";
echo "\nAll authoritative version consistency checks passed successfully!\n";

// If in GitHub Actions, write output parameters
$github_output = getenv( 'GITHUB_OUTPUT' );
if ( $github_output && file_exists( $github_output ) ) {
	file_put_contents( $github_output, "version={$version_string}\n", FILE_APPEND );
	file_put_contents( $github_output, "base_version={$base_version}\n", FILE_APPEND );
	file_put_contents( $github_output, "is_prerelease=" . ( $is_prerelease ? 'true' : 'false' ) . "\n", FILE_APPEND );
	file_put_contents( $github_output, "draft=true\n", FILE_APPEND );
}

exit( 0 );
