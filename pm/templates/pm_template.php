<?php
/*
 * e107 website system
 *
 * Copyright (C) 2008-2024 e107 Inc (e107.org)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
 *	PM plugin - template file (Bootstrap 5.3, three-pane mail-client layout)
 *
 *	The markup below is the no-JS / first-paint fallback. When JavaScript is
 *	available, pm_app.js replaces the message list (#pm-list-body) and the
 *	reading pane (#pm-reading) with content it fetches from pm_api.php, so the
 *	whole client can be used without a full page reload. Everything rendered
 *	here must therefore stay functional on its own: rows are real <a> links,
 *	actions are real tokenised GET links, and the compose form is a normal POST.
 */

/**
 *	e107 Private messenger plugin
 *
 *	@package	e107_plugins
 *	@subpackage	pm
 */

if (!defined('e107_INIT')) { exit; }

// Base URL every relative PM action link is built from.
$PM_BASE = function_exists('pm_url_prefix') ? pm_url_prefix() : (e107::url('pm', 'index') . '?');

if(deftrue('BOOTSTRAP') && deftrue('FONTAWESOME'))
{
	define('PM_READ_ICON', pm_icon('check'));
	define('PM_UNREAD_ICON', pm_icon('dot'));
	$PM_DRAFT_GLYPH = pm_icon('edit');
}
else
{
	if (!defined('PM_READ_ICON')) define('PM_READ_ICON', "<img src='".e_PLUGIN_ABS."pm/images/read.png' class='icon S16' alt='".LAN_PM_111."' />");
	if (!defined('PM_UNREAD_ICON')) define('PM_UNREAD_ICON', "<img src='".e_PLUGIN_ABS."pm/images/unread.png' class='icon S16' alt='".LAN_PM_27."' />");
	$PM_DRAFT_GLYPH = '';
}

/*
 *	Shortcode wrappers. sc_pm_attachments() emits a <li> per file, so the
 *	attachments wrapper opens a <ul>; the rest are plain spacing divs.
 */
$PM_WRAPPER['PM_ATTACHMENT_ICON'] = " {---}";
$PM_WRAPPER['PM_ATTACHMENTS']     = "<ul class='pm-att-list list-unstyled'>{---}</ul>";
$PM_WRAPPER['PM_EMOTES']          = "<div class='pm-emotes mt-3'>{---}</div>";
$PM_WRAPPER['PM_ATTACHMENT']      = "<div class='pm-attach-field mb-3'>{---}</div>";
$PM_WRAPPER['PM_RECEIPT']         = "<div class='form-check pm-receipt-field mt-3 mb-0'>{---}</div>";
$PM_WRAPPER['PM_REPLY']           = "{---}";


/*
 *	Compose form. show_send() wraps this in the actual <form ... method=post>,
 *	so the <form> tag deliberately does not appear here. {PM_DRAFT_HIDDEN} and
 *	{PM_SAVE_DRAFT_BUTTON} are no-ops on a fresh compose (empty / hidden).
 */
$PM_TEMPLATE['send'] = "
<div class='card pm-compose-card'>
	<div class='card-header'>".LAN_PLUGIN_PM_NEW."</div>
	<div class='card-body'>
		{PM_DRAFT_HIDDEN}
		<div class='mb-3'>
			<label class='form-label'>".LAN_PM_2."</label>
			{PM_FORM_TO}
		</div>
		<div class='mb-3'>
			<label class='form-label'>".LAN_PM_5."</label>
			{PM_FORM_SUBJECT}
		</div>
		<div class='mb-3'>
			<label class='form-label'>".LAN_PM_6."</label>
			{PM_FORM_MESSAGE}
			{PM_EMOTES}
			{PM_RECEIPT}
		</div>
		{PM_ATTACHMENT}
		<div class='d-flex flex-wrap gap-2 pt-2'>
			{PM_POST_BUTTON}
			{PM_SAVE_DRAFT_BUTTON}
		</div>
	</div>
</div>
";


/*
 *	Inbox list. Rows are <li role=option> inside a listbox; the whole row is a
 *	stretched <a> (pm-row-anchor) and the per-row actions float above it.
 */
$PM_TEMPLATE['inbox']['start'] = "<ul id='pm-rows' class='pm-rows list-unstyled' role='listbox' aria-label='".LAN_PM_130."'>";

