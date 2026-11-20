<?php
/*
 * e107 website system
 *
 * Copyright (C) 2008-2009 e107 Inc (e107.org)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
 *	PM plugin - menu template (Bootstrap 5.3)
 *
 */

if (!defined('e107_INIT')) { exit; }

if(deftrue('BOOTSTRAP') && deftrue('FONTAWESOME'))
{
	define('PM_INBOX_ICON',  pm_icon('inbox'));
	define('PM_OUTBOX_ICON', pm_icon('outbox'));
	define('NEWPM_ANIMATION', pm_icon('inbox'));
}
else
{
	if (!defined('PM_INBOX_ICON'))  define('PM_INBOX_ICON',  pm_icon('inbox'));
	if (!defined('PM_OUTBOX_ICON')) define('PM_OUTBOX_ICON', pm_icon('outbox'));
	if (!defined('NEWPM_ANIMATION')) define('NEWPM_ANIMATION', pm_icon('inbox'));
}

/* Use e107's URL builder with query params – handles SEF / non-SEF / Windows paths */
$inboxUrl   = pm_url('inbox');
$outboxUrl  = pm_url('outbox');
$blockedUrl = pm_url('blocked');
$composeUrl = pm_url('send');

$PM_MENU_WRAPPER['PM_SEND_PM_LINK']          = "<a class='btn btn-sm btn-primary w-100 mt-2' href='" . $composeUrl . "'>" . LAN_PLUGIN_PM_NEW . "</a>";
$PM_MENU_WRAPPER['PM_INBOX_FILLED']          = "<span class='badge text-bg-secondary'>[{---}%]</span>";
$PM_MENU_WRAPPER['PM_OUTBOX_FILLED']         = "<span class='badge text-bg-secondary'>[{---}%]</span>";
$PM_MENU_WRAPPER['PM_NEWPM_ANIMATE']         = "<a href='" . $inboxUrl . "' class='text-decoration-none'>{---}</a>";
$PM_MENU_WRAPPER['PM_BLOCKED_SENDERS_MANAGE']= "<a href='" . $blockedUrl . "' class='d-block small text-muted mt-1'>" . LAN_PM_66 . "</a>";

$PM_MENU_TEMPLATE = "
<nav class='pm-menu' aria-label='" . LAN_PM . "'>
  <ul class='list-unstyled mb-0 small'>
    <li class='d-flex align-items-center py-1'>
      <a href='" . $inboxUrl . "' class='d-flex align-items-center text-decoration-none flex-grow-1'>
        <span class='me-2'>" . PM_INBOX_ICON . "</span>
        <span>" . LAN_PLUGIN_PM_INBOX . "</span>
        <span class='ms-auto'>{PM_NEWPM_ANIMATE}</span>
      </a>
    </li>
    <li class='px-3 py-1 text-muted d-flex flex-wrap gap-1'>
      <span>{PM_INBOX_TOTAL} " . LAN_PM_36 . ", {PM_INBOX_UNREAD} " . LAN_PM_37 . " {PM_INBOX_FILLED}</span>
    </li>
    <li class='d-flex align-items-center py-1 mt-1'>
      <a href='" . $outboxUrl . "' class='d-flex align-items-center text-decoration-none flex-grow-1'>
        <span class='me-2'>" . PM_OUTBOX_ICON . "</span>
        <span>" . LAN_PLUGIN_PM_OUTBOX . "</span>
      </a>
    </li>
    <li class='px-3 py-1 text-muted d-flex flex-wrap gap-1'>
      <span>{PM_OUTBOX_TOTAL} " . LAN_PM_36 . ", {PM_OUTBOX_UNREAD} " . LAN_PM_37 . " {PM_OUTBOX_FILLED}</span>
    </li>
  </ul>
  {PM_SEND_PM_LINK}
  {PM_BLOCKED_SENDERS_MANAGE}
</nav>";
