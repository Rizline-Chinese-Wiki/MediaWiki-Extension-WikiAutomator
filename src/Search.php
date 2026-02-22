<?php

namespace WikiAutomator;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Database-level search for pages matching content or title patterns.
 * Modeled after ReplaceText's Search class.
 */
class Search {

	/**
	 * Find pages whose content matches the given search term.
	 * Joins: page -> revision -> slots -> content -> text
	 *
	 * @param string $search Search term
	 * @param string $matchMode literal/wildcard/regex
	 * @param array $namespaces Namespace IDs to search in (empty = all)
	 * @param string|null $category Limit to pages in this category
	 * @param string|null $prefix Limit to pages with this title prefix
	 * @param int $limit Max results
	 * @return Title[] Array of matching Title objects
	 */
	public static function doSearchQuery(
		$search, $matchMode = 'literal', $namespaces = [],
		$category = null, $prefix = null, $limit = 500
	) {
		if ( $search === '' ) {
			return [];
		}

		$services = MediaWikiServices::getInstance();
		$dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );

		$tables = [ 'page', 'revision', 'slots', 'content', 'text' ];
		$fields = [ 'page_namespace', 'page_title' ];
		$conds = [];
		$options = [ 'LIMIT' => $limit, 'ORDER BY' => [ 'page_namespace', 'page_title' ] ];
		$joinConds = [
			'revision' => [ 'JOIN', 'page_latest = rev_id' ],
			'slots' => [ 'JOIN', 'rev_id = slot_revision_id' ],
			'content' => [ 'JOIN', 'slot_content_id = content_id' ],
			'text' => [ 'JOIN', "CONCAT('tt:', old_id) = content_address" ],
		];

		// Content matching condition
		if ( $matchMode === 'regex' ) {
			// MySQL/MariaDB regex
			$conds[] = 'CAST(old_text AS BINARY) REGEXP BINARY ' . $dbr->addQuotes( $search );
		} elseif ( $matchMode === 'wildcard' ) {
			// Convert wildcard * to LIKE wildcards
			// buildLike() escapes plain strings, so split on * and interleave with anyString()
			$segments = explode( '*', $search );
			$likeParts = [ $dbr->anyString() ];
			foreach ( $segments as $i => $seg ) {
				if ( $i > 0 ) {
					$likeParts[] = $dbr->anyString();
				}
				if ( $seg !== '' ) {
					$likeParts[] = $seg;
				}
			}
			$likeParts[] = $dbr->anyString();
			$conds[] = 'old_text' . $dbr->buildLike( ...$likeParts );
		} else {
			// Literal: use LIKE for substring match
			$conds[] = 'old_text' . $dbr->buildLike( $dbr->anyString(), $search, $dbr->anyString() );
		}

		// Namespace filter
		if ( !empty( $namespaces ) ) {
			$conds['page_namespace'] = array_map( 'intval', $namespaces );
		}

		// Category filter
		if ( $category !== null && $category !== '' ) {
			$tables[] = 'categorylinks';
			$joinConds['categorylinks'] = [ 'JOIN', 'page_id = cl_from' ];
			$categoryTitle = Title::newFromText( $category, NS_CATEGORY );
			if ( $categoryTitle ) {
				$conds['cl_to'] = $categoryTitle->getDBkey();
			}
		}

		// Prefix filter
		if ( $prefix !== null && $prefix !== '' ) {
			$conds[] = 'page_title' . $dbr->buildLike( $prefix, $dbr->anyString() );
		}

		$res = $dbr->select( $tables, $fields, $conds, __METHOD__, $options, $joinConds );

		$titles = [];
		foreach ( $res as $row ) {
			$title = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
			if ( $title ) {
				$titles[] = $title;
			}
		}

		return $titles;
	}

	/**
	 * Find pages whose titles match the given search term.
	 *
	 * @param string $search Search term
	 * @param string $matchMode literal/wildcard/regex
	 * @param array $namespaces Namespace IDs (empty = all)
	 * @param int $limit Max results
	 * @return Title[] Array of matching Title objects
	 */
	public static function doTitleSearchQuery(
		$search, $matchMode = 'literal', $namespaces = [], $limit = 500
	) {
		if ( $search === '' ) {
			return [];
		}

		$services = MediaWikiServices::getInstance();
		$dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );

		$conds = [];
		$options = [ 'LIMIT' => $limit, 'ORDER BY' => [ 'page_namespace', 'page_title' ] ];

		if ( $matchMode === 'regex' ) {
			$conds[] = 'CAST(page_title AS BINARY) REGEXP BINARY ' . $dbr->addQuotes( $search );
		} elseif ( $matchMode === 'wildcard' ) {
			$segments = explode( '*', $search );
			$likeParts = [ $dbr->anyString() ];
			foreach ( $segments as $i => $seg ) {
				if ( $i > 0 ) {
					$likeParts[] = $dbr->anyString();
				}
				if ( $seg !== '' ) {
					$likeParts[] = $seg;
				}
			}
			$likeParts[] = $dbr->anyString();
			$conds[] = 'page_title' . $dbr->buildLike( ...$likeParts );
		} else {
			$conds[] = 'page_title' . $dbr->buildLike( $dbr->anyString(), $search, $dbr->anyString() );
		}

		if ( !empty( $namespaces ) ) {
			$conds['page_namespace'] = array_map( 'intval', $namespaces );
		}

		$res = $dbr->select(
			'page',
			[ 'page_namespace', 'page_title' ],
			$conds,
			__METHOD__,
			$options
		);

		$titles = [];
		foreach ( $res as $row ) {
			$title = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
			if ( $title ) {
				$titles[] = $title;
			}
		}

		return $titles;
	}

	/**
	 * Get warnings before executing a replacement
	 *
	 * @param string $search Search string
	 * @param string $replace Replacement string
	 * @param string $matchMode Match mode
	 * @return array Warning message keys
	 */
	public static function getWarnings( $search, $replace, $matchMode ) {
		$warnings = [];

		if ( $search !== '' && $replace === '' ) {
			$warnings[] = 'wikiautomator-warning-blank-replacement';
		}

		// Skip reverse-existence check for regex/wildcard (unpredictable output)
		if ( $replace !== '' && $matchMode === 'literal' ) {
			$existingPages = self::doSearchQuery( $replace, 'literal', [], null, null, 1 );
			if ( !empty( $existingPages ) ) {
				$warnings[] = 'wikiautomator-warning-replacement-exists';
			}
		}

		return $warnings;
	}
}
