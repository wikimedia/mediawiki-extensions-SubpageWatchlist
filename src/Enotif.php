<?php

namespace MediaWiki\extensions\SubpageWatchlist;

use Config;
use DeferredUpdates;
use Language;
use MailAddress;
use MediaWiki\Hook\AbortEmailNotificationHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\Hook\PageViewUpdatesHook;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Title\Title;
use MediaWiki\User\UserOptionsLookup;
use MediaWiki\Utils\UrlUtils;
use MediaWiki\Watchlist\WatchlistManager;
use MessageCache;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Skin;
use SpecialPage;
use User;
use UserMailer;
use WatchedItemStore;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;

class Enotif implements AbortEmailNotificationHook, LoggerAwareInterface, PageViewUpdatesHook {
	use LoggerAwareTrait;

	private IReadableDatabase $dbr;
	private Config $config;
	private UserOptionsLookup $userOptionsLookup;
	private Language $contLang;
	private MessageCache $messageCache;
	private UrlUtils $urlUtils;
	private WatchlistManager $watchlistManager;
	private WatchedItemStore $watchedItemStore;

	/**
	 * @param IConnectionProvider $dbProvider
	 * @param Config $config
	 * @param UserOptionsLookup $userOptionsLookup
	 * @param Language $contLang
	 * @param MessageCache $messageCache
	 * @param UrlUtils $urlUtils
	 * @param WatchlistManager $watchlistManager
	 * @param WatchedItemStore $watchedItemStore
	 */
	public function __construct(
		IConnectionProvider $dbProvider,
		Config $config,
		UserOptionsLookup $userOptionsLookup,
		Language $contLang,
		MessageCache $messageCache,
		UrlUtils $urlUtils,
		WatchlistManager $watchlistManager,
		WatchedItemStore $watchedItemStore
	) {
		$this->dbr = $dbProvider->getReplicaDatabase();
		$this->config = $config;
		$this->userOptionsLookup = $userOptionsLookup;
		$this->contLang = $contLang;
		// This is what core does, although it seems odd.
		$this->messageCache = $messageCache;
		$this->urlUtils = $urlUtils;
		$this->watchlistManager = $watchlistManager;
		$this->watchedItemStore = $watchedItemStore;
		// Is there a way to get extension.json to inject this?
		$this->logger = LoggerFactory::getInstance( 'SubpageWatchlist' );
	}

	/**
	 * @inheritDoc
	 */
	public function onAbortEmailNotification( $editor, $title, $rc ) {
		// A future TODO might be to do this from job queue instead of deferred update.
		// The rest of the extension does not differ depending on if NS has
		// subpages enabled, so don't do so here either.

		$pages = $this->getParts( $title );
		if ( !$pages ) {
			return;
		}

		// FIXME IF the user is watching multiple base page names,
		// then the notification timestamp thing will first go to the
		// first one, then the second one, and so on, resulting in
		// multiple notifications before they stop.
		$res = $this->dbr->select(
			[ 'w1' => 'watchlist', 'w2' => 'watchlist', 'watchlist_expiry' ],
			[ 'userId' => 'w1.wl_user', 'page' => 'MIN( w1.wl_title )' ],
			[
				'w1.wl_namespace' => $title->getNamespace(),
				'w1.wl_title' => $pages,
				'w2.wl_user' => null,
				'we_item' => null,
				'w1.wl_notificationtimestamp' => null
			],
			__METHOD__,
			[ 'GROUP BY' => 'userId' ],
			[
				'w2' => [
					'LEFT JOIN',
					[
						'w1.wl_user = w2.wl_user',
						'w2.wl_namespace' => $title->getNamespace(),
						'w2.wl_title' => $title->getDBKey()
					]
				],
				// Even if watchlist expiry is disabled, doesn't hurt to join.
				'watchlist_expiry' => [
					'LEFT JOIN',
					[
						'w1.wl_id = we_item',
						'we_expiry <= ' . $this->dbr->addQuotes( $this->dbr->timestamp() )
					]
				]
			]
		);
		$timestampsToReset = [];
		foreach ( $res as $res ) {
			$user = User::newFromId( $res->userId );
			$watchedTitle = Title::makeTitle( $title->getNamespace(), $res->page );
			if (
				!$this->weShouldNotifyUser(
					$user,
					$editor,
					$rc->getAttribute( 'rc_minor' )
				)
			) {
				continue;
			}
			if ( !isset( $timestampsToReset[$res->page] ) ) {
				$timestampsToReset[$res->page] = [];
			}
			$timestampsToReset[$res->page][] = $user;
			[ $subject, $body ] = $this->composeEmail(
				$user,
				$editor,
				$title,
				$watchedTitle,
				$rc->getAttribute( 'rc_timestamp' ),
				$rc->getAttribute( 'rc_comment' ),
				$rc->getAttribute( 'rc_last_oldid' ),
				$rc->getAttribute( 'rc_minor' ),
				$rc->mExtra['pageStatus']
			);
			$to = MailAddress::newFromUser( $user );
			// Core adds a list-help header, not sure if we should do the same.
			[ $from, $replyTo ] = $this->getFromAndReplyTo( $editor );
			$status = UserMailer::send(
				$to,
				$from,
				$subject,
				$body,
				[ 'replyTo' => $replyTo ]
			);
			if ( !$status->isOK() ) {
				// Not much we can really do at this point.
				$this->logger->error( __METHOD__ .
					" Could not send watch email to {user} due to {error}",
					[ 'user' => $user->getName(), $status->getMessage() ]
				);
			}
		}
		$this->resetNotificationTimestamps( $title->getNamespace(), $timestampsToReset );
	}

