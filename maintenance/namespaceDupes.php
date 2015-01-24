<?php
/**
 * Check for articles to fix after adding/deleting namespaces
 *
 * Copyright © 2005-2007 Brion Vibber <brion@pobox.com>
 * https://www.mediawiki.org/
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
 * @ingroup Maintenance
 */

require_once __DIR__ . '/Maintenance.php';

/**
 * Maintenance script that checks for articles to fix after
 * adding/deleting namespaces.
 *
 * @ingroup Maintenance
 */
class NamespaceConflictChecker extends Maintenance {

	/**
	 * @var DatabaseBase
	 */
	protected $db;

	public function __construct() {
		parent::__construct();
		$this->mDescription = "";
		$this->addOption( 'fix', 'Attempt to automatically fix errors' );
		$this->addOption( 'suffix', "Dupes will be renamed with correct namespace with " .
			"<text> appended after the article name", false, true );
		$this->addOption( 'prefix', "Do an explicit check for the given title prefix " .
			"appended after the article name", false, true );
	}

	public function execute() {
		$this->db = wfGetDB( DB_MASTER );

		$fix = $this->hasOption( 'fix' );
		$suffix = $this->getOption( 'suffix', '' );
		$prefix = $this->getOption( 'prefix', '' );
		$key = intval( $this->getOption( 'key', 0 ) );

		if ( $prefix ) {
			$retval = $this->checkPrefix( $key, $prefix, $fix, $suffix );
		} else {
			$retval = $this->checkAll( $fix, $suffix );
		}

		if ( $retval ) {
			$this->output( "\nLooks good!\n" );
		} else {
			$this->output( "\nOh noeees\n" );
		}
	}

	/**
	 * @todo Document
	 * @param bool $fix Whether or not to fix broken entries
	 * @param string $suffix Suffix to append to renamed articles
	 *
	 * @return bool
	 */
	private function checkAll( $fix, $suffix = '' ) {
		global $wgContLang, $wgNamespaceAliases, $wgCapitalLinks;

		$spaces = array();

		// List interwikis first, so they'll be overridden
		// by any conflicting local namespaces.
		foreach ( $this->getInterwikiList() as $prefix ) {
			$name = $wgContLang->ucfirst( $prefix );
			$spaces[$name] = 0;
		}

		// Now pull in all canonical and alias namespaces...
		foreach ( MWNamespace::getCanonicalNamespaces() as $ns => $name ) {
			// This includes $wgExtraNamespaces
			if ( $name !== '' ) {
				$spaces[$name] = $ns;
			}
		}
		foreach ( $wgContLang->getNamespaces() as $ns => $name ) {
			if ( $name !== '' ) {
				$spaces[$name] = $ns;
			}
		}
		foreach ( $wgNamespaceAliases as $name => $ns ) {
			$spaces[$name] = $ns;
		}
		foreach ( $wgContLang->getNamespaceAliases() as $name => $ns ) {
			$spaces[$name] = $ns;
		}

		// We'll need to check for lowercase keys as well,
		// since we're doing case-sensitive searches in the db.
		foreach ( $spaces as $name => $ns ) {
			$moreNames = array();
			$moreNames[] = $wgContLang->uc( $name );
			$moreNames[] = $wgContLang->ucfirst( $wgContLang->lc( $name ) );
			$moreNames[] = $wgContLang->ucwords( $name );
			$moreNames[] = $wgContLang->ucwords( $wgContLang->lc( $name ) );
			$moreNames[] = $wgContLang->ucwordbreaks( $name );
			$moreNames[] = $wgContLang->ucwordbreaks( $wgContLang->lc( $name ) );
			if ( !$wgCapitalLinks ) {
				foreach ( $moreNames as $altName ) {
					$moreNames[] = $wgContLang->lcfirst( $altName );
				}
				$moreNames[] = $wgContLang->lcfirst( $name );
			}
			foreach ( array_unique( $moreNames ) as $altName ) {
				if ( $altName !== $name ) {
					$spaces[$altName] = $ns;
				}
			}
		}

		ksort( $spaces );
		asort( $spaces );

		$ok = true;
		foreach ( $spaces as $name => $ns ) {
			$ok = $this->checkNamespace( $ns, $name, $fix, $suffix ) && $ok;
		}

		return $ok;
	}

	/**
	 * Get the interwiki list
	 *
	 * @return array
	 */
	private function getInterwikiList() {
		$result = Interwiki::getAllPrefixes();
		$prefixes = array();
		foreach ( $result as $row ) {
			$prefixes[] = $row['iw_prefix'];
		}

		return $prefixes;
	}

	/**
	 * @todo Document
	 * @param int $ns A namespace id
	 * @param string $name
	 * @param bool $fix Whether to fix broken entries
	 * @param string $suffix Suffix to append to renamed articles
	 * @return bool
	 */
	private function checkNamespace( $ns, $name, $fix, $suffix = '' ) {
		$conflicts = $this->getConflicts( $ns, $name );
		$count = count( $conflicts );
		if ( $count == 0 ) {
			return true;
		}

		$ok = true;
		foreach ( $conflicts as $row ) {
			$resolvable = $this->reportConflict( $row, $suffix );
			$ok = $ok && $resolvable;
			if ( $fix && ( $resolvable || $suffix != '' ) ) {
				$ok = $this->resolveConflict( $row, $resolvable, $suffix ) && $ok;
			}
		}

		return $ok;
	}