$PM_TEMPLATE['inbox']['item'] = "
<li class='pm-row {PM_STATUS_CLASS}' data-pm-id='{PM_ID}' data-who-id='{PM_WHO_ID}' data-mode='inbox'>
	<a class='pm-row-anchor' href='".$PM_BASE."show.{PM_ID}.inbox'>
		<span class='pm-row-avatar'>{PM_AVATAR: shape=circle}</span>
		<span class='pm-row-main'>
			<span class='pm-row-top'>
				<span class='pm-row-who'>{PM_FROM}</span>
				<span class='pm-row-date'>{PM_DATE}</span>
			</span>
			<span class='pm-row-subject'>{PM_SUBJECT}{PM_ATTACHMENT_ICON}</span>
		</span>
	</a>
	<span class='pm-row-actions'>{PM_BLOCK_USER}{PM_DELETE=inbox}</span>
	<span class='pm-row-search' hidden>{PM_SUBJECT} {PM_FROM}</span>
</li>
";

$PM_TEMPLATE['inbox']['empty'] = "
<li class='pm-empty'>
	<div class='pm-empty-state d-flex flex-column align-items-center justify-content-center text-center text-muted' style='padding:4rem 1.5rem;'>
		<div class='fs-1 mb-3' aria-hidden='true'>' . pm_icon('inbox') . '</div>
		<p class='mb-1 fw-semibold'>" . LAN_PM_34 . "</p>
		<p class='mb-0 small'>" . defset('LAN_PM_154', 'New messages will appear here.') . "</p>
	</div>
</li>";

$PM_TEMPLATE['inbox']['end'] = "</ul><nav class='pm-pager pm-pager-nojs' aria-label='pagination'>{PM_NEXTPREV=inbox}</nav>";


/*
 *	Outbox (sent) list. No block action; the recipient is the "who" column.
 */
$PM_TEMPLATE['outbox']['start'] = "<ul id='pm-rows' class='pm-rows list-unstyled' role='listbox' aria-label='".LAN_PM_131."'>";

$PM_TEMPLATE['outbox']['item'] = "
<li class='pm-row {PM_STATUS_CLASS}' data-pm-id='{PM_ID}' data-who-id='{PM_WHO_ID}' data-mode='outbox'>
	<a class='pm-row-anchor' href='".$PM_BASE."show.{PM_ID}.outbox'>
		<span class='pm-row-avatar'>{PM_AVATAR: shape=circle}</span>
		<span class='pm-row-main'>
			<span class='pm-row-top'>
				<span class='pm-row-who'>{PM_TO}</span>
				<span class='pm-row-date'>{PM_DATE}</span>
			</span>
			<span class='pm-row-subject'>{PM_SUBJECT}{PM_ATTACHMENT_ICON}</span>
		</span>
	</a>
	<span class='pm-row-actions'>{PM_DELETE=outbox}</span>
	<span class='pm-row-search' hidden>{PM_SUBJECT} {PM_TO}</span>
</li>
";

$PM_TEMPLATE['outbox']['empty'] = "
<li class='pm-empty'>
	<div class='pm-empty-state d-flex flex-column align-items-center justify-content-center text-center text-muted' style='padding:4rem 1.5rem;'>
		<div class='fs-1 mb-3' aria-hidden='true'>' . pm_icon('outbox') . '</div>
		<p class='mb-1 fw-semibold'>" . LAN_PM_34 . "</p>
		<p class='mb-0 small'>" . defset('LAN_PM_157', 'Messages you send will appear here.') . "</p>
	</div>
</li>";

$PM_TEMPLATE['outbox']['end'] = "</ul><nav class='pm-pager pm-pager-nojs' aria-label='pagination'>{PM_NEXTPREV=outbox}</nav>";


/*
 *	Drafts list. Opening a draft is a full-page load into the compose form.
 */
$PM_TEMPLATE['drafts']['start'] = "<ul id='pm-rows' class='pm-rows list-unstyled' role='listbox' aria-label='".LAN_PM_135."'>";

$PM_TEMPLATE['drafts']['item'] = "
<li class='pm-row pm-row-draft' data-pm-id='{PM_ID}' data-mode='drafts'>
	<a class='pm-row-anchor' href='".$PM_BASE."draft.{PM_ID}'>
		<span class='pm-row-avatar pm-row-avatar-icon'>".$PM_DRAFT_GLYPH."</span>
		<span class='pm-row-main'>
			<span class='pm-row-top'>
				<span class='pm-row-who'>".LAN_PM_140.": {PM_DRAFT_TO}</span>
				<span class='pm-row-date'>{PM_DRAFT_DATE}</span>
			</span>
			<span class='pm-row-subject'>{PM_DRAFT_SUBJECT}</span>
		</span>
	</a>
	<span class='pm-row-actions'>{PM_DRAFT_DELETE}</span>
	<span class='pm-row-search' hidden>{PM_DRAFT_SUBJECT} {PM_DRAFT_TO}</span>
