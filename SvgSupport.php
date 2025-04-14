<?php
/*
Plugin Name: SvgSupport
Description: Adds SVG support, securely.
Version: 0.1
Author: Shorix / 0xnoid
Author URI: https://github.com/0xnoid
Plugin URI: https://github.com/YatagarasuIndustries/SvgSupport
License: GPLv3
*/

/**
 * Allow SVG and SVGZ
 *
 * @param array $mimes Existing allowed MIME types.
 * @return array Modified MIME types including SVG and SVGZ.
 */
function csu_add_svg_mime_types( $mimes ) {
    $mimes['svg']  = 'image/svg+xml';
    $mimes['svgz'] = 'image/svg+xml';
    return $mimes;
}
add_filter( 'upload_mimes', 'csu_add_svg_mime_types' );

/**
 * Sanitize SVG uploads.
 *
 * This function reads the uploaded file, loads its XML content,
 * removes potential security risks (like script elements,
 * foreignObject elements, inline event handlers, and dangerous href attributes),
 * and then rewrites the sanitized content back to the temporary file.
 *
 * @param array $file File upload array.
 * @return array|WP_Error The modified file array or WP_Error if the file is invalid.
 */
function csu_sanitize_svg_upload( $file ) {
    $extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    
    // Target SVGs only
    if ( $extension === 'svg' ) {
        $svg = file_get_contents( $file['tmp_name'] );
        if ( false === $svg ) {
            return new WP_Error( 'svg_error', __( 'Unable to read the SVG file.', 'custom-svg-uploader' ) );
        }

        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        // Disable network access while parsing
        if ( ! $dom->loadXML( $svg, LIBXML_NONET ) ) {
            libxml_clear_errors();
            return new WP_Error( 'svg_error', __( 'Invalid SVG file.', 'custom-svg-uploader' ) );
        }

        // Remove <script> tags.
        $scripts = $dom->getElementsByTagName( 'script' );
        for ( $i = $scripts->length - 1; $i >= 0; $i-- ) {
            $script = $scripts->item( $i );
            $script->parentNode->removeChild( $script );
        }

        // Remove <foreignObject> tags.
        $foreignObjects = $dom->getElementsByTagName( 'foreignObject' );
        for ( $i = $foreignObjects->length - 1; $i >= 0; $i-- ) {
            $foreign = $foreignObjects->item( $i );
            $foreign->parentNode->removeChild( $foreign );
        }

        // Remove inline event handlers from elements.
        $xpath = new DOMXPath( $dom );
        $nodes = $xpath->query( '//*[@*[starts-with(name(), "on")]]' );
        foreach ( $nodes as $node ) {
            $attributes = $node->attributes;
            $to_remove = [];
            foreach ( $attributes as $attr ) {
                if ( strpos( $attr->name, 'on' ) === 0 ) {
                    $to_remove[] = $attr->name;
                }
            }
            foreach ( $to_remove as $attr_name ) {
                $node->removeAttribute( $attr_name );
            }
        }

        // Remove JavaScript references in href attributes.
        $nodesWithHref = $xpath->query( '//*[@href or @xlink:href]' );
        foreach ( $nodesWithHref as $node ) {
            if ( $node->hasAttribute( 'href' ) ) {
                $hrefVal = $node->getAttribute( 'href' );
                if ( stripos( $hrefVal, 'javascript:' ) === 0 ) {
                    $node->removeAttribute( 'href' );
                }
            }
            if ( $node->hasAttribute( 'xlink:href' ) ) {
                $xlinkVal = $node->getAttribute( 'xlink:href' );
                if ( stripos( $xlinkVal, 'javascript:' ) === 0 ) {
                    $node->removeAttribute( 'xlink:href' );
                }
            }
        }

        // Retrieve the sanitized SVG content.
        $sanitized_svg = $dom->saveXML();
        if ( $sanitized_svg ) {
            file_put_contents( $file['tmp_name'], $sanitized_svg );
        }
        libxml_clear_errors();
    }
    return $file;
}
add_filter( 'wp_handle_upload_prefilter', 'csu_sanitize_svg_upload' );
