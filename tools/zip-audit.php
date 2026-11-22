<?php
/**
 * Keepmerge release ZIP audit. Development tool, never shipped.
 *
 * Usage: php tools/zip-audit.php dist/keepmerge-push-staging-to-live.zip
 * Exit code 0 = clean, 1 = findings, 2 = usage error.
 */

$slug = 'keepmerge-push-staging-to-live';
$zip  = isset( $argv[1] ) ? $argv[1] : '';
if ( '' === $zip || ! is_file( $zip ) ) {
	fwrite( STDERR, "usage: php zip-audit.php <zip>\n" );
	exit( 2 );
}

$z = new ZipArchive();
if ( true !== $z->open( $zip ) ) {
	fwrite( STDERR, "cannot open $zip\n" );
	exit( 2 );
}

$findings = array();
$bytes    = 0;
$names    = array();
$blocked_dirs = array( '.git', '.github', 'tests', 'test', 'docs', 'env', 'dev', 'tools', 'release', 'node_modules', 'vendor', '__macosx' );

for ( $i = 0; $i < $z->numFiles; $i++ ) {
	$stat  = $z->statIndex( $i );
	$name  = $stat['name'];
	$bytes += (int) $stat['size'];
	$names[] = $name;

	if ( false !== strpos( $name, '\\' ) ) {
		$findings[] = "backslash in entry name: $name";
	}
	if ( 0 !== strpos( $name, $slug . '/' ) ) {
		$findings[] = "entry outside $slug/: $name";
		continue;
	}
	$rel      = substr( $name, strlen( $slug ) + 1 );
	$segments = array_values( array_filter( explode( '/', $rel ), 'strlen' ) );
	foreach ( $segments as $seg ) {
		if ( '.' === $seg[0] ) {
			$findings[] = "dotfile or dot-folder: $name";
		}
		if ( in_array( strtolower( $seg ), $blocked_dirs, true ) ) {
			$findings[] = "development folder: $name";
		}
	}
	if ( '' === $rel || '/' === substr( $name, -1 ) ) {
		continue;
	}
	$leaf = strtolower( basename( $rel ) );
	if ( preg_match( '/\.md$/', $leaf ) ) {
		$findings[] = "markdown file: $name";
	}
	if ( preg_match( '/\.dist$|^phpcs\.xml|^phpunit\.xml/', $leaf ) ) {
		$findings[] = "build config: $name";
	}
	if ( preg_match( '/\.(sh|phar|zip|gz|tgz|tar|rar|7z|bz2|exe|dll|so|sql|log|map)$/', $leaf ) ) {
		$findings[] = "disallowed file type: $name";
	}
	if ( preg_match( '/[^A-Za-z0-9._\-\/]/', $rel ) ) {
		$findings[] = "unusual character in path: $name";
	}
	if ( preg_match( '/\.(php|js|css|txt|json|pot)$/', $leaf ) ) {
		$content = $z->getFromIndex( $i );
		if ( false !== strpos( $content, "\r" ) ) {
			$findings[] = "CRLF line endings: $name";
		}
		if ( 0 === strncmp( $content, "\xEF\xBB\xBF", 3 ) ) {
			$findings[] = "byte order mark: $name";
		}
	}
}
$z->close();

$size = filesize( $zip );
if ( $size > 10 * 1024 * 1024 ) {
	$findings[] = sprintf( 'zip is %d bytes, over 10 MB', $size );
}
foreach ( array( "$slug/$slug.php", "$slug/readme.txt", "$slug/uninstall.php" ) as $required ) {
	if ( ! in_array( $required, $names, true ) ) {
		$findings[] = "required file missing: $required";
	}
}

printf( "zip-audit: %s\nentries: %d, uncompressed: %d bytes, archive: %d bytes\n", basename( $zip ), count( $names ), $bytes, $size );
if ( $findings ) {
	foreach ( array_unique( $findings ) as $f ) {
		echo 'FAIL ', $f, "\n";
	}
	exit( 1 );
}
echo "PASS: forward slashes, all entries under $slug/, no dotfiles, no docs/tests/dev/tools/env/release/node_modules, no *.md, *.dist, phpcs/phpunit config, scripts or archives, LF and no BOM in text files, under 10 MB\n";
exit( 0 );