	/**
	 * Reset wl_notificationtimestamp to avoid overwhelming user.
	 *
	 * @param int $ns Namespace of page in question
	 * @param array $timestampsToReset of page db keys => [user]
	 */
	private function resetNotificationTimestamps( int $ns, array $timestampsToReset ) {
		// this is probably pretty inefficient, but if we do our own
		// db query, the cache probably won't be cleared.
		// Perhaps we should generate the timestamp before sending email?
		$ts = wfTimestampNow();
		foreach ( $timestampsToReset as $page => $users ) {
			foreach ( $users as $user ) {
				$target = PageReferenceValue::localReference( $ns, $page );
				$this->watchedItemStore->setNotificationTimestampsForUser(
					$user,
					$ts,
					[ $target ]
				);
			}
		}
	}

	/**
	 * Check if we should notify a specific user
	 *
	 * @param User $targetUser User who is watching page
	 * @param User $editor User who made the edit in question
	 * @param bool $isMinor Is this a minor edit?
	 * @return bool Whether or not to notify the user
	 */
	private function weShouldNotifyUser(
		User $targetUser,
		User $editor,
		$isMinor
	): bool {
		// Note: We do not support $wgEnotifImpersonal at this time.
		// Based on MW core EmailNotification::actuallyNotifyOnPageChange.
		if ( $isMinor &&
			( !$this->config->get( MainConfigNames::EnotifMinorEdits )
			|| $editor->isAllowed( 'nominornewtalk' )
			|| !$this->userOptionsLookup->getOption( $targetUser, 'enotifminoredits' ) )
		) {
			return false;
		}
		if ( $this->config->get( MainConfigNames::BlockDisablesLogin ) && $targetUser->getBlock() ) {
			return false;
		}
		if ( !$targetUser->isEmailConfirmed() ) {
			return false;
		}
		if ( !$this->userOptionsLookup->getOption( $targetUser, 'enotifwatchlistsubpages' ) ) {
			return false;
		}
		// We shouldn't need to check if is talk page, because it wouldn't be a subpage.
		if ( in_array( $targetUser->getName(), $this->config->get( MainConfigNames::UsersNotifiedOnAllChanges ) ) ) {
			return false;
		}
		if ( $targetUser->getId() === $editor->getId() ) {
			return false;
		}
		return true;
	}

