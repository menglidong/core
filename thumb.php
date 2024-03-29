<?php
/**
 * PHP script to stream out an image thumbnail.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Media
 */

define( 'MW_NO_OUTPUT_COMPRESSION', 1 );
require __DIR__ . '/includes/WebStart.php';

// Don't use fancy mime detection, just check the file extension for jpg/gif/png
$wgTrivialMimeDetection = true;

if ( defined( 'THUMB_HANDLER' ) ) {
	// Called from thumb_handler.php via 404; extract params from the URI...
	wfThumbHandle404();
} else {
	// Called directly, use $_GET params
	wfThumbHandleRequest();
}

wfLogProfilingData();
// Commit and close up!
$factory = wfGetLBFactory();
$factory->commitMasterChanges();
$factory->shutdown();

//--------------------------------------------------------------------------

/**
 * Handle a thumbnail request via query parameters
 *
 * @return void
 */
function wfThumbHandleRequest() {
	$params = get_magic_quotes_gpc()
		? array_map( 'stripslashes', $_GET )
		: $_GET;

	wfStreamThumb( $params ); // stream the thumbnail
}

/**
 * Handle a thumbnail request via thumbnail file URL
 *
 * @return void
 */
function wfThumbHandle404() {
	global $wgArticlePath;

	# Set action base paths so that WebRequest::getPathInfo()
	# recognizes the "X" as the 'title' in ../thumb_handler.php/X urls.
	# Note: If Custom per-extension repo paths are set, this may break.
	$repo = RepoGroup::singleton()->getLocalRepo();
	$oldArticlePath = $wgArticlePath;
	$wgArticlePath = $repo->getZoneUrl( 'thumb' ) . '/$1';

	$matches = WebRequest::getPathInfo();

	$wgArticlePath = $oldArticlePath;

	if ( !isset( $matches['title'] ) ) {
		wfThumbError( 404, 'Could not determine the name of the requested thumbnail.' );
		return;
	}

	$params = wfExtractThumbRequestInfo( $matches['title'] ); // basic wiki URL param extracting
	if ( $params == null ) {
		wfThumbError( 400, 'The specified thumbnail parameters are not recognized.' );
		return;
	}

	wfStreamThumb( $params ); // stream the thumbnail
}

/**
 * Stream a thumbnail specified by parameters
 *
 * @param array $params List of thumbnailing parameters. In addition to parameters
 *  passed to the MediaHandler, this may also includes the keys:
 *   f (for filename), archived (if archived file), temp (if temp file),
 *   w (alias for width), p (alias for page), r (ignored; historical),
 *   rel404 (path for render on 404 to verify hash path correct),
 *   thumbName (thumbnail name to potentially extract more parameters from
 *   e.g. 'lossy-page1-120px-Foo.tiff' would add page, lossy and width
 *   to the parameters)
 * @return void
 */
