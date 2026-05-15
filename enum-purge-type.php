<?php
/**
 * Varnish purge operation type definitions.
 *
 * @package CLP_Varnish_Cache
 */

/**
 * Backed string enum representing the kind of PURGE operation performed.
 */
enum PurgeType: string {
	case Host    = 'host';
	case Tag     = 'tag';
	case Url     = 'url';
	case HostTag = 'host+tag';
	case All     = 'all';
}