	/**
	 * Compose an email
	 *
	 * @param User $targetUser User who is watching the page
	 * @param User $originalEditor User who edited the page
	 * @param Title $editedTitle Title that was edited
	 * @param Title $watchedTitle Title that is watched (base page of $editedTitle)
	 * @param string $timestamp MW format timestamp
	 * @param string $editSummary
	 * @param int $oldid Revision id of edit
	 * @param bool $isMinor Is it a minor edit
	 * @param string $pageAction e.g. created, deleted, edited, etc
	 * @return string[] Subject and body of email
	 */
	private function composeEmail(
		User $targetUser,
		User $originalEditor,
		Title $editedTitle,
		Title $watchedTitle,
		string $timestamp,
		$editSummary,
		$oldid,
		bool $isMinor,
		string $pageAction
	) {
		// Based on composeCommonMailtext
		// Which uses the content language not the target users language, so stay consistent.
		$keys = [];
		$postTransformKeys = [];
		if ( $oldid ) {
			$keys['$NEWPAGE'] = "\n\n" . wfMessage(
				'enotif_lastdiff',
				$editedTitle->getCanonicalURL( [ 'diff' => 'next', 'oldid' => $oldid ] )
			)->inContentLanguage()->text() .
			"\n\n" . wfMessage(
				'enotif_lastvisited',
				$editedTitle->getCanonicalURL( [ 'diff' => '0', 'oldid' => $oldid ] )
			)->inContentLanguage()->text();
			$keys['$OLDID'] = $oldid;
		} else {
			$keys['$OLDID'] = '';
		}
		$keys['$PAGETITLE'] = $editedTitle->getPrefixedText();
		$keys['$PAGETITLE_URL'] = $editedTitle->getCanonicalURL();
		$keys['$PAGETITLEWATCHED'] = $watchedTitle->getPrefixedText();
		$keys['$PAGETITLEWATCHED_URL'] = $watchedTitle->getCanonicalURL();
		$keys['$PAGEMINOREDIT'] = $isMinor ?
			"\n\n" . wfMessage( 'enotif_minoredit' )->inContentLanguage()->text() :
			'';
		$keys['$UNWATCHURL'] = $watchedTitle->getCanonicalURL( 'action=unwatch' );
		if ( !$originalEditor->isRegistered() ) {
			$keys['$PAGEEDITOR'] = wfMessage( 'enotif_anon_editor', $originalEditor->getName() )
				->inContentLanguage()->text();
			$keys['$PAGEEDITOR_EMAIL'] = wfMessage( 'noemailtitle' )->inContentLanguage()->text();
		} else {
			$keys['$PAGEEDITOR'] = $this->config->get( MainConfigNames::EnotifUseRealName ) &&
					$originalEditor->getRealName() !== ''
				? $originalEditor->getRealName() : $originalEditor->getName();
			$emailPage = SpecialPage::getSafeTitleFor( 'Emailuser', $originalEditor->getName() );
			$keys['$PAGEEDITOR_EMAIL'] = $emailPage->getCanonicalURL();
		}

		$keys['$PAGEEDITOR_WIKI'] = $originalEditor->getUserPage()->getCanonicalURL();
		$keys['$HELPPAGE'] = $this->urlUtils->expand(
			Skin::makeInternalOrExternalUrl( wfMessage( 'helppage' )->inContentLanguage()->text() )
		);

		$postTransformKeys['$PAGESUMMARY'] = $editSummary ?: '-';

		// Now build message's subject and body

		// Messages:
		// enotif_subject_deleted, enotif_subject_created, enotif_subject_moved,
		// enotif_subject_restored, enotif_subject_changed
			$subject = wfMessage( 'enotif_subject_' . $pageAction )->inContentLanguage()
			->params( $keys['$PAGETITLE'], $keys['$PAGEEDITOR'] )->text();

		// Messages:
		// enotif_body_intro_deleted, enotif_body_intro_created, enotif_body_intro_moved,
		// enotif_body_intro_restored, enotif_body_intro_changed
		$keys['$PAGEINTRO'] = wfMessage( 'enotif_body_intro_' . $pageAction )
			->inContentLanguage()
			->params( $keys['$PAGETITLE'], $keys['$PAGEEDITOR'], $keys['$PAGETITLE_URL'] )
			->text();

		$body = wfMessage( 'subpagewatchlist-enotif-body' )->inContentLanguage()->plain();
		$body = strtr( $body, $keys );
		$body = $this->messageCache->transform( $body, false, null, $editedTitle );
		$body = wordwrap( strtr( $body, $postTransformKeys ), 72 );

		// This part based on EmailNotification::sendPersonalised
		$watchingUserName = (
			$this->config->get( MainConfigNames::EnotifUseRealName ) &&
			$targetUser->getRealName() !== ''
		) ? $targetUser->getRealName() : $targetUser->getUser()->getName();
		$body = str_replace(
			[
				'$WATCHINGUSERNAME',
				'$PAGEEDITDATE',
				'$PAGEEDITTIME'
			],
			[
				$watchingUserName,
				$this->contLang->userDate( $timestamp, $targetUser ),
				$this->contLang->userTime( $timestamp, $targetUser )
			],
			$body
		);
		return [ $subject, $body ];
	}