	/**
	 * @todo Do this for real
	 * @param int $key
	 * @param string $prefix
	 * @param bool $fix
	 * @param string $suffix
	 * @return bool
	 */
	private function checkPrefix( $key, $prefix, $fix, $suffix = '' ) {
		$this->output( "Checking prefix \"$prefix\" vs namespace $key\n" );

		return $this->checkNamespace( $key, $prefix, $fix, $suffix );
	}

	/**
	 * Find pages in mainspace that have a prefix of the new namespace
	 * so we know titles that will need migrating
	 *
	 * @param int $ns Namespace id (id for new namespace?)
	 * @param string $name Prefix that is being made a namespace
	 *
	 * @return array
	 */
	private function getConflicts( $ns, $name ) {
		$titleSql = "TRIM(LEADING {$this->db->addQuotes( "$name:" )} FROM page_title)";
		if ( $ns == 0 ) {
			// An interwiki; try an alternate encoding with '-' for ':'
			$titleSql = $this->db->buildConcat( array(
				$this->db->addQuotes( "$name-" ),
				$titleSql,
			) );
		}

		return iterator_to_array( $this->db->select( 'page',
			array(
				'id' => 'page_id',
				'oldtitle' => 'page_title',
				'namespace' => $this->db->addQuotes( $ns ) . ' + page_namespace',
				'title' => $titleSql,
				'oldnamespace' => 'page_namespace',
			),
			array(
				'page_namespace' => array( 0, 1 ),
				'page_title' . $this->db->buildLike( "$name:", $this->db->anyString() ),
			),
			__METHOD__
		) );
	}

	/**
	 * Report any conflicts we find
	 *
	 * @param stdClass $row
	 * @param string $suffix
	 * @return bool
	 */
	private function reportConflict( $row, $suffix ) {
		$newTitle = Title::makeTitleSafe( $row->namespace, $row->title );
		if ( is_null( $newTitle ) || !$newTitle->canExist() ) {
			// Title is also an illegal title...
			// For the moment we'll let these slide to cleanupTitles or whoever.
			$this->output( sprintf( "... %d (%d,\"%s\")\n",
				$row->id,
				$row->oldnamespace,
				$row->oldtitle ) );
			$this->output( "...  *** cannot resolve automatically; illegal title ***\n" );

			return false;
		}

		$this->output( sprintf( "... %d (%d,\"%s\") -> (%d,\"%s\") [[%s]]\n",
			$row->id,
			$row->oldnamespace,
			$row->oldtitle,
			$newTitle->getNamespace(),
			$newTitle->getDBkey(),
			$newTitle->getPrefixedText() ) );

		$id = $newTitle->getArticleID();
		if ( $id ) {
			$this->output( "...  *** cannot resolve automatically; page exists with ID $id ***\n" );

			return false;
		} else {
			return true;
		}
	}

	/**
	 * Resolve any conflicts
	 *
	 * @param stdClass $row Row from the page table to fix
	 * @param bool $resolvable
	 * @param string $suffix Suffix to append to the fixed page
	 * @return bool
	 */
	private function resolveConflict( $row, $resolvable, $suffix ) {
		if ( !$resolvable ) {
			$this->output( "...  *** old title {$row->title}\n" );
			while ( true ) {
				$row->title .= $suffix;
				$this->output( "...  *** new title {$row->title}\n" );
				$title = Title::makeTitleSafe( $row->namespace, $row->title );
				if ( !$title ) {
					$this->output( "... !!! invalid title\n" );

					return false;
				}
				$id = $title->getArticleID();
				if ( $id ) {
					$this->output( "...  *** page exists with ID $id ***\n" );
				} else {
					break;
				}
			}
			$this->output( "...  *** using suffixed form [[" . $title->getPrefixedText() . "]] ***\n" );
		}
		$this->resolveConflictOn( $row, 'page', 'page' );

		return true;
	}

	/**
	 * Resolve a given conflict
	 *
	 * @param stdClass $row Row from the old broken entry
	 * @param string $table Table to update
	 * @param string $prefix Prefix for column name, like page or ar
	 * @return bool
	 */
	private function resolveConflictOn( $row, $table, $prefix ) {
		$this->output( "... resolving on $table... " );
		$newTitle = Title::makeTitleSafe( $row->namespace, $row->title );
		$this->db->update( $table,
			array(
				"{$prefix}_namespace" => $newTitle->getNamespace(),
				"{$prefix}_title" => $newTitle->getDBkey(),
			),
			array(
				// "{$prefix}_namespace" => 0,
				// "{$prefix}_title" => $row->oldtitle,
				"{$prefix}_id" => $row->id,
			),
			__METHOD__ );
		$this->output( "ok.\n" );

		return true;
	}
}

$maintClass = "NamespaceConflictChecker";
require_once RUN_MAINTENANCE_IF_MAIN;
