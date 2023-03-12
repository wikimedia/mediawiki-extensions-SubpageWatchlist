<?php
/*
 * Copyright (C) 2013  Brian Wolff
 * Copyright (C) 2016  Mark A. Hershberger
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace MediaWiki\extensions\SubpageWatchlist;

use ChangesListBooleanFilter;
use Config;
use LogicException;
use MediaWiki\MainConfigNames;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\SpecialPage\Hook\ChangesListSpecialPageStructuredFiltersHook;
use MediaWiki\User\UserOptionsLookup;
use SpecialWatchlist;
use User;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

class Hooks implements
	GetPreferencesHook,
	ChangesListSpecialPageStructuredFiltersHook
{

	private UserOptionsLookup $userOptionsLookup;
	private IDatabase $dbr;
	private Config $config;

	/**
	 * @param UserOptionsLookup $uol
	 * @param ILoadBalancer $lb
	 * @param Config $config
	 */
	public function __construct( UserOptionsLookup $uol, ILoadBalancer $lb, Config $config ) {
		$this->userOptionsLookup = $uol;
		$this->dbr = $lb->getconnectionRef( ILoadBalancer::DB_REPLICA );
		$this->config = $config;
	}

	/**
	 * Add a new user preference.
	 *
	 * @param User $user the user being modified.
	 * @param array &$preference the preference array
	 */
	public function onGetPreferences( $user, &$preference ) {
		$preference['watchlisthidesubpages'] = [
			'type' => 'toggle',
			'section' => 'watchlist/changeswatchlist',
			'label-message' => 'tog-watchlisthidesubpages',
		];
		if ( $this->config->get( MainConfigNames::EnotifWatchlist ) ) {
			$preference['enotifwatchlistsubpages'] = [
				'type' => 'toggle',
				'section' => 'personal/email',
				'label-message' => 'tog-enotifwatchlistsubpages'
			];
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onChangesListSpecialPageStructuredFilters(
		$special
	) {
		if ( !$special instanceof SpecialWatchlist ) {
			return;
		}

		$group = $special->getFilterGroup( 'changeType' );

		$filterGroup = new ChangesListBooleanFilter( [
			'name' => 'hidesubpages',
			'group' => $group,
			'priority' => 0,
			'default' => $this->userOptionsLookup->getBoolOption(
				$special->getUser(),
				'watchlisthidesubpages'
			),
			// 'queryCallable' => [ $this, 'modifyQuery' ],
			'label' => 'subpagewatchlist-rcfilter-showsubpages-label',
			'description' => 'subpagewatchlist-rcfilter-showsubpages-description',
			'showHide' => 'subpagewatchlist-rcshowsubpages'
			// TODO: highlighting
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function onChangesListSpecialPageQuery(
		string $type,
		array &$tables,
		array &$fields,
		array &$conds,
		array &$query_options,
		array &$join_conds,
		$opts
	) {
		if ( $type !== 'Watchlist' || $opts['hidesubpages'] ) {
			return;
		}

		if ( isset( $join_conds['watchlist'] )
			&& $join_conds['watchlist'][1][1] === 'wl_title=rc_title'
		) {
			// FIXME: Avoid showing duplicates
			$join_conds['watchlist'][1][1] = $this->dbr->makeList(
				[
					'wl_title = rc_title',
					$this->dbr->buildConcat(
					[ 'wl_title', $this->dbr->addQuotes( '/' ) ] ) . '=' .
					'SUBSTR( rc_title, 1, CHAR_LENGTH( wl_title ) + 1 )',
				],
				LIST_OR
			);
		} else {
			throw new LogicException( "Could not understand watchlist query" );
		}
	}
}