	/**
	 * Get the From and Reply-To addresses
	 *
	 * @param User $originalEditor User who made the edit
	 * @return array of MailAddress or null - from and reply-to address
	 */
	private function getFromAndReplyTo( User $originalEditor ) {
		# Reveal the page editor's address as REPLY-TO address only if
		# the user has not opted-out and the option is enabled at the
		# global configuration level.
		$adminAddress = new MailAddress(
			$this->config->get( MainConfigNames::PasswordSender ),
			wfMessage( 'emailsender' )->inContentLanguage()->text()
		);
		if ( $this->config->get( MainConfigNames::EnotifRevealEditorAddress )
			&& ( $originalEditor->getEmail() != '' )
			&& $this->userOptionsLookup->getOption( $originalEditor, 'enotifrevealaddr' )
		) {
			$editorAddress = MailAddress::newFromUser( $originalEditor );
			if ( $this->config->get( MainConfigNames::EnotifFromEditor ) ) {
				$from = $editorAddress;
				$replyto = null;
			} else {
				$from = $adminAddress;
				$replyto = $editorAddress;
			}
		} else {
			$from = $adminAddress;
			$replyto = new MailAddress(
				$this->config->get( MainConfigNames::NoReplyAddress )
			);
		}
		return [ $from, $replyto ];
	}

	/**
	 * Get all base prefixes of a title
	 *
	 * @note This ignores whether or not a namespace has subpages enabled
	 * @param Title $title Starting Title
	 * @return string[] Page db keys (not titles)
	 */
	private function getParts( Title $title ): array {
		$parts = explode( '/', $title->getDBKey() );
		if ( count( $parts ) <= 1 ) {
			// Not a subpage
			return [];
		}
		// We don't want the full page name.
		unset( $parts[count( $parts ) - 1] );
		$pages = [];
		$prev = '';
		foreach ( $parts as $part ) {
			$prev .= $part;
			$pages[] = $prev;
			$prev .= '/';
		}
		return $pages;
	}

	/**
	 * Split into base pages return result as Title array
	 *
	 * @param Title $title Starting title
	 * @return Title[]
	 */
	private function getPartsAsTitles( Title $title ): array {
		$parts = $this->getParts( $title );
		return array_map( static function ( $part ) use ( $title ) {
			return Title::makeTitle( $title->getNamespace(), $part );
		}, $parts );
	}

	/**
	 * @inheritDoc
	 */
	public function onPageViewUpdates( $wikiPage, $user ) {
		$viewedTitle = $wikiPage->getTitle();
		// Core seems to do this presend instead for some reason.
		DeferredUpdates::addCallableUpdate( function () use ( $viewedTitle, $user ) {
			$this->purgeNotificationTimestamp( $viewedTitle, $user );
		} );
	}

	/**
	 * Set wl_notificationtimestamp back to null for base pages
	 *
	 * @param Title $viewedTitle
	 * @param User $user
	 */
	public function purgeNotificationTimestamp( $viewedTitle, $user ) {
		if (
			!$this->userOptionsLookup->getOption( $user, 'enotifwatchlistsubpages' ) ||
			!$this->config->get( MainConfigNames::EnotifWatchlist )
		) {
			return;
		}
		$titles = $this->getPartsAsTitles( $viewedTitle );
		// Core would check oldid, and only do this if
		// viewing current page or a diff to it.
		// Logic is complex so we just do this always.
		foreach ( $titles as $title ) {
			if ( $user->getTalkPage()->equals( $title ) ) {
				// clearing watchlist notification seems to also
				// clear talk page notifications, which we do not want.
				// Alternatively, we could call resetNotificationTimestamp directly.
				continue;
			}
			$this->watchlistManager->clearTitleUserNotifications(
				$user,
				$title
			);
		}
	}
}
