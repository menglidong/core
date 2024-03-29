<?php
/**
 * Implements Special:Wantedfiles
 *
 * Copyright © 2008 Soxred93
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
 * @ingroup SpecialPage
 * @author Soxred93 <soxred93@gmail.com>
 */

/**
 * Querypage that lists the most wanted files
 *
 * @ingroup SpecialPage
 */
class WantedFilesPage extends WantedQueryPage {

	function __construct( $name = 'Wantedfiles' ) {
		parent::__construct( $name );
	}

	function getPageHeader() {
		# Specifically setting to use "Wanted Files" (NS_MAIN) as title, so as to get what
		# category would be used on main namespace pages, for those tricky wikipedia
		# admins who like to do {{#ifeq:{{NAMESPACE}}|foo|bar|....}}.
		$catMessage = $this->msg( 'broken-file-category' )
			->title( Title::newFromText( "Wanted Files", NS_MAIN ) )
			->inContentLanguage();

		if ( !$catMessage->isDisabled() ) {
			$category = Title::makeTitleSafe( NS_CATEGORY, $catMessage->text() );
		} else {
			$category = false;
		}

		$noForeign = '';
		if ( !$this->likelyToHaveFalsePositives() ) {
			// Additional messages for grep:
			// wantedfiletext-cat-noforeign, wantedfiletext-nocat
			$noForeign = '-noforeign';
		}

		if ( $category ) {
			return $this
				->msg( 'wantedfiletext-cat' . $noForeign )
				->params( $category->getFullText() )
				->parseAsBlock();
		} else {
			return $this
				->msg( 'wantedfiletext-nocat' . $noForeign )
				->parseAsBlock();
		}
	}

	/**
	 * Whether foreign repos are likely to cause false positives
	 *
	 * In its own function to allow subclasses to override.
	 * @see SpecialWantedFilesGUOverride in GlobalUsage extension.
	 * @since 1.24
	 */
	protected function likelyToHaveFalsePositives() {
		return RepoGroup::singleton()->hasForeignRepos();
	}

	/**
	 * KLUGE: The results may contain false positives for files
	 * that exist e.g. in a shared repo.  Setting this at least
	 * keeps them from showing up as redlinks in the output, even
	 * if it doesn't fix the real problem (bug 6220).
	 *
	 * @note could also have existing links here from broken file
	 * redirects.
	 * @return bool
	 */
	function forceExistenceCheck() {
		return true;
	}

	function getQueryInfo() {
		return array(
			'tables' => array(
				'imagelinks',
				'page',
				'redirect',
				'img1' => 'image',
				'img2' => 'image',
			),
			'fields' => array(
				'namespace' => NS_FILE,
				'title' => 'il_to',
				'value' => 'COUNT(*)'
			),
			'conds' => array(
				'img1.img_name' => null,
				// We also need to exclude file redirects
				'img2.img_name' => null,
			 ),
			'options' => array( 'GROUP BY' => 'il_to' ),
			'join_conds' => array(
				'img1' => array( 'LEFT JOIN',
					'il_to = img1.img_name'
				),
				'page' => array( 'LEFT JOIN', array(
					'il_to = page_title',
					'page_namespace' => NS_FILE,
				) ),
				'redirect' => array( 'LEFT JOIN', array(
					'page_id = rd_from',
					'rd_namespace' => NS_FILE,
					'rd_interwiki' => ''
				) ),
				'img2' => array( 'LEFT JOIN',
					'rd_title = img2.img_name'
				)
			)
		);
	}

	protected function getGroupName() {
		return 'maintenance';
	}
}