function wfStreamThumb( array $params ) {
	global $wgVaryOnXFP;

	$section = new ProfileSection( __METHOD__ );

	$headers = array(); // HTTP headers to send

	$fileName = isset( $params['f'] ) ? $params['f'] : '';

	// Backwards compatibility parameters
	if ( isset( $params['w'] ) ) {
		$params['width'] = $params['w'];
		unset( $params['w'] );
	}
	if ( isset( $params['width'] ) && substr( $params['width'], -2 ) == 'px' ) {
		// strip the px (pixel) suffix, if found
		$params['width'] = substr( $params['width'], 0, -2 );
	}
	if ( isset( $params['p'] ) ) {
		$params['page'] = $params['p'];
	}

	// Is this a thumb of an archived file?
	$isOld = ( isset( $params['archived'] ) && $params['archived'] );
	unset( $params['archived'] ); // handlers don't care

	// Is this a thumb of a temp file?
	$isTemp = ( isset( $params['temp'] ) && $params['temp'] );
	unset( $params['temp'] ); // handlers don't care

	// Some basic input validation
	$fileName = strtr( $fileName, '\\/', '__' );

	// Actually fetch the image. Method depends on whether it is archived or not.
	if ( $isTemp ) {
		$repo = RepoGroup::singleton()->getLocalRepo()->getTempRepo();
		$img = new UnregisteredLocalFile( null, $repo,
			# Temp files are hashed based on the name without the timestamp.
			# The thumbnails will be hashed based on the entire name however.
			# @todo fix this convention to actually be reasonable.
			$repo->getZonePath( 'public' ) . '/' . $repo->getTempHashPath( $fileName ) . $fileName
		);
	} elseif ( $isOld ) {
		// Format is <timestamp>!<name>
		$bits = explode( '!', $fileName, 2 );
		if ( count( $bits ) != 2 ) {
			wfThumbError( 404, wfMessage( 'badtitletext' )->text() );
			return;
		}
		$title = Title::makeTitleSafe( NS_FILE, $bits[1] );
		if ( !$title ) {
			wfThumbError( 404, wfMessage( 'badtitletext' )->text() );
			return;
		}
		$img = RepoGroup::singleton()->getLocalRepo()->newFromArchiveName( $title, $fileName );
	} else {
		$img = wfLocalFile( $fileName );
	}

	// Check the source file title
	if ( !$img ) {
		wfThumbError( 404, wfMessage( 'badtitletext' )->text() );
		return;
	}

	// Check permissions if there are read restrictions
	$varyHeader = array();
	if ( !in_array( 'read', User::getGroupPermissions( array( '*' ) ), true ) ) {
		if ( !$img->getTitle() || !$img->getTitle()->userCan( 'read' ) ) {
			wfThumbError( 403, 'Access denied. You do not have permission to access ' .
				'the source file.' );
			return;
		}
		$headers[] = 'Cache-Control: private';
		$varyHeader[] = 'Cookie';
	}

	// Check if the file is hidden
	if ( $img->isDeleted( File::DELETED_FILE ) ) {
		wfThumbError( 404, "The source file '$fileName' does not exist." );
		return;
	}

	// Do rendering parameters extraction from thumbnail name.
	if ( isset( $params['thumbName'] ) ) {
		$params = wfExtractThumbParams( $img, $params );
	}
	if ( $params == null ) {
		wfThumbError( 400, 'The specified thumbnail parameters are not recognized.' );
		return;
	}

	// Check the source file storage path
	if ( !$img->exists() ) {
		$redirectedLocation = false;
		if ( !$isTemp ) {
			// Check for file redirect
			// Since redirects are associated with pages, not versions of files,
			// we look for the most current version to see if its a redirect.
			$possRedirFile = RepoGroup::singleton()->getLocalRepo()->findFile( $img->getName() );
			if ( $possRedirFile && !is_null( $possRedirFile->getRedirected() ) ) {
				$redirTarget = $possRedirFile->getName();
				$targetFile = wfLocalFile( Title::makeTitleSafe( NS_FILE, $redirTarget ) );
				if ( $targetFile->exists() ) {
					$newThumbName = $targetFile->thumbName( $params );
					if ( $isOld ) {
						$newThumbUrl = $targetFile->getArchiveThumbUrl(
							$bits[0] . '!' . $targetFile->getName(), $newThumbName );
					} else {
						$newThumbUrl = $targetFile->getThumbUrl( $newThumbName );
					}
					$redirectedLocation = wfExpandUrl( $newThumbUrl, PROTO_CURRENT );
				}
			}
		}

		if ( $redirectedLocation ) {
			// File has been moved. Give redirect.
			$response = RequestContext::getMain()->getRequest()->response();
			$response->header( "HTTP/1.1 302 " . HttpStatus::getMessage( 302 ) );
			$response->header( 'Location: ' . $redirectedLocation );
			$response->header( 'Expires: ' .
				gmdate( 'D, d M Y H:i:s', time() + 12 * 3600 ) . ' GMT' );
			if ( $wgVaryOnXFP ) {
				$varyHeader[] = 'X-Forwarded-Proto';
			}
			if ( count( $varyHeader ) ) {
				$response->header( 'Vary: ' . implode( ', ', $varyHeader ) );
			}
			return;
		}

		// If its not a redirect that has a target as a local file, give 404.
		wfThumbError( 404, "The source file '$fileName' does not exist." );
		return;
	} elseif ( $img->getPath() === false ) {
		wfThumbError( 500, "The source file '$fileName' is not locally accessible." );
		return;
	}

	// Check IMS against the source file
	// This means that clients can keep a cached copy even after it has been deleted on the server
	if ( !empty( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
		// Fix IE brokenness
		$imsString = preg_replace( '/;.*$/', '', $_SERVER["HTTP_IF_MODIFIED_SINCE"] );
		// Calculate time
		wfSuppressWarnings();
		$imsUnix = strtotime( $imsString );
		wfRestoreWarnings();
		if ( wfTimestamp( TS_UNIX, $img->getTimestamp() ) <= $imsUnix ) {
			header( 'HTTP/1.1 304 Not Modified' );
			return;
		}
	}

	$rel404 = isset( $params['rel404'] ) ? $params['rel404'] : null;
	unset( $params['r'] ); // ignore 'r' because we unconditionally pass File::RENDER
	unset( $params['f'] ); // We're done with 'f' parameter.
	unset( $params['rel404'] ); // moved to $rel404

	// Get the normalized thumbnail name from the parameters...
	try {
		$thumbName = $img->thumbName( $params );
		if ( !strlen( $thumbName ) ) { // invalid params?
			wfThumbError( 400, 'The specified thumbnail parameters are not valid.' );
			return;
		}
		$thumbName2 = $img->thumbName( $params, File::THUMB_FULL_NAME ); // b/c; "long" style
	} catch ( MWException $e ) {
		wfThumbError( 500, $e->getHTML() );
		return;
	}

	// For 404 handled thumbnails, we only use the the base name of the URI
	// for the thumb params and the parent directory for the source file name.
	// Check that the zone relative path matches up so squid caches won't pick
	// up thumbs that would not be purged on source file deletion (bug 34231).
	if ( $rel404 !== null ) { // thumbnail was handled via 404
		if ( rawurldecode( $rel404 ) === $img->getThumbRel( $thumbName ) ) {
			// Request for the canonical thumbnail name
		} elseif ( rawurldecode( $rel404 ) === $img->getThumbRel( $thumbName2 ) ) {
			// Request for the "long" thumbnail name; redirect to canonical name
			$response = RequestContext::getMain()->getRequest()->response();
			$response->header( "HTTP/1.1 301 " . HttpStatus::getMessage( 301 ) );
			$response->header( 'Location: ' .
				wfExpandUrl( $img->getThumbUrl( $thumbName ), PROTO_CURRENT ) );
			$response->header( 'Expires: ' .
				gmdate( 'D, d M Y H:i:s', time() + 7 * 86400 ) . ' GMT' );
			if ( $wgVaryOnXFP ) {
				$varyHeader[] = 'X-Forwarded-Proto';
			}
			if ( count( $varyHeader ) ) {
				$response->header( 'Vary: ' . implode( ', ', $varyHeader ) );
			}
			return;
		} else {
			wfThumbError( 404, "The given path of the specified thumbnail is incorrect;
				expected '" . $img->getThumbRel( $thumbName ) . "' but got '" .
				rawurldecode( $rel404 ) . "'." );
			return;
		}
	}

	$dispositionType = isset( $params['download'] ) ? 'attachment' : 'inline';

	// Suggest a good name for users downloading this thumbnail
	$headers[] = "Content-Disposition: {$img->getThumbDisposition( $thumbName, $dispositionType )}";

	if ( count( $varyHeader ) ) {
		$headers[] = 'Vary: ' . implode( ', ', $varyHeader );
	}

	// Stream the file if it exists already...
	$thumbPath = $img->getThumbPath( $thumbName );
	if ( $img->getRepo()->fileExists( $thumbPath ) ) {
		$img->getRepo()->streamFile( $thumbPath, $headers );
		return;
	}

	$user = RequestContext::getMain()->getUser();
	if ( !wfThumbIsStandard( $img, $params ) && $user->pingLimiter( 'renderfile-nonstandard' ) ) {
		wfThumbError( 500, wfMessage( 'actionthrottledtext' ) );
		return;
	} elseif ( $user->pingLimiter( 'renderfile' ) ) {
		wfThumbError( 500, wfMessage( 'actionthrottledtext' ) );
		return;
	}

	// Actually generate a new thumbnail
	list( $thumb, $errorMsg ) = wfGenerateThumbnail( $img, $params, $thumbName, $thumbPath );

	// Check for thumbnail generation errors...
	$msg = wfMessage( 'thumbnail_error' );
	if ( !$thumb ) {
		$errorMsg = $errorMsg ?: $msg->rawParams( 'File::transform() returned false' )->escaped();
	} elseif ( $thumb->isError() ) {
		$errorMsg = $thumb->getHtmlMsg();
	} elseif ( !$thumb->hasFile() ) {
		$errorMsg = $msg->rawParams( 'No path supplied in thumbnail object' )->escaped();
	} elseif ( $thumb->fileIsSource() ) {
		$errorMsg = $msg->
			rawParams( 'Image was not scaled, is the requested width bigger than the source?' )->escaped();
	}

	if ( $errorMsg !== false ) {
		wfThumbError( 500, $errorMsg );
	} else {
		// Stream the file if there were no errors
		$thumb->streamFile( $headers );
	}
}

/**
 * Actually try to generate a new thumbnail
 *
 * @param File $file
 * @param array $params
 * @param string $thumbName
 * @param string $thumbPath
 * @return array (MediaTransformOutput|bool, string|bool error message HTML)
 */
function wfGenerateThumbnail( File $file, array $params, $thumbName, $thumbPath ) {
	global $wgMemc, $wgAttemptFailureEpoch;

	$key = wfMemcKey( 'attempt-failures', $wgAttemptFailureEpoch,
		$file->getRepo()->getName(), $file->getSha1(), md5( $thumbName ) );

	// Check if this file keeps failing to render
	if ( $wgMemc->get( $key ) >= 4 ) {
		return array( false, wfMessage( 'thumbnail_image-failure-limit', 4 ) );
	}

	$done = false;
	// Record failures on PHP fatals in addition to caching exceptions
	register_shutdown_function( function() use ( &$done, $key ) {
		if ( !$done ) { // transform() gave a fatal
			global $wgMemc;
			// Randomize TTL to reduce stampedes
			$wgMemc->incrWithInit( $key, 3600 + mt_rand( 0, 300 ) );
		}
	} );

	$thumb = false;
	$errorHtml = false;

	// guard thumbnail rendering with PoolCounter to avoid stampedes
	// expensive files use a separate PoolCounter config so it is possible to set up a global limit on them
	if ( $file->isExpensiveToThumbnail() ) {
		$poolCounterType = 'FileRenderExpensive';
	} else {
		$poolCounterType = 'FileRender';
	}

	// Thumbnail isn't already there, so create the new thumbnail...
	try {
		$work = new PoolCounterWorkViaCallback( $poolCounterType, sha1( $file->getName() ),
			array(
				'doWork' => function() use ( $file, $params ) {
					return $file->transform( $params, File::RENDER_NOW );
				},
				'getCachedWork' => function() use ( $file, $params, $thumbPath ) {
					// If the worker that finished made this thumbnail then use it.
					// Otherwise, it probably made a different thumbnail for this file.
					return $file->getRepo()->fileExists( $thumbPath )
						? $file->transform( $params, File::RENDER_NOW )
						: false; // retry once more in exclusive mode
				},
				'fallback' => function() {
					return wfMessage( 'generic-pool-error' )->parse();
				},
				'error' => function ( $status ) {
					return $status->getHTML();
				}
			)
		);
		$result = $work->execute();
		if ( $result instanceof MediaTransformOutput ) {
			$thumb = $result;
		} elseif ( is_string( $result ) ) { // error
			$errorHtml = $result;
		}
	} catch ( Exception $e ) {
		// Tried to select a page on a non-paged file?
	}

	$done = true; // no PHP fatal occured

	if ( !$thumb || $thumb->isError() ) {
		// Randomize TTL to reduce stampedes
		$wgMemc->incrWithInit( $key, 3600 + mt_rand( 0, 300 ) );
	}

	return array( $thumb, $errorHtml );
}

/**
 * Returns true if this thumbnail is one that MediaWiki generates
 * links to on file description pages and possibly parser output.
 *
 * $params is considered non-standard if they involve a non-standard
 * width or any non-default parameters aside from width and page number.
 * The number of possible files with standard parameters is far less than
 * that of all combinations; rate-limiting for them can thus be more generious.
 *
 * @param File $file
 * @param array $params
 * @return bool
 */
function wfThumbIsStandard( File $file, array $params ) {
	global $wgThumbLimits, $wgImageLimits;

	$handler = $file->getHandler();
	if ( !$handler || !isset( $params['width'] ) ) {
		return false;
	}

	$basicParams = array();
	if ( isset( $params['page'] ) ) {
		$basicParams['page'] = $params['page'];
	}

	// Check if the width matches one of $wgThumbLimits
	if ( in_array( $params['width'], $wgThumbLimits ) ) {
		$normalParams = $basicParams + array( 'width' => $params['width'] );
		// Append any default values to the map (e.g. "lossy", "lossless", ...)
		$handler->normaliseParams( $file, $normalParams );
	} else {
		// If not, then check if the width matchs one of $wgImageLimits
		$match = false;
		foreach ( $wgImageLimits as $pair ) {
			$normalParams = $basicParams + array( 'width' => $pair[0], 'height' => $pair[1] );
			// Decide whether the thumbnail should be scaled on width or height.
			// Also append any default values to the map (e.g. "lossy", "lossless", ...)
			$handler->normaliseParams( $file, $normalParams );
			// Check if this standard thumbnail size maps to the given width
			if ( $normalParams['width'] == $params['width'] ) {
				$match = true;
				break;
			}
		}
		if ( !$match ) {
			return false; // not standard for description pages
		}
	}

	// Check that the given values for non-page, non-width, params are just defaults
	foreach ( $params as $key => $value ) {
		if ( !isset( $normalParams[$key] ) || $normalParams[$key] != $value ) {
			return false;
		}
	}

	return true;
}

/**
 * Convert pathinfo type parameter, into normal request parameters
 *
 * So for example, if the request was redirected from
 * /w/images/thumb/a/ab/Foo.png/120px-Foo.png. The $thumbRel parameter
 * of this function would be set to "a/ab/Foo.png/120px-Foo.png".
 * This method is responsible for turning that into an array
 * with the folowing keys:
 *  * f => the filename (Foo.png)
 *  * rel404 => the whole thing (a/ab/Foo.png/120px-Foo.png)
 *  * archived => 1 (If the request is for an archived thumb)
 *  * temp => 1 (If the file is in the "temporary" zone)
 *  * thumbName => the thumbnail name, including parameters (120px-Foo.png)
 *
 * Transform specific parameters are set later via wfExtractThumbParams().
 *
 * @param string $thumbRel Thumbnail path relative to the thumb zone
 * @return array|null Associative params array or null
 */
function wfExtractThumbRequestInfo( $thumbRel ) {
	$repo = RepoGroup::singleton()->getLocalRepo();

	$hashDirReg = $subdirReg = '';
	for ( $i = 0; $i < $repo->getHashLevels(); $i++ ) {
		$subdirReg .= '[0-9a-f]';
		$hashDirReg .= "$subdirReg/";
	}

	// Check if this is a thumbnail of an original in the local file repo
	if ( preg_match( "!^((archive/)?$hashDirReg([^/]*)/([^/]*))$!", $thumbRel, $m ) ) {
		list( /*all*/, $rel, $archOrTemp, $filename, $thumbname ) = $m;
	// Check if this is a thumbnail of an temp file in the local file repo
	} elseif ( preg_match( "!^(temp/)($hashDirReg([^/]*)/([^/]*))$!", $thumbRel, $m ) ) {
		list( /*all*/, $archOrTemp, $rel, $filename, $thumbname ) = $m;
	} else {
		return null; // not a valid looking thumbnail request
	}

	$params = array( 'f' => $filename, 'rel404' => $rel );
	if ( $archOrTemp === 'archive/' ) {
		$params['archived'] = 1;
	} elseif ( $archOrTemp === 'temp/' ) {
		$params['temp'] = 1;
	}

	$params['thumbName'] = $thumbname;
	return $params;
}

/**
 * Convert a thumbnail name (122px-foo.png) to parameters, using
 * file handler.
 *
 * @param File $file File object for file in question
 * @param array $param Array of parameters so far
 * @return array Parameters array with more parameters
 */
function wfExtractThumbParams( $file, $params ) {
	if ( !isset( $params['thumbName'] ) ) {
		throw new MWException( "No thumbnail name passed to wfExtractThumbParams" );
	}

	$thumbname = $params['thumbName'];
	unset( $params['thumbName'] );

	// Do the hook first for older extensions that rely on it.
	if ( !wfRunHooks( 'ExtractThumbParameters', array( $thumbname, &$params ) ) ) {
		// Check hooks if parameters can be extracted
		// Hooks return false if they manage to *resolve* the parameters
		// This hook should be considered deprecated
		wfDeprecated( 'ExtractThumbParameters', '1.22' );
		return $params; // valid thumbnail URL (via extension or config)
	}

	// FIXME: Files in the temp zone don't set a mime type, which means
	// they don't have a handler. Which means we can't parse the param
	// string. However, not a big issue as what good is a param string
	// if you have no handler to make use of the param string and
	// actually generate the thumbnail.
	$handler = $file->getHandler();

	// Based on UploadStash::parseKey
	$fileNamePos = strrpos( $thumbname, $params['f'] );
	if ( $fileNamePos === false ) {
		// Maybe using a short filename? (see FileRepo::nameForThumb)
		$fileNamePos = strrpos( $thumbname, 'thumbnail' );
	}

	if ( $handler && $fileNamePos !== false ) {
		$paramString = substr( $thumbname, 0, $fileNamePos - 1 );
		$extraParams = $handler->parseParamString( $paramString );
		if ( $extraParams !== false ) {
			return $params + $extraParams;
		}
	}

	// As a last ditch fallback, use the traditional common parameters
	if ( preg_match( '!^(page(\d*)-)*(\d*)px-[^/]*$!', $thumbname, $matches ) ) {
		list( /* all */, $pagefull, $pagenum, $size ) = $matches;
		$params['width'] = $size;
		if ( $pagenum ) {
			$params['page'] = $pagenum;
		}
		return $params; // valid thumbnail URL
	}
	return null;
}

/**
 * Output a thumbnail generation error message
 *
 * @param int $status
 * @param string $msg
 * @return void
 */
function wfThumbError( $status, $msg ) {
	global $wgShowHostnames;

	header( 'Cache-Control: no-cache' );
	header( 'Content-Type: text/html; charset=utf-8' );
	if ( $status == 404 ) {
		header( 'HTTP/1.1 404 Not found' );
	} elseif ( $status == 403 ) {
		header( 'HTTP/1.1 403 Forbidden' );
		header( 'Vary: Cookie' );
	} else {
		header( 'HTTP/1.1 500 Internal server error' );
	}
	if ( $wgShowHostnames ) {
		header( 'X-MW-Thumbnail-Renderer: ' . wfHostname() );
		$url = htmlspecialchars( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' );
		$hostname = htmlspecialchars( wfHostname() );
		$debug = "<!-- $url -->\n<!-- $hostname -->\n";
	} else {
		$debug = '';
	}
	echo <<<EOT
<html><head><title>Error generating thumbnail</title></head>
<body>
<h1>Error generating thumbnail</h1>
<p>
$msg
</p>
$debug
</body>
</html>

EOT;
}