</li>
";

$PM_TEMPLATE['drafts']['empty'] = "
<li class='pm-empty'>
	<div class='pm-empty-state d-flex flex-column align-items-center justify-content-center text-center text-muted' style='padding:4rem 1.5rem;'>
		<div class='fs-1 mb-3' aria-hidden='true'>' . pm_icon('drafts') . '</div>
		<p class='mb-1 fw-semibold'>" . defset('LAN_PM_136', 'No drafts') . "</p>
		<p class='mb-0 small'>" . defset('LAN_PM_155', 'Messages you save will appear here.') . "</p>
	</div>
</li>";

$PM_TEMPLATE['drafts']['end'] = "</ul><nav class='pm-pager pm-pager-nojs' aria-label='pagination'>{PM_NEXTPREV=drafts}</nav>";


/*
 *	Blocked senders list. Rows are not openable; the only action is unblock.
 */
$PM_TEMPLATE['blocked']['start'] = "<ul id='pm-rows' class='pm-rows list-unstyled' role='listbox' aria-label='".defset('LAN_PM_66', 'Blocked senders')."'>";

$PM_TEMPLATE['blocked']['item'] = "
<li class='pm-row pm-row-blocked' data-pm-id='{PM_ID}' data-mode='blocked'>
	<span class='pm-row-main pm-row-blocked-main'>
		<span class='pm-row-top'>
			<span class='pm-row-who'>{PM_BLOCKED_USER}</span>
			<span class='pm-row-date'>{PM_BLOCKED_DATE}</span>
		</span>
	</span>
	<span class='pm-row-actions'>{PM_BLOCKED_DELETE}</span>
	<span class='pm-row-search' hidden>{PM_BLOCKED_USER}</span>
</li>
";

$PM_TEMPLATE['blocked']['empty'] = "
<li class='pm-empty'>
	<div class='pm-empty-state d-flex flex-column align-items-center justify-content-center text-center text-muted' style='padding:4rem 1.5rem;'>
		<div class='fs-1 mb-3' aria-hidden='true'>&#128683;</div>
		<p class='mb-1 fw-semibold'>" . defset('LAN_PM_67', 'No users blocked') . "</p>
		<p class='mb-0 small'>" . defset('LAN_PM_156', 'Blocked users will be listed here.') . "</p>
	</div>
</li>";

$PM_TEMPLATE['blocked']['end'] = "</ul>";


/*
 *	Single message (reading pane), no-JS fallback. pm_app.js renders its own
 *	copy from the API; this keeps the page usable without JavaScript.
 */
$PM_TEMPLATE['show'] = "
<article class='pm-message' data-pm-id='{PM_ID}' data-who-id='{PM_WHO_ID}'>
	<header class='pm-msg-head'>
		<h2 class='pm-msg-subject'>{PM_SUBJECT}</h2>
		<div class='pm-msg-meta'>
			<span class='pm-msg-avatar'>{PM_AVATAR: shape=circle}</span>
			<span class='pm-msg-who'>{PM_FROM_TO}</span>
			<time class='pm-msg-date'>{PM_DATE}</time>
		</div>
	</header>
	<div class='pm-msg-body'>{PM_MESSAGE}</div>
	{PM_ATTACHMENTS}
	<footer class='pm-msg-actions d-flex flex-wrap gap-2 align-items-center'>
		{PM_REPLY}
		{PM_DELETE}
	</footer>
</article>
";


/*
 *	Out-of-band e-mail notification. Not part of the mail-client UI; left
 *	intact so the notification e-mail keeps its layout.
 */
$PM_TEMPLATE['notify'] =
"<div>
<h4>".LAN_PM_101." {SITENAME}</h4>
<table class='table table-striped'>
<tr><td>".LAN_PM_102."</td><td>{USERNAME}</td></tr>
<tr><td>".LAN_PM_103."</td><td>{PM_SUBJECT}</td></tr>
<tr><td>".LAN_PM_108."</td><td>{PM_DATE}</td></tr>
<tr><td>".LAN_PM_104."</td><td>{PM_ATTACHMENTS}</td></tr>
</table>
<table class='table'><tr><td class='text-center'><br />
<a class='btn btn-primary btn-lg' href='{PM_URL}'>".LAN_PM_113."</a></td></tr>
</table>
";
